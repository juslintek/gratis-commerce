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
        $product_id = (int) $request['product_id'];
        $product = get_post($product_id);
        if (!$product || $product->post_type !== 'gratis_product') {
            return new \WP_REST_Response(['error' => 'Product not found'], 404);
        }

        $price = (float) get_post_meta($product_id, '_price', true);
        $stripe_key = Settings::stripeSecret();
        if ('' === $stripe_key) {
            return new \WP_REST_Response(['error' => 'Stripe not configured'], 500);
        }

        // Create Stripe Checkout Session via API
        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'headers' => ['Authorization' => 'Bearer ' . $stripe_key],
            'body' => [
                'mode' => 'payment',
                'success_url' => home_url('/thank-you/?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => get_permalink($product_id),
                'line_items[0][price_data][currency]' => Settings::currency(),
                'line_items[0][price_data][product_data][name]' => $product->post_title,
                'line_items[0][price_data][unit_amount]' => (int) ($price * 100),
                'line_items[0][quantity]' => 1,
                'metadata[product_id]' => $product_id,
            ],
        ]);

        if (is_wp_error($response)) {
            return new \WP_REST_Response(['error' => $response->get_error_message()], 500);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return new \WP_REST_Response(['url' => $data['url'] ?? '']);
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
            $session = $event['data']['object'] ?? [];
            $product_id = (int) ($session['metadata']['product_id'] ?? 0);

            wp_insert_post([
                'post_type'   => 'gratis_order',
                'post_title'  => 'Order #' . time(),
                'post_status' => 'publish',
                'meta_input'  => [
                    '_product_id'  => $product_id,
                    '_amount'      => ($session['amount_total'] ?? 0) / 100,
                    '_customer'    => $session['customer_email'] ?? '',
                    '_stripe_id'   => $session['id'] ?? '',
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
