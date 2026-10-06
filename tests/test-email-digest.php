<?php
/**
 * Email summary: the period it reports on, when it is sent, what it counts, the history it
 * keeps, and that it stays quiet when there is nothing to say.
 *
 * @package BigFileUploads
 */

/**
 * @testdox Email summary
 *
 * @covers Big_File_Uploads_Email_Digest
 */
class Test_BFU_Email_Digest extends BFU_TestCase {

	public function set_up() {
		parent::set_up();

		update_option( 'timezone_string', 'America/Chicago' );
		reset_phpmailer_instance();
	}

	public function tear_down() {
		update_option( 'timezone_string', '' );
		reset_phpmailer_instance();
		unset( $_POST['digest'] );

		parent::tear_down();
	}

	/**
	 * @return Big_File_Uploads_Email_Digest
	 */
	private function digest() {
		return $this->bfu()->digest;
	}

	/**
	 * @param string $time Site-local date and time.
	 *
	 * @return DateTimeImmutable
	 */
	private function local( $time ) {
		return new DateTimeImmutable( $time, wp_timezone() );
	}

	/**
	 * An attachment dated in site time, with a stored file size.
	 *
	 * @param string   $file
	 * @param string   $local_date
	 * @param int|null $filesize Null leaves the size out of the metadata.
	 *
	 * @return int
	 */
	private function attachment( $file, $local_date, $filesize ) {
		$id = self::factory()->attachment->create_object(
			$file,
			0,
			array(
				'post_mime_type' => wp_check_filetype( $file )['type'],
				'post_date'      => $local_date,
				'post_title'     => $file,
			)
		);

		update_post_meta( $id, '_wp_attachment_metadata', null === $filesize ? array() : array( 'filesize' => $filesize ) );

		return $id;
	}

	/**
	 * @return array[]
	 */
	private function sent_mail() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Which period, and when
	 * ---------------------------------------------------------------------
	 */

	public function test_a_monthly_summary_reports_on_the_previous_calendar_month() {
		$period = $this->digest()->get_period( 'monthly', $this->local( '2026-10-02 09:00' ) );

		$this->assertSame( '2026-09-01 00:00', $period['start']->format( 'Y-m-d H:i' ) );
		$this->assertSame( '2026-10-01 00:00', $period['end']->format( 'Y-m-d H:i' ) );
	}

	public function test_a_weekly_summary_reports_on_the_previous_monday_to_sunday() {
		$period = $this->digest()->get_period( 'weekly', $this->local( '2026-10-07 09:00' ) );

		$this->assertSame( '2026-09-28', $period['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-05', $period['end']->format( 'Y-m-d' ) );
	}

	public function test_a_weekly_summary_sent_on_a_monday_covers_the_week_that_just_ended() {
		$period = $this->digest()->get_period( 'weekly', $this->local( '2026-10-05 09:00' ) );

		$this->assertSame( '2026-09-28', $period['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-05', $period['end']->format( 'Y-m-d' ) );
	}

	public function test_a_daily_summary_reports_on_yesterday() {
		$period = $this->digest()->get_period( 'daily', $this->local( '2026-10-02 09:00' ) );

		$this->assertSame( '2026-10-01 00:00', $period['start']->format( 'Y-m-d H:i' ) );
		$this->assertSame( '2026-10-02 00:00', $period['end']->format( 'Y-m-d H:i' ) );
	}

	public function test_the_next_send_lands_at_nine_in_the_morning_site_time_at_the_start_of_the_next_period() {
		$now = $this->local( '2026-10-07 15:30' );

		$this->assertSame( '2026-11-01 09:00', wp_date( 'Y-m-d H:i', $this->digest()->get_next_send( 'monthly', $now ) ) );
		$this->assertSame( '2026-10-12 09:00', wp_date( 'Y-m-d H:i', $this->digest()->get_next_send( 'weekly', $now ) ) );
		$this->assertSame( '2026-10-08 09:00', wp_date( 'Y-m-d H:i', $this->digest()->get_next_send( 'daily', $now ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scheduling
	 * ---------------------------------------------------------------------
	 */

	public function test_it_is_monthly_by_default() {
		$this->assertSame( 'monthly', $this->digest()->get_frequency() );
	}

	public function test_an_unknown_saved_frequency_falls_back_to_monthly() {
		$this->set_settings( array( 'digest' => 'hourly' ) );

		$this->assertSame( 'monthly', $this->digest()->get_frequency() );
	}

	public function test_admin_init_schedules_the_summary_for_installs_that_never_had_it() {
		$this->assertFalse( wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK ) );

		$this->digest()->maybe_schedule();

		$this->assertSame(
			$this->digest()->get_next_send( 'monthly' ),
			wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK )
		);
	}

	public function test_turning_it_off_removes_the_scheduled_event() {
		$this->digest()->maybe_schedule();
		$this->set_settings( array( 'digest' => 'off' ) );

		$this->digest()->maybe_schedule();

		$this->assertFalse( wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK ) );
	}

	public function test_the_settings_page_shows_when_the_next_summary_goes_out() {
		$this->digest()->maybe_schedule();

		$this->assertStringStartsWith(
			wp_date( get_option( 'date_format' ), wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK ) ),
			$this->digest()->get_next_send_label()
		);

		$this->set_settings( array( 'digest' => 'off' ) );
		$this->assertNull( $this->digest()->get_next_send_label() );
	}

	public function test_it_never_schedules_during_ajax_requests() {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->digest()->maybe_schedule();

		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK ), 'Chunked uploads run through admin-ajax and should not pay for this.' );
	}

