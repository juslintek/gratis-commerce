<?php declare(strict_types=1);
namespace Gratis\Commerce;

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
        $stripe_key = get_option('gratis_commerce_stripe_secret');
        if (!$stripe_key) {
            return new \WP_REST_Response(['error' => 'Stripe not configured'], 500);
        }

        // Create Stripe Checkout Session via API
        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'headers' => ['Authorization' => 'Bearer ' . $stripe_key],
            'body' => [
                'mode' => 'payment',
                'success_url' => home_url('/thank-you/?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => get_permalink($product_id),
                'line_items[0][price_data][currency]' => 'usd',
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
        $payload = $request->get_body();
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
}
