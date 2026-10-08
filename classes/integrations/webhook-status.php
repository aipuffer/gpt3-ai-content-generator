<?php

namespace WPAICG\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What each webhook endpoint shows in For developers: when it last got an event, and the sends that
 * didn't go through, told in plain words with the endpoint's own answer kept for the details.
 */
final class AIPKit_Event_Webhook_Status
{
    public const SENT_OPTION = 'aipkit_event_webhook_endpoint_sent';

    private const RECENT_FAILURES = 20;
    private const KEEP = 50;

    public static function register_hooks(): void
    {
        add_action('aipkit_event_webhooks_delivery_completed', [self::class, 'record_sent'], 10, 1);
    }

    /**
     * Keeps when each endpoint last took an event. At most one write a minute per endpoint.
     *
     * @param array<string, mixed> $result
     */
    public static function record_sent($result): void
    {
        $endpoint_id = is_array($result) ? sanitize_key((string) ($result['endpoint_id'] ?? '')) : '';
        if ($endpoint_id === '') {
            return;
        }
        $sent = self::sent_times();
        $now = time();
        if (isset($sent[$endpoint_id]) && $now - $sent[$endpoint_id] < MINUTE_IN_SECONDS) {
            return;
        }
        $sent[$endpoint_id] = $now;
        arsort($sent);
        update_option(self::SENT_OPTION, array_slice($sent, 0, self::KEEP, true), false);
    }

    /**
     * @return array<string, int>
     */
    public static function sent_times(): array
    {
        $sent = get_option(self::SENT_OPTION, []);
        if (!is_array($sent)) {
            return [];
        }
        $clean = [];
        foreach ($sent as $endpoint_id => $timestamp) {
            $key = sanitize_key((string) $endpoint_id);
            if ($key !== '' && (int) $timestamp > 0) {
                $clean[$key] = (int) $timestamp;
            }
        }
        return $clean;
    }

    /**
     * "2 hours ago", or "just now".
     */
    public static function ago(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        return time() - $timestamp < MINUTE_IN_SECONDS
            ? __('just now', 'gpt3-ai-content-generator')
            /* translators: %s: how long ago, e.g. "2 hours". */
            : sprintf(__('%s ago', 'gpt3-ai-content-generator'), human_time_diff($timestamp, time()));
    }

    /**
     * Each event in plain words, as the endpoint's panel lists it.
     *
     * @return array<string, string>
     */
    public static function event_labels(): array
    {
        return [
            'chatbot.session_started' => __('A chat starts', 'gpt3-ai-content-generator'),
            'chatbot.user_message_submitted' => __('Someone sends a message', 'gpt3-ai-content-generator'),
            'chatbot.response_generated' => __('The chatbot replies', 'gpt3-ai-content-generator'),
            'chatbot.fb_submitted' => __('Someone rates a reply', 'gpt3-ai-content-generator'),
            'chatbot.form_submitted' => __('Someone sends a chatbot form', 'gpt3-ai-content-generator'),
            'content.generated' => __('Content is written', 'gpt3-ai-content-generator'),
            'task.item_completed' => __('A task finishes an item', 'gpt3-ai-content-generator'),
            'form.submitted' => __('Someone sends an AI form', 'gpt3-ai-content-generator'),
            'image.generated' => __('An image is made', 'gpt3-ai-content-generator'),
            'kb.source_indexed' => __('A source is added to the knowledge base', 'gpt3-ai-content-generator'),
        ];
    }

