<?php
/** Run with `php bin/test-settings-saves.php`; no WordPress database or network required. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new RuntimeException($message . ' at ' . $file . ':' . $line);
});
define('ABSPATH', __DIR__ . '/');
define('EGENTIFY_WOOCOMMERCE_PLUGIN_FILE', __FILE__);

$stored_settings = array();
$hooks = array();
$registered_settings = array();
$capabilities = array('manage_woocommerce' => true, 'manage_options' => true);
$checks = 0;
$failures = array();
function check_same($actual, $expected, $label) {
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) $failures[] = $label;
}
function get_option($key, $default = false) { global $stored_settings; return $stored_settings; }
function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
function sanitize_text_field($value) { return is_scalar($value) ? trim(strip_tags((string) $value)) : ''; }
function sanitize_textarea_field($value) { return sanitize_text_field($value); }
function sanitize_hex_color($value) { return preg_match('/^#[0-9a-f]{6}$/i', (string) $value) ? $value : null; }
function add_filter($hook, $callback) { global $hooks; $hooks[$hook][] = $callback; }
function add_action($hook, $callback) { add_filter($hook, $callback); }
function apply_filters($hook, $value) {
    global $hooks;
    foreach ($hooks[$hook] ?? array() as $callback) $value = call_user_func($callback, $value);
    return $value;
}
function register_setting($group, $key, $callback) { global $registered_settings; $registered_settings[$group][$key] = $callback; }
function current_user_can($capability) { global $capabilities; return !empty($capabilities[$capability]); }
function wp_die($message) { throw new RuntimeException($message); }
function __($text, $domain = '') { return $text; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_attr__($text, $domain = '') { return esc_attr($text); }
function esc_textarea($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function esc_url_raw($value) { return $value; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function plugins_url($path, $file) { return '/plugin/' . $path; }
function wp_nonce_url($url, $action) { return $url; }
function admin_url($path) { return '/admin/' . $path; }
function settings_fields($group) {
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">';
    echo '<input type="hidden" name="action" value="update"><input type="hidden" name="_wpnonce" value="local-test-nonce">';
}
function submit_button($text = 'Save Changes') { echo '<button type="submit">' . esc_html($text) . '</button>'; }
function selected($actual, $expected) { if ($actual === $expected) echo 'selected'; }
function checked($actual, $expected) { if ($actual === $expected) echo 'checked'; }
class Egentify_WooCommerce_Connect {
    public static function get_connection() { return array(); }
    public function is_connected() { return false; }
}

$plugin_dir = $argv[1] ?? dirname(__DIR__);
require $plugin_dir . '/includes/class-egentify-woocommerce-settings.php';
require $plugin_dir . '/includes/class-egentify-woocommerce-admin.php';
$settings = new Egentify_WooCommerce_Settings();
$admin = new Egentify_WooCommerce_Admin($settings, new Egentify_WooCommerce_Connect());

function settings_forms($admin) {
    ob_start();
    $admin->render_settings_page();
    $html = ob_get_clean();
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return (new DOMXPath($dom))->query('//form[@action="options.php"]');
}
/** Serialize successful form controls in document order, including duplicate checkbox names. */
function form_submission($form, $checkboxes = array()) {
    $pairs = array();
    $xpath = new DOMXPath($form->ownerDocument);
    foreach ($xpath->query('.//input | .//select | .//textarea', $form) as $control) {
        $name = $control->getAttribute('name');
        if ('' === $name || $control->hasAttribute('disabled')) continue;
        if ('checkbox' === $control->getAttribute('type') && !($checkboxes[$name] ?? $control->hasAttribute('checked'))) continue;
        if ('select' === $control->tagName) {
            $options = $xpath->query('./option[@selected]', $control);
            $value = ($options->item(0) ?? $xpath->query('./option', $control)->item(0))->getAttribute('value');
        } else {
            $value = 'textarea' === $control->tagName ? $control->textContent : $control->getAttribute('value');
        }
        $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
    }
    parse_str(implode('&', $pairs), $parsed);
    return $parsed;
}

$key = Egentify_WooCommerce_Settings::OPTION_KEY;
$stored_settings = array_merge($settings->get_defaults(), array(
    'project_id' => 'local-project', 'signing_secret' => 'local-secret',
    'primary_color' => '#123456', 'auto_inject' => '1',
    'widget_position' => 'bottom-left', 'widget_offset_x' => '30', 'widget_offset_y' => '40',
    'widget_window_corner_radius' => '15', 'widget_starter_buttons' => array('Track order', 'Ask a question'),
    'widget_welcome_text' => 'Welcome!',
    'search_synonyms' => array(array('term' => 'shirt', 'aliases' => array('tee', 't-shirt'))),
    'widget_tooltip_enabled' => '0', 'widget_shine_enabled' => '0',
));
foreach (array('home', 'category', 'product', 'cart', 'fallback') as $page) {
    $stored_settings['widget_tooltip_' . $page . '_heading'] = $page . ' heading';
    $stored_settings['widget_tooltip_' . $page . '_message'] = $page . ' message';
}
$snapshot = $stored_settings;
$forms = settings_forms($admin);
check_same($forms->length, 2, 'both settings forms render');
$manual = form_submission($forms->item(0));
check_same(array_keys($manual[$key]), array('project_id', 'signing_secret'), 'manual form submits credentials only');
$manual[$key]['project_id'] = 'changed-project';
$saved = $settings->sanitize_settings($manual[$key]);
foreach ($snapshot as $field => $value) {
    check_same($saved[$field], 'project_id' === $field ? 'changed-project' : $value, 'manual save preserves ' . $field);
}
check_same($settings->sanitize_settings(array('signing_secret' => 'replacement-secret'))['signing_secret'], 'replacement-secret', 'manual save can replace secret');
check_same($settings->sanitize_settings(array('project_id' => ''))['project_id'], '', 'project ID can be explicitly cleared');
check_same($settings->sanitize_settings(null), $snapshot, 'malformed submission does not erase settings');

