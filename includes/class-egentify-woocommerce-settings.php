<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce_Settings {
    public const OPTION_KEY = 'egentify_woocommerce_settings';
    public const SETTINGS_GROUP = 'egentify_woocommerce';
    public const MENU_SLUG = 'egentify-for-woocommerce';
    public const SHORTCODE = 'egentify_chat_widget';
    public const TEXT_DOMAIN = 'egentify-for-woocommerce';
    public const REST_NAMESPACE = 'egentify/v1';
    public const REST_ROUTE = '/widget-session';
    public const SIGNING_SECRET_CONSTANT = 'EGENTIFY_WOOCOMMERCE_SIGNING_SECRET';
    public const DEFAULT_APP_BASE_URL = 'https://egentify.com';

    public function register_setting_definition() {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            array($this, 'sanitize_settings')
        );
    }

    public function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $existing_settings = get_option(self::OPTION_KEY, array());
        $existing_secret = '';

        if (is_array($existing_settings) && !empty($existing_settings['signing_secret'])) {
            $existing_secret = sanitize_text_field($existing_settings['signing_secret']);
        }

        $signing_secret = '';
        if (!$this->is_constant_signing_secret_configured()) {
            $submitted_secret = sanitize_text_field($input['signing_secret'] ?? '');
            $signing_secret = '' !== $submitted_secret ? $submitted_secret : $existing_secret;
        }

        return array(
            'project_id' => sanitize_text_field($input['project_id'] ?? ''),
            'signing_secret' => $signing_secret,
            'primary_color' => sanitize_hex_color($input['primary_color'] ?? '') ?: '',
            'auto_inject' => !empty($input['auto_inject']) ? '1' : '0',
            'search_synonyms' => $this->sanitize_search_synonyms($input['search_synonyms'] ?? array()),
            'widget_position' => $this->sanitize_widget_position($input['widget_position'] ?? ''),
            'widget_offset_x' => $this->sanitize_widget_int($input['widget_offset_x'] ?? '', 0, 200),
            'widget_offset_y' => $this->sanitize_widget_int($input['widget_offset_y'] ?? '', 0, 200),
            'widget_window_corner_radius' => $this->sanitize_widget_int($input['widget_window_corner_radius'] ?? '', 0, 30),
            'widget_starter_buttons' => $this->sanitize_widget_starter_buttons($input['widget_starter_buttons'] ?? array()),
            'widget_welcome_text' => $this->sanitize_widget_welcome_text($input['widget_welcome_text'] ?? ''),
        );
    }

    public function get_settings() {
        $settings = get_option(self::OPTION_KEY, array());

        if (!is_array($settings)) {
            $settings = array();
        }

        return wp_parse_args($settings, $this->get_defaults());
    }

    public function get_defaults() {
        return array(
            'project_id' => '',
            'signing_secret' => '',
            'primary_color' => '',
            'auto_inject' => '1',
            'search_synonyms' => array(),
            'widget_position' => '',
            'widget_offset_x' => '',
            'widget_offset_y' => '',
            'widget_window_corner_radius' => '',
            'widget_starter_buttons' => array(),
            'widget_welcome_text' => '',
        );
    }

    private function sanitize_widget_position($value): string {
        $value = is_string($value) ? trim($value) : '';
        return in_array($value, array('bottom-right', 'bottom-left'), true) ? $value : '';
    }

    private function sanitize_widget_int($value, int $min, int $max): string {
        if (!is_scalar($value)) return '';
        $trimmed = trim((string) $value);
        if ('' === $trimmed) return '';
        if (!preg_match('/^-?\d+$/', $trimmed)) return '';
        $n = (int) $trimmed;
        if ($n < $min || $n > $max) return '';
        return (string) $n;
    }

    private function sanitize_widget_starter_buttons($value): array {
        if (!is_array($value)) return array();
        $cleaned = array();
        foreach ($value as $entry) {
            if (!is_string($entry)) continue;
            $trimmed = trim($entry);
            if ('' === $trimmed) continue;
            // Server-side cap on bytes; egentify revalidates on receive.
            if (mb_strlen($trimmed) > 40) {
                $trimmed = mb_substr($trimmed, 0, 40);
            }
            $cleaned[] = sanitize_text_field($trimmed);
            if (count($cleaned) >= 4) break;
        }
        return $cleaned;
    }

    private function sanitize_widget_welcome_text($value): string {
        if (!is_string($value)) return '';
        $trimmed = trim($value);
        if ('' === $trimmed) return '';
        if (mb_strlen($trimmed) > 300) {
            $trimmed = mb_substr($trimmed, 0, 300);
        }
        return sanitize_textarea_field($trimmed);
    }

    public function sanitize_search_synonyms($value) {
        if (!is_array($value)) {
            return array();
        }

        $rows = array();

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $term = sanitize_text_field($row['term'] ?? '');
            $aliases = $row['aliases'] ?? array();

            if (is_string($aliases)) {
                $aliases = explode(',', $aliases);
            }

            $aliases = is_array($aliases) ? $aliases : array();
            $aliases = array_map('sanitize_text_field', $aliases);
            $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases))));

            if ('' === $term || empty($aliases)) {
                continue;
            }

            $rows[] = array(
                'term' => $term,
                'aliases' => $aliases,
            );
        }

        return array_values($rows);
    }

    public function get_rest_endpoint_url() {
        return rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
    }

    public function get_app_base_url() {
        $app_base_url = apply_filters('egentify_woocommerce_app_base_url', self::DEFAULT_APP_BASE_URL);
        $app_base_url = esc_url_raw(untrailingslashit((string) $app_base_url));

        return '' !== $app_base_url ? $app_base_url : self::DEFAULT_APP_BASE_URL;
    }

    public function get_api_url() {
        return $this->get_app_base_url() . '/api/chat';
    }

    public function get_widget_script_url() {
        return $this->get_app_base_url() . '/widget/chat-widget.js';
    }

    public function get_signing_secret() {
        // 1. Constant takes absolute precedence
        if ($this->is_constant_signing_secret_configured()) {
            return (string) constant(self::SIGNING_SECRET_CONSTANT);
        }

        // 2. Connected install secret
        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!empty($connection['signing_secret'])) {
            return (string) $connection['signing_secret'];
        }

        // 3. Manual database entry (advanced fallback)
        $settings = $this->get_settings();
        return !empty($settings['signing_secret']) ? (string) $settings['signing_secret'] : '';
    }

    /**
     * Get the project ID. Checks connection first, falls back to manual settings.
     */
    public function get_project_id(): string {
        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!empty($connection['project_id'])) {
            return (string) $connection['project_id'];
        }

        $settings = $this->get_settings();
        return !empty($settings['project_id']) ? (string) $settings['project_id'] : '';
    }

    /**
     * Get the primary color. Manual settings take precedence so the
     * local widget color control still works while connected.
     */
    public function get_primary_color(): string {
        $settings = $this->get_settings();
        if (!empty($settings['primary_color'])) {
            return (string) $settings['primary_color'];
        }

        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!empty($connection['primary_color'])) {
            return (string) $connection['primary_color'];
        }

        return '';
    }

    /**
     * Get the agent name from the connection (no manual fallback).
     */
    public function get_agent_name(): string {
        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!empty($connection['agent_name'])) {
            return (string) $connection['agent_name'];
        }

        return '';
    }

    public function has_signing_secret() {
        return '' !== $this->get_signing_secret();
    }

    public function is_constant_signing_secret_configured() {
        return defined(self::SIGNING_SECRET_CONSTANT) && '' !== trim((string) constant(self::SIGNING_SECRET_CONSTANT));
    }

    public function has_stored_signing_secret() {
        $settings = $this->get_settings();
        return !empty($settings['signing_secret']);
    }

    public function get_signing_secret_source() {
        if ($this->is_constant_signing_secret_configured()) {
            return 'constant';
        }

        if ($this->has_stored_signing_secret()) {
            return 'database';
        }

        return 'missing';
    }

    public function get_search_synonym_groups() {
        $settings = $this->get_settings();
        $raw_value = $settings['search_synonyms'] ?? array();
        $raw_value = is_array($raw_value) ? $raw_value : array();
        $groups = array();

        foreach ($raw_value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $phrases = array_merge(
                array(sanitize_text_field($row['term'] ?? '')),
                array_map('sanitize_text_field', is_array($row['aliases'] ?? null) ? $row['aliases'] : array())
            );
            $phrases = array_values(array_unique(array_filter(array_map('trim', $phrases))));

            if (count($phrases) < 2) {
                continue;
            }

            $groups[] = $phrases;
        }

        return apply_filters('egentify_woocommerce_search_synonym_groups', $groups, $settings);
    }

    public function get_current_customer_id() {
        // Try the standard WordPress path first (works when nonce is valid).
        $user_id = get_current_user_id();

        // Fallback: validate the logged_in cookie directly. WordPress REST API
        // zeros out the current user when no nonce is sent, even with valid
        // cookies. Since this endpoint is read-only, CSRF protection is not
        // needed and we can authenticate from the cookie alone.
        if (!$user_id) {
            $user_id = wp_validate_auth_cookie('', 'logged_in');
        }

        if (!$user_id) {
            return '';
        }

        $user = get_userdata($user_id);
        if (!($user instanceof WP_User) || !$user->exists()) {
            return '';
        }

        return (string) $user_id;
    }

    public function get_store_host() {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    public function sign_payload($payload, $secret) {
        $encoded = rtrim(strtr(base64_encode(wp_json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $secret);

        return $encoded . '.' . $signature;
    }

    public function build_json_response($data, $status = 200) {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Robots-Tag', 'noindex');
        $response->header('Vary', 'Cookie');

        return $response;
    }
}
