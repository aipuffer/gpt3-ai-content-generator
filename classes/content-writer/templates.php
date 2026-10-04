<?php

namespace WPAICG\ContentWriter;

use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
* Manages CRUD operations for Content Writer templates.
*/
class AIPKit_Content_Writer_Template_Manager
{
    private $wpdb;
    private $table_name;
    private $allowed_config_keys = [
        'template_scope', 'ai_provider', 'ai_model', 'content_title', 'content_title_bulk',
        'content_keywords',
        'ai_temperature', 'content_length', 'content_max_tokens',
        'post_type', 'post_author', 'post_status',
        'post_content_format',
        'post_schedule_date', 'post_schedule_time',
        'schedule_mode', 'smart_schedule_start_datetime',
        'smart_schedule_interval_value', 'smart_schedule_interval_unit',
        'post_categories', 'prompt_mode', 'custom_title_prompt', 'custom_content_prompt',
        'generate_title', 'generate_content',
        'generate_meta_description', 'custom_meta_prompt',
        'generate_focus_keyword', 'custom_keyword_prompt',
        'generate_excerpt', 'custom_excerpt_prompt',
        'generate_tags', 'custom_tags_prompt',
        'custom_title_prompt_update', 'custom_content_prompt_update',
        'custom_meta_prompt_update', 'custom_keyword_prompt_update',
        'custom_excerpt_prompt_update', 'custom_tags_prompt_update',
        'cw_generation_mode', 'rss_feeds',
        'gsheets_sheet_id', 'gsheets_credentials',
        'url_list',
        'generate_toc',
        'generate_seo_slug', // NEW: Add generate_seo_slug
        'seo_score_improvement_enabled', 'seo_score_continue_until_target',
        'seo_score_target', 'seo_score_max_passes', 'seo_score_profile', 'seo_score_disabled_rules',
        'generate_images_enabled', 'image_provider', 'image_model', 'image_provider_options', 'image_prompt',
        'image_prompt_update',
        'generate_image_title', 'generate_image_alt_text', 'generate_image_caption', 'generate_image_description',
        'image_title_prompt', 'image_alt_text_prompt', 'image_caption_prompt', 'image_description_prompt',
        'image_title_prompt_update', 'image_alt_text_prompt_update', 'image_caption_prompt_update', 'image_description_prompt_update',
        'image_count', 'image_placement', 'image_placement_param_x', 'image_alignment', 'image_size',
        'generate_featured_image', 'featured_image_prompt',
        'featured_image_prompt_update',
    'enable_vector_store', 'vector_store_provider', 'openai_vector_store_ids', 'google_file_search_store_names', 'pinecone_index_name', 'qdrant_collection_name', 'chroma_collection_name', 'local_store_id', 'vector_embedding_provider', 'vector_embedding_model', 'vector_store_top_k', 'vector_store_confidence_threshold',
        'rss_include_keywords', 'rss_exclude_keywords', 'rss_item_limit',
        'pexels_orientation', 'pexels_size', 'pexels_color',
        'pixabay_orientation', 'pixabay_image_type', 'pixabay_category',
        'update_title', 'update_excerpt', 'update_content', 'update_meta',
        'title_prompt', 'excerpt_prompt', 'content_prompt', 'meta_prompt',
        'title', 'excerpt', 'content', 'meta', 'keyword', 'tags', // These are the keys for bulk enhancer templates
        'reasoning_effort' // For o1 models reasoning effort setting
    ];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'aipkit_content_writer_templates';
    }

    public static function ensure_default_template_exists()
    {
        TemplateManagerMethods\ensure_default_template_exists_logic(new self());
    }

    /**
     * @return int|\WP_Error
     */
    public function create_template(string $template_name, array $config, string $template_type = 'content_writer')
    {
        return TemplateManagerMethods\create_template_logic($this, $template_name, $config, $template_type);
    }

    /**
     * @return bool|\WP_Error
     */
    public function update_template(int $template_id, string $template_name, array $config)
    {
        return TemplateManagerMethods\update_template_logic($this, $template_id, $template_name, $config);
    }

    /**
     * @return bool|\WP_Error
     */
    public function rename_template(int $template_id, string $template_name)
    {
        return TemplateManagerMethods\rename_template_logic($this, $template_id, $template_name);
    }

    /**
     * @return bool|\WP_Error
     */
    public function delete_template(int $template_id)
    {
        return TemplateManagerMethods\delete_template_logic($this, $template_id);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_template(int $template_id, ?int $user_id_override = null)
    {
        return TemplateManagerMethods\get_template_logic($this, $template_id, $user_id_override);
    }

    public function get_templates_for_user(string $type = 'content_writer'): array
    {
        return TemplateManagerMethods\get_templates_for_user_logic($this, $type);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function reset_starter_templates()
    {
        return TemplateManagerMethods\reset_starter_templates_logic($this);
    }

    // --- Getters for use by namespaced functions ---
    public function get_wpdb()
    {
        return $this->wpdb;
    }
    public function get_table_name(): string
    {
        return $this->table_name;
    }
    public function get_allowed_config_keys(): array
    {
        return $this->allowed_config_keys;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public static function finalize_task_config(array $content_writer_config)
    {
        $schedule_mode = $content_writer_config['schedule_mode'] ?? 'immediate';
        $content_writer_config['schedule_mode'] = in_array($schedule_mode, ['immediate', 'smart', 'from_input'], true)
            ? $schedule_mode
            : 'immediate';
        $content_writer_config['smart_schedule_start_datetime'] = $content_writer_config['smart_schedule_start_datetime'] ?? '';
        $content_writer_config['smart_schedule_interval_value'] = max(1, absint($content_writer_config['smart_schedule_interval_value'] ?? 1));
        $interval_unit = $content_writer_config['smart_schedule_interval_unit'] ?? 'hours';
        $content_writer_config['smart_schedule_interval_unit'] = in_array($interval_unit, ['hours', 'days'], true)
            ? $interval_unit
            : 'hours';
        $content_writer_config['seo_score_improvement_enabled'] = $content_writer_config['seo_score_improvement_enabled'] ?? '0';
        $content_writer_config['seo_score_continue_until_target'] = $content_writer_config['seo_score_continue_until_target'] ?? '1';
        $content_writer_config['seo_score_target'] = $content_writer_config['seo_score_target'] ?? '100';
        $content_writer_config['seo_score_max_passes'] = $content_writer_config['seo_score_max_passes'] ?? '3';
        $content_writer_config['seo_score_profile'] = $content_writer_config['seo_score_profile'] ?? 'auto';
        $content_writer_config['seo_score_disabled_rules'] = $content_writer_config['seo_score_disabled_rules']
            ?? (class_exists(AIPKit_Content_Writer_SEO_Config::class) ? AIPKit_Content_Writer_SEO_Config::default_disabled_rules() : '[]');
        $content_writer_config['image_provider_options'] = class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)
            ? AIPKit_Content_Writer_Image_Provider_Options::sanitize_options_json($content_writer_config['image_provider_options'] ?? '{}', $content_writer_config)
            : ($content_writer_config['image_provider_options'] ?? '{}');

        if (class_exists(AIPKit_Content_Writer_SEO_Config::class)) {
            $seo_permission_check = AIPKit_Content_Writer_SEO_Config::require_pro_for_improvement($content_writer_config);
            if (is_wp_error($seo_permission_check)) {
                return $seo_permission_check;
            }
            $content_writer_config = AIPKit_Content_Writer_SEO_Config::normalize($content_writer_config);
        }

        return $content_writer_config;
    }
}

namespace WPAICG\ContentWriter\TemplateManagerMethods;

use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config;
use WP_Error;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Image_Provider_Options;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;

/**
 * Builds the base configuration used for default and starter templates.
 *
 * @param int $user_id The current user ID.
 * @return array
 */
function get_cw_base_template_config(int $user_id): array
{
    if (!$user_id) {
        return [];
    }
    if (
        !class_exists(AIPKit_Providers::class) ||
        !class_exists(AIPKIT_AI_Settings::class) ||
        !class_exists(AIPKit_Content_Writer_Prompts::class)
    ) {
        return [];
    }

    $feature_defaults = AIPKit_Providers::get_new_feature_defaults();
    $new_ai_selection = AIPKit_Providers::get_new_text_generation_selection();
    $ai_parameters = AIPKIT_AI_Settings::get_ai_parameters();

    $provider_for_template = $new_ai_selection['provider'] ?? 'OpenAI';
    $model_for_template = $new_ai_selection['model'] ?? '';
    return [
        'ai_provider' => $provider_for_template,
        'ai_model' => $model_for_template,
        'content_title' => '',
        'content_keywords' => '',
        'ai_temperature' => (string)($ai_parameters['temperature'] ?? 1.0),
        'content_length' => 'medium',
        'post_type' => 'post',
        'post_author' => $user_id ?: 1,
        'post_status' => 'draft',
        'post_content_format' => 'gutenberg',
        'post_schedule_date' => '',
        'post_schedule_time' => '',
        'schedule_mode' => 'immediate',
        'smart_schedule_start_datetime' => '',
        'smart_schedule_interval_value' => '1',
        'smart_schedule_interval_unit' => 'hours',
        'post_categories' => [],
        'prompt_mode' => 'custom',
        'custom_title_prompt' => AIPKit_Content_Writer_Prompts::get_default_title_prompt(),
        'custom_content_prompt' => AIPKit_Content_Writer_Prompts::get_default_content_prompt(),
        'generate_title' => '1',
        'generate_content' => '1',
        'generate_meta_description' => '1',
        'custom_meta_prompt' => AIPKit_Content_Writer_Prompts::get_default_meta_prompt(),
        'generate_focus_keyword' => '1',
        'custom_keyword_prompt' => AIPKit_Content_Writer_Prompts::get_default_keyword_prompt(),
        'generate_excerpt' => '1',
        'custom_excerpt_prompt' => AIPKit_Content_Writer_Prompts::get_default_excerpt_prompt(),
        'generate_tags' => '1',
        'custom_tags_prompt' => AIPKit_Content_Writer_Prompts::get_default_tags_prompt(),
        'custom_title_prompt_update' => '',
        'custom_content_prompt_update' => '',
        'custom_meta_prompt_update' => '',
        'custom_keyword_prompt_update' => '',
        'custom_excerpt_prompt_update' => '',
        'custom_tags_prompt_update' => '',
        'cw_generation_mode' => 'task',
        'rss_feeds' => '',
        'rss_include_keywords' => '',
        'rss_exclude_keywords' => '',
        'rss_item_limit' => '0',
        'gsheets_sheet_id' => '',
        'gsheets_credentials' => '',
        'url_list' => '',
        'generate_toc' => '0',
        'generate_seo_slug' => '0',
        'seo_score_improvement_enabled' => '0',
        'seo_score_continue_until_target' => '1',
        'seo_score_target' => '100',
        'seo_score_max_passes' => '3',
        'seo_score_profile' => 'auto',
        'seo_score_disabled_rules' => class_exists(AIPKit_Content_Writer_SEO_Config::class) ? AIPKit_Content_Writer_SEO_Config::default_disabled_rules() : '[]',
        'generate_images_enabled' => '0',
        'image_provider' => $feature_defaults['image_provider'],
        'image_model' => $feature_defaults['image_model'],
        'image_provider_options' => '{}',
        'image_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_prompt(),
        'image_prompt_update' => '',
        'image_count' => '1',
        'image_placement' => 'after_first_h2',
        'image_placement_param_x' => '2',
        'image_alignment' => 'none',
        'image_size' => 'large',
        'generate_image_title' => '1',
        'generate_image_alt_text' => '1',
        'generate_image_caption' => '1',
        'generate_image_description' => '1',
        'image_title_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_title_prompt(),
        'image_alt_text_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt(),
        'image_caption_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt(),
        'image_description_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_description_prompt(),
        'image_title_prompt_update' => AIPKit_Content_Writer_Prompts::get_default_image_title_prompt_update(),
        'image_alt_text_prompt_update' => AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt_update(),
        'image_caption_prompt_update' => AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt_update(),
        'image_description_prompt_update' => AIPKit_Content_Writer_Prompts::get_default_image_description_prompt_update(),
        'generate_featured_image' => '0',
        'featured_image_prompt' => AIPKit_Content_Writer_Prompts::get_default_featured_image_prompt(),
        'featured_image_prompt_update' => '',
        'pexels_orientation' => 'none',
        'pexels_size' => 'none',
        'pexels_color' => '',
        'pixabay_orientation' => 'all',
        'pixabay_image_type' => 'all',
        'pixabay_category' => '',
        'enable_vector_store' => '0',
        'vector_store_provider' => $feature_defaults['vector_store_provider'],
        'openai_vector_store_ids' => [],
        'google_file_search_store_names' => [],
        'pinecone_index_name' => '',
        'qdrant_collection_name' => '',
        'chroma_collection_name' => '',
        'local_store_id' => '',
        'vector_embedding_provider' => $feature_defaults['vector_embedding_provider'],
        'vector_embedding_model' => $feature_defaults['vector_embedding_model'],
        'vector_store_top_k' => '3',
        'vector_store_confidence_threshold' => '20',
    ];
}

/**
* Logic for ensuring a user-specific default template exists.
*
* @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance The instance of the template manager.
*/
function ensure_default_template_exists_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance)
{
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        return; // Do not create default templates for logged-out users/processes
    }
    ensure_starter_templates_exist_logic($managerInstance);
    set_cw_short_starter_as_default($managerInstance);
}

