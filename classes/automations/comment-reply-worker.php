<?php

namespace WPAICG\AutoGPT\Cron\EventProcessor\Processor\CommentReply;

use WP_Error;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;
use WPAICG\Chat\Storage\LogStorage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the final prompt for generating a comment reply.
 *
 * @param string $prompt_template The prompt template from task config.
 * @param \WP_Comment $comment The original comment object.
 * @param \WP_Post $post The post the comment belongs to.
 * @return string The final, processed prompt.
 */
function build_reply_prompt_logic(string $prompt_template, \WP_Comment $comment, \WP_Post $post): string
{
    $placeholders = [
        '{comment_author}' => $comment->comment_author,
        '{comment_content}' => $comment->comment_content,
        '{post_title}' => $post->post_title,
    ];

    return str_replace(array_keys($placeholders), array_values($placeholders), $prompt_template);
}

/**
 * Inserts the AI-generated reply as a new comment.
 *
 * @param string $reply_content The AI-generated text for the reply.
 * @param \WP_Comment $original_comment The original comment object to reply to.
 * @param array $task_config The configuration of the task.
 * @return int|WP_Error The new comment ID or a WP_Error on failure.
 */
function insert_comment_reply_logic(string $reply_content, \WP_Comment $original_comment, array $task_config)
{
    // Check if the site owner should be the author of the reply
    $reply_author = get_user_by('id', $original_comment->post_author);
    $user_id = $reply_author ? $reply_author->ID : 1; // Default to admin if post author not found
    $user_data = get_userdata($user_id);

    $comment_data = [
        'comment_post_ID'      => $original_comment->comment_post_ID,
        'comment_author'       => $user_data->display_name,
        'comment_author_email' => $user_data->user_email,
        'comment_author_url'   => $user_data->user_url,
        'comment_content'      => $reply_content,
        'comment_parent'       => $original_comment->comment_ID,
        'user_id'              => $user_id,
        'comment_date'         => current_time('mysql'),
        'comment_date_gmt'     => current_time('mysql', 1),
        'comment_approved'     => ($task_config['reply_action'] ?? 'approve') === 'approve' ? 1 : 0,
    ];

    $new_comment_id = wp_insert_comment($comment_data);

    if (is_wp_error($new_comment_id)) {
        return $new_comment_id;
    }
    if ($new_comment_id === 0) {
        return new WP_Error('comment_insert_failed', 'Could not insert comment into the database.');
    }

    // Add meta to identify this as an automated reply from a specific task
    add_comment_meta($new_comment_id, '_aipkit_automated_reply', $task_config['task_id'] ?? 0, true);

    return $new_comment_id;
}

/**
 * Processes a single "community_reply_comments" queue item.
 *
 * @param array $item The queue item from the database.
 * @param array $item_config The decoded item_config from the queue item.
 * @return array ['status' => 'success'|'error', 'message' => '...']
 */
