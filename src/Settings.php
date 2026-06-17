<?php declare(strict_types=1);

namespace Gratis\Commerce;

defined('ABSPATH') || exit;

/**
 * Admin settings screen for Gratis Commerce.
 *
 * Stores the Stripe secret key, the Stripe webhook signing secret and the
 * default Checkout currency via the WordPress Settings API. Secrets are write
 * only: the stored value is never echoed back to the browser — a fixed mask is
 * rendered instead, and re-submitting the mask preserves the existing value.
 */
final class Settings {

    private const GROUP = 'gratis_commerce_settings_group';
    private const PAGE  = 'gratis-commerce-settings';

    /**
     * Stripe secret key option name.
     *
     * Kept identical to the original ad-hoc option so existing installs keep
     * their configured key; it is now a first-class, registered setting read
     * everywhere via {@see Settings::stripeSecret()}.
     */
    public const OPT_STRIPE_SECRET = 'gratis_commerce_stripe_secret';

    /** Stripe webhook signing secret option name. */
    public const OPT_WEBHOOK_SECRET = 'gratis_commerce_stripe_webhook_secret';

    /** Default Checkout currency option name. */
    public const OPT_CURRENCY = 'gratis_commerce_currency';

    /** Placeholder shown in place of a stored secret; never the real value. */
    private const SECRET_MASK = '********';

    /** Fallback currency (Stripe expects a lowercase ISO code). */
    private const DEFAULT_CURRENCY = 'usd';

    /** Register the settings submenu under the Products menu. */
    public static function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=gratis_product',
            __('Commerce Settings', 'gratis-commerce'),
            __('Settings', 'gratis-commerce'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    /** Register options with typed, write-safe sanitisers. */
    public static function register_settings(): void {
        register_setting(self::GROUP, self::OPT_STRIPE_SECRET, [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_stripe_secret'],
            'default'           => '',
            'show_in_rest'      => false,
        ]);
        register_setting(self::GROUP, self::OPT_WEBHOOK_SECRET, [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_webhook_secret'],
            'default'           => '',
            'show_in_rest'      => false,
        ]);
        register_setting(self::GROUP, self::OPT_CURRENCY, [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_currency'],
            'default'           => self::DEFAULT_CURRENCY,
        ]);
    }

    /** Keep the stored Stripe secret when the masked placeholder is re-submitted. */
    public static function sanitize_stripe_secret($value): string {
        return self::preserve_secret(self::OPT_STRIPE_SECRET, $value);
    }

    /** Keep the stored webhook secret when the masked placeholder is re-submitted. */
    public static function sanitize_webhook_secret($value): string {
        return self::preserve_secret(self::OPT_WEBHOOK_SECRET, $value);
    }

    /**
     * Shared secret sanitiser: the mask means "unchanged"; anything else is the
     * new value (sanitised). Prevents the masked display from overwriting a real
     * key when the form is saved without re-entering it.
     */
    private static function preserve_secret(string $option, $value): string {
        $value = sanitize_text_field((string) $value);
        if (self::SECRET_MASK === $value) {
            return (string) get_option($option, '');
        }
        return $value;
    }

    /** Normalise a Stripe currency code to a lowercase 3-letter ISO code. */
    public static function sanitize_currency($value): string {
        $code = strtolower(sanitize_text_field((string) $value));
        $code = (string) preg_replace('/[^a-z]/', '', $code);
        $code = substr($code, 0, 3);
        return 3 === strlen($code) ? $code : self::DEFAULT_CURRENCY;
    }

    /** Configured Stripe secret key (empty string when unset). */
    public static function stripeSecret(): string {
        return (string) get_option(self::OPT_STRIPE_SECRET, '');
    }

    /** Configured Stripe webhook signing secret (empty string when unset). */
    public static function webhookSecret(): string {
        return (string) get_option(self::OPT_WEBHOOK_SECRET, '');
    }

    /** Configured default Checkout currency (lowercase ISO code). */
    public static function currency(): string {
        $code = (string) get_option(self::OPT_CURRENCY, self::DEFAULT_CURRENCY);
        return '' !== $code ? $code : self::DEFAULT_CURRENCY;
    }

    /** Render the settings screen. */
    public static function render_page(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gratis-commerce'));
        }

        $has_secret  = '' !== self::stripeSecret();
        $has_webhook = '' !== self::webhookSecret();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Commerce Settings', 'gratis-commerce') . '</h1>';
        echo '<p>' . esc_html__('Configure Stripe Checkout. Secret values are stored write-only and never displayed.', 'gratis-commerce') . '</p>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gc_stripe_secret">' . esc_html__('Stripe secret key', 'gratis-commerce') . '</label></th><td>';
        echo '<input type="password" id="gc_stripe_secret" class="regular-text" autocomplete="new-password" name="' . esc_attr(self::OPT_STRIPE_SECRET) . '" value="' . esc_attr($has_secret ? self::SECRET_MASK : '') . '">';
        echo '<p class="description">' . esc_html__('Your Stripe API secret key (sk_…). Leave the masked value to keep the current key; clear it to remove.', 'gratis-commerce') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gc_webhook_secret">' . esc_html__('Stripe webhook signing secret', 'gratis-commerce') . '</label></th><td>';
        echo '<input type="password" id="gc_webhook_secret" class="regular-text" autocomplete="new-password" name="' . esc_attr(self::OPT_WEBHOOK_SECRET) . '" value="' . esc_attr($has_webhook ? self::SECRET_MASK : '') . '">';
        echo '<p class="description">' . esc_html__('The signing secret (whsec_…) for the Checkout webhook endpoint. Used to verify incoming Stripe events.', 'gratis-commerce') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gc_currency">' . esc_html__('Default currency', 'gratis-commerce') . '</label></th><td>';
        echo '<input type="text" id="gc_currency" class="small-text" maxlength="3" name="' . esc_attr(self::OPT_CURRENCY) . '" value="' . esc_attr(self::currency()) . '">';
        echo '<p class="description">' . esc_html__('Three-letter ISO currency code used for Checkout sessions (e.g. usd, eur, gbp).', 'gratis-commerce') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save settings', 'gratis-commerce'));
        echo '</form>';

        echo '<h2>' . esc_html__('Webhook endpoint', 'gratis-commerce') . '</h2>';
        echo '<p>' . esc_html__('Add this URL as a Stripe webhook destination and paste its signing secret above:', 'gratis-commerce') . '</p>';
        echo '<p><code>' . esc_html(rest_url('gratis-commerce/v1/webhook')) . '</code></p>';

        echo '</div>';
    }
}