/**
 * Returns the user meta key for starter template IDs.
 */
function get_cw_starter_templates_meta_key(): string
{
    return '_aipkit_cw_starter_template_ids';
}

/**
 * Returns the user meta key storing starter template seed version.
 */
function get_cw_starter_templates_seeded_meta_key(): string
{
    return '_aipkit_cw_starter_templates_seeded';
}

/**
 * Returns the current starter templates seed version.
 */
function get_cw_starter_templates_seeded_version(): int
{
    return 12;
}

/**
 * Gets the seeded version for a user.
 *
 * @param int $user_id
 * @return int
 */
function get_cw_starter_templates_seeded_version_for_user(int $user_id): int
{
    if (!$user_id) {
        return 0;
    }
    $seeded = get_user_meta($user_id, get_cw_starter_templates_seeded_meta_key(), true);
    if ($seeded === '' || $seeded === null) {
        return 0;
    }
    if (is_numeric($seeded)) {
        return (int)$seeded;
    }
    return $seeded ? 1 : 0;
}

/**
 * Stores the seeded version for a user.
 *
 * @param int $user_id
 * @param int $version
 * @return void
 */
function set_cw_starter_templates_seeded_version_for_user(int $user_id, int $version): void
{
    if (!$user_id) {
        return;
    }
    update_user_meta($user_id, get_cw_starter_templates_seeded_meta_key(), $version);
}

