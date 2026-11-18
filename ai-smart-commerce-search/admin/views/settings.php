<?php
defined( 'ABSPATH' ) || exit;

$settings = ASC_Settings::instance()->all();
?>
<div class="wrap asc-settings-wrap" dir="rtl">
    <h1><?php esc_html_e( 'AI Smart Search Settings', 'ai-smart-commerce-search' ); ?></h1>

    <?php settings_errors( 'asc_settings_group' ); ?>

    <form method="post" action="options.php" id="asc-settings-form">
        <?php settings_fields( 'asc_settings_group' ); ?>

        <!-- ======================================================= -->
        <!-- Step 1 — Enable / Disable                               -->
        <!-- ======================================================= -->
        <div class="asc-wizard-step">
            <h2 class="asc-step-title">
                <span class="asc-step-num">1</span>
                <?php esc_html_e( 'Search Drawer', 'ai-smart-commerce-search' ); ?>
            </h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="asc_enabled">
                            <?php esc_html_e( 'Enable AI Smart Search', 'ai-smart-commerce-search' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox"
                               id="asc_enabled"
                               name="asc_settings[enabled]"
                               value="1"
                               <?php checked( $settings['enabled'] ); ?>>
                        <p class="description">
                            <?php esc_html_e( 'Uncheck to disable the drawer and restore native site search.', 'ai-smart-commerce-search' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ======================================================= -->
        <!-- Step 2 — Cloud Setup Wizard                             -->
        <!-- ======================================================= -->
        <div class="asc-wizard-step">
            <h2 class="asc-step-title">
                <span class="asc-step-num">2</span>
                <?php esc_html_e( 'Cloud Setup', 'ai-smart-commerce-search' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Leave blank to run in local-search-only mode (no AI features).', 'ai-smart-commerce-search' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="asc_project_id">
                            <?php esc_html_e( 'Project ID', 'ai-smart-commerce-search' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               id="asc_project_id"
                               name="asc_settings[cloud_project_id]"
                               value="<?php echo esc_attr( $settings['cloud_project_id'] ); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="asc_environment">
                            <?php esc_html_e( 'Environment', 'ai-smart-commerce-search' ); ?>
                        </label>
                    </th>
                    <td>
                        <select id="asc_environment" name="asc_settings[cloud_environment]">
                            <?php foreach ( [ 'production' => esc_html__( 'Production', 'ai-smart-commerce-search' ), 'staging' => esc_html__( 'Testing', 'ai-smart-commerce-search' ), 'development' => esc_html__( 'Development', 'ai-smart-commerce-search' ) ] as $val => $label ): ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['cloud_environment'], $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="asc_region">
                            <?php esc_html_e( 'Region', 'ai-smart-commerce-search' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               id="asc_region"
                               name="asc_settings[cloud_region]"
                               value="<?php echo esc_attr( $settings['cloud_region'] ); ?>"
                               class="regular-text"
                               placeholder="us-central1">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="asc_tenant_id">
                            <?php esc_html_e( 'Tenant ID', 'ai-smart-commerce-search' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               id="asc_tenant_id"
                               name="asc_settings[cloud_tenant_id]"
                               value="<?php echo esc_attr( $settings['cloud_tenant_id'] ); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="asc_api_url">
                            <?php esc_html_e( 'Cloud API Base URL', 'ai-smart-commerce-search' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url"
                               id="asc_api_url"
                               name="asc_settings[cloud_api_url]"
                               value="<?php echo esc_url( $settings['cloud_api_url'] ); ?>"
                               class="regular-text"
                               placeholder="https://your-cloud-run-service.run.app">
                        <p class="description">
                            <?php esc_html_e( 'Cloud Run service URL. Leave empty to run in local mode.', 'ai-smart-commerce-search' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Connection Check', 'ai-smart-commerce-search' ); ?></th>
                    <td>
                        <button type="button" id="asc-test-connection" class="button button-secondary">
                            <?php esc_html_e( 'Connection Check', 'ai-smart-commerce-search' ); ?>
                        </button>
                        <span id="asc-test-result" class="asc-test-result"></span>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button( esc_html__( 'Save Settings', 'ai-smart-commerce-search' ) ); ?>
    </form>
</div>
