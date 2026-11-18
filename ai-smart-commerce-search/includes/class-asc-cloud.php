<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cloud connector — Gemini / Vertex AI backend.
 *
 * In R1 this is a configured, testable seam. No live Gemini calls are made.
 * When the backend URL is set, search and test-connection calls are routed here;
 * failures fall back silently to local search.
 */
class ASC_Cloud {

    private static ?self $instance = null;
    private ASC_Settings $settings;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = ASC_Settings::instance();
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'asc/v1', '/test-connection', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test_connection' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ] );
    }

    /**
     * Attempt a lightweight ping to the configured cloud API.
     * Returns one of: connected, auth_failed, permission_missing, unavailable, not_configured.
     */
    public function test_connection( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->settings->is_cloud_configured() ) {
            return new WP_REST_Response( [
                'status'  => 'not_configured',
                'message' => esc_html__( 'Cloud API URL and Project ID are required.', 'ai-smart-commerce-search' ),
            ], 200 );
        }

        $url = trailingslashit( $this->settings->get( 'cloud_api_url' ) ) . 'health';
        $response = wp_remote_get( $url, [
            'timeout' => 8,
            'headers' => $this->auth_headers(),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_REST_Response( [
                'status'  => 'unavailable',
                'message' => $response->get_error_message(),
            ], 200 );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 === $code ) {
            return new WP_REST_Response( [
                'status'  => 'connected',
                'message' => esc_html__( 'Connected', 'ai-smart-commerce-search' ),
            ], 200 );
        }

        if ( 401 === $code ) {
            return new WP_REST_Response( [
                'status'  => 'auth_failed',
                'message' => esc_html__( 'Authentication Failed', 'ai-smart-commerce-search' ),
            ], 200 );
        }

        if ( 403 === $code ) {
            return new WP_REST_Response( [
                'status'  => 'permission_missing',
                'message' => esc_html__( 'Permission Missing', 'ai-smart-commerce-search' ),
            ], 200 );
        }

        return new WP_REST_Response( [
            'status'  => 'unavailable',
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                esc_html__( 'API Unavailable (HTTP %d)', 'ai-smart-commerce-search' ),
                $code
            ),
        ], 200 );
    }

    /**
     * Call the cloud /assistant endpoint.
     * Returns { assistant: {message, tone, follow_ups}, intent, trace_id } or WP_Error.
     */
    public function assistant( string $q ): array|WP_Error {
        $url = add_query_arg( [], trailingslashit( $this->settings->get( 'cloud_api_url' ) ) . 'assistant' );

        $response = wp_remote_post( $url, [
            'timeout' => 8,
            'headers' => array_merge( $this->auth_headers(), [ 'Content-Type' => 'application/json' ] ),
            'body'    => wp_json_encode( [
                'q'         => $q,
                'tenant_id' => $this->settings->get( 'cloud_tenant_id' ),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'cloud_error', 'Cloud assistant failed with HTTP ' . $code );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'cloud_parse_error', 'Invalid JSON from cloud assistant' );
        }

        return $body;
    }

    /**
     * Proxy a search query to the cloud backend, passing WC products for
     * AI ranking + editorial grouping (cloud has no WC access of its own).
     *
     * @param string $q       User query.
     * @param int    $per_page Results per page.
     * @param int    $page     Page number.
     * @param array  $products Enriched WC product arrays from local search (R5).
     * @return array|WP_Error Normalised cloud result or WP_Error on failure.
     */
    public function search( string $q, int $per_page, int $page, array $products = [] ): array|WP_Error {
        $url = trailingslashit( $this->settings->get( 'cloud_api_url' ) ) . 'search';

        $response = wp_remote_post( $url, [
            'timeout' => 12,
            'headers' => array_merge( $this->auth_headers(), [ 'Content-Type' => 'application/json' ] ),
            'body'    => wp_json_encode( [
                'q'          => $q,
                'tenant_id'  => $this->settings->get( 'cloud_tenant_id' ),
                'per_page'   => $per_page,
                'page'       => $page,
                'provisional' => false,
                // R5: pass WC products so cloud can rank + group editorially
                'products'   => array_values( $products ),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'cloud_error', 'Cloud search failed with HTTP ' . $code );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'cloud_parse_error', 'Invalid JSON from cloud search' );
        }

        return array_merge( $body, [ 'source' => 'cloud' ] );
    }

    private function auth_headers(): array {
        $project_id = $this->settings->get( 'cloud_project_id' );
        $tenant_id  = $this->settings->get( 'cloud_tenant_id' );
        return [
            'X-ASC-Project'  => $project_id,
            'X-ASC-Tenant'   => $tenant_id,
            'X-ASC-Version'  => ASC_VERSION,
        ];
    }
}
