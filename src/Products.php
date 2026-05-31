<?php declare(strict_types=1);
namespace Gratis\Commerce;

final class Products
{
    public static function register(): void
    {
        register_post_type('gratis_product', [
            'labels'       => ['name' => 'Products', 'singular_name' => 'Product'],
            'public'       => true,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-cart',
            'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'has_archive'  => true,
            'rewrite'      => ['slug' => 'products'],
        ]);

        register_post_type('gratis_order', [
            'labels'  => ['name' => 'Orders', 'singular_name' => 'Order'],
            'public'  => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=gratis_product',
        ]);

        // Register product meta
        register_post_meta('gratis_product', '_price', ['type' => 'number', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_product', '_sale_price', ['type' => 'number', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_product', '_downloadable', ['type' => 'boolean', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_product', '_download_url', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
    }
}

    /** WooCommerce compatibility: make gratis_product work with WC themes. */
    public static function wooCompat(): void
    {
        // Map WC template functions to our product data
        if (!function_exists('wc_get_product')) {
            function wc_get_product($id = 0) {
                $post = get_post($id);
                if (!$post || $post->post_type !== 'gratis_product') return null;
                return (object) [
                    'get_id' => fn() => $post->ID,
                    'get_name' => fn() => $post->post_title,
                    'get_price' => fn() => get_post_meta($post->ID, '_price', true),
                    'get_regular_price' => fn() => get_post_meta($post->ID, '_price', true),
                    'get_sale_price' => fn() => get_post_meta($post->ID, '_sale_price', true),
                    'is_on_sale' => fn() => !empty(get_post_meta($post->ID, '_sale_price', true)),
                    'get_permalink' => fn() => get_permalink($post->ID),
                    'get_image' => fn($size = 'thumbnail') => get_the_post_thumbnail($post->ID, $size),
                ];
            }
        }
    }
