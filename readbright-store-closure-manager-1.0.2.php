<?php
/**
 * Plugin Name: ReadBright Store Closure Manager
 * Description: Disable WooCommerce purchasing during custom holiday closures and weekly Friday-sundown through Saturday-twilight closures, with an admin panel and sitewide banner.
 * Version: 1.0.2
 * Author: OpenAI
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: rb-store-closure-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('RB_Store_Closure_Manager')) {
    final class RB_Store_Closure_Manager {
        const OPTION_KEY = 'rb_scm_settings';
        const MENU_SLUG = 'rb-store-closure-manager';

        private static $instance = null;

        public static function instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

            add_filter('woocommerce_is_purchasable', [$this, 'filter_is_purchasable'], 10, 2);
            add_filter('woocommerce_variation_is_purchasable', [$this, 'filter_is_purchasable'], 10, 2);
            add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 5);

            add_action('wp', [$this, 'maybe_remove_add_to_cart_buttons']);
            add_action('wp_head', [$this, 'output_banner_styles']);
            add_action('wp_body_open', [$this, 'output_banner']);
            add_action('wp_footer', [$this, 'output_banner_fallback']);
            add_action('wp_footer', [$this, 'output_frontend_lockdown_script'], 99);
        }

        public function defaults() {
            return [
                'enabled' => 1,
                'banner_enabled' => 1,
                'banner_position' => 'top-fixed',
                'banner_bg' => '#1f2937',
                'banner_text_color' => '#ffffff',
                'banner_text_holiday' => 'Our store is currently closed for the holiday.',
                'banner_text_shabbat' => 'Our store is currently closed for Shabbat.',
                'closed_button_text' => 'Store Closed',
                'show_notice_on_product' => 1,
                'show_notice_on_shop' => 1,
                'force_closed' => 0,
                'holiday_ranges' => [],
                'weekly_enabled' => 1,
                'latitude' => '40.0959',
                'longitude' => '-74.2221',
                'friday_offset' => 0,
                'saturday_offset' => 45,
            ];
        }

        public function get_settings() {
            $saved = get_option(self::OPTION_KEY, []);
            if (!is_array($saved)) {
                $saved = [];
            }

            $settings = wp_parse_args($saved, $this->defaults());
            $settings['holiday_ranges'] = $this->normalize_holiday_ranges($settings['holiday_ranges'] ?? []);
            $settings['enabled'] = !empty($settings['enabled']) ? 1 : 0;
            $settings['banner_enabled'] = !empty($settings['banner_enabled']) ? 1 : 0;
            $settings['weekly_enabled'] = !empty($settings['weekly_enabled']) ? 1 : 0;
            $settings['show_notice_on_product'] = !empty($settings['show_notice_on_product']) ? 1 : 0;
            $settings['show_notice_on_shop'] = !empty($settings['show_notice_on_shop']) ? 1 : 0;
            $settings['force_closed'] = !empty($settings['force_closed']) ? 1 : 0;

            return $settings;
        }

        public function register_settings() {
            register_setting(
                'rb_scm_settings_group',
                self::OPTION_KEY,
                [
                    'type' => 'array',
                    'sanitize_callback' => [$this, 'sanitize_settings'],
                    'default' => $this->defaults(),
                ]
            );
        }

        public function register_admin_menu() {
            add_submenu_page(
                'woocommerce',
                __('Store Closure', 'rb-store-closure-manager'),
                __('Store Closure', 'rb-store-closure-manager'),
                'manage_woocommerce',
                self::MENU_SLUG,
                [$this, 'render_admin_page']
            );
        }

        public function enqueue_admin_assets($hook) {
            if ('woocommerce_page_' . self::MENU_SLUG !== $hook) {
                return;
            }

            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');

            $inline_js = <<<JS
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.rb-color-field').forEach(function (field) {
                    if (window.jQuery && window.jQuery.fn.wpColorPicker) {
                        window.jQuery(field).wpColorPicker();
                    }
                });

                var tableBody = document.querySelector('#rb-scm-holiday-table tbody');
                var addButton = document.querySelector('#rb-scm-add-row');
                var jsonField = document.querySelector('#rb-scm-holiday-ranges-json');
                var form = document.querySelector('form[action="options.php"]');

                function bindRemoveButtons(context) {
                    context.querySelectorAll('.rb-scm-remove-row').forEach(function (button) {
                        button.onclick = function () {
                            var row = button.closest('tr');
                            if (row) {
                                row.remove();
                                syncJson();
                            }
                        };
                    });
                }

                function syncJson() {
                    if (!tableBody || !jsonField) return;

                    var rows = [];
                    tableBody.querySelectorAll('tr').forEach(function (row) {
                        var label = row.querySelector('[data-field="label"]');
                        var start = row.querySelector('[data-field="start"]');
                        var end = row.querySelector('[data-field="end"]');

                        var item = {
                            label: label ? label.value : '',
                            start: start ? start.value : '',
                            end: end ? end.value : ''
                        };

                        if (item.start && item.end) {
                            rows.push(item);
                        }
                    });

                    jsonField.value = JSON.stringify(rows);
                }

                function bindRowInputs(context) {
                    context.querySelectorAll('input').forEach(function (input) {
                        input.addEventListener('change', syncJson);
                        input.addEventListener('input', syncJson);
                    });
                }

                if (tableBody) {
                    bindRemoveButtons(tableBody);
                    bindRowInputs(tableBody);
                }

                if (addButton && tableBody) {
                    addButton.addEventListener('click', function () {
                        var row = document.createElement('tr');
                        row.innerHTML = `
                            <td><input type="text" class="regular-text" data-field="label" value="Holiday"></td>
                            <td><input type="datetime-local" data-field="start" value=""></td>
                            <td><input type="datetime-local" data-field="end" value=""></td>
                            <td><button type="button" class="button button-secondary rb-scm-remove-row">Remove</button></td>
                        `;
                        tableBody.appendChild(row);
                        bindRemoveButtons(row);
                        bindRowInputs(row);
                        syncJson();
                    });
                }

                if (form) {
                    form.addEventListener('submit', syncJson);
                }

                syncJson();
            });
            JS;

            wp_register_script('rb-scm-admin-inline', '', [], '1.0.2', true);
            wp_enqueue_script('rb-scm-admin-inline');
            wp_add_inline_script('rb-scm-admin-inline', $inline_js);
        }

        public function sanitize_settings($input) {
            $defaults = $this->defaults();
            $output = [];

            $output['enabled'] = !empty($input['enabled']) ? 1 : 0;
            $output['banner_enabled'] = !empty($input['banner_enabled']) ? 1 : 0;
            $output['banner_position'] = in_array($input['banner_position'] ?? '', ['top-fixed', 'top-static'], true) ? $input['banner_position'] : $defaults['banner_position'];
            $output['banner_bg'] = sanitize_hex_color($input['banner_bg'] ?? '') ?: $defaults['banner_bg'];
            $output['banner_text_color'] = sanitize_hex_color($input['banner_text_color'] ?? '') ?: $defaults['banner_text_color'];
            $output['banner_text_holiday'] = sanitize_text_field($input['banner_text_holiday'] ?? $defaults['banner_text_holiday']);
            $output['banner_text_shabbat'] = sanitize_text_field($input['banner_text_shabbat'] ?? $defaults['banner_text_shabbat']);
            $output['closed_button_text'] = sanitize_text_field($input['closed_button_text'] ?? $defaults['closed_button_text']);
            $output['show_notice_on_product'] = !empty($input['show_notice_on_product']) ? 1 : 0;
            $output['show_notice_on_shop'] = !empty($input['show_notice_on_shop']) ? 1 : 0;
            $output['force_closed'] = !empty($input['force_closed']) ? 1 : 0;

            $output['weekly_enabled'] = !empty($input['weekly_enabled']) ? 1 : 0;
            $output['latitude'] = is_numeric($input['latitude'] ?? null) ? (string) $input['latitude'] : $defaults['latitude'];
            $output['longitude'] = is_numeric($input['longitude'] ?? null) ? (string) $input['longitude'] : $defaults['longitude'];
            $output['friday_offset'] = is_numeric($input['friday_offset'] ?? null) ? (int) $input['friday_offset'] : (int) $defaults['friday_offset'];
            $output['saturday_offset'] = is_numeric($input['saturday_offset'] ?? null) ? (int) $input['saturday_offset'] : (int) $defaults['saturday_offset'];

            $json = isset($input['holiday_ranges_json']) ? wp_unslash($input['holiday_ranges_json']) : '[]';
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $output['holiday_ranges'] = $this->normalize_holiday_ranges($decoded);

            return $output;
        }

        private function normalize_holiday_ranges($ranges) {
            $normalized = [];

            if (!is_array($ranges)) {
                return $normalized;
            }

            foreach ($ranges as $range) {
                if (!is_array($range)) {
                    continue;
                }

                $label = isset($range['label']) ? sanitize_text_field(wp_unslash($range['label'])) : 'Holiday';
                $start = isset($range['start']) ? trim((string) wp_unslash($range['start'])) : '';
                $end = isset($range['end']) ? trim((string) wp_unslash($range['end'])) : '';

                if ($start === '' || $end === '') {
                    continue;
                }

                $start_ts = $this->parse_site_datetime_to_timestamp($start);
                $end_ts = $this->parse_site_datetime_to_timestamp($end);

                if ($start_ts === false || $end_ts === false || $end_ts < $start_ts) {
                    continue;
                }

                $normalized[] = [
                    'label' => $label ?: 'Holiday',
                    'start' => wp_date('Y-m-d H:i:s', (int) $start_ts, wp_timezone()),
                    'end' => wp_date('Y-m-d H:i:s', (int) $end_ts, wp_timezone()),
                ];
            }

            return array_values($normalized);
        }

        private function parse_site_datetime_to_timestamp($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return false;
            }

            $timezone = wp_timezone();
            $value = str_replace('T', ' ', $value);
            $formats = ['Y-m-d H:i:s', 'Y-m-d H:i'];

            foreach ($formats as $format) {
                $dt = \DateTime::createFromFormat($format, $value, $timezone);
                if ($dt instanceof \DateTime) {
                    return $dt->getTimestamp();
                }
            }

            try {
                return (new \DateTime($value, $timezone))->getTimestamp();
            } catch (\Exception $e) {
                return false;
            }
        }

        private function timestamp_to_datetime_local($timestamp) {
            return wp_date('Y-m-d\TH:i', (int) $timestamp, wp_timezone());
        }

        private function storage_to_datetime_local($value) {
            $timestamp = $this->parse_site_datetime_to_timestamp($value);
            if ($timestamp === false) {
                return '';
            }

            return $this->timestamp_to_datetime_local($timestamp);
        }

        private function get_now_timestamp() {
            return current_datetime()->getTimestamp();
        }

        public function get_closure_state() {
            $settings = $this->get_settings();

            if (empty($settings['enabled'])) {
                return [
                    'closed' => false,
                    'type' => '',
                    'message' => '',
                    'label' => '',
                ];
            }

            if (!empty($settings['force_closed'])) {
                return [
                    'closed' => true,
                    'type' => 'manual',
                    'message' => !empty($settings['banner_text_holiday']) ? $settings['banner_text_holiday'] : __('Our store is currently closed.', 'rb-store-closure-manager'),
                    'label' => 'Manual',
                ];
            }

            $holiday_state = $this->get_holiday_state($settings);
            if ($holiday_state['closed']) {
                return $holiday_state;
            }

            $weekly_state = $this->get_weekly_state($settings);
            if ($weekly_state['closed']) {
                return $weekly_state;
            }

            return [
                'closed' => false,
                'type' => '',
                'message' => '',
                'label' => '',
            ];
        }

        private function get_holiday_state($settings) {
            $now = $this->get_now_timestamp();

            foreach ($settings['holiday_ranges'] as $range) {
                $start = $this->parse_site_datetime_to_timestamp($range['start']);
                $end = $this->parse_site_datetime_to_timestamp($range['end']);

                if ($start && $end && $now >= $start && $now <= $end) {
                    return [
                        'closed' => true,
                        'type' => 'holiday',
                        'message' => !empty($settings['banner_text_holiday']) ? $settings['banner_text_holiday'] : __('Our store is currently closed for the holiday.', 'rb-store-closure-manager'),
                        'label' => $range['label'] ?? 'Holiday',
                    ];
                }
            }

            return [
                'closed' => false,
                'type' => '',
                'message' => '',
                'label' => '',
            ];
        }

        private function get_weekly_state($settings) {
            if (empty($settings['weekly_enabled'])) {
                return [
                    'closed' => false,
                    'type' => '',
                    'message' => '',
                    'label' => '',
                ];
            }

            $now = current_datetime();
            $tz = wp_timezone();
            $now_ts = $now->getTimestamp();
            $weekday = (int) $now->format('w');
            $lat = (float) $settings['latitude'];
            $lng = (float) $settings['longitude'];
            $friday_offset = (int) $settings['friday_offset'];
            $saturday_offset = (int) $settings['saturday_offset'];

            if (5 === $weekday) {
                $sun = $this->get_sun_times_for_date($now->format('Y-m-d'), $lat, $lng, $tz);
                if (!empty($sun['sunset'])) {
                    $start_ts = $sun['sunset'] + ($friday_offset * 60);
                    if ($now_ts >= $start_ts) {
                        return [
                            'closed' => true,
                            'type' => 'shabbat',
                            'message' => !empty($settings['banner_text_shabbat']) ? $settings['banner_text_shabbat'] : __('Our store is currently closed for Shabbat.', 'rb-store-closure-manager'),
                            'label' => 'Shabbat',
                        ];
                    }
                }
            }

            if (6 === $weekday) {
                $sun = $this->get_sun_times_for_date($now->format('Y-m-d'), $lat, $lng, $tz);
                if (!empty($sun['sunset'])) {
                    $end_ts = $sun['sunset'] + ($saturday_offset * 60);
                    if ($now_ts <= $end_ts) {
                        return [
                            'closed' => true,
                            'type' => 'shabbat',
                            'message' => !empty($settings['banner_text_shabbat']) ? $settings['banner_text_shabbat'] : __('Our store is currently closed for Shabbat.', 'rb-store-closure-manager'),
                            'label' => 'Shabbat',
                        ];
                    }
                }
            }

            return [
                'closed' => false,
                'type' => '',
                'message' => '',
                'label' => '',
            ];
        }

        private function get_sun_times_for_date($ymd, $lat, $lng, $timezone) {
            try {
                $midday = new \DateTime($ymd . ' 12:00:00', $timezone);
            } catch (\Exception $e) {
                return [];
            }

            $info = date_sun_info($midday->getTimestamp(), $lat, $lng);

            return [
                'sunrise' => !empty($info['sunrise']) ? (int) $info['sunrise'] : null,
                'sunset' => !empty($info['sunset']) ? (int) $info['sunset'] : null,
            ];
        }

        public function filter_is_purchasable($purchasable, $product) {
            $state = $this->get_closure_state();
            if (!empty($state['closed']) && !is_product()) {
                return false;
            }
            return $purchasable;
        }

        public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
            $state = $this->get_closure_state();
            if (!empty($state['closed'])) {
                wc_add_notice($state['message'], 'error');
                return false;
            }
            return $passed;
        }

        public function maybe_remove_add_to_cart_buttons() {
            $state = $this->get_closure_state();
            if (empty($state['closed'])) {
                return;
            }

            remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);

            $settings = $this->get_settings();
            if (!empty($settings['show_notice_on_shop'])) {
                add_action('woocommerce_after_shop_loop_item', [$this, 'render_loop_closed_notice'], 10);
            }
            if (!empty($settings['show_notice_on_product'])) {
                add_action('woocommerce_single_product_summary', [$this, 'render_single_closed_notice'], 30);
            }
        }

        public function render_loop_closed_notice() {
            $settings = $this->get_settings();
            $text = !empty($settings['closed_button_text']) ? $settings['closed_button_text'] : __('Store Closed', 'rb-store-closure-manager');
            echo '<span class="button disabled wc-forward rb-scm-closed-button" aria-disabled="true">' . esc_html($text) . '</span>';
        }

        public function render_single_closed_notice() {
            $state = $this->get_closure_state();
            echo '<p class="stock out-of-stock rb-scm-closed-message">' . esc_html($state['message']) . '</p>';
        }

        public function output_banner_styles() {
            $settings = $this->get_settings();
            if (empty($settings['banner_enabled'])) {
                return;
            }

            $position_css = 'position:fixed;top:0;left:0;width:100%;z-index:99999;';
            if ('top-static' === $settings['banner_position']) {
                $position_css = 'position:relative;width:100%;z-index:99999;';
            }

            echo '<style id="rb-scm-styles">';
            echo '.rb-scm-banner{' . esc_html($position_css) . 'background:' . esc_html($settings['banner_bg']) . ';color:' . esc_html($settings['banner_text_color']) . ';padding:12px 18px;text-align:center;font-size:14px;line-height:1.4;font-weight:600;}';
            echo '.admin-bar .rb-scm-banner{top:32px;}';
            echo '@media (max-width:782px){.admin-bar .rb-scm-banner{top:46px;}}';
            echo '.rb-scm-closed-button.disabled,.rb-scm-closed-button[disabled]{opacity:1;cursor:not-allowed;pointer-events:none;}';
            echo '.rb-scm-single-closed-wrap{display:flex;flex-direction:column;gap:10px;}';
            echo '.rb-scm-closed-message{margin:0;}';
            echo '.rb-scm-force-hide{display:none !important;}';
            echo '.rb-scm-disabled-link{opacity:.65 !important;cursor:not-allowed !important;}';
            echo '</style>';
        }

        public function output_banner() {
            if (!function_exists('is_admin') || is_admin()) {
                return;
            }

            $this->render_banner_markup();
        }

        public function output_banner_fallback() {
            if (!function_exists('is_admin') || is_admin()) {
                return;
            }

            if (!did_action('wp_body_open')) {
                $this->render_banner_markup();
            }
        }

        private function render_banner_markup() {
            static $printed = false;
            if ($printed) {
                return;
            }

            $settings = $this->get_settings();
            $state = $this->get_closure_state();

            if (empty($settings['banner_enabled']) || empty($state['closed'])) {
                return;
            }

            $printed = true;
            echo '<div class="rb-scm-banner" role="status" aria-live="polite">' . esc_html($state['message']) . '</div>';
        }

        public function output_frontend_lockdown_script() {
            if (is_admin()) {
                return;
            }

            $state = $this->get_closure_state();
            if (empty($state['closed'])) {
                return;
            }

            $settings = $this->get_settings();
            $button_text = !empty($settings['closed_button_text']) ? $settings['closed_button_text'] : __('Store Closed', 'rb-store-closure-manager');
            $message = !empty($state['message']) ? $state['message'] : __('Store is currently closed.', 'rb-store-closure-manager');
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                var buttonText = <?php echo wp_json_encode($button_text); ?>;
                var message = <?php echo wp_json_encode($message); ?>;
                var selectors = [
                    'form.cart',
                    '.single_add_to_cart_button',
                    '.add_to_cart_button',
                    '.ajax_add_to_cart',
                    '.elementor-widget-woocommerce-product-add-to-cart form.cart',
                    '.elementor-widget-woocommerce-product-add-to-cart .single_add_to_cart_button',
                    '.elementor-widget-wc-archive-products .add_to_cart_button',
                    '.elementor-widget-loop-grid .add_to_cart_button',
                    '.woocommerce a.button.add_to_cart_button',
                    '.woocommerce button.single_add_to_cart_button'
                ];

                selectors.forEach(function (selector) {
                    document.querySelectorAll(selector).forEach(function (el) {
                        if (el.dataset.rbScmBound === '1') {
                            return;
                        }

                        el.dataset.rbScmBound = '1';

                        if (el.tagName === 'FORM') {
                            el.addEventListener('submit', function (event) {
                                event.preventDefault();
                                event.stopPropagation();
                                alert(message);
                            }, true);
                        } else if (el.closest('form.cart')) {
                            el.addEventListener('click', function (event) {
                                event.preventDefault();
                                event.stopPropagation();
                                alert(message);
                            }, true);
                        } else {
                            el.classList.add('rb-scm-disabled-link');
                            el.setAttribute('aria-disabled', 'true');

                            if (el.textContent && el.textContent.trim() !== '') {
                                el.textContent = buttonText;
                            }

                            el.addEventListener('click', function (event) {
                                event.preventDefault();
                                event.stopPropagation();
                                alert(message);
                            }, true);
                        }
                    });
                });
            });
            </script>
            <?php
        }

        public function render_admin_page() {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $settings = $this->get_settings();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Store Closure Manager', 'rb-store-closure-manager'); ?></h1>
                <p><?php esc_html_e('Choose holiday closure dates, control the weekly Friday-sundown through Saturday-twilight closure, and customize the banner and button text.', 'rb-store-closure-manager'); ?></p>

                <?php settings_errors(); ?>

                <form method="post" action="options.php">
                    <?php settings_fields('rb_scm_settings_group'); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable plugin', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked($settings['enabled'], 1); ?>>
                                        <?php esc_html_e('Enable closure rules', 'rb-store-closure-manager'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable banner', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[banner_enabled]" value="1" <?php checked($settings['banner_enabled'], 1); ?>>
                                        <?php esc_html_e('Show sitewide closure banner', 'rb-store-closure-manager'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Banner position', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[banner_position]">
                                        <option value="top-fixed" <?php selected($settings['banner_position'], 'top-fixed'); ?>><?php esc_html_e('Fixed to top', 'rb-store-closure-manager'); ?></option>
                                        <option value="top-static" <?php selected($settings['banner_position'], 'top-static'); ?>><?php esc_html_e('Static in page flow', 'rb-store-closure-manager'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Banner background', 'rb-store-closure-manager'); ?></th>
                                <td><input type="text" class="rb-color-field" name="<?php echo esc_attr(self::OPTION_KEY); ?>[banner_bg]" value="<?php echo esc_attr($settings['banner_bg']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Banner text color', 'rb-store-closure-manager'); ?></th>
                                <td><input type="text" class="rb-color-field" name="<?php echo esc_attr(self::OPTION_KEY); ?>[banner_text_color]" value="<?php echo esc_attr($settings['banner_text_color']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Holiday banner text', 'rb-store-closure-manager'); ?></th>
                                <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[banner_text_holiday]" value="<?php echo esc_attr($settings['banner_text_holiday']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Weekly banner text', 'rb-store-closure-manager'); ?></th>
                                <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[banner_text_shabbat]" value="<?php echo esc_attr($settings['banner_text_shabbat']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Closed button text', 'rb-store-closure-manager'); ?></th>
                                <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[closed_button_text]" value="<?php echo esc_attr($settings['closed_button_text']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Testing / manual override', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[force_closed]" value="1" <?php checked($settings['force_closed'], 1); ?>>
                                        <?php esc_html_e('Force store closed right now for testing', 'rb-store-closure-manager'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Shop/product replacement', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_notice_on_shop]" value="1" <?php checked($settings['show_notice_on_shop'], 1); ?>>
                                        <?php esc_html_e('Replace loop add-to-cart button', 'rb-store-closure-manager'); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_notice_on_product]" value="1" <?php checked($settings['show_notice_on_product'], 1); ?>>
                                        <?php esc_html_e('Show closed message on single product page', 'rb-store-closure-manager'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <hr>
                    <h2><?php esc_html_e('Weekly Friday/Saturday Closure', 'rb-store-closure-manager'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable weekly closure', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[weekly_enabled]" value="1" <?php checked($settings['weekly_enabled'], 1); ?>>
                                        <?php esc_html_e('Close every Friday at sundown through Saturday evening/twilight', 'rb-store-closure-manager'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Latitude', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <input type="number" step="0.000001" name="<?php echo esc_attr(self::OPTION_KEY); ?>[latitude]" value="<?php echo esc_attr($settings['latitude']); ?>">
                                    <p class="description"><?php esc_html_e('Used to calculate local sunset time.', 'rb-store-closure-manager'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Longitude', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <input type="number" step="0.000001" name="<?php echo esc_attr(self::OPTION_KEY); ?>[longitude]" value="<?php echo esc_attr($settings['longitude']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Friday offset (minutes)', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <input type="number" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[friday_offset]" value="<?php echo esc_attr($settings['friday_offset']); ?>">
                                    <p class="description"><?php esc_html_e('Use a negative number to start before sunset, or positive to start after sunset.', 'rb-store-closure-manager'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Saturday offset (minutes)', 'rb-store-closure-manager'); ?></th>
                                <td>
                                    <input type="number" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[saturday_offset]" value="<?php echo esc_attr($settings['saturday_offset']); ?>">
                                    <p class="description"><?php esc_html_e('Common values are 40–72 minutes after sunset.', 'rb-store-closure-manager'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <hr>
                    <h2><?php esc_html_e('Holiday Closure Dates', 'rb-store-closure-manager'); ?></h2>
                    <p><?php esc_html_e('Add as many custom closure date ranges as you want.', 'rb-store-closure-manager'); ?></p>
                    <input type="hidden" id="rb-scm-holiday-ranges-json" name="<?php echo esc_attr(self::OPTION_KEY); ?>[holiday_ranges_json]" value="">

                    <table class="widefat striped" id="rb-scm-holiday-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Label', 'rb-store-closure-manager'); ?></th>
                                <th><?php esc_html_e('Start', 'rb-store-closure-manager'); ?></th>
                                <th><?php esc_html_e('End', 'rb-store-closure-manager'); ?></th>
                                <th><?php esc_html_e('Actions', 'rb-store-closure-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($settings['holiday_ranges'])) : ?>
                                <?php foreach ($settings['holiday_ranges'] as $range) : ?>
                                    <tr>
                                        <td>
                                            <input type="text" class="regular-text" data-field="label" value="<?php echo esc_attr($range['label']); ?>">
                                        </td>
                                        <td>
                                            <input type="datetime-local" data-field="start" value="<?php echo esc_attr($this->storage_to_datetime_local($range['start'])); ?>">
                                        </td>
                                        <td>
                                            <input type="datetime-local" data-field="end" value="<?php echo esc_attr($this->storage_to_datetime_local($range['end'])); ?>">
                                        </td>
                                        <td>
                                            <button type="button" class="button button-secondary rb-scm-remove-row"><?php esc_html_e('Remove', 'rb-store-closure-manager'); ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <p style="margin-top:12px;">
                        <button type="button" class="button" id="rb-scm-add-row"><?php esc_html_e('Add Holiday Range', 'rb-store-closure-manager'); ?></button>
                    </p>

                    <?php submit_button(__('Save Settings', 'rb-store-closure-manager')); ?>
                </form>
            </div>
            <?php
        }
    }

    add_action('plugins_loaded', function () {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . esc_html__('ReadBright Store Closure Manager requires WooCommerce to be active.', 'rb-store-closure-manager') . '</p></div>';
            });
            return;
        }

        RB_Store_Closure_Manager::instance();
    });
}
