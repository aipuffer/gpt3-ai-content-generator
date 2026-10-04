<?php
/**
 * Chatbot Knowledge panel: source inputs, draft controls and training status.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
?>
<section id="aipkit_sources_training_card" class="aipkit_builder_card aipkit_builder_card--training">
    <div class="aipkit_training_decision" data-aipkit-training-decision>
        <div class="aipkit_training_decision_header">
            <div class="aipkit_training_header_copy">
                <p class="aipkit_training_decision_title">
                    <?php esc_html_e('Knowledge', 'gpt3-ai-content-generator'); ?>
                </p>
                <p class="aipkit_training_decision_subtitle">
                    <?php esc_html_e('Give this chatbot reliable sources to answer from.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <button
                type="button"
                class="aipkit_training_add_source"
                aria-haspopup="dialog"
                aria-controls="aipkit_training_source_popover"
                aria-expanded="false"
            >
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <span><?php esc_html_e('Add source', 'gpt3-ai-content-generator'); ?></span>
            </button>
        </div>

        <?php
        $aipkit_default_training_post_types = ['page', 'post'];
        ?>
        <div
            id="aipkit_training_source_popover"
            class="aipkit_training_source_popover"
            data-aipkit-training-source-popover
            role="dialog"
            aria-label="<?php esc_attr_e('Add knowledge source', 'gpt3-ai-content-generator'); ?>"
            hidden
        >
            <div class="aipkit_training_source_picker" data-aipkit-training-source-picker>
                <p class="aipkit_training_source_picker_label">
                    <?php esc_html_e('What kind of source?', 'gpt3-ai-content-generator'); ?>
                </p>
                <?php
                $aipkit_training_source_options = [
                    'website' => [
                        'label' => __('Website', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-admin-site-alt3',
                        'title' => __('From my website', 'gpt3-ai-content-generator'),
                        'description' => __('Sync posts, pages, or products.', 'gpt3-ai-content-generator'),
                    ],
                    'qa' => [
                        'label' => __('Q&A', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-format-chat',
                        'title' => __('Write a Q&A', 'gpt3-ai-content-generator'),
                        'description' => __('Add a question and answer.', 'gpt3-ai-content-generator'),
                    ],
                    'text' => [
                        'label' => __('Text', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-media-text',
                        'title' => __('Paste text', 'gpt3-ai-content-generator'),
                        'description' => __('Add raw text content.', 'gpt3-ai-content-generator'),
                    ],
                    'files' => [
                        'label' => __('Files', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-paperclip',
                        'title' => __('Upload files', 'gpt3-ai-content-generator'),
                        'description' => __('PDF, TXT, or DOCX.', 'gpt3-ai-content-generator'),
                    ],
                ];
                foreach ($aipkit_training_source_options as $aipkit_training_source_key => $aipkit_training_source_option) :
                    ?>
                    <button
                        type="button"
                        class="aipkit_training_source_picker_option"
                        data-aipkit-training-source-option="<?php echo esc_attr($aipkit_training_source_key); ?>"
                    >
                        <span class="aipkit_training_source_picker_icon dashicons <?php echo esc_attr($aipkit_training_source_option['icon']); ?>" aria-hidden="true"></span>
                        <span class="aipkit_training_source_picker_copy">
                            <strong><?php echo esc_html($aipkit_training_source_option['title']); ?></strong>
                            <span><?php echo esc_html($aipkit_training_source_option['description']); ?></span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="aipkit_training_source_form" data-aipkit-training-source-form hidden>
                <div class="aipkit_training_source_tabs" role="tablist" aria-label="<?php esc_attr_e('Knowledge source type', 'gpt3-ai-content-generator'); ?>">
                    <?php
                    foreach ($aipkit_training_source_options as $aipkit_training_source_key => $aipkit_training_source_option) :
                        ?>
                        <button
                            type="button"
                            class="aipkit_training_source_tab"
                            data-aipkit-training-source-option="<?php echo esc_attr($aipkit_training_source_key); ?>"
                            role="tab"
                            aria-selected="false"
                        >
                            <span class="dashicons <?php echo esc_attr($aipkit_training_source_option['icon']); ?>" aria-hidden="true"></span>
                            <span><?php echo esc_html($aipkit_training_source_option['label']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <select
                    id="aipkit_training_other_source_type"
                    class="aipkit_form-input aipkit_training_other_source_select"
                    data-aipkit-training-source-select
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <option value="qa"><?php esc_html_e('Q&A', 'gpt3-ai-content-generator'); ?></option>
                    <option value="text"><?php esc_html_e('Text', 'gpt3-ai-content-generator'); ?></option>
                    <option value="files"><?php esc_html_e('Files', 'gpt3-ai-content-generator'); ?></option>
                </select>

                <div class="aipkit_training_website_panel" data-aipkit-training-website-panel hidden>
                    <div class="aipkit_training_source_form_heading">
                        <strong><?php esc_html_e('Include content types', 'gpt3-ai-content-generator'); ?></strong>
                    </div>
                    <div id="aipkit_wp_content_bulk_panel" class="aipkit_training_site_field aipkit_training_site_field--menu">
                        <div class="aipkit_training_site_dropdown" data-aipkit-training-types="bulk">
                            <div id="aipkit_training_types_menu_bulk" class="aipkit_training_site_dropdown_panel">
                                <div id="aipkit_vs_wp_types_checkboxes" class="aipkit_training_site_checks aipkit_training_site_checks--dropdown">
                                    <?php foreach ($all_selectable_post_types as $post_type_slug => $post_type_obj) : ?>
                                        <label class="aipkit_training_site_check" data-ptype="<?php echo esc_attr($post_type_slug); ?>">
                                            <input type="checkbox" class="aipkit_wp_type_cb" value="<?php echo esc_attr($post_type_slug); ?>" <?php checked(in_array($post_type_slug, $aipkit_default_training_post_types, true)); ?> />
                                            <span class="aipkit_training_site_check_label"><?php echo esc_html($post_type_obj->label); ?></span>
                                            <span class="aipkit_count_badge" data-count="-1"></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <select id="aipkit_vs_wp_content_post_types" class="aipkit_training_site_hidden_select" multiple size="3">
                            <?php foreach ($all_selectable_post_types as $post_type_slug => $post_type_obj) : ?>
                                <option value="<?php echo esc_attr($post_type_slug); ?>" <?php selected(in_array($post_type_slug, $aipkit_default_training_post_types, true)); ?>>
                                    <?php echo esc_html($post_type_obj->label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aipkit_training_sheet_footer">
                        <span class="aipkit_training_status" data-aipkit-training-status aria-live="polite"></span>
                        <button
                            type="button"
                            class="aipkit_training_action_btn aipkit_training_decision_start"
                            data-training-action="add"
                            data-aipkit-training-start
                            data-aipkit-training-main-action
                        >
                            <span class="aipkit_training_action_spinner" aria-hidden="true"></span>
                            <span class="aipkit_training_action_text"><?php esc_html_e('Sync', 'gpt3-ai-content-generator'); ?></span>
                        </button>
                    </div>
                </div>

                <div class="aipkit_builder_tab_panels aipkit_builder_tab_panels--training aipkit_training_other_panels" data-aipkit-training-other-panels hidden>
                    <div class="aipkit_builder_tab_panel" data-aipkit-panel="qa" hidden>
                        <div class="aipkit_builder_training_qa">
                            <div class="aipkit_training_field">
                                <div class="aipkit_training_field_heading">
                                    <label class="aipkit_training_field_label" for="aipkit_training_qa_question"><?php esc_html_e('Question', 'gpt3-ai-content-generator'); ?></label>
                                    <span class="aipkit_training_field_help"><?php esc_html_e('What visitors may ask.', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <textarea id="aipkit_training_qa_question" class="aipkit_builder_textarea aipkit_training_textarea" rows="2" placeholder="<?php esc_attr_e('What is your return policy?', 'gpt3-ai-content-generator'); ?>"></textarea>
                                <div class="aipkit_training_common_questions">
                                    <button type="button" class="aipkit_training_common_toggle" data-aipkit-common-questions-toggle aria-expanded="false" aria-controls="aipkit_training_common_questions_list">
                                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                        <span class="aipkit_training_common_toggle_label"><?php esc_html_e('Add from common questions', 'gpt3-ai-content-generator'); ?></span>
                                    </button>
                                    <div id="aipkit_training_common_questions_list" class="aipkit_training_common_panel" data-aipkit-common-questions-panel hidden>
                                        <?php
                                        $aipkit_common_training_questions = [
                                            __('What is your return policy?', 'gpt3-ai-content-generator'),
                                            __('What is your refund policy?', 'gpt3-ai-content-generator'),
                                            __('What is your shipping info?', 'gpt3-ai-content-generator'),
                                            __('What is your warranty info?', 'gpt3-ai-content-generator'),
                                            __('What are your payment options?', 'gpt3-ai-content-generator'),
                                            __("What's your phone number?", 'gpt3-ai-content-generator'),
                                            __('What is your address?', 'gpt3-ai-content-generator'),
                                            __('What is your email?', 'gpt3-ai-content-generator'),
                                            __('What are your business hours?', 'gpt3-ai-content-generator'),
                                        ];
                                        foreach ($aipkit_common_training_questions as $aipkit_common_training_question) :
                                            ?>
                                            <button type="button" class="aipkit_training_common_question" data-aipkit-common-question="<?php echo esc_attr($aipkit_common_training_question); ?>">
                                                <?php echo esc_html($aipkit_common_training_question); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="aipkit_training_field">
                                <div class="aipkit_training_field_heading">
                                    <label class="aipkit_training_field_label" for="aipkit_training_qa_answer"><?php esc_html_e('Answer', 'gpt3-ai-content-generator'); ?></label>
                                    <span class="aipkit_training_field_help"><?php esc_html_e('The response your chatbot gives.', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <textarea id="aipkit_training_qa_answer" class="aipkit_builder_textarea aipkit_training_textarea" rows="3" placeholder="<?php esc_attr_e('We offer refunds within 30 days of purchase.', 'gpt3-ai-content-generator'); ?>"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="aipkit_builder_tab_panel" data-aipkit-panel="text" hidden>
                        <div class="aipkit_training_field">
                            <div class="aipkit_training_field_heading">
                                <label class="aipkit_training_field_label" for="aipkit_training_text_input"><?php esc_html_e('Content', 'gpt3-ai-content-generator'); ?></label>
                            </div>
                            <textarea id="aipkit_training_text_input" name="training_text" class="aipkit_builder_textarea aipkit_training_textarea aipkit_training_text_input" rows="5" placeholder="<?php esc_attr_e('Paste any text you want the chatbot to know.', 'gpt3-ai-content-generator'); ?>"></textarea>
                        </div>
                    </div>

                    <div class="aipkit_builder_tab_panel" data-aipkit-panel="files" hidden>
                        <div class="aipkit_training_field">
                            <div class="aipkit_builder_dropzone aipkit_training_dropzone">
                                <div class="aipkit_builder_dropzone_inner">
                                    <span class="dashicons dashicons-upload aipkit_training_dropzone_icon" aria-hidden="true"></span>
                                    <div class="aipkit_training_dropzone_copy">
                                        <strong><?php esc_html_e('Drop files or browse', 'gpt3-ai-content-generator'); ?></strong>
                                        <span><?php esc_html_e('PDF, DOCX, TXT, MD, CSV, or JSON', 'gpt3-ai-content-generator'); ?></span>
                                    </div>
                                    <?php if ($is_pro_plan) : ?>
                                        <?php
                                        $aipkit_file_upload_controls_path = WPAICG_PLUGIN_DIR . 'lib/views/knowledge-base/file-upload-controls.php';
                                        if (file_exists($aipkit_file_upload_controls_path)) {
                                            include $aipkit_file_upload_controls_path;
                                        }
                                        ?>
                                    <?php else : ?>
                                        <a class="aipkit_btn aipkit_btn-primary aipkit_builder_action_btn aipkit_training_files_button aipkit_pro_upgrade_button" href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="aipkit_training_file_queue" data-aipkit-training-file-queue hidden>
                            <div class="aipkit_training_file_queue_header">
                                <span><?php esc_html_e('Selected files', 'gpt3-ai-content-generator'); ?></span>
                                <span class="aipkit_training_file_queue_count" data-aipkit-training-file-count aria-live="polite"></span>
                            </div>
                            <div
                                class="aipkit_training_file_list"
                                id="aipkit_training_file_list"
                                data-upgrade-url="<?php echo esc_url($pricing_url); ?>"
                                role="list"
                                aria-label="<?php esc_attr_e('Files selected for upload', 'gpt3-ai-content-generator'); ?>"
                            ></div>
                        </div>
                    </div>
                </div>

                <div class="aipkit_training_sheet_footer" data-aipkit-training-other-footer hidden>
                    <span class="aipkit_training_status aipkit_training_sheet_status" data-aipkit-training-status aria-live="polite"></span>
                    <button
                        type="button"
                        class="aipkit_training_action_btn aipkit_training_decision_start aipkit_training_sheet_start"
                        data-training-action="add"
                        data-aipkit-training-start
                        data-aipkit-training-sheet-action
                    >
                        <span class="aipkit_training_action_spinner" aria-hidden="true"></span>
                        <span class="aipkit_training_action_text"><?php esc_html_e('Add source', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                </div>
            </div>
            <div
                class="aipkit_training_discard_prompt"
                data-aipkit-training-discard-prompt
                role="alertdialog"
                aria-modal="false"
                aria-labelledby="aipkit_chatbot_training_discard_title"
                aria-describedby="aipkit_chatbot_training_discard_message"
                hidden
            >
                <div class="aipkit_training_discard_panel">
                    <h3 class="aipkit_training_discard_title" id="aipkit_chatbot_training_discard_title">
                        <?php esc_html_e('Discard this source?', 'gpt3-ai-content-generator'); ?>
                    </h3>
                    <p class="aipkit_training_discard_message" id="aipkit_chatbot_training_discard_message">
                        <?php esc_html_e('Your source has not been added yet and will be lost.', 'gpt3-ai-content-generator'); ?>
                    </p>
                    <div class="aipkit_training_discard_actions">
                        <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_training_discard_keep">
                            <?php esc_html_e('Keep editing', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_btn aipkit_btn-danger aipkit_training_discard_confirm">
                            <?php esc_html_e('Discard', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="aipkit_training_source_overview" data-aipkit-training-source-overview>
            <div class="aipkit_training_source_empty" data-aipkit-training-source-empty>
                <span class="dashicons dashicons-database" aria-hidden="true"></span>
                <div>
                    <strong data-aipkit-training-source-empty-title><?php esc_html_e('No sources yet', 'gpt3-ai-content-generator'); ?></strong>
                    <span data-aipkit-training-source-empty-description><?php esc_html_e('Add website content, a Q&A, text, or files.', 'gpt3-ai-content-generator'); ?></span>
                </div>
            </div>
            <div class="aipkit_training_managed_sources" data-aipkit-training-managed-sources hidden></div>
        </div>

        <div class="aipkit_training_decision_actions">
            <div class="aipkit_training_action_meta">
                <div class="aipkit_training_state_wrap" data-aipkit-training-state-wrap>
                    <button
                        type="button"
                        class="aipkit_training_state is-pending"
                        data-aipkit-training-state
                        aria-live="polite"
                        aria-busy="true"
                        aria-haspopup="dialog"
                        aria-expanded="false"
                        aria-controls="aipkit_training_state_menu"
                        aria-label="<?php esc_attr_e('Knowledge status', 'gpt3-ai-content-generator'); ?>"
                        disabled
                    >
                        <span class="aipkit_training_state_dot" aria-hidden="true"></span>
                        <span class="aipkit_training_state_value" data-aipkit-training-state-value>
                            <?php esc_html_e('No knowledge yet', 'gpt3-ai-content-generator'); ?>
                        </span>
                        <span class="aipkit_training_state_help" aria-hidden="true">?</span>
                    </button>
                    <div
                        id="aipkit_training_state_menu"
                        class="aipkit_training_state_menu"
                        data-aipkit-training-state-menu
                        role="dialog"
                        aria-label="<?php esc_attr_e('Knowledge details', 'gpt3-ai-content-generator'); ?>"
                        hidden
                    >
                        <div class="aipkit_training_state_menu_title" data-aipkit-training-report-status>
                            <?php esc_html_e('Adding knowledge', 'gpt3-ai-content-generator'); ?>
                        </div>
                        <div class="aipkit_training_state_report">
                            <div class="aipkit_training_state_metrics">
                                <div class="aipkit_training_state_metric aipkit_training_state_metric--sources">
                                    <strong data-aipkit-training-report-trained>0</strong>
                                    <span><?php esc_html_e('Sources', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <div class="aipkit_training_state_metric aipkit_training_state_metric--processing">
                                    <strong data-aipkit-training-report-processing>0</strong>
                                    <span><?php esc_html_e('Processing', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <div class="aipkit_training_state_metric aipkit_training_state_metric--failed">
                                    <strong data-aipkit-training-report-failed>0</strong>
                                    <span><?php esc_html_e('Failed', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="aipkit_training_state_sources" data-aipkit-view-training-sources>
                            <span><?php esc_html_e('Manage sources', 'gpt3-ai-content-generator'); ?></span>
                            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                        </button>
                        <button type="button" class="aipkit_training_state_stop" data-aipkit-stop-training>
                            <?php esc_html_e('Stop training', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
                <span class="aipkit_training_status" id="aipkit_training_status" data-aipkit-training-status aria-live="polite"></span>
            </div>
        </div>
    </div>

    <select id="aipkit_vs_wp_content_status" class="aipkit_training_site_hidden_select" aria-hidden="true" tabindex="-1">
        <option value="publish" selected><?php esc_html_e('Published', 'gpt3-ai-content-generator'); ?></option>
    </select>
    <select id="aipkit_vs_global_target_select" class="aipkit_training_site_target_select" aria-hidden="true" tabindex="-1">
        <option value=""></option>
    </select>

    <button
        type="button"
        class="aipkit_training_sources_btn aipkit_builder_sheet_trigger"
        data-base-label="<?php echo esc_attr__('Knowledge sources', 'gpt3-ai-content-generator'); ?>"
        data-sheet-title="<?php echo esc_attr__('Knowledge sources', 'gpt3-ai-content-generator'); ?>"
        data-sheet-description="<?php echo esc_attr__('Review and manage what this chatbot knows.', 'gpt3-ai-content-generator'); ?>"
        data-sheet-content="sources"
        hidden
    >
        <span class="aipkit_training_sources_label">
            <?php echo esc_html__('Knowledge sources', 'gpt3-ai-content-generator'); ?>
        </span>
        <span class="aipkit_training_sources_count" aria-hidden="true">0</span>
    </button>
</section>
