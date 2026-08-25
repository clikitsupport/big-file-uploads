/**
 * Shared helpers for the end-to-end suite.
 */

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const crypto = require( 'crypto' );
const zlib = require( 'zlib' );

const WP_USERNAME = process.env.WP_USERNAME || 'admin';
const WP_PASSWORD = process.env.WP_PASSWORD || 'password';

/**
 * The upload ceiling the server itself enforces, set by tests/e2e/php-limits.htaccess.
 *
 * Anything larger than this cannot reach WordPress in a single request, which is the whole point
 * of the tests that reference it.
 */
const SERVER_UPLOAD_LIMIT_BYTES = 2 * 1024 * 1024;

/**
 * Log in to wp-admin.
 *
 * @param {import('@playwright/test').Page} page
 */
async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', WP_USERNAME );
	await page.fill( '#user_pass', WP_PASSWORD );
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.click( '#wp-submit' ),
	] );
}

/**
 * Set the "all users" upload limit through the plugin's own settings screen.
 *
 * Driven through the UI rather than the database so the value under test is one an administrator
 * could actually have saved.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                          amount One value in the chosen unit.
 * @param {string}                          unit   'MB' or 'GB'.
 */
async function setUploadLimit( page, amount, unit = 'MB' ) {
	await page.goto( '/wp-admin/options-general.php?page=big_file_uploads' );

	// Make sure per-role mode is off, so the "all users" field is the one that governs.
	const byRole = page.locator( '#customSwitch_role' );
	if ( await byRole.isChecked() ) {
		await byRole.uncheck();
	}

	/*
	 * Order matters. assets/js/admin.js converts the number whenever the unit select fires change -
	 * choosing MB multiplies it by 1024, choosing GB divides. Filling the number first and picking
	 * the unit second means the page rewrites the value from under you: 3 + "MB" is saved as 3072MB.
	 * Set the unit first and let it convert whatever is there, then overwrite with the real number.
	 *
	 * (A person cannot trip this, because re-picking the option a select is already on fires no
	 * change event. selectOption always fires one.)
	 */
	await page.selectOption( '#upload-limit-format', unit );
	await page.fill( '#upload-limit', String( amount ) );

	await Promise.all( [
		page.waitForLoadState( 'load' ),
		page.click( 'button[name="bfu_settings_submit"]' ),
	] );

	// Saving is the precondition for every assertion that follows, so make sure it actually did.
	// The settings page uses .bfu-alert--success; the subscribe modal has its own .alert-success,
	// so the text filter keeps them apart.
	await page
		.locator( '.bfu-alert--success', { hasText: 'Settings saved' } )
		.waitFor( { state: 'visible', timeout: 10_000 } );
}

/**
 * Write a text file of an exact byte length to a temp directory.
 *
 * The contents are deterministic and position-dependent: every line carries its own index, so a
 * chunk dropped, duplicated or reordered during reassembly changes the hash rather than just the
 * length. Kept to plain ASCII because WordPress validates a .txt upload's real mime type and
 * rejects one whose bytes look binary.
 *
 * @param {number} sizeBytes Exact size to produce.
 * @param {string} name      File name.
 *
 * @return {{path: string, size: number, sha256: string}} The file written.
 */
function makeTextFile( sizeBytes, name ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'bfu-e2e-' ) );
	const filePath = path.join( dir, name );

	const parts = [];
	let written = 0;
	let index = 0;

	while ( written < sizeBytes ) {
		const line = `line ${ String( index ).padStart( 12, '0' ) } abcdefghijklmnopqrstuvwxyz0123456789\n`;
		parts.push( line );
		written += Buffer.byteLength( line );
		index ++;
	}

	const buffer = Buffer.from( parts.join( '' ) ).subarray( 0, sizeBytes );
	fs.writeFileSync( filePath, buffer );

	return {
		path: filePath,
		size: buffer.length,
		sha256: crypto.createHash( 'sha256' ).update( buffer ).digest( 'hex' ),
	};
}

/**
 * CRC32, as PNG chunks require it.
 *
 * @param {Buffer} buf
 * @return {number}
 */
