<?php

namespace WPAICG\Core;

use WPAICG\Chat\Core\AIPKit_Content_Aware;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AIPKit_Instruction_Manager
 *
 * Centralized class for building and modifying AI system instructions.
 * Handles base instructions, date replacement, content aware context, and vector search results.
 */
class AIPKit_Instruction_Manager {

    /**
     * Builds the final system instruction string based on provided context.
     *
     * @param array $context Associative array containing context data.
     *                      Expected keys:
     *                      - 'base_instructions': (string) The user-defined base instructions.
     *                      Optional keys:
     *                      - 'bot_settings': (array) Settings of the specific bot (used for 'content_aware_enabled').
     *                      - 'post_id': (int) ID of the current post (passed from frontend).
     *                      - 'vector_search_results': (string) Pre-formatted string of vector search results.
     * @return string The fully constructed system instruction string.
     */
    public static function build_instructions(array $context = []): string {
        $base_instructions     = isset($context['base_instructions']) ? trim($context['base_instructions']) : '';
        $bot_settings          = $context['bot_settings'] ?? [];
        $post_id               = $context['post_id'] ?? 0;
        $vector_search_results = $context['vector_search_results'] ?? null;
        $content_aware_enabled = isset($bot_settings['content_aware_enabled']) && $bot_settings['content_aware_enabled'] === '1';

        $prepended_context = '';

        if ($content_aware_enabled && $post_id > 0) {
            if (!class_exists(AIPKit_Content_Aware::class)) {
                $content_aware_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/content-context.php';
                if (file_exists($content_aware_path)) {
                    require_once $content_aware_path;
                }
            }

            if (class_exists(AIPKit_Content_Aware::class)) {
                $content_snippet = AIPKit_Content_Aware::get_content_snippet($post_id);
                if ($content_snippet !== null) {
                    $prepended_context .= $content_snippet . "\n\n"; // Add snippet and spacing
                }
            }
        }

        if (!empty($vector_search_results) && is_string($vector_search_results)) {
             $prepended_context .= "## Relevant information from knowledge base:\n" . trim($vector_search_results) . "\n##\n\n";
        }

        // Process base instructions (date placeholder, default text)
        $processed_base = self::process_base_instructions($base_instructions);

        // Combine prepended context and processed base instructions
        $final_instructions = trim($prepended_context . $processed_base);

        // Apply a final filter allowing modification of the complete instruction set
        $final_instructions = apply_filters('aipkit_final_system_instruction', $final_instructions, $context);

        return trim($final_instructions);
    }

    /**
     * Processes the base instructions (e.g., replaces placeholders).
     *
     * @param string $instructions_raw The raw base instructions.
     * @return string Processed base instructions.
     */
    private static function process_base_instructions(string $instructions_raw): string {
        if (empty($instructions_raw)) {
            // Provide a minimal default if base instructions are empty
            $instructions_raw = 'You are a helpful AI Assistant.';
        }

        // Replace [date] placeholder
        if (strpos($instructions_raw, '[date]') !== false) {
            // Use wp_date for timezone awareness
             $current_date = wp_date(get_option('date_format', 'F j, Y'));
             $instructions_raw = str_replace('[date]', $current_date, $instructions_raw);
        }

        // Replace [username] placeholder with the logged-in user's username (user_login)
        if (strpos($instructions_raw, '[username]') !== false) {
            $username = '';

            if (function_exists('wp_get_current_user')) {
                $user = wp_get_current_user();
                if ($user && $user->exists()) {
                    // Use the WordPress username (login). Developers can filter this if they prefer display_name, etc.
                    $username = $user->user_login ?: '';
                }
            }

            /**
             * Filter the value used to replace the [username] placeholder in system instructions.
             *
             * @since 1.0.0
             * @param string $username The derived username (defaults to WP user_login or empty string if not logged-in).
             */
            $username = apply_filters('aipkit_instruction_username', $username);

            $instructions_raw = str_replace('[username]', $username, $instructions_raw);
        }

        return $instructions_raw;
    }
} // End Class
