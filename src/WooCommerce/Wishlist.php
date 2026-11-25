<?php declare(strict_types=1);

namespace Gratis\Commerce\WooCommerce;

defined('ABSPATH') || exit;
/**
 * Wishlist for WooCommerce products.
 *
 * Storage strategy:
 *   - Logged-in users: persisted in user meta (`_gratis_wishlist`) so it
 *     follows them across devices/sessions.
 *   - Guests: stored in a cookie so the list survives a page reload without
 *     requiring an account, keeping server-rendered pages cache-friendly.
 *
 * On login the guest cookie is merged into the user's saved list.
 */
final class Wishlist {

    private const META_KEY    = '_gratis_wishlist';
    private const COOKIE_NAME = 'gratis_wishlist';

    public static function init(): void {
        add_action('woocommerce_after_shop_loop_item', [__CLASS__, 'renderButton'], 12);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'renderButton'], 35);

        add_action('wp_ajax_gratis_commerce_wishlist_toggle', [__CLASS__, 'ajaxToggle']);
        add_action('wp_ajax_nopriv_gratis_commerce_wishlist_toggle', [__CLASS__, 'ajaxToggle']);

        add_action('wp_login', [__CLASS__, 'mergeCookieOnLogin'], 10, 2);
        add_shortcode('gratis_wishlist', [__CLASS__, 'shortcode']);
    }

    /**
     * Render the add/remove wishlist toggle for the current product.
     */
    public static function renderButton(): void {
        global $product;

        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $id       = (int) $product->get_id();
        $in_list  = in_array($id, self::items(), true);
        $label    = $in_list
            ? __('In wishlist', 'gratis-commerce')
            : __('Add to wishlist', 'gratis-commerce');

        printf(
            '<button type="button" class="gratis-wishlist-btn%1$s" data-product="%2$d" aria-pressed="%3$s">%4$s</button>',
            $in_list ? ' is-active' : '',
            absint($id),
            $in_list ? 'true' : 'false',
            esc_html($label)
        );
    }

    /**
     * AJAX: toggle a product in/out of the wishlist.
     */
    public static function ajaxToggle(): void {
        check_ajax_referer(Plugin::NONCE_ACTION, 'nonce');

        // Nonce verified above by check_ajax_referer.
        $product_id = isset($_POST['product']) ? absint(wp_unslash($_POST['product'])) : 0;

        if (!$product_id || !function_exists('wc_get_product') || !wc_get_product($product_id)) {
            wp_send_json_error(['message' => __('Product not found.', 'gratis-commerce')], 404);
        }

        $items = self::items();
        $key   = array_search($product_id, $items, true);

        if (false !== $key) {
            unset($items[$key]);
            $active = false;
        } else {
            $items[] = $product_id;
            $active  = true;
        }

        self::store(array_values(array_unique(array_map('intval', $items))));

        wp_send_json_success([
            'active' => $active,
            'count'  => count(self::items()),
        ]);
    }

    /**
     * `[gratis_wishlist]` — render the current user's wishlist as a product grid.
     */
    public static function shortcode(): string {
        $items = self::items();

        if (empty($items)) {
            return '<p class="gratis-wishlist-empty">' . esc_html__('Your wishlist is empty.', 'gratis-commerce') . '</p>';
        }

        ob_start();
        echo '<ul class="gratis-wishlist-grid">';
        foreach ($items as $id) {
            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
            if (!$product) {
                continue;
            }
            printf(
                '<li class="gratis-wishlist-item"><a href="%1$s">%2$s<span class="gratis-wishlist-item__title">%3$s</span></a><span class="gratis-wishlist-item__price">%4$s</span></li>',
                esc_url(get_permalink($id)),
                wp_kses_post($product->get_image('woocommerce_thumbnail')),
                esc_html($product->get_name()),
                wp_kses_post($product->get_price_html())
            );
        }
        echo '</ul>';

        return (string) ob_get_clean();
    }

    /**
     * Merge a guest cookie list into the user's saved list on login.
     *
     * @param string   $user_login The user's login name.
     * @param \WP_User $user       The logged-in user object.
     */
    public static function mergeCookieOnLogin($user_login, $user): void {
        if (!$user instanceof \WP_User) {
            return;
        }

        $cookie = self::readCookie();
        if (empty($cookie)) {
            return;
        }

        $saved  = (array) get_user_meta($user->ID, self::META_KEY, true);
        $merged = array_values(array_unique(array_map('intval', array_merge($saved, $cookie))));

        update_user_meta($user->ID, self::META_KEY, $merged);
    }

    /**
     * Current wishlist product IDs for the active visitor.
     *
     * @return int[]
     */
    public static function items(): array {
        if (is_user_logged_in()) {
            $items = get_user_meta(get_current_user_id(), self::META_KEY, true);
            return is_array($items) ? array_map('intval', $items) : [];
        }

        return self::readCookie();
    }

    /**
     * Persist the wishlist to user meta (logged-in) or cookie (guest).
     *
     * @param int[] $items Product IDs.
     */
    private static function store(array $items): void {
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), self::META_KEY, $items);
            return;
        }

        $value = implode(',', $items);
        setcookie(self::COOKIE_NAME, $value, time() + (30 * DAY_IN_SECONDS), COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), false);
        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    /**
     * Parse the guest wishlist cookie into a list of product IDs.
     *
     * @return int[]
     */
    private static function readCookie(): array {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return [];
        }

        $raw = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        $ids = array_filter(array_map('absint', explode(',', $raw)));

        return array_values(array_unique($ids));
    }
}
