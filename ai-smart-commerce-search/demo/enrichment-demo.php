<?php
/**
 * Enrichment Demo — V3 Knowledge R2
 *
 * Run from the plugin root:  php demo/enrichment-demo.php
 *
 * This script mocks the WordPress / WooCommerce environment and runs the real
 * ASC_Enrichment_Stub heuristics against 10 nfarm-style cheese products to
 * show exactly what fields are produced, their confidence scores, and which
 * fields remain review_required.
 *
 * Knowledge graph substitutes and recipe intelligence are shown as pre-seeded
 * examples — the same data that seed_sample_substitutes() and seed_sample_recipes()
 * would write into the DB on a live staging site.
 *
 * Output: coloured terminal text + JSON for each profile.
 */

// ---- WordPress / WooCommerce stubs ----------------------------------------

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ): string {
        return $type === 'mysql' ? date( 'Y-m-d H:i:s' ) : date( 'c' );
    }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); }
}
if ( ! function_exists( 'get_the_terms' ) ) {
    function get_the_terms( int $id, string $taxonomy ): array { return []; }
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
    function wp_list_pluck( array $list, string $field ): array {
        return array_map( fn($item) => $item->$field ?? null, $list );
    }
}

/** Minimal WC_Product stub */
class WC_Product {
    private array $data;
    public function __construct( array $d ) { $this->data = $d; }
    public function get_id(): int              { return $this->data['id']; }
    public function get_name(): string         { return $this->data['name']; }
    public function get_sku(): string          { return $this->data['sku'] ?? ''; }
    public function get_price(): string        { return (string) ($this->data['price'] ?? ''); }
    public function get_regular_price(): string { return (string) ($this->data['price'] ?? ''); }
    public function get_sale_price(): string   { return ''; }
    public function is_on_sale(): bool         { return false; }
    public function is_in_stock(): bool        { return true; }
    public function get_stock_status(): string { return 'instock'; }
    public function get_weight(): string       { return (string) ($this->data['weight'] ?? ''); }
    public function is_type( string $t ): bool { return false; }
    public function get_description(): string  { return $this->data['description'] ?? ''; }
    public function get_short_description(): string { return ''; }
}

/** wc_get_product must return a WC_Product — override after products are defined */
function wc_get_product( int $id ): ?WC_Product {
    global $_demo_products;
    return $_demo_products[ $id ] ?? null;
}

// ---- Product catalogue -------------------------------------------------------

$products_data = [
    // Cheese products based on typical nfarm specialty dairy catalogue
    ['id' => 1001, 'name' => 'גבינת פרמזן מגורדת (250ג)',           'sku' => 'CH-PARM-250',  'price' => 32.90, 'weight' => 0.25, 'categories' => ['גבינות', 'cheese'], 'description' => 'פרמזן איטלקי מקורי, מגורד דק'],
    ['id' => 1002, 'name' => 'מוצרלה טריה (250ג)',                   'sku' => 'CH-MOZZ-250',  'price' => 24.50, 'weight' => 0.25, 'categories' => ['גבינות', 'cheese'], 'description' => 'מוצרלה טריה, מתאימה לפיצה וסלט'],
    ['id' => 1003, 'name' => 'גבינת גאודה צהובה (200ג)',             'sku' => 'CH-GOUD-200',  'price' => 18.90, 'weight' => 0.20, 'categories' => ['גבינות', 'cheese'], 'description' => 'גאודה הולנדית קלאסית'],
    ['id' => 1004, 'name' => 'גבינת פטה יוונית בציר (200ג)',         'sku' => 'CH-FETA-200',  'price' => 22.00, 'weight' => 0.20, 'categories' => ['גבינות', 'cheese'], 'description' => 'פטה מקורית, מלוחה ופריכה'],
    ['id' => 1005, 'name' => 'קממבר צרפתי (125ג)',                   'sku' => 'CH-CAMB-125',  'price' => 29.90, 'weight' => 0.125,'categories' => ['גבינות', 'cheese'], 'description' => 'קממבר רך, עם קרום לבן'],
    ['id' => 1006, 'name' => "צ'דר ישן מיושן (200ג)",               'sku' => 'CH-CHDR-200',  'price' => 21.50, 'weight' => 0.20, 'categories' => ['גבינות', 'cheese'], 'description' => "צ'דר בריטי מיושן 12 חודש"],
    ['id' => 1007, 'name' => 'ריקוטה איטלקית (250ג)',                'sku' => 'CH-RICO-250',  'price' => 16.90, 'weight' => 0.25, 'categories' => ['גבינות', 'cheese'], 'description' => 'ריקוטה טרייה, קלה ומקרמת'],
    ['id' => 1008, 'name' => 'גרויאר שוויצרי (200ג)',                'sku' => 'CH-GRUY-200',  'price' => 35.90, 'weight' => 0.20, 'categories' => ['גבינות', 'cheese'], 'description' => 'גרויאר AOC, מתאים לפונדו'],
    ['id' => 1009, 'name' => 'גבינה בולגרית (200ג)',                 'sku' => 'CH-BULG-200',  'price' => 14.90, 'weight' => 0.20, 'categories' => ['גבינות', 'cheese'], 'description' => 'גבינה בולגרית, רכה מעט'],
    ['id' => 1010, 'name' => 'גבינת שמנת להברשה (200ג)',            'sku' => 'CH-CREM-200',  'price' => 11.90, 'weight' => 0.20, 'categories' => ['גבינות', 'cheese'], 'description' => 'גבינת שמנת להברשה, spread'],
];

