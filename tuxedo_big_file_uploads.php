<?php
/**
 * Plugin Name: Big File Uploads
 * Description: Enable large file uploads in the built-in WordPress media uploader via multipart uploads, and set maximum upload file size to any value based on user role. Uploads can be as large as available disk space allows.
 * Version:     2.1.9
 * Author:      Infinite Uploads
 * Author URI:  https://infiniteuploads.com/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_content=meta
 * Network:     true
 * License:     GPLv2 or later
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tests up to: 7.0
 * Text Domain: tuxedo-big-file-uploads
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright 2021-2025 ClikIT, LLC
 *
 * @package BigFileUploads
 * @version 2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die();
}

define( 'BIG_FILE_UPLOADS_VERSION', '2.1.9' );

if ( ! defined( 'BIG_FILE_UPLOADS_PLUGIN_URL' ) ) {
    define( 'BIG_FILE_UPLOADS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Big File Uploads manager class.
 *
 * Bootstraps the plugin by hooking into plupload defaults and
 * media settings.
 *
 * @since 1.0.0
 */
class BigFileUploads {

    /**
     * BigFileUploads instance.
     *
     * @since  1.0.0
     * @access private
     * @static
     * @var BigFileUploads
     */
    private static $instance = false;

    /**
     * The API server.
     *
     * @var string (URL)
     */
    public $server_root = 'https://infiniteuploads.com/';
    protected $capability;
    protected $max_upload_size;
    public $ajax_timelimit = 20;

