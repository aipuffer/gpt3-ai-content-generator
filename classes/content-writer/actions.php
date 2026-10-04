<?php

namespace WPAICG\ContentWriter\Ajax;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists(BaseDashboardAjaxHandler::class)) {
    require_once WPAICG_PLUGIN_DIR . 'classes/admin/ajax.php';
}

/**
* Base class for Content Writer AJAX actions.
* Initializes common dependencies like LogStorage, AICaller, and VectorStoreManager.
*/
abstract class AIPKit_Content_Writer_Base_Ajax_Action extends BaseDashboardAjaxHandler
{
    private const BATCH_RUN_TRANSIENT_PREFIX = 'aipkit_cw_batch_run_';
    private const BATCH_RUN_TTL = 30 * MINUTE_IN_SECONDS;

    public $log_storage;
    public $ai_caller;
    public $vector_store_manager;
    protected $disabled_functions = [];

    public function __construct()
    {
        $this->disabled_functions = $this->get_disabled_functions();

        // Ensure LogStorage is available
        if (class_exists(\WPAICG\Chat\Storage\LogStorage::class)) {
            $this->log_storage = new LogStorage();
        }

        // Ensure AICaller is available
        if (class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $this->ai_caller = new AIPKit_AI_Caller();
        }

        // Ensure VectorStoreManager is available
        if (class_exists(\WPAICG\Vector\AIPKit_Vector_Store_Manager::class)) {
            $this->vector_store_manager = new AIPKit_Vector_Store_Manager();
        }
    }

    /**
    * Public getter for the ai_caller dependency.
    * @return AIPKit_AI_Caller|null
    */
    public function get_ai_caller(): ?AIPKit_AI_Caller
    {
        return $this->ai_caller;
    }

