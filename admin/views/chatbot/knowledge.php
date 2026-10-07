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
                    <?php esc_html_e('It answers from what you add here.', 'gpt3-ai-content-generator'); ?>
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

        <?php // One sentence on what it answers from; Knowledge scripts fill it from the training status and sources. ?>
        <div class="aipkit_knowledge_strip" data-aipkit-knowledge-strip data-tone="neutral" hidden>
            <span class="aipkit_knowledge_strip_dot" aria-hidden="true"></span>
            <span class="aipkit_knowledge_strip_text" data-aipkit-knowledge-strip-text aria-live="polite"></span>
            <button type="button" class="aipkit_knowledge_strip_action" data-aipkit-knowledge-strip-action hidden></button>
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
            <?php // What you can add, in plain words; the website choice says what is learned (Knowledge scripts). ?>
            <div class="aipkit_training_source_picker" data-aipkit-training-source-picker>
                <?php
                $aipkit_training_source_options = [
                    'website' => [
                        'label' => __('Website', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-admin-site-alt3',
                        'title' => __('Your website', 'gpt3-ai-content-generator'),
                        'description' => __('Learn posts, pages or products.', 'gpt3-ai-content-generator'),
                    ],
                    'qa' => [
                        'label' => __('Q&A', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-format-chat',
                        'title' => __('A question and answer', 'gpt3-ai-content-generator'),
                        'description' => __('Write what visitors ask, and what it says.', 'gpt3-ai-content-generator'),
                    ],
                    'text' => [
                        'label' => __('Text', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-media-text',
                        'title' => __('Text', 'gpt3-ai-content-generator'),
                        'description' => __('Paste opening hours, policies or prices.', 'gpt3-ai-content-generator'),
                    ],
                    'files' => [
                        'label' => __('Files', 'gpt3-ai-content-generator'),
                        'icon' => 'dashicons-paperclip',
                        'title' => __('Files', 'gpt3-ai-content-generator'),
                        'description' => __('PDF, Word or text files.', 'gpt3-ai-content-generator'),
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
                            <span data-aipkit-training-source-hint><?php echo esc_html($aipkit_training_source_option['description']); ?></span>
                        </span>
                        <?php if ($aipkit_training_source_key === 'website') : ?>
                            <span class="aipkit_training_source_picker_tag" data-aipkit-training-source-start hidden><?php esc_html_e('Start here', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="aipkit_training_source_form" data-aipkit-training-source-form hidden>
                <div
                    class="aipkit_training_source_sheet_header"
                    data-aipkit-training-source-sheet-header
                    data-title-website="<?php esc_attr_e('Learn your website', 'gpt3-ai-content-generator'); ?>"
                    data-hint-website="<?php esc_attr_e('Pick the content your chatbot should know.', 'gpt3-ai-content-generator'); ?>"
                    data-title-qa="<?php esc_attr_e('Add a question and answer', 'gpt3-ai-content-generator'); ?>"
                    data-hint-qa="<?php esc_attr_e('Best for facts people ask often: prices, hours, policies.', 'gpt3-ai-content-generator'); ?>"
                    data-title-text="<?php esc_attr_e('Add text', 'gpt3-ai-content-generator'); ?>"
                    data-hint-text="<?php esc_attr_e('Paste policies, notes or anything else it should know.', 'gpt3-ai-content-generator'); ?>"
                    data-title-files="<?php esc_attr_e('Upload files', 'gpt3-ai-content-generator'); ?>"
                    data-hint-files="<?php esc_attr_e('PDF, Word, text, Markdown, CSV or JSON files.', 'gpt3-ai-content-generator'); ?>"
                >
                    <div class="aipkit_training_source_sheet_copy">
                        <h3 class="aipkit_training_source_sheet_title" data-aipkit-training-source-sheet-title></h3>
                        <p class="aipkit_training_source_sheet_hint" data-aipkit-training-source-sheet-hint></p>
                    </div>
                    <button type="button" class="aipkit_training_source_sheet_close" data-aipkit-training-source-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </div>
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
                    <?php
                    // Pages, posts and products cover most sites; other types (often builder templates) fold away unless already chosen.
                    $aipkit_training_main_types = [];
                    $aipkit_training_more_types = [];
                    foreach ($all_selectable_post_types as $post_type_slug => $post_type_obj) {
                        $aipkit_training_type_counts = wp_count_posts($post_type_slug);
                        $aipkit_training_type = [
                            'label' => $post_type_obj->label,
                            'count' => isset($aipkit_training_type_counts->publish) ? (int) $aipkit_training_type_counts->publish : 0,
                            'checked' => in_array($post_type_slug, $aipkit_default_training_post_types, true),
                        ];
                        if ($aipkit_training_type['checked'] || in_array($post_type_slug, ['page', 'post', 'product'], true)) {
                            $aipkit_training_main_types[$post_type_slug] = $aipkit_training_type;
                        } else {
                            $aipkit_training_more_types[$post_type_slug] = $aipkit_training_type;
                        }
                    }
                    uasort($aipkit_training_more_types, static function ($a, $b) {
                        return $b['count'] <=> $a['count'];
                    });
                    $aipkit_training_type_row = static function ($slug, $type) {
                        ?>
                        <label class="aipkit_training_site_check">
                            <input type="checkbox" class="aipkit_wp_type_cb" value="<?php echo esc_attr($slug); ?>" data-count="<?php echo esc_attr((string) $type['count']); ?>" <?php checked($type['checked']); ?> />
                            <span class="aipkit_training_site_check_label"><?php echo esc_html($type['label']); ?></span>
                            <span class="aipkit_training_site_check_count">
                                <?php
                                /* translators: %s: number of published items of this content type. */
                                echo esc_html(sprintf(__('%s published', 'gpt3-ai-content-generator'), number_format_i18n($type['count'])));
                                ?>
                            </span>
                        </label>
                        <?php
                    };
                    ?>
                    <div class="aipkit_training_website_body">
                        <p class="aipkit_training_source_form_heading"><?php esc_html_e('What should it learn from?', 'gpt3-ai-content-generator'); ?></p>
                        <div id="aipkit_wp_content_bulk_panel" class="aipkit_training_site_field aipkit_training_site_field--menu">
                            <div class="aipkit_training_site_dropdown" data-aipkit-training-types="bulk">
                                <div id="aipkit_training_types_menu_bulk" class="aipkit_training_site_dropdown_panel">
                                    <div id="aipkit_vs_wp_types_checkboxes" class="aipkit_training_site_checks">
                                        <?php foreach ($aipkit_training_main_types as $post_type_slug => $aipkit_training_type) : ?>
                                            <?php $aipkit_training_type_row($post_type_slug, $aipkit_training_type); ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (!empty($aipkit_training_more_types)) : ?>
                                        <details class="aipkit_training_site_more">
                                            <summary class="aipkit_training_site_more_summary">
                                                <?php
                                                /* translators: %d: number of other content types. */
                                                echo esc_html(sprintf(_n('Show %d more content type', 'Show %d more content types', count($aipkit_training_more_types), 'gpt3-ai-content-generator'), count($aipkit_training_more_types)));
                                                ?>
                                            </summary>
                                            <div class="aipkit_training_site_checks">
                                                <?php foreach ($aipkit_training_more_types as $post_type_slug => $aipkit_training_type) : ?>
                                                    <?php $aipkit_training_type_row($post_type_slug, $aipkit_training_type); ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
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
                        <p class="aipkit_training_source_note">
                            <span class="dashicons dashicons-update" aria-hidden="true"></span>
                            <span><?php esc_html_e('Only published content is used. When you change your site, use Learn again on the Knowledge card.', 'gpt3-ai-content-generator'); ?></span>
                        </p>
                    </div>
                    <div class="aipkit_training_sheet_footer">
                        <span class="aipkit_training_website_summary" data-aipkit-training-website-summary data-one="<?php esc_attr_e('1 item selected', 'gpt3-ai-content-generator'); ?>" data-many="<?php /* translators: %s: number of selected items. */ esc_attr_e('%s items selected', 'gpt3-ai-content-generator'); ?>"></span>
                        <span class="aipkit_training_status" data-aipkit-training-status aria-live="polite"></span>
                        <button type="button" class="aipkit_training_cancel_btn" data-aipkit-training-source-cancel><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></button>
                        <button
                            type="button"
                            class="aipkit_training_action_btn aipkit_training_decision_start"
                            data-training-action="add"
                            data-aipkit-training-start
                            data-aipkit-training-main-action
                        >
                            <span class="aipkit_training_action_spinner" aria-hidden="true"></span>
                            <span class="aipkit_training_action_text"><?php esc_html_e('Start learning', 'gpt3-ai-content-generator'); ?></span>
                        </button>
                    </div>
                </div>

                <div class="aipkit_builder_tab_panels aipkit_builder_tab_panels--training aipkit_training_other_panels" data-aipkit-training-other-panels hidden>
                    <div class="aipkit_builder_tab_panel" data-aipkit-panel="qa" hidden>
                        <div class="aipkit_builder_training_qa">
                            <div class="aipkit_training_common_questions">
                                <span class="aipkit_training_common_label" id="aipkit_training_common_questions_label"><?php esc_html_e('Start from a common question', 'gpt3-ai-content-generator'); ?></span>
                                <div id="aipkit_training_common_questions_list" class="aipkit_training_common_panel" data-aipkit-common-questions-panel role="group" aria-labelledby="aipkit_training_common_questions_label">
                                    <?php
                                    $aipkit_common_training_questions = [
                                        __('Return policy', 'gpt3-ai-content-generator') => __('What is your return policy?', 'gpt3-ai-content-generator'),
                                        __('Refunds', 'gpt3-ai-content-generator') => __('What is your refund policy?', 'gpt3-ai-content-generator'),
                                        __('Shipping', 'gpt3-ai-content-generator') => __('What is your shipping info?', 'gpt3-ai-content-generator'),
                                        __('Warranty', 'gpt3-ai-content-generator') => __('What is your warranty info?', 'gpt3-ai-content-generator'),
                                        __('Payment options', 'gpt3-ai-content-generator') => __('What are your payment options?', 'gpt3-ai-content-generator'),
                                        __('Business hours', 'gpt3-ai-content-generator') => __('What are your business hours?', 'gpt3-ai-content-generator'),
                                        __('Phone number', 'gpt3-ai-content-generator') => __("What's your phone number?", 'gpt3-ai-content-generator'),
                                        __('Address', 'gpt3-ai-content-generator') => __('What is your address?', 'gpt3-ai-content-generator'),
                                        __('Email', 'gpt3-ai-content-generator') => __('What is your email?', 'gpt3-ai-content-generator'),
                                    ];
                                    foreach ($aipkit_common_training_questions as $aipkit_common_training_label => $aipkit_common_training_question) :
                                        ?>
                                        <button type="button" class="aipkit_training_common_question" data-aipkit-common-question="<?php echo esc_attr($aipkit_common_training_question); ?>">
                                            <?php echo esc_html($aipkit_common_training_label); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="aipkit_training_field">
                                <div class="aipkit_training_field_heading">
                                    <label class="aipkit_training_field_label" for="aipkit_training_qa_question"><?php esc_html_e('Question', 'gpt3-ai-content-generator'); ?></label>
                                    <span class="aipkit_training_field_help"><?php esc_html_e('What visitors may ask.', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <textarea id="aipkit_training_qa_question" class="aipkit_builder_textarea aipkit_training_textarea" rows="2" placeholder="<?php esc_attr_e('What is your return policy?', 'gpt3-ai-content-generator'); ?>"></textarea>
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
                    <button type="button" class="aipkit_training_cancel_btn" data-aipkit-training-source-cancel><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit_training_cancel_btn aipkit_training_add_another_btn" data-aipkit-training-add-another hidden><?php esc_html_e('Save and add another', 'gpt3-ai-content-generator'); ?></button>
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
                    <strong data-aipkit-training-source-empty-title><?php esc_html_e('Start with your website', 'gpt3-ai-content-generator'); ?></strong>
                    <span data-aipkit-training-source-empty-description><?php esc_html_e('Most sites are learned in about a minute.', 'gpt3-ai-content-generator'); ?></span>
                </div>
                <button type="button" class="aipkit_training_source_empty_action" data-aipkit-training-source-add-again="website">
                    <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                    <span><?php esc_html_e('Learn my website', 'gpt3-ai-content-generator'); ?></span>
                </button>
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
        data-base-label="<?php echo esc_attr__('What it knows', 'gpt3-ai-content-generator'); ?>"
        data-sheet-title="<?php echo esc_attr__('What it knows', 'gpt3-ai-content-generator'); ?>"
        data-sheet-description="<?php echo esc_attr__('Everything this chatbot answers from.', 'gpt3-ai-content-generator'); ?>"
        data-sheet-content="sources"
        hidden
    >
        <span class="aipkit_training_sources_label">
            <?php echo esc_html__('Knowledge sources', 'gpt3-ai-content-generator'); ?>
        </span>
        <span class="aipkit_training_sources_count" aria-hidden="true">0</span>
    </button>
    <?php if (!empty($aipkit_knowledge_search_settings)) : ?>
        <?php // Search settings opens a side panel; the chatbot page renders it with the tab's other panels. ?>
        <div class="aipkit_knowledge_rows">
            <?php
            $render_feature_row([
                'key' => 'search',
                'icon' => 'search',
                'title' => __('Search settings', 'gpt3-ai-content-generator'),
                'hint' => __("What answers draw on and how knowledge is searched.", 'gpt3-ai-content-generator'),
            ]);
            ?>
        </div>
    <?php endif; ?>
    <?php unset($aipkit_knowledge_search_settings); ?>
</section>