/**
 * Fetches starter template IDs for a user.
 *
 * @param int $user_id
 * @return array
 */
function get_cw_starter_template_ids_for_user(int $user_id): array
{
    if (!$user_id) {
        return [];
    }
    $ids = get_user_meta($user_id, get_cw_starter_templates_meta_key(), true);
    if (!is_array($ids)) {
        return [];
    }
    $ids = array_map('absint', $ids);
    $ids = array_filter($ids, static fn($id) => $id > 0);
    return array_values(array_unique($ids));
}

/**
 * Stores starter template IDs for a user.
 *
 * @param int $user_id
 * @param array $ids
 * @return void
 */
function set_cw_starter_template_ids_for_user(int $user_id, array $ids): void
{
    if (!$user_id) {
        return;
    }
    $ids = array_map('absint', $ids);
    $ids = array_filter($ids, static fn($id) => $id > 0);
    update_user_meta($user_id, get_cw_starter_templates_meta_key(), array_values(array_unique($ids)));
}

/**
 * Returns only the starter template IDs that still exist for the user.
 *
 * This is used to recover from partial local resets where the user meta still
 * references starter template IDs that no longer exist in the custom table.
 *
 * @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance
 * @param int $user_id
 * @return array
 */
function get_existing_cw_starter_template_ids_for_user(
    \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance,
    int $user_id
): array {
    $starter_ids = get_cw_starter_template_ids_for_user($user_id);
    if (!$user_id || empty($starter_ids)) {
        return [];
    }

    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();
    $placeholders = implode(', ', array_fill(0, count($starter_ids), '%d'));
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct read from a plugin-owned custom table with a prepared placeholder list.
    $existing_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE user_id = %d AND template_type = 'content_writer' AND id IN ({$placeholders})",
            ...array_merge([$user_id], $starter_ids)
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $existing_lookup = array_fill_keys(array_map('intval', $existing_ids), true);

    return array_values(array_filter(
        array_map('intval', $starter_ids),
        static fn($id) => isset($existing_lookup[(int)$id])
    ));
}

/**
 * Removes a starter template ID from a user's meta.
 *
 * @param int $user_id
 * @param int $template_id
 * @return void
 */
function remove_cw_starter_template_id_for_user(int $user_id, int $template_id): void
{
    if (!$user_id || !$template_id) {
        return;
    }
    $ids = get_cw_starter_template_ids_for_user($user_id);
    if (empty($ids)) {
        return;
    }
    $ids = array_filter($ids, static fn($id) => (int)$id !== (int)$template_id);
    set_cw_starter_template_ids_for_user($user_id, $ids);
}

/**
 * Ensures the first starter template is the default and removes legacy default templates.
 *
 * @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance
 * @return void
 */
