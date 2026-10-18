<?php
/**
 * Website activity: what assistants read and changed on this site, from the operations journal, for Usage › Activity
 * › Website and the Cloud panel's Website tab. Summaries only: request and response bodies are never sent to the
 * browser. Rows are kept for Journal::RETENTION_DAYS.
 */
namespace WPAICG\Mcp;

use WPAICG\Cloud\Connection;

if (!defined('ABSPATH')) { exit; }

final class Activity
{
    private const FILTERS = ['', 'changes', 'reads', 'attention'];
    private static $grants = [];

    public static function register(): void
    {
        add_action('wp_ajax_aipkit_mcp_activity', [self::class, 'handle']);
    }

    public static function handle(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST' || !Connection::allowed() || !check_ajax_referer('aipkit_nonce', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('You cannot view website activity.', 'gpt3-ai-content-generator')], 403);
        }
        $view = isset($_POST['view']) && is_string($_POST['view']) ? sanitize_key(wp_unslash($_POST['view'])) : '';
        if ($view === 'breakdown') {
            $days = isset($_POST['days']) && is_scalar($_POST['days']) ? absint($_POST['days']) : 30;
            $breakdown = self::breakdown($days);
            if (is_wp_error($breakdown)) { wp_send_json_error(['message' => $breakdown->get_error_message()], 503); }
            wp_send_json_success($breakdown);
        }
        $filter = isset($_POST['filter']) && is_string($_POST['filter']) ? sanitize_key(wp_unslash($_POST['filter'])) : '';
        $search = isset($_POST['search']) && is_string($_POST['search']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['search'])), 0, 100) : '';
        $page = isset($_POST['page']) ? max(1, min(1000, absint($_POST['page']))) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, min(50, absint($_POST['per_page']))) : 20;
        if (!in_array($filter, self::FILTERS, true)) { $filter = ''; }
        // Usage › Everything asks for the selected period (the same UTC calendar days as the breakdown); the Website view does not.
        $days = isset($_POST['days']) && is_scalar($_POST['days']) ? absint($_POST['days']) : 0;
        $since = in_array($days, [1, 7, 30, 90], true) ? (int) (floor(time() / DAY_IN_SECONDS) * DAY_IN_SECONDS) - ($days - 1) * DAY_IN_SECONDS : 0;
        wp_send_json_success(self::feed($filter, $search, $page, $per_page, $since));
    }

    /** One page of activity, newest first, with the counts the sub-filters show. */
    public static function feed(string $filter = '', string $search = '', int $page = 1, int $per_page = 20, int $since = 0): array
    {
        $empty = ['items' => [], 'counts' => ['all' => 0, 'changes' => 0, 'reads' => 0, 'attention' => 0], 'has_more' => false,
            'connected' => Identity::active(), 'retention_days' => Journal::RETENTION_DAYS];
        if (!Schema::ready()) { return $empty; }
        global $wpdb;
        $table = Schema::table('operations');
        $writes = Registry::write_names();
        $in = implode(',', array_fill(0, count($writes), '%s'));
        $stale = time() - Screens::STALE_AFTER;
        // Conditions are built from generated placeholders only; every value below is prepared.
        $change = "tool IN ($in)";
        $attention = "((state = 'pending_approval' AND expires_at > %d) OR (tool IN ($in) AND (state = 'unknown' OR (state IN ('received', 'executing') AND updated_at <= %d))))";
        $attention_values = array_merge([time()], $writes, [$stale]);
        $where = "tool <> '' AND state <> 'fenced'";
        $values = [];
        if ($since > 0) {
            $where .= ' AND created_at >= %d';
            $values[] = $since;
        }
        if ($search !== '') {
            // Arguments are stored as JSON, which escapes slashes and non-ASCII text: match that form too.
            $where .= ' AND (tool LIKE %s OR args LIKE %s OR args LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $values = array_merge($values, [$like, $like, '%' . $wpdb->esc_like(substr((string) wp_json_encode($search), 1, -1)) . '%']);
        }
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Live activity and approval totals; Schema allowlists the table and all filter values/generated placeholders are prepared.
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS all_rows, SUM($change) AS changes, SUM($attention) AS attention FROM $table WHERE $where",
            array_merge($writes, $attention_values, $values)
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $scope = ['' => ['', []], 'changes' => [" AND $change", $writes], 'reads' => [" AND NOT ($change)", $writes], 'attention' => [" AND $attention", $attention_values]][$filter];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Live activity and approval totals; Schema allowlists the table and all filter values/generated placeholders are prepared.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT operation_id, grant_id, acting_user, tool, args, state, response, expires_at, created_at, updated_at FROM $table
             WHERE $where{$scope[0]} ORDER BY created_at DESC, operation_id DESC LIMIT %d OFFSET %d",
            array_merge($values, $scope[1], [$per_page + 1, ($page - 1) * $per_page])
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (!is_array($rows) || !is_array($counts)) { return $empty; }
        $all = (int) $counts['all_rows'];
        $changes = (int) $counts['changes'];
        return ['items' => array_map([self::class, 'item'], array_slice($rows, 0, $per_page)),
            'counts' => ['all' => $all, 'changes' => $changes, 'reads' => $all - $changes, 'attention' => (int) $counts['attention']],
            'has_more' => count($rows) > $per_page] + array_slice($empty, 3);
    }

    /** Successful, action-using operations on this site, counted once from the journal's unique operation IDs.
     * Completion time determines the UTC calendar period; pending, failed, free and replayed deliveries add nothing.
     * This is local history, not a replacement for the bridge's account-wide balance or settlement ledger.
     *
     * @return array|\WP_Error
     */
    public static function breakdown(int $days = 30)
    {
        $days = in_array($days, [1, 7, 30, 90], true) ? $days : 30;
        $end = time();
        $start = (int) (floor($end / DAY_IN_SECONDS) * DAY_IN_SECONDS) - ($days - 1) * DAY_IN_SECONDS;
        $out = ['days' => $days, 'from' => $start, 'through' => $end, 'total' => 0, 'categories' => [], 'connected' => Identity::active()];
        if (!Schema::ready()) {
            return $out['connected'] ? new \WP_Error('usage_unavailable', __('Website action history is unavailable. Try Refresh.', 'gpt3-ai-content-generator')) : $out;
        }
        global $wpdb;
        $table = Schema::table('operations');
        // Aggregate in the database: no request/response bodies are loaded into PHP or exposed to the browser.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live local history; Schema allowlists the table; period values are prepared. Uncached reporting must include newly completed actions.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT tool, COUNT(*) AS actions FROM $table
            WHERE state = 'succeeded' AND updated_at >= %d AND updated_at <= %d
            AND JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(response), response, '{}'), '$.actions_used')) = 'INTEGER'
            AND JSON_EXTRACT(IF(JSON_VALID(response), response, '{}'), '$.actions_used') = 1
            GROUP BY tool", $start, $end), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (!is_array($rows) || $wpdb->last_error !== '') {
            return new \WP_Error('usage_unavailable', __('Website action history is unavailable. Try Refresh.', 'gpt3-ai-content-generator'));
        }
        $labels = [
            'account_checks' => __('Account checks', 'gpt3-ai-content-generator'),
            'guidance' => __('Guidance', 'gpt3-ai-content-generator'),
            'site_checks' => __('Site checks', 'gpt3-ai-content-generator'),
            'content_reads' => __('Content reads', 'gpt3-ai-content-generator'),
            'content_creation' => __('Content creation', 'gpt3-ai-content-generator'),
            'content_updates' => __('Content updates', 'gpt3-ai-content-generator'),
            'publishing' => __('Publishing', 'gpt3-ai-content-generator'),
            'media_reads' => __('Media reads', 'gpt3-ai-content-generator'),
            'media_uploads' => __('Media uploads', 'gpt3-ai-content-generator'),
            'media_updates' => __('Media updates', 'gpt3-ai-content-generator'),
            'plugin_data_reads' => __('Plugin data reads', 'gpt3-ai-content-generator'),
            'plugin_data_changes' => __('Plugin data changes', 'gpt3-ai-content-generator'),
            'other' => __('Other operations', 'gpt3-ai-content-generator'),
        ];
        $totals = [];
        foreach ($rows as $row) {
            $category = Registry::category($row['tool']);
            $totals[$category] = ($totals[$category] ?? 0) + (int) $row['actions'];
        }
        arsort($totals, SORT_NUMERIC);
        $out['total'] = array_sum($totals);
        foreach ($totals as $category => $actions) {
            $out['categories'][] = ['category' => $category, 'label' => $labels[$category], 'actions' => $actions];
        }
        return $out;
    }

    /** A row as the activity list shows it: who did what to which item, the outcome, and where to go next. */
    private static function item(array $row): array
    {
        $grant = self::$grants[$row['grant_id']] ?? (self::$grants[$row['grant_id']] = Store::grant($row['grant_id']));
        $assistant = ucfirst(Screens::client_label($grant));
        $args = json_decode((string) $row['args']);
        $args = is_object($args) ? $args : new \stdClass();
        $response = json_decode((string) $row['response'], true);
        $response = is_array($response) ? $response : [];
        $state = $row['state'];
        if ($state === 'pending_approval' && (int) $row['expires_at'] <= time()) { $state = 'expired'; }
        $interrupted = in_array($state, ['received', 'executing', 'unknown'], true);
        $post = self::post($row['tool'], $args, $response);
        $subject = self::subject($row['tool'], $args, $post);
        $user = get_userdata((int) $row['acting_user']);
        $links = [];
        if ($state === 'pending_approval' && Registry::can_approve($row)) { $links[] = ['review', __('Review', 'gpt3-ai-content-generator'), Screens::request_url($row['operation_id'])]; }
        if ($interrupted && Registry::is_write($row['tool']) && ($state === 'unknown' || (int) $row['updated_at'] <= time() - Screens::STALE_AFTER)) {
            $links[] = ['review', __('Review', 'gpt3-ai-content-generator'), Screens::review_url()];
        }
        if ($post && current_user_can('read_post', $post->ID)) {
            if ($post->post_status === 'publish') { $links[] = ['view', __('View post', 'gpt3-ai-content-generator'), get_permalink($post)]; }
            if (current_user_can('edit_post', $post->ID)) { $links[] = ['edit', __('Open in editor', 'gpt3-ai-content-generator'), get_edit_post_link($post->ID, 'raw')]; }
        }
        return [
            'id' => $row['operation_id'],
            'tool' => $row['tool'],
            'category' => Registry::category($row['tool']),
            'kind' => Registry::is_write($row['tool']) ? 'change' : 'read',
            'title' => self::title($row['tool'], $state, $assistant, $subject, $args),
            'assistant' => $assistant,
            'acting_user' => $user ? $user->user_login : '',
            'level' => $grant ? Registry::level_label((string) $grant['access_level']) : '',
            'state' => $state,
            'pill' => $state === 'succeeded' && $row['tool'] === 'publish_content' && isset($args->publish_at)
                ? ['label' => __('Scheduled', 'gpt3-ai-content-generator'), 'tone' => 'ok'] : self::pill($row['tool'], $state),
            'actions' => (int) ($response['actions_used'] ?? 0),
            'owner_review' => isset($response['owner_review']),
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
            'expires_at' => $state === 'pending_approval' ? (int) $row['expires_at'] : 0,
            'subject' => $subject,
            'links' => array_map(static function (array $link): array { return ['type' => $link[0], 'label' => $link[1], 'url' => $link[2]]; }, $links),
        ];
    }

    /** The post an operation acted on, when there is one. */
    private static function post(string $tool, object $args, array $response): ?\WP_Post
    {
        $id = in_array($tool, ['get_content', 'publish_content', 'update_content', 'get_media', 'update_media', 'inspect_media', 'fill_media_text'], true) ? ($args->id ?? null)
            : (in_array($tool, ['create_draft', 'upload_media', 'upload_image_file'], true) ? ($response['result']['id'] ?? null) : null);
        $post = is_int($id) && $id > 0 ? get_post($id) : null;
        return $post instanceof \WP_Post && in_array($post->post_type, ['post', 'page', 'attachment'], true) ? $post : null;
    }

    /** What the operation was about, in a few words: a post title, a search, an ability or a route. */
    private static function subject(string $tool, object $args, ?\WP_Post $post): string
    {
        if ($post) { return $post->post_title !== '' ? $post->post_title : __('(no title)', 'gpt3-ai-content-generator'); }
        if (in_array($tool, ['get_content', 'publish_content', 'update_content', 'get_media', 'update_media', 'inspect_media', 'fill_media_text'], true) && is_int($args->id ?? null)) {
            /* translators: %d: post ID of a post that no longer exists. */
            return sprintf(__('post #%d', 'gpt3-ai-content-generator'), $args->id);
        }
        switch ($tool) {
            case 'create_draft':
                return is_string($args->title ?? null) ? $args->title : '';
            case 'search_content':
                return is_string($args->query ?? null) ? $args->query : '';
            case 'read_ability':
            case 'run_ability':
                return is_string($args->name ?? null) ? $args->name : '';
            case 'read_rest_route':
                return 'GET ' . (is_string($args->route ?? null) ? $args->route : '');
            case 'write_rest_route':
                return strtoupper(is_string($args->method ?? null) ? $args->method : '') . ' ' . (is_string($args->route ?? null) ? $args->route : '');
            case 'find_abilities':
                return is_string($args->query ?? null) ? $args->query : (is_string($args->category ?? null) ? $args->category : '');
        }
        return '';
    }

    private static function title(string $tool, string $state, string $assistant, string $subject, object $args): string
    {
        $quoted = $subject !== '' ? '“' . $subject . '”' : '';
        if (in_array($state, ['received', 'executing', 'unknown'], true) && Registry::is_write($tool)) {
            /* translators: %s: assistant name. */
            return sprintf(__('A change from %s was interrupted', 'gpt3-ai-content-generator'), $assistant);
        }
        if ($state === 'pending_approval') {
            /* translators: 1: assistant name, 2: what it asked for. */
            return $tool === 'publish_content' ? sprintf(__('%1$s asked to publish %2$s', 'gpt3-ai-content-generator'), $assistant, $quoted)
                /* translators: 1: assistant name, 2: ability name or REST method and route. */
                : sprintf(__('%1$s asked to run %2$s', 'gpt3-ai-content-generator'), $assistant, $subject);
        }
        if ($state === 'declined') {
            /* translators: %s: what was declined. */
            return sprintf(__('You declined %s', 'gpt3-ai-content-generator'), $tool === 'publish_content' ? sprintf(__('publishing %s', 'gpt3-ai-content-generator'), $quoted) : $subject);
        }
        if ($state === 'expired') {
            /* translators: %s: what expired. */
            return sprintf(__('%s expired before approval', 'gpt3-ai-content-generator'), $tool === 'publish_content' ? sprintf(__('Publishing %s', 'gpt3-ai-content-generator'), $quoted) : ucfirst($subject));
        }
        if (in_array($state, ['failed', 'conflict'], true)) {
            if ($tool === 'publish_content') {
                /* translators: 1: assistant name, 2: post title. */
                return sprintf(__('%1$s couldn’t publish %2$s', 'gpt3-ai-content-generator'), $assistant, $quoted);
            }
            if ($tool === 'create_draft') {
                /* translators: 1: assistant name, 2: post title. */
                return sprintf(__('%1$s couldn’t create the draft %2$s', 'gpt3-ai-content-generator'), $assistant, $quoted);
            }
            /* translators: 1: assistant name, 2: what it tried, such as an ability name or a REST route. */
            return sprintf(__('%1$s couldn’t complete %2$s', 'gpt3-ai-content-generator'), $assistant, $subject !== '' ? $subject : $tool);
        }
        $titles = [
            /* translators: 1: assistant name, 2: image title. */
            'upload_media' => __('%1$s uploaded %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: image title. */
            'upload_image_file' => __('%1$s uploaded %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: image title. */
            'update_media' => __('%1$s updated image alt text for %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: image title. */
            'fill_media_text' => __('%1$s filled missing image text for %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: image title. */
            'inspect_media' => __('%1$s viewed %2$s', 'gpt3-ai-content-generator'),
            /* translators: %1$s: assistant name. */
            'find_media_missing_text' => __('%1$s checked images for missing text', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: image title. */
            'get_media' => __('%1$s read image details for %2$s', 'gpt3-ai-content-generator'),
            /* translators: %1$s: assistant name. */
            'search_media' => __('%1$s searched images', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: post title. */
            'create_draft' => __('%1$s created the draft %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: post title. */
            'publish_content' => isset($args->publish_at) ? __('%1$s scheduled publication of %2$s', 'gpt3-ai-content-generator') : __('%1$s published %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: content title. */
            'update_content' => __('%1$s updated %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: post title. */
            'get_content' => __('%1$s read %2$s', 'gpt3-ai-content-generator'),
            'search_content' => ($args->post_type ?? 'post') === 'page'
                ? ($subject !== ''
                    /* translators: 1: assistant name, 2: search words. */
                    ? __('%1$s searched pages for %2$s', 'gpt3-ai-content-generator')
                    /* translators: %1$s: assistant name. */
                    : __('%1$s listed pages', 'gpt3-ai-content-generator'))
                : ($subject !== ''
                    /* translators: 1: assistant name, 2: search words. */
                    ? __('%1$s searched posts for %2$s', 'gpt3-ai-content-generator')
                    /* translators: %1$s: assistant name. */
                    : __('%1$s listed posts', 'gpt3-ai-content-generator')),
            /* translators: %1$s: assistant name. */
            'get_site_overview' => __('%1$s read the site overview', 'gpt3-ai-content-generator'),
            /* translators: %1$s: assistant name. */
            'find_abilities' => __('%1$s looked up what the site can do', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: ability name. */
            'read_ability' => __('%1$s read %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: ability name. */
            'run_ability' => __('%1$s ran %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: REST route. */
            'read_rest_route' => __('%1$s read %2$s', 'gpt3-ai-content-generator'),
            /* translators: 1: assistant name, 2: REST method and route. */
            'write_rest_route' => __('%1$s sent %2$s', 'gpt3-ai-content-generator'),
        ];
        $quoted_tools = ['create_draft', 'publish_content', 'update_content', 'get_content', 'search_content'];
        return sprintf($titles[$tool] ?? '%1$s: %2$s', $assistant, in_array($tool, $quoted_tools, true) ? $quoted : $subject);
    }

    /** The outcome pill: label and tone (ok, warn, bad, grey), matching the other activity rows. */
    private static function pill(string $tool, string $state): array
    {
        if (in_array($state, ['received', 'executing', 'unknown'], true)) {
            return Registry::is_write($tool) ? ['label' => __('Needs review', 'gpt3-ai-content-generator'), 'tone' => 'warn'] : ['label' => __('Not completed', 'gpt3-ai-content-generator'), 'tone' => 'grey'];
        }
        $pills = [
            'pending_approval' => [__('Needs approval', 'gpt3-ai-content-generator'), 'warn'],
            'declined' => [__('Declined', 'gpt3-ai-content-generator'), 'grey'],
            'expired' => [__('Expired', 'gpt3-ai-content-generator'), 'grey'],
            'failed' => [__('Not applied', 'gpt3-ai-content-generator'), 'bad'],
            'conflict' => [__('Not applied', 'gpt3-ai-content-generator'), 'bad'],
        ];
        if (isset($pills[$state])) { return ['label' => $pills[$state][0], 'tone' => $pills[$state][1]]; }
        $done = ['fill_media_text' => __('Missing text updated', 'gpt3-ai-content-generator'), 'upload_image_file' => __('Image uploaded', 'gpt3-ai-content-generator'), 'upload_media' => __('Image uploaded', 'gpt3-ai-content-generator'), 'update_media' => __('Alt text updated', 'gpt3-ai-content-generator'), 'create_draft' => __('Draft created', 'gpt3-ai-content-generator'), 'publish_content' => __('Published', 'gpt3-ai-content-generator'),
            'update_content' => __('Updated', 'gpt3-ai-content-generator'), 'run_ability' => __('Done', 'gpt3-ai-content-generator'), 'write_rest_route' => __('Done', 'gpt3-ai-content-generator')];
        return isset($done[$tool]) ? ['label' => $done[$tool], 'tone' => 'ok'] : ['label' => __('Read', 'gpt3-ai-content-generator'), 'tone' => 'grey'];
    }
}
