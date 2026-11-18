<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles WooCommerce product search (local fallback + cloud when available).
 * REST endpoint: GET /wp-json/asc/v1/search?q=...&per_page=...&page=...
 */
class ASC_Search {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ---------------------------------------------------------------- */
    /* REST route                                                        */
    /* ---------------------------------------------------------------- */

    public function register_routes(): void {
        register_rest_route( 'asc/v1', '/search', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_search' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q'        => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'per_page' => [ 'type' => 'integer', 'default' => 12, 'minimum' => 1, 'maximum' => 48 ],
                'page'     => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
            ],
        ] );

        register_rest_route( 'asc/v1', '/assistant', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_assistant' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( 'asc/v1', '/product/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_product' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    public function handle_search( WP_REST_Request $request ): WP_REST_Response {
        $settings = ASC_Settings::instance();

        // Disabled — return empty.
        if ( ! $settings->is_enabled() ) {
            return new WP_REST_Response( [ 'products' => [], 'total' => 0, 'source' => 'disabled' ], 200 );
        }

        $q        = trim( $request->get_param( 'q' ) );
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );

        // Always run local WC search (the cloud ranking layer is not yet live).
        $local = $this->local_search( $q, $per_page, $page );

        // Try cloud first; fall back silently to local.
        if ( $settings->is_cloud_configured() ) {
            $cloud_result = ASC_Cloud::instance()->search( $q, $per_page, $page );
            if ( ! is_wp_error( $cloud_result ) ) {
                // Merge: use cloud assistant + search_plan, local WC products.
                $response = array_merge( $local, $cloud_result );
                // If cloud returned products of its own use those; otherwise local.
                if ( empty( $cloud_result['products'] ) ) {
                    $response['products'] = $local['products'];
                }
                // Enrich products with per-product R4 fields.
                $response['products'] = array_map( [ $this, 'enrich_product_r4' ], $response['products'] );
                return new WP_REST_Response( $response, 200 );
            }
        }

        // Local-only path: enrich products and return without assistant.
        $local['products'] = array_map( [ $this, 'enrich_product_r4' ], $local['products'] );
        return new WP_REST_Response( $local, 200 );
    }

    /**
     * GET /wp-json/asc/v1/assistant?q=...
     *
     * Proxies to the cloud /assistant endpoint. Designed to be called in parallel
     * with /search so the assistant message arrives before the product cards.
     * Falls back gracefully when the cloud is not configured.
     */
    public function handle_assistant( WP_REST_Request $request ): WP_REST_Response {
        $settings = ASC_Settings::instance();

        if ( ! $settings->is_enabled() ) {
            return new WP_REST_Response(
                [ 'assistant' => [ 'message' => '', 'tone' => 'none', 'follow_ups' => [] ] ],
                200
            );
        }

        $q = trim( $request->get_param( 'q' ) );

        if ( $settings->is_cloud_configured() ) {
            $result = ASC_Cloud::instance()->assistant( $q );
            if ( ! is_wp_error( $result ) ) {
                return new WP_REST_Response( $result, 200 );
            }
        }

        // No cloud: return a silent assistant response.
        return new WP_REST_Response(
            [ 'assistant' => [ 'message' => '', 'tone' => 'none', 'follow_ups' => [] ] ],
            200
        );
    }

    public function handle_product( WP_REST_Request $request ): WP_REST_Response {
        $product = wc_get_product( (int) $request->get_param( 'id' ) );
        if ( ! $product || ! $product->is_visible() ) {
            return new WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }
        return new WP_REST_Response( $this->format_product( $product ), 200 );
    }

    /* ---------------------------------------------------------------- */
    /* Local WooCommerce search                                          */
    /* ---------------------------------------------------------------- */

    private function local_search( string $q, int $per_page, int $page ): array {
        $args = [
            'status'   => 'publish',
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
            'type'     => array_keys( wc_get_product_types() ),
        ];

        // WooCommerce search
        $results = wc_get_products( array_merge( $args, [ 's' => $q ] ) );

        $products = [];
        foreach ( $results->products as $product ) {
            if ( $product->is_visible() ) {
                $products[] = $this->format_product( $product );
            }
        }

        return [
            'products' => $products,
            'total'    => (int) $results->total,
            'pages'    => (int) $results->max_num_pages,
            'source'   => 'local',
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Product card formatter                                            */
    /* ---------------------------------------------------------------- */

    public function format_product( WC_Product $product ): array {
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );

        $data = [
            'id'          => $product->get_id(),
            'name'        => $product->get_name(),
            'permalink'   => get_permalink( $product->get_id() ),
            'image'       => $image_url,
            'price_html'  => $product->get_price_html(),
            'in_stock'    => $product->is_in_stock(),
            'stock_status'=> $product->get_stock_status(),
            'type'        => $product->get_type(),
            'is_weighted' => false, // set below
        ];

        // Weighed product detection: sold_individually + variable weight or external indication.
        if ( $product->is_sold_individually() && 'simple' === $product->get_type() ) {
            // Check for a custom field set by popular weight plugins.
            $weight_unit = get_post_meta( $product->get_id(), '_sold_by_weight', true );
            if ( $weight_unit ) {
                $data['is_weighted'] = true;
                $data['price_note']  = esc_html__( 'Final price depends on actual weight at time of purchase.', 'ai-smart-commerce-search' );
            }
        }

        // Variations.
        if ( $product->is_type( 'variable' ) ) {
            $variations = $product->get_available_variations( 'array' );
            $attrs      = $product->get_variation_attributes();
            $data['attributes']  = $this->format_attributes( $product, $attrs );
            $data['variations']  = array_map( function( $v ) use ( $product ) {
                return [
                    'variation_id' => $v['variation_id'],
                    'attributes'   => $v['attributes'],
                    'price'        => isset( $v['display_price'] ) ? (float) $v['display_price'] : null,
                    'in_stock'     => $v['is_in_stock'],
                    'image'        => isset( $v['image']['src'] ) ? $v['image']['src'] : null,
                ];
            }, array_slice( $variations, 0, 20 ) ); // cap to 20 variants to keep payload sane
        }

        // Add-to-cart endpoint (Store API).
        $data['add_to_cart_url'] = rest_url( 'wc/store/v1/cart/add-item' );

        // R4: reliable "needs options modal" flag.
        // add_to_cart_text() returns "Select options" when the theme/plugin overrides
        // the button — this is the ONLY reliable signal; type/has_options/variations lie.
        $cart_text = $product->add_to_cart_text();
        $requires_options = ( 'Select options' === $cart_text || 'בחר אפשרויות' === $cart_text );
        $data['requires_options'] = $requires_options;
        $data['options_url']      = $requires_options ? get_permalink( $product->get_id() ) : null;

        // R4: price_display = price_html (never use prices.price for weighted products).
        $data['price_display'] = $product->get_price_html();

        return $data;
    }

    /**
     * Enrich a plain product array (from cloud or local) with R4 fields.
     * Called after WC product data is retrieved, before sending to the browser.
     *
     * @param array $product Plain associative product array.
     * @return array Enriched product array.
     */
    private function enrich_product_r4( array $product ): array {
        // If the product already carries requires_options from format_product, keep it.
        // If it came from cloud (which doesn't know WC-level add_to_cart_text), re-derive it.
        if ( ! isset( $product['requires_options'] ) && ! empty( $product['id'] ) ) {
            $wc_product = wc_get_product( (int) $product['id'] );
            if ( $wc_product ) {
                $cart_text = $wc_product->add_to_cart_text();
                $requires  = ( 'Select options' === $cart_text || 'בחר אפשרויות' === $cart_text );
                $product['requires_options'] = $requires;
                $product['options_url']      = $requires ? get_permalink( $wc_product->get_id() ) : null;
                if ( empty( $product['price_display'] ) ) {
                    $product['price_display'] = $wc_product->get_price_html();
                }
            }
        }

        // Ensure price_display always equals price_html.
        if ( empty( $product['price_display'] ) ) {
            $product['price_display'] = $product['price_html'] ?? '';
        }

        return $product;
    }

    private function format_attributes( WC_Product $product, array $attrs ): array {
        $formatted = [];
        foreach ( $attrs as $attr_name => $options ) {
            $label   = wc_attribute_label( $attr_name, $product );
            $terms   = is_array( $options ) ? $options : explode( ' | ', $options );
            $formatted[] = [
                'name'    => $attr_name,
                'label'   => $label,
                'options' => $terms,
            ];
        }
        return $formatted;
    }

    /* ---------------------------------------------------------------- */
    /* Front-end assets                                                  */
    /* ---------------------------------------------------------------- */

    public function enqueue_assets(): void {
        if ( ! ASC_Settings::instance()->is_enabled() ) {
            return;
        }
        if ( is_admin() ) {
            return;
        }

        $ver = ASC_VERSION;

        // Drawer CSS.
        wp_enqueue_style(
            'asc-drawer',
            ASC_PLUGIN_URL . 'assets/css/drawer.css',
            [],
            $ver
        );

        // JS bundle.
        wp_enqueue_script(
            'asc-drawer',
            ASC_PLUGIN_URL . 'build/index.js',
            [],
            $ver,
            true
        );

        // Script translations (loads he_IL.json if present).
        wp_set_script_translations( 'asc-drawer', 'ai-smart-commerce-search', ASC_PLUGIN_DIR . 'languages' );

        // Runtime config passed to the JS.
        $settings = ASC_Settings::instance();
        wp_localize_script( 'asc-drawer', 'ascConfig', [
'restUrl'       => rest_url( 'asc/v1' ),
            'storeApi'      => rest_url( 'wc/store/v1' ),
            'nonce'         => wp_create_nonce( 'wc_store_api' ),
            'locale'        => get_locale(),
            'isRtl'         => is_rtl(),
            'currency'      => get_woocommerce_currency_symbol(),
            'hasCloud'      => $settings->is_cloud_configured(),
            'voiceEnabled'  => $settings->is_voice_configured(),
            'transcribeUrl' => rest_url( 'asc/v1/transcribe' ),
            'i18n'          => [
                'placeholder'              => esc_html__( 'What are you looking for? E.g. "Shabbat dinner for five, on sale"', 'ai-smart-commerce-search' ),
                'noResults'                => esc_html__( 'No products found', 'ai-smart-commerce-search' ),
                'addToCart'                => esc_html__( 'Add to cart', 'ai-smart-commerce-search' ),
                'addingToCart'             => esc_html__( 'Addingâ¦', 'ai-smart-commerce-search' ),
                'addedToCart'              => esc_html__( 'Added to cart', 'ai-smart-commerce-search' ),
                'errorAdding'              => esc_html__( 'Error adding to cart', 'ai-smart-commerce-search' ),
                'selectOptions'            => esc_html__( 'Please select all options', 'ai-smart-commerce-search' ),
                'inStock'                  => esc_html__( 'In stock', 'ai-smart-commerce-search' ),
                'outOfStock'               => esc_html__( 'Out of stock', 'ai-smart-commerce-search' ),
                'quantity'                 => esc_html__( 'Quantity', 'ai-smart-commerce-search' ),
                'close'                    => esc_html__( 'Close search', 'ai-smart-commerce-search' ),
                'searching'                => esc_html__( 'Searchingâ¦', 'ai-smart-commerce-search' ),
                'weightNote'               => esc_html__( 'Final price depends on actual weight at time of purchase.', 'ai-smart-commerce-search' ),
                'aiThinking'               => esc_html__( 'AI is analyzing your requestâ¦', 'ai-smart-commerce-search' ),
                'aiFetching'               => esc_html__( 'AI is finding relevant productsâ¦', 'ai-smart-commerce-search' ),
                'aiRanking'                => esc_html__( 'AI is ranking results for youâ¦', 'ai-smart-commerce-search' ),
                'showMore'                 => esc_html__( 'Show {n} more results', 'ai-smart-commerce-search' ),
                'voiceSearch'              => esc_html__( 'Voice search', 'ai-smart-commerce-search' ),
                'stopRecording'            => esc_html__( 'Stop recording', 'ai-smart-commerce-search' ),
                'voiceListening'           => esc_html__( 'Listeningâ¦', 'ai-smart-commerce-search' ),
                'voiceProcessing'          => esc_html__( 'Processingâ¦', 'ai-smart-commerce-search' ),
                'voiceConfirm'             => esc_html__( 'Search', 'ai-smart-commerce-search' ),
                'voiceCancel'              => esc_html__( 'Cancel', 'ai-smart-commerce-search' ),
                'voiceContext'             => esc_html__( 'Context: ', 'ai-smart-commerce-search' ),
                'voiceTranscriptLabel'     => esc_html__( 'Transcript — tap to edit', 'ai-smart-commerce-search' ),
                'voicePermissionDenied'    => esc_html__( 'Microphone access denied. Check browser permissions.', 'ai-smart-commerce-search' ),
                'voiceSilence'             => esc_html__( 'No speech detected. Try again.', 'ai-smart-commerce-search' ),
                'voiceUnintelligible'      => esc_html__( 'Could not understand. Try again or type.', 'ai-smart-commerce-search' ),
                'voiceServiceUnavailable'  => esc_html__( 'Voice service unavailable. Please type.', 'ai-smart-commerce-search' ),
                'voiceNotConfigured'       => esc_html__( 'Voice search is not configured.', 'ai-smart-commerce-search' ),
                'voiceUnsupportedBrowser'  => esc_html__( 'Voice search is not supported in this browser.', 'ai-smart-commerce-search' ),
            ],
        ] );
    }
}
