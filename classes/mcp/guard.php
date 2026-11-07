<?php
/**
 * Domain names unavailable to generic bridge declarations and name-based REST/ability access.
 *
 * First-party typed adapters such as WP-CLI use an explicit command whitelist and native WordPress capabilities.
 * They do not open these blocked generic routes or execute shell/PHP input.
 * This is a check on the registry's declarations, not a sandbox: it refuses to register an operation that declares
 * a blocked domain, and the generic engines (abilities, REST) ask it before every call. A handler itself is plain PHP,
 * so what keeps it inside its domain is review of that handler and the acting user's own capabilities, which
 * WordPress checks on every change.
 *
 * For the generic engines it is a filter on names only: it reads an ability's name and category and a route's
 * segments, never what the callback behind them does. A plugin can name a route "preferences" and change site options
 * in it. Registry applies the owner’s selected approval policy; these name checks are not a guarantee about a callback.
 */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Guard
{
    /**
     * Users and roles, installing or switching plugins and themes, files, code and raw
     * SQL. These change how the whole site works or who controls it.
     */
    public const BLOCKED_DOMAINS = ['users', 'roles', 'capabilities', 'plugins', 'themes', 'files', 'code', 'sql'];

    public static function allows_domain(string $domain): bool
    {
        return $domain !== '' && !in_array(strtolower($domain), self::BLOCKED_DOMAINS, true);
    }

    /** Words that name a blocked domain in an ability's name or category, or in a REST route segment. */
    private const BLOCKED_WORDS = ['user', 'users', 'role', 'roles', 'capability',
        'capabilities', 'plugin', 'plugins', 'theme', 'themes', 'file', 'files', 'code', 'sql', 'database'];

    /** REST namespaces and routes no call may reach: this plugin's own, batch and bulk routes anywhere (one request
     *  carrying many creates, updates and deletes: the core /batch/v1 and plugin ones such as WooCommerce's
     *  /wc/v3/products/batch), abilities (they have their own tools), site health, the outbound oEmbed proxy and
     *  application passwords. */
    private const BLOCKED_ROUTES = ['#^/aipkit(/|$)#', '#(^|/)(batch|bulk)(/|$)#', '#^/wp-abilities/#', '#^/wp-site-health/#',
        '#^/oembed/1\.0/proxy#', '#/application-passwords(/|$)#',
        // Hostinger registers this credential rotation as a GET.
        '#^/hostinger-tools-plugin/v1/regenerate-bypass-code(/|$)#'];

    /** Words that name a composite change in an ability's name: many actions in one call, never run as one. */
    private const COMPOSITE_WORDS = ['batch', 'bulk'];

    /** Route segments a change may not touch: accounts, code and site design, outbound hooks. */
    private const BLOCKED_SEGMENTS = ['users', 'roles', 'capabilities', 'plugins', 'themes',
        'templates', 'template-parts', 'global-styles', 'font-families', 'font-collections', 'webhooks', 'system_status'];

    /**
     * An ability may run unless it changes something and its category or a word of its name names a blocked domain
     * (such as "delete-user" or "activate-plugin") or a composite change ("products-batch"). Ordinary settings follow Registry’s connection policy.
     * Reading is allowed; the acting user's permissions still apply.
     */
    public static function allows_ability(string $name, string $category, string $risk): bool
    {
        if ($risk === 'read') { return true; }
        // Preferences change Rank Math configuration, not installed-plugin activation. Native capabilities,
        // schema validation and the connection's selected approval policy still apply.
        if ($name === 'rank-math/set-plugin-preferences' && $category === 'rank-math-settings') { return true; }
        // Greenshift's plugin variables are design tokens, not plugin installation or activation.
        if ($name === 'greenshift/add-plugin-variables' && $category === 'greenshift') { return true; }
        $words = preg_split('/[\/_-]+/', strtolower($name . '/' . $category), -1, PREG_SPLIT_NO_EMPTY);
        return !array_intersect($words, array_merge(self::BLOCKED_WORDS, self::COMPOSITE_WORDS));
    }

    /** A REST call may run unless its route is blocked, or it changes something under a blocked segment. */
    public static function allows_rest(string $method, string $route): bool
    {
        $route = strtolower(rawurldecode($route));
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
    /** Credential values only: ordinary token counts, permissions and licence status are not secrets. */
    private static function credential_key(string $key): bool
    {
        $key = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
        return (bool) preg_match('/(?:password|passwd|userpass|smtppass|secretkey|secret|apikey|privatekey|accesskey|authkey|authsalt|secureauth|nonce|clientsecret|consumerkey|licensekey|licencekey|applicationpassword|refreshtoken|accesstoken|idtoken|authtoken|bearertoken|authorization|backupcodes|totp|otpcode|sessiontoken|sessioncookie|authcookie|loggedincookie|useractivationkey|loggedinsalt)$/', $key)
            || in_array($key, ['token', 'credentials', 'credential', 'cookie', 'cookies', 'salt', 'salts', 'passkey', 'pwd', 'jwt', 'bypasscode'], true);
    }

    /** Values associated with credentials or this connection's internal state never become assistant input/output. */
    public static function private_key(string $key): bool
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key)) === 'bypasscode'
            || (bool) preg_match('/(?:password|passwd|secret|token|api.?key|private.?key|auth.?key|auth.?salt|secure_auth|logged_in|nonce.?salt|license|licence|oauth|consumer.?(?:key|email)|access.?key|activation.?key|new_email|two_factor|2fa|totp|webauthn|passkey|backup.?codes|application_password|session|capabilities|user_level|aipkit_mcp|aipkit_cloud|freemius|fs_accounts)/i', $key);
    }

    public static function public_data($value, int $depth = 0, bool $credentials_only = false)
    {
        if ($depth > ($credentials_only ? 512 : 12)) { return '[depth limit]'; }
        if (is_string($value) && preg_match('/^\s*[\[{]/', $value)) {
            $decoded = json_decode($value, !$credentials_only);
            if (is_array($decoded) || is_object($decoded)) { $safe = self::public_data($decoded, $depth + 1, $credentials_only); return wp_json_encode($safe) === wp_json_encode($decoded) ? $value : wp_json_encode($safe); }
        }
        $object = is_object($value);
        if ($object) { $value = get_object_vars($value); }
        if (!is_array($value)) { return $value; }
        foreach ($value as $key => $item) {
            $private = is_string($key) && ($credentials_only ? self::credential_key($key) && !is_bool($item) && $item !== null : self::private_key($key));
            // Discovery describes secret inputs; keep the schema, never a supplied default/example value.
            $schema = is_object($item) ? get_object_vars($item) : $item;
            if ($credentials_only && $private && is_array($schema) && in_array($schema['type'] ?? '', ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'], true)) {
                $item = array_intersect_key($schema, array_flip(['type', 'description', 'title', 'format', 'properties', 'required', 'items', 'additionalProperties', 'minLength', 'maxLength', 'minimum', 'maximum', 'pattern', 'oneOf', 'anyOf', 'allOf']));
                $private = false;
            }
            $value[$key] = $private ? '[redacted]' : self::public_data($item, $depth + 1, $credentials_only);
        }
        return $credentials_only && $object ? (object) $value : $value;
    }
}
