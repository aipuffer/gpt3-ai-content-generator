<?php
/** Automations batch editor, quick-paste and CSV topic input. */
if (!defined('ABSPATH')) {
    exit;
}

function aipkit_render_automation_topic_input(array $aipkit_view_data): void
{
    $cw_available_post_types = $aipkit_view_data['cw_available_post_types'] ?? [];
    $cw_users_for_author = $aipkit_view_data['cw_users_for_author'] ?? [];
    $cw_wp_categories = $aipkit_view_data['cw_wp_categories'] ?? [];

    $aipkit_render_autogpt_bulk_row = static function () use ($cw_available_post_types, $cw_users_for_author, $cw_wp_categories) {
        ?>
        <div class="aipkit_cw_bulk_row" data-aipkit-bulk-row>
            <div class="aipkit_cw_bulk_row_main">
                <span class="aipkit_cw_bulk_row_number" data-aipkit-topic-row-number aria-hidden="true"></span>
                <label class="aipkit_cw_bulk_field aipkit_cw_bulk_field--topic">
                    <input
                        type="text"
                        class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_input--topic"
                        data-bulk-field="topic"
                        placeholder="<?php esc_attr_e('e.g. Best hiking trails in Colorado', 'gpt3-ai-content-generator'); ?>"
                        aria-label="<?php esc_attr_e('Topic', 'gpt3-ai-content-generator'); ?>"
                    >
                </label>
                <label class="aipkit_cw_bulk_field aipkit_cw_bulk_field--keywords-inline">
                    <input
                        type="text"
                        class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_input--keywords-inline"
                        data-bulk-field="keywords"
                        placeholder="<?php esc_attr_e('Keywords', 'gpt3-ai-content-generator'); ?>"
                        aria-label="<?php esc_attr_e('Keywords', 'gpt3-ai-content-generator'); ?>"
                    >
                </label>
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
                    <button
                        type="button"
                        class="aipkit_cw_bulk_remove_row"
                        aria-label="<?php esc_attr_e('Remove row', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
            <div class="aipkit_cw_bulk_row_details">
                <label class="aipkit_cw_bulk_detail_field">
                    <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Category', 'gpt3-ai-content-generator'); ?></span>
                    <select
                        class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail"
                        data-bulk-field="category"
                        aria-label="<?php esc_attr_e('Category', 'gpt3-ai-content-generator'); ?>"
                    >
                        <option value=""><?php esc_html_e('Category', 'gpt3-ai-content-generator'); ?></option>
                        <?php foreach ($cw_wp_categories as $category) : ?>
                            <option value="<?php echo esc_attr($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="aipkit_cw_bulk_detail_field">
                    <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Author', 'gpt3-ai-content-generator'); ?></span>
                    <select
                        class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail"
                        data-bulk-field="author"
                        aria-label="<?php esc_attr_e('Author', 'gpt3-ai-content-generator'); ?>"
                    >
                        <option value=""><?php esc_html_e('Author', 'gpt3-ai-content-generator'); ?></option>
                        <?php foreach ($cw_users_for_author as $user) : ?>
                            <option value="<?php echo esc_attr($user->user_login ?? ''); ?>" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                <?php echo esc_html($user->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="aipkit_cw_bulk_detail_field">
                    <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Post Type', 'gpt3-ai-content-generator'); ?></span>
                    <select
                        class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail"
                        data-bulk-field="type"
                        aria-label="<?php esc_attr_e('Post Type', 'gpt3-ai-content-generator'); ?>"
                    >
                        <option value=""><?php esc_html_e('Post', 'gpt3-ai-content-generator'); ?></option>
                        <?php foreach ($cw_available_post_types as $pt_slug => $pt_obj) : ?>
                            <option value="<?php echo esc_attr($pt_slug); ?>"><?php echo esc_html($pt_obj->label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="aipkit_cw_bulk_detail_field">
                    <span class="aipkit_cw_bulk_detail_label"><?php esc_html_e('Schedule', 'gpt3-ai-content-generator'); ?></span>
                    <input
                        type="datetime-local"
                        class="aipkit_form-input aipkit_cw_bulk_input aipkit_cw_bulk_detail"
                        data-bulk-field="schedule"
                        aria-label="<?php esc_attr_e('Schedule', 'gpt3-ai-content-generator'); ?>"
                    >
                </label>
            </div>
        </div>
        <?php
    };
    ?>
    <div id="aipkit_task_cw_input_mode_bulk" class="aipkit_task_cw_input_mode_section">
        <div class="aipkit_cw_task_entry_shell aipkit_cw_task_entry_shell--batch" data-task-entry-view="batch">
            <div class="aipkit_cw_task_entry_panel aipkit_cw_task_entry_panel--batch" data-aipkit-task-entry-panel="batch">
                <div id="aipkit_task_cw_paste_importer" class="aipkit_cw_paste_importer" data-aipkit-paste-topics-panel hidden>
                    <label class="screen-reader-text" for="aipkit_task_cw_content_title_bulk"><?php esc_html_e('Paste topics', 'gpt3-ai-content-generator'); ?></label>
                    <textarea
                        id="aipkit_task_cw_content_title_bulk"
                        name="content_title_bulk"
                        class="aipkit_form-input aipkit_cw_paste_textarea aipkit_cw_paste_textarea--compact"
                        data-aipkit-paste-topics-input
                        data-aipkit-manual-topics-source
                        rows="5"
                        placeholder="<?php echo esc_attr__("How to frost cupcakes\nBeginner bread guide", 'gpt3-ai-content-generator'); ?>"
                    ></textarea>
                    <p class="aipkit_cw_paste_format_hint">
                        <span><?php esc_html_e('Topic | Keywords | Category ID | Author Login | Post Type | Schedule.', 'gpt3-ai-content-generator'); ?></span>
                        <button type="button" class="aipkit_cw_paste_sample_link" data-aipkit-paste-topics-sample><?php esc_html_e('Add sample', 'gpt3-ai-content-generator'); ?></button>
                    </p>
                </div>
                <div class="aipkit_cw_bulk_editor" data-aipkit-bulk-editor>
                    <div class="aipkit_cw_bulk_rows" data-aipkit-bulk-rows>
                        <?php $aipkit_render_autogpt_bulk_row(); ?>
                    </div>
                    <template id="aipkit_task_cw_bulk_row_template">
                        <?php $aipkit_render_autogpt_bulk_row(); ?>
                    </template>
                    <button type="button" class="aipkit_cw_add_topic_btn" data-aipkit-add-topic>
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <?php esc_html_e('Add another topic', 'gpt3-ai-content-generator'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="aipkit_task_cw_input_mode_csv" class="aipkit_task_cw_input_mode_section">
        <div class="aipkit_csv_import_container aipkit_cw_source_mode_shell aipkit_cw_source_mode_shell--csv">
            <div class="aipkit_cw_source_mode_stage">
                <div class="aipkit_csv_upload_zone" data-csv-upload-zone>
                    <label for="aipkit_task_cw_csv_file_input" class="aipkit_csv_dropzone">
                        <span class="aipkit_csv_dropzone_icon" aria-hidden="true">
                            <span class="dashicons dashicons-upload"></span>
                        </span>
                        <span class="aipkit_csv_dropzone_text">
                            <span class="aipkit_csv_dropzone_primary"><?php esc_html_e('Drop your CSV file here', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit_csv_dropzone_secondary"><?php esc_html_e('or click to browse', 'gpt3-ai-content-generator'); ?></span>
                        </span>
                        <input
                            type="file"
                            id="aipkit_task_cw_csv_file_input"
                            name="csv_file_input"
                            class="aipkit_csv_file_input_hidden"
                            accept=".csv, text/csv"
                        >
                    </label>
                </div>

                <div class="aipkit_csv_status_container" id="aipkit_task_cw_csv_status_container" data-csv-status hidden>
                    <div class="aipkit_csv_status_card" data-csv-status-card>
                        <div class="aipkit_csv_status_icon" data-csv-status-icon>
                            <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                        </div>
                        <div class="aipkit_csv_status_content">
                            <span class="aipkit_csv_file_name" data-csv-file-name></span>
                            <span id="aipkit_task_cw_csv_analysis_results" class="aipkit_csv_analysis_results" data-csv-message></span>
                        </div>
                        <button type="button" class="aipkit_csv_clear_btn" data-csv-clear aria-label="<?php esc_attr_e('Remove file', 'gpt3-ai-content-generator'); ?>">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            </div>

            <?php // This hidden textarea will be populated by JS with the parsed CSV data (pipe-separated) ?>
            <textarea name="content_title" id="aipkit_task_cw_csv_data_holder" class="aipkit_csv_data_holder" style="display: none;" readonly></textarea>

            <p class="aipkit_cw_source_format_hint">
                <span><?php esc_html_e('Topic | Keywords | Category ID | Author Login | Post Type | Schedule.', 'gpt3-ai-content-generator'); ?></span>
                <a href="https://docs.google.com/spreadsheets/d/1WOnO_UKkbRCoyjRxQnDDTy0i-RsnrY_MDKD3Ks09JJk/edit?usp=sharing" target="_blank" rel="noopener noreferrer" class="aipkit_cw_paste_sample_link"><?php esc_html_e('View sample CSV', 'gpt3-ai-content-generator'); ?></a>
            </p>
        </div>
    </div>
<?php
}
