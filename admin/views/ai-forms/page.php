<?php

/**
 * AIPKit AI Forms Module - Admin View
 * Main screen for managing AI Forms.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\AIPKit_Providers;

$aipkit_vector_store_localization = [
    'openai_vector_stores' => [],
    'pinecone_indexes' => [],
    'qdrant_collections' => [],
    'chroma_collections' => [],
    'google_file_search_stores' => [],
];
if (class_exists(AIPKit_Providers::class)) {
    $aipkit_vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('ai_forms_editor_ui');
}
$openai_vector_stores = isset($aipkit_vector_store_localization['openai_vector_stores']) && is_array($aipkit_vector_store_localization['openai_vector_stores'])
    ? $aipkit_vector_store_localization['openai_vector_stores']
    : [];
$pinecone_indexes = isset($aipkit_vector_store_localization['pinecone_indexes']) && is_array($aipkit_vector_store_localization['pinecone_indexes'])
    ? $aipkit_vector_store_localization['pinecone_indexes']
    : [];
$qdrant_collections = isset($aipkit_vector_store_localization['qdrant_collections']) && is_array($aipkit_vector_store_localization['qdrant_collections'])
    ? $aipkit_vector_store_localization['qdrant_collections']
    : [];
$chroma_collections = isset($aipkit_vector_store_localization['chroma_collections']) && is_array($aipkit_vector_store_localization['chroma_collections'])
    ? $aipkit_vector_store_localization['chroma_collections']
    : [];
$google_file_search_stores = isset($aipkit_vector_store_localization['google_file_search_stores']) && is_array($aipkit_vector_store_localization['google_file_search_stores'])
    ? $aipkit_vector_store_localization['google_file_search_stores']
    : [];

$aipkit_ai_form_template_icons_url = WPAICG_PLUGIN_URL . 'admin/images/ai-forms/';
$aipkit_ai_form_templates = [
    [
        'key' => 'lead_capture',
        'icon_url' => $aipkit_ai_form_template_icons_url . 'lead-capture.svg',
        'tone' => 'peach',
        'name' => __('Lead capture', 'gpt3-ai-content-generator'),
        'description' => __('Collect name, email and intent.', 'gpt3-ai-content-generator'),
    ],
    [
        'key' => 'customer_feedback',
        'icon_url' => $aipkit_ai_form_template_icons_url . 'customer-feedback.svg',
        'tone' => 'blue',
        'name' => __('Customer feedback', 'gpt3-ai-content-generator'),
        'description' => __('Rate and review an experience.', 'gpt3-ai-content-generator'),
    ],
    [
        'key' => 'book_appointment',
        'icon_url' => $aipkit_ai_form_template_icons_url . 'book-appointment.svg',
        'tone' => 'green',
        'name' => __('Book appointment', 'gpt3-ai-content-generator'),
        'description' => __('Let visitors pick a time slot.', 'gpt3-ai-content-generator'),
    ],
    [
        'key' => 'support_request',
        'icon_url' => $aipkit_ai_form_template_icons_url . 'support-request.svg',
        'tone' => 'pink',
        'name' => __('Support request', 'gpt3-ai-content-generator'),
        'description' => __('Route issues to your team.', 'gpt3-ai-content-generator'),
    ],
    [
        'key' => 'waitlist_signup',
        'icon_url' => $aipkit_ai_form_template_icons_url . 'waitlist-signup.svg',
        'tone' => 'purple',
        'name' => __('Waitlist signup', 'gpt3-ai-content-generator'),
        'description' => __('Capture early interest fast.', 'gpt3-ai-content-generator'),
    ],
];

?>
<?php
$aipkit_notice_id = 'aipkit_provider_notice_ai_forms';
$aipkit_notice_context = __('use this AI form', 'gpt3-ai-content-generator');
include WPAICG_PLUGIN_DIR . 'admin/views/shared/provider-key-notice.php';
?>
<div
    class="aipkit_container aipkit_ai_forms_container aipkit_admin_ui"
    id="aipkit_ai_forms_container"
>
    <?php include WPAICG_PLUGIN_DIR . 'admin/views/shared/vector-store-nonce-fields.php'; ?>
    <div class="aipkit_container-body">
        <input type="file" id="aipkit_ai_forms_import_file_input" style="display: none;" accept="application/json">
        <div id="aipkit_form_editor_container" class="aipkit_form_editor_container" style="display:none;">
            <?php include __DIR__ . '/form-editor.php'; ?>
        </div>
        <div id="aipkit_ai_forms_list_container">
            <header class="aipkit_ai_forms_page_header">
                <div class="aipkit_ai_forms_header_copy">
                    <div class="aipkit_ai_forms_header_title_row">
                        <h1 class="aipkit_ai_forms_page_title" id="aipkit_ai_forms_header_title_default"><?php esc_html_e('AI Forms', 'gpt3-ai-content-generator'); ?></h1>
                        <span id="aipkit_ai_forms_settings_status" class="aipkit_training_status aipkit_global_status_area" aria-live="polite"></span>
                    </div>
                    <p class="aipkit_ai_forms_header_hint"><?php esc_html_e('Launch a ready-made form or build your own.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <div class="aipkit_ai_forms_page_actions">
                    <button type="button" id="aipkit_create_new_ai_form_btn" class="aipkit_btn aipkit_btn-primary aipkit_ai_forms_create_button">
                        <?php esc_html_e('+ Create form', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <button type="button" class="aipkit_ai_forms_header_utility aipkit_ai_forms_workspace_tab" id="aipkit_ai_forms_settings_tab" aria-controls="aipkit_ai_forms_settings_panel" aria-selected="false" data-aipkit-ai-forms-tab="settings" title="<?php esc_attr_e('AI Forms settings', 'gpt3-ai-content-generator'); ?>" aria-label="<?php esc_attr_e('AI Forms settings', 'gpt3-ai-content-generator'); ?>">
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                    </button>
                </div>
            </header>
            <div id="aipkit_ai_forms_data_panel" class="aipkit_ai_forms_workspace_panel is-active" role="tabpanel" aria-labelledby="aipkit_ai_forms_owned_title">
                <section class="aipkit_ai_forms_templates" aria-labelledby="aipkit_ai_forms_templates_title">
                    <h2 class="aipkit_ai_forms_section_title" id="aipkit_ai_forms_templates_title"><?php esc_html_e('Templates', 'gpt3-ai-content-generator'); ?></h2>
                    <div class="aipkit_ai_forms_template_grid">
                        <?php foreach ($aipkit_ai_form_templates as $aipkit_ai_form_template) : ?>
                            <article class="aipkit_ai_forms_template_card">
                                <span class="aipkit_ai_forms_template_icon aipkit_ai_forms_template_icon--<?php echo esc_attr($aipkit_ai_form_template['tone']); ?>" aria-hidden="true">
                                    <img class="aipkit_ai_forms_template_icon_image" src="<?php echo esc_url($aipkit_ai_form_template['icon_url']); ?>" alt="" width="16" height="16">
                                </span>
                                <h3 class="aipkit_ai_forms_template_name"><?php echo esc_html($aipkit_ai_form_template['name']); ?></h3>
                                <p class="aipkit_ai_forms_template_description"><?php echo esc_html($aipkit_ai_form_template['description']); ?></p>
                                <button type="button" class="aipkit_ai_forms_template_action" data-aipkit-template-key="<?php echo esc_attr($aipkit_ai_form_template['key']); ?>">
                                    <?php esc_html_e('Use template →', 'gpt3-ai-content-generator'); ?>
                                </button>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <section class="aipkit_ai_forms_owned" aria-labelledby="aipkit_ai_forms_owned_title">
                    <div class="aipkit_ai_forms_list_heading">
                        <h2 class="aipkit_ai_forms_section_title" id="aipkit_ai_forms_owned_title"><?php esc_html_e('Your forms', 'gpt3-ai-content-generator'); ?></h2>
                        <div class="aipkit_ai_forms_toolbar" aria-live="polite">
                            <div id="aipkit_ai_forms_filters" class="aipkit_ai_forms_filters">
                                <label class="aipkit_ai_forms_search" for="aipkit_ai_forms_search_input">
                                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                    <span class="screen-reader-text"><?php esc_html_e('Search forms', 'gpt3-ai-content-generator'); ?></span>
                                    <input
                                        type="search"
                                        id="aipkit_ai_forms_search_input"
                                        class="aipkit_ai_forms_search_input"
                                        placeholder="<?php esc_attr_e('Search forms', 'gpt3-ai-content-generator'); ?>"
                                        autocomplete="off"
                                    >
                                </label>
                                <div class="aipkit_actions_menu aipkit_ai_forms_toolbar_menu">
                                    <button type="button" class="aipkit_ai_forms_toolbar_more aipkit_actions_menu_toggle" aria-haspopup="menu" aria-expanded="false" title="<?php esc_attr_e('More form actions', 'gpt3-ai-content-generator'); ?>" aria-label="<?php esc_attr_e('More form actions', 'gpt3-ai-content-generator'); ?>">
                                        <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
                                    </button>
                                    <div class="aipkit_actions_dropdown_menu" role="menu">
                                        <button type="button" role="menuitem" id="aipkit_import_ai_forms_btn" class="aipkit_dropdown-item-btn">
                                            <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                                            <span><?php esc_html_e('Import', 'gpt3-ai-content-generator'); ?></span>
                                        </button>
                                        <button type="button" role="menuitem" id="aipkit_export_all_ai_forms_btn" class="aipkit_dropdown-item-btn">
                                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                            <span><?php esc_html_e('Export all', 'gpt3-ai-content-generator'); ?></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div id="aipkit_ai_forms_selection" class="aipkit_ai_forms_selection" hidden>
                                <span id="aipkit_ai_forms_selection_count" class="aipkit_ai_forms_selection_count"></span>
                                <span class="aipkit_ai_forms_selection_actions">
                                    <button type="button" id="aipkit_export_selected_ai_forms_btn" class="aipkit_ai_forms_selection_action">
                                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                        <span><?php esc_html_e('Export selected', 'gpt3-ai-content-generator'); ?></span>
                                        <span class="aipkit_spinner" style="display:none;"></span>
                                    </button>
                                    <button type="button" id="aipkit_delete_selected_ai_forms_btn" class="aipkit_ai_forms_selection_action aipkit_ai_forms_selection_action--danger">
                                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                        <span><?php esc_html_e('Delete selected', 'gpt3-ai-content-generator'); ?></span>
                                        <span class="aipkit_spinner" style="display:none;"></span>
                                    </button>
                                    <button type="button" id="aipkit_clear_ai_forms_selection_btn" class="aipkit_ai_forms_selection_action">
                                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                        <span><?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?></span>
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="aipkit_data-table-frame aipkit_ai_forms_table_frame">
                        <div class="aipkit_data-table aipkit_ai_forms_list_table">
                            <table class="aipkit_data-table__table">
                                <thead>
                                    <tr>
                                        <th class="aipkit_ai_forms_select_cell">
                                            <input type="checkbox" id="aipkit_ai_forms_select_all" aria-label="<?php esc_attr_e('Select all forms on this page', 'gpt3-ai-content-generator'); ?>">
                                        </th>
                                        <th class="aipkit-sortable-col" data-sort-key="title"><span><?php esc_html_e('Name', 'gpt3-ai-content-generator'); ?></span></th>
                                        <th><?php esc_html_e('Shortcode', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Responses', 'gpt3-ai-content-generator'); ?></th>
                                        <th class="aipkit-sortable-col" data-sort-key="modified"><span><?php esc_html_e('Updated', 'gpt3-ai-content-generator'); ?></span></th>
                                        <th class="aipkit_actions_cell_header"><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="aipkit_ai_forms_list_tbody">
                                </tbody>
                            </table>
                            <div id="aipkit_no_ai_forms_message" class="aipkit_ai_forms_empty_state">
                                <span class="aipkit_ai_forms_empty_icon" aria-hidden="true">
                                    <span class="dashicons dashicons-feedback"></span>
                                </span>
                                <h3 class="aipkit_ai_forms_empty_title"><?php esc_html_e('Build your first form', 'gpt3-ai-content-generator'); ?></h3>
                                <p class="aipkit_ai_forms_empty_description"><?php esc_html_e('Turn visitor answers into AI-generated results.', 'gpt3-ai-content-generator'); ?></p>
                                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_ai_forms_empty_create"><?php esc_html_e('Create your first form', 'gpt3-ai-content-generator'); ?></button>
                            </div>
                        </div>
                        <div id="aipkit_ai_forms_pagination" class="aipkit_logs_pagination_container aipkit_data-table-footer"></div>
                    </div>
                </section>
            </div>
            <div id="aipkit_ai_forms_settings_panel" class="aipkit_ai_forms_workspace_panel aipkit_ai_forms_settings_panel" role="tabpanel" aria-labelledby="aipkit_ai_forms_settings_tab" hidden>
                <button type="button" class="aipkit_ai_forms_settings_back aipkit_ai_forms_workspace_tab" data-aipkit-ai-forms-tab="forms">
                    <?php esc_html_e('← Back to forms', 'gpt3-ai-content-generator'); ?>
                </button>
                <?php include __DIR__ . '/settings.php'; ?>
            </div>
        </div>
    </div>
    <div class="aipkit_builder_sheet_overlay aipkit_ai_forms_preview_sheet" id="aipkit_ai_forms_preview_sheet" aria-hidden="true">
        <div
            class="aipkit_builder_sheet_panel"
            role="dialog"
            aria-labelledby="aipkit_ai_forms_preview_sheet_title"
            aria-describedby="aipkit_ai_forms_preview_sheet_description"
        >
            <div class="aipkit_builder_sheet_header">
                <div>
                    <div class="aipkit_builder_sheet_title_row">
                        <h3 class="aipkit_builder_sheet_title" id="aipkit_ai_forms_preview_sheet_title">
                            <?php esc_html_e('Preview', 'gpt3-ai-content-generator'); ?>
                        </h3>
                    </div>
                    <p class="aipkit_builder_sheet_description" id="aipkit_ai_forms_preview_sheet_description"></p>
                </div>
                <button
                    type="button"
                    class="aipkit_builder_sheet_close"
                    id="aipkit_ai_forms_preview_sheet_close"
                    aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <div class="aipkit_builder_sheet_body">
                <div class="aipkit_ai_forms_preview_frame" id="aipkit_ai_forms_preview_frame"></div>
            </div>
        </div>
    </div>
</div>
