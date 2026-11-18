<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product Knowledge Graph.
 *
 * Stores typed, reasoned relations between products:
 *   substitute    — product B can replace product A (ships with reason + differences)
 *   pairs_with    — frequently used together
 *   recipe_uses   — recipe R uses product A as an ingredient
 *
 * Every relation carries review_required because even a manually curated relation can
 * become stale as products change. A substitute without a reason and without stated
 * differences is not a substitute — it is a similarity score, which this graph forbids.
 */
class ASC_Knowledge_Graph {

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
  KEY relation_type (relation_type)
) $charset_collate;";

        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Add a relation.
     *
     * $reason and $differences are mandatory for 'substitute' relations — a substitute
     * without a reason is just a similarity score and must not be stored.
     */
    public function add_relation(
        int $from_id,
        int $to_id,
        string $type,
        string $reason,
        string $differences,
        string $source,
        float $confidence
    ): bool {
        global $wpdb;

        if ( 'substitute' === $type && ( '' === $reason || '' === $differences ) ) {
            // Safety: refuse to store a substitute without both reason and differences.
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

        $counts = [ 'substitute' => 0, 'pairs_with' => 0, 'recipe_uses' => 0 ];
        foreach ( $rows ?: [] as $row ) {
            $counts[ $row['relation_type'] ] = (int) $row['cnt'];
        }

        return $counts;
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
    }

    public function rest_substitutes( WP_REST_Request $request ): WP_REST_Response {
        $product_id  = (int) $request->get_param( 'id' );
        $substitutes = $this->get_substitutes( $product_id );
        return new WP_REST_Response( [
            'product_id'       => $product_id,
            'substitutes'      => $substitutes,
            'schema_note'      => 'Every substitute includes reason (why it works) and differences (what changes). review_required is always true until a human confirms.',
        ], 200 );
    }

    public function rest_pairs( WP_REST_Request $request ): WP_REST_Response {
        $product_id = (int) $request->get_param( 'id' );
        return new WP_REST_Response( [
            'product_id' => $product_id,
            'pairs_with' => $this->get_pairs( $product_id ),
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
}
