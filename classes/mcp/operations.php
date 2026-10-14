<?php
/** Post operations: WordPress APIs as the assistant grant's real user. Registry declares them. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Operations
{
    public static $mutation_started = false;

    public static function error(string $code): array
    {
        return ['state' => 'failed', 'actions_used' => 0, 'error' => ['code' => $code, 'message' => 'The website could not complete this request.']];
    }

    private static function ok(array $result): array
    {
        return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $result];
    }

    public static function version(\WP_Post $post): string
    {
        return $post->post_modified_gmt . '|' . substr(hash('sha256', $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt . "\0" . $post->post_status), 0, 16);
    }

    private static function item(\WP_Post $post): array
    {
        return ['id' => (int) $post->ID, 'type' => 'post', 'title' => $post->post_title,
            'status' => $post->post_status, 'version' => self::version($post),
            'link' => get_permalink($post), 'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit')];
    }

    /**
     * Runs one registered operation as the acting user. The safety level decides whether it runs now or returns its
     * preview for the owner's approval; the user's capabilities are checked inside each handler.
     */
    public static function run(string $tool, object $args, array $grant): array
    {
        self::$mutation_started = false;
        $operation = Registry::get($tool);
        if ($operation === null || !Registry::allowed($tool, $grant, $args) || !current_user_can('read')) { return self::error('permission_denied'); }
        if (Registry::needs_approval($tool, $grant, $args)) {
            return empty($operation['prepare']) ? self::error('permission_denied') : call_user_func($operation['prepare'], $args);
        }
        return $tool === 'get_site_overview' ? self::site_overview($args, $grant) : call_user_func($operation['run'], $args);
    }

    public static function site_overview(object $args, array $grant = []): array
    {
        return self::ok(['title' => get_bloginfo('name'), 'address' => home_url(),
            'wordpress_version' => get_bloginfo('version'), 'language' => get_bloginfo('language'),
            'safety_level' => Registry::level_label((string) ($grant['access_level'] ?? '')),
            'allowed_tools' => array_values(array_filter(Registry::available(), static function ($name) use ($grant) {
                return Registry::allowed($name, $grant);
            }))]);
    }

    public static function search_content(object $args): array
    {
        if (isset($args->post_type) && $args->post_type !== 'post') { return self::error('invalid_input'); }
        if (isset($args->query) && (!is_string($args->query) || mb_strlen($args->query) > 200)) { return self::error('invalid_input'); }
        $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
        if (isset($args->status) && $args->status !== 'any' && !in_array($args->status, $statuses, true)) { return self::error('invalid_input'); }
        foreach (['page' => 1000, 'per_page' => 50] as $key => $max) {
            if (isset($args->$key) && (!is_int($args->$key) || $args->$key < 1 || $args->$key > $max)) { return self::error('invalid_input'); }
        }
        $page = $args->page ?? 1;
        $query = new \WP_Query(['post_type' => 'post', 'post_status' => !isset($args->status) || $args->status === 'any' ? $statuses : $args->status,
            's' => $args->query ?? '', 'posts_per_page' => $args->per_page ?? 20, 'paged' => $page,
            'perm' => 'readable', 'orderby' => 'modified', 'order' => 'DESC']);
        $items = [];
        foreach ($query->posts as $post) {
            if (current_user_can('read_post', $post->ID)) { $items[] = self::item($post); }
        }
        // Do not leak inaccessible counts from WordPress's broader SQL result.
        return self::ok(['items' => $items, 'page' => $page, 'has_more' => $page < (int) $query->max_num_pages]);
    }

    public static function create_draft(object $args): array
    {
        if (!current_user_can('edit_posts')) { return self::error('permission_denied'); }
        if (isset($args->post_type) && $args->post_type !== 'post') { return self::error('invalid_input'); }
        if (!is_string($args->title ?? null) || trim($args->title) === '' || mb_strlen($args->title) > 300
            || !is_string($args->content ?? '') || strlen($args->content ?? '') > 200000
            || !is_string($args->excerpt ?? '') || mb_strlen($args->excerpt ?? '') > 2000
            || !is_string($args->slug ?? '')) { return self::error('invalid_input'); }
        // v1 creates classic HTML only. It never executes supplied shortcodes,
        // dynamic/reusable blocks or builder instructions.
        if (strpos($args->content ?? '', '<!--') !== false || preg_match('/\[[A-Za-z][A-Za-z0-9_-]*(?:\s|\/|\])/', $args->content ?? '')) {
            return self::error('unsupported_content');
        }
        $html = $args->content ?? '';
        $post = ['post_type' => 'post', 'post_status' => 'draft', 'post_author' => get_current_user_id(),
            'post_title' => wp_strip_all_tags($args->title), 'post_content' => wp_kses_post($html),
            'post_excerpt' => sanitize_textarea_field($args->excerpt ?? ''), 'post_name' => sanitize_title($args->slug ?? '')];
        self::$mutation_started = true;
        $id = wp_insert_post(wp_slash($post), true);
        if (is_wp_error($id)) { return ['state' => 'unknown', 'actions_used' => 0]; }
        $saved = get_post($id);
        return $saved instanceof \WP_Post ? self::ok(self::item($saved)) : ['state' => 'unknown', 'actions_used' => 0];
    }

    public static function get_content(object $args): array
    {
        if (!is_int($args->id ?? null) || $args->id < 1) { return self::error('invalid_input'); }
        $post = get_post($args->id);
        if (!$post || $post->post_type !== 'post' || !current_user_can('read_post', $post->ID)) { return self::error('permission_denied'); }
        if (!is_int($args->offset ?? 0) || ($args->offset ?? 0) < 0) { return self::error('invalid_input'); }
        $offset = $args->offset ?? 0;
        $content = mb_substr($post->post_content, $offset, 20000);
        return self::ok(self::item($post) + ['content' => $content, 'offset' => $offset,
            'next_offset' => $offset + mb_strlen($content) < mb_strlen($post->post_content) ? $offset + mb_strlen($content) : null]);
    }

    public static function publish_preview(object $args): array
    {
        if (!is_int($args->id ?? null) || !is_string($args->version ?? null)) { return self::error('invalid_input'); }
        $post = get_post($args->id);
        if (!$post || $post->post_type !== 'post' || !current_user_can('edit_post', $post->ID)
            || !current_user_can('publish_posts')) { return self::error('permission_denied'); }
        if (!hash_equals(self::version($post), $args->version)) { return ['state' => 'conflict', 'actions_used' => 0]; }
        if (!in_array($post->post_status, ['draft', 'pending'], true)) { return self::error('invalid_status'); }
        return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => self::item($post)
            + ['preview_url' => get_preview_post_link($post)]];
    }

    /**
     * Publishes a draft: after the owner's approval (the nonce-protected confirmation handler), or directly for a
     * Trusted connection. Either way the version, status and the current user's capabilities are checked again here.
     */
    public static function publish(object $args): array
    {
        global $wpdb;
        self::$mutation_started = false;
        if (!is_int($args->id ?? null)) { return self::error('invalid_input'); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction and post-row locking require actual database state; WordPress owns the table and post IDs are prepared.
        if ($wpdb->query('START TRANSACTION') === false) { return self::error('site_unavailable'); }
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and post-row locking require actual database state; WordPress owns the table and post IDs are prepared.
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $args->id));
            if (!$row) { return self::error('permission_denied'); }
            // Bypass stale object caches while holding the actual post row. Normal
            // WordPress editor saves serialize with this lock, not the MCP lock.
            $post = new \WP_Post($row);
            if ($post->post_type !== 'post' || !current_user_can('edit_post', $post->ID) || !current_user_can('publish_posts')) { return self::error('permission_denied'); }
            if (!is_string($args->version ?? null) || !hash_equals(self::version($post), $args->version)) { return ['state' => 'conflict', 'actions_used' => 0]; }
            if (!in_array($post->post_status, ['draft', 'pending'], true)) { return self::error('invalid_status'); }
            clean_post_cache($post->ID);
            self::$mutation_started = true;
            // Drafts may have no slug, or share one with another draft. The
            // normal save path generates a unique permalink when publishing.
            $published = wp_update_post(['ID' => $post->ID, 'post_status' => 'publish'], true);
            if (is_wp_error($published) || !$published) { return ['state' => 'unknown', 'actions_used' => 0]; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and post-row locking require actual database state; WordPress owns the table and post IDs are prepared.
            $saved = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post->ID));
            if (!$saved || $saved->post_status !== 'publish') { return ['state' => 'unknown', 'actions_used' => 0]; }
            foreach (['post_title', 'post_content', 'post_excerpt', 'post_type', 'post_author'] as $field) {
                if ((string) $saved->$field !== (string) $post->$field) { return ['state' => 'unknown', 'actions_used' => 0]; }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction and post-row locking require actual database state; WordPress owns the table and post IDs are prepared.
            if ($wpdb->query('COMMIT') === false) { return ['state' => 'unknown', 'actions_used' => 0]; }
            return self::ok(self::item(new \WP_Post($saved)));
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction and post-row locking require actual database state; WordPress owns the table and post IDs are prepared.
            $wpdb->query('ROLLBACK');
            clean_post_cache($args->id);
        }
    }
}