function set_cw_short_starter_as_default(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance): void
{
    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }

    $starter_ids = get_cw_starter_template_ids_for_user($user_id);
    if (empty($starter_ids)) {
        return;
    }

    $short_template_id = (int) $starter_ids[0];
    if ($short_template_id <= 0) {
        return;
    }

    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query to a plugin-owned custom table. Caches will be invalidated.
    $default_template = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, template_name FROM {$table_name} WHERE user_id = %d AND template_type = 'content_writer' AND is_default = 1 LIMIT 1",
            $user_id
        ),
        ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    $default_id = isset($default_template['id']) ? (int) $default_template['id'] : 0;
    if ($default_id !== $short_template_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
        $wpdb->update(
            $table_name,
            ['is_default' => 0],
            ['user_id' => $user_id, 'template_type' => 'content_writer'],
            ['%d'],
            ['%d', '%s']
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
        $wpdb->update(
            $table_name,
            ['is_default' => 1],
            ['id' => $short_template_id, 'user_id' => $user_id],
            ['%d'],
            ['%d', '%d']
        );
    }

    $default_names = array_values(array_unique([
        __('Default Template', 'gpt3-ai-content-generator'),
        'Default Template',
    ]));
    $default_names = array_filter($default_names, static fn($name) => $name !== '');
    if (!empty($default_names)) {
        $placeholders = implode(', ', array_fill(0, count($default_names), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic placeholder list for plugin-owned template cleanup.
        $delete_query = $wpdb->prepare("DELETE FROM {$table_name} WHERE user_id = %d AND template_type = 'content_writer' AND template_name IN ({$placeholders}) AND id != %d", ...array_merge([$user_id], $default_names, [$short_template_id]));
        if ($delete_query) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query string is prepared above for the dynamic placeholder list against a plugin-owned table.
            $wpdb->query($delete_query);
        }
    }
}

/**
 * Returns the first available model ID from a provider model list.
 *
 * @param array $models
 * @return string
 */
function get_cw_first_image_model_id(array $models): string
{
    foreach ($models as $model) {
        if (is_array($model)) {
            $model_id = $model['id'] ?? ($model['name'] ?? '');
            if (!empty($model_id)) {
                return (string) $model_id;
            }
        } elseif (is_string($model)) {
            $model_id = trim($model);
            if ($model_id !== '') {
                return $model_id;
            }
        }
    }
    return '';
}

/**
 * Resolves default image provider + model based on the main dashboard provider.
 *
 * @param string $main_provider
 * @return array{provider: string, model: string}
 */
function get_cw_starter_template_image_defaults(string $main_provider): array
{
    $fallback = [
        'provider' => 'openai',
        'model' => AIPKit_Providers::get_default_openai_image_model(),
    ];

    if (!class_exists(AIPKit_Providers::class)) {
        return $fallback;
    }

    $provider_key = strtolower($main_provider);

    if ($provider_key === 'google') {
        return [
            'provider' => 'google',
            'model' => AIPKit_Providers::normalize_google_image_model(''),
        ];
    }

    if ($provider_key === 'azure') {
        $azure_models = AIPKit_Providers::get_azure_image_models();
        $model_id = get_cw_first_image_model_id($azure_models);
        return [
            'provider' => 'azure',
            'model' => $model_id,
        ];
    }

    if ($provider_key === 'openai') {
        return $fallback;
    }

    return $fallback;
}

/**
 * Returns the starter template definitions with prompt and config overrides.
 *
 * @param array $base_config
 * @return array
 */
function get_cw_starter_template_definitions(array $base_config): array
{
    if (empty($base_config)) {
        return [];
    }

    $default_provider = $base_config['ai_provider'] ?? 'OpenAI';
    $default_model = $base_config['ai_model'] ?? '';
    if (class_exists(AIPKit_Providers::class) && strtolower($default_provider) === 'google') {
        $google_default_model = AIPKit_Providers::get_default_model_id('Google');
        if ($google_default_model !== '') {
            $default_model = $google_default_model;
        }
    }
    $image_defaults = get_cw_starter_template_image_defaults($default_provider);
    $default_image_provider = $image_defaults['provider'] ?? 'openai';
    $default_image_model = $image_defaults['model'] ?? AIPKit_Providers::get_default_openai_image_model();
    $default_image_count = $base_config['image_count'] ?? '1';
    $default_image_size = $base_config['image_size'] ?? 'large';
    $default_image_alignment = $base_config['image_alignment'] ?? 'none';
    $default_image_placement = $base_config['image_placement'] ?? 'after_first_h2';
    $default_image_prompt = AIPKit_Content_Writer_Prompts::get_default_image_prompt();
    $default_featured_image_prompt = AIPKit_Content_Writer_Prompts::get_default_featured_image_prompt();
    $default_meta_prompt = AIPKit_Content_Writer_Prompts::get_default_meta_prompt();
    $default_keyword_prompt = AIPKit_Content_Writer_Prompts::get_default_keyword_prompt();
    $default_excerpt_prompt = AIPKit_Content_Writer_Prompts::get_default_excerpt_prompt();
    $default_tags_prompt = AIPKit_Content_Writer_Prompts::get_default_tags_prompt();
    $default_smart_seo_disabled_rules = $base_config['seo_score_disabled_rules'] ?? '[]';
    $default_title_prompt = 'Write a clear, SEO-friendly title that includes the main keyword. Keep it concise and suitable for search results (about 8-12 words). Return only the title text with no extra text or annotations.

Topic: "{topic}"
Keywords: "{keywords}"';

    $shared_config = [
        'ai_provider' => $default_provider,
        'ai_model' => $default_model,
        'content_length' => '',
        'generate_meta_description' => '1',
        'generate_focus_keyword' => '1',
        'generate_excerpt' => '1',
        'generate_tags' => '1',
        'seo_score_disabled_rules' => $default_smart_seo_disabled_rules,
        'generate_images_enabled' => '1',
        'generate_featured_image' => '1',
        'image_provider' => $default_image_provider,
        'image_model' => $default_image_model,
        'image_count' => $default_image_count,
        'image_size' => $default_image_size,
        'image_alignment' => $default_image_alignment,
        'image_placement' => $default_image_placement,
        'image_prompt' => $default_image_prompt,
        'featured_image_prompt' => $default_featured_image_prompt,
        'template_scope' => 'prompts_only',
        'prompt_mode' => 'custom',
        'custom_title_prompt' => $default_title_prompt,
        'custom_content_prompt' => '',
        'custom_meta_prompt' => $default_meta_prompt,
        'custom_keyword_prompt' => $default_keyword_prompt,
        'custom_excerpt_prompt' => $default_excerpt_prompt,
        'custom_tags_prompt' => $default_tags_prompt,
    ];

    $templates = [
        [
            'name' => __('Short (600-800 words)', 'gpt3-ai-content-generator'),
            'length' => 'short',
            'content_prompt' => 'Write a short blog post of about 600-800 words. Use headings, short paragraphs, and include the focus keyword naturally. Format the article in proper Markdown with clear H2/H3 headings, lists when helpful, and no extra commentary.

Return only the Markdown article.

Topic: "{topic}"
Keywords: "{keywords}"',
        ],
        [
            'name' => __('Medium (1200-1600 words)', 'gpt3-ai-content-generator'),
            'length' => 'medium',
            'content_prompt' => 'Write a medium-length blog post of about 1200-1600 words. Use clear headings, examples, and a short conclusion. Format the article in proper Markdown with clear H2/H3 headings, lists when helpful, and no extra commentary.

Return only the Markdown article.

Topic: "{topic}"
Keywords: "{keywords}"',
        ],
        [
            'name' => __('Long (2000-2500 words)', 'gpt3-ai-content-generator'),
            'length' => 'long',
            'content_prompt' => 'Write a long-form blog post of about 2000-2500 words. Use clear headings, examples, and a concise conclusion. Format the article in proper Markdown with clear H2/H3 headings, lists when helpful, and no extra commentary.

Return only the Markdown article.

Topic: "{topic}"
Keywords: "{keywords}"',
        ],
    ];

    $definitions = [];
    foreach ($templates as $template) {
        $definitions[] = [
            'template_name' => $template['name'],
            'introduced_in' => 12,
            'config' => array_replace($shared_config, [
                'content_length' => $template['length'],
                'custom_content_prompt' => $template['content_prompt'],
            ]),
        ];
    }

    return $definitions;
}

/**
 * Ensures starter templates exist for the current user.
 *
 * @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance
 * @return void
 */
function ensure_starter_templates_exist_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance): void
{
    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }

    $seeded_version = get_cw_starter_templates_seeded_version_for_user($user_id);
    $target_version = get_cw_starter_templates_seeded_version();
    if ($seeded_version >= $target_version) {
        set_cw_short_starter_as_default($managerInstance);
        return;
    }

    $existing_ids = get_cw_starter_template_ids_for_user($user_id);
    if (!empty($existing_ids)) {
        foreach ($existing_ids as $template_id) {
            delete_template_logic($managerInstance, (int)$template_id);
        }
    }
    delete_user_meta($user_id, get_cw_starter_templates_meta_key());
    delete_user_meta($user_id, get_cw_starter_templates_seeded_meta_key());

    $base_config = get_cw_base_template_config($user_id);
    if (empty($base_config)) {
        return;
    }

    $definitions = get_cw_starter_template_definitions($base_config);
    if (empty($definitions)) {
        return;
    }

    $created_ids = [];
    $existing_ids = [];
    foreach ($definitions as $definition) {
        $template_name = $definition['template_name'] ?? '';
        $config = $definition['config'] ?? [];
        $introduced_in = isset($definition['introduced_in']) ? (int)$definition['introduced_in'] : 1;
        if ($introduced_in <= $seeded_version) {
            continue;
        }
        if (!$template_name || empty($config)) {
            continue;
        }

        $result = create_template_logic($managerInstance, $template_name, $config, 'content_writer');
        if (!is_wp_error($result)) {
            $created_ids[] = (int)$result;
        }
    }

    $combined_ids = array_merge($existing_ids, $created_ids);
    if (!empty($combined_ids)) {
        set_cw_starter_template_ids_for_user($user_id, $combined_ids);
    }
    set_cw_starter_templates_seeded_version_for_user($user_id, $target_version);
    set_cw_short_starter_as_default($managerInstance);
}

