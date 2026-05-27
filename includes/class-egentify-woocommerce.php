<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce {
    /** @var Egentify_WooCommerce_Settings */
    private $settings;

    /** @var Egentify_WooCommerce_Connect */
    private $connect;

    /** @var Egentify_WooCommerce_Admin */
    private $admin;

    /** @var Egentify_WooCommerce_REST_Controller */
    private $rest_controller;

    /** @var Egentify_WooCommerce_Product_Search */
    private $product_search;

    /** @var Egentify_WooCommerce_Content_Search */
    private $content_search;

    /** @var Egentify_WooCommerce_Widget */
    private $widget;

    public function __construct() {
        $this->settings = new Egentify_WooCommerce_Settings();
        $this->connect = new Egentify_WooCommerce_Connect($this->settings);
        $this->content_search = new Egentify_WooCommerce_Content_Search($this->settings);
        $this->product_search = new Egentify_WooCommerce_Product_Search($this->settings);
        $this->admin = new Egentify_WooCommerce_Admin($this->settings, $this->connect);
        $this->rest_controller = new Egentify_WooCommerce_REST_Controller($this->settings, $this->product_search, $this->content_search);
        $this->widget = new Egentify_WooCommerce_Widget($this->settings);
    }

    public function run() {
        $this->connect->register_hooks();
        $this->admin->register_hooks();
        $this->rest_controller->register_hooks();
        $this->widget->register_hooks();
    }
}
