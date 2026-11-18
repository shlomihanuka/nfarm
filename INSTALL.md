# AI Smart Commerce Search — Installation Guide

## Requirements
- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher

## Install steps
1. In WP Admin, go to **Plugins → Add New → Upload Plugin**
2. Select `ai-smart-commerce-search-0.1.1.zip`
3. Click **Install Now**, then **Activate Plugin**

## Settings screen
Navigate to **Settings → AI Smart Search** to configure:

- **Enable AI Smart Search** — master on/off switch (Step 1)
- **Cloud Setup** (Step 2) — Project ID, Environment, Region, Tenant ID, Cloud API URL
  - Leave all cloud fields blank to run in local-search-only mode (no AI features, full WooCommerce catalogue search still works)
  - Click **Connection Check** to verify the cloud API is reachable

## How to turn it off
In **Settings → AI Smart Search**, uncheck **Enable AI Smart Search** and click **Save Settings**.
The site's native search is immediately restored. Re-enable at any time.

## Hebrew / RTL
The plugin ships with a compiled `.mo` for `he_IL`. WordPress loads it automatically when
the site locale is set to Hebrew.

## Local search
When the cloud is not configured or unreachable, the plugin falls back to WooCommerce's own
product search silently. No customer-facing error is shown.

## Plugin version
0.1.1 — includes compiled Hebrew .mo (compiled 2026-11-18).
