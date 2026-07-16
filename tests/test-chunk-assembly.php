<?php
/**
 * Chunked upload assembly.
 *
 * This is the one place in the plugin that can corrupt a user's file, so these tests compare
 * hashes rather than sizes, and assert that every failure path leaves nothing behind.
 *
 * @package BigFileUploads
 */

/**
 * @covers BigFileUploads::append_chunk
 * @covers BigFileUploads::chunk_path
 * @covers BigFileUploads::cleanup_stale_chunks
 * @covers BigFileUploads::prepare_temp_dir
 */
class Test_BFU_Chunk_Assembly extends BFU_TestCase {

	/**
	 * Stands in for WP_CONTENT_DIR/bfu-temp.
	 *
	 * @var string
	 */
	private $temp_dir;

	public function set_up() {
		parent::set_up();

		$this->temp_dir = $this->make_scratch_dir( 'bfu-chunks-' );

		add_filter(
			'bfu_temp_dir',
			function () {
				return $this->temp_dir;
			}
		);
	}

	/**
	 * Write bytes to a file standing in for $_FILES['async-upload']['tmp_name'].
	 *
	 * append_chunk() unlinks this on success, exactly as it does for a real upload.
	 *
	 * @param string $bytes Chunk payload.
	 *
	 * @return string Absolute path.
	 */
	private function incoming_chunk( $bytes ) {
		$path = $this->temp_dir . '/incoming-' . wp_generate_password( 12, false );
		file_put_contents( $path, $bytes );

		return $path;
	}

	/**
	 * Feed a payload through append_chunk() the way plupload would, in order.
	 *
	 * @param string $file_name  Name of the file being uploaded.
	 * @param string $payload    The complete file contents.
	 * @param int    $chunk_size Bytes per chunk.
	 *
	 * @return string The assembled temp file path.
	 */
	private function upload_in_chunks( $file_name, $payload, $chunk_size ) {
		$path   = $this->bfu()->chunk_path( $file_name );
		$chunks = str_split( $payload, $chunk_size );

		foreach ( $chunks as $index => $chunk ) {
			$result = $this->bfu()->append_chunk( $path, $this->incoming_chunk( $chunk ), $index );

			$this->assertTrue( $result, "Chunk $index should have appended cleanly." );
		}

		return $path;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Happy path: the assembled file is byte-identical
	 * ---------------------------------------------------------------------
	 */

	public function test_chunks_in_order_reassemble_byte_identical() {
		// Deliberately not a multiple of the 4096 read buffer, nor of the chunk size, so the final
		// read of the final chunk is a short one.
		$payload = random_bytes( 100_003 );

		$path = $this->upload_in_chunks( 'movie.mp4', $payload, 4097 );

		$this->assertSame( strlen( $payload ), filesize( $path ) );
		$this->assertSame(
			sha1( $payload ),
			sha1_file( $path ),
			'The reassembled file must be byte-identical to what was uploaded.'
		);
	}

	public function test_single_chunk_upload_reassembles_byte_identical() {
		$payload = random_bytes( 2048 );

		$path = $this->upload_in_chunks( 'small.bin', $payload, 4096 );

		$this->assertSame( sha1( $payload ), sha1_file( $path ) );
	}

	public function test_payload_on_exact_buffer_boundary_reassembles_byte_identical() {
		$payload = random_bytes( 4096 * 4 );

		$path = $this->upload_in_chunks( 'aligned.bin', $payload, 4096 );

		$this->assertSame( sha1( $payload ), sha1_file( $path ) );
	}

	public function test_chunk_ending_in_a_zero_byte_is_not_truncated() {
		/*
		 * Regression test. The read loop used to be `while ( $buff = fread( $in, 4096 ) )`, which
		 * stops on any falsy read - including the perfectly valid string "0". A chunk whose read
		 * lands exactly on a trailing "0" byte silently lost that byte and everything after it.
		 */
		$payload = str_repeat( 'a', 4096 ) . '0';

		$path = $this->upload_in_chunks( 'trailing-zero.bin', $payload, 8192 );

		$this->assertSame( strlen( $payload ), filesize( $path ) );
		$this->assertSame( sha1( $payload ), sha1_file( $path ) );
	}

	public function test_chunk_consisting_only_of_a_zero_byte_is_not_dropped() {
		$path = $this->bfu()->chunk_path( 'zero.txt' );

		$this->assertTrue( $this->bfu()->append_chunk( $path, $this->incoming_chunk( '0' ), 0 ) );

		$this->assertSame( '0', file_get_contents( $path ) );
	}

	public function test_successful_append_removes_the_incoming_chunk() {
		$path     = $this->bfu()->chunk_path( 'cleanup.bin' );
		$incoming = $this->incoming_chunk( 'AAAA' );

		$this->bfu()->append_chunk( $path, $incoming, 0 );

		$this->assertFileDoesNotExist( $incoming, 'The uploaded chunk should be consumed, not left in temp.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Out of order, duplicated, restarted
	 * ---------------------------------------------------------------------
	 */

	public function test_chunk_arriving_before_chunk_zero_errors_and_publishes_nothing() {
		$path = $this->bfu()->chunk_path( 'out-of-order.bin' );

		$result = $this->bfu()->append_chunk( $path, $this->incoming_chunk( 'CCCC' ), 2 );

		$this->assertWPError( $result );
		$this->assertSame( 'bfu_output_stream', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path, 'A stray chunk must not create a temp file to append onto.' );
	}

	public function test_chunk_arriving_after_its_temp_file_was_swept_errors() {
		$path = $this->bfu()->chunk_path( 'swept.bin' );
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'AAAA' ), 0 );

		// Simulate the cleanup sweep (or an ops-level /tmp purge) removing the file mid-upload.
		unlink( $path );

		$result = $this->bfu()->append_chunk( $path, $this->incoming_chunk( 'BBBB' ), 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'bfu_output_stream', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_restarted_upload_truncates_the_abandoned_attempt() {
		$path = $this->bfu()->chunk_path( 'restart.bin' );

		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'AAAA' ), 0 );
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'BBBB' ), 1 );

