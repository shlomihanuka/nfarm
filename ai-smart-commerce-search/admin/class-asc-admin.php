<?php
defined( 'ABSPATH' ) || exit;

class ASC_Admin {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_asc_save_settings', [ $this, 'ajax_save_settings' ] );
    }

    public function add_menu(): void {
        add_options_page(
            esc_html__( 'AI Smart Search Settings', 'ai-smart-commerce-search' ),
            esc_html__( 'AI Smart Search', 'ai-smart-commerce-search' ),
            'manage_options',
            'asc-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'asc_settings_group', 'asc_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ): array {
        $defaults = ASC_Settings::defaults();
        $clean    = [];

        $clean['enabled']           = ! empty( $input['enabled'] );
        $clean['cloud_project_id']  = sanitize_text_field( $input['cloud_project_id']  ?? '' );
        $clean['cloud_environment'] = in_array( $input['cloud_environment'] ?? 'production', [ 'production', 'staging', 'development' ], true )
            ? $input['cloud_environment']
            : 'production';
        $clean['cloud_region']      = sanitize_text_field( $input['cloud_region']     ?? 'us-central1' );
        $clean['cloud_tenant_id']   = sanitize_text_field( $input['cloud_tenant_id']  ?? '' );
        $clean['cloud_api_url']     = esc_url_raw( $input['cloud_api_url']           ?? '' );

        return $clean;
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'settings_page_asc-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'asc-admin',
            ASC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ASC_VERSION
        );
        wp_enqueue_script(
            'asc-admin',
            ASC_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            ASC_VERSION,
            true
        );
        wp_localize_script( 'asc-admin', 'ascAdmin', [
            'nonce'           => wp_create_nonce( 'asc_admin_nonce' ),
            'testEndpoint'    => rest_url( 'asc/v1/test-connection' ),
            'restNonce'       => wp_create_nonce( 'wp_rest' ),
            'i18n'            => [
                'testing'           => esc_html__( 'Testing…', 'ai-smart-commerce-search' ),
                'connected'         => esc_html__( 'Connected', 'ai-smart-commerce-search' ),
                'authFailed'        => esc_html__( 'Authentication Failed', 'ai-smart-commerce-search' ),
                'permissionMissing' => esc_html__( 'Permission Missing', 'ai-smart-commerce-search' ),
                'unavailable'       => esc_html__( 'API Unavailable', 'ai-smart-commerce-search' ),
                'notConfigured'     => esc_html__( 'Not Configured', 'ai-smart-commerce-search' ),
            ],
        ] );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-smart-commerce-search' ) );
        }
        require ASC_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function ajax_save_settings(): void {
        check_ajax_referer( 'asc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'ai-smart-commerce-search' ) ] );
        }
        // Settings are saved by the standard register_setting / options-general.php flow;
        // this endpoint exists for inline AJAX saves from the wizard.
        $settings = ASC_Settings::instance();
        $settings->update( [
            'enabled'           => ! empty( $_POST['enabled'] ),
            'cloud_project_id'  => sanitize_text_field( $_POST['cloud_project_id']  ?? '' ),
            'cloud_environment' => sanitize_text_field( $_POST['cloud_environment'] ?? 'production' ),
            'cloud_region'      => sanitize_text_field( $_POST['cloud_region']      ?? 'us-central1' ),
            'cloud_tenant_id'   => sanitize_text_field( $_POST['cloud_tenant_id']   ?? '' ),
            'cloud_api_url'     => esc_url_raw( $_POST['cloud_api_url']             ?? '' ),
        ] );
        wp_send_json_success( [ 'message' => esc_html__( 'Settings saved.', 'ai-smart-commerce-search' ) ] );
    }
}