    /**
     * Recent failed sends, newest first, for each endpoint they didn't reach. The queue is read unless jobs are given.
     *
     * @param array<string, string> $endpoint_hosts
     * @param array<int, mixed>|null $jobs
     * @return array<string, array<int, array<string, string>>>
     */
    public static function failures_by_endpoint(array $endpoint_hosts = [], ?array $jobs = null): array
    {
        $store = AIPKit_Event_Queue_Store::class;
        if ($jobs === null) {
            $jobs = class_exists($store) && method_exists($store, 'get_recent_failed_webhook_jobs')
                ? (array) $store::get_recent_failed_webhook_jobs(self::RECENT_FAILURES)
                : [];
        }

        $failures = [];
        foreach ($jobs as $job) {
            if (!is_array($job) || sanitize_text_field((string) ($job['job_uuid'] ?? '')) === '') {
                continue;
            }
            foreach ((array) ($job['targets'] ?? []) as $target) {
                $endpoint_id = is_array($target) ? sanitize_key((string) ($target['id'] ?? '')) : '';
                if ($endpoint_id === '') {
                    continue;
                }
                $host = (string) ($endpoint_hosts[$endpoint_id] ?? wp_parse_url((string) ($target['url'] ?? ''), PHP_URL_HOST));
                $failures[$endpoint_id][] = self::describe($job, $host);
            }
        }

        return $failures;
    }

    /**
     * One failed send: its ID, what went wrong in plain words, the endpoint's own answer, and when.
     *
     * @param array<string, mixed> $job
     * @return array{uuid: string, reason: string, details: string, when: string, event: string}
     */
    public static function describe(array $job, string $host = ''): array
    {
        $raw = sanitize_text_field((string) ($job['error_message'] ?? ''));
        $lower = strtolower($raw);
        $where = $host !== '' ? sanitize_text_field($host) : __('The endpoint', 'gpt3-ai-content-generator');
        $http = preg_match('/\bHTTP\s+(\d{3})\b/i', $raw, $match) === 1 ? (int) $match[1] : 0;
        $curl = preg_match('/\bcurl error\s+(\d+)\b/i', $raw, $match) === 1 ? (int) $match[1] : 0;

        if (in_array($http, [401, 403], true)) {
            /* translators: %s: the endpoint's host, e.g. hooks.example.com. */
            $reason = sprintf(__('%s didn’t accept the request. Check what it expects.', 'gpt3-ai-content-generator'), $where);
        } elseif (in_array($http, [404, 410], true)) {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('%s couldn’t find the address. Check the URL.', 'gpt3-ai-content-generator'), $where);
        } elseif ($http === 429) {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('%s asked to slow down. Send it again in a few minutes.', 'gpt3-ai-content-generator'), $where);
        } elseif ($http >= 500) {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('%s had a problem on its side. Send it again later.', 'gpt3-ai-content-generator'), $where);
        } elseif ($http >= 400) {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('%s didn’t accept it. The details have its answer.', 'gpt3-ai-content-generator'), $where);
        } elseif ($curl === 28 || strpos($lower, 'timed out') !== false) {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('%s didn’t answer in time. Send it again.', 'gpt3-ai-content-generator'), $where);
        } elseif (in_array($curl, [35, 51, 58, 60], true) || strpos($lower, 'ssl') !== false || strpos($lower, 'certificate') !== false) {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('%s’s security certificate wasn’t accepted. Check the URL.', 'gpt3-ai-content-generator'), $where);
        } elseif (in_array($curl, [6, 7], true) || strpos($lower, 'resolve host') !== false || strpos($lower, 'failed to connect') !== false || strpos($lower, 'connection refused') !== false) {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('%s couldn’t be reached. Check the URL.', 'gpt3-ai-content-generator'), $where);
        } else {
            /* translators: %s: the endpoint's host. */
            $reason = sprintf(__('It wasn’t sent to %s. The details say why.', 'gpt3-ai-content-generator'), $where);
        }

        $at = sanitize_text_field((string) (($job['displayed_at'] ?? '') ?: ($job['created_at'] ?? '')));
        $timestamp = $at !== '' ? (int) strtotime($at . ' UTC') : 0;
        $details = trim(implode(' · ', array_filter([$raw, $at !== '' ? $at . ' UTC' : ''])));
        $event_name = sanitize_text_field((string) ($job['event_name'] ?? ''));

        return [
            'uuid' => sanitize_text_field((string) ($job['job_uuid'] ?? '')),
            'reason' => $reason,
            'details' => $details,
            'when' => self::ago($timestamp),
            'event' => (string) (self::event_labels()[$event_name] ?? $event_name),
        ];
    }
}