foreach (array(false, true) as $enabled) {
    $submission = form_submission($forms->item(1), array(
        $key . '[auto_inject]' => $enabled,
        $key . '[widget_tooltip_enabled]' => $enabled,
        $key . '[widget_shine_enabled]' => $enabled,
    ));
    $saved = $settings->sanitize_settings($submission[$key]);
    foreach (array('auto_inject', 'widget_tooltip_enabled', 'widget_shine_enabled') as $field) {
        check_same($submission[$key][$field] ?? null, $enabled ? '1' : '0', 'form submits explicit toggle ' . $field);
        check_same($saved[$field], $enabled ? '1' : '0', 'widget save toggles ' . $field);
    }
    foreach (array('project_id', 'signing_secret', 'search_synonyms', 'primary_color', 'widget_starter_buttons', 'widget_welcome_text', 'widget_offset_x', 'widget_offset_y', 'widget_position', 'widget_window_corner_radius') as $field) {
        check_same($saved[$field], $snapshot[$field], 'widget form preserves ' . $field);
    }
    foreach ($forms as $form) {
        $data = form_submission($form);
        check_same($data['option_page'], Egentify_WooCommerce_Settings::SETTINGS_GROUP, 'form retains correct option group');
        check_same($data['_wpnonce'], 'local-test-nonce', 'form retains settings_fields nonce');
    }
    $stored_settings = $saved;
    check_same($settings->sanitize_settings(array('project_id' => 'local-project', 'signing_secret' => '')), $saved, 'manual save retains enabled or disabled state');
}

$cleared = $settings->sanitize_settings(array(
    'primary_color' => '', 'widget_position' => '', 'widget_offset_x' => '', 'widget_offset_y' => '',
    'widget_window_corner_radius' => '', 'widget_starter_buttons' => array('', ''),
    'widget_welcome_text' => '', 'search_synonyms' => array(), 'widget_tooltip_home_heading' => '', 'widget_tooltip_home_message' => '',
));
foreach (array('primary_color', 'widget_position', 'widget_offset_x', 'widget_offset_y', 'widget_window_corner_radius', 'widget_welcome_text', 'widget_tooltip_home_heading', 'widget_tooltip_home_message') as $field) check_same($cleared[$field], '', 'can clear ' . $field);
check_same($cleared['widget_starter_buttons'], array(), 'can clear starter buttons');
check_same($cleared['search_synonyms'], array(), 'can clear synonyms');

foreach (array(array(), false, 'invalid-option') as $old_option) {
    $stored_settings = $old_option;
    $saved = $settings->sanitize_settings(array('project_id' => 'first-project', 'signing_secret' => 'first-secret'));
    check_same($saved['auto_inject'], '1', 'new or corrupt options retain injection default');
    check_same($saved['widget_tooltip_enabled'], '1', 'new or corrupt options retain tooltip default');
    check_same($saved['widget_shine_enabled'], '1', 'new or corrupt options retain shine default');
}

$admin->register_hooks();
foreach ($hooks['admin_init'] ?? array() as $callback) call_user_func($callback);
check_same($registered_settings[Egentify_WooCommerce_Settings::SETTINGS_GROUP][$key] ?? null, array($settings, 'sanitize_settings'), 'sanitizer stays registered');
$save_capability = apply_filters('option_page_capability_' . Egentify_WooCommerce_Settings::SETTINGS_GROUP, 'manage_options');
check_same($save_capability, 'manage_woocommerce', 'plugin save capability matches its settings page');
check_same(apply_filters('option_page_capability_unrelated_plugin', 'manage_options'), 'manage_options', 'unrelated option groups keep default permission');
foreach (array(
    'administrator' => array('manage_woocommerce' => true, 'manage_options' => true),
    'shop_manager' => array('manage_woocommerce' => true),
    'editor' => array('edit_posts' => true),
    'customer' => array('read' => true),
) as $role => $caps) {
    $capabilities = $caps;
    $allowed = in_array($role, array('administrator', 'shop_manager'), true);
    check_same(current_user_can($save_capability), $allowed, $role . ' save permission');
    check_same(current_user_can('manage_options'), 'administrator' === $role, $role . ' unrelated settings permission unchanged');
    ob_start();
    $page_allowed = true;
    try {
        $admin->render_settings_page();
    } catch (RuntimeException $error) {
        $page_allowed = false;
    } finally {
        ob_end_clean();
    }
    check_same($page_allowed, $allowed, $role . ' settings page permission');
}

$stored_settings = $snapshot;
define(Egentify_WooCommerce_Settings::SIGNING_SECRET_CONSTANT, 'constant-secret');
check_same($settings->get_signing_secret(), 'constant-secret', 'constant secret still takes precedence');
check_same($settings->sanitize_settings(array('signing_secret' => 'submitted-secret'))['signing_secret'], '', 'constant prevents storing submitted secret');
check_same($settings->sanitize_settings(array('primary_color' => '#abcdef'))['signing_secret'], '', 'constant prevents retaining database secret');

if ($failures) {
    fwrite(STDERR, count($failures) . ' of ' . $checks . " settings checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo $checks . " settings checks passed.\n";