    /**
    * Public getter for the vector_store_manager dependency.
    * @return AIPKit_Vector_Store_Manager|null
    */
    public function get_vector_store_manager(): ?AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
    }

    public function ensure_content_writer_conversation_uuid(string $conversation_uuid): string
    {
        if ($conversation_uuid !== '') {
            return $conversation_uuid;
        }

        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('aipkit-', true);
    }

    public function build_content_writer_log_base(string $conversation_uuid, string $provider = '', string $model = ''): array
    {
        $current_user = wp_get_current_user();

        return [
            'bot_id' => null,
            'user_id' => get_current_user_id(),
            'session_id' => null,
            'conversation_uuid' => $conversation_uuid,
            'module' => 'content_writer',
            'is_guest' => 0,
            'role' => is_a($current_user, 'WP_User') ? implode(', ', $current_user->roles) : '',
            'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null,
            'timestamp' => time(),
            'ai_provider' => $provider,
            'ai_model' => $model,
        ];
    }

    public function get_content_writer_request_conversation_uuid(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the content writer nonce before logging.
        return isset($_POST['conversation_uuid']) ? sanitize_text_field(wp_unslash($_POST['conversation_uuid'])) : '';
    }

    public function log_content_writer_generation_step(
        string $provider,
        string $model,
        string $user_message_content,
        array $user_request_payload,
        string $bot_message_content,
        $usage,
        string $user_prompt,
        array $ai_params,
        string $system_instruction,
        array $ai_result
    ): string {
        $conversation_uuid = $this->get_content_writer_request_conversation_uuid();
        if (!$this->log_storage) {
            return $conversation_uuid;
        }

        $conversation_uuid = $this->ensure_content_writer_conversation_uuid($conversation_uuid);
        $base = $this->build_content_writer_log_base($conversation_uuid, $provider, $model);
        $this->log_storage->log_message(array_merge($base, [
            'message_role' => 'user',
            'message_content' => $user_message_content,
            'request_payload' => $user_request_payload,
        ]));

        $bot_log = array_merge($base, [
            'message_role' => 'bot',
            'message_content' => $bot_message_content,
            'usage' => $usage,
            'request_payload' => [
                'provider' => $provider,
                'model' => $model,
                'payload_sent' => [
                    'messages' => [['role' => 'user', 'content' => $user_prompt]],
                    'ai_params' => $ai_params,
                    'system_instruction' => $system_instruction,
                ],
            ],
        ]);
        if (!empty($ai_result['vector_search_scores'])) {
            $bot_log['vector_search_scores'] = $ai_result['vector_search_scores'];
        }
        $this->log_storage->log_message($bot_log);

        return $conversation_uuid;
    }

    public function get_content_writer_generation_request(string $custom_prompt_key): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers verify the content writer nonce; custom prompt text is sanitized by AIPKit_Prompt_Sanitizer.
        return [
            'generated_content' => isset($_POST['generated_content']) ? wp_kses_post(wp_unslash($_POST['generated_content'])) : '',
            'final_title' => isset($_POST['final_title']) ? sanitize_text_field(wp_unslash($_POST['final_title'])) : '',
            'keywords' => isset($_POST['keywords']) ? sanitize_text_field(wp_unslash($_POST['keywords'])) : '',
            'provider_raw' => isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '',
            'model' => isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '',
            'prompt_mode' => isset($_POST['prompt_mode']) ? sanitize_key($_POST['prompt_mode']) : 'standard',
            'custom_prompt' => isset($_POST[$custom_prompt_key]) ? AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($_POST[$custom_prompt_key])) : null,
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }

    public function prepare_content_writer_vector_context(
        string $user_prompt,
        string $provider,
        string $system_instruction,
        array $ai_params,
        ?array $form_data = null
    ) {
        if (!function_exists('WPAICG\\Core\\Stream\\Vector\\prepare_vector_standard_call')) {
            $helper_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/retrieval-context.php';
            if (file_exists($helper_path)) {
                require_once $helper_path;
            }
        }

        $instruction_context = [];
        if (function_exists('WPAICG\\Core\\Stream\\Vector\\prepare_vector_standard_call')) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the content writer nonce before preparing vector context.
            $form_data = $form_data ?? $_POST;
            $prep = \WPAICG\Core\Stream\Vector\prepare_vector_standard_call(
                $this->get_ai_caller(),
                $this->get_vector_store_manager(),
                $user_prompt,
                $form_data,
                $provider,
                $system_instruction,
                $ai_params
            );
            if (is_wp_error($prep)) { return $prep; }
            $system_instruction = $prep['system_instruction'] ?? $system_instruction;
            $ai_params = $prep['ai_params'] ?? $ai_params;
            $instruction_context = $prep['instruction_context'] ?? [];
        }

        return [$system_instruction, $ai_params, $instruction_context];
    }

    protected function check_content_writer_or_autogpt_request_access(): bool
    {
        if (!\WPAICG\AIPKit_Role_Manager::user_can_access_module('content-writer') && !\WPAICG\AIPKit_Role_Manager::user_can_access_module('autogpt')) {
            $this->send_wp_error(new WP_Error('permission_denied', __('You do not have permission to use this feature.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return false;
        }

        $nonce = isset($_POST['_ajax_nonce']) ? sanitize_key(wp_unslash($_POST['_ajax_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'aipkit_content_writer_nonce') && !wp_verify_nonce($nonce, 'aipkit_nonce') && !wp_verify_nonce($nonce, 'aipkit_automated_tasks_manage_nonce')) {
            $this->send_wp_error(new WP_Error('nonce_failure', __('Security check failed.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return false;
        }

        return true;
    }

    /**
     * Creates a short-lived, user-bound token for an inline batch run.
     */
    protected function create_content_writer_batch_run(): string
    {
        $token = wp_generate_password(40, false, false);
        set_transient(
            $this->get_content_writer_batch_run_transient_key($token),
            [
                'user_id' => get_current_user_id(),
                'cancelled' => false,
                'created_at' => time(),
            ],
            self::BATCH_RUN_TTL
        );

        return $token;
    }

    /**
     * Validates the optional batch token attached to a generation request.
     * Requests without a token are single-mode or legacy calls and retain
     * their existing behavior.
     *
     * @return true|WP_Error
     */
    protected function validate_content_writer_batch_run_request()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the Content Writer nonce before invoking this method.
        $token = isset($_POST['batch_run_token']) ? sanitize_text_field(wp_unslash($_POST['batch_run_token'])) : '';
        if ($token === '') {
            return true;
        }

        $payload = get_transient($this->get_content_writer_batch_run_transient_key($token));
        if (!is_array($payload)) {
            return new WP_Error('expired_batch_run', __('The batch session expired. Please start the batch again.', 'gpt3-ai-content-generator'), ['status' => 409]);
        }
        if ((int) ($payload['user_id'] ?? 0) !== get_current_user_id()) {
            return new WP_Error('invalid_batch_run_user', __('This batch session is not valid for the current user.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        if (!empty($payload['cancelled'])) {
            return new WP_Error('batch_run_stopped', __('This batch was stopped.', 'gpt3-ai-content-generator'), ['status' => 409]);
        }

        return true;
    }

    /**
     * Marks a batch token as cancelled so in-flight requests cannot commit
     * generated content after Stop is pressed.
     *
     * @return true|WP_Error
     */
    protected function cancel_content_writer_batch_run(string $token)
    {
        if ($token === '') {
            return new WP_Error('missing_batch_run', __('The batch session is missing. Please start the batch again.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        $transient_key = $this->get_content_writer_batch_run_transient_key($token);
        $payload = get_transient($transient_key);
        if (!is_array($payload)) {
            return new WP_Error('expired_batch_run', __('The batch session expired. Please start the batch again.', 'gpt3-ai-content-generator'), ['status' => 409]);
        }
        if ((int) ($payload['user_id'] ?? 0) !== get_current_user_id()) {
            return new WP_Error('invalid_batch_run_user', __('This batch session is not valid for the current user.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        $payload['cancelled'] = true;
        $payload['cancelled_at'] = time();
        set_transient($transient_key, $payload, self::BATCH_RUN_TTL);

        return true;
    }

    private function get_content_writer_batch_run_transient_key(string $token): string
    {
        return self::BATCH_RUN_TRANSIENT_PREFIX . md5($token);
    }

    protected function maybe_extend_execution_limits(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $max_execution_time = function_exists('ini_get') ? (int) ini_get('max_execution_time') : 0;
        if ($max_execution_time > 0 && $max_execution_time < $seconds) {
            $this->maybe_set_time_limit($seconds);
            $this->maybe_set_ini_value('max_execution_time', (string) $seconds);
        }

        $socket_timeout = function_exists('ini_get') ? (int) ini_get('default_socket_timeout') : 0;
        if ($socket_timeout > 0 && $socket_timeout < $seconds) {
            $this->maybe_set_ini_value('default_socket_timeout', (string) $seconds);
        }
    }

    private function maybe_set_time_limit(int $seconds): void
    {
        if (!$this->can_use_function('set_time_limit')) {
            return;
        }

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Content generation requests may legitimately need a longer admin-request window.
        set_time_limit($seconds);
    }

    private function maybe_set_ini_value(string $option_name, string $value): void
    {
        if (!$this->can_use_function('ini_set')) {
            return;
        }

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped runtime tuning is intentional for long-running admin-triggered generation requests.
        ini_set($option_name, $value);
    }

    private function can_use_function(string $function_name): bool
    {
        return function_exists($function_name) && !in_array($function_name, $this->disabled_functions, true);
    }

    private function get_disabled_functions(): array
    {
        if (!function_exists('ini_get')) {
            return [];
        }

        $disabled_functions = (string) ini_get('disable_functions');
        if ($disabled_functions === '') {
            return [];
        }

        return array_map('trim', explode(',', $disabled_functions));
    }
}

namespace WPAICG\ContentWriter\Ajax\Actions\Shared;

use WPAICG\ContentWriter\Ajax\AIPKit_Content_Writer_Base_Ajax_Action;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config;
use WPAICG\AIPKit_Providers;
use WP_Error;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_System_Instruction_Builder;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_User_Prompt_Builder;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;
use WPAICG\Core\AIPKit_AI_Caller;

/**
 * Validates the input for content generation AJAX actions.
 * UPDATED: Simplified to remove guided mode fields.
 *
 * @param AIPKit_Content_Writer_Base_Ajax_Action $handler The handler instance.
 * @param array $settings The raw POST data.
 * @return array|WP_Error An array of validated parameters or a WP_Error on failure.
 */
function validate_and_normalize_input_logic(AIPKit_Content_Writer_Base_Ajax_Action $handler, array $settings)
{
    $permission_check = $handler->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
    if (is_wp_error($permission_check)) {
        return $permission_check;
    }

    if (class_exists(AIPKit_Content_Writer_SEO_Config::class)) {
        $seo_permission_check = AIPKit_Content_Writer_SEO_Config::require_pro_for_improvement($settings);
        if (is_wp_error($seo_permission_check)) {
            return $seo_permission_check;
        }
        $settings = AIPKit_Content_Writer_SEO_Config::normalize($settings);
    }

    $content_title_raw = isset($settings['content_title']) ? sanitize_text_field(wp_unslash($settings['content_title'])) : '';
    if (empty($content_title_raw)) {
        return new WP_Error('missing_title', __('Content title/topic is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    // --- START: Parse title and keywords ---
    $topic = $content_title_raw;
    $inline_keywords = '';
    if (strpos($content_title_raw, '|') !== false) {
        $parts = explode('|', $content_title_raw, 2);
        $topic = trim($parts[0]);
        $inline_keywords = isset($parts[1]) ? trim($parts[1]) : ''; // Only take the second part as keywords
    }
    // --- END: Parse ---

    $provider_raw = isset($settings['ai_provider']) && !empty($settings['ai_provider'])
                   ? sanitize_text_field($settings['ai_provider'])
                   : AIPKit_Providers::get_current_provider();

    $provider = AIPKit_Providers::normalize_provider_label($provider_raw);

    $model_data = AIPKit_Providers::get_provider_data($provider);
    $model = isset($settings['ai_model']) && !empty($settings['ai_model'])
             ? sanitize_text_field($settings['ai_model'])
             : ($model_data['model'] ?? '');

    if (empty($model)) {
        return new WP_Error('missing_model', __('AI model selection is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    $content_length = isset($settings['content_length'])
        ? sanitize_key($settings['content_length'])
        : '';
    if (!in_array($content_length, ['short', 'medium', 'long'], true)) {
        $content_length = 'medium';
    }

    $rss_description = isset($settings['rss_description'])
        ? sanitize_textarea_field(wp_unslash($settings['rss_description']))
        : '';
    $url_content_context = isset($settings['url_content_context'])
        ? sanitize_textarea_field(wp_unslash($settings['url_content_context']))
        : '';
    $source_url = isset($settings['source_url'])
        ? esc_url_raw(wp_unslash($settings['source_url']))
        : '';

    $validated_params = $settings;
    $validated_params['content_title'] = $topic; // Use parsed topic
    $validated_params['inline_keywords'] = $inline_keywords; // Add parsed keywords
    $validated_params['provider'] = $provider;
    $validated_params['model'] = $model;
    $validated_params['content_length'] = $content_length;
    $validated_params['rss_description'] = $rss_description;
    $validated_params['url_content_context'] = $url_content_context;
    $validated_params['source_url'] = $source_url;

    $vector_provider = sanitize_key((string) ($settings['vector_store_provider'] ?? 'openai'));
    if (!in_array($vector_provider, ['local', 'openai', 'google', 'pinecone', 'qdrant', 'chroma'], true)) {
        $vector_provider = 'openai';
    }
    if (($settings['enable_vector_store'] ?? '0') === '1' && $vector_provider === 'google' && $provider !== 'Google') {
        return new WP_Error(
            'google_file_search_provider_mismatch',
            __('Google File Search requires Google as the AI provider.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }
    $validated_params['vector_store_provider'] = $vector_provider;
    $google_store_names = isset($settings['google_file_search_store_names']) && is_array($settings['google_file_search_store_names'])
        ? $settings['google_file_search_store_names']
        : [];
    $validated_params['google_file_search_store_names'] = array_values(array_unique(array_filter(array_map(
        static function ($store_name): string {
            $store_name = sanitize_text_field((string) $store_name);
            return strpos($store_name, 'fileSearchStores/') === 0 ? $store_name : '';
        },
        $google_store_names
    ))));

    return $validated_params;
}

/**
 * Builds the system instruction and user prompt for the Content Writer.
 * UPDATED: Simplified to remove guided mode logic. Replaces placeholders in custom prompt.
 *
 * @param array $validated_params The validated settings from the request.
 * @return array|WP_Error An array containing 'system_instruction' and 'user_prompt' or WP_Error.
 */
function build_prompts_logic(array $validated_params)
{
    if (!class_exists(AIPKit_Content_Writer_System_Instruction_Builder::class) || !class_exists(AIPKit_Content_Writer_User_Prompt_Builder::class)) {
        return new WP_Error('dependency_missing', 'Content writer prompt builders are unavailable.');
    }

    // 1. Build the system instruction. This function is now very simple.
    $system_instruction = AIPKit_Content_Writer_System_Instruction_Builder::build($validated_params);

    // 2. Build the user prompt *template*. This will now only return the custom prompt.
    $user_prompt_template = AIPKit_Content_Writer_User_Prompt_Builder::build($validated_params);
    if (empty($user_prompt_template)) {
        return new WP_Error('missing_prompt', 'Content Prompt cannot be empty.', ['status' => 400]);
    }

    // 3. Replace placeholders in the final prompt template.
    // The `content_title` in $validated_params has already been parsed.
    $final_title_for_prompt = $validated_params['content_title'] ?? 'AI Generated Content';
    // Prioritize inline keywords, fall back to global, then to empty.
    $final_keywords = !empty($validated_params['inline_keywords']) ? $validated_params['inline_keywords'] : ($validated_params['content_keywords'] ?? '');
    $rss_description = $validated_params['rss_description'] ?? '';
    $url_content_context = $validated_params['url_content_context'] ?? '';
    $source_url = $validated_params['source_url'] ?? '';

    $user_prompt = str_replace('{topic}', $final_title_for_prompt, $user_prompt_template);
    $user_prompt = str_replace('{keywords}', $final_keywords, $user_prompt);
    $user_prompt = str_replace('{description}', $rss_description, $user_prompt);
    $user_prompt = str_replace('{url_content}', $url_content_context, $user_prompt);
    $user_prompt = str_replace('{source_url}', $source_url, $user_prompt);

    return [
        'system_instruction' => $system_instruction,
        'user_prompt' => $user_prompt,
    ];
}

/**
 * Prepares an array of AI parameter overrides from the submitted settings.
 * This does NOT merge with global defaults; it only prepares the override values.
 *
 * @param array $settings The validated settings from the request.
 * @return array The array of AI parameter overrides.
 */
function prepare_ai_params_logic(array $settings): array
{
    $ai_params_override = [];

    if (isset($settings['ai_temperature'])) {
        $ai_params_override['temperature'] = floatval($settings['ai_temperature']);
    }

    $max_completion_tokens = null;
    if (isset($settings['max_completion_tokens']) && is_numeric($settings['max_completion_tokens'])) {
        $max_completion_tokens = absint($settings['max_completion_tokens']);
    } elseif (isset($settings['max_tokens']) && is_numeric($settings['max_tokens'])) {
        $max_completion_tokens = absint($settings['max_tokens']);
    } else {
        $content_length = isset($settings['content_length'])
            ? sanitize_key($settings['content_length'])
            : '';
        $length_map = [
            'short' => 2000,
            'medium' => 4000,
            'long' => 6000,
        ];
        if (isset($length_map[$content_length])) {
            $max_completion_tokens = $length_map[$content_length];
        }
    }
    if ($max_completion_tokens) {
        $ai_params_override['max_completion_tokens'] = $max_completion_tokens;
    }
    // Add provider-specific reasoning / think controls.
    if (($settings['provider'] ?? '') === 'OpenAI') {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
            (string) ($settings['ai_model'] ?? ''),
            $settings['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($settings['provider'] ?? '') === 'AIPufferCloud') {
        $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) ($settings['ai_model'] ?? ''), $settings['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($settings['provider'] ?? '') === 'OpenRouter') {
        $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
            (string) ($settings['ai_model'] ?? ''),
            $settings['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($settings['provider'] ?? '') === 'Ollama') {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($settings['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '' && $reasoning_effort !== 'none') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    }

    $ai_params_override['top_p'] = null;

    return $ai_params_override;
}

/**
 * Logs the initial user request for content generation (both standard and stream).
 *
 * @param AIPKit_Content_Writer_Base_Ajax_Action $handler The handler instance.
 * @param array $request_data The validated and normalized request parameters.
 * @param string $request_type A string indicating the request type (e.g., 'Stream Init', 'AJAX').
 * @return void
 */
function log_initial_request_logic(AIPKit_Content_Writer_Base_Ajax_Action $handler, array $request_data, string $request_type): void
{
    if (!$handler->log_storage) {
        return;
    }

    $initial_request_details_for_log = [
        'title'              => $request_data['content_title'] ?? '',
        'keywords'           => $request_data['content_keywords'] ?? null,
    ];

    // Reuse provided conversation_uuid when available so all steps belong to one session
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $conversation_uuid = $handler->ensure_content_writer_conversation_uuid(
        isset($request_data['conversation_uuid']) && !empty($request_data['conversation_uuid'])
            ? sanitize_text_field($request_data['conversation_uuid'])
            : ''
    );

    $handler->log_storage->log_message(array_merge($handler->build_content_writer_log_base(
        $conversation_uuid,
        (string) ($request_data['provider'] ?? ''),
        (string) ($request_data['model'] ?? '')
    ), [
        'message_role'      => 'user',
        'message_content'   => "Content Writer Request ({$request_type}): " . esc_html($request_data['content_title']),
        'request_payload'   => $initial_request_details_for_log
    ]));
}

/**
 * Updates the Google Sheets status column for Content Writer gsheets mode.
 *
 * @param array  $settings The current action payload or sanitized settings.
 * @param string $status_prefix Status prefix such as "Queued on" or "Processed on".
 * @return bool|WP_Error True on success or no-op, WP_Error on failure.
 */
function maybe_update_gsheets_row_status_logic(array $settings, string $status_prefix)
{
    if (sanitize_key((string) ($settings['cw_generation_mode'] ?? '')) !== 'gsheets') {
        return true;
    }
    $paid_actions = paid_actions_class_logic();
    if ($paid_actions) {
        return $paid_actions::maybe_update_gsheets_row_status_logic($settings, $status_prefix);
    }
    if (sanitize_text_field((string) ($settings['gsheets_sheet_id'] ?? '')) === '' || absint($settings['gsheets_row_index'] ?? 0) <= 0) {
        return new WP_Error('missing_gsheets_status_context', __('Google Sheets status update is missing the sheet ID or row index.', 'gpt3-ai-content-generator'));
    }
    return new WP_Error('gsheets_credentials_handler_missing', __('Google Sheets credentials handler is unavailable.', 'gpt3-ai-content-generator'));
}

/**
 * Resolves duplicate or unsuitable Smart SEO focus keyphrases before dependent generation steps.
 */
function resolve_smart_seo_keywords_logic(array $validated_params, ?AIPKit_AI_Caller $ai_caller, array $context = []): array
{
    $paid_actions = paid_actions_class_logic();
    return $paid_actions
        ? $paid_actions::resolve_smart_seo_keywords_logic($validated_params, $ai_caller, $context)
        : ['params' => $validated_params, 'resolution' => []];
}

function smart_seo_keyword_resolution_response_fields_logic(array $resolution): array
{
    if (empty($resolution['changed'])) {
        return [];
    }

    return [
        'resolved_focus_keyword' => sanitize_text_field((string) ($resolution['keyword'] ?? '')),
        'resolved_keywords' => sanitize_text_field((string) ($resolution['keywords'] ?? '')),
        'resolved_keyword_source' => sanitize_key((string) ($resolution['source'] ?? '')),
        'resolved_content_title' => sanitize_text_field((string) ($resolution['resolved_content_title'] ?? '')),
        'resolved_keyword_original' => sanitize_text_field((string) ($resolution['original_keyword'] ?? '')),
        'resolved_keyword_used_count' => absint($resolution['used_count'] ?? 0),
        'resolved_keyword_reason' => sanitize_key((string) ($resolution['reason'] ?? '')),
    ];
}

/**
 * @param mixed $primary_usage
 * @param mixed $secondary_usage
 * @return mixed
 */
function merge_smart_seo_usage_logic($primary_usage, $secondary_usage)
{
    if (!is_array($secondary_usage)) {
        return $primary_usage;
    }

    if (!is_array($primary_usage)) {
        return $secondary_usage;
    }

    $merged = $primary_usage;
    $merged['input_tokens'] = (int) ($primary_usage['input_tokens'] ?? 0) + (int) ($secondary_usage['input_tokens'] ?? 0);
    $merged['output_tokens'] = (int) ($primary_usage['output_tokens'] ?? 0) + (int) ($secondary_usage['output_tokens'] ?? 0);
    $merged['total_tokens'] = (int) ($primary_usage['total_tokens'] ?? 0) + (int) ($secondary_usage['total_tokens'] ?? 0);

    if (isset($secondary_usage['provider_raw'])) {
        $primary_provider_raw = isset($primary_usage['provider_raw']) ? $primary_usage['provider_raw'] : [];
        $merged['provider_raw'] = [$primary_provider_raw, $secondary_usage['provider_raw']];
    }

    return $merged;
}

/** A missing lib directory is normal in the Freemius Free artifact. */
function paid_actions_class_logic(): ?string
{
    $class_name = '\\WPAICG\\Lib\\ContentWriter\\Actions';
    if (!class_exists($class_name) && defined('WPAICG_LIB_DIR')) {
        $path = WPAICG_LIB_DIR . 'content-writer/actions.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
    return class_exists($class_name) ? $class_name : null;
}

/** Preserves the shared response shape when the paid keyword resolver is absent. */
function resolve_generated_keyword_logic(string $keyword, array $config, ?AIPKit_AI_Caller $ai_caller, array $context): array
{
    $paid_actions = paid_actions_class_logic();
    return $paid_actions ? $paid_actions::resolve_generated_keyword($keyword, $config, $ai_caller, $context) : [];
}

namespace WPAICG\ContentWriter\Ajax\Actions\InitStream;

use WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache;
use WP_Error;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\ContentWriter\Ajax\Actions\Shared;
use WPAICG\Core\Models\AIPKit_Model_Catalog;

/**
* Ensures the SSE Cache class is available, loading it if necessary.
*
* @return true|WP_Error True on success, WP_Error on failure.
*/
function ensure_sse_cache_available_logic()
{
    if (!class_exists(AIPKit_SSE_Message_Cache::class)) {
        $sse_cache_path = WPAICG_PLUGIN_DIR . 'classes/streaming/cache.php';
        if (file_exists($sse_cache_path)) {
            require_once $sse_cache_path;
        } else {
            return new WP_Error('dependency_missing', __('SSE Caching component is missing.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
    }
    return true;
}

/**
 * Merges global AI settings with form-specific parameter overrides.
 * This is specific to the stream initializer, which needs the fully merged
 * parameters before caching.
 *
 * @param array $settings The sanitized settings from the request.
 * @return array The final merged AI parameters.
 */
function merge_ai_params_logic(array $settings): array
{
    $ai_params_from_form = Shared\prepare_ai_params_logic($settings);
    $global_ai_params = AIPKIT_AI_Settings::get_ai_parameters();
    return array_merge($global_ai_params, $ai_params_from_form);
}

/**
* Builds the structured payload to be stored in the SSE cache.
* UPDATED: Removed guided mode fields.
*
* @param string $system_instruction The built system instruction.
* @param string $user_prompt The built user prompt.
* @param string $provider The normalized provider name.
* @param string $model The normalized model name.
* @param array $ai_params_for_cache The merged AI parameters.
* @param array $settings The sanitized settings from the request.
* @return array The structured cache payload.
*/
function build_cache_payload_logic(
    string $system_instruction,
    string $user_prompt,
    string $provider,
    string $model,
    array $ai_params_for_cache,
    array $settings
): array {
    // Reuse conversation_uuid from the request if present; otherwise generate a new one
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $provided_uuid = isset($settings['conversation_uuid']) ? sanitize_text_field(wp_unslash($settings['conversation_uuid'])) : '';
    $conversation_uuid = !empty($provided_uuid) ? $provided_uuid : wp_generate_uuid4();
    return [
    'stream_context' => 'content_writer',
    'system_instruction' => $system_instruction,
    'user_message' => $user_prompt,
    'provider' => $provider,
    'model' => $model,
    'ai_params' => $ai_params_for_cache,
    'conversation_uuid' => $conversation_uuid,
    'user_id' => get_current_user_id(),
    'bot_id' => null,
    'session_id' => null,
    'post_id' => 0,
    'initial_request_details' => [
    'title' => $settings['content_title'] ?? '',
    'keywords' => $settings['content_keywords'] ?? null,
    'inline_keywords' => $settings['inline_keywords'] ?? '',
    'generate_meta_description' => $settings['generate_meta_description'] ?? '0',
    'custom_meta_prompt' => $settings['custom_meta_prompt'] ?? '',
    'generate_focus_keyword' => $settings['generate_focus_keyword'] ?? '0',
    'custom_keyword_prompt' => $settings['custom_keyword_prompt'] ?? '',
    'generate_images_enabled' => $settings['generate_images_enabled'] ?? '0',
    'image_provider' => $settings['image_provider'] ?? 'openai',
    'image_model' => $settings['image_model'] ?? AIPKit_Model_Catalog::get_default_id('OpenAIImage'),
    'image_provider_options' => $settings['image_provider_options'] ?? '{}',
    'image_prompt' => $settings['image_prompt'] ?? '',
    'image_count' => $settings['image_count'] ?? 1,
    'image_placement' => $settings['image_placement'] ?? 'after_first_h2',
    'image_placement_param_x' => $settings['image_placement_param_x'] ?? 2,
    'generate_featured_image' => $settings['generate_featured_image'] ?? '0',
    'featured_image_prompt' => $settings['featured_image_prompt'] ?? '',
    'pexels_orientation' => $settings['pexels_orientation'] ?? 'none',
    'pexels_size' => $settings['pexels_size'] ?? 'none',
    'pexels_color' => $settings['pexels_color'] ?? '',
    'pixabay_orientation' => $settings['pixabay_orientation'] ?? 'all',
    'pixabay_image_type' => $settings['pixabay_image_type'] ?? 'all',
    'pixabay_category' => $settings['pixabay_category'] ?? '',
    ],
    'enable_vector_store'           => $settings['enable_vector_store'] ?? '0',
    'vector_store_provider'         => $settings['vector_store_provider'] ?? 'openai',
    'openai_vector_store_ids'       => $settings['openai_vector_store_ids'] ?? [],
    'google_file_search_store_names' => $settings['google_file_search_store_names'] ?? [],
    'pinecone_index_name'           => $settings['pinecone_index_name'] ?? '',
    'qdrant_collection_name'        => $settings['qdrant_collection_name'] ?? '',
    'chroma_collection_name'        => $settings['chroma_collection_name'] ?? '',
    'local_store_id'                => sanitize_text_field((string) ($settings['local_store_id'] ?? '')),
    'vector_embedding_provider'     => $settings['vector_embedding_provider'] ?? 'openai',
    'vector_embedding_model'        => $settings['vector_embedding_model'] ?? '',
    'vector_store_top_k'            => isset($settings['vector_store_top_k']) ? absint($settings['vector_store_top_k']) : 3,
    'vector_store_confidence_threshold' => isset($settings['vector_store_confidence_threshold']) ? max(0, min(absint($settings['vector_store_confidence_threshold']), 100)) : 20,
    ];
}

/**
* Writes the payload to the SSE cache and returns the cache key.
*
* @param array $data_to_cache The structured payload to cache.
* @return string|WP_Error The cache key on success, or WP_Error on failure.
*/
function write_to_sse_cache_logic(array $data_to_cache)
{
    $sse_message_cache = new AIPKit_SSE_Message_Cache();
    $cache_key_result = $sse_message_cache->set(wp_json_encode($data_to_cache));

    if (is_wp_error($cache_key_result)) {
        return $cache_key_result;
    }
    return $cache_key_result;
}

namespace WPAICG\ContentWriter\Ajax\Actions\StandardGeneration;

use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Standard_Generation_Action;
use WP_Error;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner;
use WPAICG\Core\AIPKit_Event_Webhooks;
use function WPAICG\ContentWriter\Ajax\Actions\Shared\merge_smart_seo_usage_logic;
use function WPAICG\ContentWriter\Ajax\Actions\Shared\smart_seo_keyword_resolution_response_fields_logic;

/**
 * Makes the call to the AI provider using the AI Caller.
 *
 * @param AIPKit_Content_Writer_Standard_Generation_Action $handler The handler instance.
 * @param string $provider The AI provider.
 * @param string $model The AI model.
 * @param array $messages The message payload for the API.
 * @param array $ai_params_override AI parameters to override globals.
 * @param string $system_instruction The system instruction for the AI.
 * @param array $form_data Validated settings for knowledge retrieval.
 * @return array|WP_Error The result from the AI Caller.
 */
function call_ai_provider_logic(
    AIPKit_Content_Writer_Standard_Generation_Action $handler,
    string $provider,
    string $model,
    array $messages,
    array $ai_params_override,
    string $system_instruction,
    array $form_data = []
) {
    $vector_preparation = $handler->prepare_content_writer_vector_context(
        $messages[0]['content'] ?? '',
        $provider,
        $system_instruction,
        $ai_params_override,
        $form_data
    );
    if (is_wp_error($vector_preparation)) {
        return $vector_preparation;
    }
    [$system_instruction, $ai_params_override, $instruction_context] = $vector_preparation;

    return $handler->get_ai_caller()->make_standard_call(
        $provider,
        $model,
        $messages,
        $ai_params_override,
        $system_instruction,
        $instruction_context
    );
}

/**
 * Handles an error response from the AI call by logging it and sending a JSON error.
 *
 * @param AIPKit_Content_Writer_Standard_Generation_Action $handler The handler instance.
 * @param WP_Error $error The error object returned from the AI call.
 * @param array $validated_params The validated parameters from the request.
 * @param string $conversation_uuid The UUID of this interaction.
 * @return void
 */
function handle_error_response_logic(AIPKit_Content_Writer_Standard_Generation_Action $handler, WP_Error $error, array $validated_params, string $conversation_uuid): void
{
    if ($handler->log_storage) {
        $error_data = $error->get_error_data() ?? [];
        $request_payload_log_on_error = is_array($error_data) ? ($error_data['request_payload_log'] ?? null) : null;

        $handler->log_storage->log_message(array_merge($handler->build_content_writer_log_base(
            $conversation_uuid,
            (string) ($validated_params['provider'] ?? ''),
            (string) ($validated_params['model'] ?? '')
        ), [
            'message_role'      => 'bot',
            'message_content'   => "Error generating content (AJAX): " . $error->get_error_message(),
            'request_payload'   => $request_payload_log_on_error,
        ]));
    }
    $handler->send_wp_error($error);
}

/**
 * Handles a successful response from the AI call by logging it and sending a JSON success response.
 * @param AIPKit_Content_Writer_Standard_Generation_Action $handler The handler instance.
 * @param array $result The successful result array from the AI call.
 * @param array $validated_params The validated parameters from the request.
 * @param string $conversation_uuid The UUID of this interaction.
 * @return void
 */
function handle_success_response_logic(AIPKit_Content_Writer_Standard_Generation_Action $handler, array $result, array $validated_params, string $conversation_uuid): void
{
    $content = $result['content'] ?? '';
    if (class_exists(AIPKit_Content_Writer_Output_Cleaner::class)) {
        $initial_keywords = !empty($validated_params['inline_keywords']) ? $validated_params['inline_keywords'] : ($validated_params['content_keywords'] ?? '');
        $initial_focus_keyword = trim((string) explode(',', (string) $initial_keywords)[0]);
        $content = AIPKit_Content_Writer_Output_Cleaner::clean_article_content((string) $content, $initial_focus_keyword);
    }
    $usage = $result['usage'] ?? null;
    $request_payload_log = $result['request_payload_log'] ?? null;
    $meta_description = null;
    $focus_keyword = null;
    $excerpt = null;
    $tags = null;
    $smart_seo_keyword_resolution = isset($validated_params['smart_seo_keyword_resolution']) && is_array($validated_params['smart_seo_keyword_resolution'])
        ? $validated_params['smart_seo_keyword_resolution']
        : [];

    $stop_on_refusal = static function ($response) use ($handler, $content, $usage, $conversation_uuid, &$meta_description, &$focus_keyword, &$excerpt, &$tags): void {
        if (!is_wp_error($response)) {
            return;
        }
        $data = $response->get_error_data();
        if (is_array($data) && !empty($data['stop_batch'])) {
            $data['generation_result'] = [
                'content' => $content, 'usage' => $usage, 'conversation_uuid' => $conversation_uuid,
                'meta_description' => $meta_description, 'focus_keyword' => $focus_keyword,
                'excerpt' => $excerpt, 'tags' => $tags,
            ];
            $response->add_data($data);
            $handler->send_wp_error($response);
        }
    };

    // Log main content generation
    if ($handler->log_storage) {
        $handler->log_storage->log_message(array_merge($handler->build_content_writer_log_base(
            $conversation_uuid,
            (string) ($validated_params['provider'] ?? ''),
            (string) ($validated_params['model'] ?? '')
        ), [
            'message_role'      => 'bot',
            'message_content'   => $content,
            'usage'             => $usage,
            'request_payload'   => $request_payload_log,
        ]));
    }

    $final_title = $validated_params['content_title'] ?? '';
    $user_provided_keywords = !empty($validated_params['inline_keywords']) ? $validated_params['inline_keywords'] : ($validated_params['content_keywords'] ?? '');

    $keywords_for_prompts = $user_provided_keywords;

    // 1. Generate Focus Keyword FIRST if needed.
    $generate_keyword = ($validated_params['generate_focus_keyword'] ?? '0') === '1';
    if ($generate_keyword && empty($user_provided_keywords) && !empty($content)) {
        $content_summary_for_kw = \WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Summarizer::summarize($content);
        $keyword_user_prompt = \WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Keyword_Prompt_Builder::build($final_title, $content_summary_for_kw, 'custom', $validated_params['custom_keyword_prompt']);
        $keyword_ai_params = ['temperature' => 1, 'top_p' => null];
        $keyword_result = $handler->get_ai_caller()->make_standard_call(
            $validated_params['provider'],
            $validated_params['model'],
            [['role' => 'user', 'content' => $keyword_user_prompt]],
            $keyword_ai_params,
            'You are an SEO expert. Your task is to provide the single best focus keyword for a piece of content.'
        );
        $stop_on_refusal($keyword_result);
        if (!is_wp_error($keyword_result) && !empty($keyword_result['content'])) {
            $focus_keyword = trim(str_replace(['"', "'", '.'], '', $keyword_result['content']));
            $keywords_for_prompts = $focus_keyword; // Use this new keyword for other SEO prompts
            $keyword_usage = $keyword_result['usage'] ?? null;
            $keyword_resolution = \WPAICG\ContentWriter\Ajax\Actions\Shared\resolve_generated_keyword_logic(
                $focus_keyword,
                $validated_params,
                $handler->get_ai_caller(),
                [
                    'ai_provider' => $validated_params['provider'] ?? '',
                    'ai_model' => $validated_params['model'] ?? '',
                    'topic' => $validated_params['content_title'] ?? '',
                    'title' => $final_title,
                    'content_summary' => $content_summary_for_kw,
                ]
            );
            if (!empty($keyword_resolution['changed'])) {
                $focus_keyword = (string) $keyword_resolution['keyword'];
                $keywords_for_prompts = $focus_keyword;
                $keyword_usage = merge_smart_seo_usage_logic($keyword_usage, $keyword_resolution['usage'] ?? null);
                $smart_seo_keyword_resolution = $keyword_resolution;
            }
            // Log keyword step
            if ($handler->log_storage) {
                $base = $handler->build_content_writer_log_base($conversation_uuid, (string) $validated_params['provider'], (string) $validated_params['model']);
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'user',
                    'message_content' => 'Generate Focus Keyword',
                    'request_payload' => [
                        'title' => $final_title,
                        'custom_keyword_prompt' => $validated_params['custom_keyword_prompt'] ?? null,
                    ],
                ]));
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'bot',
                    'message_content' => $focus_keyword,
                    'usage' => $keyword_usage,
                    'request_payload' => [
                        'payload_sent' => [
                            'messages' => [['role' => 'user', 'content' => $keyword_user_prompt]],
                            'ai_params' => $keyword_ai_params,
                        ],
                    ],
                ]));
            }
        }
    } elseif ($generate_keyword) {
        $focus_keyword = explode(',', $user_provided_keywords)[0];
    }

    $content_summary = \WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Summarizer::summarize($content);

    // 2. Generate Excerpt
    if (($validated_params['generate_excerpt'] ?? '0') === '1' && !empty($content)) {
        $excerpt_user_prompt = \WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Excerpt_Prompt_Builder::build($final_title, $content_summary, $keywords_for_prompts, 'custom', $validated_params['custom_excerpt_prompt']);
        $excerpt_ai_params = ['temperature' => 1, 'top_p' => null];
        $excerpt_result = $handler->get_ai_caller()->make_standard_call($validated_params['provider'], $validated_params['model'], [['role' => 'user', 'content' => $excerpt_user_prompt]], $excerpt_ai_params);
        $stop_on_refusal($excerpt_result);
        if (!is_wp_error($excerpt_result) && !empty($excerpt_result['content'])) {
            $excerpt = trim(str_replace(['"', "'"], '', $excerpt_result['content']));
            if ($handler->log_storage) {
                $base = $handler->build_content_writer_log_base($conversation_uuid, (string) $validated_params['provider'], (string) $validated_params['model']);
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'user',
                    'message_content' => 'Generate Excerpt',
                    'request_payload' => [
                        'title' => $final_title,
                        'keywords' => $keywords_for_prompts,
                        'custom_excerpt_prompt' => $validated_params['custom_excerpt_prompt'] ?? null,
                    ],
                ]));
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'bot',
                    'message_content' => $excerpt,
                    'usage' => $excerpt_result['usage'] ?? null,
                    'request_payload' => [
                        'payload_sent' => [
                            'messages' => [['role' => 'user', 'content' => $excerpt_user_prompt]],
                            'ai_params' => $excerpt_ai_params,
                        ],
                    ],
                ]));
            }
        }
    }

    // 3. Generate Tags
    if (($validated_params['generate_tags'] ?? '0') === '1' && !empty($content)) {
        $tags_user_prompt = \WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Tags_Prompt_Builder::build($final_title, $content_summary, $keywords_for_prompts, 'custom', $validated_params['custom_tags_prompt']);
        $tags_ai_params = ['temperature' => 0.5, 'top_p' => null];
        $tags_result = $handler->get_ai_caller()->make_standard_call($validated_params['provider'], $validated_params['model'], [['role' => 'user', 'content' => $tags_user_prompt]], $tags_ai_params);
        $stop_on_refusal($tags_result);
        if (!is_wp_error($tags_result) && !empty($tags_result['content'])) {
            $tags = trim(str_replace(['"', "'"], '', $tags_result['content']));
            if ($handler->log_storage) {
                $base = $handler->build_content_writer_log_base($conversation_uuid, (string) $validated_params['provider'], (string) $validated_params['model']);
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'user',
                    'message_content' => 'Generate Tags',
                    'request_payload' => [
                        'title' => $final_title,
                        'keywords' => $keywords_for_prompts,
                        'custom_tags_prompt' => $validated_params['custom_tags_prompt'] ?? null,
                    ],
                ]));
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'bot',
                    'message_content' => $tags,
                    'usage' => $tags_result['usage'] ?? null,
                    'request_payload' => [
                        'payload_sent' => [
                            'messages' => [['role' => 'user', 'content' => $tags_user_prompt]],
                            'ai_params' => $tags_ai_params,
                        ],
                    ],
                ]));
            }
        }
    }

    // 4. Generate Meta Description
    if (($validated_params['generate_meta_description'] ?? '0') === '1' && !empty($content)) {
        $meta_user_prompt = \WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Meta_Prompt_Builder::build($final_title, $content_summary, $keywords_for_prompts, 'custom', $validated_params['custom_meta_prompt']);
        $meta_ai_params = ['temperature' => 1, 'top_p' => null];
        $meta_result = $handler->get_ai_caller()->make_standard_call($validated_params['provider'], $validated_params['model'], [['role' => 'user', 'content' => $meta_user_prompt]], $meta_ai_params);
        $stop_on_refusal($meta_result);
        if (!is_wp_error($meta_result) && !empty($meta_result['content'])) {
            $meta_description = AIPKit_Content_Writer_Output_Cleaner::clean_meta_description((string) $meta_result['content']);
            if ($handler->log_storage) {
                $base = $handler->build_content_writer_log_base($conversation_uuid, (string) $validated_params['provider'], (string) $validated_params['model']);
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'user',
                    'message_content' => 'Generate Meta Description',
                    'request_payload' => [
                        'title' => $final_title,
                        'keywords' => $keywords_for_prompts,
                        'custom_meta_prompt' => $validated_params['custom_meta_prompt'] ?? null,
                    ],
                ]));
                $handler->log_storage->log_message(array_merge($base, [
                    'message_role' => 'bot',
                    'message_content' => $meta_description,
                    'usage' => $meta_result['usage'] ?? null,
                    'request_payload' => [
                        'payload_sent' => [
                            'messages' => [['role' => 'user', 'content' => $meta_user_prompt]],
                            'ai_params' => $meta_ai_params,
                        ],
                    ],
                ]));
            }
        }
    }
    if (class_exists(AIPKit_Event_Webhooks::class) && !empty($content)) {
        $actor_user_id = get_current_user_id();
        AIPKit_Event_Webhooks::emit(
            'content.generated',
            [
                'title' => $final_title,
                'content' => $content,
                'excerpt' => $excerpt,
                'meta_description' => $meta_description,
                'focus_keyword' => $focus_keyword,
                'tags' => $tags,
                'conversation' => [
                    'id' => $conversation_uuid,
                ],
                'ai' => [
                    'provider' => $validated_params['provider'],
                    'model' => $validated_params['model'],
                ],
                'input' => [
                    'keywords' => $keywords_for_prompts,
                    'source_url' => $validated_params['source_url'] ?? '',
                    'content_length' => $validated_params['content_length'] ?? '',
                ],
                'actor' => [
                    'type' => $actor_user_id ? 'user' : 'guest',
                    'user_id' => $actor_user_id ?: null,
                ],
            ],
            [
                'module' => 'content_writer',
                'origin' => 'direct_standard',
                'resource' => [
                    'type' => 'content_generation',
                    'id' => $conversation_uuid,
                    'label' => $final_title !== '' ? $final_title : __('Generated content', 'gpt3-ai-content-generator'),
                ],
                'meta' => [
                    'provider' => $validated_params['provider'],
                    'model' => $validated_params['model'],
                    'conversation_uuid' => $conversation_uuid,
                ],
                'idempotency_key' => sha1(implode('|', [
                    'content.generated',
                    'direct_standard',
                    $conversation_uuid,
                    $final_title,
                    $validated_params['provider'],
                    $validated_params['model'],
                ])),
            ]
        );
    }

    wp_send_json_success(array_merge([
        'content' => $content,
        'usage' => $usage,
        'meta_description' => $meta_description,
        'focus_keyword' => $focus_keyword,
        'excerpt' => $excerpt,
    'tags' => $tags,
    'conversation_uuid' => $conversation_uuid,
        'image_data' => null // Non-streaming doesn't generate images for now.
    ], smart_seo_keyword_resolution_response_fields_logic($smart_seo_keyword_resolution)));
}

namespace WPAICG\ContentWriter\Ajax\Actions\GenerateTitle;

use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Title_Action;
use WPAICG\AIPKit_Providers;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;
use function WPAICG\ContentWriter\Ajax\Actions\Shared\smart_seo_keyword_resolution_response_fields_logic;

/**
 * Validates the input for the title generation AJAX action.
 *
 * @param AIPKit_Content_Writer_Generate_Title_Action $handler The handler instance.
 * @return array|WP_Error An array of validated parameters or a WP_Error on failure.
 */
function validate_and_normalize_input_logic(AIPKit_Content_Writer_Generate_Title_Action $handler)
{
    $permission_check = $handler->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
    if (is_wp_error($permission_check)) {
        return $permission_check;
    }

    if (!$handler->get_ai_caller()) {
        return new WP_Error('ai_caller_missing', __('AI processing component is unavailable.', 'gpt3-ai-content-generator'), ['status' => 500]);
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_module_access_permissions method.
    $settings = isset($_POST) ? wp_unslash($_POST) : [];
    $original_title = isset($settings['content_title']) ? sanitize_text_field(wp_unslash($settings['content_title'])) : '';

    if (empty($original_title)) {
        return new WP_Error('missing_original_title', __('Original title/topic is required to generate a new title.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    // --- START: Parse title and keywords ---
    $topic = $original_title;
    $inline_keywords = '';
    if (strpos($original_title, '|') !== false) {
        $parts = explode('|', $original_title, 2);
        $topic = trim($parts[0]);
        $inline_keywords = isset($parts[1]) ? trim($parts[1]) : ''; // Only take the second part as keywords
    }
    // --- END: Parse ---

    $provider_raw = isset($settings['ai_provider']) && !empty($settings['ai_provider'])
                   ? sanitize_text_field($settings['ai_provider'])
                   : AIPKit_Providers::get_current_provider();

    $provider = AIPKit_Providers::normalize_provider_label($provider_raw);

    $model_data = AIPKit_Providers::get_provider_data($provider);
    $model = isset($settings['ai_model']) && !empty($settings['ai_model'])
             ? sanitize_text_field($settings['ai_model'])
             : ($model_data['model'] ?? '');

    if (empty($model)) {
        return new WP_Error('missing_model_title_gen', __('AI model selection is required for title generation.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    // Sanitize other used fields that might be passed in
    $settings['ai_temperature'] = isset($settings['ai_temperature']) ? floatval($settings['ai_temperature']) : 1.0;
    $settings['custom_title_prompt'] = isset($settings['custom_title_prompt']) ? AIPKit_Prompt_Sanitizer::sanitize($settings['custom_title_prompt']) : '';
    $settings['rss_description'] = isset($settings['rss_description'])
        ? sanitize_textarea_field(wp_unslash($settings['rss_description']))
        : '';
    $settings['url_content_context'] = isset($settings['url_content_context'])
        ? sanitize_textarea_field(wp_unslash($settings['url_content_context']))
        : '';
    $settings['source_url'] = isset($settings['source_url'])
        ? esc_url_raw(wp_unslash($settings['source_url']))
        : '';

    // Return the full set of validated and normalized parameters
    $validated_params = $settings;
    $validated_params['content_title'] = $topic; // Use parsed topic
    $validated_params['inline_keywords'] = $inline_keywords; // Add parsed keywords
    $validated_params['provider'] = $provider;
    $validated_params['model'] = $model;

    return $validated_params;
}

/**
 * Builds the system instruction and user prompt for title generation.
 * UPDATED: Simplified to only use the custom title prompt, as guided mode is removed.
 *
 * @param array $validated_params The validated parameters from the request.
 * @return array An array containing 'system_instruction' and 'user_prompt'.
 */
function build_title_prompt_logic(array $validated_params): array
{
    $system_instruction = "You are an expert copywriter specializing in crafting engaging headlines.";

    // Use the custom prompt from settings, or the central default if empty.
    $user_prompt_template = $validated_params['custom_title_prompt'] ?? AIPKit_Content_Writer_Prompts::get_default_title_prompt();
    if (empty(trim($user_prompt_template))) {
        $user_prompt_template = AIPKit_Content_Writer_Prompts::get_default_title_prompt();
    }

    // Replace placeholders
    $final_title_for_prompt = $validated_params['content_title'] ?? '';
    $final_keywords_for_prompt = !empty($validated_params['inline_keywords']) ? $validated_params['inline_keywords'] : ($validated_params['content_keywords'] ?? '');

    $rss_description = $validated_params['rss_description'] ?? '';
    $url_content_context = $validated_params['url_content_context'] ?? '';
    $source_url = $validated_params['source_url'] ?? '';

    $user_prompt = str_replace('{topic}', $final_title_for_prompt, $user_prompt_template);
    $user_prompt = str_replace('{keywords}', $final_keywords_for_prompt, $user_prompt);
    $user_prompt = str_replace('{description}', $rss_description, $user_prompt);
    $user_prompt = str_replace('{url_content}', $url_content_context, $user_prompt);
    $user_prompt = str_replace('{source_url}', $source_url, $user_prompt);

    return [
        'user_prompt' => $user_prompt,
        'system_instruction' => $system_instruction,
    ];
}

/**
 * Prepares the final AI parameters by merging global settings with form-specific overrides for title generation.
 *
 * @param array $validated_params The validated settings from the request.
 * @return array The array of AI parameter overrides.
 */
function prepare_ai_params_logic(array $validated_params): array
{
    $ai_params_override = [];

    if (isset($validated_params['ai_temperature'])) {
        $ai_params_override['temperature'] = floatval($validated_params['ai_temperature']);
    }

    // Add provider-specific reasoning / think controls.
    if (($validated_params['provider'] ?? '') === 'OpenAI') {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
            (string) ($validated_params['ai_model'] ?? ''),
            $validated_params['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($validated_params['provider'] ?? '') === 'AIPufferCloud') {
        $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) ($validated_params['ai_model'] ?? ''), $validated_params['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($validated_params['provider'] ?? '') === 'OpenRouter') {
        $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
            (string) ($validated_params['ai_model'] ?? ''),
            $validated_params['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($validated_params['provider'] ?? '') === 'Ollama') {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($validated_params['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '' && $reasoning_effort !== 'none') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    }

    $ai_params_override['top_p'] = null;

    return $ai_params_override;
}

/**
 * Makes the call to the AI provider using the AI Caller.
 *
 * @param AIPKit_Content_Writer_Generate_Title_Action $handler The handler instance.
 * @param string $provider The AI provider.
 * @param string $model The AI model.
 * @param array $messages The message payload for the API.
 * @param array $ai_params_override AI parameters to override globals.
 * @param string $system_instruction The system instruction for the AI.
 * @param array $form_data The form data containing vector settings.
 * @return array|WP_Error The result from the AI Caller.
 */
function call_title_generator_logic(
    AIPKit_Content_Writer_Generate_Title_Action $handler,
    string $provider,
    string $model,
    array $messages,
    array $ai_params_override,
    string $system_instruction,
    array $form_data = []
) {
    $user_message = $messages[0]['content'] ?? '';
    $vector_preparation = $handler->prepare_content_writer_vector_context(
        $user_message,
        $provider,
        $system_instruction,
        $ai_params_override,
        $form_data
    );
    if (is_wp_error($vector_preparation)) { return $vector_preparation; }
    [$system_instruction, $ai_params_override, $instruction_context] = $vector_preparation;

    return $handler->get_ai_caller()->make_standard_call(
        $provider,
        $model,
        $messages,
        $ai_params_override,
        $system_instruction,
        $instruction_context
    );
}

/**
 * Handles the response from the AI call, cleaning it and sending a JSON response.
 * Also logs the request/response under the same conversation if conversation_uuid is provided.
 *
 * @param AIPKit_Content_Writer_Generate_Title_Action $handler The handler instance.
 * @param array|WP_Error $result The result from the AI Caller.
 * @param array $validated_params The validated request parameters.
 * @param array $prompts The prompts array with 'user_prompt' and 'system_instruction'.
 * @param array $ai_params_override The AI params used.
 * @return void
 */
function handle_title_response_logic(
    AIPKit_Content_Writer_Generate_Title_Action $handler,
    $result,
    array $validated_params,
    array $prompts,
    array $ai_params_override
): void
{
    if (is_wp_error($result)) {
        $handler->send_wp_error($result);
        return;
    }

    $generated_title = trim($result['content'] ?? '');

    // Clean up potential extra formatting from the AI
    if (preg_match('/^"(.*)"$/', $generated_title, $matches)) {
        $generated_title = $matches[1];
    }
    $generated_title = trim(str_replace(["\n", "\r"], ' ', $generated_title));
    $generated_title = preg_replace('/\s+/', ' ', $generated_title);
    $focus_keyword_for_title = '';
    foreach (['inline_keywords', 'content_keywords'] as $keyword_key) {
        if (!empty($validated_params[$keyword_key])) {
            $keyword_parts = array_map('trim', explode(',', (string) $validated_params[$keyword_key]));
            $focus_keyword_for_title = (string) ($keyword_parts[0] ?? '');
            break;
        }
    }
    if (class_exists(\WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::class)) {
        $generated_title = \WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::clean_title($generated_title, $focus_keyword_for_title);
    }

    if (empty($generated_title)) {
        $handler->send_wp_error(new WP_Error('title_gen_empty', __('AI did not return a valid title.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // Ensure logging under a conversation. Generate UUID if missing so first-run title is captured.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $conversation_uuid = isset($_POST['conversation_uuid']) ? sanitize_text_field(wp_unslash($_POST['conversation_uuid'])) : '';
    if ($handler->log_storage) {
        $conversation_uuid = $handler->ensure_content_writer_conversation_uuid($conversation_uuid);
        $provider = $validated_params['provider'] ?? '';
        $model = $validated_params['model'] ?? '';
        $base = $handler->build_content_writer_log_base($conversation_uuid, $provider, $model);
        // User intent log
        $handler->log_storage->log_message(array_merge($base, [
            'message_role' => 'user',
            'message_content' => 'Generate Title',
            'request_payload' => [
                'original_topic' => $validated_params['content_title'] ?? '',
                'inline_keywords' => $validated_params['inline_keywords'] ?? '',
                'custom_title_prompt' => $validated_params['custom_title_prompt'] ?? '',
            ],
        ]));
        // Bot response log (surface vector_search_scores top-level like SSE)
        $botLog = array_merge($base, [
            'message_role' => 'bot',
            'message_content' => $generated_title,
            'usage' => $result['usage'] ?? null,
            'request_payload' => [
                'provider' => $provider,
                'model' => $model,
                'payload_sent' => [
                    'messages' => [['role' => 'user', 'content' => $prompts['user_prompt'] ?? '']],
                    'ai_params' => $ai_params_override,
                    'system_instruction' => $prompts['system_instruction'] ?? '',
                ],
            ],
        ]);
        if (!empty($result['vector_search_scores'])) {
            $botLog['vector_search_scores'] = $result['vector_search_scores'];
        }
        $handler->log_storage->log_message($botLog);
    }

    $smart_seo_keyword_resolution = isset($validated_params['smart_seo_keyword_resolution']) && is_array($validated_params['smart_seo_keyword_resolution'])
        ? $validated_params['smart_seo_keyword_resolution']
        : [];

    wp_send_json_success(array_merge([
        'new_title' => $generated_title,
        'usage' => $result['usage'] ?? null,
        'conversation_uuid' => $conversation_uuid,
    ], smart_seo_keyword_resolution_response_fields_logic($smart_seo_keyword_resolution)));
}

namespace WPAICG\ContentWriter\Ajax\Actions\SavePost;

use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Save_Post_Action;
use WP_Error;
use WPAICG\Lib\Utils\AIPKit_Google_Credentials_Handler;
use WPAICG\Utils\AIPKit_TOC_Generator;
use WPAICG\ContentWriter\AIPKit_Image_Injector;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper;
use WPAICG\Images\AIPKit_Image_Storage_Helper;

/**
 * Validates nonce and module access permissions for saving a post.
 *
 * @param AIPKit_Content_Writer_Save_Post_Action $handler The handler instance.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function validate_permissions_logic(AIPKit_Content_Writer_Save_Post_Action $handler)
{
    return $handler->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
}

/**
 * Extracts and sanitizes post data from the $_POST superglobal.
 *
 * @return array The sanitized post data.
 */
function extract_post_data_logic(): array
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked by the calling handler in validate_permissions_logic().
    $raw_data = isset($_POST) ? wp_unslash($_POST) : [];

    $sanitized = [];
    $sanitized['post_title']   = isset($raw_data['post_title']) ? sanitize_text_field($raw_data['post_title']) : 'AI Generated Content';
    $sanitized['post_content'] = isset($raw_data['post_content']) ? wp_kses_post($raw_data['post_content']) : '';
    $sanitized['post_content_format'] = \WPAICG\ContentWriter\AIPKit_Content_Writer_Block_Converter::normalize_format($raw_data['post_content_format'] ?? 'html');
    $sanitized['excerpt'] = isset($raw_data['generated_excerpt']) ? wp_kses_post($raw_data['generated_excerpt']) : ''; // ADDED
    $sanitized['tags'] = isset($raw_data['generated_tags']) ? sanitize_text_field($raw_data['generated_tags']) : '';
    $sanitized['meta_description'] = isset($raw_data['meta_description']) ? sanitize_textarea_field($raw_data['meta_description']) : '';
    $sanitized['focus_keyword'] = isset($raw_data['focus_keyword']) ? sanitize_text_field($raw_data['focus_keyword']) : '';
    $sanitized['post_type']    = isset($raw_data['post_type']) ? sanitize_key($raw_data['post_type']) : 'post';
    $sanitized['post_author']  = isset($raw_data['post_author']) ? absint($raw_data['post_author']) : get_current_user_id();
    $sanitized['post_status']  = isset($raw_data['post_status']) ? sanitize_key($raw_data['post_status']) : 'draft';
    $sanitized['cw_generation_mode'] = isset($raw_data['cw_generation_mode']) ? sanitize_key($raw_data['cw_generation_mode']) : 'task';
    $sanitized['schedule_date'] = isset($raw_data['post_schedule_date']) ? sanitize_text_field($raw_data['post_schedule_date']) : '';
    $sanitized['schedule_time'] = isset($raw_data['post_schedule_time']) ? sanitize_text_field($raw_data['post_schedule_time']) : '';
    $sanitized['generate_toc'] = isset($raw_data['generate_toc']) && $raw_data['generate_toc'] === '1' ? '1' : '0';
    $sanitized['generate_seo_slug'] = isset($raw_data['generate_seo_slug']) && $raw_data['generate_seo_slug'] === '1' ? '1' : '0'; // NEW
    $seo_score_profile = isset($raw_data['seo_score_profile']) ? sanitize_key((string) $raw_data['seo_score_profile']) : 'auto';
    $sanitized['seo_score_profile'] = in_array($seo_score_profile, ['auto', 'aipkit', 'yoast', 'rank_math', 'aioseo', 'framework'], true) ? $seo_score_profile : 'auto';
    $sanitized['seo_score_disabled_rules'] = class_exists('\\WPAICG\\ContentWriter\\SEO\\AIPKit_Content_Writer_SEO_Config')
        ? \WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config::sanitize_disabled_rules(
            $raw_data['seo_score_disabled_rules']
                ?? \WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config::default_disabled_rule_ids()
        )
        : '[]';
    $sanitized['smart_seo_slug'] = isset($raw_data['smart_seo_slug']) ? sanitize_title((string) $raw_data['smart_seo_slug']) : '';
    $sanitized['gsheets_sheet_id'] = isset($raw_data['gsheets_sheet_id']) ? sanitize_text_field($raw_data['gsheets_sheet_id']) : '';
    $sanitized['gsheets_row_index'] = isset($raw_data['gsheets_row_index']) ? absint($raw_data['gsheets_row_index']) : 0;
    $sanitized['gsheets_credentials'] = class_exists(AIPKit_Google_Credentials_Handler::class)
        ? AIPKit_Google_Credentials_Handler::process_credentials($raw_data['gsheets_credentials'] ?? null)
        : null;

    $category_ids_from_post = isset($raw_data['post_categories']) && is_array($raw_data['post_categories'])
        ? array_map('absint', $raw_data['post_categories'])
        : [];
    $sanitized['category_ids'] = array_filter($category_ids_from_post, function ($id) {
        return $id > 0;
    });

    $sanitized['image_data'] = null;
    if (isset($raw_data['image_data']) && $raw_data['image_data'] !== '') {
        if (is_array($raw_data['image_data'])) {
            $sanitized['image_data'] = $raw_data['image_data'];
        } elseif (is_string($raw_data['image_data'])) {
            $image_data_json = trim($raw_data['image_data']);
            if ($image_data_json !== '') {
                $decoded_image_data = json_decode($image_data_json, true);

                // Fallback for payloads that might still be over-escaped by transport.
                if (!is_array($decoded_image_data)) {
                    $decoded_image_data = json_decode(stripslashes($image_data_json), true);
                }

                if (is_array($decoded_image_data)) {
                    $sanitized['image_data'] = $decoded_image_data;
                }
            }
        }
    }
    $sanitized['image_alignment'] = isset($raw_data['image_alignment']) ? sanitize_key($raw_data['image_alignment']) : 'none';
    $sanitized['image_size'] = isset($raw_data['image_size']) ? sanitize_key($raw_data['image_size']) : 'large';

    return $sanitized;
}

/**
 * Validates the sanitized post data.
 *
 * @param array $data The sanitized post data.
 * @return true|WP_Error True if data is valid, WP_Error otherwise.
 */
function validate_post_data_logic(array $data)
{
    if (empty($data['post_title'])) {
        return new WP_Error('missing_title', __('Post title cannot be empty.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (empty($data['post_content'])) {
        return new WP_Error('missing_content', __('Post content cannot be empty.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (!post_type_exists($data['post_type'])) {
        return new WP_Error('invalid_post_type', __('Invalid post type specified.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (!user_can($data['post_author'], 'edit_posts') || !user_can($data['post_author'], get_post_type_object($data['post_type'])->cap->create_posts)) {
        return new WP_Error('invalid_author', __('Selected author does not have permission to create this post type.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }
    $valid_statuses = ['draft', 'publish', 'pending', 'private'];
    if (!in_array($data['post_status'], $valid_statuses, true)) {
        // While the data extractor defaults this, a validation step is still good practice.
        return new WP_Error('invalid_status', __('Invalid post status specified.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    $has_schedule_value = !empty($data['schedule_date']) || !empty($data['schedule_time']);
    if ($data['post_status'] === 'publish' && $has_schedule_value) {
        if (empty($data['schedule_date']) || empty($data['schedule_time'])) {
            return new WP_Error(
                'invalid_item_schedule',
                __('A scheduled post needs both a valid date and time.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $scheduled_local = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $data['schedule_date'] . ' ' . $data['schedule_time'],
            wp_timezone()
        );
        $schedule_errors = \DateTimeImmutable::getLastErrors();
        $is_valid_schedule = $scheduled_local && (
            $schedule_errors === false ||
            ($schedule_errors['warning_count'] === 0 && $schedule_errors['error_count'] === 0)
        );
        if (!$is_valid_schedule) {
            return new WP_Error(
                'invalid_item_schedule',
                __('Choose a valid date and time for the scheduled post.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        if ($scheduled_local->getTimestamp() <= time()) {
            return new WP_Error(
                'schedule_not_future',
                __('The scheduled publishing time has passed. Choose a future date and try again.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }
    }

    return true;
}

/**
 * Modifies the post array for scheduling if a future date/time is provided for a 'publish' status.
 *
 * @param array &$postarr Reference to the post array for wp_insert_post.
 * @param array $data The sanitized post data.
 * @return void
 */
function prepare_scheduled_post_logic(array &$postarr, array $data): void
{
    if ($data['post_status'] === 'publish' && !empty($data['schedule_date']) && !empty($data['schedule_time'])) {
        $schedule_datetime_str = $data['schedule_date'] . ' ' . $data['schedule_time'] . ':00';
        $schedule_timestamp_gmt = get_gmt_from_date($schedule_datetime_str);
        $current_timestamp_gmt = current_time('timestamp', true);

        if (strtotime($schedule_timestamp_gmt) > $current_timestamp_gmt) {
            $postarr['post_status'] = 'future';
            $postarr['post_date'] = get_date_from_gmt($schedule_timestamp_gmt, 'Y-m-d H:i:s');
            $postarr['post_date_gmt'] = $schedule_timestamp_gmt;
        }
    }
}

/**
 * Adds category information to the post array for standard 'post' types.
 *
 * @param array &$postarr Reference to the post array for wp_insert_post.
 * @param array $data The sanitized post data containing 'category_ids'.
 * @return void
 */
function prepare_categories_logic(array &$postarr, array $data): void
{
    if (!empty($data['category_ids'])) {
        if ($data['post_type'] === 'post') {
            $postarr['post_category'] = $data['category_ids'];
        }
    }
}

// Ensure dependencies are loaded
if (!function_exists('WPAICG\ContentWriter\TemplateManagerMethods\calculate_schedule_datetime_logic')) {
    $aipkit_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/templates.php';
    if (file_exists($aipkit_path)) {
        require_once $aipkit_path;
    }
}
if (!class_exists('\WPAICG\Utils\AIPKit_TOC_Generator')) {
    $aipkit_toc_generator_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/toc.php';
    if (file_exists($aipkit_toc_generator_path)) {
        require_once $aipkit_toc_generator_path;
    }
}
if (!class_exists('\WPAICG\SEO\AIPKit_SEO_Helper')) {
    $aipkit_seo_helper_path = WPAICG_PLUGIN_DIR . 'classes/seo/manager.php';
    if (file_exists($aipkit_seo_helper_path)) {
        require_once $aipkit_seo_helper_path;
    }
}
if (!class_exists(AIPKit_Image_Injector::class)) {
    $aipkit_injector_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/image-placement.php';
    if (file_exists($aipkit_injector_path)) {
        require_once $aipkit_injector_path;
    }
}
if (!class_exists(AIPKit_Image_Storage_Helper::class)) {
    $aipkit_storage_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/storage.php';
    if (file_exists($aipkit_storage_path)) {
        require_once $aipkit_storage_path;
    }
}
if (!class_exists(AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::class)) {
    $aipkit_image_alt_helper_path = WPAICG_LIB_DIR . 'content-writer/seo-images.php';
    if (file_exists($aipkit_image_alt_helper_path)) {
        require_once $aipkit_image_alt_helper_path;
    }
}

/**
 * Inserts the generated content as a new post.
 *
 * @param array      $postarr           The final prepared post array.
 * @param string|null $excerpt           Optional post excerpt.
 * @param array|null $image_data        Optional data for generated images.
 * @param string     $image_alignment   Optional alignment for injected images.
 * @param string     $image_size        Optional display size for injected images.
 * @param string|null $focus_keyword     Optional focus keyword for SEO-aware image alt fallback.
 * @param array       $seo_context       Optional Smart SEO profile and disabled rule context.
 * @return int|WP_Error The new post ID or a WP_Error on failure.
 */
function insert_post_logic(array $postarr, ?string $excerpt = null, ?array $image_data = null, string $image_alignment = 'none', string $image_size = 'large', ?string $focus_keyword = null, array $seo_context = [])
{
    if (class_exists(\WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::class)) {
        $postarr['post_title'] = \WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::clean_title(
            (string) ($postarr['post_title'] ?? ''),
            (string) $focus_keyword
        );
    }

    $html_content = $postarr['post_content'];
    if (class_exists(\WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::class)) {
        $html_content = \WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::clean_article_content((string) $html_content, (string) $focus_keyword);
    }

    $html_content = \WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::convert_basic_markdown_to_html((string) $html_content);
    $postarr['post_content'] = $html_content;

    if (is_array($image_data) && class_exists(AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::class)) {
        AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::maybe_prepare_rank_math_image_alt($image_data, (string) $focus_keyword, $seo_context);
    }

    if (!empty($image_data['in_content_images']) && class_exists(AIPKit_Image_Storage_Helper::class)) {
        $normalized_images = [];
        foreach ($image_data['in_content_images'] as $image_item) {
            $image_alt = class_exists(AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::class)
                ? AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::resolve_image_alt_text($image_item)
                : '';
            if (empty($image_item['attachment_id'])) {
                $fallback_url = $image_item['media_library_url'] ?? ($image_item['url'] ?? ($image_item['src'] ?? ($image_item['image_url'] ?? null)));
                if (!empty($fallback_url)) {
                    $image_payload = ['url' => $fallback_url];
                    if ($image_alt !== '') {
                        $image_payload['alt'] = $image_alt;
                    }
                    $attachment_id = AIPKit_Image_Storage_Helper::save_image_to_media_library(
                        $image_payload,
                        $postarr['post_title'],
                        [],
                        absint($postarr['post_author'])
                    );
                    if (!is_wp_error($attachment_id) && $attachment_id) {
                        $image_item['attachment_id'] = $attachment_id;
                        $image_item['media_library_url'] = wp_get_attachment_url($attachment_id);
                    }
                }
            }
            if (!empty($image_item['attachment_id']) && $image_alt !== '') {
                $attachment_id = absint($image_item['attachment_id']);
                if ($attachment_id > 0 && trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true)) === '') {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $image_alt);
                    update_post_meta($attachment_id, '_aipkit_image_alt_text', $image_alt);
                }
            }
            $normalized_images[] = $image_item;
        }
        $image_data['in_content_images'] = $normalized_images;
    }
    if (empty($image_data['featured_image_id']) && !empty($image_data['featured_image_url']) && class_exists(AIPKit_Image_Storage_Helper::class)) {
        $featured_attachment_id = AIPKit_Image_Storage_Helper::save_image_to_media_library(
            ['url' => $image_data['featured_image_url']],
            $postarr['post_title'],
            [],
            absint($postarr['post_author'])
        );
        if (!is_wp_error($featured_attachment_id) && $featured_attachment_id) {
            $image_data['featured_image_id'] = $featured_attachment_id;
        }
    }
    if (!empty($image_data['featured_image_id']) && isset($image_data['featured_image_alt'])) {
        $featured_attachment_id = absint($image_data['featured_image_id']);
        if ($featured_attachment_id > 0) {
            update_post_meta(
                $featured_attachment_id,
                '_wp_attachment_image_alt',
                sanitize_text_field((string) $image_data['featured_image_alt'])
            );
        }
    }

    if (!empty($image_data['in_content_images']) && class_exists(AIPKit_Image_Injector::class)) {
        $image_injector = new AIPKit_Image_Injector();
        $postarr['post_content'] = $image_injector->inject_images(
            $postarr['post_content'],
            $image_data['in_content_images'],
            $image_data['placement_settings']['placement'] ?? 'after_first_h2',
            absint($image_data['placement_settings']['param_x'] ?? 2),
            $image_alignment,
            $image_size
        );
    }

    // Generate ToC after images have been placed
    if (isset($postarr['generate_toc']) && $postarr['generate_toc'] === '1' && class_exists(AIPKit_TOC_Generator::class)) {
        $toc_result = AIPKit_TOC_Generator::generate($postarr['post_content'], [
            'rank_math_compatible' => class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')
                && sanitize_key((string) (\WPAICG\SEO\AIPKit_SEO_Helper::get_active_plugin_profile()['profile'] ?? '')) === 'rank_math',
        ]);
        if (!empty($toc_result['toc'])) {
            // Prepend ToC to the modified content
            $postarr['post_content'] = $toc_result['toc'] . $toc_result['content'];
        }
    }
    // Unset the custom key before passing to wp_insert_post
    unset($postarr['generate_toc']);

    // Add excerpt if provided
    if (!empty($excerpt)) {
        $postarr['post_excerpt'] = $excerpt;
    }

    if (($postarr['post_content_format'] ?? 'html') === 'gutenberg') {
        // wp_insert_post expects slashed data, including escaped JSON block attributes.
        $postarr['post_content'] = wp_slash(\WPAICG\ContentWriter\AIPKit_Content_Writer_Block_Converter::convert($postarr['post_content']));
    }
    unset($postarr['post_content_format']);

    $post_id_or_error = wp_insert_post($postarr, true);

    if (is_wp_error($post_id_or_error)) {
        return $post_id_or_error;
    }

    if (!empty($image_data['featured_image_id'])) {
        set_post_thumbnail($post_id_or_error, $image_data['featured_image_id']);
    }

    return $post_id_or_error;
}

/**
 * Assigns taxonomies (like categories) to non-standard post types after creation.
 *
 * @param int $post_id The ID of the newly created post.
 * @param array $data The sanitized post data containing 'post_type' and 'category_ids'.
 * @return void
 */
function assign_taxonomies_logic(int $post_id, array $data): void
{
    if ($data['post_type'] !== 'post' && !empty($data['category_ids'])) {
        $taxonomy = 'category'; // Hardcoded for now, could be made dynamic if needed
        if (is_object_in_taxonomy($data['post_type'], $taxonomy)) {
            wp_set_post_terms($post_id, $data['category_ids'], $taxonomy);
        }
    }
}

/**
 * Saves the SEO meta description for a given post.
 *
 * @param int    $post_id The ID of the post.
 * @param string $meta_description The meta description to save.
 * @return void
 */
function save_seo_meta_logic(int $post_id, string $meta_description): void
{
    if ($post_id > 0 && !empty($meta_description) && class_exists('\WPAICG\SEO\AIPKit_SEO_Helper')) {
        \WPAICG\SEO\AIPKit_SEO_Helper::update_meta_description($post_id, $meta_description);
    }
}

/**
 * Saves the SEO focus keyword for a given post.
 *
 * @param int    $post_id The ID of the post.
 * @param string $focus_keyword The focus keyword to save.
 * @return void
 */
function save_seo_focus_keyword_logic(int $post_id, string $focus_keyword): void
{
    if ($post_id > 0 && !empty($focus_keyword) && class_exists('\WPAICG\SEO\AIPKit_SEO_Helper')) {
        \WPAICG\SEO\AIPKit_SEO_Helper::update_focus_keyword($post_id, $focus_keyword);
    }
}

/**
 * Sets the tags for a given post.
 *
 * @param int    $post_id The ID of the post.
 * @param string $tags A comma-separated string of tags.
 * @return void
 */
function set_post_tags_logic(int $post_id, string $tags): void
{
    if ($post_id > 0 && !empty($tags)) {
        // wp_set_post_tags handles creating tags that don't exist
        // and sanitizing them. It accepts a string or an array.
        wp_set_post_tags($post_id, $tags, false);
    }
}

namespace WPAICG\ContentWriter\Ajax\Actions\CreateTask;

use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Create_Task_Action;
use WP_Error;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Image_Provider_Options;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;

/**
* Validates nonce and module access permissions.
*
* @param AIPKit_Content_Writer_Create_Task_Action $handler The handler instance.
* @return true|WP_Error True on success, WP_Error on failure.
*/
function validate_permissions_logic(AIPKit_Content_Writer_Create_Task_Action $handler)
{
    return $handler->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
}

/**
* Normalizes and sanitizes basic task settings like name, frequency, and status from raw POST data.
*
* @param array $settings The raw POST data.
* @return array An array containing 'task_name', 'task_frequency', and 'task_status'.
*/
function normalize_task_settings_logic(array $settings): array
{
    $task_name = isset($settings['task_name']) ? sanitize_text_field(wp_unslash($settings['task_name'])) : '';

    if (empty($task_name)) {
        $first_title_line = isset($settings['content_title']) ? explode("\n", $settings['content_title'])[0] : 'Untitled ' . time();
        $task_name = 'Automated Content: ' . sanitize_text_field(wp_unslash($first_title_line));
    }

    $task_frequency = isset($settings['task_frequency']) && in_array($settings['task_frequency'], ['one-time', 'aipkit_five_minutes', 'aipkit_fifteen_minutes', 'aipkit_thirty_minutes', 'hourly', 'twicedaily', 'daily', 'weekly'])
    ? sanitize_key($settings['task_frequency'])
    : 'daily';

    $task_status = isset($settings['task_status']) && in_array($settings['task_status'], ['active', 'paused'])
    ? sanitize_key($settings['task_status'])
    : 'active';

    return [
    'task_name' => $task_name,
    'task_frequency' => $task_frequency,
    'task_status' => $task_status,
    ];
}

if (!class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)) {
    $aipkit_image_provider_options_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/image-options.php';
    if (file_exists($aipkit_image_provider_options_path)) {
        require_once $aipkit_image_provider_options_path;
    }
}

/**
* Builds and validates the specific configuration array for the content writing task.
* UPDATED: Removed guided mode fields.
*
* @param array $settings The raw POST data.
* @param string $task_frequency The sanitized task frequency.
* @param string $task_status The sanitized task status.
* @return array|WP_Error The sanitized content writer config array or WP_Error on failure.
*/
function build_content_writer_config_logic(array $settings, string $task_frequency, string $task_status)
{
    if (class_exists('\WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector')) {
        $rss_validation = \WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector::validate_config($settings);
        if (is_wp_error($rss_validation)) {
            return $rss_validation;
        }
    }
    $content_writer_config = [];
    if (class_exists(AIPKit_Content_Writer_Template_Manager::class)) {
        // This list should ideally mirror the one in AIPKit_Content_Writer_Template_Manager for consistency.
        $allowed_keys_from_template_manager = [
            'ai_provider', 'ai_model', 'content_title_bulk', 'content_keywords',
            'ai_temperature', 'content_length', 'post_type', 'post_author',
            'post_status', 'post_schedule_date', 'post_schedule_time',
            'post_content_format',
            'schedule_mode', 'smart_schedule_start_datetime', 'smart_schedule_interval_value', 'smart_schedule_interval_unit',
            'post_categories',
            'prompt_mode', 'custom_title_prompt', 'custom_content_prompt',
            'generate_meta_description', 'custom_meta_prompt',
            'generate_focus_keyword', 'custom_keyword_prompt',
            'generate_excerpt', 'custom_excerpt_prompt',
            'generate_tags', 'custom_tags_prompt',
            'cw_generation_mode', 'rss_feeds',
            'gsheets_sheet_id', 'gsheets_credentials',
            'url_list',
            'content_title',
            'generate_toc', 'generate_seo_slug',
            'seo_score_improvement_enabled', 'seo_score_continue_until_target',
            'seo_score_target', 'seo_score_max_passes', 'seo_score_profile', 'seo_score_disabled_rules',
            'generate_images_enabled', 'image_provider', 'image_model', 'image_provider_options', 'image_prompt',
            'image_count', 'image_placement', 'image_placement_param_x', 'image_alignment', 'image_size',
            'generate_image_title', 'generate_image_alt_text', 'generate_image_caption', 'generate_image_description',
            'image_title_prompt', 'image_alt_text_prompt', 'image_caption_prompt', 'image_description_prompt',
            'image_title_prompt_update', 'image_alt_text_prompt_update', 'image_caption_prompt_update', 'image_description_prompt_update',
            'generate_featured_image', 'featured_image_prompt',
            'pexels_orientation', 'pexels_size', 'pexels_color',
            'pixabay_orientation', 'pixabay_image_type', 'pixabay_category',
            'enable_vector_store', 'vector_store_provider', 'openai_vector_store_ids', 'google_file_search_store_names',
            'pinecone_index_name', 'qdrant_collection_name', 'chroma_collection_name', 'local_store_id', 'vector_embedding_provider',
            'vector_embedding_model', 'vector_store_top_k',
            'vector_store_confidence_threshold',
            'rss_include_keywords', 'rss_exclude_keywords', 'rss_item_limit',
            'reasoning_effort', // ADDED
        ];
        $prompt_template_keys = [
            'custom_title_prompt', 'custom_content_prompt', 'custom_meta_prompt',
            'custom_keyword_prompt', 'custom_excerpt_prompt', 'custom_tags_prompt',
            'image_prompt', 'featured_image_prompt',
            'image_title_prompt', 'image_alt_text_prompt', 'image_caption_prompt',
            'image_description_prompt', 'image_title_prompt_update',
            'image_alt_text_prompt_update', 'image_caption_prompt_update',
            'image_description_prompt_update',
        ];
        $textarea_keys = [
            'content_title_bulk', 'rss_feeds', 'url_list', 'rss_include_keywords',
            'rss_exclude_keywords', 'content_title', 'smart_schedule_start_datetime',
        ];

        foreach ($allowed_keys_from_template_manager as $key) {
            if (isset($settings[$key])) {
                if (in_array($key, $prompt_template_keys, true)) {
                    $content_writer_config[$key] = AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($settings[$key]));
                } elseif (in_array($key, $textarea_keys, true)) {
                    $content_writer_config[$key] = sanitize_textarea_field(wp_unslash($settings[$key]));
                } elseif ($key === 'gsheets_credentials') {
                    if (class_exists('\WPAICG\Lib\Utils\AIPKit_Google_Credentials_Handler')) {
                        // The handler returns an array or null, which will be properly JSON encoded later.
                        $content_writer_config[$key] = \WPAICG\Lib\Utils\AIPKit_Google_Credentials_Handler::process_credentials($settings[$key]);
                    } else {
                        $content_writer_config[$key] = null;
                    }
                } elseif ($key === 'image_provider_options') {
                    $content_writer_config[$key] = class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)
                        ? AIPKit_Content_Writer_Image_Provider_Options::sanitize_options_json($settings[$key], $settings)
                        : '{}';
                } elseif ($key === 'ai_provider' || $key === 'image_provider') {
                    $provider_raw = sanitize_text_field(wp_unslash($settings[$key]));
                    $provider_key = strtolower($provider_raw);
                    $provider_map = [
                        'openai' => 'OpenAI',
                        'openrouter' => 'OpenRouter',
                        'google' => 'Google',
                        'azure' => 'Azure',
                        'claude' => 'Claude',
                        'deepseek' => 'DeepSeek',
                        'ollama' => 'Ollama',
                        'xai' => 'xAI',
                    ];
                    $content_writer_config[$key] = $provider_map[$provider_key] ?? \WPAICG\AIPKit_Providers::normalize_provider_label($provider_raw);
                } elseif (in_array($key, ['generate_meta_description', 'generate_focus_keyword', 'generate_excerpt', 'generate_tags', 'generate_toc', 'generate_seo_slug', 'seo_score_improvement_enabled', 'seo_score_continue_until_target', 'generate_images_enabled', 'generate_featured_image', 'generate_image_title', 'generate_image_alt_text', 'generate_image_caption', 'generate_image_description', 'enable_vector_store'], true)) {
                    $content_writer_config[$key] = ($settings[$key] === '1' || $settings[$key] === true || $settings[$key] === 1) ? '1' : '0';
                } elseif ($key === 'post_categories' && is_array($settings[$key])) {
                    $content_writer_config[$key] = array_map('absint', $settings[$key]);
                } elseif (in_array($key, ['post_author', 'image_count', 'image_placement_param_x', 'vector_store_top_k', 'smart_schedule_interval_value'], true)) {
                    $content_writer_config[$key] = absint($settings[$key]);
                } elseif ($key === 'seo_score_target') {
                    $raw = isset($settings[$key]) ? absint($settings[$key]) : 100;
                    $content_writer_config[$key] = (string) max(80, min($raw, 100));
                } elseif ($key === 'seo_score_max_passes') {
                    $raw = isset($settings[$key]) ? absint($settings[$key]) : 3;
                    $content_writer_config[$key] = (string) max(1, min($raw, 5));
                } elseif ($key === 'seo_score_disabled_rules') {
                    $content_writer_config[$key] = class_exists(AIPKit_Content_Writer_SEO_Config::class)
                        ? AIPKit_Content_Writer_SEO_Config::sanitize_disabled_rules($settings[$key])
                        : '[]';
                } elseif ($key === 'vector_store_confidence_threshold') {
                    $raw = isset($settings[$key]) ? absint($settings[$key]) : 20;
                    $content_writer_config[$key] = max(0, min($raw, 100));
                } elseif ($key === 'ai_temperature') {
                    $content_writer_config[$key] = (string)floatval($settings[$key]);
                } elseif ($key === 'post_content_format') {
                    $content_writer_config[$key] = \WPAICG\ContentWriter\AIPKit_Content_Writer_Block_Converter::normalize_format($settings[$key]);
                } elseif ($key === 'content_length') {
                    $value = sanitize_key($settings[$key]);
                    $content_writer_config[$key] = in_array($value, ['short', 'medium', 'long'], true) ? $value : 'medium';
                } elseif (in_array($key, ['openai_vector_store_ids', 'google_file_search_store_names'], true) && is_array($settings[$key])) {
                    $content_writer_config[$key] = array_map('sanitize_text_field', $settings[$key]);
                } elseif ($key === 'reasoning_effort') {
                    $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($settings[$key] ?? '');
                    $content_writer_config[$key] = $reasoning_effort !== '' ? $reasoning_effort : 'none';
                } elseif ($key === 'seo_score_profile') {
                    $profile = sanitize_key($settings[$key]);
                    $allowed_profiles = ['auto', 'aipkit', 'yoast', 'rank_math', 'aioseo', 'framework'];
                    $content_writer_config[$key] = in_array($profile, $allowed_profiles, true) ? $profile : 'auto';
                } elseif (in_array($key, ['post_type', 'post_status', 'prompt_mode', 'cw_generation_mode', 'image_provider', 'image_placement', 'image_alignment', 'image_size', 'vector_store_provider', 'vector_embedding_provider', 'pexels_orientation', 'pexels_size', 'pexels_color', 'pixabay_orientation', 'pixabay_image_type', 'pixabay_category', 'schedule_mode', 'smart_schedule_interval_unit'], true)) {
                    $content_writer_config[$key] = sanitize_key($settings[$key]);
                } elseif (is_string($settings[$key])) {
                    $content_writer_config[$key] = sanitize_text_field(wp_unslash($settings[$key]));
                } else {
                    $content_writer_config[$key] = $settings[$key];
                }
            }
        }

        $generation_mode = $content_writer_config['cw_generation_mode'] ?? 'task';
        if ($generation_mode === 'single') {
            $generation_mode = 'task';
            $content_writer_config['cw_generation_mode'] = 'task';
        }

        // Only map bulk input into content_title for bulk/task mode.
        if ($generation_mode === 'task' && !empty($content_writer_config['content_title_bulk'])) {
            $content_writer_config['content_title'] = $content_writer_config['content_title_bulk'];
        }
        unset($content_writer_config['content_title_bulk']);

        $content_writer_config = AIPKit_Content_Writer_Template_Manager::finalize_task_config($content_writer_config);
        if (is_wp_error($content_writer_config)) {
            return $content_writer_config;
        }

        $content_writer_config['task_frequency'] = $task_frequency;
        $content_writer_config['task_status_on_creation'] = $task_status;
    }
    return $content_writer_config;
}

/**
* Validates that the built content writer config has the required fields.
*
* @param array $config The built content writer config array.
* @return true|WP_Error True on success, WP_Error on failure.
*/
function validate_task_requirements_logic(array $config)
{
    $generation_mode = $config['cw_generation_mode'] ?? 'task';
    if ($generation_mode === 'single') {
        $generation_mode = 'task';
    }

    if ($generation_mode === 'rss') {
        if (empty($config['rss_feeds'])) {
            return new WP_Error('missing_rss_feeds', __('RSS Feed URLs are required for this task type.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
    } elseif ($generation_mode === 'gsheets') {
        if (empty($config['gsheets_sheet_id']) || empty($config['gsheets_credentials'])) {
            return new WP_Error('missing_gsheets_config', __('Google Sheet ID and Credentials are required for this task type.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
    } elseif ($generation_mode === 'url') { // NEW
        if (empty($config['url_list'])) {
            return new WP_Error('missing_url_list', __('Website URLs are required for this task type.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
    } elseif ($generation_mode === 'task' || $generation_mode === 'csv') {
        if (empty($config['content_title'])) {
            return new WP_Error('missing_content_title_cw', __('Content Title/Topic is required for this task type.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
    }

    if (empty($config['ai_provider']) || empty($config['ai_model'])) {
        return new WP_Error('missing_ai_config_cw', __('AI Provider and Model are required for content writing task.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (
        ($config['enable_vector_store'] ?? '0') === '1'
        && ($config['vector_store_provider'] ?? '') === 'google'
        && ($config['ai_provider'] ?? '') !== 'Google'
    ) {
        return new WP_Error('google_file_search_provider_mismatch', __('Google File Search requires Google as the AI provider.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (($config['prompt_mode'] ?? 'standard') === 'custom' && empty($config['custom_content_prompt'])) {
        return new WP_Error('missing_custom_content_prompt', __('Custom Content Prompt cannot be empty when in Custom Prompt mode.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    $schedule_validation = validate_publishing_schedule_config_logic($config, $generation_mode);
    if (is_wp_error($schedule_validation)) {
        return $schedule_validation;
    }

    return true;
}

/**
 * Validates publishing schedule settings before any source items are prepared.
 *
 * @param array  $config          The sanitized content writer configuration.
 * @param string $generation_mode The normalized generation mode.
 * @return true|WP_Error True when the schedule is safe to use.
 */
function validate_publishing_schedule_config_logic(array $config, string $generation_mode)
{
    if (($config['post_status'] ?? 'draft') !== 'publish') {
        return true;
    }

    $schedule_mode = sanitize_key($config['schedule_mode'] ?? 'immediate');
    if (!in_array($schedule_mode, ['immediate', 'smart', 'from_input'], true)) {
        return new WP_Error(
            'invalid_schedule_mode',
            __('Choose a valid publishing schedule before generating.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    if ($schedule_mode === 'immediate') {
        return true;
    }

    if ($schedule_mode === 'from_input') {
        if (!in_array($generation_mode, ['task', 'bulk', 'csv', 'gsheets'], true)) {
            return new WP_Error(
                'invalid_schedule_source',
                __('This source cannot use dates from input. Choose another schedule.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }
        return true;
    }

    $start_raw = trim((string) ($config['smart_schedule_start_datetime'] ?? ''));
    $start_local = false;
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i'] as $format) {
        $candidate = \DateTimeImmutable::createFromFormat('!' . $format, $start_raw, wp_timezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if ($candidate && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $start_local = $candidate;
            break;
        }
    }

    if (!$start_local) {
        return new WP_Error(
            'invalid_smart_schedule',
            __('Choose a valid start date and time before generating.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    if ($start_local->getTimestamp() <= time()) {
        return new WP_Error(
            'schedule_not_future',
            __('The schedule must start in the future.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    $interval_value = absint($config['smart_schedule_interval_value'] ?? 0);
    $interval_unit = sanitize_key($config['smart_schedule_interval_unit'] ?? '');
    if ($interval_value < 1 || !in_array($interval_unit, ['hours', 'days'], true)) {
        return new WP_Error(
            'invalid_smart_schedule_interval',
            __('Choose a valid publishing interval before generating.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    return true;
}

/**
* Inserts the new task into the custom database table.
*
* @param string $task_name The sanitized task name.
* @param string $task_type The type of the task.
* @param array $config The built and validated content writer config.
* @param string $task_status The sanitized task status ('active' or 'paused').
* @return int|WP_Error The ID of the newly inserted task, or a WP_Error on failure.
*/
function insert_task_into_db_logic(string $task_name, string $task_type, array $config, string $task_status)
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';

    $task_data = [
    'task_name' => $task_name,
    'task_type' => $task_type,
    'task_config' => wp_json_encode($config),
    'status' => $task_status,
    'created_at' => current_time('mysql', 1),
    'updated_at' => current_time('mysql', 1),
    ];
    $task_formats = ['%s', '%s', '%s', '%s', '%s', '%s'];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct insert to a custom table. Caches will be invalidated.
    $inserted = $wpdb->insert($tasks_table_name, $task_data, $task_formats);

    if ($inserted === false) {
        return new WP_Error('db_insert_error', __('Failed to create automated task.', 'gpt3-ai-content-generator'), ['status' => 500]);
    }
    return $wpdb->insert_id;
}

/**
* Schedules the cron event for the new task if its status is 'active'.
*
* @param int $task_id The ID of the newly created task.
* @param string $task_status The status of the task ('active' or 'paused').
* @param string $task_frequency The scheduling frequency ('hourly', 'daily', etc.).
* @return void
*/
function schedule_task_if_active_logic(int $task_id, string $task_status, string $task_frequency): void
{
    if ($task_status === 'active' && class_exists(AIPKit_Automated_Task_Scheduler::class)) {
        AIPKit_Automated_Task_Scheduler::schedule_task_event($task_id, $task_frequency, 'active');
    }
}

namespace WPAICG\ContentWriter\Ajax\Actions;

use WPAICG\ContentWriter\Ajax\AIPKit_Content_Writer_Base_Ajax_Action;
use WP_Error;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Image_Handler;
use WPAICG\ContentWriter\Ajax\Actions\CreateTask;
use WPAICG\aipkit_dashboard;
use WPAICG\AIPKit_Providers;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Summarizer;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Meta_Prompt_Builder;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Keyword_Prompt_Builder;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config;
use function WPAICG\ContentWriter\Ajax\Actions\Shared\merge_smart_seo_usage_logic;
use function WPAICG\ContentWriter\Ajax\Actions\Shared\smart_seo_keyword_resolution_response_fields_logic;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Excerpt_Prompt_Builder;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Tags_Prompt_Builder;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Image_Provider_Options;
use WPAICG\Includes\AIPKit_Upload_Utils;

/**
* Handles the AJAX action for initializing a content generation stream.
*/
class AIPKit_Content_Writer_Init_Stream_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    /**
    * Handles the AJAX request to initialize the stream.
    */
    public function handle()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in Shared\validate_and_normalize_input_logic().
        $settings = isset($_POST) ? wp_unslash($_POST) : [];

        // 1. Validate permissions and normalize input
        $validated_params = Shared\validate_and_normalize_input_logic($this, $settings);
        if (is_wp_error($validated_params)) {
            $this->send_wp_error($validated_params);
            return;
        }

        // 2. Ensure SSE Cache class is available
        $cache_check_result = InitStream\ensure_sse_cache_available_logic();
        if (is_wp_error($cache_check_result)) {
            $this->send_wp_error($cache_check_result);
            return;
        }

        $keyword_resolution = [];
        $resolved_keyword_params = Shared\resolve_smart_seo_keywords_logic(
            $validated_params,
            $this->get_ai_caller(),
            [
                'topic' => $validated_params['content_title'] ?? '',
                'title' => $validated_params['content_title'] ?? '',
            ]
        );
        $validated_params = $resolved_keyword_params['params'];
        $keyword_resolution = $resolved_keyword_params['resolution'];

        // 3. Build prompts
        $prompts = Shared\build_prompts_logic($validated_params);
        if (is_wp_error($prompts)) {
            $this->send_wp_error($prompts);
            return;
        }

        // 4. Merge AI Parameters
        $ai_params_for_cache = InitStream\merge_ai_params_logic($validated_params);

        // 5. Build the final cache payload
        $data_to_cache = InitStream\build_cache_payload_logic(
            $prompts['system_instruction'],
            $prompts['user_prompt'],
            $validated_params['provider'],
            $validated_params['model'],
            $ai_params_for_cache,
            $validated_params // Pass all validated params for logging context
        );

        // 6. Write payload to SSE cache
        $cache_key_result = InitStream\write_to_sse_cache_logic($data_to_cache);
        if (is_wp_error($cache_key_result)) {
            $this->send_wp_error($cache_key_result);
            return;
        }

    // 7. Send success response (include conversation_uuid for downstream logging)
        $conversation_uuid = isset($data_to_cache['conversation_uuid']) ? $data_to_cache['conversation_uuid'] : '';
        wp_send_json_success(array_merge(
            ['cache_key' => $cache_key_result, 'conversation_uuid' => $conversation_uuid],
            Shared\smart_seo_keyword_resolution_response_fields_logic($keyword_resolution)
        ));
    }
}

/**
 * Handles the AJAX action for standard (non-streaming) content generation.
 */
class AIPKit_Content_Writer_Standard_Generation_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    /**
     * Handles the AJAX request for standard content generation using the shared validation and response helpers.
     */
    public function handle()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in Shared\validate_and_normalize_input_logic().
        $settings = isset($_POST) ? wp_unslash($_POST) : [];

        // 1. Validate input and check permissions
        $validated_params = Shared\validate_and_normalize_input_logic($this, $settings);
        if (is_wp_error($validated_params)) {
            $this->send_wp_error($validated_params);
            return;
        }

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->send_wp_error($batch_run_check);
            return;
        }

        $this->maybe_extend_execution_limits(300);

        Shared\maybe_update_gsheets_row_status_logic($validated_params, 'Queued on');

        // 2. Check for required dependencies (AI Caller, Logger)
        if (!$this->ai_caller) {
            $this->send_wp_error(new WP_Error('ai_caller_missing', __('AI processing component is unavailable.', 'gpt3-ai-content-generator')), 500);
            return;
        }

        $resolved_keyword_params = Shared\resolve_smart_seo_keywords_logic(
            $validated_params,
            $this->get_ai_caller(),
            [
                'topic' => $validated_params['content_title'] ?? '',
                'title' => $validated_params['content_title'] ?? '',
            ]
        );
        $validated_params = $resolved_keyword_params['params'];
        $validated_params['smart_seo_keyword_resolution'] = $resolved_keyword_params['resolution'];

        // 3. Build prompts
        $prompts = Shared\build_prompts_logic($validated_params);
        if (is_wp_error($prompts)) {
            $this->send_wp_error($prompts);
            return;
        }

        $final_title = $validated_params['content_title'];
        $final_user_prompt = str_replace('{topic}', $final_title, $prompts['user_prompt']);

        // 4. Prepare AI parameters
        $ai_params_override = Shared\prepare_ai_params_logic($validated_params);

        // 5. Determine conversation UUID (reuse if provided, else create)
        $conversation_uuid = isset($settings['conversation_uuid']) && !empty($settings['conversation_uuid'])
            ? sanitize_text_field((string) $settings['conversation_uuid'])
            : wp_generate_uuid4();
        // Attach to params so the initial log uses the same conversation
        $validated_params['conversation_uuid'] = $conversation_uuid;
        Shared\log_initial_request_logic($this, $validated_params, 'AJAX');

        // 6. Make the AI call
        $ai_result = StandardGeneration\call_ai_provider_logic(
            $this,
            $validated_params['provider'],
            $validated_params['model'],
            [['role' => 'user', 'content' => $final_user_prompt]], // Use the final prompt
            $ai_params_override,
            $prompts['system_instruction'],
            $validated_params
        );

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->send_wp_error($batch_run_check);
            return;
        }

        // 7. Handle the response (success or error)
        if (is_wp_error($ai_result)) {
            StandardGeneration\handle_error_response_logic($this, $ai_result, $validated_params, $conversation_uuid);
        } else {
            StandardGeneration\handle_success_response_logic($this, $ai_result, $validated_params, $conversation_uuid);
        }
    }
}

/**
 * Handles the AJAX action for generating a new title for content.
  */
class AIPKit_Content_Writer_Generate_Title_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    /**
     * Handles the AJAX request to generate a title.
     */
    public function handle()
    {
        // 1. Validate input and permissions
        $validated_params = GenerateTitle\validate_and_normalize_input_logic($this);
        if (is_wp_error($validated_params)) {
            $this->send_wp_error($validated_params);
            return;
        }

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->send_wp_error($batch_run_check);
            return;
        }

        $resolved_keyword_params = Shared\resolve_smart_seo_keywords_logic(
            $validated_params,
            $this->get_ai_caller(),
            [
                'topic' => $validated_params['content_title'] ?? '',
                'title' => $validated_params['content_title'] ?? '',
            ]
        );
        $validated_params = $resolved_keyword_params['params'];
        $validated_params['smart_seo_keyword_resolution'] = $resolved_keyword_params['resolution'];

        // 2. Build the prompt for the AI
        $prompts = GenerateTitle\build_title_prompt_logic($validated_params);

        // 3. Prepare AI-specific parameters
        $ai_params_override = GenerateTitle\prepare_ai_params_logic($validated_params);

        // 4. Call the AI provider
        $ai_result = GenerateTitle\call_title_generator_logic(
            $this,
            $validated_params['provider'],
            $validated_params['model'],
            [['role' => 'user', 'content' => $prompts['user_prompt']]],
            $ai_params_override,
            $prompts['system_instruction'],
            $validated_params // Pass the full form data for vector support
        );

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->send_wp_error($batch_run_check);
            return;
        }

        // 5. Handle the AI response (success or error) and log under conversation if provided
        GenerateTitle\handle_title_response_logic($this, $ai_result, $validated_params, $prompts, $ai_params_override);
    }
}

/**
 * Handles the AJAX action for saving generated content as a new WordPress post.
 */
class AIPKit_Content_Writer_Save_Post_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    /**
     * Removes a cancelled batch post and any images generated exclusively for
     * that in-flight item.
     *
     * @param mixed $image_data
     */
    private function rollback_cancelled_batch_post(int $post_id, $image_data): void
    {
        if ($post_id > 0) {
            wp_delete_post($post_id, true);
        }

        if (is_string($image_data) && $image_data !== '') {
            $decoded = json_decode($image_data, true);
            $image_data = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($image_data)) {
            return;
        }

        $attachment_ids = [];
        if (!empty($image_data['in_content_images']) && is_array($image_data['in_content_images'])) {
            foreach ($image_data['in_content_images'] as $image_item) {
                if (is_array($image_item) && !empty($image_item['attachment_id'])) {
                    $attachment_ids[] = absint($image_item['attachment_id']);
                }
            }
        }
        if (!empty($image_data['featured_image_id'])) {
            $attachment_ids[] = absint($image_data['featured_image_id']);
        }

        foreach (array_unique(array_filter($attachment_ids)) as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * Handles the AJAX request for saving a post using the shared validation and response helpers.
     */
    public function handle()
    {
        // 1. Validate permissions
        $permission_check = SavePost\validate_permissions_logic($this);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->send_wp_error($batch_run_check);
            return;
        }

        // 2. Extract and sanitize all data from the POST request
        $post_data = SavePost\extract_post_data_logic();

        // 3. Validate the extracted data
        $validation_result = SavePost\validate_post_data_logic($post_data);
        if (is_wp_error($validation_result)) {
            $this->send_wp_error($validation_result);
            return;
        }

        $image_data = $post_data['image_data'] ?? null;
        $image_alignment = $post_data['image_alignment'] ?? 'none';
        $image_size = $post_data['image_size'] ?? 'large';

        $smart_seo_slug = !empty($post_data['smart_seo_slug']) ? sanitize_title((string) $post_data['smart_seo_slug']) : '';

        // 4. Prepare the initial post array for wp_insert_post
        $postarr = [
            'post_title'   => $post_data['post_title'],
            'post_content' => $post_data['post_content'],
            'post_content_format' => $post_data['post_content_format'],
            'post_type'    => $post_data['post_type'],
            'post_author'  => $post_data['post_author'],
            'post_status'  => $post_data['post_status'],
            'generate_toc' => $post_data['generate_toc'],
        ];
        if ($smart_seo_slug !== '') {
            $postarr['post_name'] = $smart_seo_slug;
        }

        // 5. Modify the post array for scheduling if needed
        SavePost\prepare_scheduled_post_logic($postarr, $post_data);

        // 6. Add categories to the post array if the post type is 'post'
        SavePost\prepare_categories_logic($postarr, $post_data);

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->send_wp_error($batch_run_check);
            return;
        }

        // 7. Insert the post into the database (this now handles image/toc injection)
        $post_id_result = SavePost\insert_post_logic($postarr, $post_data['excerpt'] ?? null, $image_data, $image_alignment, $image_size, $post_data['focus_keyword'] ?? '', $post_data);
        if (is_wp_error($post_id_result)) {
            $this->send_wp_error($post_id_result);
            return;
        }

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->rollback_cancelled_batch_post((int) $post_id_result, $image_data);
            $this->send_wp_error($batch_run_check);
            return;
        }

        // 8. Assign taxonomies (like categories) to non-standard post types
        SavePost\assign_taxonomies_logic($post_id_result, $post_data);

        // 9. Save SEO Meta Description
        if (!empty($post_data['meta_description'])) {
            SavePost\save_seo_meta_logic($post_id_result, $post_data['meta_description']);
        }

        if (!empty($post_data['focus_keyword'])) {
            SavePost\save_seo_focus_keyword_logic($post_id_result, $post_data['focus_keyword']);
        }

        if (!empty($post_data['tags'])) {
            SavePost\set_post_tags_logic($post_id_result, $post_data['tags']);
        }

        if ($smart_seo_slug === '' && isset($post_data['generate_seo_slug']) && $post_data['generate_seo_slug'] === '1' && class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
            \WPAICG\SEO\AIPKit_SEO_Helper::update_post_slug_for_seo($post_id_result);
        }

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->rollback_cancelled_batch_post((int) $post_id_result, $image_data);
            $this->send_wp_error($batch_run_check);
            return;
        }

        Shared\maybe_update_gsheets_row_status_logic($post_data, 'Processed on');

        $post_status = get_post_status($post_id_result);
        $view_link = get_permalink($post_id_result);
        if (in_array($post_status, ['draft', 'pending', 'auto-draft'], true)) {
            $view_link = get_preview_post_link($post_id_result);
        }

        // 13. Send a success response
        wp_send_json_success([
            'message' => __('Post saved successfully!', 'gpt3-ai-content-generator'),
            'post_id' => $post_id_result,
            'edit_link' => get_edit_post_link($post_id_result, 'raw'),
            'view_link' => $view_link
        ]);
    }
}

require_once WPAICG_PLUGIN_DIR . 'classes/automations/module-triggers.php';

/**
* Handles the AJAX action for creating an Automated Task from the Content Writer UI.
*/
class AIPKit_Content_Writer_Create_Task_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle()
    {
        // 1. Validate permissions
        $permission_check = CreateTask\validate_permissions_logic($this);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in validate_permissions_logic.
        $raw_settings = isset($_POST) ? wp_unslash($_POST) : [];

        $generation_mode = $raw_settings['cw_generation_mode'] ?? 'task';
        if ($generation_mode === 'single') {
            $generation_mode = 'task';
        }
        if ($generation_mode === 'gsheets') {
            $paid_actions = Shared\paid_actions_class_logic();
            $verification_result = $paid_actions
                ? $paid_actions::verify_gsheets_task($raw_settings)
                : new WP_Error('gsheets_pro_feature_missing', __('Google Sheets integration is a Pro feature or its components are missing.', 'gpt3-ai-content-generator'));
            if (is_wp_error($verification_result)) {
                $this->send_wp_error($verification_result);
                return;
            }
        }

        // 2. Normalize basic task settings (name, frequency, status)
        $normalized_task_settings = CreateTask\normalize_task_settings_logic($raw_settings);
        $task_name = $normalized_task_settings['task_name'];
        $task_frequency = $normalized_task_settings['task_frequency'];
        $task_status = $normalized_task_settings['task_status'];

        // 3. Build the specific config for the content writer task
        $content_writer_config = CreateTask\build_content_writer_config_logic($raw_settings, $task_frequency, $task_status);
        if (is_wp_error($content_writer_config)) {
            $this->send_wp_error($content_writer_config);
            return;
        }

        // 4. Validate that the built config has all requirements
        $requirements_check = CreateTask\validate_task_requirements_logic($content_writer_config);
        if (is_wp_error($requirements_check)) {
            $this->send_wp_error($requirements_check);
            return;
        }

        if (in_array($generation_mode, ['task', 'csv'], true)) {
            $manual_items_validation = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\validate_manual_mode_items_logic($content_writer_config);
            if (is_wp_error($manual_items_validation)) {
                $this->send_wp_error($manual_items_validation);
                return;
            }
        }

        // --- START FIX: Determine task type based on generation mode sent from JS ---
        $mode_map = [
            'task'    => 'content_writing_bulk',
            'csv'     => 'content_writing_csv',
            'rss'     => 'content_writing_rss',
            'url'     => 'content_writing_url',
            'gsheets' => 'content_writing_gsheets',
        ];
        $task_type = $mode_map[$generation_mode] ?? 'content_writing_bulk'; // Fallback to bulk for safety.
        // --- END FIX ---

        // 5. Insert the task into the database
        $insert_result = CreateTask\insert_task_into_db_logic($task_name, $task_type, $content_writer_config, $task_status);
        if (is_wp_error($insert_result)) {
            $this->send_wp_error($insert_result);
            return;
        }
        $new_task_id = $insert_result;

        // 6. Schedule the cron event if the task is active
        CreateTask\schedule_task_if_active_logic($new_task_id, $task_status, $task_frequency);

        // 7. Send success response
        wp_send_json_success([
            'message' => __('Your content writing task is queued. You can track it under the Automated Tasks tab.', 'gpt3-ai-content-generator'),
            'task_id' => $new_task_id
        ]);
    }
}

/**
 * Prepares a batch of content items for inline generation (non-single modes).
 */
class AIPKit_Content_Writer_Prepare_Batch_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle()
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $raw_settings = isset($_POST) ? wp_unslash($_POST) : [];
        $generation_mode = isset($raw_settings['cw_generation_mode'])
            ? sanitize_key($raw_settings['cw_generation_mode'])
            : 'task';
        if ($generation_mode === 'single') {
            $generation_mode = 'task';
        }

        $allowed_modes = ['task', 'csv', 'rss', 'url', 'gsheets'];
        if (!in_array($generation_mode, $allowed_modes, true)) {
            $this->send_wp_error(new WP_Error('invalid_generation_mode', __('Invalid generation mode.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        if ($generation_mode === 'csv') {
            $raw_settings['content_title'] = $raw_settings['content_title_csv'] ?? '';
        }

        if ($generation_mode !== 'task') {
            unset($raw_settings['content_title_bulk']);
            if ($generation_mode !== 'csv') {
                $raw_settings['content_title'] = '';
            }
        }

        if (in_array($generation_mode, ['rss', 'url', 'gsheets'], true) && (!class_exists(aipkit_dashboard::class) || !aipkit_dashboard::is_pro_plan())) {
            $this->send_wp_error(new WP_Error('pro_feature_required', __('This generation mode is a Pro feature.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }

        $task_frequency = isset($raw_settings['task_frequency']) ? sanitize_key($raw_settings['task_frequency']) : 'manual';
        $task_status = isset($raw_settings['task_status']) ? sanitize_key($raw_settings['task_status']) : 'active';

        $task_config = CreateTask\build_content_writer_config_logic($raw_settings, $task_frequency, $task_status);
        if (is_wp_error($task_config)) {
            $this->send_wp_error($task_config);
            return;
        }

        $validation = CreateTask\validate_task_requirements_logic($task_config);
        if (is_wp_error($validation)) {
            $this->send_wp_error($validation);
            return;
        }

        if (in_array($generation_mode, ['task', 'csv'], true)) {
            $manual_items_validation = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\validate_manual_mode_items_logic($task_config);
            if (is_wp_error($manual_items_validation)) {
                $this->send_wp_error($manual_items_validation);
                return;
            }
        }

        $items = [];
        $scraped_contexts = [];

        switch ($generation_mode) {
            case 'task':
            case 'csv':
                $items = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\manual_mode_generate_items_logic($task_config);
                break;
            case 'rss':
                $items = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\rss_mode_generate_items_logic(0, $task_config, null);
                break;
            case 'url':
                $url_result = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\url_mode_generate_items_logic(0, $task_config);
                if (!is_wp_error($url_result)) {
                    $items = $url_result['topics'] ?? [];
                    $scraped_contexts = $url_result['contexts'] ?? [];
                } else {
                    $items = $url_result;
                }
                break;
            case 'gsheets':
                $items = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\gsheets_mode_generate_items_logic(0, $task_config);
                break;
        }

        if (is_wp_error($items)) {
            $this->send_wp_error($items);
            return;
        }

        if (!is_array($items)) {
            $items = [];
        }

        $prepared = [];
        $item_index = 0;
        $limit = 100;
        if ($generation_mode === 'rss' && class_exists('\WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector') && !\WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector::uses_legacy_selection($task_config)) {
            // The paid selector has already applied the user's chosen quantity.
            $limit = count($items);
        }

        foreach ($items as $item_data) {
            if (count($prepared) >= $limit) {
                break;
            }
            $item_config = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\prepare_item_config_logic(
                $item_data,
                $task_config,
                $scraped_contexts
            );

            $topic = $item_config['content_title'] ?? '';
            if ($topic === '') {
                $item_index++;
                continue;
            }
            $topic_label = sanitize_text_field($topic);

            $schedule_gmt = \WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules\compute_item_schedule_gmt_logic(
                $item_data,
                $task_config,
                $item_index,
                $generation_mode
            );

            if (is_wp_error($schedule_gmt)) {
                $this->send_wp_error($schedule_gmt);
                return;
            }

            if ($schedule_gmt) {
                $local_datetime = get_date_from_gmt($schedule_gmt, 'Y-m-d H:i:s');
                $parts = explode(' ', $local_datetime);
                if (count($parts) === 2) {
                    $item_config['post_schedule_date'] = $parts[0];
                    $item_config['post_schedule_time'] = substr($parts[1], 0, 5);
                }
            }

            $prepared[] = [
                'topic' => $topic_label,
                'item_config' => $item_config,
            ];

            $item_index++;
        }

        $empty_reason = '';
        if ($generation_mode === 'gsheets' && empty($prepared)) {
            $empty_reason = 'no_unprocessed_rows';
        }

        wp_send_json_success([
            'items' => $prepared,
            'total' => count($items),
            'returned' => count($prepared),
            'limit' => $limit,
            'empty_reason' => $empty_reason,
            'run_token' => $this->create_content_writer_batch_run(),
        ]);
    }

    /**
     * Cancels an active inline batch run on the server.
     */
    public function handle_cancel(): void
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $token = isset($_POST['batch_run_token']) ? sanitize_text_field(wp_unslash($_POST['batch_run_token'])) : '';
        $result = $this->cancel_content_writer_batch_run($token);
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }

        wp_send_json_success(['cancelled' => true]);
    }
}

/**
 * Handles the dedicated AJAX action for generating an SEO meta description after main content is created.
 */
class AIPKit_Content_Writer_Generate_Meta_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle()
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        [
            'generated_content' => $generated_content,
            'final_title' => $final_title,
            'keywords' => $keywords,
            'provider_raw' => $provider_raw,
            'model' => $model,
            'prompt_mode' => $prompt_mode,
            'custom_prompt' => $custom_meta_prompt,
        ] = $this->get_content_writer_generation_request('custom_meta_prompt');

        if (empty($generated_content) || empty($final_title) || empty($provider_raw) || empty($model)) {
            $this->send_wp_error(new WP_Error('missing_meta_data', 'Missing required data for meta description generation.', ['status' => 400]));
            return;
        }

        $provider = AIPKit_Providers::normalize_provider_label($provider_raw);

        if (!class_exists(AIPKit_Content_Writer_Summarizer::class) || !class_exists(AIPKit_Content_Writer_Meta_Prompt_Builder::class) || !$this->get_ai_caller()) {
            $this->send_wp_error(new WP_Error('missing_meta_dependencies', 'A component required for meta description generation is missing.', ['status' => 500]));
            return;
        }

        $content_summary = AIPKit_Content_Writer_Summarizer::summarize($generated_content);
        $meta_user_prompt = AIPKit_Content_Writer_Meta_Prompt_Builder::build($final_title, $content_summary, $keywords, $prompt_mode, $custom_meta_prompt);
        $meta_system_instruction = 'You are an SEO expert specializing in writing meta descriptions.';
        $meta_ai_params = [];

        $vector_preparation = $this->prepare_content_writer_vector_context(
            $meta_user_prompt,
            $provider,
            $meta_system_instruction,
            $meta_ai_params
        );
        if (is_wp_error($vector_preparation)) { $this->send_wp_error($vector_preparation); return; }
        [$meta_system_instruction, $meta_ai_params, $meta_instruction_context] = $vector_preparation;
        $meta_ai_params['top_p'] = null;

        $meta_result = $this->get_ai_caller()->make_standard_call(
            $provider,
            $model,
            [['role' => 'user', 'content' => $meta_user_prompt]],
            $meta_ai_params,
            $meta_system_instruction,
            $meta_instruction_context
        );

        if (is_wp_error($meta_result)) {
            $this->send_wp_error($meta_result);
            return;
        }

        $meta_description = !empty($meta_result['content']) ? AIPKit_Content_Writer_Output_Cleaner::clean_meta_description((string) $meta_result['content']) : null;
        if (empty($meta_description)) {
            $this->send_wp_error(new WP_Error('meta_gen_empty', 'AI did not return a valid meta description.', ['status' => 500]));
            return;
        }
        $conversation_uuid = $this->log_content_writer_generation_step(
            $provider,
            $model,
            'Generate Meta Description',
            [
                'title' => $final_title,
                'keywords' => $keywords,
                'prompt_mode' => $prompt_mode,
                'custom_meta_prompt' => $custom_meta_prompt,
            ],
            $meta_description,
            $meta_result['usage'] ?? null,
            $meta_user_prompt,
            $meta_ai_params,
            $meta_system_instruction,
            $meta_result
        );

        wp_send_json_success([
            'meta_description' => $meta_description,
            'usage' => $meta_result['usage'] ?? null,
            'conversation_uuid' => $conversation_uuid,
        ]);
    }
}

/**
 * Handles the dedicated AJAX action for generating an SEO focus keyword.
 */
class AIPKit_Content_Writer_Generate_Keyword_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle()
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        [
            'generated_content' => $generated_content,
            'final_title' => $final_title,
            'provider_raw' => $provider_raw,
            'model' => $model,
            'prompt_mode' => $prompt_mode,
            'custom_prompt' => $custom_keyword_prompt,
        ] = $this->get_content_writer_generation_request('custom_keyword_prompt');

        if (empty($generated_content) || empty($final_title) || empty($provider_raw) || empty($model)) {
            $this->send_wp_error(new WP_Error('missing_keyword_data', 'Missing required data for keyword generation.', ['status' => 400]));
            return;
        }

        $provider = AIPKit_Providers::normalize_provider_label($provider_raw);

        if (!class_exists(AIPKit_Content_Writer_Summarizer::class) || !class_exists(AIPKit_Content_Writer_Keyword_Prompt_Builder::class) || !$this->get_ai_caller()) {
            $this->send_wp_error(new WP_Error('missing_keyword_dependencies', 'A component required for keyword generation is missing.', ['status' => 500]));
            return;
        }

        $content_summary = AIPKit_Content_Writer_Summarizer::summarize($generated_content);
        $keyword_user_prompt = AIPKit_Content_Writer_Keyword_Prompt_Builder::build($final_title, $content_summary, $prompt_mode, $custom_keyword_prompt);
        $keyword_system_instruction = 'You are an SEO expert. Your task is to provide the single best focus keyword for a piece of content.';
        $keyword_ai_params = [];

        $vector_preparation = $this->prepare_content_writer_vector_context(
            $keyword_user_prompt,
            $provider,
            $keyword_system_instruction,
            $keyword_ai_params
        );
        if (is_wp_error($vector_preparation)) { $this->send_wp_error($vector_preparation); return; }
        [$keyword_system_instruction, $keyword_ai_params, $keyword_instruction_context] = $vector_preparation;
        $keyword_ai_params['top_p'] = null;

        $keyword_result = $this->get_ai_caller()->make_standard_call(
            $provider,
            $model,
            [['role' => 'user', 'content' => $keyword_user_prompt]],
            $keyword_ai_params,
            $keyword_system_instruction,
            $keyword_instruction_context
        );

        if (is_wp_error($keyword_result)) {
            $this->send_wp_error($keyword_result);
            return;
        }

        $focus_keyword = !empty($keyword_result['content']) ? trim(str_replace(['"', "'", '.'], '', $keyword_result['content'])) : null;
        if (empty($focus_keyword)) {
            $this->send_wp_error(new WP_Error('keyword_gen_empty', 'AI did not return a valid focus keyword.', ['status' => 500]));
            return;
        }

        $keyword_usage = $keyword_result['usage'] ?? null;
        $smart_seo_keyword_resolution = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $seo_score_improvement_enabled = isset($_POST['seo_score_improvement_enabled']) ? sanitize_text_field(wp_unslash($_POST['seo_score_improvement_enabled'])) : '0';
        $seo_score_disabled_rules = '[]';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        if (isset($_POST['seo_score_disabled_rules']) && class_exists(AIPKit_Content_Writer_SEO_Config::class)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked in check_module_access_permissions; sanitize_disabled_rules() sanitizes the nested rules payload after wp_unslash().
            $raw_seo_score_disabled_rules = wp_unslash($_POST['seo_score_disabled_rules']);
            $seo_score_disabled_rules = AIPKit_Content_Writer_SEO_Config::sanitize_disabled_rules($raw_seo_score_disabled_rules);
        }
        $smart_seo_config = [
            'seo_score_improvement_enabled' => $seo_score_improvement_enabled,
            'seo_score_continue_until_target' => '1',
            'seo_score_target' => '100',
            'seo_score_max_passes' => '3',
            'seo_score_profile' => 'auto',
            'seo_score_disabled_rules' => $seo_score_disabled_rules,
            'ai_provider' => $provider,
            'ai_model' => $model,
            'content_title' => $final_title,
        ];
        $keyword_resolution = Shared\resolve_generated_keyword_logic(
            $focus_keyword,
            $smart_seo_config,
            $this->get_ai_caller(),
            [
                'ai_provider' => $provider,
                'ai_model' => $model,
                'topic' => $final_title,
                'title' => $final_title,
                'content_summary' => $content_summary,
            ]
        );
        if (!empty($keyword_resolution['changed'])) {
            $focus_keyword = (string) $keyword_resolution['keyword'];
            $keyword_usage = merge_smart_seo_usage_logic($keyword_usage, $keyword_resolution['usage'] ?? null);
            $smart_seo_keyword_resolution = $keyword_resolution;
        }

        $conversation_uuid = $this->log_content_writer_generation_step(
            $provider,
            $model,
            'Generate Focus Keyword',
            [
                'title' => $final_title,
                'prompt_mode' => $prompt_mode,
                'custom_keyword_prompt' => $custom_keyword_prompt,
            ],
            $focus_keyword,
            $keyword_usage,
            $keyword_user_prompt,
            $keyword_ai_params,
            $keyword_system_instruction,
            $keyword_result
        );

        wp_send_json_success(array_merge([
            'focus_keyword' => $focus_keyword,
            'usage' => $keyword_usage,
            'conversation_uuid' => $conversation_uuid,
        ], smart_seo_keyword_resolution_response_fields_logic($smart_seo_keyword_resolution)));
    }

}

class AIPKit_Content_Writer_Generate_Excerpt_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle()
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        [
            'generated_content' => $generated_content,
            'final_title' => $final_title,
            'keywords' => $keywords,
            'provider_raw' => $provider_raw,
            'model' => $model,
            'prompt_mode' => $prompt_mode,
            'custom_prompt' => $custom_excerpt_prompt,
        ] = $this->get_content_writer_generation_request('custom_excerpt_prompt');

        if (empty($generated_content) || empty($final_title) || empty($provider_raw) || empty($model)) {
            $this->send_wp_error(new WP_Error('missing_excerpt_data', 'Missing required data for excerpt generation.', ['status' => 400]));
            return;
        }

        $provider = AIPKit_Providers::normalize_provider_label($provider_raw);

        if (
            !class_exists(AIPKit_Content_Writer_Summarizer::class) ||
            !class_exists(AIPKit_Content_Writer_Excerpt_Prompt_Builder::class) ||
            !$this->get_ai_caller()
        ) {
            $this->send_wp_error(new WP_Error('missing_excerpt_dependencies', 'A component required for excerpt generation is missing.', ['status' => 500]));
            return;
        }

        $content_summary = AIPKit_Content_Writer_Summarizer::summarize($generated_content);
        $excerpt_user_prompt = AIPKit_Content_Writer_Excerpt_Prompt_Builder::build(
            $final_title,
            $content_summary,
            $keywords,
            $prompt_mode,
            $custom_excerpt_prompt
        );
        $excerpt_system_instruction = 'You are an expert copywriter. Your task is to provide an engaging excerpt for a piece of content.';

        $excerpt_ai_params = [];

        $vector_preparation = $this->prepare_content_writer_vector_context(
            $excerpt_user_prompt,
            $provider,
            $excerpt_system_instruction,
            $excerpt_ai_params
        );
        if (is_wp_error($vector_preparation)) { $this->send_wp_error($vector_preparation); return; }
        [$excerpt_system_instruction, $excerpt_ai_params, $excerpt_instruction_context] = $vector_preparation;
        $excerpt_ai_params['top_p'] = null;

        $excerpt_result = $this->get_ai_caller()->make_standard_call(
            $provider,
            $model,
            [['role' => 'user', 'content' => $excerpt_user_prompt]],
            $excerpt_ai_params,
            $excerpt_system_instruction,
            $excerpt_instruction_context
        );

        if (is_wp_error($excerpt_result)) {
            $this->send_wp_error($excerpt_result);
            return;
        }

        $excerpt = !empty($excerpt_result['content']) ? trim(str_replace(['"', "'"], '', $excerpt_result['content'])) : null;
        if (empty($excerpt)) {
            $this->send_wp_error(new WP_Error('excerpt_gen_empty', 'AI did not return a valid excerpt.', ['status' => 500]));
            return;
        }

        $conversation_uuid = $this->log_content_writer_generation_step(
            $provider,
            $model,
            'Generate Excerpt',
            [
                'title' => $final_title,
                'keywords' => $keywords,
                'prompt_mode' => $prompt_mode,
                'custom_excerpt_prompt' => $custom_excerpt_prompt,
            ],
            $excerpt,
            $excerpt_result['usage'] ?? null,
            $excerpt_user_prompt,
            $excerpt_ai_params,
            $excerpt_system_instruction,
            $excerpt_result
        );

        wp_send_json_success([
            'excerpt' => $excerpt,
            'usage' => $excerpt_result['usage'] ?? null,
            'conversation_uuid' => $conversation_uuid,
        ]);
    }
}

/**
 * Handles the dedicated AJAX action for generating post tags.
 */
class AIPKit_Content_Writer_Generate_Tags_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle(): void
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        [
            'generated_content' => $generated_content,
            'final_title' => $final_title,
            'keywords' => $keywords,
            'provider_raw' => $provider_raw,
            'model' => $model,
            'prompt_mode' => $prompt_mode,
            'custom_prompt' => $custom_tags_prompt,
        ] = $this->get_content_writer_generation_request('custom_tags_prompt');

        if (empty($generated_content) || empty($final_title) || empty($provider_raw) || empty($model)) {
            $this->send_wp_error(new WP_Error('missing_tags_data', 'Missing required data for tags generation.', ['status' => 400]));
            return;
        }

        $provider = AIPKit_Providers::normalize_provider_label($provider_raw);

        if (!class_exists(AIPKit_Content_Writer_Summarizer::class) || !class_exists(AIPKit_Content_Writer_Tags_Prompt_Builder::class) || !$this->get_ai_caller()) {
            $this->send_wp_error(new WP_Error('missing_tags_dependencies', 'A component required for tags generation is missing.', ['status' => 500]));
            return;
        }

        $content_summary = AIPKit_Content_Writer_Summarizer::summarize($generated_content);
        $tags_user_prompt = AIPKit_Content_Writer_Tags_Prompt_Builder::build($final_title, $content_summary, $keywords, $prompt_mode, $custom_tags_prompt);
        $tags_system_instruction = 'You are an SEO expert. Your task is to provide a comma-separated list of relevant tags for a piece of content.';
        $tags_ai_params = [];

        $vector_preparation = $this->prepare_content_writer_vector_context(
            $tags_user_prompt,
            $provider,
            $tags_system_instruction,
            $tags_ai_params
        );
        if (is_wp_error($vector_preparation)) { $this->send_wp_error($vector_preparation); return; }
        [$tags_system_instruction, $tags_ai_params, $tags_instruction_context] = $vector_preparation;
        $tags_ai_params['top_p'] = null;

        $tags_result = $this->get_ai_caller()->make_standard_call(
            $provider,
            $model,
            [['role' => 'user', 'content' => $tags_user_prompt]],
            $tags_ai_params,
            $tags_system_instruction,
            $tags_instruction_context
        );

        if (is_wp_error($tags_result)) {
            $this->send_wp_error($tags_result);
            return;
        }

        $tags = !empty($tags_result['content']) ? trim(str_replace(['"', "'"], '', $tags_result['content'])) : null;
        if (empty($tags)) {
            $this->send_wp_error(new WP_Error('tags_gen_empty', 'AI did not return any valid tags.', ['status' => 500]));
            return;
        }
        $conversation_uuid = $this->log_content_writer_generation_step(
            $provider,
            $model,
            'Generate Tags',
            [
                'title' => $final_title,
                'keywords' => $keywords,
                'prompt_mode' => $prompt_mode,
                'custom_tags_prompt' => $custom_tags_prompt,
            ],
            $tags,
            $tags_result['usage'] ?? null,
            $tags_user_prompt,
            $tags_ai_params,
            $tags_system_instruction,
            $tags_result
        );

        wp_send_json_success([
            'tags' => $tags,
            'usage' => $tags_result['usage'] ?? null,
            'conversation_uuid' => $conversation_uuid,
        ]);
    }
}


/**
 * Handles the dedicated AJAX action for generating AI images within the Content Writer.
 * UPDATED: Strips large base64 data from the response to prevent "Request Entity Too Large" errors.
 */
class AIPKit_Content_Writer_Generate_Images_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    private const IMAGE_REQUEST_TTL = DAY_IN_SECONDS;
    private const IMAGE_REQUEST_STALE_SECONDS = 900;

    private function sanitize_image_request_id(string $request_id): string
    {
        $request_id = sanitize_key($request_id);
        if ($request_id === '') {
            return '';
        }

        return substr($request_id, 0, 96);
    }

    private function get_image_request_transient_key(string $request_id): string
    {
        $user_id = get_current_user_id();
        return 'aipkit_cw_img_req_' . md5($user_id . '|' . $request_id);
    }

    private function build_image_request_hash(array $settings): string
    {
        $hash_fields = [
            'original_topic',
            'final_title',
            'post_title',
            'keywords',
            'excerpt',
            'provider',
            'model',
            'ai_provider',
            'ai_model',
            'ai_temperature',
            'reasoning_effort',
            'image_provider',
            'image_model',
            'image_prompt',
            'featured_image_prompt',
            'generate_images_enabled',
            'image_count',
            'image_start_index',
            'generate_featured_image',
            'image_placement',
            'image_placement_param_x',
            'generate_image_title',
            'generate_image_alt_text',
            'generate_image_caption',
            'generate_image_description',
            'image_title_prompt',
            'image_alt_text_prompt',
            'image_caption_prompt',
            'image_description_prompt',
            'pexels_orientation',
            'pexels_size',
            'pexels_color',
            'pixabay_orientation',
            'pixabay_image_type',
            'pixabay_category',
        ];
        $fingerprint = [];

        foreach ($hash_fields as $field) {
            if (array_key_exists($field, $settings)) {
                $fingerprint[$field] = is_scalar($settings[$field]) ? (string) $settings[$field] : '';
            }
        }

        $fingerprint['image_provider_options'] = class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)
            ? AIPKit_Content_Writer_Image_Provider_Options::get_hash_value($settings)
            : (is_scalar($settings['image_provider_options'] ?? null) ? (string) $settings['image_provider_options'] : '');

        ksort($fingerprint);
        return md5(wp_json_encode($fingerprint));
    }

    private function get_image_request_record(string $request_id): array
    {
        $record = get_transient($this->get_image_request_transient_key($request_id));
        return is_array($record) ? $record : [];
    }

    private function set_image_request_record(string $request_id, array $record): void
    {
        set_transient($this->get_image_request_transient_key($request_id), $record, self::IMAGE_REQUEST_TTL);
    }

    private function is_image_request_running(array $record): bool
    {
        return ($record['status'] ?? '') === 'running';
    }

    private function is_image_request_stale(array $record): bool
    {
        $started_at = isset($record['started_at']) ? (int) $record['started_at'] : 0;
        return $started_at <= 0 || (time() - $started_at) > self::IMAGE_REQUEST_STALE_SECONDS;
    }

    private function send_image_request_record_response(string $request_id, array $record): void
    {
        $status = (string) ($record['status'] ?? '');

        if ($status === 'completed' && isset($record['image_data']) && is_array($record['image_data'])) {
            wp_send_json_success([
                'image_data' => $record['image_data'],
                'image_status' => 'completed',
                'image_request_id' => $request_id,
                'recovered' => true,
            ]);
        }

        if ($status === 'failed') {
            $message = isset($record['message']) && is_string($record['message'])
                ? $record['message']
                : __('Image generation failed.', 'gpt3-ai-content-generator');
            $error_data = is_array($record['error_data'] ?? null) ? $record['error_data'] : ['status' => 500];
            $error_data['image_status'] = 'failed';
            $error_data['image_request_id'] = $request_id;
            $this->send_wp_error(new WP_Error($record['error_code'] ?? 'image_request_failed', $message, $error_data));
            return;
        }

        wp_send_json_success([
            'image_status' => 'running',
            'image_request_id' => $request_id,
            'retry_after' => 3,
        ]);
    }

    /**
     * Removes attachments created by an image request that was cancelled
     * before the batch item could be committed.
     */
    private function delete_generated_image_attachments(array $image_result): void
    {
        $attachment_ids = [];
        if (!empty($image_result['in_content_images']) && is_array($image_result['in_content_images'])) {
            foreach ($image_result['in_content_images'] as $image_item) {
                if (is_array($image_item) && !empty($image_item['attachment_id'])) {
                    $attachment_ids[] = absint($image_item['attachment_id']);
                }
            }
        }
        if (!empty($image_result['featured_image_id'])) {
            $attachment_ids[] = absint($image_result['featured_image_id']);
        }

        foreach (array_unique(array_filter($attachment_ids)) as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }

    public function handle()
    {
        $this->maybe_extend_execution_limits(300);
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->send_wp_error($batch_run_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_module_access_permissions.
        $settings = isset($_POST) ? wp_unslash($_POST) : [];
        $settings['aipkit_event_module'] = 'content_writer';
        $settings['aipkit_event_origin'] = 'content_writer_direct_images';
        $image_request_id = isset($settings['image_request_id'])
            ? $this->sanitize_image_request_id((string) $settings['image_request_id'])
            : '';
        $is_image_request_poll = isset($settings['image_request_poll']) && (string) $settings['image_request_poll'] === '1';
        $image_request_hash = $image_request_id !== '' ? $this->build_image_request_hash($settings) : '';

        if ($image_request_id !== '') {
            $existing_record = $this->get_image_request_record($image_request_id);
            $existing_hash = isset($existing_record['request_hash']) ? (string) $existing_record['request_hash'] : '';
            $hash_matches = $existing_hash === '' || $existing_hash === $image_request_hash;

            if (!empty($existing_record) && $hash_matches) {
                $is_running = $this->is_image_request_running($existing_record);
                $is_stale = $this->is_image_request_stale($existing_record);
                if ($is_running && $is_stale && $is_image_request_poll) {
                    wp_send_json_success([
                        'image_status' => 'missing',
                        'image_request_id' => $image_request_id,
                        'retry_after' => 3,
                    ]);
                    return;
                }

                if (!$is_running || !$is_stale || $is_image_request_poll) {
                    $this->send_image_request_record_response($image_request_id, $existing_record);
                    return;
                }
            } elseif ($is_image_request_poll) {
                wp_send_json_success([
                    'image_status' => 'missing',
                    'image_request_id' => $image_request_id,
                    'retry_after' => 3,
                ]);
                return;
            }

            $this->set_image_request_record($image_request_id, [
                'status' => 'running',
                'request_hash' => $image_request_hash,
                'started_at' => time(),
                'updated_at' => time(),
            ]);
        } elseif ($is_image_request_poll) {
            wp_send_json_success([
                'image_status' => 'missing',
                'retry_after' => 3,
            ]);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_module_access_permissions.
        $final_title = isset($settings['final_title']) ? sanitize_text_field($settings['final_title']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_module_access_permissions.
        $final_keywords = isset($settings['keywords']) ? sanitize_text_field($settings['keywords']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_module_access_permissions.
        $original_topic = isset($settings['original_topic']) ? sanitize_text_field($settings['original_topic']) : $final_title;

        if (!class_exists(AIPKit_Content_Writer_Image_Handler::class)) {
            $this->send_wp_error(new WP_Error('missing_image_handler', 'Image generation component is missing.', ['status' => 500]));
            return;
        }

        $image_handler = new AIPKit_Content_Writer_Image_Handler();
        $image_result = $image_handler->generate_and_prepare_images($settings, $final_title, $final_keywords, $original_topic);

        if (is_wp_error($image_result)) {
            if ($image_request_id !== '') {
                $this->set_image_request_record($image_request_id, [
                    'status' => 'failed',
                    'request_hash' => $image_request_hash,
                    'message' => $image_result->get_error_message(),
                    'error_code' => $image_result->get_error_code(),
                    'error_data' => $image_result->get_error_data(),
                    'started_at' => time(),
                    'updated_at' => time(),
                    'completed_at' => time(),
                ]);
            }
            $this->send_wp_error($image_result);
            return;
        }

        $batch_run_check = $this->validate_content_writer_batch_run_request();
        if (is_wp_error($batch_run_check)) {
            $this->delete_generated_image_attachments($image_result);
            if ($image_request_id !== '') {
                $this->set_image_request_record($image_request_id, [
                    'status' => 'failed',
                    'request_hash' => $image_request_hash,
                    'message' => $batch_run_check->get_error_message(),
                    'started_at' => time(),
                    'updated_at' => time(),
                    'completed_at' => time(),
                ]);
            }
            $this->send_wp_error($batch_run_check);
            return;
        }

        $requested_inline = ($settings['generate_images_enabled'] ?? '0') === '1' && absint($settings['image_count'] ?? 0) > 0;
        $requested_featured = ($settings['generate_featured_image'] ?? '0') === '1';
        $requested_any_image = $requested_inline || $requested_featured;
        $inline_count = !empty($image_result['in_content_images']) && is_array($image_result['in_content_images'])
            ? count($image_result['in_content_images'])
            : 0;
        $has_featured = !empty($image_result['featured_image_id']) || !empty($image_result['featured_image_url']);
        $generated_any_image = $inline_count > 0 || $has_featured;
        $warning_messages = [];
        if (!empty($image_result['warnings']) && is_array($image_result['warnings'])) {
            foreach ($image_result['warnings'] as $warning_message) {
                $normalized_warning = trim(wp_strip_all_tags((string) $warning_message));
                if ($normalized_warning === '' || in_array($normalized_warning, $warning_messages, true)) {
                    continue;
                }
                $warning_messages[] = $normalized_warning;
            }
        } elseif (!empty($image_result['warning']) && is_string($image_result['warning'])) {
            $normalized_warning = trim(wp_strip_all_tags($image_result['warning']));
            if ($normalized_warning !== '') {
                $warning_messages[] = $normalized_warning;
            }
        }

        if ($requested_any_image && !$generated_any_image && empty($warning_messages)) {
            $provider = isset($settings['image_provider']) ? sanitize_text_field((string) $settings['image_provider']) : '';
            $model = isset($settings['image_model']) ? sanitize_text_field((string) $settings['image_model']) : '';
            $fallback_warning = __('Image generation did not return any image data for the selected provider/model.', 'gpt3-ai-content-generator');
            if ($provider !== '' || $model !== '') {
                $fallback_warning .= ' ' . sprintf(
                    /* translators: 1: provider name, 2: model name */
                    __('Provider: %1$s, Model: %2$s.', 'gpt3-ai-content-generator'),
                    $provider !== '' ? $provider : __('unknown provider', 'gpt3-ai-content-generator'),
                    $model !== '' ? $model : __('unknown model', 'gpt3-ai-content-generator')
                );
            }
            $warning_messages[] = $fallback_warning;
        }

        if (!empty($warning_messages)) {
            $image_result['warnings'] = $warning_messages;
            $image_result['warning'] = $warning_messages[0];
        }

        // --- START FIX: Strip b64_json from response to prevent 413 "Request Entity Too Large" on subsequent saves ---
        if (isset($image_result['in_content_images']) && is_array($image_result['in_content_images'])) {
            foreach ($image_result['in_content_images'] as &$image_item) {
                unset($image_item['b64_json']);
            }
            unset($image_item); // Unset the reference
        }
        // --- END FIX ---

        if ($image_request_id !== '') {
            $this->set_image_request_record($image_request_id, [
                'status' => 'completed',
                'request_hash' => $image_request_hash,
                'image_data' => $image_result,
                'started_at' => time(),
                'updated_at' => time(),
                'completed_at' => time(),
            ]);
        }

        // Optional logging under the same conversation
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $conversation_uuid = isset($_POST['conversation_uuid']) ? sanitize_text_field(wp_unslash($_POST['conversation_uuid'])) : '';
        if (!empty($conversation_uuid) && $this->log_storage) {
            $provider = isset($settings['image_provider']) ? sanitize_text_field($settings['image_provider']) : '';
            $model = isset($settings['image_model']) ? sanitize_text_field($settings['image_model']) : '';
            $generate_in_content = ($settings['generate_images_enabled'] ?? '0') === '1';
            $image_count = absint($settings['image_count'] ?? 0);
            $generate_featured = ($settings['generate_featured_image'] ?? '0') === '1';

            $base = $this->build_content_writer_log_base($conversation_uuid, $provider, $model);

            // Build a compact list of images to avoid large payloads
            $inline_images_meta = [];
            if (!empty($image_result['in_content_images']) && is_array($image_result['in_content_images'])) {
                foreach ($image_result['in_content_images'] as $idx => $img) {
                    $inline_images_meta[] = [
                        'type' => 'inline',
                        'index' => $idx,
                        'attachment_id' => isset($img['attachment_id']) ? $img['attachment_id'] : null,
                        'url' => $img['url'] ?? ($img['src'] ?? ($img['image_url'] ?? null)),
                        'provider' => $provider,
                    ];
                }
            }
            $featured_meta = null;
            if (!empty($image_result['featured_image_id'])) {
                $featured_meta = [
                    'type' => 'featured',
                    'attachment_id' => $image_result['featured_image_id'],
                    'provider' => $provider,
                ];
            }

            // Log the user intent
            $this->log_storage->log_message(array_merge($base, [
                'message_role' => 'user',
                'message_content' => 'Generate Images',
                'request_payload' => [
                    'original_topic' => $original_topic,
                    'final_title' => $final_title,
                    'keywords' => $final_keywords,
                    'image_prompt' => isset($settings['image_prompt']) ? (string) $settings['image_prompt'] : null,
                    'featured_image_prompt' => isset($settings['featured_image_prompt']) ? (string) $settings['featured_image_prompt'] : null,
                    'generate_images_enabled' => $generate_in_content ? 1 : 0,
                    'image_count' => $image_count,
                    'generate_featured_image' => $generate_featured ? 1 : 0,
                    'image_provider' => $provider,
                    'image_model' => $model,
                    'placement' => $settings['image_placement'] ?? 'after_first_h2',
                    'placement_param_x' => isset($settings['image_placement_param_x']) ? absint($settings['image_placement_param_x']) : null,
                ],
            ]));

            // Log the result with compact metadata
            $this->log_storage->log_message(array_merge($base, [
                'message_role' => 'bot',
                'message_content' => $generated_any_image ? 'Images Generated' : 'Images Skipped',
                'usage' => null,
                'request_payload' => [
                    'result_summary' => [
                        'inline_count' => count($inline_images_meta),
                        'featured_present' => !empty($featured_meta),
                        'warning' => $image_result['warning'] ?? null,
                        'warnings' => $image_result['warnings'] ?? [],
                    ],
                    'images' => [
                        'inline' => $inline_images_meta,
                        'featured' => $featured_meta,
                    ],
                ],
            ]));
        }

        wp_send_json_success([
            'image_data' => $image_result,
            'image_status' => 'completed',
            'image_request_id' => $image_request_id,
        ]);
    }
}

class AIPKit_Content_Writer_Parse_Csv_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle()
    {
        // Manual permission check for either 'content-writer' or 'autogpt'
        if (
            !\WPAICG\AIPKit_Role_Manager::user_can_access_module('content-writer') &&
            !\WPAICG\AIPKit_Role_Manager::user_can_access_module('autogpt')
        ) {
            $this->send_wp_error(new WP_Error('permission_denied', __('You do not have permission to use this feature.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }

        // Manual nonce check for either page's nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce is verified directly with wp_verify_nonce().
        $nonce = $_POST['_ajax_nonce'] ?? '';
        if (
            !wp_verify_nonce($nonce, 'aipkit_content_writer_nonce') &&
            !wp_verify_nonce($nonce, 'aipkit_automated_tasks_manage_nonce')
        ) {
            $this->send_wp_error(new WP_Error('nonce_failure', __('Security check failed.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }

        // --- Task 2.2: File Validation ---
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES data is validated by AIPKit_Upload_Utils::validate_upload_file().
        if (!isset($_FILES['file'])) {
            $this->send_wp_error(new WP_Error('no_file_received', __('No CSV file was received.', 'gpt3-ai-content-generator')), 400);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES data is validated by AIPKit_Upload_Utils::validate_upload_file().
        $file_data = $_FILES['file'];

        if (!class_exists(AIPKit_Upload_Utils::class)) {
            $this->send_wp_error(new WP_Error('internal_error', __('File validation component is missing.', 'gpt3-ai-content-generator')), 500);
            return;
        }

        $allowed_mime_types = AIPKit_Upload_Utils::get_content_writer_allowed_mime_types();
        // Use the general validation function, passing our specific MIME types
        $validation_result = AIPKit_Upload_Utils::validate_upload_file($file_data, $allowed_mime_types);

        if (is_wp_error($validation_result)) {
            $this->send_wp_error($validation_result);
            return;
        }

        // --- Task 2.3: CSV Parsing Logic ---
        $csv_file_path = $file_data['tmp_name'];
        $formatted_data = '';
        $tasks_found = 0;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading from a temporary uploaded file is a standard and safe use case for these functions.
        if (($handle = fopen($csv_file_path, "r")) !== false) {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Convert row array to pipe-separated string
                $formatted_data .= implode('|', $row) . "\n";
                $tasks_found++;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Reading from a temporary uploaded file is a standard and safe use case for these functions.
            fclose($handle);
        } else {
            $this->send_wp_error(new WP_Error('csv_read_error', __('Could not open the uploaded CSV file.', 'gpt3-ai-content-generator')), 500);
            return;
        }

        wp_send_json_success([
            'tasks_found' => $tasks_found,
            'formatted_data' => trim($formatted_data)
        ]);
    }
}

class AIPKit_Content_Writer_Fetch_Posts_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    public function handle()
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $media_filter = isset($_POST['media_filter']) ? sanitize_key(wp_unslash($_POST['media_filter'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $paged = isset($_POST['paged']) ? absint($_POST['paged']) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 10;

        if ($paged < 1) {
            $paged = 1;
        }
        $allowed_per_page = [10, 25, 50, 100, 1000];
        if (!in_array($per_page, $allowed_per_page, true)) {
            $per_page = 10;
        }

        $post_types = get_post_types(['public' => true], 'objects');
        $allowed_types = array_keys($post_types);
        $allowed_non_attachment = array_diff($allowed_types, ['attachment']);

        $query_post_type = $allowed_non_attachment;
        if (empty($query_post_type)) {
            $query_post_type = 'any';
        }
        if ($post_type === 'attachment') {
            $query_post_type = 'attachment';
        } elseif ($post_type && in_array($post_type, $allowed_non_attachment, true)) {
            $query_post_type = $post_type;
        }

        $query_status = $query_post_type === 'attachment' ? 'inherit' : 'any';

        if ($media_filter === 'unattached') {
            $media_filter = 'detached';
        }

        $query_args = [
            'post_type' => $query_post_type,
            'post_status' => $query_status,
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
            's' => $search,
            'no_found_rows' => false,
        ];

        if ($query_post_type === 'attachment' && $media_filter) {
            if ($media_filter === 'image') {
                $query_args['post_mime_type'] = 'image';
            } elseif ($media_filter === 'detached') {
                $query_args['post_parent'] = 0;
            } elseif ($media_filter === 'mine') {
                $query_args['author'] = get_current_user_id();
            }
        }

        $query = new \WP_Query($query_args);

        $posts = [];
        foreach ($query->posts as $post) {
            $title = get_the_title($post);
            if ($title === '') {
                $title = __('(Untitled)', 'gpt3-ai-content-generator');
            }

            $status_object = get_post_status_object($post->post_status);
            $alt_text = '';
            $caption = '';
            $description = '';
            $thumb_url = '';
            $file_name = '';

            if ($post->post_type === 'attachment') {
                $alt_text = get_post_meta($post->ID, '_wp_attachment_image_alt', true);
                $caption = $post->post_excerpt;
                $description = $post->post_content;
                $alt_text = trim(wp_strip_all_tags((string) $alt_text));
                $caption = trim(wp_strip_all_tags((string) $caption));
                $description = trim(wp_strip_all_tags((string) $description));
                $thumb_url = (string) wp_get_attachment_image_url($post->ID, 'thumbnail');
                $file_path = get_attached_file($post->ID);
                if ($file_path) {
                    $file_name = wp_basename($file_path);
                } else {
                    $guid_path = wp_parse_url((string) $post->guid, PHP_URL_PATH);
                    if (!empty($guid_path)) {
                        $file_name = wp_basename($guid_path);
                    }
                }
            }

            $posts[] = [
                'id' => (int) $post->ID,
                'title' => $title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'status_label' => $status_object ? $status_object->label : $post->post_status,
                'edit_link' => (string) get_edit_post_link($post->ID, ''),
                'alt_text' => $alt_text,
                'caption' => $caption,
                'description' => $description,
                'thumb_url' => $thumb_url,
                'file_name' => $file_name,
            ];
        }

        wp_send_json_success([
            'posts' => $posts,
            'pagination' => [
                'page' => $paged,
                'per_page' => $per_page,
                'total' => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ]);
    }
}

/**
 * Prepares and validates a server-bound Content Writer update run.
 */
class AIPKit_Content_Writer_Prepare_Update_Run_Action extends AIPKit_Content_Writer_Base_Ajax_Action
{
    private const TRANSIENT_PREFIX = 'aipkit_cw_update_run_';
    private const RUN_TTL = 30 * MINUTE_IN_SECONDS;
    private const TEXT_FIELDS = ['title', 'content', 'meta', 'keyword', 'excerpt', 'tags'];
    private const IMAGE_FIELDS = ['title', 'keyword', 'excerpt', 'content'];

    public function handle(): void
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $mode = $this->get_request_key('mode');
        $post_ids = $this->get_request_array('post_ids', 'absint');
        $fields = $this->get_request_array('fields', 'sanitize_key');
        $result = self::create_run($mode, $post_ids, $fields);

        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }

        wp_send_json_success(['run_token' => $result]);
    }

    /**
     * Marks a prepared update run as cancelled so in-flight field requests
     * cannot write their generated value after the user presses Stop.
     */
    public function handle_cancel(): void
    {
        $permission_check = $this->check_module_access_permissions('content-writer', 'aipkit_content_writer_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions.
        $token = isset($_POST['run_token']) ? sanitize_text_field(wp_unslash($_POST['run_token'])) : '';
        $result = self::cancel_run($token);
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }

        wp_send_json_success(['cancelled' => true]);
    }

    /**
     * @param int[]    $post_ids
     * @param string[] $fields
     * @return string|WP_Error
     */
    private static function create_run(string $mode, array $post_ids, array $fields)
    {
        $allowed_modes = ['existing-content', 'existing-images', 'existing-products'];
        if (!in_array($mode, $allowed_modes, true)) {
            return new WP_Error('invalid_update_mode', __('Invalid update mode.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $post_ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
        $fields = array_values(array_unique(array_filter(array_map('sanitize_key', $fields))));
        if (empty($post_ids) || empty($fields)) {
            return new WP_Error('invalid_update_run', __('Select at least one item and field to update.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $allowed_fields = $mode === 'existing-images' ? self::IMAGE_FIELDS : self::TEXT_FIELDS;
        if (array_diff($fields, $allowed_fields)) {
            return new WP_Error('invalid_update_fields', __('The update contains unsupported fields.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $is_pro = class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
        if (!$is_pro && $mode === 'existing-images' && count($post_ids) > 1) {
            return new WP_Error('bulk_images_pro_required', __('Bulk image optimization is available on Pro.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        if (!$is_pro && $mode === 'existing-products') {
            return new WP_Error('products_pro_required', __('Product optimization is a Pro feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || !current_user_can('edit_post', $post_id)) {
                return new WP_Error('invalid_update_item', __('One or more selected items cannot be updated.', 'gpt3-ai-content-generator'), ['status' => 403]);
            }
            if ($mode === 'existing-images' && $post->post_type !== 'attachment') {
                return new WP_Error('invalid_image_item', __('Image updates require media attachments.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            if ($mode === 'existing-products' && $post->post_type !== 'product') {
                return new WP_Error('invalid_product_item', __('WooCommerce updates require products.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            if ($mode === 'existing-content' && $post->post_type === 'attachment') {
                return new WP_Error('invalid_content_item', __('Rewrite content does not update media attachments.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            if (!$is_pro && $mode === 'existing-content' && count($post_ids) > 1 && $post->post_type === 'product') {
                return new WP_Error('bulk_products_pro_required', __('Bulk product optimization is available on Pro.', 'gpt3-ai-content-generator'), ['status' => 403]);
            }
        }

        $token = wp_generate_password(40, false, false);
        $payload = [
            'user_id' => get_current_user_id(),
            'mode' => $mode,
            'post_ids' => $post_ids,
            'fields' => $fields,
            'cancelled' => false,
        ];
        set_transient(self::get_transient_key($token), $payload, self::RUN_TTL);

        return $token;
    }

    /**
     * Verifies that an individual field request belongs to a prepared run.
     *
     * @return mixed[]|WP_Error
     */
    public static function validate_request(string $token, int $post_id, string $field)
    {
        if ($token === '') {
            return new WP_Error('missing_update_run', __('The update session is missing. Please start the update again.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        $payload = get_transient(self::get_transient_key($token));
        if (!is_array($payload)) {
            return new WP_Error('expired_update_run', __('The update session expired. Please start the update again.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        if ((int) ($payload['user_id'] ?? 0) !== get_current_user_id()) {
            return new WP_Error('invalid_update_run_user', __('This update session is not valid for the current user.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        if (!empty($payload['cancelled'])) {
            return new WP_Error('update_run_stopped', __('This update was stopped.', 'gpt3-ai-content-generator'), ['status' => 409]);
        }
        if (!in_array($post_id, $payload['post_ids'] ?? [], true) || !in_array($field, $payload['fields'] ?? [], true)) {
            return new WP_Error('invalid_update_run_item', __('This item or field is not part of the prepared update.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        return $payload;
    }

    /**
     * @return true|WP_Error
     */
    private static function cancel_run(string $token)
    {
        if ($token === '') {
            return new WP_Error('missing_update_run', __('The update session is missing. Please start the update again.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        $transient_key = self::get_transient_key($token);
        $payload = get_transient($transient_key);
        if (!is_array($payload)) {
            return new WP_Error('expired_update_run', __('The update session expired. Please start the update again.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        if ((int) ($payload['user_id'] ?? 0) !== get_current_user_id()) {
            return new WP_Error('invalid_update_run_user', __('This update session is not valid for the current user.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        $payload['cancelled'] = true;
        set_transient($transient_key, $payload, self::RUN_TTL);

        return true;
    }

    private static function get_transient_key(string $token): string
    {
        return self::TRANSIENT_PREFIX . md5($token);
    }

    private function get_request_key(string $name): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked at the start of handle().
        return isset($_POST[$name]) ? sanitize_key(wp_unslash($_POST[$name])) : '';
    }

    /**
     * @return mixed[]
     */
    private function get_request_array(string $name, string $sanitize_callback): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is checked at the start of handle; decoded values are sanitized below.
        $raw_value = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '[]';
        $values = is_array($raw_value) ? $raw_value : json_decode((string) $raw_value, true);
        if (!is_array($values)) {
            return [];
        }

        return array_map($sanitize_callback, $values);
    }
}
