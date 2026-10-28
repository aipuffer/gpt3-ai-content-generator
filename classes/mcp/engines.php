<?php
/**
 * Generic engines: the WordPress Abilities API and the site's own REST routes, run as the acting user.
 *
 * Plugins that register abilities or REST routes become usable with no code here. What a plugin's callback actually
 * does can't be read from outside: names, annotations and HTTP methods are its author's description, not a guarantee.
 * So the rules are conservative:
 * - every change needs the publish scope (it may publish) and follows the owner's selected approval policy (Registry);
 * - a change that may delete is marked destructive, for reporting and the Standard approval preview;
 * - Guard refuses blocked domains and batch requests by name before anything runs;
 * - content goes through the same rules as create_draft: no scripts, comments, blocks, shortcodes or protected
 *   metadata in the request, and WordPress filters saved HTML as for a user without unfiltered_html;
 * - an error after the plugin's callback started is an unknown outcome, never "nothing changed".
 * The acting user's own capabilities, checked by the ability or route, stay the ceiling.
 *
 * Arbitrary JSON (an ability's input and result, a request's query, body and response) travels as JSON text in
 * `*_json` fields: signed requests and outcomes use canonical JSON, which allows only whole numbers and plain keys.
 * The bridge encodes inputs this way and decodes results after verifying the signature.
 */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Engines
{
    /** Largest request body an assistant may send, and largest result returned before it is cut. */
    public const MAX_INPUT = 200000;
    public const MAX_RESULT = 100000;

    /** Fixed diagnostic identifiers accepted by the bridge audit contract; never export arbitrary plugin errors. */
    private const ABILITY_ERROR_CODES = [
        'ability_callback_exception', 'ability_failed', 'ability_invalid_execute_callback',
        'ability_invalid_input', 'ability_invalid_output', 'ability_invalid_permission_callback',
        'ability_invalid_permissions', 'ability_missing_input_schema', 'ability_unavailable', 'account_paused',
        'active_connection_plugin', 'active_theme_in_use', 'administrator_role_required', 'already_trashed',
        'audit_disabled', 'audit_account_limit', 'audit_busy', 'audit_invalid_response', 'audit_page_unavailable', 'audit_rate_limited', 'audit_timeout', 'audit_unavailable',
        'batch_too_large', 'blocked_route', 'bridge_bad_response', 'bridge_destination_blocked',
        'bridge_dispatch_blocked', 'bridge_error', 'bridge_internal_error', 'bridge_mismatched_operation',
        'bridge_network', 'bridge_no_valid_endpoint', 'bridge_response_too_large', 'bridge_timeout',
        'bridge_unauthenticated_response', 'bridge_unreachable', 'cache_plugin_unavailable',
        'checksums_unavailable', 'command_failed', 'command_unavailable', 'config_value_not_exposed',
        'connected_role_in_use', 'connected_user_in_use', 'connection_cron_protected', 'consent_required',
        'content_changed', 'content_changes_stopped', 'create_a_draft_first', 'cron_callback_not_registered',
        'cron_event_not_found', 'cron_loopback_unavailable', 'database_query_failed', 'database_unavailable',
        'deactivate_first', 'definition_conflict', 'definition_limit', 'definition_unconfirmed',
        'document_download_failed', 'duplicate_column', 'duplicate_result_alias', 'elementor_conflict',
        'elementor_unavailable', 'failure_unreported', 'filesystem_access_required', 'gutenberg_failed',
        'idempotency_conflict', 'identity_changed', 'identity_unavailable', 'image_download_failed',
        'image_unavailable', 'inspection_not_active', 'inspection_not_public', 'inspection_timeout',
        'inspection_unavailable', 'inspection_disabled', 'inspection_account_limit', 'inspection_busy', 'inspection_capacity', 'integer_primary_key_required', 'invalid_capability', 'invalid_column',
        'invalid_cron_hook', 'invalid_email', 'invalid_group_projection', 'invalid_image', 'invalid_input',
        'invalid_inspection_url', 'invalid_level', 'invalid_media', 'invalid_media_url', 'invalid_menu_parent',
        'invalid_or_existing_menu', 'invalid_or_existing_user', 'invalid_or_installed_software',
        'invalid_patch_action', 'invalid_patch_path', 'invalid_patch_value', 'invalid_permalink_structure',
        'invalid_permalink_tag', 'invalid_post_type', 'invalid_probe', 'invalid_reassignment_user',
        'invalid_registration', 'invalid_replacement', 'invalid_revocation', 'invalid_role', 'invalid_schedule',
        'invalid_settlement_receipt', 'invalid_software_name', 'invalid_status', 'invalid_svg',
        'invalid_taxonomy', 'invalid_taxonomy_base', 'invalid_term', 'invalid_url', 'join_too_broad',
        'large_group_field', 'managed_execution_required', 'matching_count_changed', 'matching_records_changed',
        'mcp_storage_unavailable', 'menu_item_not_found', 'menu_location_not_registered', 'menu_not_found',
        'menu_url_is_derived', 'metadata_not_found', 'network_managed_plugin', 'no_changes',
        'no_default_for_custom_role', 'no_token', 'not_found', 'one_table_per_cursor', 'outcome_unknown',
        'owner_confirmed_not_run', 'pairing_superseded', 'pdf_complexity', 'pdf_file_unreadable',
        'pdf_page_range', 'pdf_pages', 'pdf_parser_not_initialized', 'pdf_parsing_failed', 'pdf_size',
        'pdf_unavailable', 'pdf_unreadable', 'pdf_unsupported', 'permission_denied', 'plugin_not_found',
        'post_type_change_not_supported', 'private_metadata', 'protected_key', 'protected_rows_selected',
        'protected_setting', 'protected_setting_fields', 'protected_user_metadata', 'query_too_broad',
        'redirect_save_unconfirmed', 'replacement_count_changed', 'repository_unavailable', 'rest_error',
        'rest_forbidden', 'result_too_large', 'role_already_exists', 'role_not_found', 'service_paused',
        'service_unavailable', 'setting_already_exists', 'setting_not_found', 'setting_path_not_found',
        'settings_unavailable', 'sidebar_not_found', 'single_record_required', 'site_busy', 'site_not_authorized',
        'site_paused', 'site_unavailable', 'source_too_large', 'stock_search_invalid_response',
        'stock_search_unavailable', 'stock_search_wait', 'storage', 'storage_unavailable', 'svg_no_vectors',
        'svg_save_unconfirmed', 'svg_size', 'svg_source_changed', 'svg_unavailable', 'svg_upload_failed',
        'table_not_exposed', 'temporary_file_failed', 'term_not_found', 'text_page_too_large',
        'text_predicate_requires_ids', 'theme_change_unconfirmed', 'theme_conflict', 'theme_not_found',
        'theme_unavailable', 'too_many_events', 'transactional_tables_required', 'typed_command_required',
        'unclassified_site_error', 'unknown_order_alias', 'unsupported_command', 'unsupported_content',
        'unsupported_group_query', 'unsupported_image', 'unsupported_insert_columns', 'unsupported_post_type',
        'unsupported_release_channel', 'unsupported_release_or_source', 'unsupported_replacement_column',
        'unsupported_replacement_table', 'unsupported_tool', 'unsupported_update_source',
        'unsupported_write_column', 'update_check_unavailable', 'usage_unavailable', 'use_read_ability',
        'use_run_ability', 'user_not_found', 'user_outside_site', 'value_order_not_exposed',
        'value_predicate_not_exposed', 'value_too_large', 'where_required', 'workflow_changed', 'workflow_limit',
        'workflow_not_found', 'write_snapshot_too_large',
    ];

    private const NAME_RE = '/^[a-z0-9-]+(?:\/[a-z0-9-]+)+$/D';
    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Route segments and request keys that ask a POST or PUT to delete or trash something. */
    private const DELETE_SEGMENTS = ['delete', 'trash', 'remove', 'purge', 'empty-trash'];
    private const DELETE_KEYS = ['delete', 'trash', 'remove', 'force_delete'];

    /** What a generic change says when its plugin failed after it started: something may have changed. */
    private const AFTER_START = 'The plugin reported an error after it started, so something may already have changed. Don’t send this change again: the site owner can check it in wp-admin, and get_operation shows the outcome.';

    // Abilities ---------------------------------------------------------------------------------------------------

    public static function abilities_available(): bool
    {
        return function_exists('wp_get_abilities') && function_exists('wp_get_ability');
    }

    /** read (annotated read-only), write (annotated non-destructive) or destructive (everything else, including unannotated). */
    public static function ability_risk(\WP_Ability $ability): string
    {
        $annotations = $ability->get_meta_item('annotations', []);
        $annotations = is_array($annotations) ? $annotations : [];
        if (($annotations['readonly'] ?? null) === true) { return 'read'; }
        return ($annotations['destructive'] ?? null) === false ? 'write' : 'destructive';
    }

    /** Abilities their authors made available to clients, and not in a blocked domain for what they do. */
    private static function offered(?\WP_Ability $ability): bool
    {
        if (!$ability) { return false; }
        // Plugins may opt into MCP without exposing a second public REST execution surface.
        $mcp = $ability->get_meta_item('mcp', []);
        $mcp_public = is_array($mcp) && ($mcp['public'] ?? null) === true;
        if (!$mcp_public && $ability->get_meta_item('public', false) !== true && $ability->get_meta_item('show_in_rest', false) !== true) { return false; }
        return (bool) apply_filters('aipkit_mcp_ability_offered', Guard::allows_ability($ability->get_name(), $ability->get_category(), self::ability_risk($ability)), $ability);
    }

    private static function ability(object $args): ?\WP_Ability
    {
        if (!self::abilities_available() || !is_string($args->name ?? null) || strlen($args->name) > 200 || !preg_match(self::NAME_RE, $args->name)) { return null; }
        $ability = wp_get_ability($args->name);
        return self::offered($ability) ? $ability : null;
    }

    /**
     * A `*_json` argument decoded (objects become arrays, as WordPress expects): null when absent, false when it isn't
     * JSON text within the size limit.
     */
    private static function decoded(object $args, string $key)
    {
        if (!property_exists($args, $key) || $args->$key === null) { return null; }
        if (!is_string($args->$key) || strlen($args->$key) > self::MAX_INPUT) { return false; }
        $value = json_decode($args->$key, true);
        return json_last_error() === JSON_ERROR_NONE ? $value : false;
    }

    /**
     * The ability input. Assistants often leave out an empty input; an ability that takes an object then gets an
     * empty one. False when the input isn't valid JSON within the size limit.
     */
    private static function input(object $args, ?\WP_Ability $ability = null)
    {
        $input = self::decoded($args, 'input_json');
        if ($input === null && $ability && ($ability->get_input_schema()['type'] ?? null) === 'object') { return []; }
        return $input;
    }

    /**
     * The risk of one call, for Registry. A change is publish at least (it can publish, change prices or send mail),
     * and destructive when it may delete: an ability not annotated as non-destructive, a DELETE request, or a request
     * whose route or body asks to delete or trash.
     */
    public static function risk(string $tool, object $args): ?string
    {
        if ($tool === 'run_ability') {
            $ability = self::ability($args);
            if (!$ability) { return null; }
            $risk = self::ability_risk($ability);
            return $risk === 'write' ? 'publish' : $risk;
        }
        if ($tool === 'write_rest_route') {
            $method = is_string($args->method ?? null) ? strtoupper($args->method) : '';
            if (!in_array($method, self::METHODS, true)) { return null; }
            return self::rest_deletes($method, (string) ($args->route ?? ''), self::decoded($args, 'body_json'), self::decoded($args, 'query_json')) ? 'destructive' : 'publish';
        }
        return 'read';
    }

    /** Whether a REST change may delete: the method, a route segment, a body or query key, or a trash status. */
    private static function rest_deletes(string $method, string $route, $body, $query): bool
    {
        if ($method === 'DELETE' || array_intersect(explode('/', strtolower(trim($route, '/'))), self::DELETE_SEGMENTS)) { return true; }
        foreach ([$body, $query] as $values) {
            if (!is_array($values)) { continue; }
            if (array_intersect(array_map('strtolower', array_map('strval', array_keys($values))), self::DELETE_KEYS)) { return true; }
            if (is_string($values['status'] ?? null) && strtolower($values['status']) === 'trash') { return true; }
        }
        return false;
    }

    /**
     * Generic changes reject scripts, comments, registered shortcodes and protected metadata. Only the root REST
     * query's validated response controls are exempt from the protected-key check; values are still inspected.
     */
    public static function unsafe_content($values, string $source = 'input', array $root = []): ?array
    {
        // Visit fields in document order so multiple offenders produce a stable first location.
        if (is_array($values)) {
            foreach ($values as $key => $item) {
                $path = array_merge($root, [$key]);
                $selector = $source === 'query' && !$root && in_array($key, ['_fields', '_embed', '_locale'], true);
                if ((!$selector && is_string($key) && strpos($key, '_') === 0) || ($key === 'key' && is_string($item) && strpos($item, '_') === 0)) {
                    $field = is_string($key) && strpos($key, '_') === 0 ? $key : $item;
                    $message = $field === '_yoast_wpseo_focuskw'
                        ? 'Protected metadata cannot be edited directly. Discover ai-puffer/yoast-set-focus-keyphrase with find_abilities and use post_id and focus_keyphrase. Verify using Yoast SEO data; scores are not recalculated.'
                        : (strpos($field, '_yoast_wpseo_') === 0
                        ? 'Protected metadata cannot be edited directly. Discover yoast-seo/update-post-seo-data with find_abilities and use post_id and seo_title/meta_description. Verify with yoast-seo/get-post-seo-data.'
                        : 'Protected fields (names starting with "_") cannot be changed through this tool. Discover a supported native plugin action with find_abilities; do not bypass this restriction.');
                    return self::content_refusal('protected_metadata', $source, $path, $message);
                }
                $error = self::unsafe_content($item, $source, $path);
                if ($error) { return $error; }
            }
        } elseif (is_string($values)) {
            return self::content_error($values, $source, $root);
        }
        return null;
    }

    /** Match executable URL contexts, not ordinary prose such as "JavaScript: The Good Parts". */
    private static function javascript_url(string $value, array $path): bool
    {
        $scheme = static function (string $url): bool {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return (bool) preg_match('/^javascript:/i', preg_replace('/[\x00-\x20\x7f]+/', '', $url));
        };
        $key = strtolower((string) end($path));
        if ((in_array($key, ['url', 'href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'xlink:href'], true)
            || preg_match('/(?:_url|_uri|_href|_src)$/D', $key)) && $scheme($value)) { return true; }
        // A bare executable URL in an unnamed/plugin field is still unsafe. A prose heading has words after ":".
        if ($scheme($value) && !preg_match('/^\s*javascript:\s+[[:alpha:]][[:alpha:] \'-]*\s+[[:alpha:]][[:alpha:] \'.!?-]*$/iu', $value)) { return true; }
        foreach (wp_html_split($value) as $element) {
            if ($element === '' || $element[0] !== '<') { continue; }
            $attributes = wp_kses_attr_parse($element);
            foreach ($attributes === false ? [] : array_slice($attributes, 1, -1) as $attribute) {
                // Inspect the original attribute, before KSES strips its protocol.
                if (preg_match('/^\s*(?:href|src|action|formaction|poster|cite|background|xlink:href)\s*=\s*(.*)$/is', $attribute, $match)
                    && $scheme(trim($match[1], " \t\n\r\0\x0B\"'"))) { return true; }
            }
        }
        return false;
    }

    /** Inspect without executing shortcodes. Unknown bracketed prose and WordPress's escaped [[tag]] are literal. */
    private static function contains_shortcode(string $value): bool
    {
        global $shortcode_tags;
        if (strpos($value, '[') === false || !is_array($shortcode_tags) || !$shortcode_tags) { return false; }
        // Match WordPress's candidate discovery, including valid plugin-defined shortcode names.
        preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $value, $candidates);
        $tags = array_intersect(array_keys($shortcode_tags), $candidates[1]);
        if (!$tags) { return false; }
        $parts = [$value];
        // WordPress expands HTML attributes before whole-text shortcodes. An escaped outer tag must not hide an
        // executable attribute (or a match spanning multiple attributes) from this check.
        foreach (wp_html_split($value) as $element) {
            if ($element === '' || $element[0] !== '<' || strpos($element, '[') === false) { continue; }
            $attributes = wp_kses_attr_parse($element);
            foreach ($attributes === false ? [$element] : array_slice($attributes, 1, -1) as $attribute) { $parts[] = $attribute; }
        }
        $pattern = '/' . get_shortcode_regex($tags) . '/';
        foreach ($parts as $part) {
            $matched = preg_match_all($pattern, $part, $matches, PREG_SET_ORDER);
            if ($matched === false) { return true; } // Fail closed when inspection cannot complete.
            foreach ($matches as $match) {
                if ($match[1] !== '[' || $match[6] !== ']') { return true; }
            }
        }
        return false;
    }

    /** Native post fields retain their existing wp_kses_post script sanitation; generic writes refuse scripts. */
    public static function content_error(string $value, string $source, array $path, bool $refuse_scripts = true): ?array
    {
        if ($refuse_scripts && stripos($value, '<script') !== false) {
            return self::content_refusal('script', $source, $path, 'Script markup cannot be sent through this tool.');
        }
        if ($refuse_scripts && self::javascript_url($value, $path)) {
            return self::content_refusal('javascript_url', $source, $path, 'JavaScript URLs cannot be sent through this tool. Use an ordinary HTTPS link.');
        }
        if (strpos($value, '<!--') !== false) {
            return preg_match('/<!--\s*\/?wp:/', $value)
                ? self::content_refusal('block_markup', $source, $path, 'Discover ai-puffer/gutenberg- with find_abilities and read get_guide topic gutenberg. Use supported native block operations; never strip block delimiters.')
                : self::content_refusal('html_comment', $source, $path, 'HTML comments cannot be sent through this tool. Send plain text or simple HTML.');
        }
        if (self::contains_shortcode($value)) {
            return self::content_refusal('shortcode', $source, $path, 'A registered WordPress shortcode cannot be sent through this tool. Use a supported native plugin operation.');
        }
        return null;
    }

    /** Only fixed categories and a bounded, allowlisted location leave WordPress; arbitrary keys and values do not. */
    public static function content_refusal(string $reason, string $source, array $path, string $message): array
    {
        $keys = ['content', 'title', 'excerpt', 'description', 'meta', 'meta_data', 'key', 'value', '_fields', '_embed', '_locale', 'layout_json', 'edits_json', 'blocks', 'find', 'replace', 'html', 'children', 'text', 'url', 'alt',
            '_elementor_data', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_price'];
        $location = '$';
        foreach (array_slice($path, 0, 12) as $key) {
            $part = is_int($key) && $key >= 0 && $key <= 999999 ? '[' . $key . ']'
                : '.' . (is_string($key) && in_array($key, $keys, true) ? $key : '*');
            if (strlen($location . $part) > 200) { break; }
            $location .= $part;
        }
        $response = self::refuse('unsupported_content', $message . ' Location: ' . $source . substr($location, 1) . '.');
        $response['operation_diagnostics'] = ['content_reason' => $reason, 'content_source' => $source, 'content_path' => $location];
        return $response;
    }

    /** The plain-content API cannot represent an existing block document without losing structure. */
    public static function preserve_blocks(\WP_Post $post, string $source, array $path): ?array
    {
        if (get_post_meta($post->ID, '_seedprod_page', true)) {
            return self::content_refusal('layout_replacement_required', $source, $path,
                'This page uses SeedProd. Discover seedprod/get-page and save-page; preserve the complete native sections and pass source_version. Free layouts need a native builder Save to generate visitor HTML. Omit content for metadata-only edits.');
        }
        if (get_post_meta($post->ID, 'pagelayer-data', true)) {
            return self::content_refusal('layout_replacement_required', $source, $path,
                'This page uses Pagelayer. Discover pagelayer-pages/get-page and update-element for native fields. Generic post-body replacement does not update its saved layout. Preserve unmentioned nodes and styling; omit content for metadata-only edits.');
        }
        if (get_post_meta($post->ID, '_fl_builder_enabled', true)) {
            return self::content_refusal('layout_replacement_required', $source, $path,
                'This page uses Beaver Builder. Discover ai-puffer/beaver-get-document and beaver-update-document for supported module fields. Generic post-body replacement does not update the visible builder layout. Omit content for metadata-only edits.');
        }
        if (get_post_meta($post->ID, 'panels_data', true)) {
            return self::content_refusal('layout_replacement_required', $source, $path,
                'This page uses SiteOrigin Page Builder. Discover siteorigin-panels/layout-get and layout-update. Preserve the complete native layout and unmentioned widgets. Generic post-body replacement does not update the visible layout. Omit content for metadata-only edits.');
        }
        return has_blocks($post->post_content) ? self::content_refusal('existing_block_document', $source, $path,
            'This is an existing Gutenberg document. Preserve its block markup. Discover ai-puffer/gutenberg-update-document: on 2.5.15+ use edits_json for partial inline edits and adding blocks, or create-document with source_post_id/source_version for a separate edited draft. Whole-layout replacement still requires explicit intent. Omit content for metadata-only edits.') : null;
    }

    /**
     * Runs a change as WordPress runs one for a user without unfiltered_html: saved post content, titles, excerpts and
     * other filtered fields go through the HTML allow-list, whatever the acting user's role, then the user's own
     * filters return. Also tells the caller whether the plugin's own callback was reached ($entered).
     */
    private static function filtered_change(callable $work)
    {
        $deny = static function (array $caps, string $cap): array { return $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps; };
        add_filter('map_meta_cap', $deny, PHP_INT_MAX, 2);
        kses_init();
        try { return $work(); }
        finally {
            remove_filter('map_meta_cap', $deny, PHP_INT_MAX);
            kses_init();
        }
    }

    public static function find_abilities(object $args): array
    {
        if (!self::abilities_available()) { return Operations::error('unsupported_tool'); }
        foreach (['query' => 100, 'category' => 100] as $key => $max) {
            if (isset($args->$key) && (!is_string($args->$key) || mb_strlen($args->$key) > $max)) { return Operations::error('invalid_input'); }
        }
        foreach (['page' => 100, 'per_page' => 50] as $key => $max) {
            if (isset($args->$key) && (!is_int($args->$key) || $args->$key < 1 || $args->$key > $max)) { return Operations::error('invalid_input'); }
        }
        // Search punctuation is interchangeable: assistants commonly ask for wp cli rather than wp-cli.
        $normalize = static function (string $text): string { return trim(preg_replace('/\s+/', ' ', preg_replace('/[-_\/]+/', ' ', strtolower($text)))); };
        $query = $normalize($args->query ?? '');
        $items = [];
        foreach (wp_get_abilities() as $ability) {
            if (!self::offered($ability) || (isset($args->category) && $ability->get_category() !== $args->category)) { continue; }
            $text = $normalize($ability->get_name() . ' ' . $ability->get_label() . ' ' . $ability->get_description());
            if ($query !== '' && strpos($text, $query) === false) { continue; }
            $risk = self::ability_risk($ability);
            $items[] = ['name' => $ability->get_name(), 'label' => mb_substr($ability->get_label(), 0, 300),
                'description' => class_exists(Elementor::class) ? mb_substr(Elementor::native_description($ability->get_name(), $ability->get_description()), 0, 600) : mb_substr($ability->get_description(), 0, 600), 'category' => $ability->get_category(),
                'risk' => $risk, 'tool' => $risk === 'read' ? 'read_ability' : 'run_ability'];
        }
        usort($items, static function (array $a, array $b): int { return strcmp($a['name'], $b['name']); });
        $per_page = $args->per_page ?? 20;
        $page = $args->page ?? 1;
        $result = ['items' => array_slice($items, ($page - 1) * $per_page, $per_page), 'page' => $page,
            'has_more' => count($items) > $page * $per_page, 'total' => count($items)];
        // Broad discovery stays compact. An exact-name search can return a larger native schema (for example
        // WooCommerce's product-type alternatives), so clients never have to guess its required input fields.
        foreach ($result['items'] as $index => $item) {
            $encoded = (string) wp_json_encode(wp_get_ability($item['name'])->get_input_schema() ?: (object) []);
            $limit = $query === $normalize($item['name']) ? 50000 : 6000;
            $result['items'][$index]['input_schema_json'] = $encoded;
            if (strlen($encoded) > $limit || strlen((string) wp_json_encode($result)) > self::MAX_RESULT - 1024) {
                unset($result['items'][$index]['input_schema_json']);
                $result['items'][$index]['input_schema_omitted'] = true;
                $result['items'][$index]['description'] .= $limit === 6000
                    ? ' Full input schema omitted. Before calling, repeat find_abilities with query "' . $item['name'] . '" and per_page 1.'
                    : ' Input schema exceeds the discovery response limit; do not guess its inputs.';
            }
        }
        return self::ok($result);
    }

    public static function read_ability(object $args): array
    {
        $ability = self::ability($args);
        if (!$ability) { return Operations::error('ability_unavailable'); }
        if (self::ability_risk($ability) !== 'read') { return self::refuse('use_run_ability', 'This ability changes the site. Use run_ability.'); }
        $input = self::input($args, $ability);
        if ($input === false) {
            $reason = is_string($args->input_json ?? null) && strlen($args->input_json) <= self::MAX_INPUT && json_last_error() === JSON_ERROR_NONE ? 'type' : 'invalid_json';
            $error = $args->name === 'ai-puffer/audit-page' ? self::audit_input_error(null, $reason, 'preflight') : null;
            return self::ability_diagnostics($error ? self::ability_result($error) : Operations::error('invalid_input'), $ability->get_name(), 'preflight', $error);
        }
        $phase = 'before_execution';
        if (class_exists(Elementor::class)) {
            $unavailable = Elementor::native_availability($ability->get_name(), (array) $input);
            if ($unavailable) { return self::ability_diagnostics($unavailable, $ability->get_name(), 'preflight'); }
        }
        $result = self::execute_ability($ability, $input, $phase);
        if ($args->name === 'ai-puffer/audit-page' && is_array($result) && is_array($result['audit_request'] ?? null)) {
            return ['state' => 'unknown', 'actions_used' => 0, 'result' => ['audit_request' => $result['audit_request']]];
        }
        return self::ability_diagnostics(self::ability_result($result), $ability->get_name(), $phase, $result);
    }

    /** Ignore only native widget bookkeeping returned unchanged from this same stored layout during inspection.
     * The original input still goes through SiteOrigin's own validation, permission and sanitization pipeline.
     */
    private static function siteorigin_check_input(string $name, $input)
    {
        if ($name !== 'siteorigin-panels/layout-update' || !is_array($input) || !is_int($input['post_id'] ?? null)
            || !current_user_can('edit_post', $input['post_id']) || !is_array($input['panels_data']['widgets'] ?? null)) { return $input; }
        $reader = wp_get_ability('siteorigin-panels/layout-get');
        $document = $reader ? $reader->execute(['post_id' => $input['post_id']]) : null;
        if (!is_array($document)) { return $input; }
        $index = $input['block_index'] ?? null;
        $layouts = array_values(array_filter($document['layouts'] ?? [], static function ($layout) use ($index) {
            return is_array($layout) && ($index === null ? ($layout['storage'] ?? '') === 'meta'
                : (($layout['storage'] ?? '') === 'block' && ($layout['block_index'] ?? null) === $index));
        }));
        // With no classic layout, the native callback may target one unambiguous Layout Block.
        if ($index === null && !$layouts && count($document['layouts'] ?? []) === 1) { $layouts = $document['layouts']; }
        if (count($layouts) !== 1) { return $input; }
        $input['panels_data'] = self::siteorigin_check_layout($input['panels_data'], $layouts[0]['panels_data'] ?? [], 0);
        return $input;
    }

    /** Match nested widget bookkeeping only within its same stored parent and layout. */
    private static function siteorigin_check_layout(array $input, array $stored, int $depth): array
    {
        if ($depth > 4 || !is_array($input['widgets'] ?? null)) { return $input; }
        foreach ($input['widgets'] as &$widget) {
            if (!is_array($widget) || empty($widget['panels_info']['widget_id'])) { continue; }
            $matches = array_values(array_filter($stored['widgets'] ?? [], static function ($old) use ($widget) {
                return is_array($old) && ($old['panels_info']['widget_id'] ?? null) === $widget['panels_info']['widget_id']
                    && ($old['panels_info']['class'] ?? null) === ($widget['panels_info']['class'] ?? null);
            }));
            if (count($matches) !== 1) { continue; }
            foreach (['_sow_form_id', '_sow_form_timestamp'] as $key) {
                if (array_key_exists($key, $widget) && array_key_exists($key, $matches[0]) && is_scalar($widget[$key]) && wp_json_encode($widget[$key]) === wp_json_encode($matches[0][$key])) { unset($widget[$key]); }
            }
            if (($widget['panels_info']['class'] ?? '') === 'SiteOrigin_Panels_Widgets_Layout'
                && is_array($widget['panels_data'] ?? null) && is_array($matches[0]['panels_data'] ?? null)) {
                $widget['panels_data'] = self::siteorigin_check_layout($widget['panels_data'], $matches[0]['panels_data'], $depth + 1);
            }
        }
        unset($widget);
        return $input;
    }

    /** Checks a change before it runs or waits: the ability, its input and the acting user's permission. */
    private static function check_ability(object $args)
    {
        $ability = self::ability($args);
        if (!$ability) { return Operations::error('ability_unavailable'); }
        if (self::ability_risk($ability) === 'read') { return self::refuse('use_read_ability', 'This ability only reads. Use read_ability.'); }
        $input = self::input($args, $ability);
        if ($input === false) { return self::ability_diagnostics(Operations::error('invalid_input'), $ability->get_name(), 'preflight'); }
        $unsafe = apply_filters('aipkit_mcp_ability_content_error', self::unsafe_content(self::siteorigin_check_input($ability->get_name(), $input)), $ability->get_name(), $input);
        if ($unsafe) { return $unsafe; }
        $input = $ability->normalize_input($input);
        if (is_wp_error($input)) { return self::ability_diagnostics(self::ability_result($input), $ability->get_name(), 'preflight', $input); }
        $valid = $ability->validate_input($input);
        if (is_wp_error($valid)) { return self::ability_diagnostics(self::ability_result($valid), $ability->get_name(), 'preflight', $valid); }
        if ($ability->check_permissions($input) !== true) { return Operations::error('permission_denied'); }
        if (class_exists(Elementor::class)) {
            $unavailable = Elementor::native_availability($ability->get_name(), (array) $input);
            if ($unavailable) { return $unavailable; }
        }
        return $ability;
    }

    public static function ability_preview(object $args): array
    {
        $ability = self::check_ability($args);
        if (is_array($ability)) { return self::ability_diagnostics($ability, $args->name ?? '', 'preflight'); }
        if (class_exists(Elementor::class) && Elementor::handles($args->name)) { return Elementor::preview($args->name, (array) self::input($args, $ability)); }
        $extension = apply_filters('aipkit_mcp_ability_result', null, $args->name, self::input($args, $ability), true);
        if (is_array($extension)) { return $extension; }
        return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'ability',
            'title' => $ability->get_label(), 'name' => $ability->get_name(), 'description' => mb_substr($ability->get_description(), 0, 600),
            'risk' => (string) self::risk('run_ability', $args), 'input' => self::excerpt(self::input($args, $ability))]];
    }

    public static function run_ability(object $args): array
    {
        $ability = self::check_ability($args);
        if (is_array($ability)) { return self::ability_diagnostics($ability, $args->name ?? '', 'preflight'); }
        if (class_exists(Elementor::class) && Elementor::handles($args->name)) { return Elementor::run($args->name, (array) self::input($args, $ability)); }
        $extension = apply_filters('aipkit_mcp_ability_result', null, $args->name, self::input($args, $ability), false);
        if (is_array($extension)) { return $extension; }
        $phase = 'before_execution';
        $native_refusal = null;
        Operations::$mutation_started = true;
        // SiteOrigin's cached static-widget shortcodes carry hidden form inputs, which WordPress KSES removes.
        // Cache their native rendered HTML instead; it still passes the same post-content sanitizer. No input tags
        // or unfiltered_html exemption is granted; untested interactive widgets keep native copy-content behaviour.
        $fallback = static function ($html, $widget, $widget_args, $instance) {
            if (empty($GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER']) || !is_object($widget)
                || !in_array(get_class($widget), ['SiteOrigin_Widget_Button_Widget', 'SiteOrigin_Widget_Image_Widget', 'SiteOrigin_Widget_Headline_Widget', 'SiteOrigin_Widget_Features_Widget', 'SiteOrigin_Widgets_Testimonials_Widget'], true)) { return $html; }
            // The Page Builder renderer supplies the outer panel once around this filter's result.
            $widget_args['before_widget'] = '';
            $widget_args['after_widget'] = '';
            ob_start();
            try { $widget->widget($widget_args, $instance); return ob_get_contents(); }
            finally { ob_end_clean(); }
        };
        $siteorigin = $ability->get_name() === 'siteorigin-panels/layout-update';
        if ($siteorigin) { add_filter('siteorigin_panels_the_widget_html', $fallback, 20, 4); }
        try {
            $result = self::filtered_change(static function () use ($ability, $args, &$phase, &$native_refusal) {
                $work = static function () use ($ability, $args, &$phase) { return self::execute_ability($ability, self::input($args, $ability), $phase); };
                return class_exists(Elementor::class) ? Elementor::native_change($ability->get_name(), (array) self::input($args, $ability), $work, $native_refusal) : $work();
            });
        } finally {
            if ($siteorigin) { remove_filter('siteorigin_panels_the_widget_html', $fallback, 20); }
        }
        // Only our wrapper can identify a refusal before invoking the native callback.
        if ($native_refusal !== null && $phase === 'before_execution') {
            Operations::$mutation_started = false;
            return self::ability_diagnostics($native_refusal, $ability->get_name(), 'preflight');
        }
        if (class_exists(Elementor::class)) {
            $native = Elementor::native_outcome($ability->get_name(), (array) self::input($args, $ability), $result);
            if ($native) {
                $native['result'] = ['data_json' => self::bounded($result)];
                return self::ability_diagnostics($native, $ability->get_name(), $phase, $result);
            }
        }
        if ($ability->get_name() === 'siteorigin-panels/layout-update' && is_array($result) && ($result['updated'] ?? null) === false) {
            // These native sources decline before saving. A failed block save can already have started: keep recovery.
            $declined = in_array($result['source'] ?? '', ['unsupported', 'block-ambiguous'], true);
            if ($declined) { Operations::$mutation_started = false; }
            $out = $declined ? self::refuse('invalid_input', 'SiteOrigin did not save the layout. Read layout-get and preserve its complete widgets, grids and grid_cells structure; supply block_index when needed.') : self::after_start('outcome_unknown');
            $out['result'] = ['data_json' => self::bounded($result)];
            return self::ability_diagnostics($out, $ability->get_name(), $phase);
        }
        // Refused before the callback (input or permission, as checked above): nothing ran. Any other error came from
        // the callback, an output check after it, or another plugin's short-circuit: something may have changed.
        if (is_wp_error($result) && ($phase !== 'before_execution' || !in_array($result->get_error_code(), ['ability_invalid_input', 'ability_invalid_permissions'], true))) {
            return self::ability_diagnostics(self::after_start(self::ability_error_code($result->get_error_code(), 'outcome_unknown')), $ability->get_name(), $phase, $result);
        }
        return self::ability_diagnostics(self::ability_result($result), $ability->get_name(), $phase, $result);
    }

    /** Observe native execution boundaries without changing WordPress validation or permissions. */
    private static function execute_ability(\WP_Ability $ability, $input, string &$phase)
    {
        // Observe WordPress's final normalized validation failure; never normalize twice or relax its decision.
        $audit_error = null;
        $validation = static function ($valid, $normalized, string $name) use (&$audit_error) {
            if ($name === 'ai-puffer/audit-page' && is_wp_error($valid) && $valid->get_error_code() === 'ability_invalid_input') { return $audit_error = self::audit_input_error($normalized); }
            return $valid;
        };
        $mark = static function (string $name) use ($ability, &$phase): void { if ($name === $ability->get_name()) { $phase = 'callback'; } };
        $output = static function ($result, $observed) use ($ability, &$phase) {
            if ($observed === $ability->get_name() && !is_wp_error($result)) { $phase = 'output_validation'; }
            return $result;
        };
        add_action('wp_before_execute_ability', $mark, PHP_INT_MIN, 1);
        add_filter('wp_ability_execute_result', $output, PHP_INT_MAX, 2);
        add_filter('wp_ability_validate_input', $validation, PHP_INT_MAX, 3);
        try {
            $result = $ability->execute($input);
            // WordPress 6.9/7.0 do not expose the validation observer filter.
            if ($phase === 'before_execution' && $ability->get_name() === 'ai-puffer/audit-page' && is_wp_error($result) && $result->get_error_code() === 'ability_invalid_input'
                && $result !== $audit_error) { return self::audit_input_error($input); }
            return $result;
        }
        catch (\Throwable $error) { return new \WP_Error('ability_failed', $error->getMessage()); }
        finally {
            remove_action('wp_before_execute_ability', $mark, PHP_INT_MIN);
            remove_filter('wp_ability_execute_result', $output, PHP_INT_MAX);
            remove_filter('wp_ability_validate_input', $validation, PHP_INT_MAX);
        }
    }

    /** Audit-only, fixed vocabulary. Never echo submitted values or arbitrary property names. */
    private static function audit_input_error($input, string $reason = '', string $phase = 'before_execution'): \WP_Error
    {
        $field = '$';
        if ($reason === '') {
            $reason = 'validation';
            if (!is_array($input) && !is_object($input)) { $reason = 'type'; }
            elseif (is_array($input) && $input !== [] && array_keys($input) === range(0, count($input) - 1)) { $reason = 'type'; }
            else {
                $values = (array) $input;
                if (!array_key_exists('url', $values)) { $field = 'url'; $reason = 'required'; }
                else {
                    foreach ($values as $key => $value) {
                        if (!in_array($key, ['url', 'strategy'], true)) { $field = in_array($key, ['viewport', 'device', 'page_url'], true) ? $key : '*'; $reason = 'additional_property'; break; }
                        if (!is_string($value)) { $field = $key; $reason = 'type'; break; }
                        if ($key === 'url' && mb_strlen($value) > 2000) { $field = 'url'; $reason = 'max_length'; break; }
                        if ($key === 'strategy' && !in_array($value, ['mobile', 'desktop'], true)) { $field = 'strategy'; $reason = 'enum'; break; }
                    }
                }
            }
        }
        $guidance = 'Use url (one public HTTPS page URL on this site) and optional strategy (mobile or desktop; omit for mobile). No other fields, nulls or arrays. * masks an unsupported field name; $ means the input object.';
        return new \WP_Error('ability_invalid_input', 'Audit input: ' . $field . ' (' . $reason . '). ' . $guidance,
            ['operation_diagnostics' => ['ability_name' => 'ai-puffer/audit-page', 'ability_phase' => $phase, 'input_issue' => ['field' => $field, 'reason' => $reason]]]);
    }

    /** Only registered names and fixed phases leave WordPress; detailed failures stay in its journal. */
    private static function ability_diagnostics(array $response, $name, string $phase, $result = null): array
    {
        if (empty($response['error'])) { return $response; }
        if (!isset($response['operation_diagnostics']) && self::abilities_available() && is_string($name) && strlen($name) <= 200 && preg_match(self::NAME_RE, $name) && wp_get_ability($name)) {
            $response['operation_diagnostics'] = ['ability_name' => $name, 'ability_phase' => $phase];
        }
        if (is_wp_error($result)) {
            $response['local_error'] = ['code' => mb_substr((string) $result->get_error_code(), 0, 200),
                'message' => mb_substr(wp_strip_all_tags($result->get_error_message()), 0, 2000)];
        }
        return $response;
    }

    /** An ability's answer when nothing could have changed (a read, or a refusal before the callback). */
    private static function ability_result($result): array
    {
        if (is_wp_error($result)) {
            $out = $result->get_error_code() === 'ability_invalid_permissions' ? Operations::error('permission_denied')
                : self::refuse(self::ability_error_code($result->get_error_code()), $result->get_error_message());
            $data = $result->get_error_data();
            if ($result->get_error_code() === 'unsupported_content' && is_array($data) && isset($data['operation_diagnostics'])) { $out['operation_diagnostics'] = $data['operation_diagnostics']; }
            if ($result->get_error_code() === 'ability_invalid_input' && is_array($data)) {
                $diagnostic = $data['operation_diagnostics'] ?? []; $issue = $diagnostic['input_issue'] ?? [];
                if (is_array($diagnostic) && count($diagnostic) === 3 && ($diagnostic['ability_name'] ?? '') === 'ai-puffer/audit-page'
                    && in_array($diagnostic['ability_phase'] ?? null, ['preflight', 'before_execution'], true) && is_array($issue) && count($issue) === 2
                    && in_array($issue['field'] ?? null, ['$', 'url', 'strategy', 'viewport', 'device', 'page_url', '*'], true)
                    && in_array($issue['reason'] ?? null, ['required', 'type', 'enum', 'max_length', 'additional_property', 'invalid_json', 'validation'], true)) { $out['operation_diagnostics'] = $diagnostic; }
            }
            return $out;
        }
        return self::ok(['data_json' => self::bounded($result)]);
    }

    private static function ability_error_code($code, string $fallback = 'ability_failed'): string
    {
        if (!is_string($code)) { return $fallback; }
        // Known native refusals use existing public codes; arbitrary plugin identifiers remain private.
        $code = [
            'yoast_seo_missing_identifier' => 'invalid_input',
            'yoast_seo_invalid_post_id' => 'not_found',
            'yoast_seo_invalid_permalink' => 'not_found',
            'yoast_seo_invalid_title' => 'not_found',
            'yoast_seo_cannot_edit_post' => 'permission_denied',
        ][$code] ?? $code;
        return in_array($code, self::ABILITY_ERROR_CODES, true) ? $code : $fallback;
    }

    // REST routes -------------------------------------------------------------------------------------------------

    /** A route as WordPress matches it: one leading slash, no query string, no dot segments. */
    private static function route(object $args): ?string
    {
        if (!is_string($args->route ?? null) || strlen($args->route) > 300 || !preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#D', $args->route)) { return null; }
        $route = '/' . ltrim($args->route, '/');
        if (preg_match('#(^|/)\.\.?(/|$)#', $route)) { return null; }
        return untrailingslashit($route) !== '' ? untrailingslashit($route) : '/';
    }

    /** Query parameters: names WordPress reads normally; the method override and envelope switches are refused. */
    private static function query(object $args): ?array
    {
        $query = self::decoded($args, 'query_json');
        if ($query === null) { return []; }
        if (!is_array($query) || ($query !== [] && array_keys($query) === range(0, count($query) - 1))) { return null; }
        foreach (array_keys($query) as $key) {
            if (!is_string($key) || in_array(strtolower($key), ['_method', '_envelope', '_jsonp'], true)) { return null; }
        }
        if (array_key_exists('_fields', $query) && !is_string($query['_fields'])) {
            $fields = $query['_fields'];
            if (!is_array($fields) || ($fields && array_keys($fields) !== range(0, count($fields) - 1))
                || count(array_filter($fields, 'is_string')) !== count($fields)) { return null; }
        }
        if (array_key_exists('_locale', $query) && !in_array($query['_locale'], ['user', 'site'], true)) { return null; }
        if (array_key_exists('_embed', $query) && !is_bool($query['_embed']) && !is_string($query['_embed'])) { return null; }
        return $query;
    }

    /** GeoDirectory 2.8.x flattens descriptions in REST even when its advanced editor is enabled.
     * Correct a copy of native arguments during MCP only; retain configured custom sanitizers and WordPress KSES.
     * Discovery uses the same correction so assistants see the format this installation actually accepts.
     */
    private static function directory_html_routes(array $routes): array
    {
        if (!function_exists('geodir_get_field_infoby') || !function_exists('geodir_sanitize_html_field')) { return $routes; }
        foreach ($routes as &$handlers) {
            foreach ($handlers as &$handler) {
                $callback = is_array($handler) ? ($handler['callback'] ?? null) : null;
                if (!is_array($callback) || !($callback[0] instanceof \GeoDir_REST_Posts_Controller)
                    || !in_array($callback[1], ['create_item', 'update_item'], true)
                    || ($handler['args']['content']['format'] ?? null) !== 'textarea-field'
                    || !in_array($handler['args']['content']['sanitize_callback'] ?? '', ['rest_sanitize_request_arg', 'geodir_rest_sanitize_request_arg'], true)) { continue; }
                $type = $callback[0]->get_item_schema()['title'] ?? '';
                $field = geodir_get_field_infoby('htmlvar_name', 'post_content', $type);
                $extra = is_array($field) ? maybe_unserialize($field['extra_fields'] ?? '') : null;
                if (!is_array($extra) || empty($extra['advanced_editor']) || empty($field['is_active'])
                    || ($field['field_type'] ?? null) !== 'textarea') { continue; }
                $handler['args']['content']['format'] = 'html-field';
                $handler['args']['content']['sanitize_callback'] = static function ($value) {
                    return is_string($value) ? wp_kses_post(geodir_sanitize_html_field(wp_slash($value))) : $value;
                };
            }
            unset($handler);
        }
        unset($handlers);
        return $routes;
    }

    public static function read_rest_route(object $args): array
    {
        $route = self::route($args);
        $query = self::query($args);
        if ($route === null || $query === null) { return Operations::error('invalid_input'); }
        if (!Guard::allows_rest('GET', $route)) { return Operations::error('blocked_route'); }
        $request = new \WP_REST_Request('GET', $route);
        $request->set_query_params($query);
        $directory_html = static function (array $routes): array { return self::directory_html_routes($routes); };
        add_filter('rest_endpoints', $directory_html);
        try { $result = self::rest_result(rest_do_request($request), false, $request); }
        finally { remove_filter('rest_endpoints', $directory_html); }
        if ($route === '/wp-super-cache/v1/settings' && $result['state'] === 'succeeded') {
            // The vendor's time-settings handler treats an omitted email checkbox as enabled.
            $result['result']['guidance'] = 'When changing WP Super Cache time settings, explicitly include the current cache_gc_email_me boolean from this read to preserve notification preferences. Read back all settings after saving; garbage-collection scheduling may advance.';
        }
        return $result;
    }

    /** Actual post-controller routes, not the type's potentially stale namespace metadata. */
    public static function post_type_routes(string $name): array
    {
        $routes = [];
        foreach (rest_get_server()->get_routes() as $route => $handlers) {
            if (!preg_match('~^/[a-zA-Z0-9/_.-]+$~D', $route) || !Guard::allows_rest('GET', $route)) { continue; }
            foreach ($handlers as $handler) {
                $callback = is_array($handler) ? ($handler['callback'] ?? null) : null;
                if (!is_array($callback) || !($callback[0] instanceof \WP_REST_Posts_Controller) || $callback[1] !== 'get_items' || empty($handler['methods']['GET'])) { continue; }
                if (($callback[0]->get_item_schema()['title'] ?? null) === $name) { $routes[] = $route; }
            }
        }
        $routes = array_values(array_unique($routes));
        sort($routes);
        return $routes;
    }

    /** Match the registered controller and route before looking up an existing post. No callback is executed. */
    private static function rest_post_target(\WP_REST_Request $request): ?\WP_Post
    {
        foreach (rest_get_server()->get_routes() as $route => $handlers) {
            if (!preg_match('~^' . str_replace('~', '\\~', $route) . '$~iD', $request->get_route(), $matches) || !isset($matches['id']) || !ctype_digit($matches['id'])) { continue; }
            foreach ($handlers as $handler) {
                $callback = is_array($handler) ? ($handler['callback'] ?? null) : null;
                if (!is_array($callback) || !($callback[0] instanceof \WP_REST_Posts_Controller) || empty($handler['methods'][$request->get_method()])) { continue; }
                // Body/query id has the same precedence as in core dispatch.
                $id = $request->get_param('id') ?? $matches['id'];
                $post = is_scalar($id) ? get_post((int) $id) : null;
                return $post && ($callback[0]->get_item_schema()['title'] ?? null) === $post->post_type ? $post : null;
            }
        }
        return null;
    }

    /** Checks a REST change before it runs or waits; returns the request, or the refusal. */
    private static function check_rest(object $args)
    {
        $route = self::route($args);
        $query = self::query($args);
        $method = is_string($args->method ?? null) ? strtoupper($args->method) : '';
        $body = self::decoded($args, 'body_json');
        if ($route === null || $query === null || !in_array($method, self::METHODS, true) || $body === false || ($body !== null && !is_array($body))) {
            return Operations::error('invalid_input');
        }
        if (!Guard::allows_rest($method, $route)) { return Operations::error('blocked_route'); }
        $unsafe = self::unsafe_content($query, 'query') ?? self::unsafe_content($body, 'body');
        if ($unsafe) {
            if (preg_match('~^/wp/v2/(posts|pages|media|categories|tags)(/[0-9]{1,10})?$~D', $route)) { $unsafe['operation_diagnostics']['rest_route'] = $route; }
            return $unsafe;
        }
        $request = new \WP_REST_Request($method, $route);
        $request->set_query_params($query);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) $args->body_json);
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && (array_key_exists('content', $body ?? []) || array_key_exists('content', $query))) {
            $post = self::rest_post_target($request);
            if ($post && current_user_can('edit_post', $post->ID)) {
                $error = self::preserve_blocks($post, array_key_exists('content', $body ?? []) ? 'body' : 'query', ['content']);
                if ($error) {
                    if (preg_match('~^/wp/v2/(posts|pages|media|categories|tags)(/[0-9]{1,10})?$~D', $route)) { $error['operation_diagnostics']['rest_route'] = $route; }
                    return $error;
                }
            }
        }
        return $request;
    }

    public static function rest_preview(object $args): array
    {
        $request = self::check_rest($args);
        if (is_array($request)) { return $request; }
        // Preview validates our input only. Dispatching here could execute another plugin's filters or callback.
        // WordPress routing, argument and permission checks run when the owner approves execution.
        $query = $request->get_query_params();
        return ['state' => 'pending_approval', 'actions_used' => 0, 'preview' => ['kind' => 'rest',
            'title' => $request->get_method() . ' ' . $request->get_route() . ($query ? '?' . http_build_query($query) : ''),
            'risk' => (string) self::risk('write_rest_route', $args),
            'input' => self::excerpt(self::decoded($args, 'body_json'))]];
    }

    public static function write_rest_route(object $args): array
    {
        $request = self::check_rest($args);
        if (is_array($request)) { return $request; }
        // The dispatch filter runs after the route's argument and permission checks, right before its callback.
        $entered = false;
        $intercepted = false;
        $before_result = null;
        $observe_before = static function ($result, $handler, $matched) use ($request, &$before_result) {
            if ($matched === $request) { $before_result = $result; }
            return $result;
        };
        $mark = static function ($result, $matched) use ($request, &$entered) {
            if ($matched === $request) { $entered = true; }
            return $result;
        };
        $pre = static function ($result, $server, $matched) use ($request, &$intercepted) {
            if ($matched === $request && $result !== null) { $intercepted = true; }
            return $result;
        };
        $before = static function ($result, $handler, $matched) use ($request, &$intercepted, &$before_result) {
            if ($matched === $request && $result !== null && $result !== $before_result) { $intercepted = true; }
            return $result;
        };
        // Plugins can register additional post fields during rest_pre_dispatch, after core cached its route args.
        // Validate those fields against their live schemas before any update callback can save them.
        $validate_fields = static function ($result, $handler, $matched) use ($request) {
            if ($matched !== $request || $result !== null || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) { return $result; }
            $callback = $handler['callback'] ?? null;
            if (!is_array($callback) || !($callback[0] instanceof \WP_REST_Posts_Controller)) { return $result; }
            $type = $callback[0]->get_item_schema()['title'] ?? null;
            if (is_string($type) && post_type_exists($type)) {
                foreach (($GLOBALS['wp_rest_additional_fields'][$type] ?? []) as $name => $field) {
                    if (!$request->has_param($name) || !is_array($field['schema'] ?? null)) { continue; }
                    $valid = rest_validate_value_from_schema($request->get_param($name), $field['schema'], $name);
                    if (is_wp_error($valid)) {
                        return new \WP_Error('rest_invalid_param', $valid->get_error_message(), ['status' => 400, 'params' => [$name => $valid->get_error_message()]]);
                    }
                }
            }
            return $result;
        };
        $directory_html = static function (array $routes): array { return self::directory_html_routes($routes); };
        add_filter('rest_endpoints', $directory_html);
        add_filter('rest_dispatch_request', $mark, PHP_INT_MIN, 2);
        add_filter('rest_pre_dispatch', $pre, PHP_INT_MAX, 3);
        add_filter('rest_request_before_callbacks', $validate_fields, PHP_INT_MIN, 3);
        add_filter('rest_request_before_callbacks', $observe_before, PHP_INT_MIN, 3);
        add_filter('rest_request_before_callbacks', $before, PHP_INT_MAX, 3);
        Operations::$mutation_started = true;
        try { $response = self::filtered_change(static function () use ($request) { return rest_do_request($request); }); }
        finally {
            remove_filter('rest_endpoints', $directory_html);
            remove_filter('rest_dispatch_request', $mark, PHP_INT_MIN);
            remove_filter('rest_pre_dispatch', $pre, PHP_INT_MAX);
            remove_filter('rest_request_before_callbacks', $observe_before, PHP_INT_MIN);
            remove_filter('rest_request_before_callbacks', $before, PHP_INT_MAX);
            remove_filter('rest_request_before_callbacks', $validate_fields, PHP_INT_MIN);
        }
        return self::rest_result($response, $entered || $intercepted, $request);
    }

    /**
     * A REST answer: 2xx succeeded. An error before the route's callback (no such route, invalid arguments, no
     * permission) changed nothing. Any error once the callback started may have changed something (a plugin can save
     * and then report a validation failure), so the outcome is unknown and the owner reviews it.
     */
    private static function rest_result(\WP_REST_Response $response, bool $started, \WP_REST_Request $request): array
    {
        $status = $response->get_status();
        // Internal dispatch omits serve_request's response processing. Use the same core projection/embed helpers.
        $response = rest_filter_response_fields($response, rest_get_server(), $request);
        $embed = $request->has_param('_embed') && $request['_embed'] !== false ? rest_parse_embed_param($request['_embed'] === true ? 'true' : $request['_embed']) : false;
        $guard = static function ($result, $server, $embedded) {
            return Guard::allows_rest($embedded->get_method(), $embedded->get_route()) ? $result
                : new \WP_Error('blocked_route', 'This embedded route is not available through website management.', ['status' => 403]);
        };
        add_filter('rest_pre_dispatch', $guard, PHP_INT_MIN, 3);
        try { $data = rest_get_server()->response_to_data($response, $embed); }
        finally { remove_filter('rest_pre_dispatch', $guard, PHP_INT_MIN); }
        if (($status < 200 || $status >= 300) && $started) { return self::after_start(); }
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '';
            $code = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : 'rest_error';
            if ($code === 'rest_no_route') {
                $message = 'No registered REST route matches this path and method. Read the plugin namespace index and use its exact registered route and schema. Custom type names and rest_namespace metadata may differ from the actual route. Do not guess another path or retry this unchanged request.';
            }
            return ['state' => 'failed', 'actions_used' => 0, 'error' => ['code' => $status === 401 || $status === 403 ? 'permission_denied' : 'rest_error',
                'message' => mb_substr(trim($message) !== '' ? $message : 'The route refused this request.', 0, 300), 'status' => $status, 'route_code' => mb_substr($code, 0, 80)]];
        }
        $headers = array_change_key_case($response->get_headers(), CASE_LOWER);
        return self::ok(['status' => $status, 'data_json' => self::bounded($data)]
            + (isset($headers['x-wp-total']) ? ['total' => (int) $headers['x-wp-total']] : [])
            + (isset($headers['x-wp-totalpages']) ? ['total_pages' => (int) $headers['x-wp-totalpages']] : []));
    }

    // Shared ------------------------------------------------------------------------------------------------------

    private static function ok($result): array
    {
        return ['state' => 'succeeded', 'actions_used' => 1, 'result' => $result];
    }

    private static function after_start(string $code = 'outcome_unknown'): array
    {
        return ['state' => 'unknown', 'actions_used' => 0, 'error' => ['code' => $code, 'message' => self::AFTER_START]];
    }

    /** A refusal with the reason the assistant needs to correct its call; nothing ran. */
    private static function refuse(string $code, string $message): array
    {
        return ['state' => 'failed', 'actions_used' => 0, 'error' => ['code' => $code, 'message' => mb_substr(wp_strip_all_tags($message), 0, 300)]];
    }

    /**
     * Data as JSON text (see the class note). Results larger than MAX_RESULT are cut, with a note on narrowing the
     * request.
     */
    private static function bounded($data): string
    {
        $encoded = (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) <= self::MAX_RESULT) { return $encoded; }
        return (string) wp_json_encode(['truncated' => true, 'size' => strlen($encoded), 'excerpt' => mb_strcut($encoded, 0, self::MAX_RESULT),
            'note' => 'The result was too large and was cut. Ask for less: use _fields, per_page, context or a narrower input.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The input as the owner sees it on the approval page, readable and bounded. */
    private static function excerpt($input): string
    {
        if ($input === null) { return ''; }
        $text = (string) wp_json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return strlen($text) > 6000 ? mb_strcut($text, 0, 6000) . "\n…" : $text;
    }
}
