<?php

namespace WPAICG;

if (!defined('ABSPATH')) {
    exit;
}

/** Records failure locations without logging prompts, credentials or exception arguments. */
class RuntimeDiagnostics
{
    public static function report(\Throwable $error, string $operation): string
    {
        $reference = wp_generate_uuid4();
        $file = wp_normalize_path($error->getFile());
        $root = wp_normalize_path(dirname(__DIR__) . '/');
        $details = [
            'reference' => $reference,
            'operation' => $operation,
            'exception' => get_class($error),
            'file' => strpos($file, $root) === 0 ? substr($file, strlen($root)) : basename($file),
            'line' => $error->getLine(),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'curl_init' => function_exists('curl_init'),
            'mb_check_encoding' => function_exists('mb_check_encoding'),
        ];
        if (preg_match('/Call to undefined function ([a-zA-Z0-9_\\\\]+)\(\)/', $error->getMessage(), $match)) {
            $details['missing_function'] = $match[1];
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Actionable server diagnostics; no request data, raw exception messages or stack arguments.
        error_log('[AI Puffer] ' . wp_json_encode($details));
        return $reference;
    }
}
