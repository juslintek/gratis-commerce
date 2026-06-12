<?php declare(strict_types=1);

namespace Gratis\Commerce\WooCommerce;

/**
 * Quick-view modal for product archive (shop / category / tag) pages.
 *
 * Adds a "Quick view" trigger to each product in the loop. Clicking it fetches
 * a compact product summary over AJAX and shows it in an accessible dialog so
 * shoppers can review and add to cart without leaving the listing.
 */
final class QuickView {

    public static function init(): void {
        add_action('woocommerce_after_shop_loop_item', [__CLASS__, 'renderTrigger'], 15);
        add_action('wp_ajax_gratis_commerce_quick_view', [__CLASS__, 'ajax']);
        add_action('wp_ajax_nopriv_gratis_commerce_quick_view', [__CLASS__, 'ajax']);
    }

    /**
     * Output the quick-view trigger button inside the product loop item.
     */
    public static function renderTrigger(): void {
        global $product;

        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        printf(
            '<button type="button" class="gratis-quick-view-btn" data-product="%1$d" aria-haspopup="dialog">%2$s</button>',
            absint($product->get_id()),
            esc_html__('Quick view', 'gratis-commerce')
        );
    }

    /**
     * AJAX: return the rendered quick-view markup for a single product.
     */
    public static function ajax(): void {
        check_ajax_referer(Plugin::NONCE_ACTION, 'nonce');

        // Nonce verified above by check_ajax_referer.
        $product_id = isset($_POST['product']) ? absint(wp_unslash($_POST['product'])) : 0;
        $product    = $product_id ? wc_get_product($product_id) : null;

        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'gratis-commerce')], 404);
        }

        wp_send_json_success(['html' => self::renderProduct($product)]);
    }

    /**
     * Build the inner HTML of the quick-view dialog for a product.
     *
     * @param \WC_Product $product The product to render.
     */
    private static function renderProduct(\WC_Product $product): string {
        $image_id = $product->get_image_id();
        $image    = $image_id ? wp_get_attachment_image($image_id, 'large', false, ['loading' => 'lazy']) : '';

        ob_start();
        ?>
        <div class="gratis-qv">
            <div class="gratis-qv__media"><?php echo wp_kses_post($image); ?></div>
            <div class="gratis-qv__summary">
                <h2 class="gratis-qv__title"><?php echo esc_html($product->get_name()); ?></h2>
                <div class="gratis-qv__price"><?php echo wp_kses_post($product->get_price_html()); ?></div>
                <div class="gratis-qv__excerpt"><?php echo wp_kses_post(wpautop($product->get_short_description())); ?></div>
                <div class="gratis-qv__actions">
                    <a class="button gratis-qv__view" href="<?php echo esc_url(get_permalink($product->get_id())); ?>">
                        <?php echo esc_html__('View details', 'gratis-commerce'); ?>
                    </a>
                    <?php if ($product->is_purchasable() && $product->is_in_stock() && !$product->is_type('variable')) : ?>
                        <a class="button gratis-qv__add"
                           href="<?php echo esc_url('?add-to-cart=' . $product->get_id()); ?>"
                           data-product="<?php echo absint($product->get_id()); ?>"
                           rel="nofollow">
                            <?php echo esc_html__('Add to cart', 'gratis-commerce'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Print the empty modal shell that JS populates. Rendered once in the footer.
     */
    public static function renderModalContainer(): void {
        ?>
        <div class="gratis-qv-modal" id="gratis-qv-modal" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Product quick view', 'gratis-commerce'); ?>" hidden>
            <div class="gratis-qv-modal__overlay" data-gratis-qv-close></div>
            <div class="gratis-qv-modal__dialog" tabindex="-1">
                <button type="button" class="gratis-qv-modal__close" data-gratis-qv-close aria-label="<?php echo esc_attr__('Close', 'gratis-commerce'); ?>">&times;</button>
                <div class="gratis-qv-modal__content" aria-live="polite"></div>
            </div>
        </div>
        <?php
    }
}
