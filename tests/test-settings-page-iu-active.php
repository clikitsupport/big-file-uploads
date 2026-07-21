<?php
/**
 * The settings page with Infinite Uploads active.
 *
 * Runs only under phpunit-iu-active.xml.dist, which declares a stub Infinite_Uploads class before
 * WordPress boots. A class cannot be undeclared, so this branch needs its own process.
 *
 * @package BigFileUploads
 *
 * @group iu-active
 */

/**
 * @testdox Settings page with Infinite Uploads active
 *
 * @covers BigFileUploads::settings_page
 *
 * @group iu-active
 */
class Test_BFU_Settings_Page_IU_Active extends BFU_TestCase {

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'settings_page_big_file_uploads' );
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

	public function test_the_stub_is_in_place() {
		$this->assertTrue(
			class_exists( 'Infinite_Uploads' ),
			'This suite is meaningless without the stub; check tests/bootstrap-iu-active.php.'
		);
	}

	public function test_settings_page_still_renders_without_a_fatal() {
		$html = $this->render();

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Big File Uploads', $html );
		$this->assertStringContainsString( 'name="upload_limit"', $html );
	}

	public function test_subscribe_modal_is_hidden_when_infinite_uploads_is_active() {
		// Nothing dismissed; the modal is suppressed purely because IU is already installed.
		$this->assertFalse( (bool) get_user_option( 'bfu_subscribe_notice_dismissed', get_current_user_id() ) );

		$this->assertStringNotContainsString( 'id="subscribe-modal"', $this->render() );
	}

	public function test_storage_scan_ui_is_hidden_when_infinite_uploads_is_active() {
		// IU does its own storage analysis, so BFU stands down.
		$html = $this->render();

		$this->assertStringNotContainsString( 'id="scan-modal"', $html );
	}

	public function test_upgrade_modal_still_renders_when_infinite_uploads_is_active() {
		$this->assertStringContainsString( 'id="upgrade-modal"', $this->render() );
	}
}
