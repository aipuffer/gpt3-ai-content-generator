<?php

namespace WPAICG\AutoGPT\Cron\EventProcessor\Processor\ContentWriting;

use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner;
use WP_Error;
use WPAICG\AutoGPT\Helpers;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_System_Instruction_Builder;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_User_Prompt_Builder;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Core\Stream\Vector as VectorContextBuilder;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;
use WPAICG\Utils\AIPKit_TOC_Generator;
use WPAICG\ContentWriter\AIPKit_Image_Injector;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper;
use WPAICG\AIPKit_Providers;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Summarizer;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Meta_Prompt_Builder;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Keyword_Prompt_Builder;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Excerpt_Prompt_Builder;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_Tags_Prompt_Builder;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Image_Handler;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Image_Provider_Options;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_Smart_SEO_Keyphrase_Usage;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_Smart_SEO_Keyword_Resolver;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_Smart_SEO_Service;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\AIPKit_Event_Webhooks;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/task-access.php';

// --- generate-title-helper.php ---
/**
 * Generates a new title for the content if requested.
 * UPDATED: Simplified to remove "Guided Mode" logic. Title generation is now only controlled by a non-empty `custom_title_prompt`.
 *
 * @param array $cw_config The specific configuration for the content writing item.
 * @param AIPKit_AI_Caller $ai_caller An instance of the AI Caller.
 * @return array|WP_Error On success, returns ['title' => string, 'usage' => array|null]. On failure, returns WP_Error.
 */
function generate_title_logic(array $cw_config, AIPKit_AI_Caller $ai_caller)
{
    $final_title = $cw_config['content_title'] ?? 'AI Generated Content';

    // Title generation is now only triggered if a custom title prompt exists and is not empty.
    $should_generate = !empty($cw_config['custom_title_prompt']);

    if (!$should_generate) {
        return ['title' => $final_title, 'usage' => null]; // Use the topic line as the title, no usage
    }

    $system_instruction_for_title = "You are an expert copywriter specializing in crafting engaging headlines.";

    // Use the custom prompt, falling back to default if for some reason it's empty but generation was triggered.
    $user_prompt_template = $cw_config['custom_title_prompt'] ?: AIPKit_Content_Writer_Prompts::get_default_title_prompt();
    $user_prompt_for_title = str_replace('{topic}', $final_title, $user_prompt_template);
    // Also replace the keywords placeholder
    $final_keywords_for_prompt = !empty($cw_config['inline_keywords']) ? $cw_config['inline_keywords'] : ($cw_config['content_keywords'] ?? '');
    $user_prompt_for_title = str_replace('{keywords}', $final_keywords_for_prompt, $user_prompt_for_title);

    $url_content = $cw_config['url_content_context'] ?? '';
    if (!empty($url_content) && strpos($user_prompt_for_title, '{url_content}') !== false) {
        $user_prompt_for_title = str_replace('{url_content}', trim($url_content), $user_prompt_for_title);
    }
    $source_url = $cw_config['source_url'] ?? '';
    if (!empty($source_url) && strpos($user_prompt_for_title, '{source_url}') !== false) {
        $user_prompt_for_title = str_replace('{source_url}', trim($source_url), $user_prompt_for_title);
    }

    $title_ai_params = [
        'temperature' => floatval($cw_config['ai_temperature'] ?? 1),
        'top_p' => null,
    ];

    $title_result = $ai_caller->make_standard_call(
        $cw_config['ai_provider'],
        $cw_config['ai_model'],
        [['role' => 'user', 'content' => $user_prompt_for_title]],
        $title_ai_params,
        $system_instruction_for_title
    );

    if (is_wp_error($title_result)) {
        return $title_result;
    }

    $generated_title_raw = trim($title_result['content'] ?? '');
    if (preg_match('/^"(.*)"$/', $generated_title_raw, $matches)) {
        $generated_title_raw = $matches[1];
    }
    $generated_title = trim(str_replace(["\n", "\r"], ' ', $generated_title_raw));
    $generated_title = preg_replace('/\s+/', ' ', $generated_title);
    if (class_exists(AIPKit_Content_Writer_Output_Cleaner::class)) {
        $keyword_parts = array_map('trim', explode(',', (string) $final_keywords_for_prompt));
        $generated_title = AIPKit_Content_Writer_Output_Cleaner::clean_title(
            (string) $generated_title,
            (string) ($keyword_parts[0] ?? '')
        );
    }

    return [
        'title' => !empty($generated_title) ? $generated_title : $final_title,
        'usage' => $title_result['usage'] ?? null
    ];
}

// --- build-content-prompt.php ---
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$vector_logic_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/retrieval-context.php';
if (file_exists($vector_logic_path)) {
    require_once $vector_logic_path;
}

/**
 * Builds the system instruction and user prompt for a content writing task.
 * UPDATED: Simplified to only use custom prompts, as Guided Mode has been removed. Placeholders are still replaced.
 * UPDATED: Handles new {url_content} and {source_url} placeholders.
 *
 * @param array $cw_config The specific configuration for the content writing item.
 *                         It's expected to have 'content_title' which is the *final* title,
 *                         and potentially 'inline_keywords'.
 * @return array|WP_Error ['system_instruction' => string, 'user_prompt' => string]
 */
