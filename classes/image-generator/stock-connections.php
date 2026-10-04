<?php

namespace WPAICG\Images;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs a lightweight, real search to verify stock-photo provider credentials.
 */
final class AIPKit_Stock_Photo_Connection_Tester
{
    /**
     * @var array<string, string>
     */
    private const PROVIDERS = [
        'pexels' => 'Pexels',
        'pixabay' => 'Pixabay',
    ];

    public static function get_provider_name(string $provider_slug): string
    {
        return self::PROVIDERS[sanitize_key($provider_slug)] ?? '';
    }

    /**
     * @return array{provider: string, provider_name: string, image_count: int}|WP_Error
     */
    public function test(string $provider_slug, string $api_key)
    {
        $provider_slug = sanitize_key($provider_slug);
        $provider_name = self::get_provider_name($provider_slug);
        $api_key = trim($api_key);

        if ($provider_name === '') {
            return new WP_Error(
                'unsupported_stock_photo_provider',
                __('Unsupported stock photo provider.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        if ($api_key === '') {
            return new WP_Error(
                'missing_stock_photo_api_key',
                sprintf(
                    /* translators: %s: Stock photo provider name. */
                    __('Enter a %s API key before connecting.', 'gpt3-ai-content-generator'),
                    $provider_name
                ),
                ['status' => 400]
            );
        }

        $strategy = AIPKit_Image_Provider_Strategy_Factory::get_strategy($provider_name);
        if (is_wp_error($strategy)) {
            return $strategy;
        }

        $result = $strategy->generate_image(
            'nature',
            ['api_key' => $api_key],
            ['n' => 1]
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $images = isset($result['images']) && is_array($result['images'])
            ? $result['images']
            : [];
        if (empty($images)) {
            return new WP_Error(
                'stock_photo_connection_empty_response',
                sprintf(
                    /* translators: %s: Stock photo provider name. */
                    __('%s accepted the request but returned no test image. Please try again.', 'gpt3-ai-content-generator'),
                    $provider_name
                ),
                ['status' => 502]
            );
        }

        return [
            'provider' => $provider_slug,
            'provider_name' => $provider_name,
            'image_count' => count($images),
        ];
    }
}
