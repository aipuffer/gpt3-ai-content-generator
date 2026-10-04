<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
// Variables from view-data.php: $is_pro
$aipkit_cw_default_category_id = (int) get_option('default_category', 0);
$aipkit_cw_available_category_ids = array_map(
    static function ($category) {
        return (int) $category->term_id;
    },
    $wp_categories
);
if (!in_array($aipkit_cw_default_category_id, $aipkit_cw_available_category_ids, true)) {
    $aipkit_cw_default_category_id = isset($wp_categories[0]->term_id)
        ? (int) $wp_categories[0]->term_id
        : 0;
}

$render_cw_bulk_row = static function () use ($wp_categories, $users_for_author, $available_post_types) {
    ?>
    <div class="aipkit_cw_bulk_row" data-aipkit-bulk-row>
        <div class="aipkit_cw_bulk_row_main">
            <label class="aipkit_cw_bulk_field aipkit_cw_bulk_field--topic">
                <input type="text" class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_input--topic aipkit_autosave_trigger" data-bulk-field="topic" placeholder="<?php esc_attr_e('Topic', 'gpt3-ai-content-generator'); ?>" aria-label="<?php esc_attr_e('Topic', 'gpt3-ai-content-generator'); ?>">
            </label>
            <div class="aipkit_cw_bulk_field aipkit_cw_bulk_field--keywords-inline">
                <div
                    class="aipkit_cw_keyword_editor aipkit_cw_bulk_keyword_editor"
                    data-aipkit-cw-bulk-keyword-editor
                    data-remove-label="<?php esc_attr_e('Remove keyword', 'gpt3-ai-content-generator'); ?>"
                >
                    <div class="aipkit_cw_keyword_chip_list" data-aipkit-cw-bulk-keyword-chip-list></div>
                    <input
                        type="text"
                        class="aipkit_cw_keyword_chip_input aipkit_cw_bulk_keyword_entry"
                        data-aipkit-cw-bulk-keyword-entry
                        placeholder="<?php esc_attr_e('Keywords', 'gpt3-ai-content-generator'); ?>"
                        autocomplete="off"
                        aria-label="<?php esc_attr_e('Keywords', 'gpt3-ai-content-generator'); ?>"
                    >
                </div>
                <input
                    type="hidden"
                    class="aipkit_cw_bulk_input aipkit_autosave_trigger"
                    data-bulk-field="keywords"
                    value=""
                >
            </div>
            <div class="aipkit_cw_bulk_row_actions">
                <button
                    type="button"
                    class="aipkit_cw_bulk_toggle_row_details"
                    aria-expanded="false"
                    aria-label="<?php esc_attr_e('Advanced fields', 'gpt3-ai-content-generator'); ?>"
                    title="<?php esc_attr_e('Advanced fields', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                </button>
                <button type="button" class="aipkit_cw_bulk_remove_row" aria-label="<?php esc_attr_e('Remove row', 'gpt3-ai-content-generator'); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
        </div>
        <span
            class="aipkit_cw_topic_validation aipkit_cw_bulk_topic_validation"
            data-aipkit-bulk-topic-validation
            role="alert"
            aria-live="polite"
            hidden
        ></span>
        <div class="aipkit_cw_bulk_row_details">
            <label class="aipkit_cw_bulk_detail_field">
                <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Category', 'gpt3-ai-content-generator'); ?></span>
                <select class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail aipkit_autosave_trigger" data-bulk-field="category" aria-label="<?php esc_attr_e('Category', 'gpt3-ai-content-generator'); ?>">
                    <option value=""><?php esc_html_e('Use default', 'gpt3-ai-content-generator'); ?></option>
                    <?php foreach ($wp_categories as $category): ?>
                        <option value="<?php echo esc_attr($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="aipkit_cw_bulk_detail_field">
                <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Author', 'gpt3-ai-content-generator'); ?></span>
                <select class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail aipkit_autosave_trigger" data-bulk-field="author" aria-label="<?php esc_attr_e('Author', 'gpt3-ai-content-generator'); ?>">
                    <option value=""><?php esc_html_e('Use default', 'gpt3-ai-content-generator'); ?></option>
                    <?php foreach ($users_for_author as $user): ?>
                        <option value="<?php echo esc_attr($user->user_login); ?>" data-user-id="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="aipkit_cw_bulk_detail_field">
                <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Post type', 'gpt3-ai-content-generator'); ?></span>
                <select class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail aipkit_autosave_trigger" data-bulk-field="type" aria-label="<?php esc_attr_e('Post type', 'gpt3-ai-content-generator'); ?>">
                    <option value=""><?php esc_html_e('Use default', 'gpt3-ai-content-generator'); ?></option>
                    <?php foreach ($available_post_types as $pt_slug => $pt_obj): ?>
                        <option value="<?php echo esc_attr($pt_slug); ?>"><?php echo esc_html($pt_obj->label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="aipkit_cw_bulk_detail_field">
                <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Schedule', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_cw_bulk_datetime">
                    <input
                        type="datetime-local"
                        class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail aipkit_autosave_trigger"
                        data-bulk-field="schedule"
                        data-aipkit-bulk-schedule
                        step="60"
                        aria-label="<?php esc_attr_e('Schedule', 'gpt3-ai-content-generator'); ?>"
                    >
                </span>
            </label>
        </div>
    </div>
    <?php
};
?>
<div class="aipkit_cw_mode_container" data-template-ready="0">
    <input type="hidden" id="aipkit_content_writer_title" name="content_title" value="" class="aipkit_autosave_trigger">
    
    <div class="aipkit_cw_mode_panel">
        <div class="aipkit_cw_tab_content_container">
            <div class="aipkit_cw_tab_content aipkit_active" data-pane="task">
                <div class="aipkit_cw_bulk_source_panel" data-aipkit-bulk-source-panel="task">
                    <div class="aipkit_cw_task_entry_shell">
                        <div class="aipkit_cw_task_entry_header">
                            <div class="aipkit_cw_task_entry_top">
                                <h3 class="aipkit_cw_task_entry_title"><?php esc_html_e('Start writing', 'gpt3-ai-content-generator'); ?></h3>
                                <div class="aipkit_cw_task_entry_switch" role="group" aria-label="<?php esc_attr_e('Manual entry layout', 'gpt3-ai-content-generator'); ?>">
                                    <button type="button" class="aipkit_cw_task_entry_switch_btn is-active" data-aipkit-task-entry-tab="single" aria-pressed="true">
                                        <?php esc_html_e('Single', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                    <button type="button" class="aipkit_cw_task_entry_switch_btn" data-aipkit-task-entry-tab="batch" aria-pressed="false">
                                        <?php esc_html_e('Batch editor', 'gpt3-ai-content-generator'); ?>
                                        <span class="aipkit_cw_task_entry_switch_count" data-aipkit-task-entry-batch-count hidden>0</span>
                                    </button>
                                    <button type="button" class="aipkit_cw_task_entry_switch_btn" data-aipkit-task-entry-tab="paste" aria-pressed="false">
                                        <?php esc_html_e('Quick paste', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                </div>
                            </div>
                            <p class="aipkit_cw_task_entry_desc" data-aipkit-task-entry-mode-desc>
                                <?php esc_html_e('Focus on one article with a single topic and keyword set.', 'gpt3-ai-content-generator'); ?>
                            </p>
                        </div>

                        <p class="aipkit_cw_task_entry_notice" data-aipkit-task-entry-note hidden>
                            <?php esc_html_e('Use Batch editor or Quick paste while multiple topics or per-item settings are present.', 'gpt3-ai-content-generator'); ?>
                        </p>

                        <div class="aipkit_cw_task_entry_panel aipkit_cw_task_entry_panel--single" data-aipkit-task-entry-panel="single">
                            <div class="aipkit_cw_single_compose_card">
                                <label class="aipkit_cw_single_compose_field aipkit_cw_single_compose_field--topic" for="aipkit_cw_single_compose_topic">
                                    <span class="aipkit_cw_single_compose_label"><?php esc_html_e('Topic', 'gpt3-ai-content-generator'); ?></span>
                                    <textarea
                                        id="aipkit_cw_single_compose_topic"
                                        class="aipkit_form-input aipkit_cw_single_compose_input aipkit_cw_single_compose_input--topic"
                                        rows="1"
                                        placeholder="<?php esc_attr_e('How to choose a standing desk for a small home office', 'gpt3-ai-content-generator'); ?>"
                                        aria-describedby="aipkit_cw_single_compose_topic_help"
                                        aria-invalid="false"
                                    ></textarea>
                                    <span
                                        class="aipkit_cw_single_compose_helper aipkit_cw_single_compose_helper--topic"
                                        id="aipkit_cw_single_compose_topic_help"
                                        data-default-message="<?php esc_attr_e('Required. Describe what the article should cover.', 'gpt3-ai-content-generator'); ?>"
                                        aria-live="polite"
                                    >
                                        <?php esc_html_e('Required. Describe what the article should cover.', 'gpt3-ai-content-generator'); ?>
                                    </span>
                                </label>

                                <div class="aipkit_cw_single_compose_field">
                                    <span class="aipkit_cw_single_compose_label" id="aipkit_cw_single_compose_keywords_label"><?php esc_html_e('Keywords', 'gpt3-ai-content-generator'); ?></span>
                                    <div
                                        class="aipkit_cw_keyword_editor"
                                        data-aipkit-cw-keyword-editor
                                        data-remove-label="<?php esc_attr_e('Remove keyword', 'gpt3-ai-content-generator'); ?>"
                                    >
                                        <div class="aipkit_cw_keyword_chip_list" data-aipkit-cw-keyword-chip-list></div>
                                        <input
                                            type="text"
                                            id="aipkit_cw_single_compose_keyword_entry"
                                            class="aipkit_cw_keyword_chip_input"
                                            placeholder="<?php esc_attr_e('Add a keyword, press Enter', 'gpt3-ai-content-generator'); ?>"
                                            autocomplete="off"
                                            aria-labelledby="aipkit_cw_single_compose_keywords_label"
                                            aria-describedby="aipkit_cw_single_compose_keywords_help"
                                        >
                                    </div>
                                    <span class="aipkit_cw_single_compose_helper" id="aipkit_cw_single_compose_keywords_help">
                                        <?php esc_html_e('Optional. Helps steer what the article covers.', 'gpt3-ai-content-generator'); ?>
                                    </span>
                                    <input type="hidden" id="aipkit_cw_single_compose_keywords" value="">
                                </div>
                            </div>
                        </div>

                        <div class="aipkit_cw_task_entry_panel aipkit_cw_task_entry_panel--batch" data-aipkit-task-entry-panel="batch" hidden>
                            <div class="aipkit_cw_bulk_editor" data-aipkit-bulk-editor>
                                <div class="aipkit_cw_bulk_rows" data-aipkit-bulk-rows>
                                    <?php $render_cw_bulk_row(); ?>
                                </div>
                                <template id="aipkit_cw_bulk_row_template">
                                    <?php $render_cw_bulk_row(); ?>
                                </template>
                                <div class="aipkit_cw_bulk_toolbar">
                                    <button type="button" class="aipkit_cw_bulk_add_row" data-aipkit-bulk-add-row>
                                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                        <?php esc_html_e('Add another topic', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                    <span class="aipkit_cw_entry_count" data-aipkit-batch-topic-count aria-live="polite">
                                        <?php esc_html_e('0 topics', 'gpt3-ai-content-generator'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="aipkit_cw_task_entry_panel aipkit_cw_task_entry_panel--paste" data-aipkit-task-entry-panel="paste" hidden>
                            <div class="aipkit_cw_paste_panel">
                                <div class="aipkit_cw_paste_field aipkit_cw_single_compose_field">
                                    <label class="aipkit_cw_single_compose_label" for="aipkit_cw_bulk_topics"><?php esc_html_e('Topics', 'gpt3-ai-content-generator'); ?></label>
                                    <textarea
                                        id="aipkit_cw_bulk_topics"
                                        name="content_title_bulk"
                                        class="aipkit_form-input aipkit_autosave_trigger aipkit_cw_paste_textarea"
                                        rows="6"
                                        placeholder="<?php esc_attr_e("electric cars | battery, tesla\nsolid state batteries | range, charging\nhome charging setup", 'gpt3-ai-content-generator'); ?>"
                                        aria-describedby="aipkit_cw_quick_paste_guide aipkit_cw_quick_paste_validation"
                                    ></textarea>
                                    <div
                                        class="aipkit_cw_quick_paste_validation"
                                        id="aipkit_cw_quick_paste_validation"
                                        data-aipkit-quick-paste-validation
                                        role="alert"
                                        aria-live="polite"
                                        hidden
                                    ></div>
                                </div>
                                <p class="aipkit_cw_paste_format_hint" id="aipkit_cw_quick_paste_guide">
                                    <span><?php esc_html_e('Topic | Keywords | Category ID | Author Login | Post Type | Schedule', 'gpt3-ai-content-generator'); ?></span>
                                    <button
                                        type="button"
                                        class="aipkit_cw_paste_sample_link"
                                        data-aipkit-paste-topics-sample
                                        data-sample-category-id="<?php echo esc_attr((string) $aipkit_cw_default_category_id); ?>"
                                    ><?php esc_html_e('Add sample', 'gpt3-ai-content-generator'); ?></button>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="aipkit_cw_tab_content" data-pane="csv">
                <div class="aipkit_csv_import_container aipkit_cw_source_mode_shell aipkit_cw_source_mode_shell--csv">
                    <div class="aipkit_cw_source_mode_header">
                        <h3 class="aipkit_cw_source_mode_title"><?php esc_html_e('Import a CSV file', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_cw_source_mode_desc"><?php esc_html_e('Upload a CSV of topics and optional metadata to generate content in bulk.', 'gpt3-ai-content-generator'); ?></p>
                    </div>

                    <div class="aipkit_cw_source_mode_stage">
                        <div class="aipkit_csv_upload_zone" data-csv-upload-zone>
                            <label for="aipkit_cw_csv_file_input" class="aipkit_csv_dropzone">
                                <span class="aipkit_csv_dropzone_icon" aria-hidden="true">
                                    <span class="dashicons dashicons-upload"></span>
                                </span>
                                <span class="aipkit_csv_dropzone_text">
                                    <span class="aipkit_csv_dropzone_primary"><?php esc_html_e('Drop your CSV file here', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_csv_dropzone_secondary"><?php esc_html_e('or click to browse', 'gpt3-ai-content-generator'); ?></span>
                                </span>
                                <input
                                    type="file"
                                    id="aipkit_cw_csv_file_input"
                                    name="csv_file_input"
                                    class="aipkit_csv_file_input_hidden"
                                    accept=".csv, text/csv"
                                >
                            </label>
                        </div>

                        <div class="aipkit_csv_status_container" id="aipkit_cw_csv_status_container" data-csv-status hidden>
                            <div class="aipkit_csv_status_card" data-csv-status-card>
                                <div class="aipkit_csv_status_icon" data-csv-status-icon>
                                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                </div>
                                <div class="aipkit_csv_status_content">
                                    <span class="aipkit_csv_file_name" data-csv-file-name></span>
                                    <span id="aipkit_cw_csv_analysis_results" class="aipkit_csv_analysis_results" data-csv-message></span>
                                    <button
                                        type="button"
                                        class="aipkit_cw_csv_preview_toggle"
                                        data-csv-preview-toggle
                                        aria-expanded="false"
                                        aria-controls="aipkit_cw_csv_preview"
                                        hidden
                                    >
                                        <span data-csv-preview-label><?php esc_html_e('Preview first 5 rows', 'gpt3-ai-content-generator'); ?></span>
                                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                    </button>
                                    <div
                                        id="aipkit_cw_csv_preview"
                                        class="aipkit_cw_csv_preview"
                                        data-csv-preview
                                        hidden
                                    >
                                        <div class="aipkit_cw_csv_preview_scroll">
                                            <table class="aipkit_cw_csv_preview_table">
                                                <thead>
                                                    <tr>
                                                        <th scope="col"><?php esc_html_e('Topic', 'gpt3-ai-content-generator'); ?></th>
                                                        <th scope="col"><?php esc_html_e('Keywords', 'gpt3-ai-content-generator'); ?></th>
                                                        <th scope="col"><?php esc_html_e('Category', 'gpt3-ai-content-generator'); ?></th>
                                                        <th scope="col"><?php esc_html_e('Author', 'gpt3-ai-content-generator'); ?></th>
                                                        <th scope="col"><?php esc_html_e('Post type', 'gpt3-ai-content-generator'); ?></th>
                                                        <th scope="col"><?php esc_html_e('Schedule', 'gpt3-ai-content-generator'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody data-csv-preview-body></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="aipkit_csv_clear_btn" data-csv-clear aria-label="<?php esc_attr_e('Remove file', 'gpt3-ai-content-generator'); ?>">
                                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <textarea name="content_title_csv" id="aipkit_cw_csv_data_holder" class="aipkit_csv_data_holder" style="display: none;" readonly></textarea>

                    <p class="aipkit_cw_source_format_hint" data-csv-help>
                        <span><?php esc_html_e('Topic | Keywords | Category ID | Author Login | Post Type | Schedule', 'gpt3-ai-content-generator'); ?></span>
                        <a
                            href="https://docs.google.com/spreadsheets/d/1WOnO_UKkbRCoyjRxQnDDTy0i-RsnrY_MDKD3Ks09JJk/edit?usp=sharing"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="aipkit_cw_paste_sample_link"
                        ><?php esc_html_e('View sample CSV', 'gpt3-ai-content-generator'); ?></a>
                    </p>
                </div>
            </div>
            <?php foreach (['rss', 'url', 'gsheets'] as $aipkit_cw_source_mode) : ?>
                <div class="aipkit_cw_tab_content" data-pane="<?php echo esc_attr($aipkit_cw_source_mode); ?>">
                    <?php include WPAICG_PLUGIN_DIR . 'admin/views/content-writer/source-modes.php'; ?>
                </div>
            <?php endforeach; ?>
            <div class="aipkit_cw_tab_content" data-pane="existing">
                <div class="aipkit_cw_existing_panel" data-aipkit-existing-panel>
                    <div class="aipkit_cw_existing_controls">
                        <div class="aipkit_cw_existing_search">
                            <label class="aipkit_form-label" for="aipkit_cw_existing_search"><?php esc_html_e('Search', 'gpt3-ai-content-generator'); ?></label>
                            <input
                                type="search"
                                id="aipkit_cw_existing_search"
                                class="aipkit_form-input"
                                placeholder="<?php esc_attr_e('Search by title...', 'gpt3-ai-content-generator'); ?>"
                            >
                        </div>
                        <div class="aipkit_cw_existing_filters" data-aipkit-existing-filter="type">
                            <label class="aipkit_form-label" for="aipkit_cw_existing_post_type"><?php esc_html_e('Type', 'gpt3-ai-content-generator'); ?></label>
                            <select id="aipkit_cw_existing_post_type" class="aipkit_form-input">
                                <option value=""><?php esc_html_e('All types', 'gpt3-ai-content-generator'); ?></option>
                                <option value="attachment" hidden><?php esc_html_e('Media', 'gpt3-ai-content-generator'); ?></option>
                                <?php foreach ($available_post_types as $pt_slug => $pt_obj): ?>
                                    <option value="<?php echo esc_attr($pt_slug); ?>"><?php echo esc_html($pt_obj->label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="aipkit_cw_existing_filters" data-aipkit-existing-filter="media">
                            <label class="aipkit_form-label" for="aipkit_cw_existing_media_filter"><?php esc_html_e('Media', 'gpt3-ai-content-generator'); ?></label>
                            <select id="aipkit_cw_existing_media_filter" class="aipkit_form-input">
                                <option value=""><?php esc_html_e('All media items', 'gpt3-ai-content-generator'); ?></option>
                                <option value="image"><?php esc_html_e('Images', 'gpt3-ai-content-generator'); ?></option>
                                <option value="detached"><?php esc_html_e('Unattached', 'gpt3-ai-content-generator'); ?></option>
                                <option value="mine"><?php esc_html_e('Mine', 'gpt3-ai-content-generator'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="aipkit_cw_existing_run_progress" data-aipkit-existing-run-progress hidden>
                        <div class="aipkit_cw_existing_run_progress_header">
                            <span id="aipkit_cw_existing_run_progress_label"><?php esc_html_e('Updating 0 of 0', 'gpt3-ai-content-generator'); ?></span>
                            <span id="aipkit_cw_existing_run_elapsed" aria-label="<?php esc_attr_e('Elapsed time', 'gpt3-ai-content-generator'); ?>">0:00</span>
                        </div>
                        <div
                            class="aipkit_cw_existing_run_progress_track"
                            id="aipkit_cw_existing_run_progress_track"
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="0"
                            aria-valuenow="0"
                            aria-labelledby="aipkit_cw_existing_run_progress_label"
                        >
                            <span class="aipkit_cw_existing_run_progress_bar" id="aipkit_cw_existing_run_progress_bar"></span>
                        </div>
                    </div>

                    <div class="aipkit_cw_existing_list aipkit_data-table-frame">
                        <div class="aipkit_cw_existing_table_wrap aipkit_data-table">
                            <table class="aipkit_cw_existing_table aipkit_data-table__table">
                                <thead>
                                    <tr>
                                        <th scope="col" class="aipkit_cw_existing_col_check">
                                            <label class="screen-reader-text" for="aipkit_cw_existing_select_all"><?php esc_html_e('Select all', 'gpt3-ai-content-generator'); ?></label>
                                            <input type="checkbox" id="aipkit_cw_existing_select_all">
                                        </th>
                                        <th scope="col" class="aipkit_cw_existing_col_title" colspan="4"><?php esc_html_e('Title', 'gpt3-ai-content-generator'); ?></th>
                                        <th scope="col" class="aipkit_cw_existing_col_alt"><?php esc_html_e('Alt', 'gpt3-ai-content-generator'); ?></th>
                                        <th scope="col" class="aipkit_cw_existing_col_caption"><?php esc_html_e('Caption', 'gpt3-ai-content-generator'); ?></th>
                                        <th scope="col" class="aipkit_cw_existing_col_description"><?php esc_html_e('Description', 'gpt3-ai-content-generator'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="aipkit_cw_existing_posts_body">
                                    <tr class="aipkit_cw_existing_empty">
                                        <td colspan="5"><?php esc_html_e('Select filters to load posts.', 'gpt3-ai-content-generator'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="aipkit_cw_existing_pagination aipkit_data-table-footer" id="aipkit_cw_existing_pagination">
                            <div class="aipkit_cw_existing_table_summary">
                                <span class="aipkit_cw_existing_selected" id="aipkit_cw_existing_selected_count" hidden><?php esc_html_e('0 selected', 'gpt3-ai-content-generator'); ?></span>
                                <span class="aipkit_cw_existing_summary_separator" aria-hidden="true" hidden>·</span>
                                <span class="aipkit_pagination-count" id="aipkit_cw_existing_total_count"><?php esc_html_e('0 items', 'gpt3-ai-content-generator'); ?></span>
                            </div>
                            <div class="aipkit_cw_existing_pagination_controls">
                                <label class="aipkit_cw_existing_page_size" for="aipkit_cw_existing_per_page">
                                    <span><?php esc_html_e('Rows per page', 'gpt3-ai-content-generator'); ?></span>
                                    <select id="aipkit_cw_existing_per_page" class="aipkit_form-input">
                                        <option value="10"><?php esc_html_e('10', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="25"><?php esc_html_e('25', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="50"><?php esc_html_e('50', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="100"><?php esc_html_e('100', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="1000"><?php esc_html_e('1000', 'gpt3-ai-content-generator'); ?></option>
                                    </select>
                                </label>
                                <div class="aipkit_cw_existing_page_nav aipkit_pagination-links">
                                    <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination_prev" id="aipkit_cw_existing_page_prev" disabled>
                                        <?php esc_html_e('Prev', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                    <span class="aipkit_cw_existing_page_status aipkit_pagination-current" id="aipkit_cw_existing_page_status"><?php esc_html_e('1/1', 'gpt3-ai-content-generator'); ?></span>
                                    <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination_next" id="aipkit_cw_existing_page_next" disabled>
                                        <?php esc_html_e('Next', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="aipkit_cw_existing_action_mount" data-aipkit-existing-action-mount></div>
                    </div>
                </div>
            </div>
        </div>

        <span data-aipkit-action-dock-home hidden></span>
        <div class="aipkit_cw_action_dock aipkit_cw_action_dock--dock">
            <div class="aipkit_cw_action_dock_primary">
                <span id="aipkit_cw_action_validation" class="aipkit_cw_action_validation" aria-live="polite"></span>
            </div>
            <div class="aipkit_cw_action_dock_actions">
                <select id="aipkit_cw_task_frequency" name="task_frequency" class="aipkit_cw_task_frequency" aria-hidden="true" tabindex="-1" hidden>
                    <?php foreach ($task_frequencies as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, 'daily'); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="aipkit_cw_action_shell" data-aipkit-cw-primary-action="generate">
                    <button type="button" id="aipkit_content_writer_generate_btn" class="aipkit_cw_action_primary">
                        <span class="dashicons dashicons-rss aipkit_cw_action_icon" aria-hidden="true" hidden></span>
                        <span class="aipkit_btn-text"><?php esc_html_e('Generate', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_spinner" style="display:none;"></span>
                    </button>
                    <button
                        type="button"
                        class="aipkit_cw_action_disclosure"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="aipkit_cw_action_menu"
                    >
                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e('More actions', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <div id="aipkit_cw_action_menu" class="aipkit_cw_action_menu" role="menu" hidden>
                        <div class="aipkit_cw_action_menu_panel" data-menu-panel="actions">
                            <button type="button" class="aipkit_cw_action_menu_option" data-action="generate" role="menuitem">
                                <span class="aipkit_cw_action_menu_option_title">
                                    <?php esc_html_e('Generate now', 'gpt3-ai-content-generator'); ?>
                                </span>
                                <span class="aipkit_cw_action_menu_option_description">
                                    <?php esc_html_e('Writes the article immediately.', 'gpt3-ai-content-generator'); ?>
                                </span>
                            </button>
                            <button type="button" class="aipkit_cw_action_menu_option" data-action="create_task" role="menuitem">
                                <span class="aipkit_cw_action_menu_option_title">
                                    <?php esc_html_e('Create task', 'gpt3-ai-content-generator'); ?>
                                </span>
                                <span class="aipkit_cw_action_menu_option_description">
                                    <?php esc_html_e('Creates it as a task in Automations.', 'gpt3-ai-content-generator'); ?>
                                </span>
                            </button>
                        </div>
                        <div class="aipkit_cw_action_menu_panel" data-menu-panel="intervals" hidden>
                            <button type="button" class="aipkit_cw_action_menu_back" data-menu-back>
                                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                                <?php esc_html_e('Back', 'gpt3-ai-content-generator'); ?>
                            </button>
                            <span class="aipkit_cw_action_menu_heading">
                                <?php esc_html_e('Choose a schedule', 'gpt3-ai-content-generator'); ?>
                            </span>
                            <?php foreach ($task_frequencies as $value => $label): ?>
                                <button type="button" class="aipkit_cw_action_menu_option aipkit_cw_action_menu_option--interval" data-interval="<?php echo esc_attr($value); ?>" role="menuitem">
                                    <span class="aipkit_cw_action_menu_option_title">
                                        <?php echo esc_html($label); ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
