<?php
/**
 * Multisite behaviour.
 *
 * Runs only under phpunit-multisite.xml.dist. The plugin resolves its capability once, in the
 * constructor, from is_multisite(), so network mode has to be decided before WordPress boots - it
 * cannot be faked from inside the single-site suite.
 *
 * @package BigFileUploads
 *
 * @group multisite
 */

/**
 * @testdox Multisite
 *
 * @covers BigFileUploads::get_upload_limit
 * @covers BigFileUploads::settings_url
 * @covers BigFileUploads::chunk_path
 * @covers BigFileUploads::settings_page
 *
 * @group multisite
 */
class Test_BFU_Multisite extends BFU_TestCase {

	/**
	 * A secondary site on the network.
	 *
	 * @var int
	 */
	private $subsite_id;

	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a network. Run with -c phpunit-multisite.xml.dist.' );
		}

		$this->subsite_id = self::factory()->blog->create();
	}

	public function tear_down() {
		if ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		parent::tear_down();
	}

	/**
	 * Render the settings page and capture its markup.
	 *
	 * @return string
	 */
	private function render() {
		$level = ob_get_level();

		ob_start();
		try {
			$this->bfu()->settings_page();

			return ob_get_clean();
		} catch ( Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			throw $e;
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Access control is network scoped
	 * ---------------------------------------------------------------------
	 */

	public function test_settings_are_gated_on_a_network_capability() {
		$this->assertSame(
			'manage_network_options',
			$this->get_capability(),
			'On a network the settings screen must be a super admin concern, not a site admin one.'
		);
	}

	public function test_a_site_administrator_cannot_open_the_settings_page() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		add_user_to_blog( $this->subsite_id, $admin, 'administrator' );
		wp_set_current_user( $admin );

		$this->assertFalse( current_user_can( 'manage_network_options' ) );

		$this->expectException( WPDieException::class );

		$this->render();
	}

	public function test_a_super_admin_can_open_the_settings_page() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		$html = $this->render();

		$this->assertStringContainsString( 'Big File Uploads', $html );
		$this->assertStringContainsString( 'name="upload_limit"', $html );
	}

	public function test_settings_url_points_at_the_network_admin() {
		$url = $this->bfu()->settings_url();

		$this->assertSame( network_admin_url( 'settings.php?page=big_file_uploads' ), $url );
		$this->assertStringContainsString( '/network/', $url );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Limits are network wide
	 * ---------------------------------------------------------------------
	 */

	public function test_the_limit_is_shared_by_every_site_on_the_network() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 250 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		$this->assertSame( 250 * MB_IN_BYTES, $this->bfu()->get_upload_limit(), 'Main site.' );

		switch_to_blog( $this->subsite_id );
		$subsite_limit = $this->bfu()->get_upload_limit();
		restore_current_blog();

		$this->assertSame(
			250 * MB_IN_BYTES,
			$subsite_limit,
			'The limit is stored in a site option, so one setting governs the whole network.'
		);
	}

	public function test_a_limit_saved_once_applies_after_switching_sites() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 750 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		switch_to_blog( $this->subsite_id );
		$emitted = apply_filters( 'upload_size_limit', 0 );
		restore_current_blog();

		$this->assertSame( 750 * MB_IN_BYTES, $emitted );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Chunk temp files are isolated per site
	 * ---------------------------------------------------------------------
	 */

	public function test_the_same_filename_on_two_sites_uses_different_temp_files() {
		$main = $this->bfu()->chunk_path( 'movie.mp4' );

		switch_to_blog( $this->subsite_id );
		$subsite = $this->bfu()->chunk_path( 'movie.mp4' );
		restore_current_blog();

		$this->assertNotSame(
			$main,
			$subsite,
			'Two sites uploading the same filename at once must not share a temp file.'
		);
		$this->assertStringContainsString( get_current_blog_id() . '-', basename( $main ) );
		$this->assertStringContainsString( $this->subsite_id . '-', basename( $subsite ) );
	}

	public function test_concurrent_uploads_of_the_same_filename_on_two_sites_do_not_corrupt_each_other() {
		$temp_dir = $this->make_scratch_dir( 'bfu-ms-chunks-' );
		add_filter(
			'bfu_temp_dir',
			function () use ( $temp_dir ) {
				return $temp_dir;
			}
		);

		$incoming = function ( $bytes ) use ( $temp_dir ) {
			$path = $temp_dir . '/incoming-' . wp_generate_password( 12, false );
			file_put_contents( $path, $bytes );

			return $path;
		};

		// Site A starts uploading movie.mp4.
		$main_path = $this->bfu()->chunk_path( 'movie.mp4' );
		$this->bfu()->append_chunk( $main_path, $incoming( 'AAAA' ), 0 );

		// Site B starts uploading its own, different movie.mp4 while A is still going.
		switch_to_blog( $this->subsite_id );
		$subsite_path = $this->bfu()->chunk_path( 'movie.mp4' );
		$this->bfu()->append_chunk( $subsite_path, $incoming( 'BBBB' ), 0 );
		$this->bfu()->append_chunk( $subsite_path, $incoming( 'BBBB' ), 1 );
		restore_current_blog();

		// Site A finishes.
		$this->bfu()->append_chunk( $main_path, $incoming( 'AAAA' ), 1 );

		$this->assertSame( 'AAAAAAAA', file_get_contents( $main_path ), "Site A's file was corrupted by site B." );
		$this->assertSame( 'BBBBBBBB', file_get_contents( $subsite_path ), "Site B's file was corrupted by site A." );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Storage scan
	 * ---------------------------------------------------------------------
	 */

	public function test_the_scan_root_covers_the_whole_network() {
		// Sites store their media under wp-content/uploads/sites/<id>, so one root covers them all.
		// The scan is registered on the main site only, and is expected to report network storage.
		$this->assertSame( WP_CONTENT_DIR . '/uploads', $this->bfu()->get_upload_dir_root() );
	}

	public function test_scan_results_are_shared_across_the_network() {
		update_site_option(
			'tuxbfu_file_scan',
			[
				'scan_finished' => time(),
				'types'         => [ 'image' => (object) [ 'size' => 2048, 'files' => 3 ] ],
			]
		);

		switch_to_blog( $this->subsite_id );
		$types = $this->bfu()->get_filetypes();
		restore_current_blog();

		$this->assertSame( 3, $types['image']->files, 'Scan results live in a site option, so the network shares them.' );
	}
}
