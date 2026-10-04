<?php

namespace WPAICG\AutoGPT\Ajax;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WP_Error;
use WPAICG\AutoGPT\Ajax\Actions\SaveTask;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Content_Queuer;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor;
use WPAICG\AutoGPT\Cron\AIPKit_Automation_Server_Cron;

if (!defined('ABSPATH')) {
    exit;
}

require_once WPAICG_PLUGIN_DIR . 'classes/automations/task-access.php';

/** Loads paid administration logic only when both the plan and files allow it. */
function load_paid_action_logic(): bool
{
    if (!\WPAICG\AutoGPT\Helpers\is_pro_plan_active()) {
        return false;
    }

    $paid_file = WPAICG_PLUGIN_DIR . 'lib/automations/actions.php';
    if (!is_file($paid_file)) {
        return false;
    }

    require_once $paid_file;
    return true;
}

/**
 * Base class for AutoGPT Automated Task AJAX actions.
 */
abstract class AIPKit_Automated_Task_Base_Ajax_Action extends BaseDashboardAjaxHandler {

    protected $tasks_table_name;
    protected $queue_table_name;
    const NONCE_ACTION = 'aipkit_automated_tasks_manage_nonce'; // Consistent nonce for all task management actions

    public function __construct() {
        global $wpdb;
        $this->tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
        $this->queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    }

    /**
     * Abstract method to be implemented by child classes to handle the specific AJAX request.
     */
    abstract public function handle_request();
}


/**
* Handles AJAX request for saving an automated task by orchestrating calls
* to modular logic functions.
*/
class AIPKit_Save_Automated_Task_Action extends AIPKit_Automated_Task_Base_Ajax_Action
{
    public function handle_request()
    {
        // 1. Validate permissions and nonce first
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // Nonce is verified, now we can process $_POST data
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $post_data = wp_unslash($_POST);

        // 2. Validate the basic request parameters
        $validated_request = SaveTask\validate_task_request_logic($this, $post_data);
        if (is_wp_error($validated_request)) {
            $this->send_wp_error($validated_request);
            return;
        }
        $task_id = $validated_request['task_id'];
        $task_name = $validated_request['task_name'];
        $task_type = $validated_request['task_type'];
        $is_new_task = ($task_id === 0);

        // 3. Build task-specific configuration
        $task_config_or_error = null;
        if ($task_type === 'content_indexing') {
            $task_config_or_error = SaveTask\build_task_config_indexing_logic($post_data);
        } elseif (strncmp($task_type, 'content_writing', strlen('content_writing')) === 0) {
            $task_config_or_error = SaveTask\build_task_config_writing_logic($post_data);
        } elseif ($task_type === 'community_reply_comments') {
            $task_config_or_error = SaveTask\build_task_config_comment_reply_logic($post_data);
        } elseif ($task_type === 'enhance_existing_content') {
            $task_config_or_error = SaveTask\build_task_config_enhancement_logic($post_data);
        } else {
            $this->send_wp_error(new WP_Error('unsupported_task_type', __('The specified task type is not supported.', 'gpt3-ai-content-generator')), 400);
            return;
        }

        if (is_wp_error($task_config_or_error)) {
            $this->send_wp_error($task_config_or_error);
            return;
        }
        $task_config = $task_config_or_error;
        // Add task_type to config for later reference in cron jobs
        $task_config['task_type'] = $task_type;

        // 4. Get task status from POST
        $task_status = isset($post_data['task_status']) && in_array($post_data['task_status'], ['active', 'paused']) ? sanitize_key($post_data['task_status']) : 'active';

        // 5. Save the task to the database
        $saved_task_id_or_error = SaveTask\save_task_to_database_logic($task_name, $task_type, $task_config, $task_status, $task_id);
        if (is_wp_error($saved_task_id_or_error)) {
            $this->send_wp_error($saved_task_id_or_error);
            return;
        }
        $final_task_id = $saved_task_id_or_error;

        // 6. Finalize the save (scheduling, etc.)
        SaveTask\finalize_task_save_logic($final_task_id, $task_config, $task_status, $is_new_task);

        // 7. Send success response
        wp_send_json_success(['message' => __('Task saved successfully.', 'gpt3-ai-content-generator'), 'task_id' => $final_task_id]);
    }
}

/**
 * Handles AJAX request for getting all automated tasks.
 */
class AIPKit_Get_Automated_Tasks_Action extends AIPKit_Automated_Task_Base_Ajax_Action
{
    public function handle_request()
    {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_module_access_permissions method
        $current_page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is checked in check_module_access_permissions method
        $requested_per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 10;
        $allowed_per_page = [5, 10, 20, 50];
        $items_per_page = in_array($requested_per_page, $allowed_per_page, true) ? $requested_per_page : 10;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin pagination count over a plugin-owned custom table.
        $count_query = "SELECT COUNT(*) FROM {$this->tasks_table_name}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin pagination count over a plugin-owned custom table.
        $total_items = $wpdb->get_var($count_query);
        $total_pages = max(1, (int) ceil((int) $total_items / $items_per_page));
        $current_page = min($current_page, $total_pages);
        $offset = ($current_page - 1) * $items_per_page;

        $tasks_query = "SELECT * FROM {$this->tasks_table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $tasks_query_args = [$items_per_page, $offset];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders and arguments are assembled immediately above.
        $tasks_query = $wpdb->prepare($tasks_query, ...$tasks_query_args);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin pagination query over a plugin-owned custom table.
        $tasks = $wpdb->get_results($tasks_query, ARRAY_A);


        wp_send_json_success([
            'tasks' => $tasks ?: [],
            'pagination' => [
                'total_items' => (int) $total_items,
                'total_pages' => $total_pages,
                'current_page' => $current_page,
                'per_page' => $items_per_page,
            ]
        ]);
    }
}

/**
 * Handles AJAX request for deleting an automated task.
 */
class AIPKit_Delete_Automated_Task_Action extends AIPKit_Automated_Task_Base_Ajax_Action {

    public function handle_request() {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) { $this->send_wp_error($permission_check); return; }

        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
        if (empty($task_id)) { $this->send_wp_error(new WP_Error('missing_task_id_delete', __('Task ID is required.', 'gpt3-ai-content-generator')), 400); return; }

