<?php
/**
 * Helper library to as for a wp.org review.
 *
 * Review notice will be shown using WordPress admin notices after
 * a specified time of plugin/theme use.
 * This is mainly developed to reuse on my plugins but anyone can
 * use it as a library.
 *
 * @author     Joel James <me@joelsays.com>
 * @license    http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * @copyright  Copyright (c) 2021, Joel James
 * @link       https://github.com/duckdev/wp-review-notice/
 * @subpackage Pages
 */

// Should be called only by WordPress.
defined( 'WPINC' ) || die;

/**
 * Class Notice.
 *
 * Main class that handles review notice.
 */
class Big_File_Uploads_Review_Notice {

	/**
	 * Prefix for all options and keys.
	 *
	 * Override only when required.
	 *
	 * @var string $prefix
	 *
	 * @since 1.0.0
	 */
	private $prefix = '';

	/**
	 * Plugin name to show in review.
	 *
	 * @var string $name
	 *
	 * @since 1.0.0
	 */
	private $name = '';

	/**
	 * Plugin slug in https://wordpress.org/plugins/{slug}.
	 *
	 * @var string $slug
	 *
	 * @since 1.0.0
	 */
	private $slug = '';

	/**
	 * Minimum no. of days to show the notice after.
	 *
	 * Currently we support only days.
	 *
	 * @var int $days
	 *
	 * @since 1.0.0
	 */
	private $days = 7;

	/**
	 * WP admin page screen IOs to show notice in.
	 *
	 * If it's empty, we will show it on all pages.
	 *
	 * @var array $screens
	 *
	 * @since 1.0.0
	 */
	private $screens = array();

	/**
	 * Notice classes to set additional classes.
	 *
	 * By default we use WP info notice class.
	 *
	 * @var array $classes
	 *
	 * @since 1.0.0
	 */
	private $classes = array( 'notice', 'notice-info' );

	/**
	 * Minimum capability for the user to see and dismiss notice.
	 *
	 * @see   https://wordpress.org/support/article/roles-and-capabilities/
	 *
	 * @var string $cap
	 *
	 * @since 1.0.0
	 */
	private $cap = 'manage_options';

	/**
	 * Text domain for translations.
	 *
	 * @var string $domain
	 *
	 * @since 1.0.0
	 */
	private $domain = '';

	/**
	 * Create new notice instance with provided options.
	 *
	 * Do not use any hooks to run these functions because
	 * we don't know in which hook and priority everyone is
	 * going to initialize this notice.
	 *
	 * @param string $slug    WP.org slug for plugin.
	 * @param string $name    Name of plugin.
	 * @param array  $options Array of options (@see Big_File_Uploads_Review_Notice::get()).
	 *
	 * @since  4.0.0
	 * @access private
	 *
	 * @return void
	 */
	private function __construct( $slug, $name, array $options ) {
		// Only for admin side.
		if ( is_admin() ) {
			// Setup options.
			$this->setup( $slug, $name, $options );

			// Process actions.
			$this->actions();
		}
	}

	/**
	 * Create and get new notice instance.
	 *
	 * Use this to setup new plugin notice to avoid multiple instances
	 * of same plugin notice.
	 * If you provide wrong slug, please note we will still link to the
	 * wrong wp.org plugin page for reviews.
	 *
	 * @param string $slug    WP.org slug for plugin.
	 * @param string $name    Name of plugin.
	 * @param array  $options {
	 *                        Array of options.
	 *
	 * @type int     $days    No. of days after the notice is shown.
	 * @type array   $screens WP screen IDs to show notice.
	 *                        Leave empty to show in all pages (not recommended).
	 * @type string  $cap     User capability to show notice.
	 *                        Make sure to use proper capability for multisite.
	 * @type array   $classes Additional class names for notice.
	 * @type string  $domain  Text domain for translations.
	 * @type string  $prefix  To override default option prefix.
	 * }
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return Big_File_Uploads_Review_Notice
	 */
	public static function get( $slug, $name, array $options ) {
		static $notices = array();

		// Create new instance if not already created.
		if ( ! isset( $notices[ $slug ] ) || ! $notices[ $slug ] instanceof Big_File_Uploads_Review_Notice ) {
			$notices[ $slug ] = new self( $slug, $name, $options );
		}

		return $notices[ $slug ];
	}

