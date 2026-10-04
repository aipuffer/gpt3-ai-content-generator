<?php

namespace WPAICG\AIForms\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AI Form uploads and saving generated content as draft posts.
 */
class AIPKit_AI_Form_Processor
{
    public $ai_caller;
    public $form_storage;

    public function __construct()
    {
        if (class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $this->ai_caller = new \WPAICG\Core\AIPKit_AI_Caller();
        }
        if (class_exists(\WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage::class)) {
            $this->form_storage = new \WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage();
        }
    }

    /**
    * AJAX handler for uploading a file from an AI Form, parsing it, and returning its text content.
    * This is a Pro feature.
    * @since 2.1
    */
    public function ajax_upload_and_parse_file()
    {
        Ajax\upload_and_parse_file_logic($this);
    }

    /**
     * AJAX handler for saving generated form content as a post.
     * @since 2.1
     */
    public function ajax_save_as_post()
    {
        Ajax\save_as_post_logic($this);
    }
}

namespace WPAICG\AIForms\Core\Ajax;

use WPAICG\AIForms\Core\AIPKit_AI_Form_Processor;

/**
 * Logic for saving generated AI Form content as a new WordPress post.
 * Called by AIPKit_AI_Form_Processor::ajax_save_as_post().
 *
 * @param AIPKit_AI_Form_Processor $processorInstance The instance of the processor class.
 * @return void
 */
function save_as_post_logic(AIPKit_AI_Form_Processor $processorInstance): void
{
    // 1. Security & Permission Checks
    check_ajax_referer('aipkit_ai_form_save_as_post_nonce', '_ajax_nonce');
    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_send_json_error(['message' => __('You do not have permission to create posts.', 'gpt3-ai-content-generator')], 403);
        return;
    }

    // 2. Get and Sanitize Data
    $post_title   = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : 'AI Form Result';
    $post_content = isset($_POST['post_content']) ? wp_kses_post(wp_unslash($_POST['post_content'])) : '';

    if (empty($post_title)) {
        wp_send_json_error(['message' => __('Post title cannot be empty.', 'gpt3-ai-content-generator')], 400);
        return;
    }
    if (empty($post_content)) {
        wp_send_json_error(['message' => __('Post content cannot be empty.', 'gpt3-ai-content-generator')], 400);
        return;
    }

    // 3. Create the post
    $postarr = [
        'post_title'   => $post_title,
        'post_content' => $post_content,
        'post_status'  => 'draft',
        'post_author'  => get_current_user_id(),
    ];

    $post_id = wp_insert_post($postarr, true);

    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => __('Failed to save post:', 'gpt3-ai-content-generator') . ' ' . $post_id->get_error_message()], 500);
        return;
    }

    // 4. Send Success Response
    wp_send_json_success([
        'message' => __('Post saved as draft!', 'gpt3-ai-content-generator'),
        'post_id' => $post_id,
        'edit_link' => get_edit_post_link($post_id, 'raw'),
    ]);
}

/** Routes a nonce-verified upload to the paid processor when available. */
function upload_and_parse_file_logic(AIPKit_AI_Form_Processor $processorInstance): void
{
    check_ajax_referer('aipkit_ai_form_upload_nonce', '_ajax_nonce');

    if (class_exists('\\WPAICG\\Lib\\AIForms\\Processor')) {
        \WPAICG\Lib\AIForms\Processor::upload_and_parse_file();
        return;
    }

    wp_send_json_error(['message' => __('This is a Pro feature.', 'gpt3-ai-content-generator')], 403);
}

namespace WPAICG\AIForms\Core\Pricing;

use WPAICG\Utils\AIPKit_Prompt_Sanitizer;

if (!function_exists(__NAMESPACE__ . '\\build_ai_form_pricing_check_context_logic')) {
    /**
     * @param array<string, mixed> $form_config
     * @param array<string, mixed> $submitted_fields
     * @return array<string, mixed>
     */
    function build_ai_form_pricing_check_context_logic(
        int $form_id,
        array $form_config,
        array $submitted_fields = [],
        ?array $image_inputs = null
    ): array {
        $provider = sanitize_text_field((string) ($form_config['ai_provider'] ?? 'OpenAI'));
        $model = sanitize_text_field((string) ($form_config['ai_model'] ?? ''));
        $prompt_template = AIPKit_Prompt_Sanitizer::sanitize($form_config['prompt_template'] ?? '');
        $input_parts = [];

        if ($prompt_template !== '') {
            $input_parts[] = $prompt_template;
        }

        foreach ($submitted_fields as $field_key => $field_value) {
            if (in_array($field_key, ['ai_provider', 'ai_model'], true)) {
                continue;
            }

            if (is_array($field_value) || is_object($field_value)) {
                $field_value = wp_json_encode($field_value);
            }

            $field_value = trim((string) $field_value);
            if ($field_value === '') {
                continue;
            }

            $input_parts[] = sanitize_text_field((string) $field_key) . ': ' . $field_value;
        }

        $input_tokens = estimate_ai_form_text_tokens_logic(implode("\n", $input_parts));
        if (!empty($image_inputs)) {
            $input_tokens += count($image_inputs) * 768;
        }
        $output_tokens = isset($form_config['max_tokens']) ? absint($form_config['max_tokens']) : 0;
        if ($output_tokens <= 0) {
            $output_tokens = 512;
        }

        $total_tokens = max(1, $input_tokens + $output_tokens);

        return [
            'provider' => $provider,
            'model' => $model,
            'operation' => 'form_submit',
            'pricing_scope_type' => 'ai_form',
            'pricing_scope_id' => $form_id > 0 ? $form_id : null,
            'usage_data' => [
                'input_tokens' => $input_tokens,
                'output_tokens' => $output_tokens,
                'total_tokens' => $total_tokens,
            ],
            'fallback_units' => $total_tokens,
        ];
    }

    function estimate_ai_form_text_tokens_logic(string $text): int
    {
        $text = AIPKit_Prompt_Sanitizer::sanitize($text);
        if ($text === '') {
            return 0;
        }

        $character_count = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);

        return max(1, (int) ceil($character_count / 4));
    }
}
