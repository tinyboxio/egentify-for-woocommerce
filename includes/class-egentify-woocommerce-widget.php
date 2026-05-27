<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce_Widget {
    const SCRIPT_HANDLE = 'egentify-chat-widget';

    /** @var Egentify_WooCommerce_Settings */
    private $settings;

    /** @var bool */
    private $rendered = false;

    public function __construct(Egentify_WooCommerce_Settings $settings) {
        $this->settings = $settings;
    }

    public function register_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_widget'));
        add_action('wp_footer', array($this, 'maybe_render_widget'), 100);
        add_shortcode(Egentify_WooCommerce_Settings::SHORTCODE, array($this, 'render_shortcode'));
    }

    public function maybe_enqueue_widget() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $settings = $this->settings->get_settings();
        if ('1' !== $settings['auto_inject']) {
            return;
        }

        $this->enqueue_widget_script();
    }

    private function enqueue_widget_script() {
        $widget_script_url = $this->settings->get_widget_script_url();
        $project_id = $this->settings->get_project_id();

        if ('' === $widget_script_url || '' === $project_id) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $widget_script_url,
            array(),
            EGENTIFY_WOOCOMMERCE_VERSION,
            true
        );

        if (function_exists('wp_create_nonce')) {
            $store_nonce = wp_create_nonce('wc_store_api');
            wp_add_inline_script(
                self::SCRIPT_HANDLE,
                'window.egentifyStoreApiNonce=' . wp_json_encode($store_nonce) . ';',
                'before'
            );
        }
    }

    public function maybe_render_widget() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $settings = $this->settings->get_settings();
        if ('1' !== $settings['auto_inject']) {
            return;
        }

        echo $this->build_widget_tag_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All attribute names and values are escaped via esc_attr() inside build_widget_tag().
    }

    public function render_shortcode() {
        $this->enqueue_widget_script();
        return $this->build_widget_tag_markup();
    }

    private function build_widget_tag_markup() {
        if ($this->rendered) {
            return '';
        }

        $widget_script_url = $this->settings->get_widget_script_url();
        $api_url = $this->settings->get_api_url();
        $project_id = $this->settings->get_project_id();

        if ('' === $widget_script_url || '' === $api_url || '' === $project_id) {
            return '';
        }

        $settings = $this->settings->get_settings();
        $primary_color = $this->settings->get_primary_color();
        $session_path = $this->settings->get_rest_endpoint_url();

        $attributes = array(
            'project-id' => $project_id,
            'api-url' => $api_url,
            'woo-session-path' => $session_path,
        );

        if ('' !== $primary_color) {
            $attributes['primary-color'] = $primary_color;
        }

        // Widget appearance — pass through only when locally configured.
        // Empty/blank means "use widget defaults" (matches Chat.svelte
        // attribute-parsing behavior).
        if (!empty($settings['widget_position'])) {
            $attributes['widget-position'] = (string) $settings['widget_position'];
        }
        if ('' !== (string) ($settings['widget_offset_x'] ?? '')) {
            $attributes['widget-offset-x'] = (string) $settings['widget_offset_x'];
        }
        if ('' !== (string) ($settings['widget_offset_y'] ?? '')) {
            $attributes['widget-offset-y'] = (string) $settings['widget_offset_y'];
        }
        if ('' !== (string) ($settings['widget_window_corner_radius'] ?? '')) {
            $attributes['widget-window-radius'] = (string) $settings['widget_window_corner_radius'];
        }
        if (!empty($settings['widget_starter_buttons']) && is_array($settings['widget_starter_buttons'])) {
            $attributes['widget-starter-buttons'] = wp_json_encode(
                array_values($settings['widget_starter_buttons'])
            );
        }
        if (!empty($settings['widget_welcome_text'])) {
            $attributes['widget-welcome-text'] = (string) $settings['widget_welcome_text'];
        }

        $this->rendered = true;

        return $this->build_widget_tag($attributes);
    }

    private function build_widget_tag($attributes) {
        $pairs = array();

        foreach ($attributes as $name => $value) {
            if ('' === $value) {
                continue;
            }

            $pairs[] = sprintf('%s="%s"', esc_attr($name), esc_attr($value));
        }

        return sprintf('<egentify-chat %s></egentify-chat>', implode(' ', $pairs));
    }
}
