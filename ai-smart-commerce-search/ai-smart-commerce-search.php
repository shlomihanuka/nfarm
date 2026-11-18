<?php
/**
 * Plugin Name: AI Smart Commerce Search
 * Plugin URI:  https://notax.co.il
 * Description: AI-driven search, substitutes, recommendations and purchase assistance for WooCommerce stores.
 * Version:     0.1.1
 * Author:      MYCO
 * Text Domain: ai-smart-commerce-search
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'ASC_VERSION',     '0.1.1' );
define( 'ASC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ASC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'ASC_PLUGIN_FILE', __FILE__ );

/* ------------------------------------------------------------------ */
/* Load translations                                                   */
/* ------------------------------------------------------------------ */
function asc_load_textdomain() {
    load_plugin_textdomain(
        'ai-smart-commerce-search',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}
add_action( 'plugins_loaded', 'asc_load_textdomain' );

/* ------------------------------------------------------------------ */
/* Declare WooCommerce HPOS compatibility                              */
/* ------------------------------------------------------------------ */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
} );

/* ------------------------------------------------------------------ */
/* Core includes                                                       */
/* ------------------------------------------------------------------ */
require_once ASC_PLUGIN_DIR . 'includes/class-asc-settings.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-search.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-woocommerce.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-cloud.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-voice.php';

if ( is_admin() ) {
    require_once ASC_PLUGIN_DIR . 'admin/class-asc-admin.php';
}

/* ------------------------------------------------------------------ */
/* Bootstrap                                                           */
/* ------------------------------------------------------------------ */
function asc_init() {
    // Guard: WooCommerce must be active.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>' .
                esc_html__( 'AI Smart Commerce Search requires WooCommerce. Please install and activate it.', 'ai-smart-commerce-search' ) .
                '</p></div>';
        } );
        return;
    }

    ASC_Settings::instance();
    ASC_Search::instance();
    ASC_WooCommerce::instance();
    ASC_Cloud::instance();
    ASC_Voice::instance();

    if ( is_admin() ) {
        ASC_Admin::instance();
    }
}
add_action( 'plugins_loaded', 'asc_init', 20 );

/* ------------------------------------------------------------------ */
/* Activation / deactivation hooks                                     */
/* ------------------------------------------------------------------ */
register_activation_hook( __FILE__, function() {
    if ( ! get_option( 'asc_settings' ) ) {
        update_option( 'asc_settings', ASC_Settings::defaults() );
    }
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );
