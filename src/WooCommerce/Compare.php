<?php declare(strict_types=1);

namespace Gratis\Commerce\WooCommerce;

defined('ABSPATH') || exit;
/**
 * Side-by-side product comparison (up to 4 products).
 *
 * Selection is stored in a cookie (guest and logged-in alike) so the chosen
 * set survives navigation while keeping cached pages valid. The
 * `[gratis_compare]` shortcode renders the comparison table: image, price,
 * stock status, SKU and each product attribute aligned in columns.
 */
final class Compare {

    public const MAX_ITEMS = 4;

    private const COOKIE_NAME = 'gratis_compare';

    public static function init(): void {
        add_action('woocommerce_after_shop_loop_item', [__CLASS__, 'renderButton'], 14);
        add_action('wp_ajax_gratis_commerce_compare_toggle', [__CLASS__, 'ajaxToggle']);
        add_action('wp_ajax_nopriv_gratis_commerce_compare_toggle', [__CLASS__, 'ajaxToggle']);
        add_shortcode('gratis_compare', [__CLASS__, 'shortcode']);
    }

    /**
     * Render the compare toggle for the current loop product.
     */
    public static function renderButton(): void {
        global $product;

        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $id      = (int) $product->get_id();
        $in_list = in_array($id, self::items(), true);

        printf(
            '<button type="button" class="gratis-compare-btn%1$s" data-product="%2$d" aria-pressed="%3$s">%4$s</button>',
            $in_list ? ' is-active' : '',
            absint($id),
            $in_list ? 'true' : 'false',
            esc_html($in_list ? __('Comparing', 'gratis-commerce') : __('Compare', 'gratis-commerce'))
        );
    }

    /**
     * AJAX: add/remove a product from the comparison set (capped at MAX_ITEMS).
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
            if (count($items) >= self::MAX_ITEMS) {
                wp_send_json_error([
                    'message' => __('You can compare up to 4 products.', 'gratis-commerce'),
                    'full'    => true,
                ], 409);
            }
            $items[] = $product_id;
            $active  = true;
        }

        $items = array_values(array_unique(array_map('intval', $items)));
        self::store($items);

        wp_send_json_success([
            'active' => $active,
            'count'  => count($items),
        ]);
    }

    /**
     * `[gratis_compare]` — render the comparison table.
     */
    public static function shortcode(): string {
        $items = self::items();

        if (empty($items)) {
            return '<p class="gratis-compare-empty">' . esc_html__('No products selected for comparison.', 'gratis-commerce') . '</p>';
        }

        $products = [];
        foreach ($items as $id) {
            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
            if ($product) {
                $products[] = $product;
            }
        }

        if (empty($products)) {
            return '<p class="gratis-compare-empty">' . esc_html__('No products selected for comparison.', 'gratis-commerce') . '</p>';
        }

        // Build the union of attribute keys across all compared products.
        $attribute_labels = [];
        foreach ($products as $product) {
            foreach ($product->get_attributes() as $key => $attribute) {
                $attribute_labels[$key] = wc_attribute_label($key);
            }
        }

        ob_start();
        echo '<div class="gratis-compare-wrap"><table class="gratis-compare-table">';

        // Image + title row.
        echo '<thead><tr><th></th>';
        foreach ($products as $product) {
            printf(
                '<th><a href="%1$s">%2$s<span class="gratis-compare-name">%3$s</span></a></th>',
                esc_url(get_permalink($product->get_id())),
                wp_kses_post($product->get_image('woocommerce_thumbnail')),
                esc_html($product->get_name())
            );
        }
        echo '</tr></thead><tbody>';

        // Price.
        echo '<tr><th>' . esc_html__('Price', 'gratis-commerce') . '</th>';
        foreach ($products as $product) {
            echo '<td>' . wp_kses_post($product->get_price_html()) . '</td>';
        }
        echo '</tr>';

        // Stock status.
        echo '<tr><th>' . esc_html__('Availability', 'gratis-commerce') . '</th>';
        foreach ($products as $product) {
            echo '<td>' . esc_html($product->is_in_stock() ? __('In stock', 'gratis-commerce') : __('Out of stock', 'gratis-commerce')) . '</td>';
        }
        echo '</tr>';

        // SKU.
        echo '<tr><th>' . esc_html__('SKU', 'gratis-commerce') . '</th>';
        foreach ($products as $product) {
            echo '<td>' . esc_html($product->get_sku() ?: '&mdash;') . '</td>';
        }
        echo '</tr>';

        // Attributes.
        foreach ($attribute_labels as $key => $label) {
            echo '<tr><th>' . esc_html($label) . '</th>';
            foreach ($products as $product) {
                $value = wc_get_product_terms($product->get_id(), $key, ['fields' => 'names']);
                if (empty($value)) {
                    $raw   = $product->get_attribute($key);
                    $value = '' !== $raw ? [$raw] : [];
                }
                echo '<td>' . esc_html(!empty($value) ? implode(', ', $value) : '&mdash;') . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        return (string) ob_get_clean();
    }

    /**
     * Current comparison product IDs.
     *
     * @return int[]
     */
    public static function items(): array {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return [];
        }

        $raw = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        $ids = array_filter(array_map('absint', explode(',', $raw)));

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_ITEMS);
    }

    /**
     * Persist the comparison set to a cookie.
     *
     * @param int[] $items Product IDs.
     */
    private static function store(array $items): void {
        $items = array_slice($items, 0, self::MAX_ITEMS);
        $value = implode(',', $items);
        setcookie(self::COOKIE_NAME, $value, time() + (7 * DAY_IN_SECONDS), COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), false);
        $_COOKIE[self::COOKIE_NAME] = $value;
    }
}
