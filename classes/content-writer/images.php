<?php


namespace WPAICG\ContentWriter;

use WPAICG\Images\AIPKit_Image_Manager;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)) {
    $aipkit_image_provider_options_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/image-options.php';
    if (file_exists($aipkit_image_provider_options_path)) {
        require_once $aipkit_image_provider_options_path;
    }
}

require_once dirname(__DIR__) . '/image-generator/storage.php';

class AIPKit_Content_Writer_Image_Handler
{
    use \WPAICG\Images\AIPKit_Image_Input_Trait;

    private $image_manager;
    private const DEFAULT_ALT_TEXT = 'Image';

    public function __construct()
    {
        if (!class_exists(AIPKit_Image_Manager::class)) {
            $manager_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/manager.php';
            if (file_exists($manager_path)) {
                require_once $manager_path;
            } else {
                $this->image_manager = null;
                return;
            }
        }
        $this->image_manager = new AIPKit_Image_Manager();
    }

    private function normalize_provider_name(string $provider): string
    {
        switch (strtolower($provider)) {
            case 'openai':
                return 'OpenAI';
            case 'aipuffercloud':
                return 'AIPufferCloud';
            case 'openrouter':
                return 'OpenRouter';
            case 'google':
                return 'Google';
            case 'azure':
                return 'Azure';
            case 'xai':
                return 'xAI';
            case 'claude':
                return 'Claude';
            case 'deepseek':
                return 'DeepSeek';
            case 'ollama':
                return 'Ollama';
            default:
                return ucfirst(strtolower($provider));
        }
    }

