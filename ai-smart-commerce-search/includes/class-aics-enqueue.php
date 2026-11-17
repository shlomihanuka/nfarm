<?php
defined( 'ABSPATH' ) || exit;

class AICS_Enqueue {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
	}

	public function enqueue_frontend() {
		if ( ! get_option( 'aics_enabled', '1' ) ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wp_enqueue_style(
			'aics-drawer',
			AICS_PLUGIN_URL . 'build/index.css',
			array(),
			AICS_VERSION
		);

		wp_enqueue_script(
			'aics-drawer',
			AICS_PLUGIN_URL . 'build/index.js',
			array(),
			AICS_VERSION,
			true
		);

		wp_localize_script( 'aics-drawer', 'AICS_Config', array(
			'enabled'  => (bool) get_option( 'aics_enabled', '1' ),
			'restUrl'  => rest_url( 'aics/v1' ),
			'storeUrl' => rest_url( 'wc/store/v1' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '&#8362;',
			'isRTL'    => is_rtl(),
			'strings'  => array(
				'searchPlaceholder' => __( 'חפש מוצרים...', 'ai-smart-commerce-search' ),
				'close'             => __( 'סגור', 'ai-smart-commerce-search' ),
				'addToCart'         => __( 'הוסף לסל', 'ai-smart-commerce-search' ),
				'addedToCart'       => __( 'נוסף לסל', 'ai-smart-commerce-search' ),
				'inStock'           => __( 'במלאי', 'ai-smart-commerce-search' ),
				'outOfStock'        => __( 'אזל מהמלאי', 'ai-smart-commerce-search' ),
				'noResults'         => __( 'לא נמצאו תוצאות', 'ai-smart-commerce-search' ),
				'searching'         => __( 'מחפש...', 'ai-smart-commerce-search' ),
				'quantity'          => __( 'כמות', 'ai-smart-commerce-search' ),
				'selectOption'      => __( 'בחר אפשרות', 'ai-smart-commerce-search' ),
				'viewProduct'       => __( 'לעמוד המוצר', 'ai-smart-commerce-search' ),
				'weightedPriceNote' => __( 'המחיר הסופי נקבע בשקילה', 'ai-smart-commerce-search' ),
				'typeToSearch'      => __( 'הקלד לחיפוש מוצרים', 'ai-smart-commerce-search' ),
				'errorAdding'       => __( 'שגיאה בהוספה לסל', 'ai-smart-commerce-search' ),
				'selectVariation'   => __( 'יש לבחור את כל האפשרויות', 'ai-smart-commerce-search' ),
			),
		) );
	}
}
