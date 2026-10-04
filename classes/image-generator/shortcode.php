<?php

namespace WPAICG\Shortcodes;

use WPAICG\aipkit_dashboard; // To check module status
use WPAICG\AIPKit_Role_Manager; // To check permissions
use WPAICG\AIPKit_Providers; // To get default provider if needed
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler; // Use settings handler
use WP_Query; // For image history

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Image_Generator_Shortcode
 *
 * Handles the rendering of the [aipkit_image_generator] shortcode.
 * REVISED: Adjusted permission check to allow rendering for guests.
 *          AJAX handler will enforce usage limits/restrictions.
 * UPDATED: Added history attribute and rendering logic.
 */
class AIPKit_Image_Generator_Shortcode
{
    public const FAVORITE_META_KEY = '_aipkit_media_favorite';
    private const HISTORY_PAGE_SIZE = 20;

    private static $current_atts = [];
    /**
     * Render the shortcode output.
     *
     * @param array $atts Shortcode attributes.
     *        Supported attributes:
     *        - show_provider (bool|string 'true'/'false', default true)
     *        - show_model (bool|string 'true'/'false', default true)
     *        - provider (string 'openai', 'azure' etc. - presets the provider)
     *        - model (string - presets the provider model ID)
     *        - size (string '1024x1024' etc. - presets the size)
     *        - number (int 1-4 - presets the number)
     *        - theme (string 'light', 'dark', 'custom', default 'light')
     *        - font (string 'theme'|'system', default 'system')
     *        - history (bool|string 'true'/'false', default 'false')
     *        - mode (string 'generate'|'edit'|'both', default 'generate')
     *        - default_mode (string 'generate'|'edit', default 'generate'; legacy compatibility for mode='both')
     *        - show_mode_switch (bool|string 'true'/'false', default 'true'; legacy compatibility for mode='both')
     *
     * @return string HTML output.
     */
    public function render_shortcode($atts = [])
    {
        // 0. Store attributes for localization
        self::$current_atts = shortcode_atts([
            'allowed_models' => null,
        ], $atts, 'aipkit_image_generator');

        // 1. Check if the main module is active
        $module_settings = aipkit_dashboard::get_module_settings();
        if (empty($module_settings['image_generator'])) {
            if (AIPKit_Role_Manager::user_can_view_admin_notices()) {
                return '<p style="color:orange;"><em>[' . esc_html__('AIPKit Image Generator Shortcode: Module is disabled in settings.', 'gpt3-ai-content-generator') . ']</em></p>';
            }
            return '';
        }

        // --- 2.5 Get Default Image Settings ---
        $image_gen_settings = AIPKit_Image_Settings_Ajax_Handler::get_settings();
        $frontend_display_settings = $image_gen_settings['frontend_display'] ?? [];
        $ui_text_settings = $image_gen_settings['ui_text'] ?? [];
        $custom_css = $image_gen_settings['common']['custom_css'] ?? '';
        $allowed_models_from_settings = $frontend_display_settings['allowed_models'] ?? '';

        // Prioritize shortcode attribute over global settings
        $final_allowed_models_str = self::$current_atts['allowed_models'] ?? $allowed_models_from_settings;
        // --- End Get Settings ---

        // 3. Parse Attributes
        $default_atts = [
            'show_provider' => 'true',
            'show_model'    => 'true',
            'provider'      => 'openai',
            'model'         => AIPKit_Providers::get_default_openai_image_model(),
            'size'          => '1024x1024',
            'number'        => 1,
            'theme'         => 'light',
            'font'          => 'system',
            'history'       => 'false',
            'mode'          => 'generate',
            'default_mode'  => 'generate',
            'show_mode_switch' => 'true',
        ];
        $atts = shortcode_atts($default_atts, $atts, 'aipkit_image_generator');

        $show_provider = filter_var($atts['show_provider'], FILTER_VALIDATE_BOOLEAN);
        $show_model    = filter_var($atts['show_model'], FILTER_VALIDATE_BOOLEAN);
        $show_history  = filter_var($atts['history'], FILTER_VALIDATE_BOOLEAN); // NEW

        $mode = sanitize_key($atts['mode'] ?? 'generate');
        $allowed_modes = ['generate', 'edit', 'both'];
        if (!in_array($mode, $allowed_modes, true)) {
            $mode = 'generate';
        }

        $default_mode = sanitize_key($atts['default_mode'] ?? 'generate');
        if (!in_array($default_mode, ['generate', 'edit'], true)) {
            $default_mode = 'generate';
        }
        $show_mode_switch = filter_var($atts['show_mode_switch'], FILTER_VALIDATE_BOOLEAN);
        if ($mode !== 'both') {
            $default_mode = $mode;
        } elseif (!$show_mode_switch) {
            // Old fixed-mode embeds used mode="both" with the switch hidden.
            $mode = $default_mode;
        }

        $preset_provider_from_att = !empty($atts['provider']) ? strtolower(sanitize_text_field($atts['provider'])) : null;
        $preset_model    = !empty($atts['model']) ? sanitize_text_field($atts['model']) : null;
        $preset_size     = !empty($atts['size']) ? sanitize_text_field($atts['size']) : null;
        $preset_number   = !empty($atts['number']) ? absint($atts['number']) : null;
        $valid_themes = ['light', 'dark', 'custom'];
        $theme = isset($atts['theme']) && in_array(strtolower($atts['theme']), $valid_themes, true)
                 ? strtolower($atts['theme'])
                 : 'light';
        $valid_font_modes = ['theme', 'system'];
        $font_mode = isset($atts['font']) && in_array(strtolower($atts['font']), $valid_font_modes, true)
            ? strtolower($atts['font'])
            : 'system';

        // --- 4. Determine Final Values ---
        $final_provider_key = $preset_provider_from_att ?? 'openai';
        $final_provider_normalized = AIPKit_Providers::normalize_provider_label($final_provider_key);
        $final_model = $preset_model;
        $final_size = $preset_size;
        $final_number = $preset_number;
        // --- End Determine Final Values ---

        // 5. Signal assets needed
        add_filter('aipkit_enqueue_public_image_generator_assets', '__return_true');

        // 6. Prepare data for the view
        $view_data = [
            'selection_policy' => \WPAICG\Images\AIPKit_Image_Manager::sign_public_policy([
                'provider' => $show_provider ? '' : $final_provider_normalized,
                'model' => !$show_model && !$show_provider ? $final_model : '',
                'mode' => $mode,
                'allowed' => self::$current_atts['allowed_models'],
                'n' => max(1, min(10, (int) $final_number)),
                'size' => $final_size,
            ]),
            'nonce' => wp_create_nonce('aipkit_image_generator_nonce'),
            'show_provider' => $show_provider,
            'show_model'    => $show_model,
            'preset_provider' => $preset_provider_from_att ? $final_provider_normalized : null,
            'preset_model'    => $preset_model,
            'preset_size'     => $preset_size,
            'preset_number'   => $preset_number,
            'final_provider' => $final_provider_normalized,
            'final_model'    => $final_model,
            'final_size'     => $final_size,
            'final_number'   => $final_number,
            'theme'          => $theme,
            'font_mode'      => $font_mode,
            'show_history'   => $show_history, // NEW: Pass to view
            'image_history_html' => ($show_history && is_user_logged_in()) ? $this->render_image_history($mode) : '', // NEW: Render history HTML
            'allowed_models' => $final_allowed_models_str,
            'mode'           => $mode,
            'initial_mode'   => $default_mode,
            'ui_text'        => $ui_text_settings,
            'custom_css'     => is_string($custom_css) ? $custom_css : '',
        ];

        // 7. Include the partial view
        ob_start();
        extract($view_data);
        $view_path = WPAICG_PLUGIN_DIR . 'public/views/image-generator.php';
        if (file_exists($view_path)) {
            include $view_path;
        } else {
            echo '<p style="color:red;">Image Generator UI cannot be loaded.</p>';
        }
        return ob_get_clean();
    }

