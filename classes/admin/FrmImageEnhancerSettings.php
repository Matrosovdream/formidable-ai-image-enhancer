<?php
if ( ! defined('ABSPATH') ) { exit; }

final class FrmImageEnhancerSettings {

    /**
     * Parent slug updated per your request
     * Child for Formidable menu.
     */
    private const PARENT_SLUG = 'formidable';

    /** Page slug for this settings page */
    private const PAGE_SLUG   = 'frm-image-enhancer-settings';

    /** Option name to store all settings */
    private const OPTION_NAME = 'frm_image_enhancer_settings';

    /** Nonce actions */
    private const NONCE_SAVE  = 'frm_image_enhancer_save_settings';
    private const NONCE_AJAX  = 'frm_image_enhancer_ajax';

    /**
     * Providers mapping
     * code => ['title' => '...']
     */
    private const API_PROVIDERS = [
        'nanobanano' => [ 'title' => 'Nano Banano' ],
    ];

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 20);
        add_action('admin_init', [__CLASS__, 'maybe_handle_save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // AJAX: verify provider
        add_action('wp_ajax_frm_image_enhancer_verify_provider', [__CLASS__, 'ajax_verify_provider']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            self::PARENT_SLUG,
            'AI image enhancer',
            'AI image enhancer',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_assets(string $hook): void {
        // Only load on our page
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }

        // Ensure your constants exist (you said they already do)
        if ( ! defined('FRM_AI_BASE_PATH') || ! defined('FRM_AI_BASE_URL') ) {
            return;
        }

        $css_rel = 'assets/admin/admin_settings.css';
        $js_rel  = 'assets/admin/admin_settings.js';

        $css_url  = rtrim((string) FRM_AI_BASE_PATH, '/') . '/' . $css_rel;
        $js_url   = rtrim((string) FRM_AI_BASE_PATH, '/') . '/' . $js_rel;

        $css_path = rtrim((string) FRM_AI_BASE_URL, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $css_rel);
        $js_path  = rtrim((string) FRM_AI_BASE_URL, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $js_rel);

        $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0';
        $js_ver  = file_exists($js_path)  ? (string) filemtime($js_path)  : '1.0.0';

        wp_enqueue_style(
            'frm-ai-admin-settings',
            $css_url,
            [],
            $css_ver
        );

        wp_enqueue_script(
            'frm-ai-admin-settings',
            $js_url,
            ['jquery'],
            $js_ver,
            true
        );

        // Pass ajax config to JS
        wp_localize_script('frm-ai-admin-settings', 'FRM_IMAGE_ENHANCER', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_AJAX),
        ]);
    }

    private static function get_current_tab(): string {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'api-connection';
        return $tab ?: 'api-connection';
    }

    private static function get_tabs(): array {
        return [
            'api-connection' => 'API connection',
        ];
    }

    private static function get_settings(): array {
        $saved = get_option(self::OPTION_NAME, []);
        return is_array($saved) ? $saved : [];
    }

    private static function get_provider_settings(array $all, string $code): array {
        $row = isset($all[$code]) && is_array($all[$code]) ? $all[$code] : [];
        return [
            'api_key' => isset($row['api_key']) ? (string) $row['api_key'] : '',
            'api_url' => isset($row['api_url']) ? (string) $row['api_url'] : '',
            'mode'    => isset($row['mode']) ? (string) $row['mode'] : 'production',
        ];
    }

    public static function maybe_handle_save(): void {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        if ( ! current_user_can('manage_options') ) {
            return;
        }

        if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce((string) $_POST['_wpnonce'], self::NONCE_SAVE) ) {
            return;
        }

        $incoming = isset($_POST['providers']) && is_array($_POST['providers']) ? $_POST['providers'] : [];

        $new = [];
        foreach (self::API_PROVIDERS as $code => $meta) {
            $row = isset($incoming[$code]) && is_array($incoming[$code]) ? $incoming[$code] : [];

            $api_key = isset($row['api_key']) ? sanitize_text_field((string) $row['api_key']) : '';
            $api_url = isset($row['api_url']) ? esc_url_raw((string) $row['api_url']) : '';

            $mode = isset($row['mode']) ? sanitize_key((string) $row['mode']) : 'production';
            if ( ! in_array($mode, ['production', 'development'], true) ) {
                $mode = 'production';
            }

            $new[$code] = [
                'api_key' => $api_key,
                'api_url' => $api_url,
                'mode'    => $mode,
            ];
        }

        update_option(self::OPTION_NAME, $new, false);

        $tab = self::get_current_tab();
        $url = add_query_arg(
            [
                'page'    => self::PAGE_SLUG,
                'tab'     => $tab,
                'updated' => '1',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public static function render_page(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Sorry, you are not allowed to access this page.');
        }

        $tab      = self::get_current_tab();
        $tabs     = self::get_tabs();
        $settings = self::get_settings();

        echo '<div class="wrap">';
        echo '<h1>AI image enhancer</h1>';

        // Tabs (reload page)
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = ($key === $tab) ? ' nav-tab-active' : '';
            $href = add_query_arg(
                ['page' => self::PAGE_SLUG, 'tab' => $key],
                admin_url('admin.php')
            );
            echo '<a href="' . esc_url($href) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        echo '<div class="fo-section">';

        if ($tab === 'api-connection') {
            echo '<form method="post" action="">';
            wp_nonce_field(self::NONCE_SAVE);

            foreach (self::API_PROVIDERS as $code => $meta) {
                $title = isset($meta['title']) ? (string) $meta['title'] : $code;
                $row   = self::get_provider_settings($settings, $code);

                echo '<div class="fo-provider">';
                echo '<h3>' . esc_html($title) . ' <span style="opacity:.6;font-weight:400;">(' . esc_html($code) . ')</span></h3>';

                echo '<div class="fo-grid">';

                echo '<label for="fo_api_key_' . esc_attr($code) . '">API key</label>';
                echo '<input type="text" class="regular-text" id="fo_api_key_' . esc_attr($code) . '" name="providers[' . esc_attr($code) . '][api_key]" value="' . esc_attr($row['api_key']) . '" />';

                echo '<label for="fo_api_url_' . esc_attr($code) . '">API url</label>';
                echo '<input type="url" class="regular-text" id="fo_api_url_' . esc_attr($code) . '" name="providers[' . esc_attr($code) . '][api_url]" value="' . esc_attr($row['api_url']) . '" placeholder="https://..." />';

                echo '<label for="fo_mode_' . esc_attr($code) . '">Mode</label>';
                echo '<select id="fo_mode_' . esc_attr($code) . '" name="providers[' . esc_attr($code) . '][mode]">';
                echo '<option value="production"' . selected($row['mode'], 'production', false) . '>production</option>';
                echo '<option value="development"' . selected($row['mode'], 'development', false) . '>development</option>';
                echo '</select>';

                echo '</div>'; // .fo-grid

                echo '<div class="fo-actions">';
                echo '<button type="button" class="button fo-verify-btn" data-provider="' . esc_attr($code) . '">Verify</button>';
                echo '<span class="fo-inline-msg fo-verify-msg ok" style="display:none;"></span>';
                echo '</div>';

                echo '</div>'; // .fo-provider
            }

            submit_button('Save settings');
            echo '</form>';
        } else {
            echo '<p>Tab not found.</p>';
        }

        echo '</div>'; // .fo-section
        echo '</div>'; // .wrap
    }

    public static function ajax_verify_provider(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['ok' => false, 'message' => 'forbidden'], 403);
        }

        $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if ( ! wp_verify_nonce($nonce, self::NONCE_AJAX) ) {
            wp_send_json_error(['ok' => false, 'message' => 'bad_nonce'], 403);
        }

        $provider = isset($_POST['provider']) ? sanitize_key((string) $_POST['provider']) : '';
        if ($provider === '' || ! array_key_exists($provider, self::API_PROVIDERS)) {
            wp_send_json_error(['ok' => false, 'message' => 'unknown_provider'], 400);
        }

        // Return ok by default (per your request)
        wp_send_json_success(['ok' => true]);
    }
}

FrmImageEnhancerSettings::init();