function build_content_prompts_logic(array $cw_config)
{
    // System instruction is now simpler as it doesn't need to reference guided fields.
    $system_instruction = class_exists(AIPKit_Content_Writer_System_Instruction_Builder::class)
        ? AIPKit_Content_Writer_System_Instruction_Builder::build($cw_config)
        : "You are an expert content writer specializing in creating high-quality WordPress article content. Return only the final article body. Do not include chat-style follow-up questions, offers to rewrite or convert the article, alternative format suggestions, or assistant commentary outside the article.";
    $final_title = $cw_config['content_title'] ?? 'AI Generated Content';
    $final_keywords = !empty($cw_config['inline_keywords']) ? $cw_config['inline_keywords'] : ($cw_config['content_keywords'] ?? '');

    // --- START: NEW Vector Store Logic ---
    $vector_store_enabled = ($cw_config['enable_vector_store'] ?? '0') === '1';

    if ($vector_store_enabled) {
        $ai_caller = class_exists(AIPKit_AI_Caller::class) ? new AIPKit_AI_Caller(true) : null;
        $vector_store_manager = class_exists(AIPKit_Vector_Store_Manager::class) ? new AIPKit_Vector_Store_Manager() : null;

        if ($ai_caller && $vector_store_manager && function_exists('\WPAICG\Core\Stream\Vector\build_vector_search_context_logic')) {
            $vector_context = VectorContextBuilder\build_vector_search_context_logic(
                $ai_caller,
                $vector_store_manager,
                $final_title, // Use the final title as the primary query text
                $cw_config, // Pass the whole config as it contains all vector settings
                $cw_config['ai_provider'],
                null,
                $cw_config['pinecone_index_name'] ?? null,
                null,
                $cw_config['qdrant_collection_name'] ?? null,
                null,
                $cw_config['chroma_collection_name'] ?? null,
                null
            );
            if (is_wp_error($vector_context)) { return $vector_context; }
            if (!empty($vector_context)) {
                $system_instruction = "## Relevant information from knowledge base:\n" . trim($vector_context) . "\n##\n\n" . $system_instruction;
            }
        }
    }
    // --- END: NEW Vector Store Logic ---


    // Get the user prompt template. The builder now only returns the custom prompt.
    $user_prompt_template = AIPKit_Content_Writer_User_Prompt_Builder::build($cw_config);

    // Add RSS description as context if it exists
    $rss_description = $cw_config['rss_description'] ?? '';
    if (!empty($rss_description)) {
        $user_prompt_template = str_replace('{description}', trim($rss_description), $user_prompt_template);
    }

    // Add URL Scraped Content as context if it exists
    $url_content = $cw_config['url_content_context'] ?? '';
    if (!empty($url_content)) {
        $user_prompt_template = str_replace('{url_content}', trim($url_content), $user_prompt_template);
    }
    $source_url = $cw_config['source_url'] ?? '';
    if (!empty($source_url)) {
        $user_prompt_template = str_replace('{source_url}', trim($source_url), $user_prompt_template);
    }

    // Replace the {topic} placeholder with the final title for the content generation step
    $user_prompt = str_replace('{topic}', $final_title, $user_prompt_template);

    // Replace the {keywords} placeholder with inline keywords if present, otherwise global keywords
    $user_prompt = str_replace('{keywords}', $final_keywords, $user_prompt);

    // Final cleanup in case placeholders were not provided for custom prompt
    $user_prompt = str_replace('{description}', '', $user_prompt);
    $user_prompt = str_replace('{url_content}', '', $user_prompt);
    $user_prompt = str_replace('{source_url}', '', $user_prompt);

    return [
        'system_instruction' => $system_instruction,
        'user_prompt' => $user_prompt,
    ];
}

// --- generate-post-helper.php ---
/**
 * Generates the main post content using the AI Caller.
 *
 * @param array $prompts The array containing system_instruction and user_prompt.
 * @param array $cw_config The specific configuration for the content writing item.
 * @param AIPKit_AI_Caller $ai_caller An instance of the AI Caller.
 * @return array|WP_Error On success, returns ['content' => string, 'usage' => array|null]. On failure, returns WP_Error.
 */