    /**
     * Get the instance.
     *
     * Returns the current instance, creates one if it
     * doesn't exist. Ensures only one instance of
     * BigFileUploads is loaded or can be loaded.
     *
     * @return BigFileUploads
     * @since 1.0.0
     * @static
     *
     */
    public static function get_instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;

    }

    /**
     * Constructor.
     *
     * Initializes and adds functions to filter and action hooks.
     *
     * @since 1.0.0
     */
    public function __construct() {
        //save default before we filter it
        $this->max_upload_size = wp_max_upload_size();
        register_activation_hook( __FILE__, array( $this, 'on_plugin_activation' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_notices', array( $this, 'init_review_notice' ) );
        add_filter( 'plupload_init', array( $this, 'filter_plupload_settings' ) );
        add_filter( 'upload_post_params', array( $this, 'filter_plupload_params' ) );
        add_filter( 'plupload_default_settings', array( $this, 'filter_plupload_settings' ) );
        add_filter( 'plupload_default_params', array( $this, 'filter_plupload_params' ) );
        add_filter( 'upload_size_limit', array( $this, 'filter_upload_size_limit' ) );
        //add_filter( 'ext2type', array( $this, 'filter_ext_types' ) );
        add_action( 'wp_ajax_bfu_chunker', array( $this, 'ajax_chunk_receiver' ) );
        add_action( 'post-upload-ui', array( $this, 'upload_output' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'gutenberg_notice' ) );
        add_filter( 'block_editor_settings_all', array( $this, 'gutenberg_size_filter' ) );


        //single site
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_filter( 'plugin_action_links_tuxedo-big-file-uploads/tuxedo_big_file_uploads.php', [
                $this,
                'plugins_list_links',
        ] );

        //multisite
        add_action( 'network_admin_menu', [ $this, 'admin_menu' ] );
        add_filter( 'network_admin_plugin_action_links_tuxedo-big-file-uploads/tuxedo_big_file_uploads.php', [
                $this,
                'plugins_list_links',
        ] );

        if ( is_main_site() ) {
            add_action( 'wp_ajax_bfu_file_scan', [ $this, 'ajax_file_scan' ] );
            add_action( 'wp_ajax_bfu_upload_dismiss', [ $this, 'ajax_upload_dismiss' ] );
            add_action( 'wp_ajax_bfu_upgrade_dismiss', [ $this, 'ajax_upgrade_dismiss' ] );
            add_action( 'wp_ajax_bfu_subscribe_dismiss', [ $this, 'ajax_subscribe_dismiss' ] );
        }

        if ( is_multisite() ) {
            add_action( 'network_admin_notices', [ $this, 'upgrade_notice' ] );
        } else {
            add_action( 'admin_notices', [ $this, 'upgrade_notice' ] );
        }

        require_once dirname( __FILE__ ) . '/classes/class-file-scan.php';

        /**
         * Filters the capability that is checked for access to Big File Uploads settings page.
         *
         * @param  {string}  $capability  The capability checked for access and editing settings. Default `manage_network_options` or `manage_options` depending on if multisite.
         *
         * @return {string}  $capability  The capability checked for access and editing settings.
         * @since  1.0
         * @hook   big_file_uploads_settings_capability
         *
         */
        $this->capability = apply_filters( 'big_file_uploads_settings_capability', ( is_multisite() ? 'manage_network_options' : 'manage_options' ) );
    }

    /**
     * Save the plugin install time
     *
     * @since 2.1.7
     */
    public function on_plugin_activation() {
        update_option( 'tuxedo_big_file_uploads_install_time', time(), '', false );
    }

    /**
     * Filter plupload params.
     *
     * @since 1.2.0
     */
    public function filter_plupload_params( $plupload_params ) {
        $plupload_params['action'] = 'bfu_chunker';

        return $plupload_params;

    }

    /**
     * Filter plupload settings.
     *
     * @since 1.0.0
     */
    public function filter_plupload_settings( $plupload_settings ) {
        $max_chunk = ( MB_IN_BYTES * 20 ); //20MB max chunk size (to avoid timeouts)
        if ( $max_chunk > $this->max_upload_size ) {
            $default_chunk = ( $this->max_upload_size * 0.8 ) / KB_IN_BYTES;
        } else {
            $default_chunk = $max_chunk / KB_IN_BYTES;
        }

        if ( ! defined( 'BIG_FILE_UPLOADS_CHUNK_SIZE_KB' ) ) {
            define( 'BIG_FILE_UPLOADS_CHUNK_SIZE_KB', $default_chunk );
        }

        if ( ! defined( 'BIG_FILE_UPLOADS_RETRIES' ) ) {
            define( 'BIG_FILE_UPLOADS_RETRIES', 1 );
        }

        $plupload_settings['url']                      = admin_url( 'admin-ajax.php' );
        $plupload_settings['filters']['max_file_size'] = $this->filter_upload_size_limit( '' ) . 'b';
        $plupload_settings['chunk_size']               = BIG_FILE_UPLOADS_CHUNK_SIZE_KB . 'kb';
        $plupload_settings['max_retries']              = BIG_FILE_UPLOADS_RETRIES;

        return $plupload_settings;
    }

    /**
     * Load Localization files.
     *
     * @since 1.0.0
     */
    public function load_textdomain() {
        $domain = 'tuxedo-big-file-uploads';
        $locale = apply_filters( 'plugin_locale', get_locale(), $domain );
        load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
        load_plugin_textdomain( $domain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );


        require_once dirname( __FILE__ ) . '/classes/class-promo-notice.php';

        // Initialize the promotion system
        $promo = new BFFU_Promo_Notice();

        $promo->add_notice( [
                'id'      => 'iu_enhanced_folder_management',
                'title'   => 'Scale Your WordPress Media Library. Upgrade to Infinite Uploads',
                'message' => 'Infinite Uploads adds folders, smart organization, cloud storage, CDN delivery, and media scalability - Start 7 Day Free Trial',
                'type'    => 'info',
                'delay_days' => 10,
                'buttons' => [
                        'link' => [
                                'text'   => 'Try for Free →',
                                'action' => 'link',
                                'link'   => $this->api_url( '/pricing/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_content=admin_notice&utm_term=try_for_free' ),
                                'type' => 'primary',
                        ],

                        'maybe_later' => [
                                'text'   => 'Remind Me Later',
                                'action' => 'delay',
                        ],
                ],
        ] );
    }

    /**
     * Load Localization files.
     *
     * @since 1.0.0
     */
    public function init_review_notice() {
        require_once dirname( __FILE__ ) . '/classes/class-review-notice.php';

        // Setup notice.
        $notice = Big_File_Uploads_Review_Notice::get(
                'tuxedo-big-file-uploads', // Plugin slug on wp.org (eg: hello-dolly).
                __( 'Big File Uploads', 'tuxedo-big-file-uploads' ), // Plugin name (eg: Hello Dolly).
                array(
                        'days'    => 14,
                        'screens' => [ 'plugins', 'settings_page_big_file_uploads', 'upload' ],
                        'cap'     => 'install_plugins',
                        'domain'  => 'tuxedo-big-file-uploads',
                        'prefix'  => 'bfu',
                ) // Notice options.
        );

        // Render notice.
        $notice->render();


    }

    /**
     * Return max upload size.
     *
     * Free space of temp directory.
     *
     * @return int $bytes Free disk space in bytes.
     * @since 1.0.0
     *
     */
    public function filter_upload_size_limit( $unused ) {
        return $this->get_upload_limit();
    }

    /**
     * Free space of temp directory.
     *
     * @return false|int $bytes Free disk space in bytes.
     * @since 2.0
     *
     */
    public function temp_available_size() {
        if ( function_exists( 'disk_free_space' ) ) {
            $bytes = disk_free_space( sys_get_temp_dir() );
        } else {
            $bytes = false;
        }

        return $bytes;
    }

    /**
     * Add the js to Gutenberg to add our custom upload size notice.
     *
     * @return void
     */
    function gutenberg_notice() {
        wp_enqueue_script(
                'bfu-block-upload-notice',
                plugin_dir_url( __FILE__ ) . 'assets/js/block-notice.js',
                [ 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-editor' ],
                BIG_FILE_UPLOADS_VERSION
        );

        wp_set_script_translations( 'bfu-block-upload-notice', 'tuxedo-big-file-uploads' );
    }

    /**
     * Always pass the original size limit to Gutenberg so it can show our error (BFU only works inside media library via plupload).
     *
     * @param $editor_settings
     *
     * @return mixed
     */
    function gutenberg_size_filter( $editor_settings ) {
        $editor_settings['maxUploadFileSize'] = $this->max_upload_size;

        return $editor_settings;
    }

    /**
     * Enqueue html on the upload form.
     *
     * @since 2.0
     */
    public function upload_output() {
        global $pagenow;
        if ( ! current_user_can( $this->capability ) || is_null( $pagenow ) || ! in_array( $pagenow, array(
                        'post-new.php',
                        'post.php',
                        'upload.php',
                        'media-new.php',
                ) ) ) {
            return;
        }
        ?>
        <script type="text/javascript">
			//When each chunk is uploaded, check if there were any errors or not and stop the rest
			jQuery(function () {
				if (typeof uploader !== 'undefined') {
					uploader.bind('ChunkUploaded', function (up, file, response) {
						//Stop the upload!
						if (response.status === 202) {
							up.removeFile(file);
							uploadSuccess(file, response.response);
						}
					});
				}
			});

			jQuery(".max-upload-size").append(' <small><a style="text-decoration:none;" href="<?php echo esc_url( $this->settings_url() ); ?>"><?php esc_html_e( 'Change', 'tuxedo-big-file-uploads' ); ?></a></small>');
            <?php
            $dismissed = get_user_option( 'bfu_notice_dismissed', get_current_user_id() );
            if ( ! $this->is_infinite_uploads_active() && ! $dismissed ) {
            ?>
			(function ($) {
				'use strict';
				$(".max-upload-size").after('<span class="bfu-upload-notice"><p class="small"><?php esc_html_e( 'Want unlimited storage space, CDN, video hosting, folders, and enhanced media library search?', 'tuxedo-big-file-uploads' ); ?> <a href="<?php echo esc_url( $this->settings_url() ); ?>#upgrade-modal"><?php esc_html_e( 'Move your media files to the Infinite Uploads cloud', 'tuxedo-big-file-uploads' ); ?>.</a></p><a style="width:12px;height:12px;font-size:12px;vertical-align:middle;" class="dashicons dashicons-no" title="<?php esc_attr_e( 'Dismiss', 'tuxedo-big-file-uploads' ); ?>" href="#"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss', 'tuxedo-big-file-uploads' ); ?></span></a></span>');
				$(function () {
					var $notice = $('.bfu-upload-notice');
					$notice.children('a.dashicons').on('click', function (event, el) {
						$.get(ajaxurl + '?action=bfu_upload_dismiss');
						$notice.hide();
					});
				});
			})(jQuery);
            <?php
            }
            ?>
        </script>
        <?php
    }

    /**
     * Output one time upgrade notice to previous users.
     *
     * @since 2.0
     */
    public function upgrade_notice() {
        if ( ! current_user_can( $this->capability ) ) {
            return;
        }
        $old_max_upload_size = get_site_option( 'tuxbfu_max_upload_size' );
        if ( $old_max_upload_size !== false ) {
            $dismissed = get_user_option( 'bfu_upgrade_notice_dismissed', get_current_user_id() );
            if ( ! $dismissed ) {
                ?>
                <script>
					(function ($) {
						'use strict';
						$(function () {
							$('.bfu-upgrade-notice').on('click', '.notice-dismiss', function (event, el) {
								$.get(ajaxurl + '?action=bfu_upgrade_dismiss');
							});
						});
					})(jQuery);
                </script>
                <div class="bfu-upgrade-notice notice notice-info is-dismissible">
                    <p><?php
                        _e( 'Tuxedo Big File Uploads has a new maintainer and <strong>new free features</strong>!', 'tuxedo-big-file-uploads' ); ?>
                        <a
                                href="<?php
                                echo esc_url( $this->settings_url() ); ?>"><?php
                            esc_html_e( 'Review your upload settings now, configure different limits per role, or analyze your uploads storage usage.', 'tuxedo-big-file-uploads' ); ?></a>
                    </p>
                </div>
                <?php
            }
        }
    }

    /**
     * AJAX endpoint to dismiss upload page notice.
     *
     * @since 2.0
     */
    public function ajax_upload_dismiss() {
        if ( ! current_user_can( $this->capability ) ) {
            wp_send_json_error();
        }

        update_user_option( get_current_user_id(), 'bfu_notice_dismissed', 1 );

        wp_send_json_success();
    }

    /**
     * AJAX endpoint to dismiss upgrade notice.
     *
     * @since 2.0
     */
    public function ajax_upgrade_dismiss() {
        if ( ! current_user_can( $this->capability ) ) {
            wp_send_json_error();
        }

        update_user_option( get_current_user_id(), 'bfu_upgrade_notice_dismissed', 1 );

        wp_send_json_success();
    }

    /**
     * AJAX endpoint to dismiss subscribe notice.
     *
     * @since 2.0
     */
    public function ajax_subscribe_dismiss() {
        if ( ! current_user_can( $this->capability ) ) {
            wp_send_json_error();
        }

        update_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed', 1 );

        wp_send_json_success();
    }

    /**
     * AJAX chunk receiver.
     *
     * Ajax callback for plupload to handle chunked uploads.
     * Based on code by Davit Barbakadze
     * https://gist.github.com/jayarjo/5846636
     *
     * Mirrors /wp-admin/async-upload.php
     *
     * @since 1.2.0
     * @todo  Figure out a way to stop furthur chunks from uploading when there is an error in gutenberg
     *
     */
    public function ajax_chunk_receiver() {
        /** Check that we have an upload and there are no errors. */
        if ( empty( $_FILES ) || $_FILES['async-upload']['error'] ) {
            /** Failed to move uploaded file. */
            wp_die();
        }

        /** Authenticate user. */
        if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
            wp_die( __( 'Sorry, you are not allowed to upload files.' ) );
        }
        check_admin_referer( 'media-form' );

        /** Check and get file chunks. */
        $chunk        = isset( $_REQUEST['chunk'] ) ? intval( $_REQUEST['chunk'] ) : 0; //zero index
        $current_part = $chunk + 1;
        $chunks       = isset( $_REQUEST['chunks'] ) ? intval( $_REQUEST['chunks'] ) : 0;

        /** Get file name and path + name. */
        $fileName = isset( $_REQUEST['name'] ) ? $_REQUEST['name'] : $_FILES['async-upload']['name'];


        $bfu_temp_dir = $this->temp_dir();

        //only run on first chunk
        if ( $chunk === 0 ) {
            $this->prepare_temp_dir( $bfu_temp_dir );

            //scan temp dir for files older than 24 hours and delete them.
            $this->cleanup_stale_chunks( $bfu_temp_dir );
        }

        $filePath = $this->chunk_path( $fileName, $bfu_temp_dir );

        //debugging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $size = file_exists( $filePath ) ? size_format( filesize( $filePath ), 3 ) : '0 B';
            error_log( "BFU: Processing \"$fileName\" part $current_part of $chunks as $filePath. $size processed so far." );
        }

        $tuxbfu_max_upload_size = $this->get_upload_limit();
        if ( file_exists( $filePath ) && filesize( $filePath ) + filesize( $_FILES['async-upload']['tmp_name'] ) > $tuxbfu_max_upload_size ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "BFU: File size limit exceeded." );
            }

            if ( ! $chunks || $chunk == $chunks - 1 ) {
                @unlink( $filePath );

                $this->send_upload_error( __( 'The file size has exceeded the maximum file size setting.', 'tuxedo-big-file-uploads' ), $fileName );
            }

            wp_die();
        }

        /** Append this chunk to the temp file. */
        $appended = $this->append_chunk( $filePath, $_FILES['async-upload']['tmp_name'], $chunk );

        if ( is_wp_error( $appended ) ) {
            if ( 'bfu_input_stream' === $appended->get_error_code() ) {
                error_log( "BFU: Error reading uploaded part $current_part of $chunks." );
                $message = sprintf( __( 'There was an error reading uploaded part %d of %d.', 'tuxedo-big-file-uploads' ), $current_part, $chunks );
            } else {
                error_log( "BFU: Failed to open output stream $filePath to write part $current_part of $chunks." );
                $message = $appended->get_error_message();
            }

            $this->send_upload_error( $message, $fileName );
        }

        /** Check if file has finished uploading all parts. */
        if ( ! $chunks || $chunk == $chunks - 1 ) {
            //debugging
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $size = file_exists( $filePath ) ? size_format( filesize( $filePath ), 3 ) : '0 B';
                error_log( "BFU: Completing \"$fileName\" upload with a $size final size." );
            }

            /** Recreate upload in $_FILES global and pass off to WordPress. */
            $_FILES['async-upload']['tmp_name'] = $filePath;
            $_FILES['async-upload']['name']     = $fileName;
            $_FILES['async-upload']['size']     = filesize( $_FILES['async-upload']['tmp_name'] );
            $wp_filetype                        = wp_check_filetype_and_ext( $_FILES['async-upload']['tmp_name'], $_FILES['async-upload']['name'] );
            $_FILES['async-upload']['type']     = $wp_filetype['type'];

            header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );

            if ( ! isset( $_REQUEST['short'] ) || ! isset( $_REQUEST['type'] ) ) { //ajax like media uploader in modal

                // Compatibility with Easy Digital Downloads plugin.
                if ( function_exists( 'edd_change_downloads_upload_dir' ) ) {
                    global $pagenow;
                    $pagenow = 'async-upload.php';
                    edd_change_downloads_upload_dir();
                }

                send_nosniff_header();
                nocache_headers();

                $this->wp_ajax_upload_attachment();
                wp_die( '0' );

            } else { //non-ajax like add new media page
                $post_id = 0;
                if ( isset( $_REQUEST['post_id'] ) ) {
                    $post_id = absint( $_REQUEST['post_id'] );
                    if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
                        $post_id = 0;
                    }
                }

                $id = media_handle_upload( 'async-upload', $post_id, [], [
                        'action'    => 'wp_handle_sideload',
                        'test_form' => false,
                ] );
                if ( is_wp_error( $id ) ) {
                    printf(
                            '<div class="error-div error">%s <strong>%s</strong><br />%s</div>',
                            sprintf(
                                    '<button type="button" class="dismiss button-link" onclick="jQuery(this).parents(\'div.media-item\').slideUp(200, function(){jQuery(this).remove();});">%s</button>',
                                    __( 'Dismiss' )
                            ),
                            sprintf(
                            /* translators: %s: Name of the file that failed to upload. */
                                    __( '&#8220;%s&#8221; has failed to upload.' ),
                                    esc_html( $_FILES['async-upload']['name'] )
                            ),
                            esc_html( $id->get_error_message() )
                    );
                    wp_die();
                }

                if ( $_REQUEST['short'] ) {
                    // Short form response - attachment ID only.
                    echo $id;
                } else {
                    // Long form response - big chunk of HTML.
                    $type = $_REQUEST['type'];

                    /**
                     * Filters the returned ID of an uploaded attachment.
                     *
                     * The dynamic portion of the hook name, `$type`, refers to the attachment type.
                     *
                     * Possible hook names include:
                     *
                     *  - `async_upload_audio`
                     *  - `async_upload_file`
                     *  - `async_upload_image`
                     *  - `async_upload_video`
                     *
                     * @param  int  $id  Uploaded attachment ID.
                     *
                     * @since 2.5.0
                     *
                     */
                    echo apply_filters( "async_upload_{$type}", $id );
                }

            }

        }

        wp_die();
    }

    /**
     * Get the directory chunks are assembled in while an upload is in progress.
     *
     * @return string Absolute path, no trailing slash.
     * @since 2.1.9
     *
     */
    public function temp_dir() {
        return apply_filters( 'bfu_temp_dir', WP_CONTENT_DIR . '/bfu-temp' );
    }

    /**
     * Get the temp file path an upload's chunks are assembled into.
     *
     * Keyed by blog ID and filename hash so concurrent uploads of different files, and uploads of
     * the same filename on different sites of a network, never share a temp file.
     *
     * @param  string       $file_name  The name of the file being uploaded.
     * @param  string|null  $temp_dir   Optional. Defaults to temp_dir().
     *
     * @return string Absolute path to the `.part` file.
     * @since 2.1.9
     *
     */
    public function chunk_path( $file_name, $temp_dir = null ) {
        if ( null === $temp_dir ) {
            $temp_dir = $this->temp_dir();
        }

        return sprintf( '%s/%d-%s.part', $temp_dir, get_current_blog_id(), sha1( $file_name ) );
    }

    /**
     * Create the temp directory if needed and protect it from browsing.
     *
     * @param  string  $temp_dir  Directory to create.
     *
     * @return void
     * @since 2.1.9
     *
     */
    public function prepare_temp_dir( $temp_dir ) {
        // Create temp directory if it doesn't exist
        if ( ! @is_dir( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        // Protect temp directory from browsing.
        $index_pathname = $temp_dir . '/index.php';
        if ( ! file_exists( $index_pathname ) ) {
            $file = fopen( $index_pathname, 'w' );
            if ( false !== $file ) {
                fwrite( $file, "<?php\n// Silence is golden.\n" );
                fclose( $file );
            }
        }
    }

    /**
     * Delete abandoned chunk files left behind by uploads that never completed.
     *
     * Only sweeps files that have not been written to for `$max_age`, so an upload that is still
     * streaming chunks is never collected out from under itself.
     *
     * @param  string  $temp_dir  Directory to sweep.
     * @param  int     $max_age   Optional. Age in seconds past which a `.part` is stale. Default 24 hours.
     *
     * @return string[] Paths that were deleted.
     * @since 2.1.9
     *
     */
    public function cleanup_stale_chunks( $temp_dir, $max_age = DAY_IN_SECONDS ) {
        $deleted = [];

        $files = glob( $temp_dir . '/*.part' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                if ( @filemtime( $file ) < time() - $max_age ) {
                    if ( @unlink( $file ) ) {
                        $deleted[] = $file;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Append one uploaded chunk to the assembled temp file.
     *
     * Chunk 0 truncates any existing temp file so a restarted upload can't append onto a stale one.
     * Every later chunk requires the temp file to already exist and be writable, so a chunk that
     * arrives out of order (or after a cleanup sweep) errors instead of creating a partial file.
     *
     * @param  string  $file_path  The assembled temp file to append to.
     * @param  string  $tmp_name   The uploaded chunk's temp path (from $_FILES).
     * @param  int     $chunk      Zero-indexed chunk number.
     *
     * @return true|WP_Error True on success. WP_Error code `bfu_output_stream` if the temp file
     *                       could not be opened, `bfu_input_stream` if the chunk could not be read.
     * @since 2.1.9
     *
     */
    public function append_chunk( $file_path, $tmp_name, $chunk ) {
        /** Open temp file. */
        if ( 0 == $chunk ) {
            $out = @fopen( $file_path, 'wb' );
        } elseif ( is_writable( $file_path ) ) {
            $out = @fopen( $file_path, 'ab' );
        } else {
            $out = false;
        }

        if ( ! $out ) {
            /** Failed to open output stream. */
            return new WP_Error(
                    'bfu_output_stream',
                    __( 'There was an error opening the temp file for writing. Available temp directory space may be exceeded or the temp file was cleaned up before the upload completed.', 'tuxedo-big-file-uploads' )
            );
        }

        /** Read binary input stream and append it to temp file. */
        $in = @fopen( $tmp_name, 'rb' );

        if ( ! $in ) {
            /** Failed to open input stream. */
            /** Attempt to clean up unfinished output. */
            @fclose( $out );
            @unlink( $file_path );

            return new WP_Error( 'bfu_input_stream', __( 'There was an error reading the uploaded part.', 'tuxedo-big-file-uploads' ) );
        }

        while ( ! feof( $in ) ) {
            $buff = fread( $in, 4096 );

            /*
             * Test against false, not falsiness: a chunk whose final read is the single byte "0"
             * is a valid read that evaluates false, and would silently truncate the file.
             */
            if ( false === $buff ) {
                @fclose( $in );
                @fclose( $out );
                @unlink( $file_path );

                return new WP_Error( 'bfu_input_stream', __( 'There was an error reading the uploaded part.', 'tuxedo-big-file-uploads' ) );
            }

            fwrite( $out, $buff );
        }

        @fclose( $in );
        @fclose( $out );
        @unlink( $tmp_name );

        return true;
    }

    /**
     * Render an upload failure back to plupload and end the request.
     *
     * Responds as JSON for the modal media uploader, or as an HTML error div with a 202 status for
     * the non-ajax add-new-media page (the 202 is what tells our JS to stop sending chunks).
     *
     * @param  string  $message    The error to display.
     * @param  string  $file_name  The file that failed.
     *
     * @return void This function does not return, it ends the request.
     * @since 2.1.9
     *
     */
    protected function send_upload_error( $message, $file_name ) {
        if ( ! isset( $_REQUEST['short'] ) || ! isset( $_REQUEST['type'] ) ) { //ajax like media uploader in modal
            echo wp_json_encode(
                    array(
                            'success' => false,
                            'data'    => array(
                                    'message'  => $message,
                                    'filename' => esc_html( $file_name ),
                            ),
                    )
            );

            wp_die();
        }

        status_header( 202 );
        printf(
                '<div class="error-div error">%s <strong>%s</strong><br />%s</div>',
                sprintf(
                        '<button type="button" class="dismiss button-link" onclick="jQuery(this).parents(\'div.media-item\').slideUp(200, function(){jQuery(this).remove();});">%s</button>',
                        __( 'Dismiss' )
                ),
                sprintf(
                /* translators: %s: Name of the file that failed to upload. */
                        __( '&#8220;%s&#8221; has failed to upload.' ),
                        esc_html( $file_name )
                ),
                $message
        );

        wp_die();
    }

    /**
     * Copied from wp-admin/includes/ajax-actions.php because we have to override the args for
     * the media_handle_upload function. As of WP 6.0.1
     */
    function wp_ajax_upload_attachment() {
        check_ajax_referer( 'media-form' );
        /*
         * This function does not use wp_send_json_success() / wp_send_json_error()
         * as the html4 Plupload handler requires a text/html content-type for older IE.
         * See https://core.trac.wordpress.org/ticket/31037
         */

        if ( ! current_user_can( 'upload_files' ) ) {
            echo wp_json_encode(
                    array(
                            'success' => false,
                            'data'    => array(
                                    'message'  => __( 'Sorry, you are not allowed to upload files.' ),
                                    'filename' => esc_html( $_FILES['async-upload']['name'] ),
                            ),
                    )
            );

            wp_die();
        }

        if ( isset( $_REQUEST['post_id'] ) ) {
            $post_id = $_REQUEST['post_id'];

            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                echo wp_json_encode(
                        array(
                                'success' => false,
                                'data'    => array(
                                        'message'  => __( 'Sorry, you are not allowed to attach files to this post.' ),
                                        'filename' => esc_html( $_FILES['async-upload']['name'] ),
                                ),
                        )
                );

                wp_die();
            }
        } else {
            $post_id = null;
        }

        $post_data = ! empty( $_REQUEST['post_data'] ) ? _wp_get_allowed_postdata( _wp_translate_postdata( false, (array) $_REQUEST['post_data'] ) ) : array();

        if ( is_wp_error( $post_data ) ) {
            wp_die( $post_data->get_error_message() );
        }

        // If the context is custom header or background, make sure the uploaded file is an image.
        if ( isset( $post_data['context'] ) && in_array( $post_data['context'], array(
                        'custom-header',
                        'custom-background',
                ), true ) ) {
            $wp_filetype = wp_check_filetype_and_ext( $_FILES['async-upload']['tmp_name'], $_FILES['async-upload']['name'] );

            if ( ! wp_match_mime_types( 'image', $wp_filetype['type'] ) ) {
                echo wp_json_encode(
                        array(
                                'success' => false,
                                'data'    => array(
                                        'message'  => __( 'The uploaded file is not a valid image. Please try again.' ),
                                        'filename' => esc_html( $_FILES['async-upload']['name'] ),
                                ),
                        )
                );

                wp_die();
            }
        }

        //this is the modded function from wp-admin/includes/ajax-actions.php
        $attachment_id = media_handle_upload( 'async-upload', $post_id, $post_data, [
                'action'    => 'wp_handle_sideload',
                'test_form' => false,
        ] );

        if ( is_wp_error( $attachment_id ) ) {
            echo wp_json_encode(
                    array(
                            'success' => false,
                            'data'    => array(
                                    'message'  => $attachment_id->get_error_message(),
                                    'filename' => esc_html( $_FILES['async-upload']['name'] ),
                            ),
                    )
            );

            wp_die();
        }

        if ( isset( $post_data['context'] ) && isset( $post_data['theme'] ) ) {
            if ( 'custom-background' === $post_data['context'] ) {
                update_post_meta( $attachment_id, '_wp_attachment_is_custom_background', $post_data['theme'] );
            }

            if ( 'custom-header' === $post_data['context'] ) {
                update_post_meta( $attachment_id, '_wp_attachment_is_custom_header', $post_data['theme'] );
            }
        }

        $attachment = wp_prepare_attachment_for_js( $attachment_id );
        if ( ! $attachment ) {
            wp_die();
        }

        echo wp_json_encode(
                array(
                        'success' => true,
                        'data'    => $attachment,
                )
        );

        wp_die();
    }

    /**
     * Return the maximum upload limit in bytes for the current user.
     *
     * @return integer
     * @since 2.0
     *
     */
    function get_upload_limit() {
        $settings = $this->get_settings();

        if ( $settings['by_role'] && is_user_logged_in() ) {
            $limit = 0;
            $user  = wp_get_current_user();
            foreach ( (array) $user->roles as $role ) {
                if ( isset( $settings['limits'][ $role ]['bytes'] ) && $settings['limits'][ $role ]['bytes'] > $limit ) { //choose the highest limit for the roles they have.
                    $limit = $settings['limits'][ $role ]['bytes'];
                }
            }
            if ( $limit ) {
                return $limit;
            } else {
                return $settings['limits']['all']['bytes'];
            }
        } else {
            return $settings['limits']['all']['bytes'];
        }
    }

    /**
     * Return a cleaned up settings array with defaults if needed.
     *
     * @param  false  $format
     *
     * @return array
     * @since 2.0
     *
     */
    function get_settings( $format = false ) {
        $settings = get_site_option( 'tuxbfu_settings' );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        if ( ! isset( $settings['by_role'] ) ) {
            $settings['by_role'] = false;
        }

        if ( ! isset( $settings['limits']['all']['bytes'] ) ) {
            $old_max_upload_size = get_site_option( 'tuxbfu_max_upload_size' );
            if ( $old_max_upload_size ) {
                $settings['limits']['all']['bytes'] = $old_max_upload_size * MB_IN_BYTES;
            } elseif ( $old_max_upload_size === 0 ) {
                $settings['limits']['all']['bytes'] = GB_IN_BYTES * 5; //default to 5GB if they had unlimited set before
            } else {
                $settings['limits']['all']['bytes'] = $this->max_upload_size;
            }
        }
        if ( ! isset( $settings['limits']['all']['format'] ) ) {
            $settings['limits']['all']['format'] = $settings['limits']['all']['bytes'] >= GB_IN_BYTES ? 'GB' : 'MB';
        }

        foreach ( wp_roles()->roles as $role_key => $role ) {
            if ( isset( $role['capabilities']['upload_files'] ) && $role['capabilities']['upload_files'] ) {
                if ( ! isset( $settings['limits'][ $role_key ]['bytes'] ) ) {
                    $settings['limits'][ $role_key ]['bytes'] = $this->max_upload_size;
                }
                if ( ! isset( $settings['limits'][ $role_key ]['format'] ) ) {
                    $settings['limits'][ $role_key ]['format'] = $settings['limits'][ $role_key ]['bytes'] >= GB_IN_BYTES ? 'GB' : 'MB';
                }
            }
        }

        if ( $format ) {
            foreach ( $settings['limits'] as $role_key => $value ) {
                $divisor                                  = ( $value['format'] == 'MB' ? MB_IN_BYTES : GB_IN_BYTES );
                $settings['limits'][ $role_key ]['bytes'] = round( $value['bytes'] / $divisor, 1 );
            }
        }

        return $settings;
    }

    /**
     * Adds settings links to plugin row.
     *
     * @since 2.0
     */
    function plugins_list_links( $actions ) {
        // Build and escape the URL.
        $url = esc_url( $this->settings_url() );

        // Create the link.
        $custom_links             = [];
        $custom_links['settings'] = "<a href='$url'>" . esc_html__( 'Settings', 'tuxedo-big-file-uploads' ) . '</a>';
        $custom_links['support']  = '<a href="' . esc_url( $this->api_url( '/support/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_term=support&utm_content=meta' ) ) . '">' . esc_html__( 'Support', 'tuxedo-big-file-uploads' ) . '</a>';
        $custom_links['upgrade']  = '<a href="' . esc_url( $this->api_url( '/pricing/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_term=go_pro&utm_content=meta' ) ) . '" aria-label="' . esc_attr__( 'Go Pro', 'tuxedo-big-file-uploads' ) . '" style="color: #93003f;" target="_blank"><b>' . esc_html__( 'Go Pro', 'tuxedo-big-file-uploads' ) . '</b></a>';

        // Adds the links to the beginning of the array.
        return array_merge( $custom_links, $actions );
    }

    /**
     * Check a submitted upload limit is a usable positive number.
     *
     * The field is a number input, so a bad value means a hand-crafted request. `$value <= 0` alone
     * does not catch it: PHP 8 compares a non-numeric string against 0 as a string, so 'abc' passes
     * that test and then fatals with a TypeError on the multiply.
     *
     * @param  mixed  $value  The raw submitted value.
     *
     * @return bool
     * @since 2.1.9
     *
     */
    protected function is_valid_upload_limit( $value ) {
        return is_numeric( $value ) && $value > 0;
    }

    /**
     * Get the settings url with optional url args.
     *
     * @param  array  $args  Optional. Same as for add_query_arg()
     *
     * @return string Unescaped url to settings page.
     * @since 2.0
     *
     */
    function settings_url( $args = [] ) {
        if ( is_multisite() ) {
            $base = network_admin_url( 'settings.php?page=big_file_uploads' );
        } else {
            $base = admin_url( 'options-general.php?page=big_file_uploads' );
        }

        return add_query_arg( $args, $base );
    }

    /**
     * Get a url to the public Infinite Uploads site.
     *
     * @param  string  $path  Optional path on the site.
     *
     * @return string
     * @since 2.0
     *
     */
    function api_url( $path = '' ) {
        $url = trailingslashit( $this->server_root );

        if ( $path && is_string( $path ) ) {
            $url .= ltrim( $path, '/' );
        }

        return $url;
    }

    /**
     * Registers a new settings page under Settings.
     *
     * @since 2.0
     */
    function admin_menu() {
        if ( is_multisite() ) {
            $page = add_submenu_page(
                    'settings.php',
                    __( 'Big File Uploads', 'tuxedo-big-file-uploads' ),
                    __( 'Big File Uploads', 'tuxedo-big-file-uploads' ),
                    $this->capability,
                    'big_file_uploads',
                    [
                            $this,
                            'settings_page',
                    ]
            );
        } else {
            $page = add_options_page(
                    __( 'Big File Uploads', 'tuxedo-big-file-uploads' ),
                    __( 'Big File Uploads', 'tuxedo-big-file-uploads' ),
                    $this->capability,
                    'big_file_uploads',
                    [
                            $this,
                            'settings_page',
                    ]
            );
        }

        add_action( 'admin_print_scripts-' . $page, [ $this, 'admin_scripts' ] );
        add_action( 'admin_print_styles-' . $page, [ $this, 'admin_styles' ] );
    }

    /**
     * Script enqueues for the settings page.
     *
     * @since 2.0
     */
    function admin_scripts() {
        // Version admin.js by file mtime so JS edits bust the browser cache even
        // when the plugin version is unchanged.
        $bfu_admin_js     = plugin_dir_path( __FILE__ ) . 'assets/js/admin.js';
        $bfu_admin_js_ver = file_exists( $bfu_admin_js ) ? filemtime( $bfu_admin_js ) : BIG_FILE_UPLOADS_VERSION;

        wp_enqueue_script( 'bfu-bootstrap', plugins_url( 'assets/bootstrap/js/bootstrap.bundle.min.js', __FILE__ ), [ 'jquery' ], BIG_FILE_UPLOADS_VERSION );
        wp_enqueue_script( 'bfu-chartjs', plugins_url( 'assets/js/Chart.min.js', __FILE__ ), [], BIG_FILE_UPLOADS_VERSION );
        wp_enqueue_script( 'bfu-js', plugins_url( 'assets/js/admin.js', __FILE__ ), [
                'bfu-bootstrap',
                'bfu-chartjs',
        ], $bfu_admin_js_ver );

        $data                        = [];
        $data['strings']             = [
                'leave_confirm'      => esc_html__( 'Are you sure you want to leave this tab? The current bulk action will be canceled and you will need to continue where it left off later.', 'tuxedo-big-file-uploads' ),
                'ajax_error'         => esc_html__( 'Too many server errors. Please try again.', 'tuxedo-big-file-uploads' ),
                'leave_confirmation' => esc_html__( 'If you leave this page the sync will be interrupted and you will have to continue where you left off later.', 'tuxedo-big-file-uploads' ),
        ];
        $data['local_types']         = $this->get_filetypes( true );
        $data['default_upload_size'] = $this->max_upload_size;

        wp_localize_script( 'bfu-js', 'bfu_data', $data );

        //disable one time upgrade notice
        $dismissed = get_user_option( 'bfu_upgrade_notice_dismissed', get_current_user_id() );
        if ( ! $dismissed ) {
            update_user_option( get_current_user_id(), 'bfu_upgrade_notice_dismissed', 1 );
        }
    }

    /**
     * Styles for the settings page.
     *
     * @since 2.0
     */
    function admin_styles() {
        // Version admin.css by file mtime so CSS edits bust the browser cache
        // even when the plugin version is unchanged.
        $bfu_admin_css     = plugin_dir_path( __FILE__ ) . 'assets/css/admin.css';
        $bfu_admin_css_ver = file_exists( $bfu_admin_css ) ? filemtime( $bfu_admin_css ) : BIG_FILE_UPLOADS_VERSION;

        wp_enqueue_style( 'tuxbfu-bootstrap', plugins_url( 'assets/bootstrap/css/bootstrap.min.css', __FILE__ ), false, BIG_FILE_UPLOADS_VERSION );
        wp_enqueue_style( 'tuxbfu-styles', plugins_url( 'assets/css/admin.css', __FILE__ ), [ 'tuxbfu-bootstrap' ], $bfu_admin_css_ver );
    }

    /**
     * Settings page display callback.
     *
     * @since 2.0
     */
    function settings_page() {
        // check caps
        if ( ! current_user_can( $this->capability ) ) {
            wp_die( esc_html__( 'Permissions Error: Please refresh the page and try again.', 'tuxedo-big-file-uploads' ) );
        }

        $save_error = $save_success = false;
        if ( isset( $_POST['bfu_settings_submit'] ) ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'bfu_settings' ) ) {
                wp_die( esc_html__( 'Permissions Error: Please refresh the page and try again.', 'tuxedo-big-file-uploads' ) );
            }

            $settings = $this->get_settings();
            if ( isset( $_POST['by_role'] ) ) {
                foreach ( wp_roles()->roles as $role_key => $role ) {
                    if ( isset( $role['capabilities']['upload_files'] ) && $role['capabilities']['upload_files'] && isset( $_POST['upload_limit'][ $role_key ] ) ) {
                        if ( ! $this->is_valid_upload_limit( $_POST['upload_limit'][ $role_key ] ) ) {
                            $save_error = true;
                        } else {
                            $format                                   = isset( $_POST['upload_limit_format'][ $role_key ] ) ? $_POST['upload_limit_format'][ $role_key ] : 'GB';
                            $settings['limits'][ $role_key ]['bytes']  = absint( $_POST['upload_limit'][ $role_key ] * ( $format == 'MB' ? MB_IN_BYTES : GB_IN_BYTES ) );
                            $settings['limits'][ $role_key ]['format'] = ( $format == 'MB' ? 'MB' : 'GB' );
                        }
                    }
                }
                $settings['by_role'] = true;
            } else {
                if ( ! isset( $_POST['upload_limit'] ) || ! $this->is_valid_upload_limit( $_POST['upload_limit'] ) ) {
                    $save_error = true;
                } else {
                    $format                              = isset( $_POST['upload_limit_format'] ) ? $_POST['upload_limit_format'] : 'GB';
                    $settings['limits']['all']['bytes']  = absint( $_POST['upload_limit'] * ( $format == 'MB' ? MB_IN_BYTES : GB_IN_BYTES ) );
                    $settings['limits']['all']['format'] = ( $format == 'MB' ? 'MB' : 'GB' );
                }
                $settings['by_role'] = false;
            }
            if ( ! $save_error ) {
                update_site_option( 'tuxbfu_settings', $settings );
                $save_success = true;
            }
        }
        ?>
        <div id="container" class="wrap bfu-background">
            <h1>
                <img src="<?php
                echo esc_url( plugins_url( '/assets/img/bfu-logo-sm.png', __FILE__ ) ); ?>" alt="Big File Uploads Logo"
                     height="50"/> <?php
                esc_html_e( 'Big File Uploads', 'tuxedo-big-file-uploads' ); ?>
            </h1>

            <?php
            //for testing
            if ( isset( $_GET['undismiss'] ) ) {
                delete_user_option( get_current_user_id(), 'bfu_notice_dismissed' );
                delete_user_option( get_current_user_id(), 'bfu_upgrade_notice_dismissed' );
                delete_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed' );
            }

            if ( $save_success ) {
                ?>
                <div class="alert alert-success mt-2 alert-dismissible fade show" role="alert">
                    <?php
                    esc_html_e( 'Settings saved!', 'tuxedo-big-file-uploads' ); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php
            }

            if ( $save_error ) { ?>
                <div class="alert alert-danger mt-2 alert-dismissible fade show" role="alert">
                    <?php
                    esc_html_e( 'Please choose a maximum size for each option.', 'tuxedo-big-file-uploads' ); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php
            } ?>

            <div id="bfu-error" class="alert alert-danger mt-1" role="alert"></div>

            <?php
            $settings = $this->get_settings( true );
            // These templates only emit markup, so they use require, not require_once - the page
            // must render in full every time it is called, not just the first time per request.
            require( dirname( __FILE__ ) . '/templates/settings.php' );

            if ( ! $this->is_infinite_uploads_active() ) {
                $scan_results = get_site_option( 'tuxbfu_file_scan' );
                if ( isset( $scan_results['scan_finished'] ) && $scan_results['scan_finished'] ) {
                    if ( isset( $scan_results['types'] ) ) {
                        $total_files   = array_sum( wp_list_pluck( $scan_results['types'], 'files' ) );
                        $total_storage = array_sum( wp_list_pluck( $scan_results['types'], 'size' ) );
                    } else {
                        $total_files   = 0;
                        $total_storage = 0;
                    }
                    require( dirname( __FILE__ ) . '/templates/scan-results.php' );
                } else {
                    require( dirname( __FILE__ ) . '/templates/scan-start.php' );
                }

                $this->render_upsell_bar();
            }
            ?>
        </div>
        <?php
        require( dirname( __FILE__ ) . '/templates/footer.php' );

        if ( ! $this->is_infinite_uploads_active() ) {
            require( dirname( __FILE__ ) . '/templates/modal-scan.php' );

            $dismissed = get_user_option( 'bfu_subscribe_notice_dismissed', get_current_user_id() );
            if ( ! $dismissed ) {
                require( dirname( __FILE__ ) . '/templates/modal-subscribe.php' );
            }
        }

        require( dirname( __FILE__ ) . '/templates/modal-upgrade.php' );
    }

    function get_filetypes_list() {
        $extensions = array_keys( wp_get_mime_types() );
        $list       = [];
        foreach ( array_keys( wp_get_ext_types() ) as $key ) {
            $list[ $key ] = [];
        }

        foreach ( $extensions as $extension ) {
            $type = wp_ext2type( explode( '|', $extension )[0] );
            if ( $type ) {
                $list[ $type ][ $extension ] = [ 'label' => str_replace( '|', '/', $extension ), 'custom' => false ];
            } else {
                $list['other'][ $extension ] = [ 'label' => str_replace( '|', '/', $extension ), 'custom' => false ];
            }
        }

        $list['image']['heif']     = [ 'label' => 'heif', 'custom' => true ];
        $list['image']['webp']     = [ 'label' => 'webp', 'custom' => true ];
        $list['image']['svg|svgz'] = [ 'label' => 'svg/svgz', 'custom' => true ];
        $list['image']['apng']     = [ 'label' => 'apng', 'custom' => true ];
        $list['image']['avif']     = [ 'label' => 'avif', 'custom' => true ];

        $list['interactive']['keynote'] = [ 'label' => 'keynote', 'custom' => true ];

        return $list;
    }

    /**
     * Add to the list of common file extensions and their types.
     *
     * @return array[] Multi-dimensional array of file extensions types keyed by the type of file.
     */
    function filter_ext_types( $types ) {
        return array_merge_recursive( $types, array(
                        'image'       => array( 'webp', 'svg', 'svgz', 'apng', 'avif' ),
                        'audio'       => array( 'ra', 'ram', 'mid', 'midi', 'wax' ),
                        'video'       => array( 'webm', 'wmx', 'wm' ),
                        'document'    => array(
                                'wri',
                                'mpp',
                                'dotx',
                                'onetoc',
                                'onetoc2',
                                'onetmp',
                                'onepkg',
                                'odg',
                                'odc',
                                'odf',
                        ),
                        'spreadsheet' => array(
                                'odb',
                                'xla',
                                'xls',
                                'xlt',
                                'xlw',
                                'mdb',
                                'xltx',
                                'xltm',
                                'xlam',
                                'odb',
                        ),
                        'interactive' => array( 'pot', 'potx', 'potm', 'ppam' ),
                        'text'        => array(
                                'ics',
                                'rtx',
                                'vtt',
                                'dfxp',
                                'log',
                                'conf',
                                'text',
                                'def',
                                'list',
                                'ini',
                        ),
                        'application' => array( 'class', 'exe' ),
                )
        );
    }

    /**
     * AJAX handler for the filesystem scanner popup.
     *
     * @since 2.0
     */
    public function ajax_file_scan() {
        // check caps
        if ( ! current_user_can( $this->capability ) ) {
            wp_send_json_error( esc_html__( 'Permissions Error: Please refresh the page and try again.', 'tuxedo-big-file-uploads' ) );
        }

        $path = $this->get_upload_dir_root();

        $remaining_dirs = [];
        //validate path is within uploads dir to prevent path traversal
        if ( isset( $_POST['remaining_dirs'] ) && is_array( $_POST['remaining_dirs'] ) ) {
            foreach ( $_POST['remaining_dirs'] as $dir ) {
                $realpath = realpath( $path . $dir );
                if ( 0 === strpos( $realpath, $path ) ) { //check that parsed path begins with upload dir
                    $remaining_dirs[] = $dir;
                }
            }
        }

        $file_scan = new Big_File_Uploads_File_Scan( $path, $this->ajax_timelimit, $remaining_dirs );
        $file_scan->start();
        $file_count     = number_format_i18n( $file_scan->get_total_files() );
        $file_size      = size_format( $file_scan->get_total_size(), 2 );
        $remaining_dirs = $file_scan->paths_left;
        $is_done        = $file_scan->is_done;

        $data = compact( 'file_count', 'file_size', 'is_done', 'remaining_dirs' );

        wp_send_json_success( $data );
    }

    /**
     * Resolve the Infinite Uploads install / activate / configure action.
     *
     * Mirrors the logic in templates/modal-upgrade.php so the upsell surfaces the
     * same one-click flow the plugin already uses for Infinite Uploads (the cloud
     * offloading successor to Big File Uploads):
     *  - not installed  -> core plugin installer (install-plugin)
     *  - installed only -> activation link
     *  - active         -> Infinite Uploads settings screen
     *
     * @return array|null Array of url/label/external, or null when the user cannot install plugins.
     */
    /**
     * Whether the Infinite Uploads plugin is installed AND active.
     *
     * Detection deliberately covers several signals. Infinite Uploads 3.x moved its main
     * class into the \ClikIT\InfiniteUploads namespace, so the legacy
     * class_exists( 'Infinite_Uploads' ) test returns false on current versions and every
     * upsell guard keyed off it fails open. The constant and bootstrap function are defined
     * as soon as the plugin file loads, so they are the dependable signals; the class checks
     * remain for older releases and for the test suite's stub.
     *
     * @return bool
     * @since 2.1.9
     */
    public function is_infinite_uploads_active() {
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'infinite-uploads/infinite-uploads.php' ) ) {
            return true;
        }

        return defined( 'INFINITE_UPLOADS_VERSION' )
               || function_exists( 'infinite_uploads_init' )
               || class_exists( 'Infinite_Uploads' )
               || class_exists( '\\ClikIT\\InfiniteUploads\\InfiniteUploads' );
    }

    public function get_infinite_uploads_action() {
        if ( ! current_user_can( 'install_plugins' ) ) {
            return null;
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file       = 'infinite-uploads/infinite-uploads.php';
        $installed_plugins = get_plugins();

        if ( array_key_exists( $plugin_file, $installed_plugins ) ) {
            if ( $this->is_infinite_uploads_active() ) {
                // 3.x namespaced its classes, so the legacy settings_url() helper may be absent.
                $iu_settings_url = class_exists( 'Infinite_Uploads_Admin' )
                    ? Infinite_Uploads_Admin::get_instance()->settings_url()
                    : self_admin_url( 'admin.php?page=infinite_uploads' );

                return array(
                    'url'      => $iu_settings_url,
                    'label'    => __( 'Configure Infinite Uploads', 'tuxedo-big-file-uploads' ),
                    'external' => false,
                );
            }

            return array(
                'url'      => wp_nonce_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin_file ), 'activate-plugin_' . $plugin_file ),
                'label'    => __( 'Activate Infinite Uploads', 'tuxedo-big-file-uploads' ),
                'external' => false,
            );
        }

        return array(
            'url'      => wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=infinite-uploads' ), 'install-plugin_infinite-uploads' ),
            'label'    => __( 'Install Infinite Uploads', 'tuxedo-big-file-uploads' ),
            'external' => false,
        );
    }

    /**
     * Render the Infinite Uploads feature upsell grid.
     *
     * Rendered beneath the storage analysis section. Clicking a feature card opens
     * a modal describing that feature with a branded "Install Infinite Uploads"
     * call to action.
     */
    public function render_upsell_bar() {
        // Feather Icons (MIT) rendered inline so no extra assets are required.
        $features = array(
            array(
                'title'    => __( 'Cloud Storage', 'tuxedo-big-file-uploads' ),
                'desc'     => __( 'Store your media securely in the cloud.', 'tuxedo-big-file-uploads' ),
                'heading'  => __( 'Offload your media to the cloud with Infinite Uploads', 'tuxedo-big-file-uploads' ),
                'subtitle' => __( 'Try Infinite Uploads for free for 7 days and reduce server load while improving performance.', 'tuxedo-big-file-uploads' ),
                'icon'     => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
            ),
            array(
                'title'    => __( 'Folders', 'tuxedo-big-file-uploads' ),
                'desc'     => __( 'Organize your media files with folders.', 'tuxedo-big-file-uploads' ),
                'heading'  => __( 'Organize your media library with smart folders', 'tuxedo-big-file-uploads' ),
                'subtitle' => __( 'Try Infinite Uploads for free for 7 days and manage your media files with ease.', 'tuxedo-big-file-uploads' ),
                'icon'     => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            ),
            array(
                'title'    => __( 'Smart Organization', 'tuxedo-big-file-uploads' ),
                'desc'     => __( 'Drag & drop media to keep your library organized.', 'tuxedo-big-file-uploads' ),
                'heading'  => __( 'Keep your media library effortlessly organized', 'tuxedo-big-file-uploads' ),
                'subtitle' => __( 'Try Infinite Uploads for free for 7 days and drag & drop your media into order.', 'tuxedo-big-file-uploads' ),
                'icon'     => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
            ),
            array(
                'title'    => __( 'CDN Delivery', 'tuxedo-big-file-uploads' ),
                'desc'     => __( 'Deliver media via global CDN for faster sites.', 'tuxedo-big-file-uploads' ),
                'heading'  => __( 'Deliver your media faster worldwide with Global CDN', 'tuxedo-big-file-uploads' ),
                'subtitle' => __( 'Try Infinite Uploads for free for 7 days and serve your media through a global CDN.', 'tuxedo-big-file-uploads' ),
                'icon'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            ),
            array(
                'title'    => __( 'Advanced Search', 'tuxedo-big-file-uploads' ),
                'desc'     => __( 'Find the right media instantly with advanced search.', 'tuxedo-big-file-uploads' ),
                'heading'  => __( 'Find any file instantly with advanced media search', 'tuxedo-big-file-uploads' ),
                'subtitle' => __( 'Try Infinite Uploads for free for 7 days and locate the right media in seconds.', 'tuxedo-big-file-uploads' ),
                'icon'     => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            ),
            array(
                'title'    => __( 'Media Scalability', 'tuxedo-big-file-uploads' ),
                'desc'     => __( 'Handle unlimited growth without limits.', 'tuxedo-big-file-uploads' ),
                'heading'  => __( 'Scale your media storage without limits', 'tuxedo-big-file-uploads' ),
                'subtitle' => __( 'Try Infinite Uploads for free for 7 days and handle unlimited growth effortlessly.', 'tuxedo-big-file-uploads' ),
                'icon'     => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
            ),
        );

        $action     = $this->get_infinite_uploads_action();
        $learn_more = $this->api_url( '/pricing/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_content=feature_modal&utm_term=upgrade' );

        // Official Infinite Uploads brand marks (bundled with the plugin).
        $iu_logo_mark = plugins_url( 'assets/img/iu-logo-blue.svg', __FILE__ );
        $iu_wordmark  = plugins_url( 'assets/img/iu-logo-words.svg', __FILE__ );
        ?>
        <div class="bfu-upsell-grid">
            <?php foreach ( $features as $index => $feature ) : ?>
                <button type="button" class="bfu-upsell-card" data-bfu-feature="<?php echo esc_attr( $index ); ?>" aria-haspopup="dialog">
                    <span class="bfu-upsell-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $feature['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG path markup. ?></svg>
                    </span>
                    <span class="bfu-upsell-text">
                        <span class="bfu-upsell-title"><?php echo esc_html( $feature['title'] ); ?></span>
                        <span class="bfu-upsell-desc"><?php echo esc_html( $feature['desc'] ); ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="bfu-cloud-banner">
            <span class="bfu-cloud-banner__badge" aria-hidden="true">
                <img src="<?php echo esc_url( $iu_logo_mark ); ?>" alt="" width="38" height="29" />
            </span>
            <span class="bfu-cloud-banner__art" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/></svg>
            </span>
            <span class="bfu-cloud-banner__content">
                <span class="bfu-cloud-banner__title"><?php esc_html_e( 'You can move your storage to Cloud on Infinite Uploads', 'tuxedo-big-file-uploads' ); ?></span>
                <span class="bfu-cloud-banner__subtitle"><?php esc_html_e( 'Try out Infinite Uploads for Free for 7 days and upload your storage to the cloud.', 'tuxedo-big-file-uploads' ); ?></span>
                <a class="bfu-cloud-banner__cta" href="<?php echo esc_url( $this->api_url( '/pricing/?utm_source=bfu_plugin&utm_medium=plugin&utm_campaign=bfu_plugin&utm_content=cloud_banner&utm_term=start_free' ) ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Start Free', 'tuxedo-big-file-uploads' ); ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </span>
            <button type="button" class="bfu-cloud-banner__dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'tuxedo-big-file-uploads' ); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <?php
        // The feature modal, dismiss handler and click wiring only need to exist once.
        static $singletons_printed = false;
        if ( $singletons_printed ) {
            return;
        }
        $singletons_printed = true;

        // Feature data for the modal, keyed by the card index. Only presentational
        // text and the icon differ per feature; the install action is shared.
        $modal_features = array();
        foreach ( $features as $index => $feature ) {
            $modal_features[ $index ] = array(
                'heading'  => $feature['heading'],
                'subtitle' => $feature['subtitle'],
                'icon'     => $feature['icon'],
            );
        }
        ?>
        <div class="bfu-feature-modal" id="bfu-feature-modal" aria-hidden="true">
            <div class="bfu-feature-modal__overlay" data-bfu-close></div>
            <div class="bfu-feature-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bfu-feature-modal-heading">
                <button type="button" class="bfu-feature-modal__close" data-bfu-close aria-label="<?php esc_attr_e( 'Close', 'tuxedo-big-file-uploads' ); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <img class="bfu-feature-modal__brand" src="<?php echo esc_url( $iu_wordmark ); ?>" alt="Infinite Uploads" width="132" height="33" />
                <span class="bfu-feature-modal__icon" id="bfu-feature-modal-icon" aria-hidden="true"></span>
                <h2 class="bfu-feature-modal__heading" id="bfu-feature-modal-heading"></h2>
                <p class="bfu-feature-modal__subtitle" id="bfu-feature-modal-subtitle"></p>
                <div class="bfu-feature-modal__actions">
                    <?php if ( $action ) : ?>
                        <a class="btn text-nowrap btn-primary btn-lg" href="<?php echo esc_url( $action['url'] ); ?>" role="button"<?php echo $action['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
                            <?php echo esc_html( $action['label'] ); ?>
                        </a>
                    <?php else : ?>
                        <a class="btn text-nowrap btn-primary btn-lg" href="<?php echo esc_url( $learn_more ); ?>" role="button" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Learn More About Infinite Uploads', 'tuxedo-big-file-uploads' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <p class="bfu-feature-modal__note">
                    <?php
                        // translators: %s is a cloud icon.
                        printf( esc_html__( 'Get 7 days of %s storage, bandwidth, media folders, and more for FREE. Plans starting at just $8.25/mo.', 'tuxedo-big-file-uploads' ), '<span class="dashicons dashicons-cloud" aria-hidden="true"></span>' );
                    ?>
                </p>
            </div>
        </div>
        <script>
        ( function () {
            var FEATURES = <?php echo wp_json_encode( $modal_features ); ?>;

            var modal      = document.getElementById( 'bfu-feature-modal' );
            var iconEl     = document.getElementById( 'bfu-feature-modal-icon' );
            var headingEl  = document.getElementById( 'bfu-feature-modal-heading' );
            var subtitleEl = document.getElementById( 'bfu-feature-modal-subtitle' );
            var lastFocus  = null;

            // Move the modal to <body> so a hidden ancestor never hides the fixed modal.
            if ( modal && modal.parentNode !== document.body ) {
                document.body.appendChild( modal );
            }

            function openModal( key ) {
                var data = FEATURES[ key ];
                if ( ! data || ! modal ) {
                    return;
                }
                iconEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + data.icon + '</svg>';
                headingEl.textContent  = data.heading;
                subtitleEl.textContent = data.subtitle;
                modal.classList.add( 'is-open' );
                modal.setAttribute( 'aria-hidden', 'false' );
                document.body.classList.add( 'bfu-modal-open' );
                var cta = modal.querySelector( '.bfu-feature-modal__actions .btn' );
                if ( cta ) {
                    cta.focus();
                }
            }

            function closeModal() {
                if ( ! modal ) {
                    return;
                }
                modal.classList.remove( 'is-open' );
                modal.setAttribute( 'aria-hidden', 'true' );
                document.body.classList.remove( 'bfu-modal-open' );
                if ( lastFocus && lastFocus.focus ) {
                    lastFocus.focus();
                }
            }

            // Cloud banner dismissal (persisted per browser).
            var BANNER_KEY = 'bfuCloudBannerDismissed';
            function hideBanners() {
                var banners = document.querySelectorAll( '.bfu-cloud-banner' );
                for ( var i = 0; i < banners.length; i++ ) {
                    banners[ i ].style.display = 'none';
                }
            }
            try {
                if ( window.localStorage && localStorage.getItem( BANNER_KEY ) === '1' ) {
                    hideBanners();
                }
            } catch ( err ) {}

            document.addEventListener( 'click', function ( e ) {
                if ( ! e.target.closest ) {
                    return;
                }

                var card = e.target.closest( '[data-bfu-feature]' );
                if ( card ) {
                    e.preventDefault();
                    lastFocus = card;
                    openModal( card.getAttribute( 'data-bfu-feature' ) );
                    return;
                }

                if ( e.target.closest( '[data-bfu-close]' ) ) {
                    e.preventDefault();
                    closeModal();
                    return;
                }

                if ( e.target.closest( '.bfu-cloud-banner__dismiss' ) ) {
                    try {
                        if ( window.localStorage ) {
                            localStorage.setItem( BANNER_KEY, '1' );
                        }
                    } catch ( err ) {}
                    hideBanners();
                }
            } );

            document.addEventListener( 'keydown', function ( e ) {
                if ( e.key === 'Escape' && modal && modal.classList.contains( 'is-open' ) ) {
                    closeModal();
                }
            } );
        } )();
        </script>
        <?php
    }

    /**
     * Get data array of filescan results.
     *
     * @param  false  $is_chart  If data should be formatted for chart.
     *
     * @return array
     * @since 2.0
     *
     */
    public function get_filetypes( $is_chart = false ) {
        $results = get_site_option( 'tuxbfu_file_scan' );
        if ( isset( $results['types'] ) ) {
            $types = $results['types'];
        } else {
            $types = [];
        }

        $data = [];
        foreach ( $types as $type => $meta ) {
            $data[ $type ] = (object) [
                    'color' => $this->get_file_type_format( $type, 'color' ),
                    'label' => $this->get_file_type_format( $type, 'label' ),
                    'size'  => $meta->size,
                    'files' => $meta->files,
            ];
        }

        $chart = [];
        if ( $is_chart ) {
            foreach ( $data as $item ) {
                $chart['datasets'][0]['data'][]            = $item->size;
                $chart['datasets'][0]['backgroundColor'][] = $item->color;
                $chart['labels'][]                         = $item->label . ": " . sprintf( _n( '%s file totalling %s', '%s files totalling %s', $item->files, 'tuxedo-big-file-uploads' ), number_format_i18n( $item->files ), size_format( $item->size, 1 ) );
            }

            $total_size     = array_sum( wp_list_pluck( $data, 'size' ) );
            $total_files    = array_sum( wp_list_pluck( $data, 'files' ) );
            $chart['total'] = sprintf( _n( '%s / %s File', '%s / %s Files', $total_files, 'tuxedo-big-file-uploads' ), size_format( $total_size, 2 ), number_format_i18n( $total_files ) );

            return $chart;
        }

        return $data;
    }

    /**
     * Get HTML format details for a filetype.
     *
     * @param  string  $type
     * @param  string  $key
     *
     * @return mixed
     * @since 2.0
     *
     */
    public function get_file_type_format( $type, $key ) {
        $labels = [
                'image'    => [ 'color' => '#26A9E0', 'label' => esc_html__( 'Images', 'tuxedo-big-file-uploads' ) ],
                'audio'    => [ 'color' => '#00A167', 'label' => esc_html__( 'Audio', 'tuxedo-big-file-uploads' ) ],
                'video'    => [ 'color' => '#C035E2', 'label' => esc_html__( 'Video', 'tuxedo-big-file-uploads' ) ],
                'document' => [ 'color' => '#EE7C1E', 'label' => esc_html__( 'Documents', 'tuxedo-big-file-uploads' ) ],
                'archive'  => [ 'color' => '#EC008C', 'label' => esc_html__( 'Archives', 'tuxedo-big-file-uploads' ) ],
                'code'     => [ 'color' => '#EFED27', 'label' => esc_html__( 'Code', 'tuxedo-big-file-uploads' ) ],
                'other'    => [ 'color' => '#8A94A6', 'label' => esc_html__( 'Other', 'tuxedo-big-file-uploads' ) ],
        ];

        if ( isset( $labels[ $type ] ) ) {
            return $labels[ $type ][ $key ];
        } else {
            return $labels['other'][ $key ];
        }
    }

    /**
     * Get the file type category for a given extension.
     *
     * @param  string  $filename
     *
     * @return string
     * @since 2.0
     *
     */
    public function get_file_type( $filename ) {
        $extensions = [
                'image'    => [
                        'jpg',
                        'jpeg',
                        'jpe',
                        'gif',
                        'png',
                        'bmp',
                        'tif',
                        'tiff',
                        'ico',
                        'svg',
                        'svgz',
                        'webp',
                ],
                'audio'    => [
                        'aac',
                        'ac3',
                        'aif',
                        'aiff',
                        'flac',
                        'm3a',
                        'm4a',
                        'm4b',
                        'mka',
                        'mp1',
                        'mp2',
                        'mp3',
                        'ogg',
                        'oga',
                        'ram',
                        'wav',
                        'wma',
                ],
                'video'    => [
                        '3g2',
                        '3gp',
                        '3gpp',
                        'asf',
                        'avi',
                        'divx',
                        'dv',
                        'flv',
                        'm4v',
                        'mkv',
                        'mov',
                        'mp4',
                        'mpeg',
                        'mpg',
                        'mpv',
                        'ogm',
                        'ogv',
                        'qt',
                        'rm',
                        'vob',
                        'wmv',
                        'webm',
                ],
                'document' => [
                        'log',
                        'asc',
                        'csv',
                        'tsv',
                        'txt',
                        'doc',
                        'docx',
                        'docm',
                        'dotm',
                        'odt',
                        'pages',
                        'pdf',
                        'xps',
                        'oxps',
                        'rtf',
                        'wp',
                        'wpd',
                        'psd',
                        'xcf',
                        'swf',
                        'key',
                        'ppt',
                        'pptx',
                        'pptm',
                        'pps',
                        'ppsx',
                        'ppsm',
                        'sldx',
                        'sldm',
                        'odp',
                        'numbers',
                        'ods',
                        'xls',
                        'xlsx',
                        'xlsm',
                        'xlsb',
                ],
                'archive'  => [
                        'bz2',
                        'cab',
                        'dmg',
                        'gz',
                        'rar',
                        'sea',
                        'sit',
                        'sqx',
                        'tar',
                        'tgz',
                        'zip',
                        '7z',
                        'data',
                        'bin',
                        'bak',
                ],
                'code'     => [ 'css', 'htm', 'html', 'php', 'js', 'md' ],
        ];

        $ext = preg_replace( '/^.+?\.([^.]+)$/', '$1', $filename );
        if ( ! empty( $ext ) ) {
            $ext = strtolower( $ext );
            foreach ( $extensions as $type => $exts ) {
                if ( in_array( $ext, $exts, true ) ) {
                    return $type;
                }
            }
        }

        return 'other';
    }

    /**
     * Get root upload dir for multisite. Based on _wp_upload_dir().
     *
     * @return string Uploads base directory
     * @since 2.0
     *
     */
    public function get_upload_dir_root() {
        $upload_path = trim( get_option( 'upload_path' ) );

        if ( empty( $upload_path ) || 'wp-content/uploads' === $upload_path ) {
            $dir = WP_CONTENT_DIR . '/uploads';
        } elseif ( 0 !== strpos( $upload_path, ABSPATH ) ) {
            // $dir is absolute, $upload_path is (maybe) relative to ABSPATH.
            $dir = path_join( ABSPATH, $upload_path );
        } else {
            $dir = $upload_path;
        }

        /*
         * Honor the value of UPLOADS. This happens as long as ms-files rewriting is disabled.
         * We also sometimes obey UPLOADS when rewriting is enabled -- see the next block.
         */
        if ( defined( 'UPLOADS' ) && ! ( is_multisite() && get_site_option( 'ms_files_rewriting' ) ) ) {
            $dir = ABSPATH . UPLOADS;
        }

        // If multisite (and if not the main site in a post-MU network).
        if ( is_multisite() && ! ( is_main_network() && is_main_site() && defined( 'MULTISITE' ) ) ) {
            if ( get_site_option( 'ms_files_rewriting' ) && defined( 'UPLOADS' ) && ! ms_is_switched() ) {
                /*
                 * Handle the old-form ms-files.php rewriting if the network still has that enabled.
                 * When ms-files rewriting is enabled, then we only listen to UPLOADS when:
                 * 1) We are not on the main site in a post-MU network, as wp-content/uploads is used
                 *    there, and
                 * 2) We are not switched, as ms_upload_constants() hardcodes these constants to reflect
                 *    the original blog ID.
                 *
                 * Rather than UPLOADS, we actually use BLOGUPLOADDIR if it is set, as it is absolute.
                 * (And it will be set, see ms_upload_constants().) Otherwise, UPLOADS can be used, as
                 * as it is relative to ABSPATH. For the final piece: when UPLOADS is used with ms-files
                 * rewriting in multisite, the resulting URL is /files. (#WP22702 for background.)
                 */

                $dir = ABSPATH . untrailingslashit( UPLOADBLOGSDIR );
            }
        }

        $basedir = $dir;

        return $basedir;
    }
}

/** Instantiate the plugin class. */
$big_file_uploads = BigFileUploads::get_instance();