/**
 * Resets starter templates for the current user.
 *
 * @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance
 * @return array|WP_Error
 */
function reset_starter_templates_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance)
{
    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('not_logged_in', __('User must be logged in to reset starter templates.', 'gpt3-ai-content-generator'));
    }

    $base_config = get_cw_base_template_config($user_id);
    if (empty($base_config)) {
        return new WP_Error('starter_reset_failed', __('Starter templates could not be restored.', 'gpt3-ai-content-generator'));
    }

    $definitions = get_cw_starter_template_definitions($base_config);
    if (empty($definitions)) {
        return new WP_Error('starter_reset_failed', __('Starter templates could not be restored.', 'gpt3-ai-content-generator'));
    }

    $expected_count = count($definitions);

    $starter_ids = get_existing_cw_starter_template_ids_for_user($managerInstance, $user_id);
    if (!empty($starter_ids)) {
        $wpdb = $managerInstance->get_wpdb();
        $table_name = $managerInstance->get_table_name();
        foreach ($starter_ids as $template_id) {
            // This is the only internal path allowed to replace protected starter
            // templates. Public rename/delete actions continue to reject them.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table; reset is explicitly requested by its owner.
            $delete_result = $wpdb->delete(
                $table_name,
                [
                    'id' => (int)$template_id,
                    'user_id' => $user_id,
                ],
                ['%d', '%d']
            );
            if ($delete_result === false) {
                return new WP_Error('starter_reset_failed', __('Starter templates could not be restored.', 'gpt3-ai-content-generator'));
            }
        }
    }

    delete_user_meta($user_id, get_cw_starter_templates_meta_key());
    delete_user_meta($user_id, get_cw_starter_templates_seeded_meta_key());

    ensure_starter_templates_exist_logic($managerInstance);

    $reset_starter_ids = get_cw_starter_template_ids_for_user($user_id);
    if (count($reset_starter_ids) !== $expected_count) {
        return new WP_Error('starter_reset_failed', __('Starter templates could not be fully restored.', 'gpt3-ai-content-generator'));
    }

    return $reset_starter_ids;
}

if (!class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)) {
    $aipkit_image_provider_options_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/image-options.php';
    if (file_exists($aipkit_image_provider_options_path)) {
        require_once $aipkit_image_provider_options_path;
    }
}

/**
* Sanitizes the configuration array for a template.
*
* @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance The instance of the template manager.
* @param array $config The raw configuration array.
* @return array The sanitized configuration array.
*/
function sanitize_config_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance, array $config): array
{
    $sanitized = [];
    $allowed_config_keys = $managerInstance->get_allowed_config_keys();
    $prompt_template_keys = [
        'custom_title_prompt', 'custom_content_prompt', 'custom_meta_prompt',
        'custom_keyword_prompt', 'custom_excerpt_prompt', 'custom_tags_prompt',
        'custom_title_prompt_update', 'custom_content_prompt_update',
        'custom_meta_prompt_update', 'custom_keyword_prompt_update',
        'custom_excerpt_prompt_update', 'custom_tags_prompt_update',
        'image_prompt', 'image_prompt_update', 'featured_image_prompt',
        'featured_image_prompt_update', 'image_title_prompt',
        'image_alt_text_prompt', 'image_caption_prompt',
        'image_description_prompt', 'image_title_prompt_update',
        'image_alt_text_prompt_update', 'image_caption_prompt_update',
        'image_description_prompt_update', 'title_prompt', 'excerpt_prompt',
        'content_prompt', 'meta_prompt', 'keyword_prompt',
    ];
    $textarea_keys = [
        'content_title', 'content_title_bulk', 'rss_feeds', 'url_list',
        'rss_include_keywords', 'rss_exclude_keywords',
    ];

    foreach ($allowed_config_keys as $key) {
        if (isset($config[$key])) {
            if (in_array($key, $prompt_template_keys, true)) {
                $sanitized[$key] = AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($config[$key]));
            } elseif (in_array($key, $textarea_keys, true)) {
                $sanitized[$key] = sanitize_textarea_field(wp_unslash($config[$key]));
            } elseif (in_array($key, ['title', 'excerpt', 'content', 'meta', 'keyword', 'tags'], true) && is_array($config[$key])) {
                $sanitized_sub_array = [];
                if (isset($config[$key]['enabled'])) {
                    $sanitized_sub_array['enabled'] = ($config[$key]['enabled'] === '1' || $config[$key]['enabled'] === true) ? '1' : '0';
                }
                if (isset($config[$key]['prompt'])) {
                    $sanitized_sub_array['prompt'] = AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($config[$key]['prompt']));
                }
                $sanitized[$key] = $sanitized_sub_array;
            } elseif ($key === 'template_scope') {
                $sanitized[$key] = $config[$key] === 'prompts_only' ? 'prompts_only' : 'full';
            } elseif ($key === 'gsheets_credentials') {
                if (class_exists('\WPAICG\Lib\Utils\AIPKit_Google_Credentials_Handler')) {
                    // The handler returns an array or null, which will be properly JSON encoded later when the whole config is saved.
                    $sanitized[$key] = \WPAICG\Lib\Utils\AIPKit_Google_Credentials_Handler::process_credentials($config[$key]);
                } else {
                    $sanitized[$key] = null;
                }
            } elseif ($key === 'image_provider_options') {
                $sanitized[$key] = class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)
                    ? AIPKit_Content_Writer_Image_Provider_Options::sanitize_options_json($config[$key], $config)
                    : '{}';
            } elseif ($key === 'ai_temperature') {
                $value = round((float) $config[$key], 1);
                $sanitized[$key] = (string) max(0, min($value, 2));
            } elseif ($key === 'post_content_format') {
                $sanitized[$key] = \WPAICG\ContentWriter\AIPKit_Content_Writer_Block_Converter::normalize_format($config[$key]);
            } elseif ($key === 'content_length') {
                $value = sanitize_key($config[$key]);
                $sanitized[$key] = in_array($value, ['short', 'medium', 'long'], true) ? $value : 'medium';
            } elseif (in_array($key, ['image_count', 'image_placement_param_x', 'vector_store_top_k', 'content_max_tokens'], true)) {
                $sanitized[$key] = absint($config[$key]);
            } elseif ($key === 'smart_schedule_interval_value') {
                $sanitized[$key] = max(1, absint($config[$key]));
            } elseif ($key === 'schedule_mode') {
                $schedule_mode = sanitize_key($config[$key]);
                $sanitized[$key] = in_array($schedule_mode, ['immediate', 'smart', 'from_input'], true)
                    ? $schedule_mode
                    : 'immediate';
            } elseif ($key === 'smart_schedule_interval_unit') {
                $interval_unit = sanitize_key($config[$key]);
                $sanitized[$key] = in_array($interval_unit, ['hours', 'days'], true)
                    ? $interval_unit
                    : 'hours';
            } elseif ($key === 'seo_score_target') {
                $sanitized[$key] = '100';
            } elseif ($key === 'seo_score_max_passes') {
                $sanitized[$key] = '3';
            } elseif ($key === 'seo_score_disabled_rules') {
                $sanitized[$key] = class_exists(AIPKit_Content_Writer_SEO_Config::class)
                    ? AIPKit_Content_Writer_SEO_Config::sanitize_disabled_rules($config[$key])
                    : '[]';
            } elseif ($key === 'vector_store_confidence_threshold') {
                $raw = isset($config[$key]) ? absint($config[$key]) : 20;
                $sanitized[$key] = max(0, min($raw, 100));
            } elseif ($key === 'seo_score_continue_until_target') {
                $sanitized[$key] = '1';
            } elseif (in_array($key, ['generate_title', 'generate_content', 'generate_meta_description', 'generate_focus_keyword', 'generate_excerpt', 'generate_tags', 'generate_toc', 'generate_seo_slug', 'seo_score_improvement_enabled', 'seo_score_continue_until_target', 'generate_images_enabled', 'generate_featured_image', 'generate_image_title', 'generate_image_alt_text', 'generate_image_caption', 'generate_image_description', 'enable_vector_store', 'update_title', 'update_excerpt', 'update_content', 'update_meta'], true)) {
                $sanitized[$key] = ($config[$key] === '1' || $config[$key] === true || $config[$key] === 1) ? '1' : '0';
            } elseif ($key === 'reasoning_effort') {
                $effort = AIPKit_OpenAI_Reasoning::sanitize_effort($config[$key] ?? '');
                $sanitized[$key] = $effort !== '' ? $effort : 'none';
            } elseif ($key === 'google_file_search_store_names') {
                $sanitized[$key] = is_array($config[$key])
                    ? array_values(array_unique(array_filter(array_map(
                        static function ($store_name): string {
                            $store_name = sanitize_text_field((string) $store_name);
                            return strpos($store_name, 'fileSearchStores/') === 0 ? $store_name : '';
                        },
                        $config[$key]
                    ))))
                    : [];
            } elseif ($key === 'openai_vector_store_ids') {
                $sanitized[$key] = is_array($config[$key])
                    ? array_values(array_unique(array_filter(array_map('sanitize_text_field', $config[$key]))))
                    : [];
            } elseif ($key === 'seo_score_profile') {
                $sanitized[$key] = 'auto';
            } elseif (in_array($key, ['post_type', 'post_status', 'ai_provider', 'prompt_mode', 'cw_generation_mode', 'image_provider', 'image_placement', 'image_alignment', 'image_size', 'vector_store_provider', 'vector_embedding_provider', 'pexels_orientation', 'pexels_size', 'pexels_color', 'pixabay_orientation', 'pixabay_image_type', 'pixabay_category'], true)) {
                $sanitized[$key] = sanitize_key($config[$key]);
            } elseif (is_string($config[$key])) {
                $sanitized[$key] = sanitize_text_field(wp_unslash($config[$key]));
            } else {
                $sanitized[$key] = $config[$key];
            }
        }
    }

    if (class_exists(AIPKit_Content_Writer_SEO_Config::class)) {
        $sanitized = AIPKit_Content_Writer_SEO_Config::normalize($sanitized, true, false);
    }

    return $sanitized;
}