function generate_post_logic(array $prompts, array $cw_config, AIPKit_AI_Caller $ai_caller)
{
    $provider = $cw_config['ai_provider'];
    $model = $cw_config['ai_model'];

    $content_ai_params = [
        'temperature' => floatval($cw_config['ai_temperature'] ?? 1),
        'top_p' => null,
    ];

    $max_completion_tokens = null;
    if (isset($cw_config['max_completion_tokens']) && is_numeric($cw_config['max_completion_tokens'])) {
        $max_completion_tokens = absint($cw_config['max_completion_tokens']);
    } elseif (isset($cw_config['max_tokens']) && is_numeric($cw_config['max_tokens'])) {
        $max_completion_tokens = absint($cw_config['max_tokens']);
    } else {
        $content_length = isset($cw_config['content_length']) ? sanitize_key($cw_config['content_length']) : '';
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
        $content_ai_params['max_completion_tokens'] = $max_completion_tokens;
    }

    if (($provider ?? '') === 'OpenAI') {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
            (string) ($model ?? ''),
            $cw_config['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $content_ai_params['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($provider ?? '') === 'AIPufferCloud') {
        $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) ($model ?? ''), $cw_config['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '') {
            $content_ai_params['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($provider ?? '') === 'OpenRouter') {
        $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
            (string) ($model ?? ''),
            $cw_config['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $content_ai_params['reasoning'] = ['effort' => $reasoning_effort];
        }
    }

    if ($provider === 'OpenAI' &&
        ($cw_config['enable_vector_store'] ?? '0') === '1' &&
        ($cw_config['vector_store_provider'] ?? '') === 'openai' &&
        !empty($cw_config['openai_vector_store_ids']) && is_array($cw_config['openai_vector_store_ids'])) {

        $vector_top_k = absint($cw_config['vector_store_top_k'] ?? 3);

        $content_ai_params['vector_store_tool_config'] = [
            'type'             => 'file_search',
            'vector_store_ids' => $cw_config['openai_vector_store_ids'],
            'max_num_results'  => max(1, min($vector_top_k, 20)),
        ];
    } elseif ($provider === 'Google' &&
        ($cw_config['enable_vector_store'] ?? '0') === '1' &&
        ($cw_config['vector_store_provider'] ?? '') === 'google' &&
        !empty($cw_config['google_file_search_store_names']) && is_array($cw_config['google_file_search_store_names'])) {

        $vector_top_k = absint($cw_config['vector_store_top_k'] ?? 3);
        $content_ai_params['google_file_search_tool_config'] = [
            'file_search_store_names' => array_values(array_unique(array_filter(array_map('sanitize_text_field', $cw_config['google_file_search_store_names'])))),
            'top_k' => max(1, min($vector_top_k, 20)),
        ];
    }

    $content_result = $ai_caller->make_standard_call(
        $provider,
        $model,
        [['role' => 'user', 'content' => $prompts['user_prompt']]],
        $content_ai_params,
        $prompts['system_instruction']
    );

    if (is_wp_error($content_result)) {
        return $content_result;
    }

    $generated_content = $content_result['content'] ?? '';
    if (class_exists(AIPKit_Content_Writer_Output_Cleaner::class)) {
        $initial_keywords = !empty($cw_config['inline_keywords']) ? $cw_config['inline_keywords'] : ($cw_config['content_keywords'] ?? '');
        $initial_focus_keyword = trim((string) explode(',', (string) $initial_keywords)[0]);
        $generated_content = AIPKit_Content_Writer_Output_Cleaner::clean_article_content((string) $generated_content, $initial_focus_keyword);
    }
    if (empty($generated_content)) {
        return new WP_Error('empty_content_response', 'AI returned empty content.');
    }

    return [
        'content' => $generated_content,
        'usage'   => $content_result['usage'] ?? null
    ];
}

// --- insert-post.php ---
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

// Ensure dependencies are loaded
if (!function_exists('WPAICG\ContentWriter\TemplateManagerMethods\calculate_schedule_datetime_logic')) {
    $path = WPAICG_PLUGIN_DIR . 'classes/content-writer/templates.php';
    if (file_exists($path)) {
        require_once $path;
    }
}
if (!class_exists('\WPAICG\Utils\AIPKit_TOC_Generator')) {
    $toc_generator_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/toc.php';
    if (file_exists($toc_generator_path)) {
        require_once $toc_generator_path;
    }
}
if (!class_exists('\WPAICG\SEO\AIPKit_SEO_Helper')) {
    $seo_helper_path = WPAICG_PLUGIN_DIR . 'classes/seo/manager.php';
    if (file_exists($seo_helper_path)) {
        require_once $seo_helper_path;
    }
}
if (!class_exists(AIPKit_Image_Injector::class)) {
    $injector_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/image-placement.php';
    if (file_exists($injector_path)) {
        require_once $injector_path;
    }
}
if (!class_exists(AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::class)) {
    $image_alt_helper_path = WPAICG_LIB_DIR . 'content-writer/seo-images.php';
    if (file_exists($image_alt_helper_path)) {
        require_once $image_alt_helper_path;
    }
}

/**
 * Inserts the generated content as a new post.
 *
 * @param string      $final_title         The final title for the post.
 * @param string      $generated_content   The main content of the post.
 * @param array       $cw_config           The specific configuration for the content writing item.
 * @param string|null $meta_description    Optional SEO meta description to save.
 * @param string|null $focus_keyword       Optional SEO focus keyword to save.
 * @param array|null  $image_data          Optional data for generated images.
 * @param string|null $excerpt             Optional post excerpt.
 * @param string|null $schedule_gmt_time   Optional GMT time string to schedule the post.
 * @return int|WP_Error The new post ID or a WP_Error on failure.
 */
function insert_post_logic(string $final_title, string $generated_content, array $cw_config, ?string $meta_description = null, ?string $focus_keyword = null, ?array $image_data = null, ?string $excerpt = null, ?string $schedule_gmt_time = null)
{
    $post_author = $cw_config['post_author'] ?? get_current_user_id() ?: 1;
    if (!user_can($post_author, 'edit_posts') || !user_can($post_author, get_post_type_object($cw_config['post_type'])->cap->create_posts)) {
        $post_author = 1; // Fallback to admin if user can't create posts
    }

    if (class_exists(\WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::class)) {
        $final_title = \WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::clean_title($final_title, (string) $focus_keyword);
        $generated_content = \WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::clean_article_content($generated_content, (string) $focus_keyword);
    }

    $content_is_html = !empty($cw_config['content_is_html']) || !empty($cw_config['aipkit_content_is_html']);
    if ($content_is_html) {
        $html_content = wp_kses_post($generated_content);
        if (!preg_match('/<\s*(p|h[1-6]|ul|ol|blockquote|table|figure|img)\b/i', $html_content)) {
            $html_content = wpautop($html_content);
        }
    } else {
        $html_content = $generated_content;
        $html_content = \WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner::convert_basic_markdown_to_html((string) $html_content);
        $html_content = wpautop($html_content);
    }

    if (is_array($image_data) && class_exists(AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::class)) {
        AIPKit_Content_Writer_Smart_SEO_Image_Alt_Helper::maybe_prepare_rank_math_image_alt($image_data, (string) $focus_keyword, $cw_config);
    }

    // Inject in-content images before ToC generation
    if (!empty($image_data['in_content_images']) && class_exists(AIPKit_Image_Injector::class)) {
        $image_injector = new AIPKit_Image_Injector();
        $image_alignment = $cw_config['image_alignment'] ?? 'none';
        $image_size = $cw_config['image_size'] ?? 'large';
        $html_content = $image_injector->inject_images(
            $html_content,
            $image_data['in_content_images'],
            $image_data['placement_settings']['placement'] ?? 'after_first_h2',
            absint($image_data['placement_settings']['param_x'] ?? 2),
            $image_alignment,
            $image_size
        );
    }

    $rank_math_profile_active = class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')
        && sanitize_key((string) (\WPAICG\SEO\AIPKit_SEO_Helper::get_active_plugin_profile()['profile'] ?? '')) === 'rank_math';
    $content_has_toc = stripos($html_content, 'wp-block-rank-math-toc-block') !== false
        || stripos($html_content, 'aipkit-toc-list') !== false;
    $should_generate_toc = !$content_has_toc && (
        (isset($cw_config['generate_toc']) && $cw_config['generate_toc'] === '1')
        || (
            $rank_math_profile_active
            && isset($cw_config['seo_score_improvement_enabled'])
            && (string) $cw_config['seo_score_improvement_enabled'] === '1'
            && (!class_exists(AIPKit_Content_Writer_SEO_Config::class) || AIPKit_Content_Writer_SEO_Config::is_rule_enabled($cw_config, 'rank_math_table_of_contents'))
        )
    );

    // Generate ToC after images have been placed.
    if ($should_generate_toc && class_exists(AIPKit_TOC_Generator::class)) {
        $toc_result = AIPKit_TOC_Generator::generate($html_content, [
            'rank_math_compatible' => $rank_math_profile_active,
        ]);
        if (!empty($toc_result['toc'])) {
            $html_content = $toc_result['toc'] . $toc_result['content'];
        }
    }

    $postarr = [
        'post_title'   => $final_title,
        'post_content' => $html_content, // Save the content before wpautop for wp_insert_post
        'post_type'    => $cw_config['post_type'] ?? 'post',
        'post_author'  => $post_author,
        'post_status'  => $cw_config['post_status'] ?? 'draft',
    ];

    if (!empty($schedule_gmt_time)) {
        $schedule_timestamp_gmt = strtotime($schedule_gmt_time);
        $current_timestamp_gmt = current_time('timestamp', true);
        if ($schedule_timestamp_gmt > $current_timestamp_gmt) {
            $postarr['post_status'] = 'future';
            $postarr['post_date_gmt'] = $schedule_gmt_time;
            $postarr['post_date'] = get_date_from_gmt($schedule_gmt_time, 'Y-m-d H:i:s');
        }
    }

    if (!empty($excerpt)) {
        $postarr['post_excerpt'] = $excerpt;
    }

    $smart_seo_slug = !empty($cw_config['smart_seo_slug']) ? sanitize_title((string) $cw_config['smart_seo_slug']) : '';
    if ($smart_seo_slug !== '') {
        $postarr['post_name'] = $smart_seo_slug;
    }

    $category_ids = $cw_config['post_categories'] ?? [];
    if (!empty($category_ids) && $postarr['post_type'] === 'post') {
        $postarr['post_category'] = $category_ids;
    }

    if (($cw_config['post_content_format'] ?? 'html') === 'gutenberg') {
        $postarr['post_content'] = wp_slash(\WPAICG\ContentWriter\AIPKit_Content_Writer_Block_Converter::convert($html_content));
    }

    $new_post_id = wp_insert_post($postarr, true);

    if (is_wp_error($new_post_id)) {
        return new WP_Error('post_insert_failed', 'Failed to save post: ' . $new_post_id->get_error_message());
    }

    if (!empty($image_data['featured_image_id'])) {
        set_post_thumbnail($new_post_id, $image_data['featured_image_id']);
    }

    if ($postarr['post_type'] !== 'post' && !empty($category_ids)) {
        $taxonomy = 'category';
        if (is_object_in_taxonomy($postarr['post_type'], $taxonomy)) {
            wp_set_post_terms($new_post_id, $category_ids, $taxonomy);
        }
    }

    if (!empty($meta_description) && class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
        \WPAICG\SEO\AIPKit_SEO_Helper::update_meta_description($new_post_id, $meta_description);
    }

    if (!empty($focus_keyword) && class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
        \WPAICG\SEO\AIPKit_SEO_Helper::update_focus_keyword($new_post_id, $focus_keyword);
    }

    if ($smart_seo_slug === '' && isset($cw_config['generate_seo_slug']) && $cw_config['generate_seo_slug'] === '1' && class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
        \WPAICG\SEO\AIPKit_SEO_Helper::update_post_slug_for_seo($new_post_id);
    }

    return $new_post_id;
}

// --- process-content-writing-item.php ---
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

// Content-writing helper functions are defined above in this file.

if (defined('WPAICG_LIB_DIR')) {
    $aipkit_paid_worker_path = WPAICG_LIB_DIR . 'automations/content-writing-worker.php';
    if (file_exists($aipkit_paid_worker_path)) {
        require_once $aipkit_paid_worker_path;
    }
}


$aipkit_smart_seo_shared_helper_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/actions.php';
if (file_exists($aipkit_smart_seo_shared_helper_path)) {
    require_once $aipkit_smart_seo_shared_helper_path;
}

// All Content Writer prompt classes share one owner.
if (!class_exists(AIPKit_Content_Writer_Prompts::class)) {
    $aipkit_prompts_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/prompts.php';
    if (file_exists($aipkit_prompts_path)) {
        require_once $aipkit_prompts_path;
    }
}
if (!class_exists(AIPKit_Content_Writer_Image_Handler::class)) {
    $image_handler_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/images.php';
    if (file_exists($image_handler_path)) {
        require_once $image_handler_path;
    }
}
if (!class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)) {
    $image_provider_options_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/image-options.php';
    if (file_exists($image_provider_options_path)) {
        require_once $image_provider_options_path;
    }
}

if (!class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
    $seo_helper_path = WPAICG_PLUGIN_DIR . 'classes/seo/manager.php';
    if (file_exists($seo_helper_path)) {
        require_once $seo_helper_path;
    }
}

if ((!class_exists(AIPKit_Content_Writer_Smart_SEO_Service::class) || !class_exists(AIPKit_Content_Writer_Smart_SEO_Keyword_Resolver::class) || !class_exists(AIPKit_Content_Writer_Smart_SEO_Keyphrase_Usage::class)) && defined('WPAICG_LIB_DIR')) {
    $smart_seo_loader_class = '\\WPAICG\\Lib\\DependencyLoaders\\Smart_SEO_Dependencies_Loader';
    $smart_seo_loader_path = WPAICG_LIB_DIR . 'dependencies.php';
    if (!class_exists($smart_seo_loader_class) && file_exists($smart_seo_loader_path)) {
        require_once $smart_seo_loader_path;
    }
    if (class_exists($smart_seo_loader_class)) {
        $smart_seo_loader_class::load();
    }
}


/**
 * Orchestrates the entire process of generating a single piece of content from a queue item.
 *
 * @param array $item_config The configuration for the specific queue item.
 * @param array $queue_item The queue item record from the database.
 * @return array ['status' => 'success'|'error', 'message' => '...']
 */
function process_content_writing_item_logic(array $item_config, array $queue_item = []): array
{
    maybe_extend_content_writing_task_runtime_logic(600);

    // --- START: Abuse Prevention ---
    $generation_mode = $item_config['cw_generation_mode'] ?? 'single';
    if (in_array($generation_mode, ['rss', 'gsheets', 'url'])) { // 'url' is also Pro
        if (!class_exists('\WPAICG\aipkit_dashboard') || !\WPAICG\aipkit_dashboard::is_pro_plan()) {
            $error_message = __('License is not active.', 'gpt3-ai-content-generator');
            return ['status' => 'error', 'message' => $error_message];
        }
    }
    // --- END: Abuse Prevention ---

    if (!empty($item_config['ai_provider']) && is_scalar($item_config['ai_provider'])) {
        $item_config['ai_provider'] = normalize_content_writing_ai_provider_logic((string) $item_config['ai_provider']);
    }
    $item_config = normalize_content_writing_image_config_logic($item_config);

    $ai_caller = new AIPKit_AI_Caller(true);
    $smart_seo_keyword_resolution = null;
    $smart_seo_keyword_usage = null;

    $resolved_keyword_result = maybe_resolve_smart_seo_task_keywords_logic($item_config, $ai_caller, [
        'topic' => $item_config['content_title'] ?? '',
        'title' => $item_config['content_title'] ?? '',
    ]);
    if ($ai_caller->get_batch_error()) { return Helpers\provider_error_result($ai_caller->get_batch_error()); }
    $item_config = $resolved_keyword_result['config'];
    if (!empty($resolved_keyword_result['resolution']['changed'])) {
        $smart_seo_keyword_resolution = $resolved_keyword_result['resolution'];
        $smart_seo_keyword_usage = $resolved_keyword_result['resolution']['usage'] ?? null;
    }

    // 1. Generate Title (if needed)
    $title_result = generate_title_logic($item_config, $ai_caller);
    if (is_wp_error($title_result)) {
        return Helpers\provider_error_result($title_result);
    }
    $final_title = $title_result['title'];
    $title_usage = $title_result['usage'] ?? null;

    // 2. Build Prompts
    $config_for_prompt = array_merge($item_config, ['content_title' => $final_title]);
    $prompts = build_content_prompts_logic($config_for_prompt);
    if (is_wp_error($prompts)) {
        return Helpers\provider_error_result($prompts);
    }

    // 3. Generate Post Content
    $content_result = generate_post_logic($prompts, $item_config, $ai_caller);
    if (is_wp_error($content_result)) {
        return Helpers\provider_error_result($content_result);
    }
    $generated_content = $content_result['content'];
    $content_usage = $content_result['usage'] ?? null;

    $refusal = null;
    $capture_refusal = static function ($result) use (&$refusal): void {
        if (is_wp_error($result) && AIPKit_AI_Caller::batch_error_data($result)['stop_batch']) {
            $refusal = $result;
        }
    };

    // 4. Generate Images
    $image_data = null;
    $image_usage = null;
    $image_generation_warning = null;
    $requested_inline_images = ($item_config['generate_images_enabled'] ?? '0') === '1' && absint($item_config['image_count'] ?? 0) > 0;
    $requested_featured_image = ($item_config['generate_featured_image'] ?? '0') === '1';
    $requested_any_image = $requested_inline_images || $requested_featured_image;

    if ($requested_any_image && class_exists(AIPKit_Content_Writer_Image_Handler::class)) {
        $image_handler = new AIPKit_Content_Writer_Image_Handler();
        $keywords_for_images = !empty($item_config['inline_keywords']) ? $item_config['inline_keywords'] : ($item_config['content_keywords'] ?? '');
        $image_settings = $item_config;
        $image_settings['aipkit_event_module'] = 'automated_tasks';
        $image_settings['aipkit_event_origin'] = 'automated_task_content_images';
        if (
            ($image_settings['image_provider'] ?? '') === 'openai'
            && class_exists(AIPKit_Providers::class)
            && AIPKit_Providers::is_openai_gpt_image_model((string) ($image_settings['image_model'] ?? ''))
        ) {
            maybe_extend_content_writing_task_runtime_logic(900);
        }

        try {
            $image_result = $image_handler->generate_and_prepare_images($image_settings, $final_title, $keywords_for_images, $item_config['content_title']);
        } catch (\Throwable $throwable) {
            $image_result = new WP_Error(
                'automated_task_image_generation_exception',
                normalize_content_writing_image_warning_logic($throwable->getMessage())
            );
        }

        $capture_refusal($image_result);
        if (is_wp_error($image_result)) {
            $partial_images = $image_result->get_error_data()['image_data'] ?? null;
            if (is_array($partial_images)) { $image_data = $partial_images; }
            // Retain partial images; a provider refusal saves the article as a draft below.
            $error_details = normalize_content_writing_image_warning_logic($image_result->get_error_message());
            $image_generation_warning = $error_details;
        } else {
            $inline_count = !empty($image_result['in_content_images']) && is_array($image_result['in_content_images'])
                ? count($image_result['in_content_images'])
                : 0;
            $has_featured = !empty($image_result['featured_image_id']) || !empty($image_result['featured_image_url']);
            $generated_any_image = $inline_count > 0 || $has_featured;

            if ($generated_any_image) {
                $image_data = $image_result;
            } else {
                $provider = sanitize_text_field((string) ($item_config['image_provider'] ?? ''));
                $model = sanitize_text_field((string) ($item_config['image_model'] ?? ''));
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

                if (!empty($warning_messages)) {
                    $image_generation_warning = normalize_content_writing_image_warning_logic($warning_messages[0]);
                } else {
                    $image_generation_warning = __('Image generation returned no images.', 'gpt3-ai-content-generator');
                    if ($provider !== '' || $model !== '') {
                        $image_generation_warning .= ' ' . sprintf(
                            /* translators: 1: provider name, 2: model name */
                            __('Provider: %1$s, Model: %2$s.', 'gpt3-ai-content-generator'),
                            $provider !== '' ? $provider : __('unknown provider', 'gpt3-ai-content-generator'),
                            $model !== '' ? $model : __('unknown model', 'gpt3-ai-content-generator')
                        );
                    }
                }
            }
        }
    } elseif ($requested_any_image) {
        $image_generation_warning = __('Image generation component is missing.', 'gpt3-ai-content-generator');
    }

    // 5. Generate SEO Data
    $meta_description = null;
    $focus_keyword = null;
    $excerpt = null;
    $tags = null;
    $meta_usage = null;
    $keyword_usage = null;
    $excerpt_usage = null;
    $tags_usage = null;

    $generate_meta = (isset($item_config['generate_meta_description']) && $item_config['generate_meta_description'] === '1');
    $generate_keyword = (isset($item_config['generate_focus_keyword']) && $item_config['generate_focus_keyword'] === '1');
    $generate_excerpt = (isset($item_config['generate_excerpt']) && $item_config['generate_excerpt'] === '1');
    $generate_tags = (isset($item_config['generate_tags']) && $item_config['generate_tags'] === '1');
    $prompt_mode = $item_config['prompt_mode'] ?? 'custom'; // For AutoGPT, we assume prompts are always custom
    $should_generate_seo = ($generate_meta || $generate_keyword || $generate_excerpt || $generate_tags) && !empty($generated_content);

    if ($should_generate_seo && !$refusal) {
        $content_summary = AIPKit_Content_Writer_Summarizer::summarize($generated_content);
        $final_keywords = !empty($item_config['inline_keywords']) ? $item_config['inline_keywords'] : ($item_config['content_keywords'] ?? '');

        if ($generate_keyword && empty($final_keywords)) { // Only generate if not provided
            $custom_keyword_prompt = $item_config['custom_keyword_prompt'] ?? null;
            $keyword_user_prompt = AIPKit_Content_Writer_Keyword_Prompt_Builder::build($final_title, $content_summary, $prompt_mode, $custom_keyword_prompt);
            $keyword_ai_params = ['temperature' => 0.2, 'top_p' => null];
            $keyword_result = $ai_caller->make_standard_call($item_config['ai_provider'], $item_config['ai_model'], [['role' => 'user', 'content' => $keyword_user_prompt]], $keyword_ai_params, 'You are an SEO expert. Your task is to provide the single best focus keyword for a piece of content.');
            $capture_refusal($keyword_result);
            if (!is_wp_error($keyword_result) && !empty($keyword_result['content'])) {
                $focus_keyword = trim(str_replace(['"', "'", '.'], '', $keyword_result['content']));
                $final_keywords = $focus_keyword; // Use this new keyword for other SEO prompts
                $keyword_usage = $keyword_result['usage'] ?? null;
                if (class_exists(\WPAICG\Lib\Automations\ContentWriting::class)) {
                    \WPAICG\Lib\Automations\ContentWriting::resolve_generated_keyword(
                        $item_config, $ai_caller, $final_title, $content_summary,
                        $focus_keyword, $final_keywords, $keyword_usage, $smart_seo_keyword_resolution
                    );
                    $capture_refusal($ai_caller->get_batch_error());
                }
            }
        } elseif (!empty($final_keywords)) {
            $focus_keyword = explode(',', $final_keywords)[0]; // Use first provided keyword as focus keyword
        }

        if (!$refusal && $generate_excerpt && class_exists(AIPKit_Content_Writer_Excerpt_Prompt_Builder::class)) {
            $custom_excerpt_prompt = $item_config['custom_excerpt_prompt'] ?? null;
            $excerpt_user_prompt = AIPKit_Content_Writer_Excerpt_Prompt_Builder::build($final_title, $content_summary, $final_keywords, $prompt_mode, $custom_excerpt_prompt);
            $excerpt_system_instruction = 'You are an expert copywriter. Your task is to provide an engaging excerpt for a piece of content.';
            $excerpt_ai_params = ['temperature' => 1, 'top_p' => null];

            $excerpt_result = $ai_caller->make_standard_call(
                $item_config['ai_provider'],
                $item_config['ai_model'],
                [['role' => 'user', 'content' => $excerpt_user_prompt]],
                $excerpt_ai_params,
                $excerpt_system_instruction
            );
            $capture_refusal($excerpt_result);
            if (!is_wp_error($excerpt_result) && !empty($excerpt_result['content'])) {
                $excerpt = trim(str_replace(['"', "'"], '', $excerpt_result['content']));
                $excerpt_usage = $excerpt_result['usage'] ?? null;
            }
        }

        if (!$refusal && $generate_tags && class_exists(AIPKit_Content_Writer_Tags_Prompt_Builder::class)) {
            $custom_tags_prompt = $item_config['custom_tags_prompt'] ?? null;
            $tags_user_prompt = AIPKit_Content_Writer_Tags_Prompt_Builder::build($final_title, $content_summary, $final_keywords, $prompt_mode, $custom_tags_prompt);
            $tags_system_instruction = 'You are an SEO expert. Your task is to provide a comma-separated list of relevant tags for a piece of content.';
            $tags_ai_params = ['temperature' => 0.5, 'top_p' => null];

            $tags_result = $ai_caller->make_standard_call(
                $item_config['ai_provider'],
                $item_config['ai_model'],
                [['role' => 'user', 'content' => $tags_user_prompt]],
                $tags_ai_params,
                $tags_system_instruction
            );
            $capture_refusal($tags_result);
            if (!is_wp_error($tags_result) && !empty($tags_result['content'])) {
                $tags = trim(str_replace(['"', "'"], '', $tags_result['content']));
                $tags_usage = $tags_result['usage'] ?? null;
            }
        }

        if (!$refusal && $generate_meta && class_exists(AIPKit_Content_Writer_Meta_Prompt_Builder::class)) {
            $custom_meta_prompt = $item_config['custom_meta_prompt'] ?? null;
            $meta_user_prompt = AIPKit_Content_Writer_Meta_Prompt_Builder::build($final_title, $content_summary, $final_keywords, $prompt_mode, $custom_meta_prompt);
            $meta_system_instruction = 'You are an SEO expert specializing in writing meta descriptions.';
            $meta_ai_params = ['temperature' => 1, 'top_p' => null];

            $meta_result = $ai_caller->make_standard_call(
                $item_config['ai_provider'],
                $item_config['ai_model'],
                [['role' => 'user', 'content' => $meta_user_prompt]],
                $meta_ai_params,
                $meta_system_instruction
            );

            $capture_refusal($meta_result);
            if (!is_wp_error($meta_result) && !empty($meta_result['content'])) {
                $meta_description = AIPKit_Content_Writer_Output_Cleaner::clean_meta_description((string) $meta_result['content']);
                $meta_usage = $meta_result['usage'] ?? null;
            }
        }
    }
    // --- END Generate SEO Data ---

    $smart_seo_result = null;
    $smart_seo_usage = null;
    $smart_seo_warning = null;
    if (!$refusal && class_exists(\WPAICG\Lib\Automations\ContentWriting::class)) {
        [
            'final_title' => $final_title,
            'generated_content' => $generated_content,
            'meta_description' => $meta_description,
            'focus_keyword' => $focus_keyword,
            'excerpt' => $excerpt,
            'tags' => $tags,
            'smart_seo_result' => $smart_seo_result,
            'smart_seo_usage' => $smart_seo_usage,
            'smart_seo_warning' => $smart_seo_warning,
        ] = \WPAICG\Lib\Automations\ContentWriting::improve_draft(
            $item_config, $ai_caller, compact('final_title', 'generated_content', 'meta_description', 'focus_keyword', 'excerpt', 'tags', 'image_data')
        );
    } elseif (($item_config['seo_score_improvement_enabled'] ?? '0') === '1') {
        $smart_seo_warning = __('Smart SEO runtime is unavailable.', 'gpt3-ai-content-generator');
    }

    $focus_slug_rule_enabled = !class_exists(AIPKit_Content_Writer_SEO_Config::class)
        || AIPKit_Content_Writer_SEO_Config::is_rule_enabled($item_config, 'focus_keyword_in_slug');
    $rank_math_permalink_rule_enabled = !class_exists(AIPKit_Content_Writer_SEO_Config::class)
        || AIPKit_Content_Writer_SEO_Config::is_rule_enabled($item_config, 'rank_math_permalink_length');

    if (
        ($item_config['seo_score_improvement_enabled'] ?? '0') === '1'
        && empty($item_config['smart_seo_slug'])
        && class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')
        && sanitize_key((string) (\WPAICG\SEO\AIPKit_SEO_Helper::get_active_plugin_profile()['profile'] ?? '')) === 'rank_math'
        && ($focus_slug_rule_enabled || $rank_math_permalink_rule_enabled)
    ) {
        $rank_math_slug = class_exists('\\WPAICG\\ContentWriter\\SEO\\AIPKit_Content_Writer_Smart_SEO_Keyphrase_Content_Helper')
            ? \WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_Smart_SEO_Keyphrase_Content_Helper::build_rank_math_slug('', $focus_slug_rule_enabled ? (string) $focus_keyword : '', (string) $final_title)
            : sanitize_title((string) (($focus_slug_rule_enabled && $focus_keyword !== '') ? $focus_keyword : $final_title));
        if ($rank_math_slug !== '') {
            $item_config['smart_seo_slug'] = $rank_math_slug;
        }
    }

    $capture_refusal($ai_caller->get_batch_error());
    if ($refusal) {
        $item_config['post_status'] = 'draft';
        unset($item_config['scheduled_gmt_time']);
    }

    // 6. Insert Post
    $scheduled_gmt_time = $item_config['scheduled_gmt_time'] ?? null;
    $insert_result = insert_post_logic($final_title, $generated_content, $item_config, $meta_description, $focus_keyword, $image_data, $excerpt, $scheduled_gmt_time);
    if (is_wp_error($insert_result)) {
        return ['status' => 'error', 'message' => $insert_result->get_error_message()];
    }
    $new_post_id = $insert_result;

    if ($smart_seo_result && class_exists(AIPKit_Content_Writer_Smart_SEO_Service::class)) {
        AIPKit_Content_Writer_Smart_SEO_Service::save_audit_meta($new_post_id, $smart_seo_result);
    }

    if (class_exists(\WPAICG\Lib\Automations\ContentWriting::class)) {
        \WPAICG\Lib\Automations\ContentWriting::record_rss_item($item_config);
    }

    if (!empty($tags) && class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
        \WPAICG\SEO\AIPKit_SEO_Helper::update_tags($new_post_id, $tags);
    }


    $smart_seo_slug = !empty($item_config['smart_seo_slug']) ? sanitize_title((string) $item_config['smart_seo_slug']) : '';
    if ($smart_seo_slug === '' && isset($item_config['generate_seo_slug']) && $item_config['generate_seo_slug'] === '1' && class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')) {
        \WPAICG\SEO\AIPKit_SEO_Helper::update_post_slug_for_seo($new_post_id);
    }

    if (class_exists(\WPAICG\Lib\Automations\ContentWriting::class)) {
        \WPAICG\Lib\Automations\ContentWriting::mark_sheet_row_processed($item_config);
    }

    // 7. Log the generated content
    $total_usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'provider_raw' => []];
    $all_usages = array_filter([$title_usage, $content_usage, $meta_usage, $keyword_usage, $image_usage, $excerpt_usage, $tags_usage, $smart_seo_keyword_usage, $smart_seo_usage]);
    foreach ($all_usages as $usage) {
        if (is_array($usage)) {
            $total_usage['input_tokens'] += (int)($usage['input_tokens'] ?? 0);
            $total_usage['output_tokens'] += (int)($usage['output_tokens'] ?? 0);
            $total_usage['total_tokens'] += (int)($usage['total_tokens'] ?? 0);
            if (isset($usage['provider_raw'])) {
                $total_usage['provider_raw'][] = $usage['provider_raw'];
            }
        }
    }
    if (class_exists(LogStorage::class)) {
        $smart_seo_log_data = null;
        if ($smart_seo_result) {
            $smart_seo_log_data = [
                'score' => $smart_seo_result['score'] ?? null,
                'target' => $smart_seo_result['target'] ?? null,
                'profile' => $smart_seo_result['profile'] ?? null,
                'profile_label' => $smart_seo_result['profile_label'] ?? null,
                'passes' => $smart_seo_result['passes'] ?? 0,
                'reached_target' => !empty($smart_seo_result['reached_target']),
                'warnings' => $smart_seo_result['warnings'] ?? [],
            ];
        } elseif ($smart_seo_warning) {
            $smart_seo_log_data = ['warning' => $smart_seo_warning];
        }

        $log_storage = new LogStorage();
        $post_author_id = $item_config['post_author'] ?? 1;
        $author_data = get_userdata($post_author_id);
        $log_data = [
            'bot_id'            => null,
            'user_id'           => $post_author_id,
            'session_id'        => null,
            'conversation_uuid' => wp_generate_uuid4(),
            'module'            => 'content_writer_automation',
            'is_guest'          => 0,
            'role'              => $author_data ? implode(', ', $author_data->roles) : null,
            'ip_address'        => null,
            'message_role'      => 'bot',
            'message_content'   => "Automated Post Generated: " . esc_html($final_title),
            'timestamp'         => time(),
            'ai_provider'       => $item_config['ai_provider'],
            'ai_model'          => $item_config['ai_model'],
            'usage'             => $total_usage,
            'request_payload'   => ['item_config' => $item_config, 'prompts' => $prompts],
            'response_data'     => ['post_id' => $new_post_id, 'title' => $final_title, 'meta' => $meta_description, 'keyword' => $focus_keyword, 'excerpt' => $excerpt, 'tags' => $tags, 'image_warning' => $image_generation_warning, 'smart_seo_keyword_resolution' => $smart_seo_keyword_resolution, 'smart_seo' => $smart_seo_log_data]
        ];
        $log_storage->log_message($log_data);
    }

    if (class_exists(AIPKit_Event_Webhooks::class)) {
        AIPKit_Event_Webhooks::emit(
            'content.generated',
            [
                'title' => $final_title,
                'content' => $generated_content,
                'post' => [
                    'id' => $new_post_id,
                    'status' => get_post_status($new_post_id),
                    'url' => get_permalink($new_post_id),
                ],
                'excerpt' => $excerpt,
                'meta_description' => $meta_description,
                'focus_keyword' => $focus_keyword,
                'tags' => $tags,
                'smart_seo' => $smart_seo_result ? [
                    'score' => $smart_seo_result['score'] ?? null,
                    'target' => $smart_seo_result['target'] ?? null,
                    'profile' => $smart_seo_result['profile'] ?? null,
                    'profile_label' => $smart_seo_result['profile_label'] ?? null,
                    'passes' => $smart_seo_result['passes'] ?? 0,
                    'reached_target' => !empty($smart_seo_result['reached_target']),
                ] : null,
                'ai' => [
                    'provider' => $item_config['ai_provider'],
                    'model' => $item_config['ai_model'],
                ],
                'task' => [
                    'id' => isset($item_config['task_id']) ? (int) $item_config['task_id'] : 0,
                    'queue_item_id' => isset($queue_item['id']) ? (int) $queue_item['id'] : 0,
                ],
            ],
            [
                'module' => 'content_writer',
                'origin' => 'automated_task_content_writing',
                'resource' => [
                    'type' => 'post',
                    'id' => $new_post_id,
                    'label' => $final_title !== '' ? $final_title : __('Generated content', 'gpt3-ai-content-generator'),
                ],
                'meta' => [
                    'task_id' => isset($item_config['task_id']) ? (int) $item_config['task_id'] : 0,
                    'queue_item_id' => isset($queue_item['id']) ? (int) $queue_item['id'] : 0,
                    'provider' => $item_config['ai_provider'],
                    'model' => $item_config['ai_model'],
                ],
                'idempotency_key' => sha1(implode('|', [
                    'content.generated',
                    'automated_task_content_writing',
                    (string) $new_post_id,
                    (string) ($queue_item['id'] ?? 0),
                    $final_title,
                ])),
            ]
        );
    }

    if ($refusal) {
        $result = Helpers\provider_error_result($refusal);
        $result['post_id'] = $new_post_id;
        $result['message'] .= ' ' . sprintf(
            /* translators: %d: saved draft post ID. */
            __('Partial content saved as draft #%d. Review it before retrying.', 'gpt3-ai-content-generator'), $new_post_id
        );
        return $result;
    }

    $success_message = 'Content generated and post created (ID: ' . $new_post_id . ').';
    if (!empty($image_generation_warning)) {
        $success_message .= ' ' . sprintf(
            /* translators: %s: warning details about image generation */
            __('Image generation warning: %s', 'gpt3-ai-content-generator'),
            $image_generation_warning
        );
    }
    if (!empty($smart_seo_warning)) {
        $success_message .= ' ' . sprintf(
            /* translators: %s: warning details about Smart SEO */
            __('Smart SEO warning: %s', 'gpt3-ai-content-generator'),
            $smart_seo_warning
        );
    }

    return ['status' => 'success', 'message' => $success_message];
}

