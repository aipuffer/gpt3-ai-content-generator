<?php
/** WP-CLI syntax adapter. One command uses WordPress APIs and the shared MCP lifecycle, never a shell or binary. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Cli
{
    private static function count_changed(string $code, int $expected, int $found): array
    {
        $unit = $code === 'replacement_count_changed' ? 'replacements' : 'matching rows';
        $out = Operations::error($code, sprintf('Expected %d %s; found %d. Nothing changed. Read a fresh dry run with the same scope and paging before applying; do not remove the count guard.', $expected, $unit, $found));
        $out['operation_diagnostics'] = ['count_kind' => $code === 'replacement_count_changed' ? 'replacements' : 'matching_rows', 'expected_count' => $expected, 'found_count' => $found];
        return $out;
    }

    private const READ = 'ai-puffer/wp-cli-read';
    private const WRITE = 'ai-puffer/wp-cli-write';
    /** Command => [read, capability, minimum/maximum positional arguments, accepted flags]. */
    private const NATIVE = [
        'plugin status' => [true, 'activate_plugins', 0, 1, []],
        'plugin get' => [true, 'activate_plugins', 1, 1, []],
        'theme status' => [true, 'switch_themes', 0, 1, []],
        'theme get' => [true, 'switch_themes', 1, 1, []],
        'post-type list' => [true, 'edit_posts', 0, 0, []],
        'post type list' => [true, 'edit_posts', 0, 0, []],
        'taxonomy list' => [true, 'edit_posts', 0, 0, ['object_type']],
        'post delete' => [false, 'delete_posts', 1, 1, ['force']],
        'post meta get' => [true, 'read', 2, 2, []],
        'post meta list' => [true, 'read', 1, 1, ['keys']],
        'post meta add' => [false, 'edit_posts', 3, 3, ['force']],
        'post meta update' => [false, 'edit_posts', 3, 3, ['force']],
        'post meta delete' => [false, 'edit_posts', 2, 3, ['force']],
        'post term set' => [false, 'edit_posts', 2, 20, ['by']],
        'post term add' => [false, 'edit_posts', 3, 20, ['by']],
        'post term remove' => [false, 'edit_posts', 3, 20, ['by']],
        'comment list' => [true, 'moderate_comments', 0, 0, ['post_id', 'status', 'number', 'offset', 'search']],
        'comment count' => [true, 'moderate_comments', 0, 0, ['post_id']],
        'comment create' => [false, 'moderate_comments', 0, 0, ['comment_post_ID', 'comment_content', 'comment_author', 'comment_author_email', 'comment_approved', 'comment_parent']],
        'comment approve' => [false, 'moderate_comments', 1, 1, []],
        'comment unapprove' => [false, 'moderate_comments', 1, 1, []],
        'comment spam' => [false, 'moderate_comments', 1, 1, []],
        'comment unspam' => [false, 'moderate_comments', 1, 1, []],
        'comment trash' => [false, 'moderate_comments', 1, 1, []],
        'comment untrash' => [false, 'moderate_comments', 1, 1, []],
        'comment delete' => [false, 'moderate_comments', 1, 1, ['force']],
        'cli version' => [true, 'read', 0, 0, []],
        'cli info' => [true, 'read', 0, 0, []],
        'media image-size' => [true, 'upload_files', 0, 0, []],
        'db query' => [false, 'manage_options', 1, 1, ['expected-count']],
        'db tables' => [true, 'manage_options', 0, 0, []],
        'db prefix' => [true, 'manage_options', 0, 0, []],
        'db columns' => [true, 'manage_options', 1, 1, []],
        'config get' => [true, 'manage_options', 1, 1, []],
        'search-replace' => [false, 'manage_options', 2, 20, ['dry-run', 'include-columns', 'skip-columns', 'limit', 'after-id', 'expected-count']],
        'plugin search' => [true, 'install_plugins', 1, 1, ['per-page', 'page']],
        'plugin install' => [false, 'install_plugins', 1, 1, ['version', 'activate']],
        'plugin activate' => [false, 'activate_plugins', 1, 1, []],
        'plugin deactivate' => [false, 'activate_plugins', 1, 1, []],
        'plugin update' => [false, 'update_plugins', 1, 1, []],
        'plugin uninstall' => [false, 'delete_plugins', 1, 1, ['deactivate', 'skip-delete']],
        'plugin verify-checksums' => [true, 'update_plugins', 1, 1, []],
        'plugin auto-updates status' => [true, 'update_plugins', 1, 1, []],
        'plugin auto-updates enable' => [false, 'update_plugins', 1, 1, []],
        'plugin auto-updates disable' => [false, 'update_plugins', 1, 1, []],
        'theme install' => [false, 'install_themes', 1, 1, ['version', 'activate']],
        'theme activate' => [false, 'switch_themes', 1, 1, []],
        'theme update' => [false, 'update_themes', 1, 1, []],
        'theme delete' => [false, 'delete_themes', 1, 1, []],
        'core check-update' => [true, 'update_core', 0, 0, []],
        'core update' => [false, 'update_core', 0, 0, []],
        'core update-db' => [false, 'update_core', 0, 0, []],
        'core verify-checksums' => [true, 'update_core', 0, 0, []],
        'maintenance-mode status' => [true, 'manage_options', 0, 0, []],
        'cache type' => [true, 'manage_options', 0, 0, []],
        'cache flush' => [false, 'manage_options', 0, 0, []],
        'cache purge' => [false, 'manage_options', 0, 0, []],
        'litespeed-purge' => [false, 'manage_options', 0, 0, []],
        'elementor flush-css' => [false, 'manage_options', 0, 0, []],
        'elementor flush_css' => [false, 'manage_options', 0, 0, []],
        'rocket clean' => [false, 'manage_options', 0, 0, []],
        'sg purge' => [false, 'manage_options', 0, 0, []],
        'super-cache flush' => [false, 'manage_options', 0, 0, []],
        'w3-total-cache flush' => [false, 'manage_options', 0, 0, []],
        'breeze purge' => [false, 'manage_options', 0, 0, []],
        'rewrite list' => [true, 'manage_options', 0, 0, ['match']],
        'rewrite flush' => [false, 'manage_options', 0, 0, []],
        'rewrite structure' => [false, 'manage_options', 1, 1, ['category-base', 'tag-base']],
        'cron event list' => [true, 'manage_options', 0, 0, ['hook']],
        'cron event run' => [false, 'manage_options', 1, 1, []],
        'cron event delete' => [false, 'manage_options', 1, 1, []],
        'cron test' => [true, 'manage_options', 0, 0, []],
        'user list' => [true, 'list_users', 0, 0, ['role', 'search', 'number', 'offset']],
        'user get' => [true, 'list_users', 1, 1, []],
        'user create' => [false, 'create_users', 2, 2, ['role', 'display_name', 'first_name', 'last_name']],
        'user update' => [false, 'edit_users', 1, 1, ['display_name', 'first_name', 'last_name', 'user_email', 'user_url', 'nickname', 'description', 'role']],
        'user delete' => [false, 'delete_users', 1, 1, ['reassign']],
        'user set-role' => [false, 'promote_users', 2, 2, []],
        'user add-role' => [false, 'promote_users', 2, 2, []],
        'user remove-role' => [false, 'promote_users', 2, 2, []],
        'user add-cap' => [false, 'promote_users', 2, 20, []],
        'user remove-cap' => [false, 'promote_users', 2, 20, []],
        'user meta get' => [true, 'list_users', 2, 2, []],
        'user meta list' => [true, 'list_users', 1, 1, ['keys']],
        'user meta add' => [false, 'edit_users', 3, 3, []],
        'user meta update' => [false, 'edit_users', 3, 3, []],
        'user meta delete' => [false, 'edit_users', 2, 3, []],
        'role list' => [true, 'list_users', 0, 0, []],
        'role create' => [false, 'promote_users', 1, 2, ['clone']],
        'role delete' => [false, 'promote_users', 1, 1, []],
        'role reset' => [false, 'promote_users', 1, 1, []],
        'cap list' => [true, 'list_users', 1, 1, []],
        'cap add' => [false, 'promote_users', 2, 20, ['grant']],
        'cap remove' => [false, 'promote_users', 2, 20, []],
        'option get' => [true, 'manage_options', 1, 1, []],
        'option pluck' => [true, 'manage_options', 2, 20, []],
        'option list' => [true, 'manage_options', 0, 0, ['search', 'autoload']],
        'option add' => [false, 'manage_options', 2, 2, ['autoload']],
        'option update' => [false, 'manage_options', 2, 2, ['autoload']],
        'option delete' => [false, 'manage_options', 1, 1, []],
        'option patch' => [false, 'manage_options', 3, 20, []],
        'transient get' => [true, 'manage_options', 1, 1, []],
        'transient list' => [true, 'manage_options', 0, 0, ['search']],
        'transient delete' => [false, 'manage_options', 1, 1, []],
        'theme mod list' => [true, 'edit_theme_options', 0, 0, []],
        'theme mod get' => [true, 'edit_theme_options', 1, 1, []],
        'theme mod set' => [false, 'edit_theme_options', 2, 2, []],
        'menu list' => [true, 'edit_theme_options', 0, 0, []],
        'menu create' => [false, 'edit_theme_options', 1, 1, []],
        'menu item list' => [true, 'edit_theme_options', 1, 1, []],
        'menu item add-custom' => [false, 'edit_theme_options', 3, 3, ['position', 'parent-id', 'description', 'attr-title', 'target', 'classes', 'xfn']],
        'menu item add-post' => [false, 'edit_theme_options', 2, 2, ['title', 'position', 'parent-id']],
        'menu item add-term' => [false, 'edit_theme_options', 3, 3, ['title', 'position', 'parent-id']],
        'menu item update' => [false, 'edit_theme_options', 1, 1, ['title', 'url', 'position', 'parent-id', 'description', 'attr-title', 'target', 'classes', 'xfn']],
        'menu item delete' => [false, 'edit_theme_options', 1, 1, []],
        'menu location list' => [true, 'edit_theme_options', 0, 0, []],
        'menu location assign' => [false, 'edit_theme_options', 2, 2, []],
        'sidebar list' => [true, 'edit_theme_options', 0, 0, []],
        'widget list' => [true, 'edit_theme_options', 1, 1, []],
    ];
    private const HELP = 'WP-CLI-compatible commands run through WordPress APIs, without shell access or a WP-CLI binary. Use wp help: additional_commands lists exact native command names, permissions, positional argument counts and flags; flags lists content/media/term adapters. One command per child operation. Use start_fleet_job for ordered background commands across explicit connected sites, get_fleet_job for progress, and cancel_fleet_job to stop commands not yet started. JSON is default; ids/count apply to returned list pages. Post creates are drafts. Read a post/media version before editing and pass --expected-version. Publication is separate from content edits. Settings values are literal strings by default. On option add/update/patch and theme mod set, --format=json decodes the input value; use --format=plaintext for literal text. Example: wp option patch update my_option layout color green --format=plaintext. db query SELECT/COUNT uses the read ability; INSERT/UPDATE/DELETE uses the write ability. Database reads support single tables or up to four INNER/LEFT joined tables, COUNT/SUM/AVG/MIN/MAX, selected pure functions, OR/IN/BETWEEN and expressions. Use wp db columns TABLE to discover custom-plugin columns. Joins use authorized/redacted snapshots capped at1000 rows/table and4MiB; narrow single-table reads for larger data. Core changes use typed WordPress APIs. Custom-table INSERT/UPDATE/DELETE uses atomic InnoDB batches of at most100 matching rows; WHERE is required, and optional --expected-count binds the match count. No DDL, credentials, connection-control tables, filesystem SQL or executable functions. Search-replace --dry-run uses the read ability; live changes use the write ability. Configuration inspection returns non-secret flags only. Unlisted commands are not implemented.';

    public static function register_abilities(): void
    {
        if (!function_exists('wp_register_ability')) { return; }
        foreach ([self::READ => true, self::WRITE => false] as $name => $read) {
            wp_register_ability($name, [
                'label' => $read ? 'WP-CLI: read' : 'WP-CLI: change',
                'description' => ($read ? 'Run a read-only WP-CLI-compatible command. ' : 'Run one supported WP-CLI-compatible website change. Same user permissions and approval policy as website tools. ') . 'Inspect or manage posts, media, comments, menus, options/settings, users, roles/capabilities, cache, cron, rewrite rules, plugins, themes and WordPress core. Software installation and updates use official WordPress.org packages. Use wp help for exact commands/flags and the read/write tool. Quote --expected-version from a fresh post/media read before editing. ' . self::HELP,
                'category' => Abilities::CATEGORY,
                'input_schema' => ['type' => 'object', 'properties' => ['command' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000]], 'required' => ['command'], 'additionalProperties' => false],
                'meta' => ['public' => true, 'annotations' => ['readonly' => $read, 'destructive' => !$read]],
                'permission_callback' => static function (): bool { return current_user_can('read'); },
                'execute_callback' => static function ($input) use ($read) {
                    // Managed changes go through the executor's journal and approval path, not a direct ability callback.
                    if (!$read) { return new \WP_Error('managed_execution_required', 'Use the connected assistant run_ability tool.'); }
                    $out = self::execute((array) $input, true, false);
                    return $out['state'] === 'succeeded' ? json_decode($out['result']['data_json'], true)
                        : new \WP_Error($out['error']['code'] ?? 'command_failed', $out['error']['message'] ?? self::HELP);
                },
            ]);
        }
    }

    /** Tokenization only: quoted text is literal, with no variable expansion, substitution or command execution. */
    private static function tokens(string $command): array
    {
        if ($command === '' || strlen($command) > 12000 || preg_match('/[\x00-\x1f\x7f]/', $command)) { throw new \InvalidArgumentException('Use one command on one line.'); }
        $tokens = []; $token = ''; $quote = ''; $started = false;
        for ($i = 0, $length = strlen($command); $i < $length; $i++) {
            $char = $command[$i];
            if ($char === '\\' && $quote !== "'") {
                if (++$i >= $length) { throw new \InvalidArgumentException('Incomplete escape.'); }
                $token .= $command[$i]; $started = true; continue;
            }
            if ($quote !== '') {
                if ($char === $quote) { $quote = ''; } else { $token .= $char; }
                continue;
            }
            if ($char === '"' || $char === "'") { $quote = $char; $started = true; continue; }
            if (strpos(';&|<>`$()', $char) !== false) { throw new \InvalidArgumentException('Shell operators are not supported. Quote literal text.'); }
            if ($char === ' ') {
                if ($started) { $tokens[] = $token; $token = ''; $started = false; }
            } else { $token .= $char; $started = true; }
            if (count($tokens) > 60) { throw new \InvalidArgumentException('Too many arguments.'); }
        }
        if ($quote !== '') { throw new \InvalidArgumentException('Unclosed quote.'); }
        if ($started) { $tokens[] = $token; }
        if (($tokens[0] ?? '') !== 'wp') { throw new \InvalidArgumentException('Start the command with wp.'); }
        return array_slice($tokens, 1);
    }

    /** Parse and translate only; does not read or mutate the site. Unknown flags never silently disappear. */
    public static function plan(string $command): array
    {
        $tokens = self::tokens($command); $words = []; $flags = [];
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (strncmp($token, '--', 2) !== 0) { $words[] = $token; continue; }
            $parts = explode('=', substr($token, 2), 2);
            $key = $parts[0];
            if (isset($flags[$key]) || !preg_match('/^[a-z][a-zA-Z0-9_-]*$/D', $key)) { throw new \InvalidArgumentException('Invalid or duplicate flag.'); }
            if (in_array($key, ['porcelain', 'force', 'activate', 'deactivate', 'skip-delete', 'dry-run'], true)) {
                if (count($parts) !== 1) { throw new \InvalidArgumentException('Use boolean flags without a value.'); }
                $flags[$key] = true;
            }
            elseif (count($parts) === 2) { $flags[$key] = $parts[1]; }
            elseif (isset($tokens[$index + 1]) && strncmp($tokens[$index + 1], '--', 2) !== 0) { $flags[$key] = $tokens[++$index]; }
            else { throw new \InvalidArgumentException('This flag needs a value. Use --flag=value.'); }
        }
        $porcelain = $flags['porcelain'] ?? false; unset($flags['porcelain']);
        $input_format = $flags['format'] ?? null;
        $format = $input_format ?? 'json'; unset($flags['format']);
        if ($format === 'plaintext' && in_array(implode(' ', array_slice($words, 0, 2)), ['option add', 'option update', 'option patch', 'theme mod'], true)) { $format = 'json'; }
        if (!in_array($format, ['json', 'ids', 'count'], true)) { throw new \InvalidArgumentException('Supported formats: json, ids, count.'); }
        $group = $words[0] ?? ''; $action = $words[1] ?? ''; $tool = ''; $args = []; $read = true;
        $consume = static function (array $mapping) use (&$flags, &$args): void {
            foreach ($mapping as $flag => $field) {
                if (array_key_exists($flag, $flags)) { $args[$field] = $flags[$flag]; unset($flags[$flag]); }
            }
        };
        $positive = static function ($value): int {
            if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value)) { throw new \InvalidArgumentException('Use a positive integer ID or page size.'); }
            return (int) $value;
        };
        // WPVibe's media-list alias maps to the same attachment read, not a second media implementation.
        if ($words === ['media', 'list']) { $words = ['post', 'list']; $group = 'post'; $flags['post_type'] = 'attachment'; }
        if ($words === ['help']) { $tool = 'help'; }
        elseif (in_array($words, [['core', 'version'], ['plugin', 'list'], ['theme', 'list']], true)) { $tool = 'get_site_overview'; }
        elseif (in_array($group, ['post', 'media'], true)) {
            if ($group === 'post' && $action === 'meta' && ($words[4] ?? '') === '_wp_attachment_image_alt'
                && (($words[2] === 'get' && count($words) === 5) || ($words[2] === 'update' && count($words) === 6))) {
                $read = $words[2] === 'get'; $tool = $read ? 'get_media' : 'update_media'; $args['id'] = $positive($words[3]);
                if (!$read) {
                    $args['alt_text'] = $words[5]; $consume(['expected-version' => 'version']);
                    if (!isset($args['version'])) { throw new \InvalidArgumentException('Read the image with wp post get ID, then pass --expected-version=VERSION.'); }
                }
            } elseif ($group === 'media' && $action !== 'import' && !isset(self::NATIVE['media ' . $action])) {
                throw new \InvalidArgumentException('Use wp post list --post_type=attachment, wp post get ID, or wp post meta update ID _wp_attachment_image_alt VALUE.');
            } elseif ($action === 'list' && count($words) === 2) {
                $tool = 'search_content';
                $consume(['s' => 'query', 'post_type' => 'post_type', 'post_status' => 'status', 'paged' => 'page', 'posts_per_page' => 'per_page']);
                if (($args['post_type'] ?? '') === 'attachment') {
                    if (isset($args['status']) && !in_array($args['status'], ['inherit', 'any'], true)) { throw new \InvalidArgumentException('Attachment lists support inherit/any status.'); }
                    $tool = 'search_media'; unset($args['post_type'], $args['status']);
                }
                foreach (['page', 'per_page'] as $field) { if (isset($args[$field])) { $args[$field] = $positive($args[$field]); } }
            } elseif ($action === 'get' && count($words) === 3) {
                $tool = 'get_content'; $args['id'] = $positive($words[2]);
            } elseif ($action === 'create' && count($words) === 2) {
                $tool = 'create_draft'; $read = false;
                if (isset($flags['post_status'])) {
                    if ($flags['post_status'] !== 'draft') { throw new \InvalidArgumentException('Create the draft first, read its version, then publish with post update.'); }
                    unset($flags['post_status']);
                }
                $consume(['post_type' => 'post_type', 'post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt', 'post_name' => 'slug']);
            } elseif ($action === 'update' && count($words) === 3) {
                $read = false; $args['id'] = $positive($words[2]); $consume(['expected-version' => 'version']);
                if (!isset($args['version'])) { throw new \InvalidArgumentException('Read with wp post get ID, then pass its version as --expected-version=VERSION.'); }
                if (isset($flags['post_status'])) {
                    if (!in_array($flags['post_status'], ['publish', 'future'], true)) { throw new \InvalidArgumentException('Only publication/scheduling is supported here; ordinary updates keep the current status.'); }
                    $tool = 'publish_content';
                    if ($flags['post_status'] === 'future' && !isset($flags['publish-at'])) { throw new \InvalidArgumentException('Scheduling requires --publish-at=ISO_DATE_WITH_TIMEZONE.'); }
                    unset($flags['post_status']); $consume(['publish-at' => 'publish_at']);
                } else {
                    $tool = 'update_content';
                    $consume(['post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt', 'post_name' => 'slug']);
                }
            } elseif ($group === 'media' && $action === 'import' && count($words) === 3) {
                $tool = 'upload_media'; $read = false; $args['url'] = $words[2];
                $consume(['title' => 'title', 'alt' => 'alt_text']);
            }
            if (in_array($tool, ['create_draft', 'update_content'], true)) {
                foreach (['post_category' => 'categories', 'tags_input' => 'tags'] as $flag => $field) {
                    if (isset($flags[$flag])) { $args[$field] = $flags[$flag] === '' ? [] : array_map($positive, explode(',', $flags[$flag])); unset($flags[$flag]); }
                }
                if (isset($flags['featured_media'])) { $args['featured_media'] = $flags['featured_media'] === '0' ? 0 : $positive($flags['featured_media']); unset($flags['featured_media']); }
            }
        } elseif ($group === 'term' && in_array($words[2] ?? '', ['category', 'post_tag'], true)) {
            $route = '/wp/v2/' . ($words[2] === 'category' ? 'categories' : 'tags'); $body = [];
            if ($action === 'list' && count($words) === 3) {
                $tool = 'read_rest_route'; $consume(['search' => 'search', 'page' => 'page', 'number' => 'per_page']);
                foreach (['page', 'per_page'] as $field) { if (isset($args[$field])) { $args[$field] = $positive($args[$field]); } }
                $args = ['query_json' => wp_json_encode((object) $args)];
            } elseif (in_array($action, ['get', 'update', 'delete'], true) && count($words) === 4) {
                $route .= '/' . $positive($words[3]); $read = $action === 'get';
                $tool = $read ? 'read_rest_route' : 'write_rest_route';
                if (!$read) { $args['method'] = $action === 'delete' ? 'DELETE' : 'POST'; }
            } elseif ($action === 'create' && count($words) === 4) {
                $read = false; $tool = 'write_rest_route'; $args['method'] = 'POST'; $body['name'] = $words[3];
            }
            if (!$read && $action !== 'delete') {
                foreach (['name', 'slug', 'description', 'parent'] as $field) {
                    if (!isset($flags[$field])) { continue; }
                    if ($field === 'name' && isset($body['name'])) { throw new \InvalidArgumentException('Specify a term name once.'); }
                    $body[$field] = $field === 'parent' ? ($flags[$field] === '0' ? 0 : $positive($flags[$field])) : $flags[$field]; unset($flags[$field]);
                }
            }
            if ($action === 'delete' && !$read) { $body['force'] = true; }
            if ($tool !== '') { $args['route'] = $route; if ($body) { $args['body_json'] = wp_json_encode($body); } }
        }
        if ($tool === '') {
            foreach (self::NATIVE as $key => [$native_read, $cap, $min, $max, $accepted]) {
                $prefix = explode(' ', $key);
                if (array_slice($words, 0, count($prefix)) !== $prefix) { continue; }
                $positionals = array_slice($words, count($prefix));
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Accepted flags are static metadata, returned in a signed JSON error rather than HTML.
                if (count($positionals) < $min || count($positionals) > $max || array_diff(array_keys($flags), $accepted)) { throw new \InvalidArgumentException('Invalid arguments. Supported flags: ' . implode(', ', $accepted)); }
                $tool = 'cli_native'; $read = $native_read;
                $args = ['key' => $key, 'positionals' => $positionals, 'flags' => $flags, 'cap' => $cap, 'input_format' => $input_format]; $flags = []; break;
            }
        }
        if ($tool === 'cli_native' && ($args['key'] === 'db query' || $args['key'] === 'search-replace')) { $read = $args['key'] === 'db query' ? (bool) preg_match('/^\s*SELECT\b/i', $args['positionals'][0]) : !empty($args['flags']['dry-run']); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static help is returned in signed JSON; HTML views escape it at rendering time.
        if ($tool === '') { throw new \InvalidArgumentException('This command or positional argument is not implemented. ' . self::HELP); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Flag names match the ASCII allowlist above; this is a JSON error, not HTML output.
        if ($flags) { throw new \InvalidArgumentException('Unsupported flag(s): ' . implode(', ', array_keys($flags)) . '. Use wp help.'); }
        if ($format !== 'json' && $action !== 'list' && !($tool === 'cli_native' && substr($args['key'], -5) === ' list')) { throw new \InvalidArgumentException('ids/count formats apply to lists only.'); }
        if ($porcelain && !($action === 'create' || $tool === 'upload_media')) { throw new \InvalidArgumentException('porcelain is supported only for create/import commands.'); }
        return ['tool' => $tool, 'args' => (object) $args, 'read' => $read, 'format' => $format, 'group' => $group, 'porcelain' => $porcelain];
    }

    public static function dispatch($result, string $name, $input, bool $preview)
    {
        if ($name !== self::WRITE) { return $result; }
        return self::execute((array) $input, false, $preview);
    }

    private static function execute(array $input, bool $read, bool $preview): array
    {
        try {
            $plan = self::plan($input['command'] ?? '');
            if ($read !== $plan['read']) { throw new \InvalidArgumentException($plan['read'] ? 'Use ai-puffer/wp-cli-read.' : 'This command changes the site. Use ai-puffer/wp-cli-write.'); }
            if ($plan['tool'] === 'help') {
                $out = ['state' => 'succeeded', 'actions_used' => 1, 'result' => ['help' => self::HELP,
                    'additional_commands' => self::NATIVE, 'conditional_read_commands' => ['db query' => 'SELECT/COUNT uses wp-cli-read; INSERT/UPDATE/DELETE uses wp-cli-write.', 'search-replace' => '--dry-run uses wp-cli-read; live changes use wp-cli-write.'], 'flags' => ['post list' => 'post_type, post_status, s, paged, posts_per_page', 'post create' => 'post_title, post_content, post_excerpt, post_name, post_type, post_status=draft, post_category/tags_input (term IDs), featured_media', 'post update' => 'quoted expected-version plus post_title/post_content/post_excerpt/post_name/post_category/tags_input/featured_media OR post_status=publish|future, publish-at', 'media import' => 'title, alt', 'post meta update' => 'Attachment alt uses expected-version; ordinary post metadata uses KEY VALUE and optional explicit force for protected keys. Credential and connection metadata is never exposed.', 'term list' => 'search, page, number (1–100)', 'term create/update' => 'name, slug, description, parent'], 'format' => 'json default; ids/count on lists. No implicit shell expansion.']];
            } else {
                if ($plan['tool'] === 'cli_native') {
                    $out = self::native((array) $plan['args'], $preview);
                } else {
                if ($plan['tool'] === 'get_content' && get_post_type($plan['args']->id) === 'attachment') { $plan['tool'] = 'get_media'; }
                $operation = Registry::get($plan['tool']);
                if ($preview) {
                    if (!empty($operation['prepare'])) { return call_user_func($operation['prepare'], $plan['args']); }
                    return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'WP-CLI command', 'name' => self::WRITE, 'description' => 'One supported website command', 'risk' => 'publish', 'input' => $input['command']]];
                }
                $out = call_user_func($operation['run'], $plan['args']);
                }
            }
            if ($out['state'] !== 'succeeded') { return $out; }
            $data = $out['result'];
            if ($plan['tool'] === 'get_site_overview') {
                if ($plan['group'] === 'theme') {
                    $items = [];
                    foreach (wp_get_themes() as $slug => $theme) {
                        $items[] = ['name' => $slug, 'title' => $theme->get('Name'), 'version' => $theme->get('Version'), 'status' => $slug === get_stylesheet() ? 'active' : 'inactive'];
                        if (count($items) === 100) { break; }
                    }
                    $data = ['items' => $items, 'limit' => 100];
                } else { $data = $plan['group'] === 'core' ? ['version' => $data['wordpress_version']] : ['items' => $data['plugins']]; }
            } elseif (isset($data['data_json'])) {
                $decoded = json_decode($data['data_json'], true);
                unset($data['data_json']); $data['data'] = $decoded;
            }
            if ($plan['format'] !== 'json') {
                $items = $data['items'] ?? $data['data'] ?? [];
                if (!is_array($items) || ($items && array_keys($items) !== range(0, count($items) - 1))) { throw new \InvalidArgumentException('This list does not support ids/count.'); }
                $data = ['value' => $plan['format'] === 'count' ? count($items) : array_map(static function ($item) { return $item['id'] ?? $item['file'] ?? $item['name'] ?? ''; }, $items), 'page_only' => true]
                    + array_intersect_key($data, array_flip(['has_more', 'page', 'total', 'total_pages']));
            }
            if ($plan['porcelain']) { $data = ['id' => $data['id'] ?? $data['data']['id'] ?? null]; }
            $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || strlen($json) > 250000) { return Operations::$mutation_started ? self::native_unknown() : Operations::error('result_too_large', 'Narrow the list or read a specific field.'); }
            return ['state' => 'succeeded', 'actions_used' => 1, 'result' => ['data_json' => $json]];
        } catch (\InvalidArgumentException $error) {
            return ['state' => Operations::$mutation_started ? 'unknown' : 'failed', 'actions_used' => 0, 'error' => ['code' => 'unsupported_command', 'message' => $error->getMessage()]];
        } catch (\Throwable $error) {
            return Operations::$mutation_started ? self::native_unknown() : Operations::error('command_unavailable', 'WordPress could not confirm this command. No successful action was recorded.');
        }
    }

    /** Values associated with credentials or this connection's internal state never become assistant input/output. */
    private static function private_key(string $key): bool
    {
        return (bool) preg_match('/(?:password|passwd|secret|token|api.?key|private.?key|auth.?key|auth.?salt|secure_auth|logged_in|nonce.?salt|license|licence|oauth|consumer.?key|access.?key|activation.?key|new_email|two_factor|2fa|totp|webauthn|passkey|backup.?codes|application_password|session|capabilities|user_level|aipkit_mcp|aipkit_cloud|freemius|fs_accounts)/i', $key);
    }

    private static function public_data($value, int $depth = 0)
    {
        if ($depth > 12) { return '[depth limit]'; }
        if (is_string($value) && preg_match('/^\s*[\[{]/', $value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) { $safe = self::public_data($decoded, $depth + 1); return $safe === $decoded ? $value : wp_json_encode($safe); }
        }
        if (is_object($value)) { $value = get_object_vars($value); }
        if (!is_array($value)) { return $value; }
        foreach ($value as $key => $item) { $value[$key] = is_string($key) && self::private_key($key) ? '[redacted]' : self::public_data($item, $depth + 1); }
        return $value;
    }

    private static function native(array $args, bool $preview): array
    {
        $key = $args['key']; $p = $args['positionals']; $f = $args['flags'];
        if (!current_user_can($args['cap'])) { return Operations::error('permission_denied'); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation messages stay JSON text and are escaped by UI renderers.
        $fail = static function (string $message): void { throw new \InvalidArgumentException($message); };
        $id = static function ($value): int {
            if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value) || (string) (int) $value !== $value) { throw new \InvalidArgumentException('Use one valid numeric ID.'); }
            return (int) $value;
        };
        $effect = static function (callable $work) { Operations::$mutation_started = true; return $work(); };
        $ok = static function ($data): array {
            if (is_wp_error($data) || $data === false || $data === null) {
                return ['state' => Operations::$mutation_started ? 'unknown' : 'failed', 'actions_used' => 0,
                    'error' => ['code' => Operations::$mutation_started ? 'outcome_unknown' : 'command_failed', 'message' => is_wp_error($data) ? $data->get_error_message() : 'WordPress did not confirm this command.']];
            }
            return ['state' => 'succeeded', 'actions_used' => 1, 'result' => self::public_data(is_array($data) ? $data : ['value' => $data])];
        };
        $approval = static function () use ($key, $p, $f): array {
            return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'WP-CLI: ' . $key,
                'name' => 'ai-puffer/wp-cli-write', 'description' => 'Run the displayed WordPress command as the connected user.', 'risk' => 'publish',
                'input' => wp_json_encode(['command' => $key, 'arguments' => $p, 'flags' => $f])]];
        };
        if (in_array(explode(' ', $key)[0], ['cache', 'litespeed-purge', 'elementor', 'rocket', 'sg', 'super-cache', 'w3-total-cache', 'breeze', 'rewrite', 'cron'], true)) { return self::maintenance($args, $preview); }
        if (strpos($key, 'user ') === 0 || strpos($key, 'role ') === 0 || strpos($key, 'cap ') === 0) { return self::users($args, $preview); }
        if (strpos($key, 'option ') === 0 || strpos($key, 'transient ') === 0 || strpos($key, 'theme mod ') === 0) { return self::settings($args, $preview); }
        if (strpos($key, 'db ') === 0 || $key === 'config get' || $key === 'search-replace') { return self::database($args, $preview); }
        if (in_array($key, ['plugin search', 'plugin install', 'plugin activate', 'plugin deactivate', 'plugin update', 'plugin uninstall', 'plugin verify-checksums', 'plugin auto-updates status', 'plugin auto-updates enable', 'plugin auto-updates disable', 'theme install', 'theme activate', 'theme update', 'theme delete', 'core check-update', 'core update', 'core update-db', 'core verify-checksums', 'maintenance-mode status'], true)) { return self::administration($args, $preview); }
        if (in_array($key, ['cli version', 'cli info'], true)) { return $ok(['emulator' => 'AI Puffer', 'version' => WPAICG_VERSION, 'shell' => false, 'wp_cli_binary' => false, 'commands' => array_keys(self::NATIVE)]); }
        if (strpos($key, 'plugin ') === 0) {
            if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
            $items = [];
            foreach (get_plugins() as $file => $plugin) {
                $slug = strpos($file, '/') !== false ? dirname($file) : basename($file, '.php');
                if (isset($p[0]) && $p[0] !== $slug && $p[0] !== $file) { continue; }
                $items[] = ['name' => $slug, 'file' => $file, 'title' => $plugin['Name'], 'version' => $plugin['Version'], 'status' => is_plugin_active($file) ? 'active' : 'inactive'];
            }
            return $items || $key === 'plugin status' ? $ok(['items' => $items, 'installed' => (bool) $items]) : Operations::error('plugin_not_found', 'The named plugin is not installed. Use wp plugin search before installing it.');
        }
        if (strpos($key, 'theme ') === 0) {
            $items = [];
            foreach (wp_get_themes() as $slug => $theme) {
                if (isset($p[0]) && $p[0] !== $slug) { continue; }
                $items[] = ['name' => $slug, 'title' => $theme->get('Name'), 'version' => $theme->get('Version'), 'status' => $slug === get_stylesheet() ? 'active' : 'inactive'];
            }
            return $items || $key === 'theme status' ? $ok(['items' => $items, 'installed' => (bool) $items]) : Operations::error('theme_not_found', 'The named theme is not installed.');
        }
        if (in_array($key, ['post-type list', 'post type list', 'taxonomy list'], true)) {
            $items = [];
            $objects = $key === 'taxonomy list' ? get_taxonomies([], 'objects') : get_post_types([], 'objects');
            foreach ($objects as $name => $object) {
                if (isset($f['object_type']) && !in_array($f['object_type'], (array) ($object->object_type ?? []), true)) { continue; }
                $items[] = ['name' => $name, 'label' => $object->label, 'public' => (bool) $object->public, 'show_in_rest' => (bool) $object->show_in_rest];
            }
            return $ok(['items' => $items]);
        }
        if ($key === 'media image-size') { return $ok(['items' => wp_get_registered_image_subsizes()]); }
        if (strpos($key, 'menu ') === 0 || $key === 'sidebar list' || $key === 'widget list') {
            return self::navigation($args, $preview);
        }
        if (strpos($key, 'post ') === 0) {
            $post_id = $id($p[0]); $post = get_post($post_id);
            if (!$post || !current_user_can(strpos($key, 'get') !== false || strpos($key, 'list') !== false ? 'read_post' : 'edit_post', $post_id)) { return Operations::error('permission_denied'); }
            if ($key === 'post delete') {
                if (!current_user_can('delete_post', $post_id) || !in_array($post->post_type, ['post', 'page'], true)) { return Operations::error('permission_denied'); }
                if ($post->post_status === 'trash' && empty($f['force'])) { return Operations::error('already_trashed'); }
                if ($preview) { return $approval(); }
                $deleted = $effect(static function () use ($post_id, $f) { return !empty($f['force']) ? wp_delete_post($post_id, true) : wp_trash_post($post_id); });
                return $deleted ? $ok(['id' => $post_id, 'deleted' => !empty($f['force']), 'status' => get_post_status($post_id) ?: 'deleted']) : $ok(false);
            }
            if (strpos($key, 'post meta ') === 0) {
                $meta = $p[1] ?? ''; $verb = substr($key, 10);
                if ($meta !== '' && self::private_key($meta)) { return Operations::error('private_metadata'); }
                if ($verb === 'list') {
                    $items = []; $keys = isset($f['keys']) ? explode(',', $f['keys']) : array_keys(get_post_meta($post_id));
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- These are response field labels, not WP_Query arguments.
                    foreach ($keys as $name) { if (!self::private_key($name)) { $items[] = ['post_id' => $post_id, 'meta_key' => $name, 'meta_value' => get_post_meta($post_id, $name, false)]; } }
                    return $ok(['items' => $items]);
                }
                if ($meta === '' || strlen($meta) > 200) { $fail('Invalid metadata key.'); }
                if ($verb === 'get') { return $ok(['id' => $post_id, 'key' => $meta, 'value' => get_post_meta($post_id, $meta, true)]); }
                if ($meta[0] === '_' && empty($f['force'])) { $fail('Use the native plugin action for protected metadata, or explicitly pass --force for this metadata key.'); }
                // A registered plugin field may impose stricter authorization than editing its parent post.
                // Explicit --force acknowledges an underscore key; it never overrides the owning plugin's rules.
                $registered = array_merge(get_registered_meta_keys('post'), get_registered_meta_keys('post', $post->post_type));
                $capability = $verb === 'delete' ? 'delete_post_meta' : ($verb === 'add' ? 'add_post_meta' : 'edit_post_meta');
                if (isset($registered[$meta]) && !current_user_can($capability, $post_id, $meta)) { return Operations::error('permission_denied'); }
                if ($verb === 'delete' && (!metadata_exists('post', $post_id, $meta) || (count($p) === 3 && !in_array($p[2], get_post_meta($post_id, $meta, false), true)))) { return Operations::error('metadata_not_found'); }
                if ($preview) { return $approval(); }
                $value = $p[2] ?? '';
                if ($verb === 'add') { return $ok($effect(static function () use ($post_id, $meta, $value) { return add_post_meta($post_id, $meta, wp_slash($value)); })); }
                if ($verb === 'delete') {
                    $effect(static function () use ($post_id, $meta, $p) { return count($p) === 3 ? delete_post_meta($post_id, $meta, $p[2]) : delete_post_meta($post_id, $meta); });
                    return $ok(['id' => $post_id, 'key' => $meta, 'remaining' => get_post_meta($post_id, $meta, false)]);
                }
                $effect(static function () use ($post_id, $meta, $value) { return update_post_meta($post_id, $meta, wp_slash($value)); });
                return get_post_meta($post_id, $meta, true) === $value ? $ok(['id' => $post_id, 'key' => $meta, 'value' => $value]) : $ok(false);
            }
            $taxonomy = get_taxonomy($p[1]);
            if (!$taxonomy || !is_object_in_taxonomy($post->post_type, $p[1]) || !current_user_can($taxonomy->cap->assign_terms)) { return Operations::error('permission_denied'); }
            $by = $f['by'] ?? 'slug'; if (!in_array($by, ['slug', 'id', 'name'], true)) { $fail('by must be slug, id or name.'); }
            $ids = [];
            foreach (array_slice($p, 2) as $value) {
                $term = get_term_by($by, $by === 'id' ? $id($value) : $value, $p[1]);
                if (!$term || is_wp_error($term)) { $fail('The requested term does not exist. Create it first.'); } $ids[] = (int) $term->term_id;
            }
            if ($preview) { return $approval(); }
            $result = $effect(static function () use ($key, $post_id, $p, $ids) {
                return $key === 'post term remove' ? wp_remove_object_terms($post_id, $ids, $p[1]) : wp_set_object_terms($post_id, $ids, $p[1], $key === 'post term add');
            });
            return is_wp_error($result) ? $ok($result) : $ok(['id' => $post_id, 'taxonomy' => $p[1], 'term_ids' => wp_get_object_terms($post_id, $p[1], ['fields' => 'ids'])]);
        }
        if (strpos($key, 'comment ') === 0) {
            if ($key === 'comment list' || $key === 'comment count') {
                foreach (['number', 'offset', 'post_id'] as $field) { if (isset($f[$field]) && !preg_match('/^\d+$/D', $f[$field])) { $fail('Invalid numeric comment filter.'); } }
                if ($key === 'comment count') { return $ok((array) wp_count_comments((int) ($f['post_id'] ?? 0))); }
                $f['number'] = min(100, max(1, (int) ($f['number'] ?? 20))); $items = [];
                foreach (get_comments($f) as $comment) { $items[] = ['id' => (int) $comment->comment_ID, 'post_id' => (int) $comment->comment_post_ID, 'author' => $comment->comment_author, 'content' => $comment->comment_content, 'status' => wp_get_comment_status($comment)]; }
                return $ok(['items' => $items]);
            }
            if ($key === 'comment create') {
                $post_id = $id($f['comment_post_ID'] ?? '');
                if (!get_post($post_id) || !current_user_can('edit_post', $post_id) || empty($f['comment_content'])) { return Operations::error('permission_denied'); }
                if (isset($f['comment_approved']) && !in_array($f['comment_approved'], ['0', '1'], true)) { $fail('comment_approved must be 0 or 1.'); }
                $parent = isset($f['comment_parent']) ? get_comment($id($f['comment_parent'])) : null;
                if (isset($f['comment_parent']) && (!$parent || (int) $parent->comment_post_ID !== $post_id)) { $fail('Parent comment must exist on this post.'); }
                if (isset($f['comment_author_email']) && !is_email($f['comment_author_email'])) { $fail('Use a valid comment author email.'); }
                if ($preview) { return $approval(); }
                $f['user_id'] = get_current_user_id(); $f['comment_content'] = wp_kses_post($f['comment_content']);
                $created = $effect(static function () use ($f) { return wp_insert_comment(wp_slash($f)); });
                return $created ? $ok(['id' => (int) $created, 'status' => wp_get_comment_status($created)]) : $ok(false);
            }
            $comment_id = $id($p[0]); $comment = get_comment($comment_id);
            if (!$comment || !current_user_can('edit_comment', $comment_id)) { return Operations::error('permission_denied'); }
            if ($preview) { return $approval(); }
            $verb = substr($key, 8);
            $result = $effect(static function () use ($verb, $comment_id, $f) {
                switch ($verb) {
                    case 'approve': return wp_set_comment_status($comment_id, 'approve', true);
                    case 'unapprove': return wp_set_comment_status($comment_id, 'hold', true);
                    case 'spam': return wp_spam_comment($comment_id);
                    case 'unspam': return wp_unspam_comment($comment_id);
                    case 'trash': return wp_trash_comment($comment_id);
                    case 'untrash': return wp_untrash_comment($comment_id);
                    case 'delete': return wp_delete_comment($comment_id, !empty($f['force']));
                }
                return false;
            });
            return $result && !is_wp_error($result) ? $ok(['id' => $comment_id, 'status' => wp_get_comment_status($comment_id) ?: 'deleted']) : $ok($result);
        }
        return Operations::error('unsupported_command');
    }

    /** Classic navigation menus. Block-theme wp_navigation documents are a different WordPress interface. */
    private static function navigation(array $args, bool $preview): array
    {
        $key = $args['key']; $p = $args['positionals']; $flags = $args['flags'];
        if (!current_user_can('edit_theme_options')) { return Operations::error('permission_denied'); }
        $ok = static function (array $data): array { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $data]; };
        $pending = ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'WP-CLI: ' . $key,
            'name' => self::WRITE, 'description' => 'Change the selected WordPress navigation menu.', 'risk' => 'publish', 'input' => wp_json_encode(['arguments' => $p, 'flags' => $flags])]];
        if ($key === 'sidebar list' || $key === 'widget list') {
            global $wp_registered_sidebars, $wp_registered_widgets;
            if ($key === 'sidebar list') {
                return $ok(['items' => array_values(array_map(static function ($sidebar) { return array_intersect_key($sidebar, array_flip(['id', 'name', 'description'])); }, (array) $wp_registered_sidebars))]);
            }
            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Read-only widget inspection needs the native runtime mapping, including core filters, without changing its option.
            $sidebars = wp_get_sidebars_widgets();
            if (!isset($wp_registered_sidebars[$p[0]]) && $p[0] !== 'wp_inactive_widgets') { return Operations::error('sidebar_not_found'); }
            $items = [];
            foreach ($sidebars[$p[0]] ?? [] as $position => $widget_id) { $items[] = ['id' => $widget_id, 'name' => $wp_registered_widgets[$widget_id]['name'] ?? $widget_id, 'position' => $position + 1, 'sidebar' => $p[0]]; }
            return $ok(['items' => $items]);
        }
        if ($key === 'menu list') {
            $items = [];
            foreach (wp_get_nav_menus(['number' => 100]) as $menu) { $items[] = ['id' => (int) $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug, 'count' => (int) $menu->count]; }
            return $ok(['items' => $items, 'limit' => 100]);
        }
        if ($key === 'menu location list') {
            $assigned = get_nav_menu_locations(); $items = [];
            foreach (get_registered_nav_menus() as $location => $description) { $items[] = ['name' => $location, 'description' => $description, 'menu_id' => (int) ($assigned[$location] ?? 0)]; }
            return $ok(['items' => $items]);
        }
        if ($key === 'menu create') {
            if (trim($p[0]) === '' || strlen($p[0]) > 200 || wp_get_nav_menu_object($p[0])) { return Operations::error('invalid_or_existing_menu'); }
            if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            $created = wp_create_nav_menu(wp_strip_all_tags($p[0]));
            return is_wp_error($created) ? self::native_unknown() : $ok(['id' => (int) $created, 'name' => $p[0]]);
        }
        $item = null;
        if (in_array($key, ['menu item update', 'menu item delete'], true)) {
            if (!ctype_digit($p[0])) { return Operations::error('invalid_input'); }
            $post = get_post((int) $p[0]);
            if (!$post || $post->post_type !== 'nav_menu_item') { return Operations::error('menu_item_not_found'); }
            $ids = wp_get_object_terms($post->ID, 'nav_menu', ['fields' => 'ids']);
            if (is_wp_error($ids) || count($ids) !== 1) { return Operations::error('menu_item_not_found'); }
            $menu = wp_get_nav_menu_object((int) $ids[0]); $item = wp_setup_nav_menu_item($post);
        } else { $menu = wp_get_nav_menu_object($p[0]); }
        if (!$menu || is_wp_error($menu)) { return Operations::error('menu_not_found'); }
        if ($key === 'menu item list') {
            $items = [];
            foreach (wp_get_nav_menu_items($menu->term_id) ?: [] as $entry) {
                $items[] = ['id' => (int) $entry->ID, 'title' => $entry->title, 'url' => $entry->url, 'type' => $entry->type,
                    'object' => $entry->object, 'object_id' => (int) $entry->object_id, 'parent' => (int) $entry->menu_item_parent, 'position' => (int) $entry->menu_order,
                    'target' => $entry->target, 'classes' => $entry->classes, 'description' => $entry->description];
            }
            return $ok(['items' => $items]);
        }
        if ($key === 'menu location assign') {
            if (!array_key_exists($p[1], get_registered_nav_menus())) { return Operations::error('menu_location_not_registered'); }
            if ($preview) { return $pending; }
            $locations = get_nav_menu_locations(); $locations[$p[1]] = (int) $menu->term_id;
            Operations::$mutation_started = true; set_theme_mod('nav_menu_locations', $locations);
            return (int) (get_nav_menu_locations()[$p[1]] ?? 0) === (int) $menu->term_id ? $ok(['location' => $p[1], 'menu_id' => (int) $menu->term_id]) : self::native_unknown();
        }
        if ($key === 'menu item delete') {
            if ($preview) { return $pending; }
            Operations::$mutation_started = true; $removed = wp_delete_post($item->ID, true);
            return $removed && !get_post($item->ID) ? $ok(['id' => (int) $item->ID, 'deleted' => true]) : self::native_unknown();
        }
        if ($item && $item->type !== 'custom' && isset($flags['url'])) { return Operations::error('menu_url_is_derived', 'Post and term menu URLs follow their source. Change the source URL or use a custom menu link.'); }
        $data = $item ? ['menu-item-type' => $item->type, 'menu-item-object' => $item->object, 'menu-item-object-id' => $item->object_id,
            'menu-item-title' => $item->title, 'menu-item-url' => $item->url, 'menu-item-parent-id' => $item->menu_item_parent,
            'menu-item-position' => $item->menu_order, 'menu-item-attr-title' => $item->attr_title, 'menu-item-description' => $item->description,
            'menu-item-target' => $item->target, 'menu-item-classes' => implode(' ', $item->classes), 'menu-item-xfn' => $item->xfn]
            : ['menu-item-status' => 'publish'];
        if ($key === 'menu item add-custom') {
            $url = esc_url_raw($p[2]);
            if ($url === '') { return Operations::error('invalid_url'); }
            $data += ['menu-item-type' => 'custom', 'menu-item-title' => wp_strip_all_tags($p[1]), 'menu-item-url' => $url];
        } elseif ($key === 'menu item add-post') {
            $post = ctype_digit($p[1]) ? get_post((int) $p[1]) : null;
            if (!$post || !current_user_can('read_post', $post->ID) || $post->post_status === 'trash') { return Operations::error('permission_denied'); }
            $data += ['menu-item-type' => 'post_type', 'menu-item-object' => $post->post_type, 'menu-item-object-id' => $post->ID];
        } elseif ($key === 'menu item add-term') {
            $term = ctype_digit($p[2]) ? get_term((int) $p[2], $p[1]) : null;
            if (!$term || is_wp_error($term) || is_wp_error(get_term_link($term))) { return Operations::error('term_not_found'); }
            $data += ['menu-item-type' => 'taxonomy', 'menu-item-object' => $p[1], 'menu-item-object-id' => $term->term_id];
        }
        foreach ($flags as $field => $value) {
            if (in_array($field, ['position', 'parent-id'], true)) {
                if (!ctype_digit($value)) { return Operations::error('invalid_input'); }
                $value = (int) $value;
                if ($field === 'parent-id' && $value > 0) {
                    $parent_menus = wp_get_object_terms($value, 'nav_menu', ['fields' => 'ids']);
                    if (($item && $value === (int) $item->ID) || is_wp_error($parent_menus) || !in_array((int) $menu->term_id, $parent_menus, true)) { return Operations::error('invalid_menu_parent'); }
                    $seen = []; $ancestor = $value;
                    while ($ancestor) {
                        if (isset($seen[$ancestor]) || ($item && $ancestor === (int) $item->ID)) { return Operations::error('invalid_menu_parent'); }
                        $seen[$ancestor] = true; $ancestor = (int) get_post_meta($ancestor, '_menu_item_menu_item_parent', true);
                    }
                }
            } elseif ($field === 'url') { $value = esc_url_raw($value); if ($value === '') { return Operations::error('invalid_url'); } }
            elseif ($field === 'target' && !in_array($value, ['', '_blank', '_self'], true)) { return Operations::error('invalid_input'); }
            else { $value = sanitize_text_field($value); }
            $data['menu-item-' . $field] = $value;
        }
        if ($preview) { return $pending; }
        Operations::$mutation_started = true;
        $saved = wp_update_nav_menu_item((int) $menu->term_id, $item ? (int) $item->ID : 0, wp_slash($data));
        return is_wp_error($saved) || !$saved ? self::native_unknown() : $ok(['id' => (int) $saved, 'menu_id' => (int) $menu->term_id]);
    }

    private static function writable_option(string $name): bool
    {
        global $wpdb; if (!preg_match('/^[a-zA-Z0-9_.:-]{1,172}$/D', $name)) { return false; } $name = strtolower($name);
        return !self::private_key($name) && !in_array($name, ['active_plugins', 'active_sitewide_plugins', 'cron', 'rewrite_rules', 'stylesheet', 'template', 'users_can_register', 'default_role', 'auto_update_plugins', 'auto_update_themes', 'allowedthemes', 'uninstall_plugins', strtolower($wpdb->prefix . 'user_roles')], true)
            && strpos($name, 'theme_mods_') !== 0 && strpos($name, '_transient_') !== 0 && strpos($name, '_site_transient_') !== 0;
    }

    /** The data-query facade never exposes credential columns or the MCP/Cloud control tables. */
    private static function database_tables(?string $custom = null): array
    {
        global $wpdb;
        $tables = [
            'posts' => ['table' => $wpdb->posts, 'id' => 'ID', 'columns' => ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_type', 'post_parent', 'post_modified', 'guid'], 'search' => ['post_title', 'post_content', 'post_excerpt', 'guid']],
            'postmeta' => ['table' => $wpdb->postmeta, 'id' => 'meta_id', 'columns' => ['meta_id', 'post_id', 'meta_key', 'meta_value'], 'search' => ['meta_value']],
            'comments' => ['table' => $wpdb->comments, 'id' => 'comment_ID', 'columns' => ['comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_date', 'comment_content', 'comment_approved', 'comment_parent', 'user_id'], 'search' => ['comment_author_url', 'comment_content']],
            'commentmeta' => ['table' => $wpdb->commentmeta, 'id' => 'meta_id', 'columns' => ['meta_id', 'comment_id', 'meta_key', 'meta_value'], 'search' => ['meta_value']],
            'options' => ['table' => $wpdb->options, 'id' => 'option_id', 'columns' => ['option_id', 'option_name', 'option_value', 'autoload'], 'search' => ['option_value']],
            'terms' => ['table' => $wpdb->terms, 'id' => 'term_id', 'columns' => ['term_id', 'name', 'slug', 'term_group'], 'search' => ['name', 'slug']],
            'term_taxonomy' => ['table' => $wpdb->term_taxonomy, 'id' => 'term_taxonomy_id', 'columns' => ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'], 'search' => ['description']],
            'users' => ['table' => $wpdb->users, 'id' => 'ID', 'columns' => ['ID', 'user_login', 'user_email', 'user_url', 'user_registered', 'display_name'], 'search' => []],
            'usermeta' => ['table' => $wpdb->usermeta, 'id' => 'umeta_id', 'columns' => ['umeta_id', 'user_id', 'meta_key', 'meta_value'], 'search' => []],
        ];
        if ($custom !== null && !isset($tables[$custom]) && !in_array($custom, array_column($tables, 'table'), true)) {
            if (strpos($custom, $wpdb->prefix) !== 0 || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $custom) || self::private_key($custom) || preg_match('/(?:aipkit|aipuffer|wpaicg|freemius|sessions?|oauth|credentials?)/i', $custom)) { return $tables; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact escaped table lookup; views cannot disguise another schema or protected source.
            $kind = $wpdb->get_row($wpdb->prepare('SHOW FULL TABLES LIKE %s', $wpdb->esc_like($custom)), ARRAY_N);
            if (!$kind || ($kind[1] ?? '') !== 'BASE TABLE') { return $tables; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Validated current-site identifier; names/columns are inspected, never caller SQL.
            $columns = $wpdb->get_results('SHOW FULL COLUMNS FROM `' . $custom . '`', ARRAY_A);
            if ($wpdb->last_error || !$columns) { return $tables; }
            $public = []; $text = []; $types = []; $primary = [];
            foreach ($columns as $column) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column['Field']) || self::private_key($column['Field']) || preg_match('/binary|blob|geometry/i', $column['Type'])) { continue; }
                $field = $column['Field']; $public[] = $field; $types[$field] = $column['Type'];
                if ($column['Key'] === 'PRI') { $primary[] = $field; }
                if (preg_match('/char|text|json/i', $column['Type'])) { $text[] = $field; }
            }
            if ($public) { $tables[$custom] = ['table' => $custom, 'id' => count($primary) === 1 ? $primary[0] : '', 'columns' => $public, 'search' => $text, 'types' => $types, 'custom' => true]; }
        }
        return $tables;
    }

    /** Strict one-statement SQL tokenizer. Input SQL is parsed, never concatenated into a database command. */
    private static function sql_tokens(string $sql): array
    {
        $tokens = []; $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char)) { $i++; continue; }
            if ($char === "'") {
                $value = ''; $closed = false; $i++;
                while ($i < $length) { if ($sql[$i] === "'") { if (($sql[$i + 1] ?? '') === "'") { $value .= "'"; $i += 2; continue; } $i++; $closed = true; break; } $value .= $sql[$i++]; }
                if (!$closed) { throw new \InvalidArgumentException('Close the SQL string; escape a quote as two single quotes.'); }
                $tokens[] = ['kind' => 'value', 'value' => $value]; continue;
            }
            if ($char === '`') {
                $end = strpos($sql, '`', $i + 1); if ($end === false) { throw new \InvalidArgumentException('Unclosed SQL identifier.'); }
                $name = substr($sql, $i + 1, $end - $i - 1); if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) { throw new \InvalidArgumentException('Use a simple table or column name.'); }
                $tokens[] = ['kind' => 'word', 'value' => $name]; $i = $end + 1; continue;
            }
            if (preg_match('/\G-?[0-9]+(?:\.[0-9]+)?/', $sql, $match, 0, $i)) { $tokens[] = ['kind' => 'value', 'value' => strpos($match[0], '.') === false ? (int) $match[0] : (float) $match[0]]; $i += strlen($match[0]); continue; }
            if (preg_match('/\G[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $match, 0, $i)) { $tokens[] = ['kind' => 'word', 'value' => $match[0]]; $i += strlen($match[0]); continue; }
            $two = substr($sql, $i, 2);
            if (in_array($two, ['>=', '<=', '!=', '<>'], true)) { $tokens[] = ['kind' => 'operator', 'value' => $two]; $i += 2; continue; }
            if (strpos('(),.*=<>;+-/', $char) !== false) { $tokens[] = ['kind' => 'operator', 'value' => $char]; $i++; continue; }
            throw new \InvalidArgumentException('Unsupported SQL syntax. Use one single-table statement, without comments, variables, joins or executable SQL functions.');
        }
        if (count($tokens) > 300) { throw new \InvalidArgumentException('Narrow this database statement.'); } return $tokens;
    }

    private static function sql_plan(string $sql): array
    {
        $tokens = self::sql_tokens($sql); $at = 0;
        $take = static function (string $word) use (&$at, $tokens): bool { $token = $tokens[$at] ?? null; if ($token && $token['kind'] !== 'value' && strcasecmp((string) $token['value'], $word) === 0) { $at++; return true; } return false; };
        $need = static function (string $word) use ($take): void { if (!$take($word)) { throw new \InvalidArgumentException('Unsupported SQL syntax. Use wp help for the supported single-table forms.'); } };
        $name = static function () use (&$at, $tokens): string { $token = $tokens[$at++] ?? null; if (!$token || $token['kind'] !== 'word') { throw new \InvalidArgumentException('Expected a table or column name.'); } return $token['value']; };
        $value = static function () use (&$at, $tokens) { $token = $tokens[$at++] ?? null; if ($token && $token['kind'] === 'value') { return $token['value']; } if ($token && strtoupper((string) $token['value']) === 'NULL') { return null; } throw new \InvalidArgumentException('Use a quoted SQL string, numeric literal or NULL.'); };
        $out = ['type' => strtoupper($name()), 'fields' => [], 'values' => [], 'where' => [], 'group' => [], 'order' => null, 'limit' => 100, 'offset' => 0];
        if ($out['type'] === 'SELECT') {
            do {
                if (($tokens[$at]['kind'] ?? '') === 'word' && strcasecmp((string) $tokens[$at]['value'], 'COUNT') === 0 && ($tokens[$at + 1]['value'] ?? '') === '(' && $take('COUNT')) { $need('('); $need('*'); $need(')'); $field = ['column' => '*', 'count' => true, 'alias' => 'count']; }
                elseif ($take('*')) { $field = ['column' => '*', 'count' => false, 'alias' => '*']; }
                else { $column = $name(); $field = ['column' => $column, 'count' => false, 'alias' => $column]; }
                if ($take('AS')) { $field['alias'] = $name(); }
                if (($field['column'] === '*' && !$field['count'] && $field['alias'] !== '*') || strpos(strtolower($field['alias']), '_mcp_') === 0 || strlen($field['alias']) > 64) { throw new \InvalidArgumentException('Reserved SQL result alias.'); }
                $out['fields'][] = $field;
            } while ($take(',')); $need('FROM'); $out['table'] = $name();
        } elseif ($out['type'] === 'UPDATE') {
            $out['table'] = $name(); $need('SET');
            do { $column = $name(); $need('='); if (array_key_exists($column, $out['values'])) { throw new \InvalidArgumentException('Specify a column once.'); } $out['values'][$column] = $value(); } while ($take(','));
        } elseif ($out['type'] === 'DELETE') { $need('FROM'); $out['table'] = $name(); }
        elseif ($out['type'] === 'INSERT') {
            $need('INTO'); $out['table'] = $name(); $need('('); $columns = []; do { $columns[] = $name(); } while ($take(',')); $need(')'); $need('VALUES'); $need('('); $values = []; do { $values[] = $value(); } while ($take(',')); $need(')');
            if (count($columns) !== count($values) || count(array_unique($columns)) !== count($columns)) { throw new \InvalidArgumentException('SQL columns and values must match once.'); } $out['values'] = array_combine($columns, $values);
        } else { throw new \InvalidArgumentException('Use SELECT, or a typed single-record INSERT/UPDATE/DELETE. Use wp db tables for table names.'); }
        if ($take('WHERE')) {
            do { $column = $name(); $token = $tokens[$at++] ?? null; $op = strtoupper((string) ($token['value'] ?? '')); if (!in_array($op, ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'IS'], true)) { throw new \InvalidArgumentException('Use supported comparisons joined by AND.'); } $literal = $value(); if (($literal === null) !== ($op === 'IS')) { throw new \InvalidArgumentException('Use IS NULL for a null comparison.'); } $out['where'][] = ['column' => $column, 'operator' => $op, 'value' => $literal]; } while ($take('AND'));
        }
        if ($out['type'] === 'SELECT') {
            if ($take('GROUP')) { $need('BY'); do { $out['group'][] = $name(); } while ($take(',')); }
            if ($take('ORDER')) { $need('BY'); $out['order'] = ['column' => $name(), 'descending' => $take('DESC')]; if (!$out['order']['descending']) { $take('ASC'); } }
            if ($take('LIMIT')) { $number = $value(); if (!is_int($number) || $number < 1 || $number > 1000) { throw new \InvalidArgumentException('SQL limit must be1–1000.'); } $out['limit'] = $number; if ($take('OFFSET')) { $out['offset'] = $value(); } elseif ($take(',')) { $out['offset'] = $number; $out['limit'] = $value(); } }
            if (!is_int($out['offset']) || $out['offset'] < 0 || $out['offset'] > 1000000 || !is_int($out['limit']) || $out['limit'] < 1 || $out['limit'] > 1000) { throw new \InvalidArgumentException('Invalid bounded SQL page.'); }
        }
        $take(';'); if ($at !== count($tokens)) { throw new \InvalidArgumentException('Only one supported single-table SQL statement is allowed.'); } return $out;
    }

    private static function database(array $args, bool $preview): array
    {
        global $wpdb;
        $key = $args['key']; $p = $args['positionals'];
        $ok = static function (array $data): array { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $data]; };
        if ($key === 'db prefix') { return $ok(['prefix' => $wpdb->prefix]); }
        if ($key === 'db tables') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded names only, current WordPress prefix escaped and prepared.
            $names = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
            return $wpdb->last_error ? Operations::error('database_unavailable') : $ok(['items' => array_map(static function ($name) { return ['name' => $name]; }, array_slice($names, 0, 200)), 'limit' => 200, 'scope' => 'Current WordPress prefix; use wp db columns TABLE for schema. Credential and MCP/Cloud control data remain excluded.']);
        }
        if ($key === 'config get') {
            $allowed = ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_CACHE', 'DISALLOW_FILE_MODS', 'DISALLOW_FILE_EDIT', 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'AUTOMATIC_UPDATER_DISABLED', 'WP_AUTO_UPDATE_CORE', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_ENVIRONMENT_TYPE', 'WP_HOME', 'WP_SITEURL', 'EMPTY_TRASH_DAYS', 'WP_POST_REVISIONS'];
            if ($p[0] === 'table_prefix') { return $ok(['name' => 'table_prefix', 'defined' => true, 'value' => $wpdb->prefix]); }
            if (!in_array($p[0], $allowed, true)) { return Operations::error('config_value_not_exposed', 'Only non-secret WordPress behavior flags are exposed. Use wp db prefix for the table prefix; passwords, salts, keys and database credentials are excluded.'); }
            $data = ['name' => $p[0], 'defined' => defined($p[0]), 'value' => defined($p[0]) ? constant($p[0]) : null]; if ($p[0] === 'WP_ENVIRONMENT_TYPE') { $data['runtime_value'] = wp_get_environment_type(); } return $ok($data);
        }
        if ($key === 'db columns') {
            foreach (self::database_tables($p[0]) as $name => $schema) { if ($name === $p[0] || $schema['table'] === $p[0]) { return $ok(['table' => $schema['table'], 'primary_key' => $schema['id'], 'columns' => $schema['columns'], 'types' => $schema['types'] ?? [], 'custom' => !empty($schema['custom'])]); } }
            return Operations::error('table_not_exposed');
        }
        if ($key === 'search-replace') { return self::search_replace($args, $preview); }
        if (preg_match('/^\s*SELECT\b/i', $p[0]) && preg_match('/\b(?:JOIN|OR|BETWEEN|DISTINCT)\b|\bIN\s*\(|\bCOUNT\s*\(\s*[a-zA-Z_]|\b(?:SUM|AVG|MIN|MAX|LOWER|UPPER|LENGTH|CHAR_LENGTH|COALESCE|IFNULL|ABS|ROUND|CONCAT|DATE|YEAR|MONTH)\s*\(|[a-zA-Z_]\w*\.[a-zA-Z_*]/i', $p[0])) { return self::relational_select($p[0]); }
        try { $plan = self::sql_plan($p[0]); }
        catch (\InvalidArgumentException $error) { if (preg_match('/^\s*SELECT\b/i', $p[0])) { return self::relational_select($p[0]); } throw $error; }
        $schema = null; $name = '';
        foreach (self::database_tables($plan['table']) as $candidate => $definition) { if ($plan['table'] === $candidate || $plan['table'] === $definition['table']) { $schema = $definition; $name = $candidate; break; } }
        if (!$schema) { return Operations::error('table_not_exposed', 'Use inspected current-site tables. Credentials and MCP/Cloud control tables are not queryable through this facade.'); }
        // Text may contain nested secrets. Apply text predicates/grouping/sorting to redacted snapshots, never raw stored values.
        if (!empty($schema['custom']) && $plan['type'] === 'SELECT') {
            $text = array_map('strtolower', $schema['search']);
            $tested = array_merge(array_column($plan['where'], 'column'), $plan['group']);
            if ($plan['order']) {
                $sort = $plan['order']['column'];
                foreach ($plan['fields'] as $field) { if (strcasecmp($field['alias'], $sort) === 0) { $sort = $field['column']; break; } }
                $tested[] = $sort;
            }
            if (array_intersect(array_map('strtolower', $tested), $text)) { return self::relational_select($p[0]); }
        }
        if (in_array($name, ['users', 'usermeta'], true) && !current_user_can('list_users')) { return Operations::error('permission_denied'); }
        $column = static function (string $input) use ($schema): string { foreach ($schema['columns'] as $valid) { if (strcasecmp($valid, $input) === 0) { return $valid; } } throw new \InvalidArgumentException('That column is not exposed; credential columns are excluded.'); };
        $values = []; foreach ($plan['values'] as $field => $literal) { $field = $column($field); if (array_key_exists($field, $values)) { return Operations::error('duplicate_column'); } $values[$field] = $literal; } $plan['values'] = $values;
        foreach ($plan['where'] as &$condition) { $condition['column'] = $column($condition['column']); } unset($condition);
        foreach ($plan['where'] as $condition) {
            if ($plan['type'] !== 'SELECT' && !empty($schema['custom']) && in_array($condition['column'], $schema['search'], true)) { return Operations::error('text_predicate_requires_ids', 'Read the redacted data first, then change its exact numeric IDs. Raw text predicates could reveal nested protected data.'); }
        }
        foreach ($plan['where'] as $condition) { if (in_array($condition['column'], ['option_value', 'meta_value'], true)) { return Operations::error('value_predicate_not_exposed', 'Filter by a named option/meta key, then inspect the redacted result. Raw value predicates could reveal protected nested data.'); } }
        if ($plan['type'] !== 'SELECT') { return !empty($schema['custom']) ? self::custom_write($schema, $plan, $preview, $args['flags']) : self::database_write($name, $plan, $preview); }
        $expanded = [];
        foreach ($plan['fields'] as $field) {
            if ($field['column'] === '*' && !$field['count']) { foreach ($schema['columns'] as $valid) { $expanded[] = ['column' => $valid, 'alias' => $valid, 'count' => false]; } }
            else { if (!$field['count']) { $field['column'] = $column($field['column']); } $expanded[] = $field; }
        }
        if (count(array_unique(array_map('strtolower', array_column($expanded, 'alias')))) !== count($expanded)) { return Operations::error('duplicate_result_alias'); }
        $plan['fields'] = $expanded; $plan['group'] = array_map($column, $plan['group']);
        $aggregate = (bool) array_filter($expanded, static function ($field) { return $field['count']; });
        if ($plan['group'] && !$aggregate) { return Operations::error('unsupported_group_query', 'Use GROUP BY with COUNT(*).'); }
        if ($aggregate) { foreach ($expanded as $field) { if (!$field['count'] && !in_array($field['column'], $plan['group'], true)) { return Operations::error('invalid_group_projection'); } } }
        $where = []; $parameters = [];
        foreach ($plan['where'] as $condition) {
            $where[] = '`' . $condition['column'] . '` ' . $condition['operator'] . ($condition['value'] === null ? ' NULL' : ' %s');
            if ($condition['value'] !== null) { $parameters[] = $condition['value']; }
        }
        $large_fields = array_unique(array_merge(['post_content', 'post_excerpt', 'meta_value', 'option_value', 'comment_content', 'description'], !empty($schema['custom']) ? $schema['search'] : []));
        if (array_intersect($plan['group'], $large_fields)) { return Operations::error('large_group_field', 'Group by a short status, type or key field.'); }
        $needed = $schema['id'] !== '' ? [$schema['id']] : []; foreach ($expanded as $field) { if (!$field['count']) { $needed[] = $field['column']; } }
        $needed = array_merge($needed, $plan['group'], ['post_id', 'meta_key', 'option_name', 'setting_key', 'key_name', 'comment_id', 'comment_approved', 'comment_ID', 'user_id']);
        if (array_intersect(array_column($expanded, 'column'), $large_fields) && $plan['limit'] > 100) { return Operations::error('text_page_too_large', 'Read at most100 rows when requesting large text values.'); }
        $select = [];
        foreach (array_intersect($schema['columns'], $needed) as $field) {
            if (in_array($field, $large_fields, true)) { $select[] = 'IF(OCTET_LENGTH(`' . $field . '`) > 65536, NULL, `' . $field . '`) AS `' . $field . '`'; }
            else { $select[] = '`' . $field . '`'; }
        }
        $order = $schema['id'] ?: $schema['columns'][0]; $descending = false;
        if ($plan['order'] && !$aggregate) {
            $order = $plan['order']['column']; foreach ($expanded as $field) { if (strcasecmp($field['alias'], $order) === 0) { $order = $field['column']; break; } }
            $order = $column($order); if (in_array($order, ['option_value', 'meta_value'], true)) { return Operations::error('value_order_not_exposed'); } $descending = $plan['order']['descending'];
        }
        $fetch = $aggregate ? 1001 : $plan['limit'] + 1; $offset = $aggregate ? 0 : $plan['offset'];
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . $schema['table'] . '`' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY `' . $order . '` ' . ($descending ? 'DESC' : 'ASC') . ' LIMIT %d OFFSET %d';
        $parameters[] = $fetch; $parameters[] = $offset;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only fixed schema identifiers/operators reach this rebuilt query; every literal and page bound is prepared.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $parameters), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) { return Operations::error('database_query_failed'); }
        if ($aggregate && count($rows) > 1000) { return Operations::error('query_too_broad', 'Narrow the WHERE clause: exact aggregates are bounded to1000 input rows, never silently truncated counts.'); }
        $has_more = !$aggregate && count($rows) > $plan['limit']; if (!$aggregate) { $rows = array_slice($rows, 0, $plan['limit']); }
        $items = []; $omitted = 0;
        foreach ($rows as $row) {
            if (!self::database_row_visible($name, $row)) { $omitted++; continue; }
            foreach (!empty($schema['custom']) ? $schema['search'] : ['meta_value', 'option_value'] as $field) { if (isset($row[$field])) { $row[$field] = self::database_value($row[$field]); } }
            $items[] = $row;
        }
        if ($aggregate) {
            $groups = []; foreach ($items as $row) { $group_key = wp_json_encode(array_intersect_key($row, array_flip($plan['group']))); if (!isset($groups[$group_key])) { $groups[$group_key] = ['row' => $row, 'count' => 0]; } $groups[$group_key]['count']++; }
            if (!$groups && !$plan['group']) { $groups['all'] = ['row' => [], 'count' => 0]; }
            $projected = []; foreach ($groups as $group) { $item = []; foreach ($expanded as $field) { $item[$field['alias']] = $field['count'] ? $group['count'] : ($group['row'][$field['column']] ?? null); } $projected[] = $item; }
            if ($plan['order']) { $sort = $plan['order']['column']; foreach (array_column($expanded, 'alias') as $alias) { if (strcasecmp($alias, $sort) === 0) { $sort = $alias; break; } } if (!in_array($sort, array_column($expanded, 'alias'), true)) { return Operations::error('unknown_order_alias'); } $direction = $plan['order']['descending'] ? -1 : 1; usort($projected, static function ($a, $b) use ($sort, $direction) { return ($a[$sort] <=> $b[$sort]) * $direction; }); }
            $items = array_slice($projected, $plan['offset'], $plan['limit']);
        } else { $projected = []; foreach ($items as $row) { $item = []; foreach ($expanded as $field) { $item[$field['alias']] = $row[$field['column']] ?? null; } $projected[] = self::public_data($item); } $items = $projected; }
        return $ok(['items' => $items, 'limit' => $plan['limit'], 'offset' => $plan['offset'], 'has_more' => $has_more, 'protected_rows_omitted' => $omitted,
            'scope' => 'Supported single-table query facade, not unrestricted SQL. Credential/control data excluded. Values over64KB are omitted as null; use a specific WordPress read tool for large content.']);
    }

    /** Atomic custom-plugin data writes. Caller SQL is parsed; identifiers come from the inspected site schema. */
    private static function custom_write(array $schema, array $plan, bool $preview, array $flags): array
    {
        global $wpdb;
        if (!$schema['id'] || !preg_match('/int/i', $schema['types'][$schema['id']] ?? '')) { return Operations::error('integer_primary_key_required', 'Custom data changes require one integer primary key.'); }
        if ($plan['type'] !== 'INSERT' && !$plan['where']) { return Operations::error('where_required', 'Choose the records with WHERE before changing custom-table data.'); }
        foreach ($plan['values'] as $field => $value) {
            if ($field === $schema['id'] || !is_scalar($value) && $value !== null) { return Operations::error('unsupported_write_column'); }
            if (strlen((string) $value) > 65536) { return Operations::error('value_too_large'); }
            if (in_array($field, ['meta_key', 'option_name', 'setting_key', 'key_name'], true) && self::private_key((string) $value)) { return Operations::error('protected_key'); }
        }
        if (isset($flags['expected-count']) && (!ctype_digit((string) $flags['expected-count']) || (int) $flags['expected-count'] > 100)) { return Operations::error('invalid_input'); }
        $where = []; $params = [];
        foreach ($plan['where'] as $condition) { $where[] = '`' . $condition['column'] . '` ' . $condition['operator'] . ($condition['value'] === null ? ' NULL' : ' %s'); if ($condition['value'] !== null) { $params[] = $condition['value']; } }
        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $select = 'SELECT ' . implode(',', array_map(static function ($field) { return '`' . $field . '`'; }, $schema['columns'])) . ' FROM `' . $schema['table'] . '`' . $where_sql . ' ORDER BY `' . $schema['id'] . '` LIMIT 101';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column/table identifiers were inspected; external WHERE literals use placeholders.
        $query = $params ? $wpdb->prepare($select, $params) : $select;
        $size_fields = array_map(static function ($field) { return 'COALESCE(OCTET_LENGTH(`' . $field . '`),0)'; }, $schema['columns']);
        $size_sql = 'SELECT SUM(row_bytes) FROM (SELECT ' . implode('+', $size_fields) . ' AS row_bytes FROM `' . $schema['table'] . '`' . $where_sql . ' LIMIT 101) AS selected_sizes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded aggregate over inspected identifiers, external WHERE literals prepared; prevents loading oversized records.
        $bytes = $plan['type'] === 'INSERT' ? 0 : $wpdb->get_var($params ? $wpdb->prepare($size_sql, $params) : $size_sql);
        if ($wpdb->last_error || (int) $bytes > 4194304) { return Operations::error('write_snapshot_too_large', 'Narrow the matching records: an atomic custom-data snapshot is limited to4MiB.'); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers are schema-inspected; all external literals prepared above. Preflight does not write.
        $rows = $plan['type'] === 'INSERT' ? [] : $wpdb->get_results($query, ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) { return Operations::error('database_query_failed'); }
        $count = $plan['type'] === 'INSERT' ? 1 : count($rows);
        if ($count > 100) { return Operations::error('batch_too_large', 'Narrow WHERE to at most100 matching records per atomic change.'); }
        foreach ($rows as $row) { if (!self::database_row_visible($schema['table'], $row)) { return Operations::error('protected_rows_selected'); } }
        if (isset($flags['expected-count']) && $count !== (int) $flags['expected-count']) { return self::count_changed('matching_count_changed', (int) $flags['expected-count'], $count); }
        $summary = ['table' => $schema['table'], 'statement' => $plan['type'], 'matching_rows' => $count, 'columns' => array_keys($plan['values'])];
        if ($preview) { return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'Custom-table data change', 'name' => self::WRITE, 'description' => 'Apply only the displayed custom-plugin data change. WordPress/plugin lifecycle hooks are not run by SQL.', 'risk' => 'publish', 'input' => wp_json_encode($summary + ['values' => $plan['values'], 'where' => $plan['where']])]]; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Check the actual engine before a transaction.
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $schema['table']), ARRAY_A);
        if (!$status || strcasecmp($status['Engine'] ?? '', 'InnoDB') !== 0) { return Operations::error('transactional_tables_required'); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit transaction; failed or lost commit remains unknown.
        if ($wpdb->query('START TRANSACTION') === false) { return Operations::error('database_unavailable'); }
        try {
            if ($plan['type'] !== 'INSERT') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Same prepared preflight, now locked; compare exact records before any effect.
                $locked = $wpdb->get_results($query . ' FOR UPDATE', ARRAY_A);
                if ($wpdb->last_error || $locked !== $rows) { throw new \RuntimeException('Selected records changed'); }
            }
            Operations::$mutation_started = true;
            $affected = 0; $inserted = null;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Allowlisted typed custom-table write under this transaction; wpdb prepares inserted values.
            if ($plan['type'] === 'INSERT') { $affected = $wpdb->insert($schema['table'], $plan['values']); $inserted = (int) $wpdb->insert_id; if ($affected !== 1) { throw new \RuntimeException('Insert not confirmed'); } }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Allowlisted custom-table records are locked and changed through prepared wpdb APIs; no cached read is valid here.
            else { foreach ($rows as $row) { $result = $plan['type'] === 'DELETE' ? $wpdb->delete($schema['table'], [$schema['id'] => $row[$schema['id']]]) : $wpdb->update($schema['table'], $plan['values'], [$schema['id'] => $row[$schema['id']]]); if ($result === false || ($plan['type'] === 'DELETE' && $result !== 1)) { throw new \RuntimeException('Write not confirmed'); } $affected += $result; } }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit acknowledgement is required for success.
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Commit not confirmed'); }
        } catch (\Throwable $error) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back this batch only; do not auto-retry unknown effects.
            $wpdb->query('ROLLBACK'); return Operations::$mutation_started ? self::native_unknown() : Operations::error('matching_records_changed');
        }
        wp_cache_flush();
        return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $summary + ['rows_changed' => $affected, 'insert_id' => $inserted, 'hooks' => 'Direct custom-table data only; use the owning plugin ability for emails, billing, indexes or other lifecycle effects.']];
    }

    private static function sql_literal($value): string
    {
        global $wpdb;
        if ($value === null) { return 'NULL'; }
        if (is_int($value)) { return (string) $value; }
        if (is_float($value)) { return $wpdb->prepare('%f', $value); }
        return $wpdb->prepare('%s', (string) $value);
    }

    /** Relational SELECT over authorized, redacted source snapshots; SQL can never reference the raw credential tables. */
    private static function relational_select(string $input, int $window = 0): array
    {
        global $wpdb;
        $tokens = self::sql_tokens($input); $at = 0; $depth = 0;
        $peek = static function () use (&$at, $tokens) { return $tokens[$at]['value'] ?? null; };
        $take = static function (string $word) use (&$at, $tokens): bool { if (($tokens[$at]['kind'] ?? '') !== 'value' && strcasecmp((string) ($tokens[$at]['value'] ?? ''), $word) === 0) { $at++; return true; } return false; };
        $need = static function (string $word) use ($take): void { if (!$take($word)) { throw new \InvalidArgumentException('Unexpected SQL syntax. Check the supported clause order.'); } };
        $name = static function () use (&$at, $tokens): string { $t = $tokens[$at++] ?? []; if (($t['kind'] ?? '') !== 'word') { throw new \InvalidArgumentException('Expected SQL identifier'); } return $t['value']; };
        $functions = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'LOWER', 'UPPER', 'LENGTH', 'CHAR_LENGTH', 'COALESCE', 'IFNULL', 'ABS', 'ROUND', 'CONCAT', 'DATE', 'YEAR', 'MONTH'];
        $expr = null;
        $expr = static function (int $minimum = 0) use (&$expr, &$at, &$depth, $tokens, $peek, $take, $need, $name, $functions) {
            if (++$depth > 24) { throw new \InvalidArgumentException('SQL expression too deep'); }
            $t = $tokens[$at] ?? null;
            if ($take('(')) { $node = $expr(); $need(')'); }
            elseif ($t && $t['kind'] === 'value') { $at++; $node = ['literal', $t['value']]; }
            elseif ($take('NULL')) { $node = ['literal', null]; }
            elseif ($take('*')) { $node = ['star', null]; }
            else {
                $word = $name();
                if ($take('(')) { $function = strtoupper($word); if (!in_array($function, $functions, true)) { throw new \InvalidArgumentException('SQL function is not supported'); } $args = []; if (!$take(')')) { do { $args[] = $expr(); } while ($take(',')); $need(')'); } if (!$args || count($args) > 20) { throw new \InvalidArgumentException('Invalid function arguments'); } $node = ['function', $function, $args]; }
                elseif ($take('.')) { $node = $take('*') ? ['star', $word] : ['column', $word, $name()]; }
                else { $node = ['column', null, $word]; }
            }
            $precedence = ['OR' => 1, 'AND' => 2, '=' => 3, '!=' => 3, '<>' => 3, '<' => 3, '>' => 3, '<=' => 3, '>=' => 3, 'LIKE' => 3, 'IS' => 3, 'IN' => 3, 'BETWEEN' => 3, '+' => 4, '-' => 4, '*' => 5, '/' => 5];
            while (isset($precedence[strtoupper((string) $peek())]) && $precedence[strtoupper((string) $peek())] >= $minimum) {
                $operator = strtoupper((string) $peek()); $at++; $rank = $precedence[$operator];
                if ($operator === 'IS') { $not = $take('NOT'); $need('NULL'); $node = ['binary', $not ? 'IS NOT' : 'IS', $node, ['literal', null]]; }
                elseif ($operator === 'IN') { $need('('); $values = []; do { $values[] = $expr(4); } while ($take(',')); $need(')'); if (count($values) > 100) { throw new \InvalidArgumentException('Too many IN values'); } $node = ['in', $node, $values]; }
                elseif ($operator === 'BETWEEN') { $low = $expr(4); $need('AND'); $high = $expr(4); $node = ['between', $node, $low, $high]; }
                else { $node = ['binary', $operator, $node, $expr($rank + 1)]; }
            }
            $depth--; return $node;
        };
        $need('SELECT'); $distinct = $take('DISTINCT'); $fields = [];
        do { $node = $expr(4); $alias = null; if ($take('AS')) { $alias = $name(); } $fields[] = [$node, $alias]; } while ($take(','));
        $source = static function () use ($name, $take, $peek): array { $table = $name(); $alias = $table; if ($take('AS')) { $alias = $name(); } elseif (is_string($peek()) && preg_match('/^[a-zA-Z_]\w*$/D', $peek()) && !in_array(strtoupper($peek()), ['WHERE', 'JOIN', 'LEFT', 'INNER', 'GROUP', 'ORDER', 'LIMIT'], true)) { $alias = $name(); } return [$table, $alias]; };
        $need('FROM'); $sources = [[$source(), null, null]];
        while (in_array(strtoupper((string) $peek()), ['JOIN', 'INNER', 'LEFT'], true)) { $kind = $take('LEFT') ? 'LEFT' : 'INNER'; if ($kind === 'LEFT') { $take('OUTER'); } else { $take('INNER'); } $need('JOIN'); $table = $source(); $need('ON'); $sources[] = [$table, $kind, $expr()]; if (count($sources) > 4) { throw new \InvalidArgumentException('At most four tables per read'); } }
        $where = $take('WHERE') ? $expr() : null; $groups = []; $orders = [];
        if ($take('GROUP')) { $need('BY'); do { $groups[] = $expr(4); } while ($take(',')); }
        if ($take('ORDER')) { $need('BY'); do { $node = $expr(4); $descending = $take('DESC'); if (!$descending) { $take('ASC'); } $orders[] = [$node, $descending]; } while ($take(',')); }
        $limit = 100; $offset = 0;
        if ($take('LIMIT')) { $n = $tokens[$at++] ?? []; $limit = $n['value'] ?? null; if ($take('OFFSET')) { $n = $tokens[$at++] ?? []; $offset = $n['value'] ?? null; } elseif ($take(',')) { $offset = $limit; $n = $tokens[$at++] ?? []; $limit = $n['value'] ?? null; } }
        if (!is_int($limit) || $limit < 1 || $limit > 1000 || !is_int($offset) || $offset < 0 || $offset > 1000000) { throw new \InvalidArgumentException('Use bounded LIMIT1–1000 and a valid offset'); }
        $take(';'); if ($at !== count($tokens)) { throw new \InvalidArgumentException('Only one SELECT statement is supported'); }
        // Resolve the complete schema before planning: an unqualified column must never become
        // unambiguous merely because another source has not been fetched yet.
        $schemas = []; $logical_names = [];
        foreach ($sources as [$table]) {
            [$requested, $alias] = $table;
            if (in_array(strtolower($alias), array_map('strtolower', array_keys($schemas)), true) || strlen($alias) > 64) { throw new \InvalidArgumentException('Use unique source aliases'); }
            foreach (self::database_tables($requested) as $key => $candidate) {
                if ($key === $requested || $candidate['table'] === $requested) { $schemas[$alias] = $candidate; $logical_names[$alias] = $key; break; }
            }
            if (!isset($schemas[$alias])) { return Operations::error('table_not_exposed'); }
            if (in_array($logical_names[$alias], ['users', 'usermeta'], true) && !current_user_can('list_users')) { return Operations::error('permission_denied'); }
        }
        $needed = []; $aggregate = false;
        $inspect = null;
        $inspect = static function (array $node) use (&$inspect, &$needed, &$aggregate, $schemas): void {
            if ($node[0] === 'column') { [$alias, $column] = self::sql_column($node, $schemas); $needed[$alias][$column] = true; }
            elseif ($node[0] === 'star') { foreach ($schemas as $alias => $schema) { if ($node[1] === null || strcasecmp($node[1], $alias) === 0) { foreach ($schema['columns'] as $column) { $needed[$alias][$column] = true; } } } }
            elseif ($node[0] === 'function') { if (in_array($node[1], ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'], true)) { $aggregate = true; } foreach ($node[2] as $child) { if ($child[0] !== 'star') { $inspect($child); } } }
            elseif ($node[0] === 'binary') { $inspect($node[2]); $inspect($node[3]); }
            elseif ($node[0] === 'in') { $inspect($node[1]); foreach ($node[2] as $child) { $inspect($child); } }
            elseif ($node[0] === 'between') { $inspect($node[1]); $inspect($node[2]); $inspect($node[3]); }
        };
        foreach ($fields as [$node]) { $inspect($node); }
        if ($where) { $inspect($where); }
        foreach ($groups as $node) { $inspect($node); }
        foreach ($sources as $entry) { if ($entry[2]) { $inspect($entry[2]); } }
        $source_orders = $orders;
        foreach ($source_orders as &$order) {
            if ($order[0][0] === 'column' && $order[0][1] === null) {
                foreach ($fields as [$node, $alias]) { if ($alias !== null && strcasecmp($alias, $order[0][2]) === 0) { $order[0] = $node; break; } }
            }
            $inspect($order[0]);
        }
        unset($order);
        $base = array_key_first($schemas);
        // A source window is valid only when later rows cannot outrank it. Never pre-limit
        // aggregates, DISTINCT, groups or ordering by a joined/redacted value.
        $paged = !$aggregate && !$distinct && !$groups && $limit + $offset < 1000;
        foreach ($source_orders as [$node]) { if (self::sql_source_expression($node, $base, $schemas) === null) { $paged = false; } }
        $initial_window = count($sources) > 1 ? 1 : $limit + $offset + 1;
        $window = $paged ? min(1000, max($window, $initial_window)) : 1000;
        $from = ''; $budget = 0; $source_counts = []; $snapshots = []; $truncated = false;
        foreach ($sources as [$table, $kind, $on]) {
            [, $alias] = $table; $schema = $schemas[$alias]; $logical = $logical_names[$alias];
            // Keep authorization keys even when they were not projected by the caller.
            $visibility = ['options' => ['option_name'], 'posts' => ['ID'], 'postmeta' => ['post_id', 'meta_key'], 'comments' => ['comment_ID', 'comment_approved'], 'commentmeta' => ['comment_id', 'meta_key'], 'users' => ['ID'], 'usermeta' => ['user_id', 'meta_key']];
            $required = $needed[$alias] ?? [];
            foreach ($visibility[$logical] ?? ['meta_key', 'option_name', 'setting_key', 'key_name'] as $column) { if (in_array($column, $schema['columns'], true)) { $required[$column] = true; } }
            $columns = [];
            foreach (array_intersect($schema['columns'], array_keys($required)) as $column) { $columns[] = 'IF(OCTET_LENGTH(`' . $column . '`) > 65536, NULL, `' . $column . '`) AS `' . $column . '`'; }
            // COUNT(*) still needs a source row when no authorization fields apply.
            if (!$columns) { $columns[] = '1 AS `_mcp_row`'; }
            $filters = [];
            // WHERE on a nullable LEFT source cannot be moved below its join (IS NULL
            // would otherwise manufacture matches). ON constraints on that source can.
            if ($where && ($alias === $base || $kind !== 'LEFT')) { $filter = self::sql_source_filter($where, $alias, $schemas); if ($filter !== null) { $filters[] = $filter; } }
            if ($on) {
                $filter = self::sql_source_filter($on, $alias, $schemas); if ($filter !== null) { $filters[] = $filter; }
                $filter = self::sql_join_filter($on, $alias, $schemas, $snapshots); if ($filter !== null) { $filters[] = $filter; }
            }
            $ordering = [];
            if ($paged && $alias === $base) { foreach ($source_orders as [$node, $descending]) { $ordering[] = self::sql_source_expression($node, $base, $schemas) . ($descending ? ' DESC' : ' ASC'); } }
            if (!$ordering && $schema['id']) { $ordering[] = '`' . $alias . '`.`' . $schema['id'] . '` ASC'; }
            $source_limit = $paged && $alias === $base ? $window : 1000;
            $query = 'SELECT ' . implode(',', $columns) . ' FROM `' . $schema['table'] . '` AS `' . $alias . '`'
                . ($filters ? ' WHERE ' . implode(' AND ', $filters) : '') . ($ordering ? ' ORDER BY ' . implode(',', $ordering) : '') . ' LIMIT ' . ($source_limit + 1);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reconstructed AST filters use prepared literals and schema-inspected invariant columns; bounded snapshots are authorized/redacted before final SQL.
            $rows = $wpdb->get_results($query, ARRAY_A);
            if ($wpdb->last_error || !is_array($rows)) { return Operations::error('database_query_failed'); }
            if (count($rows) > $source_limit) {
                if (!$paged || $alias !== $base) { return Operations::error('source_too_large', 'Narrow the read using IDs or numeric ranges: at most1000 selected source rows per table.'); }
                $truncated = true; $rows = array_slice($rows, 0, $source_limit);
            }
            $selects = []; $snapshots[$alias] = [];
            foreach ($rows as $row) {
                if (!self::database_row_visible($logical, $row)) { continue; }
                $values = [];
                foreach (array_keys($row) as $column) {
                    $value = self::database_value($row[$column]); if (is_array($value) || is_object($value)) { $value = wp_json_encode($value); }
                    $row[$column] = $value;
                    $literal = self::sql_literal($value); $type = $schema['types'][$column] ?? (preg_match('/^(ID|.*_id|.*_ID|count|parent|post_author|post_parent|term_group|user_id)$/D', $column) ? 'bigint' : 'text');
                    if ($value !== null && preg_match('/int/i', $type)) { $literal = 'CAST(' . $literal . (stripos($type, 'unsigned') !== false ? ' AS UNSIGNED)' : ' AS SIGNED)'); }
                    elseif ($value !== null && preg_match('/^(?:decimal|numeric)\(([0-9]{1,2}),([0-9]{1,2})\)/i', $type, $precision)) { $literal = 'CAST(' . $literal . ' AS DECIMAL(' . min(65, (int) $precision[1]) . ',' . min(30, (int) $precision[2]) . '))'; }
                    elseif ($value !== null && preg_match('/float|double/i', $type)) { $literal = 'CAST(' . $literal . ' AS DECIMAL(65,15))'; }
                    $values[] = $literal . ' AS `' . $column . '`';
                }
                $snapshots[$alias][] = $row;
                $part = 'SELECT ' . implode(',', $values); $budget += strlen($part); if ($budget > 4194304) { return Operations::error('source_too_large', 'Narrow this read: authorized source snapshots are limited to4MiB.'); } $selects[] = $part;
            }
            $source_counts[$alias] = count($selects); $candidates = 1; foreach ($source_counts as $source_count) { $candidates *= max(1, $source_count); } if ($candidates > 1000000) { return Operations::error('join_too_broad', 'Narrow the joined data: at most one million source-row combinations per read.'); }
            if (!$selects) { $selects[] = 'SELECT ' . implode(',', array_map(static function ($column) { return 'NULL AS `' . $column . '`'; }, $schema['columns'])) . ' WHERE 1=0'; }
            $part = '(' . implode(' UNION ALL ', $selects) . ') AS `' . $alias . '`';
            $from .= $kind ? ' ' . $kind . ' JOIN ' . $part . ' ON ' . self::sql_expression($on, $schemas) : $part;
        }
        $projection = []; $aliases = [];
        foreach ($fields as [$node, $alias]) {
            if ($node[0] === 'star') {
                foreach ($schemas as $source_alias => $schema) { if ($node[1] !== null && $node[1] !== $source_alias) { continue; } foreach ($schema['columns'] as $column) { if (isset($aliases[strtolower($column)])) { throw new \InvalidArgumentException('Select explicit unique aliases for duplicate columns'); } $projection[] = '`' . $source_alias . '`.`' . $column . '` AS `' . $column . '`'; $aliases[strtolower($column)] = $column; } }
            } else { $alias = $alias ?? ($node[0] === 'column' ? $node[2] : ($node[0] === 'function' ? strtolower($node[1]) : 'value')); if (!preg_match('/^[a-zA-Z_]\w{0,63}$/D', $alias) || isset($aliases[strtolower($alias)])) { throw new \InvalidArgumentException('Use unique result aliases'); } $aliases[strtolower($alias)] = $alias; $projection[] = self::sql_expression($node, $schemas) . ' AS `' . $alias . '`'; }
        }
        if (!$projection) { throw new \InvalidArgumentException('No selected columns'); }
        $query = 'SELECT ' . ($distinct ? 'DISTINCT ' : '') . implode(',', $projection) . ' FROM ' . $from;
        if ($where) { $query .= ' WHERE ' . self::sql_expression($where, $schemas); }
        if ($groups) { $query .= ' GROUP BY ' . implode(',', array_map(static function ($node) use ($schemas) { return self::sql_expression($node, $schemas); }, $groups)); }
        if ($orders) { $parts = []; foreach ($orders as [$node, $descending]) { $parts[] = ($node[0] === 'column' && $node[1] === null && isset($aliases[strtolower($node[2])]) ? '`' . $aliases[strtolower($node[2])] . '`' : self::sql_expression($node, $schemas)) . ($descending ? ' DESC' : ' ASC'); } $query .= ' ORDER BY ' . implode(',', $parts); }
        $query .= ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fully reconstructed SELECT over authorized/redacted literal snapshots, supported AST operators/functions and inspected identifiers only. No caller SQL reaches the database.
        $items = $wpdb->get_results($query, ARRAY_A);
        if ($wpdb->last_error || !is_array($items)) { return Operations::error('database_query_failed'); }
        if ($truncated && count($items) <= $limit) {
            // Do not turn an incomplete scan into an empty page, a partial count, or false has_more.
            if ($window >= 1000) { return Operations::error('source_too_large', 'Narrow the read: its result could not be established within1000 source rows.'); }
            return self::relational_select($input, min(1000, $window * 2));
        }
        return ['state' => 'succeeded', 'actions_used' => 1, 'result' => ['items' => array_slice($items, 0, $limit), 'limit' => $limit, 'offset' => $offset, 'has_more' => count($items) > $limit, 'sources' => $source_counts, 'scope' => 'Authorized source snapshots, maximum1000 rows/table and4MiB. Protected fields/rows are excluded before joins, predicates and aggregates.']];
    }

    private static function sql_expression(array $node, array $schemas): string
    {
        $kind = $node[0];
        if ($kind === 'literal') { return self::sql_literal($node[1]); }
        if ($kind === 'star') { return '*'; }
        if ($kind === 'column') {
            [$alias, $column] = self::sql_column($node, $schemas); return '`' . $alias . '`.`' . $column . '`';
        }
        if ($kind === 'function') { return $node[1] . '(' . implode(',', array_map(static function ($child) use ($schemas) { return self::sql_expression($child, $schemas); }, $node[2])) . ')'; }
        if ($kind === 'binary') { return '(' . self::sql_expression($node[2], $schemas) . ' ' . $node[1] . ' ' . self::sql_expression($node[3], $schemas) . ')'; }
        if ($kind === 'in') { return '(' . self::sql_expression($node[1], $schemas) . ' IN (' . implode(',', array_map(static function ($child) use ($schemas) { return self::sql_expression($child, $schemas); }, $node[2])) . '))'; }
        if ($kind === 'between') { return '(' . self::sql_expression($node[1], $schemas) . ' BETWEEN ' . self::sql_expression($node[2], $schemas) . ' AND ' . self::sql_expression($node[3], $schemas) . ')'; }
        throw new \InvalidArgumentException('Unsupported SQL expression');
    }

    /** Resolve against all sources, including protected/ambiguous column rejection. */
    private static function sql_column(array $node, array $schemas): array
    {
        $matches = [];
        foreach ($schemas as $alias => $schema) {
            if ($node[1] !== null && strcasecmp($node[1], $alias) !== 0) { continue; }
            foreach ($schema['columns'] as $column) { if (strcasecmp($node[2], $column) === 0) { $matches[] = [$alias, $column]; } }
        }
        if (count($matches) !== 1) { throw new \InvalidArgumentException('Unknown, protected or ambiguous SQL column'); }
        return $matches[0];
    }

    /** Only typed integers/dates are invariant under PHP redaction and the64KB cell cap.
     * Text, JSON and serialized data must still be filtered AFTER database_value(). */
    private static function sql_source_expression(array $node, string $source, array $schemas, ?array &$structural = null): ?string
    {
        if ($node[0] === 'literal') { return self::sql_literal($node[1]); }
        if ($node[0] === 'column') {
            [$alias, $column] = self::sql_column($node, $schemas);
            if ($alias !== $source) { return null; }
            $type = $schemas[$alias]['types'][$column] ?? (preg_match('/^(ID|.*_id|.*_ID|count|parent|post_author|post_parent|term_group|user_id)$/D', $column) ? 'bigint' : 'text');
            $sql = '`' . $alias . '`.`' . $column . '`';
            if (preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\b|\()/i', $type)) { return 'CAST(' . $sql . (stripos($type, 'unsigned') !== false ? ' AS UNSIGNED)' : ' AS SIGNED)'); }
            // Core dates are known WordPress DATETIME fields, not arbitrary text.
            if (preg_match('/^(?:date|datetime|timestamp|time)(?:\(|$)/i', $type)
                || (empty($schemas[$alias]['custom']) && in_array($column, ['post_date', 'post_date_gmt', 'post_modified', 'comment_date', 'user_registered'], true))) { return 'CAST(' . $sql . ' AS CHAR)'; }
            if ($structural !== null && empty($schemas[$alias]['custom']) && in_array($column, ['post_type', 'post_status'], true)) {
                // Ordinary WP type/status keys are invariant. Always retain unexpected
                // encodings/oversized cells too, so PHP redaction still decides their match.
                $structural[] = '(OCTET_LENGTH(' . $sql . ') > 65536 OR CAST(' . $sql . ' AS CHAR) NOT REGEXP ' . self::sql_literal('^[a-zA-Z0-9_-]*$') . ')';
                return 'CAST(' . $sql . ' AS CHAR)';
            }
            return null;
        }
        if ($node[0] === 'function' || $node[0] === 'star') { return null; }
        if ($node[0] === 'binary') {
            $left = self::sql_source_expression($node[2], $source, $schemas, $structural); $right = self::sql_source_expression($node[3], $source, $schemas, $structural);
            return $left === null || $right === null ? null : '(' . $left . ' ' . $node[1] . ' ' . $right . ')';
        }
        if ($node[0] === 'in' || $node[0] === 'between') {
            $parts = []; foreach ($node[0] === 'in' ? array_merge([$node[1]], $node[2]) : array_slice($node, 1) as $child) { $part = self::sql_source_expression($child, $source, $schemas, $structural); if ($part === null) { return null; } $parts[] = $part; }
            return $node[0] === 'in' ? '(' . array_shift($parts) . ' IN (' . implode(',', $parts) . '))' : '(' . $parts[0] . ' BETWEEN ' . $parts[1] . ' AND ' . $parts[2] . ')';
        }
        return null;
    }

    /** Extract only necessary AND conditions. Never narrow one branch of a mixed OR. */
    private static function sql_source_filter(array $node, string $source, array $schemas): ?string
    {
        $structural = [];
        $sql = self::sql_source_expression($node, $source, $schemas, $structural);
        if ($sql !== null) { return $structural ? '(' . $sql . ' OR ' . implode(' OR ', array_unique($structural)) . ')' : $sql; }
        if ($node[0] !== 'binary' || $node[1] !== 'AND') { return null; }
        $parts = [];
        foreach ([$node[2], $node[3]] as $child) { $part = self::sql_source_filter($child, $source, $schemas); if ($part !== null) { $parts[] = $part; } }
        return $parts ? '(' . implode(' AND ', $parts) . ')' : null;
    }

    /** Reduce an equijoin to already authorized/redacted source IDs, never raw private values. */
    private static function sql_join_filter(array $node, string $source, array $schemas, array $snapshots): ?string
    {
        if ($node[0] === 'binary' && $node[1] === 'AND') {
            $parts = []; foreach ([$node[2], $node[3]] as $child) { $part = self::sql_join_filter($child, $source, $schemas, $snapshots); if ($part !== null) { $parts[] = $part; } }
            return $parts ? '(' . implode(' AND ', $parts) . ')' : null;
        }
        if ($node[0] !== 'binary' || $node[1] !== '=' || $node[2][0] !== 'column' || $node[3][0] !== 'column') { return null; }
        foreach ([[$node[2], $node[3]], [$node[3], $node[2]]] as [$current, $previous]) {
            [$alias, $column] = self::sql_column($previous, $schemas);
            if (!array_key_exists($alias, $snapshots)) { continue; }
            $sql = self::sql_source_expression($current, $source, $schemas);
            $prior = self::sql_source_expression($previous, $alias, $schemas);
            if ($sql === null || $prior === null || !preg_match('/ AS (?:SIGNED|UNSIGNED)\)$/D', $sql) || !preg_match('/ AS (?:SIGNED|UNSIGNED)\)$/D', $prior)) { continue; }
            $values = []; foreach ($snapshots[$alias] as $row) { if ($row[$column] !== null) { $literal = self::sql_literal($row[$column]); $values[] = 'CAST(' . $literal . (substr($prior, -12) === 'AS UNSIGNED)' ? ' AS UNSIGNED)' : ' AS SIGNED)'); } }
            return $values ? '(' . $sql . ' IN (' . implode(',', array_unique($values)) . '))' : '(1=0)';
        }
        return null;
    }

    private static function database_value($value)
    {
        if (!is_string($value) || !is_serialized($value)) { return self::public_data($value); }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Existing WordPress data only; classes disabled and depth bounded, so no object callbacks can run.
        $decoded = @unserialize($value, ['allowed_classes' => false, 'max_depth' => 32]);
        return is_object($decoded) ? '[serialized object omitted]' : self::public_data($decoded);
    }

    private static function database_row_visible(string $table, array $row): bool
    {
        if ($table === 'options') { return !self::private_key($row['option_name']); }
        if (in_array($table, ['postmeta', 'commentmeta', 'usermeta'], true) && self::private_key($row['meta_key'])) { return false; }
        if ($table === 'posts') { return current_user_can('read_post', (int) $row['ID']); }
        if ($table === 'postmeta') { return current_user_can('read_post', (int) $row['post_id']); }
        if ($table === 'comments') { return $row['comment_approved'] === '1' || current_user_can('edit_comment', (int) $row['comment_ID']); }
        if ($table === 'commentmeta') { return current_user_can('edit_comment', (int) $row['comment_id']); }
        if ($table === 'users' || $table === 'usermeta') { $id = (int) ($row['ID'] ?? $row['user_id']); return current_user_can('list_users') && (!is_multisite() || current_user_can('manage_network_users') || is_user_member_of_blog($id)); }
        foreach (['meta_key', 'option_name', 'setting_key', 'key_name'] as $key) { if (isset($row[$key]) && self::private_key((string) $row[$key])) { return false; } }
        return true;
    }

    /** Write SQL is an input facade for typed APIs: no arbitrary database statement is executed. */
    private static function database_write(string $name, array $plan, bool $preview): array
    {
        $where = []; foreach ($plan['where'] as $condition) { if ($condition['operator'] !== '=' || array_key_exists($condition['column'], $where)) { return Operations::error('single_record_required', 'Use one exact ID/key for a data change, or search-replace --dry-run for a bulk text change.'); } $where[$condition['column']] = $condition['value']; }
        $values = $plan['values'];
        if ($name === 'options') {
            if ($plan['type'] === 'INSERT') { if (array_diff(array_keys($values), ['option_name', 'option_value']) || !isset($values['option_name'], $values['option_value'])) { return Operations::error('unsupported_insert_columns'); } $key = $values['option_name']; $value = $values['option_value']; $verb = 'add'; }
            else { if (array_keys($where) !== ['option_name'] || ($plan['type'] === 'UPDATE' && array_keys($values) !== ['option_value'])) { return Operations::error('single_record_required'); } $key = $where['option_name']; $value = $values['option_value'] ?? ''; $verb = $plan['type'] === 'DELETE' ? 'delete' : 'update'; }
            if (!is_string($key) || !is_scalar($value)) { return Operations::error('invalid_input'); }
            return self::settings(['key' => 'option ' . $verb, 'positionals' => $verb === 'delete' ? [$key] : [$key, (string) $value], 'flags' => [], 'input_format' => 'plaintext'], $preview);
        }
        if ($name === 'posts') {
            $fields = ['post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt', 'post_name' => 'slug', 'post_status' => 'status', 'post_type' => 'post_type']; $body = [];
            foreach ($values as $field => $value) { if (!isset($fields[$field]) || !is_scalar($value)) { return Operations::error('unsupported_write_column'); } $body[$fields[$field]] = (string) $value; }
            if ($plan['type'] === 'INSERT') {
                if (($body['status'] ?? 'draft') !== 'draft') { return Operations::error('create_a_draft_first', 'Create a draft, read its version, then request publication as a separate operation.'); }
                $body['post_type'] = $body['post_type'] ?? 'post'; $body['title'] = $body['title'] ?? ''; unset($body['status']);
                if (!in_array($body['post_type'], ['post', 'page'], true)) { return Operations::error('unsupported_post_type'); }
                if (!current_user_can($body['post_type'] === 'page' ? 'edit_pages' : 'edit_posts')) { return Operations::error('permission_denied'); }
                if ($preview) { return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'SQL: create draft', 'name' => self::WRITE, 'description' => 'Create the displayed draft.', 'risk' => 'publish', 'input' => wp_json_encode($body)]]; }
                return Operations::create_draft((object) $body);
            }
            if (array_keys($where) !== ['ID'] || !ctype_digit((string) $where['ID']) || !(int) $where['ID']) { return Operations::error('single_record_required'); }
            $id = (int) $where['ID']; $post = get_post($id);
            if (!$post || !in_array($post->post_type, ['post', 'page'], true) || !current_user_can($plan['type'] === 'DELETE' ? 'delete_post' : 'edit_post', $id)) { return Operations::error('permission_denied'); }
            if ($plan['type'] === 'DELETE') { return self::native(['key' => 'post delete', 'positionals' => [(string) $id], 'flags' => ['force' => true], 'cap' => 'delete_posts'], $preview); }
            if (isset($body['post_type'])) { return Operations::error('post_type_change_not_supported'); }
            $route = '/wp/v2/' . ($post->post_type === 'page' ? 'pages' : 'posts') . '/' . $id;
            $args = (object) ['method' => 'POST', 'route' => $route, 'body_json' => wp_json_encode($body)];
            return $preview ? Engines::rest_preview($args) : Engines::write_rest_route($args);
        }
        return Operations::error('typed_command_required', 'Use wp post meta, wp user, wp term or the owning plugin ability for this change. This SQL facade does not bypass those handlers.');
    }

    private static function unsafe_serialized_tree($value, int $depth = 0): bool
    {
        if ($depth > 32 || is_object($value)) { return true; }
        if (is_array($value)) { foreach ($value as $child) { if (self::unsafe_serialized_tree($child, $depth + 1)) { return true; } } } return false;
    }

    /** Replace values recursively, preserving serialized lengths and leaving credential fields/array keys unchanged. */
    private static function replace_value($value, string $old, string $new, int &$matches, int &$skipped, int &$growth, int $depth = 0)
    {
        if ($depth > 32 || is_object($value)) { $skipped++; return $value; }
        if (is_array($value)) { foreach ($value as $key => $child) { if (!is_string($key) || !self::private_key($key)) { $value[$key] = self::replace_value($child, $old, $new, $matches, $skipped, $growth, $depth + 1); } } return $value; }
        if (!is_string($value)) { return $value; }
        if (is_serialized($value)) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Classes disabled and object/deep trees rejected before any edit; never caller-created PHP objects.
            $decoded = @unserialize($value, ['allowed_classes' => false, 'max_depth' => 32]);
            if (($decoded === false && $value !== 'b:0;') || self::unsafe_serialized_tree($decoded)) { $skipped++; return $value; }
            $next = self::replace_value($decoded, $old, $new, $matches, $skipped, $growth, $depth + 1);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Preserves the existing WordPress serialized representation and byte lengths; object trees were rejected above.
            return $next === $decoded ? $value : serialize($next);
        }
        if (preg_match('/^\s*[\[{]/', $value)) {
            $decoded = json_decode($value, true, 32);
            if (is_array($decoded)) { $next = self::replace_value($decoded, $old, $new, $matches, $skipped, $growth, $depth + 1); return $next === $decoded ? $value : wp_json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        }
        $count = substr_count($value, $old); $needed = max(0, $count * (strlen($new) - strlen($old)));
        if ($needed > $growth) { $skipped++; return $value; } $growth -= $needed;
        $next = str_replace($old, $new, $value); $matches += $count; return $next;
    }

    private static function search_replace(array $args, bool $preview): array
    {
        global $wpdb;
        [$old, $new] = $args['positionals']; $flags = $args['flags']; $dry = !empty($flags['dry-run']);
        if ($old === '' || strlen($old) > 2000 || strlen($new) > 2000) { return Operations::error('invalid_replacement'); }
        foreach (['limit', 'after-id', 'expected-count'] as $field) { if (isset($flags[$field]) && (!ctype_digit($flags[$field]) || strlen($flags[$field]) > 10)) { return Operations::error('invalid_input'); } }
        $limit = (int) ($flags['limit'] ?? 100); $after = (int) ($flags['after-id'] ?? 0);
        if ($limit < 1 || $limit > 100) { return Operations::error('invalid_input', 'Replacement batches support1–100 rows per table; follow returned cursors for more.'); }
        $definitions = self::database_tables(); $selected = array_slice($args['positionals'], 2); $tables = [];
        foreach ($selected as $custom) { $definitions = array_merge($definitions, self::database_tables($custom)); }
        foreach ($definitions as $name => $definition) { if ($definition['search'] && $definition['id'] !== '' && (!$selected || in_array($name, $selected, true) || in_array($definition['table'], $selected, true))) { $tables[$name] = $definition; } }
        foreach ($selected as $table) { if (!isset($tables[$table]) && !in_array($table, array_column($tables, 'table'), true)) { return Operations::error('unsupported_replacement_table', 'Choose the listed WordPress content/settings tables; credentials, users, caches, connection state and arbitrary plugin tables are excluded.'); } }
        $include = isset($flags['include-columns']) ? explode(',', $flags['include-columns']) : [];
        $exclude = isset($flags['skip-columns']) ? explode(',', $flags['skip-columns']) : ['guid'];
        foreach (array_merge($include, $exclude) as $field) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field)) { return Operations::error('invalid_column'); } }
        $known = []; foreach ($tables as $schema) { $known = array_merge($known, $schema['search']); }
        if (array_diff($include, $known) || array_diff($exclude, array_merge($known, ['guid']))) { return Operations::error('unsupported_replacement_column'); }
        foreach ($tables as $name => $schema) { $fields = array_values(array_diff($include ? array_intersect($schema['search'], $include) : $schema['search'], $exclude)); if (!$fields) { unset($tables[$name]); } else { $tables[$name]['replacement_fields'] = $fields; } }
        if ($after > 0 && count($tables) > 1) { return Operations::error('one_table_per_cursor', 'Resume one returned table/cursor at a time; table IDs are not interchangeable.'); }
        $changes = []; $reports = []; $matches = 0; $skipped = 0; $large = 0; $protected = 0; $more = false; $budget = 0; $budget_stop = false;
        foreach ($tables as $name => $schema) {
            $fields = $schema['replacement_fields'];
            $select = []; foreach ($schema['columns'] as $field) { $select[] = in_array($field, $schema['search'], true) ? 'IF(OCTET_LENGTH(`' . $field . '`) > 65536, NULL, `' . $field . '`) AS `' . $field . '`' : '`' . $field . '`'; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers originate in fixed or inspected current-site schemas; cursor and limit are prepared. Never caller SQL.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT ' . implode(', ', $select) . ' FROM `' . $schema['table'] . '` WHERE `' . $schema['id'] . '` > %d ORDER BY `' . $schema['id'] . '` LIMIT %d', $after, $limit + 1), ARRAY_A);
            if ($wpdb->last_error || !is_array($rows)) { return Operations::error('database_unavailable'); }
            $has_more = count($rows) > $limit; $more = $more || $has_more; $rows = array_slice($rows, 0, $limit); $table_matches = 0; $row_changes = 0; $last = $after; $processed = 0;
            foreach ($rows as $row) {
                $previous_cursor = $last; $last = (int) $row[$schema['id']]; $processed++;
                if (!self::database_row_visible($name, $row) || ($name === 'options' && !self::writable_option($row['option_name']))) { $protected++; continue; }
                if ($name === 'posts' || $name === 'postmeta') { $post_id = (int) ($name === 'posts' ? $row['ID'] : $row['post_id']); $post = get_post($post_id); if (!$post || !in_array($post->post_type, ['post', 'page', 'attachment'], true) || !current_user_can('edit_post', $post_id)) { $protected++; continue; } }
                if (($name === 'comments' || $name === 'commentmeta') && !current_user_can('edit_comment', (int) ($row['comment_ID'] ?? $row['comment_id']))) { $protected++; continue; }
                if (in_array($name, ['terms', 'term_taxonomy'], true) && !current_user_can('manage_categories')) { $protected++; continue; }
                $set = []; $before = []; $row_matches = 0;
                foreach ($fields as $field) {
                    if ($row[$field] === null) { $large++; continue; }
                    $count = 0; $skip_before = $skipped; $growth = max(0, 65536 - strlen($row[$field]));
                    $changed = self::replace_value($row[$field], $old, $new, $count, $skipped, $growth);
                    if ($skipped > $skip_before || strlen($changed) > 65536) { if ($skipped === $skip_before) { $skipped++; } continue; }
                    if ($changed !== $row[$field]) { $set[$field] = $changed; $before[$field] = $row[$field]; $row_matches += $count; }
                }
                if ($set) {
                    $bytes = array_sum(array_map('strlen', $set)) + array_sum(array_map('strlen', $before));
                    if ($budget + $bytes > 4194304) { $last = $previous_cursor; $processed--; $has_more = true; $more = true; $budget_stop = true; break; }
                    $budget += $bytes; $table_matches += $row_matches;
                    $context = array_intersect_key($row, array_flip(['post_id', 'option_name', 'comment_id', 'term_id', 'taxonomy']));
                    $changes[] = ['schema' => $schema, 'kind' => $name, 'id' => (int) $row[$schema['id']], 'row' => $context, 'set' => $set, 'before' => $before]; $row_changes++;
                }
            }
            $matches += $table_matches; $reports[] = ['table' => $schema['table'], 'rows_scanned' => $processed, 'rows_with_changes' => $row_changes, 'replacements' => $table_matches, 'has_more' => $has_more, 'next_after_id' => $has_more ? $last : null];
            if ($budget_stop) { break; }
        }
        if (isset($flags['expected-count']) && (int) $flags['expected-count'] !== $matches) { return self::count_changed('replacement_count_changed', (int) $flags['expected-count'], $matches); }
        $result = ['dry_run' => $dry, 'replacements' => $matches, 'rows_with_changes' => count($changes), 'tables' => $reports, 'has_more' => $more, 'unscanned_tables' => array_slice(array_column($tables, 'table'), count($reports)),
            'values_skipped' => $skipped, 'skip_reasons' => 'Serialized objects, excessive depth or output expansion' , 'large_values_omitted' => $large, 'protected_rows_excluded' => $protected,
            'complete_for_eligible_values' => !$more && !$skipped && !$large, 'scope' => 'Eligible text values only; array keys, serialized objects, credential/access/cache/connection settings and unsupported post types are unchanged. Explicit custom tables use their inspected noncredential columns. GUIDs excluded by default. No save_post hooks; rebuild third-party indexes when needed.'];
        if ($dry) { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $result]; }
        if ($preview) { return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'Search and replace', 'name' => self::WRITE,
            'description' => 'Apply this bounded replacement. A fresh scan runs at approval; expected-count can bind the earlier dry-run count.', 'risk' => 'publish', 'input' => wp_json_encode(['old' => $old, 'new' => $new, 'scan' => $result])]]; }
        if (!$changes) { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $result + ['rows_changed' => 0]]; }
        $changed_tables = []; foreach ($changes as $change) { $changed_tables[$change['schema']['table']] = $change['schema']; }
        foreach ($changed_tables as $schema) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live engine verification is required before an atomic replacement batch.
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $schema['table']), ARRAY_A);
            if (!$status || strcasecmp($status['Engine'] ?? '', 'InnoDB') !== 0) { return Operations::error('transactional_tables_required', 'Dry-run is available, but applying this batch requires transactional InnoDB tables.'); }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A bounded atomic replacement; no caller statement enters this transaction.
        if ($wpdb->query('START TRANSACTION') === false) { return Operations::error('database_unavailable'); }
        try {
            foreach ($changes as $change) {
                $set_sql = []; $compare = []; $parameters = [];
                foreach ($change['set'] as $field => $value) { $set_sql[] = '`' . $field . '` = %s'; $parameters[] = $value; }
                $parameters[] = $change['id'];
                foreach ($change['before'] as $field => $value) { $compare[] = 'BINARY `' . $field . '` = BINARY %s'; $parameters[] = $value; }
                $schema = $change['schema']; $sql = 'UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $set_sql) . ' WHERE `' . $schema['id'] . '` = %d AND ' . implode(' AND ', $compare);
                Operations::$mutation_started = true;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed or inspected current-site identifiers only; all replacement and comparison data are prepared, with compare-and-swap against the scanned row.
                if ($wpdb->query($wpdb->prepare($sql, $parameters)) !== 1) { throw new \RuntimeException('Replacement row changed or failed'); }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit acknowledgement determines whether the batch can be reported successful.
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Commit not confirmed'); }
        } catch (\Throwable $error) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Best-effort rollback; ambiguous storage never becomes a successful retryable result.
            $wpdb->query('ROLLBACK'); return self::native_unknown();
        }
        foreach ($changes as $change) {
            if ($change['kind'] === 'posts') { clean_post_cache($change['id']); }
            elseif ($change['kind'] === 'postmeta') { wp_cache_delete((int) $change['row']['post_id'], 'post_meta'); clean_post_cache((int) $change['row']['post_id']); }
            elseif ($change['kind'] === 'options') { wp_cache_delete($change['row']['option_name'], 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options'); }
            elseif ($change['kind'] === 'comments') { clean_comment_cache($change['id']); }
            elseif ($change['kind'] === 'commentmeta') { wp_cache_delete((int) $change['row']['comment_id'], 'comment_meta'); }
            elseif ($change['kind'] === 'terms') { clean_term_cache($change['id']); }
            elseif ($change['kind'] === 'term_taxonomy') { clean_term_cache((int) $change['row']['term_id'], $change['row']['taxonomy']); }
        }
        foreach ($changed_tables as $schema) { if (!empty($schema['custom'])) { wp_cache_flush(); break; } }
        return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $result + ['rows_changed' => count($changes)]];
    }

    /** Official repository administration uses WordPress upgraders and honors host filesystem/capability policy. */
    private static function administration(array $args, bool $preview): array
    {
        $key = $args['key']; $p = $args['positionals']; $f = $args['flags'];
        $ok = static function (array $data): array { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $data]; };
        $pending = ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'WP-CLI: ' . $key,
            'name' => self::WRITE, 'description' => 'Apply the displayed WordPress software change.', 'risk' => 'publish', 'input' => wp_json_encode(['arguments' => $p, 'flags' => $f])]];
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/update.php'; require_once ABSPATH . 'wp-admin/includes/file.php';
        if ($key === 'maintenance-mode status') { return $ok(['core_active' => wp_is_maintenance_mode(), 'custom_maintenance_page' => file_exists(WP_CONTENT_DIR . '/maintenance.php'), 'scope' => 'Core maintenance state; third-party coming-soon settings are not inferred.']); }
        if ($key === 'plugin search') {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            foreach (['per-page' => 20, 'page' => 100] as $field => $max) { if (isset($f[$field]) && (!ctype_digit($f[$field]) || (int) $f[$field] < 1 || (int) $f[$field] > $max)) { return Operations::error('invalid_input'); } }
            $result = plugins_api('query_plugins', ['search' => $p[0], 'per_page' => (int) ($f['per-page'] ?? 20), 'page' => (int) ($f['page'] ?? 1), 'fields' => ['sections' => false, 'short_description' => true]]);
            if (is_wp_error($result)) { return Operations::error('repository_unavailable'); }
            $items = []; foreach ($result->plugins as $plugin) { $items[] = ['name' => $plugin['slug'], 'title' => wp_strip_all_tags($plugin['name']), 'version' => $plugin['version'], 'description' => wp_strip_all_tags($plugin['short_description'])]; }
            return $ok(['items' => $items, 'page' => (int) ($result->info['page'] ?? 1), 'total_pages' => (int) ($result->info['pages'] ?? 1)]);
        }
        if ($key === 'core verify-checksums') {
            $checksums = get_core_checksums(get_bloginfo('version'), get_locale());
            if (!$checksums || !is_array($checksums)) { return Operations::error('checksums_unavailable'); }
            $changed = []; $checked = 0;
            foreach ($checksums as $relative => $expected) {
                if (!is_string($relative) || strpos($relative, '..') !== false || strpos($relative, ':') !== false || $relative[0] === '/' || strpos($relative, 'wp-content/') === 0) { continue; }
                $checked++; if (!is_file(ABSPATH . $relative) || !hash_equals($expected, (string) md5_file(ABSPATH . $relative))) { $changed[] = $relative; }
            }
            return $ok(['verified' => !$changed, 'files_checked' => $checked, 'mismatches' => array_slice($changed, 0, 100), 'scope' => 'Official core-file checksums; custom wp-content files are excluded.']);
        }
        if ($key === 'core update-db') {
            if ($preview) { return $pending; }
            require_once ABSPATH . 'wp-admin/includes/upgrade.php'; Operations::$mutation_started = true; wp_upgrade();
            global $wp_db_version;
            return (int) get_option('db_version') === (int) $wp_db_version ? $ok(['database_version' => $wp_db_version, 'updated' => true]) : self::native_unknown();
        }
        if ($key === 'core check-update' || $key === 'core update') {
            wp_version_check([], true); $updates = get_core_updates();
            if (!is_array($updates) || !$updates) { return Operations::error('update_check_unavailable'); }
            $current = $updates[0];
            if ($key === 'core check-update') { return $ok(['current_version' => get_bloginfo('version'), 'available_version' => $current->current, 'update_available' => $current->response === 'upgrade']); }
            if ($current->response === 'latest') { return $ok(['version' => get_bloginfo('version'), 'up_to_date' => true]); }
            if ($current->response !== 'upgrade') { return Operations::error('unsupported_release_channel'); }
            foreach ((array) $current->packages as $package) { if ($package && !self::official_package($package)) { return Operations::error('unsupported_update_source'); } }
            if ($preview) { return $pending; }
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            if (!WP_Filesystem()) { return Operations::error('filesystem_access_required', 'Use WordPress Updates to configure the filesystem access required by this host.'); }
            Operations::$mutation_started = true;
            $upgrader = new \Core_Upgrader(new \Automatic_Upgrader_Skin()); $saved = $upgrader->upgrade($current, ['attempt_rollback' => true]);
            return is_wp_error($saved) || !$saved ? self::native_unknown() : $ok(['version' => $saved, 'database_update' => 'Run wp core update-db next if WordPress requires it.']);
        }
        $theme = strpos($key, 'theme ') === 0; $install = substr($key, -8) === ' install';
        $slug = $p[0];
        if (!preg_match('/^[a-zA-Z0-9_.\/-]{1,191}$/D', $slug) || strpos($slug, '..') !== false || $slug[0] === '/') { return Operations::error('invalid_software_name'); }
        $file = null; $object = null;
        if ($theme) { $object = wp_get_theme($slug); if (!$install && !$object->exists()) { return Operations::error('theme_not_found'); } }
        else {
            foreach (get_plugins() as $entry => $metadata) { if ($slug === $entry || $slug === (strpos($entry, '/') !== false ? dirname($entry) : basename($entry, '.php'))) { $file = $entry; $object = $metadata; break; } }
            if (!$install && !$file) { return Operations::error('plugin_not_found'); }
            if ($file && strpos($file, basename(untrailingslashit(WPAICG_PLUGIN_DIR)) . '/') === 0 && !self::NATIVE[$key][0]) { return Operations::error('active_connection_plugin', 'Disconnect website management and use WordPress to change AI Puffer itself.'); }
        }
        if (!$theme && strpos($key, 'plugin auto-updates ') === 0) {
            if (is_multisite() && !current_user_can('manage_network_plugins')) { return Operations::error('permission_denied'); }
            $enabled = (array) get_site_option('auto_update_plugins', []);
            if ($key === 'plugin auto-updates status') { return $ok(['file' => $file, 'enabled' => in_array($file, $enabled, true)]); }
            if ($preview) { return $pending; } Operations::$mutation_started = true;
            $enabled = array_values(array_diff($enabled, [$file])); if ($key === 'plugin auto-updates enable') { $enabled[] = $file; }
            update_site_option('auto_update_plugins', $enabled);
            return (array) get_site_option('auto_update_plugins', []) === $enabled ? $ok(['file' => $file, 'enabled' => $key === 'plugin auto-updates enable']) : self::native_unknown();
        }
        if ($key === 'plugin verify-checksums') {
            $repo_slug = strpos($file, '/') !== false ? dirname($file) : basename($file, '.php');
            $response = wp_remote_get(add_query_arg(['slug' => $repo_slug, 'version' => $object['Version']], 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($repo_slug) . '/' . rawurlencode($object['Version']) . '.json'), ['timeout' => 10]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return Operations::error('checksums_unavailable', 'This installed version has no published WordPress.org checksums.'); }
            $body = json_decode(wp_remote_retrieve_body($response), true); if (!is_array($body['files'] ?? null)) { return Operations::error('checksums_unavailable'); }
            $base = strpos($file, '/') !== false ? WP_PLUGIN_DIR . '/' . dirname($file) . '/' : WP_PLUGIN_DIR . '/'; $changed = []; $count = 0;
            foreach ($body['files'] as $relative => $hashes) { if (strpos($relative, '..') !== false || strpos($relative, ':') !== false || $relative[0] === '/') { continue; } $count++; if (!is_file($base . $relative) || !in_array(md5_file($base . $relative), (array) ($hashes['md5'] ?? []), true)) { $changed[] = $relative; } }
            return $ok(['name' => $repo_slug, 'verified' => !$changed && $count > 0, 'files_checked' => $count, 'mismatches' => array_slice($changed, 0, 100)]);
        }
        if ($key === 'plugin activate' || $key === 'plugin deactivate') {
            if (is_plugin_active_for_network($file)) { return Operations::error('network_managed_plugin'); }
            if ($preview) { return $pending; } Operations::$mutation_started = true;
            $result = $key === 'plugin activate' ? activate_plugin($file) : deactivate_plugins($file, false, false);
            return !is_wp_error($result) && is_plugin_active($file) === ($key === 'plugin activate') ? $ok(['file' => $file, 'active' => is_plugin_active($file)]) : self::native_unknown();
        }
        if ($key === 'plugin uninstall') {
            if (is_plugin_active_for_network($file)) { return Operations::error('network_managed_plugin'); }
            if (is_plugin_active($file) && (empty($f['deactivate']) || !current_user_can('activate_plugins'))) { return Operations::error('deactivate_first'); }
            if ($preview) { return $pending; }
            if (empty($f['skip-delete']) && !WP_Filesystem()) { return Operations::error('filesystem_access_required'); }
            Operations::$mutation_started = true;
            if (is_plugin_active($file)) { deactivate_plugins($file); }
            if (!empty($f['skip-delete'])) { uninstall_plugin($file); return $ok(['file' => $file, 'uninstalled' => true, 'files_kept' => true]); }
            $deleted = delete_plugins([$file]); wp_clean_plugins_cache();
            return !is_wp_error($deleted) && !isset(get_plugins()[$file]) ? $ok(['file' => $file, 'deleted' => true]) : self::native_unknown();
        }
        if ($key === 'theme activate') {
            if ($object->errors() || !$object->is_allowed()) { return Operations::error('theme_unavailable'); }
            if ($preview) { return $pending; } Operations::$mutation_started = true; switch_theme($slug);
            return get_stylesheet() === $slug ? $ok(['name' => $slug, 'active' => true]) : self::native_unknown();
        }
        if ($key === 'theme delete') {
            if (in_array($slug, [get_stylesheet(), get_template()], true)) { return Operations::error('active_theme_in_use'); }
            if ($preview) { return $pending; } if (!WP_Filesystem()) { return Operations::error('filesystem_access_required'); }
            Operations::$mutation_started = true; $deleted = delete_theme($slug); wp_clean_themes_cache();
            return !is_wp_error($deleted) && !wp_get_theme($slug)->exists() ? $ok(['name' => $slug, 'deleted' => true]) : self::native_unknown();
        }
        if ($install) {
            if (!preg_match('/^[a-z0-9-]+$/D', $slug) || ($theme ? $object->exists() : $file !== null)) { return Operations::error('invalid_or_installed_software'); }
            if (!empty($f['activate']) && !current_user_can($theme ? 'switch_themes' : 'activate_plugins')) { return Operations::error('permission_denied'); }
            require_once ABSPATH . 'wp-admin/includes/' . ($theme ? 'theme-install.php' : 'plugin-install.php');
            $info = $theme ? themes_api('theme_information', ['slug' => $slug, 'fields' => ['versions' => true]]) : plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['versions' => true]]);
            if (is_wp_error($info)) { return Operations::error('repository_unavailable'); }
            $package = isset($f['version']) ? ($info->versions[$f['version']] ?? '') : ($info->download_link ?? '');
            if (!$package || !self::official_package($package)) { return Operations::error('unsupported_release_or_source'); }
            if ($preview) { return $pending; }
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; if (!WP_Filesystem()) { return Operations::error('filesystem_access_required'); }
            Operations::$mutation_started = true; $upgrader = $theme ? new \Theme_Upgrader(new \Automatic_Upgrader_Skin()) : new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
            $installed = $upgrader->install($package); if (is_wp_error($installed) || !$installed) { return self::native_unknown(); }
            $installed_name = $theme ? $slug : $upgrader->plugin_info(); wp_clean_plugins_cache(); wp_clean_themes_cache();
            if ($theme ? !wp_get_theme($slug)->exists() : (!is_string($installed_name) || !isset(get_plugins()[$installed_name]))) { return self::native_unknown(); }
            if (!empty($f['activate'])) { if ($theme) { switch_theme($slug); if (get_stylesheet() !== $slug) { return self::native_unknown(); } } else { $activated = activate_plugin($installed_name); if (is_wp_error($activated) || !is_plugin_active($installed_name)) { return self::native_unknown(); } } }
            return $ok(['name' => $installed_name, 'installed' => true, 'active' => $theme ? get_stylesheet() === $slug : is_plugin_active($installed_name)]);
        }
        // Update only the selected installed item. Package hosts are checked before entering WordPress's upgrader.
        if ($theme) { wp_update_themes(); $state = get_site_transient('update_themes'); $update = $state->response[$slug] ?? null; }
        else { wp_update_plugins(); $state = get_site_transient('update_plugins'); $update = $state->response[$file] ?? null; }
        if (!$update) { return $ok(['name' => $slug, 'up_to_date' => true]); }
        $package = $theme ? ($update['package'] ?? '') : ($update->package ?? '');
        if (!$package || !self::official_package($package)) { return Operations::error('unsupported_update_source', 'This command supports official WordPress.org packages. Use the vendor’s normal updater for private packages.'); }
        if ($preview) { return $pending; } require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; if (!WP_Filesystem()) { return Operations::error('filesystem_access_required'); }
        Operations::$mutation_started = true; $upgrader = $theme ? new \Theme_Upgrader(new \Automatic_Upgrader_Skin()) : new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $upgraded = $upgrader->upgrade($theme ? $slug : $file); wp_clean_plugins_cache(); wp_clean_themes_cache();
        $expected = $theme ? ($update['new_version'] ?? '') : ($update->new_version ?? '');
        $version = $theme ? wp_get_theme($slug)->get('Version') : (get_plugins()[$file]['Version'] ?? '');
        return is_wp_error($upgraded) || !$upgraded || !$expected || $version !== $expected ? self::native_unknown() : $ok(['name' => $slug, 'updated' => true, 'version' => $version]);
    }

    private static function official_package(string $url): bool
    {
        $parts = wp_parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), ['downloads.wordpress.org', 'wordpress.org'], true)
            && empty($parts['user']) && empty($parts['pass']) && empty($parts['port']);
    }

    /** Cache/maintenance commands call the installed software's APIs, never shell processes. */
    private static function maintenance(array $args, bool $preview): array
    {
        global $wp_rewrite;
        $key = $args['key']; $p = $args['positionals']; $f = $args['flags'];
        $ok = static function (array $data): array { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $data]; };
        $pending = ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'WP-CLI: ' . $key,
            'name' => self::WRITE, 'description' => 'Run the displayed maintenance command.', 'risk' => 'publish', 'input' => wp_json_encode(['arguments' => $p, 'flags' => $f])]];
        if ($key === 'cache type') { return $ok(['object_cache' => wp_using_ext_object_cache() ? 'external' : 'default', 'drop_in' => file_exists(WP_CONTENT_DIR . '/object-cache.php')]); }
        if ($key === 'cron test') {
            // An unmatched random cron key lets wp-cron.php answer without executing pending callbacks or changing its lock.
            $response = wp_remote_post(add_query_arg('doing_wp_cron', 'aipuffer-probe-' . wp_generate_uuid4(), site_url('wp-cron.php')), ['timeout' => 8, 'redirection' => 0]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return Operations::error('cron_loopback_unavailable', 'WordPress could not confirm its cron HTTP endpoint. No scheduled jobs were requested.'); }
            return $ok(['http_status' => 200, 'automatic_spawning_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'alternate_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
                'callbacks_executed' => false, 'scope' => 'HTTP reachability only; this does not prove a system scheduler or individual callback is working.']);
        }
        if ($key === 'cron event list' || $key === 'cron event run' || $key === 'cron event delete') {
            $selected = $f['hook'] ?? ($p[0] ?? null); $events = [];
            if ($selected !== null && !preg_match('/^[a-zA-Z0-9_.:\\-]{1,191}$/D', $selected)) { return Operations::error('invalid_cron_hook'); }
            foreach (_get_cron_array() ?: [] as $timestamp => $hooks) {
                foreach ($hooks as $hook => $instances) { if ($selected !== null && $hook !== $selected) { continue; }
                    foreach ($instances as $event) { $events[] = ['hook' => $hook, 'timestamp' => (int) $timestamp, 'schedule' => $event['schedule'], 'interval' => (int) ($event['interval'] ?? 0), 'args' => $event['args']]; if (count($events) > 100) { break 3; } }
                }
            }
            if ($key === 'cron event list') { $items = array_slice($events, 0, 100); foreach ($items as &$listed) { $listed['argument_count'] = count($listed['args']); unset($listed['args']); } unset($listed); return $ok(['items' => $items, 'limit' => 100, 'has_more' => count($events) > 100]); }
            if (self::private_key($selected) || strpos($selected, 'aipkit_') === 0) { return Operations::error('connection_cron_protected'); }
            if (!$events) { return Operations::error('cron_event_not_found'); }
            if (count($events) > 100) { return Operations::error('too_many_events', 'Manage this unusually large hook through WordPress.'); }
            if ($key === 'cron event run' && has_action($selected) === false) { return Operations::error('cron_callback_not_registered'); }
            if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            if ($key === 'cron event delete') { $deleted = wp_unschedule_hook($selected, true); return is_wp_error($deleted) || $deleted === false ? self::native_unknown() : $ok(['hook' => $selected, 'events_removed' => $deleted]); }
            $event = $events[0];
            if ($event['schedule']) {
                // Core's rescheduler uses now+interval for a future event, which can collide with that event itself.
                $scheduled = $event['timestamp'] > time() && $event['interval'] > 0
                    ? wp_schedule_event($event['timestamp'] + $event['interval'], $event['schedule'], $selected, $event['args'], true)
                    : wp_reschedule_event($event['timestamp'], $event['schedule'], $selected, $event['args'], true);
                if (is_wp_error($scheduled) || !$scheduled) { return self::native_unknown(); }
            }
            $removed = wp_unschedule_event($event['timestamp'], $selected, $event['args'], true);
            if (is_wp_error($removed) || !$removed) { return self::native_unknown(); }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Explicit administrator command executes one existing WordPress cron hook, not a new plugin hook.
            do_action_ref_array($selected, $event['args']);
            return $ok(['hook' => $selected, 'events_executed' => 1, 'remaining_instances' => max(0, count($events) - 1), 'recurring_rescheduled' => (bool) $event['schedule']]);
        }
        if (strpos($key, 'rewrite ') === 0) {
            if ($key === 'rewrite list') {
                $items = []; foreach ($wp_rewrite->rewrite_rules() ?: [] as $match => $query) { if (isset($f['match']) && @preg_match('#^' . str_replace('#', '\#', $match) . '#', $f['match']) !== 1) { continue; } $items[] = ['match' => $match, 'query' => $query]; if (count($items) === 100) { break; } }
                return $ok(['items' => $items, 'limit' => 100]);
            }
            if ($key === 'rewrite structure') {
                $structure = $p[0];
                if (strlen($structure) > 300 || !preg_match('#^/[a-zA-Z0-9_/%.-]+/$#D', $structure)) { return Operations::error('invalid_permalink_structure'); }
                preg_match_all('/%([^%]+)%/', $structure, $tags);
                if (!$tags[1] || array_diff($tags[1], ['year', 'monthnum', 'day', 'hour', 'minute', 'second', 'post_id', 'postname', 'category', 'author'])) { return Operations::error('invalid_permalink_tag'); }
                foreach ($f as $base) { if (!preg_match('#^[a-zA-Z0-9_/-]{1,150}$#D', $base) || strpos($base, '..') !== false) { return Operations::error('invalid_taxonomy_base'); } }
            }
            if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            if ($key === 'rewrite structure') { $wp_rewrite->set_permalink_structure($p[0]); if (isset($f['category-base'])) { $wp_rewrite->set_category_base($f['category-base']); } if (isset($f['tag-base'])) { $wp_rewrite->set_tag_base($f['tag-base']); } }
            $wp_rewrite->flush_rules(false);
            if ($key === 'rewrite structure' && (get_option('permalink_structure') !== $p[0]
                || (isset($f['category-base']) && get_option('category_base') !== $f['category-base'])
                || (isset($f['tag-base']) && get_option('tag_base') !== $f['tag-base']))) { return self::native_unknown(); }
            return $ok(['permalink_structure' => get_option('permalink_structure'), 'server_files_written' => false, 'scope' => 'WordPress rewrite rules; host-level routing is unchanged.']);
        }
        $adapters = [
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public LiteSpeed cache purge hook belongs to that plugin.
            'litespeed-purge' => ['available' => defined('LSCWP_V') && has_action('litespeed_purge_all') !== false, 'call' => static function () { do_action('litespeed_purge_all'); }],
            'elementor flush-css' => ['available' => class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->files_manager), 'call' => static function () { \Elementor\Plugin::$instance->files_manager->clear_cache(); }],
            'rocket clean' => ['available' => function_exists('rocket_clean_domain'), 'call' => static function () { return rocket_clean_domain(); }],
            'sg purge' => ['available' => function_exists('sg_cachepress_purge_cache'), 'call' => static function () { return sg_cachepress_purge_cache(); }],
            'super-cache flush' => ['available' => function_exists('wp_cache_clear_cache'), 'call' => static function () { return wp_cache_clear_cache(); }],
            'w3-total-cache flush' => ['available' => function_exists('w3tc_flush_all'), 'call' => static function () { return w3tc_flush_all(); }],
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public Breeze cache purge hook belongs to that plugin.
            'breeze purge' => ['available' => class_exists('Breeze_PurgeCache') && has_action('breeze_clear_all_cache') !== false, 'call' => static function () { do_action('breeze_clear_all_cache'); }],
        ];
        $adapters['elementor flush_css'] = $adapters['elementor flush-css'];
        if ($key === 'cache flush' || $key === 'cache purge') {
            if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            if (!wp_cache_flush()) { return self::native_unknown(); }
            $requested = ['wordpress-object-cache'];
            if ($key === 'cache purge') { foreach ($adapters as $name => $adapter) { if ($name === 'elementor flush_css' || !$adapter['available']) { continue; } if ($adapter['call']() === false) { return self::native_unknown(); } $requested[] = $name; } }
            return $ok(['requested' => $requested, 'scope' => 'Installed local cache APIs only. External CDN caches are not verified.']);
        }
        if (!isset($adapters[$key]) || !$adapters[$key]['available']) { return Operations::error('cache_plugin_unavailable', 'The required cache plugin and its purge API are not active on this site.'); }
        if ($preview) { return $pending; }
        Operations::$mutation_started = true;
        return $adapters[$key]['call']() === false ? self::native_unknown() : $ok(['requested' => [$key], 'scope' => 'The installed plugin purge API was invoked; external cache delivery is not verified.']);
    }

    /** User management never returns passwords, sessions, credential metadata or generated login credentials. */
    private static function users(array $args, bool $preview): array
    {
        $key = $args['key']; $p = $args['positionals']; $f = $args['flags']; $write = !self::NATIVE[$key][0];
        $actor = wp_get_current_user();
        $ok = static function (array $value): array { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $value]; };
        $pending = ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'WP-CLI: ' . $key,
            'name' => self::WRITE, 'description' => 'Apply the displayed user or role change.', 'risk' => 'publish', 'input' => wp_json_encode(['arguments' => $p, 'flags' => $f])]];
        $user_data = static function (\WP_User $u): array { return ['id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'display_name' => $u->display_name, 'roles' => array_values($u->roles), 'registered' => $u->user_registered]; };
        $can_grant = static function (array $caps) use ($actor): bool {
            foreach ($caps as $cap => $grant) { if ($grant && empty($actor->allcaps[$cap])) { return false; } } return true;
        };
        if ($key === 'role list') {
            $items = []; foreach (wp_roles()->roles as $slug => $definition) { $items[] = ['name' => $slug, 'label' => $definition['name'], 'capability_count' => count(array_filter($definition['capabilities']))]; }
            return $ok(['items' => $items]);
        }
        if ($key === 'user list') {
            $query = ['number' => 100, 'offset' => 0, 'orderby' => 'ID', 'order' => 'ASC'];
            foreach (['number', 'offset'] as $field) {
                if (isset($f[$field])) { if (!ctype_digit($f[$field]) || (int) $f[$field] > ($field === 'number' ? 100 : 100000) || ($field === 'number' && (int) $f[$field] < 1)) { return Operations::error('invalid_input'); } $query[$field] = (int) $f[$field]; }
            }
            foreach (['role', 'search'] as $field) { if (isset($f[$field])) { $query[$field] = $f[$field]; } }
            $found = new \WP_User_Query($query);
            return $ok(['items' => array_map($user_data, $found->get_results()), 'total' => (int) $found->get_total(), 'offset' => $query['offset'], 'limit' => $query['number']]);
        }
        if (strpos($key, 'role ') === 0 || strpos($key, 'cap ') === 0) {
            $slug = $p[0]; if (sanitize_key($slug) !== $slug || $slug === '') { return Operations::error('invalid_role'); }
            $role = get_role($slug);
            if ($key === 'cap list') {
                if (!$role) { return Operations::error('role_not_found'); }
                $items = []; foreach ($role->capabilities as $cap => $grant) { $items[] = ['name' => $cap, 'grant' => (bool) $grant]; } return $ok(['items' => $items]);
            }
            if ($key !== 'role create' && !$role) { return Operations::error('role_not_found'); }
            if ($key === 'role create') {
                if ($role) { return Operations::error('role_already_exists'); }
                $copy = isset($f['clone']) ? get_role($f['clone']) : null;
                if (isset($f['clone']) && !$copy) { return Operations::error('role_not_found'); }
                $caps = $copy ? $copy->capabilities : [];
                if (!$can_grant($caps)) { return Operations::error('permission_denied'); }
                if ($preview) { return $pending; }
                Operations::$mutation_started = true; $created = add_role($slug, sanitize_text_field($p[1] ?? $slug), $caps);
                $saved = get_option(wp_roles()->role_key, []);
                return $created && isset($saved[$slug]) && $saved[$slug]['capabilities'] === $caps ? $ok(['name' => $slug, 'created' => true]) : self::native_unknown();
            }
            // A connected administrator must not remove their own access while this change is being recorded.
            if (in_array($slug, $actor->roles, true) && in_array($key, ['role delete', 'cap remove', 'cap add'], true)) { return Operations::error('connected_role_in_use', 'Use another administrator connection to change the connected role.'); }
            if ($key === 'role delete' && $slug === 'administrator') { return Operations::error('administrator_role_required'); }
            if ($key === 'role reset') {
                if (!in_array($slug, ['administrator', 'editor', 'author', 'contributor', 'subscriber'], true)) { return Operations::error('no_default_for_custom_role'); }
                require_once ABSPATH . 'wp-admin/includes/schema.php';
                global $wp_roles; $original = $wp_roles;
                try { $wp_roles = clone wp_roles(); $wp_roles->use_db = false; $wp_roles->roles = []; $wp_roles->role_objects = []; $wp_roles->role_names = []; populate_roles(); $caps = $wp_roles->roles[$slug]['capabilities']; }
                finally { $wp_roles = $original; }
                if (!$can_grant($caps)) { return Operations::error('permission_denied'); }
            } elseif ($key === 'cap add' || $key === 'cap remove') {
                if (isset($f['grant']) && !in_array($f['grant'], ['true', 'false', '1', '0'], true)) { return Operations::error('invalid_input'); }
                $grant = !isset($f['grant']) || in_array($f['grant'], ['true', '1'], true); $caps = $role->capabilities;
                foreach (array_slice($p, 1) as $cap) {
                    if (sanitize_key($cap) !== $cap || $cap === '') { return Operations::error('invalid_capability'); }
                    if ($key === 'cap add') { if ($grant && empty($actor->allcaps[$cap])) { return Operations::error('permission_denied'); } $caps[$cap] = $grant; }
                    else { unset($caps[$cap]); }
                }
            }
            if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            if ($key === 'role delete') { remove_role($slug); $saved = get_option(wp_roles()->role_key, []); return !isset($saved[$slug]) ? $ok(['name' => $slug, 'deleted' => true]) : self::native_unknown(); }
            $roles = wp_roles()->roles; $roles[$slug]['capabilities'] = $caps;
            update_option(wp_roles()->role_key, $roles);
            $saved = get_option(wp_roles()->role_key, []); wp_roles()->for_site();
            return ($saved[$slug]['capabilities'] ?? null) === $caps ? $ok(['name' => $slug, 'updated' => true]) : self::native_unknown();
        }
        if ($key === 'user create') {
            $login = $p[0]; $email = $p[1]; $role_name = $f['role'] ?? 'subscriber'; $role = get_role($role_name);
            if (sanitize_user($login, true) !== $login || $login === '' || strlen($login) > 60 || !is_email($email) || username_exists($login) || email_exists($email)) { return Operations::error('invalid_or_existing_user'); }
            if (!$role || !current_user_can('promote_users') || !$can_grant($role->capabilities)) { return Operations::error('permission_denied'); }
            if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            $new = wp_insert_user(wp_slash(['user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(32, true, true), 'role' => $role_name] + array_intersect_key($f, array_flip(['display_name', 'first_name', 'last_name']))));
            $fresh = !is_wp_error($new) ? get_userdata($new) : null;
            return !$fresh || !in_array($role_name, $fresh->roles, true) ? self::native_unknown() : $ok($user_data($fresh) + ['credentials' => 'No password returned. Use WordPress password reset if sign-in is needed.']);
        }
        $user = ctype_digit($p[0]) ? get_user_by('id', (int) $p[0]) : (get_user_by('login', $p[0]) ?: get_user_by('email', $p[0]));
        if (!$user) { return Operations::error('user_not_found'); }
        if (is_multisite() && !current_user_can('manage_network_users') && !is_user_member_of_blog($user->ID)) { return Operations::error('user_outside_site'); }
        if ($key === 'user get') { return $ok($user_data($user)); }
        if ($write && !current_user_can('edit_user', $user->ID)) { return Operations::error('permission_denied'); }
        if (strpos($key, 'user meta ') === 0) {
            $verb = substr($key, 10); $meta = $p[1] ?? '';
            if ($verb !== 'list' && (self::private_key($meta) || !preg_match('/^[a-zA-Z0-9_.:-]{1,172}$/D', $meta))) { return Operations::error('protected_user_metadata'); }
            if ($verb === 'list') {
                $items = []; $selected = isset($f['keys']) ? explode(',', $f['keys']) : [];
                foreach (get_user_meta($user->ID) as $name => $values) { if (self::private_key($name) || ($selected && !in_array($name, $selected, true))) { continue; } $items[] = ['name' => $name, 'values' => self::public_data(array_map('maybe_unserialize', $values))]; }
                return $ok(['items' => array_slice($items, 0, 100), 'limit' => 100]);
            }
            if ($verb === 'get') { return metadata_exists('user', $user->ID, $meta) ? $ok(['id' => $user->ID, 'key' => $meta, 'value' => self::public_data(get_user_meta($user->ID, $meta, true))]) : Operations::error('metadata_not_found'); }
            if ($verb === 'delete' && !metadata_exists('user', $user->ID, $meta)) { return Operations::error('metadata_not_found'); }
            $value = $p[2] ?? ''; if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            if ($verb === 'add') { $saved = add_user_meta($user->ID, $meta, wp_slash($value)); }
            elseif ($verb === 'update') { $saved = update_user_meta($user->ID, $meta, wp_slash($value)); if ($saved === false && get_user_meta($user->ID, $meta, true) === $value) { $saved = true; } }
            else { $saved = count($p) === 3 ? delete_user_meta($user->ID, $meta, wp_slash($value)) : delete_user_meta($user->ID, $meta); }
            return $saved ? $ok(['id' => $user->ID, 'key' => $meta, 'updated' => true]) : self::native_unknown();
        }
        if ($key === 'user delete') {
            if ($user->ID === $actor->ID || !current_user_can(is_multisite() ? 'remove_user' : 'delete_user', $user->ID)) { return Operations::error('permission_denied'); }
            $reassign = isset($f['reassign']) && ctype_digit($f['reassign']) ? get_userdata((int) $f['reassign']) : null;
            if (isset($f['reassign']) && (!$reassign || $reassign->ID === $user->ID)) { return Operations::error('invalid_reassignment_user'); }
            if ($preview) { return $pending; }
            require_once ABSPATH . 'wp-admin/includes/user.php'; Operations::$mutation_started = true;
            $deleted = is_multisite() ? remove_user_from_blog($user->ID, get_current_blog_id(), $reassign ? $reassign->ID : 0) : wp_delete_user($user->ID, $reassign ? $reassign->ID : null);
            $confirmed = is_multisite() ? !is_user_member_of_blog($user->ID) : !get_userdata($user->ID);
            return !is_wp_error($deleted) && $deleted && $confirmed ? $ok(['id' => $user->ID, 'deleted' => !is_multisite(), 'removed_from_site' => is_multisite(), 'reassigned_to' => $reassign ? $reassign->ID : null]) : self::native_unknown();
        }
        if ($key === 'user update') {
            if (isset($f['user_email']) && !is_email($f['user_email'])) { return Operations::error('invalid_email'); }
            if (isset($f['role'])) { $role = get_role($f['role']); if (!$role || !current_user_can('promote_user', $user->ID) || !$can_grant($role->capabilities) || $user->ID === $actor->ID) { return Operations::error('permission_denied'); } }
            if (!$f) { return Operations::error('no_changes'); }
            if ($preview) { return $pending; }
            Operations::$mutation_started = true; $saved = wp_update_user(wp_slash(['ID' => $user->ID] + $f));
            $fresh = !is_wp_error($saved) ? get_userdata($user->ID) : null;
            return !$fresh || (isset($f['role']) && !in_array($f['role'], $fresh->roles, true)) ? self::native_unknown() : $ok($user_data($fresh));
        }
        if ($user->ID === $actor->ID || !current_user_can('promote_user', $user->ID)) { return Operations::error('connected_user_in_use'); }
        if (strpos($key, '-role') !== false) {
            $role = get_role($p[1]); if (!$role || !$can_grant($role->capabilities)) { return Operations::error('permission_denied'); }
            if ($preview) { return $pending; }
            Operations::$mutation_started = true;
            if ($key === 'user set-role') { $user->set_role($p[1]); } elseif ($key === 'user add-role') { $user->add_role($p[1]); } else { $user->remove_role($p[1]); }
            $fresh = get_userdata($user->ID);
            $present = in_array($p[1], $fresh->roles, true);
            return $present === ($key !== 'user remove-role') ? $ok($user_data($fresh)) : self::native_unknown();
        }
        foreach (array_slice($p, 1) as $cap) { if (sanitize_key($cap) !== $cap || $cap === '' || ($key === 'user add-cap' && empty($actor->allcaps[$cap]))) { return Operations::error('permission_denied'); } }
        if ($preview) { return $pending; }
        Operations::$mutation_started = true;
        foreach (array_slice($p, 1) as $cap) { if ($key === 'user add-cap') { $user->add_cap($cap); } else { $user->remove_cap($cap); } }
        $fresh = get_userdata($user->ID);
        foreach (array_slice($p, 1) as $cap) { if ($key === 'user add-cap' ? empty($fresh->caps[$cap]) : isset($fresh->caps[$cap])) { return self::native_unknown(); } }
        return $ok(['id' => $user->ID, 'updated' => true]);
    }

    /** Typed settings operations. No caller-supplied serialization or executable PHP. */
    private static function settings(array $args, bool $preview): array
    {
        global $wpdb;
        $key = $args['key']; $p = $args['positionals']; $f = $args['flags'];
        $preview_input = ['arguments' => $p, 'flags' => $f, 'value_format' => $args['input_format'] ?? 'plaintext'];
        $write = !self::NATIVE[$key][0]; $theme = strpos($key, 'theme mod ') === 0; $transient = strpos($key, 'transient ') === 0;
        $verb = substr($key, strrpos($key, ' ') + 1); $patch = $key === 'option patch';
        if ($patch) { $verb = array_shift($p); if (!in_array($verb, ['insert', 'update', 'delete'], true)) { return Operations::error('invalid_patch_action'); } }
        $valid = static function (string $name): bool { return (bool) preg_match('/^[a-zA-Z0-9_.:-]{1,172}$/D', $name) && !self::private_key($name); };
        $ok = static function (array $data): array { return ['state' => 'succeeded', 'actions_used' => 1, 'result' => self::public_data($data)]; };
        if ($verb === 'list') {
            if ($theme) {
                $items = []; foreach ((array) get_theme_mods() as $name => $value) { if ($valid((string) $name)) { $items[] = ['name' => $name, 'value' => self::public_data($value)]; } }
                return $ok(['items' => array_slice($items, 0, 100), 'limit' => 100]);
            }
            $search = $f['search'] ?? '*';
            $pattern = str_replace(['*', '?'], ['%', '_'], $wpdb->esc_like($search));
            $pattern = $transient ? $wpdb->esc_like('_transient_') . $pattern : $pattern;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded live option-name enumeration; WordPress owns the table and user filter is prepared.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 101", $pattern), ARRAY_A);
            if ($wpdb->last_error || !is_array($rows)) { return Operations::error('settings_unavailable'); }
            if (isset($f['autoload']) && !in_array($f['autoload'], ['yes', 'no', 'on', 'off', 'true', 'false', '1', '0'], true)) { return Operations::error('invalid_input'); }
            $items = [];
            foreach ($rows as $row) {
                $name = $transient ? substr($row['option_name'], 11) : $row['option_name'];
                if (!$valid($name) || (!$transient && strpos($name, '_transient_') === 0) || ($transient && strpos($name, 'timeout_') === 0)) { continue; }
                $autoload = !in_array($row['autoload'], ['off', 'no', 'auto-off'], true);
                if (isset($f['autoload']) && $autoload !== in_array($f['autoload'], ['yes', 'on', 'true', '1'], true)) { continue; }
                $value = $transient ? get_transient($name) : get_option($name);
                if ($transient && $value === false) { continue; }
                $items[] = ['name' => $name, 'value' => self::public_data($value), 'autoload' => $autoload];
                if (count($items) === 100) { break; }
            }
            return $ok(['items' => $items, 'limit' => 100, 'possibly_truncated' => count($rows) > 100,
                'scope' => $transient && wp_using_ext_object_cache() ? 'database-backed transient names only; external cache enumeration is unavailable' : 'database']);
        }
        $name = array_shift($p);
        if (!$valid($name) || ($write && !$theme && !$transient && !self::writable_option($name))) { return Operations::error('protected_setting', 'Use the dedicated WordPress command for access, plugin, theme or scheduled-task changes. Credentials and connection internals are never exposed.'); }
        $missing = new \stdClass();
        $current = $theme ? get_theme_mod($name, $missing) : ($transient ? get_transient($name) : get_option($name, $missing));
        $exists = $transient ? $current !== false : $current !== $missing;
        if (!$write) {
            if (!$exists) { return Operations::error('setting_not_found'); }
            foreach ($p as $segment) {
                if (self::private_key($segment) || !is_array($current) || !array_key_exists($segment, $current)) { return Operations::error('setting_path_not_found'); }
                $current = $current[$segment];
            }
            return $ok(['name' => $name, 'value' => self::public_data($current)]);
        }
        if (isset($f['autoload']) && !in_array($f['autoload'], ['yes', 'no', 'on', 'off', 'true', 'false', '1', '0'], true)) { return Operations::error('invalid_input'); }
        $autoload = isset($f['autoload']) ? in_array($f['autoload'], ['yes', 'on', 'true', '1'], true) : null;
        $decode = static function (string $raw) use ($args) {
            if (($args['input_format'] ?? '') !== 'json') { return $raw; }
            $value = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) { throw new \InvalidArgumentException('Invalid JSON value. For literal text omit --format=json or use --format=plaintext; for typed JSON strings include their JSON double quotes.'); }
            return $value;
        };
        if (($verb === 'add' || $verb === 'insert') && !$patch && $exists) { return Operations::error('setting_already_exists'); }
        if (($verb === 'delete' || $patch) && !$exists) { return Operations::error('setting_not_found'); }
        if ($patch) {
            if ($verb !== 'delete' && count($p) < 2) { return Operations::error('invalid_patch_path'); }
            $value = $verb === 'delete' ? null : $decode(array_pop($p));
            if (!is_array($current) || !$p || self::public_data($value) !== $value) { return Operations::error('invalid_patch_value'); }
            $next = $current; $node =& $next; $last = array_pop($p);
            foreach ($p as $segment) {
                if (self::private_key($segment) || !isset($node[$segment]) || !is_array($node[$segment])) { return Operations::error('setting_path_not_found'); }
                $node =& $node[$segment];
            }
            if (self::private_key($last) || ($verb === 'insert' ? array_key_exists($last, $node) : !array_key_exists($last, $node))) { return Operations::error('invalid_patch_path'); }
            if ($verb === 'delete') { unset($node[$last]); } else { $node[$last] = $value; }
            unset($node);
        } elseif ($verb !== 'delete') {
            $next = $decode($p[0]);
            if (self::public_data($next) !== $next || ($exists && self::public_data($current) !== $current)) { return Operations::error('protected_setting_fields', 'Use a non-secret option patch path to preserve credential fields.'); }
        }
        if ($preview) { return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability', 'title' => 'WP-CLI: ' . $key,
            'name' => self::WRITE, 'description' => 'Change the selected WordPress setting.', 'risk' => 'publish', 'input' => wp_json_encode($preview_input)]]; }
        if ($verb === 'delete' && !$patch) {
            Operations::$mutation_started = true;
            $removed = $transient ? delete_transient($name) : delete_option($name);
            return $removed ? $ok(['name' => $name, 'deleted' => true]) : self::native_unknown();
        }
        Operations::$mutation_started = true;
        if ($theme) { set_theme_mod($name, $next); $saved = get_theme_mod($name, $missing); }
        else {
            if ($verb === 'add') { add_option($name, $next, '', $autoload); } else { update_option($name, $next, $autoload); }
            $saved = get_option($name, $missing);
        }
        return $saved === $next ? $ok(['name' => $name, 'value' => self::public_data($saved)]) : self::native_unknown();
    }

    private static function native_unknown(): array
    {
        return ['state' => 'unknown', 'actions_used' => 0, 'error' => ['code' => 'outcome_unknown', 'message' => 'WordPress did not confirm the change. Check the operation before trying again.']];
    }
}
