/**
 * Large-file chunked upload: the real integration.
 *
 * plupload slicing the file in the browser and the server reassembling the pieces only ever meet
 * here. The PHPUnit suite drives the endpoint chunk by chunk with a hand-built $_FILES, which
 * proves the server half; it cannot prove that plupload, configured by the plugin's own
 * filter_plupload_settings(), slices a real file the way the server expects.
 *
 * The dev site is capped at 2MB per request by tests/e2e/php-limits.htaccess, so a 5MB file that
 * arrives intact cannot have been sent in one piece.
 */

const { test, expect } = require( '@playwright/test' );
const crypto = require( 'crypto' );

const {
	SERVER_UPLOAD_LIMIT_BYTES,
	login,
	setUploadLimit,
	makeTextFile,
	makeImageFile,
	uploadViaPlupload,
	recordChunkUploads,
	recordMediaRestUploads,
	waitForUploadedAttachmentId,
} = require( './helpers' );

const FILE_SIZE = 5 * 1024 * 1024; // Comfortably over the server's 2MB ceiling.

test.describe( 'Large file chunked upload', () => {

	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'a file larger than the server limit uploads intact via chunking', async ( { page } ) => {
		expect(
			FILE_SIZE,
			'The fixture has to exceed the server ceiling or this test proves nothing.'
		).toBeGreaterThan( SERVER_UPLOAD_LIMIT_BYTES );

		// Allow well above the file size, so the plugin's own gate is not what is under test here.
		await setUploadLimit( page, 100, 'MB' );

		const file = makeTextFile( FILE_SIZE, 'big-upload.txt' );
		const uploads = recordChunkUploads( page );

		await uploadViaPlupload( page, file.path );

		const attachmentId = await waitForUploadedAttachmentId( page );
		await expect( page.locator( '#media-items .error-div' ) ).toHaveCount( 0 );

		await uploads.settle();
		const sizes = uploads.sizes();

		// How the bytes actually travelled. Without these the test would still pass on a server
		// with no upload limit, having proven nothing about chunking.
		expect(
			sizes.length,
			'Expected plupload to slice the file into several requests.'
		).toBeGreaterThan( 1 );

		expect(
			Math.max( ...sizes ),
			'Every request must fit under the server limit - that is the point of chunking.'
		).toBeLessThan( SERVER_UPLOAD_LIMIT_BYTES );

		expect(
			sizes.reduce( ( total, size ) => total + size, 0 ),
			'The chunks together should account for the whole file.'
		).toBeGreaterThanOrEqual( FILE_SIZE );

		// Ask WordPress where it put the file, then fetch the published bytes back.
		const media = await ( await page.request.get( `/wp-json/wp/v2/media/${ attachmentId }` ) ).json();
		expect( media.source_url ).toBeTruthy();

		const published = await page.request.get( media.source_url );
		expect( published.ok() ).toBe( true );

		const body = await published.body();

		expect( body.length, 'The published file is the wrong size.' ).toBe( file.size );
		expect(
			crypto.createHash( 'sha256' ).update( body ).digest( 'hex' ),
			'The reassembled file is not byte-identical to what was uploaded.'
		).toBe( file.sha256 );
	} );

	test( 'a file larger than the configured limit is refused', async ( { page } ) => {
		// The plugin hands its limit to plupload as filters.max_file_size, so an oversized file is
		// stopped in the browser before a single chunk is sent.
		await setUploadLimit( page, 3, 'MB' );

		const file = makeTextFile( FILE_SIZE, 'too-big.txt' );
		const uploads = recordChunkUploads( page );

		await uploadViaPlupload( page, file.path );

		await expect( page.locator( '#media-items .media-item' ) ).toContainText(
			/exceeds the maximum upload size|file size has exceeded/i
		);
		await expect( page.locator( '#media-items .media-item a.edit-attachment' ) ).toHaveCount( 0 );

		await uploads.settle();
		expect(
			uploads.sizes().length,
			'An over-limit file should be rejected in the browser, without uploading anything.'
		).toBe( 0 );
	} );

	test( 'a large image still rides the chunked path, not client-side media processing', async ( { page } ) => {
		// WordPress 7.1 added client-side media processing (CSMP): images can be resized/converted
		// in the browser and uploaded via the REST media endpoint. It runs in the block editor, not
		// the media library, so a large image dropped on Add New Media must still go through BFU's
		// plupload chunking and reach the server byte-for-byte, untouched. If a future release ever
		// diverts media-library image uploads into CSMP, this is the test that catches it: the file
		// would arrive reprocessed (not byte-identical) or over REST (no BFU chunks).
		await setUploadLimit( page, 100, 'MB' );

		const image = makeImageFile( 1400, 900, 'big-photo.png' ); // ~3.7MB of random pixels.
		expect( image.size ).toBeGreaterThan( SERVER_UPLOAD_LIMIT_BYTES );

		const chunks = recordChunkUploads( page );
		const rest = recordMediaRestUploads( page );

		await uploadViaPlupload( page, image.path );

		const attachmentId = await waitForUploadedAttachmentId( page );
		await expect( page.locator( '#media-items .error-div' ) ).toHaveCount( 0 );
		await chunks.settle();

		expect(
			chunks.sizes().length,
			'The image should have been chunked by BFU.'
		).toBeGreaterThan( 1 );
		expect(
			rest.calls().length,
			'A media-library image must not be diverted to the REST media endpoint by CSMP.'
		).toBe( 0 );

		const media = await ( await page.request.get( `/wp-json/wp/v2/media/${ attachmentId }` ) ).json();
		expect( media.mime_type ).toBe( 'image/png' );

		const body = await ( await page.request.get( media.source_url ) ).body();
		expect( body.length, 'The stored image is the wrong size.' ).toBe( image.size );
		expect(
			crypto.createHash( 'sha256' ).update( body ).digest( 'hex' ),
			'The image was reprocessed (not byte-identical) — CSMP likely intercepted it.'
		).toBe( image.sha256 );
	} );
} );