/**
* Calculates a MySQL DATETIME string from date and time parts.
*
* @param string $date_str The date string (Y-m-d).
* @param string $time_str The time string (H:i).
* @return string|null The formatted datetime string or null if input is invalid.
*/
function calculate_schedule_datetime_logic(string $date_str, string $time_str): ?string
{
    if (empty($date_str) || empty($time_str)) {
        return null;
    }
    try {
        $datetime = new \DateTime("{$date_str} {$time_str}", wp_timezone());
        return $datetime->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        return null;
    }
}

/**
* Logic for creating a new template.
*
* @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance The instance of the template manager.
* @param string $template_name The name for the new template.
* @param array $config The configuration array for the template.
* @param string $template_type The type of template (e.g., 'content_writer').
* @return int|WP_Error The new post ID or a WP_Error on failure.
*/
function create_template_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance, string $template_name, array $config, string $template_type = 'content_writer')
{
    if (class_exists('\WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector')) {
        $rss_validation = \WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector::validate_config($config);
        if (is_wp_error($rss_validation)) {
            return $rss_validation;
        }
    }
    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();

    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('not_logged_in', __('User must be logged in to create templates.', 'gpt3-ai-content-generator'));
    }
    if (empty($template_name)) {
        return new WP_Error('empty_template_name', __('Template name cannot be empty.', 'gpt3-ai-content-generator'));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE user_id = %d AND template_name = %s AND template_type = %s",
        $user_id,
        $template_name,
        $template_type
    ));
    if ($existing) {
        return new WP_Error('duplicate_template_name', __('A template with this name already exists for your account.', 'gpt3-ai-content-generator'));
    }

    $sanitized_config = sanitize_config_logic($managerInstance, $config);
    $post_schedule_datetime = calculate_schedule_datetime_logic($sanitized_config['post_schedule_date'] ?? '', $sanitized_config['post_schedule_time'] ?? '');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct insert to a custom table. Caches will be invalidated.
    $result = $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'template_name' => sanitize_text_field($template_name),
            'template_type' => sanitize_key($template_type),
            'config' => wp_json_encode($sanitized_config),
            'is_default' => 0,
            'created_at' => current_time('mysql', 1),
            'updated_at' => current_time('mysql', 1),
            'post_type' => $sanitized_config['post_type'] ?? 'post',
            'post_author' => $sanitized_config['post_author'] ?? $user_id,
            'post_status' => $sanitized_config['post_status'] ?? 'draft',
            'post_schedule' => $post_schedule_datetime,
            'post_categories' => wp_json_encode($sanitized_config['post_categories'] ?? []),
            ],
        ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
    );

    if ($result === false) {
        return new WP_Error('db_insert_error', __('Failed to save template.', 'gpt3-ai-content-generator'));
    }
    return $wpdb->insert_id;
}

