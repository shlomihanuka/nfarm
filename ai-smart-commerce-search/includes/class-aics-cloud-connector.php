<?php
defined( 'ABSPATH' ) || exit;

class AICS_Cloud_Connector {

	public function test_connection() {
		$base_url = get_option( 'aics_cloud_api_url', '' );

		if ( empty( $base_url ) ) {
			return array( 'status' => 'unavailable', 'message' => 'API Unavailable' );
		}

		$health_url  = rtrim( $base_url, '/' ) . '/v1/health';
		$auth_method = get_option( 'aics_auth_method', 'api_key' );

		$headers = array( 'Content-Type' => 'application/json' );

		if ( 'api_key' === $auth_method ) {
			$api_key = get_option( 'aics_api_key', '' );
			if ( $api_key ) {
				$headers['X-API-Key'] = $api_key;
			}
		}

		$response = wp_remote_get( $health_url, array(
			'headers' => $headers,
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unavailable', 'message' => 'API Unavailable' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array( 'status' => 'connected', 'message' => 'Connected' );
		} elseif ( 401 === $code ) {
			return array( 'status' => 'auth_failed', 'message' => 'Authentication Failed' );
		} elseif ( 403 === $code ) {
			return array( 'status' => 'permission_missing', 'message' => 'Permission Missing' );
		}

		return array( 'status' => 'unavailable', 'message' => 'API Unavailable' );
	}
}
