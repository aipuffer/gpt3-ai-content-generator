<?php

namespace WPAICG\Core;

use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Canonical event definitions for the Universal Event Webhooks foundation.
 */
class AIPKit_Event_Registry
{
    public const SCHEMA_VERSION = '2026-05-21';

    /**
     * Returns the current v1 event definitions.
     *
     * @return array<string, array<string, string>>
     */
    public static function get_definitions(): array
    {
        return [
            'chatbot.session_started' => [
                'module' => 'chatbot',
                'category' => 'chatbot',
                'label' => 'Chat session started',
            ],
            'chatbot.user_message_submitted' => [
                'module' => 'chatbot',
                'category' => 'chatbot',
                'label' => 'Chat user message submitted',
            ],
            'chatbot.response_generated' => [
                'module' => 'chatbot',
                'category' => 'chatbot',
                'label' => 'Chat response generated',
            ],
            'chatbot.fb_submitted' => [
                'module' => 'chatbot',
                'category' => 'chatbot',
                'label' => 'Chat feedback submitted',
            ],
            'chatbot.form_submitted' => [
                'module' => 'chatbot',
                'category' => 'chatbot',
                'label' => 'Chatbot form submitted',
            ],
            'content.generated' => [
                'module' => 'content_writer',
                'category' => 'content',
                'label' => 'Content generated',
            ],
            'task.item_completed' => [
                'module' => 'automated_tasks',
                'category' => 'tasks',
                'label' => 'Task queue item completed',
            ],
            'form.submitted' => [
                'module' => 'ai_forms',
                'category' => 'forms',
                'label' => 'AI form submitted',
            ],
            'image.generated' => [
                'module' => 'image_generator',
                'category' => 'images',
                'label' => 'Image generated',
            ],
            'kb.source_indexed' => [
                'module' => 'knowledge_base',
                'category' => 'knowledge_base',
                'label' => 'KB source indexed',
            ],
        ];
    }

    /**
     * Returns whether the given event is registered.
     *
     * @param string $event_name
     * @return bool
     */
    public static function has_event(string $event_name): bool
    {
        $definitions = self::get_definitions();
        return isset($definitions[$event_name]);
    }

    /**
     * Returns a single event definition or null if unsupported.
     *
     * @param string $event_name
     * @return array<string, string>|null
     */
    public static function get_definition(string $event_name): ?array
    {
        $definitions = self::get_definitions();
        return $definitions[$event_name] ?? null;
    }

    /**
     * Returns the current schema version string.
     *
     * @return string
     */
    public static function get_schema_version(): string
    {
        return self::SCHEMA_VERSION;
    }
}

/**
 * Builds canonical payload envelopes for Universal Event Webhooks.
 */
class AIPKit_Event_Payload_Builder
{
    /**
     * Builds the event envelope.
     *
     * @param string $event_name
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function build_envelope(string $event_name, array $payload = [], array $context = []): array
    {
        $definition = AIPKit_Event_Registry::get_definition($event_name) ?? [];
        $module = sanitize_key((string) ($context['module'] ?? ($definition['module'] ?? 'system')));
        $resource = self::normalize_resource($context['resource'] ?? []);
        $meta = self::normalize_meta($context['meta'] ?? []);
        $payload = self::normalize_payload($payload);
        $occurred_at = gmdate('c');
        $event_id = wp_generate_uuid4();

        $envelope = [
            'id' => $event_id,
            'type' => $event_name,
            'schema_version' => AIPKit_Event_Registry::get_schema_version(),
            'occurred_at' => $occurred_at,
            'idempotency_key' => self::build_idempotency_key($event_name, $module, $resource, $context),
            'site' => [
                'url' => site_url(),
                'name' => get_bloginfo('name'),
            ],
            'plugin' => [
                'slug' => 'gpt3-ai-content-generator',
                'version' => defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0',
            ],
            'source' => [
                'module' => $module,
                'origin' => sanitize_key((string) ($context['origin'] ?? 'internal')),
            ],
            'data' => $payload,
        ];

        if (!empty($resource)) {
            $envelope['resource'] = $resource;
        }
        if (!empty($meta)) {
            $envelope['meta'] = $meta;
        }

        return $envelope;
    }

    /**
     * Normalizes the resource section.
     *
     * @param mixed $resource
     * @return array<string, mixed>
     */
    private static function normalize_resource($resource): array
    {
        if (!is_array($resource)) {
            return [];
        }

        $normalized = [];
        if (isset($resource['type']) && $resource['type'] !== '') {
            $normalized['type'] = sanitize_key((string) $resource['type']);
        }
        if (isset($resource['id']) && is_scalar($resource['id']) && (string) $resource['id'] !== '') {
            $normalized['id'] = is_numeric($resource['id'])
                ? (int) $resource['id']
                : sanitize_text_field((string) $resource['id']);
        }
        if (isset($resource['label']) && is_scalar($resource['label']) && (string) $resource['label'] !== '') {
            $normalized['label'] = sanitize_text_field((string) $resource['label']);
        }

        return $normalized;
    }

