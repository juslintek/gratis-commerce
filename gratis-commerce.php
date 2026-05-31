<?php
/**
 * Plugin Name: Gratis Commerce
 * Description: Lightweight ecommerce with Stripe Checkout. No cart bloat. Replaces WooCommerce for simple stores.
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * License: GPL-2.0-or-later
 * Text Domain: gratis-commerce
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/autoload.php';

add_action('init', [\Gratis\Commerce\Products::class, 'register']);
add_action('rest_api_init', [\Gratis\Commerce\Checkout::class, 'register']);
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-commerce', \Gratis\Commerce\CLI\CommerceCommand::class);
    }
});
