<?php

namespace WPAICG\Includes;

use WPAICG\AIPKit_Role_Manager;
use WPAICG\aipkit_dashboard;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Admin_Setup;
use WPAICG\Chat\Admin\AdminSetup;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registers and renders Gutenberg blocks for shortcode-driven embeds.
 */
class AIPKit_Blocks_Manager
{
    private const EDITOR_SCRIPT_HANDLE = 'aipkit-editor-embed-blocks';

    private string $version;

    public function __construct(string $version)
    {
        $this->version = $version;
    }

    public function init_hooks(): void
    {
        add_action('init', [$this, 'register_block_editor_script']);
        add_action('init', [$this, 'register_blocks']);
        add_action('enqueue_block_editor_assets', [$this, 'localize_block_editor_assets']);
    }

    public function register_block_editor_script(): void
    {
        $script_path = WPAICG_PLUGIN_DIR . 'dist/js/admin-embed-blocks.bundle.js';
        if (!file_exists($script_path)) {
            return;
        }

        $version = filemtime($script_path);
        if (!is_int($version) || $version <= 0) {
            $version = $this->version;
        }

        if (!wp_script_is(self::EDITOR_SCRIPT_HANDLE, 'registered')) {
            wp_register_script(
                self::EDITOR_SCRIPT_HANDLE,
                WPAICG_PLUGIN_URL . 'dist/js/admin-embed-blocks.bundle.js',
                ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor'],
                (string) $version,
                true
            );
        }
    }

    public function localize_block_editor_assets(): void
    {
        if (!is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !method_exists($screen, 'is_block_editor') || !$screen->is_block_editor()) {
            return;
        }

        $this->register_block_editor_script();
        if (!wp_script_is(self::EDITOR_SCRIPT_HANDLE, 'registered')) {
            return;
        }

        if (!$this->is_script_localized(self::EDITOR_SCRIPT_HANDLE, 'aipkit_blocks_data')) {
            wp_localize_script(self::EDITOR_SCRIPT_HANDLE, 'aipkit_blocks_data', $this->get_block_editor_data());
        }

        wp_set_script_translations(self::EDITOR_SCRIPT_HANDLE, 'gpt3-ai-content-generator', WPAICG_PLUGIN_DIR . 'languages');
    }

    public function register_blocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        $this->register_block_editor_script();

