<?php
/**
 * Big_File_Uploads_File_Scan: totals over a fixture tree, resumption across batches, and
 * resilience to directories it cannot read.
 *
 * @package BigFileUploads
 */

/**
 * @covers Big_File_Uploads_File_Scan
 * @covers BigFileUploads::get_file_type
 */
class Test_BFU_File_Scan extends BFU_TestCase {

	/**
	 * Root of the fixture tree, standing in for the uploads directory.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Expected totals for the tree built by build_fixture_tree().
	 */
	const EXPECTED_FILES = 6;
	const EXPECTED_SIZE  = 2100;

	public function set_up() {
		parent::set_up();

		$this->root = $this->make_scratch_dir( 'bfu-scan-' );
	}

	/**
	 * Build a known tree: 6 files totalling 2100 bytes across 4 type buckets and 3 directory levels.
	 *
	 * @return void
	 */
	private function build_fixture_tree() {
		$files = [
			'photo.jpg'                  => 100,  // image
			'clip.mp4'                   => 200,  // video
			'docs/report.pdf'            => 300,  // document
			'docs/notes.txt'             => 400,  // document
			'docs/nested/archive.zip'    => 500,  // archive
			'docs/nested/deeper/log.txt' => 600,  // document
		];

		foreach ( $files as $relative => $size ) {
			$this->write_file( $this->root . '/' . $relative, str_repeat( 'x', $size ) );
		}

		// An empty directory the scan must walk into and out of without incident.
		mkdir( $this->root . '/empty', 0777, true );
	}

