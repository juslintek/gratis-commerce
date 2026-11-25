=== Gratis Commerce ===
Contributors: juslintek
Tags: ecommerce, stripe, woocommerce, wishlist, checkout
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight Stripe Checkout store with WooCommerce quick view, wishlist, compare and filters.

== Description ==

Lightweight Stripe Checkout store with WooCommerce quick view, wishlist, compare and filters.

Part of the Gratis suite — a collection of free, no-upsell WordPress plugins.

**Features:**

* Lightweight Stripe Checkout store
* WooCommerce product quick view (opt-in, graceful degradation)
* Wishlist with guest cookie and logged-in user-meta storage
* Side-by-side product comparison
* AJAX attribute and in-stock filtering

**Philosophy:**

Everything is free. No "Pro" version, no locked features, no nag screens. Optional managed cloud services may be offered separately to cover infrastructure costs.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Configure it from its settings screen in the WordPress admin.

== Frequently Asked Questions ==

= Is this plugin really free? =

Yes. Everything is free — no "Pro" version, no locked features, and no nag screens. Optional managed cloud services may be offered separately to cover infrastructure costs.

= Does this plugin make external HTTP requests? =

Only when you configure Stripe keys and a customer initiates a Stripe Checkout session. The plugin does not phone home or send analytics.

= Where do I get support? =

Open an issue on the plugin's GitHub repository.

== Third-Party Services ==

This plugin integrates with Stripe when you configure your Stripe secret key and webhook signing secret to process payments.

= Stripe Checkout =

When a visitor initiates checkout for a Gratis Product, the plugin sends product name, price, currency, and redirect URLs to `https://api.stripe.com/v1/checkout/sessions` to create a hosted Stripe Checkout session.

* [Stripe Services Agreement](https://stripe.com/legal/ssa)
* [Stripe Privacy Policy](https://stripe.com/privacy)

No data is sent to Stripe unless you configure Stripe credentials in the plugin settings.

== Changelog ==

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
