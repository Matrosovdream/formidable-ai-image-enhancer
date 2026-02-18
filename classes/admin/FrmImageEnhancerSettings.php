<?php
if ( ! defined('ABSPATH') ) { exit; }

final class FrmImageEnhancerSettings
{
    private const PARENT_SLUG = 'formidable';
    private const PAGE_SLUG   = 'frm-image-enhancer-settings';
    private const NONCE_SAVE  = 'frm_image_enhancer_save_settings';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 20);
        add_action('admin_init', [__CLASS__, 'maybe_handle_save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            'AI image enhancer',
            'AI image enhancer',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== self::PAGE_SLUG) return;

        if ( ! defined('FRM_AI_BASE_PATH') ) return;

        wp_enqueue_style(
            'frm-ai-admin-settings',
            FRM_AI_BASE_PATH . 'assets/admin/admin_settings.css?time=' . time()
        );

        wp_enqueue_script(
            'frm-ai-admin-settings',
            FRM_AI_BASE_PATH . 'assets/admin/admin_settings.js?time=' . time(),
            ['jquery'],
            null,
            true
        );

        // model descriptions for UI
        $apis = FrmAiSettingsHelper::getApis();
        $modelDescriptions = [];
        foreach ($apis as $apiKey => $apiMeta) {
            $modelDescriptions[(string)$apiKey] = FrmAiSettingsHelper::getModelDescriptions((string)$apiKey);
        }

        wp_localize_script('frm-ai-admin-settings', 'FRM_IMAGE_ENHANCER', [
            'model_descriptions' => $modelDescriptions,
        ]);
    }

    private static function get_tabs(): array
    {
        return [
            'api-connection' => 'API connection',
            'enhancer'       => 'Enhancer',
        ];
    }

    /** Tab must survive reload + POST save */
    private static function get_current_tab(): string
    {
        $tabs = self::get_tabs();

        $postTab = isset($_POST['frm_ai_tab']) ? sanitize_key((string) $_POST['frm_ai_tab']) : '';
        if ($postTab !== '' && isset($tabs[$postTab])) {
            return $postTab;
        }

        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'api-connection';
        if ($tab === '' || !isset($tabs[$tab])) {
            $tab = 'api-connection';
        }

        return $tab;
    }

    public static function maybe_handle_save(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== self::PAGE_SLUG) return;

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if ( ! current_user_can('manage_options') ) return;

        if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce((string) $_POST['_wpnonce'], self::NONCE_SAVE) ) {
            return;
        }

        $tab = self::get_current_tab();

        // Load existing saved settings first (so we can merge)
        $saved = FrmAiSettingsHelper::getSettings();

        // Normalize base structure
        if (!is_array($saved)) { $saved = []; }
        if (!isset($saved['providers']) || !is_array($saved['providers'])) { $saved['providers'] = []; }
        if (!isset($saved['enhancer'])  || !is_array($saved['enhancer']))  { $saved['enhancer']  = []; }

        // Update only what was submitted / current tab
        if ($tab === 'api-connection') {

            $incomingProviders = isset($_POST['providers']) && is_array($_POST['providers']) ? $_POST['providers'] : [];
            $providersNew = FrmAiSettingsHelper::sanitizeIncomingProviders($incomingProviders);

            $saved['providers'] = $providersNew;

        } elseif ($tab === 'enhancer') {

            $incomingEnhancer = isset($_POST['enhancer']) && is_array($_POST['enhancer']) ? $_POST['enhancer'] : [];
            $enhancerNew = FrmAiSettingsHelper::sanitizeIncomingEnhancer($incomingEnhancer);

            // merge enhancer section (so later можно расширять enhancer ещё полями)
            $saved['enhancer'] = array_merge($saved['enhancer'], $enhancerNew);

        } else {
            // Unknown tab - do nothing
        }

        update_option(FrmAiSettingsHelper::OPTION_NAME, $saved, false);

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

    public static function render_page(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Sorry, you are not allowed to access this page.');
        }

        $tab      = self::get_current_tab();
        $tabs     = self::get_tabs();
        $settings = FrmAiSettingsHelper::getSettings();
        $apis     = FrmAiSettingsHelper::getApis();

        echo '<div class="wrap">';
        echo '<h1>AI image enhancer</h1>';

        // Tabs (reload)
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = ($key === $tab) ? ' nav-tab-active' : '';
            $href = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $key], admin_url('admin.php'));
            echo '<a href="' . esc_url($href) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        echo '<div class="fo-section">';
        echo '<form method="post" action="">';
        wp_nonce_field(self::NONCE_SAVE);
        echo '<input type="hidden" name="frm_ai_tab" value="' . esc_attr($tab) . '">';

        // -------------------------
        // TAB: API connection
        // -------------------------
        if ($tab === 'api-connection') {

            if (empty($apis)) {
                echo '<p>No providers found. Define <code>FRM_AI_APIS</code> first.</p>';
            } else {

                foreach ($apis as $apiKey => $apiMeta) {
                    $apiKey = (string) $apiKey;

                    $provider = FrmAiSettingsHelper::getApiProviderByKey($apiKey);
                    if (!$provider) continue;

                    $title = (string) $provider['name'];
                    $row   = FrmAiSettingsHelper::getProviderSettings($settings, $apiKey);

                    $options = FrmAiSettingsHelper::getModelSelectOptions($apiKey, null);
                    $defaultModel = FrmAiSettingsHelper::getDefaultModel($apiKey);
                    $selectedModel = $row['model'] !== '' ? $row['model'] : $defaultModel;

                    echo '<div class="fo-provider">';
                    echo '<h3>' . esc_html($title) . ' <span style="opacity:.6;font-weight:400;">(' . esc_html($apiKey) . ')</span></h3>';

                    echo '<div class="fo-grid">';

                    echo '<label for="fo_api_key_' . esc_attr($apiKey) . '">API key</label>';
                    echo '<input type="text" class="regular-text" id="fo_api_key_' . esc_attr($apiKey) . '" name="providers[' . esc_attr($apiKey) . '][api_key]" value="' . esc_attr($row['api_key']) . '" />';

                    echo '<label for="fo_model_' . esc_attr($apiKey) . '">Model</label>';
                    echo '<div>';
                    echo '<select class="fo-model-select" id="fo_model_' . esc_attr($apiKey) . '" name="providers[' . esc_attr($apiKey) . '][model]" data-api="' . esc_attr($apiKey) . '">';
                    echo '<option value="">— Select model —</option>';

                    foreach ($options as $modelKey => $modelTitle) {
                        echo '<option value="' . esc_attr($modelKey) . '"' . selected($selectedModel, $modelKey, false) . '>'
                            . esc_html($modelTitle)
                            . ' (' . esc_html($modelKey) . ')'
                            . '</option>';
                    }

                    echo '</select>';
                    echo '<p class="description fo-model-desc" style="margin:6px 0 0;"></p>';
                    echo '</div>';

                    echo '</div>'; // grid
                    echo '</div>'; // provider
                }
            }

            submit_button('Save settings');
        }

        // -------------------------
        // TAB: Enhancer
        // -------------------------
        elseif ($tab === 'enhancer') {

            $existing = [];
            if (isset($settings['enhancer']['default_prompts']) && is_array($settings['enhancer']['default_prompts'])) {
                $existing = $settings['enhancer']['default_prompts'];
            }

            if (empty($existing)) {
                $existing[] = ['title' => '', 'text' => '', 'selected' => 0];
            }

            echo '<div class="fo-provider">';
            echo '<h3>Default prompts</h3>';
            echo '<p class="description" style="margin-top:6px;">Each row has <b>Title</b>, long <b>Text</b>, and paired <b>Selected</b>.</p>';

            echo '<div class="fo-prompts" id="foDefaultPrompts">';

            foreach ($existing as $r) {
                $title = isset($r['title']) ? (string) $r['title'] : '';
                $text  = isset($r['text']) ? (string) $r['text'] : '';
                $sel   = !empty($r['selected']);

                echo '<div class="fo-prompt-row">';

                echo '<label class="fo-prompt-title-label">Title</label>';
                echo '<input type="text" class="regular-text fo-prompt-title" '
                    . 'name="enhancer[default_prompts_title][]" '
                    . 'placeholder="Prompt title..." '
                    . 'value="' . esc_attr($title) . '">';

                echo '<label class="fo-prompt-text-label">Text</label>';
                echo '<textarea class="large-text fo-prompt-text" rows="4" '
                    . 'name="enhancer[default_prompts_text][]" '
                    . 'placeholder="Enter prompt...">'
                    . esc_textarea($text)
                    . '</textarea>';

                // Hidden stores 0/1 and is posted always
                echo '<input type="hidden" name="enhancer[default_prompts_selected][]" value="' . esc_attr($sel ? '1' : '0') . '">';

                echo '<label class="fo-prompt-selected">';
                echo '<input type="checkbox" class="fo-prompt-check" value="1"' . checked($sel, true, false) . '> Selected';
                echo '</label>';

                // X remove button bottom-right
                echo '<button type="button" class="fo-prompt-remove" aria-label="Remove prompt" title="Remove">×</button>';

                echo '</div>';
            }

            echo '</div>'; // foDefaultPrompts

            echo '<p style="margin:10px 0 0;">';
            echo '<button type="button" class="button" id="foPromptAdd">Add prompt</button>';
            echo '</p>';

            echo '</div>'; // provider

            submit_button('Save settings');
        }

        else {
            echo '<p>Tab not found.</p>';
        }

        echo '</form>';
        echo '</div>'; // section
        echo '</div>'; // wrap
    }
}

FrmImageEnhancerSettings::init();