	public function test_deactivating_the_plugin_removes_the_scheduled_event() {
		$this->digest()->maybe_schedule();

		Big_File_Uploads_Email_Digest::unschedule();

		$this->assertFalse( wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * What it counts
	 * ---------------------------------------------------------------------
	 */

	public function test_it_counts_only_uploads_dated_inside_the_period() {
		$this->attachment( 'before.jpg', '2026-08-31 23:59:59', 1000 );
		$this->attachment( 'first.jpg', '2026-09-01 00:00:00', 2000 );
		$this->attachment( 'last.jpg', '2026-09-30 23:59:59', 3000 );
		$this->attachment( 'after.jpg', '2026-10-01 00:00:00', 4000 );

		$uploads = $this->digest()->get_period_uploads( $this->local( '2026-09-01 00:00' ), $this->local( '2026-10-01 00:00' ) );

		$this->assertSame( 2, $uploads['count'] );
		$this->assertSame( 5000, $uploads['bytes'] );
	}

	public function test_it_groups_uploads_by_file_type_largest_first() {
		$this->attachment( 'photo.jpg', '2026-09-10 10:00:00', 2 * MB_IN_BYTES );
		$this->attachment( 'clip.mp4', '2026-09-11 10:00:00', 50 * MB_IN_BYTES );
		$this->attachment( 'other-photo.png', '2026-09-12 10:00:00', 3 * MB_IN_BYTES );

		$uploads = $this->digest()->get_period_uploads( $this->local( '2026-09-01' ), $this->local( '2026-10-01' ) );

		$this->assertSame( array( 'video', 'image' ), array_keys( $uploads['types'] ) );
		$this->assertSame( 2, $uploads['types']['image']['count'] );
		$this->assertSame( 5 * MB_IN_BYTES, $uploads['types']['image']['bytes'] );
	}

	public function test_it_lists_the_three_largest_uploads() {
		foreach ( array( 1, 9, 4, 7 ) as $mb ) {
			$this->attachment( "file-{$mb}.pdf", '2026-09-15 10:00:00', $mb * MB_IN_BYTES );
		}

		$uploads = $this->digest()->get_period_uploads( $this->local( '2026-09-01' ), $this->local( '2026-10-01' ) );

		$this->assertSame( array( 'file-9.pdf', 'file-7.pdf', 'file-4.pdf' ), wp_list_pluck( $uploads['largest'], 'title' ) );
	}

	public function test_an_upload_without_a_stored_size_still_counts_toward_the_total() {
		$this->attachment( 'old-upload.jpg', '2026-09-15 10:00:00', null );

		$uploads = $this->digest()->get_period_uploads( $this->local( '2026-09-01' ), $this->local( '2026-10-01' ) );

		$this->assertSame( 1, $uploads['count'] );
		$this->assertSame( array(), $uploads['largest'], 'A file with no measurable size is not a "largest upload".' );
	}

	public function test_library_totals_come_from_the_last_finished_scan_only() {
		$this->assertNull( $this->digest()->get_library() );

		update_site_option(
			'tuxbfu_file_scan',
			array(
				'scan_finished' => 1790000000,
				'types'         => array(
					'image' => (object) array( 'size' => 100, 'files' => 2 ),
					'video' => (object) array( 'size' => 900, 'files' => 1 ),
				),
			)
		);

		$this->assertSame(
			array( 'bytes' => 1000, 'files' => 3, 'scanned' => 1790000000, 'added_since' => 0 ),
			$this->digest()->get_library()
		);
	}

	public function test_library_totals_say_how_many_uploads_the_scan_predates() {
		$scanned = strtotime( '2026-09-15 12:00:00 UTC' );
		update_site_option(
			'tuxbfu_file_scan',
			array(
				'scan_finished' => $scanned,
				'types'         => array( 'image' => (object) array( 'size' => 100, 'files' => 1 ) ),
			)
		);
		$this->attachment( 'before-scan.jpg', get_date_from_gmt( '2026-09-15 11:00:00' ), 10 );
		$this->attachment( 'after-scan.jpg', get_date_from_gmt( '2026-09-15 13:00:00' ), 10 );
		$this->attachment( 'much-later.jpg', get_date_from_gmt( '2026-09-29 13:00:00' ), 10 );

		$library = $this->digest()->get_library();

		$this->assertSame( 2, $library['added_since'] );
		$this->assertStringContainsString( '2 files have been uploaded since', $this->digest()->render( $this->digest()->build_report( 'monthly' ) ) );
	}

	public function test_the_email_never_reports_server_disk_space() {
		$html = $this->digest()->render( $this->digest()->build_report( 'monthly' ) );

		$this->assertStringNotContainsString( 'on your server', $html, 'PHP sees the physical disk, not the account quota, so the figure misleads on shared hosting.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * History and comparison
	 * ---------------------------------------------------------------------
	 */

	public function test_the_history_keeps_only_what_the_next_comparison_needs() {
		for ( $month = 1; $month <= 8; $month++ ) {
			$this->digest()->record_period( $this->digest()->build_report( 'monthly', $this->local( sprintf( '2026-%02d-02', $month + 1 ) ) ) );
		}

		$history = $this->digest()->get_history();

		$this->assertCount( Big_File_Uploads_Email_Digest::HISTORY_LENGTH, $history );
		$this->assertSame( '2026-07-01', wp_date( 'Y-m-d', $history[0]['start'] ) );
		$this->assertSame( '2026-08-01', wp_date( 'Y-m-d', end( $history )['start'] ) );
	}

	public function test_the_history_option_is_not_autoloaded() {
		$this->digest()->record_period( $this->digest()->build_report( 'monthly' ) );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Big_File_Uploads_Email_Digest::HISTORY_OPTION ) );

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_recording_the_same_period_twice_replaces_it() {
		$report = $this->digest()->build_report( 'monthly', $this->local( '2026-10-02' ) );

		$this->digest()->record_period( $report );
		$this->digest()->record_period( $report );

		$this->assertCount( 1, $this->digest()->get_history() );
	}

	public function test_it_compares_with_the_period_directly_before() {
		$this->attachment( 'august.jpg', '2026-08-10 10:00:00', 100 );
		$this->digest()->record_period( $this->digest()->build_report( 'monthly', $this->local( '2026-09-02' ) ) );
		$this->attachment( 'september.jpg', '2026-09-10 10:00:00', 150 );

		$report = $this->digest()->build_report( 'monthly', $this->local( '2026-10-02' ) );

		$this->assertSame( 50, $report['change']['percent'] );
		$this->assertSame( 0, $report['change_files']['percent'], 'One file each month.' );
	}

	public function test_there_is_no_comparison_after_a_gap() {
		$this->attachment( 'june.jpg', '2026-06-10 10:00:00', 100 );
		$this->digest()->record_period( $this->digest()->build_report( 'monthly', $this->local( '2026-07-02' ) ) );

		$report = $this->digest()->build_report( 'monthly', $this->local( '2026-10-02' ) );

		$this->assertNull( $report['change'], 'June is not "last month" for a September summary.' );
	}

	public function test_weekly_history_does_not_feed_a_monthly_comparison() {
		$this->digest()->record_period( $this->digest()->build_report( 'weekly', $this->local( '2026-09-07' ) ) );

		$report = $this->digest()->build_report( 'monthly', $this->local( '2026-10-02' ) );

		$this->assertNull( $report['change'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sending
	 * ---------------------------------------------------------------------
	 */

	public function test_a_period_with_no_uploads_sends_nothing_but_is_still_recorded() {
		$this->digest()->send_scheduled();

		$this->assertCount( 0, $this->sent_mail() );
		$this->assertCount( 1, $this->digest()->get_history(), 'An empty period still counts toward the comparison.' );
		$this->assertNotFalse( wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK ), 'The next summary is still queued.' );
	}

	public function test_a_period_with_uploads_emails_the_site_admin_as_html() {
		$period = $this->digest()->get_period( 'monthly' );
		$this->attachment( 'big-video.mp4', $period['start']->modify( '+2 days' )->format( 'Y-m-d H:i:s' ), 300 * MB_IN_BYTES );

		$this->digest()->send_scheduled();

		$mail = $this->sent_mail();
		$this->assertCount( 1, $mail );
		$this->assertSame( get_option( 'admin_email' ), $mail[0]['to'][0][0] );
		$this->assertStringContainsString( 'text/html', $mail[0]['header'] );
		$this->assertStringContainsString( '1 file', $mail[0]['subject'] );
		$this->assertStringContainsString( 'big-video.mp4', $mail[0]['body'] );
	}

	public function test_the_email_loads_images_only_from_the_site_itself() {
		$period = $this->digest()->get_period( 'monthly' );
		$this->attachment( 'photo.jpg', $period['start']->modify( '+2 days' )->format( 'Y-m-d H:i:s' ), MB_IN_BYTES );

		$html = $this->digest()->render( $this->digest()->build_report( 'monthly' ) );

		preg_match_all( '/<img[^>]+src="([^"]+)"/i', $html, $images );
		$this->assertNotEmpty( $images[1], 'The header logo should render.' );
		foreach ( $images[1] as $src ) {
			$this->assertStringStartsWith( esc_url( plugins_url() ), $src, 'Opening the email must not call out to a third party.' );
		}
		$this->assertDoesNotMatchRegularExpression( '/<link|url\(/i', $html );
	}

	public function test_the_email_links_back_to_the_setting() {
		$html = $this->digest()->render( $this->digest()->build_report( 'monthly' ) );

		$this->assertStringContainsString( esc_url( $this->bfu()->settings_url() . '#bfu-email-summary' ), $html );
	}

	public function test_the_infinite_uploads_offer_shows_while_it_is_not_installed() {
		$report              = $this->digest()->build_report( 'monthly' );
		$report['iu_active'] = false;

		$this->assertStringContainsString( 'utm_content=upsell', $this->digest()->render( $report ) );
	}

	public function test_the_infinite_uploads_offer_is_left_out_when_it_is_already_active() {
		$report              = $this->digest()->build_report( 'monthly' );
		$report['iu_active'] = true;

		$this->assertStringNotContainsString( 'utm_content=upsell', $this->digest()->render( $report ) );
	}

	public function test_the_recipient_can_be_filtered() {
		add_filter(
			'bfu_email_digest_recipients',
			function () {
				return array( 'media@example.com', 'not-an-email' );
			}
		);

		$this->assertSame( array( 'media@example.com' ), $this->digest()->get_recipients() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The setting
	 * ---------------------------------------------------------------------
	 */

	public function test_saving_a_new_frequency_reschedules_the_summary() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->digest()->maybe_schedule();

		$_POST['bfu_settings_submit'] = '1';
		$_POST['_wpnonce']            = wp_create_nonce( 'bfu_settings' );
		$_POST['upload_limit']        = '1';
		$_POST['upload_limit_format'] = 'GB';
		$_POST['digest']              = 'weekly';

		ob_start();
		$this->bfu()->settings_page();
		ob_end_clean();

		$this->assertSame( 'weekly', $this->digest()->get_frequency() );
		$this->assertSame( $this->digest()->get_next_send( 'weekly' ), wp_next_scheduled( Big_File_Uploads_Email_Digest::HOOK ) );
	}

	public function test_the_settings_page_shows_the_frequency_setting() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_settings( array( 'digest' => 'daily' ) );

		ob_start();
		$this->bfu()->settings_page();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<option value="daily"\s+selected/', $html );
	}
}
