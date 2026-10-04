<?php
/** Automations image generation, instructions and display settings. */
if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;

require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/prompt-editor.php';

function aipkit_render_automation_image_settings(): void
{
    $aipkit_cw_image_prompt_items = [];
    foreach (AIPKit_Content_Writer_Prompts::get_autogpt_content_writing_prompt_items() as $aipkit_cw_image_prompt_item) {
        $aipkit_cw_image_prompt_key = (string) ($aipkit_cw_image_prompt_item['key'] ?? '');
        if (in_array($aipkit_cw_image_prompt_key, ['image', 'featured_image'], true)) {
            $aipkit_cw_image_prompt_items[$aipkit_cw_image_prompt_key] = $aipkit_cw_image_prompt_item;
        }
    }
    $aipkit_cw_content_image_prompt_item = $aipkit_cw_image_prompt_items['image'] ?? [];
    $aipkit_cw_featured_image_prompt_item = $aipkit_cw_image_prompt_items['featured_image'] ?? [];
    ?>

    <div class="aipkit_image_settings_redesigned">
        <div class="aipkit_cw_image_section">
            <div class="aipkit_cw_image_hidden_fields" hidden aria-hidden="true">
                <input
                    type="checkbox"
                    id="aipkit_task_cw_generate_images_enabled"
                    name="generate_images_enabled"
                    class="aipkit_toggle_switch aipkit_task_cw_image_enable_toggle"
                    value="1"
                    tabindex="-1"
                >
                <input
                    type="checkbox"
                    id="aipkit_task_cw_generate_featured_image"
                    name="generate_featured_image"
                    class="aipkit_toggle_switch"
                    value="1"
                    tabindex="-1"
                >
                <select id="aipkit_task_cw_image_mode_control" tabindex="-1">
                    <option value="off"><?php esc_html_e('None', 'gpt3-ai-content-generator'); ?></option>
                    <option value="content"><?php esc_html_e('In content', 'gpt3-ai-content-generator'); ?></option>
                    <option value="featured"><?php esc_html_e('Featured', 'gpt3-ai-content-generator'); ?></option>
                    <option value="both"><?php esc_html_e('Both', 'gpt3-ai-content-generator'); ?></option>
                </select>
                <select id="aipkit_task_cw_image_provider" name="image_provider" tabindex="-1">
                    <optgroup label="<?php echo esc_attr__('AI Providers', 'gpt3-ai-content-generator'); ?>">
                        <option value="openai" selected>OpenAI</option>
                        <option value="aipuffercloud"><?php esc_html_e('AI Puffer Cloud', 'gpt3-ai-content-generator'); ?></option>
                        <option value="google">Google</option>
                        <option value="openrouter">OpenRouter</option>
                        <option value="azure">Azure</option>
                        <option value="xai">xAI</option>
                        <option value="replicate"><?php esc_html_e('Replicate', 'gpt3-ai-content-generator'); ?></option>
                    </optgroup>
                    <optgroup label="<?php echo esc_attr__('Stock Photos', 'gpt3-ai-content-generator'); ?>">
                        <option value="pexels"><?php esc_html_e('Pexels', 'gpt3-ai-content-generator'); ?></option>
                        <option value="pixabay"><?php esc_html_e('Pixabay', 'gpt3-ai-content-generator'); ?></option>
                    </optgroup>
                </select>
                <select id="aipkit_task_cw_image_model" name="image_model" tabindex="-1">
                    <?php // Populated by JS ?>
                </select>
                <input
                    type="hidden"
                    id="aipkit_task_cw_image_provider_options"
                    name="image_provider_options"
                    value="{}"
                >
            </div>
        </div>

        <div
            data-aipkit-image-instructions
            data-aipkit-inline-prompts="images"
            data-aipkit-inline-prompt-layout="rows"
        >
            <div
                class="aipkit_autogpt_content_field aipkit_autogpt_image_instruction_field"
                data-aipkit-content-field
                data-aipkit-prompt-key="image"
                data-aipkit-instruction-label="<?php echo esc_attr__('Content image', 'gpt3-ai-content-generator'); ?>"
            >
                <div class="aipkit_autogpt_image_feature_row">
                    <span class="aipkit_autogpt_image_feature_copy">
                        <span class="aipkit_autogpt_image_feature_title"><?php esc_html_e('Content images', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_autogpt_image_feature_helper"><?php esc_html_e('Inline images placed within the article body', 'gpt3-ai-content-generator'); ?></span>
                    </span>
                    <span class="aipkit_autogpt_image_feature_actions">
                        <button
                            type="button"
                            class="aipkit_autogpt_content_prompt_trigger"
                            data-aipkit-row-prompt-toggle
                            aria-controls="aipkit_task_cw_image_instructions_modal"
                            aria-haspopup="dialog"
                        >
                            <span
                                data-aipkit-row-prompt-status
                                data-built-in-label="<?php esc_attr_e('Instructions', 'gpt3-ai-content-generator'); ?>"
                                data-custom-label="<?php esc_attr_e('Custom instructions', 'gpt3-ai-content-generator'); ?>"
                            ><?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?></span>
                            <span class="dashicons dashicons-edit aipkit_autogpt_content_prompt_icon" aria-hidden="true"></span>
                        </button>
                        <label class="aipkit_switch aipkit_autogpt_image_feature_switch" aria-label="<?php esc_attr_e('Include content images', 'gpt3-ai-content-generator'); ?>">
                            <input type="checkbox" data-aipkit-image-child-toggle="image">
                            <span class="aipkit_switch_slider"></span>
                        </label>
                    </span>
                </div>
                <div
                    id="aipkit_task_cw_image_instruction_panel"
                    class="aipkit_autogpt_content_prompt_panel"
                    data-aipkit-row-prompt-panel
                    hidden
                >
                    <?php
                    aipkit_render_automation_prompt_editor([$aipkit_cw_content_image_prompt_item]);
                    ?>
                </div>
            </div>

            <div
                class="aipkit_autogpt_content_field aipkit_autogpt_image_instruction_field"
                data-aipkit-content-field
                data-aipkit-prompt-key="featured_image"
                data-aipkit-instruction-label="<?php echo esc_attr__('Featured image', 'gpt3-ai-content-generator'); ?>"
            >
                <div class="aipkit_autogpt_image_feature_row aipkit_autogpt_image_feature_row--featured">
                    <span class="aipkit_autogpt_image_feature_copy">
                        <span class="aipkit_autogpt_image_feature_title"><?php esc_html_e('Featured image', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_autogpt_image_feature_helper"><?php esc_html_e('Hero image used for the post thumbnail and social sharing', 'gpt3-ai-content-generator'); ?></span>
                    </span>
                    <span class="aipkit_autogpt_image_feature_actions">
                        <button
                            type="button"
                            class="aipkit_autogpt_content_prompt_trigger"
                            data-aipkit-row-prompt-toggle
                            aria-controls="aipkit_task_cw_image_instructions_modal"
                            aria-haspopup="dialog"
                        >
                            <span
                                data-aipkit-row-prompt-status
                                data-built-in-label="<?php esc_attr_e('Instructions', 'gpt3-ai-content-generator'); ?>"
                                data-custom-label="<?php esc_attr_e('Custom instructions', 'gpt3-ai-content-generator'); ?>"
                            ><?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?></span>
                            <span class="dashicons dashicons-edit aipkit_autogpt_content_prompt_icon" aria-hidden="true"></span>
                        </button>
                        <label class="aipkit_switch aipkit_autogpt_image_feature_switch" aria-label="<?php esc_attr_e('Include a featured image', 'gpt3-ai-content-generator'); ?>">
                            <input type="checkbox" data-aipkit-image-child-toggle="featured_image">
                            <span class="aipkit_switch_slider"></span>
                        </label>
                    </span>
                </div>
                <div
                    id="aipkit_task_cw_featured_image_instruction_panel"
                    class="aipkit_autogpt_content_prompt_panel"
                    data-aipkit-row-prompt-panel
                    hidden
                >
                    <?php
                    aipkit_render_automation_prompt_editor([$aipkit_cw_featured_image_prompt_item]);
                    ?>
                </div>
            </div>

        <div class="aipkit_task_cw_image_settings_container" hidden>
            <div class="aipkit_autogpt_image_shared_settings">
            <div class="aipkit_cw_image_section aipkit_task_cw_image_source_section">
                <div class="aipkit_cw_image_row aipkit_autogpt_question_row aipkit_cw_image_row--source">
                    <div class="aipkit_cw_panel_label_wrap">
                        <label
                            class="aipkit_cw_panel_label aipkit_autogpt_question"
                            for="aipkit_task_cw_image_selection_trigger"
                        >
                            <?php esc_html_e('Image source', 'gpt3-ai-content-generator'); ?>
                        </label>
                    </div>
                    <div class="aipkit_cw_image_control aipkit_cw_image_control--selection aipkit_cw_ai_control aipkit_cw_ai_control--model">
                        <div class="aipkit_cw_image_selection_inline" data-aipkit-image-source-picker>
                            <select
                                id="aipkit_task_cw_image_selection"
                                data-aipkit-unified-model-source
                                hidden
                                aria-hidden="true"
                                tabindex="-1"
                            >
                                <?php // Populated by JS ?>
                            </select>
                            <?php
                            $aipkit_unified_model_selector_config = [
                                'trigger_id' => 'aipkit_task_cw_image_selection_trigger',
                                'source_id' => 'aipkit_task_cw_image_selection',
                                'initial_label' => __('Select image source', 'gpt3-ai-content-generator'),
                                'class_name' => 'aipkit_autogpt_unified_model_selector',
                                'capability' => 'image_generation',
                                'show_trigger_logo' => true,
                                'show_provider_diagnostics' => true,
                                'search_placeholder' => __('Search image sources...', 'gpt3-ai-content-generator'),
                                'empty_text' => __('No image sources found', 'gpt3-ai-content-generator'),
                            ];
                            include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            $aipkit_notice_id = 'aipkit_task_cw_image_provider_notice';
            $aipkit_notice_class = 'aipkit_task_cw_image_provider_notice';
            $aipkit_notice_context = __('generate images for this automation', 'gpt3-ai-content-generator');
            $aipkit_notice_model_source = 'aipkit_task_cw_image_selection';
            include WPAICG_PLUGIN_DIR . 'admin/views/shared/provider-key-notice.php';
            ?>

            <div class="aipkit_cw_image_primary_options aipkit_cw_image_primary_options--shared" data-aipkit-image-primary-options>
                <div class="aipkit_cw_image_row aipkit_cw_image_row--inline-helper aipkit_autogpt_question_row" data-aipkit-image-shape-row hidden>
                    <div class="aipkit_cw_panel_label_wrap">
                        <span
                            id="aipkit_task_cw_image_shape_label"
                            class="aipkit_cw_panel_label aipkit_autogpt_question"
                            data-aipkit-image-shape-label
                            data-ai-label="<?php echo esc_attr__('Aspect ratio', 'gpt3-ai-content-generator'); ?>"
                            data-stock-label="<?php echo esc_attr__('Orientation', 'gpt3-ai-content-generator'); ?>"
                        ><?php esc_html_e('Aspect ratio', 'gpt3-ai-content-generator'); ?></span>
                        <span
                            class="aipkit_autogpt_question_helper"
                            data-aipkit-image-shape-helper
                            data-ai-helper="<?php echo esc_attr__('Shape of each generated image.', 'gpt3-ai-content-generator'); ?>"
                            data-ai-content-helper="<?php echo esc_attr__('Shape of content images.', 'gpt3-ai-content-generator'); ?>"
                            data-ai-featured-helper="<?php echo esc_attr__('Shape of the featured image.', 'gpt3-ai-content-generator'); ?>"
                            data-ai-both-helper="<?php echo esc_attr__('Shape of content and featured images.', 'gpt3-ai-content-generator'); ?>"
                        ><?php esc_html_e('Shape of each generated image.', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                    <div class="aipkit_cw_image_control">
                        <select id="aipkit_task_cw_image_shape" data-aipkit-image-shape-control hidden aria-hidden="true" tabindex="-1">
                            <option value=""><?php esc_html_e('Default', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                        <div
                            class="aipkit_autogpt_compact_choices aipkit_autogpt_image_shape_choices"
                            data-aipkit-image-shape-choices
                            role="radiogroup"
                            aria-labelledby="aipkit_task_cw_image_shape_label"
                        ></div>
                    </div>
                </div>
            </div>
            </div>

            <div class="aipkit_autogpt_image_content_settings" data-aipkit-image-content-panel>
                <div class="aipkit_cw_image_primary_options aipkit_cw_image_primary_options--content">
                <div class="aipkit_cw_image_row aipkit_autogpt_question_row" id="aipkit_task_cw_image_display_count_field" data-aipkit-image-content-option>
                    <div class="aipkit_cw_panel_label_wrap">
                        <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_image_count">
                            <?php esc_html_e('Images per article', 'gpt3-ai-content-generator'); ?>
                        </label>
                    </div>
                    <div class="aipkit_cw_image_control">
                        <div class="aipkit_autogpt_image_count_control">
                            <button type="button" data-aipkit-image-count-step="-1" aria-label="<?php esc_attr_e('Use one fewer image', 'gpt3-ai-content-generator'); ?>">−</button>
                            <input type="number" id="aipkit_task_cw_image_count" name="image_count" class="aipkit_form-input" value="1" min="1" max="10">
                            <button type="button" data-aipkit-image-count-step="1" aria-label="<?php esc_attr_e('Use one more image', 'gpt3-ai-content-generator'); ?>">+</button>
                        </div>
                    </div>
                </div>

                <div class="aipkit_cw_image_row aipkit_autogpt_question_row" id="aipkit_task_cw_image_display_placement_field" data-aipkit-image-content-option>
                    <div class="aipkit_cw_panel_label_wrap">
                        <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_image_placement">
                            <?php esc_html_e('Placement', 'gpt3-ai-content-generator'); ?>
                        </label>
                    </div>
                    <div class="aipkit_cw_image_control aipkit_autogpt_image_placement_control">
                        <select id="aipkit_task_cw_image_placement" name="image_placement" class="aipkit_form-input aipkit_cw_blended_chevron_select aipkit_task_cw_image_placement_select">
                            <option value="after_first_h2"><?php esc_html_e('After 1st H2', 'gpt3-ai-content-generator'); ?></option>
                            <option value="after_first_h3"><?php esc_html_e('After 1st H3', 'gpt3-ai-content-generator'); ?></option>
                            <option value="after_every_x_h2"><?php esc_html_e('Every X H2s', 'gpt3-ai-content-generator'); ?></option>
                            <option value="after_every_x_h3"><?php esc_html_e('Every X H3s', 'gpt3-ai-content-generator'); ?></option>
                            <option value="after_every_x_p"><?php esc_html_e('Every X paragraphs', 'gpt3-ai-content-generator'); ?></option>
                            <option value="at_end"><?php esc_html_e('End of content', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                        <div class="aipkit_autogpt_image_count_control aipkit_autogpt_image_count_control--placement" id="aipkit_task_cw_image_display_param_x_field" hidden>
                            <button type="button" data-aipkit-image-placement-step="-1" aria-label="<?php esc_attr_e('Use a smaller interval', 'gpt3-ai-content-generator'); ?>">−</button>
                            <input type="number" id="aipkit_task_cw_image_placement_param_x" name="image_placement_param_x" class="aipkit_form-input" value="2" min="1" aria-label="<?php esc_attr_e('Placement interval', 'gpt3-ai-content-generator'); ?>">
                            <button type="button" data-aipkit-image-placement-step="1" aria-label="<?php esc_attr_e('Use a larger interval', 'gpt3-ai-content-generator'); ?>">+</button>
                        </div>
                    </div>
                </div>

                <div class="aipkit_cw_image_row aipkit_cw_image_row--display aipkit_autogpt_question_row" id="aipkit_task_cw_image_display_size_field" data-aipkit-image-content-option>
                    <div class="aipkit_cw_panel_label_wrap">
                        <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_image_size">
                            <?php esc_html_e('Display size', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <span class="aipkit_autogpt_question_helper"><?php esc_html_e('WordPress image size in the post.', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                    <div class="aipkit_cw_image_control">
                        <select id="aipkit_task_cw_image_size" name="image_size" class="aipkit_form-input aipkit_cw_blended_chevron_select">
                            <option value="large" selected><?php esc_html_e('Large', 'gpt3-ai-content-generator'); ?></option>
                            <option value="medium"><?php esc_html_e('Medium', 'gpt3-ai-content-generator'); ?></option>
                            <option value="thumbnail"><?php esc_html_e('Thumbnail', 'gpt3-ai-content-generator'); ?></option>
                            <option value="full"><?php esc_html_e('Full', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="aipkit_cw_image_row aipkit_cw_image_row--display aipkit_autogpt_question_row" id="aipkit_task_cw_image_display_alignment_field" data-aipkit-image-content-option>
                    <div class="aipkit_cw_panel_label_wrap">
                        <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_image_alignment">
                            <?php esc_html_e('Align', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <span class="aipkit_autogpt_question_helper"><?php esc_html_e('Image alignment.', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                    <div class="aipkit_cw_image_control">
                        <select id="aipkit_task_cw_image_alignment" name="image_alignment" class="aipkit_form-input aipkit_cw_blended_chevron_select">
                            <option value="none" selected><?php esc_html_e('None', 'gpt3-ai-content-generator'); ?></option>
                            <option value="left"><?php esc_html_e('Left', 'gpt3-ai-content-generator'); ?></option>
                            <option value="center"><?php esc_html_e('Center', 'gpt3-ai-content-generator'); ?></option>
                            <option value="right"><?php esc_html_e('Right', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </div>
                </div>

                </div>
            </div>

            <div class="aipkit_autogpt_image_advanced" data-aipkit-image-advanced>
                <button
                    type="button"
                    class="aipkit_autogpt_image_advanced_toggle"
                    data-aipkit-image-advanced-toggle
                    aria-expanded="false"
                    aria-controls="aipkit_task_cw_image_advanced_panel"
                >
                    <span class="aipkit_autogpt_image_advanced_copy">
                        <span class="aipkit_autogpt_image_advanced_title"><?php esc_html_e('Advanced settings', 'gpt3-ai-content-generator'); ?></span>
                        <span
                            class="aipkit_autogpt_image_advanced_summary"
                            data-aipkit-image-advanced-summary
                            data-default-label="<?php esc_attr_e('Using recommended defaults', 'gpt3-ai-content-generator'); ?>"
                            data-custom-one-label="<?php esc_attr_e('1 customized', 'gpt3-ai-content-generator'); ?>"
                            data-custom-many-label="<?php
                            /* translators: %d: number of customized image settings. */
                            esc_attr_e('%d customized', 'gpt3-ai-content-generator');
                            ?>"
                        ><?php esc_html_e('Using recommended defaults', 'gpt3-ai-content-generator'); ?></span>
                    </span>
                    <span class="dashicons dashicons-arrow-down-alt2 aipkit_autogpt_image_advanced_chevron" aria-hidden="true"></span>
                </button>

                <div
                    id="aipkit_task_cw_image_advanced_panel"
                    class="aipkit_autogpt_image_advanced_panel"
                    data-aipkit-image-advanced-panel
                    hidden
                >
                    <?php
                    $aipkit_image_display_settings_id_prefix = 'aipkit_task_cw';
                    $aipkit_image_display_settings_render_mode = 'inline';
                    $aipkit_image_display_settings_autosave = false;
                    $aipkit_image_display_settings_excluded_common_fields = [
                        'image_count',
                        'image_placement',
                        'image_placement_param_x',
                        'image_size',
                        'image_alignment',
                    ];
                    $aipkit_image_display_settings_placement_extra_class = 'aipkit_task_cw_image_placement_select';
                    $aipkit_image_display_settings_pixabay_orientation_helper = __('Choose landscape or portrait images.', 'gpt3-ai-content-generator');
                    $aipkit_image_display_settings_pixabay_type_helper = __('Filter results by image type.', 'gpt3-ai-content-generator');
                    $aipkit_image_display_settings_pixabay_category_helper = __('Limit results to a subject area.', 'gpt3-ai-content-generator');
                    
                    include WPAICG_PLUGIN_DIR . 'admin/views/shared/image-display-settings.php';
                    ?>
                </div>
            </div>
        </div>

            <div
                id="aipkit_task_cw_image_instructions_modal"
                class="aipkit_autogpt_instructions_modal"
                data-aipkit-instructions-modal
                data-title-template="<?php
                /* translators: %s: image type name. */
                echo esc_attr(__('%s instructions', 'gpt3-ai-content-generator'));
                ?>"
                aria-hidden="true"
                hidden
            >
                <div
                    class="aipkit_autogpt_instructions_modal_panel"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="aipkit_task_cw_image_instructions_modal_title"
                    aria-describedby="aipkit_task_cw_image_instructions_modal_description"
                >
                    <div class="aipkit_autogpt_instructions_modal_header">
                        <div class="aipkit_autogpt_instructions_modal_heading">
                            <h2 id="aipkit_task_cw_image_instructions_modal_title" data-aipkit-instructions-modal-title>
                                <?php esc_html_e('Image instructions', 'gpt3-ai-content-generator'); ?>
                            </h2>
                            <p id="aipkit_task_cw_image_instructions_modal_description">
                                <?php esc_html_e('Tell AI what images to create for every post.', 'gpt3-ai-content-generator'); ?>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="aipkit_autogpt_instructions_modal_close"
                            data-aipkit-instructions-modal-close
                            aria-label="<?php esc_attr_e('Close image instructions', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="aipkit_autogpt_instructions_modal_body" data-aipkit-instructions-modal-body></div>
                    <div class="aipkit_autogpt_instructions_modal_footer">
                        <button type="button" class="aipkit_btn aipkit_btn-secondary" data-aipkit-instructions-modal-cancel>
                            <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-instructions-modal-save>
                            <?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
}
