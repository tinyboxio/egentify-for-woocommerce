<?php
// Local regression fixture. No WordPress database or live HTTP requests.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message) { throw new RuntimeException($message); });
define('ABSPATH', __DIR__);

class WP_Post {
    public $ID, $post_type, $post_status, $post_password;
    public $post_title = 'Shipping policy';
    public $post_name = 'shipping-policy';
    public $post_content = '<p>Shipping policy fixture content</p>';
    public $post_excerpt = '';
    public $post_date_gmt = '2026-01-01 00:00:00';
    public $post_modified_gmt = '2026-01-01 00:00:00';
    public function __construct($id, $type, $status, $password) {
        $this->ID = $id; $this->post_type = $type;
        $this->post_status = $status; $this->post_password = $password;
    }
}
class WP_Query {
    public $posts;
    public function __construct($args) { $this->posts = array_keys($GLOBALS['fixture_posts']); }
}
class Fixture_DB {
    public $posts = 'fixture_posts';
    public function esc_like($value) { return $value; }
    public function prepare($sql, ...$args) { return $sql; }
    public function get_results($sql) { return array_values($GLOBALS['fixture_posts']); }
    public function get_col($sql) { return array_keys($GLOBALS['fixture_posts']); }
}
class WP_REST_Request {
    private $params;
    public function __construct($params) { $this->params = $params; }
    public function get_param($key) { return $this->params[$key] ?? null; }
}
class WP_REST_Response {
    public $data, $status;
    public $headers = array();
    public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
    public function header($name, $value) { $this->headers[$name] = $value; }
}
function get_post($id) { return $GLOBALS['fixture_posts'][$id] ?? null; }
function get_the_title($post) { return $post->post_title; }
function get_permalink($post) { return 'https://fixture.invalid/' . $post->ID; }
function has_excerpt($post) { return $post->post_excerpt !== ''; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_trim_words($text, $limit, $more) { return $text; }
function mysql2date($format, $date, $translate) { return $date; }
function setup_postdata($post) {}
function wp_reset_postdata() {}
function get_bloginfo($field) { return 'UTF-8'; }
function apply_filters($hook, $value) { return $value; }
function absint($value) { return abs((int) $value); }
function get_option($name, $default = false) { return $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function sanitize_key($text) { return strtolower($text); }
function sanitize_title($text) { return strtolower(str_replace(' ', '-', $text)); }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function remove_accents($text) { return $text; }
function update_object_term_cache($ids, $types) {}
function get_object_taxonomies($type, $output) { return array(); }
function wp_get_post_terms($id, $taxonomies, $args) { return array(); }
function is_wp_error($value) { return false; }
// Simulate a visitor who has already supplied the correct password.
function post_password_required($post) { return !empty($post->post_password) && empty($_COOKIE); }

$plugin_root = dirname(__DIR__);
require $plugin_root . '/includes/class-egentify-woocommerce-settings.php';
require $plugin_root . '/includes/class-egentify-woocommerce-content-search.php';
require $plugin_root . '/includes/class-egentify-woocommerce-product-search.php';
require $plugin_root . '/includes/class-egentify-woocommerce-rest-controller.php';

$settings = new Egentify_WooCommerce_Settings();
$content = new Egentify_WooCommerce_Content_Search($settings);
$controller = new Egentify_WooCommerce_REST_Controller($settings, new Egentify_WooCommerce_Product_Search($settings), $content);
$wpdb = new Fixture_DB();
$checks = 0;
function expect_same($actual, $expected, $label) {
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export($actual, true));
    $GLOBALS['checks']++;
}
// Include the literal password "0": PHP empty() must not treat it as public.
$cases = array(
    array(1, 'page', 'publish', '', true),
    array(2, 'post', 'publish', '', true),
    array(3, 'page', 'publish', 'fixture-password', false),
    array(4, 'post', 'publish', 'fixture-password', false),
    array(5, 'page', 'publish', '0', false),
    array(6, 'post', 'publish', '0', false),
    array(7, 'page', 'draft', '', false),
    array(8, 'post', 'private', '', false),
    array(9, 'page', 'future', '', false),
    array(10, 'attachment', 'publish', '', false),
);
$fixture_posts = array();
foreach ($cases as $case) $fixture_posts[$case[0]] = new WP_Post($case[0], $case[1], $case[2], $case[3]);
foreach (array(false, true) as $unlocked) {
    $_COOKIE = $unlocked ? array('wp-postpass_fixture' => 'synthetic-cookie') : array();
    foreach ($cases as $case) {
        list($id, $type, $status, $password, $visible) = $case;
        expect_same($content->get_content_item($id) !== null, $visible, 'direct lookup ' . $id);
        foreach (array(false, true) as $html) {
            $response = $controller->handle_content_item(new WP_REST_Request(array('id' => $id, 'html' => $html)));
            expect_same($response->status, $visible ? 200 : 404, 'REST lookup ' . $id);
            if ($visible) {
                expect_same($response->data['contentText'], 'Shipping policy fixture content', 'public content retained');
                expect_same(isset($response->data['contentHtml']), $html, 'HTML option retained');
            } else {
                expect_same(isset($response->data['contentText']), false, 'no protected body');
                expect_same(isset($response->data['excerpt']), false, 'no protected excerpt');
                expect_same(isset($response->data['contentHtml']), false, 'no protected HTML');
                expect_same(strpos($response->headers['Cache-Control'], 'no-store') !== false, true, '404 not publicly cached');
            }
        }
    }
    foreach (array(false, true) as $debug) {
        $response = $controller->handle_content_search(new WP_REST_Request(array('q' => 'shipping', 'debug' => $debug)));
        expect_same($response->status, 200, 'search succeeds');
        $ids = array_column($response->data['results'], 'id');
        sort($ids);
        expect_same($ids, array(1, 2), 'search excludes protected and nonpublic content');
    }
}
expect_same($content->get_content_item(999), null, 'missing ID');
echo $checks . " content visibility regression checks passed.\n";