	/**
	 * Render the review notice.
	 *
	 * Review notice will be rendered only if all these conditions met:
	 * > Current screen is an allowed screen (@see Big_File_Uploads_Review_Notice::in_screen())
	 * > Current user has the required capability (@see Big_File_Uploads_Review_Notice::is_capable())
	 * > It's time to show the notice (@see Big_File_Uploads_Review_Notice::is_time())
	 * > User has not dismissed the notice (@see Big_File_Uploads_Review_Notice::is_dismissed())
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function render() {
		// Check conditions.
		if ( ! $this->can_show() ) {
			return;
		}

		// Get current user data.
		$current_user = wp_get_current_user();
		// Make sure we have name.
		$name = empty( $current_user->display_name ) ? __( 'friend', $this->domain ) : ucwords( $current_user->display_name );
		?>
		<style>
			#bfu-reviews-notice {
				display: flex;
				justify-content: center;
				align-items: center;
			}
			#bfu-reviews-notice img {
				display: block;
				float: left;
				margin: 10px 0;
			}
			#bfu-reviews-notice div {
				margin-left: 10px;
			}
			#bfu-review-buttons a {
				background-color: rgba(38, 169, 224, 1);
				color: white;
				border-radius: 5px;
				border: 2px solid rgba(38, 169, 224, 1);
				display: inline-block;
				padding: .5em 1em;
				text-decoration: none;
				margin-right: 10px;
				font-weight: bold;
			}
			#bfu-review-buttons a:hover, #bfu-review-buttons a:active, #bfu-review-buttons a:focus {
				background-color: rgba(38, 169, 224, 0.7) !important;
				border-color: transparent;
				color: white;
			}

			#bfu-review-buttons .bfu-btn-later {
				background-color: transparent;
				color: rgba(38, 169, 224, 1);
				font-weight: normal;
			}
			.bfu-btn-dismiss {
				color: #EE7C1E;
			}
			.bfu-btn-dismiss:hover, .bfu-btn-dismiss:active, .bfu-btn-dismiss:focus {
				color: rgba(238, 124, 30, 0.7) !important;
			}
		</style>
		<div id="bfu-reviews-notice" class="<?php echo esc_attr( $this->get_classes() ); ?>">
			<img src="<?php echo esc_url( plugins_url( '/assets/img/bfu-logo-sm.png', dirname( __FILE__ ) ) ); ?>" alt="Big File Uploads Logo" height="100" />
			<div>
			<p>
				<?php
				printf(
				// translators: %1$s Current user's name, %2$s Plugin name, %3$d.
					esc_html__( 'Hey %1$s, %2$s has been helping you upload large files for a while now – that’s awesome! If you love it, would you rate it? Giving your favorite free plugins a 5-star rating helps developers like us maintain and build free tools. Thank you for the support!', $this->domain ),
					esc_html( $name ),
					'<strong>' . esc_html( $this->name ) . '</strong>'
				);
				?>
			</p>
			<p id="bfu-review-buttons">
				<a href="https://wordpress.org/support/plugin/<?php echo esc_html( $this->slug ); ?>/reviews/#new-post" target="_blank">
					<?php esc_html_e( 'You deserve it!', $this->domain ); ?>
				</a>
				<a class="bfu-btn-later" href="<?php echo esc_url( wp_nonce_url( add_query_arg( $this->key( 'action' ), 'later' ), 'bfu_rate' ) ); ?>">
					<?php esc_html_e( 'Maybe later', $this->domain ); ?>
				</a>
			</p>
				<p>
					<a class="bfu-btn-dismiss" href="<?php echo esc_url( wp_nonce_url( add_query_arg( $this->key( 'action' ), 'dismiss' ), 'bfu_rate' ) ); ?>">
						<?php esc_html_e( 'Leave me alone', $this->domain ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if it's time to show the notice.
	 *
	 * Based on the day provided, we will check if the current
	 * timestamp exceeded or reached the notice time.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @uses   get_site_option()
	 * @uses   update_site_option()
	 *
	 * @return bool
	 */
	protected function is_time() {
		$install_time = get_option( 'tuxedo_big_file_uploads_install_time' );

        // If not set, we will use current time.
        if ( ! $install_time ) {
            $install_time = time();
            add_option( 'tuxedo_big_file_uploads_install_time', $install_time );
        }

		// Bail if the plugin is installed less than an hour ago.
		if ( ( time() - $install_time ) < HOUR_IN_SECONDS ) {
			return false;
		}

		// Get the time when we should show the notice.
		$time = get_site_option( $this->key( 'time' ) );

		// If not set, set now and bail.
		if ( empty( $time ) ) {
			$time = time() + ( $this->days * DAY_IN_SECONDS );

			// Set to future.
			update_site_option( $this->key( 'time' ), $time );

			return true;
		}

		// Check if time passed or reached.
		return (int) $time <= time();
	}

	/**
	 * Check if the notice is already dismissed.
	 *
	 * If a user has dismissed the notice, do not show
	 * notice to the current user again.
	 * We store the flag in current user's meta data.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @uses   get_user_meta()
	 *
	 * @return bool
	 */
	protected function is_dismissed() {
		// Get current user.
		$current_user = wp_get_current_user();

		// Check if current item is dismissed.
		return (bool) get_user_meta(
			$current_user->ID,
			$this->key( 'dismissed' ),
			true
		);
	}

