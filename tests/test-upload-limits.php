<?php
/**
 * Upload limit resolution: which limit a given user actually gets, and what the plugin emits
 * downstream to plupload and the upload_size_limit filter.
 *
 * @package BigFileUploads
 */

/**
 * @testdox Upload limit resolution
 *
 * @covers BigFileUploads::get_upload_limit
 * @covers BigFileUploads::get_settings
 * @covers BigFileUploads::filter_upload_size_limit
 * @covers BigFileUploads::filter_plupload_settings
 * @covers BigFileUploads::filter_plupload_params
 */
class Test_BFU_Upload_Limits extends BFU_TestCase {

	/**
	 * Settings with a distinct limit per role, so a wrong resolution can't coincidentally pass.
	 *
	 * @param bool $by_role Whether per-role limits are switched on.
	 *
	 * @return array
	 */
	private function seed_role_limits( $by_role ) {
		$settings = [
			'by_role' => $by_role,
			'limits'  => [
				'all'           => [ 'bytes' => 750 * MB_IN_BYTES, 'format' => 'MB' ],
				'administrator' => [ 'bytes' => 5 * GB_IN_BYTES, 'format' => 'GB' ],
				'editor'        => [ 'bytes' => 2 * GB_IN_BYTES, 'format' => 'GB' ],
				'author'        => [ 'bytes' => 100 * MB_IN_BYTES, 'format' => 'MB' ],
			],
		];

		$this->set_settings( $settings );

		return $settings;
	}

	/**
	 * Log in as a new user with the given role.
	 *
	 * @param string $role Role slug.
	 *
	 * @return int User ID.
	 */
	private function login_as( $role ) {
		return $this->login_with_roles( [ $role ] );
	}

	/**
	 * Log in as a new user holding several roles at once.
	 *
	 * @param string[] $roles Role slugs. The first becomes the primary role.
	 *
	 * @return int User ID.
	 */
	private function login_with_roles( array $roles ) {
		$user_id = self::factory()->user->create( [ 'role' => array_shift( $roles ) ] );

		$user = new WP_User( $user_id );
		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}

		// wp_set_current_user() returns early when the ID is unchanged, so log out first or the
		// globally cached user object would be the one from before add_role() ran.
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/*
	 * ---------------------------------------------------------------------
	 * by_role off
	 * ---------------------------------------------------------------------
	 */

