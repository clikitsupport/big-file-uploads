<?php
/**
 * The bfu_chunker AJAX endpoint, end to end.
 *
 * test-chunk-assembly.php covers append_chunk() in isolation. This file drives the real endpoint,
 * because the decision of *when* to hand a file to WordPress lives here and nowhere else - so this
 * is the only place "a partial file is never published" can actually be proven.
 *
 * @package BigFileUploads
 */

/**
 * @covers BigFileUploads::ajax_chunk_receiver
 * @covers BigFileUploads::send_upload_error
 */
class Test_BFU_Chunk_Receiver extends BFU_TestCase {

	/**
	 * Stands in for WP_CONTENT_DIR/bfu-temp.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Stands in for PHP's upload_tmp_dir, where $_FILES tmp_name points.
	 *
	 * Deliberately separate from $temp_dir: PHP's temp directory is not BFU's, and one test
	 * deletes BFU's to check it gets recreated.
	 *
	 * @var string
	 */
	private $incoming_dir;

	public function set_up() {
		parent::set_up();

		$this->clear_uploads();

		$this->temp_dir     = $this->make_scratch_dir( 'bfu-receiver-' );
		$this->incoming_dir = $this->make_scratch_dir( 'bfu-incoming-' );

		add_filter(
			'bfu_temp_dir',
			function () {
				return $this->temp_dir;
			}
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		$this->clear_uploads();

		$_FILES = [];
		unset(
			$_REQUEST['chunk'],
			$_REQUEST['chunks'],
			$_REQUEST['name'],
			$_REQUEST['_wpnonce'],
			$_REQUEST['short'],
			$_REQUEST['type'],
			$_REQUEST['post_id'],
			$_POST['_wpnonce']
		);

		parent::tear_down();
	}

	/**
	 * POST one chunk to the endpoint the way plupload would.
	 *
	 * @param string $file_name Name of the file being uploaded.
	 * @param string $payload   This chunk's bytes.
	 * @param int    $chunk     Zero-indexed chunk number.
	 * @param int    $chunks    Total chunk count.
	 * @param array  $request   Extra $_REQUEST values (e.g. short/type for the non-ajax path).
	 *
	 * @return array{output: string, died: bool} What the endpoint emitted.
	 */
	private function post_chunk( $file_name, $payload, $chunk, $chunks, array $request = [] ) {
		$incoming = $this->incoming_dir . '/incoming-' . wp_generate_password( 12, false );
		file_put_contents( $incoming, $payload );

		$_FILES = [
			'async-upload' => [
				'name'     => $file_name,
				'tmp_name' => $incoming,
				'error'    => 0,
				'size'     => strlen( $payload ),
				'type'     => 'application/octet-stream',
			],
		];

		$nonce = wp_create_nonce( 'media-form' );

		$_REQUEST['chunk']    = $chunk;
		$_REQUEST['chunks']   = $chunks;
		$_REQUEST['name']     = $file_name;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['_wpnonce']    = $nonce;

		foreach ( $request as $key => $value ) {
			$_REQUEST[ $key ] = $value;
		}

		return $this->capture( function () {
			$this->bfu()->ajax_chunk_receiver();
		} );
	}

	/**
	 * Every attachment currently in the media library.
	 *
	 * @return WP_Post[]
	 */
	private function attachments() {
		return get_posts(
			[
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
			]
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Publishing happens only on the final chunk
	 * ---------------------------------------------------------------------
	 */

	public function test_final_chunk_publishes_a_byte_identical_attachment() {
		$payload = str_repeat( 'The quick brown fox. ', 500 );
		$chunks  = str_split( $payload, 4096 );
		$total   = count( $chunks );

		$this->assertGreaterThan( 1, $total, 'This test is only meaningful with several chunks.' );

		foreach ( $chunks as $index => $chunk ) {
			$result = $this->post_chunk( 'story.txt', $chunk, $index, $total );

			if ( $index < $total - 1 ) {
				$this->assertCount(
					0,
					$this->attachments(),
					"Chunk $index of $total is not the last, so nothing may be published yet."
				);
			}
		}

		$attachments = $this->attachments();
		$this->assertCount( 1, $attachments, 'The final chunk should publish exactly one attachment.' );

		$published = get_attached_file( $attachments[0]->ID );
		$this->assertFileExists( $published );
		$this->assertSame(
			sha1( $payload ),
			sha1_file( $published ),
			'The published file must be byte-identical to what was uploaded.'
		);

		$this->assertStringContainsString( '"success":true', $result['output'] );
	}

	public function test_single_chunk_upload_publishes() {
		// plupload sends chunks=0 when it decides not to chunk at all.
		$this->post_chunk( 'single.txt', 'no chunking needed', 0, 0 );

		$attachments = $this->attachments();
		$this->assertCount( 1, $attachments );
		$this->assertSame( 'no chunking needed', file_get_contents( get_attached_file( $attachments[0]->ID ) ) );
	}

	public function test_missing_final_chunk_publishes_nothing() {
		// Two of an expected three chunks arrive, then the upload dies. This is the assertion that
		// matters: the endpoint really was driven, and it really did not publish.
		$this->post_chunk( 'incomplete.txt', 'AAAA', 0, 3 );
		$this->post_chunk( 'incomplete.txt', 'BBBB', 1, 3 );

		$this->assertCount( 0, $this->attachments(), 'An incomplete upload must not produce an attachment.' );

		$this->assertFileExists(
			$this->bfu()->chunk_path( 'incomplete.txt' ),
			'The partial file waits in temp for the final chunk.'
		);

		$uploads = wp_upload_dir();
		$this->assertFileDoesNotExist(
			trailingslashit( $uploads['path'] ) . 'incomplete.txt',
			'A partial file must never reach the uploads directory.'
		);
	}

	public function test_publishing_consumes_the_temp_file() {
		$this->post_chunk( 'consumed.txt', 'AAAA', 0, 2 );
		$temp = $this->bfu()->chunk_path( 'consumed.txt' );
		$this->assertFileExists( $temp );

		$this->post_chunk( 'consumed.txt', 'BBBB', 1, 2 );

		$this->assertFileDoesNotExist( $temp, 'The temp file is moved into uploads, not left behind.' );
		$this->assertCount( 1, $this->attachments() );
	}

	public function test_out_of_order_chunk_publishes_nothing_and_reports_an_error() {
		// The final chunk arrives first: there is no temp file to append to.
		$result = $this->post_chunk( 'jumbled.txt', 'CCCC', 2, 3 );

		$this->assertTrue( $result['died'] );
		$this->assertStringContainsString( '"success":false', $result['output'] );
		$this->assertStringContainsString( 'error opening the temp file', $result['output'] );
		$this->assertCount( 0, $this->attachments() );
		$this->assertFileDoesNotExist( $this->bfu()->chunk_path( 'jumbled.txt' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The size limit gate
	 * ---------------------------------------------------------------------
	 */

	public function test_upload_exceeding_the_limit_is_rejected_and_the_partial_discarded() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 10, 'format' => 'MB' ] ],
			]
		);

		$this->post_chunk( 'toobig.txt', str_repeat( 'A', 8 ), 0, 2 );

		// 8 bytes already written + 8 more would exceed the 10 byte limit, and this is the final
		// chunk, so the upload is failed outright.
		$result = $this->post_chunk( 'toobig.txt', str_repeat( 'B', 8 ), 1, 2 );

		$this->assertTrue( $result['died'] );
		$this->assertStringContainsString( 'exceeded the maximum file size', $result['output'] );
		$this->assertCount( 0, $this->attachments(), 'An over-limit upload must not be published.' );
		$this->assertFileDoesNotExist(
			$this->bfu()->chunk_path( 'toobig.txt' ),
			'The over-limit partial must be cleaned up, not left to fill the disk.'
		);
	}

	public function test_upload_within_the_limit_is_published() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 5 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		$this->post_chunk( 'fits.txt', 'AAAA', 0, 2 );
		$this->post_chunk( 'fits.txt', 'BBBB', 1, 2 );

		$this->assertCount( 1, $this->attachments() );
	}

