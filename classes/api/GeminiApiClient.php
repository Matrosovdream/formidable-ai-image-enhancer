<?php

class GeminiApiClient
{
    /**
     * Final images saved here (must be writable).
     * You requested this exact value.
     */
    public const TMP_DIR = WP_CONTENT_DIR . '/uploads/tmp_gemini_ai/tmp';

    /**
     * Pricing (USD) — approximate.
     * Input tokens follow Gemini Flash pricing; image output tokens priced higher for Flash Image.
     */
    private const PRICE_INPUT_PER_1M_TOKENS_USD  = 0.30;
    private const PRICE_OUTPUT_PER_1M_TOKENS_USD = 30.00; // for image output tokens

    private string $apiKey;
    private string $model;   // e.g. gemini-2.5-flash-image
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    private string $tmpDir;

    public function __construct(string $apiKey, string $model = 'gemini-2.5-flash-image', ?string $tmpDir = null)
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
        $this->tmpDir = $tmpDir ?: self::TMP_DIR;

        $this->ensureTmpDir();
    }

    /**
     * Main entry point.
     *
     * Usage:
     *   $result = $client->processImage($inputFilePath, 'Turn this into a cyberpunk night scene...');
     *
     * New params:
     *   $doResize (bool) - resize to square PNG or keep original size (re-encode to PNG)
     *   $size (int) - square size when resizing (default 600)
     *
     * Returns:
     * [
     *   'tokens_spent' => int,
     *   'approx_price_usd' => float,
     *   'usage' => ['prompt_tokens'=>int,'output_tokens'=>int,'total_tokens'=>int],
     *   'data' => [
     *      'final_url' => string|null,
     *      'final_path' => string,
     *      'final_filename' => string,
     *      'final_mime' => 'image/png',
     *      'final_width' => int,
     *      'final_height' => int,
     *   ],
     * ]
     */
    public function processImage(
        string $inputFilePath,
        string $prompt,
        bool $doResize = false,
        int $size = 600
    ): array {

        // Convert URL to local path if needed
        $inputFilePath = str_replace(content_url(), WP_CONTENT_DIR, $inputFilePath);

        if (!is_file($inputFilePath) || !is_readable($inputFilePath)) {
            throw new Exception('Input file not found or not readable: ' . $inputFilePath);
        }

        $imageBytes = file_get_contents($inputFilePath);
        if ($imageBytes === false || $imageBytes === '') {
            throw new Exception('Failed to read input file: ' . $inputFilePath);
        }

        $mimeType = $this->guessMimeTypeFromPath($inputFilePath) ?: 'image/jpeg';

        // Build payload: image inline_data + prompt text
        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data'      => base64_encode($imageBytes),
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
        ];

        $rawResponse = $this->requestGenerateContent($payload);

        $imgB64 = $this->extractFirstImageBase64($rawResponse);
        if (!$imgB64) {
            // Include response in exception for debugging (trim if too large)
            throw new Exception('Gemini returned no image. Response: ' . json_encode($rawResponse));
        }

        $modelBinary = base64_decode($imgB64, true);
        if ($modelBinary === false || $modelBinary === '') {
            throw new Exception('Failed to base64-decode model image.');
        }

        $finalMime = 'image/png';
        $finalExt  = 'png';

        if ($doResize) {
            $size = max(1, (int) $size);
            $finalPngBinary = $this->resizeToSquarePng($modelBinary, $size);
            $finalWidth  = $size;
            $finalHeight = $size;
        } else {
            // No resize: re-encode to PNG without changing dimensions (keeps alpha)
            $re = $this->reencodeToPng($modelBinary);
            $finalPngBinary = $re['png'];
            $finalWidth  = $re['width'];
            $finalHeight = $re['height'];
        }

        // Save as: originalfilename_TIMESTAMP.png
        $finalFilename = $this->makeTimestampedFilename($inputFilePath, $finalExt);
        $finalPath     = $this->tmpDir . DIRECTORY_SEPARATOR . $finalFilename;

        if (file_put_contents($finalPath, $finalPngBinary) === false) {
            throw new Exception('Failed to write final file: ' . $finalPath);
        }

        $finalUrl = $this->pathToPublicUrl($finalPath);

        // Usage & pricing
        $usage = $this->extractUsage($rawResponse);
        $approxPrice = $this->estimatePriceUsd($usage['prompt_tokens'], $usage['output_tokens']);

        return [
            'tokens_spent'      => (int) $usage['total_tokens'],
            'approx_price_usd'  => $approxPrice,
            'usage'             => $usage,
            'data'              => [
                'final_url'      => $finalUrl,
                'final_path'     => $finalPath,
                'final_filename' => $finalFilename,
                'final_mime'     => $finalMime,
                'final_width'    => (int) $finalWidth,
                'final_height'   => (int) $finalHeight,
            ],
            //'raw_response'      => $rawResponse,
        ];
    }

    /**
     * Extract first generated image base64 from Gemini response.
     * Supports inlineData (camelCase) and inline_data (snake_case).
     */
    public function extractFirstImageBase64(array $response): ?string
    {
        if (empty($response['candidates'][0]['content']['parts'])) {
            return null;
        }

        foreach ($response['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['inlineData']['data'])) {
                return $part['inlineData']['data'];
            }
            if (isset($part['inline_data']['data'])) {
                return $part['inline_data']['data'];
            }
        }

        return null;
    }

    // -------------------------
    // Internals
    // -------------------------

    private function requestGenerateContent(array $payload): array
    {
        $url = sprintf(
            '%s/models/%s:generateContent?key=%s',
            $this->baseUrl,
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Curl error: ' . $err);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);

        if ($httpCode >= 400) {
            $msg = $json['error']['message'] ?? $raw;
            throw new Exception("Gemini API error (HTTP $httpCode): " . $msg);
        }

        return $json ?: ['raw' => $raw];
    }

    /**
     * Usage can be under usageMetadata. We normalize it.
     */
    private function extractUsage(array $response): array
    {
        // Common structure in Gemini API:
        // usageMetadata: { promptTokenCount, candidatesTokenCount, totalTokenCount }
        $u = $response['usageMetadata'] ?? [];

        $prompt = (int) ($u['promptTokenCount'] ?? 0);
        $out    = (int) ($u['candidatesTokenCount'] ?? 0);
        $total  = (int) ($u['totalTokenCount'] ?? ($prompt + $out));

        return [
            'prompt_tokens' => $prompt,
            'output_tokens' => $out,
            'total_tokens'  => $total,
        ];
    }

    private function estimatePriceUsd(int $promptTokens, int $outputTokens): float
    {
        $cost =
            ($promptTokens / 1_000_000) * self::PRICE_INPUT_PER_1M_TOKENS_USD +
            ($outputTokens  / 1_000_000) * self::PRICE_OUTPUT_PER_1M_TOKENS_USD;

        return (float) round($cost, 6);
    }

    private function makeTimestampedFilename(string $inputFilePath, string $newExt): string
    {
        $base = pathinfo($inputFilePath, PATHINFO_FILENAME);
        $base = $this->sanitizeFilename($base);

        // timestamp as number (you requested)
        $ts = time();

        return $base . '_' . $ts . '.' . $newExt;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_\-\.]+/', '_', $name) ?? $name;
        $name = trim($name, '._-');
        return $name !== '' ? $name : 'image';
    }

    private function guessMimeTypeFromPath(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
    }

    private function ensureTmpDir(): void
    {
        if (!is_dir($this->tmpDir)) {
            if (!mkdir($this->tmpDir, 0755, true) && !is_dir($this->tmpDir)) {
                throw new Exception('Failed to create tmp dir: ' . $this->tmpDir);
            }
        }

        if (!is_writable($this->tmpDir)) {
            throw new Exception('Tmp dir not writable: ' . $this->tmpDir);
        }
    }

    /**
     * Convert an absolute file path into a public URL if possible.
     * - If WordPress is available, uses wp_upload_dir mapping.
     * - Otherwise tries document root mapping.
     *
     * Returns null if it can't safely map.
     */
    private function pathToPublicUrl(string $absolutePath): ?string
    {
        // WordPress mapping (best)
        if (function_exists('wp_upload_dir')) {
            $u = wp_upload_dir(null, false);
            $basedir = $u['basedir'] ?? null;
            $baseurl = $u['baseurl'] ?? null;

            if ($basedir && $baseurl) {
                $basedir = rtrim(str_replace('\\', '/', $basedir), '/');
                $abs     = str_replace('\\', '/', $absolutePath);

                if (function_exists('str_starts_with') && str_starts_with($abs, $basedir . '/')) {
                    $rel = substr($abs, strlen($basedir) + 1);
                    return rtrim($baseurl, '/') . '/' . ltrim($rel, '/');
                }

                // PHP < 8 fallback
                if (!function_exists('str_starts_with') && strpos($abs, $basedir . '/') === 0) {
                    $rel = substr($abs, strlen($basedir) + 1);
                    return rtrim($baseurl, '/') . '/' . ltrim($rel, '/');
                }
            }
        }

        // Fallback: map from DOCUMENT_ROOT
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $host    = $_SERVER['HTTP_HOST'] ?? null;

        if ($docRoot && $host) {
            $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
            $abs     = str_replace('\\', '/', $absolutePath);

            if (function_exists('str_starts_with') && str_starts_with($abs, $docRoot . '/')) {
                $rel = substr($abs, strlen($docRoot));
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                return $scheme . '://' . $host . $rel;
            }

            // PHP < 8 fallback
            if (!function_exists('str_starts_with') && strpos($abs, $docRoot . '/') === 0) {
                $rel = substr($abs, strlen($docRoot));
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                return $scheme . '://' . $host . $rel;
            }
        }

        return null;
    }

    /**
     * Resize any input image binary to a NxN PNG (letterboxed, centered).
     * Requires PHP GD extension.
     */
    private function resizeToSquarePng(?string $srcBinary, int $size = 600): string
    {
        if ($srcBinary === null || $srcBinary === '') {
            throw new Exception('Empty image binary received from model.');
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new Exception('GD extension is required (imagecreatefromstring not found).');
        }

        $src = @imagecreatefromstring($srcBinary);
        if (!$src) {
            throw new Exception('Failed to decode image binary (GD).');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            throw new Exception('Invalid source image size.');
        }

        // Destination canvas NxN with transparent background
        $dst = imagecreatetruecolor($size, $size);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        // Keep aspect ratio, contain
        $scale = min($size / $srcW, $size / $srcH);
        $newW  = (int) round($srcW * $scale);
        $newH  = (int) round($srcH * $scale);

        $dstX  = (int) floor(($size - $newW) / 2);
        $dstY  = (int) floor(($size - $newH) / 2);

        imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);

        ob_start();
        imagepng($dst);
        $png = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if ($png === '') {
            throw new Exception('Failed to encode PNG.');
        }

        return $png;
    }

    /**
     * Decode binary image and re-encode to PNG without resizing.
     * Returns png bytes + original dimensions.
     */
    private function reencodeToPng(string $srcBinary): array
    {
        if ($srcBinary === '') {
            throw new Exception('Empty image binary.');
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new Exception('GD extension is required.');
        }

        $src = @imagecreatefromstring($srcBinary);
        if (!$src) {
            throw new Exception('Failed to decode image binary (GD).');
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // Preserve alpha
        imagesavealpha($src, true);

        ob_start();
        imagepng($src);
        $png = (string) ob_get_clean();

        imagedestroy($src);

        if ($png === '') {
            throw new Exception('Failed to encode PNG.');
        }

        return [
            'png'    => $png,
            'width'  => (int) $w,
            'height' => (int) $h,
        ];
    }
}
