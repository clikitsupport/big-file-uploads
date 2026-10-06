<?php
/**
 * BigFileUploads Uninstall
 *
 * Uninstalling BigFileUploads deletes all options.
 *
 * @package BigFileUploads
 * @since   1.0.0
 */

/** Check if we are uninstalling. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Delete options. */
delete_option( 'tuxbfu_max_upload_size' );
delete_option( 'tuxbfu_chunk_size' );
delete_option( 'tuxbfu_max_retries' );
delete_option( 'tuxbfu_file_scan' );
delete_option( 'tuxbfu_settings' );
delete_option( 'tuxbfu_digest_history' );
wp_clear_scheduled_hook( 'bfu_send_email_digest' );
delete_user_option( get_current_user_id(), 'bfu_notice_dismissed' );
delete_user_option( get_current_user_id(), 'bfu_upgrade_notice_dismissed' );
delete_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed' );

/** Delete any temp files */
$bfu_temp_dir = apply_filters( 'bfu_temp_dir', WP_CONTENT_DIR . '/bfu-temp' );
$files = glob( $bfu_temp_dir . '/*' );
if ( is_array( $files ) ) {
	foreach ( $files as $file ) {
		@unlink( $file );
	}
}
@rmdir( $bfu_temp_dir );