	public function test_limit_gate_follows_the_current_users_role() {
		$this->set_settings(
			[
				'by_role' => true,
				'limits'  => [
					'all'           => [ 'bytes' => 5 * MB_IN_BYTES, 'format' => 'MB' ],
					'administrator' => [ 'bytes' => 10, 'format' => 'MB' ],
				],
			]
		);

		// The admin's own 10 byte limit applies, not the roomier all-users value.
		$this->post_chunk( 'roled.txt', str_repeat( 'A', 8 ), 0, 2 );
		$result = $this->post_chunk( 'roled.txt', str_repeat( 'B', 8 ), 1, 2 );

		$this->assertStringContainsString( 'exceeded the maximum file size', $result['output'] );
		$this->assertCount( 0, $this->attachments() );
	}

	public function test_over_limit_mid_upload_stops_without_publishing() {
		/*
		 * Characterization. When the limit is blown on a chunk that is *not* the last one, the
		 * endpoint just ends the request: no error is sent, and the partial file is left in temp
		 * for the 24 hour sweep to collect. The upload can never complete, so nothing is published,
		 * but the user is told nothing until a later chunk happens to be the final one.
		 */
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 10, 'format' => 'MB' ] ],
			]
		);

		$this->post_chunk( 'midway.txt', str_repeat( 'A', 8 ), 0, 5 );
		$result = $this->post_chunk( 'midway.txt', str_repeat( 'B', 8 ), 1, 5 );

		$this->assertSame( '', $result['output'], 'No error is reported mid-upload.' );
		$this->assertCount( 0, $this->attachments() );
		$this->assertFileExists( $this->bfu()->chunk_path( 'midway.txt' ), 'The partial is left for the sweep.' );
		$this->assertSame( 8, filesize( $this->bfu()->chunk_path( 'midway.txt' ) ), 'The over-limit chunk was not appended.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Authentication and request validation
	 * ---------------------------------------------------------------------
	 */

	public function test_logged_out_request_is_refused() {
		wp_set_current_user( 0 );

		$result = $this->post_chunk( 'nope.txt', 'AAAA', 0, 1 );

		$this->assertTrue( $result['died'] );
		$this->assertCount( 0, $this->attachments() );
		$this->assertFileDoesNotExist( $this->bfu()->chunk_path( 'nope.txt' ) );
	}

	public function test_user_without_upload_files_is_refused() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->post_chunk( 'nope.txt', 'AAAA', 0, 1 );

		$this->assertTrue( $result['died'] );
		$this->assertCount( 0, $this->attachments() );
		$this->assertFileDoesNotExist( $this->bfu()->chunk_path( 'nope.txt' ) );
	}

	public function test_request_with_a_bad_nonce_is_refused() {
		$incoming = $this->incoming_dir . '/incoming-bad-nonce';
		file_put_contents( $incoming, 'AAAA' );

		$_FILES = [
			'async-upload' => [
				'name'     => 'nonce.txt',
				'tmp_name' => $incoming,
				'error'    => 0,
				'size'     => 4,
				'type'     => 'text/plain',
			],
		];

		$_REQUEST['chunk']    = 0;
		$_REQUEST['chunks']   = 1;
		$_REQUEST['name']     = 'nonce.txt';
		$_REQUEST['_wpnonce'] = 'not-a-real-nonce';
		$_POST['_wpnonce']    = 'not-a-real-nonce';

		$result = $this->capture( function () {
			$this->bfu()->ajax_chunk_receiver();
		} );

		$this->assertTrue( $result['died'] );
		$this->assertCount( 0, $this->attachments() );
		$this->assertFileDoesNotExist( $this->bfu()->chunk_path( 'nonce.txt' ) );
	}

	public function test_request_with_no_file_is_refused() {
		$_FILES = [];

		$result = $this->capture( function () {
			$this->bfu()->ajax_chunk_receiver();
		} );

		$this->assertTrue( $result['died'] );
		$this->assertCount( 0, $this->attachments() );
	}

	public function test_request_with_a_php_upload_error_is_refused() {
		$_FILES = [
			'async-upload' => [
				'name'     => 'broken.txt',
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_PARTIAL,
				'size'     => 0,
				'type'     => 'text/plain',
			],
		];

		$result = $this->capture( function () {
			$this->bfu()->ajax_chunk_receiver();
		} );

		$this->assertTrue( $result['died'] );
		$this->assertCount( 0, $this->attachments() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Temp directory side effects
	 * ---------------------------------------------------------------------
	 */

	public function test_first_chunk_prepares_the_temp_directory() {
		$this->rmdir_recursive( $this->temp_dir );
		$this->assertDirectoryDoesNotExist( $this->temp_dir );

		$this->post_chunk( 'fresh.txt', 'AAAA', 0, 2 );

		$this->assertDirectoryExists( $this->temp_dir );
		$this->assertFileExists( $this->temp_dir . '/index.php', 'The temp dir must be protected from browsing.' );
	}

	public function test_first_chunk_sweeps_stale_chunks_but_not_the_upload_in_progress() {
		$stale = $this->write_file( $this->temp_dir . '/1-stale.part', 'abandoned' );
		touch( $stale, time() - DAY_IN_SECONDS - MINUTE_IN_SECONDS );

		$this->post_chunk( 'sweeper.txt', 'AAAA', 0, 2 );

		$this->assertFileDoesNotExist( $stale, 'Chunk 0 should have swept the abandoned file.' );
		$this->assertFileExists(
			$this->bfu()->chunk_path( 'sweeper.txt' ),
			'The sweep must not take out the upload that triggered it.'
		);
	}

	public function test_later_chunks_do_not_sweep() {
		$this->post_chunk( 'keeper.txt', 'AAAA', 0, 3 );

		// An unrelated upload goes stale midway through this one.
		$stale = $this->write_file( $this->temp_dir . '/1-stale.part', 'abandoned' );
		touch( $stale, time() - DAY_IN_SECONDS - MINUTE_IN_SECONDS );

		$this->post_chunk( 'keeper.txt', 'BBBB', 1, 3 );

		$this->assertFileExists( $stale, 'The sweep only runs on chunk 0.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The non-ajax (add new media page) response path
	 * ---------------------------------------------------------------------
	 */

	public function test_non_ajax_path_publishes_and_returns_the_attachment_id() {
		$this->post_chunk( 'shortform.txt', 'AAAA', 0, 2, [ 'short' => '1', 'type' => 'file' ] );
		$result = $this->post_chunk( 'shortform.txt', 'BBBB', 1, 2, [ 'short' => '1', 'type' => 'file' ] );

		$attachments = $this->attachments();
		$this->assertCount( 1, $attachments );
		$this->assertSame( (string) $attachments[0]->ID, trim( $result['output'] ) );
	}

	public function test_non_ajax_path_renders_an_html_error_rather_than_json() {
		$result = $this->post_chunk( 'jumbled.txt', 'CCCC', 2, 3, [ 'short' => '1', 'type' => 'file' ] );

		$this->assertTrue( $result['died'] );
		$this->assertStringContainsString( 'error-div', $result['output'] );
		$this->assertStringContainsString( 'has failed to upload', $result['output'] );
		$this->assertStringNotContainsString( '"success":false', $result['output'] );
		$this->assertCount( 0, $this->attachments() );
	}
}
