<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce_REST_Controller {
    /** @var Egentify_WooCommerce_Settings */
    private $settings;

    /** @var Egentify_WooCommerce_Product_Search */
    private $product_search;

    /** @var Egentify_WooCommerce_Content_Search */
    private $content_search;

    public function __construct(Egentify_WooCommerce_Settings $settings, Egentify_WooCommerce_Product_Search $product_search, Egentify_WooCommerce_Content_Search $content_search) {
        $this->settings = $settings;
        $this->product_search = $product_search;
        $this->content_search = $content_search;
    }

    public function register_hooks() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function register_rest_routes() {
        register_rest_route(
            Egentify_WooCommerce_Settings::REST_NAMESPACE,
            Egentify_WooCommerce_Settings::REST_ROUTE,
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_widget_session'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            Egentify_WooCommerce_Settings::REST_NAMESPACE,
            '/search/products',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_product_search'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'q' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'limit' => array(
                        'sanitize_callback' => 'absint',
                    ),
                    'category' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'min_price' => array(
                        'sanitize_callback' => array($this, 'sanitize_decimal'),
                    ),
                    'max_price' => array(
                        'sanitize_callback' => array($this, 'sanitize_decimal'),
                    ),
                ),
            )
        );

        register_rest_route(
            Egentify_WooCommerce_Settings::REST_NAMESPACE,
            '/search/content',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_content_search'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'q' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'limit' => array(
                        'sanitize_callback' => 'absint',
                    ),
                    'type' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        register_rest_route(
            Egentify_WooCommerce_Settings::REST_NAMESPACE,
            '/content/(?P<id>\d+)',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_content_item'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'sanitize_callback' => 'absint',
                    ),
                    'html' => array(
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        register_rest_route(
            Egentify_WooCommerce_Settings::REST_NAMESPACE,
            '/rotate-signing-secret',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'handle_rotate_signing_secret'),
                'permission_callback' => '__return_true',
            )
        );

    }

    public function handle_widget_session(WP_REST_Request $request) {
        $project_id = $this->settings->get_project_id();
        $signing_secret = $this->settings->get_signing_secret();

        if ('' === $project_id || '' === $signing_secret) {
            return $this->settings->build_json_response(
                array('error' => 'Plugin is not fully configured.'),
                503
            );
        }

        $customer_id = $this->settings->get_current_customer_id();

        if ('' === $customer_id) {
            return $this->settings->build_json_response(
                array(
                    'authenticated' => false,
                    'customerId' => '',
                    'token' => '',
                    'expiresAt' => null,
                )
            );
        }

        $issued_at = time();
        $expires_at = $issued_at + 300;
        $payload = array(
            'source' => 'woo_widget_session',
            'projectId' => $project_id,
            'customerId' => $customer_id,
            'site' => $this->settings->get_store_host(),
            'iat' => $issued_at,
            'exp' => $expires_at,
        );

        return $this->settings->build_json_response(
            array(
                'authenticated' => true,
                'customerId' => $customer_id,
                'token' => $this->settings->sign_payload($payload, $signing_secret),
                'expiresAt' => $expires_at,
            )
        );
    }

    public function handle_product_search(WP_REST_Request $request) {
        if (!function_exists('wc_get_product')) {
            return $this->settings->build_json_response(
                array('error' => 'WooCommerce is not available.'),
                503
            );
        }

        $query = sanitize_text_field((string) $request->get_param('q'));
        if ('' === trim($query)) {
            return $this->settings->build_json_response(
                array('error' => 'A search query is required.'),
                400
            );
        }

        $debug = $this->to_bool($request->get_param('debug'));

        return $this->build_product_search_response(
            $this->product_search->search(
                $query,
                array(
                    'limit' => $request->get_param('limit'),
                    'in_stock' => $this->to_bool($request->get_param('in_stock')),
                    'on_sale' => $this->to_bool($request->get_param('on_sale')),
                    'categories' => $this->parse_list($request->get_param('category')),
                    'min_price' => $this->sanitize_decimal($request->get_param('min_price')),
                    'max_price' => $this->sanitize_decimal($request->get_param('max_price')),
                    'debug' => $debug,
                )
            ),
            $debug
        );
    }

    public function handle_content_search(WP_REST_Request $request) {
        $query = sanitize_text_field((string) $request->get_param('q'));
        if ('' === trim($query)) {
            return $this->settings->build_json_response(
                array('error' => 'A search query is required.'),
                400
            );
        }

        $debug = $this->to_bool($request->get_param('debug'));

        return $this->build_search_response(
            $this->content_search->search(
                $query,
                array(
                    'limit' => $request->get_param('limit'),
                    'types' => $this->parse_list($request->get_param('type')),
                    'debug' => $debug,
                )
            ),
            $debug,
            'egentify_woocommerce_content_search_cache_ttl'
        );
    }

    public function handle_content_item(WP_REST_Request $request) {
        $post_id = absint($request->get_param('id'));

        if ($post_id < 1) {
            return $this->settings->build_json_response(
                array('error' => 'A valid content ID is required.'),
                400
            );
        }

        $item = $this->content_search->get_content_item(
            $post_id,
            array(
                'include_html' => $this->to_bool($request->get_param('html')),
            )
        );

        if (!is_array($item)) {
            return $this->settings->build_json_response(
                array('error' => 'Content item not found.'),
                404
            );
        }

        return $this->build_search_response(
            $item,
            false,
            'egentify_woocommerce_content_item_cache_ttl'
        );
    }

    public function handle_rotate_signing_secret(WP_REST_Request $request) {
        $connection = Egentify_WooCommerce_Connect::get_connection();
        if (!$connection) {
            return new WP_REST_Response(array('error' => 'not_connected'), 404);
        }

        // Validate installation_id matches
        $installation_id = sanitize_text_field($request->get_param('installation_id'));
        if (!$installation_id || $installation_id !== ($connection['installation_id'] ?? '')) {
            return new WP_REST_Response(array('error' => 'unauthorized'), 401);
        }

        // Validate installation_secret: try custom header first, then Bearer fallback.
        // Custom header avoids failures on WordPress hosts that strip Authorization.
        $provided = $request->get_header('X-Egentify-Installation-Secret');
        if (!$provided) {
            $auth_header = $request->get_header('Authorization');
            $provided = $auth_header ? preg_replace('/^Bearer\s+/i', '', $auth_header) : '';
        }
        if (!$provided || !hash_equals($connection['installation_secret'], $provided)) {
            return new WP_REST_Response(array('error' => 'unauthorized'), 401);
        }

        // Update signing_secret in connection option only (canonical source)
        $new_secret = sanitize_text_field($request->get_param('signing_secret'));
        if (!$new_secret || strlen($new_secret) < 32) {
            return new WP_REST_Response(array('error' => 'invalid_secret'), 400);
        }

        $connection['signing_secret'] = $new_secret;
        update_option(Egentify_WooCommerce_Connect::CONNECT_OPTION_KEY, $connection, false);

        return new WP_REST_Response(array('rotated' => true), 200);
    }

    private function build_product_search_response($data, $debug = false) {
        return $this->build_search_response($data, $debug, 'egentify_woocommerce_product_search_cache_ttl');
    }

    private function build_search_response($data, $debug = false, $ttl_filter = 'egentify_woocommerce_product_search_cache_ttl') {
        $response = new WP_REST_Response($data, 200);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $ttl_filter is set by callers to a hardcoded, plugin-prefixed string (egentify_woocommerce_*).
        $cache_ttl = $debug ? 0 : (int) apply_filters($ttl_filter, 120);

        if ($cache_ttl > 0) {
            $response->header('Cache-Control', sprintf('public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d', $cache_ttl, $cache_ttl, $cache_ttl));
        } else {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
        }

        $response->header('X-Robots-Tag', 'noindex');

        return $response;
    }

    public function sanitize_decimal($value) {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function to_bool($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return 1 === (int) $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, array('1', 'true', 'yes', 'on'), true);
        }

        return false;
    }

    private function parse_list($value) {
        if (!is_string($value) || '' === trim($value)) {
            return array();
        }

        $items = array_map('trim', explode(',', $value));
        $items = array_filter(array_map('sanitize_text_field', $items));

        return array_values(array_unique($items));
    }
}
