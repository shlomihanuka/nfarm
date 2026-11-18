<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stub enricher — derives product intelligence from existing WooCommerce data only.
 *
 * Everything here is a heuristic from product name, categories, description, and
 * WooCommerce attributes. No external API calls are made.
 *
 * Rules that are never relaxed:
 *  - Allergens are NEVER inferred. The field is always returned as [] with confidence 0
 *    and review_required true. Inferring allergens from a product name or image is a
 *    safety failure, not a UX win.
 *  - Ingredients are NEVER inferred from images or names.
 *  - Milk type, origin country, kosher certification are returned as null/unknown with
 *    review_required true; they need a human or a trusted data source to verify.
 *  - Every field carries source, confidence, and review_required so callers know exactly
 *    how much to trust it.
 */
class ASC_Enrichment_Stub {

    public function enrich( int $product_id ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return [];
        }

        $now        = current_time( 'c' );
        $categories = $this->category_names( $product );
        $tags       = $this->tag_names( $product );
        $name       = $product->get_name();
        $desc       = wp_strip_all_tags(
            $product->get_description() . ' ' . $product->get_short_description()
        );

        $product_type = $this->infer_product_type( $categories, $name, $now );
        $sub_type     = $this->infer_sub_type( $product_type, $name, $now );
        $meltable     = $this->infer_meltable( $product_type, $sub_type, $desc, $now );
        $storage      = $this->infer_storage( $product_type, $name, $categories, $now );
        $flavor       = $this->infer_flavor( $product_type, $sub_type, $name, $now );
        $texture      = $this->infer_texture( $sub_type, $now );
        $kosher       = $this->infer_kosher( $name, $desc, $now );
        $servings     = $this->infer_servings( $product, $now );

        $inferred = [
            'product_type'         => $product_type,
            'sub_type'             => $sub_type,
            'flavor_profile'       => $flavor,
            'texture'              => $texture,
            'meltable'             => $meltable,
            'storage_method'       => $storage,
            'origin_country'       => $this->null_field( 'stub:needs_verified_source', $now ),
            'milk_type'            => $this->null_field( 'stub:needs_verified_source', $now ),
            'kosher_certification' => $kosher,
            'allergens'            => [
                // Safety constraint: NEVER populated by stub.
                // Allergens require a verified label scan, not a heuristic.
                'value'           => [],
                'source'          => 'stub:safety_blank',
                'confidence'      => 0.0,
                'review_required' => true,
                'updated_at'      => $now,
            ],
            'ingredients'          => [
                // Safety constraint: NEVER inferred from name, category, or image.
                'value'           => [],
                'source'          => 'stub:safety_blank',
                'confidence'      => 0.0,
                'review_required' => true,
                'updated_at'      => $now,
            ],
            'servings_per_pack'    => $servings,
        ];

        $needs_review = array_keys(
            array_filter( $inferred, fn( $f ) => ! empty( $f['review_required'] ) )
        );

