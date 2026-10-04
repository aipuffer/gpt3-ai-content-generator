<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;

$feature_defaults = \WPAICG\AIPKit_Providers::get_new_feature_defaults();

$default_image_title_prompt_update = AIPKit_Content_Writer_Prompts::get_default_image_title_prompt_update();
$default_image_alt_text_prompt_update = AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt_update();
$default_image_caption_prompt_update = AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt_update();
$default_image_description_prompt_update = AIPKit_Content_Writer_Prompts::get_default_image_description_prompt_update();
?>

<input type="hidden" name="image_prompt_update" id="aipkit_cw_image_prompt_update" value="">
<input type="hidden" name="featured_image_prompt_update" id="aipkit_cw_featured_image_prompt_update" value="">
<input type="hidden" name="image_title_prompt_update" id="aipkit_cw_image_title_prompt_update" value="<?php echo esc_attr($default_image_title_prompt_update); ?>">
<input type="hidden" name="image_alt_text_prompt_update" id="aipkit_cw_image_alt_text_prompt_update" value="<?php echo esc_attr($default_image_alt_text_prompt_update); ?>">
<input type="hidden" name="image_caption_prompt_update" id="aipkit_cw_image_caption_prompt_update" value="<?php echo esc_attr($default_image_caption_prompt_update); ?>">
<input type="hidden" name="image_description_prompt_update" id="aipkit_cw_image_description_prompt_update" value="<?php echo esc_attr($default_image_description_prompt_update); ?>">

