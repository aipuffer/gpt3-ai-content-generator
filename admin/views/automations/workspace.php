<?php
/** Automations task editor, task list and queue. Data comes from page.php. */

if (!defined('ABSPATH')) {
    exit;
}

require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/model-settings.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/context-settings.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/rewrite-settings.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/comment-reply-settings.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/topic-input.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/content-settings.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/image-settings.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/indexing-settings.php';
require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/task-selector.php';

$aipkit_automation_view_data = [
    'cw_providers_for_select' => $cw_providers_for_select ?? [],
    'cw_default_temperature' => $cw_default_temperature ?? '1',
    'cw_default_max_tokens' => $cw_default_max_tokens ?? '4000',
    'all_selectable_post_types' => $all_selectable_post_types ?? [],
    'cw_available_post_types' => $cw_available_post_types ?? [],
    'cw_wp_categories' => $cw_wp_categories ?? [],
    'cw_users_for_author' => $cw_users_for_author ?? [],
    'openai_vector_stores' => $openai_vector_stores ?? [],
    'google_file_search_stores' => $google_file_search_stores ?? [],
    'pinecone_indexes' => $pinecone_indexes ?? [],
    'qdrant_collections' => $qdrant_collections ?? [],
    'chroma_collections' => $chroma_collections ?? [],
];
?>
<div class="aipkit_container-body">
    <div id="aipkit_automated_task_form_wrapper">
        <div class="aipkit_task_form_container">
            <form id="aipkit_automated_task_form" onsubmit="return false;">
                <input type="hidden" name="task_id" id="aipkit_automated_task_id" value="">
                <input type="hidden" name="task_name" id="aipkit_automated_task_name" value="">

                <div class="aipkit_wizard_content_container">
                    <div class="aipkit_autogpt_form_layout aipkit_autogpt_progressive_builder" data-aipkit-autogpt-builder data-mode="create" data-current-step="type">
                        <div class="aipkit_autogpt_builder_titlebar">
                            <div class="aipkit_autogpt_builder_titlecopy">
                                <div class="aipkit_autogpt_form_titlebar">
                                    <div class="aipkit_autogpt_header_title_editor" id="aipkit_autogpt_header_title_editor" style="display: none;">
                                        <div class="aipkit_autogpt_title_control">
                                            <button
                                                type="button"
                                                class="aipkit_autogpt_title_display is-placeholder"
                                                id="aipkit_autogpt_title_display"
                                                aria-label="<?php esc_attr_e('Edit task name', 'gpt3-ai-content-generator'); ?>"
                                            >
                                                <span class="aipkit_autogpt_title_text" id="aipkit_autogpt_title_text" data-default-label="<?php esc_attr_e('Untitled', 'gpt3-ai-content-generator'); ?>">
                                                    <?php esc_html_e('Untitled', 'gpt3-ai-content-generator'); ?>
                                                </span>
                                                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                            </button>
                                            <input
                                                type="text"
                                                id="aipkit_autogpt_task_title_input"
                                                class="aipkit_form-input aipkit_autogpt_title_input"
                                                placeholder="<?php esc_attr_e('Untitled', 'gpt3-ai-content-generator'); ?>"
                                                style="display: none;"
                                            >
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <section class="aipkit_autogpt_builder_step" data-aipkit-builder-step="content" data-state="current">
                            <button type="button" class="aipkit_autogpt_builder_step_header" data-aipkit-builder-step-toggle="content" aria-expanded="true" aria-controls="aipkit_autogpt_builder_step_type_body">
                                <span class="aipkit_autogpt_builder_step_status" aria-hidden="true"><span class="aipkit_autogpt_builder_step_number">1</span></span>
                                <span class="aipkit_autogpt_builder_step_copy">
                                    <span class="aipkit_autogpt_builder_step_title"><?php esc_html_e('Setup', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_autogpt_builder_step_summary" data-aipkit-builder-summary="content"><?php esc_html_e('Choose a source', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <span class="aipkit_autogpt_builder_step_chevron" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_autogpt_builder_step_body" id="aipkit_autogpt_builder_step_type_body">
                                <div class="aipkit_autogpt_phase_intro" data-aipkit-builder-question>
                                    <h2 data-aipkit-builder-type-title data-intent-title="<?php esc_attr_e('What do you want to automate?', 'gpt3-ai-content-generator'); ?>" data-source-title="<?php esc_attr_e('Where should your topics come from?', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('What do you want to automate?', 'gpt3-ai-content-generator'); ?></h2>
                                    <p
                                        data-aipkit-builder-type-description
                                        data-intent-description="<?php esc_attr_e('Choose what you want to automate.', 'gpt3-ai-content-generator'); ?>"
                                        data-source-description="<?php esc_attr_e('Choose a topic source to continue.', 'gpt3-ai-content-generator'); ?>"
                                    ><?php esc_html_e('Choose what you want to automate.', 'gpt3-ai-content-generator'); ?></p>
                                </div>
                                <?php aipkit_render_automation_task_selector(!empty($is_pro)); ?>
                                <div class="aipkit_autogpt_content_source" data-aipkit-content-source hidden>
                                    <div class="aipkit_autogpt_phase_intro aipkit_autogpt_phase_intro--source">
                                        <div class="aipkit_autogpt_phase_intro_copy">
                                            <h3 data-aipkit-builder-source-title><?php esc_html_e('Add a source', 'gpt3-ai-content-generator'); ?></h3>
                                            <p data-aipkit-builder-source-description></p>
                                        </div>
                                    </div>
                                    <div class="aipkit_autogpt_form_main aipkit_autogpt_form_shell">
                                        <div class="aipkit_autogpt_form_shell_body">
                                            <div class="aipkit_wizard_content_step" data-content-id="task_form_setup">
                                                <select id="aipkit_automated_task_category" name="task_category" class="aipkit_form-input" hidden aria-hidden="true" tabindex="-1">
                                                    <?php foreach ($task_categories as $aipkit_category_key => $aipkit_category_label) : ?>
                                                        <option value="<?php echo esc_attr($aipkit_category_key); ?>"><?php echo esc_html($aipkit_category_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div id="aipkit_task_ci_source_wrapper" class="aipkit_task_config_section" style="display: none;">
                                                    <?php aipkit_render_automation_indexing_settings($aipkit_automation_view_data); ?>
                                                </div>
                                                <div id="aipkit_task_cw_input_modes_wrapper" class="aipkit_task_config_section" style="display: none;">
                                                    <?php // This hidden input will be set by JS based on the selected task_type?>
                                                    <input type="hidden" name="cw_generation_mode" id="aipkit_task_cw_generation_mode" value="">
                                                    <select id="aipkit_automated_task_type" name="task_type" class="aipkit_form-input" hidden aria-hidden="true" tabindex="-1" disabled>
                                                        <option value=""><?php esc_html_e('-- Select a category first --', 'gpt3-ai-content-generator'); ?></option>
                                                    </select>

                                                    <?php aipkit_render_automation_topic_input($aipkit_automation_view_data); ?>

                                                    <?php
                                                    $aipkit_source_modes_view = WPAICG_PLUGIN_DIR . 'admin/views/content-writer/source-modes.php';
                                                    foreach (['rss' => 'RSS', 'url' => 'URL', 'gsheets' => 'Google Sheets'] as $aipkit_cw_source_mode => $aipkit_source_mode_label) :
                                                        ?>
                                                        <div id="aipkit_task_cw_input_mode_<?php echo esc_attr($aipkit_cw_source_mode); ?>" class="aipkit_task_cw_input_mode_section" style="display:none;">
                                                            <?php
                                                            if (file_exists($aipkit_source_modes_view)) {
                                                                $aipkit_show_rss_fetch_button = $aipkit_cw_source_mode === 'rss';
                                                                $aipkit_autogpt_source_mode = true;
                                                                include $aipkit_source_modes_view;
                                                                unset($aipkit_show_rss_fetch_button, $aipkit_autogpt_source_mode);
                                                            } else {
                                                                echo '<p>This is a Pro feature. Please upgrade to access the ' . esc_html($aipkit_source_mode_label) . ' feature.</p>';
                                                            }
                                                            ?>
                                                        </div>
                                                    <?php endforeach; ?>

                                                </div>
                                                <div id="aipkit_task_cc_source_wrapper" class="aipkit_task_config_section" style="display: none;">
                                                    <?php
                                                    aipkit_render_automation_comment_reply_panel('source', $aipkit_automation_view_data);
                                                ?>
                                                </div>
                                                <div id="aipkit_task_ce_content_selection_wrapper" class="aipkit_task_config_section" style="display: none;">
                                                    <?php
                                                    aipkit_render_automation_rewrite_panel('source', $aipkit_automation_view_data);
                                                ?>
                                                </div>
                                            </div>
                                            <div class="aipkit_wizard_content_step" data-content-id="task_config_comment_reply">
                                                <?php aipkit_render_automation_comment_reply_panel('filters'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="aipkit_autogpt_builder_step_error" data-aipkit-builder-error="content" role="alert"></div>
                            </div>
                        </section>

                        <section class="aipkit_autogpt_builder_step" data-aipkit-builder-step="ai" data-state="locked">
                            <button type="button" class="aipkit_autogpt_builder_step_header" data-aipkit-builder-step-toggle="ai" aria-expanded="false" aria-controls="aipkit_autogpt_builder_step_ai_body" disabled>
                                <span class="aipkit_autogpt_builder_step_status" aria-hidden="true"><span class="aipkit_autogpt_builder_step_number">2</span></span>
                                <span class="aipkit_autogpt_builder_step_copy">
                                    <span class="aipkit_autogpt_builder_step_title"><?php esc_html_e('AI', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_autogpt_builder_step_summary" data-aipkit-builder-summary="ai"><?php esc_html_e('Recommended settings', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <span class="aipkit_autogpt_builder_step_chevron" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_autogpt_builder_step_body" id="aipkit_autogpt_builder_step_ai_body" hidden>
                                <div class="aipkit_autogpt_phase_empty" data-aipkit-ai-empty hidden><?php esc_html_e('This automation does not need an AI writing model.', 'gpt3-ai-content-generator'); ?></div>
                                <div class="aipkit_autogpt_form_right aipkit_cw_inspector_stack aipkit_autogpt_inspector_stack">
                                    <section
                                        id="aipkit_autogpt_setup_card"
                                        class="aipkit_cw_inspector_card aipkit_autogpt_inspector_card aipkit_autogpt_inspector_card--setup"
                                        data-aipkit-autogpt-inspector-card="setup"
                                    >
                                        <div class="aipkit_cw_inspector_card_header">
                                            <button
                                                type="button"
                                                class="aipkit_autogpt_inspector_card_toggle"
                                                data-aipkit-autogpt-card-toggle="setup"
                                                aria-expanded="true"
                                                aria-controls="aipkit_autogpt_setup_card_body"
                                            >
                                                <span class="aipkit_cw_inspector_card_header_copy">
                                                    <span class="aipkit_cw_inspector_card_title"><?php esc_html_e('AI & writing', 'gpt3-ai-content-generator'); ?></span>
                                                </span>
                                                <span class="aipkit_autogpt_inspector_card_toggle_icon" aria-hidden="true"></span>
                                            </button>
                                        </div>
                                        <div id="aipkit_autogpt_setup_card_body" class="aipkit_cw_inspector_card_body aipkit_autogpt_inspector_card_body aipkit_autogpt_inspector_card_body--setup">
                                            <div class="aipkit_autogpt_setup_sections">
                                                <div
                                                    class="aipkit_autogpt_setup_section"
                                                    data-aipkit-autogpt-setup-section="content_writing"
                                                    hidden
                                                >
                                                    <?php aipkit_render_automation_model_settings(['has_length' => true], $aipkit_automation_view_data); ?>
                                                </div>
                                                <div
                                                    class="aipkit_autogpt_setup_section"
                                                    data-aipkit-autogpt-setup-section="enhance_existing_content"
                                                    hidden
                                                >
                                                    <?php aipkit_render_automation_rewrite_panel('model', $aipkit_automation_view_data); ?>
                                                </div>
                                                <div
                                                    class="aipkit_autogpt_setup_section"
                                                    data-aipkit-autogpt-setup-section="community_reply_comments"
                                                    hidden
                                                >
                                                    <?php aipkit_render_automation_model_settings([
                                                        'scope' => 'cc',
                                                        'name_prefix' => 'cc_',
                                                        'max_tokens_question' => __('Maximum reply length', 'gpt3-ai-content-generator'),
                                                        'has_max_tokens' => true,
                                                        'default_max_tokens' => $aipkit_automation_view_data['cw_default_max_tokens'],
                                                    ], $aipkit_automation_view_data); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </section>
                                </div>
                                <div class="aipkit_autogpt_builder_step_error" data-aipkit-builder-error="ai" role="alert"></div>
                            </div>
                        </section>

                        <section class="aipkit_autogpt_builder_step" data-aipkit-builder-step="fields" data-state="locked">
                            <button type="button" class="aipkit_autogpt_builder_step_header" data-aipkit-builder-step-toggle="fields" aria-expanded="false" aria-controls="aipkit_autogpt_builder_step_fields_body" disabled>
                                <span class="aipkit_autogpt_builder_step_status" aria-hidden="true"><span class="aipkit_autogpt_builder_step_number">3</span></span>
                                <span class="aipkit_autogpt_builder_step_copy">
                                    <span class="aipkit_autogpt_builder_step_title"><?php esc_html_e('Content', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_autogpt_builder_step_summary" data-aipkit-builder-summary="fields"><?php esc_html_e('Title + article · Default instructions', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <span class="aipkit_autogpt_builder_step_chevron" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_autogpt_builder_step_body" id="aipkit_autogpt_builder_step_fields_body" hidden>
                                <div
                                    class="aipkit_autogpt_post_content_step"
                                    data-aipkit-autogpt-content-fields="content_writing"
                                    hidden
                                ><?php aipkit_render_automation_content_settings(); ?></div>
                                <div
                                    class="aipkit_autogpt_post_content_step"
                                    data-aipkit-autogpt-content-fields="enhance_existing_content"
                                    hidden
                                ><?php aipkit_render_automation_rewrite_panel('prompts', $aipkit_automation_view_data); ?></div>
                                <div class="aipkit_autogpt_builder_step_error" data-aipkit-builder-error="fields" role="alert"></div>
                            </div>
                        </section>

                        <section class="aipkit_autogpt_builder_step" data-aipkit-builder-step="images" data-state="locked">
                            <button type="button" class="aipkit_autogpt_builder_step_header" data-aipkit-builder-step-toggle="images" aria-expanded="false" aria-controls="aipkit_autogpt_builder_step_images_body" disabled>
                                <span class="aipkit_autogpt_builder_step_status" aria-hidden="true"><span class="aipkit_autogpt_builder_step_number">4</span></span>
                                <span class="aipkit_autogpt_builder_step_copy">
                                    <span class="aipkit_autogpt_builder_step_title"><?php esc_html_e('Images', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_autogpt_builder_step_summary" data-aipkit-builder-summary="images"><?php esc_html_e('Off', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <span class="aipkit_autogpt_builder_step_chevron" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_autogpt_builder_step_body" id="aipkit_autogpt_builder_step_images_body" hidden>
                                <div class="aipkit_autogpt_form_right aipkit_cw_inspector_stack aipkit_autogpt_inspector_stack">
                                    <section
                                        id="aipkit_autogpt_media_card"
                                        class="aipkit_cw_inspector_card aipkit_autogpt_inspector_card aipkit_autogpt_inspector_card--media"
                                        data-aipkit-autogpt-inspector-card="media"
                                    >
                                        <div class="aipkit_cw_inspector_card_header">
                                            <button
                                                type="button"
                                                class="aipkit_autogpt_inspector_card_toggle"
                                                data-aipkit-autogpt-card-toggle="media"
                                                aria-expanded="true"
                                                aria-controls="aipkit_autogpt_media_card_body"
                                            >
                                                <span class="aipkit_cw_inspector_card_header_copy">
                                                    <span class="aipkit_cw_inspector_card_title"><?php esc_html_e('Images', 'gpt3-ai-content-generator'); ?></span>
                                                </span>
                                                <span class="aipkit_autogpt_inspector_card_toggle_icon" aria-hidden="true"></span>
                                            </button>
                                        </div>
                                        <div id="aipkit_autogpt_media_card_body" class="aipkit_cw_inspector_card_body aipkit_autogpt_inspector_card_body aipkit_autogpt_inspector_card_body--media">
                                            <?php aipkit_render_automation_image_settings(); ?>
                                        </div>
                                    </section>
                                </div>
                                <div class="aipkit_autogpt_builder_step_error" data-aipkit-builder-error="images" role="alert"></div>
                            </div>
                        </section>

                        <section class="aipkit_autogpt_builder_step" data-aipkit-builder-step="seo" data-state="locked">
                            <button type="button" class="aipkit_autogpt_builder_step_header" data-aipkit-builder-step-toggle="seo" aria-expanded="false" aria-controls="aipkit_autogpt_builder_step_seo_body" disabled>
                                <span class="aipkit_autogpt_builder_step_status" aria-hidden="true"><span class="aipkit_autogpt_builder_step_number">5</span></span>
                                <span class="aipkit_autogpt_builder_step_copy">
                                    <span class="aipkit_autogpt_builder_step_title"><?php esc_html_e('SEO', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_autogpt_builder_step_summary" data-aipkit-builder-summary="seo"><?php esc_html_e('Off', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <span class="aipkit_autogpt_builder_step_chevron" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_autogpt_builder_step_body" id="aipkit_autogpt_builder_step_seo_body" hidden>
                                <div class="aipkit_autogpt_seo_step"><?php include WPAICG_PLUGIN_DIR . 'admin/views/automations/seo-settings.php'; ?></div>
                                <div class="aipkit_autogpt_builder_step_error" data-aipkit-builder-error="seo" role="alert"></div>
                            </div>
                        </section>

                        <section class="aipkit_autogpt_builder_step" data-aipkit-builder-step="knowledge" data-state="locked">
                            <button type="button" class="aipkit_autogpt_builder_step_header" data-aipkit-builder-step-toggle="knowledge" aria-expanded="false" aria-controls="aipkit_autogpt_builder_step_knowledge_body" disabled>
                                <span class="aipkit_autogpt_builder_step_status" aria-hidden="true"><span class="aipkit_autogpt_builder_step_number">6</span></span>
                                <span class="aipkit_autogpt_builder_step_copy">
                                    <span class="aipkit_autogpt_builder_step_title"><?php esc_html_e('Knowledge', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_autogpt_builder_step_summary" data-aipkit-builder-summary="context"><?php esc_html_e('Off', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <span class="aipkit_autogpt_builder_step_chevron" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_autogpt_builder_step_body" id="aipkit_autogpt_builder_step_knowledge_body" hidden>
                                <div class="aipkit_autogpt_form_right aipkit_cw_inspector_stack aipkit_autogpt_inspector_stack">
                                    <section
                                        id="aipkit_autogpt_advanced_card"
                                        class="aipkit_cw_inspector_card aipkit_autogpt_inspector_card aipkit_autogpt_inspector_card--advanced"
                                        data-aipkit-autogpt-inspector-card="advanced"
                                    >
                                        <div class="aipkit_cw_inspector_card_header">
                                            <button
                                                type="button"
                                                class="aipkit_autogpt_inspector_card_toggle"
                                                data-aipkit-autogpt-card-toggle="advanced"
                                                aria-expanded="true"
                                                aria-controls="aipkit_autogpt_advanced_card_body"
                                            >
                                                <span class="aipkit_cw_inspector_card_header_copy">
                                                    <span class="aipkit_cw_inspector_card_title"><?php esc_html_e('Advanced', 'gpt3-ai-content-generator'); ?></span>
                                                </span>
                                                <span class="aipkit_autogpt_inspector_card_toggle_icon" aria-hidden="true"></span>
                                            </button>
                                        </div>
                                        <div id="aipkit_autogpt_advanced_card_body" class="aipkit_cw_inspector_card_body aipkit_autogpt_inspector_card_body aipkit_autogpt_inspector_card_body--advanced">
                                            <div class="aipkit_autogpt_advanced_sections">
                                                <div
                                                    class="aipkit_autogpt_advanced_section"
                                                    data-aipkit-autogpt-advanced-section="content_writing"
                                                    hidden
                                                >
                                                    <?php aipkit_render_automation_context_settings(['use_autosave_class' => true, 'show_confidence_threshold' => true], $aipkit_automation_view_data); ?>
                                                </div>
                                                <div
                                                    class="aipkit_autogpt_advanced_section"
                                                    data-aipkit-autogpt-advanced-section="enhance_existing_content"
                                                    hidden
                                                >
                                                    <?php aipkit_render_automation_rewrite_panel('context', $aipkit_automation_view_data); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </section>
                                </div>
                                <div class="aipkit_autogpt_builder_step_error" data-aipkit-builder-error="knowledge" role="alert"></div>
                            </div>
                        </section>

                        <section class="aipkit_autogpt_builder_step" data-aipkit-builder-step="finish" data-state="locked">
                            <button type="button" class="aipkit_autogpt_builder_step_header" data-aipkit-builder-step-toggle="finish" aria-expanded="false" aria-controls="aipkit_autogpt_builder_step_finish_body" disabled>
                                <span class="aipkit_autogpt_builder_step_status" aria-hidden="true"><span class="aipkit_autogpt_builder_step_number">7</span></span>
                                <span class="aipkit_autogpt_builder_step_copy">
                                    <span class="aipkit_autogpt_builder_step_title"><?php esc_html_e('Finish', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_autogpt_builder_step_summary" data-aipkit-builder-summary="finish"><?php esc_html_e('Publishing and schedule', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <span class="aipkit_autogpt_builder_step_chevron" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_autogpt_builder_step_body" id="aipkit_autogpt_builder_step_finish_body" hidden>
                                <section
                                    id="aipkit_autogpt_schedule_card"
                                    class="aipkit_cw_inspector_card aipkit_autogpt_inspector_card aipkit_autogpt_inspector_card--schedule"
                                    data-aipkit-autogpt-inspector-card="schedule"
                                    data-status="active"
                                >
                                    <div class="aipkit_cw_inspector_card_header">
                                        <button
                                            type="button"
                                            class="aipkit_autogpt_inspector_card_toggle"
                                            data-aipkit-autogpt-card-toggle="schedule"
                                            aria-expanded="true"
                                            aria-controls="aipkit_autogpt_schedule_card_body"
                                        >
                                            <span class="aipkit_cw_inspector_card_header_copy">
                                                <span class="aipkit_cw_inspector_card_title"><?php esc_html_e('Schedule', 'gpt3-ai-content-generator'); ?></span>
                                            </span>
                                            <span class="aipkit_autogpt_inspector_card_toggle_icon" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                    <div id="aipkit_autogpt_schedule_card_body" class="aipkit_cw_inspector_card_body aipkit_autogpt_inspector_card_body aipkit_autogpt_inspector_card_body--schedule">
                                        <div class="aipkit_wizard_content_step aipkit_autogpt_sidebar_step" data-content-id="task_config_status">
                                            <?php include WPAICG_PLUGIN_DIR . 'admin/views/automations/schedule-settings.php'; ?>
                                        </div>
                                    </div>
                                </section>
                                <div class="aipkit_autogpt_builder_step_error" data-aipkit-builder-error="finish" role="alert"></div>
                            </div>
                        </section>

                        <div
                            id="aipkit_autogpt_editor_actions"
                            class="aipkit_autogpt_wizard_footer"
                        >
                            <div
                                class="aipkit_autogpt_wizard_progress_track"
                                role="progressbar"
                                aria-label="<?php esc_attr_e('Automation setup progress', 'gpt3-ai-content-generator'); ?>"
                                aria-valuemin="1"
                                aria-valuemax="7"
                                aria-valuenow="1"
                                aria-valuetext="<?php esc_attr_e('Step 1 of 7', 'gpt3-ai-content-generator'); ?>"
                                data-aipkit-builder-progress-track
                            >
                                <span class="aipkit_autogpt_wizard_progress_fill" data-aipkit-builder-progress-fill style="width: 14.2857%;"></span>
                            </div>
                            <div class="aipkit_autogpt_wizard_footer_side">
                                <button type="button" id="aipkit_cancel_edit_task_btn" class="aipkit_btn aipkit_btn-secondary" hidden><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></button>
                                <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_autogpt_footer_previous" data-aipkit-builder-footer-previous disabled aria-disabled="true"><?php esc_html_e('← Previous', 'gpt3-ai-content-generator'); ?></button>
                            </div>
                            <div class="aipkit_autogpt_quick_create_slot">
                                <button
                                    type="button"
                                    class="aipkit_btn aipkit_btn-secondary aipkit_autogpt_quick_create"
                                    form="aipkit_automated_task_form"
                                    data-aipkit-builder-quick-create
                                    aria-label="<?php esc_attr_e('Quick create using recommended defaults', 'gpt3-ai-content-generator'); ?>"
                                    title="<?php esc_attr_e('Create using recommended defaults', 'gpt3-ai-content-generator'); ?>"
                                    disabled
                                    aria-disabled="true"
                                >
                                    <span class="aipkit_autogpt_quick_create_emoji" aria-hidden="true">⚡</span>
                                    <span class="aipkit_btn-text"><?php esc_html_e('Quick create', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_spinner" style="display:none;"></span>
                                </button>
                            </div>
                            <div class="aipkit_autogpt_wizard_footer_side aipkit_autogpt_wizard_footer_side--end">
                                <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-builder-footer-next><span class="aipkit_btn-text"><?php esc_html_e('Customize →', 'gpt3-ai-content-generator'); ?></span></button>
                                <button type="submit" id="aipkit_save_task_btn" class="aipkit_btn aipkit_btn-primary" form="aipkit_automated_task_form" hidden>
                                    <span class="aipkit_btn-text"><?php esc_html_e('Save task', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_spinner" style="display:none;"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>


            </form>
        </div>
    </div>

    <div id="aipkit_autogpt_empty_workspace" class="aipkit_autogpt_empty_workspace" hidden>
        <div class="aipkit_autogpt_empty_workspace_intro">
            <span class="aipkit_autogpt_empty_workspace_icon" aria-hidden="true">⚡</span>
            <div class="aipkit_autogpt_empty_workspace_copy">
                <h2><?php esc_html_e('Create a new automation', 'gpt3-ai-content-generator'); ?></h2>
                <p><?php esc_html_e('Set it up once, and it will run automatically in the background.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-autogpt-empty-create>
                <?php esc_html_e('Create automation', 'gpt3-ai-content-generator'); ?>
            </button>
        </div>
    </div>

    <?php include WPAICG_PLUGIN_DIR . 'admin/views/automations/task-list.php'; ?>

    <?php include WPAICG_PLUGIN_DIR . 'admin/views/automations/task-queue.php'; ?>

</div>
