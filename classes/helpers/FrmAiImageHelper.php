<?php

if ( ! defined('ABSPATH') ) { exit; }

class FrmAiImageHelper
{
    /**
     * Process image using selected provider.
     *
     * @param string $inputFilePath
     * @param array  $prompts
     * @param string $provider (default: gemini)
     *
     * @return array
     */
    public function processImage(string $inputFilePath, array $prompts = [], string $provider = 'gemini'): array
    {
        if (empty($inputFilePath) || !file_exists($inputFilePath)) {
            return [
                'ok' => false,
                'message' => 'Input file not found',
            ];
        }

        // Get provider class
        $class = FrmAiSettingsHelper::getProviderClass($provider);
        if (!$class || !class_exists($class)) {
            return [
                'ok' => false,
                'message' => 'Provider class not found: ' . $provider,
            ];
        }

        // Get saved settings
        $allSettings = FrmAiSettingsHelper::getSettings();
        $providerSettings = FrmAiSettingsHelper::getProviderSettings($allSettings, $provider);

        $apiKey = $providerSettings['api_key'] ?? '';
        $model  = $providerSettings['model'] ?? '';

        if (!$apiKey) {
            return [
                'ok' => false,
                'message' => 'API key not configured for provider: ' . $provider,
            ];
        }

        // If model not selected → take default
        if (!$model) {
            $model = FrmAiSettingsHelper::getDefaultModel($provider);
        }

        // 3Prepare prompts
        $fullPrompts = !empty($prompts) ? implode(', ', $prompts) : '';

        // Instantiate provider class
        try {

            /**
             * Expecting constructor like:
             * new GeminiApiClient($apiKey, $model)
             */
            $client = new $class($apiKey, $model);

            $result = $client->processImage(
                $inputFilePath,
                $fullPrompts
            );

            return $result;

        } catch (\Throwable $e) {

            return [
                'ok' => false,
                'message' => 'Processing failed: ' . $e->getMessage(),
            ];
        }
    }
}
