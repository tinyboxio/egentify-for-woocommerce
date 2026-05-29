<?php
/**
 * Uninstall cleanup for Egentify for WooCommerce.
 *
 * Runs only when the user deletes the plugin from the Plugins screen.
 * Removes stored options, transients, the weekly heartbeat cron event, and
 * the auto-generated read_write WooCommerce REST API keys (which would
 * otherwise remain in the database if the plugin is deleted without first
 * clicking Disconnect).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Option keys — kept in sync with the plugin's class constants.
$egentify_options = array(
    'egentify_woocommerce_connection',  // Connect::CONNECT_OPTION_KEY
    'egentify_woocommerce_instance_id', // Connect::INSTANCE_ID_OPTION_KEY
    'egentify_woocommerce_settings',    // Settings::OPTION_KEY
);

foreach ($egentify_options as $egentify_option) {
    delete_option($egentify_option);
}

// Unschedule the weekly heartbeat cron, if still scheduled.
$egentify_heartbeat_hook = 'egentify_weekly_heartbeat'; // Connect::HEARTBEAT_CRON_HOOK
$egentify_timestamp = wp_next_scheduled($egentify_heartbeat_hook);
if ($egentify_timestamp) {
    wp_unschedule_event($egentify_timestamp, $egentify_heartbeat_hook);
}
wp_clear_scheduled_hook($egentify_heartbeat_hook);

global $wpdb;

// Remove auto-generated WooCommerce REST API keys (read_write) created on connect.
$egentify_api_keys_table = $wpdb->prefix . 'woocommerce_api_keys';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time uninstall cleanup; table existence checked first.
$egentify_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $egentify_api_keys_table));
if ($egentify_table_exists === $egentify_api_keys_table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup.
    $wpdb->delete(
        $egentify_api_keys_table,
        array('description' => 'Egentify (auto-generated)'),
        array('%s')
    );
}

// Remove any leftover user-scoped transients (connect-pending state, admin notices).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup of plugin-prefixed transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_egentify_connect_pending_%'
        OR option_name LIKE '_transient_timeout_egentify_connect_pending_%'
        OR option_name LIKE '_transient_egentify_admin_notice_%'
        OR option_name LIKE '_transient_timeout_egentify_admin_notice_%'"
);
