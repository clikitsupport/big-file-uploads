<?php

class BFFU_Promo_Notice {
    private $notices = [];
    private $default_delay = 7; // Days to wait before showing again after "Maybe Later"

    public function __construct() {
        add_action( 'admin_notices', [ $this, 'display_notices' ] );
        add_action( 'wp_ajax_bffu_handle_promo_action', [ $this, 'handle_promo_action' ] );
    }

    /**
     * Add a new promotion notice
     *
     * @param  array  $notice  Notice configuration
     *
     * @return void
     */
    public function add_notice( $notice ) {
        $default = [
                'id'          => '',
                'title'       => '',
                'message'     => '',
                'link'        => '',
                'link_text'   => __( 'Learn More', 'tuxedo-big-file-uploads' ),
                'delay_days'  => $this->default_delay,
                'type'        => 'info', // info, warning, error, success
                'dismissible' => true,
                'buttons'     => [
                        'primary'     => [],
                        'secondary'   => [],
                        'dismiss'     => [
                                'text'   => __( 'Dismiss', 'tuxedo-big-file-uploads' ),
                                'action' => 'dismiss',
                        ],
                        'maybe_later' => [
                                'text'   => __( 'Maybe Later', 'tuxedo-big-file-uploads' ),
                                'action' => 'delay',
                        ],
                ],
        ];

        $notice          = wp_parse_args( $notice, $default );
        $this->notices[] = $notice;
    }

    /**
     * Display all active notices
     */
    public function display_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        foreach ( $this->notices as $notice ) {
            if ( $this->should_display_notice( $notice ) ) {
                $this->render_notice( $notice );
            }
        }

        $this->enqueue_scripts();
    }

    /**
     * Check if notice should be displayed
     */
    private function should_display_notice( $notice ) {
        $user_id       = get_current_user_id();
        $notice_status = get_user_meta( $user_id, 'bffu_notice_' . $notice['id'], true );

        if ( $notice_status === 'dismissed' || $notice_status === 'visited' ) {
            return false;
        }

        if ( $notice_status && is_array( $notice_status ) ) {
            if ( $notice_status['action'] === 'delay' &&
                 time() < $notice_status['show_after'] ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render individual notice
     */
    private function render_notice( $notice ) {
        ?>
        <div class="notice notice-<?php
        echo esc_attr( $notice['type'] ); ?> bffu-notice is-dismissible"
             data-notice-id="<?php
             echo esc_attr( $notice['id'] ); ?>">
            <button type="button" class="notice-dismiss" data-action="dismiss">
                <span class="screen-reader-text"><?php
                    esc_html_e( 'Dismiss this notice', 'tuxedo-big-file-uploads' ); ?></span>
            </button>

            <h3><?php
                echo esc_html( $notice['title'] ); ?></h3>
            <p><?php
                echo wp_kses_post( $notice['message'] ); ?></p>

            <p class="bffu-notice-actions">
                <?php
                foreach ( $notice['buttons'] as $type => $button ):
                    // Skip dismiss button since we have the X
                    if ( empty( $button ) ) {
                        continue;
                    }

                    $class = $type = isset( $button['type'] ) && $button['type'] === 'primary' ? 'button-primary' : 'button-secondary';

                    ?>
                    <button type="button"
                            class="button <?php
                            echo esc_attr( $class ); ?>"
                            data-action="<?php
                            echo esc_attr( $button['action'] ); ?>"
                            data-link="<?php
                            if ( ! empty( $button['link'] ) ) {
                                echo esc_attr( $button['link'] );
                            } ?>"
                    >
                        <?php
                        echo esc_html( $button['text'] ); ?>
                    </button>
                <?php
                endforeach; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Handle AJAX actions for notices
     */
    public function handle_promo_action() {
        check_ajax_referer( 'bffu_promo_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $notice_id = sanitize_text_field( $_POST['notice_id'] );
        $action    = sanitize_text_field( $_POST['action_type'] );
        $user_id   = get_current_user_id();

        switch ( $action ) {
            case 'dismiss':
                update_user_meta( $user_id, 'bffu_notice_' . $notice_id, 'dismissed' );
                break;

            case 'link':
                update_user_meta( $user_id, 'bffu_notice_' . $notice_id, 'visited' );
                break;

            case 'delay':
                // How to use delay_days from the notice config?
                $notice = array_filter( $this->notices, function ( $n ) use ( $notice_id ) {
					return $n['id'] === $notice_id;
				} );

                $notice = reset( $notice );

				if ( ! $notice || ! isset( $notice['delay_days'] ) ) {
                    $delay_days = $this->default_delay; // Fallback to default if not set
				} else {
					$delay_days = $notice['delay_days'];
                }

                $show_after = time() + ( DAY_IN_SECONDS * $delay_days );
                update_user_meta( $user_id, 'bffu_notice_' . $notice_id, [
                        'action'     => 'delay',
                        'show_after' => $show_after,
                ] );
                break;
        }

        wp_send_json_success();
    }

    /**
     * Enqueue necessary scripts
     */
    private function enqueue_scripts() {
        wp_enqueue_script(
                'bffu-promo-notices',
                BIG_FILE_UPLOADS_PLUGIN_URL . 'assets/js/promo-notices.js',
                [ 'jquery' ],
                BIG_FILE_UPLOADS_VERSION,
                true
        );

        wp_localize_script( 'bffu-promo-notices', 'bffuPromo', [
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'bffu_promo_nonce' ),
        ] );
    }
}