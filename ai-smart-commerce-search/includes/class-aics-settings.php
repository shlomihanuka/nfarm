<?php
defined( 'ABSPATH' ) || exit;

class AICS_Settings {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
	}

	public function maybe_save() {
		if ( ! isset( $_POST['aics_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! check_admin_referer( 'aics_save_settings', 'aics_nonce' ) ) {
			return;
		}

		$text_fields = array(
			'aics_gcp_project_id',
			'aics_gcp_region',
			'aics_gcp_tenant_id',
			'aics_api_key',
			'aics_wc_consumer_key',
			'aics_wc_consumer_secret',
		);
		foreach ( $text_fields as $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_option( $field, $value );
		}

		$select_fields = array( 'aics_gcp_environment', 'aics_auth_method' );
		foreach ( $select_fields as $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_key( wp_unslash( $_POST[ $field ] ) ) : '';
			update_option( $field, $value );
		}

		$url = isset( $_POST['aics_cloud_api_url'] )
			? esc_url_raw( sanitize_text_field( wp_unslash( $_POST['aics_cloud_api_url'] ) ) )
			: '';
		update_option( 'aics_cloud_api_url', $url );

		update_option( 'aics_enabled', isset( $_POST['aics_enabled'] ) ? '1' : '0' );

		set_transient( 'aics_settings_saved', true, 30 );
	}

	public static function get( $key, $default = '' ) {
		return get_option( 'aics_' . $key, $default );
	}
}