    private function trim_text(string $text, int $max_length): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > $max_length) {
            $text = mb_substr($text, 0, $max_length);
        } elseif (strlen($text) > $max_length) {
            $text = (string) substr($text, 0, $max_length);
        }
        return rtrim($text);
    }

    private function get_primary_keyword(string $keywords): string
    {
        $parts = preg_split('/[,\|\n;]+/', $keywords);
        if (!$parts) {
            return '';
        }
        foreach ($parts as $part) {
            $value = trim($part);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function build_filename_base(string $topic, string $primary_keyword, bool $is_featured, int $index): string
    {
        $parts = [];
        if ($topic !== '') {
            $parts[] = $topic;
        }
        if ($primary_keyword !== '' && stripos($topic, $primary_keyword) === false) {
            $parts[] = $primary_keyword;
        }
        $parts[] = $is_featured ? 'featured' : 'image';
        if ($index > 1) {
            $parts[] = (string) $index;
        }
        return sanitize_title_with_dashes(implode(' ', $parts));
    }

    private function build_attachment_meta(string $topic, string $keywords, string $post_title, string $excerpt, int $index, bool $is_featured): array
    {
        $primary_keyword = $this->get_primary_keyword($keywords);
        $topic_label = $post_title !== '' ? $post_title : ($topic !== '' ? $topic : $primary_keyword);

        if ($topic_label === '') {
            $alt_text = self::DEFAULT_ALT_TEXT;
            $title_text = 'Image';
        } else {
            if ($primary_keyword !== '' && stripos($topic_label, $primary_keyword) === false) {
                $alt_text = sprintf('%s illustration for %s', $primary_keyword, $topic_label);
            } else {
                $alt_text = sprintf('Illustration of %s', $topic_label);
            }
            if ($index > 1) {
                $alt_text = sprintf('Additional %s', $alt_text);
            }
            $title_text = $topic_label;
        }

        $alt_text = $this->trim_text($alt_text, 125);
        $title_suffix = $is_featured ? 'Featured image' : 'Image';
        $title_text = $this->trim_text(sprintf('%s — %s', $title_text, $title_suffix), 80);

        $caption_text = $excerpt !== '' ? $this->trim_text($excerpt, 120) : '';
        $description_text = $excerpt !== ''
            ? $this->trim_text($excerpt, 240)
            : ($topic_label !== '' ? sprintf('Image for %s.', $topic_label) : 'Image.');

        return [
            'filename_base' => $this->build_filename_base($topic_label, $primary_keyword, $is_featured, $index),
            'title' => $title_text,
            'alt' => $alt_text,
            'caption' => $caption_text,
            'description' => $description_text,
        ];
    }

    private function build_image_metadata_prompt(string $template, array $placeholders, string $image_context): string
    {
        $prompt = str_replace(array_keys($placeholders), array_values($placeholders), $template);
        if ($image_context !== '') {
            if (strpos($prompt, '{image_context}') !== false) {
                $prompt = str_replace('{image_context}', $image_context, $prompt);
            } else {
                $prompt .= "\n\nImage context: " . $image_context;
            }
        } else {
            $prompt = str_replace('{image_context}', '', $prompt);
        }

        return trim($prompt);
    }

    private function get_image_event_context_options(array $settings): array
    {
        $module = sanitize_key((string) ($settings['aipkit_event_module'] ?? 'content_writer'));
        if ($module === '') {
            $module = 'content_writer';
        }

        $origin = sanitize_key((string) ($settings['aipkit_event_origin'] ?? 'content_writer_images'));
        if ($origin === '') {
            $origin = 'content_writer_images';
        }

        return [
            'aipkit_event_module' => $module,
            'aipkit_event_origin' => $origin,
        ];
    }

    private function apply_provider_generation_options(array $generation_options, array $provider_options, string $provider, string $model): array
    {
        if ($provider === 'google') {
            return $this->apply_google_generation_options($generation_options, $provider_options, $model);
        }

        if ($provider === 'xai') {
            return $this->apply_xai_generation_options($generation_options, $provider_options);
        }

        if ($provider === 'replicate') {
            return $this->apply_replicate_generation_options($generation_options, $provider_options, $model);
        }

        if ($provider === 'openrouter') {
            return $this->apply_openrouter_generation_options($generation_options, $provider_options);
        }

        if ($provider === 'azure') {
            return $this->apply_azure_generation_options($generation_options, $provider_options);
        }

        if ($provider !== 'openai' || !class_exists(AIPKit_Providers::class) || !AIPKit_Providers::is_openai_gpt_image_model($model)) {
            return $generation_options;
        }

        $openai_options = isset($provider_options['openai']) && is_array($provider_options['openai'])
            ? $provider_options['openai']
            : [];
        if (empty($openai_options)) {
            return $generation_options;
        }

        $canvas_size = sanitize_text_field((string) ($openai_options['canvas_size'] ?? ''));
        if (in_array($canvas_size, ['1024x1024', '1536x1024', '1024x1536', 'auto'], true)) {
            $generation_options['size'] = $canvas_size;
        }

        $quality = sanitize_key((string) ($openai_options['quality'] ?? ''));
        if (in_array($quality, ['auto', 'low', 'medium', 'high'], true)) {
            $generation_options['quality'] = $quality;
        }

        $output_format = sanitize_key((string) ($openai_options['output_format'] ?? ''));
        if (in_array($output_format, ['png', 'jpeg', 'webp'], true)) {
            $generation_options['output_format'] = $output_format;
        }

        $background = sanitize_key((string) ($openai_options['background'] ?? ''));
        if (in_array($background, ['auto', 'opaque'], true)) {
            $generation_options['background'] = $background;
        } elseif (
            $background === 'transparent'
            && ($output_format === '' || in_array($output_format, ['png', 'webp'], true))
            && AIPKit_Providers::openai_image_model_supports_transparent_background($model)
        ) {
            $generation_options['background'] = $background;
        }

        $moderation = sanitize_key((string) ($openai_options['moderation'] ?? ''));
        if (in_array($moderation, ['auto', 'low'], true)) {
            $generation_options['moderation'] = $moderation;
        }

        $compression = $openai_options['output_compression'] ?? '';
        if (in_array($output_format, ['jpeg', 'webp'], true) && $compression !== '') {
            $generation_options['output_compression'] = max(0, min(absint($compression), 100));
        } else {
            unset($generation_options['output_compression']);
        }

        return $generation_options;
    }

    private function apply_azure_generation_options(array $generation_options, array $provider_options): array
    {
        unset($generation_options['style'], $generation_options['output_compression']);

        $azure_options = isset($provider_options['azure']) && is_array($provider_options['azure'])
            ? $provider_options['azure']
            : [];
        if (empty($azure_options)) {
            return $generation_options;
        }

        $canvas_size = sanitize_text_field((string) ($azure_options['canvas_size'] ?? ''));
        if (in_array($canvas_size, ['1024x1024', '1536x1024', '1024x1536', 'auto'], true)) {
            $generation_options['size'] = $canvas_size;
        }

        $quality = sanitize_key((string) ($azure_options['quality'] ?? ''));
        if (in_array($quality, ['low', 'medium', 'high'], true)) {
            $generation_options['quality'] = $quality;
        }

        $output_format = sanitize_key((string) ($azure_options['output_format'] ?? ''));
        if (in_array($output_format, ['png', 'jpeg'], true)) {
            $generation_options['output_format'] = $output_format;
        }

        $background = sanitize_key((string) ($azure_options['background'] ?? ''));
        if (in_array($background, ['auto', 'transparent'], true)) {
            $generation_options['background'] = $background;
            if ($background === 'transparent') {
                $generation_options['output_format'] = 'png';
                $output_format = 'png';
            }
        }

        $compression = $azure_options['output_compression'] ?? '';
        if ($output_format === 'jpeg' && $compression !== '') {
            $generation_options['output_compression'] = max(0, min(absint($compression), 100));
        }

        return $generation_options;
    }

    private function apply_openrouter_generation_options(array $generation_options, array $provider_options): array
    {
        unset(
            $generation_options['aspect_ratio'],
            $generation_options['image_size'],
            $generation_options['resolution'],
            $generation_options['output_format'],
            $generation_options['output_compression'],
            $generation_options['background']
        );

        $openrouter_options = isset($provider_options['openrouter']) && is_array($provider_options['openrouter'])
            ? $provider_options['openrouter']
            : [];
        if (empty($openrouter_options)) {
            return $generation_options;
        }

        $aspect_ratio = sanitize_text_field((string) ($openrouter_options['aspect_ratio'] ?? ''));
        $allowed_aspect_ratios = ['auto', '1:1', '1:2', '2:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '9:19.5', '19.5:9', '9:20', '20:9', '9:21', '21:9', '1:4', '4:1', '1:8', '8:1'];
        if ($aspect_ratio !== '' && in_array($aspect_ratio, $allowed_aspect_ratios, true)) {
            $generation_options['aspect_ratio'] = $aspect_ratio;
        }

        $resolution = strtolower(sanitize_text_field((string) ($openrouter_options['image_size'] ?? '')));
        if ($resolution === '0.5k') {
            $resolution = '512';
        }
        if (in_array($resolution, ['512', '1k', '2k', '4k'], true)) {
            $generation_options['resolution'] = $resolution === '512' ? '512' : strtoupper($resolution);
        }

        $quality = sanitize_key((string) ($openrouter_options['quality'] ?? ''));
        if (in_array($quality, ['auto', 'low', 'medium', 'high'], true)) {
            $generation_options['quality'] = $quality;
        } else {
            unset($generation_options['quality']);
        }

        $output_format = sanitize_key((string) ($openrouter_options['output_format'] ?? ''));
        if (in_array($output_format, ['png', 'jpeg', 'webp'], true)) {
            $generation_options['output_format'] = $output_format;
        }

        $background = sanitize_key((string) ($openrouter_options['background'] ?? ''));
        if (in_array($background, ['auto', 'transparent', 'opaque'], true)) {
            $generation_options['background'] = $background;
        }

        $compression = $openrouter_options['output_compression'] ?? '';
        if (in_array($output_format, ['jpeg', 'webp'], true) && $compression !== '') {
            $generation_options['output_compression'] = max(0, min(absint($compression), 100));
        }

        return $generation_options;
    }

    private function apply_xai_generation_options(array $generation_options, array $provider_options): array
    {
        $xai_options = isset($provider_options['xai']) && is_array($provider_options['xai'])
            ? $provider_options['xai']
            : [];
        if (empty($xai_options)) {
            return $generation_options;
        }

        $aspect_ratio = sanitize_text_field((string) ($xai_options['aspect_ratio'] ?? ''));
        if ($aspect_ratio !== '' && in_array($aspect_ratio, ['auto', '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '2:1', '1:2', '19.5:9', '9:19.5', '20:9', '9:20'], true)) {
            $generation_options['aspect_ratio'] = $aspect_ratio;
        } else {
            unset($generation_options['aspect_ratio']);
        }

        $resolution = sanitize_key((string) ($xai_options['resolution'] ?? ''));
        if (in_array($resolution, ['1k', '2k'], true)) {
            $generation_options['resolution'] = $resolution;
        } else {
            unset($generation_options['resolution']);
        }

        return $generation_options;
    }

    private function apply_replicate_generation_options(array $generation_options, array $provider_options, string $model): array
    {
        $replicate_fields = [
            'aspect_ratio',
            'width',
            'height',
            'negative_prompt',
            'guidance',
            'num_inference_steps',
            'seed',
            'output_format',
            'output_quality',
        ];

        foreach ($replicate_fields as $field) {
            unset($generation_options[$field]);
        }

        $replicate_options = isset($provider_options['replicate']) && is_array($provider_options['replicate'])
            ? $provider_options['replicate']
            : [];
        if (empty($replicate_options)) {
            return $generation_options;
        }

        $schema_fields = $this->get_replicate_model_input_fields($model);
        if (empty($schema_fields)) {
            return $generation_options;
        }

        $aspect_ratio = sanitize_text_field((string) ($replicate_options['aspect_ratio'] ?? ''));
        if ($aspect_ratio !== '' && $this->is_replicate_string_option_supported($schema_fields, 'aspect_ratio', $aspect_ratio, ['1:1', '16:9', '21:9', '3:2', '2:3', '4:3', '3:4', '4:5', '5:4', '9:16', '1:2', '2:1', '3:1', '1:3'])) {
            $generation_options = $this->set_replicate_input_option($generation_options, $schema_fields, 'aspect_ratio', $aspect_ratio);
        }

        $negative_prompt = AIPKit_Prompt_Sanitizer::sanitize($replicate_options['negative_prompt'] ?? '');
        if ($negative_prompt !== '' && isset($schema_fields['negative_prompt'])) {
            $generation_options = $this->set_replicate_input_option(
                $generation_options,
                $schema_fields,
                'negative_prompt',
                function_exists('mb_substr') ? mb_substr($negative_prompt, 0, 1000) : substr($negative_prompt, 0, 1000)
            );
        }

        foreach (['width', 'height'] as $dimension_field) {
            $dimension = $this->sanitize_replicate_int_option($replicate_options[$dimension_field] ?? '', $schema_fields[$dimension_field] ?? null, 64, 4096);
            if ($dimension !== null) {
                $generation_options = $this->set_replicate_input_option($generation_options, $schema_fields, $dimension_field, $dimension);
            }
        }

        $guidance = $this->sanitize_replicate_float_option($replicate_options['guidance'] ?? '', $schema_fields['guidance'] ?? null, 0.0, 30.0);
        if ($guidance !== null) {
            $generation_options = $this->set_replicate_input_option($generation_options, $schema_fields, 'guidance', $guidance);
        }

        $steps = $this->sanitize_replicate_int_option($replicate_options['num_inference_steps'] ?? '', $schema_fields['num_inference_steps'] ?? null, 1, 100);
        if ($steps !== null) {
            $generation_options = $this->set_replicate_input_option($generation_options, $schema_fields, 'num_inference_steps', $steps);
        }

        $seed = $this->sanitize_replicate_int_option($replicate_options['seed'] ?? '', $schema_fields['seed'] ?? null, 0, 2147483647);
        if ($seed !== null) {
            $generation_options = $this->set_replicate_input_option($generation_options, $schema_fields, 'seed', $seed);
        }

        $output_format = sanitize_key((string) ($replicate_options['output_format'] ?? ''));
        if ($output_format !== '' && $this->is_replicate_string_option_supported($schema_fields, 'output_format', $output_format, ['webp', 'png', 'jpg', 'jpeg'])) {
            $generation_options = $this->set_replicate_input_option($generation_options, $schema_fields, 'output_format', $output_format);
        }

        $output_quality = $this->sanitize_replicate_int_option($replicate_options['output_quality'] ?? '', $schema_fields['output_quality'] ?? null, 0, 100);
        if ($output_quality !== null) {
            $generation_options = $this->set_replicate_input_option($generation_options, $schema_fields, 'output_quality', $output_quality);
        }

        return $generation_options;
    }

    private function set_replicate_input_option(array $generation_options, array $schema_fields, string $field, $value): array
    {
        if (!isset($schema_fields[$field]) || !is_array($schema_fields[$field])) {
            return $generation_options;
        }

        $input_name = sanitize_key((string) ($schema_fields[$field]['input_name'] ?? $field));
        if ($input_name === '') {
            return $generation_options;
        }

        if (!isset($generation_options['replicate_input_options']) || !is_array($generation_options['replicate_input_options'])) {
            $generation_options['replicate_input_options'] = [];
        }
        $generation_options['replicate_input_options'][$input_name] = $value;

        return $generation_options;
    }

    private function get_replicate_model_input_fields(string $model): array
    {
        if (!class_exists(AIPKit_Providers::class)) {
            return [];
        }

        $target_model = strtolower(trim($model));
        if ($target_model === '') {
            return [];
        }

        $models = AIPKit_Providers::get_replicate_models();
        if (!is_array($models)) {
            return [];
        }

        foreach ($models as $model_row) {
            if (!is_array($model_row)) {
                continue;
            }
            $model_id = strtolower(trim((string) ($model_row['id'] ?? '')));
            if ($model_id !== $target_model) {
                continue;
            }

            $schema = $model_row['input_schema'] ?? ($model_row['replicate_input_schema'] ?? []);
            if (!is_array($schema)) {
                return [];
            }
            $fields = $schema['fields'] ?? $schema;

            return is_array($fields) ? $fields : [];
        }

        return [];
    }

    private function is_replicate_string_option_supported(array $schema_fields, string $field, string $value, array $fallback_allowed): bool
    {
        if (!isset($schema_fields[$field]) || !is_array($schema_fields[$field])) {
            return false;
        }

        $enum = isset($schema_fields[$field]['enum']) && is_array($schema_fields[$field]['enum'])
            ? array_map('strval', $schema_fields[$field]['enum'])
            : [];

        if (!empty($enum)) {
            return in_array($value, $enum, true);
        }

        return in_array($value, $fallback_allowed, true);
    }

    private function sanitize_replicate_int_option($value, $schema_field, int $fallback_min, int $fallback_max): ?int
    {
        if (!is_array($schema_field) || $value === null || $value === '') {
            return null;
        }

        $value = is_scalar($value) ? (string) wp_unslash($value) : '';
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = absint($value);
        $minimum = isset($schema_field['minimum']) && is_numeric($schema_field['minimum'])
            ? (int) $schema_field['minimum']
            : $fallback_min;
        $maximum = isset($schema_field['maximum']) && is_numeric($schema_field['maximum'])
            ? (int) $schema_field['maximum']
            : $fallback_max;

        if ($number < $minimum || $number > $maximum) {
            return null;
        }

        return $number;
    }

    private function sanitize_replicate_float_option($value, $schema_field, float $fallback_min, float $fallback_max): ?float
    {
        if (!is_array($schema_field) || $value === null || $value === '') {
            return null;
        }

        $value = is_scalar($value) ? (string) wp_unslash($value) : '';
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        $minimum = isset($schema_field['minimum']) && is_numeric($schema_field['minimum'])
            ? (float) $schema_field['minimum']
            : $fallback_min;
        $maximum = isset($schema_field['maximum']) && is_numeric($schema_field['maximum'])
            ? (float) $schema_field['maximum']
            : $fallback_max;

        if ($number < $minimum || $number > $maximum) {
            return null;
        }

        return $number;
    }

    private function apply_google_generation_options(array $generation_options, array $provider_options, string $model): array
    {
        $google_options = isset($provider_options['google']) && is_array($provider_options['google'])
            ? $provider_options['google']
            : [];
        if (empty($google_options)) {
            return $generation_options;
        }

        $aspect_ratio = sanitize_text_field((string) ($google_options['aspect_ratio'] ?? ''));
        if ($aspect_ratio !== '' && $this->is_google_aspect_ratio_supported($model, $aspect_ratio)) {
            $generation_options['aspect_ratio'] = $aspect_ratio;
        } else {
            unset($generation_options['aspect_ratio']);
        }

        $image_size = sanitize_key((string) ($google_options['image_size'] ?? ''));
        if ($image_size !== '' && $this->is_google_image_size_supported($model, $image_size)) {
            $generation_options['image_size'] = $this->normalize_google_image_size($image_size);
        } else {
            unset($generation_options['image_size']);
        }

        return $generation_options;
    }

    private function is_google_aspect_ratio_supported(string $model, string $aspect_ratio): bool
    {
        $model = strtolower($model);
        $gemini_ratios = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
        $gemini_31_ratios = array_merge($gemini_ratios, ['1:4', '4:1', '1:8', '8:1']);

        if (strpos($model, 'gemini-3.1-flash-image') !== false) {
            return in_array($aspect_ratio, $gemini_31_ratios, true);
        }

        return AIPKit_Providers::is_supported_google_image_model($model)
            && in_array($aspect_ratio, $gemini_ratios, true);
    }

    private function is_google_image_size_supported(string $model, string $image_size): bool
    {
        $model = strtolower($model);
        $gemini_31_sizes = ['512', '1k', '2k', '4k'];
        $gemini_3_pro_sizes = ['1k', '2k', '4k'];

        if (strpos($model, 'gemini-3.1-flash-image') !== false) {
            return in_array($image_size, $gemini_31_sizes, true);
        }

        if (strpos($model, 'gemini-3.1-flash-lite-image') !== false) {
            return $image_size === '1k';
        }

        if (strpos($model, 'gemini-3-pro-image') !== false) {
            return in_array($image_size, $gemini_3_pro_sizes, true);
        }

        return false;
    }

    private function normalize_google_image_size(string $image_size): string
    {
        return $image_size === '512' ? '512' : strtoupper($image_size);
    }

    private function is_gemini_native_image_model(string $model): bool
    {
        $model = strtolower($model);

        return strpos($model, 'gemini') !== false
            && (
                strpos($model, 'image-generation') !== false
                || strpos($model, 'flash-image') !== false
                || strpos($model, 'pro-image') !== false
            );
    }

    private function prepare_native_image_prompt(string $prompt, string $provider, string $model): string
    {
        $provider = strtolower($provider);
        if ($provider !== 'google' || !$this->is_gemini_native_image_model($model)) {
            return $prompt;
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            return $prompt;
        }

        $directive = 'Create the image itself. Do not write or describe an image prompt. Return image output.';
        if (stripos($prompt, $directive) !== false) {
            return $prompt;
        }

        return $prompt . "\n\n" . $directive;
    }

    private function maybe_generate_image_metadata(
        array $settings,
        array &$image_data,
        string $final_title,
        string $final_keywords,
        string $post_title,
        string $excerpt,
        string $meta_topic
    ): ?WP_Error {
        $mode = sanitize_key((string) ($settings['cw_generation_mode'] ?? ''));
        if ($mode !== '' && strpos($mode, 'existing') === 0) {
            return null;
        }

        $has_inline = !empty($image_data['in_content_images']) && is_array($image_data['in_content_images']);
        $has_featured = !empty($image_data['featured_image_id']);
        if (!$has_inline && !$has_featured) {
            return null;
        }

        $field_flags = [
            'title' => ($settings['generate_image_title'] ?? '0') === '1',
            'alt' => ($settings['generate_image_alt_text'] ?? '0') === '1',
            'caption' => ($settings['generate_image_caption'] ?? '0') === '1',
            'description' => ($settings['generate_image_description'] ?? '0') === '1',
        ];
        if (!in_array(true, $field_flags, true)) {
            return null;
        }

        if (!class_exists(AIPKit_AI_Caller::class)) {
            $ai_caller_path = WPAICG_PLUGIN_DIR . 'classes/ai/caller.php';
            if (file_exists($ai_caller_path)) {
                require_once $ai_caller_path;
            } else {
                return null;
            }
        }

        $ai_caller = new AIPKit_AI_Caller(true, (string) ($settings['aipkit_event_module'] ?? 'content_writer'));
        $global_config = AIPKit_Providers::get_new_text_generation_selection();
        $global_ai_params = AIPKIT_AI_Settings::get_ai_parameters();

        $provider_raw = (string) ($settings['ai_provider'] ?? ($global_config['provider'] ?? 'OpenAI'));
        $provider = $this->normalize_provider_name($provider_raw);
        $model = (string) ($settings['ai_model'] ?? ($global_config['model'] ?? ''));
        if ($model === '') {
            return null;
        }

        $provider_config = AIPKit_Providers::get_provider_data($provider);
        if ($provider !== 'Ollama' && empty($provider_config['api_key'])) {
            return null;
        }

        $temperature = isset($settings['ai_temperature']) && $settings['ai_temperature'] !== ''
            ? floatval($settings['ai_temperature'])
            : floatval($global_ai_params['temperature'] ?? 0.7);

        $reasoning_effort = '';
        if ($provider === 'OpenAI') {
            $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
                (string) $model,
                $settings['reasoning_effort'] ?? ''
            );
        } elseif ($provider === 'AIPufferCloud') {
            $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) $model, $settings['reasoning_effort'] ?? '');
        } elseif ($provider === 'OpenRouter') {
            $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
                (string) $model,
                $settings['reasoning_effort'] ?? ''
            );
        }

        $prompt_templates = [
            'title' => trim((string) ($settings['image_title_prompt'] ?? '')),
            'alt' => trim((string) ($settings['image_alt_text_prompt'] ?? '')),
            'caption' => trim((string) ($settings['image_caption_prompt'] ?? '')),
            'description' => trim((string) ($settings['image_description_prompt'] ?? '')),
        ];

        if ($prompt_templates['title'] === '') {
            $prompt_templates['title'] = AIPKit_Content_Writer_Prompts::get_default_image_title_prompt();
        }
        if ($prompt_templates['alt'] === '') {
            $prompt_templates['alt'] = AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt();
        }
        if ($prompt_templates['caption'] === '') {
            $prompt_templates['caption'] = AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt();
        }
        if ($prompt_templates['description'] === '') {
            $prompt_templates['description'] = AIPKit_Content_Writer_Prompts::get_default_image_description_prompt();
        }

        $base_placeholders = [
            '{topic}' => $meta_topic !== '' ? $meta_topic : $final_title,
            '{keywords}' => $final_keywords,
            '{post_title}' => $post_title,
            '{excerpt}' => $excerpt,
        ];

        $system_instruction = 'You are an expert assistant. Follow the prompt exactly and return only the requested text.';

        $context_cache = [];
        $metadata_error = null;
        $process_attachment = function (int $attachment_id, array &$image_item) use (
            $ai_caller,
            $provider,
            $model,
            $temperature,
            $reasoning_effort,
            $prompt_templates,
            $field_flags,
            $base_placeholders,
            $system_instruction,
            &$context_cache,
            &$metadata_error
        ) {
            $file_name = '';
            $file_path = (string) get_attached_file($attachment_id);
            if ($file_path !== '') {
                $file_name = wp_basename($file_path);
            }

            $image_context = '';
            if (AIPKit_Providers::model_supports_image_input($provider, $model)) {
                if (!isset($context_cache[$attachment_id])) {
                    $context_cache[$attachment_id] = $this->get_image_context_for_attachment(
                        $attachment_id,
                        $provider,
                        $ai_caller,
                        $model,
                        $reasoning_effort !== '' ? ['reasoning' => ['effort' => $reasoning_effort]] : []
                    );
                }
                if (is_wp_error($context_cache[$attachment_id])) { $metadata_error = $context_cache[$attachment_id]; return null; }
                $image_context = (string) $context_cache[$attachment_id];
            }

            $placeholders = array_merge($base_placeholders, [
                '{file_name}' => $file_name,
            ]);

            $updated_values = [
                'title' => '',
                'alt' => '',
                'caption' => '',
                'description' => '',
            ];

            foreach ($field_flags as $field => $enabled) {
                if (!$enabled) {
                    continue;
                }

                $prompt = $this->build_image_metadata_prompt(
                    $prompt_templates[$field],
                    $placeholders,
                    $image_context
                );
                if ($prompt === '') {
                    continue;
                }

                $max_tokens = 120;
                if ($field === 'title') {
                    $max_tokens = 60;
                } elseif ($field === 'alt' || $field === 'caption') {
                    $max_tokens = 80;
                } elseif ($field === 'description') {
                    $max_tokens = 140;
                }

                $ai_params = [
                    'temperature' => $temperature,
                    'max_completion_tokens' => $max_tokens,
                ];
                if ($reasoning_effort !== '') {
                    $ai_params['reasoning'] = ['effort' => $reasoning_effort];
                }

                $ai_result = $ai_caller->make_standard_call(
                    $provider,
                    $model,
                    [['role' => 'user', 'content' => $prompt]],
                    $ai_params,
                    $system_instruction,
                    ['attachment_id' => $attachment_id]
                );

                if (is_wp_error($ai_result)) { $metadata_error = $ai_result; return null; }
                if (empty($ai_result['content'])) { continue; }

                $value = trim(preg_replace('/\s+/', ' ', (string) $ai_result['content']));
                if ($value === '') {
                    continue;
                }

                switch ($field) {
                    case 'title':
                        $value = $this->trim_text(sanitize_text_field($value), 80);
                        break;
                    case 'alt':
                        $value = $this->trim_text(sanitize_text_field($value), 125);
                        break;
                    case 'caption':
                        $value = $this->trim_text(sanitize_text_field($value), 120);
                        break;
                    case 'description':
                        $value = $this->trim_text(sanitize_textarea_field($value), 240);
                        break;
                }

                if ($value !== '') {
                    $updated_values[$field] = $value;
                }
            }

            $post_update = ['ID' => $attachment_id];
            if ($updated_values['title'] !== '') {
                $post_update['post_title'] = $updated_values['title'];
                $image_item['title'] = $updated_values['title'];
            }
            if ($updated_values['caption'] !== '') {
                $post_update['post_excerpt'] = $updated_values['caption'];
                $image_item['caption'] = $updated_values['caption'];
            }
            if ($updated_values['description'] !== '') {
                $post_update['post_content'] = $updated_values['description'];
                $image_item['description'] = $updated_values['description'];
            }
            if (count($post_update) > 1) {
                wp_update_post($post_update);
            }
            if ($updated_values['alt'] !== '') {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $updated_values['alt']);
                update_post_meta($attachment_id, '_aipkit_image_alt_text', $updated_values['alt']);
                $image_item['alt'] = $updated_values['alt'];
            }
        };

        if ($has_inline) {
            foreach ($image_data['in_content_images'] as &$image_item) {
                $attachment_id = isset($image_item['attachment_id']) ? absint($image_item['attachment_id']) : 0;
                if ($attachment_id <= 0) {
                    continue;
                }
                $process_attachment($attachment_id, $image_item);
                if ($metadata_error) { break; }
            }
            unset($image_item);
        }

        if ($has_featured && !$metadata_error) {
            $featured_id = absint($image_data['featured_image_id']);
            if ($featured_id > 0) {
                $featured_item = ['attachment_id' => $featured_id];
                $process_attachment($featured_id, $featured_item);
                if (!empty($featured_item['alt'])) {
                    $image_data['featured_image_alt'] = $featured_item['alt'];
                }
                if (!empty($featured_item['title'])) {
                    $image_data['featured_image_title'] = $featured_item['title'];
                }
                if (!empty($featured_item['caption'])) {
                    $image_data['featured_image_caption'] = $featured_item['caption'];
                }
                if (!empty($featured_item['description'])) {
                    $image_data['featured_image_description'] = $featured_item['description'];
                }
            }
        }
        return $metadata_error;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_and_prepare_images(array $settings, string $final_title, string $final_keywords, ?string $original_topic = null)
    {
        if (!$this->image_manager) {
            return new WP_Error('image_manager_missing', 'Image Manager dependency is not available.');
        }

        $generate_in_content = ($settings['generate_images_enabled'] ?? '0') === '1';
        $image_count = absint($settings['image_count'] ?? 1);
        $image_start_index = absint($settings['image_start_index'] ?? 1);
        if ($image_start_index < 1) {
            $image_start_index = 1;
        }
        $image_provider = strtolower($settings['image_provider'] ?? 'openai');
        $is_stock_provider = in_array($image_provider, ['pexels', 'pixabay'], true);
        $generate_featured = ($settings['generate_featured_image'] ?? '0') === '1';
        $provider_options = class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)
            ? AIPKit_Content_Writer_Image_Provider_Options::normalize($settings)
            : [];

        $main_image_prompt_template = $settings['image_prompt'] ?? '{topic}';
        $featured_image_prompt_template = !empty($settings['featured_image_prompt']) ? $settings['featured_image_prompt'] : $main_image_prompt_template;

        $post_title = isset($settings['post_title']) ? sanitize_text_field($settings['post_title']) : $final_title;
        $excerpt = isset($settings['excerpt']) ? sanitize_textarea_field($settings['excerpt']) : '';
        $meta_topic = $final_title !== '' ? $final_title : ($original_topic ?? '');

        // Replace placeholders for AI-specific prompts
        $replacements = [
            '{topic}' => $final_title,
            '{keywords}' => $final_keywords,
            '{post_title}' => $post_title,
            '{excerpt}' => $excerpt,
        ];
        $ai_main_prompt = str_replace(array_keys($replacements), array_values($replacements), $main_image_prompt_template);
        $ai_featured_prompt = str_replace(array_keys($replacements), array_values($replacements), $featured_image_prompt_template);

        // --- MODIFICATION: "Keyword-First" search strategy for Pexels/Pixabay ---
        $stock_image_topic = !empty(trim($final_keywords)) ? trim($final_keywords) : (!empty($original_topic) ? $original_topic : $final_title);
        $prompt_for_main_images = ($image_provider === 'pexels' || $image_provider === 'pixabay') ? $stock_image_topic : $ai_main_prompt;
        $prompt_for_featured_image = !empty($featured_image_prompt_template)
                                     ? (($image_provider === 'pexels' || $image_provider === 'pixabay') ? $stock_image_topic : $ai_featured_prompt)
                                     : $prompt_for_main_images; // Fallback to main prompt if featured is empty

        $final_image_data = [
            'in_content_images' => [],
            'featured_image_id' => null,
            'featured_image_url' => null,
            'placement_settings' => [
                'placement' => $settings['image_placement'] ?? 'after_first_h2',
                'param_x' => absint($settings['image_placement_param_x'] ?? 2)
            ]
        ];
        $image_warnings = [];
        $append_image_warning = static function (array &$warnings, string $message): void {
            $message = trim(wp_strip_all_tags($message));
            if ($message === '' || in_array($message, $warnings, true)) {
                return;
            }
            $warnings[] = $message;
        };
        $event_context_options = $this->get_image_event_context_options($settings);

        $current_user_id = get_current_user_id() ?: 1;
        $default_image_model = $image_provider === 'aipuffercloud' ? '' : AIPKit_Providers::get_default_openai_image_model();
        $resolved_image_model = sanitize_text_field((string) ($settings['image_model'] ?? $default_image_model));
        if ($image_provider === 'openai') {
            $resolved_image_model = AIPKit_Providers::normalize_openai_image_model($resolved_image_model);
        }
        if ($image_provider === 'xai') {
            $resolved_image_model = AIPKit_Providers::normalize_xai_image_model($resolved_image_model);
        }
        if ($image_provider === 'google') {
            $resolved_image_model = AIPKit_Providers::normalize_google_image_model($resolved_image_model);
        }

        if ($image_provider === 'openrouter' && in_array($resolved_image_model, ['', 'openrouter/auto', 'auto'], true)) {
            $openrouter_image_models = class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_openrouter_image_models() : [];
            if (is_array($openrouter_image_models) && !empty($openrouter_image_models)) {
                foreach ($openrouter_image_models as $openrouter_model) {
                    if (!is_array($openrouter_model) || empty($openrouter_model['id'])) {
                        continue;
                    }
                    $candidate_id = sanitize_text_field((string) $openrouter_model['id']);
                    if ($candidate_id === '' || in_array($candidate_id, ['openrouter/auto', 'auto'], true)) {
                        continue;
                    }
                    $resolved_image_model = $candidate_id;
                    break;
                }
            }
        }

        if ($is_stock_provider) {
            $num_to_fetch = 0;
            if ($generate_in_content) {
                $num_to_fetch += $image_count;
            }
            if ($generate_featured) {
                $num_to_fetch++;
            }

            if ($num_to_fetch > 0) {
                $generation_options = array_merge($event_context_options, ['n' => $num_to_fetch]);
                $meta_list = [];
                if ($generate_in_content && $image_count > 0) {
                    for ($i = 1; $i <= $image_count; $i++) {
                        $meta_list[] = $this->build_attachment_meta(
                            $meta_topic,
                            $final_keywords,
                            $post_title,
                            $excerpt,
                            $image_start_index + $i - 1,
                            false
                        );
                    }
                }
                if ($generate_featured) {
                    $meta_list[] = $this->build_attachment_meta(
                        $meta_topic,
                        $final_keywords,
                        $post_title,
                        $excerpt,
                        1,
                        true
                    );
                }
                if (!empty($meta_list)) {
                    $generation_options['aipkit_attachment_meta_list'] = $meta_list;
                }
                if ($image_provider === 'pexels') {
                    $pexels_options = isset($provider_options['pexels']) && is_array($provider_options['pexels']) ? $provider_options['pexels'] : [];
                    $generation_options['provider'] = 'pexels';
                    $generation_options['orientation'] = $settings['pexels_orientation'] ?? ($pexels_options['orientation'] ?? 'none');
                    $generation_options['size'] = $settings['pexels_size'] ?? ($pexels_options['size'] ?? 'none');
                    $generation_options['color'] = $settings['pexels_color'] ?? ($pexels_options['color'] ?? '');
                } elseif ($image_provider === 'pixabay') {
                    $pixabay_options = isset($provider_options['pixabay']) && is_array($provider_options['pixabay']) ? $provider_options['pixabay'] : [];
                    $generation_options['provider'] = 'pixabay';
                    $generation_options['orientation'] = $settings['pixabay_orientation'] ?? ($pixabay_options['orientation'] ?? 'all');
                    $generation_options['image_type'] = $settings['pixabay_image_type'] ?? ($pixabay_options['image_type'] ?? 'all');
                    $generation_options['category'] = $settings['pixabay_category'] ?? ($pixabay_options['category'] ?? '');
                }


                $stock_result = $this->image_manager->generate_image($prompt_for_main_images, $generation_options, $current_user_id);
                if (!is_wp_error($stock_result) && !empty($stock_result['images'])) {
                    $this->pexels_image_cache = $stock_result['images']; // Reusing this cache variable name for simplicity
                } else {
                    $error_msg = is_wp_error($stock_result) ? $stock_result->get_error_message() : "No images returned from {$image_provider} API.";
                    $append_image_warning($image_warnings, $error_msg);
                }
            }

            // Populate in-content images from cache
            if ($generate_in_content && $image_count > 0) {
                $final_image_data['in_content_images'] = array_splice($this->pexels_image_cache, 0, $image_count);
            }

            // Populate featured image from the *remaining* cache
            if ($generate_featured) {
                $featured_image_data = array_shift($this->pexels_image_cache);
                if ($featured_image_data) {
                    if (!empty($featured_image_data['attachment_id'])) {
                        $final_image_data['featured_image_id'] = $featured_image_data['attachment_id'];
                    }
                    $featured_image_url = $featured_image_data['media_library_url'] ?? ($featured_image_data['url'] ?? ($featured_image_data['src'] ?? ($featured_image_data['image_url'] ?? null)));
                    if (!empty($featured_image_url)) {
                        $final_image_data['featured_image_url'] = $featured_image_url;
                    }
                }
            }
        }


        // --- Original AI Generation Logic (for OpenAI, Google, etc.) ---
        // Main image generation
        if (!$is_stock_provider && $generate_in_content && $image_count > 0 && !empty($prompt_for_main_images)) {
            $image_model = $resolved_image_model;
            $prompt_for_main_generation = $this->prepare_native_image_prompt($prompt_for_main_images, $image_provider, $image_model);
            $generation_options = array_merge($event_context_options, [
                'provider' => strtolower($settings['image_provider'] ?? 'openai'),
                'model' => $image_model,
                'size' => '1024x1024',
                'response_format' => 'url',
                'user' => 'cw_user_' . $current_user_id,
                'quality' => 'standard',
                'style' => 'vivid'
            ]);
            $generation_options = $this->apply_provider_generation_options($generation_options, $provider_options, $image_provider, $image_model);

            // Models/providers that only support returning one image per request
            $models_with_n_equals_1 = [];
            if ($image_provider === 'openai' && AIPKit_Providers::is_openai_gpt_image_model($image_model)) {
                $models_with_n_equals_1[] = $image_model;
            }
            // OpenRouter routes often return a single image even when n > 1 is requested.
            // Force one-by-one requests so requested image_count is consistently honored.
            $force_single_image_requests = in_array($image_provider, ['google', 'replicate', 'openrouter', 'aipuffercloud'], true);

            if ($force_single_image_requests || in_array($image_model, $models_with_n_equals_1, true)) {
                for ($i = 0; $i < $image_count; $i++) {
                    $generation_options['n'] = 1;
                    $generation_options['aipkit_attachment_meta'] = $this->build_attachment_meta(
                        $meta_topic,
                        $final_keywords,
                        $post_title,
                        $excerpt,
                        $image_start_index + $i,
                        false
                    );
                    $result = $this->image_manager->generate_image($prompt_for_main_generation, $generation_options, $current_user_id);
                    if ($this->preserve_image_refusal($result, $final_image_data)) {
                        return $result;
                    }
                    if (!is_wp_error($result) && !empty($result['images'])) {
                        $final_image_data['in_content_images'][] = $result['images'][0];
                    } else {
                        $error_msg = is_wp_error($result) ? $result->get_error_message() : 'No images returned from API.';
                        $append_image_warning($image_warnings, $error_msg);
                    }
                }
            } else { // Models that support n > 1
                $generation_options['n'] = min($image_count, 10);
                $meta_list = [];
                for ($i = 1; $i <= $generation_options['n']; $i++) {
                    $meta_list[] = $this->build_attachment_meta(
                        $meta_topic,
                        $final_keywords,
                        $post_title,
                        $excerpt,
                        $image_start_index + $i - 1,
                        false
                    );
                }
                if (!empty($meta_list)) {
                    $generation_options['aipkit_attachment_meta_list'] = $meta_list;
                }

                $result = $this->image_manager->generate_image($prompt_for_main_generation, $generation_options, $current_user_id);
                if ($this->preserve_image_refusal($result, $final_image_data)) {
                    return $result;
                }
                if (!is_wp_error($result) && !empty($result['images'])) {
                    $final_image_data['in_content_images'] = array_merge($final_image_data['in_content_images'], $result['images']);
                    if (count($final_image_data['in_content_images']) > $image_count) {
                        $final_image_data['in_content_images'] = array_slice($final_image_data['in_content_images'], 0, $image_count);
                    }
                } else {
                    $error_msg = is_wp_error($result) ? $result->get_error_message() : 'No images returned from API.';
                    $append_image_warning($image_warnings, $error_msg);
                }
            }
        }

        // Featured image generation
        if (!$is_stock_provider && $generate_featured && !empty($prompt_for_featured_image)) {
            // Note: The original logic already had a separate call for the featured image for AI providers,
            // which is correct behavior as it might use a different prompt.
            $prompt_for_featured_generation = $this->prepare_native_image_prompt($prompt_for_featured_image, $image_provider, $resolved_image_model);
            $generation_options = array_merge($event_context_options, [
                'provider' => strtolower($settings['image_provider'] ?? 'openai'),
                'model' => $resolved_image_model,
                'n' => 1,
                'size' => '1024x1024',
                'response_format' => 'url',
                'user' => 'cw_user_featured_' . $current_user_id,
                'quality' => 'hd',
                'style' => 'vivid'
            ]);
            $generation_options = $this->apply_provider_generation_options($generation_options, $provider_options, $image_provider, $resolved_image_model);
            $generation_options['aipkit_attachment_meta'] = $this->build_attachment_meta(
                $meta_topic,
                $final_keywords,
                $post_title,
                $excerpt,
                1,
                true
            );

            $result = $this->image_manager->generate_image($prompt_for_featured_generation, $generation_options, $current_user_id);
            if ($this->preserve_image_refusal($result, $final_image_data)) {
                return $result;
            }

            if (!is_wp_error($result) && !empty($result['images'][0])) {
                $featured_image = $result['images'][0];
                if (!empty($featured_image['attachment_id'])) {
                    $final_image_data['featured_image_id'] = $featured_image['attachment_id'];
                }
                $featured_image_url = $featured_image['media_library_url'] ?? ($featured_image['url'] ?? ($featured_image['src'] ?? ($featured_image['image_url'] ?? null)));
                if (!empty($featured_image_url)) {
                    $final_image_data['featured_image_url'] = $featured_image_url;
                }
                if (empty($final_image_data['featured_image_id']) && empty($final_image_data['featured_image_url'])) {
                    $append_image_warning($image_warnings, 'No featured image URL returned.');
                }
            } else {
                $error_msg = is_wp_error($result) ? $result->get_error_message() : 'No featured image returned.';
                $append_image_warning($image_warnings, $error_msg);
            }
        }

        $metadata_error = $this->maybe_generate_image_metadata(
            $settings,
            $final_image_data,
            $final_title,
            $final_keywords,
            $post_title,
            $excerpt,
            $meta_topic
        );

        if (is_wp_error($metadata_error)) {
            if ($this->preserve_image_refusal($metadata_error, $final_image_data)) { return $metadata_error; }
            $append_image_warning($image_warnings, $metadata_error->get_error_message());
        }

        if (!empty($image_warnings)) {
            $final_image_data['warnings'] = $image_warnings;
            $final_image_data['warning'] = $image_warnings[0];
        }

        return $final_image_data;
    }

    private function preserve_image_refusal($result, array $image_data): bool
    {
        if (!is_wp_error($result)) {
            return false;
        }
        $error_data = $result->get_error_data();
        if (!is_array($error_data) || empty($error_data['stop_batch'])) {
            return false;
        }
        $error_data['image_data'] = $image_data;
        $result->add_data($error_data);
        return true;
    }



}
