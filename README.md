# Egentify for WooCommerce

AI-powered customer support widget for WooCommerce. Connects your store to [Egentify](https://egentify.com) for chat, voice, and ticketing.

📦 **Available on WordPress.org:** [AI Chatbot & Helpdesk Agent for WooCommerce](https://wordpress.org/plugins/egentify-for-woocommerce/)

## Install

The easiest way is to install directly from the [WordPress.org plugin directory](https://wordpress.org/plugins/egentify-for-woocommerce/). To install the GitHub build manually:

1. Download the latest `egentify-for-woocommerce-vX.Y.Z.zip` from the [Releases page](https://github.com/tinyboxio/egentify-for-woocommerce/releases/latest).
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip, install, and activate.
4. A new **Egentify** menu item appears in the WordPress sidebar.

## Connect to Egentify

1. Open **Egentify** in the WP admin.
2. Click **Connect to Egentify**.
3. Authorize the connection in the popup. The plugin generates WooCommerce API keys automatically and stores the signing secret.
4. Once connected, the widget is live on your storefront.

That's it for most stores.

## Configure the widget

Open **Egentify** in WP admin to customize:

- **Widget appearance**: primary color, launcher position (bottom-right / bottom-left), side and bottom offsets, chat window corner radius
- **Welcome message**: shown the first time a customer opens the chat. Use `{{agent_name}}` to insert the assistant's name.
- **Starter buttons**: quick-reply buttons shown on first open. Up to 4 buttons, 40 characters each.
- **Auto-inject**: render the widget in `wp_footer` automatically (on by default). Disable if you want to use the `[egentify_chat_widget]` shortcode instead.

Settings save to WordPress and apply on the next page load. Hard refresh to see changes immediately.

## Manual shortcode

If you'd rather place the widget yourself instead of auto-inject, drop this into any page or theme template:

```
[egentify_chat_widget]
```

## REST endpoints

The plugin exposes endpoints that the hosted Egentify backend calls when processing customer messages. You don't need to interact with these directly — they're documented here for reference.

### Widget session

```
GET /wp-json/egentify/v1/widget-session
```

Issues a short-lived signed token for the current logged-in customer (or guest if logged out). Used by the widget to authenticate against the Egentify backend.

### Product search

```
GET /wp-json/egentify/v1/search/products?q=blue+hoodie
```

WooCommerce-aware product search. Outranks the default WP/Woo search on:

- SKU + title exact/prefix matches
- Category, tag, attribute term matches
- Normalized compound tokens (`delta-8`, `usb-c`, `2 oz`)
- Singular/plural variants (`pen`/`pens`, `gummy`/`gummies`)
- In-stock, on-sale, featured, popular boosts

Optional params: `limit` (default 8, max 20), `category`, `in_stock=1`, `on_sale=1`, `min_price`, `max_price`, `debug=1`.

### Content search

```
GET /wp-json/egentify/v1/search/content?q=shipping+policy
```

Searches WordPress pages and posts (for FAQ / policy lookups). Same normalization as product search.

Optional params: `limit` (default 8, max 20), `type` (`page` / `post`), `debug=1`.

## Advanced: manual configuration

The Connect flow handles most setups. If you self-host the Egentify backend or need to configure manually:

1. Open **Egentify** in WP admin.
2. Expand **Advanced: Manual Configuration**.
3. Enter your Project ID and Signing Secret.
4. Save.

The signing secret must match the value on your Egentify project. The plugin doesn't display the saved secret — leaving the field blank keeps the existing value.

## Advanced: signing secret via constant

To avoid storing the signing secret in the database, define it in `wp-config.php`:

```php
define('EGENTIFY_WOOCOMMERCE_SIGNING_SECRET', 'your-shared-secret');
```

If the constant is set, the plugin uses it and ignores any value in the database.

## Advanced: alternative Egentify host

For staging or self-hosted Egentify deployments, override the base URL with a filter:

```php
add_filter('egentify_woocommerce_app_base_url', function () {
    return 'https://staging.egentify.com';
});
```

## Requirements

- WordPress 6.4+
- WooCommerce 8.0+
- PHP 7.4+

## License

GPL-2.0-or-later
