<?php
defined( 'ABSPATH' ) || exit;

/**
 * Recipe Intelligence — Doc 4 §7 (SPEC-V3-FULL line 3847).
 *
 * Three tables (all prefixed with wp_):
 *   asc_recipes             — recipe header: name, dish_type, servings, occasion, source
 *   asc_recipe_ingredients  — per-ingredient rows: name, quantity, unit, matched_product_id
 *   asc_recipe_product_matches — explicit recipe ↔ product links with role and confidence
 *
 * Linking strategy (stub tier — full Gemini tier is Phase 3):
 *   For each ingredient we do a WooCommerce product search by name fragments.
 *   The first match above the confidence threshold is stored; unmatched ingredients
 *   are stored with matched_product_id NULL and match_confidence 0 (needs review).
 *   Ingredients and allergens are NEVER inferred from images.
 */
class ASC_Recipe_Intelligence {

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

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $cc = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$wpdb->prefix}asc_recipes (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  description text,
  dish_type varchar(100),
  servings int UNSIGNED DEFAULT 4,
  occasion varchar(100),
  source_url varchar(500),
  source_label varchar(100) NOT NULL DEFAULT 'manual',
  approval_state varchar(50) NOT NULL DEFAULT 'pending_review',
  enriched_at datetime NOT NULL,
  reviewed_at datetime DEFAULT NULL,
  reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY dish_type (dish_type),
  KEY approval_state (approval_state)
) $cc;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}asc_recipe_ingredients (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id bigint(20) UNSIGNED NOT NULL,
  ingredient_name varchar(255) NOT NULL,
  quantity varchar(50),
  unit varchar(50),
  is_required tinyint(1) NOT NULL DEFAULT 1,
  matched_product_id bigint(20) UNSIGNED DEFAULT NULL,
  match_confidence float NOT NULL DEFAULT 0,
  match_source varchar(100) NOT NULL DEFAULT 'stub:name_search',
  match_review_required tinyint(1) NOT NULL DEFAULT 1,
  matched_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY recipe_id (recipe_id),
  KEY matched_product_id (matched_product_id)
) $cc;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}asc_recipe_product_matches (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id bigint(20) UNSIGNED NOT NULL,
  product_id bigint(20) UNSIGNED NOT NULL,
  match_role varchar(100) NOT NULL DEFAULT 'ingredient',
  confidence float NOT NULL DEFAULT 0,
  matched_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY recipe_product (recipe_id, product_id),
  KEY recipe_id (recipe_id),
  KEY product_id (product_id)
) $cc;" );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Store a recipe and its ingredients, then run product linking.
     *
     * $data = [
     *   'name'        => string,
     *   'description' => string,
     *   'dish_type'   => string,
     *   'servings'    => int,
     *   'occasion'    => string|null,
     *   'source_url'  => string|null,
     *   'source_label'=> string,
     *   'ingredients' => [ ['name'=>..., 'quantity'=>..., 'unit'=>...], ... ],
     * ]
     */
    public function add_recipe( array $data ): int|false {
        global $wpdb;

        $now = current_time( 'mysql' );

        $wpdb->insert(
            $wpdb->prefix . 'asc_recipes',
            [
                'name'          => sanitize_text_field( $data['name'] ),
                'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
                'dish_type'     => sanitize_text_field( $data['dish_type'] ?? '' ),
                'servings'      => max( 1, (int) ( $data['servings'] ?? 4 ) ),
                'occasion'      => sanitize_text_field( $data['occasion'] ?? '' ),
                'source_url'    => esc_url_raw( $data['source_url'] ?? '' ),
                'source_label'  => sanitize_text_field( $data['source_label'] ?? 'manual' ),
                'approval_state'=> 'pending_review',
                'enriched_at'   => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        $recipe_id = (int) $wpdb->insert_id;
        if ( ! $recipe_id ) {
            return false;
        }

        foreach ( $data['ingredients'] ?? [] as $ing ) {
            $this->add_ingredient( $recipe_id, $ing );
        }

        $this->link_recipe_to_products( $recipe_id );

        return $recipe_id;
    }

    private function add_ingredient( int $recipe_id, array $ing ): void {
        global $wpdb;

        $name    = sanitize_text_field( $ing['name'] ?? '' );
        $matched = $this->find_product_for_ingredient( $name );

        $wpdb->insert(
            $wpdb->prefix . 'asc_recipe_ingredients',
            [
                'recipe_id'           => $recipe_id,
                'ingredient_name'     => $name,
                'quantity'            => sanitize_text_field( $ing['quantity'] ?? '' ),
                'unit'                => sanitize_text_field( $ing['unit'] ?? '' ),
                'is_required'         => isset( $ing['required'] ) ? (int) $ing['required'] : 1,
                'matched_product_id'  => $matched['product_id'],
                'match_confidence'    => $matched['confidence'],
                'match_source'        => $matched['source'],
                'match_review_required' => 1,
                'matched_at'          => $matched['product_id'] ? current_time( 'mysql' ) : null,
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%d', '%s' ]
        );
    }

    /**
     * Search WooCommerce for a product whose name contains the ingredient text.
     * Returns [ product_id|null, confidence, source ].
     *
     * This is a stub — full Gemini understanding will replace this in Phase 3.
     * We rely on WooCommerce's own search rather than hand-crafted SQL to stay portable.
     */
    public function find_product_for_ingredient( string $ingredient_name ): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [ 'product_id' => null, 'confidence' => 0.0, 'source' => 'stub:wc_unavailable' ];
        }

        $words = array_filter( explode( ' ', $ingredient_name ), fn( $w ) => mb_strlen( $w ) > 2 );
        if ( empty( $words ) ) {
            return [ 'product_id' => null, 'confidence' => 0.0, 'source' => 'stub:empty_name' ];
        }

        // Try longest-first fragments until we get a hit.
        $search_term = implode( ' ', $words );
        $products = wc_get_products( [
            's'      => $search_term,
            'limit'  => 3,
            'status' => 'publish',
        ] );

        if ( ! empty( $products ) ) {
            return [
                'product_id' => $products[0]->get_id(),
                'confidence' => 0.65,
                'source'     => 'stub:wc_name_search',
            ];
        }

        // Retry with the first meaningful word only.
        $single_word = reset( $words );
        $products = wc_get_products( [
            's'      => $single_word,
            'limit'  => 3,
            'status' => 'publish',
        ] );

        if ( ! empty( $products ) ) {
            return [
                'product_id' => $products[0]->get_id(),
                'confidence' => 0.40,
                'source'     => 'stub:wc_single_word_search',
            ];
        }

        return [ 'product_id' => null, 'confidence' => 0.0, 'source' => 'stub:no_match' ];
    }

    /**
     * After all ingredients are stored, build the recipe_product_matches summary table.
     * Each unique matched product appears once per recipe.
     */
    public function link_recipe_to_products( int $recipe_id ): int {
        global $wpdb;

        $ingredients = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT matched_product_id, MAX(match_confidence) as max_conf
                 FROM {$wpdb->prefix}asc_recipe_ingredients
                 WHERE recipe_id = %d AND matched_product_id IS NOT NULL
                 GROUP BY matched_product_id",
                $recipe_id
            ),
            ARRAY_A
        );

        $count = 0;
        $now   = current_time( 'mysql' );

        foreach ( $ingredients ?: [] as $row ) {
            $wpdb->replace(
                $wpdb->prefix . 'asc_recipe_product_matches',
                [
                    'recipe_id'  => $recipe_id,
                    'product_id' => (int) $row['matched_product_id'],
                    'match_role' => 'ingredient',
                    'confidence' => (float) $row['max_conf'],
                    'matched_at' => $now,
                ],
                [ '%d', '%d', '%s', '%f', '%s' ]
            );
            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function get_recipe( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}asc_recipes WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }

        $row['ingredients'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asc_recipe_ingredients WHERE recipe_id = %d ORDER BY id ASC",
                $id
            ),
            ARRAY_A
        ) ?: [];

        $row['product_matches'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*, p.post_title as product_name
                 FROM {$wpdb->prefix}asc_recipe_product_matches m
                 LEFT JOIN {$wpdb->posts} p ON p.ID = m.product_id
                 WHERE m.recipe_id = %d
                 ORDER BY m.confidence DESC",
                $id
            ),
            ARRAY_A
        ) ?: [];

        return $row;
    }

    public function list_recipes( int $limit = 20, int $offset = 0 ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*,
                        COUNT(DISTINCT i.id) as ingredient_count,
                        SUM(CASE WHEN i.matched_product_id IS NOT NULL THEN 1 ELSE 0 END) as matched_ingredient_count
                 FROM {$wpdb->prefix}asc_recipes r
                 LEFT JOIN {$wpdb->prefix}asc_recipe_ingredients i ON i.recipe_id = r.id
                 GROUP BY r.id
                 ORDER BY r.enriched_at DESC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}asc_recipes" );

        return [ 'recipes' => $rows, 'total' => $total ];
    }

    // -------------------------------------------------------------------------
    // Seed sample recipes (stub tier — proves the pipeline works)
    // -------------------------------------------------------------------------

    /**
     * Seed sample recipes against the live catalogue.
     * Safe to call multiple times — checks for name uniqueness before inserting.
     * Returns count of newly inserted recipes.
     */
    public function seed_sample_recipes(): int {
        global $wpdb;

        $samples = [
            [
                'name'        => 'פסטה עם גבינה ועגבניות',
                'description' => 'מנת פסטה איטלקית קלאסית עם רוטב עגבניות וגבינה',
                'dish_type'   => 'pasta',
                'servings'    => 4,
                'occasion'    => null,
                'source_label'=> 'sample_seed',
                'ingredients' => [
                    [ 'name' => 'פסטה ספגטי', 'quantity' => '500', 'unit' => 'ג' ],
                    [ 'name' => 'עגבניות', 'quantity' => '400', 'unit' => 'ג' ],
                    [ 'name' => 'גבינת פרמזן', 'quantity' => '50', 'unit' => 'ג' ],
                    [ 'name' => 'שמן זית', 'quantity' => '3', 'unit' => 'כף' ],
                    [ 'name' => 'שום', 'quantity' => '3', 'unit' => 'שיני' ],
                ],
            ],
            [
                'name'        => 'פיצה ביתית',
                'description' => 'פיצה ביתית עם מוצרלה ועגבניות',
                'dish_type'   => 'pizza',
                'servings'    => 4,
                'occasion'    => null,
                'source_label'=> 'sample_seed',
                'ingredients' => [
                    [ 'name' => 'קמח לחם', 'quantity' => '400', 'unit' => 'ג' ],
                    [ 'name' => 'עגבניות', 'quantity' => '300', 'unit' => 'ג' ],
                    [ 'name' => 'גבינת מוצרלה', 'quantity' => '250', 'unit' => 'ג' ],
                    [ 'name' => 'שמן זית', 'quantity' => '2', 'unit' => 'כף' ],
                    [ 'name' => 'שמרים', 'quantity' => '7', 'unit' => 'ג' ],
                ],
            ],
            [
                'name'        => 'שקשוקה',
                'description' => 'מנת ביצים ברוטב עגבניות ופלפלים',
                'dish_type'   => 'egg',
                'servings'    => 2,
                'occasion'    => 'breakfast',
                'source_label'=> 'sample_seed',
                'ingredients' => [
                    [ 'name' => 'ביצים', 'quantity' => '4', 'unit' => 'יחידות' ],
                    [ 'name' => 'עגבניות שרי', 'quantity' => '400', 'unit' => 'ג' ],
                    [ 'name' => 'פלפל אדום', 'quantity' => '1', 'unit' => 'יחידה' ],
                    [ 'name' => 'שמן זית', 'quantity' => '2', 'unit' => 'כף' ],
                    [ 'name' => 'שום', 'quantity' => '2', 'unit' => 'שיני' ],
                ],
            ],
        ];

        $inserted = 0;
        foreach ( $samples as $sample ) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}asc_recipes WHERE name = %s LIMIT 1",
                    $sample['name']
                )
            );
            if ( $exists ) {
                continue;
            }
            if ( $this->add_recipe( $sample ) ) {
                $inserted++;
            }
        }

        return $inserted;
    }

    // -------------------------------------------------------------------------
    // REST routes
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route( 'asc/v1', '/recipes', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_list' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
                'args'                => [
                    'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                    'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_add' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
                'args'                => [
                    'name'        => [ 'type' => 'string', 'required' => true, 'minLength' => 2 ],
                    'description' => [ 'type' => 'string', 'default'  => '' ],
                    'dish_type'   => [ 'type' => 'string', 'default'  => '' ],
                    'servings'    => [ 'type' => 'integer', 'default' => 4, 'minimum' => 1 ],
                    'occasion'    => [ 'type' => 'string', 'default'  => '' ],
                    'source_url'  => [ 'type' => 'string', 'default'  => '' ],
                    'ingredients' => [ 'type' => 'array',  'default'  => [] ],
                ],
            ],
        ] );

        register_rest_route( 'asc/v1', '/recipes/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( 'asc/v1', '/recipes/(?P<id>\d+)/link-products', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_link_products' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( 'asc/v1', '/recipes/seed', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_seed' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
    }

    public function rest_list( WP_REST_Request $request ): WP_REST_Response {
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );
        return new WP_REST_Response( $this->list_recipes( $per_page, ( $page - 1 ) * $per_page ), 200 );
    }

    public function rest_add( WP_REST_Request $request ): WP_REST_Response {
        $data = [
            'name'        => $request->get_param( 'name' ),
            'description' => $request->get_param( 'description' ),
            'dish_type'   => $request->get_param( 'dish_type' ),
            'servings'    => $request->get_param( 'servings' ),
            'occasion'    => $request->get_param( 'occasion' ),
            'source_url'  => $request->get_param( 'source_url' ),
            'source_label'=> 'admin_ui',
            'ingredients' => $request->get_param( 'ingredients' ) ?: [],
        ];

        $id = $this->add_recipe( $data );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'error' => 'insert_failed' ], 500 );
        }

        return new WP_REST_Response( $this->get_recipe( $id ), 201 );
    }

    public function rest_get( WP_REST_Request $request ): WP_REST_Response {
        $recipe = $this->get_recipe( (int) $request->get_param( 'id' ) );
        if ( ! $recipe ) {
            return new WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }
        return new WP_REST_Response( $recipe, 200 );
    }

    public function rest_link_products( WP_REST_Request $request ): WP_REST_Response {
        $id    = (int) $request->get_param( 'id' );
        $count = $this->link_recipe_to_products( $id );
        return new WP_REST_Response( [ 'recipe_id' => $id, 'products_linked' => $count ], 200 );
    }

    public function rest_seed( WP_REST_Request $request ): WP_REST_Response {
        $inserted = $this->seed_sample_recipes();
        return new WP_REST_Response( [ 'recipes_inserted' => $inserted ], 200 );
    }
}
