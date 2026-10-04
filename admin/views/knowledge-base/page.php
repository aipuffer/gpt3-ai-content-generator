<?php
/**
 * AIPKit Sources Module - Admin View
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$is_pro_plan = class_exists('\\WPAICG\\aipkit_dashboard')
    ? \WPAICG\aipkit_dashboard::is_pro_plan()
    : false;
// Search configuration and indexing settings affect the entire site.
$can_manage_knowledge_settings = \WPAICG\AIPKit_Role_Manager::user_can_access_module('settings');
$upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
$post_types_args = ['public' => true];
$all_selectable_post_types = get_post_types($post_types_args, 'objects');
$all_selectable_post_types = array_filter($all_selectable_post_types, function ($post_type_obj) {
    return $post_type_obj->name !== 'attachment';
});
?>
<div
    class="aipkit_container aipkit_sources_container"
    id="aipkit_sources_module_container"
    data-aipkit-sources-active-tab="data"
>
    <div class="aipkit_container-header">
        <div class="aipkit_container-header-left">
            <div class="aipkit_sources_header_copy">
                <div class="aipkit_sources_header_title_row">
                    <div class="aipkit_container-title"><?php esc_html_e('Knowledge base', 'gpt3-ai-content-generator'); ?></div>
                    <span id="aipkit_sources_status" class="aipkit_training_status aipkit_global_status_area" aria-live="polite"></span>
                </div>
                <p class="aipkit_sources_header_hint"><?php esc_html_e('Manage the data sources and vector stores that power your knowledge base.', 'gpt3-ai-content-generator'); ?></p>
            </div>
        </div>
    </div>
    <div class="aipkit_container-body" id="aipkit_sources_container_body">
        <div class="aipkit_chatbot_builder aipkit_admin_ui" data-aipkit-context="sources">
            <?php include WPAICG_PLUGIN_DIR . 'admin/views/shared/vector-store-nonce-fields.php'; ?>
            <div class="aipkit_sources_workspace_bar">
                <div class="aipkit_sources_workspace_tabs" role="tablist" aria-label="<?php esc_attr_e('Knowledge base sections', 'gpt3-ai-content-generator'); ?>">
                    <button type="button" class="aipkit_sources_workspace_tab is-active" id="aipkit_sources_data_tab" role="tab" aria-selected="true" aria-controls="aipkit_sources_data_panel" data-aipkit-sources-tab="data">
                        <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                        <?php esc_html_e('Data', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <button type="button" class="aipkit_sources_workspace_tab" id="aipkit_sources_stores_tab" role="tab" aria-selected="false" aria-controls="aipkit_sources_stores_panel" data-aipkit-sources-tab="stores">
                        <span class="dashicons dashicons-archive" aria-hidden="true"></span>
                        <?php esc_html_e('Stores', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <?php if ($can_manage_knowledge_settings) : ?>
                    <button type="button" class="aipkit_sources_workspace_tab" id="aipkit_sources_search_tab" role="tab" aria-selected="false" aria-controls="aipkit_sources_search_panel" data-aipkit-sources-tab="search">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <?php esc_html_e('Search', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <button type="button" class="aipkit_sources_workspace_tab" id="aipkit_sources_settings_tab" role="tab" aria-selected="false" aria-controls="aipkit_sources_settings_panel" data-aipkit-sources-tab="settings">
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                        <?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="aipkit_sources_meta aipkit_sources_workspace_tools is-active" data-aipkit-sources-tools="data" aria-label="<?php esc_attr_e('Data tools', 'gpt3-ai-content-generator'); ?>">
                    <label class="screen-reader-text" for="aipkit_sources_provider_filter">
                        <?php esc_html_e('Provider filter', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <select
                        id="aipkit_sources_provider_filter"
                        class="aipkit_sources_filter_select aipkit_sources_native_filter_select"
                    >
                        <option value=""><?php esc_html_e('All providers', 'gpt3-ai-content-generator'); ?></option>
                        <option value="local"><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                        <option value="openai"><?php esc_html_e('OpenAI', 'gpt3-ai-content-generator'); ?></option>
                        <option value="google"><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                        <option value="pinecone"><?php esc_html_e('Pinecone', 'gpt3-ai-content-generator'); ?></option>
                        <option value="qdrant"><?php esc_html_e('Qdrant', 'gpt3-ai-content-generator'); ?></option>
                        <option value="chroma"><?php esc_html_e('Chroma', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                    <label class="screen-reader-text" for="aipkit_sources_index_filter">
                        <?php esc_html_e('Index selection', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <select
                        id="aipkit_sources_index_filter"
                        class="aipkit_sources_filter_select aipkit_sources_native_filter_select"
                        hidden
                        disabled
                    >
                        <option value=""><?php esc_html_e('All indexes', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                    <label class="screen-reader-text" for="aipkit_sources_search_input">
                        <?php esc_html_e('Search sources', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_sources_search_control">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input
                            type="search"
                            id="aipkit_sources_search_input"
                            class="aipkit_sources_search_input"
                            placeholder="<?php esc_attr_e('Search', 'gpt3-ai-content-generator'); ?>"
                        >
                    </span>
                    <span class="aipkit_sources_meta_action">
                        <button
                            type="button"
                            class="aipkit_btn aipkit_btn-primary aipkit_btn-small"
                            id="aipkit_sources_training_toggle"
                            aria-expanded="false"
                            aria-controls="aipkit_sources_training_panel"
                        >
                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                            <?php esc_html_e('Add source', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </span>
                </div>
                <div class="aipkit_sources_meta aipkit_sources_workspace_tools aipkit_sources_stores_meta" data-aipkit-sources-tools="stores" aria-label="<?php esc_attr_e('Store tools', 'gpt3-ai-content-generator'); ?>" hidden>
                    <label class="screen-reader-text" for="aipkit_sources_store_provider_filter">
                        <?php esc_html_e('Provider filter', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <select
                        id="aipkit_sources_store_provider_filter"
                        class="aipkit_sources_filter_select aipkit_sources_native_filter_select"
                    >
                        <option value=""><?php esc_html_e('All providers', 'gpt3-ai-content-generator'); ?></option>
                        <option value="local"><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                        <option value="openai"><?php esc_html_e('OpenAI', 'gpt3-ai-content-generator'); ?></option>
                        <option value="google"><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                        <option value="pinecone"><?php esc_html_e('Pinecone', 'gpt3-ai-content-generator'); ?></option>
                        <option value="qdrant"><?php esc_html_e('Qdrant', 'gpt3-ai-content-generator'); ?></option>
                        <option value="chroma"><?php esc_html_e('Chroma', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                    <button
                        type="button"
                        id="aipkit_sources_stores_refresh"
                        class="aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_sources_sync_btn"
                    >
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <span class="aipkit_btn_label"><?php esc_html_e('Refresh', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <span class="aipkit_sources_meta_action">
                        <button type="button" id="aipkit_sources_create_store" class="aipkit_btn aipkit_btn-primary aipkit_btn-small">
                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                            <?php esc_html_e('Create store', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </span>
                </div>
            </div>
            <div id="aipkit_sources_data_panel" class="aipkit_sources_workspace_panel is-active" role="tabpanel" aria-labelledby="aipkit_sources_data_tab">
            <div
                class="aipkit-modal-overlay aipkit_sources_training_modal"
                id="aipkit_sources_training_panel"
                aria-hidden="true"
                hidden
            >
                <div
                    class="aipkit-modal-content aipkit_sources_training_modal_content"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="aipkit_sources_training_panel_title"
                    aria-describedby="aipkit_sources_training_panel_description"
                >
                    <div class="aipkit-modal-header aipkit_sources_training_inline_header">
                        <div class="aipkit_sources_inline_heading">
                            <h2 class="aipkit-modal-title aipkit_sources_inline_title" id="aipkit_sources_training_panel_title">
                                <?php esc_html_e('Add source', 'gpt3-ai-content-generator'); ?>
                            </h2>
                            <p class="aipkit_sources_inline_subtitle" id="aipkit_sources_training_panel_description">
                                <?php esc_html_e('Add a new source and choose where it is indexed.', 'gpt3-ai-content-generator'); ?>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="aipkit-modal-close-btn aipkit_sources_training_close"
                            aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                <div id="aipkit_sources_training_card">
                    <div class="aipkit_sources_training_inline_body">
                        <div class="aipkit_builder_card_body">
                    <div class="aipkit_sources_training_content">
                    <div class="aipkit_sources_training_section_label"><?php esc_html_e('Source', 'gpt3-ai-content-generator'); ?></div>
                    <div class="aipkit_builder_tabs aipkit_builder_tabs--training" role="tablist" aria-label="<?php esc_attr_e('Training sources', 'gpt3-ai-content-generator'); ?>" data-aipkit-tabs="training">
                        <button type="button" class="aipkit_builder_tab is-active" role="tab" aria-selected="true" data-aipkit-tab="qa">
                            <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                            <?php esc_html_e('Q&A', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_builder_tab" role="tab" aria-selected="false" data-aipkit-tab="text">
                            <span class="dashicons dashicons-media-text" aria-hidden="true"></span>
                            <?php esc_html_e('Text', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_builder_tab" role="tab" aria-selected="false" data-aipkit-tab="website">
                            <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                            <?php esc_html_e('Website', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_builder_tab" role="tab" aria-selected="false" data-aipkit-tab="files">
                            <span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
                            <?php esc_html_e('Files', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>

                    <div class="aipkit_builder_tab_panels aipkit_builder_tab_panels--training">
                        <div class="aipkit_builder_tab_panel is-active" data-aipkit-panel="qa">
                            <div class="aipkit_builder_training_qa">
                                <div class="aipkit_training_field">
                                    <div class="aipkit_training_field_heading">
                                        <label class="aipkit_training_field_label" for="aipkit_training_qa_question"><?php esc_html_e('Question', 'gpt3-ai-content-generator'); ?></label>
                                        <span class="aipkit_training_field_help"><?php esc_html_e('What visitors may ask.', 'gpt3-ai-content-generator'); ?></span>
                                    </div>
                                    <textarea
                                        id="aipkit_training_qa_question"
                                        class="aipkit_builder_textarea aipkit_training_textarea"
                                        rows="2"
                                        placeholder="<?php esc_attr_e('What is your return policy?', 'gpt3-ai-content-generator'); ?>"
                                    ></textarea>
                                    <div class="aipkit_training_common_questions">
                                        <button type="button" class="aipkit_training_common_toggle" data-aipkit-common-questions-toggle aria-expanded="false" aria-controls="aipkit_sources_training_common_questions_list">
                                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                            <span class="aipkit_training_common_toggle_label"><?php esc_html_e('Add from common questions', 'gpt3-ai-content-generator'); ?></span>
                                        </button>
                                        <div id="aipkit_sources_training_common_questions_list" class="aipkit_training_common_panel" data-aipkit-common-questions-panel hidden>
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
                                    <textarea
                                        id="aipkit_training_qa_answer"
                                        class="aipkit_builder_textarea aipkit_training_textarea"
                                        rows="3"
                                        placeholder="<?php esc_attr_e('We offer refunds within 30 days of purchase.', 'gpt3-ai-content-generator'); ?>"
                                    ></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="aipkit_builder_tab_panel" data-aipkit-panel="text" hidden>
                            <div class="aipkit_training_field">
                                <div class="aipkit_training_field_heading">
                                    <label class="aipkit_training_field_label" for="aipkit_training_text_input"><?php esc_html_e('Content', 'gpt3-ai-content-generator'); ?></label>
                                </div>
                                <textarea
                                    id="aipkit_training_text_input"
                                    name="training_text"
                                    class="aipkit_builder_textarea aipkit_training_textarea aipkit_training_text_input"
                                    rows="5"
                                    placeholder="<?php esc_attr_e('Paste any text you want the chatbot to know.', 'gpt3-ai-content-generator'); ?>"
                                ></textarea>
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
                                        <?php if ( $is_pro_plan ) : ?>
                                            <?php
                                            $aipkit_file_upload_controls_path = WPAICG_PLUGIN_DIR . 'lib/views/knowledge-base/file-upload-controls.php';
                                            if (file_exists($aipkit_file_upload_controls_path)) {
                                                include $aipkit_file_upload_controls_path;
                                            }
                                            ?>
                                        <?php else : ?>
                                            <a
                                                class="aipkit_btn aipkit_btn-primary aipkit_builder_action_btn aipkit_training_files_button aipkit_pro_upgrade_button"
                                                href="<?php echo esc_url($upgrade_url); ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                                            </a>
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
                                    data-upgrade-url="<?php echo esc_url($upgrade_url); ?>"
                                    role="list"
                                    aria-label="<?php esc_attr_e('Files selected for upload', 'gpt3-ai-content-generator'); ?>"
                                ></div>
                            </div>
                        </div>
                        <div class="aipkit_builder_tab_panel" data-aipkit-panel="website" hidden>
                            <div class="aipkit_training_website_panel">
                                <div class="aipkit_training_source_form_heading">
                                    <strong><?php esc_html_e('Include content types', 'gpt3-ai-content-generator'); ?></strong>
                                </div>
                                <div id="aipkit_wp_content_bulk_panel" class="aipkit_training_site_field aipkit_training_site_field--menu">
                                    <div class="aipkit_training_site_dropdown" data-aipkit-training-types="bulk">
                                        <div class="aipkit_training_site_dropdown_panel">
                                                <div id="aipkit_vs_wp_types_checkboxes" class="aipkit_training_site_checks aipkit_training_site_checks--dropdown">
                                                    <?php foreach ($all_selectable_post_types as $post_type_slug => $post_type_obj) : ?>
                                                        <label class="aipkit_training_site_check" data-ptype="<?php echo esc_attr($post_type_slug); ?>">
                                                            <input type="checkbox" class="aipkit_wp_type_cb" value="<?php echo esc_attr($post_type_slug); ?>" <?php checked(in_array($post_type_slug, ['post', 'page'], true)); ?> />
                                                            <span class="aipkit_training_site_check_label"><?php echo esc_html($post_type_obj->label); ?></span>
                                                            <span class="aipkit_count_badge" data-count="-1"></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                        </div>
                                    </div>
                                    <select id="aipkit_vs_wp_content_post_types" class="aipkit_training_site_hidden_select" multiple size="3">
                                        <?php foreach ($all_selectable_post_types as $post_type_slug => $post_type_obj) : ?>
                                            <option value="<?php echo esc_attr($post_type_slug); ?>" <?php selected(in_array($post_type_slug, ['post', 'page'], true)); ?>>
                                                <?php echo esc_html($post_type_obj->label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <select id="aipkit_vs_wp_content_status" class="aipkit_training_site_hidden_select" aria-hidden="true" tabindex="-1">
                                    <option value="publish" selected><?php esc_html_e('Published', 'gpt3-ai-content-generator'); ?></option>
                                </select>
                                <div id="aipkit_vs_wp_content_messages_area" class="aipkit_form-help aipkit_training_site_status" aria-live="polite"></div>
                                <select id="aipkit_vs_global_target_select" class="aipkit_training_site_target_select" aria-hidden="true" tabindex="-1"></select>
                            </div>
                        </div>
                    </div>
                    <div class="aipkit_sources_training_target">
                        <div class="aipkit_sources_training_section_label"><?php esc_html_e('Destination', 'gpt3-ai-content-generator'); ?></div>
                        <div class="aipkit_sources_training_target_controls">
                            <span class="aipkit_sources_training_control aipkit_sources_training_control--provider">
                                <label class="aipkit_sources_training_control_label" for="aipkit_sources_training_provider">
                                    <?php esc_html_e('Provider', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <select id="aipkit_sources_training_provider" class="aipkit_sources_filter_select aipkit_sources_training_select">
                                    <option value="local"><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="openai"><?php esc_html_e('OpenAI', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="google"><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="pinecone"><?php esc_html_e('Pinecone', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="qdrant"><?php esc_html_e('Qdrant', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="chroma"><?php esc_html_e('Chroma', 'gpt3-ai-content-generator'); ?></option>
                                </select>
                            </span>
                            <span class="aipkit_sources_training_control aipkit_sources_training_control--store">
                                <label class="aipkit_sources_training_control_label" id="aipkit_sources_training_store_label" for="aipkit_sources_training_store">
                                    <?php esc_html_e('Vector store', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <select id="aipkit_sources_training_store" class="aipkit_sources_filter_select aipkit_sources_training_select">
                                    <option value=""><?php esc_html_e('Select store', 'gpt3-ai-content-generator'); ?></option>
                                </select>
                            </span>
                            <span class="aipkit_sources_training_control aipkit_sources_training_control--embedding" id="aipkit_sources_embedding_row" hidden>
                                <label class="aipkit_sources_training_control_label" for="aipkit_sources_embedding_model">
                                    <?php esc_html_e('Embedding model', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <select id="aipkit_sources_embedding_model" class="aipkit_sources_filter_select aipkit_sources_training_select" data-aipkit-universal-model-combined="1" data-aipkit-universal-model-capability="embeddings">
                                    <option value=""><?php esc_html_e('Select embedding model', 'gpt3-ai-content-generator'); ?></option>
                                </select>
                            </span>
                            <button
                                type="button"
                                class="aipkit_sources_training_refresh_stores"
                                id="aipkit_sources_training_refresh_stores"
                                title="<?php esc_attr_e('Sync', 'gpt3-ai-content-generator'); ?>"
                            >
                                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                <span class="aipkit_btn_label screen-reader-text"><?php esc_html_e('Sync', 'gpt3-ai-content-generator'); ?></span>
                            </button>
                        </div>
                        <div class="aipkit_sources_training_create_store_section" id="aipkit_sources_training_create_store_section">
                            <button
                                type="button"
                                class="aipkit_sources_training_create_store"
                                id="aipkit_sources_training_create_store"
                                aria-expanded="false"
                                aria-controls="aipkit_sources_training_create_store_panel"
                            >
                                <span class="aipkit_sources_training_create_store_plus" aria-hidden="true">+</span>
                                <span class="aipkit_sources_training_create_store_label"><?php esc_html_e('Create a new store', 'gpt3-ai-content-generator'); ?></span>
                                <span class="dashicons dashicons-arrow-down-alt2 aipkit_sources_training_create_store_chevron" aria-hidden="true"></span>
                            </button>
                            <div
                                class="aipkit_sources_training_create_store_panel"
                                id="aipkit_sources_training_create_store_panel"
                                hidden
                            >
                                <div class="aipkit_sources_training_create_store_fields">
                                    <label class="aipkit_sources_training_create_store_field" for="aipkit_sources_training_create_store_name">
                                        <span class="aipkit_sources_training_control_label"><?php esc_html_e('Name', 'gpt3-ai-content-generator'); ?></span>
                                        <input
                                            type="text"
                                            id="aipkit_sources_training_create_store_name"
                                            class="aipkit_form-input"
                                            placeholder="<?php esc_attr_e('my-knowledge-store', 'gpt3-ai-content-generator'); ?>"
                                        >
                                    </label>
                                    <label
                                        class="aipkit_sources_training_create_store_field"
                                        id="aipkit_sources_training_create_store_dimension_row"
                                        for="aipkit_sources_training_create_store_dimension"
                                    >
                                        <span class="aipkit_sources_training_control_label"><?php esc_html_e('Dimension', 'gpt3-ai-content-generator'); ?></span>
                                        <input
                                            type="text"
                                            id="aipkit_sources_training_create_store_dimension"
                                            class="aipkit_form-input"
                                            min="1"
                                            step="1"
                                            value="<?php echo esc_attr__('Not required', 'gpt3-ai-content-generator'); ?>"
                                            disabled
                                        >
                                    </label>
                                </div>
                                <div class="aipkit_sources_training_create_store_footer">
                                    <span
                                        class="aipkit_sources_training_create_store_status aipkit_form-help"
                                        id="aipkit_sources_training_create_store_status"
                                        aria-live="polite"
                                    ></span>
                                    <button
                                        type="button"
                                        class="aipkit_btn aipkit_sources_training_create_store_submit"
                                        id="aipkit_sources_training_create_store_submit"
                                    >
                                        <?php esc_html_e('Create store', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="aipkit_sources_training_target_empty" id="aipkit_sources_training_store_empty" hidden>
                            <?php esc_html_e('No stores found for this provider. Create one to start adding a source.', 'gpt3-ai-content-generator'); ?>
                        </div>
                    </div>
                    <div class="aipkit_sources_training_inline_footer aipkit_training_footer">
                        <div class="aipkit_sources_training_footer_meta">
                            <span id="aipkit_training_status" class="aipkit_training_status" aria-live="polite"></span>
                        </div>
                        <div class="aipkit_builder_action_row aipkit_training_action_row">
                            <div class="aipkit_builder_action_group aipkit_training_primary_actions">
                                <button
                                    type="button"
                                    class="aipkit_btn aipkit_btn-secondary aipkit_sources_training_cancel"
                                >
                                    <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                                </button>
                                <button
                                    type="button"
                                    class="aipkit_btn aipkit_btn-primary aipkit_builder_action_btn aipkit_training_action_btn"
                                    data-training-action="add"
                                >
                                    <span class="aipkit_training_action_spinner" aria-hidden="true"></span>
                                    <span class="aipkit_btn_label"><?php esc_html_e('Sync', 'gpt3-ai-content-generator'); ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                    </div>
                            </div>
                        </div>
                    </div>
            </div>
                <div
                    class="aipkit_training_discard_prompt"
                    id="aipkit_sources_training_discard_prompt"
                    role="alertdialog"
                    aria-modal="false"
                    aria-labelledby="aipkit_sources_training_discard_title"
                    aria-describedby="aipkit_sources_training_discard_message"
                    hidden
                >
                    <div class="aipkit_training_discard_panel">
                        <h3 class="aipkit_training_discard_title" id="aipkit_sources_training_discard_title">
                            <?php esc_html_e('Discard this source?', 'gpt3-ai-content-generator'); ?>
                        </h3>
                        <p class="aipkit_training_discard_message" id="aipkit_sources_training_discard_message">
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
            <div class="aipkit_sources_bulk_bar" id="aipkit_sources_bulk_bar" hidden>
                <div class="aipkit_sources_bulk_text">
                    <span class="aipkit_sources_bulk_count" id="aipkit_sources_bulk_count">
                        <?php esc_html_e('0 selected', 'gpt3-ai-content-generator'); ?>
                    </span>
                    <span class="aipkit_sources_bulk_progress" id="aipkit_sources_bulk_progress" aria-live="polite"></span>
                </div>
                <div class="aipkit_sources_bulk_actions">
                    <button type="button" class="aipkit_btn aipkit_btn-danger aipkit_sources_bulk_delete">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        <span><?php esc_html_e('Delete', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_bulk_retry" hidden>
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <span><?php esc_html_e('Retry failed', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_bulk_clear">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        <span><?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                </div>
            </div>
            <div class="aipkit_data-table-frame aipkit_sources_data_table_frame">
                <div class="aipkit_data-table aipkit_sources_table">
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <span class="aipkit_sources_status_header">
                                        <input
                                            type="checkbox"
                                            id="aipkit_sources_select_all"
                                            class="aipkit_sources_select_all"
                                            aria-label="<?php esc_attr_e('Select all deletable sources', 'gpt3-ai-content-generator'); ?>"
                                            disabled
                                        />
                                        <span><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></span>
                                    </span>
                                </th>
                                <th><?php esc_html_e('Item', 'gpt3-ai-content-generator'); ?></th>
                                <th><?php esc_html_e('Updated', 'gpt3-ai-content-generator'); ?></th>
                                <th class="aipkit_actions_cell_header"><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="aipkit_sources_table_body">
                            <tr>
                                <td colspan="4" class="aipkit_text-center">
                                    <?php esc_html_e('Sources will appear here.', 'gpt3-ai-content-generator'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div id="aipkit_sources_pagination" class="aipkit_logs_pagination_container aipkit_data-table-footer"></div>
            </div>
            </div>
            <div
                id="aipkit_sources_stores_panel"
                class="aipkit_sources_workspace_panel"
                role="tabpanel"
                aria-labelledby="aipkit_sources_stores_tab"
                hidden
            >
                <div class="aipkit_data-table aipkit_sources_table aipkit_sources_stores_table">
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Provider', 'gpt3-ai-content-generator'); ?></th>
                                <th><?php esc_html_e('Store', 'gpt3-ai-content-generator'); ?></th>
                                <th><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></th>
                                <th class="aipkit_actions_cell_header"><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="aipkit_sources_stores_table_body">
                            <tr>
                                <td colspan="4" class="aipkit_text-center">
                                    <?php esc_html_e('Stores will appear here.', 'gpt3-ai-content-generator'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($can_manage_knowledge_settings) : ?>
            <div
                id="aipkit_sources_search_panel"
                class="aipkit_sources_workspace_panel aipkit_sources_search_panel"
                role="tabpanel"
                aria-labelledby="aipkit_sources_search_tab"
                hidden
            >
                <div class="aipkit_sources_settings_form aipkit_sources_search_form">
                    <?php include WPAICG_PLUGIN_DIR . 'admin/views/knowledge-base/search-settings.php'; ?>
                </div>
            </div>
            <div
                id="aipkit_sources_settings_panel"
                class="aipkit_sources_workspace_panel aipkit_sources_settings_panel"
                role="tabpanel"
                aria-labelledby="aipkit_sources_settings_tab"
                hidden
            >
                <div class="aipkit_sources_settings_form">
                    <?php
                    $is_pro = $is_pro_plan;
                    include WPAICG_PLUGIN_DIR . 'admin/views/knowledge-base/indexing-settings.php';
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div
    class="aipkit-modal-overlay aipkit_sources_modal aipkit_sources_store_modal"
    id="aipkit_sources_store_modal"
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content aipkit_sources_modal_panel aipkit_sources_store_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_sources_store_modal_title"
        aria-describedby="aipkit_sources_store_modal_description"
    >
        <div class="aipkit-modal-header aipkit_sources_modal_header">
            <div class="aipkit_sources_modal_heading">
                <h2 class="aipkit-modal-title aipkit_sources_modal_title" id="aipkit_sources_store_modal_title">
                    <?php esc_html_e('Create store', 'gpt3-ai-content-generator'); ?>
                </h2>
                <p class="aipkit_builder_modal_subtitle aipkit_sources_modal_subtitle" id="aipkit_sources_store_modal_description">
                    <?php esc_html_e('Create the destination where new knowledge base data is saved.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit_sources_modal_close aipkit_sources_store_modal_close"
                aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="aipkit-modal-body aipkit_sources_modal_body">
            <div class="aipkit_sources_store_form">
                <div class="aipkit_builder_field">
                    <label class="aipkit_builder_label" for="aipkit_sources_store_modal_provider">
                        <?php esc_html_e('Provider', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <select id="aipkit_sources_store_modal_provider" class="aipkit_sources_filter_select">
                        <option value="local"><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                        <option value="openai"><?php esc_html_e('OpenAI', 'gpt3-ai-content-generator'); ?></option>
                        <option value="google"><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                        <option value="pinecone"><?php esc_html_e('Pinecone', 'gpt3-ai-content-generator'); ?></option>
                        <option value="qdrant"><?php esc_html_e('Qdrant', 'gpt3-ai-content-generator'); ?></option>
                        <option value="chroma"><?php esc_html_e('Chroma', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                </div>
                <div class="aipkit_builder_field">
                    <label class="aipkit_builder_label" for="aipkit_sources_store_modal_name">
                        <?php esc_html_e('Name', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <input
                        type="text"
                        id="aipkit_sources_store_modal_name"
                        class="aipkit_form-input"
                        placeholder="<?php esc_attr_e('my-knowledge-store', 'gpt3-ai-content-generator'); ?>"
                    >
                </div>
                <div class="aipkit_builder_field" id="aipkit_sources_store_modal_dimension_row">
                    <label class="aipkit_builder_label" for="aipkit_sources_store_modal_dimension">
                        <?php esc_html_e('Dimension', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <input
                        type="text"
                        id="aipkit_sources_store_modal_dimension"
                        class="aipkit_form-input"
                        min="1"
                        step="1"
                        value="<?php echo esc_attr__('Not required', 'gpt3-ai-content-generator'); ?>"
                        disabled
                    >
                </div>
            </div>
        </div>
        <div class="aipkit_sources_modal_footer">
            <div id="aipkit_sources_store_modal_status" class="aipkit_sources_modal_status aipkit_form-help" aria-live="polite"></div>
            <div class="aipkit_builder_action_row aipkit_sources_store_modal_actions">
                <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_store_modal_cancel">
                    <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                </button>
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_sources_store_modal_create">
                    <?php esc_html_e('Create store', 'gpt3-ai-content-generator'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php require WPAICG_PLUGIN_DIR . 'admin/views/shared/source-editor-modal.php'; ?>

<div
    class="aipkit-modal-overlay aipkit_sources_modal aipkit_sources_view_modal"
    id="aipkit_sources_view_modal"
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content aipkit_sources_modal_panel aipkit_sources_view_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_sources_view_title"
        aria-describedby="aipkit_sources_view_description"
    >
        <div class="aipkit-modal-header aipkit_sources_modal_header">
            <div class="aipkit_sources_modal_heading">
                <h2 class="aipkit-modal-title aipkit_sources_modal_title" id="aipkit_sources_view_title">
                    <?php esc_html_e('Source preview', 'gpt3-ai-content-generator'); ?>
                </h2>
                <p class="aipkit_builder_modal_subtitle aipkit_sources_modal_subtitle" id="aipkit_sources_view_description">
                    <?php esc_html_e('Review the indexed content stored for this source.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit_sources_modal_close aipkit_sources_view_close"
                aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="aipkit-modal-body aipkit_sources_modal_body">
            <div
                class="aipkit_sources_view_preview"
                aria-label="<?php esc_attr_e('Source content preview', 'gpt3-ai-content-generator'); ?>"
            ></div>
        </div>
        <div class="aipkit_sources_modal_footer">
            <div class="aipkit_sources_modal_status" aria-hidden="true"></div>
            <div class="aipkit_builder_action_row aipkit_sources_view_actions">
                <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_view_close_btn">
                    <?php esc_html_e('Close', 'gpt3-ai-content-generator'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
