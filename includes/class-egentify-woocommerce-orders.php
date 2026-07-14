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
    public const REQUEUE_HOOK = 'egentify_requeue_unsent_orders';
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
        add_action(self::REQUEUE_HOOK, array($this, 'requeue_unsent_orders'));
        add_action('egentify_woocommerce_connected', array($this, 'schedule_requeue_scan'));
        add_action(Egentify_WooCommerce_Connect::HEARTBEAT_CRON_HOOK, array($this, 'schedule_requeue_scan'), 20);
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', array($this, 'add_cpt_unsent_meta_query'), 10, 2);
    }

    /**
     * Run the recovery scan in the background so the connect callback and
     * heartbeat are not slowed by order queries.
     */
    public function schedule_requeue_scan() {
        if (function_exists('as_enqueue_async_action') && as_enqueue_async_action(self::REQUEUE_HOOK, array(), 'egentify')) {
            return;
        }

        // Never scan synchronously; retry via WP-Cron instead.
        if (!wp_next_scheduled(self::REQUEUE_HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::REQUEUE_HOOK);
        }
    }

    /**
     * Queue attributed paid orders that were never reported, e.g. orders
     * placed while disconnected. Runs on connect and weekly heartbeat.
     * Queueing removes orders from the result set, so each round re-reads
     * page one instead of paginating by offset.
     */
    public function requeue_unsent_orders($round = 1) {
        $round = max(1, (int) $round);
        $has_as = function_exists('as_enqueue_async_action');

        $orders = wc_get_orders(array(
            'status'          => array('processing', 'completed'),
            'limit'           => $has_as ? 100 : 10, // Without Action Scheduler sends run inline; keep the batch small.
            'date_modified'   => '>' . (time() - 30 * DAY_IN_SECONDS),
            'meta_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- HPOS storage.
                array('key' => self::CHAT_META_KEY, 'compare' => 'EXISTS'),
                array('key' => self::SENT_META_KEY, 'compare' => 'NOT EXISTS'),
                array(
                    'relation' => 'OR',
                    array('key' => self::QUEUED_META_KEY, 'compare' => 'NOT EXISTS'),
                    array('key' => self::QUEUED_META_KEY, 'compare' => '<', 'value' => $this->queued_lease_cutoff()),
                ),
            ),
            'egentify_unsent' => true, // Legacy storage; see add_cpt_unsent_meta_query().
        ));

        $queued = 0;
        foreach ($orders as $order) {
            if ($this->queue_purchase_event($order->get_id())) {
                $queued++;
            }
        }

        // Chain only when this round made progress; otherwise enqueueing is
        // failing and another round would rescan the same orders.
        if ($has_as && $queued > 0 && 100 === count($orders) && $round < 10) {
            if (!as_enqueue_async_action(self::REQUEUE_HOOK, array($round + 1), 'egentify') && !wp_next_scheduled(self::REQUEUE_HOOK)) {
                wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::REQUEUE_HOOK);
            }
        }
    }

    /** Queued markers older than this are stale leases; the order is recoverable again. */
    private function queued_lease_cutoff() {
        return gmdate('c', time() - DAY_IN_SECONDS);
    }

    /**
     * Legacy post storage ignores meta_query passed to wc_get_orders;
     * translate our custom flag into one there instead.
     */
    public function add_cpt_unsent_meta_query($query, $query_vars) {
        if (!empty($query_vars['egentify_unsent'])) {
            $query['meta_query'][] = array('key' => self::CHAT_META_KEY, 'compare' => 'EXISTS');
            $query['meta_query'][] = array('key' => self::SENT_META_KEY, 'compare' => 'NOT EXISTS');
            $query['meta_query'][] = array(
                'relation' => 'OR',
                array('key' => self::QUEUED_META_KEY, 'compare' => 'NOT EXISTS'),
                array('key' => self::QUEUED_META_KEY, 'compare' => '<', 'value' => $this->queued_lease_cutoff()),
            );
        }
        return $query;
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

        // Consume the cookie so only this order is attributed. Payment
        // retries keep attribution via the meta on the reused order.
        unset($_COOKIE[self::CHAT_COOKIE]);
        if (!headers_sent()) {
            setcookie(self::CHAT_COOKIE, '', time() - HOUR_IN_SECONDS, '/');
        }
    }

    /**
     * Queue the async purchase report for a chat-attributed order.
     * Fires from several payment hooks; the queued flag dedupes.
     */
    public function queue_purchase_event($order_id) {
        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!$connection || empty($connection['installation_secret'])) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_meta(self::CHAT_META_KEY) || $order->get_meta(self::SENT_META_KEY)) {
            return false;
        }

        // A fresh queued marker means a send action is already pending.
        $queued_at = $order->get_meta(self::QUEUED_META_KEY);
        if ($queued_at && $queued_at > $this->queued_lease_cutoff()) {
            return false;
        }

        if (!function_exists('as_enqueue_async_action')) {
            $this->send_purchase_event($order->get_id(), 1);
            return false;
        }

        // Mark queued only on successful scheduling so a failure here can requeue later.
        if (as_enqueue_async_action(self::SEND_HOOK, array($order->get_id(), 1), 'egentify')) {
            $order->update_meta_data(self::QUEUED_META_KEY, gmdate('c'));
            $order->save();
            return true;
        }

        return false;
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
