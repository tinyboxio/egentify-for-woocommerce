<?php
/**
 * Plugin Name: Egentify for WooCommerce
 * Plugin URI: https://github.com/tinyboxio/egentify-for-woocommerce
 * Description: AI-powered customer support widget for WooCommerce. Connects your store to Egentify for chat, voice, and ticketing.
 * Version: 1.0.2
 * Author: Egentify
 * Author URI: https://egentify.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Requires at least: 6.4
 * Tested up to: 7.0
 * WC requires at least: 8.0
 * WC tested up to: 10.8
 * Text Domain: egentify-for-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EGENTIFY_WOOCOMMERCE_VERSION', '1.0.2');
define('EGENTIFY_WOOCOMMERCE_PLUGIN_FILE', __FILE__);
define('EGENTIFY_WOOCOMMERCE_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce-connect.php';
require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce-settings.php';
require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce-admin.php';
require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce-content-search.php';
require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce-product-search.php';
require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce-rest-controller.php';
require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce-widget.php';
require_once EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-egentify-woocommerce.php';

function egentify_woocommerce() {
    static $plugin = null;

    if (null === $plugin) {
        $plugin = new Egentify_WooCommerce();
    }

    return $plugin;
}

egentify_woocommerce()->run();

register_deactivation_hook(__FILE__, array('Egentify_WooCommerce_Connect', 'deactivate'));
