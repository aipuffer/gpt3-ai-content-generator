<?php
/** Site and post operations: WordPress APIs as the assistant grant's real user. Registry declares them. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Operations
{
    public static $mutation_started = false;
    /** Active authenticated journal row, scoped by Executor and cleared when that call ends. */
    public static $operation_id = '';

    public static function error(string $code, string $message = 'The website could not complete this request.'): array
    {
        return ['state' => 'failed', 'actions_used' => 0, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function ok(array $result): array
    {
        return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $result];
    }

    public static function version(\WP_Post $post): string
    {
        return self::content_version($post, self::content_terms($post));
    }

    private static function content_version(\WP_Post $post, array $terms): string
    {
        return $post->post_modified_gmt . '|' . substr(hash('sha256', $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt . "\0" . $post->post_status . "\0" . $post->post_name . "\0" . $post->post_date_gmt . "\0" . wp_json_encode($terms) . "\0" . (int) get_post_thumbnail_id($post->ID)), 0, 16);
    }

    private static function item(\WP_Post $post): array
    {
        $terms = self::content_terms($post);
        return ['id' => (int) $post->ID, 'type' => $post->post_type, 'title' => $post->post_title,
            'status' => $post->post_status, 'version' => self::content_version($post, $terms),
            'slug' => $post->post_name, 'excerpt' => $post->post_excerpt, 'publication_date_gmt' => $post->post_date_gmt,
            'categories' => $terms['categories'], 'tags' => $terms['tags'], 'featured_media' => (int) get_post_thumbnail_id($post->ID),
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
        $theme = wp_get_theme();
        $parent = $theme->parent();
        $user = wp_get_current_user();
        // Describe WordPress permissions, not a promise that MCP exposes every action those permissions allow.
        $capabilities = [];
        foreach (['read', 'edit_posts', 'publish_posts', 'delete_posts', 'edit_pages', 'publish_pages', 'delete_pages',
            'upload_files', 'manage_categories', 'manage_options', 'edit_theme_options', 'activate_plugins',
            'manage_woocommerce', 'edit_products', 'publish_products', 'delete_products'] as $capability) {
            $capabilities[$capability] = current_user_can($capability);
        }
        return self::ok(['title' => get_bloginfo('name'), 'address' => home_url(),
            'wordpress_version' => get_bloginfo('version'), 'language' => get_bloginfo('language'),
            'php_version' => PHP_VERSION, 'plugin_version' => defined('WPAICG_VERSION') ? WPAICG_VERSION : '',
            'active_theme' => ['name' => sanitize_text_field($theme->get('Name')), 'stylesheet' => $theme->get_stylesheet(),
                'version' => sanitize_text_field($theme->get('Version')), 'is_block_theme' => $theme->is_block_theme(),
                'parent' => $parent ? ['name' => sanitize_text_field($parent->get('Name')), 'stylesheet' => $parent->get_stylesheet(),
                    'version' => sanitize_text_field($parent->get('Version'))] : null],
            'plugins' => self::site_plugins(),
            'connected_user' => ['login' => $user->user_login, 'roles' => array_values($user->roles), 'capabilities' => $capabilities],
            'features' => ['abilities_api' => Engines::abilities_available(), 'rest_api' => true],
            'safety_level' => Registry::level_label((string) ($grant['access_level'] ?? '')),
            'unattended_changes' => ($grant['access_level'] ?? '') === 'trusted' && ($grant['unattended'] ?? false) === true,
            'allowed_tools' => array_values(array_filter(Registry::available(), static function ($name) use ($grant) {
                return Registry::allowed($name, $grant);
            }))]);
    }

    /** Installed metadata only: no settings, license keys, file contents or absolute server paths. */
    private static function site_plugins(): array
    {
        // Signed REST and admin-ajax calls do not necessarily load the WordPress admin plugin helpers.
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $plugins = [];
        foreach (['standard' => get_plugins(), 'must_use' => get_mu_plugins()] as $type => $inventory) {
            foreach ($inventory as $file => $metadata) {
                $plugins[] = ['file' => $file, 'name' => sanitize_text_field($metadata['Name']),
                    'version' => sanitize_text_field($metadata['Version']), 'type' => $type,
                    'active' => $type === 'must_use' || is_plugin_active($file),
                    'network_active' => $type === 'standard' && is_plugin_active_for_network($file)];
            }
        }
        usort($plugins, static function ($left, $right) { return strcmp($left['type'] . ':' . $left['file'], $right['type'] . ':' . $right['file']); });
        return $plugins;
    }

    public static function search_content(object $args): array
    {
        if (!in_array($args->post_type ?? 'post', ['post', 'page'], true)) { return self::error('invalid_input'); }
        if (isset($args->query) && (!is_string($args->query) || mb_strlen($args->query) > 200)) { return self::error('invalid_input'); }
        $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
        if (isset($args->status) && $args->status !== 'any' && !in_array($args->status, $statuses, true)) { return self::error('invalid_input'); }
        foreach (['page' => 1000, 'per_page' => 50] as $key => $max) {
            if (isset($args->$key) && (!is_int($args->$key) || $args->$key < 1 || $args->$key > $max)) { return self::error('invalid_input'); }
        }
        $page = $args->page ?? 1;
        $query = new \WP_Query(['post_type' => $args->post_type ?? 'post', 'post_status' => !isset($args->status) || $args->status === 'any' ? $statuses : $args->status,
            's' => $args->query ?? '', 'posts_per_page' => $args->per_page ?? 20, 'paged' => $page,
            'perm' => 'readable', 'orderby' => 'modified', 'order' => 'DESC']);
        $items = [];
        foreach ($query->posts as $post) {
            if (current_user_can('read_post', $post->ID)) { $items[] = self::item($post); }
        }
        // Do not leak inaccessible counts from WordPress's broader SQL result.
        return self::ok(['items' => $items, 'page' => $page, 'has_more' => $page < (int) $query->max_num_pages]);
    }

    /** Validated native fields only; term creation uses the existing category/tag REST routes. */
    private static function content_fields(object $args, string $type, bool $creating = false)
    {
        $fields = [];
        foreach (['title' => ['post_title', 300], 'content' => ['post_content', 200000],
            'excerpt' => ['post_excerpt', 2000], 'slug' => ['post_name', 200]] as $name => $spec) {
            if (!property_exists($args, $name)) { continue; }
            $value = $args->$name;
            if (!is_string($value) || ($name === 'content' ? strlen($value) : mb_strlen($value)) > $spec[1]
                || ($name === 'title' && trim(wp_strip_all_tags($value)) === '')) { return new \WP_Error('invalid_input'); }
            if ($name === 'content' && (strpos($value, '<!--') !== false
                || preg_match('/\[[A-Za-z][A-Za-z0-9_-]*(?:\s|\/|\])/', $value))) { return new \WP_Error('unsupported_content'); }
            $fields[$spec[0]] = $name === 'content' ? wp_kses_post($value)
                : ($name === 'slug' ? sanitize_title($value) : ($name === 'title' ? wp_strip_all_tags($value) : sanitize_textarea_field($value)));
        }
        if ($creating && !isset($fields['post_title'])) { return new \WP_Error('invalid_input'); }
        foreach (['categories' => 'category', 'tags' => 'post_tag'] as $name => $taxonomy) {
            if (!property_exists($args, $name)) { continue; }
            $ids = $args->$name;
            if (!is_array($ids) || count($ids) > 100 || !is_object_in_taxonomy($type, $taxonomy)) { return new \WP_Error('invalid_taxonomy'); }
            $tax = get_taxonomy($taxonomy);
            if (!$tax || !current_user_can($tax->cap->assign_terms)) { return new \WP_Error('permission_denied'); }
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1 || !term_exists($id, $taxonomy)) { return new \WP_Error('invalid_term'); }
            }
            $fields['tax_input'][$taxonomy] = array_values(array_unique($ids));
        }
        if (property_exists($args, 'featured_media')) {
            $id = $args->featured_media;
            if (!is_int($id) || $id < 0 || ($id > 0 && (!wp_attachment_is_image($id) || !current_user_can('read_post', $id)))) { return new \WP_Error('invalid_media'); }
            $fields['meta_input']['_thumbnail_id'] = $id;
        }
        return $fields;
    }

    private static function content_terms(\WP_Post $post): array
    {
        $result = ['categories' => [], 'tags' => []];
        foreach (['categories' => 'category', 'tags' => 'post_tag'] as $name => $taxonomy) {
            if (!is_object_in_taxonomy($post->post_type, $taxonomy)) { continue; }
            $ids = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($ids)) { throw new \RuntimeException('taxonomy_unavailable'); }
            $result[$name] = array_map('intval', $ids); sort($result[$name]);
        }
        return $result;
    }

    public static function create_draft(object $args): array
    {
        $type = $args->post_type ?? 'post';
        if (!in_array($type, ['post', 'page'], true)) { return self::error('invalid_input'); }
        if (!current_user_can($type === 'page' ? 'edit_pages' : 'edit_posts')) { return self::error('permission_denied'); }
        $fields = self::content_fields($args, $type, true);
        if (is_wp_error($fields)) { return self::error($fields->get_error_code()); }
        $post = $fields + ['post_type' => $type, 'post_status' => 'draft', 'post_author' => get_current_user_id()];
        self::$mutation_started = true;
        $id = wp_insert_post(wp_slash($post), true);
        if (is_wp_error($id) || !$id) { return ['state' => 'unknown', 'actions_used' => 0]; }
        $saved = get_post($id);
        return $saved instanceof \WP_Post && $saved->post_status === 'draft' && $saved->post_type === $type
            && (!isset($args->featured_media) || (int) get_post_thumbnail_id($id) === $args->featured_media)
            ? self::ok(self::item($saved)) : ['state' => 'unknown', 'actions_used' => 0];
    }

    public static function update_preview(object $args): array
    {
        if (!is_int($args->id ?? null) || !is_string($args->version ?? null)) { return self::error('invalid_input'); }
        $post = get_post($args->id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true) || !current_user_can('edit_post', $post->ID)
            || !in_array($post->post_status, ['draft', 'pending', 'publish', 'private', 'future'], true)) { return self::error('permission_denied'); }
        if (in_array($post->post_status, ['publish', 'private', 'future'], true)
            && !current_user_can($post->post_type === 'page' ? 'publish_pages' : 'publish_posts')) { return self::error('permission_denied'); }
        if (!hash_equals(self::version($post), $args->version)) { return ['state' => 'conflict', 'actions_used' => 0, 'error' => ['code' => 'content_changed', 'message' => 'This content changed since it was read. Read it again before editing or publishing.']]; }
        $allowed = ['id', 'version', 'request_key', 'title', 'content', 'excerpt', 'slug', 'categories', 'tags', 'featured_media'];
        if (array_diff(array_keys(get_object_vars($args)), $allowed)) { return self::error('invalid_input'); }
        $fields = self::content_fields($args, $post->post_type);
        if (is_wp_error($fields)) { return self::error($fields->get_error_code()); }
        if (!$fields) { return self::error('invalid_input'); }
        return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => self::item($post)
            + ['preview_url' => get_preview_post_link($post), 'changes' => $fields]];
    }

    /** Updates one post/page with an optimistic version check; preserves its status, type and author. */
    public static function update(object $args): array
    {
        global $wpdb;
        self::$mutation_started = false;
        if (!is_int($args->id ?? null)) { return self::error('invalid_input'); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Serialize with the WordPress editor's post-row write; all IDs are prepared.
        if ($wpdb->query('START TRANSACTION') === false) { return self::error('site_unavailable'); }
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The locked database row, not a stale cache, governs this update.
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $args->id));
            if (!$row) { return self::error('permission_denied'); }
            clean_post_cache($args->id); clean_object_term_cache($args->id, $row->post_type);
            $preview = self::update_preview($args);
            if ($preview['state'] !== 'pending_approval') { return $preview; }
            $fields = self::content_fields($args, $row->post_type);
            if (is_wp_error($fields)) { return self::error($fields->get_error_code()); }
            self::$mutation_started = true;
            $id = wp_update_post(wp_slash($fields + ['ID' => $args->id]), true);
            if (is_wp_error($id) || !$id) { return ['state' => 'unknown', 'actions_used' => 0]; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the saved row before committing.
            $saved = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $args->id));
            if (!$saved || $saved->post_status !== $row->post_status || $saved->post_type !== $row->post_type
                || (int) $saved->post_author !== (int) $row->post_author || $saved->post_date_gmt !== $row->post_date_gmt) { return ['state' => 'unknown', 'actions_used' => 0]; }
            if (isset($args->featured_media) && (int) get_post_thumbnail_id($args->id) !== $args->featured_media) { return ['state' => 'unknown', 'actions_used' => 0]; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- End the short post update transaction; the operation journal still governs at-most-once delivery.
            if ($wpdb->query('COMMIT') === false) { return ['state' => 'unknown', 'actions_used' => 0]; }
            clean_post_cache($id); clean_object_term_cache($id, $saved->post_type);
            return self::ok(self::item(new \WP_Post($saved)));
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Every refused or interrupted update exits its transaction.
            $wpdb->query('ROLLBACK'); clean_post_cache($args->id);
            if (isset($row) && $row) { clean_object_term_cache($args->id, $row->post_type); }
        }
    }

    /** Explicit timezone required; never silently publish an already-passed scheduled date. */
    private static function publication_fields(object $args)
    {
        if (!property_exists($args, 'publish_at')) { return ['post_status' => 'publish']; }
        if (!is_string($args->publish_at) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/D', $args->publish_at)) { return new \WP_Error('invalid_schedule'); }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $args->publish_at);
        $errors = \DateTimeImmutable::getLastErrors();
        $canonical = preg_replace('/Z$/', '+00:00', $args->publish_at);
        if (!$date || $date->format('Y-m-d\TH:i:sP') !== $canonical || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp() < time() + 60) { return new \WP_Error('invalid_schedule'); }
        $gmt = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return ['post_status' => 'future', 'edit_date' => true, 'post_date_gmt' => $gmt, 'post_date' => get_date_from_gmt($gmt)];
    }

    public static function get_content(object $args): array
    {
        if (!is_int($args->id ?? null) || $args->id < 1) { return self::error('invalid_input'); }
        $post = get_post($args->id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true) || !current_user_can('read_post', $post->ID)) { return self::error('permission_denied'); }
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
        if (!$post || !in_array($post->post_type, ['post', 'page'], true) || !current_user_can('edit_post', $post->ID)
            || !current_user_can($post->post_type === 'page' ? 'publish_pages' : 'publish_posts')) { return self::error('permission_denied'); }
        if (!hash_equals(self::version($post), $args->version)) { return ['state' => 'conflict', 'actions_used' => 0, 'error' => ['code' => 'content_changed', 'message' => 'This content changed since it was read. Read it again before editing or publishing.']]; }
        if (!in_array($post->post_status, ['draft', 'pending'], true)) { return self::error('invalid_status'); }
        $fields = self::publication_fields($args);
        if (is_wp_error($fields)) { return self::error($fields->get_error_code()); }
        return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => array_merge(self::item($post), ['preview_url' => get_preview_post_link($post), 'publication_date_gmt' => $fields['post_date_gmt'] ?? null, 'scheduled' => $fields['post_status'] === 'future'])];
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
            if (!in_array($post->post_type, ['post', 'page'], true) || !current_user_can('edit_post', $post->ID) || !current_user_can($post->post_type === 'page' ? 'publish_pages' : 'publish_posts')) { return self::error('permission_denied'); }
            if (!is_string($args->version ?? null) || !hash_equals(self::version($post), $args->version)) { return ['state' => 'conflict', 'actions_used' => 0, 'error' => ['code' => 'content_changed', 'message' => 'This content changed since it was read. Read it again before editing or publishing.']]; }
            if (!in_array($post->post_status, ['draft', 'pending'], true)) { return self::error('invalid_status'); }
            $fields = self::publication_fields($args);
            if (is_wp_error($fields)) { return self::error($fields->get_error_code()); }
            clean_post_cache($post->ID);
            self::$mutation_started = true;
            // Drafts may have no slug, or share one with another draft. The
            // normal save path generates a unique permalink when publishing.
            $published = wp_update_post($fields + ['ID' => $post->ID], true);
            if (is_wp_error($published) || !$published) { return ['state' => 'unknown', 'actions_used' => 0]; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction and post-row locking require actual database state; WordPress owns the table and post IDs are prepared.
            $saved = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post->ID));
            if (!$saved || $saved->post_status !== $fields['post_status']
                || (isset($fields['post_date_gmt']) && $saved->post_date_gmt !== $fields['post_date_gmt'])) { return ['state' => 'unknown', 'actions_used' => 0]; }
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

    private static function media_item(\WP_Post $post): array
    {
        $alt = (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true);
        $metadata = wp_get_attachment_metadata($post->ID);
        return ['id' => (int) $post->ID, 'title' => $post->post_title, 'alt_text' => $alt,
            'caption' => $post->post_excerpt, 'description' => $post->post_content,
            'url' => wp_get_attachment_url($post->ID), 'mime_type' => $post->post_mime_type,
            'width' => (int) ($metadata['width'] ?? 0), 'height' => (int) ($metadata['height'] ?? 0),
            'version' => $post->post_modified_gmt . '|' . substr(hash('sha256', $post->post_title . "\0" . $alt . "\0" . $post->post_excerpt . "\0" . $post->post_content), 0, 16),
            'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit')];
    }

    public static function search_media(object $args): array
    {
        if (isset($args->query) && (!is_string($args->query) || mb_strlen($args->query) > 200)) { return self::error('invalid_input'); }
        foreach (['page' => 1000, 'per_page' => 50] as $name => $max) {
            if (isset($args->$name) && (!is_int($args->$name) || $args->$name < 1 || $args->$name > $max)) { return self::error('invalid_input'); }
        }
        $page = $args->page ?? 1;
        $query = new \WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
            's' => $args->query ?? '', 'posts_per_page' => $args->per_page ?? 20, 'paged' => $page, 'perm' => 'readable', 'orderby' => 'ID', 'order' => 'DESC']);
        $items = [];
        foreach ($query->posts as $post) { if (current_user_can('read_post', $post->ID)) { $items[] = self::media_item($post); } }
        return self::ok(['items' => $items, 'page' => $page, 'has_more' => $page < (int) $query->max_num_pages]);
    }

    public static function get_media(object $args): array
    {
        if (!is_int($args->id ?? null) || $args->id < 1) { return self::error('invalid_input'); }
        $post = get_post($args->id);
        if (!$post || $post->post_type !== 'attachment' || $post->post_status === 'trash'
            || !wp_attachment_is_image($post->ID) || !current_user_can('read_post', $post->ID)) { return self::error('permission_denied'); }
        return self::ok(self::media_item($post));
    }

    /** Stable keyset paging: filling earlier rows cannot make later missing images disappear between pages. */
    public static function find_media_missing_text(object $args): array
    {
        global $wpdb;
        $fields = $args->fields ?? ['alt_text', 'caption', 'title'];
        $after = $args->after_id ?? 0; $limit = $args->limit ?? 10;
        if (!is_array($fields) || !$fields || count($fields) > 3 || count(array_filter($fields, 'is_string')) !== count($fields) || array_diff($fields, ['alt_text', 'caption', 'title'])
            || !is_int($after) || $after < 0 || !is_int($limit) || $limit < 1 || $limit > 10
            || (isset($args->query) && (!is_string($args->query) || mb_strlen($args->query) > 200))) { return self::error('invalid_input'); }
        $missing = [];
        if (in_array('title', $fields, true)) { $missing[] = "{$wpdb->posts}.post_title REGEXP '^[[:space:]]*$'"; }
        if (in_array('caption', $fields, true)) { $missing[] = "{$wpdb->posts}.post_excerpt REGEXP '^[[:space:]]*$'"; }
        if (in_array('alt_text', $fields, true)) {
            $missing[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} mt WHERE mt.post_id = {$wpdb->posts}.ID AND mt.meta_key = '_wp_attachment_image_alt' AND mt.meta_value NOT REGEXP '^[[:space:]]*$')";
        }
        $where = static function ($sql, $query) use ($wpdb, $missing, $after) {
            if (!$query->get('aipkit_missing_media')) { return $sql; }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Native table names and fixed allowlisted predicates only; the cursor is prepared.
            return $sql . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d AND (" . implode(' OR ', $missing) . ')', $after);
        };
        add_filter('posts_where', $where, 10, 2);
        try {
            $query = new \WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
                's' => $args->query ?? '', 'posts_per_page' => $limit, 'perm' => 'readable', 'orderby' => 'ID', 'order' => 'ASC',
                'aipkit_missing_media' => true, 'no_found_rows' => true]);
        } finally { remove_filter('posts_where', $where, 10); }
        $items = []; $cursor = $after;
        foreach ($query->posts as $post) {
            $cursor = (int) $post->ID;
            if (!current_user_can('read_post', $post->ID)) { continue; }
            $item = self::media_item($post);
            $item['missing_fields'] = array_values(array_filter($fields, static function ($field) use ($item): bool { return trim($item[$field]) === ''; }));
            $items[] = $item;
        }
        return self::ok(['items' => $items, 'next_after_id' => $cursor, 'has_more' => count($query->posts) === $limit,
            'message' => 'Continue with next_after_id, not a page offset. Only whitespace-empty fields are missing; filename-like titles are preserved. Media Library edits do not rewrite images already embedded in posts.']);
    }

    /** Send a bounded local raster preview to the assistant; no vision model or external image host is called. */
    public static function inspect_media(object $args): array
    {
        $read = self::get_media($args);
        if ($read['state'] !== 'succeeded') { return $read; }
        $file = realpath((string) get_attached_file($args->id));
        $uploads = wp_get_upload_dir(); $root = realpath($uploads['basedir']);
        if (!$file || !$root || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)
            || filesize($file) > 20 * MB_IN_BYTES) { return self::error('image_unavailable', 'A readable local image up to 20 MB is required.'); }
        $size = wp_getimagesize($file);
        if (!$size || $size[0] * $size[1] > 40000000 || !in_array($size['mime'] ?? '', ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return self::error('unsupported_image', 'Inspect a JPEG, PNG, GIF or WebP up to 40 megapixels.');
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) { return self::error('image_unavailable'); }
        // WordPress reports a resize error when an image already fits the bounds.
        // Keep its native dimensions and still encode the bounded JPEG preview.
        if ($size[0] > 640 || $size[1] > 640) {
            $resized = $editor->resize(640, 640, false);
            // Core also skips near-identical (1px) resizes. Resample the full
            // frame explicitly so those previews still respect the 640px limit.
            if (is_wp_error($resized) && $resized->get_error_code() === 'error_getting_dimensions') {
                [$width, $height] = wp_constrain_dimensions($size[0], $size[1], 640, 640);
                $resized = $editor->crop(0, 0, $size[0], $size[1], $width, $height);
            }
            if (is_wp_error($resized)) { return self::error('image_unavailable', 'The image could not be resized for inspection.'); }
        }
        $editor->set_quality(70);
        $tmp = wp_tempnam('aipkit-media-preview');
        if (!$tmp) { return self::error('image_unavailable'); }
        $saved = null;
        try {
            $saved = $editor->save($tmp . '.jpg', 'image/jpeg');
            // Lossless WebP sources can retain quality 100 during conversion.
            // Reopen the JPEG so quality applies to its actual output format,
            // then reduce only the temporary preview in a bounded number of steps.
            foreach ([[640, 60], [480, 50], [320, 40]] as [$edge, $quality]) {
                if (is_wp_error($saved) || !is_file($saved['path'])) { break; }
                clearstatcache(true, $saved['path']);
                if (filesize($saved['path']) <= 192 * KB_IN_BYTES) { break; }
                $editor = wp_get_image_editor($saved['path']);
                if (is_wp_error($editor)) { break; }
                if ($saved['width'] > $edge || $saved['height'] > $edge) {
                    [$width, $height] = wp_constrain_dimensions($saved['width'], $saved['height'], $edge, $edge);
                    $resized = $editor->crop(0, 0, $saved['width'], $saved['height'], $width, $height);
                    if (is_wp_error($resized)) { break; }
                }
                if (is_wp_error($editor->set_quality($quality))) { break; }
                $encoded = $editor->save($saved['path'], 'image/jpeg');
                if (is_wp_error($encoded)) {
                    return self::error('image_unavailable', 'The image preview could not be compressed.');
                }
                if ($encoded['path'] !== $saved['path']) { wp_delete_file($saved['path']); }
                $saved = $encoded;
            }
            if (is_array($saved) && isset($saved['path'])) { clearstatcache(true, $saved['path']); }
            if (is_wp_error($saved) || !is_file($saved['path']) || filesize($saved['path']) > 192 * KB_IN_BYTES) {
                return self::error('image_unavailable', 'A bounded preview could not be created. Do not guess from the filename.');
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local temporary preview, never a URL.
            $bytes = file_get_contents($saved['path']);
            if ($bytes === false) { return self::error('image_unavailable'); }
            $read['result']['image_preview'] = ['mime_type' => 'image/jpeg', 'data' => base64_encode($bytes),
                'width' => $saved['width'], 'height' => $saved['height']];
            $read['result']['message'] = 'Use the actual image, not its filename, to propose accurate text. Do not infer identities, hidden details or unreadable text. GIF preview is one frame. Existing text must be preserved.';
            return $read;
        } finally {
            wp_delete_file($tmp);
            if (is_array($saved) && !empty($saved['path'])) { wp_delete_file($saved['path']); }
            else { wp_delete_file($tmp . '.jpg'); }
        }
    }

    /** Validate a missing-only edit both before approval and again under the write lock. */
    public static function media_text_preview(object $args): array
    {
        if (!is_string($args->version ?? null) || array_diff(array_keys(get_object_vars($args)), ['id', 'version', 'alt_text', 'caption', 'title', 'request_key'])) { return self::error('invalid_input'); }
        $input = [];
        foreach (['alt_text' => 2000, 'caption' => 2000, 'title' => 300] as $field => $max) {
            if (!property_exists($args, $field)) { continue; }
            if (!is_string($args->$field) || trim($args->$field) === '' || mb_strlen($args->$field) > $max
                || sanitize_text_field($args->$field) !== $args->$field) { return self::error('invalid_input', 'Send nonempty plain text only.'); }
            $input[$field] = $args->$field;
        }
        if (!$input) { return self::error('invalid_input'); }
        $read = self::get_media($args);
        if ($read['state'] !== 'succeeded') { return $read; }
        if (!current_user_can('upload_files') || !current_user_can('edit_post', $args->id)) { return self::error('permission_denied'); }
        if (!hash_equals($read['result']['version'], $args->version)) { return ['state' => 'conflict', 'actions_used' => 0,
            'error' => ['code' => 'content_changed', 'message' => 'The image text changed. Read it again; preserve the new text.']]; }
        return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'media', 'title' => $read['result']['title'],
            'description' => 'Fill missing image text only', 'risk' => 'publish', 'input' => wp_json_encode($input), 'name' => (string) $args->id]];
    }

    public static function fill_media_text(object $args): array
    {
        global $wpdb;
        if (!is_int($args->id ?? null) || $args->id < 1) { return self::error('invalid_input'); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Serialize the attachment row and metadata while filling empty fields.
        if ($wpdb->query('START TRANSACTION') === false) { return self::error('site_unavailable'); }
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared ID; native attachment row lock.
            $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $args->id));
            if (!$post) { return self::error('permission_denied'); }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock existing metadata and its insertion range, including duplicate alt rows.
            if ($wpdb->get_results($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $args->id)) === null) { return self::error('site_unavailable'); }
            clean_post_cache($args->id);
            $preview = self::media_text_preview($args);
            if ($preview['state'] !== 'pending_approval') { return $preview; }
            $before = self::media_item(new \WP_Post($post)); $updates = []; $skipped = [];
            foreach (['alt_text', 'caption', 'title'] as $field) {
                if (!property_exists($args, $field)) { continue; }
                if (trim($before[$field]) !== '') { $skipped[] = $field; continue; }
                if ($field === 'alt_text') {
                    foreach (get_post_meta($args->id, '_wp_attachment_image_alt', false) as $value) {
                        if (trim((string) $value) !== '') { $skipped[] = $field; continue 2; }
                    }
                }
                $updates[$field] = $args->$field;
            }
            if (!$updates) { return ['state' => 'succeeded', 'actions_used' => 0, 'result' => $before + ['updated_fields' => [], 'skipped_fields' => $skipped]]; }
            self::$mutation_started = true;
            $fields = ['ID' => $args->id];
            if (isset($updates['title'])) { $fields['post_title'] = $updates['title']; }
            if (isset($updates['caption'])) { $fields['post_excerpt'] = $updates['caption']; }
            if (count($fields) > 1 && is_wp_error(wp_update_post(wp_slash($fields), true))) { return ['state' => 'unknown', 'actions_used' => 0]; }
            if (isset($updates['alt_text'])) { update_post_meta($args->id, '_wp_attachment_image_alt', wp_slash($updates['alt_text'])); }
            clean_post_cache($args->id);
            $after = self::media_item(get_post($args->id));
            foreach (['alt_text', 'caption', 'title', 'description'] as $field) {
                if ($after[$field] !== ($updates[$field] ?? $before[$field])) { return ['state' => 'unknown', 'actions_used' => 0]; }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit the single verified attachment change.
            if ($wpdb->query('COMMIT') === false) { return ['state' => 'unknown', 'actions_used' => 0]; }
            return self::ok($after + ['updated_fields' => array_keys($updates), 'skipped_fields' => $skipped]);
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Roll back any incomplete edit and discard transaction-scoped caches.
            $wpdb->query('ROLLBACK'); clean_post_cache($args->id);
        }
    }

    /** Native client file references carry a temporary download URL, never a local filesystem path. */
    public static function upload_image_file(object $args): array
    {
        $file = $args->file ?? null;
        if (!is_object($file) || !is_string($file->file_id ?? null)
            || $file->file_id === '' || strlen($file->file_id) > 512 || preg_match('/[\x00-\x1f\x7f]/', $file->file_id)
            || !is_string($file->download_url ?? null) || strlen($file->download_url) > 8192
            || (isset($file->file_name) && (!is_string($file->file_name) || strlen($file->file_name) > 255))
            || (isset($file->mime_type) && (!is_string($file->mime_type) || strlen($file->mime_type) > 100))) {
            return self::error('invalid_input');
        }
        return self::upload_media((object) ['url' => $file->download_url,
            'title' => $args->title ?? '', 'alt_text' => $args->alt_text ?? '']);
    }

    /** Public HTTPS raster images only; safe redirects, bounded download, content sniffing and native WP upload checks. */
    public static function upload_media(object $args): array
    {
        if (!current_user_can('upload_files')) { return self::error('permission_denied'); }
        if (!is_string($args->url ?? null) || strlen($args->url) > 8192
            || !is_string($args->title ?? '') || mb_strlen($args->title ?? '') > 300
            || !is_string($args->alt_text ?? '') || mb_strlen($args->alt_text ?? '') > 2000) { return self::error('invalid_input'); }
        $parts = wp_parse_url($args->url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443) || !wp_http_validate_url($args->url)) { return self::error('invalid_media_url'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = wp_tempnam('aipuffer-image');
        if (!$tmp) { return self::error('site_unavailable'); }
        try {
            $max = min(5 * MB_IN_BYTES, wp_max_upload_size());
            $response = wp_safe_remote_get($args->url, ['timeout' => 15, 'redirection' => 3, 'stream' => true,
                'filename' => $tmp, 'limit_response_size' => $max + 1]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return self::error('image_download_failed'); }
            $bytes = filesize($tmp);
            $size = wp_getimagesize($tmp);
            $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (!$bytes || $bytes > $max || !$size || !isset($types[$size['mime'] ?? ''])
                || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 40000000) { return self::error('invalid_image'); }
            $title = sanitize_text_field($args->title ?? '');
            $name = sanitize_file_name(($title !== '' ? $title : 'image') . '.' . $types[$size['mime']]);
            $check = wp_check_filetype_and_ext($tmp, $name, get_allowed_mime_types());
            if (($check['type'] ?? '') !== $size['mime']) { return self::error('invalid_image'); }
            self::$mutation_started = true;
            $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, $title,
                ['post_title' => $title !== '' ? $title : pathinfo($name, PATHINFO_FILENAME)]);
            if (is_wp_error($id) || !$id) { return ['state' => 'unknown', 'actions_used' => 0]; }
            $alt = sanitize_text_field($args->alt_text ?? '');
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
            $post = get_post($id);
            if (!$post || !wp_attachment_is_image($id) || (string) get_post_meta($id, '_wp_attachment_image_alt', true) !== $alt) {
                return ['state' => 'unknown', 'actions_used' => 0, 'result' => ['id' => (int) $id]];
            }
            return self::ok(self::media_item($post));
        } finally { if (file_exists($tmp)) { wp_delete_file($tmp); } }
    }

    public static function media_preview(object $args): array
    {
        if (!is_string($args->version ?? null) || !is_string($args->alt_text ?? null) || mb_strlen($args->alt_text) > 2000
            || array_diff(array_keys(get_object_vars($args)), ['id', 'version', 'alt_text', 'request_key'])) { return self::error('invalid_input'); }
        $read = self::get_media($args);
        if ($read['state'] !== 'succeeded') { return $read; }
        if (!current_user_can('upload_files') || !current_user_can('edit_post', $args->id)) { return self::error('permission_denied'); }
        if (!hash_equals($read['result']['version'], $args->version)) { return ['state' => 'conflict', 'actions_used' => 0,
            'error' => ['code' => 'content_changed', 'message' => 'This image changed. Read it again before updating.']]; }
        return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'media', 'title' => $read['result']['title'],
            'description' => 'Update image alt text', 'risk' => 'publish', 'input' => wp_json_encode(['alt_text' => sanitize_text_field($args->alt_text)]), 'name' => (string) $args->id]];
    }

    public static function update_media(object $args): array
    {
        global $wpdb;
        if (!is_int($args->id ?? null)) { return self::error('invalid_input'); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Serialize attachment metadata changes using the existing post row.
        if ($wpdb->query('START TRANSACTION') === false) { return self::error('site_unavailable'); }
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared ID and locked row are required for stale-version protection.
            $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $args->id));
            if (!$post) { return self::error('permission_denied'); }
            clean_post_cache($args->id);
            $preview = self::media_preview($args);
            if ($preview['state'] !== 'pending_approval') { return $preview; }
            self::$mutation_started = true;
            $alt = sanitize_text_field($args->alt_text);
            update_post_meta($args->id, '_wp_attachment_image_alt', wp_slash($alt));
            if ((string) get_post_meta($args->id, '_wp_attachment_image_alt', true) !== $alt) { return ['state' => 'unknown', 'actions_used' => 0]; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit the single attachment edit; journal settlement is separate.
            if ($wpdb->query('COMMIT') === false) { return ['state' => 'unknown', 'actions_used' => 0]; }
            return self::ok(self::media_item(new \WP_Post($post)));
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Close every failed transaction and discard cached metadata.
            $wpdb->query('ROLLBACK'); clean_post_cache($args->id);
        }
    }

}
