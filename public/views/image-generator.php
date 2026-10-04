<?php

/**
 * Partial View: Frontend Image Generator Shortcode UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables only.

$theme_class = 'aipkit-theme-' . esc_attr($theme);
$allowed_font_modes = ['theme', 'system'];
$resolved_font_mode = isset($font_mode) ? sanitize_key((string) $font_mode) : 'system';
if (!in_array($resolved_font_mode, $allowed_font_modes, true)) {
    $resolved_font_mode = 'system';
}
$font_class = 'aipkit-font-' . $resolved_font_mode;
$allowed_modes = ['generate', 'edit', 'both'];
$shortcode_mode = isset($mode) ? sanitize_key((string) $mode) : 'generate';
if (!in_array($shortcode_mode, $allowed_modes, true)) {
    $shortcode_mode = 'generate';
}

$edit_available = in_array($shortcode_mode, ['edit', 'both'], true);
$requested_initial_mode = isset($initial_mode) ? sanitize_key((string) $initial_mode) : 'generate';
$current_image_mode = $requested_initial_mode === 'edit' && $edit_available ? 'edit' : 'generate';

$ui_text_settings = isset($ui_text) && is_array($ui_text) ? $ui_text : [];
$get_ui_text = static function (string $key, string $default) use ($ui_text_settings): string {
    if (!isset($ui_text_settings[$key])) {
        return $default;
    }

    $value = sanitize_text_field((string) $ui_text_settings[$key]);
    return $value !== '' ? $value : $default;
};

$generate_label = $get_ui_text('generate_label', __('Generate', 'gpt3-ai-content-generator'));
$edit_label = $get_ui_text('edit_label', __('Edit Image', 'gpt3-ai-content-generator'));
$generate_placeholder = $get_ui_text('generate_placeholder', __('Describe your image…', 'gpt3-ai-content-generator'));
$edit_placeholder = $get_ui_text('edit_placeholder', __('Describe the edit…', 'gpt3-ai-content-generator'));
$history_title = $get_ui_text('history_title', __('Your Images', 'gpt3-ai-content-generator'));
$initial_prompt_placeholder = $current_image_mode === 'edit' ? $edit_placeholder : $generate_placeholder;
$initial_action_label = $current_image_mode === 'edit' ? $edit_label : $generate_label;
$show_model_picker = !empty($show_provider) || !empty($show_model);
$initial_picker_label = !empty($show_model)
    ? ((string) $final_model !== '' ? (string) $final_model : __('Select model', 'gpt3-ai-content-generator'))
    : (string) $final_provider;
$picker_class_names = ['aipkit_image_generator_model_picker'];
if (empty($show_provider)) {
    $picker_class_names[] = 'aipkit_image_generator_model_picker--provider-hidden';
}
if (empty($show_model)) {
    $picker_class_names[] = 'aipkit_image_generator_model_picker--model-hidden';
}

?>
<div
    class="aipkit_shortcode_container aipkit_image_generator_public_wrapper <?php echo esc_attr($theme_class); ?> <?php echo esc_attr($font_class); ?>"
    id="aipkit_public_image_generator"
    data-allowed-models="<?php echo esc_attr($allowed_models); ?>"
    data-selection-policy="<?php echo esc_attr($selection_policy); ?>"
    data-image-mode="<?php echo esc_attr($shortcode_mode); ?>"
    data-initial-image-mode="<?php echo esc_attr($current_image_mode); ?>"
    data-is-admin-preview="<?php echo is_admin() ? '1' : '0'; ?>"
    data-user-logged-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>"
    data-show-provider="<?php echo !empty($show_provider) ? '1' : '0'; ?>"
    data-show-model="<?php echo !empty($show_model) ? '1' : '0'; ?>"
    data-theme="<?php echo esc_attr($theme); ?>"
    data-font="<?php echo esc_attr($resolved_font_mode); ?>"
    data-generate-placeholder="<?php echo esc_attr($generate_placeholder); ?>"
    data-edit-placeholder="<?php echo esc_attr($edit_placeholder); ?>"
    data-generate-label="<?php echo esc_attr($generate_label); ?>"
    data-edit-label="<?php echo esc_attr($edit_label); ?>"
    data-edit-upload-required="<?php echo esc_attr(__('Please upload an image to edit.', 'gpt3-ai-content-generator')); ?>"
    data-edit-provider-unsupported="<?php echo esc_attr(__('Image editing is currently supported only for Google, OpenAI, OpenRouter, and xAI providers.', 'gpt3-ai-content-generator')); ?>"
    data-edit-model-unsupported="<?php echo esc_attr(__('Selected model does not support image editing.', 'gpt3-ai-content-generator')); ?>"
>
    <?php if ($theme === 'custom' && !empty($custom_css)) : ?>
        <style class="aipkit_image_generator_custom_theme_css">
            <?php echo wp_strip_all_tags($custom_css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Saved CSS is tag-stripped and intentionally emitted as CSS. ?>
        </style>
    <?php endif; ?>

    <div class="aipkit_shortcode_body">
        <div class="aipkit_image_generator_input_bar">
            <input type="hidden" id="aipkit_public_image_mode" name="image_mode" value="<?php echo esc_attr($current_image_mode); ?>">
            <input type="hidden" id="aipkit_public_image_provider" name="image_provider" value="<?php echo esc_attr($final_provider); ?>">
            <input type="hidden" id="aipkit_public_image_model" name="image_model" value="<?php echo esc_attr($final_model); ?>">

            <div class="aipkit_image_generator_composer">
                <?php if ($edit_available) : ?>
                    <div id="aipkit_public_image_attachment_chip" class="aipkit_image_attachment_chip" hidden>
                        <img
                            id="aipkit_public_image_edit_file_preview"
                            class="aipkit_image_attachment_thumbnail"
                            alt="<?php esc_attr_e('Selected source image preview', 'gpt3-ai-content-generator'); ?>"
                            hidden
                        >
                        <span id="aipkit_public_image_edit_file_name" class="aipkit_image_attachment_name"></span>
                        <button
                            type="button"
                            id="aipkit_public_image_edit_file_remove"
                            class="aipkit_image_attachment_remove"
                            aria-label="<?php esc_attr_e('Remove attached image', 'gpt3-ai-content-generator'); ?>"
                        >
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m6.5 6.5 7 7m0-7-7 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="aipkit_image_composer_pill" data-aipkit-image-composer-pill data-mode="<?php echo esc_attr($current_image_mode); ?>">
                    <?php if ($edit_available) : ?>
                        <button
                            type="button"
                            id="aipkit_public_image_attach_btn"
                            class="aipkit_image_composer_attach"
                            aria-label="<?php esc_attr_e('Attach an image to edit', 'gpt3-ai-content-generator'); ?>"
                            aria-controls="aipkit_public_image_edit_source_file"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="aipkit_image_composer_attach_icon" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 7l-6.5 6.5a1.5 1.5 0 0 0 3 3l6.5 -6.5a3 3 0 0 0 -6 -6l-6.5 6.5a4.5 4.5 0 0 0 9 9l6.5 -6.5" /></svg>
                        </button>
                        <input
                            type="file"
                            id="aipkit_public_image_edit_source_file"
                            name="source_image"
                            class="aipkit_image_edit_source_input"
                            accept="image/jpeg,image/png,image/webp,image/gif"
                            tabindex="-1"
                            aria-hidden="true"
                        >
                    <?php endif; ?>

                    <label class="aipkit_image_generator_sr_only" for="aipkit_public_image_prompt">
                        <?php esc_html_e('Image prompt', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <textarea
                        id="aipkit_public_image_prompt"
                        name="image_prompt"
                        class="aipkit_image_prompt_textarea"
                        rows="1"
                        placeholder="<?php echo esc_attr($initial_prompt_placeholder); ?>"
                    ></textarea>

                    <?php if ($show_model_picker) : ?>
                        <div class="aipkit_image_generator_model_control">
                            <select
                                id="aipkit_public_image_model_picker_source"
                                data-aipkit-unified-model-source
                                hidden
                                aria-hidden="true"
                                tabindex="-1"
                            ></select>
                            <?php
                            $aipkit_unified_model_selector_config = [
                                'trigger_id' => 'aipkit_public_image_model_picker_trigger',
                                'source_id' => 'aipkit_public_image_model_picker_source',
                                'initial_label' => $initial_picker_label,
                                'class_name' => implode(' ', $picker_class_names),
                                'capability' => 'image_generation',
                                'popover_placement' => 'auto',
                                'trigger_aria_label' => __('Choose image model', 'gpt3-ai-content-generator'),
                                'show_trigger_logo' => !empty($show_provider),
                                'context_label' => empty($show_provider)
                                    ? __('Model', 'gpt3-ai-content-generator')
                                    : (empty($show_model) ? __('Provider', 'gpt3-ai-content-generator') : ''),
                                'search_placeholder' => empty($show_model)
                                    ? __('Search providers...', 'gpt3-ai-content-generator')
                                    : __('Search image models...', 'gpt3-ai-content-generator'),
                                'empty_text' => empty($show_model)
                                    ? __('No providers available', 'gpt3-ai-content-generator')
                                    : __('No image models available', 'gpt3-ai-content-generator'),
                                'show_provider_diagnostics' => is_admin(),
                                'show_manage_link' => is_admin(),
                            ];
                            include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                            unset($aipkit_unified_model_selector_config);
                            ?>
                            <span
                                class="aipkit_image_generator_single_model"
                                data-aipkit-image-single-model
                                aria-label="<?php esc_attr_e('Selected image model', 'gpt3-ai-content-generator'); ?>"
                                hidden
                            >
                                <?php if (!empty($show_provider)) : ?>
                                    <span class="aipkit_unified_model_logo" data-aipkit-image-single-model-logo aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="aipkit_image_generator_single_model_name" data-aipkit-image-single-model-name></span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <button
                        type="button"
                        id="aipkit_public_generate_image_btn"
                        class="aipkit_image_generate_btn"
                        aria-label="<?php echo esc_attr($initial_action_label); ?>"
                        data-action-label="<?php echo esc_attr($initial_action_label); ?>"
                        disabled
                    >
                        <span class="aipkit_image_generate_btn_icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none"><path d="M10 14.5v-9m0 0L6.5 9M10 5.5 13.5 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="aipkit_spinner" hidden></span>
                    </button>
                </div>

                <?php if ($edit_available) : ?>
                    <span id="aipkit_public_image_edit_upload_feedback" class="aipkit_image_edit_upload_feedback" role="status" aria-live="polite" hidden></span>
                <?php endif; ?>
                <div id="aipkit_public_image_edit_mode_notice" class="aipkit_image_generator_edit_mode_notice" role="status" hidden></div>
            </div>
        </div>

        <section
            class="aipkit_image_generator_results"
            id="aipkit_public_image_results"
            data-state="empty"
            aria-label="<?php esc_attr_e('Generation result', 'gpt3-ai-content-generator'); ?>"
            aria-busy="false"
        >
            <div class="aipkit_image_result_shell" data-aipkit-result-shell>
                <div class="aipkit_image_result_actions aipkit_image_result_actions--overlay" data-aipkit-result-actions-overlay hidden></div>
            </div>
            <div class="aipkit_image_result_below">
                <div class="aipkit_image_result_actions aipkit_image_result_actions--touch" data-aipkit-result-actions-touch hidden></div>
                <div class="aipkit_image_result_pagination" data-aipkit-result-pagination hidden></div>
                <div class="aipkit_image_result_caption" data-aipkit-result-caption hidden></div>
            </div>
            <div class="aipkit_image_result_feedback" data-aipkit-result-feedback hidden></div>
            <div class="aipkit_image_generator_sr_only" data-aipkit-result-announcer role="status" aria-live="polite" aria-atomic="true"></div>
        </section>
        <input type="hidden" id="aipkit_image_generator_public_nonce" value="<?php echo esc_attr($nonce); ?>">

        <?php if (isset($show_history) && $show_history && is_user_logged_in()) : ?>
            <section
                class="aipkit_image_history_section"
                aria-labelledby="aipkit_image_history_title"
                data-aipkit-image-history
                data-filter="all"
                data-empty-all="<?php esc_attr_e('Your generated media will appear here.', 'gpt3-ai-content-generator'); ?>"
                data-empty-favorites="<?php esc_attr_e('You have not favorited any media yet.', 'gpt3-ai-content-generator'); ?>"
            >
                <div class="aipkit_image_history_heading">
                    <h3 class="aipkit_image_history_title" id="aipkit_image_history_title"><?php echo esc_html($history_title); ?></h3>
                    <div class="aipkit_image_history_filters" role="group" aria-label="<?php esc_attr_e('Filter history', 'gpt3-ai-content-generator'); ?>">
                        <button type="button" class="aipkit_image_history_filter is-active" data-aipkit-history-filter="all" aria-pressed="true">
                            <?php esc_html_e('All', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_image_history_filter" data-aipkit-history-filter="favorites" aria-pressed="false">
                            <?php esc_html_e('Favorites', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is generated with escaped values in the shortcode class.
                echo $image_history_html;
                ?>
                <dialog
                    class="aipkit-image-history-viewer"
                    data-aipkit-history-viewer
                    aria-label="<?php esc_attr_e('Generated image viewer', 'gpt3-ai-content-generator'); ?>"
                >
                    <div class="aipkit-image-history-viewer-stage">
                        <img class="aipkit-image-history-viewer-media" data-aipkit-history-viewer-media alt="">
                        <button
                            type="button"
                            class="aipkit-image-history-viewer-close"
                            data-aipkit-history-viewer-close
                            aria-label="<?php esc_attr_e('Close image viewer', 'gpt3-ai-content-generator'); ?>"
                            title="<?php esc_attr_e('Close image viewer', 'gpt3-ai-content-generator'); ?>"
                        >
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </button>
                        <div class="aipkit-image-history-viewer-scrim" aria-hidden="true"></div>
                        <div class="aipkit-image-history-viewer-actions" aria-label="<?php esc_attr_e('Image actions', 'gpt3-ai-content-generator'); ?>">
                            <button
                                type="button"
                                class="aipkit-image-history-viewer-action"
                                data-aipkit-history-viewer-action="download"
                                aria-label="<?php esc_attr_e('Download image', 'gpt3-ai-content-generator'); ?>"
                                title="<?php esc_attr_e('Download image', 'gpt3-ai-content-generator'); ?>"
                            >
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <button
                                type="button"
                                class="aipkit-image-history-viewer-action"
                                data-aipkit-history-viewer-action="favorite"
                                aria-label="<?php esc_attr_e('Add to favorites', 'gpt3-ai-content-generator'); ?>"
                                title="<?php esc_attr_e('Add to favorites', 'gpt3-ai-content-generator'); ?>"
                                aria-pressed="false"
                            >
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 17.75 5.83 21l1.18-6.88L2 9.25l6.91-1L12 2l3.09 6.25 6.91 1-5 4.87L18.17 21 12 17.75Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                            </button>
                            <?php if ($edit_available): ?>
                                <button
                                    type="button"
                                    class="aipkit-image-history-viewer-action"
                                    data-aipkit-history-viewer-action="edit"
                                    aria-label="<?php esc_attr_e('Use as edit source', 'gpt3-ai-content-generator'); ?>"
                                    title="<?php esc_attr_e('Use as edit source', 'gpt3-ai-content-generator'); ?>"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m14.5 5.5 4 4M4 20l4.1-1 10.4-10.4a2.12 2.12 0 0 0-3-3L5.1 16 4 20Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            <?php endif; ?>
                            <button
                                type="button"
                                class="aipkit-image-history-viewer-action aipkit-image-history-viewer-action--danger"
                                data-aipkit-history-viewer-action="delete"
                                data-label="<?php esc_attr_e('Delete image', 'gpt3-ai-content-generator'); ?>"
                                data-confirm-label="<?php esc_attr_e('Confirm delete image', 'gpt3-ai-content-generator'); ?>"
                                aria-label="<?php esc_attr_e('Delete image', 'gpt3-ai-content-generator'); ?>"
                                title="<?php esc_attr_e('Delete image', 'gpt3-ai-content-generator'); ?>"
                            >
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16m-10 4v5m4-5v5M9 7l1-3h4l1 3m3 0-1 13H7L6 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    </div>
                    <p class="aipkit-image-history-viewer-caption" data-aipkit-history-viewer-caption></p>
                </dialog>
            </section>
        <?php endif; ?>
    </div>
</div>
