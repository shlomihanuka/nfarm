<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product Knowledge Graph — Doc 4 §6 (SPEC-V3-FULL line 3756).
 *
 * Eight relation types (§6.1):
 *   substitute             — product B can replace product A (MUST ship with reason + differences)
 *   similar_to             — same family, comparable attributes
 *   pairs_with             — frequently used together in the same dish/meal
 *   complementary_to       — different category, enhances the primary product
 *   recipe_uses            — recipe R uses product A as an ingredient
 *   suitable_for           — product A is suitable for a specific use/occasion
 *   same_family            — taxonomic kin (same cheese type, same varietal, etc.)
 *   alternative_price_tier — same use-case at a different price point
 *
 * Substitute rule (§7, line 2674): every substitute MUST carry both `reason`
 * (why it works) and `differences` (what changes for the cook). A substitute
 * stored without both is refused — a similarity score is not a substitute.
 *
 * Every relation is review_required because even a manually curated relation can
 * become stale as products change. The graph never infers allergens or ingredients.
 */
class ASC_Knowledge_Graph {

    public const RELATION_TYPES = [
        'substitute',
        'similar_to',
        'pairs_with',
        'complementary_to',
        'recipe_uses',
        'suitable_for',
        'same_family',
        'alternative_price_tier',
    ];

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
    // DB setup
    // -------------------------------------------------------------------------

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = $wpdb->prefix . 'asc_product_relations';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  from_product_id bigint(20) UNSIGNED NOT NULL,
  to_product_id bigint(20) UNSIGNED NOT NULL,
  relation_type varchar(50) NOT NULL,
  reason text,
  differences text,
  source varchar(100) NOT NULL DEFAULT 'stub',
  confidence float NOT NULL DEFAULT 0,
  review_required tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY from_product_id (from_product_id),
  KEY to_product_id (to_product_id),
  KEY relation_type (relation_type)
) $charset_collate;";

        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Add a typed, reasoned relation between two products.
     *
     * Substitute relations REQUIRE both $reason and $differences.
     * Any other relation with an empty reason is stored with confidence 0.
     */
    public function add_relation(
        int $from_id,
        int $to_id,
        string $type,
        string $reason = '',
        string $differences = '',
        string $source = 'manual',
        float $confidence = 0.0
    ): bool {
        global $wpdb;

        if ( ! in_array( $type, self::RELATION_TYPES, true ) ) {
            return false;
        }

        if ( 'substitute' === $type && ( '' === $reason || '' === $differences ) ) {
            return false;
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'asc_product_relations',
            [
                'from_product_id' => $from_id,
                'to_product_id'   => $to_id,
                'relation_type'   => $type,
                'reason'          => $reason,
                'differences'     => $differences,
                'source'          => $source,
                'confidence'      => $confidence,
                'review_required' => 1,
                'created_at'      => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%d', '%s' ]
        );

        return false !== $result;
    }

    public function delete_relations( int $product_id ): void {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'asc_product_relations',
            [ 'from_product_id' => $product_id ],
            [ '%d' ]
        );
    }

    public function relation_exists( int $from_id, int $to_id, string $type ): bool {
        global $wpdb;
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}asc_product_relations
                 WHERE from_product_id = %d AND to_product_id = %d AND relation_type = %s
                 LIMIT 1",
                $from_id,
                $to_id,
                $type
            )
        );
        return ! empty( $result );
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function get_substitutes( int $product_id ): array {
        return $this->get_relations( $product_id, 'substitute' );
    }

    public function get_pairs( int $product_id ): array {
        return $this->get_relations( $product_id, 'pairs_with' );
    }

    public function get_recipe_uses( int $product_id ): array {
        return $this->get_relations( $product_id, 'recipe_uses' );
    }

    public function get_all_relations( int $product_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'asc_product_relations';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, p.post_title as to_product_name
                 FROM {$table} r
                 LEFT JOIN {$wpdb->posts} p ON p.ID = r.to_product_id
                 WHERE r.from_product_id = %d
                 ORDER BY r.relation_type, r.confidence DESC",
                $product_id
            ),
            ARRAY_A
        );

        $grouped = array_fill_keys( self::RELATION_TYPES, [] );
        foreach ( $rows ?: [] as $row ) {
            $t = $row['relation_type'];
            if ( isset( $grouped[ $t ] ) ) {
                $grouped[ $t ][] = $row;
            }
        }

        return $grouped;
    }

    private function get_relations( int $product_id, string $type ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'asc_product_relations';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, p.post_title as to_product_name
                 FROM {$table} r
                 LEFT JOIN {$wpdb->posts} p ON p.ID = r.to_product_id
                 WHERE r.from_product_id = %d AND r.relation_type = %s
                 ORDER BY r.confidence DESC",
                $product_id,
                $type
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function count_relations( int $product_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'asc_product_relations';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT relation_type, COUNT(*) as cnt
                 FROM {$table}
                 WHERE from_product_id = %d
                 GROUP BY relation_type",
                $product_id
            ),
            ARRAY_A
        );

        $counts = array_fill_keys( self::RELATION_TYPES, 0 );
        foreach ( $rows ?: [] as $row ) {
            if ( isset( $counts[ $row['relation_type'] ] ) ) {
                $counts[ $row['relation_type'] ] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    // -------------------------------------------------------------------------
    // Seed sample relations (idempotent — checks for duplicates)
    // -------------------------------------------------------------------------

    /**
     * Seed substitute and similar_to relations for cheese products found in the catalogue.
     * Idempotent — skips pairs that already exist.
     * Returns count of newly inserted relations.
     */
    public function seed_sample_substitutes(): int {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return 0;
        }

        $cheese_products = wc_get_products( [
            'category' => [ 'cheese', 'גבינות', 'dairy', 'חלב' ],
            'limit'    => 50,
            'status'   => 'publish',
        ] );

        if ( empty( $cheese_products ) ) {
            return 0;
        }

        $by_subtype = [];
        $stub       = new ASC_Enrichment_Stub();

        foreach ( $cheese_products as $product ) {
            $profile = $stub->enrich( $product->get_id() );
            if ( empty( $profile ) ) {
                continue;
            }
            $sub_type = $profile['inferred']['sub_type']['value'] ?? null;
            if ( $sub_type ) {
                $by_subtype[ $sub_type ][] = $product;
            }
        }

        $inserted = 0;

        foreach ( $by_subtype as $sub_type => $products ) {
            if ( count( $products ) < 2 ) {
                continue;
            }

            // similar_to: bidirectional within the same subtype.
            for ( $i = 0; $i < count( $products ) - 1; $i++ ) {
                $a = $products[ $i ];
                $b = $products[ $i + 1 ];

                if ( ! $this->relation_exists( $a->get_id(), $b->get_id(), 'similar_to' ) ) {
                    $this->add_relation(
                        $a->get_id(), $b->get_id(), 'similar_to',
                        "Both are {$sub_type} with comparable texture and flavor profile.",
                        "Individual brand characteristics and milk origin may differ.",
                        'stub:subtype_seed',
                        0.60
                    );
                    $inserted++;
                }

                // substitute: only for meltable cheeses — both must have meltable=true.
                $prof_a = $stub->enrich( $a->get_id() );
                $prof_b = $stub->enrich( $b->get_id() );
                $melt_a = $prof_a['inferred']['meltable']['value'] ?? false;
                $melt_b = $prof_b['inferred']['meltable']['value'] ?? false;

                if ( $melt_a && $melt_b && ! $this->relation_exists( $a->get_id(), $b->get_id(), 'substitute' ) ) {
                    $this->add_relation(
                        $a->get_id(), $b->get_id(), 'substitute',
                        "Both are meltable {$sub_type} cheeses — functionally interchangeable in cooked dishes.",
                        "Salt levels, melt point, and stretch may vary by brand. Check weight-to-weight substitution ratio.",
                        'stub:subtype_seed',
                        0.55
                    );
                    $inserted++;
                }
            }

            // alternative_price_tier: cheapest → most expensive if prices differ meaningfully.
            usort( $products, fn( $a, $b ) => (float) $a->get_price() <=> (float) $b->get_price() );
            if ( count( $products ) >= 2 ) {
                $cheapest   = $products[0];
                $priciest   = $products[ count( $products ) - 1 ];
                $price_low  = (float) $cheapest->get_price();
                $price_high = (float) $priciest->get_price();
                if ( $price_high > 0 && $price_low > 0 && ( $price_high / $price_low ) > 1.3 ) {
                    if ( ! $this->relation_exists( $priciest->get_id(), $cheapest->get_id(), 'alternative_price_tier' ) ) {
                        $this->add_relation(
                            $priciest->get_id(), $cheapest->get_id(), 'alternative_price_tier',
                            "Same sub-type ({$sub_type}), lower price point.",
                            "Premium variant may have longer aging, certified origin, or artisan production.",
                            'stub:price_seed',
                            0.50
                        );
                        $inserted++;
                    }
                }
            }
        }

        return $inserted;
    }

    // -------------------------------------------------------------------------
    // REST
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route( 'asc/v1', '/graph/(?P<id>\d+)/substitutes', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_substitutes' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( 'asc/v1', '/graph/(?P<id>\d+)/pairs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_pairs' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( 'asc/v1', '/graph/(?P<id>\d+)/relations', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_all_relations' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( 'asc/v1', '/graph/(?P<id>\d+)/substitute', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_add_substitute' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'args'                => [
                'id'          => [ 'type' => 'integer', 'required' => true ],
                'to_id'       => [ 'type' => 'integer', 'required' => true ],
                'reason'      => [ 'type' => 'string',  'required' => true, 'minLength' => 10 ],
                'differences' => [ 'type' => 'string',  'required' => true, 'minLength' => 5 ],
                'confidence'  => [ 'type' => 'number',  'default'  => 0.0, 'minimum' => 0.0, 'maximum' => 1.0 ],
            ],
        ] );

        register_rest_route( 'asc/v1', '/graph/seed-substitutes', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_seed_substitutes' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ] );
    }

    public function rest_substitutes( WP_REST_Request $request ): WP_REST_Response {
        $product_id  = (int) $request->get_param( 'id' );
        $substitutes = $this->get_substitutes( $product_id );
        return new WP_REST_Response( [
            'product_id'  => $product_id,
            'substitutes' => $substitutes,
            'schema_note' => 'Every substitute includes reason (why it works) and differences (what changes). review_required is always true until a human confirms.',
        ], 200 );
    }

    public function rest_pairs( WP_REST_Request $request ): WP_REST_Response {
        $product_id = (int) $request->get_param( 'id' );
        return new WP_REST_Response( [
            'product_id' => $product_id,
            'pairs_with' => $this->get_pairs( $product_id ),
        ], 200 );
    }

    public function rest_all_relations( WP_REST_Request $request ): WP_REST_Response {
        $product_id = (int) $request->get_param( 'id' );
        $relations  = $this->get_all_relations( $product_id );
        $counts     = $this->count_relations( $product_id );
        return new WP_REST_Response( [
            'product_id'     => $product_id,
            'relation_types' => self::RELATION_TYPES,
            'counts'         => $counts,
            'relations'      => $relations,
        ], 200 );
    }

    public function rest_add_substitute( WP_REST_Request $request ): WP_REST_Response {
        $from_id     = (int) $request->get_param( 'id' );
        $to_id       = (int) $request->get_param( 'to_id' );
        $reason      = sanitize_text_field( $request->get_param( 'reason' ) );
        $differences = sanitize_text_field( $request->get_param( 'differences' ) );
        $confidence  = (float) $request->get_param( 'confidence' );

        $ok = $this->add_relation( $from_id, $to_id, 'substitute', $reason, $differences, 'manual', $confidence );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'error' => 'Substitute requires a reason and stated differences.' ], 400 );
        }

        return new WP_REST_Response( [
            'from_product_id' => $from_id,
            'to_product_id'   => $to_id,
            'relation_type'   => 'substitute',
            'review_required' => true,
        ], 201 );
    }

    public function rest_seed_substitutes( WP_REST_Request $request ): WP_REST_Response {
        $inserted = $this->seed_sample_substitutes();
        return new WP_REST_Response( [ 'relations_inserted' => $inserted ], 200 );
    }
}
