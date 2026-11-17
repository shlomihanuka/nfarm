# AI Smart Commerce Search — Installation Guide

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 8.0 |
| Browser | Any modern browser (Chrome 90+, Firefox 88+, Safari 14+, Edge 90+) |

## Install steps

### 1 — Upload the plugin

1. Log in to your WordPress admin panel.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Click **Choose File** and select `ai-smart-commerce-search-0.1.0.zip`.
4. Click **Install Now**.
5. After installation completes, click **Activate Plugin**.

### 2 — Create a WooCommerce REST API key

1. In wp-admin go to **WooCommerce → Settings → Advanced → REST API**.
2. Click **Add key**.
3. Set Description to `AI Smart Search`, User to your admin user, Permissions to **Read**.
4. Click **Generate API key**.
5. **Copy both the Consumer Key and Consumer Secret now** — the secret is shown only once.

### 3 — Configure the plugin

1. Go to **AI Commerce → Settings** in the WordPress admin menu.
2. Under **WooCommerce API Credentials**, paste the Consumer Key and Consumer Secret.
3. Click **Save Settings**.
4. The search drawer is now active on your storefront.

### 4 — (Optional) Connect to Google Cloud AI backend

1. Under **Step 1 — Google Cloud Project**, enter your GCP Project ID, environment, region, and tenant ID.
2. Under **Step 2 — Backend Connection**, enter your Cloud API Base URL and authentication credentials.
3. Click **Test Connection** to verify. The button reports one of:
   - **Connected** — the backend is reachable and authenticated.
   - **Authentication Failed** — the URL is reachable but the API key is wrong.
   - **Permission Missing** — authenticated but insufficient permissions.
   - **API Unavailable** — URL is empty, unreachable, or returned an unexpected status.
4. Click **Save Settings**.

## Settings screen location

**WordPress admin → AI Commerce → Settings**

## Turning the plugin off (restoring native search)

1. Go to **AI Commerce → Settings**.
2. Uncheck **Enable AI Smart Search**.
3. Click **Save Settings**.

The plugin ceases to intercept any search field. All search fields revert to their default WordPress/WooCommerce behaviour. No page template changes required.

To fully remove the plugin, go to **Plugins**, deactivate and then delete **AI Smart Commerce Search**.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Search drawer does not open | Check the plugin is active and "Enable AI Smart Search" is checked in settings. |
| No search results | Add WooCommerce REST API credentials in settings. |
| "Add to cart" fails | The WooCommerce Store API (`/wp-json/wc/store/v1/`) must be accessible. Check that no security plugin blocks it. |
| Test Connection always shows "API Unavailable" | Cloud backend is not required for R1. This is expected until the backend is deployed. |