/**
* Logic for updating an existing template.
*
* @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance The instance of the template manager.
* @param int $template_id The ID of the template to update.
* @param string $template_name The new name for the template.
* @param array $config The new configuration for the template.
* @return bool|WP_Error True on success, or a WP_Error on failure.
*/
function update_template_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance, int $template_id, string $template_name, array $config)
{
    if (class_exists('\WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector')) {
        $rss_validation = \WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector::validate_config($config);
        if (is_wp_error($rss_validation)) {
            return $rss_validation;
        }
    }
    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();

    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        return new WP_Error('not_logged_in', __('User must be logged in to update templates.', 'gpt3-ai-content-generator'));
    }
    if (empty($template_name)) {
        return new WP_Error('empty_template_name', __('Template name cannot be empty.', 'gpt3-ai-content-generator'));
    }

    // Get the original template to check ownership and type
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $original_template = $wpdb->get_row($wpdb->prepare("SELECT user_id, template_type, is_default FROM {$table_name} WHERE id = %d", $template_id), ARRAY_A);

    if (!$original_template) {
        return new WP_Error('template_not_found', __('Template not found.', 'gpt3-ai-content-generator'));
    }

    $is_owner = ((int) $original_template['user_id'] === $current_user_id);

    // Shared templates are intentionally read-only, including for administrators.
    if (!$is_owner) {
        return new WP_Error('permission_denied', __('You can only update your own templates.', 'gpt3-ai-content-generator'));
    }

    // Check for duplicate name for the original owner of the template.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $existing = $wpdb->get_var($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from the manager and WordPress; all request values use placeholders.
        "SELECT id FROM {$table_name} WHERE user_id = %d AND template_name = %s AND template_type = %s AND id != %d",
        $original_template['user_id'],
        $template_name,
        $original_template['template_type'],
        $template_id
    ));
    if ($existing) {
        return new WP_Error('duplicate_template_name_update', __('Another template with this name already exists for the owner of this template.', 'gpt3-ai-content-generator'));
    }

    $sanitized_config = sanitize_config_logic($managerInstance, $config);
    $post_schedule_datetime = calculate_schedule_datetime_logic($sanitized_config['post_schedule_date'] ?? '', $sanitized_config['post_schedule_time'] ?? '');

    $data_to_update = [
        'template_name' => sanitize_text_field($template_name),
        'config' => wp_json_encode($sanitized_config),
        'updated_at' => current_time('mysql', 1),
        'post_type' => $sanitized_config['post_type'] ?? 'post',
        'post_author' => $sanitized_config['post_author'] ?? $original_template['user_id'], // Keep original author if not specified
        'post_status' => $sanitized_config['post_status'] ?? 'draft',
        'post_schedule' => $post_schedule_datetime,
        'post_categories' => wp_json_encode($sanitized_config['post_categories'] ?? []),
    ];
    $data_formats = ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'];

    $where = [
        'id' => $template_id,
        'user_id' => $current_user_id,
    ];
    $where_formats = ['%d', '%d'];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update($table_name, $data_to_update, $where, $data_formats, $where_formats);

    if ($result === false) {
        return new WP_Error('db_update_error', __('Failed to update template.', 'gpt3-ai-content-generator'));
    }
    return true;
}

/**
 * Renames a custom template owned by the current user without changing its
 * saved configuration.
 *
 * @return bool|WP_Error
 */
function rename_template_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance, int $template_id, string $template_name)
{
    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();
    $current_user_id = get_current_user_id();
    $sanitized_name = sanitize_text_field($template_name);

    if (!$current_user_id) {
        return new WP_Error('not_logged_in', __('User must be logged in to rename templates.', 'gpt3-ai-content-generator'));
    }
    if ($template_id < 1 || $sanitized_name === '') {
        return new WP_Error('invalid_template_rename', __('Choose a template and enter a name.', 'gpt3-ai-content-generator'));
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom table and prepared scalar.
    $template = $wpdb->get_row(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from the manager and WordPress; all request values use placeholders.
            "SELECT user_id, template_type, is_default FROM {$table_name} WHERE id = %d",
            $template_id
        ),
        ARRAY_A
    );

    if (!$template || (int) $template['user_id'] !== $current_user_id) {
        return new WP_Error('rename_permission_denied', __('You can only rename your own templates.', 'gpt3-ai-content-generator'));
    }

    $starter_ids = get_cw_starter_template_ids_for_user($current_user_id);
    if ((int) $template['is_default'] === 1 || in_array($template_id, $starter_ids, true)) {
        return new WP_Error('protected_template', __('Starter templates cannot be renamed.', 'gpt3-ai-content-generator'));
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom table and prepared scalar values.
    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from the manager and WordPress; all request values use placeholders.
            "SELECT id FROM {$table_name} WHERE user_id = %d AND template_name = %s AND template_type = %s AND id != %d",
            $current_user_id,
            $sanitized_name,
            $template['template_type'],
            $template_id
        )
    );
    if ($existing_id) {
        return new WP_Error('duplicate_template_name_update', __('A template with this name already exists.', 'gpt3-ai-content-generator'));
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom table.
    $result = $wpdb->update(
        $table_name,
        [
            'template_name' => $sanitized_name,
            'updated_at' => current_time('mysql', 1),
        ],
        [
            'id' => $template_id,
            'user_id' => $current_user_id,
        ],
        ['%s', '%s'],
        ['%d', '%d']
    );

    if ($result === false) {
        return new WP_Error('db_rename_error', __('Could not rename the template.', 'gpt3-ai-content-generator'));
    }

    return true;
}

