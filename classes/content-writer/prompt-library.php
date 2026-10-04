<?php

namespace WPAICG\ContentWriter;

use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Centralized manager for global prompt library CRUD operations.
 * Stores custom prompts in a single option and merges them with built-in prompts on demand.
 */
class AIPKit_Content_Writer_Prompt_Library_Manager
{
    public const OPTION_NAME = 'aipkit_global_prompt_library_v1';

    private const MAX_LABEL_LENGTH = 180;
    private const MAX_PROMPT_LENGTH = 20000;

    /**
     * @var array<int, string>|null
     */
    private ?array $supported_prompt_types = null;

    /**
     * Returns prompt entries grouped by type.
     *
     * @param string|null $prompt_type Restrict to one prompt type when provided.
     * @param bool $include_builtin Include built-in prompt entries.
     * @param bool $include_custom Include custom prompt entries.
     * @return array<string, mixed>|WP_Error
     */
    public function get_library_entries(?string $prompt_type = null, bool $include_builtin = true, bool $include_custom = true)
    {
        $types_result = $this->resolve_requested_types($prompt_type);
        if (is_wp_error($types_result)) {
            return $types_result;
        }

        $types = $types_result;
        $library = [];
        $counts = [];

        foreach ($types as $type) {
            $library[$type] = [];
            $counts[$type] = [
                'builtin' => 0,
                'custom'  => 0,
                'total'   => 0,
            ];
        }

        if ($include_builtin) {
            $builtins = $this->get_builtin_library();
            foreach ($types as $type) {
                $options = $builtins[$type] ?? [];
                if (!is_array($options)) {
                    continue;
                }

                foreach ($options as $index => $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $label = $this->sanitize_label((string) ($option['label'] ?? ''));
                    $prompt = $this->sanitize_prompt((string) ($option['prompt'] ?? ''));
                    if ($label === '' || $prompt === '') {
                        continue;
                    }

                    $library[$type][] = [
                        'id'          => 'builtin_' . $type . '_' . ((int) $index + 1),
                        'type'        => $type,
                        'label'       => $label,
                        'prompt'      => $prompt,
                        'source'      => 'builtin',
                        'is_builtin'  => true,
                        'is_editable' => false,
                    ];
                    $counts[$type]['builtin']++;
                }
            }
        }

        if ($include_custom) {
            $custom_items = $this->get_custom_prompts_grouped();
            foreach ($types as $type) {
                $items = $custom_items[$type] ?? [];
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $library[$type][] = $this->prepare_custom_item_for_api($item);
                    $counts[$type]['custom']++;
                }
            }
        }

        foreach ($types as $type) {
            $counts[$type]['total'] = $counts[$type]['builtin'] + $counts[$type]['custom'];
        }

