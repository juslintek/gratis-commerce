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

        register_post_meta('gratis_product', '_price', ['type' => 'number', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_product', '_sale_price', ['type' => 'number', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_product', '_downloadable', ['type' => 'boolean', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_product', '_download_url', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
    }
}