/**
* Logic for deleting a template.
*
* @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance The instance of the template manager.
* @param int $template_id The ID of the template to delete.
* @return bool|WP_Error True on success, or a WP_Error on failure.
*/
function delete_template_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance, int $template_id)
{
    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();
    $user_id = get_current_user_id();

    if (!$user_id) {
        return new WP_Error('not_logged_in', __('User must be logged in to delete templates.', 'gpt3-ai-content-generator'));
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom table and prepared scalar.
    $template = $wpdb->get_row(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from the manager and WordPress; all request values use placeholders.
            "SELECT user_id, is_default FROM {$table_name} WHERE id = %d",
            $template_id
        ),
        ARRAY_A
    );
    if (!$template || (int) $template['user_id'] !== $user_id) {
        return new WP_Error('delete_permission_denied', __('You can only delete your own templates.', 'gpt3-ai-content-generator'));
    }

    $starter_ids = get_cw_starter_template_ids_for_user($user_id);
    if ((int) $template['is_default'] === 1 || in_array($template_id, $starter_ids, true)) {
        return new WP_Error('protected_template', __('Starter templates cannot be deleted.', 'gpt3-ai-content-generator'));
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table.
    $result = $wpdb->delete(
        $table_name,
        [
            'id' => $template_id,
            'user_id' => $user_id,
        ],
        ['%d', '%d']
    );

    if ($result === false) {
        return new WP_Error('db_delete_error', __('Failed to delete template from the database.', 'gpt3-ai-content-generator'));
    }
    if ($result === 0) {
        // This can happen if a non-admin tries to delete another user's template.
        return new WP_Error('delete_permission_denied', __('Template not found or you do not have permission to delete it.', 'gpt3-ai-content-generator'));
    }

    return true;
}

/** Decodes saved template data consistently for single reads and template lists. */
function normalize_template_row_logic(array $template): array
{
    // Decode the config JSON
    $config = json_decode($template['config'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        $template['config'] = [];
    } else {
        // Handle Google Sheets credentials if they exist
        if (isset($config['gsheets_credentials'])) {
            $creds = $config['gsheets_credentials'];

            // If it's a string, it might be double-encoded JSON from a previous bug. Try to decode it.
            if (is_string($creds)) {
                $decoded_creds = json_decode($creds, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_creds)) {
                    // It was a JSON string, replace it with the decoded array.
                    $config['gsheets_credentials'] = $decoded_creds;
                }
            }
        }
        $template['config'] = $config;
    }

    if (isset($template['post_categories'])) {
        $cat_ids_from_db = json_decode($template['post_categories'], true);
        $template['config']['post_categories'] = is_array($cat_ids_from_db) ? array_map('absint', $cat_ids_from_db) : [];
    } elseif (isset($template['config']['post_categories'])) {
        if (is_string($template['config']['post_categories'])) {
            $cat_input = array_map('trim', explode(',', $template['config']['post_categories']));
            $cat_ids = array_filter(array_map('absint', $cat_input), function ($id) {
                return $id > 0;
            });
            $template['config']['post_categories'] = array_values(array_unique($cat_ids));
        } elseif (!is_array($template['config']['post_categories'])) {
            $template['config']['post_categories'] = [];
        } else {
            $template['config']['post_categories'] = array_values(array_unique(array_map('absint', $template['config']['post_categories'])));
        }
    } else {
        $template['config']['post_categories'] = [];
    }
    unset($template['config']['post_tags']);

    $db_config_keys = ['post_type', 'post_author', 'post_status'];
    foreach ($db_config_keys as $key) {
        if (!isset($template['config'][$key]) && isset($template[$key])) {
            $template['config'][$key] = $template[$key];
        }
    }
    if (isset($template['post_schedule']) && $template['post_schedule'] !== null && $template['post_schedule'] !== '0000-00-00 00:00:00') {
        $schedule_timestamp = strtotime($template['post_schedule']);
        $template['config']['post_schedule_date'] = wp_date('Y-m-d', $schedule_timestamp);
        $template['config']['post_schedule_time'] = wp_date('H:i', $schedule_timestamp);
    } else {
        $template['config']['post_schedule_date'] = '';
        $template['config']['post_schedule_time'] = '';
    }

    // Protected starter templates only apply writing presets, even when their
    // saved configuration predates persistence of the scope field.
    if (($template['template_type'] ?? '') === 'content_writer'
        && in_array((int) $template['id'], get_cw_starter_template_ids_for_user((int) $template['user_id']), true)) {
        // Seeded presets have no personal settings yet. Autosave writes a full
        // form configuration, which must be restored when reopening the writer.
        $template['has_saved_settings'] = ($template['config']['template_scope'] ?? '') !== 'prompts_only';
        $template['config']['template_scope'] = 'prompts_only';
    }

    return $template;
}

/**
* Logic for retrieving a single template's data.
*
* @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance The instance of the template manager.
* @param int $template_id The ID of the template to retrieve.
* @param int|null $user_id_override Optional user ID to override the current user.
* @return array|WP_Error The template data array or a WP_Error on failure.
*/
function get_template_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance, int $template_id, ?int $user_id_override = null)
{
    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();

    $user_id = $user_id_override ?? get_current_user_id();
    if (!$user_id && $user_id_override !== 0) {
        return new WP_Error('not_logged_in_get', __('User must be logged in to get templates.', 'gpt3-ai-content-generator'));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
    $template = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d AND user_id = %d", $template_id, $user_id), ARRAY_A);

    if (!$template) {
        return new WP_Error('template_not_found', __('Template not found or access denied.', 'gpt3-ai-content-generator'));
    }

    return normalize_template_row_logic($template);
}

/**
* Logic for retrieving all templates for the current user.
*
* @param \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance The instance of the template manager.
* @param string $type The type of template to retrieve.
* @return array An array of template objects.
*/
function get_templates_for_user_logic(\WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager $managerInstance, string $type = 'content_writer'): array
{
    $wpdb = $managerInstance->get_wpdb();
    $table_name = $managerInstance->get_table_name();

    $user_id = get_current_user_id();
    if (!$user_id) {
        return [];
    }

    $starter_ids = get_cw_starter_template_ids_for_user($user_id);
    $starter_lookup = !empty($starter_ids) ? array_fill_keys($starter_ids, true) : [];
    $starter_order_lookup = !empty($starter_ids)
        ? array_flip(array_map('intval', $starter_ids))
        : [];

    $all_templates_raw = [];

    // All users (including admins) get their own templates first.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
    $user_templates_raw = $wpdb->get_results($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from the manager and WordPress; all request values use placeholders.
        "SELECT t.*, u.display_name FROM {$table_name} t LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.user_id = %d AND t.template_type = %s ORDER BY t.is_default DESC, t.template_name ASC",
        $user_id,
        $type
    ), ARRAY_A);

    if (!empty($user_templates_raw)) {
        $all_templates_raw = array_merge($all_templates_raw, $user_templates_raw);
    }

    // If user is an admin, get templates from all OTHER users.
    if (\WPAICG\AIPKit_Role_Manager::user_can_manage_others_content()) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
        $other_users_templates_raw = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from the manager and WordPress; all request values use placeholders.
            "SELECT t.*, u.display_name FROM {$table_name} t INNER JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.user_id != %d AND t.template_type = %s AND t.is_default = 0 AND u.display_name != '' ORDER BY u.display_name ASC, t.template_name ASC",
            $user_id,
            $type
        ), ARRAY_A);

        if (!empty($other_users_templates_raw)) {
            $all_templates_raw = array_merge($all_templates_raw, $other_users_templates_raw);
        }
    }

    $templates = [];

    if (is_array($all_templates_raw)) {
        foreach ($all_templates_raw as $template_raw) {
            if (!$template_raw) {
                continue;
            }
            $raw_template = normalize_template_row_logic($template_raw);
            $is_starter = ((int)$raw_template['user_id'] === (int)$user_id && isset($starter_lookup[(int)$raw_template['id']]));
            $raw_template['is_starter'] = $is_starter ? 1 : 0;
            if ($is_starter && isset($starter_order_lookup[(int)$raw_template['id']])) {
                $raw_template['starter_order'] = (int)$starter_order_lookup[(int)$raw_template['id']];
            }
            $templates[] = $raw_template;
        }
    }
    return $templates;
}