function crc32( buf ) {
	let crc = 0xffffffff;
	for ( let i = 0; i < buf.length; i++ ) {
		let c = ( crc ^ buf[ i ] ) & 0xff;
		for ( let k = 0; k < 8; k++ ) {
			c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
		}
		crc = ( crc >>> 8 ) ^ c;
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}

/**
 * Assemble one PNG chunk (length + type + data + CRC).
 *
 * @param {string} type
 * @param {Buffer} data
 * @return {Buffer}
 */
function pngChunk( type, data ) {
	const len = Buffer.alloc( 4 );
	len.writeUInt32BE( data.length, 0 );
	const typeBuf = Buffer.from( type, 'ascii' );
	const crc = Buffer.alloc( 4 );
	crc.writeUInt32BE( crc32( Buffer.concat( [ typeBuf, data ] ) ), 0 );
	return Buffer.concat( [ len, typeBuf, data, crc ] );
}

/**
 * Write a valid, decodable PNG whose pixels are random noise, so it is genuinely image data that
 * WordPress accepts as `image/png` yet does not compress below the target size.
 *
 * Built by hand rather than pulled from a library so the suite has no image dependency, and sized
 * to clear the server upload limit: this is the fixture for proving that a *large image* still
 * rides BFU's chunked path on the media library, rather than being diverted into WordPress 7.1's
 * client-side media processing (which reprocesses images and only runs in the block editor).
 *
 * The IDAT is stored uncompressed (zlib level 0), which keeps generation fast and the byte count
 * predictable — the file is ~ width * height * 3 bytes.
 *
 * @param {number} width
 * @param {number} height
 * @param {string} name
 *
 * @return {{path: string, size: number, sha256: string, width: number, height: number}}
 */
function makeImageFile( width, height, name ) {
	const signature = Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] );

	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( width, 0 );
	ihdr.writeUInt32BE( height, 4 );
	ihdr[ 8 ] = 8; // 8 bits per channel
	ihdr[ 9 ] = 2; // colour type 2 = RGB

	// One filter byte (0 = none) per scanline, then random RGB pixels.
	const rowLen = 1 + width * 3;
	const raw = crypto.randomBytes( rowLen * height );
	for ( let y = 0; y < height; y++ ) {
		raw[ y * rowLen ] = 0;
	}

	const png = Buffer.concat( [
		signature,
		pngChunk( 'IHDR', ihdr ),
		pngChunk( 'IDAT', zlib.deflateSync( raw, { level: 0 } ) ),
		pngChunk( 'IEND', Buffer.alloc( 0 ) ),
	] );

	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'bfu-img-' ) );
	const filePath = path.join( dir, name );
	fs.writeFileSync( filePath, png );

	return {
		path: filePath,
		size: png.length,
		sha256: crypto.createHash( 'sha256' ).update( png ).digest( 'hex' ),
		width,
		height,
	};
}

/**
 * Hand a file to plupload on the Add New Media screen.
 *
 * Targets the input plupload's html5 runtime injects, not the `#async-upload` field belonging to
 * the no-JS browser uploader - that one posts straight to async-upload.php and never touches Big
 * File Uploads at all, so using it would make the test a no-op.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          filePath
 */
async function uploadViaPlupload( page, filePath ) {
	await page.goto( '/wp-admin/media-new.php' );

	// The uploader only exists once plupload has initialised.
	const input = page.locator( '.moxie-shim input[type="file"]' );
	await input.waitFor( { state: 'attached' } );

	await input.setInputFiles( filePath );
}

/**
 * Record the size of every chunk the browser uploads, so a test can prove how the file actually
 * went over the wire.
 *
 * Reads content-length from allHeaders(). The obvious routes do not work: postData() and
 * postDataBuffer() both return null for a body this large, sizes().requestBodySize reports 0 for
 * an uploaded file, and headers() omits content-length. Each of those fails by reporting *zero
 * chunks*, which would make an assertion that chunking happened quietly unfalsifiable.
 *
 * A chunk is the only multipart/form-data POST these screens make - heartbeat and the rest are
 * urlencoded - so the content type identifies them without needing to read the body.
 *
 * @param {import('@playwright/test').Page} page
 *
 * @return {{sizes: () => number[], settle: () => Promise<unknown>}} Sizes in bytes, in flight
 *                                                                   order. Await settle() before
 *                                                                   reading sizes().
 */
function recordChunkUploads( page ) {
	const sizes = [];
	const pending = [];

	page.on( 'requestfinished', ( request ) => {
		if ( request.method() !== 'POST' || ! request.url().includes( 'admin-ajax.php' ) ) {
			return;
		}

		pending.push(
			request
				.allHeaders()
				.then( ( headers ) => {
					if ( ! ( headers[ 'content-type' ] || '' ).startsWith( 'multipart/form-data' ) ) {
						return;
					}
					sizes.push( Number( headers[ 'content-length' ] ) );
				} )
				.catch( () => {} )
		);
	} );

	return {
		sizes: () => sizes,
		settle: () => Promise.all( pending ),
	};
}

/**
 * Record calls to the media REST endpoint.
 *
 * WordPress 7.1's client-side media processing uploads through `POST /wp/v2/media` (and its
 * sideload sibling) rather than the plupload/admin-ajax path BFU hooks. Counting these tells a test
 * whether an upload was diverted away from BFU — for a media-library upload the expectation is that
 * there are none.
 *
 * @param {import('@playwright/test').Page} page
 *
 * @return {{calls: () => string[]}}
 */
function recordMediaRestUploads( page ) {
	const calls = [];

	page.on( 'request', ( request ) => {
		if ( request.method() !== 'POST' ) {
			return;
		}
		if ( /\/wp-json\/wp\/v2\/media/.test( request.url() ) || /rest_route=[^&]*wp%2Fv2%2Fmedia/.test( request.url() ) ) {
			calls.push( request.url() );
		}
	} );

	return { calls: () => calls };
}

/**
 * Wait for an upload to be accepted and return the attachment it produced.
 *
 * Success shows up as the Edit link WordPress swaps into the media item once the server has taken
 * the finished file. The item's own id keeps plupload's client-side uid throughout, so it is no
 * use as a signal.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                          timeout
 *
 * @return {Promise<string>} The new attachment's ID.
 */
async function waitForUploadedAttachmentId( page, timeout = 90_000 ) {
	const editLink = page.locator( '#media-items .media-item a.edit-attachment' ).first();
	await editLink.waitFor( { state: 'attached', timeout } );

	const href = await editLink.getAttribute( 'href' );

	return new URL( href ).searchParams.get( 'post' );
}

module.exports = {
	SERVER_UPLOAD_LIMIT_BYTES,
	login,
	setUploadLimit,
	makeTextFile,
	makeImageFile,
	uploadViaPlupload,
	recordChunkUploads,
	recordMediaRestUploads,
	waitForUploadedAttachmentId,
};
