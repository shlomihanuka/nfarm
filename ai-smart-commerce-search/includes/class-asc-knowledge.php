<?php
defined( 'ABSPATH' ) || exit;

/**
 * Catalog intelligence layer.
 *
 * Manages Product Intelligence Profiles — a structured, two-tier view of each product:
 *   verified   — facts sourced directly from WooCommerce (price, stock, name, categories)
 *   inferred   — model-generated attributes (each with source, confidence, review_required)
 *
 * Safety constraints are enforced at every level:
 *   - Allergens and ingredients are never inferred from images.
 *   - Every inferred field carries review_required, source, and confidence.
 *   - Autonomous changes are attributable (source tag) and reversible (re-enrich clears old data).
 *   - Safety-relevant fields (allergens, ingredients, milk_type, origin) always need review.
 *
 * Also handles recipe decomposition stubs and the Knowledge lane's extension of IntentResult
 * (servings, ingredients[], occasion) as defined in the V3 plan Phase 2.
 */
class ASC_Knowledge {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    // -------------------------------------------------------------------------
    // DB setup (called from activation hook)
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = $wpdb->prefix . 'asc_product_profiles';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id bigint(20) UNSIGNED NOT NULL,
  schema_version varchar(10) NOT NULL DEFAULT '1.0',
  profile_json longtext NOT NULL,
  enrichment_source varchar(100) NOT NULL DEFAULT 'stub',
  approval_state varchar(50) NOT NULL DEFAULT 'pending_review',
  enriched_at datetime NOT NULL,
  reviewed_at datetime DEFAULT NULL,
  reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY product_id (product_id),
  KEY approval_state (approval_state)
) $charset_collate;";

        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Enrichment
    // -------------------------------------------------------------------------

    /**
     * Enrich a single product and persist the resulting profile.
     *
     * Currently routes to the stub enricher; will dispatch to the Cloud enricher
     * (POST /enrich on Dev-SmartSearchCloud) once that endpoint exists.
     */
    public function enrich_product( int $product_id ): array|WP_Error {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_visible() ) {
            return new WP_Error( 'not_found', "Product {$product_id} not found or not visible." );
        }

        $profile = $this->resolve_enricher()->enrich( $product_id );
        if ( empty( $profile ) ) {
            return new WP_Error( 'enrich_failed', "Enrichment returned empty for product {$product_id}." );
        }

        // Merge relation counts from the graph.
        $graph                          = ASC_Knowledge_Graph::instance();
        $counts                         = $graph->count_relations( $product_id );
        $profile['relations_summary']   = [
            'substitute_count'  => $counts['substitute'],
            'recipe_count'      => $counts['recipe_uses'],
            'pairs_with_count'  => $counts['pairs_with'],
        ];

        $this->save_profile( $product_id, $profile );
        return $profile;
    }

    /**
     * Enrich up to $limit products that have no stored profile yet.
     * Returns a summary suitable for display in the admin panel.
     */
    public function batch_enrich( int $limit = 20 ): array {
        $product_ids = $this->products_needing_enrichment( $limit );

        $enriched       = 0;
        $errors         = 0;
        $fields_summary = [];
        $profiles       = [];

        foreach ( $product_ids as $product_id ) {
            $result = $this->enrich_product( $product_id );
            if ( is_wp_error( $result ) ) {
                $errors++;
                continue;
            }
            $enriched++;
            foreach ( $result['review_summary']['fields_needing_review'] as $field ) {
                $fields_summary[ $field ] = ( $fields_summary[ $field ] ?? 0 ) + 1;
            }
            $profiles[] = [
                'product_id'   => $result['product_id'],
                'name'         => $result['verified']['name'],
                'approval_state'          => $result['approval_state'],
                'enrichment_source'       => $result['enrichment_source'],
                'needs_review_count'      => $result['review_summary']['needs_review'],
                'fields_needing_review'   => $result['review_summary']['fields_needing_review'],
            ];
        }

        arsort( $fields_summary ); // most-flagged fields first

        return [
            'requested'                       => count( $product_ids ),
            'enriched'                        => $enriched,
            'errors'                          => $errors,
            'profiles'                        => $profiles,
            'fields_needing_review_counts'    => $fields_summary,
        ];
    }

    // -------------------------------------------------------------------------
    // Recipe intelligence (stub — Phase 2)
    //
    // Takes a natural-language query and returns a decomposed ingredient list.
    // The stub uses a small lookup table of common Hebrew dish names; the full
    // implementation will use Gemini (Dev-SmartSearchCloud, POST /intent).
    //
    // All returned ingredients are review_required: true.
    // -------------------------------------------------------------------------

    public function decompose_recipe_query( string $query ): array {
        $now = current_time( 'c' );
        $q   = strtolower( $query );

        // Stub recipe decomposition lookup.
        $recipes = [
            'ארוחת שבת'  => [ 'עוף', 'דגים', 'חלה', 'יין', 'סלט' ],
            'שבת'        => [ 'עוף', 'חלה', 'יין', 'סלט' ],
            'פסטה'       => [ 'פסטה', 'עגבניות', 'שמן זית', 'שום', 'בזיליקום' ],
            'לזניה'      => [ 'פסטה לזניה', 'בשר טחון', 'עגבניות', 'גבינה', 'שמנת' ],
            'פיצה'       => [ 'קמח', 'עגבניות', 'מוצרלה', 'שמן זית', 'שמרים' ],
            'סלט ירקות'  => [ 'עגבנייה', 'מלפפון', 'פלפל', 'בצל', 'שמן זית', 'לימון' ],
            'שקשוקה'     => [ 'ביצים', 'עגבניות', 'פלפל', 'שום', 'שמן', 'תבלינים' ],
            'עוגת גבינה' => [ 'גבינת שמנת', 'ביצים', 'סוכר', 'קמח', 'שמנת' ],
        ];

        $found_key  = null;
        $found_ingredients = [];

        foreach ( $recipes as $dish => $ingredients ) {
            if ( str_contains( $q, $dish ) ) {
                $found_key        = $dish;
                $found_ingredients = $ingredients;
                break;
            }
        }

        // Extract servings hint from query.
        $servings = 4; // plan stub constant
        if ( preg_match( '/(\d+)\s*(אנשים|סועדים|מנות|portions?|people|servings?)/', $query, $m ) ) {
            $servings = (int) $m[1];
        }

        return [
            'query'          => $query,
            'recipe_match'   => $found_key,
            'ingredients'    => array_map( fn( $i ) => [
                'name'            => $i,
                'source'          => 'stub:recipe_lookup',
                'confidence'      => 0.60,
                'review_required' => true,
            ], $found_ingredients ),
            'servings'       => [
                'value'           => $servings,
                'source'          => $servings > 4
                    ? 'stub:query_number_extraction'
                    : 'stub:constant_fallback',
                'confidence'      => $servings > 4 ? 0.85 : 0.0,
                'review_required' => true,
            ],
            'occasion'       => [
                'value'           => str_contains( $q, 'שבת' ) ? 'shabbat'
                    : ( str_contains( $q, 'חג' ) ? 'holiday' : null ),
                'source'          => 'stub:query_keyword',
                'confidence'      => 0.70,
                'review_required' => true,
            ],
            'sale_preference' => [
                'value'           => str_contains( $q, 'מבצע' ) || str_contains( $q, 'זול' ),
                'source'          => 'stub:query_keyword',
                'confidence'      => 0.80,
                'review_required' => false,  // sale_preference has no safety implications
            ],
            'enriched_at'    => $now,
            'enrichment_source' => 'stub',
        ];
    }

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------

    private function save_profile( int $product_id, array $profile ): void {
        global $wpdb;

        $wpdb->replace(
            $wpdb->prefix . 'asc_product_profiles',
            [
                'product_id'        => $product_id,
                'schema_version'    => $profile['schema_version'],
                'profile_json'      => wp_json_encode( $profile ),
                'enrichment_source' => $profile['enrichment_source'],
                'approval_state'    => $profile['approval_state'],
                'enriched_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public function get_profile( int $product_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asc_product_profiles WHERE product_id = %d",
                $product_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        return json_decode( $row['profile_json'], true ) ?: null;
    }

    public function list_profiles( string $approval_state, int $limit, int $offset ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'asc_product_profiles';

        if ( $approval_state ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id, schema_version, enrichment_source, approval_state, enriched_at
                     FROM {$table}
                     WHERE approval_state = %s
                     ORDER BY enriched_at DESC
                     LIMIT %d OFFSET %d",
                    $approval_state,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
            $total = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE approval_state = %s", $approval_state )
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id, schema_version, enrichment_source, approval_state, enriched_at
                     FROM {$table}
                     ORDER BY enriched_at DESC
                     LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        return [ 'profiles' => $rows ?: [], 'total' => $total ];
    }

    private function set_approval_state( int $product_id, string $state ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'asc_product_profiles';

        $updated = $wpdb->update(
            $table,
            [
                'approval_state' => $state,
                'reviewed_at'    => current_time( 'mysql' ),
                'reviewed_by'    => get_current_user_id(),
            ],
            [ 'product_id' => $product_id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return false;
        }

        // Mirror the approval_state inside the JSON blob for consistency.
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT profile_json FROM {$table} WHERE product_id = %d", $product_id ),
            ARRAY_A
        );
        if ( $row ) {
            $profile = json_decode( $row['profile_json'], true );
            if ( $profile ) {
                $profile['approval_state'] = $state;
                $wpdb->update( $table, [ 'profile_json' => wp_json_encode( $profile ) ], [ 'product_id' => $product_id ], [ '%s' ], [ '%d' ] );
            }
        }

        return true;
    }

    private function products_needing_enrichment( int $limit ): array {
        global $wpdb;
        $profiles_table = $wpdb->prefix . 'asc_product_profiles';

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$profiles_table} ap ON ap.product_id = p.ID
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND ap.product_id IS NULL
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $limit
            )
        );

        return array_map( 'intval', $ids ?: [] );
    }

    private function resolve_enricher(): ASC_Enrichment_Stub {
        // Phase 2 stub — will dispatch to cloud enricher once Dev-SmartSearchCloud
        // ships the POST /enrich endpoint and the IntentResult schema is finalised.
        return new ASC_Enrichment_Stub();
    }

    // -------------------------------------------------------------------------
    // REST routes
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route( 'asc/v1', '/profiles', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_list' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'approval_state' => [ 'type' => 'string',  'default' => '' ],
                'per_page'       => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                'page'           => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
            ],
        ] );

        register_rest_route( 'asc/v1', '/profiles/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( 'asc/v1', '/profiles/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_approve' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'id'     => [ 'type' => 'string',  'required' => true ],
                'action' => [ 'type' => 'string',  'default'  => 'approve', 'enum' => [ 'approve', 'reject' ] ],
            ],
        ] );

        register_rest_route( 'asc/v1', '/enrich/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_enrich_one' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( 'asc/v1', '/enrich/batch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_enrich_batch' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'limit' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );

        register_rest_route( 'asc/v1', '/recipe/decompose', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_recipe_decompose' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    public function rest_list( WP_REST_Request $request ): WP_REST_Response {
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );
        $state    = (string) $request->get_param( 'approval_state' );
        return new WP_REST_Response(
            $this->list_profiles( $state, $per_page, ( $page - 1 ) * $per_page ),
            200
        );
    }

    public function rest_get( WP_REST_Request $request ): WP_REST_Response {
        $product_id = (int) $request->get_param( 'id' );
        $profile    = $this->get_profile( $product_id );
        if ( ! $profile ) {
            return new WP_REST_Response( [ 'error' => 'not_found', 'product_id' => $product_id ], 404 );
        }
        return new WP_REST_Response( $profile, 200 );
    }

    public function rest_approve( WP_REST_Request $request ): WP_REST_Response {
        $product_id = (int) $request->get_param( 'id' );
        $action     = $request->get_param( 'action' );
        $new_state  = ( 'approve' === $action ) ? 'approved' : 'rejected';

        if ( ! $this->set_approval_state( $product_id, $new_state ) ) {
            return new WP_REST_Response( [ 'error' => 'update_failed' ], 500 );
        }

        return new WP_REST_Response( [ 'product_id' => $product_id, 'approval_state' => $new_state ], 200 );
    }

    public function rest_enrich_one( WP_REST_Request $request ): WP_REST_Response {
        $product_id = (int) $request->get_param( 'id' );
        $result     = $this->enrich_product( $product_id );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 404 );
        }
        return new WP_REST_Response( $result, 200 );
    }

    public function rest_enrich_batch( WP_REST_Request $request ): WP_REST_Response {
        $limit = (int) $request->get_param( 'limit' );
        return new WP_REST_Response( $this->batch_enrich( $limit ), 200 );
    }

    public function rest_recipe_decompose( WP_REST_Request $request ): WP_REST_Response {
        $q = $request->get_param( 'q' );
        return new WP_REST_Response( $this->decompose_recipe_query( $q ), 200 );
    }
}
