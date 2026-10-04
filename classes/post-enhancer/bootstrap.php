<?php


namespace WPAICG\PostEnhancer;

use WPAICG\AIPKit_Role_Manager; // To check module access permissions
use WPAICG\Utils\AIPKit_Admin_Header_Action_Buttons; // Shared header button injector

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Core class for the Content Enhancer module.
 * Handles hooking into WordPress actions and filters.
 */
class Core
{
    public const BULK_ASSISTANT_MODULE = 'bulk_assistant';
    public const ROW_ASSISTANT_MODULE = 'row_assistant';
    public const CLASSIC_EDITOR_ASSISTANT_MODULE = 'classic_editor_assistant';
    public const BLOCK_EDITOR_ASSISTANT_MODULE = 'block_editor_assistant';

    /** @var bool */
    private $list_screen_hooks_registered = false;

    /** @var array<int, string> */
    private $list_screen_post_types = [];

    /**
     * Registers hooks.
     */
    public function init_hooks()
    {
        // Ensure AJAX handler class is loaded
        $ajax_handler_path = WPAICG_PLUGIN_DIR . 'classes/post-enhancer/actions.php';
        if (file_exists($ajax_handler_path)) {
            require_once $ajax_handler_path;
            // Register AJAX actions if class exists
            if (class_exists('\\WPAICG\\PostEnhancer\\AjaxHandler')) {
                $ajax_handler = new AjaxHandler();
                // Title actions
                add_action('wp_ajax_aipkit_generate_title_suggestions', [$ajax_handler, 'generate_title_suggestions']);
                add_action('wp_ajax_aipkit_update_post_title', [$ajax_handler, 'update_post_title']);
                // Excerpt actions
                add_action('wp_ajax_aipkit_generate_excerpt_suggestions', [$ajax_handler, 'generate_excerpt_suggestions']);
                add_action('wp_ajax_aipkit_update_post_excerpt', [$ajax_handler, 'update_post_excerpt']);
                // Meta Description actions
                add_action('wp_ajax_aipkit_generate_meta_suggestions', [$ajax_handler, 'generate_meta_suggestions']);
                add_action('wp_ajax_aipkit_update_post_meta_desc', [$ajax_handler, 'update_post_meta_desc']);
                add_action('wp_ajax_aipkit_generate_tags_suggestions', [$ajax_handler, 'generate_tags_suggestions']);
                add_action('wp_ajax_aipkit_update_post_tags', [$ajax_handler, 'update_post_tags']);
                add_action('wp_ajax_aipkit_bulk_process_single_field', [$ajax_handler, 'ajax_bulk_process_single_field']);
                add_action('wp_ajax_aipkit_bulk_update_seo_slug', [$ajax_handler, 'ajax_bulk_update_seo_slug']);
                add_action('wp_ajax_aipkit_process_enhancer_text', [$ajax_handler, 'ajax_process_enhancer_text']);
                add_action('wp_ajax_aipkit_enhancer_request_status', [$ajax_handler, 'ajax_request_status']);
            }
        }

        if (did_action('admin_init')) {
            $this->register_list_screen_hooks();
        } else {
            add_action('admin_init', [$this, 'register_list_screen_hooks'], 1);
        }

        $aipkit_options = get_option('aipkit_options', []);
        $enhancer_editor_integration_enabled = $aipkit_options['enhancer_settings']['editor_integration'] ?? '1';

        if ($enhancer_editor_integration_enabled === '1') {
            add_action('admin_init', [$this, 'setup_tinymce_button']);
            add_action('admin_init', [$this, 'setup_block_editor_button']);
        }
    }

