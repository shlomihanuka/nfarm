<?php
defined( 'ABSPATH' ) || exit;

class AICS_Woo_Adapter {

	public function search( $query, $page = 1 ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->empty_response();
		}

		$consumer_key    = get_option( 'aics_wc_consumer_key', '' );
		$consumer_secret = get_option( 'aics_wc_consumer_secret', '' );

		if ( $consumer_key && $consumer_secret ) {
			$result = $this->search_via_rest_api( $query, $page, $consumer_key, $consumer_secret );
			if ( $result ) {
				return $result;
			}
		}

		return $this->search_via_wc_query( $query, $page );
	}

	private function search_via_rest_api( $query, $page, $key, $secret ) {
		$endpoint = get_site_url() . '/wp-json/wc/v3/products';
		$params   = array(
			'search'   => $query,
			'per_page' => 12,
			'page'     => $page,
			'status'   => 'publish',
		);

		$response = wp_remote_get(
			$endpoint . '?' . http_build_query( $params ),
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $key . ':' . $secret ),
				),
				'timeout'   => 15,
				'sslverify' => apply_filters( 'aics_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		$products = array_map( array( $this, 'format_rest_product' ), $body );
		return array( 'products' => $products, 'total' => count( $products ), 'source' => 'wc_rest' );
	}

	private function search_via_wc_query( $query, $page ) {
		$per_page = 12;
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		$title_query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			's'              => $query,
			'fields'         => 'ids',
		) );

		$sku_query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_sku',
					'value'   => $query,
					'compare' => 'LIKE',
				),
			),
		) );

		$ids = array_unique( array_merge( $title_query->posts, $sku_query->posts ) );
		$ids = array_slice( $ids, 0, $per_page );

		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product ) {
				$products[] = $this->format_product_object( $product );
			}
		}

		return array( 'products' => $products, 'total' => count( $products ), 'source' => 'wc_query' );
	}

	private function empty_response() {
		return array( 'products' => array(), 'total' => 0, 'source' => 'none' );
	}

	private function format_rest_product( $p ) {
		$image      = ! empty( $p['images'][0]['src'] ) ? $p['images'][0]['src'] : '';
		$is_on_sale = isset( $p['sale_price'] ) && '' !== $p['sale_price'];

		$attributes = array();
		if ( ! empty( $p['attributes'] ) ) {
			foreach ( $p['attributes'] as $attr ) {
				if ( ! empty( $attr['variation'] ) ) {
					$attributes[] = array(
						'id'      => $attr['id'],
						'name'    => $attr['name'],
						'options' => $attr['options'],
					);
				}
			}
		}

		$categories = array();
		if ( ! empty( $p['categories'] ) ) {
			foreach ( $p['categories'] as $cat ) {
				$categories[] = $cat['name'];
			}
		}

		return array(
			'id'             => (int) $p['id'],
			'name'           => $p['name'],
			'type'           => $p['type'],
			'permalink'      => $p['permalink'],
			'image'          => $image,
			'price'          => $p['price'],
			'regular_price'  => $p['regular_price'],
			'sale_price'     => $is_on_sale ? $p['sale_price'] : '',
			'is_on_sale'     => $is_on_sale,
			'price_html'     => $p['price_html'],
			'currency'       => get_woocommerce_currency_symbol(),
			'in_stock'       => isset( $p['stock_status'] ) && 'instock' === $p['stock_status'],
			'stock_status'   => $p['stock_status'] ?? 'instock',
			'is_weighed'     => $this->detect_weighed_rest( $p ),
			'attributes'     => $attributes,
			'has_variations' => 'variable' === $p['type'] && ! empty( $p['variations'] ),
			'sku'            => $p['sku'],
			'categories'     => $categories,
		);
	}

	private function format_product_object( WC_Product $product ) {
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_url( $image_id ) : wc_placeholder_img_src();

		$is_on_sale = $product->is_on_sale();

		$attributes = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
				$attributes[] = array(
					'id'      => 0,
					'name'    => wc_attribute_label( $attr_name ),
					'options' => $options,
				);
			}
		}

		$cat_names = array();
		$terms     = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$cat_names = wp_list_pluck( $terms, 'name' );
		}

		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'type'           => $product->get_type(),
			'permalink'      => get_permalink( $product->get_id() ),
			'image'          => $image_url ?: '',
			'price'          => $product->get_price(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $is_on_sale ? $product->get_sale_price() : '',
			'is_on_sale'     => $is_on_sale,
			'price_html'     => $product->get_price_html(),
			'currency'       => get_woocommerce_currency_symbol(),
			'in_stock'       => $product->is_in_stock(),
			'stock_status'   => $product->get_stock_status(),
			'is_weighed'     => $this->detect_weighed_product( $product ),
			'attributes'     => $attributes,
			'has_variations' => $product->is_type( 'variable' ),
			'sku'            => $product->get_sku(),
			'categories'     => $cat_names,
		);
	}

	private function detect_weighed_rest( $p ) {
		if ( ! empty( $p['meta_data'] ) ) {
			foreach ( $p['meta_data'] as $meta ) {
				if ( in_array( $meta['key'], array( '_sold_individually_by_weight', '_price_by_weight' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function detect_weighed_product( WC_Product $product ) {
		$meta_keys = array( '_sold_individually_by_weight', '_price_by_weight', 'wc_measurement_price_calculator_pricing' );
		foreach ( $meta_keys as $key ) {
			if ( $product->get_meta( $key ) ) {
				return true;
			}
		}
		return false;
	}
}