        return [
            'product_id'        => $product_id,
            'schema_version'    => '1.0',
            'enriched_at'       => $now,
            'enrichment_source' => 'stub',
            'approval_state'    => 'pending_review',
            'verified'          => $this->verified_fields( $product, $categories, $tags, $now ),
            'inferred'          => $inferred,
            'relations_summary' => [
                'substitute_count'  => 0,
                'recipe_count'      => 0,
                'pairs_with_count'  => 0,
            ],
            'review_summary'    => [
                'total_fields'          => count( $inferred ),
                'needs_review'          => count( $needs_review ),
                'fields_needing_review' => $needs_review,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Verified fields (from WooCommerce — no inference needed)
    // -------------------------------------------------------------------------

    private function verified_fields( WC_Product $product, array $categories, array $tags, string $now ): array {
        return [
            'name'              => $product->get_name(),
            'sku'               => $product->get_sku(),
            'price'             => $product->get_price() !== '' ? (float) $product->get_price() : null,
            'regular_price'     => $product->get_regular_price() !== '' ? (float) $product->get_regular_price() : null,
            'sale_price'        => $product->get_sale_price() !== '' ? (float) $product->get_sale_price() : null,
            'on_sale'           => $product->is_on_sale(),
            'in_stock'          => $product->is_in_stock(),
            'stock_status'      => $product->get_stock_status(),
            'categories'        => $categories,
            'tags'              => $tags,
            'weight'            => $product->get_weight(),
            'weight_unit'       => get_option( 'woocommerce_weight_unit', 'kg' ),
            'is_variable'       => $product->is_type( 'variable' ),
            'short_description' => wp_strip_all_tags( $product->get_short_description() ),
            'source'            => 'woocommerce',
            'updated_at'        => $now,
        ];
    }

    // -------------------------------------------------------------------------
    // Heuristic inferences
    // -------------------------------------------------------------------------

    private function infer_product_type( array $categories, string $name, string $now ): array {
        $h = strtolower( implode( ' ', $categories ) . ' ' . $name );

        // Order matters: more specific types first.
        $map = [
            'cheese'    => [ 'גבינ', 'cheese' ],
            'dairy'     => [ 'חלב', 'dairy', 'milk', 'יוגורט', 'yogurt', 'שמנת', 'cream', 'גבינ' ],
            'meat'      => [ 'בשר', 'meat', 'נקניק', 'sausage', 'salami' ],
            'poultry'   => [ 'עוף', 'chicken', 'turkey', 'הודו' ],
            'fish'      => [ 'דג', 'fish', 'salmon', 'סלמון', 'tuna', 'טונה' ],
            'egg'       => [ 'ביצ', 'egg' ],
            'produce'   => [ 'ירק', 'vegeta', 'עלים', 'salad', 'פטרי', 'mushroom' ],
            'fruit'     => [ 'פרי', 'fruit', 'תפוח', 'apple', 'banana', 'בננה' ],
            'bakery'    => [ 'לחם', 'bread', 'pita', 'פיתה', 'אפי', 'bak', 'חלה' ],
            'pasta'     => [ 'פסטה', 'pasta', 'noodle', 'ספגטי', 'spaghetti' ],
            'grain'     => [ 'אורז', 'rice', 'quinoa', 'קינואה', 'grain' ],
            'oil'       => [ 'שמן', 'oil', 'olive', 'זית' ],
            'spice'     => [ 'תבל', 'spice', 'herb', 'שום', 'garlic' ],
            'legume'    => [ 'קטניות', 'legume', 'bean', 'שעועית', 'עדשים', 'lentil', 'חומוס', 'chickpea' ],
            'beverage'  => [ 'שתי', 'drink', 'juice', 'מיץ', 'wine', 'יין', 'beer', 'בירה', 'water', 'מים' ],
            'sweet'     => [ 'שוקולד', 'chocolate', 'candy', 'עוגה', 'cake', 'עוגיה', 'cookie', 'גלידה', 'ice cream' ],
        ];

        foreach ( $map as $type => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( str_contains( $h, $kw ) ) {
                    return [
                        'value'           => $type,
                        'source'          => 'stub:category_name_heuristic',
                        'confidence'      => 0.65,
                        'review_required' => true,
                        'updated_at'      => $now,
                    ];
                }
            }
        }

        return $this->null_field( 'stub:no_match', $now );
    }

    private function infer_sub_type( array $type_field, string $name, string $now ): array {
        $type_val = $type_field['value'] ?? null;
        $n        = strtolower( $name );

        if ( 'cheese' === $type_val ) {
            $subtypes = [
                'hard_cheese'      => [ 'פרמזן', 'parmesan', "מנצ'גו", 'manchego', 'פקורינו', 'pecorino', 'אסיאגו', 'asiago' ],
                'semi_hard_cheese' => [ "צ'דר", 'cheddar', 'גאודה', 'gouda', 'אדם', 'edam', 'אמנטל', 'emmental', 'גרויאר', 'gruyere', 'הוורטי', 'havarti' ],
                'fresh_cheese'     => [ 'מוצרלה', 'mozzarella', 'ריקוטה', 'ricotta', 'קוטג', 'cottage', 'לבנה', 'פרש', 'fresh' ],
                'soft_cheese'      => [ 'קממבר', 'camembert', 'ברי', 'brie', 'רוקפור', 'roquefort', 'גורגונזולה', 'gorgonzola' ],
                'brined_cheese'    => [ 'פטה', 'feta', 'בולגרית', 'bulgarian', 'ברינזה' ],
                'processed_cheese' => [ 'צהוב', 'yellow', 'processed', 'מעובד', 'spread', 'ממרח' ],
            ];
            foreach ( $subtypes as $sub => $kws ) {
                foreach ( $kws as $kw ) {
                    if ( str_contains( $n, $kw ) ) {
                        return [
                            'value'           => $sub,
                            'source'          => 'stub:name_keyword',
                            'confidence'      => 0.70,
                            'review_required' => true,
                            'updated_at'      => $now,
                        ];
                    }
                }
            }
        }

        return $this->null_field( 'stub:no_sub_type_match', $now );
    }

    private function infer_meltable( array $type_field, array $sub_type_field, string $desc, string $now ): array {
        $sub_val = $sub_type_field['value'] ?? null;
        $d       = strtolower( $desc );

        $melt_map = [
            'hard_cheese'      => [ 'value' => true,  'confidence' => 0.75 ],
            'semi_hard_cheese' => [ 'value' => true,  'confidence' => 0.80 ],
            'fresh_cheese'     => [ 'value' => false, 'confidence' => 0.60 ],
            'soft_cheese'      => [ 'value' => false, 'confidence' => 0.50 ],
            'brined_cheese'    => [ 'value' => false, 'confidence' => 0.65 ],
            'processed_cheese' => [ 'value' => true,  'confidence' => 0.88 ],
        ];

        if ( $sub_val && isset( $melt_map[ $sub_val ] ) ) {
            return array_merge( $melt_map[ $sub_val ], [
                'source'          => 'stub:sub_type_heuristic',
                'review_required' => true,
                'updated_at'      => $now,
            ] );
        }

        if ( str_contains( $d, 'נמס' ) || str_contains( $d, 'melt' ) ) {
            return [ 'value' => true, 'source' => 'stub:description_keyword', 'confidence' => 0.65, 'review_required' => true, 'updated_at' => $now ];
        }

        if ( 'cheese' !== ( $type_field['value'] ?? '' ) ) {
            return $this->null_field( 'stub:not_applicable', $now );
        }

        return $this->null_field( 'stub:no_match', $now );
    }

    private function infer_storage( array $type_field, string $name, array $categories, string $now ): array {
        $h = strtolower( $name . ' ' . implode( ' ', $categories ) );

        if ( str_contains( $h, 'קפוא' ) || str_contains( $h, 'frozen' ) ) {
            return [ 'value' => 'frozen', 'source' => 'stub:name_keyword', 'confidence' => 0.90, 'review_required' => true, 'updated_at' => $now ];
        }

        if ( str_contains( $h, 'שימור' ) || str_contains( $h, 'canned' ) ) {
            return [ 'value' => 'shelf_stable', 'source' => 'stub:name_keyword', 'confidence' => 0.75, 'review_required' => true, 'updated_at' => $now ];
        }

        $refrigerated_types = [ 'cheese', 'dairy', 'meat', 'poultry', 'fish', 'egg' ];
        if ( in_array( $type_field['value'] ?? '', $refrigerated_types, true ) ) {
            return [ 'value' => 'refrigerated', 'source' => 'stub:product_type_heuristic', 'confidence' => 0.80, 'review_required' => true, 'updated_at' => $now ];
        }

        return $this->null_field( 'stub:no_match', $now );
    }

    private function infer_flavor( array $type_field, array $sub_type_field, string $name, string $now ): array {
        $n       = strtolower( $name );
        $sub_val = $sub_type_field['value'] ?? null;

        if ( str_contains( $n, 'חריף' ) || str_contains( $n, 'spicy' ) ) {
            return [ 'value' => 'spicy', 'source' => 'stub:name_keyword', 'confidence' => 0.75, 'review_required' => true, 'updated_at' => $now ];
        }

        if ( str_contains( $n, 'מתוק' ) || str_contains( $n, 'sweet' ) ) {
            return [ 'value' => 'sweet', 'source' => 'stub:name_keyword', 'confidence' => 0.75, 'review_required' => true, 'updated_at' => $now ];
        }

        $flavor_map = [
            'hard_cheese'      => 'sharp',
            'fresh_cheese'     => 'mild',
            'brined_cheese'    => 'salty',
            'processed_cheese' => 'mild',
            'soft_cheese'      => 'creamy',
        ];

        if ( $sub_val && isset( $flavor_map[ $sub_val ] ) ) {
            return [ 'value' => $flavor_map[ $sub_val ], 'source' => 'stub:sub_type_heuristic', 'confidence' => 0.50, 'review_required' => true, 'updated_at' => $now ];
        }

        return $this->null_field( 'stub:no_match', $now );
    }

    private function infer_texture( array $sub_type_field, string $now ): array {
        $sub_val = $sub_type_field['value'] ?? null;

        $texture_map = [
            'hard_cheese'      => 'hard',
            'semi_hard_cheese' => 'semi_hard',
            'fresh_cheese'     => 'soft',
            'soft_cheese'      => 'soft',
            'brined_cheese'    => 'crumbly',
            'processed_cheese' => 'soft',
        ];

        if ( $sub_val && isset( $texture_map[ $sub_val ] ) ) {
            return [ 'value' => $texture_map[ $sub_val ], 'source' => 'stub:sub_type_heuristic', 'confidence' => 0.65, 'review_required' => true, 'updated_at' => $now ];
        }

        return $this->null_field( 'stub:no_match', $now );
    }

    private function infer_kosher( string $name, string $desc, string $now ): array {
        $h = strtolower( $name . ' ' . $desc );

        // Only match explicit certification text — never infer from product category alone.
        $keywords = [ 'כשר', 'kosher', 'בדץ', 'מהדרין', 'חלק', 'halak' ];
        foreach ( $keywords as $kw ) {
            if ( str_contains( $h, $kw ) ) {
                return [
                    'value'           => $kw,
                    'source'          => 'stub:name_description_keyword',
                    'confidence'      => 0.50,
                    'review_required' => true,
                    'updated_at'      => $now,
                ];
            }
        }

        return $this->null_field( 'stub:no_mention_found', $now );
    }

    private function infer_servings( WC_Product $product, string $now ): array {
        $weight = (float) $product->get_weight();
        if ( $weight > 0 ) {
            // Weight unit is store-configured (kg/g/lbs/oz) — confidence is low.
            return [
                'value'           => 4,
                'source'          => 'stub:constant_weight_present_but_unit_unknown',
                'confidence'      => 0.20,
                'review_required' => true,
                'updated_at'      => $now,
            ];
        }

        return [
            'value'           => 4,
            'source'          => 'stub:constant_fallback',
            'confidence'      => 0.0,
            'review_required' => true,
            'updated_at'      => $now,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function null_field( string $source, string $now ): array {
        return [
            'value'           => null,
            'source'          => $source,
            'confidence'      => 0.0,
            'review_required' => true,
            'updated_at'      => $now,
        ];
    }

    private function category_names( WC_Product $product ): array {
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }
        return wp_list_pluck( $terms, 'name' );
    }

    private function tag_names( WC_Product $product ): array {
        $terms = get_the_terms( $product->get_id(), 'product_tag' );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }
        return wp_list_pluck( $terms, 'name' );
    }
}
