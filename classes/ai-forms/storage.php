<?php

namespace WPAICG\AIForms\Storage;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Manages storage and retrieval of AI Form CPTs and their settings (meta).
 */
class AIPKit_AI_Form_Storage
{
    /**
     * Retrieves AI Form data and settings.
     *
     * @param int $form_id The ID of the AI Form post.
     * @return array|\WP_Error Form data array or WP_Error if not found or invalid.
     */
    public function get_form_data(int $form_id)
    {
        return Methods\get_form_data_logic($this, $form_id);
    }

    /**
     * Saves AI Form settings.
     *
     * @param int $form_id The ID of the form CPT.
     * @param array $settings An array containing settings.
     * @return bool True on success, false on failure.
     */
    public function save_form_settings(int $form_id, array $settings): bool
    {
        return Methods\save_form_settings_logic($this, $form_id, $settings);
    }

    /**
     * Creates a new AI Form CPT.
     *
     * @param string $title The title of the form.
     * @param array $settings Optional settings to save.
     * @return int|\WP_Error The new post ID on success, or WP_Error on failure.
     */
    public function create_form(string $title, array $settings = [])
    {
        return Methods\create_form_logic($this, $title, $settings);
    }

    /**
     * Deletes an AI Form CPT.
     *
     * @param int $form_id The ID of the form to delete.
     * @return bool True on success, false on failure.
     */
    public function delete_form(int $form_id): bool
    {
        return Methods\delete_form_logic($this, $form_id);
    }

    /**
     * Retrieves a list of all AI Forms.
     *
     * @param array $args WP_Query arguments.
     * @return array List of form objects (ID, title, shortcode).
     */
    public function get_forms_list(array $args = []): array
    {
        return Methods\get_forms_list_logic($this, $args);
    }

}

namespace WPAICG\AIForms\Storage\Methods;

use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Admin_Setup;
use WPAICG\AIPKit_Providers;
use WPAICG\Lib\AIForms\StorageSettings;
use WP_Error;
use WP_Query;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;

if (!defined('ABSPATH')) {
    exit;
}

/** Shared labels plus defaults supplied by the available paid edition. */
function get_label_defaults(): array
{
    $default_labels = [
        'generate_button' => __('Generate', 'gpt3-ai-content-generator'),
        'stop_button'     => __('Stop', 'gpt3-ai-content-generator'),
        'download_button' => __('Download', 'gpt3-ai-content-generator'),
        'save_button'     => __('Save', 'gpt3-ai-content-generator'),
        'copy_button'     => __('Copy', 'gpt3-ai-content-generator'),
        'provider_label'  => __('AI Provider', 'gpt3-ai-content-generator'),
        'model_label'     => __('AI Model', 'gpt3-ai-content-generator'),
    ];
    return class_exists(StorageSettings::class)
        ? array_merge($default_labels, StorageSettings::get_label_defaults())
        : $default_labels;
}

/**
 * Logic for retrieving AI Form data and settings.
 *
 * @param \WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance The instance of the storage class.
 * @param int $form_id The ID of the AI Form post.
 * @return array|WP_Error Form data array or WP_Error if not found or invalid.
 */