function maybe_extend_content_writing_task_runtime_logic(int $seconds): void
{
    $seconds = max(60, $seconds);
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }
    if (function_exists('set_time_limit')) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running background content tasks need a bounded runtime extension.
        @set_time_limit($seconds);
    }
    if (function_exists('ini_set')) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running background content tasks need a bounded runtime extension.
        @ini_set('max_execution_time', (string) $seconds);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running background content tasks need a bounded socket timeout extension.
        @ini_set('default_socket_timeout', (string) max(120, min($seconds, 300)));
    }
}

function normalize_content_writing_ai_provider_logic(string $provider): string
{
    return AIPKit_Providers::normalize_provider_label($provider);
}

function normalize_content_writing_image_config_logic(array $item_config): array
{
    $provider = normalize_content_writing_image_provider_logic((string) ($item_config['image_provider'] ?? 'openai'));
    $item_config['image_provider'] = $provider;

    $model = sanitize_text_field((string) ($item_config['image_model'] ?? ''));
    if ($provider === 'openai' && class_exists(AIPKit_Providers::class)) {
        $model = AIPKit_Providers::normalize_openai_image_model($model);
    } elseif ($provider === 'xai' && class_exists(AIPKit_Providers::class)) {
        $model = AIPKit_Providers::normalize_xai_image_model($model);
    } elseif ($provider === 'google' && class_exists(AIPKit_Providers::class)) {
        $model = AIPKit_Providers::normalize_google_image_model($model);
    } elseif ($provider === 'pexels' || $provider === 'pixabay') {
        $model = '';
    }
    $item_config['image_model'] = $model;

    $item_config['generate_images_enabled'] = (($item_config['generate_images_enabled'] ?? '0') === '1') ? '1' : '0';
    $item_config['generate_featured_image'] = (($item_config['generate_featured_image'] ?? '0') === '1') ? '1' : '0';
    $item_config['image_count'] = max(1, min(absint($item_config['image_count'] ?? 1), 10));
    $item_config['image_placement_param_x'] = max(1, absint($item_config['image_placement_param_x'] ?? 2));
    $item_config['image_provider_options'] = class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)
        ? AIPKit_Content_Writer_Image_Provider_Options::sanitize_options_json($item_config['image_provider_options'] ?? '{}', $item_config)
        : ($item_config['image_provider_options'] ?? '{}');

    return $item_config;
}

