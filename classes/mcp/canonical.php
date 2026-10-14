<?php
/** Byte-identical request and outcome encoding for the hosted website bridge. */
namespace WPAICG\Mcp;

use InvalidArgumentException;

if (!defined('ABSPATH')) { exit; }

final class Canonical
{
    public static function encode($value): string
    {
        if ($value === null) { return 'null'; }
        if (is_bool($value)) { return $value ? 'true' : 'false'; }
        if (is_int($value) && abs($value) <= 9007199254740991) { return (string) $value; }
        if (is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
            if ($encoded === false) { throw new InvalidArgumentException('invalid_utf8'); }
            return $encoded;
        }
        if (is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1))) {
            return '[' . implode(',', array_map([self::class, 'encode'], $value)) . ']';
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $items = [];
            foreach ($properties as $key => $item) {
                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string) $key)) { throw new InvalidArgumentException('invalid_key'); }
                $items[] = self::encode((string) $key) . ':' . self::encode($item);
            }
            return '{' . implode(',', $items) . '}';
        }
        throw new InvalidArgumentException('unsupported_json');
    }

    public static function intent(object $body): string
    {
        $intent = new \stdClass();
        foreach (['account_id', 'site_id', 'connection_id', 'acting_user', 'tool', 'args'] as $key) {
            if (!property_exists($body, $key)) { throw new InvalidArgumentException('missing_binding'); }
            $intent->$key = $body->$key;
        }
        return hash('sha256', self::encode($intent));
    }

    public static function request(string $host, string $time, string $nonce, string $grant, string $body_hash): string
    {
        return implode("\n", ['aipkit-mcp-v1', 'POST', strtolower($host), '/aipkit/v1/mcp/execute', $time, $nonce, $grant, $body_hash]);
    }
}