		// The user cancels and re-uploads the same filename; chunk 0 must start from scratch.
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'CCCC' ), 0 );

		$this->assertSame(
			'CCCC',
			file_get_contents( $path ),
			'Chunk 0 must truncate, or a restarted upload appends onto the abandoned one.'
		);
	}

	public function test_duplicate_chunk_is_appended_twice() {
		/*
		 * Characterization, not endorsement. There is no per-chunk dedupe: BFU trusts plupload to
		 * send each chunk once, so a chunk delivered twice duplicates its bytes and corrupts the
		 * file. Nothing in the plugin detects this today. If dedupe is ever added, this test should
		 * be inverted rather than deleted.
		 */
		$path = $this->bfu()->chunk_path( 'duplicate.bin' );

		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'AAAA' ), 0 );
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'BBBB' ), 1 );
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'BBBB' ), 1 );

		$this->assertSame( 'AAAABBBBBBBB', file_get_contents( $path ) );
	}

	public function test_missing_final_chunk_publishes_nothing() {
		$path = $this->bfu()->chunk_path( 'incomplete.bin' );

		// Two of an expected three chunks arrive, then the upload dies.
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'AAAA' ), 0 );
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'BBBB' ), 1 );

		$this->assertFileExists( $path, 'The partial file stays in temp, awaiting the final chunk.' );

		// Nothing is handed to WordPress until the final chunk lands.
		$this->assertCount(
			0,
			get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit' ] ),
			'An incomplete upload must not produce an attachment.'
		);

		$uploads = wp_upload_dir();
		$this->assertFileDoesNotExist(
			trailingslashit( $uploads['path'] ) . 'incomplete.bin',
			'A partial file must never reach the uploads directory.'
		);
	}

	public function test_unreadable_incoming_chunk_errors_and_discards_the_partial_file() {
		$path = $this->bfu()->chunk_path( 'unreadable.bin' );
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'AAAA' ), 0 );

		$result = $this->bfu()->append_chunk( $path, $this->temp_dir . '/does-not-exist', 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'bfu_input_stream', $result->get_error_code() );
		$this->assertFileDoesNotExist(
			$path,
			'A file that can no longer be completed must be discarded, not left to be published half-written.'
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Temp file naming
	 * ---------------------------------------------------------------------
	 */

	public function test_chunk_path_is_derived_from_the_filename() {
		$path = $this->bfu()->chunk_path( 'movie.mp4' );

		$this->assertSame(
			sprintf( '%s/%d-%s.part', $this->temp_dir, get_current_blog_id(), sha1( 'movie.mp4' ) ),
			$path
		);
	}

	public function test_concurrent_uploads_of_different_files_use_different_temp_files() {
		$this->assertNotSame(
			$this->bfu()->chunk_path( 'a.mp4' ),
			$this->bfu()->chunk_path( 'b.mp4' ),
			'Two uploads in flight at once must not share a temp file.'
		);
	}

	public function test_chunk_path_honours_the_bfu_temp_dir_filter() {
		$this->assertStringStartsWith( $this->temp_dir . '/', $this->bfu()->chunk_path( 'filtered.bin' ) );
	}

	public function test_chunk_path_of_a_traversing_filename_stays_inside_the_temp_dir() {
		// The filename is hashed, so it cannot escape the temp directory however it is crafted.
		$path = $this->bfu()->chunk_path( '../../../wp-config.php' );

		$this->assertStringStartsWith( $this->temp_dir . '/', $path );
		$this->assertStringNotContainsString( '..', $path );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Temp directory preparation and the stale chunk sweep
	 * ---------------------------------------------------------------------
	 */

	public function test_prepare_temp_dir_creates_the_directory_and_blocks_browsing() {
		$dir = $this->temp_dir . '/nested/deep';

		$this->bfu()->prepare_temp_dir( $dir );

		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . '/index.php', 'The temp dir must carry an index guard.' );
		$this->assertStringContainsString( 'Silence is golden', file_get_contents( $dir . '/index.php' ) );
	}

	public function test_prepare_temp_dir_is_idempotent() {
		$this->bfu()->prepare_temp_dir( $this->temp_dir );
		file_put_contents( $this->temp_dir . '/index.php', '<?php // customised' );

		$this->bfu()->prepare_temp_dir( $this->temp_dir );

		$this->assertStringContainsString(
			'customised',
			file_get_contents( $this->temp_dir . '/index.php' ),
			'An existing index guard should be left alone.'
		);
	}

	public function test_sweep_removes_stale_chunks_but_not_in_flight_ones() {
		$stale     = $this->write_file( $this->temp_dir . '/1-stale.part', 'abandoned yesterday' );
		$in_flight = $this->write_file( $this->temp_dir . '/1-in-flight.part', 'still uploading' );

		touch( $stale, time() - DAY_IN_SECONDS - MINUTE_IN_SECONDS );
		touch( $in_flight, time() - MINUTE_IN_SECONDS ); // last chunk landed a minute ago

		$deleted = $this->bfu()->cleanup_stale_chunks( $this->temp_dir );

		$this->assertSame( [ $stale ], $deleted );
		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists(
			$in_flight,
			'The sweep must never delete a temp file an upload is still appending to.'
		);
	}

	public function test_sweep_only_touches_part_files() {
		$index = $this->write_file( $this->temp_dir . '/index.php', '<?php' );
		$other = $this->write_file( $this->temp_dir . '/notes.txt', 'unrelated' );

		touch( $index, time() - DAY_IN_SECONDS - MINUTE_IN_SECONDS );
		touch( $other, time() - DAY_IN_SECONDS - MINUTE_IN_SECONDS );

		$deleted = $this->bfu()->cleanup_stale_chunks( $this->temp_dir );

		$this->assertSame( [], $deleted );
		$this->assertFileExists( $index, 'The index guard must survive the sweep.' );
		$this->assertFileExists( $other );
	}

	public function test_sweep_respects_a_custom_max_age() {
		$recent = $this->write_file( $this->temp_dir . '/1-recent.part', 'x' );
		touch( $recent, time() - ( 2 * HOUR_IN_SECONDS ) );

		$this->assertSame(
			[],
			$this->bfu()->cleanup_stale_chunks( $this->temp_dir ),
			'Two hours old is not stale under the 24 hour default.'
		);

		$this->assertSame(
			[ $recent ],
			$this->bfu()->cleanup_stale_chunks( $this->temp_dir, HOUR_IN_SECONDS ),
			'...but is stale under a one hour max age.'
		);
	}

	public function test_sweep_of_a_missing_directory_does_not_error() {
		$this->assertSame( [], $this->bfu()->cleanup_stale_chunks( $this->temp_dir . '/never-created' ) );
	}

	public function test_sweep_leaves_a_freshly_started_upload_alone() {
		// End to end: a real chunk 0 lands, then the sweep runs (as it does on every chunk 0).
		$path = $this->bfu()->chunk_path( 'in-progress.bin' );
		$this->bfu()->append_chunk( $path, $this->incoming_chunk( 'AAAA' ), 0 );

		$this->bfu()->cleanup_stale_chunks( $this->temp_dir );

		$this->assertFileExists( $path );
		$this->assertTrue( $this->bfu()->append_chunk( $path, $this->incoming_chunk( 'BBBB' ), 1 ) );
		$this->assertSame( 'AAAABBBB', file_get_contents( $path ) );
	}
}
