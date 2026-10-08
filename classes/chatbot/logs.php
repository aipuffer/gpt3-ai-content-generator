<?php

namespace {
    if (!defined('ABSPATH')) {
        exit;
    }
}

namespace WPAICG\Chat\Storage\LoggerMethods {

use WPAICG\Core\AIPKit_Payload_Sanitizer;

/**
 * Generates a unique ID for message parents.
 *
 * @return string
 */
function generate_parent_id_logic(): string {
    return str_replace('.', '', uniqid('aipkit-parent-', true));
}

/**
 * Generates a unique ID for individual messages, removing the dot.
 *
 * @return string
 */
function generate_message_id_logic(): string {
    return str_replace('.', '', uniqid('aipkit-msg-', true));
}

/**
 * Sanitizes a payload array for safe log persistence.
 *
 * @param mixed $payload
 * @return mixed
 */
function sanitize_chat_log_payload_if_array($payload) {
    return AIPKit_Payload_Sanitizer::sanitize_payload_if_array($payload);
}

/**
 * Builds the new message object for logging.
 * UPDATED: Moved request_payload and response_data handling to be general.
 * UPDATED: Add provider, model, usage, feedback, and OpenAI/Google specific fields if present in log_data, regardless of role.
 * ADDED: Handling for 'system' role with 'trigger_log' event_sub_type to store detailed trigger log data.
 * FIXED: Ensure 'form_submission_stored' subtype correctly logs 'form_id' and 'submitted_data_snapshot'.
 *
 * @param array $log_data Associative array containing message details.
 * @param string $message_id The generated or provided message ID.
 * @param int $current_timestamp The current timestamp for the message.
 * @return array The structured message object.
 */
function build_message_object_logic(array $log_data, string $message_id, int $current_timestamp): array {
    $new_message = [
        'message_id'=> $message_id,
        'role'      => sanitize_key($log_data['message_role']),
        'content'   => wp_kses_post($log_data['message_content']), // wp_kses_post for message content
        'timestamp' => $current_timestamp,
    ];

    if ($new_message['role'] === 'system' && isset($log_data['event_sub_type']) && $log_data['event_sub_type'] === 'trigger_log') {
        $new_message['event_sub_type'] = 'trigger_log'; // Explicitly store this sub-type

        if (function_exists('WPAICG\\Chat\\Storage\\ProLogMethods\\build_trigger_message')) {
            return \WPAICG\Chat\Storage\ProLogMethods\build_trigger_message($log_data, $new_message);
        }
        return $new_message; // Return early for trigger logs
    }


    // Add these fields if they exist in log_data, regardless of role (for user/bot messages)
    if (isset($log_data['ai_provider']) && !empty($log_data['ai_provider'])) {
        $new_message['provider'] = sanitize_text_field($log_data['ai_provider']);
    }
    if (isset($log_data['ai_model']) && !empty($log_data['ai_model'])) {
        $new_message['model'] = sanitize_text_field($log_data['ai_model']);
    }
    if (isset($log_data['usage']) && is_array($log_data['usage'])) {
        $new_message['usage'] = $log_data['usage']; // Assume usage data is safe
    }
    if (isset($log_data['feedback'])) {
        $new_message['feedback'] = sanitize_key($log_data['feedback']);
    }
    if (isset($log_data['request_payload'])) {
        $new_message['request_payload'] = sanitize_chat_log_payload_if_array($log_data['request_payload']);
    }
    if (isset($log_data['response_data'])) {
        $new_message['response_data'] = sanitize_chat_log_payload_if_array($log_data['response_data']);
    }
    // Store OpenAI specific IDs
    if (isset($log_data['openai_response_id']) && !empty($log_data['openai_response_id'])) {
        $new_message['openai_response_id'] = sanitize_text_field($log_data['openai_response_id']);
    }
    if (isset($log_data['google_interaction_id']) && !empty($log_data['google_interaction_id'])) {
        $new_message['google_interaction_id'] = sanitize_text_field($log_data['google_interaction_id']);
    }
    if (!empty($log_data['used_previous_google_interaction_id'])) {
        $new_message['used_previous_google_interaction_id'] = true;
    }
    if (isset($log_data['used_previous_response_id']) && $log_data['used_previous_response_id'] === true) {
        $new_message['used_previous_response_id'] = true;
    }
    if (isset($log_data['citations']) && is_array($log_data['citations']) && !empty($log_data['citations'])) {
        $new_message['citations'] = sanitize_chat_log_payload_if_array($log_data['citations']);
    }
    // Store Vector Search Scores
    if (isset($log_data['vector_search_scores']) && is_array($log_data['vector_search_scores']) && !empty($log_data['vector_search_scores'])) {
        $new_message['vector_search_scores'] = $log_data['vector_search_scores'];
    }

    return $new_message;
}

/**
 * Builds WHERE clauses and parameters for finding an existing conversation log row.
 *
 * @param string $conversation_uuid
 * @param string $module
 * @param int|null $bot_id
 * @param int|null $user_id
 * @param string|null $session_id
 * @return array ['where_sql' => string, 'params' => array]
 */
function build_where_clauses_logic(
    string $conversation_uuid,
    string $module,
    ?int $bot_id,
    ?int $user_id,
    ?string $session_id
): array {
    $where_clauses = ["conversation_uuid = %s", "module = %s"];
    $params = [$conversation_uuid, $module];

    if ($bot_id !== null) {
        $where_clauses[] = "bot_id = %d";
        $params[] = $bot_id;
    } else {
        $where_clauses[] = "bot_id IS NULL";
    }

    if ($user_id) {
        $where_clauses[] = "user_id = %d";
        $params[] = $user_id;
    } else {
        // Ensure session_id is not empty for guest condition
        if (empty($session_id)) {
            // This case should ideally be caught by validation in log_message
            // but adding a safeguard here.
            // Fallback to a condition that won't match anything safely or throw an error.
            // For now, let it proceed, log_message should have caught it.
            $where_clauses[] = "1=0"; // Will not match
        } else {
            $where_clauses[] = "(user_id IS NULL AND session_id = %s AND is_guest = 1)";
            $params[] = $session_id;
        }
    }
    return ['where_sql' => implode(" AND ", $where_clauses), 'params' => $params];
}

/**
 * Updates an existing conversation log row with a new message.
 *
 * @param \wpdb $wpdb WordPress database object.
 * @param string $table_name The name of the log table.
 * @param array $existing_log_row The existing log row data from DB.
 * @param array $new_message The new message object to add.
 * @param int $current_timestamp The current timestamp for the message.
 * @param string|null $ip_to_store Address stored according to the privacy setting.
 * @param string|null $user_wp_role User's WordPress role.
 * @param bool $ip_anonymized Whether the stored address was shortened.
 * @return array|false ['log_id' => int, 'message_id' => string] on success, false on failure.
 */
function update_existing_log_logic(
    \wpdb $wpdb,
    string $table_name,
    array $existing_log_row,
    array $new_message,
    int $current_timestamp,
    ?string $ip_to_store,
    ?string $user_wp_role,
    bool $ip_anonymized = false
) {
    $log_id = absint($existing_log_row['id']);
    $messages_json = $existing_log_row['messages'] ?? null;
    $conversation_data = $messages_json ? json_decode($messages_json, true) : null;

    if (!is_array($conversation_data) || !isset($conversation_data['parent_id']) || !isset($conversation_data['messages'])) {
        $parent_id = generate_parent_id_logic(); // Call namespaced function
        $messages_array = [];
    } else {
        $parent_id = $conversation_data['parent_id'];
        $messages_array = $conversation_data['messages'];
         if (!is_array($messages_array)) $messages_array = []; // Ensure it's an array
    }

    $messages_array[] = $new_message;

    $updated_conversation_data = [
        'parent_id' => $parent_id,
        'messages' => $messages_array,
        'ip_anonymized' => $ip_anonymized,
    ];

    $update_data_fields = [
        'messages'         => wp_json_encode($updated_conversation_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'message_count'    => count($messages_array),
        'last_message_ts'  => $current_timestamp,
        'updated_at'       => current_time('mysql', 1),
        'ip_address'       => $ip_to_store, // This might be null
        'user_wp_role'     => $user_wp_role ? sanitize_text_field($user_wp_role) : null, // This might be null
    ];
    $update_formats_map = ['%s', '%d', '%d', '%s', '%s', '%s']; // Corresponds to update_data_fields order

    $data_to_update = [];
    $formats_to_use = [];
    foreach ($update_data_fields as $key => $value) {
        // Include key if it's explicitly not null, OR if it's one of the keys that *can* be null
        if ($value !== null || in_array($key, ['ip_address', 'user_wp_role'])) {
             $data_to_update[$key] = $value;
             $key_index = array_search($key, array_keys($update_data_fields));
             if ($key_index !== false) {
                 $formats_to_use[] = $update_formats_map[$key_index];
             }
        }
    }

    if (empty($data_to_update)) {
        // Nothing changed except potentially the messages array itself if no other metadata was updated
        // This path should ideally not be taken if we always update 'messages' and 'message_count'
        return ['log_id' => $log_id, 'message_id' => $new_message['message_id']];
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table is necessary. Conversation readers query current data.
    $updated = $wpdb->update(
        $table_name,
        $data_to_update,
        ['id' => $log_id],
        $formats_to_use,
        ['%d'] // WHERE format for id
    );

    if ($updated === false) {
        return false;
    }

    return ['log_id' => $log_id, 'message_id' => $new_message['message_id']];
}

/**
 * Inserts a new conversation log row.
 *
 * @param \wpdb $wpdb WordPress database object.
 * @param string $table_name The name of the log table.
 * @param int|null $bot_id
 * @param int|null $user_id
 * @param string|null $session_id
 * @param string $conversation_uuid
 * @param string $module
 * @param int $is_guest
 * @param array $new_message The first message object.
 * @param int $current_timestamp The current timestamp for the message.
 * @param string|null $ip_to_store Address stored according to the privacy setting.
 * @param string|null $user_wp_role User's WordPress role.
 * @param bool $ip_anonymized Whether the stored address was shortened.
 * @return array|false ['log_id' => int, 'message_id' => string, 'is_new_session' => true] on success, false on failure.
 */
function insert_new_log_logic(
    \wpdb $wpdb,
    string $table_name,
    ?int $bot_id,
    ?int $user_id,
    ?string $session_id,
    string $conversation_uuid,
    string $module,
    int $is_guest,
    array $new_message,
    int $current_timestamp,
    ?string $ip_to_store,
    ?string $user_wp_role,
    bool $ip_anonymized = false
) {
    $parent_id = generate_parent_id_logic(); // Call namespaced function
    $messages_array = [$new_message];

    $conversation_data = [
         'parent_id' => $parent_id,
         'messages' => $messages_array,
         'ip_anonymized' => $ip_anonymized,
    ];

    $insert_data_fields = [
        'bot_id'            => $bot_id,
        'user_id'           => $user_id,
        'session_id'        => $session_id,
        'conversation_uuid' => $conversation_uuid,
        'module'            => $module,
        'is_guest'          => $is_guest,
        'messages'          => wp_json_encode($conversation_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'message_count'     => 1,
        'first_message_ts'  => $current_timestamp,
        'last_message_ts'   => $current_timestamp,
        'ip_address'        => $ip_to_store, // This might be null
        'user_wp_role'      => $user_wp_role ? sanitize_text_field($user_wp_role) : null, // This might be null
        'created_at'        => current_time('mysql', 1),
        'updated_at'        => current_time('mysql', 1),
    ];
    $formats_map = ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s'];

    $data_to_insert = [];
    $formats_to_use = [];
    foreach ($insert_data_fields as $key => $value) {
        // Always include the key in data_to_insert, even if null.
        // The format string will determine how $wpdb->prepare handles NULLs.
        $data_to_insert[$key] = $value;
        $key_index = array_search($key, array_keys($insert_data_fields));
        if ($key_index !== false) {
            $formats_to_use[] = $formats_map[$key_index];
        }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason: Necessary insert operation into a custom table.
    $inserted = $wpdb->insert($table_name, $data_to_insert, $formats_to_use);

    if ($inserted === false) {
        return false;
    }
    $new_log_id = $wpdb->insert_id;
    return ['log_id' => $new_log_id, 'message_id' => $new_message['message_id'], 'is_new_session' => true];
}

}

namespace WPAICG\Chat\Storage\ReaderMethods {

use WPAICG\Chat\Storage\ConversationReader;
use WPAICG\Core\AIPKit_Payload_Sanitizer;

/**
 * Redacts sensitive payload fields in an already-stored message object.
 *
 * @param array $message
 * @return array
 */
function sanitize_message_payload_fields_for_history(array $message): array {
    foreach (['request_payload', 'response_data'] as $field_key) {
        if (array_key_exists($field_key, $message)) {
            $message[$field_key] = AIPKit_Payload_Sanitizer::sanitize_payload_if_array($message[$field_key]);
        }
    }

    return $message;
}

/**
 * Logic for the get_conversation_thread_history method of ConversationReader.
 * Retrieves the conversation history (array of messages) for a specific conversation thread.
 * Handles the new JSON structure. Includes feedback, usage, openai_response_id, and used_previous_response_id.
 * MODIFIED: Filters out system messages with event_sub_type 'trigger_log'.
 *
 * @param ConversationReader $readerInstance The instance of the ConversationReader class.
 * @param int|null $user_id The user ID (null for guests).
 * @param string|null $session_id The guest UUID (null for logged-in users).
 * @param int $bot_id The bot ID.
 * @param string $conversation_uuid The specific conversation thread UUID.
 * @return array The array of messages [{message_id, role, content, timestamp, provider?, model?, feedback?, usage?, openai_response_id?, used_previous_response_id?}, ...].
 */
function get_conversation_thread_history_logic(
    ConversationReader $readerInstance,
    ?int $user_id,
    ?string $session_id,
    int $bot_id,
    string $conversation_uuid
): array {
    if (empty($bot_id) || empty($conversation_uuid) || (!$user_id && empty($session_id))) {
        return [];
    }

    $wpdb = $readerInstance->get_wpdb();
    $table_name = $readerInstance->get_table_name();

    // Check ownership on every read and reflect appended messages, feedback and
    // deletions immediately. A conversation UUID alone is not an access scope.
    $where_sql = "bot_id = %d AND conversation_uuid = %s AND ";
    $params = [$bot_id, $conversation_uuid];
    if ($user_id) {
        $where_sql .= "user_id = %d";
        $params[] = $user_id;
    } else {
        $where_sql .= "(user_id IS NULL AND session_id = %s AND is_guest = 1)";
        $params[] = $session_id;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared ownership query; uncached history must reflect current access and content.
    $messages_json = $wpdb->get_var($wpdb->prepare("SELECT messages FROM {$table_name} WHERE {$where_sql} LIMIT 1", $params));

    if (empty($messages_json)) {
        return [];
    }

    $conversation_data = json_decode($messages_json, true);
    $messages_array = null;

    // Check if it's the new structure or the old simple array
    if (is_array($conversation_data) && isset($conversation_data['parent_id']) && isset($conversation_data['messages']) && is_array($conversation_data['messages'])) {
        $messages_array = $conversation_data['messages'];
    } elseif (is_array($conversation_data)) { // Assume old structure (simple array) for backward compatibility
        $messages_array = $conversation_data;
    } else {
        return [];
    }

    $filtered_messages = [];
    foreach ($messages_array as $msg) {
        // --- MODIFICATION: Filter out system trigger logs ---
        if (isset($msg['role']) && $msg['role'] === 'system' && isset($msg['event_sub_type']) && $msg['event_sub_type'] === 'trigger_log') {
            continue; // Skip this message
        }

        if (isset($msg['timestamp'])) {
            $msg['timestamp'] = (int)$msg['timestamp'];
        }
        if (!isset($msg['message_id'])) {
            $msg['message_id'] = generate_message_id_logic(); // Call namespaced function
        }
        $msg = sanitize_message_payload_fields_for_history($msg);
        $filtered_messages[] = $msg;
    }

    return $filtered_messages;
}

/**
 * Logic for the get_all_conversation_data method of ConversationReader.
 * Retrieves summary data for all distinct conversations for a user/session and bot.
 * Handles the new JSON structure to extract the title.
 *
 * @param ConversationReader $readerInstance The instance of the ConversationReader class.
 * @param int|null $user_id The user ID (null for guests).
 * @param string|null $session_id The guest UUID (null for logged-in users).
 * @param int $bot_id The bot ID.
 * @return array|null An array of conversation summaries or null on error.
 */
function get_all_conversation_data_logic(
    ConversationReader $readerInstance,
    ?int $user_id,
    ?string $session_id,
    int $bot_id
): ?array {
    if (empty($bot_id) || (!$user_id && empty($session_id))) {
        return null;
    }

    $wpdb = $readerInstance->get_wpdb();
    $table_name = $readerInstance->get_table_name();
    $query_helper = $readerInstance->get_query_helper();

    // Lists must reflect writes, deletions and ownership changes immediately.
    $filters = ['bot_id' => $bot_id, 'user_id' => $user_id ?: 0];
    if (!$user_id) {
        $filters['session_id'] = $session_id;
    }
    $query_parts = $query_helper->build_conversation_query_parts($filters, 'last_message_ts', 'DESC', 0, 0, true);
    if (!$user_id) {
        $query_parts['where_sql'] .= " AND {$table_name}.is_guest = 1";
    }
    $query = "SELECT {$query_parts['select_sql']} FROM {$table_name} {$query_parts['join_sql']} WHERE {$query_parts['where_sql']} ORDER BY {$query_parts['orderby']} {$query_parts['order']}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query parts provide the bot/owner placeholders and parameters.
    $query = $wpdb->prepare($query, $query_parts['params']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared owner-scoped read; lists must reflect current access and content.
    $summaries = $wpdb->get_results($query, ARRAY_A);

    if ($summaries === null) {
        return null;
    }
    if (empty($summaries)) {
        return [];
    }

    $conversation_list = [];
    foreach ($summaries as $summary) {
        $conv_uuid = $summary['conversation_uuid'];
        $timestamp = (int)$summary['last_message_ts'];
        $title = $conv_uuid; // Default title

        $conversation_data = json_decode($summary['messages'] ?? '[]', true);
        $messages_array = null;
        // Check for new structure
        if (is_array($conversation_data) && isset($conversation_data['messages']) && is_array($conversation_data['messages'])) {
            $messages_array = $conversation_data['messages'];
        } elseif (is_array($conversation_data)) { // Backward compatibility for old structure
            $messages_array = $conversation_data;
        }

        if (is_array($messages_array)) {
            foreach ($messages_array as $msg) { // Find first user message
                if (($msg['role'] ?? '') === 'user' && !empty($msg['content'])) {
                    $title = wp_trim_words($msg['content'], 5, '...');
                    break;
                }
            }
            if ($title === $conv_uuid && !empty($messages_array[0]['content'])) { // Fallback to first message
                $title = wp_trim_words($messages_array[0]['content'], 5, '...');
            }
        }

        $conversation_list[] = ['id' => $conv_uuid, 'title' => $title, 'timestamp' => $timestamp];
    }

    usort($conversation_list, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return $conversation_list;
}

/**
 * Generates a unique ID for individual messages, removing the dot.
 * This logic was previously a private method in ConversationReader.
 *
 * @return string
 */
function generate_message_id_logic(): string {
    return str_replace('.', '', uniqid('aipkit-msg-', true));
}

}

namespace WPAICG\Chat\Storage {

use WPAICG\AIPKit\Addons\AIPKit_IP_Anonymization;
use WPAICG\Core\Moderation\AIPKit_Global_Security_Settings;
use WPAICG\Chat\Storage\LoggerMethods;
use WPAICG\Chat\Storage\ReaderMethods;
use WP_Error;

/**
 * Helper class to build SQL query parts for conversation log rows.
 * FIXED: Improved filter handling for message_like searches.
 * ADDED: Handles 'module' column filtering and selection.
 * FIXED: Ensure empty module filter fetches ALL logs (including NULL module).
 * FIXED: Stricter checks for bot_id and module filters to ensure empty filters don't restrict query.
 * ADDED: Module and date-range filters.
 */
class LogQueryHelper
{
    private $table_name;

    public function __construct($table_name)
    {
        $this->table_name = $table_name;
    }

    /**
     * Builds SQL query parts for fetching conversation log rows based on filters.
     *
     * @param array $filters
     *  - bot_id => int|string|null (null, '', '0' for no bot)
     *  - user_id => int|null (null for guests)
     *  - session_id => string (for guests)
     *  - conversation_uuid => string
     *  - search => string (OR match across user display_name, guest session_id, and messages JSON)
     *  - user_name => string (partial match of user display_name or session_id for guests)
     *  - message_like => string (partial match in the 'messages' JSON column - **performance warning**)
     *  - module => string (module slug; use 'chatbot' to match NULL/empty)
     *  - start_ts => int (unix timestamp, inclusive)
     *  - end_ts => int (unix timestamp, inclusive)
     * @param string $orderby Field to order by (e.g., 'updated_at', 'last_message_ts').
     * @param string $order 'ASC' or 'DESC'.
     * @param int $limit Max rows.
     * @param int $offset Start row.
     * @param bool $select_messages Whether to include the messages JSON column in the SELECT clause.
     *
     * @return array {
     *    'select_sql' => string,
     *    'where_sql' => string,
     *    'params'    => array,
     *    'orderby'   => string,
     *    'order'     => string,
     *    'limit_sql' => string,
     *    'join_sql'  => string
     * }
     */
    public function build_conversation_query_parts(
        array $filters = [],
        string $orderby = 'updated_at',
        string $order = 'DESC',
        int $limit = 50,
        int $offset = 0,
        bool $select_messages = false // Flag to include the messages JSON column
    ): array {
        global $wpdb;

        $where_clauses = ['1=1'];
        $params = [];
        $join_sql = ''; // For joining wp_users if user_name filter is used

        // --- SELECT Clause ---
        // Select core metadata by default. Include messages JSON only if requested.
        // Module is still selected because LogManager uses it for display names.
        $select_fields = [
            "{$this->table_name}.id", "{$this->table_name}.bot_id", "{$this->table_name}.user_id",
            "{$this->table_name}.session_id", "{$this->table_name}.conversation_uuid",
            "{$this->table_name}.is_guest", "{$this->table_name}.module",
            "{$this->table_name}.message_count",
            "{$this->table_name}.first_message_ts", "{$this->table_name}.last_message_ts",
            "{$this->table_name}.ip_address", "{$this->table_name}.user_wp_role",
            "{$this->table_name}.created_at", "{$this->table_name}.updated_at"
        ];
        if ($select_messages) {
            $select_fields[] = "{$this->table_name}.messages";
        }

        // --- WHERE Clause & JOIN ---

        // Bot ID Filter: Only apply if bot_id is explicitly set
        if (isset($filters['bot_id'])) {
            $bot_filter_value = trim((string)$filters['bot_id']);
            if ($bot_filter_value === '' || $bot_filter_value === '0') {
                $where_clauses[] = "{$this->table_name}.bot_id IS NULL";
            } else {
                $where_clauses[] = "{$this->table_name}.bot_id = %d";
                $params[] = absint($bot_filter_value);
            }
        }

        // User ID Filter: Only apply if user_id is explicitly set
        if (isset($filters['user_id'])) {
            if ($filters['user_id'] === null || $filters['user_id'] === 0) {
                $where_clauses[] = "{$this->table_name}.user_id IS NULL";
            } else {
                $where_clauses[] = "{$this->table_name}.user_id = %d";
                $params[] = absint($filters['user_id']);
            }
        }

        if (!empty($filters['session_id'])) {
            $where_clauses[] = "{$this->table_name}.session_id = %s";
            $params[] = sanitize_text_field($filters['session_id']);
        }
        if (!empty($filters['conversation_uuid'])) {
            $where_clauses[] = "{$this->table_name}.conversation_uuid = %s";
            $params[] = sanitize_key($filters['conversation_uuid']);
        }

        if (!empty($filters['module'])) {
            $module_filter = sanitize_key($filters['module']);
            if ($module_filter === 'chatbot') {
                $where_clauses[] = "({$this->table_name}.module IS NULL OR {$this->table_name}.module = '')";
            } else {
                $where_clauses[] = "{$this->table_name}.module = %s";
                $params[] = $module_filter;
            }
        }

        if (!empty($filters['start_ts'])) {
            $where_clauses[] = "{$this->table_name}.last_message_ts >= %d";
            $params[] = absint($filters['start_ts']);
        }

        if (!empty($filters['end_ts'])) {
            $where_clauses[] = "{$this->table_name}.last_message_ts <= %d";
            $params[] = absint($filters['end_ts']);
        }

        // search filter (OR across user display_name, guest session_id, and messages JSON)
        $search_value = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search_value !== '') {
            $join_sql = " LEFT JOIN {$wpdb->users} AS u ON u.ID = {$this->table_name}.user_id ";
            $select_fields[] = "u.display_name as user_display_name";
            $likeVal = '%' . $wpdb->esc_like($search_value) . '%';
            $where_clauses[] = "( (u.display_name IS NOT NULL AND u.display_name LIKE %s) OR ({$this->table_name}.is_guest = 1 AND {$this->table_name}.session_id LIKE %s) OR {$this->table_name}.messages LIKE %s )";
            $params[] = $likeVal;
            $params[] = $likeVal;
            $params[] = $likeVal;
        } else {
            // user_name filter (joins wp_users)
            if (!empty($filters['user_name'])) {
                $join_sql = " LEFT JOIN {$wpdb->users} AS u ON u.ID = {$this->table_name}.user_id ";
                $select_fields[] = "u.display_name as user_display_name";
                $where_clauses[] = "( (u.display_name IS NOT NULL AND u.display_name LIKE %s) OR ({$this->table_name}.is_guest = 1 AND {$this->table_name}.session_id LIKE %s) )";
                $likeVal = '%' . $wpdb->esc_like($filters['user_name']) . '%';
                $params[] = $likeVal;
                $params[] = $likeVal;
            } else {
                $select_fields[] = "NULL as user_display_name";
            }

            // message_like filter
            if (!empty($filters['message_like'])) {
                $where_clauses[] = "{$this->table_name}.messages LIKE %s";
                $likeVal = '%' . $wpdb->esc_like($filters['message_like']) . '%';
                $params[] = $likeVal;
            }
        }

        // --- ORDER BY Clause ---
        $valid_orderby = ['id', 'bot_id', 'user_id', 'session_id', 'conversation_uuid', 'message_count', 'first_message_ts', 'last_message_ts', 'created_at', 'updated_at'];
        if ($join_sql) {
            $valid_orderby[] = 'user_display_name';
        }

        $orderby_final = 'last_message_ts'; // Changed default order
        if (in_array(strtolower($orderby), $valid_orderby)) {
            if ($orderby === 'user_display_name' && $join_sql) {
                $orderby_final = 'u.display_name';
            } else {
                $orderby_final = $this->table_name . '.' . strtolower($orderby);
            }
        }
        $order_final = in_array(strtoupper($order), ['ASC','DESC']) ? strtoupper($order) : 'DESC';

        // --- LIMIT Clause ---
        $limit_sql = '';
        if ($limit > 0) {
            $limit_sql = $wpdb->prepare('LIMIT %d OFFSET %d', absint($limit), absint($offset));
        }

        $where_sql = implode(' AND ', $where_clauses);
        $select_sql = implode(', ', $select_fields);

        return [
            'select_sql' => $select_sql,
            'where_sql' => $where_sql,
            'params'    => $params,
            'orderby'   => $orderby_final,
            'order'     => $order_final,
            'limit_sql' => $limit_sql,
            'join_sql'  => $join_sql,
        ];
    }

    /**
    * Builds SQL query parts for counting conversation rows based on filters.
    * ADDED: Module and date-range filters (inherited from build_conversation_query_parts).
    *
    * @param array $filters Filters (same as build_conversation_query_parts).
    *
    * @return array {
    *    'count_sql' => string, // The full SQL query for counting
    *    'params'    => array   // Parameters for the query
    * }
    */
    public function build_conversation_count_query_parts(array $filters = []): array
    {
        global $wpdb;
        $query_parts = $this->build_conversation_query_parts($filters, '', '', 0, 0, false);

        $count_sql = "SELECT COUNT(*)
                      FROM {$this->table_name}
                      {$query_parts['join_sql']}
                      WHERE {$query_parts['where_sql']}";

        return [
            'count_sql' => $count_sql,
            'params'    => $query_parts['params'],
        ];
    }

    /**
     * Builds SQL query parts for fetching message rows based on filters.
     * Used specifically for export operations.
     * ADDED: Module and date-range filters.
     *
     * @param array $filters Filters (same as build_conversation_query_parts)
     * @param string $orderby Field to order by
     * @param string $order 'ASC' or 'DESC'
     * @param int $limit Max rows
     * @param int $offset Start row
     *
     * @return array Query parts
     */
    public function build_message_query_parts(array $filters = [], string $orderby = 'id', string $order = 'ASC', int $limit = 100, int $offset = 0): array
    {
        return $this->build_conversation_query_parts($filters, $orderby, $order, $limit, $offset, true);
    }

}

/**
 * Handles logging individual messages to the conversation log table.
 * Creates new conversation rows or updates existing ones.
 * Manages the JSON structure within the 'messages' column.
 * This class now delegates its core logic to namespaced functions.
 */
class ConversationLogger
{
    private $wpdb;
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'aipkit_chat_logs';

        // Ensure AIPKit_IP_Anonymization is loaded as it's used by externalized logic
        if (!class_exists(AIPKit_IP_Anonymization::class)) {
            $ip_anon_path = WPAICG_PLUGIN_DIR . 'classes/security/ip-anonymization.php';
            if (file_exists($ip_anon_path)) {
                require_once $ip_anon_path;
            }
        }
        if (!class_exists(AIPKit_Global_Security_Settings::class)) {
            $security_settings_path = WPAICG_PLUGIN_DIR . 'classes/security/moderation.php';
            if (file_exists($security_settings_path)) {
                require_once $security_settings_path;
            }
        }
    }

    /**
     * Logs a single message by finding the appropriate conversation row
     * and appending the message to its 'messages' JSON array within the structured object.
     * Creates a new conversation row if one doesn't exist.
     *
     * @param array $log_data Associative array containing message details.
     *              Must include: conversation_uuid, message_role, message_content, module.
     *              Must include either user_id OR session_id.
     *              Should include bot_id if applicable to the module (can be null).
     *              May include: bot_message_id, usage (token usage data), ai_provider, ai_model, feedback,
     *                           request_payload, response_data (for image gen),
     *                           openai_response_id, used_previous_response_id.
     * @return array|false ['log_id' => int, 'message_id' => string, 'is_new_session' => bool] on success, false on failure.
     */
    public function log_message(array $log_data)
    {
        // --- 1. Basic Validation ---
        if (empty($log_data['conversation_uuid']) || empty($log_data['message_role']) ||
            !isset($log_data['message_content']) || empty($log_data['module']) ||
            (!isset($log_data['user_id']) && empty($log_data['session_id']))
        ) {
            return false;
        }

        // --- 2. Sanitize Core Identifiers ---
        $bot_id = null;
        if (isset($log_data['bot_id'])) {
            if (is_numeric($log_data['bot_id']) && intval($log_data['bot_id']) > 0) {
                $bot_id = absint($log_data['bot_id']);
            } // null, empty string, '0' will result in $bot_id = null
        }
        $user_id           = isset($log_data['user_id']) && $log_data['user_id'] > 0 ? absint($log_data['user_id']) : null;
        $session_id        = $user_id ? null : sanitize_text_field($log_data['session_id'] ?? '');
        $conversation_uuid = sanitize_key($log_data['conversation_uuid']);
        $module            = sanitize_key($log_data['module']);
        $is_guest          = $user_id ? 0 : 1;
        $original_ip = isset($log_data['ip_address']) ? sanitize_text_field($log_data['ip_address']) : null;
        $global_ip_anonymize = class_exists(AIPKit_Global_Security_Settings::class)
            && AIPKit_Global_Security_Settings::is_ip_anonymization_enabled();
        $ip_anonymize = $global_ip_anonymize
            || (isset($log_data['ip_anonymize']) && in_array($log_data['ip_anonymize'], ['1', 1, true], true));
        $ip_to_store = ($ip_anonymize && class_exists(AIPKit_IP_Anonymization::class))
            ? AIPKit_IP_Anonymization::maybe_anonymize($original_ip, true)
            : $original_ip;
        $user_wp_role = $log_data['user_wp_role'] ?? ($user_id ? implode(', ', wp_get_current_user()->roles) : null);

        // --- 3. Determine Message ID and Timestamp ---
        $current_timestamp = isset($log_data['timestamp']) ? absint($log_data['timestamp']) : time();
        $message_id = isset($log_data['bot_message_id']) && !empty(trim($log_data['bot_message_id']))
                       ? str_replace('.', '', sanitize_key($log_data['bot_message_id']))
                       : (isset($log_data['message_id']) && !empty(trim($log_data['message_id']))
                          ? str_replace('.', '', sanitize_key($log_data['message_id']))
                          : LoggerMethods\generate_message_id_logic());

        // --- 4. Build the New Message Object ---
        $new_message = LoggerMethods\build_message_object_logic($log_data, $message_id, $current_timestamp);

        // --- 5. Find Existing Conversation Row ---
        $where_parts = LoggerMethods\build_where_clauses_logic($conversation_uuid, $module, $bot_id, $user_id, $session_id);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Reason: $this->table_name is safe (from $wpdb->prefix), and $where_parts['where_sql'] contains placeholders for the prepare method.
        $sql = "SELECT id, messages FROM {$this->table_name} WHERE {$where_parts['where_sql']} LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: $sql is constructed with placeholders and safe variables, and is prepared here.
        $existing_log_row = $this->wpdb->get_row($this->wpdb->prepare($sql, $where_parts['params']), ARRAY_A);

        // --- 6. Update or Insert ---
        if ($existing_log_row) {
            $update_result = LoggerMethods\update_existing_log_logic(
                $this->wpdb,
                $this->table_name,
                $existing_log_row,
                $new_message,
                $current_timestamp,
                $ip_to_store,
                $user_wp_role,
                $ip_anonymize
            );
            if (is_array($update_result)) {
                $update_result['is_new_session'] = false; // It's an update to an existing log
            }
            return $update_result;
        } else {
            // insert_new_log_logic already sets 'is_new_session' => true
            return LoggerMethods\insert_new_log_logic(
                $this->wpdb,
                $this->table_name,
                $bot_id,
                $user_id,
                $session_id,
                $conversation_uuid,
                $module,
                $is_guest,
                $new_message,
                $current_timestamp,
                $ip_to_store,
                $user_wp_role,
                $ip_anonymize
            );
        }
    }
}

/**
 * Handles reading conversation history and summaries from the log table.
 * Public methods delegate to the reader functions in this module.
 */
class ConversationReader {

    private $wpdb;
    private $table_name;
    private $query_helper;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'aipkit_chat_logs';
        $this->query_helper = new LogQueryHelper($this->table_name);
    }

    /**
     * Public getter for $wpdb.
     * @return \wpdb
     */
    public function get_wpdb(): \wpdb {
        return $this->wpdb;
    }

    /**
     * Public getter for $table_name.
     * @return string
     */
    public function get_table_name(): string {
        return $this->table_name;
    }

    /**
     * Public getter for $query_helper.
     * @return LogQueryHelper
     */
    public function get_query_helper(): LogQueryHelper {
        return $this->query_helper;
    }

    /**
     * Retrieves the conversation history (array of messages) for a specific conversation thread.
     * Handles the new JSON structure. Includes feedback, usage, openai_response_id, and used_previous_response_id.
     *
     * @param int|null $user_id The user ID (null for guests).
     * @param string|null $session_id The guest UUID (null for logged-in users).
     * @param int $bot_id The bot ID.
     * @param string $conversation_uuid The specific conversation thread UUID.
     * @return array The array of messages [{message_id, role, content, timestamp, provider?, model?, feedback?, usage?, openai_response_id?, used_previous_response_id?}, ...].
     */
    public function get_conversation_thread_history(?int $user_id, ?string $session_id, int $bot_id, string $conversation_uuid): array {
        return ReaderMethods\get_conversation_thread_history_logic($this, $user_id, $session_id, $bot_id, $conversation_uuid);
    }

     /**
      * Retrieves summary data for all distinct conversations for a user/session and bot.
      * Handles the new JSON structure to extract the title.
      *
      * @param int|null $user_id The user ID (null for guests).
      * @param string|null $session_id The guest UUID (null for logged-in users).
      * @param int $bot_id The bot ID.
      * @return array|null An array of conversation summaries or null on error.
      */
     public function get_all_conversation_data(?int $user_id, ?string $session_id, int $bot_id): ?array {
        return ReaderMethods\get_all_conversation_data_logic($this, $user_id, $session_id, $bot_id);
     }

}

/**
 * Handles storing feedback for specific messages within a conversation log.
 */
class FeedbackManager
{
    private $wpdb;
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'aipkit_chat_logs';
    }

    /**
     * Stores or clears feedback for a specific message within a conversation.
     *
     * @param int|null $user_id The user ID (null for guests).
     * @param string|null $session_id The guest UUID (null for logged-in users).
     * @param int $bot_id The bot ID.
     * @param string $conversation_uuid The specific conversation thread UUID.
     * @param string $message_id The ID of the message receiving feedback.
     * @param string $feedback_type 'up', 'down', or 'none' to clear feedback.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function store_feedback_for_message(?int $user_id, ?string $session_id, int $bot_id, string $conversation_uuid, string $message_id, string $feedback_type)
    {
        // Validation
        if (empty($bot_id) || empty($conversation_uuid) || empty($message_id) || !in_array($feedback_type, ['up', 'down', 'none'], true)) {
            return new \WP_Error('invalid_feedback_data', __('Missing required data for feedback.', 'gpt3-ai-content-generator'));
        }
        if (!$user_id && empty($session_id)) {
            return new \WP_Error('missing_identifier', __('User or Session ID is required for feedback.', 'gpt3-ai-content-generator'));
        }

        // Find the conversation log row
        $where_sql = "bot_id = %d AND conversation_uuid = %s";
        $params = [$bot_id, $conversation_uuid];
        if ($user_id) {
            $where_sql .= " AND user_id = %d";
            $params[] = $user_id;
        } else {
            $where_sql .= " AND user_id IS NULL AND session_id = %s AND is_guest = 1";
            $params[] = $session_id;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Reason: $this->table_name is safe (from $wpdb->prefix), and $where_sql contains placeholders.
        $sql = "SELECT id, messages FROM {$this->table_name} WHERE {$where_sql} LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: $this->wpdb->prepare is used below.
        $log_row = $this->wpdb->get_row($this->wpdb->prepare($sql, $params), ARRAY_A);

        if (!$log_row) {
            return new \WP_Error('conversation_not_found', __('Conversation not found.', 'gpt3-ai-content-generator'), ['status' => 404]);
        }

        $messages_json = $log_row['messages'] ?? null;
        $conversation_data = $messages_json ? json_decode($messages_json, true) : null;

        // Check structure
        if (!is_array($conversation_data) || !isset($conversation_data['parent_id']) || !isset($conversation_data['messages']) || !is_array($conversation_data['messages'])) {
            return new \WP_Error('invalid_log_structure', __('Error processing conversation data.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        $messages_array = $conversation_data['messages'];
        $message_found = false;

        // Find the message and add, update, or clear feedback.
        foreach ($messages_array as &$msg) { // Use reference to modify
            if (isset($msg['message_id']) && $msg['message_id'] === $message_id) {
                if ($feedback_type === 'none') {
                    unset($msg['feedback']);
                } else {
                    $msg['feedback'] = sanitize_key($feedback_type);
                }
                $message_found = true;
                break;
            }
        }
        unset($msg); // Unset reference

        if (!$message_found) {
            return new \WP_Error('message_not_found', __('Message not found within the conversation.', 'gpt3-ai-content-generator'), ['status' => 404]);
        }

        // Update the database
        $updated_conversation_data = [
            'parent_id' => $conversation_data['parent_id'],
            'messages' => $messages_array,
            // Note: We don't update last_message_ts for feedback
        ];
        $updated = $this->wpdb->update(
            $this->table_name,
            ['messages' => wp_json_encode($updated_conversation_data, JSON_UNESCAPED_UNICODE)],
            ['id' => $log_row['id']],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return new \WP_Error('db_update_failed', __('Failed to save feedback.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        return true;
    }
}

/**
 * Handles admin-focused log management tasks: fetching for display, counting, pruning, exporting, deleting.
 */
class LogManager
{
    private $wpdb;
    private $table_name;
    private $query_helper;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'aipkit_chat_logs';
        $this->query_helper = new LogQueryHelper($this->table_name);
    }

    private function is_content_writer_placeholder(string $content): bool
    {
        $normalized = strtolower(trim($content));
        if ($normalized === '') {
            return false;
        }
        return (strpos($normalized, 'generate ') === 0) || (strpos($normalized, 'content writer request') === 0);
    }

    private function extract_prompt_from_payload_sent(array $payload): ?string
    {
        $sent = $payload['payload_sent'] ?? null;
        if (!is_array($sent)) {
            return null;
        }
        $messages = $sent['messages'] ?? null;
        if (!is_array($messages)) {
            $messages = $sent['input'] ?? null;
        }
        if (!is_array($messages)) {
            return null;
        }
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (!is_string($content)) {
                continue;
            }
            $content = trim($content);
            if ($content !== '') {
                return $content;
            }
        }
        return null;
    }

    private function find_prompt_field(array $payload, string $content): ?string
    {
        $map = [
            'generate excerpt' => 'custom_excerpt_prompt',
            'generate tags' => 'custom_tags_prompt',
            'generate meta description' => 'custom_meta_prompt',
            'generate focus keyword' => 'custom_keyword_prompt',
            'generate image prompt' => 'image_prompt',
            'generate featured image' => 'featured_image_prompt',
            'generate title' => 'custom_title_prompt',
        ];
        $normalized = strtolower(trim($content));
        if (!isset($map[$normalized])) {
            return null;
        }
        $field = $map[$normalized];
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function find_any_prompt_field(array $payload): ?string
    {
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (preg_match('/_prompt$/i', $key) !== 1) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function resolve_content_writer_preview(array $messages): ?string
    {
        $preview = null;
        $count = count($messages);
        for ($i = 0; $i < $count; $i++) {
            $message = $messages[$i] ?? null;
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (!is_string($content)) {
                continue;
            }
            $content = trim($content);
            if ($content === '') {
                continue;
            }
            $preview = $content;
            if (!$this->is_content_writer_placeholder($content)) {
                continue;
            }

            $replacement = null;
            $next_payload = $messages[$i + 1]['request_payload'] ?? null;
            if (is_array($next_payload)) {
                $replacement = $this->extract_prompt_from_payload_sent($next_payload);
            }
            if (!$replacement && isset($message['request_payload']) && is_array($message['request_payload'])) {
                $replacement = $this->extract_prompt_from_payload_sent($message['request_payload']);
            }
            if (!$replacement && isset($message['request_payload']) && is_array($message['request_payload'])) {
                $replacement = $this->find_prompt_field($message['request_payload'], $content);
            }
            if (!$replacement && isset($message['request_payload']) && is_array($message['request_payload'])) {
                $replacement = $this->find_any_prompt_field($message['request_payload']);
            }
            if ($replacement) {
                $preview = $replacement;
            }
        }
        return $preview;
    }

    /**
     * Retrieves conversation summary rows for the admin log view.
     * Handles the new JSON structure to extract the last message snippet and check for feedback.
     * Calculates total tokens used in the conversation.
     * Includes 'module' in the results.
     * Changed default sort order.
     */
    public function get_logs(array $filters = [], int $limit = 50, int $offset = 0, string $orderby = 'last_message_ts', string $order = 'DESC'): array // Default orderby changed to 'last_message_ts'
    {$query_parts = $this->query_helper->build_conversation_query_parts($filters, $orderby, $order, $limit, $offset, true); // Select messages JSON and module
        $query = "SELECT {$query_parts['select_sql']} FROM {$this->table_name} {$query_parts['join_sql']} WHERE {$query_parts['where_sql']} ORDER BY {$query_parts['orderby']} {$query_parts['order']} {$query_parts['limit_sql']}";
        if (!empty($query_parts['params'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Reason: $this->wpdb->prepare is safe to use here.
            $query = $this->wpdb->prepare($query, $query_parts['params']);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to custom table for log retrieval.
        $results = $this->wpdb->get_results($query, ARRAY_A);

        if ($results) {
            foreach ($results as &$log_row) {
                // Enrich Bot/User Name
                if (!empty($log_row['bot_id'])) {
                    $log_row['bot_name'] = get_the_title($log_row['bot_id']) ?: __('(Deleted Bot)', 'gpt3-ai-content-generator');
                } elseif (empty($log_row['module'])) {
                    $log_row['bot_name'] = __('(No Bot)', 'gpt3-ai-content-generator');
                } else {
                    // Friendly labels for non-bot sources
                    if ($log_row['module'] === 'ai_post_enhancer') {
                        $log_row['bot_name'] = __('Content Assistant', 'gpt3-ai-content-generator');
                    } elseif ($log_row['module'] === 'wp_ai_client') {
                        $log_row['bot_name'] = __('WP AI Client', 'gpt3-ai-content-generator');
                    } else {
                        $log_row['bot_name'] = esc_html(ucfirst(str_replace('_', ' ', $log_row['module'])));
                    }
                }

                if (!isset($log_row['user_display_name'])) {
                    if (!$log_row['is_guest'] && !empty($log_row['user_id'])) {
                        $ud = get_userdata($log_row['user_id']);
                        $log_row['user_display_name'] = $ud ? $ud->display_name : __('(Deleted User)', 'gpt3-ai-content-generator');
                    } elseif ($log_row['is_guest']) {
                        $log_row['user_display_name'] = __('Guest', 'gpt3-ai-content-generator');
                    } else {
                        $log_row['user_display_name'] = __('(Unknown User)', 'gpt3-ai-content-generator');
                    }
                }

                // Extract last message info from JSON
                $conversation_data = json_decode($log_row['messages'] ?? '[]', true);
                $messages_array = null;
                $has_feedback = false;
                $total_conversation_tokens = 0;

                if (is_array($conversation_data) && isset($conversation_data['messages']) && is_array($conversation_data['messages'])) {
                    $messages_array = $conversation_data['messages'];
                } elseif (is_array($conversation_data)) {
                    $messages_array = $conversation_data;
                }

                if (is_array($messages_array)) {
                    $last_message_obj = end($messages_array);
                    $log_row['last_message_role'] = $last_message_obj['role'] ?? '';
                    $log_row['last_message_content'] = $last_message_obj['content'] ?? __('(No messages)', 'gpt3-ai-content-generator');

                    if (($log_row['module'] ?? '') === 'content_writer') {
                        $content_writer_preview = $this->resolve_content_writer_preview($messages_array);
                        if ($content_writer_preview) {
                            $log_row['last_message_role'] = 'user';
                            $log_row['last_message_content'] = $content_writer_preview;
                        }
                    }

                    foreach ($messages_array as $msg) {
                        if (isset($msg['feedback']) && ($msg['feedback'] === 'up' || $msg['feedback'] === 'down')) {
                            $has_feedback = true;
                        }
                        if (isset($msg['usage']['total_tokens']) && is_numeric($msg['usage']['total_tokens'])) {
                            $total_conversation_tokens += (int) $msg['usage']['total_tokens'];
                        } elseif (isset($msg['usage']['totalTokenCount']) && is_numeric($msg['usage']['totalTokenCount'])) {
                            $total_conversation_tokens += (int) $msg['usage']['totalTokenCount'];
                        }
                    }
                } else {
                    $log_row['last_message_role'] = '';
                    $log_row['last_message_content'] = __('(No messages)', 'gpt3-ai-content-generator');
                }

                $log_row['has_feedback'] = $has_feedback;
                $log_row['total_conversation_tokens'] = $total_conversation_tokens;

                unset($log_row['messages']);
            }
            unset($log_row);
        }
        return $results ?: [];
    }

    /**
     * Count how many distinct conversation rows match the given filters.
     * Includes 'module' filter.
     */
    public function count_logs(array $filters = []): int
    {
        $query_parts = $this->query_helper->build_conversation_count_query_parts($filters);
        $query = $query_parts['count_sql'];
        if (!empty($query_parts['params'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Reason: $this->wpdb->prepare is safe to use here.
            $query = $this->wpdb->prepare($query, $query_parts['params']);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to custom table for log counting.
        return (int) $this->wpdb->get_var($query);
    }

    /**
     * Deletes conversation rows older than X days (based on last_message_ts).
     * @return int|false
     */
    public function prune_logs(float $days)
    {
        if (function_exists('WPAICG\\Chat\\Storage\\ProLogMethods\\prune_logs')) {
            return ProLogMethods\prune_logs($this->wpdb, $this->table_name, $days);
        }
        return 0;
    }

    /**
     * Deletes conversation rows matching the provided filters, up to a specified limit.
     * Includes 'module' filter.
     * @return int|false
     */
    public function delete_logs(array $filters = [], int $limit = 500)
    {
        if ($limit <= 0) {
            return 0;
        }
        $query_parts = $this->query_helper->build_conversation_query_parts($filters, 'id', 'ASC', $limit, 0, false);
        $select_ids_query = "SELECT {$this->table_name}.id FROM {$this->table_name} {$query_parts['join_sql']} WHERE {$query_parts['where_sql']} ORDER BY {$query_parts['orderby']} {$query_parts['order']} {$query_parts['limit_sql']}";
        if (!empty($query_parts['params'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Reason: $this->wpdb->prepare is safe to use here.
            $select_ids_query = $this->wpdb->prepare($select_ids_query, $query_parts['params']);
        }
        if (!$select_ids_query) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to custom table for log deletion.
        $log_ids_to_delete = $this->wpdb->get_col($select_ids_query);
        if (empty($log_ids_to_delete)) {
            return 0;
        }
        $ids_placeholder = implode(', ', array_fill(0, count($log_ids_to_delete), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: $this->table_name is safe. $ids_placeholder is an array of %d.
        $delete_query = "DELETE FROM {$this->table_name} WHERE id IN ($ids_placeholder)";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Reason: $this->wpdb->prepare is safe to use here.
        $delete_query_prepared = $this->wpdb->prepare($delete_query, $log_ids_to_delete);
        if (!$delete_query_prepared) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Reason: Direct query to custom table for log deletion.
        return $this->wpdb->query($delete_query_prepared);
    }

    /**
     * Helper function to get raw conversation rows including the messages JSON.
     * Used specifically for the export feature. Includes feedback and usage data.
     * Includes 'module' filter and data.
     */
    public function get_raw_conversations_for_export(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $query_parts = $this->query_helper->build_message_query_parts($filters, 'id', 'ASC', $limit, $offset);

        $query = "SELECT {$query_parts['select_sql']}
                   FROM {$this->table_name}
                   {$query_parts['join_sql']}
                   WHERE {$query_parts['where_sql']}
                   ORDER BY {$query_parts['orderby']} {$query_parts['order']}
                   {$query_parts['limit_sql']}";

        if (!empty($query_parts['params'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Reason: $this->wpdb->prepare is safe to use here.
            $query = $this->wpdb->prepare($query, $query_parts['params']);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to custom table for log retrieval.
        $results = $this->wpdb->get_results($query, ARRAY_A) ?: [];

        if ($results) {
            foreach ($results as &$log_row) {
                if (!empty($log_row['bot_id'])) {
                    $log_row['bot_name'] = get_the_title($log_row['bot_id']) ?: __('(Deleted Bot)', 'gpt3-ai-content-generator');
                } elseif (empty($log_row['module'])) {
                    $log_row['bot_name'] = __('(No Bot)', 'gpt3-ai-content-generator');
                } else {
                    // Friendly labels for non-bot sources
                    if ($log_row['module'] === 'ai_post_enhancer') {
                        $log_row['bot_name'] = __('Content Assistant', 'gpt3-ai-content-generator');
                    } else {
                        $log_row['bot_name'] = esc_html(ucfirst(str_replace('_', ' ', $log_row['module'])));
                    }
                }

                if (!isset($log_row['user_display_name'])) {
                    if (!$log_row['is_guest'] && !empty($log_row['user_id'])) {
                        $ud = get_userdata($log_row['user_id']);
                        $log_row['user_display_name'] = $ud ? $ud->display_name : __('(Deleted User)', 'gpt3-ai-content-generator');
                    } elseif ($log_row['is_guest']) {
                        $log_row['user_display_name'] = __('Guest', 'gpt3-ai-content-generator');
                    } else {
                        $log_row['user_display_name'] = __('(Unknown User)', 'gpt3-ai-content-generator');
                    }
                }

            }
            unset($log_row);
        }
        return $results;
    }

    /**
     * Retrieves single conversation row by its primary ID.
     * Includes enrichment with user/bot names and module. Does NOT decode messages JSON here.
     */
    public function get_log_by_id(int $log_id): ?array
    {
        if (empty($log_id)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Reason: $this->table_name is safe.
        $log_row = $this->wpdb->get_row($this->wpdb->prepare("SELECT id, bot_id, user_id, session_id, conversation_uuid, module, is_guest, message_count, first_message_ts, last_message_ts, ip_address, user_wp_role, created_at, updated_at, messages FROM {$this->table_name} WHERE id = %d", $log_id), ARRAY_A);
        if (!$log_row) {
            return null;
        }
        $conversation = json_decode((string) ($log_row['messages'] ?? ''), true);
        $log_row['ip_anonymized'] = is_array($conversation) && array_key_exists('ip_anonymized', $conversation)
            ? (bool) $conversation['ip_anonymized'] : null;
        unset($log_row['messages']);
        if (!empty($log_row['bot_id'])) {
            $log_row['bot_name'] = get_the_title($log_row['bot_id']) ?: __('(Deleted Bot)', 'gpt3-ai-content-generator');
        } elseif (empty($log_row['module'])) {
            $log_row['bot_name'] = __('(No Bot)', 'gpt3-ai-content-generator');
        } else {
            // Friendly labels for non-bot sources
            if ($log_row['module'] === 'ai_post_enhancer') {
                $log_row['bot_name'] = __('Content Assistant', 'gpt3-ai-content-generator');
            } else {
                $log_row['bot_name'] = esc_html(ucfirst(str_replace('_', ' ', $log_row['module'])));
            }
        }

        if (!$log_row['is_guest'] && !empty($log_row['user_id'])) {
            $ud = get_userdata($log_row['user_id']);
            $log_row['user_display_name'] = $ud ? $ud->display_name : __('(Deleted User)', 'gpt3-ai-content-generator');
        } elseif ($log_row['is_guest']) {
            $log_row['user_display_name'] = __('Guest', 'gpt3-ai-content-generator');
            if (!empty($log_row['session_id'])) {
                $log_row['user_display_name'] .= ' (' . substr($log_row['session_id'], 0, 8) . '...)';
            }
        } else {
            $log_row['user_display_name'] = __('(Unknown User)', 'gpt3-ai-content-generator');
        }
        return $log_row;
    }

    /**
     * Deletes a single conversation thread based on provided identifiers.
     *
     * @param int|null $user_id The user ID (null for guests).
     * @param string|null $session_id The guest session ID (null for users).
     * @param int|null $bot_id The bot ID (null if not associated with a specific bot).
     * @param string $conversation_uuid The conversation UUID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_single_conversation(?int $user_id, ?string $session_id, ?int $bot_id, string $conversation_uuid)
    {
        // Basic validation
        if (empty($conversation_uuid)) {
            return new WP_Error('invalid_data', __('Conversation UUID is required for deletion.', 'gpt3-ai-content-generator'));
        }
        if (!$user_id && empty($session_id)) {
            return new WP_Error('invalid_data', __('User ID or Session ID is required for deletion.', 'gpt3-ai-content-generator'));
        }

        $where_clauses = ["conversation_uuid = %s"];
        $params = [$conversation_uuid];

        if ($bot_id !== null) {
            $where_clauses[] = "bot_id = %d";
            $params[] = $bot_id;
        } else {
            $where_clauses[] = "bot_id IS NULL";
        }

        if ($user_id) {
            $where_clauses[] = "user_id = %d";
            $params[] = $user_id;
        } else {
            $where_clauses[] = "(user_id IS NULL AND session_id = %s AND is_guest = 1)";
            $params[] = $session_id;
        }

        $where_sql = implode(" AND ", $where_clauses);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Reason: $this->table_name is safe. $where_sql contains placeholders for the prepare method.
        $query = $this->wpdb->prepare("DELETE FROM {$this->table_name} WHERE {$where_sql}", $params);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to custom table for log deletion.
        $deleted_rows = $this->wpdb->query($query);

        if ($deleted_rows === false) {
            return new WP_Error('db_delete_failed', __('Failed to delete conversation log.', 'gpt3-ai-content-generator'));
        }

        if ($deleted_rows === 0) {
            // Not necessarily an error, might have been deleted already or IDs didn't match
            return true; // Return true as the state is now "deleted"
        }

        return true;
    }
}

/**
 * Manages the WP-Cron job for automatic log pruning.
 */
class LogCronManager
{
    public const HOOK_NAME = 'aipkit_prune_logs_cron';

    /**
     * Schedules the daily pruning event if it's not already scheduled.
     */
    public static function schedule_event()
    {
        if (function_exists('WPAICG\\Chat\\Storage\\ProLogMethods\\schedule_event')) {
            ProLogMethods\schedule_event();
        } else {
            self::unschedule_event();
        }
    }

    /**
     * Unschedules the pruning event.
     */
    public static function unschedule_event()
    {
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
        // Also clear any other potential schedules for the same hook
        wp_clear_scheduled_hook(self::HOOK_NAME);
    }

    /**
     * The main callback function for the cron job.
     * Reads settings and triggers the pruning process.
     */
    public static function run_pruning()
    {
        if (function_exists('WPAICG\\Chat\\Storage\\ProLogMethods\\run_pruning')) {
            ProLogMethods\run_pruning();
        } else {
            self::unschedule_event();
        }
    }
}

/**
 * Facade class for interacting with chat conversation logs.
 * Delegates operations to specialized classes: ConversationLogger, ConversationReader, LogManager, FeedbackManager.
 */
class LogStorage {

    private $logger;
    private $reader;
    private $manager;
    private $feedback_manager;

    public function __construct() {
        // Instantiate the specialized classes
        $this->logger = new ConversationLogger();
        $this->reader = new ConversationReader();
        $this->manager = new LogManager();
        $this->feedback_manager = new FeedbackManager();
    }

    /**
     * Logs a single message. Delegates to ConversationLogger.
     * @return mixed[]|false
     */
    public function log_message(array $log_data) {
        return $this->logger->log_message($log_data);
    }

    /**
     * Retrieves the conversation history. Delegates to ConversationReader.
     */
    public function get_conversation_thread_history(?int $user_id, ?string $session_id, int $bot_id, string $conversation_uuid): array {
        return $this->reader->get_conversation_thread_history($user_id, $session_id, $bot_id, $conversation_uuid);
    }

    /**
     * Retrieves conversation summaries for the sidebar. Delegates to ConversationReader.
     */
     public function get_all_conversation_data(?int $user_id, ?string $session_id, int $bot_id): ?array {
         return $this->reader->get_all_conversation_data($user_id, $session_id, $bot_id);
     }

    /**
     * Retrieves conversation summaries for the admin log view. Delegates to LogManager.
     */
    public function get_logs(array $filters = [], int $limit = 50, int $offset = 0, string $orderby = 'last_message_ts', string $order = 'DESC'): array {
        return $this->manager->get_logs($filters, $limit, $offset, $orderby, $order);
    }

    /**
     * Counts conversation rows matching filters. Delegates to LogManager.
     */
    public function count_logs(array $filters = []): int {
        return $this->manager->count_logs($filters);
    }

    /**
     * Deletes conversation rows older than X days. Delegates to LogManager.
     * @return int|false
     */
    public function prune_logs(float $days) {
        return $this->manager->prune_logs($days);
    }

    /**
     * Deletes conversation rows matching filters. Delegates to LogManager.
     * @return int|false
     */
    public function delete_logs(array $filters = [], int $limit = 500) {
        return $this->manager->delete_logs($filters, $limit);
    }

    /**
     * NEW: Deletes a single conversation thread. Delegates to LogManager.
     * @return bool|\WP_Error
     */
    public function delete_single_conversation(?int $user_id, ?string $session_id, ?int $bot_id, string $conversation_uuid) {
         return $this->manager->delete_single_conversation($user_id, $session_id, $bot_id, $conversation_uuid);
    }

    /**
     * Gets raw conversation data for export. Delegates to LogManager.
     */
    public function get_raw_conversations_for_export(array $filters = [], int $limit = 100, int $offset = 0): array {
        return $this->manager->get_raw_conversations_for_export($filters, $limit, $offset);
    }

    /**
     * Stores feedback for a specific message. Delegates to FeedbackManager.
     * Note: This method wasn't in the original LogStorage but makes sense here for the facade.
     * @return bool|\WP_Error
     */
    public function store_feedback(?int $user_id, ?string $session_id, int $bot_id, string $conversation_uuid, string $message_id, string $feedback_type) {
        return $this->feedback_manager->store_feedback_for_message($user_id, $session_id, $bot_id, $conversation_uuid, $message_id, $feedback_type);
    }

    // --- Potentially keep methods that don't fit neatly elsewhere or are simple helpers ---

    /**
     * Retrieves single conversation row by its primary ID. Delegates to LogManager.
     * Kept here for completeness, though less commonly needed externally.
     */
    public function get_log_by_id(int $log_id): ?array {
        return $this->manager->get_log_by_id($log_id);
    }
}

}

namespace WPAICG\Chat\Utils {

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Configuration constants and utilities for log management.
 *
 * Centralizes configuration options to reduce code duplication
 * and make maintenance easier.
 */
class LogConfig
{
    /**
     * Valid retention period options in days.
     * Used by both frontend form and backend validation.
     *
     * @return array Array of period values => labels
     */
    public static function get_retention_periods(): array
    {
        return [
            1 => __('1 day', 'gpt3-ai-content-generator'),
            3 => __('3 days', 'gpt3-ai-content-generator'),
            7 => __('7 days', 'gpt3-ai-content-generator'),
            15 => __('15 days', 'gpt3-ai-content-generator'),
            30 => __('30 days', 'gpt3-ai-content-generator'),
            60 => __('60 days', 'gpt3-ai-content-generator'),
            90 => __('90 days', 'gpt3-ai-content-generator'),
            180 => __('6 months', 'gpt3-ai-content-generator'),
            365 => __('1 year', 'gpt3-ai-content-generator')
        ];
    }

    /**
     * Get valid retention period values only.
     *
     * @return array Array of valid period values
     */
    public static function get_valid_periods(): array
    {
        return array_keys(self::get_retention_periods());
    }

    /**
     * Validates if a retention period is valid.
     *
     * @param mixed $period The period to validate
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_period($period): bool
    {
        if (!is_numeric($period)) {
            return false;
        }

        $numericPeriod = (float) $period;
        if ($numericPeriod < 1 || $numericPeriod > 365) {
            return false;
        }

        return true;
    }

    /**
     * Get default log settings.
     *
     * @return array Default settings array
     */
    public static function get_default_settings(): array
    {
        return [
            'enable_pruning' => false,
            'retention_period_days' => 90
        ];
    }

    /**
     * Get sanitized log settings from database.
     *
     * @return array Sanitized settings with defaults
     */
    public static function get_log_settings(): array
    {
        $settings = get_option('aipkit_log_settings', self::get_default_settings());

        // Ensure settings have required keys with proper types
        return [
            'enable_pruning' => (bool)($settings['enable_pruning'] ?? false),
            'retention_period_days' => (float)($settings['retention_period_days'] ?? 90)
        ];
    }
}

}
