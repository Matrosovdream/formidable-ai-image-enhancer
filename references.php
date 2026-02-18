<?php

const FRM_AI_APIS = [

    'gemini' => [
        'name' => 'Gemini',
        'class' => 'GeminiApiClient',
        'models' => [

            /*
             |--------------------------------------------------------------------------
             | Image Generation / Editing
             |--------------------------------------------------------------------------
             */

            'gemini-2.5-flash-image' => [
                'title'       => 'Gemini 2.5 Flash Image (Nano Banana)',
                'type'        => 'image',
                'description' => 'Latest optimized model for image generation and editing. Best for background removal, repositioning, passport fixes, and 600x600 output.',
            ],

            'gemini-2.0-flash-image' => [
                'title'       => 'Gemini 2.0 Flash Image',
                'type'        => 'image',
                'description' => 'Stable image generation and editing model. Slightly older and less advanced than 2.5 Flash Image.',
            ],

            /*
             |--------------------------------------------------------------------------
             | Multimodal (Image Input + Reasoning)
             |--------------------------------------------------------------------------
             */

            'gemini-2.5-pro' => [
                'title'       => 'Gemini 2.5 Pro',
                'type'        => 'multimodal',
                'description' => 'High reasoning multimodal model. Supports image input and complex analysis, but not optimized for direct image generation.',
            ],

            'gemini-2.5-flash' => [
                'title'       => 'Gemini 2.5 Flash',
                'type'        => 'multimodal',
                'description' => 'Fast multimodal model with image understanding. Good for analysis and light transformations.',
            ],

            'gemini-2.0-pro' => [
                'title'       => 'Gemini 2.0 Pro',
                'type'        => 'multimodal',
                'description' => 'Previous generation high-capability multimodal reasoning model.',
            ],

            'gemini-2.0-flash' => [
                'title'       => 'Gemini 2.0 Flash',
                'type'        => 'multimodal',
                'description' => 'Balanced speed and reasoning. Supports image input but not specialized for editing.',
            ],

            'gemini-1.5-pro' => [
                'title'       => 'Gemini 1.5 Pro',
                'type'        => 'multimodal',
                'description' => 'Earlier generation multimodal model with strong reasoning and image analysis support.',
            ],

            'gemini-1.5-flash' => [
                'title'       => 'Gemini 1.5 Flash',
                'type'        => 'multimodal',
                'description' => 'Fast and lightweight multimodal model. Suitable for quick image analysis tasks.',
            ],

        ],
    ],

];
