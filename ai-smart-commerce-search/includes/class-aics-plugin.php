<?php
defined( 'ABSPATH' ) || exit;

class AICS_Plugin {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	public function init() {
		load_plugin_textdomain(
			'ai-smart-commerce-search',
			false,
			dirname( plugin_basename( AICS_PLUGIN_FILE ) ) . '/languages'
		);
		AICS_Enqueue::get_instance()->init();
		AICS_Settings::get_instance()->init();
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'AI Commerce', 'ai-smart-commerce-search' ),
			__( 'AI Commerce', 'ai-smart-commerce-search' ),
			'manage_options',
			'aics-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-search',
			56
		);
	}

	public function render_settings_page() {
		require_once AICS_PLUGIN_DIR . 'admin/settings-page.php';
	}

	public function register_rest_routes() {
		register_rest_route(
			'aics/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'query' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'  => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'aics/v1',
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_test_connection' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	public function handle_search( $request ) {
		$query = $request->get_param( 'query' );

		if ( strlen( $query ) < 2 ) {
			return new WP_REST_Response( array( 'products' => array(), 'total' => 0 ), 200 );
		}

		$adapter  = new AICS_Woo_Adapter();
		$products = $adapter->search( $query, $request->get_param( 'page' ) );

		return new WP_REST_Response( $products, 200 );
	}

	public function handle_test_connection() {
		$connector = new AICS_Cloud_Connector();
		return new WP_REST_Response( $connector->test_connection(), 200 );
	}

	public static function activate() {
		$defaults = array(
			'aics_enabled'            => '1',
			'aics_gcp_project_id'     => '',
			'aics_gcp_environment'    => 'development',
			'aics_gcp_region'         => '',
			'aics_gcp_tenant_id'      => '',
			'aics_cloud_api_url'      => '',
			'aics_auth_method'        => 'api_key',
			'aics_api_key'            => '',
			'aics_wc_consumer_key'    => '',
			'aics_wc_consumer_secret' => '',
		);
		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
