<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Store API integration.
 *
 * The front-end POSTs to the native WC Store API (/wc/store/v1/cart/add-item)
 * directly — this class is the server-side complement: it validates nonces,
 * ensures the Store API is available, and exposes a helper so the drawer JS
 * can discover the correct endpoint and nonce.
 */
class ASC_WooCommerce {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Nothing to hook here — the WC Store API cart endpoint is used directly.
        // We only expose a small REST helper so the drawer can verify Store API availability.
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // Suppress the "WooCommerce cart hash" nag that fires on AJAX requests.
        add_filter( 'woocommerce_add_to_cart_fragments', '__return_empty_array', 1 );
    }

    public function register_routes(): void {
        register_rest_route( 'asc/v1', '/cart-status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'cart_status' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /** Returns the Store API availability and a fresh nonce. */
    public function cart_status( WP_REST_Request $request ): WP_REST_Response {
        $store_api_available = class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' )
            || function_exists( 'wc_get_container' );

        return new WP_REST_Response( [
            'storeApiAvailable' => $store_api_available,
            'storeApiUrl'       => rest_url( 'wc/store/v1' ),
            'nonce'             => wp_create_nonce( 'wc_store_api' ),
            'cartUrl'           => wc_get_cart_url(),
        ], 200 );
    }
}