        if (class_exists(AIPKit_Automated_Task_Scheduler::class)) {
             AIPKit_Automated_Task_Scheduler::clear_task_event($task_id);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct deletion from a custom table. Caches will be invalidated.
        $wpdb->delete($this->queue_table_name, ['task_id' => $task_id], ['%d']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct deletion from a custom table. Caches will be invalidated.
        $result = $wpdb->delete($this->tasks_table_name, ['id' => $task_id], ['%d']);

        if ($result === false) {
            $this->send_wp_error(new WP_Error('db_error_delete_task', __('Failed to delete task.', 'gpt3-ai-content-generator')), 500);
        } else {
            if (class_exists(AIPKit_Automated_Task_Content_Queuer::class)) {
                AIPKit_Automated_Task_Content_Queuer::clear_task_state($task_id);
            }
            wp_send_json_success(['message' => __('Task deleted successfully.', 'gpt3-ai-content-generator')]);
        }
    }
}

/**
 * Handles AJAX request for updating the status of an automated task.
 */
class AIPKit_Update_Automated_Task_Status_Action extends AIPKit_Automated_Task_Base_Ajax_Action
{
    public function handle_request()
    {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $status = isset($_POST['status']) && in_array($_POST['status'], ['active', 'paused']) ? sanitize_key($_POST['status']) : null;
        if (empty($task_id) || $status === null) {
            $this->send_wp_error(new WP_Error('missing_params_status_update', __('Task ID and status are required.', 'gpt3-ai-content-generator')), 400);
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated
        $task = $wpdb->get_row($wpdb->prepare("SELECT task_config FROM {$this->tasks_table_name} WHERE id = %d", $task_id), ARRAY_A);
        if (!$task) {
            $this->send_wp_error(new WP_Error('task_not_found_status', __('Task not found.', 'gpt3-ai-content-generator')), 404);
            return;
        }
        $task_config = json_decode($task['task_config'], true);
        $frequency = $task_config['indexing_frequency'] ?? ($task_config['task_frequency'] ?? 'daily');
        if ($status === 'active' && !\WPAICG\AutoGPT\Helpers\clear_provider_hold($task_id)) {
            $this->send_wp_error(new WP_Error('provider_hold_clear_failed', __('Could not resume the task. Please try again.', 'gpt3-ai-content-generator')), 500);
            return;
        }


        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
        $result = $wpdb->update($this->tasks_table_name, ['status' => $status, 'updated_at' => current_time('mysql', 1)], ['id' => $task_id], ['%s', '%s'], ['%d']);
        if ($result === false) {
            $this->send_wp_error(new WP_Error('db_error_update_status', __('Failed to update task status.', 'gpt3-ai-content-generator')), 500);
        } else {
            if (class_exists(AIPKit_Automated_Task_Scheduler::class)) {
                if ($status === 'active') {
                    AIPKit_Automated_Task_Scheduler::schedule_task_event($task_id, $frequency, 'active');
                } else {
                    AIPKit_Automated_Task_Scheduler::clear_task_event($task_id);
                }
            }
            wp_send_json_success(['message' => __('Task status updated successfully.', 'gpt3-ai-content-generator')]);
        }
    }
}


/**
 * Handles AJAX request for running an automated task immediately by orchestrating
 * calls to modular logic functions.
 */
class AIPKit_Run_Automated_Task_Now_Action extends AIPKit_Automated_Task_Base_Ajax_Action
{
    public function handle_request()
    {

        // 1. Validate permissions and get the task data
        $task_or_error = Actions\RunNow\validate_task_and_permissions_logic($this);
        if (is_wp_error($task_or_error)) {
            $this->send_wp_error($task_or_error);
            return;
        }
        $task = $task_or_error;
        $task_id = (int)$task['id'];
        $task_config = json_decode($task['task_config'], true) ?: [];
        $task_type = $task['task_type'];

        // 2. Queue items based on task type
        switch (true) { // REVISED: Use switch(true) for clearer conditional logic
            case $task_type === 'content_indexing':
                Actions\RunNow\run_now_content_indexing_logic($task_id, $task_config);
                break;

            case strncmp($task_type, 'content_writing', strlen('content_writing')) === 0: // Covers 'content_writing' and 'content_writing_*'
                $result = Actions\RunNow\run_now_content_writing_logic($task_id, $task_config);
                if (is_wp_error($result)) {
                    $this->send_wp_error($result);
                    return;
                }
                break;

            case $task_type === 'community_reply_comments': // NEW CASE
                Actions\RunNow\run_now_comment_reply_logic($task_id, $task_config, $task['last_run_time']);
                break;

            case $task_type === 'enhance_existing_content':
                Actions\RunNow\run_now_content_enhancement_logic($task_id, $task_config);
                break;

            default:
                $this->send_wp_error(new WP_Error('unsupported_task_type_run_now', __('This task type does not support "Run Now".', 'gpt3-ai-content-generator')), 400);
                return;
        }

        // 3. Finalize the task run
        Actions\RunNow\finalize_run_now_task_logic($task_id);

        // 4. Send success response
        wp_send_json_success(['message' => __('Task run initiated. Check queue for progress.', 'gpt3-ai-content-generator')]);
    }
}

/**
 * Handles AJAX requests for the automated task queue.
 *
 * Queue rows use cursor pagination so the first page and deep navigation do not
 * depend on an exact count or a large OFFSET. Aggregate metrics are loaded by a
 * separate, short-lived cached request and never block the queue preview.
 */
class AIPKit_Get_Automated_Task_Queue_Items_Action extends AIPKit_Automated_Task_Base_Ajax_Action
{
    private const ITEMS_PER_PAGE = 15;
    private const SUMMARY_CACHE_KEY = 'aipkit_automated_task_queue_summary_v2';

