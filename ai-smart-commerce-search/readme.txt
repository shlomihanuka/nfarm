=== AI Smart Commerce Search ===
Contributors: mycoplatform
Tags: woocommerce, search, ai, hebrew, rtl
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered search drawer for WooCommerce with Hebrew RTL support.

== Description ==

AI Smart Commerce Search replaces the standard WordPress/WooCommerce search with a full-screen overlay drawer that:

* Searches your WooCommerce catalogue in real time (debounced 300 ms).
* Displays product cards with real images, prices, stock status, quantity selectors and variation pickers.
* Adds products to the native WooCommerce cart via the Store API — no parallel cart.
* Supports Hebrew RTL and mobile layouts with keyboard-awareness.
* Connects to a Google Cloud AI backend when configured (optional — the plugin works fully with local WooCommerce search alone).

= Key features =

* Full-width overlay drawer, desktop and mobile layouts.
* Hebrew RTL (`dir="rtl"`) throughout the drawer.
* Add to cart via WooCommerce Store API — updates the native cart count widget.
* Variable product support: attribute selectors shown per product card.
* Weighed product support: shows the store's selling method without promising a final price.
* Settings screen under AI Commerce -> Settings in wp-admin.
* One-toggle off-switch: disables the drawer and restores native search with zero interference.
* Google Cloud backend connection test (honest status: Connected / Auth Failed / Permission Missing / API Unavailable).

== Installation ==

See INSTALL.md included with the plugin ZIP for step-by-step instructions.

1. Upload the plugin ZIP via Plugins -> Add New -> Upload Plugin.
2. Activate the plugin.
3. Navigate to AI Commerce -> Settings.
4. Enter your WooCommerce REST API consumer key and secret (Read permission).
5. Optionally configure the Google Cloud backend.

== Frequently Asked Questions ==

= Does the plugin work without a Google Cloud backend? =
Yes. Local WooCommerce search is fully functional without any cloud configuration.

= Will it break my checkout? =
No. If the plugin's JavaScript encounters an error, the customer can still search and buy through the site's native flow. The off-switch in settings also restores native search instantly.

= Does it support RTL / Hebrew? =
Yes. The drawer uses `dir="rtl"` and all default labels are in Hebrew.

== Changelog ==

= 0.1.0 =
* Initial release — R1 scope: drawer UI, local WooCommerce search, Store API cart, settings screen.
