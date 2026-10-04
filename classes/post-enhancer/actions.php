<?php

namespace WPAICG\PostEnhancer\Ajax\Base;

use WP_Error;
use WPAICG\AIPKit_Role_Manager;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/ai/http.php';

abstract class AIPKit_Post_Enhancer_Base_Ajax_Action
{
    public const CONTENT_WRITER_MODULE = 'content-writer';
    public const BULK_ASSISTANT_MODULE = 'bulk_assistant';
    public const ROW_ASSISTANT_MODULE = 'row_assistant';
    public const CLASSIC_EDITOR_ASSISTANT_MODULE = 'classic_editor_assistant';
    public const BLOCK_EDITOR_ASSISTANT_MODULE = 'block_editor_assistant';

    abstract public function handle(): void;

    /**
     * @param string|mixed[] $module_slugs
     * @return bool|\WP_Error
     */
    protected function check_permissions(string $nonce_action, $module_slugs = [])
    {
        if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_key($_POST['_ajax_nonce']), $nonce_action)) {
            return new WP_Error('nonce_failure', __('Security check failed.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        $module_slugs = array_filter(array_map('sanitize_key', (array) $module_slugs));
        if (!empty($module_slugs) && !AIPKit_Role_Manager::user_can_access_any_module($module_slugs)) {
            return new WP_Error('permission_denied_module', __('You do not have permission to use this feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        return true;
    }

    /**
     * @return bool|\WP_Error
     */
    protected function check_row_assistant_permissions(\WP_Post $post)
    {
        return $this->check_post_utility_permission($post, self::ROW_ASSISTANT_MODULE);
    }

    /**
     * @return bool|\WP_Error
     */
    protected function check_content_update_permissions(\WP_Post $post)
    {
        $source = $this->get_enhancer_source();
        if ($source === 'content_writer') {
            return AIPKit_Role_Manager::user_can_access_module(self::CONTENT_WRITER_MODULE)
                ? true
                : new WP_Error('permission_denied_module', __('You do not have permission to use this feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        if ($source === 'post_enhancer') {
            return $this->check_post_utility_permission($post, self::BULK_ASSISTANT_MODULE);
        }

        return new WP_Error('invalid_enhancer_source', __('Invalid assistant request source.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    /**
     * @return bool|\WP_Error
     */
    protected function check_editor_context_permissions()
    {
        $context = $this->get_editor_context();
        if ($context === 'classic') {
            $module_slug = self::CLASSIC_EDITOR_ASSISTANT_MODULE;
        } elseif ($context === 'block') {
            $module_slug = self::BLOCK_EDITOR_ASSISTANT_MODULE;
        } else {
            return new WP_Error('invalid_editor_context', __('Invalid editor context.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        return AIPKit_Role_Manager::user_can_access_module($module_slug)
            ? true
            : new WP_Error('permission_denied_module', __('You do not have permission to use this feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }

    /**
     * @return bool|\WP_Error
     */
    private function check_post_utility_permission(\WP_Post $post, string $non_product_module_slug)
    {
        return AIPKit_Role_Manager::user_can_access_module($non_product_module_slug)
            ? true
            : new WP_Error('permission_denied_module', __('You do not have permission to use this feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }

    private function get_enhancer_source(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_permissions.
        return isset($_POST['enhancer_source']) ? sanitize_key(wp_unslash($_POST['enhancer_source'])) : '';
    }

    private function get_editor_context(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_permissions.
        return isset($_POST['editor_context']) ? sanitize_key(wp_unslash($_POST['editor_context'])) : '';
    }

    /**
     * @return \WP_Post|\WP_Error
     */
    protected function get_post()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_permissions.
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) {
            return new WP_Error('missing_post_id', __('Missing post ID.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found.', 'gpt3-ai-content-generator'), ['status' => 404]);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('permission_denied_post', __('You do not have permission to edit this post.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        return $post;
    }

    public static function request_error_data(WP_Error $error, int $default_status = 400): array
    {
        $data = \WPAICG\Core\AIPKit_HTTP_Request::public_error_data($error, $default_status);
        $id = $data['cloud_operation_id'] ?? '';
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
            // The status capability belongs to the user who received this operation's error.
            $data['request_status_nonce'] = wp_create_nonce('aipkit_enhancer_status_' . $id);
        }
        return $data;
    }

    protected function send_error_response(WP_Error $error): void
    {
        $data = self::request_error_data($error);
        $data['message'] = $error->get_error_message();
        if ($error->get_error_code() === 'post_update_failed') {
            $source = $error->get_error_data();
            $data['generated_value'] = (string) ($source['generated_value'] ?? '');
        }
        wp_send_json_error($data, $data['status']);
    }
}

namespace WPAICG\PostEnhancer\Ajax\Base;

use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\SEO\AIPKit_SEO_Helper;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This file intentionally uses core WordPress hook names.

function get_post_content_snippet_logic(\WP_Post $post, int $length = 500): string
{
    $content_raw = $post->post_content;
    $content_trimmed = wp_strip_all_tags($content_raw);
    $content_trimmed = mb_substr($content_trimmed, 0, $length);
    return trim($content_trimmed);
}

/**
 * Gets the full, clean text content of a post.
 * @param \WP_Post $post The post object.
 * @return string The clean text content.
 */
function get_post_full_content(\WP_Post $post): string
{
    $content = $post->post_content;
    $content = apply_filters('the_content', $content);
    $content = wp_strip_all_tags($content, true);
    $content = strip_shortcodes($content);
    $content = preg_replace('/\s+/', ' ', $content);
    return trim($content);
}

/**
 * Returns the value the generated suggestions would replace.
 */
function get_current_suggestion_value_logic(string $type, \WP_Post $post): string
{
    switch ($type) {
        case 'title':
            return wp_strip_all_tags((string) $post->post_title);
        case 'excerpt':
            return wp_strip_all_tags((string) $post->post_excerpt);
        case 'meta':
            return AIPKit_SEO_Helper::get_meta_description($post->ID);
        case 'tags':
            return AIPKit_SEO_Helper::get_tags_as_string($post->ID);
        default:
            return '';
    }
}

function generate_suggestions_logic(string $type, \WP_Post $post, string $final_prompt): void
{
    $global_config = AIPKit_Providers::get_new_text_generation_selection();
    $ai_params = AIPKIT_AI_Settings::get_ai_parameters();
    $provider = $global_config['provider'];
    $model = $global_config['model'];

    $ai_caller = new AIPKit_AI_Caller(true, 'ai_post_enhancer');
    $messages = [['role' => 'user', 'content' => $final_prompt]];

    $result = $ai_caller->make_standard_call(
        $provider,
        $model,
        $messages,
        $ai_params,
        null,
        ['post_id' => $post->ID]
    );

    $suggestions_raw = '';
    $usage_data = null;
    $request_payload_log = null;

    if (is_wp_error($result)) {
        $error_message = 'AI Error: ' . $result->get_error_message();
        $error_data = $result->get_error_data() ?? [];
        $request_payload_log = $error_data['request_payload'] ?? null;
        log_enhancer_interaction_logic(
            $post->ID,
            $type,
            $final_prompt,
            $error_message,
            $provider,
            $model,
            null,
            $request_payload_log
        );
        $public_error = AIPKit_Post_Enhancer_Base_Ajax_Action::request_error_data($result, 500);
        wp_send_json_error(array_merge($public_error, ['message' => $result->get_error_message()]), $public_error['status']);
        return;
    } else {
        $suggestions_raw = $result['content'] ?? '';
        $usage_data = $result['usage'] ?? null;
        $request_payload_log = $result['request_payload_log'] ?? null;
    }

    $suggestions = [];
    $lines = explode("\n", $suggestions_raw);
    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('/^[\d\*\-\.]+\s*/', '', $line);
        if (preg_match('/^"(.*)"$/', $line, $matches)) {
            $line = $matches[1];
        }
        if (!empty($line)) {
            $suggestions[] = $line;
        }
        if (count($suggestions) >= 5) {
            break;
        }
    }

    $log_content = empty($suggestions) ? "(No valid suggestions generated)" : implode("\n", $suggestions);
    log_enhancer_interaction_logic(
        $post->ID,
        $type,
        $final_prompt,
        $log_content,
        $provider,
        $model,
        $usage_data,
        $request_payload_log
    );

    if (empty($suggestions)) {
        /* translators: %s: The type of suggestions that were expected */
        wp_send_json_error(['message' => sprintf(__('AI did not generate any valid %s suggestions.', 'gpt3-ai-content-generator'), $type)], 500);
        return;
    }

    wp_send_json_success([
        'suggestions' => $suggestions,
        'current_value' => get_current_suggestion_value_logic($type, $post),
    ]);
}

function log_enhancer_interaction_logic(int $post_id, string $type, string $prompt, string $response_content, string $provider, string $model, ?array $usage, ?array $request_payload): void
{
    if (!class_exists(LogStorage::class)) {
        return;
    }
    $log_storage = new LogStorage();
    $user_id = get_current_user_id();
    $user_wp_role = $user_id ? implode(', ', wp_get_current_user()->roles) : null;
    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null;
    $conversation_uuid = 'enhancer-' . $type . '-' . $post_id . '-' . time();

    $log_data = [
        'bot_id'             => null,
        'user_id'            => $user_id ?: null,
        'session_id'         => null,
        'conversation_uuid'  => $conversation_uuid,
        'module'             => 'ai_post_enhancer',
        'is_guest'           => false,
        'role'               => $user_wp_role,
        'ip_address'         => $ip_address,
        'message_role'       => 'bot',
        'message_content'    => sprintf("Generated %s suggestions for Post ID: %d.\nPrompt Snippet: %s...\nResult:\n%s", $type, $post_id, mb_substr($prompt, 0, 100), $response_content),
        'timestamp'          => time(),
        'ai_provider'        => $provider,
        'ai_model'           => $model,
        'usage'              => $usage,
        'request_payload'    => $request_payload,
    ];

    $log_storage->log_message($log_data);

}

/**
 * Logs a bulk Post Enhancer update (single-field step) into the shared Admin Logs storage.
 * The content format is aligned with the Post Enhancer log renderer expectations:
 * it includes lines for "Post ID:", "Prompt Snippet:", and "Result:" so the UI can parse and display nicely.
 */
function log_enhancer_bulk_update_logic(int $post_id, string $field, string $prompt, string $response_content, string $provider, string $model, ?array $usage, ?array $request_payload, ?string $conversation_uuid_override = null): void
{
    if (!class_exists(LogStorage::class)) {
        return;
    }
    $log_storage = new LogStorage();
    $user_id = get_current_user_id();
    $user_wp_role = $user_id ? implode(', ', wp_get_current_user()->roles) : null;
    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null;
    // Use provided conversation UUID to aggregate all bulk step messages into one record
    $conversation_uuid = $conversation_uuid_override && is_string($conversation_uuid_override)
        ? substr(sanitize_key($conversation_uuid_override), 0, 36)
        : ('enhancer-bulk-' . $field . '-' . $post_id . '-' . time());

    $message_content = sprintf(
        "Assistant generated %s for Post ID: %d.\nPrompt Snippet: %s...\nResult:\n%s",
        $field,
        $post_id,
        mb_substr($prompt, 0, 100),
        $response_content
    );

    $log_data = [
        'bot_id'             => null,
        'user_id'            => $user_id ?: null,
        'session_id'         => null,
        'conversation_uuid'  => $conversation_uuid,
        'module'             => 'ai_post_enhancer',
        'is_guest'           => false,
        'role'               => $user_wp_role,
        'ip_address'         => $ip_address,
        'message_role'       => 'bot',
        'message_content'    => $message_content,
        'timestamp'          => time(),
        'ai_provider'        => $provider,
        'ai_model'           => $model,
        'usage'              => $usage,
        'request_payload'    => $request_payload,
    ];

    $log_storage->log_message($log_data);
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

namespace WPAICG\PostEnhancer\Ajax\Actions;

use WPAICG\PostEnhancer\Ajax\Base\AIPKit_Post_Enhancer_Base_Ajax_Action;
use function WPAICG\PostEnhancer\Ajax\Base\get_post_content_snippet_logic;
use function WPAICG\PostEnhancer\Ajax\Base\generate_suggestions_logic;
use WP_Error;
use WPAICG\SEO\AIPKit_SEO_Helper;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Prepare_Update_Run_Action;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Core\Stream\Vector as VectorContextBuilder;
use function WPAICG\PostEnhancer\Ajax\Base\get_post_full_content;
use function WPAICG\PostEnhancer\Ajax\Base\log_enhancer_bulk_update_logic;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;

class AIPKit_PostEnhancer_Generate_Title extends AIPKit_Post_Enhancer_Base_Ajax_Action {
    public function handle(): void {
        $permission_check = $this->check_permissions('aipkit_generate_title_nonce');
        if (is_wp_error($permission_check)) { $this->send_error_response($permission_check); return; }

        $post = $this->get_post();
        if (is_wp_error($post)) { $this->send_error_response($post); return; }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) { $this->send_error_response($feature_permission); return; }

        $original_title = trim($post->post_title);
        $post_content_snippet = get_post_content_snippet_logic($post);

        $prompt_template = 'Generate exactly 5 alternative titles for a blog post based on the following information.' . "\n" .
                           'Return ONLY the 5 titles, each on a new line.' . "\n" .
                           'Do NOT include any introduction, explanation, numbering, or markdown formatting (like **).' . "\n\n" .
                           'Original title: "{title}"' . "\n" .
                           'Post content snippet: "{content}"';

        $prompt = str_replace(['{title}', '{content}'], [$original_title, $post_content_snippet], $prompt_template);
        $final_prompt = apply_filters('aipkit_post_enhancer_title_prompt', $prompt, $post->ID);

        generate_suggestions_logic('title', $post, $final_prompt);
    }
}

class AIPKit_PostEnhancer_Generate_Excerpt extends AIPKit_Post_Enhancer_Base_Ajax_Action {
    public function handle(): void {
        $permission_check = $this->check_permissions('aipkit_generate_excerpt_nonce');
        if (is_wp_error($permission_check)) { $this->send_error_response($permission_check); return; }

        $post = $this->get_post();
        if (is_wp_error($post)) { $this->send_error_response($post); return; }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) { $this->send_error_response($feature_permission); return; }

        $original_title = trim($post->post_title);
        $post_content_snippet = get_post_content_snippet_logic($post, 800);

        $prompt_template = 'Generate exactly 5 short, compelling excerpt suggestions (about 1-2 sentences each) for a blog post based on the following information.' . "\n" .
                           'Return ONLY the 5 excerpts, each on a new line.' . "\n" .
                           'Do NOT include any introduction, explanation, numbering, or markdown formatting (like **).' . "\n\n" .
                           'Post title: "{title}"' . "\n" .
                           'Post content snippet: "{content}"';

        $prompt = str_replace(['{title}', '{content}'], [$original_title, $post_content_snippet], $prompt_template);
        $final_prompt = apply_filters('aipkit_post_enhancer_excerpt_prompt', $prompt, $post->ID);

        generate_suggestions_logic('excerpt', $post, $final_prompt);
    }
}

class AIPKit_PostEnhancer_Generate_Meta extends AIPKit_Post_Enhancer_Base_Ajax_Action {
    public function handle(): void {
        $permission_check = $this->check_permissions('aipkit_generate_meta_nonce');
        if (is_wp_error($permission_check)) { $this->send_error_response($permission_check); return; }

        $post = $this->get_post();
        if (is_wp_error($post)) { $this->send_error_response($post); return; }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) { $this->send_error_response($feature_permission); return; }

        $original_title = trim($post->post_title);
        $post_content_snippet = get_post_content_snippet_logic($post, 800);

        $prompt_template = 'Generate exactly 5 concise and SEO-friendly meta description suggestions (under 156 characters each) for a web page based on the provided information.' . "\n" .
                           'Return ONLY the 5 meta descriptions, each on a new line.' . "\n" .
                           'Do NOT include any introduction, explanation, numbering, markdown formatting (like **), or surrounding quotes.' . "\n\n" .
                           'Page title: "{title}"' . "\n" .
                           'Page content snippet: "{content}"';

        $prompt = str_replace(['{title}', '{content}'], [$original_title, $post_content_snippet], $prompt_template);
        $final_prompt = apply_filters('aipkit_post_enhancer_meta_prompt', $prompt, $post->ID);

        generate_suggestions_logic('meta', $post, $final_prompt);
    }
}

class AIPKit_PostEnhancer_Generate_Tags extends AIPKit_Post_Enhancer_Base_Ajax_Action
{
    public function handle(): void
    {
        $permission_check = $this->check_permissions('aipkit_generate_tags_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_error_response($permission_check);
            return;
        }

        $post = $this->get_post();
        if (is_wp_error($post)) {
            $this->send_error_response($post);
            return;
        }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) {
            $this->send_error_response($feature_permission);
            return;
        }

        $original_title = trim($post->post_title);
        $post_content_snippet = get_post_content_snippet_logic($post);

        $prompt_template = 'Generate exactly 5 suggestions for a comma-separated list of tags for a blog post based on the following information.' . "\n" .
                           'Each suggestion should be on a new line. Each suggestion should contain 5-10 relevant tags.' . "\n" .
                           'Return ONLY the 5 comma-separated lists.' . "\n" .
                           'Do NOT include any introduction, explanation, numbering, or markdown formatting (like **).' . "\n\n" .
                           'Original title: "{title}"' . "\n" .
                           'Post content snippet: "{content}"';

        $prompt = str_replace(['{title}', '{content}'], [$original_title, $post_content_snippet], $prompt_template);
        $final_prompt = apply_filters('aipkit_post_enhancer_tags_prompt', $prompt, $post->ID);

        generate_suggestions_logic('tags', $post, $final_prompt);
    }
}

class AIPKit_PostEnhancer_Update_Title extends AIPKit_Post_Enhancer_Base_Ajax_Action
{
    public function handle(): void
    {
        $permission_check = $this->check_permissions('aipkit_update_title_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_error_response($permission_check);
            return;
        }

        $post = $this->get_post();
        if (is_wp_error($post)) {
            $this->send_error_response($post);
            return;
        }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) {
            $this->send_error_response($feature_permission);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_permissions.
        $new_title = isset($_POST['new_value']) ? sanitize_text_field(wp_unslash($_POST['new_value'])) : '';
        if (empty($new_title)) {
            $this->send_error_response(new WP_Error('empty_title', __('New title cannot be empty.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        $update_result = wp_update_post(['ID' => $post->ID, 'post_title' => $new_title], true);
        if (is_wp_error($update_result)) {
            wp_send_json_error(['message' => 'Failed to update post title: ' . $update_result->get_error_message()], 500);
        } else {
            wp_send_json_success(['message' => __('Post title updated successfully.', 'gpt3-ai-content-generator')]);
        }
    }
}

class AIPKit_PostEnhancer_Update_Excerpt extends AIPKit_Post_Enhancer_Base_Ajax_Action {
    public function handle(): void {
        $permission_check = $this->check_permissions('aipkit_update_excerpt_nonce');
        if (is_wp_error($permission_check)) { $this->send_error_response($permission_check); return; }

        $post = $this->get_post();
        if (is_wp_error($post)) { $this->send_error_response($post); return; }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) { $this->send_error_response($feature_permission); return; }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in the base class.
        $new_excerpt = isset($_POST['new_value']) ? wp_kses_post(wp_unslash($_POST['new_value'])) : '';

        $update_result = wp_update_post(['ID' => $post->ID, 'post_excerpt' => $new_excerpt], true);
        if (is_wp_error($update_result)) {
            wp_send_json_error(['message' => 'Failed to update post excerpt: ' . $update_result->get_error_message()], 500);
        } else {
            wp_send_json_success(['message' => __('Post excerpt updated successfully.', 'gpt3-ai-content-generator')]);
        }
    }
}

class AIPKit_PostEnhancer_Update_Meta extends AIPKit_Post_Enhancer_Base_Ajax_Action {
    public function handle(): void {
        $permission_check = $this->check_permissions('aipkit_update_meta_nonce');
        if (is_wp_error($permission_check)) { $this->send_error_response($permission_check); return; }

        $post = $this->get_post();
        if (is_wp_error($post)) { $this->send_error_response($post); return; }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) { $this->send_error_response($feature_permission); return; }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_permissions.
        $new_meta_desc = isset($_POST['new_value']) ? sanitize_text_field(wp_unslash($_POST['new_value'])) : '';

        if (strlen($new_meta_desc) > 300) {
            $this->send_error_response(new WP_Error('meta_too_long', __('Meta description is too long.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        AIPKit_SEO_Helper::update_meta_description($post->ID, $new_meta_desc);
        wp_send_json_success(['message' => __('Meta description updated successfully.', 'gpt3-ai-content-generator')]);
    }
}

class AIPKit_PostEnhancer_Update_Tags extends AIPKit_Post_Enhancer_Base_Ajax_Action
{
    public function handle(): void
    {
        $permission_check = $this->check_permissions('aipkit_update_tags_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_error_response($permission_check);
            return;
        }

        $post = $this->get_post();
        if (is_wp_error($post)) {
            $this->send_error_response($post);
            return;
        }
        $feature_permission = $this->check_row_assistant_permissions($post);
        if (is_wp_error($feature_permission)) {
            $this->send_error_response($feature_permission);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_permissions.
        $new_tags = isset($_POST['new_value']) ? sanitize_text_field(wp_unslash($_POST['new_value'])) : '';

        // Use the SEO helper to correctly set tags for any post type
        $result = AIPKit_SEO_Helper::update_tags($post->ID, $new_tags);

        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to update post tags.'], 500);
        } else {
            wp_send_json_success(['message' => __('Post tags updated successfully.', 'gpt3-ai-content-generator')]);
        }
    }
}

if (!class_exists(AIPKit_Content_Writer_Output_Cleaner::class)) {
    $aipkit_output_cleaner_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/output.php';
    if (file_exists($aipkit_output_cleaner_path)) {
        require_once $aipkit_output_cleaner_path;
    }
}


$aipkit_vector_logic_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/retrieval-context.php';
if (file_exists($aipkit_vector_logic_path)) {
    require_once $aipkit_vector_logic_path;
}

require_once dirname(__DIR__) . '/image-generator/storage.php';

/**
 * AJAX handler for processing a single field of a post during bulk enhancement.
 */
class AIPKit_PostEnhancer_Bulk_Process_Single_Field extends AIPKit_Post_Enhancer_Base_Ajax_Action
{
    use \WPAICG\Images\AIPKit_Image_Input_Trait;

    public function handle(): void
    {
        $permission_check = $this->check_permissions('aipkit_generate_title_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_error_response($permission_check);
            return;
        }

        $post = $this->get_post();
        if (is_wp_error($post)) {
            $this->send_error_response($post);
            return;
        }
        $feature_permission = $this->check_content_update_permissions($post);
        if (is_wp_error($feature_permission)) {
            $this->send_error_response($feature_permission);
            return;
        }

    // Optional: a conversation UUID to aggregate all steps into one log record
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reason: Nonce is checked in check_permissions.
    $conv_uuid = isset($_POST['conversation_uuid']) ? sanitize_key(wp_unslash($_POST['conversation_uuid'])) : null;

        // Get the specific field to process
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reason: Nonce is checked in check_permissions.
        $field = isset($_POST['field']) ? sanitize_text_field(wp_unslash($_POST['field'])) : '';
        if (empty($field)) {
            $this->send_error_response(new WP_Error('missing_field', __('No field specified for processing.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        $is_pro = class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reason: Nonce is checked in check_permissions.
        $enhancer_source = isset($_POST['enhancer_source']) ? sanitize_key(wp_unslash($_POST['enhancer_source'])) : '';
        $bypass_pro_checks = ($enhancer_source === 'post_enhancer');

        if ($enhancer_source === 'content_writer') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request nonce is checked at the start of handle().
            $run_token = isset($_POST['content_writer_run_token']) ? sanitize_text_field(wp_unslash($_POST['content_writer_run_token'])) : '';
            if (!class_exists(AIPKit_Content_Writer_Prepare_Update_Run_Action::class)) {
                $this->send_error_response(new WP_Error('update_run_guard_missing', __('The update service is unavailable. Please reload the page.', 'gpt3-ai-content-generator'), ['status' => 500]));
                return;
            }
            $run_permission = AIPKit_Content_Writer_Prepare_Update_Run_Action::validate_request($run_token, (int) $post->ID, $field);
            if (is_wp_error($run_permission)) {
                $this->send_error_response($run_permission);
                return;
            }
        }

        if (!$is_pro && !$bypass_pro_checks) {
            if ($post->post_type === 'product') {
                $content_writer_mode = is_array($run_permission ?? null)
                    ? sanitize_key((string) ($run_permission['mode'] ?? ''))
                    : '';
                $content_writer_item_count = is_array($run_permission ?? null)
                    ? count($run_permission['post_ids'] ?? [])
                    : 0;
                $is_allowed_single_rewrite = $enhancer_source === 'content_writer'
                    && $content_writer_mode === 'existing-content'
                    && $content_writer_item_count === 1;
                if (!$is_allowed_single_rewrite) {
                    $this->send_error_response(new WP_Error('pro_required', __('Product optimization is a Pro feature.', 'gpt3-ai-content-generator'), ['status' => 403]));
                    return;
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reason: Nonce is checked in check_permissions.
        $item_config_json = isset($_POST['enhancements']) ? wp_unslash($_POST['enhancements']) : '{}';
        $item_config = json_decode($item_config_json, true);

        if (empty($item_config) || !is_array($item_config) || empty($item_config[$field]['prompt'])) {
            $this->send_error_response(new WP_Error('invalid_field_config', __('Invalid configuration for the specified field.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        // AI setup
        $ai_caller = new AIPKit_AI_Caller(true, $enhancer_source === 'content_writer' ? 'content_writer' : 'ai_post_enhancer');
        $global_config = AIPKit_Providers::get_new_text_generation_selection();
        $global_ai_params = AIPKIT_AI_Settings::get_ai_parameters();

        // Use AI config from the request, with fallback to globals
        $provider_raw = $item_config['ai_provider'] ?? $global_config['provider'];
        switch (strtolower($provider_raw)) {
            case 'openai':
                $provider = 'OpenAI';
                break;
            case 'openrouter':
                $provider = 'OpenRouter';
                break;
            case 'google':
                $provider = 'Google';
                break;
            case 'azure':
                $provider = 'Azure';
                break;
            case 'claude':
                $provider = 'Claude';
                break;
            case 'deepseek':
                $provider = 'DeepSeek';
                break;
            case 'xai':
                $provider = 'xAI';
                break;
            case 'ollama':
                $provider = 'Ollama';
                break;
            default:
                $provider = \WPAICG\AIPKit_Providers::normalize_provider_label((string) $provider_raw);
                break;
        }
        $model = $item_config['ai_model'] ?? $global_config['model'];
        $ai_params = [
            'temperature' => isset($item_config['temperature']) ? floatval($item_config['temperature']) : ($global_ai_params['temperature'] ?? 1.0),
            'top_p' => isset($item_config['top_p']) ? floatval($item_config['top_p']) : ($global_ai_params['top_p'] ?? 1.0),
            'max_completion_tokens' => isset($item_config['max_tokens']) ? absint($item_config['max_tokens']) : ($global_ai_params['max_completion_tokens'] ?? 4000),
        ];
        if ($provider === 'OpenAI') {
            $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
                (string) $model,
                $item_config['reasoning_effort'] ?? ''
            );
            if ($reasoning_effort !== '') {
                $ai_params['reasoning'] = ['effort' => $reasoning_effort];
            }
        } elseif ($provider === 'AIPufferCloud') {
            $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) $model, $item_config['reasoning_effort'] ?? '');
            if ($reasoning_effort !== '') {
                $ai_params['reasoning'] = ['effort' => $reasoning_effort];
            }
        } elseif ($provider === 'OpenRouter') {
            $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
                (string) $model,
                $item_config['reasoning_effort'] ?? ''
            );
            if ($reasoning_effort !== '') {
                $ai_params['reasoning'] = ['effort' => $reasoning_effort];
            }
        }

        // Extract Vector Store Settings
        $vector_store_enabled = ($item_config['enable_vector_store'] ?? '0') === '1';
        $vector_store_provider = $item_config['vector_store_provider'] ?? null;
        $vector_store_top_k = isset($item_config['vector_store_top_k']) ? absint($item_config['vector_store_top_k']) : 3;
        $openai_vector_store_ids = $item_config['openai_vector_store_ids'] ?? [];
        $google_file_search_store_names = isset($item_config['google_file_search_store_names']) && is_array($item_config['google_file_search_store_names'])
            ? array_values(array_unique(array_filter(array_map(
                static function ($store_name): string {
                    $store_name = sanitize_text_field((string) $store_name);
                    return strpos($store_name, 'fileSearchStores/') === 0 ? $store_name : '';
                },
                $item_config['google_file_search_store_names']
            ))))
            : [];

        // Prepare OpenAI vector tools parameter if needed
        if ($vector_store_enabled && $provider === 'OpenAI' && $vector_store_provider === 'openai' && !empty($openai_vector_store_ids)) {
            $ai_params['vector_store_tool_config'] = [
                'type'             => 'file_search',
                'vector_store_ids' => $openai_vector_store_ids,
                'max_num_results'  => $vector_store_top_k,
            ];
        }
        if ($vector_store_enabled && $vector_store_provider === 'google') {
            if ($provider !== 'Google') {
                $this->send_error_response(new WP_Error(
                    'google_file_search_provider_mismatch',
                    __('Google File Search requires Google as the AI provider.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                ));
                return;
            }
            if (empty($google_file_search_store_names)) {
                $this->send_error_response(new WP_Error(
                    'google_file_search_store_required',
                    __('Select at least one Google store.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                ));
                return;
            }
            $ai_params['google_file_search_tool_config'] = [
                'file_search_store_names' => $google_file_search_store_names,
                'top_k' => $vector_store_top_k,
            ];
        }

        $system_instruction = 'You are an expert SEO copywriter. You follow instructions precisely. Your response must contain ONLY the generated text, with no introductory phrases, labels, or quotation marks.';

        // Gather placeholders
        $original_meta = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true) ?: (get_post_meta($post->ID, '_aioseo_description', true) ?: '');
        $original_focus_keyword = AIPKit_SEO_Helper::get_focus_keyword($post->ID);
        $original_tags = AIPKit_SEO_Helper::get_tags_as_string($post->ID);
        $categories = AIPKit_SEO_Helper::get_categories_as_string($post->ID);
        $original_alt = '';
        $original_caption = '';
        $original_description = '';
        $file_name = '';

        if ($post->post_type === 'attachment') {
            $original_alt = (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true);
            $original_caption = (string) $post->post_excerpt;
            $original_description = (string) $post->post_content;
            $attached_file = (string) get_attached_file($post->ID);
            if ($attached_file) {
                $file_name = wp_basename($attached_file);
            }
        }

        $placeholders = [
            '{original_title}' => $post->post_title,
            '{original_content}' => get_post_full_content($post),
            '{original_excerpt}' => $post->post_excerpt,
            '{original_meta_description}' => $original_meta,
            '{original_focus_keyword}' => $original_focus_keyword ?: '',
            '{original_tags}' => $original_tags,
            '{categories}' => $categories,
            '{original_caption}' => $original_caption,
            '{original_description}' => $original_description,
            '{original_alt}' => $original_alt,
            '{file_name}' => $file_name,
        ];

        // Add WooCommerce placeholders if applicable
        if ($post->post_type === 'product' && class_exists('WooCommerce')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $placeholders['{price}'] = $product->get_price();
                $placeholders['{regular_price}'] = $product->get_regular_price();
                $placeholders['{sale_price}'] = $product->get_sale_price();
                $placeholders['{sku}'] = $product->get_sku();
                $placeholders['{stock_quantity}'] = $product->get_stock_quantity() ?? 'N/A';
                $placeholders['{stock_status}'] = $product->get_stock_status();
                $placeholders['{weight}'] = $product->get_weight();
                $placeholders['{length}'] = $product->get_length();
                $placeholders['{width}'] = $product->get_width();
                $placeholders['{height}'] = $product->get_height();
                $placeholders['{short_description}'] = wp_strip_all_tags($product->get_short_description());
                $placeholders['{purchase_note}'] = $product->get_purchase_note();

                $category_terms = get_the_terms($post->ID, 'product_cat');
                if (!is_wp_error($category_terms) && !empty($category_terms)) {
                    $category_names = wp_list_pluck($category_terms, 'name');
                    $placeholders['{product_categories}'] = implode(', ', $category_names);
                } else {
                    $placeholders['{product_categories}'] = '';
                }

                $attributes = $product->get_attributes();
                $attribute_string = '';
                foreach ($attributes as $attribute) {
                    if ($attribute->is_taxonomy()) {
                        $terms = wp_get_post_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                        if (!is_wp_error($terms) && !empty($terms)) {
                            $attribute_string .= wc_attribute_label($attribute->get_name()) . ': ' . implode(', ', $terms) . '; ';
                        }
                    } else {
                        $attribute_string .= wc_attribute_label($attribute->get_name()) . ': ' . implode(', ', $attribute->get_options()) . '; ';
                    }
                }
                $placeholders['{attributes}'] = rtrim($attribute_string, '; ');
            }
        }

        // Vector Context Logic
        $vector_context = '';
        $vector_store_manager = null;
        if ($vector_store_enabled) {
            if (class_exists(AIPKit_Vector_Store_Manager::class)) {
                $vector_store_manager = new AIPKit_Vector_Store_Manager();
            }

            if ($vector_store_manager && function_exists('\WPAICG\Core\Stream\Vector\build_vector_search_context_logic')) {
                $vector_context = VectorContextBuilder\build_vector_search_context_logic(
                    $ai_caller,
                    $vector_store_manager,
                    $post->post_title, // Use post title as the query
                    $item_config,      // Pass the whole config as it contains all vector settings
                    $provider,         // Main AI provider
                    null,
                    null,
                    null,
                    null,
                    null // No frontend context in bulk enhancer
                );
            }
        }
        if (is_wp_error($vector_context)) {
            $this->send_error_response($vector_context);
            return;
        }
        if (!empty($vector_context)) {
            $system_instruction = "## Relevant information from knowledge base:\n" . trim($vector_context) . "\n##\n\n" . $system_instruction;
        }

        $image_context = '';
        $image_fields = ['keyword', 'title', 'excerpt', 'content'];
        if ($post->post_type === 'attachment' && in_array($field, $image_fields, true)) {
            $image_context = $this->get_image_context_for_attachment($post->ID, $provider, $ai_caller, $model, $ai_params);
            if (is_wp_error($image_context)) { $this->send_error_response($image_context); return; }
            if (!empty($image_context)) {
                $placeholders['{image_context}'] = $image_context;
            }
        }

        // Process the specific field
        $raw_prompt = $item_config[$field]['prompt'];
        $prompt = str_replace(array_keys($placeholders), array_values($placeholders), $raw_prompt);
        if (!empty($image_context) && strpos($raw_prompt, '{image_context}') === false) {
            $prompt .= "\n\nImage context: " . $image_context;
        }

    $ai_result = $ai_caller->make_standard_call($provider, $model, [['role' => 'user', 'content' => $prompt]], $ai_params, $system_instruction, ['post_id' => $post->ID]);

        if (is_wp_error($ai_result)) {
            // Log error to Admin Logs as well
            $error_message = 'Error: ' . $ai_result->get_error_message();
            $error_data = $ai_result->get_error_data() ?? [];
            $request_payload_log = $error_data['request_payload'] ?? null;
            log_enhancer_bulk_update_logic(
                (int) $post->ID,
                $field,
                $prompt,
                $error_message,
                $provider,
                $model,
                null,
                is_array($request_payload_log) ? $request_payload_log : null,
                $conv_uuid
            );
            $this->send_error_response($ai_result);
            return;
        }

        if (empty($ai_result['content'])) {
            // Log empty response as an error entry
            $request_payload_log = $ai_result['request_payload_log'] ?? null;
            log_enhancer_bulk_update_logic(
                (int) $post->ID,
                $field,
                $prompt,
                'Error: AI returned empty response for this field.',
                $provider,
                $model,
                $ai_result['usage'] ?? null,
                is_array($request_payload_log) ? $request_payload_log : null,
                $conv_uuid
            );
            $this->send_error_response(new WP_Error('empty_response', __('AI returned empty response for this field.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }

        // A Stop request can arrive while the AI provider call is in flight.
        // Recheck the prepared run immediately before any database write.
        if ($enhancer_source === 'content_writer') {
            $run_permission = AIPKit_Content_Writer_Prepare_Update_Run_Action::validate_request($run_token, (int) $post->ID, $field);
            if (is_wp_error($run_permission)) {
                $this->send_error_response($run_permission);
                return;
            }
        }

        $new_value = trim(str_replace('"', '', $ai_result['content']));
        $success_message = '';
        $updated_value = null;

        // Retain the generated content even if the subsequent save fails.
        log_enhancer_bulk_update_logic(
            (int) $post->ID,
            $field,
            $prompt,
            $new_value,
            $provider,
            $model,
            $ai_result['usage'] ?? null,
            $ai_result['request_payload_log'] ?? null,
            $conv_uuid
        );

        // A provider response can succeed while WordPress rejects its save.
        $saved = true;
        switch ($field) {
            case 'keyword':
                if ($post->post_type === 'attachment') {
                    $value = sanitize_text_field($new_value);
                    $saved = get_post_meta($post->ID, '_wp_attachment_image_alt', true) === $value || update_post_meta($post->ID, '_wp_attachment_image_alt', wp_slash($value)) !== false;
                    $success_message = 'Alt text updated successfully';
                } else {
                    $value = sanitize_text_field($new_value);
                    $saved = AIPKit_SEO_Helper::get_focus_keyword($post->ID) === $value || AIPKit_SEO_Helper::update_focus_keyword($post->ID, $value);
                    $success_message = 'Focus keyword updated successfully';
                }
                break;
            case 'title':
                $saved = wp_update_post(wp_slash(['ID' => $post->ID, 'post_title' => sanitize_text_field($new_value)]), true);
                $updated_value = get_post_field('post_title', $post->ID);
                $success_message = 'Title updated successfully';
                break;
            case 'excerpt':
                $saved = wp_update_post(wp_slash(['ID' => $post->ID, 'post_excerpt' => wp_kses_post($new_value)]), true);
                $success_message = 'Excerpt updated successfully';
                break;
            case 'content':
                $html_content = AIPKit_Content_Writer_Output_Cleaner::convert_basic_markdown_to_html($new_value);
                $saved = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => wp_kses_post($html_content)]), true);
                $success_message = 'Content updated successfully';
                break;
            case 'tags':
                if (class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
                    $saved = \WPAICG\SEO\AIPKit_SEO_Helper::update_tags($post->ID, sanitize_text_field($new_value));
                    $success_message = 'Tags updated successfully';
                } else {
                    $this->send_error_response(new WP_Error('tags_helper_missing', __('Tags helper class not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
                    return;
                }
                break;
            case 'meta':
                $value = sanitize_text_field($new_value);
                $saved = AIPKit_SEO_Helper::get_meta_description($post->ID) === $value || AIPKit_SEO_Helper::update_meta_description($post->ID, $value);
                $success_message = 'Meta description updated successfully';
                break;
            default:
                $this->send_error_response(new WP_Error('unsupported_field', __('Unsupported field type.', 'gpt3-ai-content-generator'), ['status' => 400]));
                return;
        }

        if (is_wp_error($saved) || !$saved) {
            $this->send_error_response(new WP_Error('post_update_failed', __('The AI response was generated, but WordPress could not save it. Copy the generated text below before closing this window.', 'gpt3-ai-content-generator'), [
                'status' => 500, 'stop_batch' => true,
                'generated_value' => $field === 'content' ? $html_content : $new_value,
            ]));
            return;
        }

        if ($post->post_type === 'attachment' && $updated_value === null) {
            switch ($field) {
                case 'keyword':
                    $updated_value = sanitize_text_field($new_value);
                    break;
                case 'excerpt':
                case 'content':
                    $updated_value = trim(wp_strip_all_tags($new_value));
                    break;
            }
        }

        $response_payload = [
            'message' => $success_message,
            'field' => $field,
            'post_id' => $post->ID
        ];
        if ($updated_value !== null) {
            $response_payload['updated_value'] = $updated_value;
        }

        wp_send_json_success($response_payload);
    }



}

// This handles updating SEO slug for a post during bulk enhancement


class AIPKit_PostEnhancer_Bulk_Update_SEO_Slug extends AIPKit_Post_Enhancer_Base_Ajax_Action
{
    public function handle(): void
    {
        $permission_check = $this->check_permissions('aipkit_generate_title_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_error_response($permission_check);
            return;
        }

        $post = $this->get_post();
        if (is_wp_error($post)) {
            $this->send_error_response($post);
            return;
        }
        $feature_permission = $this->check_content_update_permissions($post);
        if (is_wp_error($feature_permission)) {
            $this->send_error_response($feature_permission);
            return;
        }

        $is_pro = class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reason: Nonce is checked in check_permissions.
        $enhancer_source = isset($_POST['enhancer_source']) ? sanitize_key(wp_unslash($_POST['enhancer_source'])) : '';
        $bypass_pro_checks = ($enhancer_source === 'post_enhancer');

        if (!$is_pro && !$bypass_pro_checks) {
            $this->send_error_response(new WP_Error('pro_required', __('Updating URL slugs is available on Pro.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }

        // Check if action type is specified
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reason: Nonce is checked in check_permissions.
        $action_type = isset($_POST['action_type']) ? sanitize_text_field(wp_unslash($_POST['action_type'])) : '';
        if ($action_type !== 'update_slug') {
            $this->send_error_response(new WP_Error('invalid_action', __('Invalid action type.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        // Update SEO slug
        if (class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
            $result = \WPAICG\SEO\AIPKit_SEO_Helper::update_post_slug_for_seo($post->ID);
            if ($result) {
                wp_send_json_success([
                    'message' => 'URL updated successfully',
                    'post_id' => $post->ID
                ]);
            } else {
                $this->send_error_response(new WP_Error('slug_update_failed', __('Failed to update URL.', 'gpt3-ai-content-generator'), ['status' => 500]));
            }
        } else {
            $this->send_error_response(new WP_Error('seo_helper_missing', __('SEO helper class not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        }
    }
}

class AIPKit_PostEnhancer_Request_Status extends AIPKit_Post_Enhancer_Base_Ajax_Action
{
    public function handle(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Operation-specific nonce is verified below before lookup.
        $id = sanitize_text_field(wp_unslash($_POST['operation_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
            $this->send_error_response(new WP_Error('invalid_operation_id', __('Invalid request ID.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        $permission = $this->check_permissions('aipkit_enhancer_status_' . $id, [self::ROW_ASSISTANT_MODULE, self::CLASSIC_EDITOR_ASSISTANT_MODULE, self::BLOCK_EDITOR_ASSISTANT_MODULE]);
        if (is_wp_error($permission)) { $this->send_error_response($permission); return; }
        try {
            $result = \WPAICG\Cloud\Connection::request_status($id);
            $state = sanitize_key($result['state'] ?? 'unknown');
            wp_send_json_success(['state' => $state]);
        } catch (\RuntimeException $error) {
            $this->send_error_response(new WP_Error('request_status_unavailable', __('The request status could not be checked. Check again before starting another request.', 'gpt3-ai-content-generator'), ['status' => 503]));
        }
    }
}

class AIPKit_PostEnhancer_Process_Text extends AIPKit_Post_Enhancer_Base_Ajax_Action
{
    public function handle(): void
    {
        $permission_check = $this->check_permissions('aipkit_process_enhancer_text_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_error_response($permission_check);
            return;
        }
        $feature_permission = $this->check_editor_context_permissions();
        if (is_wp_error($feature_permission)) {
            $this->send_error_response($feature_permission);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked in check_permissions(); AIPKit_Prompt_Sanitizer preserves literal HTML while sanitizing prompt text.
        $final_prompt = isset($_POST['final_prompt']) ? AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($_POST['final_prompt'])) : '';
        // text_to_process is still useful for context but the prompt is king
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_permissions.
        $text_to_process = isset($_POST['text_to_process']) ? wp_kses_post(wp_unslash($_POST['text_to_process'])) : '';

        if (empty($text_to_process) || empty($final_prompt)) {
            $this->send_error_response(new WP_Error('missing_params', __('Text and a prompt are required.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        // AI Call setup
        $global_config = AIPKit_Providers::get_new_text_generation_selection();
        $ai_params = AIPKIT_AI_Settings::get_ai_parameters();
        $provider = $global_config['provider'];
        $model = $global_config['model'];
        $ai_caller = new AIPKit_AI_Caller(true, 'ai_post_enhancer');
        $messages = [['role' => 'user', 'content' => $final_prompt]];

        $result = $ai_caller->make_standard_call($provider, $model, $messages, $ai_params);
        if (is_wp_error($result)) {
            $this->send_error_response($result);
            return;
        }

        $new_text_raw = $result['content'] ?? '';

        $html_content = wp_kses_post(AIPKit_Content_Writer_Output_Cleaner::convert_basic_markdown_to_html((string) $new_text_raw));

        wp_send_json_success(['text' => $html_content]);
    }
}

namespace WPAICG\PostEnhancer\Ajax;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;

// The core can load this owner before dashboard dependencies are initialized.
if (!class_exists(BaseDashboardAjaxHandler::class)) {
    require_once WPAICG_PLUGIN_DIR . 'classes/admin/ajax.php';
}

/**
 * Handles AJAX requests for managing Post Enhancer custom actions.
 */
class AIPKit_Enhancer_Actions_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private const OPTION_NAME = 'aipkit_enhancer_actions';
    public const MODULE_SLUG = 'settings';
    public const MAX_ACTIONS = 20;

    /**
     * Get the default set of actions.
     * @return array
     */
    public function get_default_actions_public(): array
    {
        return [
            [
                'id' => 'rewrite-' . wp_generate_uuid4(),
                'label' => __('Rewrite', 'gpt3-ai-content-generator'),
                'prompt' => 'Rewrite this to improve clarity and engagement: {selected_text}',
                'insert_position' => 'replace',
                'is_default' => true
            ],
            [
                'id' => 'expand-' . wp_generate_uuid4(),
                'label' => __('Expand', 'gpt3-ai-content-generator'),
                'prompt' => 'Expand on the following point: {selected_text}',
                'insert_position' => 'replace',
                'is_default' => true
            ],
            [
                'id' => 'fix_grammar-' . wp_generate_uuid4(),
                'label' => __('Fix grammar and spelling', 'gpt3-ai-content-generator'),
                'prompt' => 'Correct any spelling and grammar mistakes in the following text: {selected_text}',
                'insert_position' => 'replace',
                'is_default' => true
            ],
            [
                'id' => 'summarize-' . wp_generate_uuid4(),
                'label' => __('Summarize', 'gpt3-ai-content-generator'),
                'prompt' => 'Summarize the following text in 3–5 concise sentences while preserving key facts and tone: {selected_text}',
                'insert_position' => 'replace',
                'is_default' => true
            ],
            [
                'id' => 'outline-' . wp_generate_uuid4(),
                'label' => __('Create outline (H2/H3)', 'gpt3-ai-content-generator'),
                'prompt' => 'Create a clear outline from the following text using headings (## for H2, ### for H3) and short bullets as needed: {selected_text}',
                'insert_position' => 'replace',
                'is_default' => true
            ],
            [
                'id' => 'faqs-' . wp_generate_uuid4(),
                'label' => __('Generate FAQs', 'gpt3-ai-content-generator'),
                'prompt' => 'Generate 5–7 relevant FAQ questions and short answers based on this text. Use a simple Q/A format in Markdown. Text: {selected_text}',
                'insert_position' => 'replace',
                'is_default' => true
            ],
            [
                'id' => 'simplify-' . wp_generate_uuid4(),
                'label' => __('Simplify tone', 'gpt3-ai-content-generator'),
                'prompt' => 'Rewrite the following in a friendly, simple tone (grade 7–8 readability) while preserving meaning and structure: {selected_text}',
                'insert_position' => 'replace',
                'is_default' => true
            ],
        ];
    }

    /**
     * Present untouched legacy defaults with the current labels and named token
     * without mutating a user's saved option until they explicitly save or reset.
     *
     * @param array $actions Stored actions.
     * @return array
     */
    public function normalize_actions_for_ui_public(array $actions): array
    {
        $legacy_labels = [
            'Fix Grammar & Spelling' => __('Fix grammar and spelling', 'gpt3-ai-content-generator'),
            'Create Outline (H2/H3)' => __('Create outline (H2/H3)', 'gpt3-ai-content-generator'),
            'Simplify Tone' => __('Simplify tone', 'gpt3-ai-content-generator'),
        ];

        foreach ($actions as &$action) {
            if (!is_array($action) || empty($action['is_default'])) {
                continue;
            }
            if (isset($action['label'], $legacy_labels[$action['label']])) {
                $action['label'] = $legacy_labels[$action['label']];
            }
            if (isset($action['prompt']) && is_string($action['prompt'])) {
                $action['prompt'] = str_replace('%s', '{selected_text}', $action['prompt']);
            }
        }
        unset($action);

        return $actions;
    }

    /**
     * AJAX: Reset actions to defaults.
     */
    public function ajax_reset_actions(): void
    {
        $permission_check = $this->check_module_access_permissions(self::MODULE_SLUG, 'aipkit_enhancer_actions_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        $defaults = $this->get_default_actions_public();
        update_option(self::OPTION_NAME, $defaults, 'no');
        wp_send_json_success([
            'message' => __('Actions reset to defaults.', 'gpt3-ai-content-generator'),
            'actions' => $defaults,
        ]);
    }

    /**
     * AJAX: Reorder actions.
     */
    public function ajax_reorder_actions(): void
    {
        $permission_check = $this->check_module_access_permissions(self::MODULE_SLUG, 'aipkit_enhancer_actions_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in the parent class.
        $post_data = wp_unslash($_POST);
        $order_raw = $post_data['order'] ?? [];
        if (!is_array($order_raw)) {
            $this->send_wp_error(new WP_Error('invalid_order', __('Invalid action order provided.', 'gpt3-ai-content-generator')));
            return;
        }

        $ordered_ids = array_values(
            array_filter(
                array_map('sanitize_text_field', $order_raw)
            )
        );

        $actions = get_option(self::OPTION_NAME, $this->get_default_actions_public());
        if (!is_array($actions) || empty($actions)) {
            wp_send_json_success([
                'actions' => [],
            ]);
        }

        $actions_by_id = [];
        foreach ($actions as $action) {
            $id = isset($action['id']) ? sanitize_text_field((string) $action['id']) : '';
            if ($id !== '') {
                $actions_by_id[$id] = $action;
            }
        }

        $reordered = [];
        foreach ($ordered_ids as $id) {
            if (isset($actions_by_id[$id])) {
                $reordered[] = $actions_by_id[$id];
                unset($actions_by_id[$id]);
            }
        }

        // Append any remaining actions in their original order.
        if (!empty($actions_by_id)) {
            foreach ($actions as $action) {
                $id = isset($action['id']) ? sanitize_text_field((string) $action['id']) : '';
                if ($id !== '' && isset($actions_by_id[$id])) {
                    $reordered[] = $action;
                    unset($actions_by_id[$id]);
                }
            }
        }

        update_option(self::OPTION_NAME, $reordered, 'no');
        wp_send_json_success([
            'message' => __('Action order updated.', 'gpt3-ai-content-generator'),
            'actions' => $this->normalize_actions_for_ui_public($reordered),
        ]);
    }

    /**
     * AJAX handler to get all custom and default actions.
     */
    public function ajax_get_actions(): void
    {
        $permission_check = $this->check_module_access_permissions(self::MODULE_SLUG, 'aipkit_enhancer_actions_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $actions = get_option(self::OPTION_NAME);
        if ($actions === false || !is_array($actions)) {
            $actions = $this->get_default_actions_public();
        }

        wp_send_json_success([
            'actions' => $this->normalize_actions_for_ui_public($actions),
        ]);
    }

    /**
     * AJAX handler to save or update an action.
     */
    public function ajax_save_action(): void
    {
        $permission_check = $this->check_module_access_permissions(self::MODULE_SLUG, 'aipkit_enhancer_actions_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in the parent class.
        $post_data = wp_unslash($_POST);
        $action_id = isset($post_data['id']) && !empty($post_data['id']) ? sanitize_text_field((string) $post_data['id']) : null;
        $label = isset($post_data['label']) ? sanitize_text_field((string) $post_data['label']) : '';
        $prompt = isset($post_data['prompt']) ? AIPKit_Prompt_Sanitizer::sanitize($post_data['prompt']) : '';
        $allowed_positions = ['replace', 'after', 'before'];
        $insert_position_raw = isset($post_data['insert_position']) ? sanitize_key((string) $post_data['insert_position']) : 'replace';
        $insert_position = in_array($insert_position_raw, $allowed_positions, true) ? $insert_position_raw : 'replace';

        if (empty($label) || empty($prompt)) {
            $this->send_wp_error(new WP_Error('missing_data', __('Label and prompt are required.', 'gpt3-ai-content-generator')));
            return;
        }

        $actions = get_option(self::OPTION_NAME, $this->get_default_actions_public());
        if (!is_array($actions)) {
            $actions = $this->get_default_actions_public(); // Fallback if option is corrupted
        }

        $found = false;
        if ($action_id && strpos($action_id, 'new-') !== 0) { // It's an existing action
            foreach ($actions as &$action) {
                if (isset($action['id']) && $action['id'] === $action_id) {
                    // A default action that is edited becomes a custom action.
                    if (isset($action['is_default'])) {
                        $action['is_default'] = false;
                    }
                    $action['label'] = $label;
                    $action['prompt'] = $prompt;
                    $action['insert_position'] = $insert_position;
                    $found = true;
                    break;
                }
            }
            unset($action);
        }

        $saved_action = null;
        if (!$found) {
            // It's a new action
            if (count($actions) >= self::MAX_ACTIONS) {
                $this->send_wp_error(new WP_Error('limit_reached', __('You have reached the maximum of 20 actions.', 'gpt3-ai-content-generator')));
                return;
            }
            $new_action = [
                'id' => 'custom-' . wp_generate_uuid4(),
                'label' => $label,
                'prompt' => $prompt,
                'insert_position' => $insert_position,
                'is_default' => false
            ];
            $actions[] = $new_action;
            $saved_action = $new_action;
        } else {
            $saved_action_array = array_filter($actions, function ($a) use ($action_id) {
                return isset($a['id']) && $a['id'] === $action_id;
            });
            $saved_action = reset($saved_action_array);
        }

        update_option(self::OPTION_NAME, $actions, 'no');
        wp_send_json_success([
            'message' => __('Action saved successfully.', 'gpt3-ai-content-generator'),
            'action' => $saved_action,
        ]);
    }

    /**
     * AJAX handler to delete an action.
     */
    public function ajax_delete_action(): void
    {
        $permission_check = $this->check_module_access_permissions(self::MODULE_SLUG, 'aipkit_enhancer_actions_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in the parent class.
        $action_id_to_delete = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : null;

        if (empty($action_id_to_delete)) {
            $this->send_wp_error(new WP_Error('missing_id', __('Action ID is required.', 'gpt3-ai-content-generator')));
            return;
        }

        $actions = get_option(self::OPTION_NAME, []);
        if (!is_array($actions)) {
            wp_send_json_success([
                'message' => __('No actions to delete.', 'gpt3-ai-content-generator'),
                'actions' => [],
            ]);
            return;
        }

        $updated_actions = array_filter($actions, function ($action) use ($action_id_to_delete) {
            return !isset($action['id']) || $action['id'] !== $action_id_to_delete;
        });

        update_option(self::OPTION_NAME, array_values($updated_actions), 'no');
        wp_send_json_success([
            'message' => __('Action deleted successfully.', 'gpt3-ai-content-generator'),
            'actions' => $this->normalize_actions_for_ui_public(array_values($updated_actions)),
        ]);
    }
}

namespace WPAICG\PostEnhancer;

use WPAICG\PostEnhancer\Ajax\Actions;

/**
 * Main AJAX handler for the Content Enhancer module.
 * Instantiates and dispatches requests to dedicated action classes.
 */
class AjaxHandler
{
    private $generate_title_handler;
    private $generate_excerpt_handler;
    private $generate_meta_handler;
    private $generate_tags_handler;
    private $update_title_handler;
    private $update_excerpt_handler;
    private $update_meta_handler;
    private $update_tags_handler;
    private $bulk_process_single_field_handler;
    private $bulk_update_seo_slug_handler;
    private $process_text_handler;

    public function __construct()
    {
        $this->generate_title_handler = new Actions\AIPKit_PostEnhancer_Generate_Title();
        $this->generate_excerpt_handler = new Actions\AIPKit_PostEnhancer_Generate_Excerpt();
        $this->generate_meta_handler = new Actions\AIPKit_PostEnhancer_Generate_Meta();
        $this->generate_tags_handler = new Actions\AIPKit_PostEnhancer_Generate_Tags();
        $this->update_title_handler = new Actions\AIPKit_PostEnhancer_Update_Title();
        $this->update_excerpt_handler = new Actions\AIPKit_PostEnhancer_Update_Excerpt();
        $this->update_meta_handler = new Actions\AIPKit_PostEnhancer_Update_Meta();
        $this->update_tags_handler = new Actions\AIPKit_PostEnhancer_Update_Tags();
        $this->bulk_process_single_field_handler = new Actions\AIPKit_PostEnhancer_Bulk_Process_Single_Field();
        $this->bulk_update_seo_slug_handler = new Actions\AIPKit_PostEnhancer_Bulk_Update_SEO_Slug();
        $this->process_text_handler = new Actions\AIPKit_PostEnhancer_Process_Text();
    }

    public function generate_title_suggestions()
    {
        $this->generate_title_handler->handle();
    }
    public function generate_excerpt_suggestions()
    {
        $this->generate_excerpt_handler->handle();
    }
    public function generate_meta_suggestions()
    {
        $this->generate_meta_handler->handle();
    }
    public function generate_tags_suggestions()
    {
        $this->generate_tags_handler->handle();
    }
    public function update_post_title()
    {
        $this->update_title_handler->handle();
    }
    public function update_post_excerpt()
    {
        $this->update_excerpt_handler->handle();
    }
    public function update_post_meta_desc()
    {
        $this->update_meta_handler->handle();
    }
    public function update_post_tags()
    {
        $this->update_tags_handler->handle();
    }

    public function ajax_bulk_process_single_field()
    {
        $this->bulk_process_single_field_handler->handle();
    }

    /**
     * AJAX handler for updating SEO slug of a post.
     */
    public function ajax_bulk_update_seo_slug()
    {
        $this->bulk_update_seo_slug_handler->handle();
    }

    /**
     * AJAX handler for processing text selected in an editor.
     */
    public function ajax_process_enhancer_text()
    {
        $this->process_text_handler->handle();
    }

    public function ajax_request_status(): void
    {
        (new Actions\AIPKit_PostEnhancer_Request_Status())->handle();
    }
}