        $blocks = [
            'aipkit/chatbot' => [
                'render_callback' => [$this, 'render_chatbot_block'],
                'attributes' => [
                    'botId' => [
                        'type' => 'number',
                        'default' => 0,
                    ],
                ],
            ],
            'aipkit/ai-form' => [
                'render_callback' => [$this, 'render_ai_form_block'],
                'attributes' => [
                    'formId' => [
                        'type' => 'number',
                        'default' => 0,
                    ],
                    'theme' => [
                        'type' => 'string',
                        'default' => 'light',
                    ],
                    'showProvider' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'showModel' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'saveButton' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'pdfDownload' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'copyButton' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
            'aipkit/image-generator' => [
                'render_callback' => [$this, 'render_image_generator_block'],
                'attributes' => [
                    'allowModelSelection' => [
                        'type' => 'boolean',
                    ],
                    'showProvider' => [
                        'type' => 'boolean',
                        'default' => true,
                    ],
                    'showModel' => [
                        'type' => 'boolean',
                        'default' => true,
                    ],
                    'history' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'mode' => [
                        'type' => 'string',
                        'default' => 'generate',
                    ],
                    'defaultMode' => [
                        'type' => 'string',
                        'default' => 'generate',
                    ],
                    'showModeSwitch' => [
                        'type' => 'boolean',
                        'default' => true,
                    ],
                    'theme' => [
                        'type' => 'string',
                        'default' => 'light',
                    ],
                    'font' => [
                        'type' => 'string',
                        'default' => 'system',
                    ],
                ],
            ],
        ];

        foreach ($blocks as $name => $definition) {
            register_block_type($name, $definition + [
                'api_version' => 3,
                'editor_script' => self::EDITOR_SCRIPT_HANDLE,
                'supports' => ['html' => false],
            ]);
        }
    }

    /**
     * Render callback for the chatbot block.
     *
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_chatbot_block(array $attributes): string
    {
        $bot_id = isset($attributes['botId']) ? absint($attributes['botId']) : 0;
        if ($bot_id <= 0) {
            return '';
        }

        return do_shortcode(sprintf('[aipkit_chatbot id=%d]', $bot_id));
    }

    /**
     * Render callback for the AI Form block.
     *
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_ai_form_block(array $attributes): string
    {
        $form_id = isset($attributes['formId']) ? absint($attributes['formId']) : 0;
        if ($form_id <= 0) {
            return '';
        }

        $shortcode = sprintf('[aipkit_ai_form id=%d', $form_id);
        $theme = isset($attributes['theme']) ? sanitize_key((string) $attributes['theme']) : 'light';
        if (in_array($theme, ['dark', 'custom'], true)) {
            $shortcode .= sprintf(' theme="%s"', $theme);
        }

        foreach ([
            'showProvider' => 'show_provider',
            'showModel' => 'show_model',
            'saveButton' => 'save_button',
            'pdfDownload' => 'pdf_download',
            'copyButton' => 'copy_button',
        ] as $attribute_key => $shortcode_key) {
            if (!empty($attributes[$attribute_key])) {
                $shortcode .= sprintf(' %s="true"', $shortcode_key);
            }
        }

        $shortcode .= ']';

        return do_shortcode($shortcode);
    }

    /**
     * Render callback for the image generator block.
     *
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_image_generator_block(array $attributes): string
    {
        $mode = isset($attributes['mode']) ? sanitize_key((string) $attributes['mode']) : 'generate';
        if (!in_array($mode, ['generate', 'edit', 'both'], true)) {
            $mode = 'generate';
        }

        $theme = isset($attributes['theme']) ? sanitize_key((string) $attributes['theme']) : 'light';
        if (!in_array($theme, ['light', 'dark', 'custom'], true)) {
            $theme = 'light';
        }

        $font = isset($attributes['font']) ? sanitize_key((string) $attributes['font']) : 'system';
        if (!in_array($font, ['theme', 'system'], true)) {
            $font = 'system';
        }

        if (array_key_exists('allowModelSelection', $attributes)) {
            $allow_model_selection = !empty($attributes['allowModelSelection']);
            $show_provider = $allow_model_selection;
            $show_model = $allow_model_selection;
        } else {
            // Preserve mixed visibility states saved by older Image Generator blocks.
            $show_provider = !array_key_exists('showProvider', $attributes) || !empty($attributes['showProvider']);
            $show_model = !array_key_exists('showModel', $attributes) || !empty($attributes['showModel']);
        }
        $show_history = !empty($attributes['history']);

        $shortcode = '[aipkit_image_generator';

        if (!$show_provider) {
            $shortcode .= ' show_provider="false"';
        }
        if (!$show_model) {
            $shortcode .= ' show_model="false"';
        }
        if ($show_history) {
            $shortcode .= ' history="true"';
        }
        if ($mode !== 'generate') {
            $shortcode .= sprintf(' mode="%s"', $mode);
        }
        if ($mode === 'both') {
            $default_mode = isset($attributes['defaultMode'])
                ? sanitize_key((string) $attributes['defaultMode'])
                : 'generate';
            if ($default_mode === 'edit') {
                $shortcode .= ' default_mode="edit"';
            }
            if (array_key_exists('showModeSwitch', $attributes) && empty($attributes['showModeSwitch'])) {
                $shortcode .= ' show_mode_switch="false"';
            }
        }
        if ($theme !== 'light') {
            $shortcode .= sprintf(' theme="%s"', $theme);
        }
        if ($font !== 'system') {
            $shortcode .= sprintf(' font="%s"', $font);
        }

        $shortcode .= ']';

        return do_shortcode($shortcode);
    }

    /**
     * @return array<string, mixed>
     */
    private function get_block_editor_data(): array
    {
        $chatbot_access = AIPKit_Role_Manager::user_can_access_module('chatbot');
        $ai_forms_access = AIPKit_Role_Manager::user_can_access_module('ai-forms');
        $image_generator_access = AIPKit_Role_Manager::user_can_access_module('image-generator');

        $module_settings = class_exists(aipkit_dashboard::class) ? aipkit_dashboard::get_module_settings() : [];

        return [
            'logoUrl' => WPAICG_LOGO_URL,
            'chatbots' => $chatbot_access ? $this->get_post_options(AdminSetup::class, ['publish', 'draft']) : [],
            'forms' => $ai_forms_access ? $this->get_post_options(AIPKit_AI_Form_Admin_Setup::class, ['publish']) : [],
            'access' => [
                'chatbot' => $chatbot_access,
                'aiForms' => $ai_forms_access,
                'imageGenerator' => $image_generator_access,
            ],
            'modules' => [
                'imageGeneratorEnabled' => !empty($module_settings['image_generator']),
            ],
            'isProPlan' => class_exists(aipkit_dashboard::class) && aipkit_dashboard::is_pro_plan(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_post_options(string $setup_class, array $statuses): array
    {
        if (!class_exists($setup_class)) {
            return [];
        }

        $posts = get_posts([
            'post_type' => $setup_class::POST_TYPE,
            'post_status' => $statuses,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $options = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $options[] = [
                'id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'status' => (string) $post->post_status,
            ];
        }

        return $options;
    }

    private function is_script_localized(string $handle, string $object_name): bool
    {
        $scripts = wp_scripts();
        if (!$scripts) {
            return false;
        }

        $data = $scripts->get_data($handle, 'data');

        return is_string($data) && strpos($data, "var {$object_name} =") !== false;
    }
}
