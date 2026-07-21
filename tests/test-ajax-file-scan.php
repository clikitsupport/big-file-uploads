<?php
/**
 * The bfu_file_scan AJAX endpoint.
 *
 * test-file-scan.php covers the scanner class. This file covers the endpoint wrapped around it,
 * whose job is to decide what the browser is allowed to ask the scanner to walk - the path
 * traversal guard on `remaining_dirs` is a security control and lives only here.
 *
 * @package BigFileUploads
 */

/**
 * @testdox File scan endpoint (bfu_file_scan)
 *
 * @covers BigFileUploads::ajax_file_scan
 * @covers BigFileUploads::get_upload_dir_root
 */
class Test_BFU_Ajax_File_Scan extends BFU_TestCase {

	/**
	 * The directory the endpoint is allowed to scan.
	 *
	 * @var string
	 */
	private $uploads_root;

	/**
	 * A directory outside the uploads root, standing in for the rest of the server.
	 *
	 * @var string
	 */
	private $outside_dir;

	/**
	 * Size of the file planted outside the uploads root. Distinctive, so it is obvious in a total.
	 */
	const SECRET_SIZE = 99991;

	public function set_up() {
		parent::set_up();

		$this->force_ajax_context();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		$this->uploads_root = $this->bfu()->get_upload_dir_root();
		$this->clear_uploads();

		// Two files inside uploads, 300 bytes all told.
		$this->write_file( $this->uploads_root . '/bfu-fixture/photo.jpg', str_repeat( 'x', 100 ) );
		$this->write_file( $this->uploads_root . '/bfu-fixture/clip.mp4', str_repeat( 'x', 200 ) );

		// One file the endpoint must never be talked into counting.
		$this->outside_dir = $this->make_scratch_dir( 'bfu-outside-' );
		$this->write_file( $this->outside_dir . '/secret.jpg', str_repeat( 'x', self::SECRET_SIZE ) );
	}

	public function tear_down() {
		$this->clear_uploads();
		unset( $_POST['remaining_dirs'] );

		parent::tear_down();
	}

	/**
	 * Call the endpoint and decode its JSON response.
	 *
	 * @param array $post Values to merge into $_POST.
	 *
	 * @return array The decoded response.
	 */
	private function call_scan( array $post = [] ) {
		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		$result = $this->capture( function () {
			$this->bfu()->ajax_file_scan();
		} );

		$decoded = json_decode( $result['output'], true );
		$this->assertIsArray( $decoded, 'The endpoint must always answer with JSON.' );

		return $decoded;
	}

	/*
	 * ---------------------------------------------------------------------
	 * The traversal guard
	 * ---------------------------------------------------------------------
	 */

	public function test_symlink_escaping_the_uploads_root_is_rejected() {
		/*
		 * The sharp case. The scanner class skips any path containing '..', so a literal ../
		 * traversal is stopped there regardless. A symlink is not caught by that check - only the
		 * endpoint's realpath() comparison stands between a crafted remaining_dirs and the rest of
		 * the filesystem.
		 */
		symlink( $this->outside_dir, $this->uploads_root . '/escape' );

		$response = $this->call_scan( [ 'remaining_dirs' => [ '/escape' ] ] );

		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'2',
			$response['data']['file_count'],
			'Only the two files inside uploads may be counted; the symlinked directory must be refused.'
		);
		$this->assertStringNotContainsString(
			(string) self::SECRET_SIZE,
			wp_json_encode( $response ),
			'Nothing from outside the uploads root may appear in the totals.'
		);
	}

	public function test_parent_directory_traversal_is_rejected() {
		$escape = '/../' . basename( $this->outside_dir );

		$response = $this->call_scan( [ 'remaining_dirs' => [ $escape ] ] );

		$this->assertTrue( $response['success'] );
		$this->assertSame( '2', $response['data']['file_count'] );
	}

	public function test_absolute_path_outside_uploads_is_rejected() {
		$response = $this->call_scan( [ 'remaining_dirs' => [ $this->outside_dir ] ] );

		$this->assertTrue( $response['success'] );
		$this->assertStringNotContainsString( (string) self::SECRET_SIZE, wp_json_encode( $response ) );
	}

	public function test_non_array_remaining_dirs_is_ignored() {
		$response = $this->call_scan( [ 'remaining_dirs' => '/escape' ] );

		$this->assertTrue( $response['success'] );
		$this->assertSame( '2', $response['data']['file_count'] );
	}

	public function test_a_directory_inside_uploads_is_accepted() {
		// Prove the guard is discriminating, not just refusing everything.
		$this->call_scan(); // First pass, so the results option exists to resume onto.

		$response = $this->call_scan( [ 'remaining_dirs' => [ '/bfu-fixture' ] ] );

		$this->assertTrue( $response['success'] );
		$this->assertGreaterThan( 0, (int) $response['data']['file_count'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Normal operation
	 * ---------------------------------------------------------------------
	 */

	public function test_scan_reports_totals_for_the_uploads_directory() {
		$response = $this->call_scan();

		$this->assertTrue( $response['success'] );
		$this->assertSame( '2', $response['data']['file_count'] );
		$this->assertSame( '300.00 B', $response['data']['file_size'] );
		$this->assertTrue( $response['data']['is_done'] );
		$this->assertSame( [], $response['data']['remaining_dirs'] );
	}

	public function test_scan_response_carries_the_keys_the_admin_js_reads() {
		$response = $this->call_scan();

		foreach ( [ 'file_count', 'file_size', 'is_done', 'remaining_dirs' ] as $key ) {
			$this->assertArrayHasKey( $key, $response['data'] );
		}
	}

	public function test_completed_scan_is_persisted_for_the_settings_page() {
		$this->call_scan();

		$results = get_site_option( 'tuxbfu_file_scan' );

		$this->assertNotEmpty( $results['scan_finished'] );
		$this->assertSame( 1, $results['types']['image']->files );
		$this->assertSame( 1, $results['types']['video']->files );
	}

	public function test_upload_dir_root_is_the_uploads_basedir() {
		$uploads = wp_upload_dir();

		$this->assertSame( $uploads['basedir'], $this->bfu()->get_upload_dir_root() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Access control
	 * ---------------------------------------------------------------------
	 */

	public function test_scan_requires_the_settings_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$response = $this->call_scan();

		$this->assertFalse( $response['success'] );
	}

	public function test_scan_refuses_logged_out_requests() {
		wp_set_current_user( 0 );

		$response = $this->call_scan();

		$this->assertFalse( $response['success'] );
	}
}