    public function register_list_screen_hooks()
    {
        if ($this->list_screen_hooks_registered) {
            return;
        }
        $this->list_screen_hooks_registered = true;

        $public_post_types = get_post_types(['public' => true]);
        unset($public_post_types['attachment']);
        $post_types = apply_filters('aipkit_post_enhancer_post_types', array_keys($public_post_types));
        $post_types = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $post_types))));
        $this->list_screen_post_types = $post_types;
        add_filter('post_row_actions', [$this, 'add_row_actions'], 10, 2);
        add_filter('page_row_actions', [$this, 'add_row_actions'], 10, 2);
        $aipkit_options = get_option('aipkit_options', []);
        $enhancer_list_button_enabled = $aipkit_options['enhancer_settings']['show_list_button'] ?? '1';

        // Register Assistant button via shared utility (list screens)
        if ($enhancer_list_button_enabled === '1') {
            AIPKit_Admin_Header_Action_Buttons::register_button(
                'aipkit_bulk_enhance_btn',
                'Assistant',
                [
                    'post_types' => $post_types,
                    'access_callback' => [__CLASS__, 'current_user_can_access_bulk_button'],
                    'label_callback' => static function (): string {
                        return __('Assistant', 'gpt3-ai-content-generator');
                    },
                ]
            );
        }
    }

    public static function current_user_can_access_bulk_button(string $post_type): bool
    {
        if (!self::current_user_can_edit_post_type($post_type)) {
            return false;
        }

        return AIPKit_Role_Manager::user_can_access_module(self::BULK_ASSISTANT_MODULE);
    }

    private static function current_user_can_edit_post_type(string $post_type): bool
    {
        $post_type_object = get_post_type_object($post_type);
        $capability = 'edit_posts';

        if ($post_type_object && isset($post_type_object->cap->edit_posts)) {
            $capability = (string) $post_type_object->cap->edit_posts;
        }

        return current_user_can($capability);
    }

    /**
     * Adds the Assistant dropdown action to post row actions.
     *
     * @param array $actions Existing actions.
     * @param \WP_Post $post The post object.
     * @return array Modified actions.
     */
    public function add_row_actions($actions, $post)
    {
        $aipkit_options = get_option('aipkit_options', []);
        $enhancer_list_button_enabled = $aipkit_options['enhancer_settings']['show_list_button'] ?? '1';
        if (!in_array((string) $post->post_type, $this->list_screen_post_types, true)) {
            return $actions;
        }

        if ($enhancer_list_button_enabled !== '1') {
            return $actions;
        }

        // Check if user has permission for the utility AND can edit this post
        if (
            AIPKit_Role_Manager::user_can_access_module(self::ROW_ASSISTANT_MODULE) &&
            current_user_can('edit_post', $post->ID)
        ) {
            // --- Main Enhancer Action with Dropdown ---
            $actions['aipkit_ai_enhance'] = sprintf(
                '<span class="aipkit_enhancer_action">
                    <a href="#" class="aipkit_enhancer_trigger" aria-label="%s"><span class="aipkit_assistant_symbol" aria-hidden="true"></span><span>%s</span></a>
                    <div class="aipkit_enhancer_popover" role="menu">
                        <div class="aipkit_enhancer_group">
                            <div class="aipkit_enhancer_group_title">%s</div>
                            <ul>
                                <li><button type="button" class="aipkit_enhancer_item" data-action-type="title" data-post-id="%d" role="menuitem"><span class="dashicons dashicons-heading" aria-hidden="true"></span><span>%s</span></button></li>
                                <li><button type="button" class="aipkit_enhancer_item" data-action-type="excerpt" data-post-id="%d" role="menuitem"><span class="dashicons dashicons-editor-alignleft" aria-hidden="true"></span><span>%s</span></button></li>
                            </ul>
                        </div>
                        <div class="aipkit_enhancer_group">
                            <div class="aipkit_enhancer_group_title">%s</div>
                            <ul>
                                <li><button type="button" class="aipkit_enhancer_item" data-action-type="meta" data-post-id="%d" role="menuitem"><span class="dashicons dashicons-media-document" aria-hidden="true"></span><span>%s</span></button></li>
                                <li><button type="button" class="aipkit_enhancer_item" data-action-type="tags" data-post-id="%d" role="menuitem"><span class="dashicons dashicons-tag" aria-hidden="true"></span><span>%s</span></button></li>
                            </ul>
                        </div>
                    </div>
                 </span>',
                esc_attr__('Update content using AI', 'gpt3-ai-content-generator'), // aria-label for trigger
                esc_html__('Assistant', 'gpt3-ai-content-generator'), // Link text for trigger
                esc_html__('Content', 'gpt3-ai-content-generator'), // Group Title
                esc_attr($post->ID),
                esc_html__('Generate title', 'gpt3-ai-content-generator'),
                esc_attr($post->ID),
                esc_html__('Generate excerpt', 'gpt3-ai-content-generator'),
                esc_html__('SEO', 'gpt3-ai-content-generator'), // Group Title
                esc_attr($post->ID),
                esc_html__('Generate meta description', 'gpt3-ai-content-generator'),
                esc_attr($post->ID),
                esc_html__('Generate tags', 'gpt3-ai-content-generator')
            );
        }
        return $actions;
    }

    /**
     * Set up hooks for TinyMCE button if user has permission.
     * Hooked to `admin_init`.
     */
    public function setup_tinymce_button()
    {
        if (
            AIPKit_Role_Manager::user_can_access_module(self::CLASSIC_EDITOR_ASSISTANT_MODULE) &&
            (current_user_can('edit_posts') || current_user_can('edit_pages')) &&
            get_user_option('rich_editing') === 'true'
        ) {
            add_filter('mce_buttons', [$this, 'register_tinymce_button']);
            add_filter('mce_external_plugins', [$this, 'add_tinymce_plugin']);
        }
    }

    /**
     * Register the button with TinyMCE.
     * @param array $buttons The array of existing buttons.
     * @return array The modified array of buttons.
     */
    public function register_tinymce_button($buttons)
    {
        array_push($buttons, 'aipkit_assistant_button');
        return $buttons;
    }

    /**
     * Add the JavaScript plugin to TinyMCE.
     * @param array $plugin_array The array of external plugins.
     * @return array The modified array of external plugins.
     */
    public function add_tinymce_plugin($plugin_array)
    {
        $plugin_array['aipkit_assistant'] = WPAICG_PLUGIN_URL . 'dist/js/admin-enhancer-tinymce.bundle.js';
        return $plugin_array;
    }

    /**
     * Set up hooks for Block Editor button if user has permission.
     * Hooked to `admin_init`.
     */
    public function setup_block_editor_button()
    {
        if (
            AIPKit_Role_Manager::user_can_access_module(self::BLOCK_EDITOR_ASSISTANT_MODULE) &&
            (current_user_can('edit_posts') || current_user_can('edit_pages'))
        ) {
            add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_plugin_script']);
        }
    }

    /**
     * Enqueue the JavaScript for the Block Editor plugin.
     */
    public function enqueue_block_editor_plugin_script()
    {
        $screen = get_current_screen();
        if (!$screen || !method_exists($screen, 'is_block_editor') || !$screen->is_block_editor()) {
            return;
        }

        $script_handle = 'aipkit-enhancer-block-editor';
        $script_path = 'dist/js/admin-enhancer-block-editor.bundle.js';

        $dependencies = ['wp-i18n', 'wp-element', 'wp-rich-text', 'wp-components', 'wp-block-editor', 'wp-blocks', 'wp-data', 'wp-hooks'];
        $version = WPAICG_VERSION;

        wp_enqueue_script(
            $script_handle,
            WPAICG_PLUGIN_URL . $script_path,
            $dependencies,
            $version,
            true // in footer
        );
    }
}
