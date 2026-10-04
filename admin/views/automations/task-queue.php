<?php
/**
 * Partial: Automated Task Queue Viewer
 * Displays items currently in the task queue.
 * REDESIGNED: Simplified selectable layout following philosophy principles
 * - Removed Attempts column (edge case info, visible in status if failed)
 * - Removed Type column (low-signal, already implied by task)
 * - Combined timing info for better chunking
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<div id="aipkit_automated_task_queue_wrapper">
    <section class="aipkit_autogpt_overview_section" aria-labelledby="aipkit_autogpt_queue_heading">
    <div class="aipkit_autogpt_section_header">
        <h2 id="aipkit_autogpt_queue_heading"><?php esc_html_e('Queue', 'gpt3-ai-content-generator'); ?></h2>
    </div>
    <div class="aipkit_autogpt_queue_toolbar" aria-live="polite">
        <div id="aipkit_autogpt_queue_filters" class="aipkit_autogpt_queue_filters">
            <label class="aipkit_autogpt_queue_search" for="aipkit_task_queue_search_input">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Search queue items', 'gpt3-ai-content-generator'); ?></span>
                <input type="search" id="aipkit_task_queue_search_input" name="aipkit_task_queue_search" placeholder="<?php esc_attr_e('Search queue items', 'gpt3-ai-content-generator'); ?>" autocomplete="off">
            </label>
            <label class="aipkit_autogpt_queue_status" for="aipkit_task_queue_status_filter">
                <span class="screen-reader-text"><?php esc_html_e('Filter queue by status', 'gpt3-ai-content-generator'); ?></span>
                <select id="aipkit_task_queue_status_filter" name="aipkit_task_queue_status">
                    <option value="all"><?php esc_html_e('All statuses', 'gpt3-ai-content-generator'); ?></option>
                    <option value="pending"><?php esc_html_e('Pending', 'gpt3-ai-content-generator'); ?></option>
                    <option value="processing"><?php esc_html_e('Processing', 'gpt3-ai-content-generator'); ?></option>
                    <option value="completed"><?php esc_html_e('Completed', 'gpt3-ai-content-generator'); ?></option>
                    <option value="failed"><?php esc_html_e('Failed', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </label>
            <button
                id="aipkit_refresh_task_queue_btn"
                class="aipkit_btn aipkit_btn-secondary aipkit_autogpt_header_tool aipkit_autogpt_header_tool--icon aipkit_autogpt_queue_refresh"
                type="button"
                title="<?php esc_attr_e('Refresh Queue', 'gpt3-ai-content-generator'); ?>"
                aria-label="<?php esc_attr_e('Refresh Queue', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
                <span class="aipkit_spinner" style="display:none;"></span>
            </button>
        </div>
        <div id="aipkit_autogpt_queue_selection" class="aipkit_autogpt_queue_selection" hidden>
            <span id="aipkit_autogpt_queue_selection_count" class="aipkit_autogpt_queue_selection_count"></span>
            <span class="aipkit_autogpt_queue_selection_actions">
                <button type="button" id="aipkit_delete_selected_queue_items_btn" class="aipkit_autogpt_queue_selection_action aipkit_autogpt_queue_selection_action--danger">
                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    <span><?php esc_html_e('Delete selected', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_spinner" style="display:none;"></span>
                </button>
                <button type="button" id="aipkit_clear_queue_selection_btn" class="aipkit_autogpt_queue_selection_action">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    <span><?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?></span>
                </button>
            </span>
        </div>
    </div>
    <div class="aipkit_autogpt_table_frame aipkit_data-table-frame">
        <div id="aipkit_automated_task_queue_viewer_area" class="aipkit_data-table aipkit_autogpt_queue_table">
            <table>
                <colgroup>
                    <col class="aipkit_autogpt_queue_col--select">
                    <col class="aipkit_autogpt_queue_col--item">
                    <col class="aipkit_autogpt_queue_col--task">
                    <col class="aipkit_autogpt_queue_col--added">
                    <col class="aipkit_autogpt_queue_col--status">
                    <col class="aipkit_autogpt_queue_col--actions">
                </colgroup>
                <thead>
                    <tr>
                        <th class="aipkit_autogpt_queue_select_cell">
                            <input type="checkbox" id="aipkit_autogpt_queue_select_all" aria-label="<?php esc_attr_e('Select all queue items on this page', 'gpt3-ai-content-generator'); ?>">
                        </th>
                        <th><?php esc_html_e('Item', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Task', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Added', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></th>
                        <th class="aipkit_actions_cell_header"><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                    </tr>
                </thead>
                <tbody id="aipkit_automated_task_queue_tbody">
                    <tr><td colspan="6" class="aipkit_text-center"><?php esc_html_e('Loading queue...', 'gpt3-ai-content-generator'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <div id="aipkit_automated_task_queue_pagination" class="aipkit_pagination aipkit_data-table-footer"></div>
    </div>
    </section>
</div>