    public static function get_current_attributes()
    {
        return self::$current_atts;
    }

    public static function normalize_history_filter(string $filter): string
    {
        return $filter === 'favorites' ? 'favorites' : 'all';
    }

    public static function build_history_query_args(int $user_id, int $page = 1, string $filter = 'all'): array
    {
        $generated_media_query = [
            'relation' => 'OR',
            [
                'key'     => '_aipkit_generated_image',
                'value'   => '1',
                'compare' => '=',
            ],
            [
                'key'     => '_aipkit_generated_video',
                'value'   => '1',
                'compare' => '=',
            ],
        ];
        $filter = self::normalize_history_filter($filter);
        $meta_query = $generated_media_query;
        if ($filter === 'favorites') {
            $meta_query = [
                'relation' => 'AND',
                $generated_media_query,
                [
                    'key'     => self::FAVORITE_META_KEY,
                    'value'   => '1',
                    'compare' => '=',
                ],
            ];
        }

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'author'         => $user_id,
            'posts_per_page' => self::HISTORY_PAGE_SIZE,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- History must filter plugin-generated media by stored generation flags.
            'meta_query'     => $meta_query,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ($page > 1) {
            $args['paged'] = $page;
        }

        return $args;
    }

    private static function render_history_favorite_button(int $attachment_id): string
    {
        if (!is_user_logged_in()) {
            return '';
        }

        $is_favorite = get_post_meta($attachment_id, self::FAVORITE_META_KEY, true) === '1';
        $label = $is_favorite
            ? __('Remove from favorites', 'gpt3-ai-content-generator')
            : __('Add to favorites', 'gpt3-ai-content-generator');

        ob_start();
        ?>
        <button
            type="button"
            class="aipkit-image-history-favorite-btn<?php echo $is_favorite ? ' is-favorite' : ''; ?>"
            data-attachment-id="<?php echo esc_attr($attachment_id); ?>"
            aria-label="<?php echo esc_attr($label); ?>"
            aria-pressed="<?php echo $is_favorite ? 'true' : 'false'; ?>"
            title="<?php echo esc_attr($label); ?>"
        >
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 17.75 5.83 21l1.18-6.88L2 9.25l6.91-1L12 2l3.09 6.25 6.91 1-5 4.87L18.17 21 12 17.75Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
        </button>
        <?php
        return ob_get_clean();
    }