	/**
	 * Check if current user has the capability.
	 *
	 * Before showing and processing the notice actions,
	 * current user should have the capability to do so.
	 *
	 * @since  1.0.0
	 * @uses   current_user_can()
	 * @access protected
	 *
	 * @return bool
	 */
	protected function is_capable() {
		return current_user_can( $this->cap );
	}

	/**
	 * Check if the current screen is allowed.
	 *
	 * Make sure the current page's screen ID is in
	 * allowed IDs list before showing a notice.
	 * If no screen ID is set, we will allow it in
	 * all pages (not recommended).
	 *
	 * @since  1.0.0
	 * @access protected
	 * @uses   get_current_screen()
	 *
	 * @return bool
	 */
	protected function in_screen() {
		// If not screen ID is set, show everywhere.
		if ( empty( $this->screens ) ) {
			return true;
		}

		// Get current screen.
		$screen = get_current_screen();

		// Check if current screen id is allowed.
		return ! empty( $screen->id ) && in_array( $screen->id, $this->screens, true );
	}

	/**
	 * Get the class names for notice div.
	 *
	 * Notice is using WordPress admin notices info notice styles.
	 * You can pass additional class names to customize it for your
	 * requirements in `classes` option when creating notice instance.
	 *
	 * @see    https://developer.wordpress.org/reference/hooks/admin_notices/
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return string
	 */
	protected function get_classes() {
		// Required classes.
		$classes = array( 'notice', 'notice-info' );

		// Add extra classes.
		if ( ! empty( $this->classes ) && is_array( $this->classes ) ) {
			$classes = array_merge( $classes, $this->classes );
			$classes = array_unique( $classes );
		}

		return implode( ' ', $classes );
	}

	/**
	 * Check if we can show the notice.
	 *
	 * > Current screen is an allowed screen (@see Big_File_Uploads_Review_Notice::in_screen())
	 * > Current user has the required capability (@see Big_File_Uploads_Review_Notice::is_capable())
	 * > It's time to show the notice (@see Big_File_Uploads_Review_Notice::is_time())
	 * > User has not dismissed the notice (@see Big_File_Uploads_Review_Notice::is_dismissed())
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return bool
	 */
	protected function can_show() {
		return (
			$this->in_screen() &&
			$this->is_capable() &&
			$this->is_time() &&
			! $this->is_dismissed()
		);
	}

	/**
	 * Process the notice actions.
	 *
	 * If current user is capable process actions.
	 * > Later: Extend the time to show the notice.
	 * > Dismiss: Hide the notice to current user.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return void
	 */
	protected function actions() {
		// Only if required.
		if ( ! $this->in_screen() || ! $this->is_capable() ) {
			return;
		}

		//check nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'bfu_rate' ) ) {
			return;
		}

		// Get the current review action.
		$action = filter_input( INPUT_GET, $this->key( 'action' ), FILTER_SANITIZE_STRING );
		do_action( 'qm/debug', $action );
		switch ( $action ) {
			case 'later':
				// Let's show after 2 times of days.
				$time = time() + ( $this->days * DAY_IN_SECONDS * 2 );
				update_site_option( $this->key( 'time' ), $time );
				break;
			case 'dismiss':
				// Do not show again to this user.
				update_user_meta(
					get_current_user_id(),
					$this->key( 'dismissed' ),
					true
				);
				break;
		}
	}

	/**
	 * Setup notice options to initialize class.
	 *
	 * Make sure the required options are set before
	 * initializing the class.
	 *
	 * @param string $slug    WP.org slug for plugin.
	 * @param string $name    Name of plugin.
	 * @param array  $options Array of options (@see Big_File_Uploads_Review_Notice::get()).
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private function setup( $slug, $name, array $options ) {
		// Plugin name is required.
		if ( empty( $name ) || empty( $slug ) ) {
			return;
		}

		// Merge options.
		$options = wp_parse_args(
			$options,
			array(
				'days'    => 7,
				'screens' => array(),
				'cap'     => 'manage_options',
				'classes' => array(),
				'domain'  => 'tuxedo-big-file-uploads',
			)
		);

		// Set options.
		$this->slug    = (string) $slug;
		$this->name    = (string) $name;
		$this->cap     = (string) $options['cap'];
		$this->days    = (int) $options['days'];
		$this->screens = (array) $options['screens'];
		$this->classes = (array) $options['classes'];
		$this->domain  = (string) $options['domain'];
		$this->prefix  = str_replace( '-', '_', $this->slug );
	}

	/**
	 * Create key by prefixing option name.
	 *
	 * Use this to create proper key for options.
	 *
	 * @param string $key Key.
	 *
	 * @since  1.0.0
	 * @access protected
	 *
	 * @return string
	 */
	private function key( $key ) {
		return $this->prefix . '_reviews_' . $key;
	}
}
