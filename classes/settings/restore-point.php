<?php

namespace WPAICG\Dashboard\Ajax;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one restore point Backups keeps: a copy of the settings, when it was saved and why.
 * It is saved by hand, and for you before every restore, so a restore can always be undone.
 */
final class AIPKit_Settings_Restore_Point
{
    public const OPTION = 'aipkit_settings_restore_point';

    private const REASONS = ['manual', 'before_file', 'before_restore'];

    public static function get(): array
    {
        $point = get_option(self::OPTION, []);
        return is_array($point) ? $point : [];
    }

    /**
     * @param array<string, mixed> $payload A settings backup, as Download makes it.
     */
    public static function save(array $payload, string $reason): bool
    {
        $payload['saved_reason'] = in_array($reason, self::REASONS, true) ? $reason : 'manual';
        update_option(self::OPTION, $payload, 'no');
        return self::get() === $payload;
    }

    /**
     * What Backups says about it: "Saved October 6, 2026, 2:20 pm · 2 days ago", and the date on its own for the warning.
     *
     * @return array{saved: bool, text: string, date: string}
     */
    public static function summary(): array
    {
        $point = self::get();
        if ($point === []) {
            return [
                'saved' => false,
                'text' => __('None saved yet. One is also saved for you before every restore.', 'gpt3-ai-content-generator'),
                'date' => '',
            ];
        }

        $timestamp = strtotime((string) ($point['exported_at'] ?? '')) ?: 0;
        $format = (get_option('date_format') ?: 'F j, Y') . ', ' . (get_option('time_format') ?: 'g:i a');
        $date = $timestamp ? (string) wp_date($format, $timestamp) : '';
        /* translators: %s: date and time, e.g. "October 6, 2026, 2:20 pm". */
        $parts = [$date !== '' ? sprintf(__('Saved %s', 'gpt3-ai-content-generator'), $date) : __('Saved', 'gpt3-ai-content-generator')];
        if ($timestamp) {
            $parts[] = time() - $timestamp < MINUTE_IN_SECONDS
                ? __('just now', 'gpt3-ai-content-generator')
                /* translators: %s: how long ago, e.g. "2 days". */
                : sprintf(__('%s ago', 'gpt3-ai-content-generator'), human_time_diff($timestamp, time()));
        }
        $reason = (string) ($point['saved_reason'] ?? 'manual');
        if ($reason === 'before_file') {
            $parts[] = __('before restoring from a file', 'gpt3-ai-content-generator');
        } elseif ($reason === 'before_restore') {
            $parts[] = __('before going back', 'gpt3-ai-content-generator');
        }

        return ['saved' => true, 'text' => implode(' · ', $parts), 'date' => $date];
    }
}
