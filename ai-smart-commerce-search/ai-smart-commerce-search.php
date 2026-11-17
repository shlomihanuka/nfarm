<?php
/**
 * Plugin Name:       AI Smart Commerce Search
 * Plugin URI:        https://github.com/myco-platform/ai-smart-commerce-search
 * Description:       AI-powered search drawer for WooCommerce with Hebrew RTL support and Google Cloud AI integration.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MYCO Platform
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-smart-commerce-search
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'AICS_VERSION', '0.1.0' );
define( 'AICS_PLUGIN_FILE', __FILE__ );
define( 'AICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AICS_PLUGIN_DIR . 'includes/class-aics-settings.php';
require_once AICS_PLUGIN_DIR . 'includes/class-aics-woo-adapter.php';
require_once AICS_PLUGIN_DIR . 'includes/class-aics-cloud-connector.php';
require_once AICS_PLUGIN_DIR . 'includes/class-aics-enqueue.php';
require_once AICS_PLUGIN_DIR . 'includes/class-aics-plugin.php';

register_activation_hook( __FILE__, array( 'AICS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AICS_Plugin', 'deactivate' ) );

AICS_Plugin::get_instance();
