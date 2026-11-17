<?php
defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai-smart-commerce-search' ) );
}

$saved = get_transient( 'aics_settings_saved' );
if ( $saved ) {
	delete_transient( 'aics_settings_saved' );
}

$enabled         = get_option( 'aics_enabled', '1' );
$gcp_project_id  = get_option( 'aics_gcp_project_id', '' );
$gcp_environment = get_option( 'aics_gcp_environment', 'development' );
$gcp_region      = get_option( 'aics_gcp_region', '' );
$gcp_tenant_id   = get_option( 'aics_gcp_tenant_id', '' );
$cloud_api_url   = get_option( 'aics_cloud_api_url', '' );
$auth_method     = get_option( 'aics_auth_method', 'api_key' );
$api_key         = get_option( 'aics_api_key', '' );
$wc_key          = get_option( 'aics_wc_consumer_key', '' );
$wc_secret       = get_option( 'aics_wc_consumer_secret', '' );
?>
<div class="wrap aics-admin-wrap">
	<h1><?php esc_html_e( 'AI Commerce — Settings', 'ai-smart-commerce-search' ); ?></h1>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved successfully.', 'ai-smart-commerce-search' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'aics_save_settings', 'aics_nonce' ); ?>

		<!-- General -->
		<div class="aics-section card">
			<h2><?php esc_html_e( 'General', 'ai-smart-commerce-search' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable AI Smart Search', 'ai-smart-commerce-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="aics_enabled" value="1" <?php checked( '1', $enabled ); ?> />
							<?php esc_html_e( 'Enable the AI search drawer (uncheck to restore native WordPress/WooCommerce search)', 'ai-smart-commerce-search' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When disabled, all search fields revert to their default behaviour with zero interference from this plugin.', 'ai-smart-commerce-search' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Step 1: GCP Project -->
		<div class="aics-section card">
			<h2><?php esc_html_e( 'Step 1 — Google Cloud Project', 'ai-smart-commerce-search' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="aics_gcp_project_id"><?php esc_html_e( 'Google Cloud Project ID', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<input type="text" name="aics_gcp_project_id" id="aics_gcp_project_id"
							value="<?php echo esc_attr( $gcp_project_id ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="aics_gcp_environment"><?php esc_html_e( 'Environment', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<select name="aics_gcp_environment" id="aics_gcp_environment">
							<option value="development" <?php selected( 'development', $gcp_environment ); ?>><?php esc_html_e( 'Development', 'ai-smart-commerce-search' ); ?></option>
							<option value="staging"     <?php selected( 'staging',     $gcp_environment ); ?>><?php esc_html_e( 'Staging', 'ai-smart-commerce-search' ); ?></option>
							<option value="production"  <?php selected( 'production',  $gcp_environment ); ?>><?php esc_html_e( 'Production', 'ai-smart-commerce-search' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="aics_gcp_region"><?php esc_html_e( 'Region', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<input type="text" name="aics_gcp_region" id="aics_gcp_region"
							value="<?php echo esc_attr( $gcp_region ); ?>" class="regular-text"
							placeholder="e.g. us-central1" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="aics_gcp_tenant_id"><?php esc_html_e( 'Tenant ID', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<input type="text" name="aics_gcp_tenant_id" id="aics_gcp_tenant_id"
							value="<?php echo esc_attr( $gcp_tenant_id ); ?>" class="regular-text" />
					</td>
				</tr>
			</table>
		</div>

		<!-- Step 2: Backend Connection -->
		<div class="aics-section card">
			<h2><?php esc_html_e( 'Step 2 — Backend Connection', 'ai-smart-commerce-search' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="aics_cloud_api_url"><?php esc_html_e( 'Cloud API Base URL', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<input type="url" name="aics_cloud_api_url" id="aics_cloud_api_url"
							value="<?php echo esc_attr( $cloud_api_url ); ?>" class="regular-text"
							placeholder="https://your-api.example.com" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="aics_auth_method"><?php esc_html_e( 'Authentication Method', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<select name="aics_auth_method" id="aics_auth_method">
							<option value="api_key"        <?php selected( 'api_key',        $auth_method ); ?>><?php esc_html_e( 'API Key', 'ai-smart-commerce-search' ); ?></option>
							<option value="service_account" <?php selected( 'service_account', $auth_method ); ?>><?php esc_html_e( 'Service Account', 'ai-smart-commerce-search' ); ?></option>
						</select>
					</td>
				</tr>
				<tr id="aics-api-key-row"<?php echo 'api_key' !== $auth_method ? ' style="display:none"' : ''; ?>>
					<th scope="row">
						<label for="aics_api_key"><?php esc_html_e( 'API Key', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<input type="password" name="aics_api_key" id="aics_api_key"
							value="<?php echo esc_attr( $api_key ); ?>" class="regular-text"
							autocomplete="new-password" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test Connection', 'ai-smart-commerce-search' ); ?></th>
					<td>
						<button type="button" id="aics-test-btn" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'ai-smart-commerce-search' ); ?>
						</button>
						<span id="aics-test-status" style="margin-right:12px;font-weight:600"></span>
					</td>
				</tr>
			</table>
		</div>

		<!-- WooCommerce Credentials -->
		<div class="aics-section card">
			<h2><?php esc_html_e( 'WooCommerce API Credentials', 'ai-smart-commerce-search' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Required for local product search. Create a Read-only key in WooCommerce → Settings → Advanced → REST API.', 'ai-smart-commerce-search' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="aics_wc_consumer_key"><?php esc_html_e( 'Consumer Key', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<input type="text" name="aics_wc_consumer_key" id="aics_wc_consumer_key"
							value="<?php echo esc_attr( $wc_key ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="aics_wc_consumer_secret"><?php esc_html_e( 'Consumer Secret', 'ai-smart-commerce-search' ); ?></label>
					</th>
					<td>
						<input type="password" name="aics_wc_consumer_secret" id="aics_wc_consumer_secret"
							value="<?php echo esc_attr( $wc_secret ); ?>" class="regular-text"
							autocomplete="new-password" />
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'ai-smart-commerce-search' ), 'primary large', 'aics_save_settings' ); ?>
	</form>
</div>

<style>
.aics-admin-wrap .aics-section.card { padding: 16px 20px 20px; margin-bottom: 20px; }
.aics-admin-wrap .aics-section h2 { margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
#aics-test-status.connected        { color: #16a34a; }
#aics-test-status.auth_failed      { color: #dc2626; }
#aics-test-status.permission_missing { color: #d97706; }
#aics-test-status.unavailable      { color: #6b7280; }
</style>

<script>
(function() {
	var authSel  = document.getElementById('aics_auth_method');
	var keyRow   = document.getElementById('aics-api-key-row');
	var testBtn  = document.getElementById('aics-test-btn');
	var statusEl = document.getElementById('aics-test-status');

	if (authSel && keyRow) {
		authSel.addEventListener('change', function() {
			keyRow.style.display = this.value === 'api_key' ? '' : 'none';
		});
	}

	if (testBtn && statusEl) {
		testBtn.addEventListener('click', function() {
			testBtn.disabled = true;
			statusEl.className = '';
			statusEl.textContent = '<?php echo esc_js( __( 'Testing...', 'ai-smart-commerce-search' ) ); ?>';

			fetch(
				'<?php echo esc_url( rest_url( 'aics/v1/test-connection' ) ); ?>',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
					},
					credentials: 'same-origin',
					body: JSON.stringify({})
				}
			)
			.then(function(r) { return r.json(); })
			.then(function(d) {
				var msgs = {
					connected:          '<?php echo esc_js( __( 'Connected', 'ai-smart-commerce-search' ) ); ?>',
					auth_failed:        '<?php echo esc_js( __( 'Authentication Failed', 'ai-smart-commerce-search' ) ); ?>',
					permission_missing: '<?php echo esc_js( __( 'Permission Missing', 'ai-smart-commerce-search' ) ); ?>',
					unavailable:        '<?php echo esc_js( __( 'API Unavailable', 'ai-smart-commerce-search' ) ); ?>'
				};
				statusEl.textContent = msgs[d.status] || d.message || msgs.unavailable;
				statusEl.className   = d.status || 'unavailable';
			})
			.catch(function() {
				statusEl.textContent = '<?php echo esc_js( __( 'API Unavailable', 'ai-smart-commerce-search' ) ); ?>';
				statusEl.className   = 'unavailable';
			})
			.finally(function() { testBtn.disabled = false; });
		});
	}
})();
</script>
