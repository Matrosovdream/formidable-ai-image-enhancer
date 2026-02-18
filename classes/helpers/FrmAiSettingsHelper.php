<?php
if ( ! defined('ABSPATH') ) { exit; }

final class FrmAiSettingsHelper
{
    /** Option name to store all settings */
    public const OPTION_NAME = 'frm_image_enhancer_settings';

    /** Safely returns FRM_AI_APIS */
    public static function getApis(): array
    {
        if ( ! defined('FRM_AI_APIS') ) {
            return [];
        }
        $apis = FRM_AI_APIS;
        return is_array($apis) ? $apis : [];
    }

    /**
     * Requested: getApiProviderByKey()
     * Returns: code, name, key
     */
    public static function getApiProviderByKey(string $apiKey): ?array
    {
        $apis = self::getApis();
        if (empty($apis[$apiKey]) || !is_array($apis[$apiKey])) {
            return null;
        }

        $row  = $apis[$apiKey];
        $name = isset($row['name']) ? (string) $row['name'] : $apiKey;

        return [
            'code' => $apiKey,
            'name' => $name,
            'key'  => $apiKey,
        ];
    }

    /** Provider client class from FRM_AI_APIS[provider]['class'] */
    public static function getProviderClass(string $apiKey): ?string
    {
        $apis = self::getApis();
        if (empty($apis[$apiKey]) || !is_array($apis[$apiKey])) {
            return null;
        }

        $class = $apis[$apiKey]['class'] ?? '';
        $class = is_string($class) ? $class : '';
        return $class !== '' ? $class : null;
    }

    public static function getModels(string $apiKey): array
    {
        $apis = self::getApis();
        $models = $apis[$apiKey]['models'] ?? [];
        return is_array($models) ? $models : [];
    }

    public static function getModelByKey(string $apiKey, string $modelKey): ?array
    {
        $models = self::getModels($apiKey);
        if (empty($models[$modelKey]) || !is_array($models[$modelKey])) {
            return null;
        }
        return $models[$modelKey];
    }

    /** Select options: [model_key => title] */
    public static function getModelSelectOptions(string $apiKey, ?string $type = null): array
    {
        $models = self::getModels($apiKey);
        if (empty($models)) {
            return [];
        }

        $out = [];
        foreach ($models as $key => $meta) {
            if (!is_array($meta)) { continue; }

            if ($type !== null) {
                $t = isset($meta['type']) ? (string) $meta['type'] : '';
                if ($t !== $type) { continue; }
            }

            $title = isset($meta['title']) ? (string) $meta['title'] : (string) $key;
            $out[(string)$key] = $title;
        }

        return $out;
    }

    /** Descriptions for JS: [model_key => description] */
    public static function getModelDescriptions(string $apiKey): array
    {
        $models = self::getModels($apiKey);
        $out = [];

        foreach ($models as $key => $meta) {
            if (!is_array($meta)) { continue; }
            $out[(string)$key] = isset($meta['description']) ? (string) $meta['description'] : '';
        }

        return $out;
    }

    /** Raw saved settings */
    public static function getSettings(): array
    {
        $saved = get_option(self::OPTION_NAME, []);
        return is_array($saved) ? $saved : [];
    }

    /**
     * Provider settings normalized.
     * Supports:
     * - new structure: settings['providers'][provider]
     * - legacy: settings[provider]
     */
    public static function getProviderSettings(array $all, string $apiKey): array
    {
        $row = [];

        if (isset($all['providers']) && is_array($all['providers']) && isset($all['providers'][$apiKey]) && is_array($all['providers'][$apiKey])) {
            $row = $all['providers'][$apiKey];
        } elseif (isset($all[$apiKey]) && is_array($all[$apiKey])) {
            $row = $all[$apiKey];
        }

        return [
            'api_key' => isset($row['api_key']) ? (string) $row['api_key'] : '',
            'model'   => isset($row['model']) ? (string) $row['model'] : '',
        ];
    }

    /** Sanitizes providers POST */
    public static function sanitizeIncomingProviders(array $incoming): array
    {
        $apis = self::getApis();
        $new  = [];

        foreach ($apis as $apiKey => $apiMeta) {
            $apiKey = (string) $apiKey;
            if (!is_array($apiMeta)) { continue; }

            $row = isset($incoming[$apiKey]) && is_array($incoming[$apiKey]) ? $incoming[$apiKey] : [];

            $api_key = isset($row['api_key']) ? sanitize_text_field((string) $row['api_key']) : '';
            $model   = isset($row['model']) ? sanitize_text_field((string) $row['model']) : '';

            if ($model !== '' && self::getModelByKey($apiKey, $model) === null) {
                $model = '';
            }

            $new[$apiKey] = [
                'api_key' => $api_key,
                'model'   => $model,
            ];
        }

        return $new;
    }

    /** Default model = first model key */
    public static function getDefaultModel(string $apiKey): string
    {
        $models = self::getModels($apiKey);
        if (empty($models)) return '';
        $keys = array_keys($models);
        return isset($keys[0]) ? (string) $keys[0] : '';
    }

    /**
     * ✅ getDefaultPrompts(): returns pairs title + text + selected
     * [
     *   ['title' => '...', 'text' => '...', 'selected' => true],
     * ]
     */
    public static function getDefaultPrompts(?array $settings = null): array
    {
        $all = is_array($settings) ? $settings : self::getSettings();

        $rows = [];
        if (isset($all['enhancer']['default_prompts']) && is_array($all['enhancer']['default_prompts'])) {
            $rows = $all['enhancer']['default_prompts'];
        }

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }

            $title = isset($r['title']) ? trim((string)$r['title']) : '';
            $text  = isset($r['text']) ? trim((string)$r['text']) : '';

            if ($title === '' && $text === '') { continue; }

            $out[] = [
                'title'    => $title,
                'text'     => $text,
                'selected' => !empty($r['selected']),
            ];
        }

        return $out;
    }

    /**
     * ✅ Sanitizes enhancer prompts from three arrays:
     * enhancer[default_prompts_title][]
     * enhancer[default_prompts_text][]
     * enhancer[default_prompts_selected][]
     */
    public static function sanitizeIncomingEnhancer(array $incomingEnhancer): array
    {
        $titles = $incomingEnhancer['default_prompts_title'] ?? [];
        $texts  = $incomingEnhancer['default_prompts_text'] ?? [];
        $sels   = $incomingEnhancer['default_prompts_selected'] ?? [];

        if (!is_array($titles)) { $titles = []; }
        if (!is_array($texts))  { $texts  = []; }
        if (!is_array($sels))   { $sels   = []; }

        $rows = [];
        $max = max(count($titles), count($texts), count($sels));

        for ($i = 0; $i < $max; $i++) {
            $title = isset($titles[$i]) ? (string) $titles[$i] : '';
            $text  = isset($texts[$i])  ? (string) $texts[$i]  : '';

            $title = isset($titles[$i]) ? (string) $titles[$i] : '';
            $title = wp_unslash($title);
            $title = trim(sanitize_text_field($title));

            $text = isset($texts[$i]) ? (string) $texts[$i] : '';
            $text = wp_unslash($text);
            $text = trim(wp_kses_post($text));

            // allow title-only or text-only (but not fully empty row)
            if ($title === '' && $text === '') { continue; }

            $selected = !empty($sels[$i]) && (string)$sels[$i] !== '0';

            $rows[] = [
                'title'    => $title,
                'text'     => $text,
                'selected' => $selected ? 1 : 0,
            ];
        }

        return [
            'default_prompts' => $rows,
        ];
    }
}
