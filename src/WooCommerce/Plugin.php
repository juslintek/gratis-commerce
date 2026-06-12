<?php declare(strict_types=1);

namespace Gratis\Commerce\WooCommerce;

/**
 * Bootstraps the WooCommerce enhancement layer.
 *
 * All features (quick view, wishlist, compare, AJAX attribute filtering) are
 * opt-in on WooCommerce being present. When WooCommerce is not active the
 * plugin degrades gracefully and surfaces an admin notice instead of fataling.
 */
final class Plugin {

    public const NONCE_ACTION = 'gratis_commerce_wc';

    /**
     * Wire up the enhancement layer. Called on `plugins_loaded`.
     */
    public static function boot(): void {
        if (!self::isWooActive()) {
            add_action('admin_notices', [__CLASS__, 'renderMissingWooNotice']);
            return;
        }

        QuickView::init();
        Wishlist::init();
        Compare::init();
        Filters::init();

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
        add_action('wp_footer', [QuickView::class, 'renderModalContainer']);
    }

    /**
     * Whether WooCommerce is loaded and available.
     */
    public static function isWooActive(): bool {
        return class_exists('WooCommerce') || function_exists('WC');
    }

    /**
     * Register and enqueue the front-end assets, localised with the AJAX
     * endpoint and a shared nonce used by every enhancement feature.
     */
    public static function enqueueAssets(): void {
        $url = defined('GRATIS_COMMERCE_URL') ? GRATIS_COMMERCE_URL : plugin_dir_url(dirname(__DIR__) . '/gratis-commerce.php');
        $version = defined('GRATIS_COMMERCE_VERSION') ? GRATIS_COMMERCE_VERSION : '1.0.0';

        wp_enqueue_style(
            'gratis-commerce',
            $url . 'assets/commerce.css',
            [],
            $version
        );

        wp_enqueue_script(
            'gratis-commerce',
            $url . 'assets/commerce.js',
            [],
            $version,
            true
        );

        wp_localize_script(
            'gratis-commerce',
            'GratisCommerce',
            [
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce(self::NONCE_ACTION),
                'compareMax'  => Compare::MAX_ITEMS,
                'i18n'        => [
                    'added'       => __('Added', 'gratis-commerce'),
                    'remove'      => __('Remove', 'gratis-commerce'),
                    'compareFull' => __('You can compare up to 4 products.', 'gratis-commerce'),
                    'error'       => __('Something went wrong. Please try again.', 'gratis-commerce'),
                    'close'       => __('Close', 'gratis-commerce'),
                ],
            ]
        );
    }

    /**
     * Admin notice shown when WooCommerce is not active.
     */
    public static function renderMissingWooNotice(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__(
                'Gratis Commerce WooCommerce enhancements (quick view, wishlist, compare, AJAX filters) require WooCommerce to be installed and active.',
                'gratis-commerce'
            )
        );
    }
}
