<?php

namespace WPAICG\Chat\Utils;

if (!defined('ABSPATH')) { exit; }

/**
 * Common helper functions (sanitization, validation, time formatting, text cleaning).
 * UPDATED: Added aipkit_clean_text_for_tts method with emoji removal.
 */
class Utils {

    /**
     * Returns the difference between two timestamps in human-readable format.
     * Uses WordPress's human_time_diff function.
     *
     * @since NEXT_VERSION
     *
     * @param int|string $from Unix timestamp or MySQL-formatted timestamp string from which the difference begins.
     * @param int|string|null $to   Unix timestamp or MySQL-formatted timestamp string to end the time difference. Default null is 'current time'.
     * @return string Human-readable time difference (e.g., "1 hour", "5 mins", "2 days").
     */
    public static function aipkit_human_time_diff($from, $to = null) {
        // Convert MySQL timestamps to Unix timestamps if necessary
        if (!is_numeric($from)) {
            $from = strtotime($from . ' GMT'); // Assume GMT if MySQL timestamp
        }
        if ($to !== null && !is_numeric($to)) {
            $to = strtotime($to . ' GMT'); // Assume GMT if MySQL timestamp
        }

        // Ensure $from is a valid timestamp
        if (!$from) {
            return __('Invalid date', 'gpt3-ai-content-generator');
        }

        // Use WordPress built-in function
        if ($to === null) {
            // Use current_time('timestamp') for WP timezone awareness if $to is null
            $diff = human_time_diff($from, current_time('timestamp', true)); // Use true for GMT
        } else {
            $diff = human_time_diff($from, $to);
        }

        // translators: %s: Human-readable time difference (e.g., "1 hour", "5 mins").
        return sprintf(__('%s ago', 'gpt3-ai-content-generator'), $diff);
    }

    /**
     * Cleans text intended for Text-to-Speech (TTS) by removing HTML, Markdown, URLs, and Emojis.
     *
     * @param string $text The input text potentially containing formatting.
     * @return string The cleaned text suitable for TTS.
     */
    public static function aipkit_clean_text_for_tts(string $text): string {
        // 1. Strip HTML tags
        $cleaned_text = wp_strip_all_tags($text);

        // 2. Remove Markdown Images: ![alt text](url)
        $cleaned_text = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $cleaned_text);

        // 3. Convert Markdown Links to Link Text: [link text](url) -> link text
        $cleaned_text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $cleaned_text);

        // 4. Remove Markdown Code Blocks (``` ... ```) - simpler multiline removal
        $cleaned_text = preg_replace('/```[\s\S]*?```/', '', $cleaned_text);

        // 5. Remove Markdown Inline Code: `code` -> code
        $cleaned_text = preg_replace('/`([^`]+)`/', '$1', $cleaned_text);

        // 6. Remove Markdown Emphasis/Strong markers (*, _, **, __) - careful not to remove legitimate uses
        //    This removes the markers themselves, keeping the text content.
        $cleaned_text = str_replace(['**', '__', '*', '_'], '', $cleaned_text);

        // 7. Remove Markdown Headers (# Heading)
        $cleaned_text = preg_replace('/^#+\s+/m', '', $cleaned_text);

        // 8. Remove Markdown Blockquotes (> Quote)
        $cleaned_text = preg_replace('/^>\s*/m', '', $cleaned_text);

        // 9. Remove Markdown List Markers (*, -, +, 1.)
        $cleaned_text = preg_replace('/^(\s*(\*|\-|\+)\s+|\s*\d+\.\s+)/m', '', $cleaned_text);

        // 10. Remove Markdown Horizontal Rules (---, ***, ___)
        $cleaned_text = preg_replace('/^( *(\*|-|_)){3,} *$/m', '', $cleaned_text);

        // 11. Remove remaining/empty Markdown artifacts like []()
        $cleaned_text = str_replace(['[]()', '()'], '', $cleaned_text);

        // 12. Remove URLs (basic check for http/https)
        //     This regex tries to avoid removing things like file.txt, focusing on web URLs.
        $cleaned_text = preg_replace('/\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|]/i', '', $cleaned_text);

        // 12.5 NEW: Remove Emojis
        // This regex targets common emoji Unicode ranges.
        $emoji_pattern = '/(?:'
            . '[\x{1F300}-\x{1F5FF}]' // Symbols and Pictographs
            . '|[\x{1F600}-\x{1F64F}]' // Emoticons
            . '|[\x{1F680}-\x{1F6FF}]' // Transport and Map Symbols
            . '|[\x{2600}-\x{26FF}]'   // Miscellaneous Symbols (includes weather, chess, zodiac etc.)
            . '|[\x{2700}-\x{27BF}]'   // Dingbats (includes check marks, crosses, pencils etc.)
            . '|[\x{1F900}-\x{1F9FF}]' // Supplemental Symbols and Pictographs (includes face parts, animals, food)
            . '|[\x{1FA70}-\x{1FAFF}]' // Symbols and Pictographs Extended-A (includes medical symbols, objects)
            . '|[\x{FE00}-\x{FE0F}]'   // Variation Selectors
            . '|[\x{E0020}-\x{E007F}]' // Tag sequences (for flags etc.) - may be less common in TTS input
            . ')+/u'; // Use 'u' modifier for Unicode support
        $cleaned_text = preg_replace($emoji_pattern, '', $cleaned_text);

        // 13. Consolidate whitespace (multiple spaces/newlines to single space)
        $cleaned_text = preg_replace('/\s+/', ' ', $cleaned_text);

        // 14. Trim leading/trailing whitespace
        $cleaned_text = trim($cleaned_text);

        return $cleaned_text;
    }

}