global $_demo_products;
$_demo_products = [];
foreach ( $products_data as $d ) {
    $_demo_products[ $d['id'] ] = new WC_Product( $d );
}

// ---- Override category_names to use our demo data ----------------------------

class DemoEnrichmentStub extends ASC_Enrichment_Stub {
    private array $product_data_map;

    public function __construct( array $products_data ) {
        foreach ( $products_data as $d ) {
            $this->product_data_map[ $d['id'] ] = $d;
        }
    }

    protected function category_names( WC_Product $product ): array {
        return $this->product_data_map[ $product->get_id() ]['categories'] ?? [];
    }

    protected function tag_names( WC_Product $product ): array {
        return [];
    }
}

// ---- Load the real enrichment stub -------------------------------------------

require_once __DIR__ . '/../includes/class-asc-enrichment-stub.php';

$stub     = new DemoEnrichmentStub( $products_data );
$profiles = [];

foreach ( $_demo_products as $product ) {
    $profiles[ $product->get_id() ] = $stub->enrich( $product->get_id() );
}

// ---- Knowledge graph (pre-seeded example data) --------------------------------

$substitutes = [
    ['from' => 1001, 'to' => 1008, 'from_name' => 'פרמזן',  'to_name' => 'גרויאר',
     'reason'      => 'Both are hard cheeses with strong umami and nuttiness, suitable for grating over pasta and baked dishes.',
     'differences' => 'Gruyere melts more smoothly; Parmesan has stronger salt and nuttiness; Gruyere lacks Parmesan\'s grainy texture.',
     'confidence'  => 0.78 ],
    ['from' => 1002, 'to' => 1007, 'from_name' => 'מוצרלה', 'to_name' => 'ריקוטה',
     'reason'      => 'Both are fresh Italian cheeses with mild, milky flavor; both work in pizza, pasta, and baked dishes.',
     'differences' => 'Ricotta is grainy and spreadable; Mozzarella has smooth stretch when melted; substitute changes dish texture.',
     'confidence'  => 0.70 ],
    ['from' => 1004, 'to' => 1009, 'from_name' => 'פטה',    'to_name' => 'בולגרית',
     'reason'      => 'Both are brined white cheeses with salty, tangy flavor suitable for salads, mezze, and grain bowls.',
     'differences' => 'Bulgarian is creamier and milder; Feta is drier and more crumbly; adjust salt in the recipe when substituting.',
     'confidence'  => 0.82 ],
    ['from' => 1006, 'to' => 1003, 'from_name' => "צ'דר",   'to_name' => 'גאודה',
     'reason'      => 'Both are semi-hard cheeses that melt well, suitable for grilled dishes, burgers, and baked toppings.',
     'differences' => 'Cheddar is sharper and more acidic; Gouda is creamier and slightly sweeter; Gouda melts more smoothly.',
     'confidence'  => 0.75 ],
];

// ---- Recipe intelligence (pre-seeded examples) --------------------------------

