<?php
defined( 'ABSPATH' ) || exit;

class ASC_Settings {

    private static ?self $instance = null;
    private array $settings;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $saved = get_option( 'asc_settings', [] );
        $this->settings = wp_parse_args( $saved, self::defaults() );
    }

    public static function defaults(): array {
        return [
            'enabled'          => true,
            'cloud_project_id' => '',
            'cloud_environment'=> 'production',
            'cloud_region'     => 'us-central1',
            'cloud_tenant_id'  => '',
            'cloud_api_url'    => '',
        ];
    }

    public function get( string $key, $fallback = null ) {
        return $this->settings[ $key ] ?? $fallback;
    }

    public function all(): array {
        return $this->settings;
    }

    public function update( array $new_values ): bool {
        $allowed = array_keys( self::defaults() );
        foreach ( $new_values as $k => $v ) {
            if ( in_array( $k, $allowed, true ) ) {
                $this->settings[ $k ] = $v;
            }
        }
        return update_option( 'asc_settings', $this->settings );
    }

    /** Is the plugin's search drawer active? */
    public function is_enabled(): bool {
        return (bool) $this->get( 'enabled', true );
    }

    /** Is the cloud backend configured (project id + api url present)? */
    public function is_cloud_configured(): bool {
        return ! empty( $this->get( 'cloud_project_id' ) )
            && ! empty( $this->get( 'cloud_api_url' ) );
    }
}
