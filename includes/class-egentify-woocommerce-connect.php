<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce_Connect {
    public const CONNECT_OPTION_KEY = 'egentify_woocommerce_connection';
    public const INSTANCE_ID_OPTION_KEY = 'egentify_woocommerce_instance_id';

    /** @var Egentify_WooCommerce_Settings */
    private $settings;

    public function __construct(Egentify_WooCommerce_Settings $settings) {
        $this->settings = $settings;
    }

    public const HEARTBEAT_CRON_HOOK = 'egentify_weekly_heartbeat';

    public function register_hooks() {
        add_action('admin_post_egentify_start_connect', array($this, 'start_connect'));
        add_action('admin_post_egentify_connect_callback', array($this, 'handle_callback'));
        add_action('admin_post_egentify_disconnect', array($this, 'disconnect'));
        add_action('admin_notices', array($this, 'display_admin_notices'));
        add_action(self::HEARTBEAT_CRON_HOOK, array($this, 'send_heartbeat'));

        // Ensure weekly heartbeat is scheduled when connected
        if ($this->is_connected() && !wp_next_scheduled(self::HEARTBEAT_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', self::HEARTBEAT_CRON_HOOK);
        }
    }

    /**
     * Unschedule the heartbeat cron. Call on plugin deactivation.
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::HEARTBEAT_CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HEARTBEAT_CRON_HOOK);
        }
    }

    /**
     * Get or create the persistent plugin instance ID (UUIDv4).
     * Generated once per install, persisted permanently.
     */
    public function get_instance_id(): string {
        $id = get_option(self::INSTANCE_ID_OPTION_KEY);

        if (!$id) {
            $id = wp_generate_uuid4();
            update_option(self::INSTANCE_ID_OPTION_KEY, $id, false);
        }

        return $id;
    }

    /**
     * Check whether the plugin has an active connection to Egentify.
     */
    public function is_connected(): bool {
        $connection = self::get_connection();
        return !empty($connection['installation_id']) && !empty($connection['signing_secret']);
    }

    /**
     * Get the stored connection data, or null if not connected.
     *
     * @return array<string, mixed>|null
     */
    public static function get_connection(): ?array {
        $connection = get_option(self::CONNECT_OPTION_KEY);

        if (!is_array($connection) || empty($connection['installation_id'])) {
            return null;
        }

        return $connection;
    }

    /**
     * Initiate the connect flow. Generates PKCE pair, stores pending state,
     * and redirects to the Egentify connect page.
     */
    public function start_connect(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'egentify-for-woocommerce'));
        }

        check_admin_referer('egentify_start_connect');

        // Generate PKCE pair
        $code_verifier = bin2hex(random_bytes(32));
        $code_challenge = rtrim(strtr(base64_encode(
            hash('sha256', $code_verifier, true)
        ), '+/', '-_'), '=');

        // Generate state for CSRF protection
        $state = bin2hex(random_bytes(16));

        // Build redirect_uri via admin-post.php
        $redirect_uri = admin_url('admin-post.php?action=egentify_connect_callback');

        // Store pending connect data as user-scoped transient (10 min TTL)
        set_transient(
            'egentify_connect_pending_' . get_current_user_id(),
            array(
                'state'         => $state,
                'code_verifier' => $code_verifier,
                'redirect_uri'  => $redirect_uri,
            ),
            600
        );

        // Build Egentify connect URL
        $connect_url = add_query_arg(
            array(
                'site_url'              => home_url(),
                'plugin_instance_id'    => $this->get_instance_id(),
                'redirect_uri'          => $redirect_uri,
                'state'                 => $state,
                'code_challenge'        => $code_challenge,
                'code_challenge_method' => 'S256',
                'connect_version'       => '1',
            ),
            $this->settings->get_app_base_url() . '/connect/woocommerce'
        );

        wp_redirect($connect_url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    /**
     * Handle the callback from Egentify after user authorizes.
     * Validates state, exchanges code for credentials, stores connection.
     */
    public function handle_callback(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'egentify-for-woocommerce'));
        }

        // Check for error response from Egentify
        if (isset($_GET['error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $error_desc = isset($_GET['error_description']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                ? sanitize_text_field(wp_unslash($_GET['error_description'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                : sanitize_text_field(wp_unslash($_GET['error'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $this->add_admin_notice(
                'error',
                /* translators: %s: error description returned by Egentify */
                sprintf(__('Egentify connection failed: %s', 'egentify-for-woocommerce'), $error_desc)
            );
            wp_safe_redirect(admin_url('admin.php?page=' . Egentify_WooCommerce_Settings::MENU_SLUG));
            exit;
        }

        // Validate state against stored pending data
        $pending = get_transient('egentify_connect_pending_' . get_current_user_id());
        $received_state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$pending || !hash_equals($pending['state'], $received_state)) {
            $this->add_admin_notice('error', __('Invalid or expired connect session. Please try again.', 'egentify-for-woocommerce'));
            wp_safe_redirect(admin_url('admin.php?page=' . Egentify_WooCommerce_Settings::MENU_SLUG));
            exit;
        }

        // Delete transient (single use)
        delete_transient('egentify_connect_pending_' . get_current_user_id());

        // Exchange code server-to-server
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ('' === $code) {
            $this->add_admin_notice('error', __('No authorization code received. Please try again.', 'egentify-for-woocommerce'));
            wp_safe_redirect(admin_url('admin.php?page=' . Egentify_WooCommerce_Settings::MENU_SLUG));
            exit;
        }

        $response = wp_remote_post(
            $this->settings->get_app_base_url() . '/api/connect/exchange',
            array(
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode(array(
                    'code'               => $code,
                    'code_verifier'      => $pending['code_verifier'],
                    'plugin_instance_id' => $this->get_instance_id(),
                    'redirect_uri'       => $pending['redirect_uri'],
                )),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $msg = is_array($body) && !empty($body['error']) ? $body['error'] : __('Unknown error during code exchange.', 'egentify-for-woocommerce');
            $this->add_admin_notice(
                'error',
                /* translators: %s: error message returned by Egentify */
                sprintf(__('Connection failed: %s', 'egentify-for-woocommerce'), $msg)
            );
            wp_safe_redirect(admin_url('admin.php?page=' . Egentify_WooCommerce_Settings::MENU_SLUG));
            exit;
        }

        // Store connection data
        $data = json_decode(wp_remote_retrieve_body($response), true);

        $connection = array(
            'installation_id'     => sanitize_text_field($data['installation_id'] ?? ''),
            'project_id'          => sanitize_text_field($data['project_id'] ?? ''),
            'signing_secret'      => sanitize_text_field($data['signing_secret'] ?? ''),
            'installation_secret' => sanitize_text_field($data['installation_secret'] ?? ''),
            'project_name'        => sanitize_text_field($data['project_name'] ?? ''),
            'primary_color'       => sanitize_hex_color($data['primary_color'] ?? '') ?: '',
            'agent_name'          => sanitize_text_field($data['agent_name'] ?? ''),
            'connected_at'        => gmdate('c'),
            'connected_by'        => get_current_user_id(),
            'site_host'           => self::normalize_host(home_url()),
        );

        update_option(self::CONNECT_OPTION_KEY, $connection, false);

        // Collect a connect trace, logged via log_debug() when WP_DEBUG is on.
        $debug = array();

        // Generate WooCommerce REST API keys and send to Egentify.
        // This gives Egentify's commerce provider the ability to call
        // WooCommerce APIs (orders, refunds, customers, products) directly.
        $this->generate_and_send_api_keys($connection, $debug);

        // Schedule weekly heartbeat cron
        if (!wp_next_scheduled(self::HEARTBEAT_CRON_HOOK)) {
            wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', self::HEARTBEAT_CRON_HOOK);
        }

        // Send first heartbeat to trigger health check.
        // This must happen AFTER storing the connection, because the health check
        // probes the plugin's widget-session endpoint which requires local config.
        // First heartbeat is non-fatal — connection succeeds even if this times out.
        $health_ok = $this->send_heartbeat($debug);

        if ($health_ok) {
            $this->add_admin_notice(
                'success',
                sprintf(
                    /* translators: %s: connected Egentify project name (wrapped in <strong>) */
                    __('Connected to <strong>%s</strong>.', 'egentify-for-woocommerce'),
                    esc_html($connection['project_name'])
                )
            );
        } else {
            $this->add_admin_notice(
                'success',
                sprintf(
                    /* translators: %s: connected Egentify project name (wrapped in <strong>) */
                    __('Connected to <strong>%s</strong>. Health check is pending — Egentify will verify your endpoints shortly.', 'egentify-for-woocommerce'),
                    esc_html($connection['project_name'])
                )
            );
        }

        // Log the connect trace for troubleshooting. Only written when WP_DEBUG
        // is enabled; never displayed to the user.
        $this->log_debug($debug);

        wp_safe_redirect(admin_url('admin.php?page=' . Egentify_WooCommerce_Settings::MENU_SLUG));
        exit;
    }

    /**
     * Generate WooCommerce REST API keys and send them to Egentify.
     *
     * Creates a read_write API key pair for the connecting user, then POSTs
     * the consumer_key and consumer_secret to Egentify's store-credentials
     * endpoint so the commerce provider can call WooCommerce APIs directly.
     *
     * Non-fatal: if key generation or sending fails, the connection still
     * succeeds — the user can configure keys manually in the dashboard.
     *
     * @param array<string, mixed> $connection The stored connection data.
     */
    private function generate_and_send_api_keys(array $connection, array &$debug = array()): void {
        if (!function_exists('wc_api_hash') || !function_exists('wc_rand_hash')) {
            $debug[] = 'API keys: WooCommerce functions not available (wc_api_hash / wc_rand_hash)';
            return;
        }

        global $wpdb;

        // Remove any previous Egentify API keys to avoid accumulation on reconnect
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete(
            $wpdb->prefix . 'woocommerce_api_keys',
            array('description' => 'Egentify (auto-generated)'),
            array('%s')
        );

        $user_id = get_current_user_id();
        if (!$user_id) {
            $debug[] = 'API keys: no current user';
            return;
        }

        $consumer_key    = 'ck_' . wc_rand_hash();
        $consumer_secret = 'cs_' . wc_rand_hash();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert(
            $wpdb->prefix . 'woocommerce_api_keys',
            array(
                'user_id'         => $user_id,
                'description'     => 'Egentify (auto-generated)',
                'permissions'     => 'read_write',
                'consumer_key'    => wc_api_hash($consumer_key),
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => substr($consumer_key, -7),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            $debug[] = 'API keys: DB insert failed — ' . $wpdb->last_error;
            return;
        }

        $debug[] = 'API keys: created (truncated: ...' . substr($consumer_key, -7) . ')';

        // Send keys to Egentify for encrypted storage
        $store_url = $this->settings->get_app_base_url() . '/api/connect/store-credentials';
        $debug[] = 'API keys: POST ' . $store_url;

        $creds_response = wp_remote_post(
            $store_url,
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . ($connection['installation_secret'] ?? ''),
                ),
                'body'    => wp_json_encode(array(
                    'installation_id' => $connection['installation_id'],
                    'consumer_key'    => $consumer_key,
                    'consumer_secret' => $consumer_secret,
                )),
                'timeout' => 10,
            )
        );

        if (is_wp_error($creds_response)) {
            $debug[] = 'API keys: WP_Error — ' . $creds_response->get_error_message();
        } else {
            $creds_status = wp_remote_retrieve_response_code($creds_response);
            if (200 === $creds_status) {
                $debug[] = 'API keys: stored in Egentify OK';
            } else {
                $debug[] = 'API keys: HTTP ' . $creds_status . ' — ' . substr(wp_remote_retrieve_body($creds_response), 0, 300);
            }
        }
    }

    /**
     * Disconnect from Egentify. Notifies Egentify (best-effort),
     * then clears connection data. Manual settings remain as fallback.
     */
    public function disconnect(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'egentify-for-woocommerce'));
        }

        check_admin_referer('egentify_disconnect');

        $connection = self::get_connection();

        if ($connection) {
            // Notify Egentify (best-effort, don't block on failure)
            wp_remote_post(
                $this->settings->get_app_base_url() . '/api/connect/disconnect',
                array(
                    'headers' => array(
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . ($connection['installation_secret'] ?? ''),
                    ),
                    'body'    => wp_json_encode(array(
                        'installation_id' => $connection['installation_id'],
                    )),
                    'timeout' => 5,
                )
            );
        }

        // Remove auto-generated WooCommerce API keys
        if (function_exists('wc_api_hash')) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete(
                $wpdb->prefix . 'woocommerce_api_keys',
                array('description' => 'Egentify (auto-generated)'),
                array('%s')
            );
        }

        // Clear connection data only. Do NOT touch the manual settings option.
        delete_option(self::CONNECT_OPTION_KEY);

        // Unschedule heartbeat cron
        $timestamp = wp_next_scheduled(self::HEARTBEAT_CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HEARTBEAT_CRON_HOOK);
        }

        // Admin notice — state what happens next
        $settings = $this->settings->get_settings();
        $has_manual_fallback = !empty($settings['project_id']) && !empty($settings['signing_secret']);

        if ($has_manual_fallback) {
            $this->add_admin_notice(
                'success',
                __('Disconnected from Egentify. Manual configuration is still active — the widget will continue working using your manually configured project ID and signing secret. Clear the Advanced settings if you want to fully disable the widget.', 'egentify-for-woocommerce')
            );
        } else {
            $this->add_admin_notice('success', __('Disconnected from Egentify. The widget is now inactive.', 'egentify-for-woocommerce'));
        }

        wp_safe_redirect(admin_url('admin.php?page=' . Egentify_WooCommerce_Settings::MENU_SLUG));
        exit;
    }

    /**
     * Send a heartbeat to Egentify. Called via WP-Cron weekly and
     * immediately after handle_callback() stores the connection.
     * Returns true on success, false on failure.
     */
    public function send_heartbeat(array &$debug = array()): bool {
        $connection = self::get_connection();

        if (!$connection || empty($connection['installation_id']) || empty($connection['installation_secret'])) {
            $debug[] = 'Heartbeat: no connection or missing installation_id/secret';
            return false;
        }

        $url = $this->settings->get_app_base_url() . '/api/connect/heartbeat';
        $debug[] = 'Heartbeat: POST ' . $url;

        $response = wp_remote_post(
            $url,
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $connection['installation_secret'],
                ),
                'body'    => wp_json_encode(array(
                    'installation_id' => $connection['installation_id'],
                    'plugin_version'  => EGENTIFY_WOOCOMMERCE_VERSION,
                    'wp_version'      => get_bloginfo('version'),
                    'woo_version'     => defined('WC_VERSION') ? WC_VERSION : '',
                    'php_version'     => PHP_VERSION,
                )),
                'timeout' => 10,
            )
        );

        if (is_wp_error($response)) {
            $debug[] = 'Heartbeat: WP_Error — ' . $response->get_error_message();
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);

        if (200 !== $status_code) {
            $debug[] = 'Heartbeat: HTTP ' . $status_code . ' — ' . substr($body, 0, 500);
        } else {
            $debug[] = 'Heartbeat: OK';
        }

        // 401 means installation was revoked — disconnect locally
        if (401 === $status_code) {
            delete_option(self::CONNECT_OPTION_KEY);
            $timestamp = wp_next_scheduled(self::HEARTBEAT_CRON_HOOK);
            if ($timestamp) {
                wp_unschedule_event($timestamp, self::HEARTBEAT_CRON_HOOK);
            }
            return false;
        }

        return 200 === $status_code;
    }

    /**
     * Store a cross-redirect admin notice via transient.
     */
    private function add_admin_notice(string $type, string $message): void {
        set_transient(
            'egentify_admin_notice_' . get_current_user_id(),
            array('type' => $type, 'message' => $message),
            60
        );
    }

    /**
     * Log the connect trace to the PHP error log for troubleshooting.
     * Only writes when WP_DEBUG is enabled; never shown to users.
     *
     * @param string[] $lines Debug log lines.
     */
    private function log_debug(array $lines): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG || empty($lines)) {
            return;
        }

        foreach ($lines as $line) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Egentify connect: ' . $line);
        }
    }

    /**
     * Display and clear any stored admin notices.
     */
    public function display_admin_notices(): void {
        $notice = get_transient('egentify_admin_notice_' . get_current_user_id());

        if ($notice && is_array($notice) && !empty($notice['type'])) {
            delete_transient('egentify_admin_notice_' . get_current_user_id());

            $type = in_array($notice['type'], array('success', 'error', 'warning', 'info'), true)
                ? $notice['type']
                : 'info';

            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                wp_kses($notice['message'], array('strong' => array()))
            );
        }
    }

    /**
     * Normalize a URL or hostname to a bare lowercase host.
     * Strips protocol, www prefix, and trailing dots.
     * Used for consistency checks, never for project selection.
     */
    public static function normalize_host(string $url): string {
        if (!preg_match('#^https?://#', $url)) {
            $url = 'https://' . $url;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $host = strtolower($host ?? '');
        $host = preg_replace('/^www\./', '', $host);

        return rtrim($host, '.');
    }
}