$recipes = [
    [
        'name'        => 'פסטה עם גבינת פרמזן ועגבניות',
        'dish_type'   => 'pasta',
        'servings'    => 4,
        'ingredients' => [
            ['name' => 'פסטה ספגטי',      'quantity' => '500', 'unit' => 'ג',    'matched_product_id' => null,  'confidence' => 0.0],
            ['name' => 'עגבניות מרוסקות',  'quantity' => '400', 'unit' => 'ג',    'matched_product_id' => null,  'confidence' => 0.0],
            ['name' => 'גבינת פרמזן מגורדת', 'quantity' => '50', 'unit' => 'ג', 'matched_product_id' => 1001, 'confidence' => 0.65],
            ['name' => 'שמן זית',           'quantity' => '3',   'unit' => 'כף',  'matched_product_id' => null,  'confidence' => 0.0],
            ['name' => 'שום',               'quantity' => '3',   'unit' => 'שיני', 'matched_product_id' => null, 'confidence' => 0.0],
        ],
    ],
    [
        'name'        => 'פיצה ביתית עם מוצרלה',
        'dish_type'   => 'pizza',
        'servings'    => 4,
        'ingredients' => [
            ['name' => 'קמח לחם',           'quantity' => '400', 'unit' => 'ג',    'matched_product_id' => null,  'confidence' => 0.0],
            ['name' => 'רוטב עגבניות',       'quantity' => '300', 'unit' => 'ג',    'matched_product_id' => null,  'confidence' => 0.0],
            ['name' => 'גבינת מוצרלה טריה',  'quantity' => '250', 'unit' => 'ג',   'matched_product_id' => 1002, 'confidence' => 0.65],
            ['name' => 'שמן זית',            'quantity' => '2',   'unit' => 'כף',  'matched_product_id' => null,  'confidence' => 0.0],
        ],
    ],
    [
        'name'        => 'סלט יווני עם פטה',
        'dish_type'   => 'salad',
        'servings'    => 2,
        'ingredients' => [
            ['name' => 'מלפפון',            'quantity' => '2',   'unit' => 'יחידות', 'matched_product_id' => null, 'confidence' => 0.0],
            ['name' => 'עגבניות',            'quantity' => '3',   'unit' => 'יחידות', 'matched_product_id' => null, 'confidence' => 0.0],
            ['name' => 'גבינת פטה יוונית',   'quantity' => '100', 'unit' => 'ג',    'matched_product_id' => 1004, 'confidence' => 0.65],
            ['name' => 'זיתים שחורים',       'quantity' => '50',  'unit' => 'ג',    'matched_product_id' => null,  'confidence' => 0.0],
            ['name' => 'שמן זית',            'quantity' => '2',   'unit' => 'כף',  'matched_product_id' => null,  'confidence' => 0.0],
        ],
    ],
];

// ---- Render output -----------------------------------------------------------

$esc = function( string $s ): string {
    return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
};

$running_in_browser = isset( $_SERVER['HTTP_HOST'] ) || (isset( $argv[1] ) && $argv[1] === '--html');