	public function test_by_role_off_gives_every_user_the_all_users_limit() {
		$this->seed_role_limits( false );

		// Every one of these roles has its own configured limit, which must be ignored entirely.
		foreach ( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ] as $role ) {
			$this->login_as( $role );

			$this->assertSame(
				750 * MB_IN_BYTES,
				$this->bfu()->get_upload_limit(),
				"A $role should get the all-users limit while by_role is off, not their own."
			);
		}
	}

	public function test_by_role_off_gives_logged_out_visitors_the_all_users_limit() {
		$this->seed_role_limits( false );
		wp_set_current_user( 0 );

		$this->assertSame( 750 * MB_IN_BYTES, $this->bfu()->get_upload_limit() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * by_role on
	 * ---------------------------------------------------------------------
	 */

	public function test_by_role_on_gives_each_role_its_own_limit() {
		$this->seed_role_limits( true );

		$expected = [
			'administrator' => 5 * GB_IN_BYTES,
			'editor'        => 2 * GB_IN_BYTES,
			'author'        => 100 * MB_IN_BYTES,
		];

		foreach ( $expected as $role => $bytes ) {
			$this->login_as( $role );

			$this->assertSame( $bytes, $this->bfu()->get_upload_limit(), "Wrong limit resolved for $role." );
		}
	}

	public function test_user_with_multiple_roles_gets_the_highest_limit() {
		$this->seed_role_limits( true );

		$this->login_with_roles( [ 'author', 'editor' ] ); // 100MB and 2GB

		$this->assertSame(
			2 * GB_IN_BYTES,
			$this->bfu()->get_upload_limit(),
			'A user holding several roles should get the most permissive limit of those roles.'
		);
	}

	public function test_highest_limit_wins_regardless_of_the_order_roles_were_added() {
		$this->seed_role_limits( true );

		// Same two roles as the test above, added in the opposite order. Resolution must not depend
		// on role order, only on the limit values.
		$this->login_with_roles( [ 'editor', 'author' ] );

		$this->assertSame( 2 * GB_IN_BYTES, $this->bfu()->get_upload_limit() );
	}

	public function test_user_with_three_roles_gets_the_highest_limit() {
		$this->seed_role_limits( true );

		$this->login_with_roles( [ 'author', 'administrator', 'editor' ] ); // 100MB, 5GB, 2GB

		$this->assertSame( 5 * GB_IN_BYTES, $this->bfu()->get_upload_limit() );
	}

	public function test_uploadless_role_does_not_drag_a_multi_role_user_down_to_the_fallback() {
		$this->seed_role_limits( true );

		// Subscriber has no configured limit; it must simply not contribute, rather than pulling
		// the user down to the all-users fallback.
		$this->login_with_roles( [ 'subscriber', 'editor' ] );

		$this->assertSame( 2 * GB_IN_BYTES, $this->bfu()->get_upload_limit() );
	}

	public function test_role_without_a_configured_limit_falls_back_to_the_all_users_limit() {
		$this->seed_role_limits( true );

		// Subscriber has no upload_files capability, so get_settings() never assigns it a limit and
		// the by_role loop finds nothing for this user.
		$this->login_as( 'subscriber' );

		$limit = $this->bfu()->get_upload_limit();

		$this->assertSame(
			750 * MB_IN_BYTES,
			$limit,
			'A role with no configured limit should fall back to the all-users limit.'
		);

		// The fallback is a real limit, not "no limit". Guard against a regression that returns 0
		// (which plupload would read as unlimited) or a falsy value.
		$this->assertGreaterThan( 0, $limit );
	}

	public function test_by_role_on_gives_logged_out_visitors_the_all_users_limit() {
		$this->seed_role_limits( true );
		wp_set_current_user( 0 );

		// get_upload_limit() short-circuits on is_user_logged_in().
		$this->assertSame( 750 * MB_IN_BYTES, $this->bfu()->get_upload_limit() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * by_role and by_type together
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Role limits carrying per-type overrides.
	 *
	 * Deliberately crossed over: Author holds the better base limit, Editor the better video
	 * limit. Any resolver that elects one winning role and then reads that role's types can
	 * only ever get one of the two right.
	 *
	 * @return array
	 */
	private function seed_role_type_limits() {
		$settings = [
			'by_role' => true,
			'by_type' => true,
			'limits'  => [
				'all'    => [ 'bytes' => 750 * MB_IN_BYTES, 'format' => 'MB' ],
				'editor' => [
					'bytes'  => 1 * GB_IN_BYTES,
					'format' => 'GB',
					'types'  => [ 'video' => [ 'bytes' => 500 * MB_IN_BYTES, 'format' => 'MB' ] ],
				],
				'author' => [
					'bytes'  => 2 * GB_IN_BYTES,
					'format' => 'GB',
					'types'  => [ 'video' => [ 'bytes' => 50 * MB_IN_BYTES, 'format' => 'MB' ] ],
				],
			],
		];

		$this->set_settings( $settings );

		return $settings;
	}

	public function test_multi_role_user_gets_the_most_permissive_per_type_limit() {
		$this->seed_role_type_limits();

		$this->login_with_roles( [ 'author', 'editor' ] );

		$this->assertSame(
			500 * MB_IN_BYTES,
			$this->bfu()->get_upload_limit( 'webinar.mp4' ),
			'Editor allows 500MB of video, so an Editor who is also an Author must not be held to the Author 50MB.'
		);
	}

	public function test_per_type_resolution_does_not_depend_on_role_order() {
		$this->seed_role_type_limits();

		$this->login_with_roles( [ 'editor', 'author' ] );

		$this->assertSame( 500 * MB_IN_BYTES, $this->bfu()->get_upload_limit( 'webinar.mp4' ) );
	}

	public function test_role_without_an_override_wins_the_type_with_its_base_limit() {
		// Editor leaves video blank, which means video inherits the Editor 1GB. That is more
		// generous than the Author explicit 50MB, so 1GB has to win.
		$this->set_settings( [
			'by_role' => true,
			'by_type' => true,
			'limits'  => [
				'all'    => [ 'bytes' => 750 * MB_IN_BYTES, 'format' => 'MB' ],
				'editor' => [ 'bytes' => 1 * GB_IN_BYTES, 'format' => 'GB' ],
				'author' => [
					'bytes'  => 100 * MB_IN_BYTES,
					'format' => 'MB',
					'types'  => [ 'video' => [ 'bytes' => 50 * MB_IN_BYTES, 'format' => 'MB' ] ],
				],
			],
		] );

		$this->login_with_roles( [ 'author', 'editor' ] );

		$this->assertSame(
			1 * GB_IN_BYTES,
			$this->bfu()->get_upload_limit( 'webinar.mp4' ),
			'A blank per-type field inherits that role base limit, so the role can still win the type with it.'
		);
	}

	public function test_type_nobody_overrides_falls_back_to_the_best_base_limit() {
		$this->seed_role_type_limits();

		$this->login_with_roles( [ 'author', 'editor' ] );

		$this->assertSame(
			2 * GB_IN_BYTES,
			$this->bfu()->get_upload_limit( 'photo.jpg' ),
			'No role overrides images, so the most permissive base limit applies.'
		);
	}

	public function test_single_role_user_is_unaffected_by_another_roles_override() {
		$this->seed_role_type_limits();

		$this->login_as( 'author' );

		$this->assertSame(
			50 * MB_IN_BYTES,
			$this->bfu()->get_upload_limit( 'webinar.mp4' ),
			'An Author on their own is still held to the Author video limit.'
		);
	}

	public function test_browser_type_map_carries_the_most_permissive_limit() {
		$this->seed_role_type_limits();

		$this->login_with_roles( [ 'author', 'editor' ] );

		$map = $this->bfu()->get_type_limit_map();

		// The map is what rejects a file in the browser. If it kept the stricter number the user
		// would be blocked client side no matter what the server would have allowed.
		$this->assertSame( 500 * MB_IN_BYTES, $map['video'] );
		$this->assertArrayNotHasKey( 'image', $map, 'Types nobody overrides are left to the scope limit.' );
	}

	public function test_advertised_ceiling_covers_the_best_limit_across_roles() {
		$this->seed_role_type_limits();

		$this->login_with_roles( [ 'author', 'editor' ] );

		// upload_size_limit has to clear the highest limit any of the user's roles can reach,
		// or core rejects the file before the per-type rule is ever consulted.
		$this->assertSame( 2 * GB_IN_BYTES, $this->bfu()->get_max_upload_limit() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Defaults for missing / malformed stored settings
	 * ---------------------------------------------------------------------
	 */

	public function test_no_stored_settings_defaults_to_the_server_upload_ceiling() {
		delete_site_option( 'tuxbfu_settings' );
		$this->set_max_upload_size( 64 * MB_IN_BYTES );

		$settings = $this->bfu()->get_settings();

		$this->assertFalse( $settings['by_role'], 'by_role should default to off.' );
		$this->assertSame( 64 * MB_IN_BYTES, $settings['limits']['all']['bytes'] );
	}

	public function test_non_array_stored_settings_are_discarded_rather_than_fataling() {
		update_site_option( 'tuxbfu_settings', 'not-an-array' );
		$this->set_max_upload_size( 64 * MB_IN_BYTES );

		$settings = $this->bfu()->get_settings();

		$this->assertIsArray( $settings );
		$this->assertSame( 64 * MB_IN_BYTES, $settings['limits']['all']['bytes'] );
	}

	public function test_every_upload_capable_role_gets_a_default_limit() {
		delete_site_option( 'tuxbfu_settings' );
		$this->set_max_upload_size( 64 * MB_IN_BYTES );

		$settings = $this->bfu()->get_settings();

		foreach ( wp_roles()->roles as $role_key => $role ) {
			$can_upload = ! empty( $role['capabilities']['upload_files'] );

			if ( $can_upload ) {
				$this->assertSame(
					64 * MB_IN_BYTES,
					$settings['limits'][ $role_key ]['bytes'],
					"$role_key can upload, so it should have been given a default limit."
				);
			} else {
				$this->assertArrayNotHasKey(
					$role_key,
					$settings['limits'],
					"$role_key cannot upload, so it should not appear in the limits list."
				);
			}
		}
	}

	public function test_legacy_megabyte_setting_is_migrated_to_bytes() {
		delete_site_option( 'tuxbfu_settings' );
		update_site_option( 'tuxbfu_max_upload_size', 100 ); // 100MB, the pre-2.0 format.

		$settings = $this->bfu()->get_settings();

		$this->assertSame( 100 * MB_IN_BYTES, $settings['limits']['all']['bytes'] );
	}

	public function test_legacy_unlimited_setting_is_migrated_to_five_gigabytes() {
		delete_site_option( 'tuxbfu_settings' );
		update_site_option( 'tuxbfu_max_upload_size', 0 ); // 0 meant "unlimited" pre-2.0.

		$settings = $this->bfu()->get_settings();

		$this->assertSame( 5 * GB_IN_BYTES, $settings['limits']['all']['bytes'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * MB / GB formatting
	 * ---------------------------------------------------------------------
	 */

	public function test_format_round_trips_gigabytes() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 2 * GB_IN_BYTES, 'format' => 'GB' ] ],
			]
		);

		$raw       = $this->bfu()->get_settings();
		$formatted = $this->bfu()->get_settings( true );

		$this->assertSame( 2 * GB_IN_BYTES, $raw['limits']['all']['bytes'], 'Unformatted reads stay in bytes.' );
		$this->assertEquals( 2, $formatted['limits']['all']['bytes'], 'Formatted reads are divided down to the stored unit.' );
		$this->assertSame( 'GB', $formatted['limits']['all']['format'] );
	}

	public function test_format_round_trips_megabytes() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 512 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		$formatted = $this->bfu()->get_settings( true );

		$this->assertEquals( 512, $formatted['limits']['all']['bytes'] );
		$this->assertSame( 'MB', $formatted['limits']['all']['format'] );
	}

	public function test_format_defaults_to_gigabytes_at_one_gigabyte_and_above() {
		$this->set_settings( [ 'limits' => [ 'all' => [ 'bytes' => GB_IN_BYTES ] ] ] );

		$settings = $this->bfu()->get_settings();

		$this->assertSame( 'GB', $settings['limits']['all']['format'] );
	}

	public function test_format_defaults_to_megabytes_below_one_gigabyte() {
		$this->set_settings( [ 'limits' => [ 'all' => [ 'bytes' => GB_IN_BYTES - 1 ] ] ] );

		$settings = $this->bfu()->get_settings();

		$this->assertSame( 'MB', $settings['limits']['all']['format'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * What the plugin emits downstream
	 * ---------------------------------------------------------------------
	 */

	public function test_upload_size_limit_filter_emits_the_stored_limit() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 250 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		$this->assertSame(
			250 * MB_IN_BYTES,
			apply_filters( 'upload_size_limit', 12345 ),
			'The filter must emit the stored setting, ignoring the incoming server value.'
		);

		$this->assertSame(
			250 * MB_IN_BYTES,
			wp_max_upload_size(),
			'wp_max_upload_size() runs the same filter, so core should agree with the setting.'
		);
	}

	public function test_upload_size_limit_filter_tracks_the_current_users_role() {
		$this->seed_role_limits( true );

		$this->login_as( 'editor' );
		$this->assertSame( 2 * GB_IN_BYTES, apply_filters( 'upload_size_limit', 0 ) );

		$this->login_as( 'author' );
		$this->assertSame( 100 * MB_IN_BYTES, apply_filters( 'upload_size_limit', 0 ) );
	}

	public function test_plupload_settings_carry_the_stored_limit_as_max_file_size() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 300 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		$settings = apply_filters( 'plupload_init', [] );

		$this->assertSame(
			( 300 * MB_IN_BYTES ) . 'b',
			$settings['filters']['max_file_size'],
			'plupload must be handed the same limit the upload_size_limit filter reports.'
		);
		$this->assertSame( admin_url( 'admin-ajax.php' ), $settings['url'] );
	}

	public function test_plupload_settings_are_applied_to_the_default_settings_filter_too() {
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 300 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		$settings = apply_filters( 'plupload_default_settings', [] );

		$this->assertSame( ( 300 * MB_IN_BYTES ) . 'b', $settings['filters']['max_file_size'] );
	}

	public function test_plupload_settings_preserve_unrelated_keys() {
		$settings = apply_filters( 'plupload_init', [ 'multi_selection' => true, 'browse_button' => 'x' ] );

		$this->assertTrue( $settings['multi_selection'] );
		$this->assertSame( 'x', $settings['browse_button'] );
	}

	public function test_plupload_settings_declare_chunking() {
		$settings = apply_filters( 'plupload_init', [] );

		// The chunk size is frozen into a constant on first use, so assert against the constant
		// rather than recomputing it - a second test run in the same process can't redefine it.
		$this->assertTrue( defined( 'BIG_FILE_UPLOADS_CHUNK_SIZE_KB' ) );
		$this->assertSame( BIG_FILE_UPLOADS_CHUNK_SIZE_KB . 'kb', $settings['chunk_size'] );
		$this->assertSame( BIG_FILE_UPLOADS_RETRIES, $settings['max_retries'] );

		$this->assertLessThanOrEqual(
			20 * MB_IN_BYTES,
			BIG_FILE_UPLOADS_CHUNK_SIZE_KB * KB_IN_BYTES,
			'Chunks are capped at 20MB to stay under request timeouts.'
		);
	}

	public function test_plupload_params_route_uploads_to_the_chunk_receiver() {
		$params = apply_filters( 'plupload_default_params', [ 'existing' => 'kept' ] );

		$this->assertSame( 'bfu_chunker', $params['action'] );
		$this->assertSame( 'kept', $params['existing'] );
	}

	public function test_gutenberg_is_given_the_unfiltered_server_limit() {
		// BFU's chunking only works through plupload in the media library, so the block editor must
		// keep seeing the real PHP ceiling rather than the (larger) BFU limit.
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 5 * GB_IN_BYTES, 'format' => 'GB' ] ],
			]
		);
		$this->set_max_upload_size( 64 * MB_IN_BYTES );

		$editor_settings = apply_filters( 'block_editor_settings_all', [], null );

		$this->assertSame( 64 * MB_IN_BYTES, $editor_settings['maxUploadFileSize'] );
	}

	public function test_plupload_settings_flag_the_video_notice() {
		$this->login_as( 'administrator' );

		$settings = apply_filters( 'plupload_init', [] );

		$this->assertTrue(
			$settings['filters']['bfu_video_notice'],
			'The uploader needs the flag before it can spot a queued video.'
		);
	}

	public function test_video_notice_is_withheld_from_users_who_cannot_act_on_it() {
		// An author can upload video but cannot install Infinite Uploads, so the nudge is
		// just noise in their way.
		$this->login_as( 'author' );

		$settings = apply_filters( 'plupload_init', [] );

		$this->assertArrayNotHasKey( 'bfu_video_notice', $settings['filters'] );
	}

	public function test_video_notice_can_be_switched_off_by_filter() {
		$this->login_as( 'administrator' );

		add_filter( 'bfu_promote_video_hosting', '__return_false' );
		$settings = apply_filters( 'plupload_init', [] );
		remove_filter( 'bfu_promote_video_hosting', '__return_false' );

		$this->assertArrayNotHasKey( 'bfu_video_notice', $settings['filters'] );
	}

	public function test_video_notice_never_blocks_the_upload_limit_filters() {
		// The notice rides alongside the size filters; it must not disturb them.
		$this->login_as( 'administrator' );
		$this->set_settings(
			[
				'by_role' => false,
				'limits'  => [ 'all' => [ 'bytes' => 300 * MB_IN_BYTES, 'format' => 'MB' ] ],
			]
		);

		$settings = apply_filters( 'plupload_init', [] );

		$this->assertSame( ( 300 * MB_IN_BYTES ) . 'b', $settings['filters']['max_file_size'] );
	}
}
