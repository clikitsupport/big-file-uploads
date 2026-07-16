<?php
/**
 * Settings page smoke tests: it renders without fatalling, saves what it is given, and shows the
 * subscribe modal only when it should.
 *
 * @package BigFileUploads
 */

/**
 * @covers BigFileUploads::settings_page
 */
class Test_BFU_Settings_Page extends BFU_TestCase {

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'settings_page_big_file_uploads' );
	}

	/**
	 * Render the settings page and capture its markup.
	 *
	 * Unwinds the buffer if the page dies partway through (a capability or nonce failure), so a
	 * test that expects WPDieException doesn't leave a buffer open behind it.
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
	 * It renders
	 * ---------------------------------------------------------------------
	 */

	public function test_settings_page_renders_without_a_fatal() {
		$html = $this->render();

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Big File Uploads', $html );
		$this->assertStringContainsString( 'name="upload_limit"', $html, 'The limit field should render.' );
		$this->assertStringContainsString( 'name="by_role"', $html, 'The per-role toggle should render.' );
	}

	public function test_settings_page_renders_a_nonce() {
		$this->assertStringContainsString( 'name="_wpnonce"', $this->render() );
	}

	public function test_settings_page_renders_the_same_markup_when_called_twice() {
		// The templates are pulled in with require, not require_once, so a second render is not
		// silently empty.
		$first  = $this->render();
		$second = $this->render();

		$this->assertNotEmpty( $second );
		$this->assertStringContainsString( 'name="upload_limit"', $second );
		$this->assertSame( strlen( $first ), strlen( $second ) );
	}

	public function test_settings_page_renders_the_stored_limit_in_display_units() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 3 * GB_IN_BYTES, 'format' => 'GB' ] ],
			]
		);

		$html = $this->render();

		$this->assertStringContainsString( 'value="3"', $html, 'The field should show 3, not 3221225472.' );
	}

	public function test_settings_page_renders_a_row_per_upload_capable_role() {
		$html = $this->render();

		foreach ( wp_roles()->roles as $role_key => $role ) {
			if ( ! empty( $role['capabilities']['upload_files'] ) ) {
				$this->assertStringContainsString(
					'upload_limit[' . $role_key . ']',
					$html,
					"$role_key can upload, so it should have a limit field."
				);
			}
		}
	}

	public function test_settings_page_renders_the_scan_prompt_before_a_scan_has_run() {
		delete_site_option( 'tuxbfu_file_scan' );

		$this->assertStringContainsString( 'id="scan-modal"', $this->render() );
	}

	public function test_settings_page_renders_scan_results_once_a_scan_has_finished() {
		update_site_option(
			'tuxbfu_file_scan',
			[
				'scan_finished' => time(),
				'types'         => [
					'image' => (object) [ 'size' => 1024, 'files' => 2 ],
					'video' => (object) [ 'size' => 4096, 'files' => 1 ],
				],
			]
		);

		$html = $this->render();

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Big File Uploads', $html );
	}

	public function test_settings_page_renders_scan_results_with_no_types_recorded() {
		// A finished scan over an empty uploads directory: scan_finished is set but types is not.
		update_site_option( 'tuxbfu_file_scan', [ 'scan_finished' => time() ] );

		$this->assertNotEmpty( $this->render() );
	}

	public function test_settings_page_denies_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->expectException( WPDieException::class );

		$this->render();
	}

	/*
	 * ---------------------------------------------------------------------
	 * The subscribe modal
	 * ---------------------------------------------------------------------
	 */

	public function test_subscribe_modal_shows_when_iu_is_inactive_and_not_dismissed() {
		$this->assertFalse(
			class_exists( 'Infinite_Uploads' ),
			'This suite runs with Infinite Uploads inactive; see phpunit-iu-active.xml.dist for the other case.'
		);

		$this->assertStringContainsString( 'id="subscribe-modal"', $this->render() );
	}

	public function test_subscribe_modal_hides_once_dismissed() {
		update_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed', 1 );

		$this->assertStringNotContainsString( 'id="subscribe-modal"', $this->render() );
	}

	public function test_dismissal_is_per_user() {
		update_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed', 1 );
		$this->assertStringNotContainsString( 'id="subscribe-modal"', $this->render() );

		// A different admin who has not dismissed it should still be shown the modal.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertStringContainsString( 'id="subscribe-modal"', $this->render() );
	}

	public function test_undismiss_query_arg_restores_the_modal() {
		update_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed', 1 );
		$_GET['undismiss'] = '1';

		try {
			$this->assertStringContainsString( 'id="subscribe-modal"', $this->render() );
		} finally {
			unset( $_GET['undismiss'] );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Saving
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Stage a settings form submission.
	 *
	 * @param array $post Fields to merge into $_POST.
	 *
	 * @return void
	 */
	private function submit( array $post ) {
		$_POST['bfu_settings_submit'] = '1';
		$_POST['_wpnonce']            = wp_create_nonce( 'bfu_settings' );

		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}
	}

	public function test_saving_the_all_users_limit_in_gigabytes() {
		$this->submit(
			[
				'upload_limit'        => '3',
				'upload_limit_format' => 'GB',
			]
		);

		$html = $this->render();

		$settings = get_site_option( 'tuxbfu_settings' );
		$this->assertFalse( $settings['by_role'] );
		$this->assertSame( 3 * GB_IN_BYTES, $settings['limits']['all']['bytes'] );
		$this->assertSame( 'GB', $settings['limits']['all']['format'] );
		$this->assertStringContainsString( 'Settings saved!', $html );
	}

	public function test_saving_the_all_users_limit_in_megabytes() {
		$this->submit(
			[
				'upload_limit'        => '512',
				'upload_limit_format' => 'MB',
			]
		);

		$this->render();

		$settings = get_site_option( 'tuxbfu_settings' );
		$this->assertSame( 512 * MB_IN_BYTES, $settings['limits']['all']['bytes'] );
		$this->assertSame( 'MB', $settings['limits']['all']['format'] );
	}

	public function test_saving_per_role_limits() {
		$this->submit(
			[
				'by_role'             => '1',
				'upload_limit'        => [ 'administrator' => '4', 'author' => '250' ],
				'upload_limit_format' => [ 'administrator' => 'GB', 'author' => 'MB' ],
			]
		);

		$html = $this->render();

		$settings = get_site_option( 'tuxbfu_settings' );
		$this->assertTrue( $settings['by_role'] );
		$this->assertSame( 4 * GB_IN_BYTES, $settings['limits']['administrator']['bytes'] );
		$this->assertSame( 250 * MB_IN_BYTES, $settings['limits']['author']['bytes'] );
		$this->assertStringContainsString( 'Settings saved!', $html );
	}

	public function test_saved_limit_is_what_the_upload_size_limit_filter_then_emits() {
		// The full round trip: what the form saves is what uploads are actually held to.
		$this->submit(
			[
				'upload_limit'        => '750',
				'upload_limit_format' => 'MB',
			]
		);
		$this->render();

		$this->assertSame( 750 * MB_IN_BYTES, apply_filters( 'upload_size_limit', 0 ) );
		$this->assertSame( 750 * MB_IN_BYTES, $this->bfu()->get_upload_limit() );
	}

	public function test_a_zero_limit_for_one_role_rejects_the_whole_submission() {
		$this->submit(
			[
				'by_role'             => '1',
				'upload_limit'        => [ 'administrator' => '4', 'author' => '0' ],
				'upload_limit_format' => [ 'administrator' => 'GB', 'author' => 'MB' ],
			]
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Please choose a maximum size for each option.', $html );
		$this->assertFalse( get_site_option( 'tuxbfu_settings' ) );
	}

	/**
	 * Values that are not a usable positive number.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function data_invalid_upload_limits() {
		return [
			'zero'              => [ '0' ],
			'negative'          => [ '-5' ],
			'empty string'      => [ '' ],
			'non numeric'       => [ 'abc' ],
			'numeric with text' => [ '5GB' ],
			'whitespace'        => [ '   ' ],
			'array'             => [ [ 'unexpected' ] ],
		];
	}

	/**
	 * @dataProvider data_invalid_upload_limits
	 *
	 * @param mixed $value Submitted upload_limit.
	 */
	public function test_an_invalid_limit_is_rejected_without_fataling( $value ) {
		/*
		 * 'abc' used to get through: PHP 8 compares a non-numeric string to 0 as a string, so
		 * 'abc' <= 0 is false, and the multiply that followed raised a TypeError. Forging this
		 * needs manage_options, but an admin should still get the validation error, not a fatal.
		 */
		$this->submit(
			[
				'upload_limit'        => $value,
				'upload_limit_format' => 'MB',
			]
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Please choose a maximum size for each option.', $html );
		$this->assertStringNotContainsString( 'Settings saved!', $html );
		$this->assertFalse( get_site_option( 'tuxbfu_settings' ), 'A rejected submission must not be persisted.' );
	}

	public function test_an_invalid_per_role_limit_is_rejected_without_fataling() {
		$this->submit(
			[
				'by_role'             => '1',
				'upload_limit'        => [ 'administrator' => '4', 'author' => 'abc' ],
				'upload_limit_format' => [ 'administrator' => 'GB', 'author' => 'MB' ],
			]
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Please choose a maximum size for each option.', $html );
		$this->assertFalse( get_site_option( 'tuxbfu_settings' ) );
	}

	public function test_a_missing_limit_field_is_rejected_without_fataling() {
		// by_role off, but no upload_limit posted at all.
		$_POST['bfu_settings_submit'] = '1';
		$_POST['_wpnonce']            = wp_create_nonce( 'bfu_settings' );

		$html = $this->render();

		$this->assertStringContainsString( 'Please choose a maximum size for each option.', $html );
		$this->assertFalse( get_site_option( 'tuxbfu_settings' ) );
	}

	public function test_a_fractional_limit_is_accepted() {
		// The field allows steps of 0.1, so 1.5GB is a legitimate submission.
		$this->submit(
			[
				'upload_limit'        => '1.5',
				'upload_limit_format' => 'GB',
			]
		);

		$this->render();

		$settings = get_site_option( 'tuxbfu_settings' );
		$this->assertSame( (int) ( 1.5 * GB_IN_BYTES ), $settings['limits']['all']['bytes'] );
	}

	public function test_saving_with_a_bad_nonce_is_refused() {
		$_POST['bfu_settings_submit'] = '1';
		$_POST['_wpnonce']            = 'not-a-real-nonce';
		$_POST['upload_limit']        = '3';
		$_POST['upload_limit_format'] = 'GB';

		$this->expectException( WPDieException::class );

		try {
			$this->render();
		} finally {
			$this->assertFalse( get_site_option( 'tuxbfu_settings' ) );
		}
	}

	public function test_an_unknown_format_is_treated_as_gigabytes() {
		// The format is a select; anything that isn't the literal "MB" falls through to GB.
		$this->submit(
			[
				'upload_limit'        => '2',
				'upload_limit_format' => 'TB',
			]
		);

		$this->render();

		$settings = get_site_option( 'tuxbfu_settings' );
		$this->assertSame( 2 * GB_IN_BYTES, $settings['limits']['all']['bytes'] );
		$this->assertSame( 'GB', $settings['limits']['all']['format'] );
	}
}