    public function handle_request()
    {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $summary_only = isset($_POST['summary_only']) && sanitize_text_field(wp_unslash($_POST['summary_only'])) === '1';
        if ($summary_only) {
            $this->send_summary_response();
            return;
        }

        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $current_page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $search_term = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $status_filter = isset($_POST['status_filter']) ? sanitize_key(wp_unslash($_POST['status_filter'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $cursor = isset($_POST['cursor']) ? sanitize_text_field(wp_unslash($_POST['cursor'])) : '';

        $where_clauses = [];
        $prepare_args = [];

        if ($search_term !== '') {
            $where_clauses[] = '(q.target_identifier LIKE %s OR t.task_name LIKE %s)';
            $prepare_args[] = '%' . $wpdb->esc_like($search_term) . '%';
            $prepare_args[] = '%' . $wpdb->esc_like($search_term) . '%';
        }

        $allowed_statuses = ['pending', 'processing', 'completed', 'failed'];
        if ($status_filter !== '' && $status_filter !== 'all' && in_array($status_filter, $allowed_statuses, true)) {
            $where_clauses[] = 'q.status = %s';
            $prepare_args[] = $status_filter;
        }

        $cursor_data = $this->decode_cursor($cursor);
        if (!empty($cursor_data)) {
            $where_clauses[] = '(q.sort_priority < %d OR (q.sort_priority = %d AND q.added_at < %s) OR (q.sort_priority = %d AND q.added_at = %s AND q.id < %d))';
            $prepare_args[] = $cursor_data['priority'];
            $prepare_args[] = $cursor_data['priority'];
            $prepare_args[] = $cursor_data['added_at'];
            $prepare_args[] = $cursor_data['priority'];
            $prepare_args[] = $cursor_data['added_at'];
            $prepare_args[] = $cursor_data['id'];
        }

        $where_sql = empty($where_clauses) ? '' : ' WHERE ' . implode(' AND ', $where_clauses);
        $query_args = $prepare_args;
        $query_args[] = self::ITEMS_PER_PAGE + 1;
        $query = "SELECT q.*, t.task_name FROM {$this->queue_table_name} q LEFT JOIN {$this->tasks_table_name} t ON q.task_id = t.id" . $where_sql . ' ORDER BY q.sort_priority DESC, q.added_at DESC, q.id DESC LIMIT %d';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables and controlled SQL fragments; all scalar values are prepared.
        $items = $wpdb->get_results($wpdb->prepare($query, $query_args), ARRAY_A);
        $has_more = count((array) $items) > self::ITEMS_PER_PAGE;
        if ($has_more) {
            array_pop($items);
        }

        $enriched_items = $this->enrich_items((array) $items);
        $next_cursor = '';
        if ($has_more && !empty($items)) {
            $last_item = end($items);
            $next_cursor = $this->encode_cursor([
                'priority' => (int) ($last_item['sort_priority'] ?? 1),
                'added_at' => (string) ($last_item['added_at'] ?? ''),
                'id' => (int) ($last_item['id'] ?? 0),
            ]);
        }

        wp_send_json_success([
            'items' => $enriched_items,
            'pagination' => [
                'current_page' => $current_page,
                'per_page' => self::ITEMS_PER_PAGE,
                'has_previous' => $current_page > 1,
                'has_more' => $has_more,
                'next_cursor' => $next_cursor,
                'cursor_mode' => true,
            ],
        ]);
    }

    private function send_summary_response(): void
    {
        $cached_summary = get_transient(self::SUMMARY_CACHE_KEY);
        if (is_array($cached_summary)) {
            wp_send_json_success(['summary' => $cached_summary]);
            return;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Index-only status aggregation over a plugin-owned table, cached off the listing path.
        $summary_rows = $wpdb->get_results("SELECT status, COUNT(*) AS item_count FROM {$this->queue_table_name} GROUP BY status", ARRAY_A);
        $summary = [
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
            'total' => 0,
        ];
        foreach ((array) $summary_rows as $summary_row) {
            $status = sanitize_key((string) ($summary_row['status'] ?? ''));
            $count = max(0, (int) ($summary_row['item_count'] ?? 0));
            $summary['total'] += $count;
            if (array_key_exists($status, $summary)) {
                $summary[$status] = $count;
            }
        }

        set_transient(self::SUMMARY_CACHE_KEY, $summary, 30);
        wp_send_json_success(['summary' => $summary]);
    }

    /**
     * @param array<int, array<string, mixed>> $items Queue rows.
     * @return array<int, array<string, mixed>>
     */
    private function enrich_items(array $items): array
    {
        $enriched_items = [];
        foreach ($items as $item) {
            $item_config = json_decode((string) ($item['item_config'] ?? '[]'), true);
            if (!is_array($item_config)) {
                $item_config = [];
            }

            $item['generated_post_id'] = null;
            if (strncmp((string) $item['task_type'], 'content_writing', strlen('content_writing')) === 0 && $item['status'] === 'completed' && !empty($item['error_message'])) {
                if (preg_match('/\(ID: (\d+)\)/', (string) $item['error_message'], $matches)) {
                    $item['generated_post_id'] = (int) $matches[1];
                }
            }

            $item['post_edit_url'] = '';
            $linked_post_id = 0;
            if ($item['status'] === 'completed') {
                if (strncmp((string) $item['task_type'], 'content_writing', strlen('content_writing')) === 0 && !empty($item['generated_post_id'])) {
                    $linked_post_id = absint($item['generated_post_id']);
                } elseif ($item['task_type'] === 'enhance_existing_content') {
                    $linked_post_id = absint($item['target_identifier']);
                }
            }
            if ($linked_post_id && get_post($linked_post_id)) {
                $post_edit_url = get_edit_post_link($linked_post_id, 'raw');
                if (is_string($post_edit_url) && $post_edit_url !== '') {
                    $item['post_edit_url'] = esc_url_raw($post_edit_url);
                }
            }

            if ($item['task_type'] === 'content_indexing' || $item['task_type'] === 'enhance_existing_content') {
                $item['target_title'] = get_the_title(absint($item['target_identifier']));
            } elseif ($item['task_type'] === 'community_reply_comments') {
                $item['target_title'] = 'Comment #' . absint($item['target_identifier']);
            } elseif (strncmp((string) $item['task_type'], 'content_writing', strlen('content_writing')) === 0 && !empty($item_config['content_title'])) {
                $item['target_title'] = $item_config['content_title'];
            } else {
                $item['target_title'] = $item['target_identifier'];
            }

            if (strncmp((string) $item['task_type'], 'content_writing', strlen('content_writing')) === 0 && !empty($item_config['scheduled_gmt_time'])) {
                $item['scheduled_gmt_time'] = $item_config['scheduled_gmt_time'];
            }
            $enriched_items[] = $item;
        }

        return $enriched_items;
    }

    /** @return array{priority:int, added_at:string, id:int}|array{} */
    private function decode_cursor(string $cursor): array
    {
        if ($cursor === '') {
            return [];
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return [];
        }
        $cursor_data = json_decode($decoded, true);
        if (!is_array($cursor_data)) {
            return [];
        }
        $priority = isset($cursor_data['priority']) ? absint($cursor_data['priority']) : 1;
        $added_at = isset($cursor_data['added_at']) ? sanitize_text_field($cursor_data['added_at']) : '';
        $id = isset($cursor_data['id']) ? absint($cursor_data['id']) : 0;
        if ($added_at === '' || $id <= 0) {
            return [];
        }
        return [
            'priority' => min(2, max(1, $priority)),
            'added_at' => $added_at,
            'id' => $id,
        ];
    }

    /** @param array{priority:int, added_at:string, id:int} $cursor_data */
    private function encode_cursor(array $cursor_data): string
    {
        $encoded = wp_json_encode($cursor_data);
        return $encoded === false ? '' : base64_encode($encoded);
    }
}

/**
 * Handles AJAX request for deleting an item from the automated task queue.
 */
class AIPKit_Delete_Automated_Task_Queue_Item_Action extends AIPKit_Automated_Task_Base_Ajax_Action {

    public function handle_request() {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) { $this->send_wp_error($permission_check); return; }
        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        if (empty($item_id)) { $this->send_wp_error(new WP_Error('missing_item_id', __('Queue item ID is required.', 'gpt3-ai-content-generator')), 400); return; }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct deletion from a custom table. Cache will be invalidated.
        $result = $wpdb->delete($this->queue_table_name, ['id' => $item_id], ['%d']);
        if ($result === false) {
            $this->send_wp_error(new WP_Error('db_error_delete_queue_item', __('Failed to delete queue item.', 'gpt3-ai-content-generator')), 500);
        } else {
            wp_send_json_success(['message' => __('Queue item deleted successfully.', 'gpt3-ai-content-generator')]);
        }
    }
}

/**
 * Handles deletion of explicitly selected automated-task queue items.
 */
class AIPKit_Delete_Automated_Task_Queue_Items_Action extends AIPKit_Automated_Task_Base_Ajax_Action
{
    /**
     * Delete only the queue item IDs supplied by the current user.
     */
    public function handle_request()
    {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $encoded_item_ids = isset($_POST['item_ids']) ? sanitize_text_field(wp_unslash($_POST['item_ids'])) : '';
        $decoded_item_ids = json_decode($encoded_item_ids, true);
        $item_ids = is_array($decoded_item_ids)
            ? array_values(array_unique(array_filter(array_map('absint', $decoded_item_ids))))
            : [];

        if (empty($item_ids)) {
            $this->send_wp_error(new WP_Error('missing_item_ids', __('Select at least one queue item to delete.', 'gpt3-ai-content-generator')), 400);
            return;
        }

        if (count($item_ids) > 100) {
            $this->send_wp_error(new WP_Error('too_many_item_ids', __('You can delete up to 100 queue items at a time.', 'gpt3-ai-content-generator')), 400);
            return;
        }

        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($item_ids), '%d'));
        $query = "DELETE FROM {$this->queue_table_name} WHERE id IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only the internal table name and a validated placeholder list.
        $prepared_query = $wpdb->prepare($query, $item_ids);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared immediately above; custom-table deletion by validated integer IDs; caching does not apply.
        $result = $wpdb->query($prepared_query);

        if ($result === false) {
            $this->send_wp_error(new WP_Error('db_error_delete_queue_items', __('Failed to delete queue items.', 'gpt3-ai-content-generator')), 500);
            return;
        }

        /* translators: %d: Number of deleted queue items. */
        wp_send_json_success(['message' => sprintf(_n('%d queue item deleted.', '%d queue items deleted.', $result, 'gpt3-ai-content-generator'), $result)]);
    }
}

/**
 * Handles AJAX request for retrying a failed item in the automated task queue.
 */
class AIPKit_Retry_Automated_Task_Queue_Item_Action extends AIPKit_Automated_Task_Base_Ajax_Action {

