<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce_Admin {
    /** @var Egentify_WooCommerce_Settings */
    private $settings;

    /** @var Egentify_WooCommerce_Connect */
    private $connect;

    public function __construct(Egentify_WooCommerce_Settings $settings, Egentify_WooCommerce_Connect $connect) {
        $this->settings = $settings;
        $this->connect = $connect;
    }

    public function register_hooks() {
        add_action('admin_init', array($this->settings, 'register_setting_definition'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function register_admin_menu() {
        $icon_path = EGENTIFY_WOOCOMMERCE_PLUGIN_DIR . 'assets/egentify-sidebar-icon.svg';
        $icon = 'dashicons-admin-comments';
        if (file_exists($icon_path)) {
            $icon = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($icon_path));
        }

        add_menu_page(
            __('Egentify', 'egentify-for-woocommerce'),
            __('Egentify', 'egentify-for-woocommerce'),
            'manage_woocommerce',
            Egentify_WooCommerce_Settings::MENU_SLUG,
            array($this, 'render_settings_page'),
            $icon,
            58
        );
    }

    public function enqueue_admin_assets($hook_suffix) {
        if ('toplevel_page_' . Egentify_WooCommerce_Settings::MENU_SLUG !== $hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'egentify-woocommerce-admin',
            plugins_url('assets/admin.css', EGENTIFY_WOOCOMMERCE_PLUGIN_FILE),
            array(),
            EGENTIFY_WOOCOMMERCE_VERSION
        );

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script(
            'egentify-woocommerce-admin',
            plugins_url('assets/admin.js', EGENTIFY_WOOCOMMERCE_PLUGIN_FILE),
            array('jquery', 'wp-color-picker'),
            EGENTIFY_WOOCOMMERCE_VERSION,
            true
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'egentify-for-woocommerce'));
        }

        $settings = $this->settings->get_settings();
        $secret_source = $this->settings->get_signing_secret_source();
        $has_secret = $this->settings->has_signing_secret();
        $is_connected = $this->connect->is_connected();
        $connection = Egentify_WooCommerce_Connect::get_connection();
        $has_manual_config = !empty($settings['project_id']) && !empty($settings['signing_secret']);
        ?>
        <div class="wrap egentify-admin-wrap">
            <h1 class="screen-reader-text"><?php echo esc_html__('Egentify Settings', 'egentify-for-woocommerce'); ?></h1>
            <hr class="wp-header-end" />
            <div class="egentify-header">
                <div class="egentify-header__logo">
                    <img
                        class="egentify-header__icon"
                        src="<?php echo esc_url(plugins_url('assets/egentify-icon.svg', EGENTIFY_WOOCOMMERCE_PLUGIN_FILE)); ?>"
                        alt=""
                        width="40"
                        height="40"
                    />
                    <div>
                        <span class="egentify-header__title"><?php echo esc_html__('Egentify', 'egentify-for-woocommerce'); ?></span>
                        <span class="egentify-header__subtitle"><?php echo esc_html__('AI-Powered Commerce Support', 'egentify-for-woocommerce'); ?></span>
                    </div>
                </div>
                <?php if ($is_connected && $connection) : ?>
                    <span class="egentify-badge egentify-badge--connected">
                        <span class="egentify-badge__dot"></span>
                        <?php echo esc_html__('Connected', 'egentify-for-woocommerce'); ?>
                    </span>
                <?php else : ?>
                    <span class="egentify-badge egentify-badge--disconnected">
                        <?php echo esc_html__('Not connected', 'egentify-for-woocommerce'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($is_connected && $connection) : ?>
                <!-- Connected State -->
                <div class="egentify-card egentify-card--connected">
                    <div class="egentify-card__header">
                        <div class="egentify-card__icon egentify-card__icon--success">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div>
                            <h2 class="egentify-card__title"><?php echo esc_html__('Connected to Egentify', 'egentify-for-woocommerce'); ?></h2>
                            <p class="egentify-card__desc"><?php echo esc_html__('Your store is linked and the AI widget is active.', 'egentify-for-woocommerce'); ?></p>
                        </div>
                    </div>
                    <div class="egentify-details">
                        <div class="egentify-details__row">
                            <span class="egentify-details__label"><?php echo esc_html__('Project', 'egentify-for-woocommerce'); ?></span>
                            <span class="egentify-details__value egentify-details__value--strong"><?php echo esc_html($connection['project_name'] ?? ''); ?></span>
                        </div>
                        <div class="egentify-details__row">
                            <span class="egentify-details__label"><?php echo esc_html__('Connected', 'egentify-for-woocommerce'); ?></span>
                            <span class="egentify-details__value">
                                <?php
                                $connected_at = $connection['connected_at'] ?? '';
                                if ($connected_at) {
                                    $timestamp = strtotime($connected_at);
                                    if ($timestamp) {
                                        echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp));
                                    }
                                }
                                $connected_by = $connection['connected_by'] ?? 0;
                                if ($connected_by) {
                                    $user = get_userdata($connected_by);
                                    if ($user) {
                                        /* translators: %s: WordPress user display name */
                                        echo ' ' . esc_html(sprintf(__('by %s', 'egentify-for-woocommerce'), $user->display_name));
                                    }
                                }
                                ?>
                            </span>
                        </div>
                        <div class="egentify-details__row">
                            <span class="egentify-details__label"><?php echo esc_html__('Site', 'egentify-for-woocommerce'); ?></span>
                            <span class="egentify-details__value"><?php echo esc_html($connection['site_host'] ?? ''); ?></span>
                        </div>
                        <div class="egentify-details__row">
                            <span class="egentify-details__label"><?php echo esc_html__('Instance ID', 'egentify-for-woocommerce'); ?></span>
                            <code class="egentify-details__code"><?php echo esc_html($this->connect->get_instance_id()); ?></code>
                        </div>
                    </div>
                    <div class="egentify-card__actions">
                        <a href="<?php echo esc_url($this->settings->get_app_base_url() . '/dashboard'); ?>" class="egentify-btn egentify-btn--primary" target="_blank" rel="noopener">
                            <?php echo esc_html__('Open Dashboard', 'egentify-for-woocommerce'); ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </a>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=egentify_disconnect'), 'egentify_disconnect')); ?>" class="egentify-btn egentify-btn--danger-outline" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to disconnect from Egentify?', 'egentify-for-woocommerce')); ?>');"><?php echo esc_html__('Disconnect', 'egentify-for-woocommerce'); ?></a>
                    </div>
                </div>
            <?php else : ?>
                <!-- Disconnected State -->
                <div class="egentify-card egentify-card--cta">
                    <div class="egentify-card__header">
                        <div class="egentify-card__icon egentify-card__icon--info">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        </div>
                        <div>
                            <?php if ($has_manual_config) : ?>
                                <h2 class="egentify-card__title"><?php echo esc_html__('Using Manual Configuration', 'egentify-for-woocommerce'); ?></h2>
                                <p class="egentify-card__desc"><?php echo esc_html__('The widget is active using a manually configured project ID and signing secret. Connect for automatic management.', 'egentify-for-woocommerce'); ?></p>
                            <?php else : ?>
                                <h2 class="egentify-card__title"><?php echo esc_html__('Connect to Egentify', 'egentify-for-woocommerce'); ?></h2>
                                <p class="egentify-card__desc"><?php echo esc_html__('Link this WooCommerce store to your Egentify project to enable the AI-powered support widget.', 'egentify-for-woocommerce'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="egentify-card__actions">
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=egentify_start_connect'), 'egentify_start_connect')); ?>" class="egentify-btn egentify-btn--primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            <?php echo esc_html__('Connect to Egentify', 'egentify-for-woocommerce'); ?>
                        </a>
                    </div>
                </div>

                <?php if (!$has_secret && !$has_manual_config) : ?>
                    <div class="egentify-notice egentify-notice--error">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <p><?php echo esc_html__('No signing secret is configured. Connect to Egentify above, or expand Advanced settings below to configure manually.', 'egentify-for-woocommerce'); ?></p>
                    </div>
                <?php elseif ('constant' === $secret_source) : ?>
                    <div class="egentify-notice egentify-notice--info">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <p><?php echo esc_html__('The signing secret is being loaded from a WordPress constant.', 'egentify-for-woocommerce'); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Advanced: Manual Configuration (collapsible) -->
                <div class="egentify-collapsible" style="margin-top: 16px;">
                    <button type="button" class="egentify-collapsible__toggle" id="egentify-toggle-manual-config">
                        <svg class="egentify-collapsible__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        <?php echo esc_html__('Advanced: Manual Configuration', 'egentify-for-woocommerce'); ?>
                    </button>
                </div>
                <div id="egentify-manual-config" class="egentify-card" style="display: none; margin-top: 8px;">
                    <form method="post" action="options.php">
                        <?php settings_fields(Egentify_WooCommerce_Settings::SETTINGS_GROUP); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="egentify-project-id"><?php echo esc_html__('Project ID', 'egentify-for-woocommerce'); ?></label></th>
                                <td><input id="egentify-project-id" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[project_id]" type="text" class="regular-text" value="<?php echo esc_attr($settings['project_id']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="egentify-signing-secret"><?php echo esc_html__('Signing Secret', 'egentify-for-woocommerce'); ?></label></th>
                                <td>
                                    <?php if ('constant' === $secret_source) : ?>
                                        <input id="egentify-signing-secret" type="password" class="regular-text code" value="" placeholder="Configured via constant" autocomplete="off" disabled>
                                        <p class="description"><?php echo esc_html__('The secret is loaded from EGENTIFY_WOOCOMMERCE_SIGNING_SECRET constant.', 'egentify-for-woocommerce'); ?></p>
                                    <?php else : ?>
                                        <input id="egentify-signing-secret" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[signing_secret]" type="password" class="regular-text code" value="" placeholder="Paste your shared signing secret" autocomplete="new-password">
                                        <p class="description"><?php echo esc_html__('Leave blank to keep the current stored secret.', 'egentify-for-woocommerce'); ?></p>
                                        <p class="description"><?php echo esc_html($this->settings->has_stored_signing_secret() ? 'A stored signing secret is configured.' : 'No stored signing secret is configured yet.'); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(__('Save Manual Settings', 'egentify-for-woocommerce')); ?>
                    </form>
                </div>
                <script>
                    (function() {
                        var toggle = document.getElementById('egentify-toggle-manual-config');
                        var panel = document.getElementById('egentify-manual-config');
                        if (toggle && panel) {
                            toggle.addEventListener('click', function() {
                                var hidden = panel.style.display === 'none';
                                panel.style.display = hidden ? '' : 'none';
                                toggle.classList.toggle('egentify-collapsible__toggle--open', hidden);
                            });
                        }
                    }());
                </script>
            <?php endif; ?>

            <!-- Common settings (shown in both connected and disconnected states) -->
            <div class="egentify-card" style="margin-top: 24px;">
                <h2 class="egentify-card__section-title"><?php echo esc_html__('Widget Settings', 'egentify-for-woocommerce'); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields(Egentify_WooCommerce_Settings::SETTINGS_GROUP); ?>
                    <input type="hidden" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[project_id]" value="<?php echo esc_attr($settings['project_id']); ?>">
                    <input type="hidden" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[signing_secret]" value="">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="egentify-primary-color"><?php echo esc_html__('Primary Color', 'egentify-for-woocommerce'); ?></label></th>
                            <td><input id="egentify-primary-color" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[primary_color]" type="text" class="egentify-color-picker" value="<?php echo esc_attr($this->settings->get_primary_color()); ?>" data-default-color="#2563eb"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="egentify-widget-position"><?php echo esc_html__('Widget Position', 'egentify-for-woocommerce'); ?></label></th>
                            <td>
                                <select id="egentify-widget-position" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[widget_position]">
                                    <option value="" <?php selected($settings['widget_position'] ?? '', ''); ?>><?php echo esc_html__('Default (bottom right)', 'egentify-for-woocommerce'); ?></option>
                                    <option value="bottom-right" <?php selected($settings['widget_position'] ?? '', 'bottom-right'); ?>><?php echo esc_html__('Bottom right', 'egentify-for-woocommerce'); ?></option>
                                    <option value="bottom-left" <?php selected($settings['widget_position'] ?? '', 'bottom-left'); ?>><?php echo esc_html__('Bottom left', 'egentify-for-woocommerce'); ?></option>
                                </select>
                                <p class="description"><?php echo esc_html__('Where the chat launcher appears on the storefront. Mobile is always full-screen.', 'egentify-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="egentify-widget-offset-x"><?php echo esc_html__('Side Offset (px)', 'egentify-for-woocommerce'); ?></label></th>
                            <td>
                                <input id="egentify-widget-offset-x" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[widget_offset_x]" type="number" min="0" max="200" step="1" value="<?php echo esc_attr($settings['widget_offset_x'] ?? ''); ?>" placeholder="24">
                                <p class="description"><?php echo esc_html__('Distance from the left or right edge. 0–200.', 'egentify-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="egentify-widget-offset-y"><?php echo esc_html__('Bottom Offset (px)', 'egentify-for-woocommerce'); ?></label></th>
                            <td>
                                <input id="egentify-widget-offset-y" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[widget_offset_y]" type="number" min="0" max="200" step="1" value="<?php echo esc_attr($settings['widget_offset_y'] ?? ''); ?>" placeholder="24">
                                <p class="description"><?php echo esc_html__('Distance from the bottom edge. 0–200.', 'egentify-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="egentify-widget-window-radius"><?php echo esc_html__('Chat Window Radius (px)', 'egentify-for-woocommerce'); ?></label></th>
                            <td>
                                <input id="egentify-widget-window-radius" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[widget_window_corner_radius]" type="number" min="0" max="30" step="1" value="<?php echo esc_attr($settings['widget_window_corner_radius'] ?? ''); ?>" placeholder="16">
                                <p class="description"><?php echo esc_html__('Corner rounding on the chat window (0 = sharp, 30 = very rounded). Launcher button stays circular.', 'egentify-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="egentify-widget-welcome-text"><?php echo esc_html__('Welcome Message', 'egentify-for-woocommerce'); ?></label></th>
                            <td>
                                <textarea id="egentify-widget-welcome-text" name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[widget_welcome_text]" rows="4" maxlength="300" class="large-text"><?php echo esc_textarea($settings['widget_welcome_text'] ?? ''); ?></textarea>
                                <p class="description"><?php echo esc_html__('Shown when a customer first opens the chat. Use {{agent_name}} to insert the assistant\'s name. Leave blank for the default. Max 300 characters.', 'egentify-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Starter Buttons', 'egentify-for-woocommerce'); ?></th>
                            <td>
                                <?php
                                $starter_buttons = isset($settings['widget_starter_buttons']) && is_array($settings['widget_starter_buttons'])
                                    ? $settings['widget_starter_buttons']
                                    : array();
                                // Pad to 4 slots for editing; empty slots are dropped server-side.
                                $starter_buttons = array_pad($starter_buttons, 4, '');
                                $option_key = Egentify_WooCommerce_Settings::OPTION_KEY;
                                foreach ($starter_buttons as $idx => $btn) :
                                    /* translators: %d: starter button slot number (1-4) */
                                    $placeholder = sprintf(__('Button %d (e.g. 📦 Track my order)', 'egentify-for-woocommerce'), $idx + 1);
                                    ?>
                                    <input type="text" name="<?php echo esc_attr($option_key); ?>[widget_starter_buttons][]" value="<?php echo esc_attr($btn); ?>" maxlength="40" class="regular-text" placeholder="<?php echo esc_attr($placeholder); ?>" style="margin-bottom: 4px; display: block;">
                                <?php endforeach; ?>
                                <p class="description"><?php echo esc_html__('Up to 4 quick-reply buttons shown when a customer first opens an empty chat. 40 characters each. Leave all slots blank to use the default buttons.', 'egentify-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Auto Inject Widget', 'egentify-for-woocommerce'); ?></th>
                            <td><label><input name="<?php echo esc_attr(Egentify_WooCommerce_Settings::OPTION_KEY); ?>[auto_inject]" type="checkbox" value="1" <?php checked($settings['auto_inject'], '1'); ?>> <?php echo esc_html__('Render the widget automatically in wp_footer.', 'egentify-for-woocommerce'); ?></label></td>
                        </tr>
                </table>
                    <?php submit_button(); ?>
                </form>
            </div>

        </div>
        <?php
    }

}