    private static function render_history_overflow_menu(
        int $attachment_id,
        bool $allow_edit_action,
        bool $is_video
    ): string {
        $menu_id = 'aipkit-image-history-menu-' . $attachment_id;
        $media_label = $is_video ? __('video', 'gpt3-ai-content-generator') : __('image', 'gpt3-ai-content-generator');
        /* translators: %s: media type, image or video. */
        $more_label = sprintf(__('More actions for this %s', 'gpt3-ai-content-generator'), $media_label);
        /* translators: %s: media type, image or video. */
        $delete_label = sprintf(__('Delete %s', 'gpt3-ai-content-generator'), $media_label);

        ob_start();
        ?>
        <button
            type="button"
            class="aipkit-image-history-more-btn"
            aria-label="<?php echo esc_attr($more_label); ?>"
            title="<?php echo esc_attr($more_label); ?>"
            aria-haspopup="menu"
            aria-expanded="false"
            aria-controls="<?php echo esc_attr($menu_id); ?>"
            data-aipkit-history-more
        >
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="5" cy="12" r="1.45" fill="currentColor"/><circle cx="12" cy="12" r="1.45" fill="currentColor"/><circle cx="19" cy="12" r="1.45" fill="currentColor"/></svg>
        </button>
        <div
            class="aipkit-image-history-menu"
            id="<?php echo esc_attr($menu_id); ?>"
            role="menu"
            aria-label="<?php echo esc_attr($more_label); ?>"
            data-aipkit-history-menu
            hidden
        >
            <button
                type="button"
                class="aipkit-image-history-menu-item"
                role="menuitem"
                data-aipkit-history-action="download"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><?php esc_html_e('Download', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <?php if ($allow_edit_action && !$is_video): ?>
                <button
                    type="button"
                    class="aipkit-image-history-menu-item"
                    role="menuitem"
                    data-aipkit-history-action="edit"
                >
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m14.5 5.5 4 4M4 20l4.1-1 10.4-10.4a2.12 2.12 0 0 0-3-3L5.1 16 4 20Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><?php esc_html_e('Use as edit source', 'gpt3-ai-content-generator'); ?></span>
                </button>
            <?php endif; ?>
            <button
                type="button"
                class="aipkit-image-history-menu-item aipkit-image-history-menu-item--danger"
                role="menuitem"
                data-aipkit-history-action="delete"
                data-label="<?php echo esc_attr($delete_label); ?>"
                data-confirm-label="<?php esc_attr_e('Click again to delete', 'gpt3-ai-content-generator'); ?>"
                aria-label="<?php echo esc_attr($delete_label); ?>"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16m-10 4v5m4-5v5M9 7l1-3h4l1 3m3 0-1 13H7L6 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><?php echo esc_html($delete_label); ?></span>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_history_item(int $attachment_id, bool $allow_edit_action): string
    {
        $full_url = wp_get_attachment_url($attachment_id);
        $is_video = get_post_meta($attachment_id, '_aipkit_generated_video', true) === '1';
        $is_image = get_post_meta($attachment_id, '_aipkit_generated_image', true) === '1';

        ob_start();
        if ($is_video) {
            $prompt = get_post_meta($attachment_id, '_aipkit_video_prompt', true);
            $provider = get_post_meta($attachment_id, '_aipkit_video_provider', true);
            $model = get_post_meta($attachment_id, '_aipkit_video_model', true);
            $size = get_post_meta($attachment_id, '_aipkit_video_size', true);
            $duration = get_post_meta($attachment_id, '_aipkit_video_duration', true);
            $video_url_path = wp_parse_url((string) $full_url, PHP_URL_PATH);
            $video_file_name = is_string($video_url_path) && $video_url_path !== ''
                ? sanitize_file_name(wp_basename($video_url_path))
                : 'video-' . $attachment_id . '.mp4';
            /* translators: %d: duration in seconds */
            $duration_display = $duration ? sprintf(__('Duration: %ds', 'gpt3-ai-content-generator'), intval($duration)) : '';
            ?>
            <article
                class="aipkit-image-history-item aipkit-video-history-item"
                data-attachment-id="<?php echo esc_attr($attachment_id); ?>"
                data-media-url="<?php echo esc_url($full_url); ?>"
                data-media-name="<?php echo esc_attr($video_file_name); ?>"
            >
                <div class="aipkit-video-preview">
                    <video controls preload="metadata">
                        <source src="<?php echo esc_url($full_url); ?>" type="video/mp4">
                        <?php esc_html_e('Your browser does not support the video tag.', 'gpt3-ai-content-generator'); ?>
                    </video>
                    <div class="aipkit-video-overlay">
                        <span class="aipkit-media-type-badge"><?php esc_html_e('VIDEO', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                </div>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper emits escaped plugin markup.
                echo self::render_history_favorite_button($attachment_id);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper emits escaped plugin markup.
                echo self::render_history_overflow_menu($attachment_id, false, true);
                ?>
                <div class="aipkit-image-history-info">
                    <?php if ($prompt): ?>
                        <p class="aipkit-image-history-prompt" title="<?php echo esc_attr($prompt); ?>">
                            <strong><?php esc_html_e('Prompt:', 'gpt3-ai-content-generator'); ?></strong> <?php echo esc_html(wp_trim_words($prompt, 10, '...')); ?>
                        </p>
                    <?php endif; ?>
                    <p class="aipkit-image-history-meta">
                        <?php
                        $meta_parts = array_filter([$provider, $model, $size, $duration_display]);
                        echo esc_html(implode(' / ', $meta_parts));
                        ?>
                    </p>
                </div>
            </article>
            <?php
        } elseif ($is_image) {
            $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            $prompt = get_post_meta($attachment_id, '_aipkit_image_prompt', true);
            $provider = get_post_meta($attachment_id, '_aipkit_image_provider', true);
            $model = get_post_meta($attachment_id, '_aipkit_image_model', true);
            $provider_name = AIPKit_Providers::get_provider_display_name((string) $provider);
            $model_name = \WPAICG\Core\Models\AIPKit_Model_Registry::get_model_display_name((string) $provider, (string) $model);
            // Providers may return a different size from the one requested. Reading
            // attachment metadata also corrects history for images saved before this fix.
            $image_metadata = wp_get_attachment_metadata($attachment_id);
            $size = !empty($image_metadata['width']) && !empty($image_metadata['height'])
                ? (int) $image_metadata['width'] . 'x' . (int) $image_metadata['height']
                : '';
            $image_url_path = wp_parse_url((string) $full_url, PHP_URL_PATH);
            $image_file_name = is_string($image_url_path) && $image_url_path !== ''
                ? sanitize_file_name(wp_basename($image_url_path))
                : 'image-' . $attachment_id . '.png';
            $thumbnail_alt = sprintf(
                /* translators: %s: image generation prompt. */
                __('Generated image, prompt: %s', 'gpt3-ai-content-generator'),
                wp_html_excerpt((string) $prompt, 260, '…')
            );
            $expand_label = sprintf(
                /* translators: %s: image generation prompt. */
                __('Expand generated image: %s', 'gpt3-ai-content-generator'),
                wp_html_excerpt((string) $prompt, 120, '…')
            );
            $model_label = implode(' / ', array_filter([$provider_name, $model_name]));
            ?>
            <article
                class="aipkit-image-history-item"
                data-attachment-id="<?php echo esc_attr($attachment_id); ?>"
                data-media-url="<?php echo esc_url($full_url); ?>"
                data-media-name="<?php echo esc_attr($image_file_name); ?>"
                data-prompt="<?php echo esc_attr($prompt); ?>"
                data-model-label="<?php echo esc_attr($model_label); ?>"
                data-edit-allowed="<?php echo $allow_edit_action ? '1' : '0'; ?>"
            >
                <div class="aipkit-image-history-thumbnail">
                    <button
                        type="button"
                        class="aipkit-image-history-view-trigger"
                        data-aipkit-history-view
                        aria-label="<?php echo esc_attr($expand_label); ?>"
                    >
                    <?php // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Reason: The image source is correctly retrieved using a WordPress function. ?>
                        <img src="<?php echo esc_url($thumbnail_url ?: $full_url); ?>" alt="<?php echo esc_attr($thumbnail_alt); ?>" loading="lazy" decoding="async">
                        <span class="aipkit-image-history-expand-cue" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M9 4H4v5m11-5h5v5M9 20H4v-5m11 5h5v-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </button>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper emits escaped plugin markup.
                echo self::render_history_favorite_button($attachment_id);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper emits escaped plugin markup.
                echo self::render_history_overflow_menu($attachment_id, $allow_edit_action, false);
                ?>
                </div>
                <div class="aipkit-image-history-info">
                    <?php if ($prompt): ?>
                        <p class="aipkit-image-history-prompt" title="<?php echo esc_attr($prompt); ?>">
                            <strong><?php esc_html_e('Prompt:', 'gpt3-ai-content-generator'); ?></strong> <?php echo esc_html($prompt); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($model): ?>
                         <p class="aipkit-image-history-meta">
                            <?php echo esc_html(implode(' / ', array_filter([$model_label, $size]))); ?>
                         </p>
                    <?php endif; ?>
                </div>
            </article>
            <?php
        }

        return ob_get_clean();
    }

    /**
     * Renders the HTML for the user's image generation history.
     *
     * @return string HTML for the image history section.
     */
    private function render_image_history(string $shortcode_mode = 'generate'): string
    {
        if (!is_user_logged_in()) {
            return '';
        }

        $allow_edit_action = in_array($shortcode_mode, ['edit', 'both'], true);

        $user_id = get_current_user_id();
        $query = new WP_Query(self::build_history_query_args($user_id));
        $has_items = $query->have_posts();

        ob_start();
        ?>
        <div class="aipkit-image-history-grid" data-aipkit-history-grid<?php echo $has_items ? '' : ' hidden'; ?>>
            <?php while ($query->have_posts()) : $query->the_post();
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_history_item() returns plugin-generated markup with escaped dynamic values.
                echo self::render_history_item((int) get_the_ID(), $allow_edit_action);
            endwhile; ?>
        </div>
        <p class="aipkit-image-history-empty" data-aipkit-history-empty<?php echo $has_items ? ' hidden' : ''; ?>>
            <?php esc_html_e('Your generated media will appear here.', 'gpt3-ai-content-generator'); ?>
        </p>
        <div class="aipkit-image-history-load-more-container" data-aipkit-history-load-more<?php echo $query->max_num_pages > 1 ? '' : ' hidden'; ?>>
            <button
                type="button"
                class="aipkit-image-history-load-more-btn"
                data-current-page="1"
                data-max-pages="<?php echo esc_attr(max(1, (int) $query->max_num_pages)); ?>"
            >
                <span class="aipkit-image-history-load-more-label"><?php esc_html_e('Load more', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_spinner" hidden></span>
            </button>
        </div>
        <?php
        wp_reset_postdata();
        return ob_get_clean();
    }
}
