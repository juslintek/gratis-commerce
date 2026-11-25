<?php declare(strict_types=1);

namespace Gratis\Commerce\WooCommerce;

defined('ABSPATH') || exit;
/**
 * Advanced AJAX product filtering by attributes.
 *
 * The `[gratis_product_filter]` shortcode renders a filter panel built from the
 * registered WooCommerce product attribute taxonomies (`pa_*`), plus price and
 * an in-stock toggle. Selecting filters issues an AJAX request that re-queries
 * products with a `tax_query` and returns a fresh product grid without a full
 * page reload.
 */
final class Filters {

    public static function init(): void {
        add_shortcode('gratis_product_filter', [__CLASS__, 'shortcode']);
        add_action('wp_ajax_gratis_commerce_filter', [__CLASS__, 'ajax']);
        add_action('wp_ajax_nopriv_gratis_commerce_filter', [__CLASS__, 'ajax']);
    }

    /**
     * Render the filter panel and an initial product grid.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     */
    public static function shortcode($atts): string {
        $atts = shortcode_atts([
            'attributes' => '',
            'per_page'   => 12,
        ], (array) $atts, 'gratis_product_filter');

        $taxonomies = self::resolveTaxonomies((string) $atts['attributes']);
        $per_page   = max(1, (int) $atts['per_page']);

        ob_start();
        echo '<div class="gratis-filter" data-per-page="' . absint($per_page) . '">';
        echo '<form class="gratis-filter__form">';

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            printf('<fieldset class="gratis-filter__group"><legend>%s</legend>', esc_html(wc_attribute_label($taxonomy)));
            foreach ($terms as $term) {
                printf(
                    '<label class="gratis-filter__option"><input type="checkbox" name="filter_attributes[%1$s][]" value="%2$s"> %3$s</label>',
                    esc_attr($taxonomy),
                    esc_attr($term->slug),
                    esc_html($term->name)
                );
            }
            echo '</fieldset>';
        }

        printf(
            '<fieldset class="gratis-filter__group"><legend>%1$s</legend><label class="gratis-filter__option"><input type="checkbox" name="filter_in_stock" value="1"> %2$s</label></fieldset>',
            esc_html__('Availability', 'gratis-commerce'),
            esc_html__('In stock only', 'gratis-commerce')
        );

        echo '</form>';
        echo '<div class="gratis-filter__results" aria-live="polite">';
        echo self::renderGrid(self::query([], false, $per_page)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderGrid returns escaped markup.
        echo '</div></div>';

        return (string) ob_get_clean();
    }

    /**
     * AJAX: return a product grid filtered by the submitted attributes.
     */
    public static function ajax(): void {
        check_ajax_referer(Plugin::NONCE_ACTION, 'nonce');

        // Nonce verified above by check_ajax_referer. The array is sanitised
        // per-element below via sanitize_key() (keys) and sanitize_title() (values).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_attributes = isset($_POST['attributes']) && is_array($_POST['attributes']) ? wp_unslash($_POST['attributes']) : [];

        $attributes = [];
        foreach ($raw_attributes as $taxonomy => $slugs) {
            $taxonomy = sanitize_key($taxonomy);
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $attributes[$taxonomy] = array_filter(array_map('sanitize_title', (array) $slugs));
        }

        $in_stock = !empty($_POST['in_stock']);
        $per_page = isset($_POST['per_page']) ? max(1, absint(wp_unslash($_POST['per_page']))) : 12;

        wp_send_json_success(['html' => self::renderGrid(self::query($attributes, $in_stock, $per_page))]);
    }

    /**
     * Resolve which attribute taxonomies to expose as filters.
     *
     * @param string $requested Comma-separated attribute slugs (with or without
     *                          the `pa_` prefix). Empty = all product attributes.
     * @return string[]
     */
    private static function resolveTaxonomies(string $requested): array {
        if ('' !== trim($requested)) {
            $out = [];
            foreach (array_map('trim', explode(',', $requested)) as $name) {
                if ('' === $name) {
                    continue;
                }
                $taxonomy = 0 === strpos($name, 'pa_') ? $name : 'pa_' . sanitize_title($name);
                if (taxonomy_exists($taxonomy)) {
                    $out[] = $taxonomy;
                }
            }
            return $out;
        }

        if (!function_exists('wc_get_attribute_taxonomy_names')) {
            return [];
        }

        return array_filter((array) wc_get_attribute_taxonomy_names(), 'taxonomy_exists');
    }

    /**
     * Run the filtered product query.
     *
     * @param array<string,string[]> $attributes Map of taxonomy => term slugs.
     * @param bool                    $in_stock  Limit to in-stock products.
     * @param int                     $per_page  Results per page.
     */
    private static function query(array $attributes, bool $in_stock, int $per_page): \WP_Query {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'no_found_rows'  => true,
        ];

        $tax_query = [];
        foreach ($attributes as $taxonomy => $slugs) {
            if (empty($slugs)) {
                continue;
            }
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $slugs,
            ];
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- intentional attribute filter.
        }

        if ($in_stock) {
            $args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional stock filter.
                [
                    'key'   => '_stock_status',
                    'value' => 'instock',
                ],
            ];
        }

        return new \WP_Query($args);
    }

    /**
     * Render a product grid from a query. Returns escaped markup.
     */
    private static function renderGrid(\WP_Query $query): string {
        if (!$query->have_posts()) {
            wp_reset_postdata();
            return '<p class="gratis-filter__empty">' . esc_html__('No products match your filters.', 'gratis-commerce') . '</p>';
        }

        ob_start();
        echo '<ul class="gratis-filter__grid">';
        while ($query->have_posts()) {
            $query->the_post();
            $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
            if (!$product) {
                continue;
            }
            printf(
                '<li class="gratis-filter__item"><a href="%1$s">%2$s<span class="gratis-filter__name">%3$s</span><span class="gratis-filter__price">%4$s</span></a></li>',
                esc_url(get_permalink()),
                wp_kses_post($product->get_image('woocommerce_thumbnail')),
                esc_html($product->get_name()),
                wp_kses_post($product->get_price_html())
            );
        }
        echo '</ul>';
        wp_reset_postdata();

        return (string) ob_get_clean();
    }
}
