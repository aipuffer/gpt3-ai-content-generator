<?php

namespace WPAICG\Utils;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitizes AI prompts and prompt templates without stripping literal HTML.
 */
class AIPKit_Prompt_Sanitizer
{
    /**
     * Preserve prompt text, including literal HTML examples, while removing
     * invalid/control bytes that should not be stored or sent to providers.
     *
     * @param mixed $prompt Raw prompt value.
     * @param bool  $trim Whether to trim leading/trailing whitespace.
     * @return string
     */
    public static function sanitize($prompt, bool $trim = true): string
    {
        if (is_array($prompt) || is_object($prompt)) {
            return '';
        }

        $prompt = wp_check_invalid_utf8((string) $prompt);
        $prompt = str_replace(["\r\n", "\r"], "\n", $prompt);
        $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $prompt);
        $prompt = is_string($prompt) ? $prompt : '';

        return $trim ? trim($prompt) : $prompt;
    }
}
