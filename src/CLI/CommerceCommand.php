<?php declare(strict_types=1);
namespace Gratis\Commerce\CLI;

final class CommerceCommand {

    /**
     * List products.
     * @when after_wp_load
     */
    public function products($args, $assoc_args): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
        $products = get_posts(['post_type' => 'gratis_product', 'numberposts' => -1]);
        if (empty($products)) { \WP_CLI::log('No products.'); return; }
        $rows = array_map(fn($p) => [
            'ID' => $p->ID, 'Title' => $p->post_title,
            'Price' => '$' . get_post_meta($p->ID, '_price', true),
        ], $products);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Title', 'Price']);
    }

    /**
     * List orders.
     * @when after_wp_load
     */
    public function orders($args, $assoc_args): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
        $orders = get_posts(['post_type' => 'gratis_order', 'numberposts' => 50]);
        if (empty($orders)) { \WP_CLI::log('No orders.'); return; }
        $rows = array_map(fn($o) => [
            'ID' => $o->ID, 'Date' => $o->post_date,
            'Amount' => '$' . get_post_meta($o->ID, '_amount', true),
            'Customer' => get_post_meta($o->ID, '_customer', true),
        ], $orders);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Date', 'Amount', 'Customer']);
    }
}
