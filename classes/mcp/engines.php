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
     * The same content rules as create_draft, for any change: no scripts, HTML comments (which carry blocks and
     * builder instructions), shortcodes or protected metadata (keys starting with "_") anywhere in the request. Null
     * when the request is fine, or the refusal.
     */
    private static function unsafe_content($values): ?array
    {
        $stack = [$values];
        while ($stack) {
            $value = array_pop($stack);
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    // A protected field by name, or a metadata entry naming one ({"key": "_price", "value": ...}).
                    if ((is_string($key) && strpos($key, '_') === 0) || ($key === 'key' && is_string($item) && strpos($item, '_') === 0)) {
                        $field = is_string($key) && strpos($key, '_') === 0 ? $key : $item;
                        $message = $field === '_yoast_wpseo_focuskw'
                            ? 'Protected metadata cannot be edited directly. Discover Yoast with find_abilities (query "yoast"); if offered, use run_ability "ai-puffer/yoast-set-focus-keyphrase" with post_id and focus_keyphrase. Verify using Yoast SEO data. Scores are not recalculated.'
                            : (strpos($field, '_yoast_wpseo_') === 0
                            ? 'Protected metadata cannot be edited directly. Discover Yoast with find_abilities (query "yoast"); if offered, use run_ability "yoast-seo/update-post-seo-data" with post_id and seo_title/meta_description. Verify using read_ability "yoast-seo/get-post-seo-data".'
                            : 'Protected fields (names starting with "_") can’t be changed through this tool. Discover a supported native plugin action with find_abilities; do not bypass this restriction.');
                        return self::refuse('unsupported_content', $message);
                    }
                    $stack[] = $item;
                }
            } elseif (is_string($value) && (stripos($value, '<script') !== false || strpos($value, '<!--') !== false
                || preg_match('/\bjavascript\s*:/i', $value) || preg_match('/\[[A-Za-z][A-Za-z0-9_-]*(?:\s|\/|\])/', $value))) {
                return self::refuse('unsupported_content', 'Scripts, HTML comments, blocks and shortcodes can’t be sent through this tool. Send plain text or simple HTML.');
            }
        }
        return null;
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
                'description' => mb_substr($ability->get_description(), 0, 600), 'category' => $ability->get_category(),
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
        if ($input === false) { return Operations::error('invalid_input'); }
        return self::ability_result($ability->execute($input));
    }

    /** Checks a change before it runs or waits: the ability, its input and the acting user's permission. */
    private static function check_ability(object $args)
    {
        $ability = self::ability($args);
        if (!$ability) { return Operations::error('ability_unavailable'); }
        if (self::ability_risk($ability) === 'read') { return self::refuse('use_read_ability', 'This ability only reads. Use read_ability.'); }
        $input = self::input($args, $ability);
        if ($input === false) { return Operations::error('invalid_input'); }
        $unsafe = apply_filters('aipkit_mcp_ability_content_error', self::unsafe_content($input), $ability->get_name(), $input);
        if ($unsafe) { return $unsafe; }
        $input = $ability->normalize_input($input);
        if (is_wp_error($input)) { return self::refuse('invalid_input', $input->get_error_message()); }
        $valid = $ability->validate_input($input);
        if (is_wp_error($valid)) { return self::refuse('invalid_input', $valid->get_error_message()); }
        if ($ability->check_permissions($input) !== true) { return Operations::error('permission_denied'); }
        return $ability;
    }

    public static function ability_preview(object $args): array
    {
        $ability = self::check_ability($args);
        if (is_array($ability)) { return $ability; }
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
        if (is_array($ability)) { return $ability; }
        if (class_exists(Elementor::class) && Elementor::handles($args->name)) { return Elementor::run($args->name, (array) self::input($args, $ability)); }
        $extension = apply_filters('aipkit_mcp_ability_result', null, $args->name, self::input($args, $ability), false);
        if (is_array($extension)) { return $extension; }
        $entered = false;
        $mark = static function (string $name) use ($ability, &$entered): void { if ($name === $ability->get_name()) { $entered = true; } };
        add_action('wp_before_execute_ability', $mark, PHP_INT_MIN, 1);
        Operations::$mutation_started = true;
        try { $result = self::filtered_change(static function () use ($ability, $args) { return $ability->execute(self::input($args, $ability)); }); }
        finally { remove_action('wp_before_execute_ability', $mark, PHP_INT_MIN); }
        // Refused before the callback (input or permission, as checked above): nothing ran. Any other error came from
        // the callback, an output check after it, or another plugin's short-circuit: something may have changed.
        if (is_wp_error($result) && ($entered || !in_array($result->get_error_code(), ['ability_invalid_input', 'ability_invalid_permissions'], true))) {
            return self::after_start();
        }
        return self::ability_result($result);
    }

    /** An ability's answer when nothing could have changed (a read, or a refusal before the callback). */
    private static function ability_result($result): array
    {
        if (is_wp_error($result)) {
            return $result->get_error_code() === 'ability_invalid_permissions' ? Operations::error('permission_denied') : self::refuse('ability_failed', $result->get_error_message());
        }
        return self::ok(['data_json' => self::bounded($result)]);
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
        return $query;
    }

    public static function read_rest_route(object $args): array
    {
        $route = self::route($args);
        $query = self::query($args);
        if ($route === null || $query === null) { return Operations::error('invalid_input'); }
        if (!Guard::allows_rest('GET', $route)) { return Operations::error('blocked_route'); }
        $request = new \WP_REST_Request('GET', $route);
        $request->set_query_params($query);
        return self::rest_result(rest_do_request($request), false);
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
        $unsafe = self::unsafe_content([$query, $body]);
        if ($unsafe) { return $unsafe; }
        $request = new \WP_REST_Request($method, $route);
        $request->set_query_params($query);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) $args->body_json);
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
        add_filter('rest_dispatch_request', $mark, PHP_INT_MIN, 2);
        add_filter('rest_pre_dispatch', $pre, PHP_INT_MAX, 3);
        add_filter('rest_request_before_callbacks', $observe_before, PHP_INT_MIN, 3);
        add_filter('rest_request_before_callbacks', $before, PHP_INT_MAX, 3);
        Operations::$mutation_started = true;
        try { $response = self::filtered_change(static function () use ($request) { return rest_do_request($request); }); }
        finally {
            remove_filter('rest_dispatch_request', $mark, PHP_INT_MIN);
            remove_filter('rest_pre_dispatch', $pre, PHP_INT_MAX);
            remove_filter('rest_request_before_callbacks', $observe_before, PHP_INT_MIN);
            remove_filter('rest_request_before_callbacks', $before, PHP_INT_MAX);
        }
        return self::rest_result($response, $entered || $intercepted);
    }

    /**
     * A REST answer: 2xx succeeded. An error before the route's callback (no such route, invalid arguments, no
     * permission) changed nothing. Any error once the callback started may have changed something (a plugin can save
     * and then report a validation failure), so the outcome is unknown and the owner reviews it.
     */
    private static function rest_result(\WP_REST_Response $response, bool $started): array
    {
        $status = $response->get_status();
        $data = rest_get_server()->response_to_data($response, false);
        if (($status < 200 || $status >= 300) && $started) { return self::after_start(); }
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '';
            $code = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : 'rest_error';
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

    private static function after_start(): array
    {
        return ['state' => 'unknown', 'actions_used' => 0, 'error' => ['code' => 'outcome_unknown', 'message' => self::AFTER_START]];
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
