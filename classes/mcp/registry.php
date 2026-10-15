<?php
/**
 * Every operation an assistant can run on this site, declared once.
 *
 * An operation names its domain (checked against Guard), its risk, and its handlers. The risk decides which safety
 * levels may use it and whether it waits for the owner's approval; the WordPress user's own capabilities, checked by
 * each handler, always remain the ceiling. The executor, the approval screen, the journal and the report sent to the
 * bridge all read this list, so a new operation is one entry here plus its handler.
 *
 * Generic operations (the abilities and REST engines) run other plugins' code, whose effects can't be verified from
 * outside: a generic change needs the publish scope (it may publish). Standard asks; owner-authorized Trusted runs it.
 * Each call's own risk (Engines::risk) can only raise that, to destructive when it may delete.
 */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Registry
{
    /** The operation report the bridge reads (sent with every registration). */
    public const CONTRACT = 2;

    /** Levels an owner can choose. The older content_editor and publisher stay valid for connections that have them. */
    public const LEVELS = ['read_only', 'standard', 'trusted'];
    public const DEFAULT_LEVEL = 'trusted';

    private const LEVEL_SCOPES = [
        'read_only' => ['content:read'],
        'standard' => ['content:read', 'content:write', 'content:publish'],
        'trusted' => ['content:read', 'content:write', 'content:publish'],
        'content_editor' => ['content:read', 'content:write'],
        'publisher' => ['content:read', 'content:write', 'content:publish'],
    ];

    /** Risk → the grant scope it needs (the same mapping as the bridge catalog). */
    private const RISK_SCOPE = ['read' => 'content:read', 'write' => 'content:write', 'publish' => 'content:publish', 'destructive' => 'content:publish'];

    /** How much each level may do, for "raise or lower" decisions. */
    private const LEVEL_RANK = ['read_only' => 0, 'content_editor' => 1, 'standard' => 2, 'publisher' => 2, 'trusted' => 3];

    private static $operations = null;

    /** Stable reporting categories, independent of permissions and website-action prices. */
    private const OPERATION_CATEGORIES = [
        'list_sites' => 'account_checks',
        'get_usage' => 'account_checks',
        'get_operation' => 'account_checks',
        'get_guide' => 'guidance',
        'get_site_overview' => 'site_checks',
        'search_content' => 'content_reads',
        'get_content' => 'content_reads',
        'create_draft' => 'content_creation',
        'update_content' => 'content_updates',
        'publish_content' => 'publishing',
        'search_media' => 'media_reads', 'get_media' => 'media_reads', 'inspect_media' => 'media_reads', 'find_media_missing_text' => 'media_reads',
        'upload_media' => 'media_uploads', 'upload_image_file' => 'media_uploads', 'update_media' => 'media_updates', 'fill_media_text' => 'media_updates',
        'find_abilities' => 'plugin_data_reads',
        'read_ability' => 'plugin_data_reads',
        'read_rest_route' => 'plugin_data_reads',
        'run_ability' => 'plugin_data_changes',
        'write_rest_route' => 'plugin_data_changes',
    ];

    /** Derived from the recorded tool name: historical rows need no migration. Not a billing decision. */
    public static function category(string $tool): string
    {
        return self::OPERATION_CATEGORIES[$tool] ?? 'other';
    }

    /**
     * name => [domain, risk, run, prepare?, approve_cap?, generic?, available?]. run executes the operation; for
     * operations that may wait for approval, prepare validates and returns the preview the owner sees, and run is
     * called again only after approval. available says whether this site can run it (reported to the bridge).
     */
    public static function operations(): array
    {
        if (self::$operations === null) {
            $operations = [
                'get_site_overview' => ['domain' => 'site', 'risk' => 'read', 'run' => [Operations::class, 'site_overview']],
                'search_content' => ['domain' => 'content', 'risk' => 'read', 'run' => [Operations::class, 'search_content']],
                'get_content' => ['domain' => 'content', 'risk' => 'read', 'run' => [Operations::class, 'get_content']],
                'create_draft' => ['domain' => 'content', 'risk' => 'write', 'run' => [Operations::class, 'create_draft']],
                'update_content' => ['domain' => 'content', 'risk' => 'publish', 'run' => [Operations::class, 'update'],
                    'prepare' => [Operations::class, 'update_preview'], 'approve_cap' => 'read'],
                'publish_content' => ['domain' => 'content', 'risk' => 'publish', 'run' => [Operations::class, 'publish'],
                    'prepare' => [Operations::class, 'publish_preview'], 'approve_cap' => 'read'],
                'search_media' => ['domain' => 'media', 'risk' => 'read', 'run' => [Operations::class, 'search_media']],
                'get_media' => ['domain' => 'media', 'risk' => 'read', 'run' => [Operations::class, 'get_media']],
                'inspect_media' => ['domain' => 'media', 'risk' => 'read', 'run' => [Operations::class, 'inspect_media']],
                'find_media_missing_text' => ['domain' => 'media', 'risk' => 'read', 'run' => [Operations::class, 'find_media_missing_text']],
                'fill_media_text' => ['domain' => 'media', 'risk' => 'publish', 'run' => [Operations::class, 'fill_media_text'],
                    'prepare' => [Operations::class, 'media_text_preview'], 'approve_cap' => 'upload_files'],
                'upload_media' => ['domain' => 'media', 'risk' => 'write', 'run' => [Operations::class, 'upload_media']],
                'upload_image_file' => ['domain' => 'media', 'risk' => 'write', 'run' => [Operations::class, 'upload_image_file']],
                'update_media' => ['domain' => 'media', 'risk' => 'publish', 'run' => [Operations::class, 'update_media'],
                    'prepare' => [Operations::class, 'media_preview'], 'approve_cap' => 'upload_files'],
                'find_abilities' => ['domain' => 'abilities', 'risk' => 'read', 'run' => [Engines::class, 'find_abilities'], 'available' => [Engines::class, 'abilities_available']],
                'read_ability' => ['domain' => 'abilities', 'risk' => 'read', 'run' => [Engines::class, 'read_ability'], 'available' => [Engines::class, 'abilities_available']],
                'run_ability' => ['domain' => 'abilities', 'risk' => 'publish', 'generic' => true, 'run' => [Engines::class, 'run_ability'],
                    'prepare' => [Engines::class, 'ability_preview'], 'approve_cap' => 'read', 'available' => [Engines::class, 'abilities_available']],
                'read_rest_route' => ['domain' => 'rest', 'risk' => 'read', 'run' => [Engines::class, 'read_rest_route']],
                'write_rest_route' => ['domain' => 'rest', 'risk' => 'publish', 'generic' => true, 'run' => [Engines::class, 'write_rest_route'],
                    'prepare' => [Engines::class, 'rest_preview'], 'approve_cap' => 'read'],
            ];
            foreach ($operations as $operation) { Guard::assert_domain($operation['domain']); }
            self::$operations = $operations;
        }
        return self::$operations;
    }

    public static function get(string $name): ?array
    {
        return self::operations()[$name] ?? null;
    }

    public static function names(): array
    {
        return array_keys(self::operations());
    }

    /** Operations that change the site: journaled as writes and refused while changes are paused. */
    public static function write_names(): array
    {
        return array_keys(array_filter(self::operations(), static function (array $operation): bool { return $operation['risk'] !== 'read'; }));
    }

    public static function is_write(string $name): bool
    {
        $operation = self::get($name);
        return $operation !== null && $operation['risk'] !== 'read';
    }

    public static function scopes(string $level): array
    {
        if (!isset(self::LEVEL_SCOPES[$level])) { throw new \InvalidArgumentException('invalid_access'); }
        return self::LEVEL_SCOPES[$level];
    }

    /**
     * Whether the connection's level allows this operation's risk at all; with the call's arguments, also the risk of
     * that call (a generic change that deletes needs what destructive needs). A call whose risk can't be read yet is
     * left to its handler, which refuses it with the reason.
     */
    public static function allowed(string $name, array $grant, ?object $args = null): bool
    {
        $operation = self::get($name);
        if ($operation === null || !in_array(self::RISK_SCOPE[$operation['risk']], (array) ($grant['scopes'] ?? []), true)) { return false; }
        $risk = $args !== null && !empty($operation['generic']) ? Engines::risk($name, $args) : null;
        return $risk === null || in_array(self::RISK_SCOPE[$risk], (array) ($grant['scopes'] ?? []), true);
    }

    /**
     * Standard asks before generic changes, including deletes. Trusted runs supported changes unattended only
     * after this site's owner chose the current policy. Existing Trusted grants keep their previous approvals.
     * The handler still validates the request and enforces the acting user's WordPress capabilities.
     */
    public static function needs_approval(string $name, array $grant, ?object $args = null): bool
    {
        $operation = self::get($name);
        if ($operation === null) { return true; }
        if ($operation['risk'] === 'read') { return false; }
        if ($name === 'run_ability' && ($args->name ?? '') === 'ai-puffer/wp-cli-write') {
            $input = is_string($args->input_json ?? null) ? json_decode($args->input_json, true) : [];
            try {
                $plan = Cli::plan($input['command'] ?? '');
                if ($plan['tool'] === 'cli_native') { return ($grant['access_level'] ?? '') !== 'trusted' || empty($grant['unattended']); }
                return self::needs_approval($plan['tool'], $grant, $plan['args']);
            } catch (\InvalidArgumentException $error) { return true; }
        }
        $unattended = ($grant['access_level'] ?? '') === 'trusted' && ($grant['unattended'] ?? false) === true;
        if (!empty($operation['generic']) || $operation['risk'] === 'destructive') { return !$unattended; }
        // Existing published/private/scheduled content changes ask at Standard; edits to drafts run directly.
        if ($name === 'update_content' && ($grant['access_level'] ?? '') !== 'trusted') {
            $post = $args && is_int($args->id ?? null) ? get_post($args->id) : null;
            return !$post || !in_array($post->post_status, ['draft', 'pending'], true);
        }
        return $operation['risk'] === 'publish' && ($grant['access_level'] ?? '') !== 'trusted';
    }

    /** Operations this site can run now, as reported to the bridge. */
    public static function available(): array
    {
        return array_keys(array_filter(self::operations(), static function (array $operation): bool {
            return empty($operation['available']) || (bool) call_user_func($operation['available']);
        }));
    }

    /**
     * Who may approve: someone who could make the change themselves, either the WordPress user the assistant acts as
     * or an administrator (an assistant can act as another user, such as a dedicated editor account). The acting user
     * must still exist and still have the capability: their role stays the ceiling even when someone else approves.
     */
    public static function can_approve(array $row): bool
    {
        $operation = self::get((string) $row['tool']);
        if ($operation === null || empty($operation['prepare'])) { return false; }
        $cap = $operation['approve_cap'] ?? 'manage_options';
        $actor = get_userdata((int) $row['acting_user']);
        if (!$actor || !user_can($actor, $cap) || !current_user_can($cap)) { return false; }
        if (in_array($row['tool'], ['publish_content', 'update_content'], true)) {
            $args = json_decode((string) ($row['args'] ?? '{}'));
            $post = is_int($args->id ?? null) ? get_post($args->id) : null;
            if (!$post || !in_array($post->post_type, ['post', 'page'], true)
                || !user_can($actor, 'edit_post', $post->ID) || !current_user_can('edit_post', $post->ID)) { return false; }
            $publish = $post->post_type === 'page' ? 'publish_pages' : 'publish_posts';
            if (($row['tool'] === 'publish_content' || in_array($post->post_status, ['publish', 'private', 'future'], true))
                && (!user_can($actor, $publish) || !current_user_can($publish))) { return false; }
        }
        if ($row['tool'] === 'update_media') {
            $args = json_decode((string) ($row['args'] ?? '{}'));
            if (!is_int($args->id ?? null) || !user_can($actor, 'edit_post', $args->id) || !current_user_can('edit_post', $args->id)) { return false; }
        }
        return (int) $actor->ID === get_current_user_id() || current_user_can('manage_options');
    }

    /**
     * Runs a handler as the operation's acting user and restores the current user afterwards, however it ends. An
     * approved change then gets exactly the acting user's permissions, never the approver's.
     */
    public static function as_actor(array $row, callable $work)
    {
        $before = get_current_user_id();
        $actor = get_userdata((int) $row['acting_user']);
        if (!$actor) { return Operations::error('permission_denied'); }
        wp_set_current_user($actor->ID);
        try { return $work(); }
        finally { wp_set_current_user($before); }
    }

    /** True when $to lets an assistant do more than $from. */
    public static function raises(string $from, string $to): bool
    {
        return (self::LEVEL_RANK[$to] ?? 0) > (self::LEVEL_RANK[$from] ?? 0);
    }

    public static function level_label(string $level): string
    {
        $labels = [
            'read_only' => __('Read only', 'gpt3-ai-content-generator'),
            'standard' => __('Standard', 'gpt3-ai-content-generator'),
            'trusted' => __('Trusted', 'gpt3-ai-content-generator'),
            'content_editor' => __('Drafts only', 'gpt3-ai-content-generator'),
            'publisher' => __('Standard', 'gpt3-ai-content-generator'),
        ];
        return $labels[$level] ?? $level;
    }

    /** What each level lets an assistant do, in the owner's words. */
    public static function level_help(string $level): string
    {
        $help = [
            'read_only' => __('Reads the site. Never changes anything.', 'gpt3-ai-content-generator'),
            'standard' => __('Reads the site and writes drafts. Asks you before publishing or any other change.', 'gpt3-ai-content-generator'),
            'trusted' => __('Runs supported website changes without asking, including publishing, plugin changes and deletion. Only use with an assistant you trust.', 'gpt3-ai-content-generator'),
            'content_editor' => __('Reads the site and writes drafts. Can’t publish.', 'gpt3-ai-content-generator'),
            'publisher' => __('Reads the site and writes drafts. Asks you before publishing or any other change.', 'gpt3-ai-content-generator'),
        ];
        return $help[$level] ?? '';
    }

    /** What the bridge is told this plugin can run, and a fingerprint so a plugin update re-registers once. */
    public static function report(): array
    {
        return self::available();
    }

    public static function fingerprint(): string
    {
        return WPAICG_VERSION . '|' . self::CONTRACT . '|' . substr(hash('sha256', implode(',', self::report())), 0, 16);
    }

    /** Autoloaded copy of the last fingerprint the bridge accepted; empty when website management is off. */
    public const REPORTED_OPTION = 'aipkit_mcp_reported';
    public const REFRESH_HOOK = 'aipkit_mcp_refresh_registration';
    private const REFRESH_ATTEMPTS = 'aipkit_mcp_refresh_attempts';
    /** Waits between attempts after a failed report: bounded, then it stops until the next update or registration. */
    private const REFRESH_BACKOFF = [10 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS];

    /**
     * After a plugin update the bridge still has the old operation list. Any request notices the fingerprint changed
     * (one autoloaded option) and queues one background registration; a completed update queues it too, so this works
     * without anyone opening wp-admin. Registration keeps the site, its address and its connections.
     */
    public static function register_hooks(): void
    {
        add_action(self::REFRESH_HOOK, [self::class, 'refresh_registration']);
        add_action('init', [self::class, 'maybe_schedule_refresh']);
        add_action('upgrader_process_complete', [self::class, 'after_update'], 10, 2);
    }

    /** True when a registration reported an older operation list than this code has. */
    public static function report_outdated(): bool
    {
        $reported = self::reported();
        return $reported !== '' && $reported !== self::fingerprint();
    }

    /**
     * What this site last told the bridge; empty when it isn't connected. A site connected before this marker existed
     * gets it once from the connection itself: connected, it reports on its next request; otherwise it records that
     * it has nothing to report.
     */
    private static function reported(): string
    {
        $reported = get_option(self::REPORTED_OPTION, null);
        if (is_string($reported)) { return $reported; }
        $reported = Identity::active() ? ((string) (Store::config()['reported'] ?? '') ?: 'unreported') : '';
        update_option(self::REPORTED_OPTION, $reported, true);
        return $reported;
    }

    /** Requests queue the report only while attempts remain, so an outage never turns into a retry per request. */
    public static function maybe_schedule_refresh(): void
    {
        if (self::report_outdated() && (int) get_option(self::REFRESH_ATTEMPTS, 0) < count(self::REFRESH_BACKOFF)) { self::schedule_refresh(0); }
    }

    /** The update just replaced this plugin's files; the new code reports from the queued event. */
    public static function after_update($upgrader, $extra): void
    {
        $plugins = is_array($extra) ? (array) ($extra['plugins'] ?? []) : [];
        $self = defined('WPAICG_PLUGIN_DIR') ? plugin_basename(WPAICG_PLUGIN_DIR . 'gpt3-ai-content-generator.php') : '';
        $mine = ($extra['type'] ?? '') === 'plugin' && ($self === '' || in_array($self, $plugins, true) || in_array('gpt3-ai-content-generator-premium/gpt3-ai-content-generator.php', $plugins, true));
        if ($mine && self::reported() !== '') {
            update_option(self::REFRESH_ATTEMPTS, 0, false); // A new version earns a fresh set of attempts.
            self::schedule_refresh(30);
        }
    }

    /** One queued event at a time. */
    private static function schedule_refresh(int $delay): void
    {
        if (!wp_next_scheduled(self::REFRESH_HOOK)) { wp_schedule_single_event(time() + $delay, self::REFRESH_HOOK); }
    }

    public static function refresh_registration(): void
    {
        try {
            if (!Identity::active()) { update_option(self::REPORTED_OPTION, '', true); update_option(self::REFRESH_ATTEMPTS, 0, false); return; }
            if ((Store::config()['reported'] ?? '') === self::fingerprint() && !self::report_outdated()) { update_option(self::REFRESH_ATTEMPTS, 0, false); return; }
            $config = Store::config();
            $result = BridgeClient::register(['account_id' => $config['account_id'], 'site_id' => $config['site_id']]);
            if (!is_wp_error($result)) { update_option(self::REFRESH_ATTEMPTS, 0, false); return; }
        } catch (\Throwable $error) {
            // Counted as a failed attempt below.
        }
        $attempts = (int) get_option(self::REFRESH_ATTEMPTS, 0);
        if ($attempts < count(self::REFRESH_BACKOFF)) {
            update_option(self::REFRESH_ATTEMPTS, $attempts + 1, false);
            wp_schedule_single_event(time() + self::REFRESH_BACKOFF[$attempts], self::REFRESH_HOOK);
        }
    }
}
