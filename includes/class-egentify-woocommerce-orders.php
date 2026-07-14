<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chat purchase attribution: copies the widget's chat cookie into order meta
 * at checkout, then reports paid attributed orders to Egentify.
 */
final class Egentify_WooCommerce_Orders {
    public const CHAT_COOKIE = '_egentify_chat';
    public const CHAT_META_KEY = '_egentify_chat';
    public const SENT_META_KEY = '_egentify_purchase_sent';
    public const QUEUED_META_KEY = '_egentify_purchase_queued';
    public const SEND_HOOK = 'egentify_send_purchase_event';
    public const MAX_ATTEMPTS = 5;

    private const CHAT_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var Egentify_WooCommerce_Settings */
    private $settings;

    public function __construct(Egentify_WooCommerce_Settings $settings) {
        $this->settings = $settings;
    }

    public function register_hooks() {
        // Classic and block checkout; the checkout flow saves the order.
        add_action('woocommerce_checkout_create_order', array($this, 'stamp_order_attribution'));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'stamp_order_attribution'));

        // payment_complete misses offline gateways (COD, BACS); the status
        // hooks cover those. The queued flag makes it once per order.
        add_action('woocommerce_payment_complete', array($this, 'queue_purchase_event'));
        add_action('woocommerce_order_status_processing', array($this, 'queue_purchase_event'));
        add_action('woocommerce_order_status_completed', array($this, 'queue_purchase_event'));

        add_action(self::SEND_HOOK, array($this, 'send_purchase_event'), 10, 2);
    }

    /**
     * Copy the widget's chat cookie into hidden order meta. Runs on both
     * classic and block checkout; the checkout flow saves the order.
     */
    public function stamp_order_attribution($order) {
        if (!$order instanceof WC_Order || $order->get_meta(self::CHAT_META_KEY)) {
            return;
        }

        $chat_id = isset($_COOKIE[self::CHAT_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::CHAT_COOKIE])) : '';

        if (!preg_match(self::CHAT_UUID_PATTERN, $chat_id)) {
            return;
        }

        $order->update_meta_data(self::CHAT_META_KEY, $chat_id);

        // Shrink the cookie to an hour: failed payment retries keep attribution, later orders do not.
        if (!headers_sent()) {
            setcookie(self::CHAT_COOKIE, $chat_id, time() + HOUR_IN_SECONDS, '/', '', is_ssl(), false);
        }
    }

    /**
     * Queue the async purchase report for a chat-attributed order.
     * Fires from several payment hooks; the queued flag dedupes.
     */
    public function queue_purchase_event($order_id) {
        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!$connection || empty($connection['installation_secret'])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_meta(self::CHAT_META_KEY) || $order->get_meta(self::QUEUED_META_KEY) || $order->get_meta(self::SENT_META_KEY)) {
            return;
        }

        if (!function_exists('as_enqueue_async_action')) {
            $this->send_purchase_event($order->get_id(), 1);
            return;
        }

        // Mark queued only on successful scheduling so a failure here can requeue later.
        if (as_enqueue_async_action(self::SEND_HOOK, array($order->get_id(), 1), 'egentify')) {
            $order->update_meta_data(self::QUEUED_META_KEY, gmdate('c'));
            $order->save();
        }
    }

    /**
     * POST the order to Egentify. Retries transient failures with backoff;
     * the backend dedupes on order id, so retries are safe.
     */
    public function send_purchase_event($order_id, $attempt = 1) {
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order) {
            return;
        }

        $chat_id = $order->get_meta(self::CHAT_META_KEY);
        if (!$chat_id || $order->get_meta(self::SENT_META_KEY)) {
            return;
        }

        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!$connection || empty($connection['installation_secret'])) {
            // Disconnected mid flight; clear the queued flag so a later status change can requeue.
            $order->delete_meta_data(self::QUEUED_META_KEY);
            $order->save();
            return;
        }

        $response = wp_remote_post(
            $this->settings->get_app_base_url() . '/api/webhooks/woocommerce/orders',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $connection['installation_secret'],
                ),
                'body'    => wp_json_encode(array(
                    'installation_id' => $connection['installation_id'],
                    'order_id'        => (string) $order->get_id(),
                    'chat_id'         => $chat_id,
                    'total'           => $order->get_total(),
                    'currency'        => $order->get_currency(),
                    'item_count'      => count($order->get_items()),
                    'order_number'    => (string) $order->get_order_number(),
                    'order_status'    => $order->get_status(),
                )),
                'timeout' => 10,
            )
        );

        $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);

        if (200 === $status) {
            $order->update_meta_data(self::SENT_META_KEY, gmdate('c'));
            $order->save();
            return;
        }

        $transient = is_wp_error($response) || $status >= 500 || in_array($status, array(408, 425, 429), true);
        if ($transient && $attempt < self::MAX_ATTEMPTS && function_exists('as_schedule_single_action')) {
            $delay = 5 * MINUTE_IN_SECONDS * pow(4, $attempt - 1);
            if (as_schedule_single_action(time() + $delay, self::SEND_HOOK, array($order->get_id(), $attempt + 1), 'egentify')) {
                return;
            }
        }

        // Giving up; clear the queued flag so a later status change can requeue.
        $order->delete_meta_data(self::QUEUED_META_KEY);
        $order->save();
    }
}
