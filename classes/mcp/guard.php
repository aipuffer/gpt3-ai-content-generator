<?php
/**
 * Domains no assistant operation may be registered for, whatever its safety level or the WordPress user's role.
 *
 * This is a check on the registry's declarations, not a sandbox: it refuses to register an operation that declares
 * a blocked domain, and the generic engines (abilities, REST) ask it before every call. A handler itself is plain PHP,
 * so what keeps it inside its domain is review of that handler and the acting user's own capabilities, which
 * WordPress checks on every change.
 *
 * For the generic engines it is a filter on names only: it reads an ability's name and category and a route's
 * segments, never what the callback behind them does. A plugin can name a route "preferences" and change site options
 * in it. That is why every generic change waits for the owner (Registry::needs_approval), who sees the exact request.
 */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Guard
{
    /**
     * Site options and settings, users and roles, installing or switching plugins and themes, files, code and raw
     * SQL. These change how the whole site works or who controls it.
     */
    public const BLOCKED_DOMAINS = ['options', 'settings', 'users', 'roles', 'capabilities', 'plugins', 'themes', 'files', 'code', 'sql'];

    public static function allows_domain(string $domain): bool
    {
        return $domain !== '' && !in_array(strtolower($domain), self::BLOCKED_DOMAINS, true);
    }

    /** Words that name a blocked domain in an ability's name or category, or in a REST route segment. */
    private const BLOCKED_WORDS = ['option', 'options', 'setting', 'settings', 'user', 'users', 'role', 'roles', 'capability',
        'capabilities', 'plugin', 'plugins', 'theme', 'themes', 'file', 'files', 'code', 'sql', 'database'];

    /** REST namespaces and routes no call may reach: this plugin's own, batch and bulk routes anywhere (one request
     *  carrying many creates, updates and deletes: the core /batch/v1 and plugin ones such as WooCommerce's
     *  /wc/v3/products/batch), abilities (they have their own tools), site health, the outbound oEmbed proxy and
     *  application passwords. */
    private const BLOCKED_ROUTES = ['#^/aipkit(/|$)#', '#(^|/)(batch|bulk)(/|$)#', '#^/wp-abilities/#', '#^/wp-site-health/#',
        '#^/oembed/1\.0/proxy#', '#/application-passwords(/|$)#'];

    /** Words that name a composite change in an ability's name: many actions in one call, never run as one. */
    private const COMPOSITE_WORDS = ['batch', 'bulk'];

    /** Route segments a change may not touch: site-wide settings, accounts, code and site design, outbound hooks. */
    private const BLOCKED_SEGMENTS = ['settings', 'options', 'users', 'roles', 'capabilities', 'plugins', 'themes',
        'templates', 'template-parts', 'global-styles', 'font-families', 'font-collections', 'webhooks', 'system_status'];

    /**
     * An ability may run unless it changes something and its category or a word of its name names a blocked domain
     * (such as "update-settings" or "delete-user") or a composite change ("products-batch"). Reading is allowed: the
     * acting user's permissions still apply.
     */
    public static function allows_ability(string $name, string $category, string $risk): bool
    {
        if ($risk === 'read') { return true; }
        $words = preg_split('/[\/_-]+/', strtolower($name . '/' . $category), -1, PREG_SPLIT_NO_EMPTY);
        return !array_intersect($words, array_merge(self::BLOCKED_WORDS, self::COMPOSITE_WORDS));
    }

    /** A REST call may run unless its route is blocked, or it changes something under a blocked segment. */
    public static function allows_rest(string $method, string $route): bool
    {
        $route = strtolower($route);
        foreach (self::BLOCKED_ROUTES as $pattern) {
            if (preg_match($pattern, $route)) { return false; }
        }
        if ($method === 'GET') { return true; }
        return !array_intersect(explode('/', trim($route, '/')), self::BLOCKED_SEGMENTS);
    }

    /** Fails loudly while building the registry, so a blocked operation can never be registered. */
    public static function assert_domain(string $domain): void
    {
        if (!self::allows_domain($domain)) {
            throw new \LogicException('This operation domain is not supported by website management.');
        }
    }
}