if ( $running_in_browser ) {
    // HTML output for browser
    echo '<!DOCTYPE html><html lang="he" dir="rtl"><head><meta charset="UTF-8">';
    echo '<title>ASC Enrichment Demo — V3 R2</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:1100px;margin:0 auto;padding:24px;direction:rtl}';
    echo 'h1{color:#1a237e}h2{color:#283593;border-bottom:2px solid #3f51b5;padding-bottom:6px}';
    echo '.product-card{background:#f5f7ff;border:1px solid #c5cae9;border-radius:8px;padding:16px;margin-bottom:24px}';
    echo '.product-name{font-size:1.1em;font-weight:bold;color:#1a237e}';
    echo '.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold}';
    echo '.badge-review{background:#fff3cd;color:#856404}.badge-safe{background:#d4edda;color:#155724}';
    echo '.fields-table{border-collapse:collapse;width:100%;margin-top:8px}';
    echo '.fields-table th,.fields-table td{padding:5px 8px;border:1px solid #c5cae9;font-size:12px}';
    echo '.fields-table th{background:#e8eaf6}.conf-high{color:#2e7d32}.conf-mid{color:#e65100}.conf-low{color:#c62828}';
    echo '.sub-card{background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:12px;margin-bottom:12px}';
    echo '.recipe-card{background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:12px;margin-bottom:12px}';
    echo '</style></head><body>';
    echo '<h1>ASC Enrichment Report — V3 Knowledge R2</h1>';
    echo '<p>Generated: ' . date('Y-m-d H:i') . ' &nbsp;|&nbsp; Branch: feat/v3-knowledge-r2 &nbsp;|&nbsp; Plugin: v0.2.0</p>';
    echo '<p>Enrichment pipeline ran against <strong>' . count( $profiles ) . ' nfarm-style cheese products</strong>. ';
    echo 'All inferred fields are <span class="badge badge-review">review_required</span>. ';
    echo 'Allergens and ingredients are always <span class="badge badge-safe">safety_blank</span>.</p>';

    echo '<h2>Product Enrichment Profiles</h2>';

    foreach ( $profiles as $pid => $profile ) {
        $product  = $_demo_products[ $pid ];
        $inferred = $profile['inferred'];
        $nr_count = $profile['review_summary']['needs_review'];
        $nr_total = $profile['review_summary']['total_fields'];

        echo '<div class="product-card">';
        echo '<div class="product-name">' . $esc( $product->get_name() ) . ' &nbsp; <small style="color:#666">SKU: ' . $esc( $product->get_sku() ) . ' &nbsp;|&nbsp; ₪' . $esc( $product->get_price() ) . '</small></div>';
        echo '<table class="fields-table"><tr><th>שדה</th><th>ערך (inferred)</th><th>מקור</th><th>ביטחון</th><th>צריך בדיקה</th></tr>';

        $field_map = [
            'product_type'         => 'סוג מוצר',
            'sub_type'             => 'תת-סוג',
            'flavor_profile'       => 'פרופיל טעם',
            'texture'              => 'מרקם',
            'meltable'             => 'נמסה?',
            'storage_method'       => 'שיטת אחסון',
            'origin_country'       => 'מדינת מקור',
            'milk_type'            => 'סוג חלב',
            'kosher_certification' => 'כשרות',
            'allergens'            => 'אלרגנים',
            'ingredients'          => 'רכיבים',
            'servings_per_pack'    => 'מנות לאריזה',
        ];

        foreach ( $field_map as $key => $label ) {
            $f    = $inferred[ $key ] ?? null;
            if ( ! $f ) continue;

            $val  = $f['value'];
            $conf = (float) ($f['confidence'] ?? 0);
            $src  = $f['source'] ?? '';
            $rev  = ! empty( $f['review_required'] );

            if ( is_bool( $val ) )      $val_str = $val ? 'כן' : 'לא';
            elseif ( is_array( $val ) ) $val_str = empty( $val ) ? '[]' : json_encode( $val, JSON_UNESCAPED_UNICODE );
            elseif ( is_null( $val ) )  $val_str = '—';
            else                        $val_str = (string) $val;

            $conf_class = $conf >= 0.7 ? 'conf-high' : ( $conf >= 0.4 ? 'conf-mid' : 'conf-low' );
            $rev_badge  = $rev ? '<span class="badge badge-review">✗ review</span>' : '<span class="badge badge-safe">✓ ok</span>';

            echo "<tr><td>{$label}</td><td>{$esc($val_str)}</td><td><small>{$esc($src)}</small></td>";
            echo "<td class='{$conf_class}'>" . number_format( $conf * 100, 0 ) . '%</td>';
            echo "<td>{$rev_badge}</td></tr>";
        }

        echo '</table>';
        echo "<p style='margin:8px 0 0;color:#666;font-size:12px'>Needs review: <strong>{$nr_count}/{$nr_total}</strong> fields | ";
        echo 'Fields: ' . implode( ', ', $profile['review_summary']['fields_needing_review'] ) . '</p>';
        echo '</div>';
    }

    echo '<h2>Knowledge Graph — Substitutes</h2>';
    echo '<p>Every substitute carries a reason (why it works) and differences (what changes for the cook). No similarity score alone.</p>';
    foreach ( $substitutes as $s ) {
        $conf_pct = number_format( $s['confidence'] * 100, 0 );
        echo '<div class="sub-card">';
        echo "<strong>{$esc($s['from_name'])}</strong> &rarr; <strong>{$esc($s['to_name'])}</strong> ";
        echo "<span class='badge badge-review'>confidence {$conf_pct}% · review_required</span>";
        echo "<p><strong>סיבה (reason):</strong> {$esc($s['reason'])}</p>";
        echo "<p><strong>הבדלים (differences):</strong> {$esc($s['differences'])}</p>";
        echo '</div>';
    }

    echo '<h2>Recipe Intelligence</h2>';
    echo '<p>Recipes decomposed to ingredients; each ingredient matched to a WooCommerce product via name search (stub tier). All matches are review_required.</p>';
    foreach ( $recipes as $r ) {
        $matched = count( array_filter( $r['ingredients'], fn($i) => ! is_null($i['matched_product_id']) ) );
        echo '<div class="recipe-card">';
        echo "<strong>{$esc($r['name'])}</strong> &nbsp; <small>{$esc($r['dish_type'])} · {$r['servings']} מנות</small>";
        echo "<p>רכיבים ({$matched}/" . count($r['ingredients']) . " קושרו למוצר):</p>";
        echo '<ul>';
        foreach ( $r['ingredients'] as $ing ) {
            $matched_note = '';
            if ( $ing['matched_product_id'] ) {
                $pn = $_demo_products[ $ing['matched_product_id'] ]->get_name();
                $pct = number_format( $ing['confidence'] * 100, 0 );
                $matched_note = " → <strong>#{$ing['matched_product_id']} {$esc($pn)}</strong> <span class='badge badge-review'>{$pct}% · review</span>";
            } else {
                $matched_note = " <span class='badge' style='background:#f5f5f5;color:#666'>לא קושר</span>";
            }
            echo "<li>{$esc($ing['quantity'])} {$esc($ing['unit'])} {$esc($ing['name'])}{$matched_note}</li>";
        }
        echo '</ul></div>';
    }

    echo '<h2>Summary</h2>';
    $total_review = 0;
    $total_fields = 0;
    foreach ( $profiles as $p ) {
        $total_review += $p['review_summary']['needs_review'];
        $total_fields += $p['review_summary']['total_fields'];
    }
    echo "<p>Products enriched: <strong>" . count($profiles) . "</strong><br>";
    echo "Total inferred fields: <strong>{$total_fields}</strong><br>";
    echo "Fields needing review: <strong>{$total_review}</strong> (" . number_format($total_review/$total_fields*100,0) . "% — expected; stub tier always marks everything review_required)<br>";
    echo "Substitutes seeded: <strong>" . count($substitutes) . "</strong><br>";
    echo "Recipes with ingredient linking: <strong>" . count($recipes) . "</strong></p>";
    echo '</body></html>';

} else {
    // Terminal output
    echo "\n=== ASC Enrichment Demo — V3 Knowledge R2 ===\n";
    echo "Products: " . count($profiles) . " | Branch: feat/v3-knowledge-r2 | Plugin: v0.2.0\n\n";

    foreach ( $profiles as $pid => $profile ) {
        $product = $_demo_products[$pid];
        echo "--- " . $product->get_name() . " (#$pid) ---\n";
        echo "  schema_version: " . $profile['schema_version'] . "\n";
        echo "  enrichment_source: " . $profile['enrichment_source'] . "\n";
        echo "  approval_state: " . $profile['approval_state'] . "\n";

        $inferred = $profile['inferred'];
        $fields_to_show = ['product_type','sub_type','flavor_profile','texture','meltable','storage_method','allergens','ingredients'];
        foreach ( $fields_to_show as $k ) {
            $f = $inferred[$k] ?? null;
            if ( !$f ) continue;
            $val = $f['value'];
            if ( is_bool($val) ) $val = $val ? 'true' : 'false';
            if ( is_array($val) ) $val = '[]';
            if ( is_null($val) ) $val = 'null';
            $rev = !empty($f['review_required']) ? ' [REVIEW]' : ' [ok]';
            $pct = number_format( ($f['confidence']??0)*100, 0 );
            echo "  $k: $val (conf: $pct%$rev)\n";
        }
        echo "  needs_review: {$profile['review_summary']['needs_review']}/{$profile['review_summary']['total_fields']}\n\n";
    }

    echo "--- Substitutes ---\n";
    foreach ( $substitutes as $s ) {
        echo "  {$s['from_name']} -> {$s['to_name']} (conf: " . number_format($s['confidence']*100,0) . "%)\n";
        echo "    reason: {$s['reason']}\n";
        echo "    differences: {$s['differences']}\n\n";
    }

    echo "--- Recipes ---\n";
    foreach ( $recipes as $r ) {
        $matched = count(array_filter($r['ingredients'], fn($i) => !is_null($i['matched_product_id'])));
        echo "  {$r['name']} ($matched/" . count($r['ingredients']) . " ingredients matched)\n";
    }
    echo "\n";
}