function process_comment_reply_item_logic(array $item, array $item_config): array
{
    $comment_id = absint($item['target_identifier']);
    $original_comment = get_comment($comment_id);

    if (!$original_comment) {
        return ['status' => 'error', 'message' => "Original comment #{$comment_id} not found."];
    }
    $post = get_post($original_comment->comment_post_ID);
    if (!$post) {
        return ['status' => 'error', 'message' => "Parent post for comment #{$comment_id} not found."];
    }

    // Keyword filtering
    $include_keywords_str = $item_config['include_keywords'] ?? '';
    $exclude_keywords_str = $item_config['exclude_keywords'] ?? '';
    if (!empty($include_keywords_str) || !empty($exclude_keywords_str)) {
        $comment_content_lower = strtolower($original_comment->comment_content);
        if (!empty($exclude_keywords_str)) {
            $exclude_keywords = array_map('trim', explode(',', strtolower($exclude_keywords_str)));
            foreach ($exclude_keywords as $keyword) {
                if (strpos($comment_content_lower, $keyword) !== false) {
                    return ['status' => 'success', 'message' => "Skipped: Comment #{$comment_id} contained exclude keyword '{$keyword}'."];
                }
            }
        }
        if (!empty($include_keywords_str)) {
            $include_keywords = array_map('trim', explode(',', strtolower($include_keywords_str)));
            $found_include = false;
            foreach ($include_keywords as $keyword) {
                if (strpos($comment_content_lower, $keyword) !== false) {
                    $found_include = true;
                    break;
                }
            }
            if (!$found_include) {
                return ['status' => 'success', 'message' => "Skipped: Comment #{$comment_id} did not contain any include keywords."];
            }
        }
    }

    // Build Prompt
    $prompt_template = $item_config['custom_content_prompt'] ?? 'Reply to this comment: {comment_content}';
    $final_prompt = build_reply_prompt_logic($prompt_template, $original_comment, $post);

    // Call AI
    $ai_caller = new AIPKit_AI_Caller();
    $ai_params_override = ['temperature' => floatval($item_config['ai_temperature'] ?? 1), 'max_completion_tokens' => intval($item_config['content_max_tokens'] ?? 4000)];
    if (($item_config['ai_provider'] ?? '') === 'OpenAI') {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
            (string) ($item_config['ai_model'] ?? ''),
            $item_config['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($item_config['ai_provider'] ?? '') === 'AIPufferCloud') {
        $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) ($item_config['ai_model'] ?? ''), $item_config['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif (($item_config['ai_provider'] ?? '') === 'OpenRouter') {
        $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
            (string) ($item_config['ai_model'] ?? ''),
            $item_config['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_override['reasoning'] = ['effort' => $reasoning_effort];
        }
    }
    $system_instruction = 'You are a helpful community manager replying to comments on a blog.';
    $ai_result = $ai_caller->make_standard_call($item_config['ai_provider'], $item_config['ai_model'], [['role' => 'user', 'content' => $final_prompt]], $ai_params_override, $system_instruction);

    if (is_wp_error($ai_result)) {
        return ['status' => 'error', 'message' => 'AI call failed: ' . $ai_result->get_error_message()];
    }
    $reply_content = $ai_result['content'] ?? '';
    if (empty($reply_content)) {
        return ['status' => 'error', 'message' => 'AI returned an empty reply.'];
    }

    // Insert Reply
    $reply_result = insert_comment_reply_logic($reply_content, $original_comment, array_merge($item_config, ['task_id' => $item['task_id']]));
    if (is_wp_error($reply_result)) {
        return ['status' => 'error', 'message' => 'Failed to insert comment reply: ' . $reply_result->get_error_message()];
    }
    $new_comment_id = $reply_result;

    // Log the interaction
    $log_storage = new LogStorage();
    $log_data = [
        'bot_id' => null,
        'user_id' => $original_comment->user_id ?: null,
        'session_id' => null,
        'conversation_uuid' => 'comment-reply-' . $new_comment_id,
        'module' => 'community_reply_comments',
        'is_guest' => empty($original_comment->user_id),
        'role' => 'system',
        'ip_address' => $original_comment->comment_author_IP,
        'message_role' => 'bot',
        'message_content' => "Automated reply to comment #{$comment_id}.",
        'timestamp' => time(),
        'ai_provider' => $item_config['ai_provider'],
        'ai_model' => $item_config['ai_model'],
        'usage' => $ai_result['usage'] ?? null,
        'request_payload' => ['item_config' => $item_config, 'prompt' => $final_prompt],
        'response_data' => ['original_comment_id' => $comment_id, 'reply_comment_id' => $new_comment_id, 'reply_content' => $reply_content]
    ];
    $log_storage->log_message($log_data);

    return ['status' => 'success', 'message' => "Replied to comment #{$comment_id} with new comment #{$new_comment_id}."];
}