function normalize_content_writing_image_provider_logic(string $provider): string
{
    $provider = sanitize_key($provider);
    $aliases = [
        'open_ai' => 'openai',
        'open-router' => 'openrouter',
        'open_router' => 'openrouter',
        'x_ai' => 'xai',
    ];
    $provider = $aliases[$provider] ?? $provider;
    $allowed = ['openai', 'aipuffercloud', 'openrouter', 'google', 'azure', 'xai', 'replicate', 'pexels', 'pixabay'];

    return in_array($provider, $allowed, true) ? $provider : 'openai';
}

function normalize_content_writing_image_warning_logic(string $message): string
{
    $message = trim(wp_strip_all_tags($message));
    if ($message === '') {
        return __('Image generation could not be completed for this automated post.', 'gpt3-ai-content-generator');
    }

    if (preg_match('/(504|gateway\\s*time|timed?\\s*out|cURL error 28|operation timed out|deadline exceeded)/i', $message)) {
        return __('Image generation took longer than expected and was skipped for this automated post.', 'gpt3-ai-content-generator');
    }

    return $message;
}

function maybe_resolve_smart_seo_task_keywords_logic(array $item_config, AIPKit_AI_Caller $ai_caller, array $context = []): array
{
    return class_exists(\WPAICG\Lib\Automations\ContentWriting::class)
        ? \WPAICG\Lib\Automations\ContentWriting::resolve_keywords($item_config, $ai_caller, $context)
        : ['config' => $item_config, 'resolution' => []];
}