function get_form_data_logic(\WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance, int $form_id)
{
    if (!class_exists(AIPKit_AI_Form_Admin_Setup::class)) {
        return new WP_Error('dependency_missing', 'AI Form Admin Setup class not found.');
    }
    $post = get_post($form_id);
    if (!$post || $post->post_type !== AIPKit_AI_Form_Admin_Setup::POST_TYPE) {
        return new WP_Error('form_not_found', 'AI Form not found or invalid ID.');
    }
    if ($post->post_status !== 'publish' && $post->post_status !== 'draft') {
        return new WP_Error('form_not_active', 'AI Form is not currently active.');
    }

    $form_structure_json = get_post_meta($form_id, '_aipkit_ai_form_structure', true);
    $form_structure = json_decode($form_structure_json, true);

    // --- Backward Compatibility Migration ---
    if (is_array($form_structure) && !empty($form_structure) && (!isset($form_structure[0]['type']) || $form_structure[0]['type'] !== 'layout-row')) {
        $migrated_structure = [];
        foreach ($form_structure as $element) {
            // Wrap each old element in its own 1-column row
            $timestamp = time() + count($migrated_structure); // Ensure unique timestamps
            $migrated_structure[] = [
                'internalId' => 'row-' . $timestamp,
                'type' => 'layout-row',
                'columns' => [
                    [
                        'internalId' => 'col-' . $timestamp . '-1',
                        'width' => '100%',
                        'elements' => [$element] // Place the old element inside
                    ]
                ]
            ];
        }
        $form_structure = $migrated_structure;
    } elseif (!is_array($form_structure)) {
        $form_structure = []; // Default to empty array if invalid JSON or not an array
    }
    // --- End Migration ---


    $new_ai_selection = [];
    if (class_exists(\WPAICG\AIPKit_Providers::class)) {
        $new_ai_selection = \WPAICG\AIPKit_Providers::get_new_text_generation_selection();
    }

    $global_ai_params = [];
    if (class_exists(\WPAICG\AIPKIT_AI_Settings::class)) {
        $global_ai_params = \WPAICG\AIPKIT_AI_Settings::get_ai_parameters();
    }

    $form_temp = get_post_meta($form_id, '_aipkit_ai_form_temperature', true);
    $form_max_tokens = get_post_meta($form_id, '_aipkit_ai_form_max_tokens', true);
    $form_top_p = get_post_meta($form_id, '_aipkit_ai_form_top_p', true);
    $form_frequency_penalty = get_post_meta($form_id, '_aipkit_ai_form_frequency_penalty', true);
    $form_presence_penalty = get_post_meta($form_id, '_aipkit_ai_form_presence_penalty', true);

    $data = [
        'id' => $form_id,
        'title' => $post->post_title,
        'status' => $post->post_status,
        'template_key' => sanitize_key((string) get_post_meta($form_id, '_aipkit_ai_form_template_key', true)),
        'prompt_template' => get_post_meta($form_id, '_aipkit_ai_form_prompt_template', true) ?: '',
        'structure' => $form_structure,
        'ai_provider' => get_post_meta($form_id, '_aipkit_ai_form_ai_provider', true) ?: ($new_ai_selection['provider'] ?? 'OpenAI'),
        'ai_model' => get_post_meta($form_id, '_aipkit_ai_form_ai_model', true) ?: ($new_ai_selection['model'] ?? ''),
        'temperature' => (is_numeric($form_temp) && $form_temp !== '') ? floatval($form_temp) : ($global_ai_params['temperature'] ?? 1.0),
        'max_tokens' => (is_numeric($form_max_tokens) && $form_max_tokens !== '') ? absint($form_max_tokens) : ($global_ai_params['max_completion_tokens'] ?? 4000),
        'top_p' => (is_numeric($form_top_p) && $form_top_p !== '') ? floatval($form_top_p) : ($global_ai_params['top_p'] ?? 1.0),
        'frequency_penalty' => (is_numeric($form_frequency_penalty) && $form_frequency_penalty !== '') ? floatval($form_frequency_penalty) : ($global_ai_params['frequency_penalty'] ?? 0.0),
        'presence_penalty' => (is_numeric($form_presence_penalty) && $form_presence_penalty !== '') ? floatval($form_presence_penalty) : ($global_ai_params['presence_penalty'] ?? 0.0),
        'reasoning_effort' => '',
        'conversation_ui_preset' => 'full',
    ];

    if (class_exists(AIPKit_Providers::class)) {
        $data['ai_provider'] = AIPKit_Providers::normalize_main_provider(
            (string) ($data['ai_provider'] ?? ''),
            (string) ($new_ai_selection['provider'] ?? 'OpenAI')
        );
    }

    $stored_reasoning_effort = get_post_meta($form_id, '_aipkit_ai_form_reasoning_effort', true) ?: 'none';
    $normalized_reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($stored_reasoning_effort);
    $data['reasoning_effort'] = $normalized_reasoning_effort !== '' ? $normalized_reasoning_effort : 'none';
    if (class_exists(StorageSettings::class)) {
        $data['conversation_ui_preset'] = StorageSettings::get_conversation_ui_preset($form_id);
    }

    // --- Add Vector Settings ---
    $data['enable_vector_store'] = get_post_meta($form_id, '_aipkit_ai_form_enable_vector_store', true) ?: '0';
    $data['vector_store_provider'] = get_post_meta($form_id, '_aipkit_ai_form_vector_store_provider', true) ?: 'openai';

    $openai_vs_ids_json = get_post_meta($form_id, '_aipkit_ai_form_openai_vector_store_ids', true) ?: '[]';
    $openai_vs_ids_array = json_decode($openai_vs_ids_json, true);
    $data['openai_vector_store_ids'] = is_array($openai_vs_ids_array) ? $openai_vs_ids_array : [];
    $google_store_names_json = get_post_meta($form_id, '_aipkit_ai_form_google_file_search_store_names', true) ?: '[]';
    $google_store_names = json_decode($google_store_names_json, true);
    $data['google_file_search_store_names'] = is_array($google_store_names) ? $google_store_names : [];

    $data['pinecone_index_name'] = get_post_meta($form_id, '_aipkit_ai_form_pinecone_index_name', true) ?: '';
    $data['qdrant_collection_name'] = get_post_meta($form_id, '_aipkit_ai_form_qdrant_collection_name', true) ?: '';
    $data['chroma_collection_name'] = get_post_meta($form_id, '_aipkit_ai_form_chroma_collection_name', true) ?: '';
    $local_store_ids = json_decode((string) (get_post_meta($form_id, '_aipkit_ai_form_local_store_ids', true) ?: '[]'), true);
    $data['local_store_ids'] = is_array($local_store_ids) ? array_values(array_filter(array_map('strval', $local_store_ids))) : [];

    $allowed_embedding_provider_keys = AIPKit_Providers::get_embedding_provider_keys('ai_forms_get_form_data');

    $vector_embedding_provider = metadata_exists('post', $form_id, '_aipkit_ai_form_vector_embedding_provider')
        ? (string) get_post_meta($form_id, '_aipkit_ai_form_vector_embedding_provider', true) : 'openai';
    if (!in_array($vector_embedding_provider, ['', 'aipuffercloud'], true) && !in_array($vector_embedding_provider, $allowed_embedding_provider_keys, true)) {
        $vector_embedding_provider = 'openai';
    }
    $data['vector_embedding_provider'] = $vector_embedding_provider;
    $data['vector_embedding_model'] = get_post_meta($form_id, '_aipkit_ai_form_vector_embedding_model', true) ?: '';
    $data['vector_store_top_k'] = get_post_meta($form_id, '_aipkit_ai_form_vector_store_top_k', true) ?: 3;
    $data['vector_store_confidence_threshold'] = get_post_meta($form_id, '_aipkit_ai_form_vector_store_confidence_threshold', true) ?: 20;
    // --- END ---

    $data['openai_web_search_enabled'] = get_post_meta($form_id, '_aipkit_ai_form_openai_web_search_enabled', true) ?: '0';
    $claude_web_search_enabled = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_enabled', true);
    $data['claude_web_search_enabled'] = in_array($claude_web_search_enabled, ['0', '1'], true) ? $claude_web_search_enabled : '0';
    $openrouter_web_search_enabled = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_enabled', true);
    $data['openrouter_web_search_enabled'] = in_array($openrouter_web_search_enabled, ['0', '1'], true) ? $openrouter_web_search_enabled : '0';
    $xai_web_search_enabled = get_post_meta($form_id, '_aipkit_ai_form_xai_web_search_enabled', true);
    $data['xai_web_search_enabled'] = in_array($xai_web_search_enabled, ['0', '1'], true) ? $xai_web_search_enabled : '0';
    $data['google_search_grounding_enabled'] = get_post_meta($form_id, '_aipkit_ai_form_google_search_grounding_enabled', true) ?: '0';

    // OpenAI Web Search sub-settings
    $data['openai_web_search_context_size'] = get_post_meta($form_id, '_aipkit_ai_form_openai_web_search_context_size', true) ?: 'medium';
    $data['openai_web_search_loc_type'] = get_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_type', true) ?: 'none';
    $data['openai_web_search_loc_country'] = get_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_country', true) ?: '';
    $data['openai_web_search_loc_city'] = get_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_city', true) ?: '';
    $data['openai_web_search_loc_region'] = get_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_region', true) ?: '';
    $data['openai_web_search_loc_timezone'] = get_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_timezone', true) ?: '';

    // Claude Web Search sub-settings
    $claude_max_uses_raw = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_max_uses', true);
    $claude_max_uses = is_numeric($claude_max_uses_raw) ? absint($claude_max_uses_raw) : 5;
    $data['claude_web_search_max_uses'] = max(1, min($claude_max_uses, 20));
    $claude_loc_type = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_type', true) ?: 'none';
    $data['claude_web_search_loc_type'] = in_array($claude_loc_type, ['none', 'approximate'], true) ? $claude_loc_type : 'none';
    $data['claude_web_search_loc_country'] = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_country', true) ?: '';
    $data['claude_web_search_loc_city'] = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_city', true) ?: '';
    $data['claude_web_search_loc_region'] = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_region', true) ?: '';
    $data['claude_web_search_loc_timezone'] = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_timezone', true) ?: '';
    $data['claude_web_search_allowed_domains'] = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_allowed_domains', true) ?: '';
    $data['claude_web_search_blocked_domains'] = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_blocked_domains', true) ?: '';
    if (!empty($data['claude_web_search_allowed_domains']) && !empty($data['claude_web_search_blocked_domains'])) {
        $data['claude_web_search_blocked_domains'] = '';
    }
    $claude_cache_ttl = get_post_meta($form_id, '_aipkit_ai_form_claude_web_search_cache_ttl', true) ?: 'none';
    $data['claude_web_search_cache_ttl'] = in_array($claude_cache_ttl, ['none', '5m', '1h'], true) ? $claude_cache_ttl : 'none';

    // OpenRouter Web Search sub-settings
    $openrouter_engine = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_engine', true) ?: 'auto';
    $data['openrouter_web_search_engine'] = in_array($openrouter_engine, ['auto', 'native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true) ? $openrouter_engine : 'auto';
    $openrouter_max_results_raw = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_max_results', true);
    $openrouter_max_results = is_numeric($openrouter_max_results_raw) ? absint($openrouter_max_results_raw) : 5;
    $data['openrouter_web_search_max_results'] = max(1, min($openrouter_max_results, 25));
    $openrouter_max_uses_raw = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_max_uses', true);
    $openrouter_max_uses = is_numeric($openrouter_max_uses_raw) ? absint($openrouter_max_uses_raw) : 1;
    $data['openrouter_web_search_max_uses'] = max(1, min($openrouter_max_uses, 10));
    $openrouter_max_total_results_raw = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_max_total_results', true);
    $openrouter_max_total_results = is_numeric($openrouter_max_total_results_raw) ? absint($openrouter_max_total_results_raw) : 10;
    $data['openrouter_web_search_max_total_results'] = max(1, min($openrouter_max_total_results, 100));
    $openrouter_context_size = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_context_size', true) ?: 'auto';
    $data['openrouter_web_search_context_size'] = in_array($openrouter_context_size, ['auto', 'low', 'medium', 'high'], true) ? $openrouter_context_size : 'auto';
    $data['openrouter_web_search_allowed_domains'] = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_allowed_domains', true) ?: '';
    $data['openrouter_web_search_excluded_domains'] = get_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_excluded_domains', true) ?: '';
    if ($data['openrouter_web_search_allowed_domains'] !== '') {
        $data['openrouter_web_search_excluded_domains'] = '';
    }

    // --- Add Labels ---
    $labels_json = get_post_meta($form_id, '_aipkit_ai_form_labels', true);
    $saved_labels = json_decode($labels_json, true);
    if (!is_array($saved_labels)) {
        $saved_labels = [];
    }
    $default_labels = get_label_defaults();

    // Merge defaults: Use saved value if not empty, otherwise use default. This handles old forms with empty strings saved.
    $final_labels = [];
    foreach ($default_labels as $key => $default_value) {
        $saved_value = isset($saved_labels[$key]) ? trim($saved_labels[$key]) : '';
        $final_labels[$key] = !empty($saved_value) ? $saved_value : $default_value;
    }

    $data['labels'] = $final_labels;

    $filtered_data = apply_filters('aipkit_ai_forms_get_form_data', $data, $form_id, $storageInstance);
    return is_array($filtered_data) ? $filtered_data : $data;
}

/**
 * Determine whether a saved nested form structure contains any field elements.
 *
 * @param mixed $structure
 * @return bool
 */
function aipkit_structure_has_elements($structure): bool
{
    if (!is_array($structure) || empty($structure)) {
        return false;
    }

    foreach ($structure as $row) {
        if (!is_array($row) || empty($row['columns']) || !is_array($row['columns'])) {
            continue;
        }
        foreach ($row['columns'] as $column) {
            if (!is_array($column) || empty($column['elements']) || !is_array($column['elements'])) {
                continue;
            }
            return true;
        }
    }

    return false;
}

/**
 * Logic for saving AI Form settings.
 *
 * @param \WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance The instance of the storage class.
 * @param int $form_id The ID of the form CPT.
 * @param array $settings An array containing settings.
 * @return bool True on success, false on failure.
 */
function save_form_settings_logic(\WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance, int $form_id, array $settings): bool
{
    if (array_key_exists('template_key', $settings)) {
        $allowed_template_keys = ['lead_capture', 'customer_feedback', 'book_appointment', 'support_request', 'waitlist_signup'];
        $template_key = sanitize_key((string) $settings['template_key']);
        if (in_array($template_key, $allowed_template_keys, true)) {
            update_post_meta($form_id, '_aipkit_ai_form_template_key', $template_key);
        } else {
            delete_post_meta($form_id, '_aipkit_ai_form_template_key');
        }
    }
    if (isset($settings['prompt_template'])) {
        update_post_meta($form_id, '_aipkit_ai_form_prompt_template', AIPKit_Prompt_Sanitizer::sanitize($settings['prompt_template']));
    }
    if (isset($settings['form_structure'])) {
        $structure_json = $settings['form_structure'];
        $decoded_structure = json_decode($structure_json, true);
        if (is_array($decoded_structure)) {
            $allow_empty_structure = !empty($settings['allow_empty_structure']);
            if (!$allow_empty_structure && !aipkit_structure_has_elements($decoded_structure)) {
                $existing_structure_json = get_post_meta($form_id, '_aipkit_ai_form_structure', true);
                $existing_structure = json_decode((string) $existing_structure_json, true);
                if (is_array($existing_structure) && aipkit_structure_has_elements($existing_structure)) {
                    return false;
                }
            }
            update_post_meta($form_id, '_aipkit_ai_form_structure', wp_kses_post($structure_json));
        }
    }
    if (isset($settings['ai_provider'])) {
        $ai_provider = sanitize_text_field($settings['ai_provider']);
        if (class_exists(AIPKit_Providers::class)) {
            $ai_provider = AIPKit_Providers::normalize_main_provider(
                $ai_provider,
                AIPKit_Providers::get_current_provider()
            );
        }
        update_post_meta($form_id, '_aipkit_ai_form_ai_provider', $ai_provider);
    }
    if (isset($settings['ai_model'])) {
        update_post_meta($form_id, '_aipkit_ai_form_ai_model', sanitize_text_field($settings['ai_model']));
    }
    if (isset($settings['temperature'])) {
        update_post_meta($form_id, '_aipkit_ai_form_temperature', sanitize_text_field($settings['temperature']));
    }
    if (isset($settings['max_tokens'])) {
        update_post_meta($form_id, '_aipkit_ai_form_max_tokens', absint($settings['max_tokens']));
    }
    if (isset($settings['top_p'])) {
        update_post_meta($form_id, '_aipkit_ai_form_top_p', sanitize_text_field($settings['top_p']));
    }
    if (isset($settings['frequency_penalty'])) {
        update_post_meta($form_id, '_aipkit_ai_form_frequency_penalty', sanitize_text_field($settings['frequency_penalty']));
    }
    if (isset($settings['presence_penalty'])) {
        update_post_meta($form_id, '_aipkit_ai_form_presence_penalty', sanitize_text_field($settings['presence_penalty']));
    }
    if (isset($settings['reasoning_effort'])) {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($settings['reasoning_effort']);
        update_post_meta(
            $form_id,
            '_aipkit_ai_form_reasoning_effort',
            $reasoning_effort !== '' ? $reasoning_effort : 'none'
        );
    }
    if (class_exists(StorageSettings::class)) {
        StorageSettings::save_conversation_ui_preset($form_id, $settings);
    }

    // --- Save Vector Settings ---
    if (isset($settings['enable_vector_store'])) {
        update_post_meta($form_id, '_aipkit_ai_form_enable_vector_store', $settings['enable_vector_store'] === '1' ? '1' : '0');
    }
    if (isset($settings['vector_store_provider'])) {
        update_post_meta($form_id, '_aipkit_ai_form_vector_store_provider', sanitize_key($settings['vector_store_provider']));
    }
    if (isset($settings['openai_vector_store_ids'])) {
        $sanitized_ids = is_array($settings['openai_vector_store_ids']) ? array_map('sanitize_text_field', $settings['openai_vector_store_ids']) : [];
        update_post_meta($form_id, '_aipkit_ai_form_openai_vector_store_ids', wp_json_encode(array_values(array_unique($sanitized_ids))));
    }
    if (isset($settings['google_file_search_store_names'])) {
        $store_names = is_array($settings['google_file_search_store_names'])
            ? array_filter(array_map(
                static function ($store_name): string {
                    $store_name = sanitize_text_field((string) $store_name);
                    return strpos($store_name, 'fileSearchStores/') === 0 ? $store_name : '';
                },
                $settings['google_file_search_store_names']
            ))
            : [];
        update_post_meta($form_id, '_aipkit_ai_form_google_file_search_store_names', wp_json_encode(array_values(array_unique($store_names))));
    }
    if (isset($settings['pinecone_index_name'])) {
        update_post_meta($form_id, '_aipkit_ai_form_pinecone_index_name', sanitize_text_field($settings['pinecone_index_name']));
    }
    if (isset($settings['qdrant_collection_name'])) {
        update_post_meta($form_id, '_aipkit_ai_form_qdrant_collection_name', sanitize_text_field($settings['qdrant_collection_name']));
    }
    if (isset($settings['chroma_collection_name'])) {
        update_post_meta($form_id, '_aipkit_ai_form_chroma_collection_name', sanitize_text_field($settings['chroma_collection_name']));
    }
    if (isset($settings['local_store_ids']) && is_array($settings['local_store_ids'])) {
        update_post_meta($form_id, '_aipkit_ai_form_local_store_ids', wp_json_encode(array_values(array_filter(array_map('sanitize_text_field', $settings['local_store_ids'])))));
    }
    if (isset($settings['vector_embedding_provider'])) {
        update_post_meta($form_id, '_aipkit_ai_form_vector_embedding_provider', sanitize_key($settings['vector_embedding_provider']));
    }
    if (isset($settings['vector_embedding_model'])) {
        update_post_meta($form_id, '_aipkit_ai_form_vector_embedding_model', sanitize_text_field($settings['vector_embedding_model']));
    }
    if (isset($settings['vector_store_top_k'])) {
        update_post_meta($form_id, '_aipkit_ai_form_vector_store_top_k', absint($settings['vector_store_top_k']));
    }
    if (isset($settings['vector_store_confidence_threshold'])) {
        update_post_meta($form_id, '_aipkit_ai_form_vector_store_confidence_threshold', absint($settings['vector_store_confidence_threshold']));
    }
    // --- END Vector Settings ---

    if (isset($settings['openai_web_search_enabled'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openai_web_search_enabled', $settings['openai_web_search_enabled'] === '1' ? '1' : '0');
    }
    if (isset($settings['claude_web_search_enabled'])) {
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_enabled', $settings['claude_web_search_enabled'] === '1' ? '1' : '0');
    }
    if (isset($settings['openrouter_web_search_enabled'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_enabled', $settings['openrouter_web_search_enabled'] === '1' ? '1' : '0');
    }
    if (isset($settings['xai_web_search_enabled'])) {
        update_post_meta($form_id, '_aipkit_ai_form_xai_web_search_enabled', $settings['xai_web_search_enabled'] === '1' ? '1' : '0');
    }
    if (isset($settings['google_search_grounding_enabled'])) {
        update_post_meta($form_id, '_aipkit_ai_form_google_search_grounding_enabled', $settings['google_search_grounding_enabled'] === '1' ? '1' : '0');
    }

    // --- Save OpenAI Web Search Sub-Settings ---
    if (isset($settings['openai_web_search_context_size'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openai_web_search_context_size', sanitize_text_field($settings['openai_web_search_context_size']));
    }
    if (isset($settings['openai_web_search_loc_type'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_type', sanitize_text_field($settings['openai_web_search_loc_type']));
    }
    if (isset($settings['openai_web_search_loc_country'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_country', sanitize_text_field($settings['openai_web_search_loc_country']));
    }
    if (isset($settings['openai_web_search_loc_city'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_city', sanitize_text_field($settings['openai_web_search_loc_city']));
    }
    if (isset($settings['openai_web_search_loc_region'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_region', sanitize_text_field($settings['openai_web_search_loc_region']));
    }
    if (isset($settings['openai_web_search_loc_timezone'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openai_web_search_loc_timezone', sanitize_text_field($settings['openai_web_search_loc_timezone']));
    }

    // --- Save Claude Web Search Sub-Settings ---
    if (isset($settings['claude_web_search_max_uses'])) {
        $max_uses = absint($settings['claude_web_search_max_uses']);
        $max_uses = max(1, min($max_uses, 20));
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_max_uses', $max_uses);
    }
    if (isset($settings['claude_web_search_loc_type'])) {
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_type', sanitize_text_field($settings['claude_web_search_loc_type']));
    }
    if (isset($settings['claude_web_search_loc_country'])) {
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_country', sanitize_text_field($settings['claude_web_search_loc_country']));
    }
    if (isset($settings['claude_web_search_loc_city'])) {
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_city', sanitize_text_field($settings['claude_web_search_loc_city']));
    }
    if (isset($settings['claude_web_search_loc_region'])) {
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_region', sanitize_text_field($settings['claude_web_search_loc_region']));
    }
    if (isset($settings['claude_web_search_loc_timezone'])) {
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_loc_timezone', sanitize_text_field($settings['claude_web_search_loc_timezone']));
    }
    $claude_allowed_domains = isset($settings['claude_web_search_allowed_domains']) ? sanitize_textarea_field($settings['claude_web_search_allowed_domains']) : null;
    $claude_blocked_domains = isset($settings['claude_web_search_blocked_domains']) ? sanitize_textarea_field($settings['claude_web_search_blocked_domains']) : null;
    if ($claude_allowed_domains !== null || $claude_blocked_domains !== null) {
        if (!empty($claude_allowed_domains)) {
            $claude_blocked_domains = '';
        }
        if ($claude_allowed_domains !== null) {
            update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_allowed_domains', $claude_allowed_domains);
        }
        if ($claude_blocked_domains !== null) {
            update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_blocked_domains', $claude_blocked_domains);
        }
    }
    if (isset($settings['claude_web_search_cache_ttl'])) {
        update_post_meta($form_id, '_aipkit_ai_form_claude_web_search_cache_ttl', sanitize_text_field($settings['claude_web_search_cache_ttl']));
    }

    // --- Save OpenRouter Web Search Sub-Settings ---
    if (isset($settings['openrouter_web_search_engine'])) {
        $engine = sanitize_key((string) $settings['openrouter_web_search_engine']);
        if (!in_array($engine, ['auto', 'native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)) {
            $engine = 'auto';
        }
        update_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_engine', $engine);
    }
    if (isset($settings['openrouter_web_search_max_results'])) {
        $max_results = absint($settings['openrouter_web_search_max_results']);
        $max_results = max(1, min($max_results, 25));
        update_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_max_results', $max_results);
    }
    if (isset($settings['openrouter_web_search_max_uses'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_max_uses', max(1, min(absint($settings['openrouter_web_search_max_uses']), 10)));
    }
    if (isset($settings['openrouter_web_search_max_total_results'])) {
        update_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_max_total_results', max(1, min(absint($settings['openrouter_web_search_max_total_results']), 100)));
    }
    if (isset($settings['openrouter_web_search_context_size'])) {
        $context_size = sanitize_key((string) $settings['openrouter_web_search_context_size']);
        update_post_meta(
            $form_id,
            '_aipkit_ai_form_openrouter_web_search_context_size',
            in_array($context_size, ['auto', 'low', 'medium', 'high'], true) ? $context_size : 'auto'
        );
    }
    $normalize_openrouter_domains = static function ($value): string {
        if (!is_string($value)) {
            return '';
        }
        $parts = preg_split('/[\r\n,]+/', $value);
        if (!is_array($parts)) {
            return '';
        }
        $domains = [];
        foreach ($parts as $part) {
            $domain = strtolower(trim((string) $part));
            $domain = preg_replace('/^https?:\/\//', '', $domain);
            $domain = trim((string) $domain, " \t\n\r\0\x0B/");
            if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
                $domains[] = $domain;
            }
        }
        return implode("\n", array_values(array_unique($domains)));
    };
    $openrouter_allowed_domains = isset($settings['openrouter_web_search_allowed_domains'])
        ? $normalize_openrouter_domains($settings['openrouter_web_search_allowed_domains'])
        : null;
    $openrouter_excluded_domains = isset($settings['openrouter_web_search_excluded_domains'])
        ? $normalize_openrouter_domains($settings['openrouter_web_search_excluded_domains'])
        : null;
    if ($openrouter_allowed_domains !== null || $openrouter_excluded_domains !== null) {
        if (!empty($openrouter_allowed_domains)) {
            $openrouter_excluded_domains = '';
        }
        if ($openrouter_allowed_domains !== null) {
            update_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_allowed_domains', $openrouter_allowed_domains);
        }
        if ($openrouter_excluded_domains !== null) {
            update_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_excluded_domains', $openrouter_excluded_domains);
        }
    }
    delete_post_meta($form_id, '_aipkit_ai_form_openrouter_web_search_search_prompt');

    // --- Save Labels ---
    if (isset($settings['labels']) && is_array($settings['labels'])) {
        $default_labels = get_label_defaults();
        $sanitized_labels = [];
        if (!class_exists(StorageSettings::class) || !StorageSettings::is_available()) {
            // Preserve settings owned by an unavailable edition when editing shared labels.
            $saved_labels = json_decode(get_post_meta($form_id, '_aipkit_ai_form_labels', true), true);
            if (is_array($saved_labels)) {
                $sanitized_labels = array_diff_key($saved_labels, $default_labels);
            }
        }
        foreach ($settings['labels'] as $key => $value) {
            if (array_key_exists($key, $default_labels)) {
                $sanitized_labels[$key] = sanitize_text_field($value);
            }
        }
        // FIX: Use JSON_UNESCAPED_UNICODE to prevent encoding issues with special characters.
        $json_to_save = wp_json_encode($sanitized_labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        update_post_meta($form_id, '_aipkit_ai_form_labels', $json_to_save);
    }

    do_action('aipkit_ai_forms_after_save_form_settings', $form_id, $settings, $storageInstance);

    return true;
}

/**
 * Logic for creating a new AI Form CPT.
 *
 * @param \WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance The instance of the storage class.
 * @param string $title The title of the form.
 * @param array $settings Optional settings to save.
 * @return int|WP_Error The new post ID on success, or WP_Error on failure.
 */
function create_form_logic(\WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance, string $title, array $settings = [])
{
    if (empty($title)) {
        return new WP_Error('title_required', __('Form title cannot be empty.', 'gpt3-ai-content-generator'));
    }
    if (!class_exists(AIPKit_AI_Form_Admin_Setup::class)) {
        return new WP_Error('dependency_missing', 'AI Form Admin Setup class not found for CPT creation.');
    }

    $selection = AIPKit_Providers::get_new_text_generation_selection();
    $provider = (string) ($settings['ai_provider'] ?? $selection['provider']);
    // An explicit provider without a model must not inherit another provider's model.
    $model = $provider === $selection['provider'] ? $selection['model'] : '';
    $defaults = AIPKit_Providers::get_new_feature_defaults('content', $provider);

    $post_data = array(
        'post_title'  => sanitize_text_field($title),
        'post_type'   => AIPKit_AI_Form_Admin_Setup::POST_TYPE,
        'post_status' => 'publish',
        'post_author' => get_current_user_id() ?: 1,
    );

    $form_id = wp_insert_post($post_data, true);

    if (is_wp_error($form_id)) {
        return $form_id;
    }

    $default_settings = [
        'prompt_template' => 'Your AI prompt for {user_input}',
        'form_structure' => '[]',
        'ai_provider' => $provider,
        'ai_model' => $model,
        'system_instruction' => '',
    ] + array_intersect_key($defaults, array_flip(['vector_store_provider', 'vector_embedding_provider', 'vector_embedding_model']));
    $final_settings = array_merge($default_settings, $settings);

    // Call the logic function via the passed storage instance
    $storageInstance->save_form_settings($form_id, $final_settings);

    return $form_id;
}

/**
 * Logic for deleting an AI Form CPT.
 *
 * @param \WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance The instance of the storage class.
 * @param int $form_id The ID of the form to delete.
 * @return bool True on success, false on failure.
 */
function delete_form_logic(\WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance, int $form_id): bool
{
    $deleted = wp_delete_post($form_id, true);
    return (bool) $deleted;
}

/**
 * Logic for retrieving a list of all AI Forms.
 * UPDATED: Now supports pagination, searching, and sorting.
 * UPDATED: Optimized to prevent N+1 queries by fetching all post meta in a single query.
 *
 * @param \WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance The instance of the storage class.
 * @param array $args WP_Query arguments extended with 'search' and 'filter_provider'.
 * @return array An array containing 'forms' and 'pagination' data.
 */
function get_forms_list_logic(\WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage $storageInstance, array $args = []): array
{
    if (!class_exists(AIPKit_AI_Form_Admin_Setup::class)) {
        return ['forms' => [], 'pagination' => ['total_forms' => 0, 'total_pages' => 0]];
    }
    $defaults = [
        'post_type'      => AIPKit_AI_Form_Admin_Setup::POST_TYPE,
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => 20,
        'paged'          => 1,
        's'              => '', // for search
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ];

    // Map 'search' arg to 's' for WP_Query
    if (isset($args['search'])) {
        $args['s'] = $args['search'];
        unset($args['search']);
    }

    $query_args = wp_parse_args($args, $defaults);
    $meta_query = [];

    // Handle provider filter
    if (!empty($args['filter_provider']) && $args['filter_provider'] !== 'all') {
        $meta_query[] = [
            'key'     => '_aipkit_ai_form_ai_provider',
            'value'   => $args['filter_provider'],
            'compare' => '=',
        ];
    }

    // Handle sorting by post meta (provider, model)
    if (isset($args['orderby']) && in_array($args['orderby'], ['provider', 'model'])) {
        // WP_Query can handle a single meta_key for sorting directly.
        // If we also have a filter, we need to use a more complex meta_query.
        $sort_key = '_aipkit_ai_form_ai_' . $args['orderby'];
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reason: The meta/tax query is essential for the feature's functionality. Its performance impact is considered acceptable as the query is highly specific, paginated, cached, or runs in a non-critical admin/cron context.
        $query_args['meta_key'] = $sort_key;
        $query_args['orderby'] = 'meta_value';
    }

    // Assign meta query to query args if it's not empty
    if (!empty($meta_query)) {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reason: The meta/tax query is essential for the feature's functionality. Its performance impact is considered acceptable as the query is highly specific, paginated, cached, or runs in a non-critical admin/cron context.
        $query_args['meta_query'] = $meta_query;
    }

    $query = new WP_Query($query_args);
    $forms_data = [];
    $post_ids = [];

    if ($query->have_posts()) {
        // First, collect all post IDs from the query result
        $post_ids = wp_list_pluck($query->posts, 'ID');
    }

    $meta_map = [];
    if (!empty($post_ids)) {
        global $wpdb;

        // --- Caching for post meta query ---
        $cache_key = 'aipkit_ai_forms_list_meta_' . md5(implode(',', $post_ids));
        $cache_group = 'aipkit_ai_forms';
        $all_meta_results = wp_cache_get($cache_key, $cache_group);

        if (false === $all_meta_results) {
            // Construct the placeholders for the IN clause
            $id_placeholders = implode(', ', array_fill(0, count($post_ids), '%d'));
            $meta_keys_to_fetch = [
                '_aipkit_ai_form_ai_provider',
                '_aipkit_ai_form_ai_model',
                '_aipkit_ai_form_submission_count',
                '_aipkit_ai_form_template_key',
            ];
            $meta_key_placeholders = implode(', ', array_fill(0, count($meta_keys_to_fetch), '%s'));

            // Prepare the query to fetch all meta data in one go
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- This is the correct and safe way to handle a dynamic number of items in an IN clause. The placeholders are generated correctly before being passed to prepare().
            $meta_query_sql = $wpdb->prepare("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_placeholders) AND meta_key IN ($meta_key_placeholders)", array_merge($post_ids, $meta_keys_to_fetch));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Efficiently fetching specific meta for a list of posts. Caching is implemented and the query is prepared on the line above.
            $all_meta_results = $wpdb->get_results($meta_query_sql, ARRAY_A);

            // Cache the result for a short period (e.g., 1 minute)
            wp_cache_set($cache_key, $all_meta_results, $cache_group, MINUTE_IN_SECONDS);
        }
        // --- End Caching ---

        // Map the results for easy lookup
        if (is_array($all_meta_results)) {
            foreach ($all_meta_results as $meta_row) {
                $meta_map[$meta_row['post_id']][$meta_row['meta_key']] = $meta_row['meta_value'];
            }
        }
    }

    // Now, build the final data array using the fetched posts and meta map
    if ($query->have_posts()) {
        foreach ($query->posts as $post) {
            $form_id = $post->ID;
            $updated_at = $post->post_modified_gmt;
            if (!$updated_at || '0000-00-00 00:00:00' === $updated_at) {
                $updated_at = $post->post_date_gmt;
            }
            $submission_count_raw = $meta_map[$form_id]['_aipkit_ai_form_submission_count'] ?? 0;
            $submission_count = is_numeric($submission_count_raw) ? (int) $submission_count_raw : 0;
            $forms_data[] = [
                'id' => $form_id,
                'title' => $post->post_title,
                'shortcode' => '[aipkit_ai_form id=' . $form_id . ']',
                'status' => $post->post_status,
                'provider' => $meta_map[$form_id]['_aipkit_ai_form_ai_provider'] ?? null,
                'model' => $meta_map[$form_id]['_aipkit_ai_form_ai_model'] ?? null,
                'template_key' => $meta_map[$form_id]['_aipkit_ai_form_template_key'] ?? '',
                'updated_at' => $updated_at,
                'submissions_count' => $submission_count,
            ];
        }
        wp_reset_postdata(); // Important after custom loops to restore the global post object
    }

    $forms_data = apply_filters('aipkit_ai_forms_list_forms_data', $forms_data, $post_ids, $storageInstance);

    return [
        'forms' => $forms_data,
        'pagination' => [
            'total_forms' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ]
    ];
}