    public function handle_request() {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) { $this->send_wp_error($permission_check); return; }
        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        if (empty($item_id)) { $this->send_wp_error(new WP_Error('missing_item_id_retry', __('Queue item ID is required.', 'gpt3-ai-content-generator')), 400); return; }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolve the exact failed item's task before an explicit retry.
        $task_id = (int) $wpdb->get_var($wpdb->prepare('SELECT task_id FROM ' . esc_sql($this->queue_table_name) . ' WHERE id = %d AND status = %s', $item_id, 'failed'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Cache will be invalidated.
        $result = $wpdb->update(
            $this->queue_table_name,
            ['status' => 'pending', 'last_attempt_time' => null, 'error_message' => null, 'attempts' => 0, 'sort_priority' => 1],
            ['id' => $item_id, 'status' => 'failed'],
            ['%s', '%s', '%s', '%d', '%d'],
            ['%d', '%s']
        );

        if ($result === false) {
            $this->send_wp_error(new WP_Error('db_error_retry_queue_item', __('Failed to mark item for retry.', 'gpt3-ai-content-generator')), 500);
        } elseif ($result === 0) {
            $this->send_wp_error(new WP_Error('item_not_retryable', __('Item not found or not in a failed state.', 'gpt3-ai-content-generator')), 404);
        } else {
            if ($task_id && !\WPAICG\AutoGPT\Helpers\clear_provider_hold($task_id)) {
                $this->send_wp_error(new WP_Error('provider_hold_clear_failed', __('Item queued, but the task could not resume. Resume the task to continue.', 'gpt3-ai-content-generator')), 500);
                return;
            }
             if (class_exists(AIPKit_Automated_Task_Event_Processor::class)) {
                 AIPKit_Automated_Task_Event_Processor::process_task_queue_event();
             }
            wp_send_json_success(['message' => __('Queue item marked for retry.', 'gpt3-ai-content-generator')]);
        }
    }
}

/**
 * Manages the opt-in external server-cron secret and status.
 */
class AIPKit_Manage_Automation_Server_Cron_Action extends AIPKit_Automated_Task_Base_Ajax_Action
{
    public function handle_request()
    {
        $permission_check = $this->check_module_access_permissions('autogpt', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        if (!class_exists(AIPKit_Automation_Server_Cron::class)) {
            $this->send_wp_error(
                new WP_Error(
                    'aipkit_server_cron_unavailable',
                    __('Server cron is unavailable.', 'gpt3-ai-content-generator')
                ),
                503
            );
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'status';

        if ($operation === 'enable') {
            $result = AIPKit_Automation_Server_Cron::enable();
        } elseif ($operation === 'rotate') {
            $result = AIPKit_Automation_Server_Cron::rotate_secret();
        } elseif ($operation === 'disable') {
            $result = AIPKit_Automation_Server_Cron::disable();
        } elseif ($operation === 'status') {
            $result = AIPKit_Automation_Server_Cron::get_status();
        } else {
            $result = new WP_Error(
                'aipkit_server_cron_invalid_operation',
                __('Invalid server cron operation.', 'gpt3-ai-content-generator')
            );
        }

        if (is_wp_error($result)) {
            $this->send_wp_error($result, 400);
            return;
        }

        wp_send_json_success($result);
    }
}

namespace WPAICG\AutoGPT\Ajax\Actions\SaveTask;

use WPAICG\AutoGPT\Ajax\AIPKit_Save_Automated_Task_Action;
use WPAICG\AutoGPT\Helpers;
use WP_Error;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Image_Provider_Options;
use WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config;
use WPAICG\AIPKit_Providers;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Content_Queuer;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * Checks whether manual content-writing input contains at least one real topic.
 *
 * Manual rows can include pipe-separated metadata after the topic:
 * topic | keywords | category | author | post type | schedule.
 * Only the first column should count as the required topic input.
 *
 * @param string $value Raw manual topic input.
 * @return bool True when at least one line has a non-empty topic.
 */
function content_writing_has_manual_topic(string $value): bool
{
    $lines = preg_split('/\r?\n/', trim($value));
    if (!is_array($lines)) {
        return false;
    }

    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', (string) $line));
        if (($parts[0] ?? '') !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Checks whether the settings required by a provider are present.
 *
 * This is intentionally a local setup check, not a remote credential test.
 *
 * @param string $provider_key Normalized provider key.
 * @return bool|null True/false for known providers, null for unknown providers.
 */
function provider_setup_is_present(string $provider_key): ?bool
{
    if (!class_exists(AIPKit_Providers::class)) {
        return null;
    }

    $provider_key = strtolower(sanitize_key($provider_key));
    $provider_names = [
        'aipuffercloud' => 'AIPufferCloud',
        'openai' => 'OpenAI',
        'google' => 'Google',
        'claude' => 'Claude',
        'openrouter' => 'OpenRouter',
        'azure' => 'Azure',
        'ollama' => 'Ollama',
        'deepseek' => 'DeepSeek',
        'xai' => 'xAI',
        'replicate' => 'Replicate',
        'pexels' => 'Pexels',
        'pixabay' => 'Pixabay',
        'pinecone' => 'Pinecone',
        'qdrant' => 'Qdrant',
        'chroma' => 'Chroma',
    ];
    if (!isset($provider_names[$provider_key])) {
        return null;
    }

    $data = AIPKit_Providers::get_provider_data($provider_names[$provider_key]);
    if ($provider_key === 'azure') {
        return !empty($data['api_key']) && !empty($data['endpoint']);
    }
    if ($provider_key === 'ollama') {
        return !empty($data['base_url']);
    }
    if ($provider_key === 'qdrant') {
        return !empty($data['api_key']) && !empty($data['url']);
    }
    if ($provider_key === 'chroma') {
        return !empty($data['url']);
    }

    return !empty($data['api_key']);
}

/**
 * Returns a save-time setup error for a provider, if one is known to be missing.
 *
 * @param string $provider_key Provider key from the request.
 * @return WP_Error|null Error when setup is missing; otherwise null.
 */
function provider_setup_error(string $provider_key): ?WP_Error
{
    $provider_key = strtolower(sanitize_key($provider_key));
    if ($provider_key === '' || provider_setup_is_present($provider_key) !== false) {
        return null;
    }

    $labels = [
        'aipuffercloud' => 'AI Puffer Cloud', 'openai' => 'OpenAI', 'google' => 'Google', 'claude' => 'Anthropic',
        'openrouter' => 'OpenRouter', 'azure' => 'Azure', 'ollama' => 'Ollama',
        'deepseek' => 'DeepSeek', 'xai' => 'xAI', 'replicate' => 'Replicate',
        'pexels' => 'Pexels', 'pixabay' => 'Pixabay', 'pinecone' => 'Pinecone',
        'qdrant' => 'Qdrant', 'chroma' => 'Chroma',
    ];
    $label = $labels[$provider_key] ?? ucfirst($provider_key);

    return new WP_Error(
        'provider_setup_required',
        sprintf(
            /* translators: %s: provider name. */
            __('Set up %s before using it in this automation.', 'gpt3-ai-content-generator'),
            $label
        ),
        ['status' => 400, 'provider' => $provider_key]
    );
}

/**
 * Validates provider setup for the task configuration being saved.
 *
 * @param string $task_type Task type.
 * @param array  $post_data Submitted task data.
 * @return WP_Error|null Error when a selected provider needs setup.
 */
function validate_task_provider_setup(string $task_type, array $post_data): ?WP_Error
{
    $providers = [];

    if (strpos($task_type, 'content_writing') === 0) {
        $providers[] = $post_data['ai_provider'] ?? '';
        if (($post_data['generate_images_enabled'] ?? '0') === '1' || ($post_data['generate_featured_image'] ?? '0') === '1') {
            $providers[] = $post_data['image_provider'] ?? '';
        }
        if (($post_data['enable_vector_store'] ?? '0') === '1') {
            $vector_provider = (string) ($post_data['vector_store_provider'] ?? 'openai');
            $providers[] = $vector_provider;
            if (in_array(strtolower($vector_provider), ['pinecone', 'qdrant', 'chroma', 'local'], true)) {
                $providers[] = $post_data['vector_embedding_provider'] ?? '';
            }
        }
    } elseif ($task_type === 'enhance_existing_content') {
        $providers[] = $post_data['ai_provider'] ?? '';
        if (($post_data['enable_vector_store'] ?? '0') === '1') {
            $vector_provider = (string) ($post_data['vector_store_provider'] ?? 'openai');
            $providers[] = $vector_provider;
            if (in_array(strtolower($vector_provider), ['pinecone', 'qdrant', 'chroma', 'local'], true)) {
                $providers[] = $post_data['vector_embedding_provider'] ?? '';
            }
        }
    } elseif ($task_type === 'community_reply_comments') {
        $providers[] = $post_data['cc_ai_provider'] ?? '';
    } elseif ($task_type === 'content_indexing') {
        $vector_provider = (string) ($post_data['target_store_provider'] ?? 'openai');
        $providers[] = $vector_provider;
        if (in_array(strtolower($vector_provider), ['pinecone', 'qdrant', 'chroma', 'local'], true)) {
            $providers[] = $post_data['embedding_provider'] ?? '';
        }
    }

    foreach (array_unique(array_filter(array_map('strval', $providers))) as $provider) {
        $error = provider_setup_error($provider);
        if (is_wp_error($error)) {
            return $error;
        }
    }

    return null;
}

/**
* Validates the AJAX request for saving an automated task.
*
* @param AIPKit_Save_Automated_Task_Action $handler The handler instance.
* @param array $post_data The raw POST data.
* @return array|WP_Error An array of validated parameters or a WP_Error on failure.
*/
function validate_task_request_logic(AIPKit_Save_Automated_Task_Action $handler, array $post_data)
{
    // Permission and nonce checks are now handled by the caller.
    // This function now only validates the presence and format of required parameters.

    $task_id = isset($post_data['task_id']) && !empty($post_data['task_id']) ? absint($post_data['task_id']) : 0;
    $task_name = isset($post_data['task_name']) ? sanitize_text_field($post_data['task_name']) : '';
    $task_type = isset($post_data['task_type']) ? sanitize_key($post_data['task_type']) : '';

    if (empty($task_name)) {
        return new WP_Error('missing_task_name', __('Task name is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (empty($task_type)) {
        return new WP_Error('missing_task_type', __('Task type is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (Helpers\task_type_requires_pro_plan($task_type) && !Helpers\is_pro_plan_active()) {
        return new WP_Error('task_type_requires_pro_plan', __('This is a Pro feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }
    $provider_setup_error = validate_task_provider_setup($task_type, $post_data);
    if (is_wp_error($provider_setup_error)) {
        return $provider_setup_error;
    }

    return [
        'task_id' => $task_id,
        'task_name' => $task_name,
        'task_type' => $task_type,
    ];
}

/**
* Builds and validates the configuration for a 'content_indexing' task.
*
* @param array $post_data The raw POST data.
* @return array|WP_Error The validated config array or WP_Error on failure.
*/
function build_task_config_indexing_logic(array $post_data)
{
    $task_config = [];
    $task_config['post_types'] = isset($post_data['post_types']) && is_array($post_data['post_types']) ? array_map('sanitize_key', $post_data['post_types']) : [];
    $task_config['indexing_categories'] = isset($post_data['indexing_categories']) && is_array($post_data['indexing_categories'])
        ? array_values(array_unique(array_filter(array_map('absint', $post_data['indexing_categories']))))
        : [];
    $task_config['specific_post_ids'] = isset($post_data['specific_post_ids']) && is_array($post_data['specific_post_ids'])
        ? array_values(array_filter(array_map('absint', $post_data['specific_post_ids'])))
        : [];
    $task_config['target_store_provider'] = isset($post_data['target_store_provider']) ? sanitize_key($post_data['target_store_provider']) : 'openai';
    if (!in_array($task_config['target_store_provider'], ['openai', 'google', 'pinecone', 'qdrant', 'chroma', 'local'], true)) {
        return new WP_Error('unsupported_target_store_provider', __('Unsupported knowledge base provider.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    $task_config['target_store_id'] = isset($post_data['target_store_id']) ? sanitize_text_field($post_data['target_store_id']) : '';
    $task_config['indexing_frequency'] = isset($post_data['task_frequency']) ? sanitize_key($post_data['task_frequency']) : 'daily';
    $task_config['index_existing_now_flag'] = isset($post_data['index_existing_now_flag']) ? '1' : '0';
    $task_config['only_new_updated_flag'] = isset($post_data['only_new_updated_flag']) ? '1' : '0';
    $task_config['source_context'] = isset($post_data['source_context']) ? sanitize_key($post_data['source_context']) : '';
    $task_config['chatbot_id'] = isset($post_data['chatbot_id']) ? absint($post_data['chatbot_id']) : 0;

    if (empty($task_config['post_types']) && empty($task_config['specific_post_ids'])) {
        return new WP_Error('missing_post_types', __('Please select at least one post type for content indexing.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (empty($task_config['target_store_id'])) {
        return new WP_Error('missing_target_store', __('Target vector store/index is required for content indexing.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (
        $task_config['target_store_provider'] === 'google'
        && strpos($task_config['target_store_id'], 'fileSearchStores/') !== 0
    ) {
        return new WP_Error('invalid_google_file_search_store', __('Select a valid Google store.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if ($task_config['index_existing_now_flag'] !== '1' && $task_config['only_new_updated_flag'] !== '1') {
        return new WP_Error('missing_indexing_behavior', __('Choose whether to index existing content, keep future content in sync, or both.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    if (
        $task_config['target_store_provider'] === 'local' ||
        $task_config['target_store_provider'] === 'pinecone' ||
        $task_config['target_store_provider'] === 'qdrant' ||
        $task_config['target_store_provider'] === 'chroma'
    ) {
        // The frontend already split the provider and model.
        $task_config['embedding_provider'] = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : null;
        $task_config['embedding_model'] = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : null;

        if (empty($task_config['embedding_provider']) || empty($task_config['embedding_model'])) {
            return new WP_Error('missing_embedding_config', __('An embedding model is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
    }
    return $task_config;
}

if (!class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)) {
    $aipkit_image_provider_options_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/image-options.php';
    if (file_exists($aipkit_image_provider_options_path)) {
        require_once $aipkit_image_provider_options_path;
    }
}

/**
* Builds and validates the configuration for a 'content_writing' task.
* UPDATED: Now handles different generation modes (RSS, GSheets, URL) and saves their specific data.
* UPDATED: Now handles vector store settings.
*
* @param array $post_data The raw POST data.
* @return array|WP_Error The validated config array or WP_Error on failure.
*/
function build_task_config_writing_logic(array $post_data)
{
    if (class_exists('\WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector')) {
        $rss_validation = \WPAICG\Lib\ContentWriter\AIPKit_Rss_Item_Selector::validate_config($post_data);
        if (is_wp_error($rss_validation)) {
            return $rss_validation;
        }
    }
    $content_writer_config = [];
    if (class_exists(AIPKit_Content_Writer_Template_Manager::class)) {
        // This list should ideally mirror the one in AIPKit_Content_Writer_Template_Manager for consistency.
        $allowed_keys_from_template_manager = [
            'ai_provider', 'ai_model', 'content_title_bulk', 'content_keywords',
            'ai_temperature', 'post_type', 'post_author',
            'content_length',
            'post_status',
            'post_content_format',
            'schedule_mode', 'smart_schedule_start_datetime', 'smart_schedule_interval_value', 'smart_schedule_interval_unit',
            'post_categories',
            'prompt_mode', 'custom_title_prompt', 'custom_content_prompt',
            'generate_meta_description', 'custom_meta_prompt',
            'generate_focus_keyword', 'custom_keyword_prompt',
            'generate_excerpt', 'custom_excerpt_prompt',
            'generate_tags', 'custom_tags_prompt',
            'cw_generation_mode', 'rss_feeds',
            'gsheets_sheet_id', 'gsheets_credentials',
            'url_list',
            'content_title',
            'generate_toc',
            'generate_seo_slug', // NEW: Add generate_seo_slug
            'seo_score_improvement_enabled', 'seo_score_continue_until_target',
            'seo_score_target', 'seo_score_max_passes', 'seo_score_profile', 'seo_score_disabled_rules',
            'generate_images_enabled', 'image_provider', 'image_model', 'image_provider_options', 'image_prompt',
            'image_count', 'image_placement', 'image_placement_param_x', 'image_alignment', 'image_size',
            'generate_featured_image', 'featured_image_prompt',
            'pexels_orientation', 'pexels_size', 'pexels_color',
            'pixabay_orientation', 'pixabay_image_type', 'pixabay_category',
            'enable_vector_store', 'vector_store_provider', 'openai_vector_store_ids', 'google_file_search_store_names',
            'pinecone_index_name', 'qdrant_collection_name', 'chroma_collection_name', 'local_store_id', 'vector_embedding_provider',
            'vector_embedding_model', 'vector_store_top_k', 'vector_store_confidence_threshold',
            'rss_include_keywords', 'rss_exclude_keywords', 'rss_item_limit',
            'reasoning_effort',
        ];
        $prompt_template_keys = [
            'custom_title_prompt', 'custom_content_prompt', 'custom_meta_prompt',
            'custom_keyword_prompt', 'custom_excerpt_prompt', 'custom_tags_prompt',
            'image_prompt', 'featured_image_prompt',
        ];
        $textarea_keys = [
            'content_title_bulk', 'rss_feeds', 'url_list', 'rss_include_keywords',
            'rss_exclude_keywords', 'content_title', 'smart_schedule_start_datetime',
        ];

        foreach ($allowed_keys_from_template_manager as $key) {
            if (isset($post_data[$key])) {
                if (in_array($key, $prompt_template_keys, true)) {
                    $content_writer_config[$key] = AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($post_data[$key]));
                } elseif (in_array($key, $textarea_keys, true)) {
                    $content_writer_config[$key] = sanitize_textarea_field(wp_unslash($post_data[$key]));
                } elseif ($key === 'gsheets_credentials') {
                    if (class_exists('\WPAICG\Lib\Utils\AIPKit_Google_Credentials_Handler')) {
                        // The handler returns an array or null, which will be properly JSON encoded later.
                        $content_writer_config[$key] = \WPAICG\Lib\Utils\AIPKit_Google_Credentials_Handler::process_credentials($post_data[$key]);
                    } else {
                        $content_writer_config[$key] = null;
                    }
                } elseif ($key === 'post_content_format') {
                    $content_writer_config[$key] = \WPAICG\ContentWriter\AIPKit_Content_Writer_Block_Converter::normalize_format($post_data[$key]);
                } elseif ($key === 'image_provider_options') {
                    $content_writer_config[$key] = class_exists(AIPKit_Content_Writer_Image_Provider_Options::class)
                        ? AIPKit_Content_Writer_Image_Provider_Options::sanitize_options_json($post_data[$key], $post_data)
                        : '{}';
                } elseif ($key === 'ai_provider') {
                    $provider_raw = sanitize_text_field(wp_unslash($post_data[$key]));
                    $provider_key = strtolower($provider_raw);
                    $provider_map = [
                        'openai' => 'OpenAI',
                        'openrouter' => 'OpenRouter',
                        'google' => 'Google',
                        'azure' => 'Azure',
                        'claude' => 'Claude',
                        'deepseek' => 'DeepSeek',
                        'ollama' => 'Ollama',
                        'xai' => 'xAI',
                    ];
                    $content_writer_config[$key] = $provider_map[$provider_key] ?? \WPAICG\AIPKit_Providers::normalize_provider_label($provider_raw);
                } elseif ($key === 'image_provider') {
                    $image_provider_key = sanitize_key(wp_unslash($post_data[$key]));
                    $allowed_image_providers = ['openai', 'aipuffercloud', 'openrouter', 'google', 'azure', 'xai', 'replicate', 'pexels', 'pixabay'];
                    $content_writer_config[$key] = in_array($image_provider_key, $allowed_image_providers, true) ? $image_provider_key : 'openai';
                } elseif (in_array($key, ['generate_meta_description', 'generate_focus_keyword', 'generate_excerpt', 'generate_tags', 'generate_toc', 'generate_images_enabled', 'generate_featured_image', 'enable_vector_store', 'generate_seo_slug', 'seo_score_improvement_enabled', 'seo_score_continue_until_target'], true)) {
                    $content_writer_config[$key] = ($post_data[$key] === '1' || $post_data[$key] === true || $post_data[$key] === 1) ? '1' : '0';
                } elseif ($key === 'post_categories' && is_array($post_data[$key])) {
                    $content_writer_config[$key] = array_map('absint', $post_data[$key]);
                } elseif ($key === 'post_author' || in_array($key, ['image_count', 'image_placement_param_x', 'vector_store_top_k', 'vector_store_confidence_threshold', 'smart_schedule_interval_value'], true)) {
                    $content_writer_config[$key] = absint($post_data[$key]);
                } elseif ($key === 'seo_score_target') {
                    $raw = isset($post_data[$key]) ? absint($post_data[$key]) : 100;
                    $content_writer_config[$key] = (string) max(80, min($raw, 100));
                } elseif ($key === 'seo_score_max_passes') {
                    $raw = isset($post_data[$key]) ? absint($post_data[$key]) : 3;
                    $content_writer_config[$key] = (string) max(1, min($raw, 5));
                } elseif ($key === 'seo_score_disabled_rules') {
                    $content_writer_config[$key] = class_exists(AIPKit_Content_Writer_SEO_Config::class)
                        ? AIPKit_Content_Writer_SEO_Config::sanitize_disabled_rules($post_data[$key])
                        : '[]';
                } elseif ($key === 'ai_temperature') {
                    $content_writer_config[$key] = (string)floatval($post_data[$key]);
                } elseif (in_array($key, ['openai_vector_store_ids', 'google_file_search_store_names'], true) && is_array($post_data[$key])) {
                    $content_writer_config[$key] = array_map('sanitize_text_field', $post_data[$key]);
                } elseif ($key === 'reasoning_effort') {
                    $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($post_data[$key] ?? '');
                    $content_writer_config[$key] = $reasoning_effort !== '' ? $reasoning_effort : 'none';
                } elseif ($key === 'seo_score_profile') {
                    $profile = sanitize_key($post_data[$key]);
                    $allowed_profiles = ['auto', 'aipkit', 'yoast', 'rank_math', 'aioseo', 'framework'];
                    $content_writer_config[$key] = in_array($profile, $allowed_profiles, true) ? $profile : 'auto';
                } elseif (in_array($key, ['schedule_mode', 'smart_schedule_interval_unit', 'content_length'], true)) {
                    $content_writer_config[$key] = sanitize_key($post_data[$key]);
                } elseif (is_string($post_data[$key])) {
                    $content_writer_config[$key] = sanitize_text_field(wp_unslash($post_data[$key]));
                } else {
                    $content_writer_config[$key] = $post_data[$key];
                }
            }
        }

        $task_type = sanitize_key($post_data['task_type'] ?? 'content_writing_bulk');
        $mode = str_replace('content_writing_', '', $task_type);
        if ($mode === 'content_writing') {
            $mode = 'bulk';
        } // The base type means bulk

        // Keep exactly one content source. Inactive source values may still be
        // present in the form so users can switch back without losing drafts.
        $source_keys_by_mode = [
            'bulk' => ['content_title_bulk'],
            'csv' => ['content_title'],
            'rss' => ['rss_feeds', 'rss_include_keywords', 'rss_exclude_keywords', 'rss_item_limit'],
            'url' => ['url_list'],
            'gsheets' => ['gsheets_sheet_id', 'gsheets_credentials'],
        ];
        $all_source_keys = array_unique(array_merge(...array_values($source_keys_by_mode)));
        $active_source_keys = $source_keys_by_mode[$mode] ?? $source_keys_by_mode['bulk'];
        foreach ($all_source_keys as $source_key) {
            if (!in_array($source_key, $active_source_keys, true)) {
                unset($content_writer_config[$source_key]);
            }
        }

        // Bulk rows use a separate form field; normalize it only when Batch
        // Editor is the selected source so it can never overwrite CSV data.
        if ($mode === 'bulk' && !empty($content_writer_config['content_title_bulk'])) {
            $content_writer_config['content_title'] = $content_writer_config['content_title_bulk'];
        }
        unset($content_writer_config['content_title_bulk']);

        $image_provider = sanitize_key((string) ($content_writer_config['image_provider'] ?? 'openai'));
        $allowed_image_providers = ['openai', 'aipuffercloud', 'openrouter', 'google', 'azure', 'xai', 'replicate', 'pexels', 'pixabay'];
        $content_writer_config['image_provider'] = in_array($image_provider, $allowed_image_providers, true) ? $image_provider : 'openai';
        if (isset($content_writer_config['image_model'])) {
            $image_model = sanitize_text_field((string) $content_writer_config['image_model']);
            if ($content_writer_config['image_provider'] === 'openai' && class_exists(AIPKit_Providers::class)) {
                $image_model = AIPKit_Providers::normalize_openai_image_model($image_model);
            } elseif ($content_writer_config['image_provider'] === 'google' && class_exists(AIPKit_Providers::class)) {
                $image_model = AIPKit_Providers::normalize_google_image_model($image_model);
            } elseif ($content_writer_config['image_provider'] === 'xai' && class_exists(AIPKit_Providers::class)) {
                $image_model = AIPKit_Providers::normalize_xai_image_model($image_model);
            } elseif (in_array($content_writer_config['image_provider'], ['pexels', 'pixabay'], true)) {
                $image_model = '';
            }
            $content_writer_config['image_model'] = $image_model;
        }

        $content_writer_config['cw_generation_mode'] = $mode;

        if ($mode === 'bulk' && !content_writing_has_manual_topic((string) ($content_writer_config['content_title'] ?? ''))) {
            return new WP_Error('missing_content_title_cw', __('Please add at least one topic.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $content_writer_config['task_frequency'] = isset($post_data['task_frequency']) ? sanitize_key($post_data['task_frequency']) : 'daily';
        $content_writer_config['task_status_on_creation'] = isset($post_data['task_status']) ? sanitize_key($post_data['task_status']) : 'active';

        $content_writer_config = AIPKit_Content_Writer_Template_Manager::finalize_task_config($content_writer_config);
        if (is_wp_error($content_writer_config)) {
            return $content_writer_config;
        }

        if (($content_writer_config['enable_vector_store'] ?? '0') === '1') {
            $vector_provider = $content_writer_config['vector_store_provider'] ?? 'openai';
            $has_vector_source = false;
            if ($vector_provider === 'openai') {
                $has_vector_source = !empty($content_writer_config['openai_vector_store_ids']);
            } elseif ($vector_provider === 'google') {
                $has_vector_source = !empty($content_writer_config['google_file_search_store_names']);
            } elseif ($vector_provider === 'pinecone') {
                $has_vector_source = !empty($content_writer_config['pinecone_index_name']);
            } elseif ($vector_provider === 'qdrant') {
                $has_vector_source = !empty($content_writer_config['qdrant_collection_name']);
            } elseif ($vector_provider === 'chroma') {
                $has_vector_source = !empty($content_writer_config['chroma_collection_name']);
            } elseif ($vector_provider === 'local') {
                $has_vector_source = !empty($content_writer_config['local_store_id']);
            }
            if (!$has_vector_source) {
                return new WP_Error('missing_vector_source', __('Please select a knowledge source before enabling context.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            if ($vector_provider === 'google' && ($content_writer_config['ai_provider'] ?? '') !== 'Google') {
                return new WP_Error('google_file_search_provider_mismatch', __('Google File Search requires Google as the AI provider.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
        }
    }
    return $content_writer_config;
}

/**
 * Builds and validates the configuration for a 'community_reply_comments' task.
 *
 * @param array $post_data The raw POST data.
 * @return array|WP_Error The validated config array or WP_Error on failure.
 */
function build_task_config_comment_reply_logic(array $post_data)
{
    $task_config = [];
    // AI & Prompt Settings
    $provider_raw = $post_data['cc_ai_provider'] ?? 'openai';
    switch (strtolower($provider_raw)) {
        case 'openai':
            $task_config['ai_provider'] = 'OpenAI';
            break;
        case 'openrouter':
            $task_config['ai_provider'] = 'OpenRouter';
            break;
        case 'google':
            $task_config['ai_provider'] = 'Google';
            break;
        case 'azure':
            $task_config['ai_provider'] = 'Azure';
            break;
        case 'claude':
            $task_config['ai_provider'] = 'Claude';
            break;
        case 'deepseek':
            $task_config['ai_provider'] = 'DeepSeek';
            break;
        case 'xai':
            $task_config['ai_provider'] = 'xAI';
            break;
        case 'ollama':
            $task_config['ai_provider'] = 'Ollama';
            break;
        default:
            $task_config['ai_provider'] = \WPAICG\AIPKit_Providers::normalize_provider_label((string) $provider_raw);
            break;
    }

    $task_config['ai_model'] = $post_data['cc_ai_model'] ?? '';
    $task_config['ai_temperature'] = isset($post_data['cc_ai_temperature']) ? floatval($post_data['cc_ai_temperature']) : 1.0;
    $task_config['content_max_tokens'] = isset($post_data['cc_content_max_tokens']) ? absint($post_data['cc_content_max_tokens']) : 4000;
    $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($post_data['cc_reasoning_effort'] ?? '');
    $task_config['reasoning_effort'] = $reasoning_effort !== '' ? $reasoning_effort : 'none';
    $task_config['custom_content_prompt'] = isset($post_data['cc_custom_content_prompt']) ? AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($post_data['cc_custom_content_prompt'])) : '';

    // Comment-specific settings
    $task_config['post_types_for_comments'] = isset($post_data['post_types_for_comments']) && is_array($post_data['post_types_for_comments']) ? array_map('sanitize_key', $post_data['post_types_for_comments']) : [];
    $task_config['reply_action'] = isset($post_data['reply_action']) && in_array($post_data['reply_action'], ['approve', 'hold'], true) ? $post_data['reply_action'] : 'hold';
    $task_config['no_reply_to_replies'] = isset($post_data['no_reply_to_replies']) ? '1' : '0';

    // Filters
    $task_config['include_keywords'] = isset($post_data['include_keywords']) ? sanitize_textarea_field(wp_unslash($post_data['include_keywords'])) : '';
    $task_config['exclude_keywords'] = isset($post_data['exclude_keywords']) ? sanitize_textarea_field(wp_unslash($post_data['exclude_keywords'])) : '';

    // Task Frequency
    $task_config['task_frequency'] = isset($post_data['task_frequency']) ? sanitize_key($post_data['task_frequency']) : 'hourly';

    // Validation
    if (empty($task_config['ai_provider']) || empty($task_config['ai_model'])) {
        return new WP_Error('missing_ai_config_comments', __('AI Provider and Model are required.', 'gpt3-ai-content-generator'));
    }
    if (empty($task_config['post_types_for_comments'])) {
        return new WP_Error('missing_post_types_comments', __('Please select at least one post type to monitor for comments.', 'gpt3-ai-content-generator'));
    }
    if (empty($task_config['custom_content_prompt'])) {
        return new WP_Error('missing_prompt_comments', __('The reply prompt cannot be empty.', 'gpt3-ai-content-generator'));
    }

    return $task_config;
}

/**
* Builds and validates the configuration for an 'enhance_existing_content' task.
*
* @param array $post_data The raw POST data.
* @return array|WP_Error The validated config array or WP_Error on failure.
*/
function build_task_config_enhancement_logic(array $post_data)
{
    if (!\WPAICG\AutoGPT\Ajax\load_paid_action_logic()) {
        return new WP_Error('task_type_requires_pro_plan', __('This is a Pro feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }

    return \WPAICG\Lib\AutoGPT\Actions\build_task_config_enhancement_logic($post_data);
}

/**
* Inserts or updates a task in the database.
*
* @param string $task_name The name of the task.
* @param string $task_type The type of the task.
* @param array $task_config The task's configuration data.
* @param string $task_status The status ('active' or 'paused').
* @param int $task_id The task ID (0 for new tasks).
* @return int|WP_Error The ID of the saved task, or a WP_Error on failure.
*/
function save_task_to_database_logic(string $task_name, string $task_type, array $task_config, string $task_status, int $task_id)
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';

    $data = [
    'task_name' => $task_name,
    'task_type' => $task_type,
    'task_config' => wp_json_encode($task_config),
    'status' => $task_status,
    'updated_at' => current_time('mysql', 1),
    ];
    $formats = ['%s', '%s', '%s', '%s', '%s'];

    if ($task_id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct lookup in a custom table before updating a task.
        $existing_task_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . esc_sql($tasks_table_name) . " WHERE id = %d", $task_id));
        if ($existing_task_id <= 0) {
            return new WP_Error('task_not_found', __('Task not found.', 'gpt3-ai-content-generator'), ['status' => 404]);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caching is handled at the read level.
        $result = $wpdb->update($tasks_table_name, $data, ['id' => $task_id], $formats, ['%d']);
        if ($result === false) {
            return new WP_Error('db_error_update_task', __('Failed to update task.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        return $task_id;
    } else {
        $data['created_at'] = current_time('mysql', 1);
        $formats[] = '%s';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason: Direct insert into a custom table.
        $result = $wpdb->insert($tasks_table_name, $data, $formats);
        if ($result === false) {
            return new WP_Error('db_error_insert_task', __('Failed to save new task.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        return $wpdb->insert_id;
    }
}

if (file_exists(WPAICG_PLUGIN_DIR . 'classes/automations/content-enhancement-trigger.php')) {
    require_once WPAICG_PLUGIN_DIR . 'classes/automations/content-enhancement-trigger.php';
}

/**
* Finalizes the task saving process by scheduling the cron event and queueing initial content if necessary.
*
* @param int $task_id The ID of the saved task.
* @param array $task_config The configuration of the task.
* @param string $task_status The status of the task ('active' or 'paused').
* @param bool $is_new_task Whether the task was just created.
* @return void
*/
function finalize_task_save_logic(int $task_id, array $task_config, string $task_status, bool $is_new_task): void
{
    if (class_exists(AIPKit_Automated_Task_Scheduler::class)) {
        $frequency = $task_config['task_frequency'] ?? ($task_config['indexing_frequency'] ?? 'daily');
        AIPKit_Automated_Task_Scheduler::schedule_task_event($task_id, $frequency, $task_status);
    }
    if (
        $is_new_task &&
        $task_status === 'active' &&
        ($task_config['task_type'] ?? '') === 'content_indexing' &&
        ($task_config['index_existing_now_flag'] ?? '0') === '1' &&
        class_exists(AIPKit_Automated_Task_Content_Queuer::class)
    ) {
        AIPKit_Automated_Task_Content_Queuer::maybe_queue_initial_indexing_content($task_id, $task_config);
    } elseif (
        $is_new_task &&
        $task_status === 'active' &&
        ($task_config['task_type'] ?? '') === 'enhance_existing_content' &&
        ($task_config['enhance_existing_now_flag'] ?? '0') === '1' &&
        function_exists('\WPAICG\AutoGPT\Cron\EventProcessor\Trigger\trigger_content_enhancement_task_logic') &&
        \WPAICG\AutoGPT\Ajax\load_paid_action_logic()
    ) {
        \WPAICG\Lib\AutoGPT\Actions\finalize_enhancement_save_logic($task_id, $task_config);
    }
}

namespace WPAICG\AutoGPT\Ajax\Actions\RunNow;

use WPAICG\AutoGPT\Ajax\AIPKit_Run_Automated_Task_Now_Action;
use WPAICG\AutoGPT\Helpers;
use WP_Error;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Content_Queuer;
use WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules as ContentWritingModules;
use WPAICG\AutoGPT\Cron\EventProcessor\Trigger;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Validates the request for running a task now.
 * Checks permissions, task ID, and task status.
 *
 * @param AIPKit_Run_Automated_Task_Now_Action $handler The handler instance.
 * @return array|WP_Error The task data array on success, or a WP_Error on failure.
 */
function validate_task_and_permissions_logic(AIPKit_Run_Automated_Task_Now_Action $handler)
{
    // Check permissions
    $permission_check = $handler->check_module_access_permissions('autogpt', $handler::NONCE_ACTION);
    if (is_wp_error($permission_check)) {
        return $permission_check;
    }

    // Get and validate task ID
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
    $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;

    if (empty($task_id)) {
        return new WP_Error('missing_task_id_run_now', __('Task ID is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    // Fetch and validate the task from the database
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caching is handled at the read level.
    $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tasks_table_name} WHERE id = %d", $task_id), ARRAY_A);

    if (!$task) {
        return new WP_Error('task_not_found_run', __('Task not found.', 'gpt3-ai-content-generator'), ['status' => 404]);
    }

    if ($task['status'] !== 'active') {
        return new WP_Error('task_not_active_run', __('Task must be active to run now.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }
    if (Helpers\task_type_requires_pro_plan((string) ($task['task_type'] ?? '')) && !Helpers\is_pro_plan_active()) {
        return new WP_Error('task_type_requires_pro_plan_run_now', __('This is a Pro feature.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }

    return $task;
}

/**
 * Queues all existing matching content for a "Run Now" action on a content indexing task.
 *
 * @param int $task_id The ID of the task.
 * @param array $task_config The configuration of the task.
 * @return void
 */
function run_now_content_indexing_logic(int $task_id, array $task_config): void
{
    if (class_exists(AIPKit_Automated_Task_Content_Queuer::class)) {
        // For "Run Now", we want to queue all existing content that matches, ignoring last run time.
        AIPKit_Automated_Task_Content_Queuer::maybe_queue_initial_indexing_content($task_id, $task_config, true);
    }
}

require_once WPAICG_PLUGIN_DIR . 'classes/automations/module-triggers.php';

/**
 * Queues items for a "Run Now" action on a content writing task.
 * This function now acts as an orchestrator, delegating logic to modular components.
 *
 * @param int $task_id The ID of the task.
 * @param array $task_config The configuration of the task.
 * @return true|WP_Error True on success, WP_Error if no titles are found.
 */
function run_now_content_writing_logic(int $task_id, array $task_config)
{
    $generation_mode = $task_config['cw_generation_mode'] ?? 'single';
    $topics_to_queue = [];
    $scraped_contexts = [];

    // 1. Generate items based on the generation mode
    switch ($generation_mode) {
        case 'rss':
            // For a "Run Now" action on RSS, we pass null to get all recent items, not just since last run.
            $topics_to_queue = ContentWritingModules\rss_mode_generate_items_logic($task_id, $task_config, null);
            break;
        case 'gsheets':
            $topics_to_queue = ContentWritingModules\gsheets_mode_generate_items_logic($task_id, $task_config);
            break;
        case 'url':
            $result = ContentWritingModules\url_mode_generate_items_logic($task_id, $task_config);
            if (!is_wp_error($result)) {
                $topics_to_queue = $result['topics'];
                $scraped_contexts = $result['contexts'];
            } else {
                $topics_to_queue = $result; // Pass the WP_Error object
            }
            break;
        default: // 'single', 'bulk', 'csv'
            $topics_to_queue = ContentWritingModules\manual_mode_generate_items_logic($task_config);
            break;
    }

    if (is_wp_error($topics_to_queue)) {
        return $topics_to_queue;
    }
    if (empty($topics_to_queue)) {
        return new WP_Error('no_titles_to_queue', __('No new or valid items found in the source to generate content for.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    // 2. Loop through generated items and queue them
    $item_index = 0;
    foreach ($topics_to_queue as $index => $item_data) {
        $item_config = ContentWritingModules\prepare_item_config_logic($item_data, $task_config, $scraped_contexts);
        $item_config['task_id'] = $task_id;
        if (empty($item_config['content_title'])) {
            continue;
        }

        // Unified scheduling helper
        $scheduled_gmt_time = ContentWritingModules\compute_item_schedule_gmt_logic($item_data, $task_config, $item_index, $generation_mode);
        if (is_wp_error($scheduled_gmt_time)) {
            return $scheduled_gmt_time;
        }
        if ($scheduled_gmt_time) {
            $item_config['scheduled_gmt_time'] = $scheduled_gmt_time;
        }

        $target_identifier = ContentWritingModules\generate_target_identifier_logic($item_data, $task_id, $index);
        if ($generation_mode !== 'bulk' && $generation_mode !== 'csv' && $generation_mode !== 'single') {
            if (ContentWritingModules\is_duplicate_topic_logic($task_id, $target_identifier)) {
                continue;
            }
        }

        if (ContentWritingModules\insert_topic_into_queue_logic($task_id, $target_identifier, $item_config)) {
            $item_index++;
        }
    }

    return true;
}

/**
 * Queues items for a "Run Now" action on a comment reply task.
 * This is essentially the same as a scheduled trigger.
 *
 * @param int $task_id The ID of the task.
 * @param array $task_config The configuration of the task.
 * @param string|null $last_run_time The last time the task ran.
 * @return void
 */
function run_now_comment_reply_logic(int $task_id, array $task_config, ?string $last_run_time): void
{
    // The logic to queue comments is the same whether it's a scheduled run or a "Run Now" trigger.
    // A manual run includes all matching comments by passing a null last-run time.
    if (function_exists('\WPAICG\AutoGPT\Cron\EventProcessor\Trigger\trigger_comment_reply_task_logic')) {
        Trigger\trigger_comment_reply_task_logic($task_id, $task_config, null);
    }
}

/**
 * Queues items for a "Run Now" action on a content enhancement task.
 * This is the same logic as the scheduled trigger.
 *
 * @param int $task_id The ID of the task.
 * @param array $task_config The configuration of the task.
 * @return void
 */
function run_now_content_enhancement_logic(int $task_id, array $task_config): void
{
    if (\WPAICG\AutoGPT\Ajax\load_paid_action_logic()) {
        \WPAICG\Lib\AutoGPT\Actions\run_now_content_enhancement_logic($task_id, $task_config);
    }
}

/**
 * Finalizes the "Run Now" action by updating the task's last run time
 * and triggering the queue processor.
 *
 * @param int $task_id The ID of the task.
 * @return void
 */
function finalize_run_now_task_logic(int $task_id): void
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';

    // Update last_run_time for the main task
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table is necessary for this action.
    $wpdb->update(
        $tasks_table_name,
        ['last_run_time' => current_time('mysql', 1)],
        ['id' => $task_id],
        ['%s'],
        ['%d']
    );

    // Trigger main queue processing immediately by scheduling a one-off event
    if (class_exists(AIPKit_Automated_Task_Event_Processor::class)) {
        // Schedule a one-off event to start processing the queue almost immediately.
        // This decouples the potentially long-running queue processing from the AJAX request.
        wp_schedule_single_event(time() + 5, AIPKit_Automated_Task_Event_Processor::MAIN_CRON_HOOK);
    }
}
