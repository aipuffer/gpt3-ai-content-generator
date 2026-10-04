<?php

namespace WPAICG\Chat\Frontend\Ajax;

use WPAICG\Utils\AIPKit_CORS_Manager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Returns a fresh nonce for the frontend chat AJAX/SSE handlers.
 *
 * This endpoint is intentionally available to unauthenticated users because the
 * nonce is required for subsequent security checks on other actions and does not
 * grant privileges on its own.
 *
 * It sets CORS headers (when relevant) and sends no-cache headers to avoid proxy caching.
 */
function ajax_get_frontend_chat_nonce_logic(): void
{
    // This endpoint intentionally issues a nonce for unauthenticated frontend users.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- A fresh nonce is the purpose of this endpoint; the request data only scopes CORS checks.
    $post_data = wp_unslash($_POST);

    // Handle preflight OPTIONS
    AIPKit_CORS_Manager::handle_preflight_request();

    // Optionally honor embed domain restrictions if a bot_id is posted
    $bot_id = 0;
    if (isset($post_data['bot_id'])) {
        $bot_id = absint($post_data['bot_id']);
    }

    if ($bot_id > 0) {
        // Only enforce CORS for true cross-origin
        $is_cross_origin = AIPKit_CORS_Manager::is_cross_origin();

        if ($is_cross_origin) {
            // Respect embed settings (plan + allowed domains) via the central CORS manager
            $origin_allowed = AIPKit_CORS_Manager::check_and_set_cors_headers($bot_id);
            if (!$origin_allowed) {
                wp_send_json_error([
                    'message' => __('This domain is not permitted to access the chatbot.', 'gpt3-ai-content-generator'),
                    'code'    => 'cors_denied'
                ], 403);
                return;
            }
        }
    }

    // Prevent caching of the nonce response anywhere
    nocache_headers();

    $nonce = wp_create_nonce('aipkit_frontend_chat_nonce');
    wp_send_json_success(['nonce' => $nonce]);
}