    /**
     * Normalizes envelope meta.
     *
     * @param mixed $meta
     * @return array<string, mixed>
     */
    private static function normalize_meta($meta): array
    {
        if (!is_array($meta)) {
            return [];
        }

        $normalized = [];
        foreach ($meta as $key => $value) {
            $sanitized_key = sanitize_key((string) $key);
            if ($sanitized_key === '') {
                continue;
            }

            if (is_scalar($value)) {
                $normalized[$sanitized_key] = is_numeric($value)
                    ? 0 + $value
                    : sanitize_text_field((string) $value);
            } elseif (is_array($value)) {
                $normalized[$sanitized_key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Normalizes known payload sections without changing event-specific shapes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalize_payload(array $payload): array
    {
        if (isset($payload['ai']) && is_array($payload['ai'])) {
            $payload['ai'] = self::normalize_ai_payload($payload['ai']);
        }

        return $payload;
    }

    /**
     * Normalizes common AI metadata embedded in event data.
     *
     * @param array<string, mixed> $ai
     * @return array<string, mixed>
     */
    private static function normalize_ai_payload(array $ai): array
    {
        if (isset($ai['provider']) && is_scalar($ai['provider'])) {
            $provider = sanitize_text_field((string) $ai['provider']);
            if (class_exists('\WPAICG\AIPKit_Providers')) {
                $provider = \WPAICG\AIPKit_Providers::normalize_provider_label($provider);
            } elseif (strtolower($provider) === 'xai') {
                $provider = 'xAI';
            }
            $ai['provider'] = $provider;
        }

        if (isset($ai['model']) && is_scalar($ai['model'])) {
            $ai['model'] = sanitize_text_field((string) $ai['model']);
        }

        return $ai;
    }

    /**
     * Builds an idempotency key from stable event context.
     *
     * @param string $event_name
     * @param string $module
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $context
     * @return string
     */
    private static function build_idempotency_key(string $event_name, string $module, array $resource, array $context): string
    {
        if (!empty($context['idempotency_key']) && is_scalar($context['idempotency_key'])) {
            return sanitize_text_field((string) $context['idempotency_key']);
        }

        $resource_type = isset($resource['type']) ? (string) $resource['type'] : '';
        $resource_id = isset($resource['id']) ? (string) $resource['id'] : '';
        $seed = implode('|', [
            $event_name,
            $module,
            $resource_type,
            $resource_id,
            (string) ($context['origin'] ?? 'internal'),
        ]);

        return sha1($seed);
    }
}

/**
 * Shared dispatcher for Universal Event Webhooks.
 *
 * This foundation validates supported event names, builds the canonical
 * payload envelope, persists a durable queue job, resolves currently
 * subscribed endpoints, performs delivery with retries/signing, and
 * exposes WordPress hooks for module integrations.
 */
class AIPKit_Event_Dispatcher
{
    /**
     * Emits an event and returns the prepared dispatch result.
     *
     * @param string $event_name
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>|WP_Error
     */
    public static function emit(string $event_name, array $payload = [], array $context = [])
    {
        $normalized_event_name = sanitize_text_field(trim($event_name));
        if ($normalized_event_name === '') {
            return new WP_Error('aipkit_event_name_missing', __('Event name is required.', 'gpt3-ai-content-generator'));
        }

        if (!AIPKit_Event_Registry::has_event($normalized_event_name)) {
            return new WP_Error(
                'aipkit_event_not_supported',
                sprintf(
                    /* translators: %s: event name */
                    __('Unsupported event: %s', 'gpt3-ai-content-generator'),
                    $normalized_event_name
                )
            );
        }

        $envelope = AIPKit_Event_Payload_Builder::build_envelope($normalized_event_name, $payload, $context);
        $envelope = apply_filters('aipkit_event_webhooks_envelope', $envelope, $normalized_event_name, $payload, $context);

        $targets = AIPKit_Event_Webhooks_Settings::get_active_endpoints_for_event($normalized_event_name);
        $targets = apply_filters('aipkit_event_webhooks_targets', $targets, $normalized_event_name, $envelope, $context);
        if (!is_array($targets)) {
            $targets = [];
        }

        $async_delivery_requested = (bool) apply_filters(
            'aipkit_event_delivery_queue_async_enabled',
            false,
            $normalized_event_name,
            $envelope,
            $context,
            $targets
        );

        $queue_context = $context;
        $queue_context['aipkit_queue'] = [
            'targets' => $targets,
        ];

        $queue_job = null;
        $queue_error = null;
        $queue_enabled = (bool) apply_filters(
            'aipkit_event_delivery_queue_enabled',
            $async_delivery_requested,
            $normalized_event_name,
            $envelope,
            $context,
            $targets
        );

        if ($queue_enabled && class_exists(AIPKit_Event_Queue_Store::class)) {
            $queue_result = AIPKit_Event_Queue_Store::enqueue_event(
                $normalized_event_name,
                $envelope,
                $queue_context,
                [
                    'target_count' => count($targets),
                ]
            );

            if (is_wp_error($queue_result)) {
                $queue_error = [
                    'code' => $queue_result->get_error_code(),
                    'message' => $queue_result->get_error_message(),
                    'details' => is_array($queue_result->get_error_data()) ? $queue_result->get_error_data() : [],
                ];

                do_action(
                    'aipkit_event_webhooks_enqueue_failed',
                    $queue_result,
                    $normalized_event_name,
                    $envelope,
                    $context,
                    $targets
                );
            } else {
                $queue_job = $queue_result;

                do_action(
                    'aipkit_event_webhooks_enqueued',
                    $queue_job,
                    $normalized_event_name,
                    $envelope,
                    $context,
                    $targets
                );
            }
        }

        $result = [
            'event_name' => $normalized_event_name,
            'envelope' => $envelope,
            'targets' => $targets,
            'target_count' => count($targets),
            'queue_job' => $queue_job,
            'queue_error' => $queue_error,
            'queued_count' => is_array($queue_job) ? 1 : 0,
            'deliveries' => [],
            'delivered_count' => 0,
            'failed_count' => 0,
            'async_delivery_requested' => $async_delivery_requested,
            'async_delivery_enabled' => false,
            'sync_delivery_enabled' => true,
        ];

        $async_delivery_available = $async_delivery_requested && is_array($queue_job);
        $sync_delivery_enabled = (bool) apply_filters(
            'aipkit_event_webhooks_sync_delivery_enabled',
            !$async_delivery_available,
            $normalized_event_name,
            $envelope,
            $context,
            $targets,
            $queue_job
        );
        $async_delivery_enabled = $async_delivery_available && !$sync_delivery_enabled;

        if ($async_delivery_enabled && is_array($queue_job) && class_exists(AIPKit_Event_Queue_Store::class)) {
            $pending_updated = AIPKit_Event_Queue_Store::update_job_state((string) ($queue_job['job_uuid'] ?? ''), [
                'status' => 'pending',
                'locked_at' => null,
                'processed_at' => null,
                'available_at' => gmdate('Y-m-d H:i:s'),
                'last_error_message' => '',
            ]);

            if ($pending_updated) {
                $queue_job['status'] = 'pending';
                $queue_job['available_at'] = gmdate('Y-m-d H:i:s');
                $result['queue_job'] = $queue_job;
            } else {
                $async_delivery_enabled = false;
                $sync_delivery_enabled = true;
            }
        }

        $result['async_delivery_enabled'] = $async_delivery_enabled;
        $result['sync_delivery_enabled'] = $sync_delivery_enabled;

        if ($sync_delivery_enabled && !empty($targets)) {
            $deliveries = AIPKit_Event_Delivery_Manager::deliver($normalized_event_name, $envelope, $targets);
            $result['deliveries'] = $deliveries;
            $result['delivered_count'] = count(array_filter($deliveries, static function ($delivery): bool {
                return is_array($delivery) && (($delivery['status'] ?? '') === 'delivered');
            }));
            $result['failed_count'] = count(array_filter($deliveries, static function ($delivery): bool {
                return is_array($delivery) && (($delivery['status'] ?? '') === 'failed');
            }));
        }

        if (is_array($queue_job) && class_exists(AIPKit_Event_Queue_Worker::class)) {
            AIPKit_Event_Queue_Worker::maybe_trigger_async_worker($queue_job, $result, $context);
        }

        if (!$async_delivery_enabled) {
            do_action('aipkit_event_webhooks_emitted', $result, $context);
            do_action('aipkit_event_webhooks_emitted_' . self::get_hook_suffix($normalized_event_name), $result, $context);
        }

        return $result;
    }

    /**
     * Normalizes an event name into a hook-safe suffix.
     *
     * @param string $event_name
     * @return string
     */
    private static function get_hook_suffix(string $event_name): string
    {
        return str_replace(['.', '-'], '_', sanitize_key($event_name));
    }
}