<div class="aipkit_cw_image_section">
    <div class="aipkit_cw_image_hidden_fields" hidden aria-hidden="true">
        <select id="aipkit_cw_image_provider" name="image_provider" class="aipkit_autosave_trigger" tabindex="-1">
            <option value="" <?php selected($feature_defaults['image_provider'], ''); ?>><?php esc_html_e('Select a provider', 'gpt3-ai-content-generator'); ?></option>
            <optgroup label="<?php echo esc_attr__('AI Providers', 'gpt3-ai-content-generator'); ?>">
                <option value="openai" <?php selected($feature_defaults['image_provider'], 'openai'); ?>>OpenAI</option>
                <option value="aipuffercloud" <?php selected($feature_defaults['image_provider'], 'aipuffercloud'); ?>><?php esc_html_e('AI Puffer Cloud', 'gpt3-ai-content-generator'); ?></option>
                <option value="google" <?php selected($feature_defaults['image_provider'], 'google'); ?>>Google</option>
                <option value="openrouter" <?php selected($feature_defaults['image_provider'], 'openrouter'); ?>>OpenRouter</option>
                <option value="azure" <?php selected($feature_defaults['image_provider'], 'azure'); ?>>Azure</option>
                <option value="xai" <?php selected($feature_defaults['image_provider'], 'xai'); ?>>xAI</option>
                <option value="replicate" <?php selected($feature_defaults['image_provider'], 'replicate'); ?>><?php esc_html_e('Replicate', 'gpt3-ai-content-generator'); ?></option>
            </optgroup>
            <optgroup label="<?php echo esc_attr__('Stock Photos', 'gpt3-ai-content-generator'); ?>">
                <option value="pexels"><?php esc_html_e('Pexels', 'gpt3-ai-content-generator'); ?></option>
                <option value="pixabay"><?php esc_html_e('Pixabay', 'gpt3-ai-content-generator'); ?></option>
            </optgroup>
        </select>
        <select id="aipkit_cw_image_model" name="image_model" class="aipkit_autosave_trigger" tabindex="-1">
            <?php // Populated by JS ?>
        </select>
        <input
            type="hidden"
            id="aipkit_cw_image_provider_options"
            name="image_provider_options"
            class="aipkit_autosave_trigger"
            value="{}"
        >
    </div>

    <div class="aipkit_cw_image_settings_container" data-aipkit-cw-image-settings hidden>
        <div class="aipkit_cw_image_option_row" data-aipkit-cw-image-source-row>
            <label
                class="aipkit_cw_image_option_label"
                for="aipkit_cw_image_selection_trigger"
            ><?php esc_html_e('Image source', 'gpt3-ai-content-generator'); ?></label>
            <div class="aipkit_cw_image_option_control aipkit_cw_image_source_control">
                <div class="aipkit_cw_image_source_picker">
                    <select id="aipkit_content_writer_image_selection" data-aipkit-unified-model-source hidden aria-hidden="true" tabindex="-1">
                        <?php // Populated by JS ?>
                    </select>
                    <?php
                    $aipkit_unified_model_selector_config = [
                        'trigger_id' => 'aipkit_cw_image_selection_trigger',
                        'source_id' => 'aipkit_content_writer_image_selection',
                        'initial_label' => __('Select image source', 'gpt3-ai-content-generator'),
                        'class_name' => 'aipkit_cw_image_unified_model_selector',
                        'capability' => 'image_generation',
                        'show_trigger_logo' => true,
                        'show_provider_diagnostics' => true,
                        'search_placeholder' => __('Search image sources...', 'gpt3-ai-content-generator'),
                        'empty_text' => __('No image sources found', 'gpt3-ai-content-generator'),
                    ];
                    include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                    ?>
                </div>
                <button
                    type="button"
                    class="aipkit_cw_image_options_trigger"
                    id="aipkit_cw_image_options_trigger"
                    data-aipkit-image-options-trigger
                    aria-controls="aipkit_cw_image_options_modal"
                    aria-expanded="false"
                    aria-haspopup="dialog"
                    aria-label="<?php esc_attr_e('Image options', 'gpt3-ai-content-generator'); ?>"
                    title="<?php esc_attr_e('Image options', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                </button>
            </div>
        </div>

        <?php include __DIR__ . '/image-prompts-settings.php'; ?>

        <div data-aipkit-image-content-panel>
            <div class="aipkit_cw_image_option_row" id="aipkit_cw_image_display_count_field">
                <label class="aipkit_cw_image_option_label" for="aipkit_cw_image_count"><?php esc_html_e('Images per article', 'gpt3-ai-content-generator'); ?></label>
                <div class="aipkit_cw_image_stepper">
                    <button type="button" data-aipkit-image-count-step="-1" aria-label="<?php esc_attr_e('Use one fewer image', 'gpt3-ai-content-generator'); ?>">−</button>
                    <input type="number" id="aipkit_cw_image_count" name="image_count" class="aipkit_form-input aipkit_autosave_trigger" value="1" min="1" max="10">
                    <button type="button" data-aipkit-image-count-step="1" aria-label="<?php esc_attr_e('Use one more image', 'gpt3-ai-content-generator'); ?>">+</button>
                </div>
            </div>

            <div class="aipkit_cw_image_option_row" id="aipkit_cw_image_display_placement_field">
                <label class="aipkit_cw_image_option_label" for="aipkit_cw_image_placement"><?php esc_html_e('Placement', 'gpt3-ai-content-generator'); ?></label>
                <div class="aipkit_cw_image_placement_control">
                    <select
                        id="aipkit_cw_image_placement"
                        name="image_placement"
                        class="aipkit_form-input aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                        data-aipkit-cw-fit-selected
                        data-aipkit-cw-fit-selected-max="150"
                    >
                        <option value="after_first_h2"><?php esc_html_e('After 1st H2', 'gpt3-ai-content-generator'); ?></option>
                        <option value="after_first_h3"><?php esc_html_e('After 1st H3', 'gpt3-ai-content-generator'); ?></option>
                        <option value="after_every_x_h2"><?php esc_html_e('Every X H2s', 'gpt3-ai-content-generator'); ?></option>
                        <option value="after_every_x_h3"><?php esc_html_e('Every X H3s', 'gpt3-ai-content-generator'); ?></option>
                        <option value="after_every_x_p"><?php esc_html_e('Every X paragraphs', 'gpt3-ai-content-generator'); ?></option>
                        <option value="at_end"><?php esc_html_e('End of content', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                </div>
            </div>

            <div class="aipkit_cw_image_option_row aipkit_cw_image_interval_row" id="aipkit_cw_image_display_param_x_field" hidden>
                <label class="aipkit_cw_image_option_label" for="aipkit_cw_image_placement_param_x"><?php esc_html_e('X', 'gpt3-ai-content-generator'); ?></label>
                <div class="aipkit_cw_image_stepper">
                    <button type="button" data-aipkit-image-placement-step="-1" aria-label="<?php esc_attr_e('Use a smaller interval', 'gpt3-ai-content-generator'); ?>">−</button>
                    <input type="number" id="aipkit_cw_image_placement_param_x" name="image_placement_param_x" class="aipkit_form-input aipkit_autosave_trigger" value="2" min="1" aria-label="<?php esc_attr_e('Placement interval', 'gpt3-ai-content-generator'); ?>">
                    <button type="button" data-aipkit-image-placement-step="1" aria-label="<?php esc_attr_e('Use a larger interval', 'gpt3-ai-content-generator'); ?>">+</button>
                </div>
            </div>

        </div>
    </div>

    <div
        class="aipkit-modal-overlay aipkit_cw_image_options_modal"
        id="aipkit_cw_image_options_modal"
        data-aipkit-image-options-modal
        aria-hidden="true"
    >
        <div
            class="aipkit-modal-content aipkit-modal-shell aipkit_cw_image_options_modal_content"
            role="dialog"
            aria-modal="true"
            aria-labelledby="aipkit_cw_image_options_modal_title"
        >
            <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_cw_image_options_modal_header">
                <div class="aipkit-modal-shell-intro">
                    <h2 class="aipkit-modal-shell-title" id="aipkit_cw_image_options_modal_title">
                        <?php esc_html_e('Image options', 'gpt3-ai-content-generator'); ?>
                    </h2>
                </div>
                <button
                    type="button"
                    class="aipkit-modal-close-btn aipkit-modal-shell-close"
                    data-aipkit-image-options-close
                    aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_cw_image_options_modal_body">
                <?php
                $aipkit_image_display_settings_id_prefix = 'aipkit_cw';
                $aipkit_image_display_settings_render_mode = 'inline';
                $aipkit_image_display_settings_autosave = true;
                $aipkit_image_display_settings_standard_fields = true;
                $aipkit_image_display_settings_trigger_hidden = false;
                $aipkit_image_display_settings_placement_extra_class = '';
                $aipkit_image_display_settings_excluded_common_fields = [
                    'image_count',
                    'image_placement',
                    'image_placement_param_x',
                ];
                include WPAICG_PLUGIN_DIR . 'admin/views/shared/image-display-settings.php';
                ?>
            </div>
        </div>
    </div>
</div>
