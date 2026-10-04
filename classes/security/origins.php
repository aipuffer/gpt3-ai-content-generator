<?php

namespace WPAICG\Utils;

use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Lib\Chat\EmbedCors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared request-origin recognition and optional paid embed integration.
 */
class AIPKit_CORS_Manager
{
    public static function init(): void
    {
        if (class_exists(EmbedCors::class)) {
            EmbedCors::register();
        }
    }

    public static function handle_ajax_cors(): void
    {
        if (class_exists(EmbedCors::class)) {
            EmbedCors::handle_ajax_cors();
        }
    }

    public static function request_origin(): string
    {
        return isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash((string) $_SERVER['HTTP_ORIGIN'])) : '';
    }

    /**
     * Preserve the existing host/scheme comparison used by frontend handlers.
     */
    public static function is_cross_origin(?string $request_origin = null): bool
    {
        if ($request_origin === null && empty($_SERVER['HTTP_ORIGIN'])) {
            return false;
        }
        $request_origin = $request_origin ?? self::request_origin();
        if (empty($request_origin)) {
            return false;
        }

        $site_parsed = wp_parse_url(get_site_url());
        $origin_parsed = wp_parse_url($request_origin);

        return $origin_parsed && $site_parsed && (
            ($origin_parsed['host'] ?? '') !== ($site_parsed['host'] ?? '') ||
            ($origin_parsed['scheme'] ?? 'http') !== ($site_parsed['scheme'] ?? 'http')
        );
    }

    public static function check_and_set_cors_headers(int $bot_id, ?BotStorage $bot_storage = null): bool
    {
        $request_origin = self::request_origin();
        if (!self::is_cross_origin($request_origin)) {
            return true;
        }

        return class_exists(EmbedCors::class) && EmbedCors::check_origin($bot_id, $request_origin, $bot_storage);
    }

    public static function set_cors_headers(string $allowed_origin = '*'): void
    {
        if (class_exists(EmbedCors::class) && EmbedCors::is_available()) {
            EmbedCors::set_cors_headers($allowed_origin);
        }
    }

    public static function handle_preflight_request(): void
    {
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : '';
        if ($request_method === 'OPTIONS') {
            self::set_cors_headers();
            status_header(200);
            exit;
        }
    }
}