        return [
            'types'   => $types,
            'library' => $library,
            'counts'  => $counts,
        ];
    }

    /**
     * Creates a new custom prompt entry.
     *
     * @param string $prompt_type Prompt type key.
     * @param string $label Prompt label.
     * @param string $prompt Prompt text content.
     * @param int $user_id Current user id.
     * @return array<string, mixed>|WP_Error
     */
    public function create_custom_prompt(string $prompt_type, string $label, string $prompt, int $user_id = 0)
    {
        $normalized_type = $this->normalize_prompt_type($prompt_type);
        if ($normalized_type === '') {
            return new WP_Error(
                'invalid_prompt_type',
                __('Invalid prompt type.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $normalized_label = $this->sanitize_label($label);
        if ($normalized_label === '') {
            return new WP_Error(
                'missing_prompt_label',
                __('Prompt name is required.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $normalized_prompt = $this->sanitize_prompt($prompt);
        if ($normalized_prompt === '') {
            return new WP_Error(
                'missing_prompt_text',
                __('Prompt text is required.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $stored_items = $this->get_stored_items();
        $prompt_id = $this->generate_prompt_id($stored_items);
        $timestamp = (int) current_time('timestamp', true);

        $item = [
            'id'         => $prompt_id,
            'type'       => $normalized_type,
            'label'      => $normalized_label,
            'prompt'     => $normalized_prompt,
            'created_by' => max(0, $user_id),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        $stored_items[$prompt_id] = $item;
        if (!$this->persist_stored_items($stored_items)) {
            return new WP_Error(
                'save_failed',
                __('Could not save the prompt preset.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            );
        }

        return $this->prepare_custom_item_for_api($item);
    }

    /**
     * Updates an existing custom prompt entry.
     *
     * @param string $prompt_id Prompt id.
     * @param array<string, mixed> $updates Fields to update.
     * @return array<string, mixed>|WP_Error
     */
    public function update_custom_prompt(string $prompt_id, array $updates)
    {
        $normalized_id = sanitize_key($prompt_id);
        if ($normalized_id === '') {
            return new WP_Error(
                'invalid_prompt_id',
                __('Prompt preset id is invalid.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $stored_items = $this->get_stored_items();
        if (!isset($stored_items[$normalized_id])) {
            return new WP_Error(
                'prompt_not_found',
                __('Prompt preset was not found.', 'gpt3-ai-content-generator'),
                ['status' => 404]
            );
        }

        $item = $stored_items[$normalized_id];
        $has_changes = false;

        if (array_key_exists('type', $updates)) {
            $type = $this->normalize_prompt_type((string) $updates['type']);
            if ($type === '') {
                return new WP_Error(
                    'invalid_prompt_type',
                    __('Invalid prompt type.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            if ($item['type'] !== $type) {
                $item['type'] = $type;
                $has_changes = true;
            }
        }

        if (array_key_exists('label', $updates)) {
            $label = $this->sanitize_label((string) $updates['label']);
            if ($label === '') {
                return new WP_Error(
                    'missing_prompt_label',
                    __('Prompt name is required.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            if ($item['label'] !== $label) {
                $item['label'] = $label;
                $has_changes = true;
            }
        }

        if (array_key_exists('prompt', $updates)) {
            $prompt = $this->sanitize_prompt((string) $updates['prompt']);
            if ($prompt === '') {
                return new WP_Error(
                    'missing_prompt_text',
                    __('Prompt text is required.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            if ($item['prompt'] !== $prompt) {
                $item['prompt'] = $prompt;
                $has_changes = true;
            }
        }

        if (!$has_changes) {
            return new WP_Error(
                'no_changes',
                __('No prompt changes were detected.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $now = (int) current_time('timestamp', true);
        $item['updated_at'] = max(((int) $item['updated_at']) + 1, $now);
        $stored_items[$normalized_id] = $item;

        if (!$this->persist_stored_items($stored_items)) {
            return new WP_Error(
                'save_failed',
                __('Could not update the prompt preset.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            );
        }

        return $this->prepare_custom_item_for_api($item);
    }

    /**
     * Deletes a custom prompt entry.
     *
     * @param string $prompt_id Prompt id.
     * @return bool|WP_Error
     */
    public function delete_custom_prompt(string $prompt_id)
    {
        $normalized_id = sanitize_key($prompt_id);
        if ($normalized_id === '') {
            return new WP_Error(
                'invalid_prompt_id',
                __('Prompt preset id is invalid.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $stored_items = $this->get_stored_items();
        if (!isset($stored_items[$normalized_id])) {
            return new WP_Error(
                'prompt_not_found',
                __('Prompt preset was not found.', 'gpt3-ai-content-generator'),
                ['status' => 404]
            );
        }

        unset($stored_items[$normalized_id]);
        if (!$this->persist_stored_items($stored_items)) {
            return new WP_Error(
                'delete_failed',
                __('Could not delete the prompt preset.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function get_supported_prompt_types(): array
    {
        if ($this->supported_prompt_types !== null) {
            return $this->supported_prompt_types;
        }

        $builtins = $this->get_builtin_library();
        $types = array_keys($builtins);
        $types = array_map('sanitize_key', $types);
        $types = array_values(array_filter($types, static fn($type): bool => $type !== ''));

        $this->supported_prompt_types = $types;
        return $this->supported_prompt_types;
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private function get_builtin_library(): array
    {
        if (!class_exists(AIPKit_Content_Writer_Prompts::class)) {
            return [];
        }

        $library = AIPKit_Content_Writer_Prompts::get_prompt_library();
        return is_array($library) ? $library : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_stored_items(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return [];
        }

        $raw_items = isset($stored['items']) && is_array($stored['items']) ? $stored['items'] : $stored;
        $normalized = [];

        foreach ($raw_items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_id = is_string($key) ? $key : (string) ($item['id'] ?? '');
            $normalized_item = $this->normalize_stored_item($item_id, $item);
            if ($normalized_item === null) {
                continue;
            }

            $normalized[$normalized_item['id']] = $normalized_item;
        }

        return $normalized;
    }

    /**
     * @param string $item_id
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function normalize_stored_item(string $item_id, array $item): ?array
    {
        $normalized_id = sanitize_key((string) ($item['id'] ?? $item_id));
        $normalized_type = $this->normalize_prompt_type((string) ($item['type'] ?? ''));
        $label = $this->sanitize_label((string) ($item['label'] ?? ''));
        $prompt = $this->sanitize_prompt((string) ($item['prompt'] ?? ''));

        if ($normalized_id === '' || $normalized_type === '' || $label === '' || $prompt === '') {
            return null;
        }

        $created_at = isset($item['created_at']) ? (int) $item['created_at'] : 0;
        $updated_at = isset($item['updated_at']) ? (int) $item['updated_at'] : 0;
        if ($created_at <= 0) {
            $created_at = (int) current_time('timestamp', true);
        }
        if ($updated_at <= 0) {
            $updated_at = $created_at;
        }
        if ($updated_at < $created_at) {
            $updated_at = $created_at;
        }

        return [
            'id'         => $normalized_id,
            'type'       => $normalized_type,
            'label'      => $label,
            'prompt'     => $prompt,
            'created_by' => isset($item['created_by']) ? max(0, (int) $item['created_by']) : 0,
            'created_at' => $created_at,
            'updated_at' => $updated_at,
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function get_custom_prompts_grouped(): array
    {
        $items = $this->get_stored_items();
        $grouped = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type === '') {
                continue;
            }
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $item;
        }

        foreach ($grouped as $type => $type_items) {
            usort($type_items, static function (array $a, array $b): int {
                $updated_comparison = ((int) ($b['updated_at'] ?? 0)) <=> ((int) ($a['updated_at'] ?? 0));
                if ($updated_comparison !== 0) {
                    return $updated_comparison;
                }
                return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });
            $grouped[$type] = $type_items;
        }

        return $grouped;
    }

    /**
     * @param array<string, array<string, mixed>> $existing_items
     */
    private function generate_prompt_id(array $existing_items): string
    {
        do {
            $id = 'prompt_' . sanitize_key(wp_generate_password(12, false, false));
        } while ($id === 'prompt_' || isset($existing_items[$id]));

        return $id;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     */
    private function persist_stored_items(array $items): bool
    {
        $payload = [
            'version' => 1,
            'items'   => $items,
        ];

        $updated = update_option(self::OPTION_NAME, $payload, 'no');
        if ($updated) {
            return true;
        }

        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return false;
        }
        $stored_items = isset($stored['items']) && is_array($stored['items']) ? $stored['items'] : $stored;
        return $stored_items == $items;
    }

    /**
     * @param string|null $prompt_type
     * @return array<int, string>|WP_Error
     */
    private function resolve_requested_types(?string $prompt_type = null)
    {
        $supported_types = $this->get_supported_prompt_types();
        if (empty($supported_types)) {
            return [];
        }

        if ($prompt_type === null || $prompt_type === '') {
            return $supported_types;
        }

        $normalized_type = $this->normalize_prompt_type($prompt_type);
        if ($normalized_type === '') {
            return new WP_Error(
                'invalid_prompt_type',
                __('Invalid prompt type.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        return [$normalized_type];
    }

    private function normalize_prompt_type(string $prompt_type): string
    {
        $candidate = sanitize_key($prompt_type);
        if ($candidate === '') {
            return '';
        }

        $supported_types = $this->get_supported_prompt_types();
        return in_array($candidate, $supported_types, true) ? $candidate : '';
    }

    private function sanitize_label(string $label): string
    {
        $normalized = trim(wp_strip_all_tags($label));
        if ($normalized === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($normalized, 0, self::MAX_LABEL_LENGTH);
        }

        return substr($normalized, 0, self::MAX_LABEL_LENGTH);
    }

    private function sanitize_prompt(string $prompt): string
    {
        $normalized = AIPKit_Prompt_Sanitizer::sanitize($prompt);
        if ($normalized === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($normalized, 0, self::MAX_PROMPT_LENGTH);
        }

        return substr($normalized, 0, self::MAX_PROMPT_LENGTH);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function prepare_custom_item_for_api(array $item): array
    {
        return [
            'id'         => (string) ($item['id'] ?? ''),
            'type'       => (string) ($item['type'] ?? ''),
            'label'      => (string) ($item['label'] ?? ''),
            'prompt'     => (string) ($item['prompt'] ?? ''),
            'source'     => 'custom',
            'is_builtin' => false,
            'is_editable' => true,
            'created_by' => isset($item['created_by']) ? (int) $item['created_by'] : 0,
            'created_at' => isset($item['created_at']) ? (int) $item['created_at'] : 0,
            'updated_at' => isset($item['updated_at']) ? (int) $item['updated_at'] : 0,
        ];
    }
}
