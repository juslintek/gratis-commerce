<?php
/**
 * Plugin Name: Gratis Commerce
 * Plugin URI: https://github.com/juslintek/gratis-commerce
 * Description: Lightweight Stripe Checkout store plus WooCommerce enhancements — product quick view, wishlist, side-by-side comparison and AJAX attribute filtering. No cart bloat.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-commerce
 */
defined('ABSPATH') || exit;

define('GRATIS_COMMERCE_VERSION', '1.0.0');
define('GRATIS_COMMERCE_FILE', __FILE__);
define('GRATIS_COMMERCE_DIR', plugin_dir_path(__FILE__));
define('GRATIS_COMMERCE_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/autoload.php';

// Standalone Stripe-based store features.
add_action('init', [\Gratis\Commerce\Products::class, 'register']);
add_action('rest_api_init', [\Gratis\Commerce\Checkout::class, 'register']);

// Admin settings screen (Stripe keys + default currency).
add_action('admin_menu', [\Gratis\Commerce\Settings::class, 'register_menu']);
add_action('admin_init', [\Gratis\Commerce\Settings::class, 'register_settings']);

// WooCommerce enhancement layer (quick view, wishlist, compare, AJAX filters).
// Boots only when WooCommerce is active; otherwise shows a graceful notice.
add_action('plugins_loaded', [\Gratis\Commerce\WooCommerce\Plugin::class, 'boot']);

add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-commerce', \Gratis\Commerce\CLI\CommerceCommand::class);
    }
});
