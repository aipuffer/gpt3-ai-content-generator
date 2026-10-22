<?php
/** wp-admin screens for assistant requests: publish approval, its result, and interrupted-change review. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Screens
{
    public const REQUEST_PAGE = 'wpaicg-mcp-request';
    public const REVIEW_PAGE = 'wpaicg-mcp-review';
    /** Requests still running after this long are offered for review. */
    public const STALE_AFTER = 600;

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            // Hidden pages (no menu entry); each screen checks its own permissions.
            add_submenu_page('options.php', __('Publish request', 'gpt3-ai-content-generator'), '', 'read', self::REQUEST_PAGE, [self::class, 'render_request']);
            add_submenu_page('options.php', __('Check an interrupted change', 'gpt3-ai-content-generator'), '', 'read', self::REVIEW_PAGE, [self::class, 'render_review']);
        });
    }

    public static function request_url(string $operation_id, string $notice = ''): string
    {
        $args = ['page' => self::REQUEST_PAGE, 'operation_id' => $operation_id];
        if ($notice !== '') { $args['notice'] = $notice; }
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function review_url(string $notice = ''): string
    {
        $args = ['page' => self::REVIEW_PAGE];
        if ($notice !== '') { $args['notice'] = $notice; }
        return add_query_arg($args, admin_url('admin.php'));
    }

    /** The assistant's name as the owner chose or the bridge reported it. */
    public static function client_label(?array $grant): string
    {
        $hints = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];
        if (!$grant) { return __('your assistant', 'gpt3-ai-content-generator'); }
        return (string) ($grant['client_name'] ?: ($hints[$grant['client_hint']] ?? __('your assistant', 'gpt3-ai-content-generator')));
    }

    /**
     * Writes on this site whose outcome is unknown, or that stopped while running.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function needs_review(): array
    {
        if (!Identity::active() || !Schema::ready()) { return []; }
        global $wpdb;
        $table = Schema::table('operations');
        $writes = Registry::write_names();
        // Only generated placeholders are interpolated; every tool name is a prepared value.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Read current approval/recovery state; Schema allowlists tables and values are prepared; stale cached decisions are unsafe.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE instance_id = %s AND tool IN (" . implode(',', array_fill(0, count($writes), '%s')) . ")
             AND (state = 'unknown' OR (state IN ('received', 'executing') AND updated_at <= %d))
             ORDER BY created_at ASC LIMIT 20",
            array_merge([Identity::registration()['instance_id'] ?? ''], $writes, [time() - self::STALE_AFTER])
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        return is_array($rows) ? $rows : [];
    }

    /** Publications on this site still waiting for the owner, oldest first. */
    public static function pending_approvals(): array
    {
        if (!Identity::active() || !Schema::ready()) { return []; }
        global $wpdb;
        $table = Schema::table('operations');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read current approval/recovery state; Schema allowlists tables and values are prepared; stale cached decisions are unsafe.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT operation_id, acting_user, tool, args FROM $table WHERE instance_id = %s AND (acting_user = %d OR %d = 1) AND state = 'pending_approval' AND expires_at > %d ORDER BY created_at ASC",
            Identity::registration()['instance_id'] ?? '',
            get_current_user_id(),
            current_user_can('manage_options') ? 1 : 0,
            time()
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Only the requests this user can approve (Registry::can_approve).
        return is_array($rows) ? array_values(array_filter($rows, [Registry::class, 'can_approve'])) : [];
    }

    /** Usage page banner: approvals waiting, then changes to review. Nothing when all is settled. */
    public static function render_usage_attention(): void
    {
        $items = [];
        $approvals = self::pending_approvals();
        if ($approvals) {
            $items[] = [
                /* translators: %s: number of assistant requests waiting for approval. */
                sprintf(_n('%s assistant request is waiting for your approval.', '%s assistant requests are waiting for your approval.', count($approvals), 'gpt3-ai-content-generator'), number_format_i18n(count($approvals))),
                __('Review', 'gpt3-ai-content-generator'),
                self::request_url($approvals[0]['operation_id']),
            ];
        }
        $review = self::needs_review();
        if ($review) {
            $items[] = [
                /* translators: %s: number of website changes whose result is unknown. */
                sprintf(_n('%s website change couldn’t be confirmed.', '%s website changes couldn’t be confirmed.', count($review), 'gpt3-ai-content-generator'), number_format_i18n(count($review))),
                __('Review', 'gpt3-ai-content-generator'),
                self::review_url(),
            ];
        }
        if (!$items) { return; }
        echo '<div class="aipkit_usage_attention" role="status">';
        foreach (array_slice($items, 0, 2) as [$text, $label, $url]) {
            echo '<div class="aipkit_usage_attention_item"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span>' . esc_html($text) . '</span><a class="aipkit_usage_btn aipkit_usage_btn--small" href="' . esc_url($url) . '">' . esc_html($label) . '</a></div>';
        }
        echo '</div>';
    }

    public static function render_request(): void
    {
        $id = isset($_GET['operation_id']) && is_string($_GET['operation_id']) ? sanitize_text_field(wp_unslash($_GET['operation_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view; every decision is a nonce-checked POST.
        $row = $id !== '' && Schema::ready() ? Journal::get($id) : null;
        echo '<div class="wrap aipkit_mcp_screen"><hr class="wp-header-end">';
        self::notice();
        if (!$row || !Registry::can_approve($row)) {
            self::message('lock', __('You can’t approve this request', 'gpt3-ai-content-generator'), __('Only the WordPress user the assistant acts as, or an administrator, can approve it, and they need permission to make this change themselves.', 'gpt3-ai-content-generator'), [[__('Back to dashboard', 'gpt3-ai-content-generator'), admin_url(), true]]);
            echo '</div>';
            return;
        }
        $row = Journal::expire($row);
        $grant = Store::grant($row['grant_id']);
        $assistant = self::client_label($grant);
        if (in_array($row['tool'], ['upload_media', 'upload_image_file', 'update_media', 'fill_media_text'], true) || !empty(Registry::get((string) $row['tool'])['generic'])) {
            self::generic_request($row, $assistant);
            echo '</div>';
            return;
        }
        $args = json_decode((string) $row['args']);
        $updating = $row['tool'] === 'update_content';
        $scheduled = !$updating && isset($args->publish_at);
        $post = is_object($args) && is_int($args->id ?? null) ? get_post($args->id) : null;
        $title = $post ? ($post->post_title !== '' ? $post->post_title : __('(no title)', 'gpt3-ai-content-generator')) : __('this post', 'gpt3-ai-content-generator');
        $current_status = $post ? (get_post_status_object($post->post_status)->label ?? $post->post_status) : __('Post no longer exists', 'gpt3-ai-content-generator');
        $close = sprintf(
            /* translators: %s: assistant name, such as "ChatGPT". */
            __('You can close this tab and return to %s.', 'gpt3-ai-content-generator'),
            $assistant
        );
        $close .= ' ' . sprintf(
            /* translators: %s: the post's current status. */
            __('Current post status: %s.', 'gpt3-ai-content-generator'), $current_status
        );
        $dashboard = [__('Back to dashboard', 'gpt3-ai-content-generator'), admin_url(), false];
        $edit = $post ? [__('Open post', 'gpt3-ai-content-generator'), get_edit_post_link($post->ID, 'raw'), false] : null;

        switch ($row['state']) {
            case 'pending_approval':
                self::approval($row, $post, $assistant);
                break;
            case 'succeeded':
                self::message('yes-alt', $updating ? __('Content updated', 'gpt3-ai-content-generator') : ($scheduled ? __('Publication scheduled', 'gpt3-ai-content-generator') : __('Publication completed', 'gpt3-ai-content-generator')), sprintf(
                    /* translators: 1: post title, 2: assistant name. */
                    $updating ? __('This request updated “%1$s”. %2$s can check the result.', 'gpt3-ai-content-generator') : ($scheduled ? __('This request scheduled “%1$s” for publication. %2$s can check the result.', 'gpt3-ai-content-generator') : __('This request published “%1$s”. %2$s can check the result.', 'gpt3-ai-content-generator')),
                    $title,
                    ucfirst($assistant)
                ), array_filter([$post && $post->post_status === 'publish' ? [__('View post', 'gpt3-ai-content-generator'), get_permalink($post), true] : $edit, $dashboard]), $close, 'ok');
                break;
            case 'declined':
                self::message('dismiss', __('Request declined', 'gpt3-ai-content-generator'), __('This request did not make the requested change.', 'gpt3-ai-content-generator'), array_filter([$edit, $dashboard]), $close, 'muted');
                break;
            case 'expired':
                self::message('clock', __('This request expired', 'gpt3-ai-content-generator'), sprintf(
                    /* translators: %s: assistant name. */
                    __('Approvals stay open for 15 minutes. This request did not make the requested change. Ask %s to check its current status.', 'gpt3-ai-content-generator'),
                    $assistant
                ), array_filter([$edit, $dashboard]), $close);
                break;
            case 'failed':
            case 'conflict':
                self::message('warning', __('The change didn’t complete', 'gpt3-ai-content-generator'), (Activity::failure((array) json_decode((string) $row['response'], true))['message'] ?? null) ?: sprintf(
                    /* translators: %s: assistant name. */
                    __('This request did not make the requested change. The content may have changed since %s asked. Ask it to check its current status.', 'gpt3-ai-content-generator'),
                    $assistant
                ), array_filter([$edit, $dashboard]), $close);
                break;
            default:
                self::message('warning', __('We couldn’t confirm the result', 'gpt3-ai-content-generator'), __('The change was interrupted. Check the content, then record what happened so it never runs twice.', 'gpt3-ai-content-generator'), [[__('Review the change', 'gpt3-ai-content-generator'), self::review_url(), true], $dashboard]);
        }
        echo '</div>';
    }

    private static function approval(array $row, ?\WP_Post $post, string $assistant): void
    {
        // The preview is what the acting user may do now; an approver sees the same limits.
        $preview = Registry::as_actor($row, static function () use ($row) { return call_user_func(Registry::get($row['tool'])['prepare'], json_decode((string) $row['args'])); });
        if ($preview['state'] !== 'pending_approval' || !$post) {
            self::message('edit', __('The post changed', 'gpt3-ai-content-generator'), sprintf(
                /* translators: %s: assistant name. */
                __('The post was edited or is no longer a draft since %s asked. This request has not published it. Ask your assistant to check its current status.', 'gpt3-ai-content-generator'),
                $assistant
            ), array_filter([$post ? [__('Open the post', 'gpt3-ai-content-generator'), get_edit_post_link($post->ID, 'raw'), false] : null, [__('Back to dashboard', 'gpt3-ai-content-generator'), admin_url(), false]]));
            return;
        }
        $updating = $row['tool'] === 'update_content';
        $scheduled = !$updating && !empty($preview['preview']['scheduled']);
        $user = get_userdata((int) $row['acting_user']);
        $minutes = max(1, (int) ceil(((int) $row['expires_at'] - time()) / 60));
        $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
        $excerpt = wp_trim_words(wp_strip_all_tags($post->post_excerpt !== '' ? $post->post_excerpt : strip_shortcodes($post->post_content)), 32);
        $author = get_userdata((int) $post->post_author);
        ?>
        <div class="aipkit_mcp_card">
            <div class="aipkit_mcp_card_head">
                <span class="dashicons dashicons-format-chat aipkit_mcp_card_icon" aria-hidden="true"></span>
                <div>
                    <h1 class="aipkit_mcp_title"><?php echo esc_html(sprintf(
                        /* translators: %s: assistant name, such as "ChatGPT". */
                        $updating ? __('%s wants to update content', 'gpt3-ai-content-generator') : ($scheduled ? __('%s wants to schedule publication', 'gpt3-ai-content-generator') : __('%s wants to publish content', 'gpt3-ai-content-generator')),
                        ucfirst($assistant)
                    )); ?></h1>
                    <p class="aipkit_mcp_meta"><?php echo esc_html(sprintf(
                        /* translators: 1: WordPress user name, 2: time since the request, such as "2 mins". */
                        __('Asked as %1$s · %2$s ago', 'gpt3-ai-content-generator'),
                        $user ? $user->user_login : '—',
                        human_time_diff((int) $row['created_at'])
                    )); ?></p>
                </div>
                <span class="aipkit_mcp_pill"><?php echo esc_html(sprintf(
                    /* translators: %s: minutes left to approve. */
                    _n('Expires in %s min', 'Expires in %s min', $minutes, 'gpt3-ai-content-generator'),
                    number_format_i18n($minutes)
                )); ?></span>
            </div>
            <div class="aipkit_mcp_post">
                <h2><?php echo esc_html($post->post_title !== '' ? $post->post_title : __('(no title)', 'gpt3-ai-content-generator')); ?></h2>
                <?php if ($excerpt !== '') : ?><p class="aipkit_mcp_meta"><?php echo esc_html($excerpt); ?></p><?php endif; ?>
                <dl class="aipkit_mcp_kv">
                    <dt><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></dt><dd><?php
                        $status_label = get_post_status_object($post->post_status)->label ?? $post->post_status;
                        echo esc_html($updating ? $status_label : sprintf(
                            /* translators: 1: current content status, 2: status after approval. */
                            __('%1$s → %2$s', 'gpt3-ai-content-generator'),
                            $status_label, $scheduled ? __('Scheduled', 'gpt3-ai-content-generator') : __('Published', 'gpt3-ai-content-generator')
                        ));
                    ?></dd>
                    <?php if ($categories) : ?><dt><?php esc_html_e('Category', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html(implode(', ', $categories)); ?></dd><?php endif; ?>
                    <dt><?php esc_html_e('Author', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html($author ? $author->display_name : '—'); ?></dd>
                    <dt><?php esc_html_e('Last edited', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html(get_the_modified_date(get_option('date_format') . ' ' . get_option('time_format'), $post)); ?></dd>
                </dl>
                <?php if ($scheduled) : ?><p class="aipkit_mcp_meta"><?php echo esc_html(sprintf(
                    /* translators: %s: scheduled publication date in UTC. */
                    __('Publishes at %s UTC', 'gpt3-ai-content-generator'), $preview['preview']['publication_date_gmt'])); ?></p><?php endif; ?>
                <?php if ($updating) : ?><p class="aipkit_mcp_meta"><?php esc_html_e('Proposed changes', 'gpt3-ai-content-generator'); ?></p><?php self::content_changes($preview['preview']['changes']); ?><?php endif; ?>
                <p class="aipkit_mcp_links">
                    <a href="<?php echo esc_url($preview['preview']['preview_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Preview the post', 'gpt3-ai-content-generator'); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                    <a href="<?php echo esc_url(get_edit_post_link($post->ID, 'raw')); ?>"><?php esc_html_e('Open in editor', 'gpt3-ai-content-generator'); ?></a>
                </p>
            </div>
            <p class="aipkit_mcp_note"><?php echo esc_html($updating ? __('Approving saves the changes and uses 1 website action. The publication status stays unchanged.', 'gpt3-ai-content-generator') : ($scheduled ? __('Approving schedules publication and uses 1 website action. WordPress publishes it at the chosen time.', 'gpt3-ai-content-generator') : __('Publishing makes this content public right away and uses 1 website action. Nothing changes if you decline.', 'gpt3-ai-content-generator'))); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aipkit_mcp_actions">
                <input type="hidden" name="action" value="aipkit_mcp_confirm">
                <input type="hidden" name="operation_id" value="<?php echo esc_attr($row['operation_id']); ?>">
                <?php wp_nonce_field('aipkit_mcp_confirm_' . $row['operation_id']); ?>
                <button type="submit" class="aipkit_usage_btn aipkit_usage_btn--primary" name="decision" value="publish"><?php echo esc_html($updating ? __('Approve changes', 'gpt3-ai-content-generator') : ($scheduled ? __('Schedule', 'gpt3-ai-content-generator') : __('Publish', 'gpt3-ai-content-generator'))); ?></button>
                <button type="submit" class="aipkit_usage_btn" name="decision" value="decline"><?php esc_html_e('Decline', 'gpt3-ai-content-generator'); ?></button>
            </form>
            <p class="aipkit_mcp_meta"><?php esc_html_e('Didn’t expect this? Decline, then pause changes in Settings › Connections › Manage my website.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <?php
    }

    private static function content_changes(array $fields): void
    {
        $labels = ['post_title' => __('Title', 'gpt3-ai-content-generator'), 'post_content' => __('Content', 'gpt3-ai-content-generator'),
            'post_excerpt' => __('Excerpt', 'gpt3-ai-content-generator'), 'post_name' => __('URL slug', 'gpt3-ai-content-generator')];
        foreach ($fields as $name => $value) {
            if ($name === 'tax_input') {
                foreach ($value as $taxonomy => $ids) {
                    $names = [];
                    foreach ($ids as $id) { $term = get_term($id, $taxonomy); $names[] = $term && !is_wp_error($term) ? $term->name : (string) $id; }
                    echo '<p class="aipkit_mcp_meta">' . esc_html($taxonomy === 'category' ? __('Categories', 'gpt3-ai-content-generator') : __('Tags', 'gpt3-ai-content-generator')) . '</p><p>' . esc_html(implode(', ', $names)) . '</p>';
                }
            } elseif ($name === 'meta_input' && isset($value['_thumbnail_id'])) {
                $image = (int) $value['_thumbnail_id'];
                echo '<p class="aipkit_mcp_meta">' . esc_html(__('Featured image', 'gpt3-ai-content-generator')) . '</p>';
                echo '<p>' . esc_html($image > 0 ? get_the_title($image) : __('Remove featured image', 'gpt3-ai-content-generator')) . '</p>';
                if ($image > 0) { echo wp_kses_post(wp_get_attachment_image($image, 'thumbnail')); }
            } elseif (isset($labels[$name])) {
                echo '<p class="aipkit_mcp_meta">' . esc_html($labels[$name]) . '</p><pre class="aipkit_mcp_code">' . esc_html($value) . '</pre>';
            }
        }
    }

    /** What a generic request asks for, in a few words: the ability's name, or the REST method and route. */
    private static function generic_subject(array $row): string
    {
        $args = json_decode((string) $row['args']);
        if (!is_object($args)) { return '—'; }
        if (in_array($row['tool'], ['upload_media', 'upload_image_file', 'update_media', 'fill_media_text'], true)) { return $row['tool'] . (isset($args->id) ? ' #' . (int) $args->id : ''); }
        if ($row['tool'] === 'run_ability') { return is_string($args->name ?? null) ? $args->name : '—'; }
        return strtoupper(is_string($args->method ?? null) ? $args->method : '') . ' ' . (is_string($args->route ?? null) ? $args->route : '');
    }

    /** Approval and outcome pages for ability and REST changes: the same flow as publishing, with a generic preview. */
    private static function generic_request(array $row, string $assistant): void
    {
        $dashboard = [__('Back to dashboard', 'gpt3-ai-content-generator'), admin_url(), false];
        /* translators: %s: assistant name, such as "ChatGPT". */
        $close = sprintf(__('You can close this tab and return to %s.', 'gpt3-ai-content-generator'), $assistant);
        switch ($row['state']) {
            case 'pending_approval':
                break;
            case 'succeeded':
                /* translators: %s: assistant name. */
                self::message('yes-alt', __('Change completed', 'gpt3-ai-content-generator'), sprintf(__('The change ran on this site. %s can check the result.', 'gpt3-ai-content-generator'), ucfirst($assistant)), [$dashboard], $close, 'ok');
                return;
            case 'declined':
                self::message('dismiss', __('Request declined', 'gpt3-ai-content-generator'), __('Nothing was changed.', 'gpt3-ai-content-generator'), [$dashboard], $close, 'muted');
                return;
            case 'expired':
                /* translators: %s: assistant name. */
                self::message('clock', __('This request expired', 'gpt3-ai-content-generator'), sprintf(__('Approvals stay open for 15 minutes. Nothing was changed. Ask %s to check its current status.', 'gpt3-ai-content-generator'), $assistant), [$dashboard], $close);
                return;
            case 'failed':
            case 'conflict':
                /* translators: %s: assistant name. */
                self::message('warning', __('The change didn’t complete', 'gpt3-ai-content-generator'), (Activity::failure((array) json_decode((string) $row['response'], true))['message'] ?? null) ?: sprintf(__('The site refused it when it ran, so nothing was changed. Ask %s to check its current status.', 'gpt3-ai-content-generator'), $assistant), [$dashboard], $close);
                return;
            default:
                self::message('warning', __('We couldn’t confirm the result', 'gpt3-ai-content-generator'), __('The change was interrupted. Check the site, then record what happened so it never runs twice.', 'gpt3-ai-content-generator'), [[__('Review the change', 'gpt3-ai-content-generator'), self::review_url(), true], $dashboard]);
                return;
        }
        // What the acting user may do now; the change is checked again when it runs.
        $preview = Registry::as_actor($row, static function () use ($row) { return call_user_func(Registry::get($row['tool'])['prepare'], json_decode((string) $row['args'])); });
        if ($preview['state'] !== 'pending_approval') {
            /* translators: %s: assistant name. */
            self::message('warning', __('This change can’t run now', 'gpt3-ai-content-generator'), sprintf(__('The site would refuse it: the WordPress user it runs as may no longer be allowed, or what it changes is no longer available. Nothing was changed. Ask %s to check its current status.', 'gpt3-ai-content-generator'), $assistant), [$dashboard]);
            return;
        }
        $item = $preview['preview'];
        $content_set = ($item['name'] ?? '') === 'ai-puffer/content-apply-changes';
        $user = get_userdata((int) $row['acting_user']);
        $minutes = max(1, (int) ceil(((int) $row['expires_at'] - time()) / 60));
        ?>
        <div class="aipkit_mcp_card">
            <div class="aipkit_mcp_card_head">
                <span class="dashicons dashicons-format-chat aipkit_mcp_card_icon" aria-hidden="true"></span>
                <div>
                    <h1 class="aipkit_mcp_title"><?php echo esc_html(sprintf(
                        /* translators: %s: assistant name, such as "ChatGPT". */
                        __('%s wants to make a change', 'gpt3-ai-content-generator'),
                        ucfirst($assistant)
                    )); ?></h1>
                    <p class="aipkit_mcp_meta"><?php echo esc_html(sprintf(
                        /* translators: 1: WordPress user name, 2: time since the request, such as "2 mins". */
                        __('Asked as %1$s · %2$s ago', 'gpt3-ai-content-generator'),
                        $user ? $user->user_login : '—',
                        human_time_diff((int) $row['created_at'])
                    )); ?></p>
                </div>
                <span class="aipkit_mcp_pill"><?php echo esc_html(sprintf(
                    /* translators: %s: minutes left to approve. */
                    _n('Expires in %s min', 'Expires in %s min', $minutes, 'gpt3-ai-content-generator'),
                    number_format_i18n($minutes)
                )); ?></span>
            </div>
            <div class="aipkit_mcp_post">
                <h2><?php echo esc_html($item['title']); ?></h2>
                <?php if (($item['description'] ?? '') !== '') : ?><p class="aipkit_mcp_meta"><?php echo esc_html($item['description']); ?></p><?php endif; ?>
                <dl class="aipkit_mcp_kv">
                    <dt><?php esc_html_e('Type', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html($item['kind'] === 'media' ? __('Image alt text', 'gpt3-ai-content-generator') : ($item['kind'] === 'ability' ? __('Ability', 'gpt3-ai-content-generator') : __('REST request', 'gpt3-ai-content-generator'))); ?></dd>
                    <?php if (($item['name'] ?? '') !== '') : ?><dt><?php esc_html_e('Name', 'gpt3-ai-content-generator'); ?></dt><dd><code><?php echo esc_html($item['name']); ?></code></dd><?php endif; ?>
                    <dt><?php esc_html_e('Effect', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html($item['risk'] === 'destructive' ? __('May delete or replace something', 'gpt3-ai-content-generator') : __('Changes the site', 'gpt3-ai-content-generator')); ?></dd>
                    <dt><?php esc_html_e('Runs as', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html($user ? $user->user_login : '—'); ?></dd>
                </dl>
                <?php if ($content_set) : ?>
                    <?php foreach (json_decode($item['input'], true) as $edit) : ?>
                        <h3><a href="<?php echo esc_url($edit['edit_url']); ?>"><?php echo esc_html($edit['title'] . ' (#' . $edit['id'] . ')'); ?></a></h3>
                        <dl class="aipkit_mcp_kv">
                        <?php foreach ($edit['changes'] as $field => $value) :
                            $labels = ['post_title' => __('New title', 'gpt3-ai-content-generator'), 'post_excerpt' => __('New excerpt', 'gpt3-ai-content-generator'), 'post_content' => __('New content', 'gpt3-ai-content-generator'), 'post_name' => __('New slug', 'gpt3-ai-content-generator'), 'tax_input' => __('Categories and tags', 'gpt3-ai-content-generator'), 'meta_input' => __('Featured image', 'gpt3-ai-content-generator')]; ?>
                            <dt><?php echo esc_html($labels[$field] ?? $field); ?></dt><dd><?php echo esc_html(is_array($value) ? wp_json_encode($value) : (string) $value); ?></dd>
                        <?php endforeach; ?>
                        </dl>
                    <?php endforeach; ?>
                <?php elseif (($item['input'] ?? '') !== '') : ?>
                    <p class="aipkit_mcp_meta"><?php esc_html_e('What it sends', 'gpt3-ai-content-generator'); ?></p>
                    <pre class="aipkit_mcp_code"><?php echo esc_html($item['input']); ?></pre>
                <?php endif; ?>
            </div>
            <p class="aipkit_mcp_note"><?php esc_html_e('Approving runs this change right away with that WordPress user’s permissions and uses 1 website action. Nothing changes if you decline.', 'gpt3-ai-content-generator'); ?></p>
            <?php if ($row['tool'] !== 'update_media' && !$content_set) : ?>
            <?php // Honest about generic changes: the request is what the assistant sent, the effect is up to the plugin. ?>
            <p class="aipkit_mcp_meta"><?php esc_html_e('This request goes to another plugin’s code. AI Puffer can show what is sent, but not what that plugin will do with it. Approve only a change you expect.', 'gpt3-ai-content-generator'); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aipkit_mcp_actions">
                <input type="hidden" name="action" value="aipkit_mcp_confirm">
                <input type="hidden" name="operation_id" value="<?php echo esc_attr($row['operation_id']); ?>">
                <?php wp_nonce_field('aipkit_mcp_confirm_' . $row['operation_id']); ?>
                <button type="submit" class="aipkit_usage_btn aipkit_usage_btn--primary" name="decision" value="approve"><?php esc_html_e('Approve', 'gpt3-ai-content-generator'); ?></button>
                <button type="submit" class="aipkit_usage_btn" name="decision" value="decline"><?php esc_html_e('Decline', 'gpt3-ai-content-generator'); ?></button>
            </form>
            <p class="aipkit_mcp_meta"><?php esc_html_e('Didn’t expect this? Decline, then pause changes in Settings › Connections › Manage my website.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <?php
    }

    public static function render_review(): void
    {
        echo '<div class="wrap aipkit_mcp_screen"><hr class="wp-header-end">';
        self::notice();
        if (!\WPAICG\Cloud\Connection::allowed()) {
            self::message('lock', __('You can’t review website changes', 'gpt3-ai-content-generator'), __('Ask a site administrator to review interrupted changes.', 'gpt3-ai-content-generator'), [[__('Back to dashboard', 'gpt3-ai-content-generator'), admin_url(), true]]);
            echo '</div>';
            return;
        }
        if (!Identity::active() || !Schema::ready()) {
            self::message('warning', __('Review is unavailable', 'gpt3-ai-content-generator'), __('Connect website management and check the site’s storage before reviewing retained requests. No result has been inferred.', 'gpt3-ai-content-generator'), [[__('Open Settings', 'gpt3-ai-content-generator'), admin_url('admin.php?page=wpaicg&aipkit_module=settings&aipkit_settings_page=connections'), true]]);
            echo '</div>';
            return;
        }
        $rows = self::needs_review();
        global $wpdb;
        if ($wpdb->last_error !== '') {
            self::message('warning', __('Review is unavailable', 'gpt3-ai-content-generator'), __('The website could not read the request records. Try again before recording a result.', 'gpt3-ai-content-generator'), [[__('Try again', 'gpt3-ai-content-generator'), self::review_url(), true]]);
            echo '</div>';
            return;
        }
        if (!$rows) {
            self::message('yes-alt', __('No interrupted changes to review', 'gpt3-ai-content-generator'), __('Requests still running are not listed here. If a request stops without a confirmed result, it appears here after 10 minutes.', 'gpt3-ai-content-generator'), [[__('Back to dashboard', 'gpt3-ai-content-generator'), admin_url(), false]], '', 'ok');
            echo '</div>';
            return;
        }
        foreach (array_slice($rows, 0, 20) as $row) {
            self::review_card($row);
        }
        echo '</div>';
    }

    private static function review_card(array $row): void
    {
        if (in_array($row['tool'], ['upload_media', 'upload_image_file', 'update_media', 'fill_media_text'], true) || !empty(Registry::get((string) $row['tool'])['generic'])) {
            self::generic_review_card($row);
            return;
        }
        $assistant = ucfirst(self::client_label(Store::grant($row['grant_id'])));
        $user = get_userdata((int) $row['acting_user']);
        $args = json_decode((string) $row['args']);
        $target = null;
        if ($row['tool'] === 'publish_content' && is_object($args) && is_int($args->id ?? null)) {
            $target = get_post($args->id);
        }
        $when = wp_date(get_option('time_format'), (int) $row['created_at']);
        $summary = $row['tool'] === 'publish_content'
            /* translators: 1: assistant name, 2: post title, 3: time, 4: WordPress user. */
            ? sprintf(__('%1$s asked to publish “%2$s” at %3$s, as %4$s.', 'gpt3-ai-content-generator'), $assistant, $target ? $target->post_title : '—', $when, $user ? $user->user_login : '—')
            /* translators: 1: assistant name, 2: time, 3: WordPress user. */
            : sprintf(__('%1$s asked to create a draft at %2$s, as %3$s.', 'gpt3-ai-content-generator'), $assistant, $when, $user ? $user->user_login : '—');
        // Posts the owner can point to; the handler re-validates the choice.
        $candidates = [];
        $searched_operation = isset($_GET['operation_id']) && is_string($_GET['operation_id']) ? sanitize_text_field(wp_unslash($_GET['operation_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only candidate search.
        $search = isset($_GET['find_post']) && is_string($_GET['find_post']) && $searched_operation === $row['operation_id'] ? substr(sanitize_text_field(wp_unslash($_GET['find_post'])), 0, 200) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only candidate search.
        $page = isset($_GET['candidate_page']) && is_string($_GET['candidate_page']) && $searched_operation === $row['operation_id'] ? max(1, min(10000, absint(wp_unslash($_GET['candidate_page'])))) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
        $more = false;
        if ($row['tool'] === 'create_draft') {
            $candidates = get_posts([
                'post_type' => 'post',
                'post_status' => ['draft', 'pending', 'publish', 'future', 'private'],
                'author' => (int) $row['acting_user'],
                // Drafts have no GMT date in WordPress, so compare local post dates.
                'date_query' => [['after' => wp_date('Y-m-d H:i:s', (int) $row['created_at'] - 300), 'column' => 'post_date', 'inclusive' => true]],
                'orderby' => 'date',
                'order' => 'ASC',
                's' => $search,
                'offset' => ($page - 1) * 5,
                'numberposts' => 6,
            ]);
            $more = count($candidates) > 5;
            $candidates = array_slice($candidates, 0, 5);
        } elseif ($target && $target->post_status === 'publish') {
            $candidates = [$target];
        }
        $form_id = 'aipkit_mcp_review_' . substr(md5($row['operation_id']), 0, 8);
        ?>
        <div class="aipkit_mcp_card">
            <div class="aipkit_mcp_card_head">
                <span class="dashicons dashicons-warning aipkit_mcp_card_icon is-warning" aria-hidden="true"></span>
                <div>
                    <h1 class="aipkit_mcp_title"><?php esc_html_e('Check an interrupted change', 'gpt3-ai-content-generator'); ?></h1>
                    <p class="aipkit_mcp_meta"><?php echo esc_html($summary . ' ' . __('The website did not confirm whether this change completed.', 'gpt3-ai-content-generator')); ?></p>
                </div>
            </div>
            <p class="aipkit_mcp_meta"><?php esc_html_e('Look at your posts, then tell us what happened. This keeps the request from running twice. Assistants can’t answer this for you.', 'gpt3-ai-content-generator'); ?></p>
            <?php if ($row['tool'] === 'create_draft') : ?>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="aipkit_mcp_actions">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::REVIEW_PAGE); ?>">
                    <input type="hidden" name="operation_id" value="<?php echo esc_attr($row['operation_id']); ?>">
                    <label><?php esc_html_e('Find the post', 'gpt3-ai-content-generator'); ?> <input type="search" name="find_post" maxlength="200" value="<?php echo esc_attr($search); ?>"></label>
                    <button class="aipkit_usage_btn" type="submit"><?php esc_html_e('Search', 'gpt3-ai-content-generator'); ?></button>
                    <?php foreach (array_filter(['previous' => $page > 1 ? $page - 1 : 0, 'next' => $more ? $page + 1 : 0]) as $direction => $target_page) : ?>
                        <a class="aipkit_usage_btn" href="<?php echo esc_url(add_query_arg(['operation_id' => $row['operation_id'], 'find_post' => $search, 'candidate_page' => $target_page], self::review_url())); ?>"><?php echo esc_html($direction === 'next' ? __('Next posts', 'gpt3-ai-content-generator') : __('Previous posts', 'gpt3-ai-content-generator')); ?></a>
                    <?php endforeach; ?>
                </form>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aipkit_mcp_review" id="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="action" value="aipkit_mcp_resolve">
                <input type="hidden" name="operation_id" value="<?php echo esc_attr($row['operation_id']); ?>">
                <?php wp_nonce_field('aipkit_mcp_resolve_' . $row['operation_id']); ?>
                <fieldset class="aipkit_mcp_option">
                    <legend class="screen-reader-text"><?php esc_html_e('What happened', 'gpt3-ai-content-generator'); ?></legend>
                    <div class="aipkit_mcp_choice">
                        <input type="radio" id="<?php echo esc_attr($form_id); ?>_done" name="decision" value="succeeded" <?php disabled(!$candidates); ?> <?php checked((bool) $candidates); ?>>
                        <span>
                            <label for="<?php echo esc_attr($form_id); ?>_done"><strong><?php echo esc_html($row['tool'] === 'publish_content' ? __('It was published', 'gpt3-ai-content-generator') : __('It was created. This is the post:', 'gpt3-ai-content-generator')); ?></strong></label>
                            <?php if (!$candidates) : ?>
                                <span class="aipkit_mcp_meta"><?php echo esc_html($row['tool'] === 'publish_content' ? __('The post isn’t published.', 'gpt3-ai-content-generator') : __('No matching posts on this page. Search again or check another page.', 'gpt3-ai-content-generator')); ?></span>
                            <?php else : ?>
                                <span class="aipkit_mcp_candidates">
                                    <?php foreach ($candidates as $index => $candidate) : ?>
                                        <label><input type="radio" name="post_id" value="<?php echo esc_attr((string) $candidate->ID); ?>" <?php checked($index, 0); ?>> <?php echo esc_html(sprintf(
                                            /* translators: 1: post title, 2: post status, 3: time. */
                                            __('“%1$s” · %2$s · %3$s', 'gpt3-ai-content-generator'),
                                            $candidate->post_title !== '' ? $candidate->post_title : __('(no title)', 'gpt3-ai-content-generator'),
                                            get_post_status_object($candidate->post_status)->label ?? $candidate->post_status,
                                            get_the_date(get_option('time_format'), $candidate)
                                        )); ?> · <a href="<?php echo esc_url(get_edit_post_link($candidate->ID, 'raw')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open', 'gpt3-ai-content-generator'); ?></a></label>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="aipkit_mcp_choice">
                        <input type="radio" id="<?php echo esc_attr($form_id); ?>_none" name="decision" value="not_run" <?php checked(!$candidates); ?>>
                        <span>
                            <label for="<?php echo esc_attr($form_id); ?>_none"><strong><?php esc_html_e('It didn’t run', 'gpt3-ai-content-generator'); ?></strong></label>
                            <span class="aipkit_mcp_meta"><?php echo esc_html($row['tool'] === 'publish_content' ? __('The post is still not published. Your assistant can safely try again.', 'gpt3-ai-content-generator') : __('No new post appeared. Your assistant can safely try again.', 'gpt3-ai-content-generator')); ?></span>
                        </span>
                    </div>
                </fieldset>
                <label class="aipkit_mcp_confirm"><input type="checkbox" name="confirm_reviewed" value="yes" required> <?php esc_html_e('I checked the website before choosing', 'gpt3-ai-content-generator'); ?></label>
                <div class="aipkit_mcp_actions">
                    <button type="submit" class="aipkit_usage_btn aipkit_usage_btn--primary"><?php esc_html_e('Record result', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    /** An interrupted ability or REST change: the owner checks the site and records whether it went through. */
    private static function generic_review_card(array $row): void
    {
        $assistant = ucfirst(self::client_label(Store::grant($row['grant_id'])));
        $user = get_userdata((int) $row['acting_user']);
        $form_id = 'aipkit_mcp_review_' . substr(md5($row['operation_id']), 0, 8);
        $progress = Content::recorded_progress($row);
        $completed = Content::has_completed_items($row);
        $item_states = ['succeeded' => __('Completed', 'gpt3-ai-content-generator'), 'not_started' => __('Not started', 'gpt3-ai-content-generator'), 'conflict' => __('Changed elsewhere', 'gpt3-ai-content-generator'), 'failed' => __('Refused', 'gpt3-ai-content-generator')];
        /* translators: 1: assistant name, 2: ability name or REST method and route, 3: time, 4: WordPress user. */
        $summary = sprintf(__('%1$s asked to run %2$s at %3$s, as %4$s.', 'gpt3-ai-content-generator'), $assistant, self::generic_subject($row), wp_date(get_option('time_format'), (int) $row['created_at']), $user ? $user->user_login : '—');
        ?>
        <div class="aipkit_mcp_card">
            <div class="aipkit_mcp_card_head">
                <span class="dashicons dashicons-warning aipkit_mcp_card_icon is-warning" aria-hidden="true"></span>
                <div>
                    <h1 class="aipkit_mcp_title"><?php esc_html_e('Check an interrupted change', 'gpt3-ai-content-generator'); ?></h1>
                    <p class="aipkit_mcp_meta"><?php echo esc_html($summary . ' ' . __('The website did not confirm whether this change completed.', 'gpt3-ai-content-generator')); ?></p>
                </div>
            </div>
            <p class="aipkit_mcp_meta"><?php esc_html_e('Check the site, then tell us what happened. This keeps the request from running twice. Assistants can’t answer this for you.', 'gpt3-ai-content-generator'); ?></p>
            <?php if ($progress !== null) : ?>
                <p class="aipkit_mcp_meta"><?php esc_html_e('Completed edits stay saved. Check every item below; this request will not run again.', 'gpt3-ai-content-generator'); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Post or page', 'gpt3-ai-content-generator'); ?></th><th><?php esc_html_e('Last recorded result', 'gpt3-ai-content-generator'); ?></th></tr></thead>
                    <tbody><?php foreach ($progress['items'] as $item) : ?>
                        <tr><td><a href="<?php echo esc_url(admin_url('post.php?post=' . absint($item['id']) . '&action=edit')); ?>"><?php echo esc_html((string) absint($item['id'])); ?></a></td><td><?php echo esc_html($item_states[$item['state']] ?? __('Needs checking', 'gpt3-ai-content-generator')); ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aipkit_mcp_review" id="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="action" value="aipkit_mcp_resolve">
                <input type="hidden" name="operation_id" value="<?php echo esc_attr($row['operation_id']); ?>">
                <?php wp_nonce_field('aipkit_mcp_resolve_' . $row['operation_id']); ?>
                <fieldset class="aipkit_mcp_option">
                    <legend class="screen-reader-text"><?php esc_html_e('What happened', 'gpt3-ai-content-generator'); ?></legend>
                    <div class="aipkit_mcp_choice">
                        <input type="radio" id="<?php echo esc_attr($form_id); ?>_done" name="decision" value="succeeded">
                        <span><label for="<?php echo esc_attr($form_id); ?>_done"><strong><?php echo esc_html($progress !== null ? __('Keep the completed edits and close this request', 'gpt3-ai-content-generator') : __('It went through', 'gpt3-ai-content-generator')); ?></strong></label>
                        <span class="aipkit_mcp_meta"><?php echo esc_html($progress !== null ? __('I checked each item. Any remaining edits need a new preview with current versions.', 'gpt3-ai-content-generator') : __('The change is on the site.', 'gpt3-ai-content-generator')); ?></span></span>
                    </div>
                    <div class="aipkit_mcp_choice">
                        <input type="radio" id="<?php echo esc_attr($form_id); ?>_none" name="decision" value="not_run" <?php checked(!$completed); ?> <?php disabled($completed); ?>>
                        <span><label for="<?php echo esc_attr($form_id); ?>_none"><strong><?php esc_html_e('It didn’t run', 'gpt3-ai-content-generator'); ?></strong></label>
                        <span class="aipkit_mcp_meta"><?php echo esc_html($completed ? __('Unavailable: at least one edit is already recorded as completed.', 'gpt3-ai-content-generator') : __('Nothing changed. Your assistant can safely try again.', 'gpt3-ai-content-generator')); ?></span></span>
                    </div>
                </fieldset>
                <label class="aipkit_mcp_confirm"><input type="checkbox" name="confirm_reviewed" value="yes" required> <?php esc_html_e('I checked the website before choosing', 'gpt3-ai-content-generator'); ?></label>
                <div class="aipkit_mcp_actions">
                    <button type="submit" class="aipkit_usage_btn aipkit_usage_btn--primary"><?php esc_html_e('Record result', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    /** Shared result and error layout: icon, title, one sentence, next steps. */
    private static function message(string $icon, string $title, string $text, array $actions, string $footnote = '', string $tone = 'warning'): void
    {
        ?>
        <div class="aipkit_mcp_card aipkit_mcp_card--message">
            <span class="aipkit_mcp_badge is-<?php echo esc_attr($tone); ?>"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span></span>
            <h1 class="aipkit_mcp_title"><?php echo esc_html($title); ?></h1>
            <p class="aipkit_mcp_meta"><?php echo esc_html($text); ?></p>
            <?php if ($actions) : ?>
                <p class="aipkit_mcp_actions">
                    <?php foreach ($actions as [$label, $url, $primary]) : ?>
                        <a class="aipkit_usage_btn<?php echo $primary ? ' aipkit_usage_btn--primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if ($footnote !== '') : ?><p class="aipkit_mcp_meta"><?php echo esc_html($footnote); ?></p><?php endif; ?>
        </div>
        <?php
    }

    /** One-line feedback after a POST redirect. */
    private static function notice(): void
    {
        $key = isset($_GET['notice']) && is_string($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        $messages = [
            'recorded' => [__('Review recorded. Return to your assistant to check the change.', 'gpt3-ai-content-generator'), 'success'],
            'busy' => [__('The website is busy. Try again in a moment.', 'gpt3-ai-content-generator'), 'error'],
            'paused' => [__('Website changes are paused or access has ended. Resume in Settings › Connections › Manage my website.', 'gpt3-ai-content-generator'), 'error'],
            'write_check_unavailable' => [__('WordPress could not check its change history. Nothing ran. Check database availability and try again.', 'gpt3-ai-content-generator'), 'error'],
            'review_first' => [__('A previous change needs your review before another change can run. Reads still work.', 'gpt3-ai-content-generator'), 'error'],
            'handled' => [__('This request was already handled.', 'gpt3-ai-content-generator'), 'error'],
            'changed' => [__('This change was updated meanwhile. Check it again.', 'gpt3-ai-content-generator'), 'error'],
            'pick_post' => [__('Choose the post that was created or published, and confirm you checked it.', 'gpt3-ai-content-generator'), 'error'],
            'confirm_reviewed' => [__('Confirm that you checked the website first.', 'gpt3-ai-content-generator'), 'error'],
            'connect' => [__('Website management isn’t connected. Connect it again in Settings › Connections.', 'gpt3-ai-content-generator'), 'error'],
            'invalid' => [__('Something went wrong. Reload the page and try again.', 'gpt3-ai-content-generator'), 'error'],
        ];
        if (!isset($messages[$key])) { return; }
        [$text, $type] = $messages[$key];
        if ($key === 'review_first') {
            echo '<div class="notice notice-error"><p>' . esc_html($text) . ' <a href="' . esc_url(self::review_url()) . '">' . esc_html__('Review the change', 'gpt3-ai-content-generator') . '</a></p></div>';
            return;
        }
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($text) . '</p></div>';
    }
}
