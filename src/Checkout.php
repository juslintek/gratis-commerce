<?php declare(strict_types=1);
namespace Gratis\Commerce;

defined('ABSPATH') || exit;
final class Checkout {

    public static function register(): void {
        register_rest_route('gratis-commerce/v1', '/checkout/(?P<product_id>\d+)', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'createSession'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('gratis-commerce/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handleWebhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function createSession(\WP_REST_Request $request): \WP_REST_Response {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';
        $ip       = (string) filter_var($ip, FILTER_VALIDATE_IP);
        $rate_key = 'gratis_comm_co_' . substr(hash('sha256', wp_salt('nonce') . '|' . gmdate('Y-m-d') . '|' . $ip), 0, 16);
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 15) {
            return new \WP_REST_Response(['error' => 'Too many checkout requests. Please try again later.'], 429);
        }

        $product_id = (int) $request['product_id'];
        $product    = get_post($product_id);
        if (!$product || $product->post_type !== 'gratis_product' || 'publish' !== $product->post_status) {
            return new \WP_REST_Response(['error' => 'Product not found'], 404);
        }

        $price = (float) get_post_meta($product_id, '_price', true);
        if ($price <= 0) {
            return new \WP_REST_Response(['error' => 'Invalid product price'], 400);
        }

        $stripe_key = Settings::stripeSecret();
        if ('' === $stripe_key) {
            return new \WP_REST_Response(['error' => 'Stripe not configured'], 500);
        }

        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

        // Create Stripe Checkout Session via API
        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'headers' => ['Authorization' => 'Bearer ' . $stripe_key],
            'body' => [
                'mode' => 'payment',
                'success_url' => home_url('/thank-you/?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => get_permalink($product_id),
                'line_items[0][price_data][currency]' => Settings::currency(),
                'line_items[0][price_data][product_data][name]' => $product->post_title,
                'line_items[0][price_data][unit_amount]' => (int) round($price * 100),
                'line_items[0][quantity]' => 1,
                'metadata[product_id]' => $product_id,
            ],
        ]);

        if (is_wp_error($response)) {
            return new \WP_REST_Response(['error' => $response->get_error_message()], 500);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return new \WP_REST_Response(['url' => esc_url_raw((string) ($data['url'] ?? ''))]);
    }

    public static function handleWebhook(\WP_REST_Request $request): \WP_REST_Response {
        $payload    = $request->get_body();
        $sig_header = (string) $request->get_header('stripe-signature');
        $secret     = Settings::webhookSecret();

        // Fail closed: without a configured signing secret we cannot trust the
        // request, and an unverifiable signature is rejected outright.
        if ('' === $secret || ! self::verifyWebhookSignature($payload, $sig_header, $secret)) {
            return new \WP_REST_Response(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);

        if (($event['type'] ?? '') === 'checkout.session.completed') {
            $session    = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
            $product_id = absint($session['metadata']['product_id'] ?? 0);
            $stripe_id  = sanitize_text_field((string) ($session['id'] ?? ''));
            $customer   = sanitize_email((string) ($session['customer_email'] ?? ''));
            $amount     = ((float) ($session['amount_total'] ?? 0)) / 100;

            // Idempotency: ignore Stripe webhook retries for an already-recorded session.
            if ('' !== $stripe_id) {
                $existing = get_posts([
                    'post_type'      => 'gratis_order',
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Stripe webhook idempotency lookup.
                    'meta_key'       => '_stripe_id',
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Stripe webhook idempotency lookup.
                    'meta_value'     => $stripe_id,
                ]);
                if (!empty($existing)) {
                    return new \WP_REST_Response(['received' => true, 'duplicate' => true]);
                }
            }

            wp_insert_post([
                'post_type'   => 'gratis_order',
                'post_title'  => 'Order #' . time(),
                'post_status' => 'publish',
                'meta_input'  => [
                    '_product_id'  => $product_id,
                    '_amount'      => $amount,
                    '_customer'    => $customer,
                    '_stripe_id'   => $stripe_id,
                ],
            ]);

            do_action('gratis_commerce_order_completed', $product_id, $session);
        }

        return new \WP_REST_Response(['received' => true]);
    }

    /**
     * Verify a Stripe webhook signature using Stripe's signing scheme.
     *
     * Parses the `Stripe-Signature` header (`t=` timestamp and one or more
     * `v1=` HMAC-SHA256 signatures), recomputes the expected signature over
     * `"{timestamp}.{payload}"` with the signing secret, and compares it in
     * constant time. Requests whose timestamp falls outside the tolerance
     * window (replay protection) or whose signatures do not match are rejected.
     *
     * @param string $payload    Raw request body, exactly as received.
     * @param string $sig_header Value of the `Stripe-Signature` header.
     * @param string $secret     Stripe webhook signing secret (whsec_…).
     * @param int    $tolerance  Allowed clock skew in seconds (default 300).
     */
    private static function verifyWebhookSignature(string $payload, string $sig_header, string $secret, int $tolerance = 300): bool {
        if ('' === $secret || '' === $sig_header) {
            return false;
        }

        $timestamp  = 0;
        $signatures = [];
        foreach (explode(',', $sig_header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ('t' === $key) {
                $timestamp = (int) $value;
            } elseif ('v1' === $key) {
                $signatures[] = $value;
            }
        }

        if ($timestamp <= 0 || empty($signatures)) {
            return false;
        }

        // Replay protection: reject timestamps outside the tolerance window.
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