	/**
	 * Run a scan to completion in a single pass.
	 *
	 * A timeout of 0 is falsy, so has_exceeded_timelimit() never fires and the walk never batches.
	 *
	 * @return Big_File_Uploads_File_Scan
	 */
	private function scan_in_one_pass() {
		$scan = new Big_File_Uploads_File_Scan( $this->root, 0 );
		$scan->start();

		return $scan;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Totals
	 * ---------------------------------------------------------------------
	 */

	public function test_scan_of_fixture_tree_reports_correct_totals() {
		$this->build_fixture_tree();

		$scan = $this->scan_in_one_pass();

		$this->assertTrue( $scan->is_done );
		$this->assertSame( self::EXPECTED_FILES, $scan->get_total_files() );
		$this->assertSame( self::EXPECTED_SIZE, $scan->get_total_size() );
		$this->assertSame( [], $scan->paths_left );
	}

	public function test_scan_groups_files_into_type_buckets() {
		$this->build_fixture_tree();
		$this->scan_in_one_pass();

		$types = get_site_option( 'tuxbfu_file_scan' )['types'];

		$this->assertSame( 1, $types['image']->files );
		$this->assertSame( 100, $types['image']->size );

		$this->assertSame( 1, $types['video']->files );
		$this->assertSame( 200, $types['video']->size );

		$this->assertSame( 3, $types['document']->files, 'pdf + two txt files.' );
		$this->assertSame( 1300, $types['document']->size );

		$this->assertSame( 1, $types['archive']->files );
		$this->assertSame( 500, $types['archive']->size );
	}

	public function test_scan_of_empty_tree_reports_zero() {
		$scan = $this->scan_in_one_pass();

		$this->assertTrue( $scan->is_done );
		$this->assertSame( 0, $scan->get_total_files() );
		$this->assertSame( 0, $scan->get_total_size() );
	}

	public function test_scan_of_missing_directory_does_not_fatal() {
		$scan = new Big_File_Uploads_File_Scan( $this->root . '/does-not-exist', 0 );
		$scan->start();

		$this->assertTrue( $scan->is_done );
		$this->assertSame( 0, $scan->get_total_files() );
	}

	public function test_completed_scan_records_a_finished_timestamp() {
		$this->build_fixture_tree();
		$this->scan_in_one_pass();

		$results = get_site_option( 'tuxbfu_file_scan' );

		$this->assertNotEmpty( $results['scan_finished'] );
		$this->assertEqualsWithDelta( time(), $results['scan_finished'], 60 );
	}

	public function test_symlinks_are_not_followed() {
		$this->build_fixture_tree();

		$outside = $this->make_scratch_dir( 'bfu-outside-' );
		$this->write_file( $outside . '/secret.jpg', str_repeat( 'x', 999 ) );
		symlink( $outside, $this->root . '/linked' );

		$scan = $this->scan_in_one_pass();

		$this->assertSame(
			self::EXPECTED_FILES,
			$scan->get_total_files(),
			'A symlinked directory must not be walked, or a loop could hang the scan.'
		);
	}

	public function test_zero_byte_files_are_not_counted() {
		/*
		 * Characterization. get_file_info() bails on an empty filesize(), so zero byte files are
		 * invisible to the scan. They contribute nothing to storage totals, which is what the scan
		 * reports, but the file count is understated.
		 */
		$this->build_fixture_tree();
		$this->write_file( $this->root . '/empty.jpg', '' );

		$scan = $this->scan_in_one_pass();

		$this->assertSame( self::EXPECTED_FILES, $scan->get_total_files() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Resumption
	 * ---------------------------------------------------------------------
	 */

	public function test_scan_resumed_across_batches_matches_a_single_pass() {
		$this->build_fixture_tree();

		$single = $this->scan_in_one_pass();
		$this->assertTrue( $single->is_done );
		$expected_files = $single->get_total_files();
		$expected_size  = $single->get_total_size();
		$expected_types = get_site_option( 'tuxbfu_file_scan' )['types'];

		// Start over, this time forcing the walk to time out partway through each request.
		delete_site_option( 'tuxbfu_file_scan' );

		/*
		 * Make each directory slow enough to trip the time limit deterministically. is_excluded()
		 * runs once per path, and has_exceeded_timelimit() rounds the elapsed time to 2 decimals,
		 * so a 3ms sleep against a 0.001s budget breaks the loop after ~2 directories.
		 */
		add_filter(
			'bfu_sync_exclusions',
			function ( $exclusions ) {
				usleep( 3000 );

				return $exclusions;
			}
		);

		$remaining  = [];
		$batches    = 0;
		$last_scan  = null;

		do {
			$last_scan = new Big_File_Uploads_File_Scan( $this->root, 0.001, $remaining );
			$last_scan->start();

			$remaining = $last_scan->paths_left;
			$batches ++;

			$this->assertLessThan( 100, $batches, 'The resumed scan never converged.' );
		} while ( ! $last_scan->is_done );

		$this->assertGreaterThan( 1, $batches, 'The scan should have been split over several requests.' );
		$this->assertSame( $expected_files, $last_scan->get_total_files(), 'Batched scan lost or double counted files.' );
		$this->assertSame( $expected_size, $last_scan->get_total_size(), 'Batched scan reported a different total size.' );
		$this->assertEquals( $expected_types, get_site_option( 'tuxbfu_file_scan' )['types'] );
	}

	public function test_first_batch_resets_previous_results() {
		$this->build_fixture_tree();
		$this->scan_in_one_pass();

		// A fresh scan (no paths_left) must not accumulate onto the previous run's totals.
		$scan = $this->scan_in_one_pass();

		$this->assertSame( self::EXPECTED_FILES, $scan->get_total_files() );
	}

	public function test_unfinished_scan_is_not_marked_finished() {
		$this->build_fixture_tree();

		add_filter(
			'bfu_sync_exclusions',
			function ( $exclusions ) {
				usleep( 3000 );

				return $exclusions;
			}
		);

		$scan = new Big_File_Uploads_File_Scan( $this->root, 0.001 );
		$scan->start();

		if ( $scan->is_done ) {
			$this->markTestSkipped( 'The tree was walked before the time limit fired; nothing to assert.' );
		}

		$this->assertNotEmpty( $scan->paths_left );

		$results = get_site_option( 'tuxbfu_file_scan' );
		$this->assertFalse(
			$results['scan_finished'],
			'A partial scan must not be reported to the settings page as finished.'
		);
	}

	public function test_resuming_with_no_stored_results_does_not_deprecate() {
		/*
		 * Regression test. A resumed batch reads the previous batch's totals out of the option; if
		 * the option is not there, get_site_option() hands back false and add_file() used to write
		 * array keys straight into it - "Automatic conversion of false to array", deprecated on PHP
		 * 8.1 and an error in PHP 9. Reachable whenever the option is cleared between batches.
		 */
		$this->build_fixture_tree();
		delete_site_option( 'tuxbfu_file_scan' );

		$scan = new Big_File_Uploads_File_Scan( $this->root, 0, [ '/docs' ] );
		$scan->start();

		$this->assertGreaterThan( 0, $scan->get_total_files(), 'The resumed batch should still count what it walks.' );
		$this->assertIsArray( get_site_option( 'tuxbfu_file_scan' ) );
	}

	public function test_resuming_with_a_corrupt_option_does_not_fatal() {
		$this->build_fixture_tree();
		update_site_option( 'tuxbfu_file_scan', 'not-an-array' );

		$scan = new Big_File_Uploads_File_Scan( $this->root, 0, [ '/docs' ] );
		$scan->start();

		$this->assertIsArray( get_site_option( 'tuxbfu_file_scan' ) );
	}

	public function test_parent_directory_traversal_in_remaining_paths_is_skipped() {
		$this->build_fixture_tree();

		$outside = $this->make_scratch_dir( 'bfu-outside-' );
		$this->write_file( $outside . '/secret.jpg', str_repeat( 'x', 999 ) );

		// A forged resumption path that tries to climb out of the uploads root.
		$scan = new Big_File_Uploads_File_Scan( $this->root, 0, [ '/../' . basename( $outside ) ] );
		$scan->start();

		$this->assertSame( 0, $scan->get_total_files(), 'A traversing path must not be scanned.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Unreadable directories
	 * ---------------------------------------------------------------------
	 */

	public function test_unreadable_directory_does_not_fatal_and_the_rest_still_scans() {
		if ( function_exists( 'posix_getuid' ) && 0 === posix_getuid() ) {
			$this->markTestSkipped( 'Running as root, which ignores directory permissions.' );
		}

		if ( is_readable( $this->root ) && false === @chmod( $this->root, 0755 ) ) {
			$this->markTestSkipped( 'Cannot change permissions on this filesystem.' );
		}

		$this->build_fixture_tree();

		$locked = $this->root . '/locked';
		$this->write_file( $locked . '/hidden.jpg', str_repeat( 'x', 999 ) );
		chmod( $locked, 0000 );

		try {
			$scan = $this->scan_in_one_pass();

			$this->assertTrue( $scan->is_done, 'One unreadable directory must not stall the scan.' );
			$this->assertSame(
				self::EXPECTED_FILES,
				$scan->get_total_files(),
				'Files outside the unreadable directory should still be counted.'
			);
		} finally {
			chmod( $locked, 0755 );
		}
	}
}
