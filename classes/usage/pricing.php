<?php

namespace WPAICG\Core\TokenManager\Pricing;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- This resolver only queries plugin-owned pricing tables and scalar values are normalized before each prepared call.

class AIPKit_Price_Resolver
{
    private $table_name;

    public function __construct(?string $table_name = null)
    {
        global $wpdb;
        $this->table_name = $table_name ?: $wpdb->prefix . 'aipkit_pricing_rules';
    }

    /**
     * @param array<string, mixed> $usage_context
     * @return array<string, mixed>|null
     */
    public function resolve_rule(string $module, array $usage_context = []): ?array
    {
        global $wpdb;

        if (!$this->table_exists()) {
            return null;
        }

        $module = sanitize_key($module);
        $provider = sanitize_text_field((string) ($usage_context['provider'] ?? ''));
        $model = sanitize_text_field((string) ($usage_context['model'] ?? ''));
        $operation = sanitize_text_field((string) ($usage_context['pricing_operation'] ?? ($usage_context['operation'] ?? '')));

        if ($module === '' || $provider === '' || $model === '' || $operation === '') {
            return null;
        }

        $scope_type = $this->normalize_scope_type((string) ($usage_context['pricing_scope_type'] ?? ''));
        $scope_id_raw = $usage_context['pricing_scope_id'] ?? null;
        $scope_id = is_numeric($scope_id_raw) ? absint($scope_id_raw) : null;

        $candidate_scopes = [];
        if ($scope_type !== '' && $scope_type !== 'global' && $scope_type !== 'module' && $scope_id !== null) {
            $candidate_scopes[] = [
                'scope_type' => $scope_type,
                'scope_id' => $scope_id,
            ];
        }

        $candidate_scopes[] = [
            'scope_type' => 'module',
            'scope_id' => 0,
        ];
        $candidate_scopes[] = [
            'scope_type' => 'global',
            'scope_id' => 0,
        ];

        foreach ($candidate_scopes as $candidate_scope) {
            if ($this->uses_unscoped_scope_id($candidate_scope['scope_type'], $candidate_scope['scope_id'])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime rule lookup from a custom table.
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$this->table_name} WHERE enabled = 1 AND scope_type = %s AND (scope_id = 0 OR scope_id IS NULL) AND module = %s AND provider = %s AND model = %s AND operation = %s ORDER BY updated_at DESC, id DESC LIMIT 1",
                        $candidate_scope['scope_type'],
                        $module,
                        $provider,
                        $model,
                        $operation
                    ),
                    ARRAY_A
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime rule lookup from a custom table.
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$this->table_name} WHERE enabled = 1 AND scope_type = %s AND scope_id = %d AND module = %s AND provider = %s AND model = %s AND operation = %s ORDER BY id DESC LIMIT 1",
                        $candidate_scope['scope_type'],
                        $candidate_scope['scope_id'],
                        $module,
                        $provider,
                        $model,
                        $operation
                    ),
                    ARRAY_A
                );
            }

            if (is_array($row) && !empty($row)) {
                return $this->normalize_rule_row($row);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list_rules(array $filters = []): array
    {
        global $wpdb;

        if (!$this->table_exists()) {
            return [];
        }

        $conditions = ['1=1'];
        $values = [];

        if (!empty($filters['module'])) {
            $conditions[] = 'module = %s';
            $values[] = sanitize_key((string) $filters['module']);
        }
        if (!empty($filters['scope_type'])) {
            $conditions[] = 'scope_type = %s';
            $values[] = $this->normalize_scope_type((string) $filters['scope_type']);
        }
        if (array_key_exists('scope_id', $filters)) {
            if ($filters['scope_id'] === null || $filters['scope_id'] === '') {
                $conditions[] = '(scope_id = 0 OR scope_id IS NULL)';
            } elseif (is_numeric($filters['scope_id'])) {
                $conditions[] = 'scope_id = %d';
                $values[] = absint($filters['scope_id']);
            }
        }
        if (!empty($filters['provider'])) {
            $conditions[] = 'provider = %s';
            $values[] = sanitize_text_field((string) $filters['provider']);
        }
        if (!empty($filters['model'])) {
            $conditions[] = 'model = %s';
            $values[] = sanitize_text_field((string) $filters['model']);
        }
        if (!empty($filters['operation'])) {
            $conditions[] = 'operation = %s';
            $values[] = sanitize_text_field((string) $filters['operation']);
        }
        if (array_key_exists('enabled', $filters)) {
            $conditions[] = 'enabled = %d';
            $values[] = empty($filters['enabled']) ? 0 : 1;
        }

        $sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' AND ', $conditions) . ' ORDER BY updated_at DESC, id DESC';
        if (!empty($values)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin/runtime reads from a custom table.
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic table and WHERE clause assembly is prepared at runtime.
                $wpdb->prepare($sql, $values),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query contains no placeholders when no filters are active.
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }
        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'normalize_rule_row'], $rows);
    }

    /**
     * @param array<string, mixed> $rule
     * @return int|WP_Error
     */
    public function save_rule(array $rule)
    {
        global $wpdb;

        if (!$this->table_exists()) {
            return new WP_Error('aipkit_pricing_rules_missing', __('Pricing rules table is not available.', 'gpt3-ai-content-generator'));
        }

        $scope_type = $this->normalize_scope_type((string) ($rule['scope_type'] ?? 'global'));
        if (in_array($scope_type, ['global', 'module'], true)) {
            $scope_id = 0;
        } elseif (isset($rule['scope_id']) && $rule['scope_id'] !== '') {
            $scope_id = absint($rule['scope_id']);
        } else {
            $scope_id = null;
        }

        $data = [
            'scope_type' => $scope_type,
            'scope_id' => $scope_id,
            'module' => sanitize_key((string) ($rule['module'] ?? '')),
            'provider' => sanitize_text_field((string) ($rule['provider'] ?? '')),
            'model' => sanitize_text_field((string) ($rule['model'] ?? '')),
            'operation' => sanitize_text_field((string) ($rule['operation'] ?? '')),
            'billing_method' => sanitize_key((string) ($rule['billing_method'] ?? '')),
            'input_rate' => $this->normalize_decimal_or_null($rule['input_rate'] ?? null),
            'output_rate' => $this->normalize_decimal_or_null($rule['output_rate'] ?? null),
            'unit_rate' => $this->normalize_decimal_or_null($rule['unit_rate'] ?? null),
            'enabled' => empty($rule['enabled']) ? 0 : 1,
        ];

        if ($data['module'] === '' || $data['provider'] === '' || $data['model'] === '' || $data['operation'] === '' || $data['billing_method'] === '') {
            return new WP_Error('aipkit_invalid_pricing_rule', __('Pricing rule is missing required fields.', 'gpt3-ai-content-generator'));
        }

        if (!in_array($scope_type, ['global', 'module'], true) && $scope_id === null) {
            return new WP_Error('aipkit_invalid_pricing_scope', __('Pricing rule is missing a scope target.', 'gpt3-ai-content-generator'));
        }

        $formats = ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d'];
        $rule_id = isset($rule['id']) ? absint($rule['id']) : 0;

        if ($rule_id <= 0) {
            $existing_rule_id = $this->find_existing_rule_id(
                $data['scope_type'],
                $data['scope_id'],
                $data['module'],
                $data['provider'],
                $data['model'],
                $data['operation']
            );
            if ($existing_rule_id > 0) {
                $rule_id = $existing_rule_id;
            }
        }

        if ($rule_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation against a plugin-owned pricing table.
            $updated = $wpdb->update(
                $this->table_name,
                $data,
                ['id' => $rule_id],
                $formats,
                ['%d']
            );

            if ($updated === false) {
                return new WP_Error('aipkit_pricing_rule_update_failed', __('Failed to update pricing rule.', 'gpt3-ai-content-generator'));
            }

            return $rule_id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation against a plugin-owned pricing table.
        $inserted = $wpdb->insert($this->table_name, $data, $formats);
        if ($inserted === false) {
            return new WP_Error('aipkit_pricing_rule_insert_failed', __('Failed to insert pricing rule.', 'gpt3-ai-content-generator'));
        }

        return (int) $wpdb->insert_id;
    }

    public function delete_rule(int $rule_id): bool
    {
        global $wpdb;

        if ($rule_id <= 0 || !$this->table_exists()) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation against a plugin-owned pricing table.
        $deleted = $wpdb->delete($this->table_name, ['id' => $rule_id], ['%d']);

        return $deleted !== false;
    }

    public function normalize_scope_type(string $scope_type): string
    {
        $scope_type = sanitize_key($scope_type);
        $aliases = [
            'bot' => 'chatbot',
            'form' => 'ai_form',
        ];

        if (isset($aliases[$scope_type])) {
            return $aliases[$scope_type];
        }

        if ($scope_type === '') {
            return 'global';
        }

        return $scope_type;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize_rule_row(array $row): array
    {
        $row['id'] = isset($row['id']) ? (int) $row['id'] : 0;
        $row['scope_id'] = isset($row['scope_id']) && $row['scope_id'] !== null ? (int) $row['scope_id'] : null;
        if (in_array((string) ($row['scope_type'] ?? ''), ['global', 'module'], true) && ((int) ($row['scope_id'] ?? 0)) === 0) {
            $row['scope_id'] = null;
        }
        $row['enabled'] = !empty($row['enabled']) ? 1 : 0;
        $row['input_rate'] = $this->normalize_decimal_or_null($row['input_rate'] ?? null);
        $row['output_rate'] = $this->normalize_decimal_or_null($row['output_rate'] ?? null);
        $row['unit_rate'] = $this->normalize_decimal_or_null($row['unit_rate'] ?? null);

        return $row;
    }

    /**
     * @param mixed $value
     */
    private function normalize_decimal_or_null($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function table_exists(): bool
    {
        global $wpdb;

        static $cache = [];
        if (isset($cache[$this->table_name])) {
            return $cache[$this->table_name];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time table existence check per request.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name)) === $this->table_name;
        $cache[$this->table_name] = $exists;

        return $exists;
    }

    private function find_existing_rule_id(
        string $scope_type,
        ?int $scope_id,
        string $module,
        string $provider,
        string $model,
        string $operation
    ): int {
        global $wpdb;

        if ($this->uses_unscoped_scope_id($scope_type, $scope_id)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin lookup against a custom table.
            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE scope_type = %s AND (scope_id = 0 OR scope_id IS NULL) AND module = %s AND provider = %s AND model = %s AND operation = %s LIMIT 1",
                    $scope_type,
                    $module,
                    $provider,
                    $model,
                    $operation
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin lookup against a custom table.
            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE scope_type = %s AND scope_id = %d AND module = %s AND provider = %s AND model = %s AND operation = %s LIMIT 1",
                    $scope_type,
                    $scope_id,
                    $module,
                    $provider,
                    $model,
                    $operation
                )
            );
        }

        return is_numeric($existing_id) ? (int) $existing_id : 0;
    }

    private function uses_unscoped_scope_id(string $scope_type, ?int $scope_id): bool
    {
        return in_array($scope_type, ['global', 'module'], true)
            && ($scope_id === null || $scope_id === 0);
    }
}

// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

class AIPKit_Usage_Normalizer
{
    /**
     * @param array<string, mixed> $usage_context
     * @return array<string, mixed>
     */
    public function normalize(array $usage_context = [], int $fallback_units = 0): array
    {
        $usage_data = isset($usage_context['usage_data']) && is_array($usage_context['usage_data'])
            ? $usage_context['usage_data']
            : [];

        // Duration has its own meter; it must never fall back to one credit per token.
        if (isset($usage_data['duration_seconds'])) {
            $seconds = $this->extract_first_int($usage_data, ['duration_seconds']);
            $previous = min($seconds, $this->extract_first_int($usage_data, ['previous_duration_seconds']));
            return [
                'input_units' => 0, 'output_units' => 0, 'total_units' => $seconds - $previous,
                'unit_count' => $seconds - $previous, 'usage_unit' => 'seconds',
                'duration_seconds' => $seconds, 'previous_duration_seconds' => $previous,
                'fallback_units' => 0, 'raw_usage_data' => $usage_data,
            ];
        }

        $input_units = $this->extract_first_int($usage_data, ['input_tokens', 'prompt_tokens', 'input_units']);
        $output_units = $this->extract_first_int($usage_data, ['output_tokens', 'completion_tokens', 'output_units']);
        $total_units = $this->extract_first_int($usage_data, ['total_tokens', 'total_units', 'billable_units']);
        $unit_count = $this->extract_first_int($usage_data, ['unit_count', 'image_count', 'video_count', 'items']);
        $tool_usage = $this->extract_tool_usage($usage_data);
        $tool_units = $this->sum_numeric_tool_usage($tool_usage);

        if ($total_units <= 0 && ($input_units > 0 || $output_units > 0)) {
            $total_units = $input_units + $output_units;
        }

        if ($total_units <= 0) {
            $total_units = max(0, $fallback_units);
        }

        if ($unit_count <= 0 && $input_units === 0 && $output_units === 0) {
            $unit_count = $total_units;
        }

        return [
            'input_units' => $input_units,
            'output_units' => $output_units,
            'total_units' => $total_units,
            'unit_count' => $unit_count,
            'tool_units' => $tool_units,
            'tool_usage' => $tool_usage,
            'fallback_units' => max(0, $fallback_units),
            'raw_usage_data' => $usage_data,
        ];
    }

    /**
     * @param array<string, mixed> $usage_data
     * @param array<int, string> $keys
     */
    private function extract_first_int(array $usage_data, array $keys): int
    {
        foreach ($keys as $key) {
            if (isset($usage_data[$key]) && is_numeric($usage_data[$key])) {
                return max(0, (int) $usage_data[$key]);
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $usage_data
     * @return array<string, mixed>
     */
    private function extract_tool_usage(array $usage_data): array
    {
        if (isset($usage_data['server_side_tool_usage']) && is_array($usage_data['server_side_tool_usage'])) {
            return $usage_data['server_side_tool_usage'];
        }

        if (isset($usage_data['tool_usage']) && is_array($usage_data['tool_usage'])) {
            return $usage_data['tool_usage'];
        }

        if (
            isset($usage_data['provider_raw']) &&
            is_array($usage_data['provider_raw']) &&
            isset($usage_data['provider_raw']['server_side_tool_usage']) &&
            is_array($usage_data['provider_raw']['server_side_tool_usage'])
        ) {
            return $usage_data['provider_raw']['server_side_tool_usage'];
        }

        return [];
    }

    /**
     * @param mixed $tool_usage
     */
    private function sum_numeric_tool_usage($tool_usage): int
    {
        if (is_numeric($tool_usage)) {
            return max(0, (int) $tool_usage);
        }
        if (!is_array($tool_usage)) {
            return 0;
        }

        $total = 0;
        foreach ($tool_usage as $value) {
            $total += $this->sum_numeric_tool_usage($value);
        }

        return $total;
    }
}

class AIPKit_Charge_Calculator
{
    /**
     * @param array<string, mixed>|null $resolved_rule
     * @param array<string, mixed> $normalized_usage
     * @return array<string, mixed>
     */
    public function calculate(?array $resolved_rule, array $normalized_usage, int $fallback_units = 0): array
    {
        $fallback_units = max(0, $fallback_units);

        if (!is_array($resolved_rule) || empty($resolved_rule)) {
            $duration = ($normalized_usage['usage_unit'] ?? '') === 'seconds';
            $required_units = $duration ? 0 : max(0, (int) ($normalized_usage['total_units'] ?? $fallback_units));

            return [
                'resolved_rule' => null,
                'billing_method' => $duration ? 'unpriced' : 'legacy_fallback',
                'required_units' => $required_units,
                'billed_credits' => $required_units,
                'raw_charge' => (float) $required_units,
                'used_legacy_fallback' => !$duration,
                'normalized_usage' => $normalized_usage,
            ];
        }

        $billing_method = (string) ($resolved_rule['billing_method'] ?? 'legacy_fallback');
        $input_units = max(0, (int) ($normalized_usage['input_units'] ?? 0));
        $output_units = max(0, (int) ($normalized_usage['output_units'] ?? 0));
        $total_units = max(0, (int) ($normalized_usage['total_units'] ?? $fallback_units));
        $unit_count = max(0, (int) ($normalized_usage['unit_count'] ?? 0));

        $raw_charge = 0.0;

        switch ($billing_method) {
            case 'per_minute':
                $rate = max(0, (float) ($resolved_rule['unit_rate'] ?? 0));
                $current_charge = max(0, (int) ($normalized_usage['duration_seconds'] ?? 0)) * $rate / 60;
                $previous_charge = max(0, (int) ($normalized_usage['previous_duration_seconds'] ?? 0)) * $rate / 60;
                $raw_charge = max(0, $current_charge - $previous_charge);
                // Round the session total, not each heartbeat. Subtract already billed credits.
                $duration_credits = max(0, (int) ceil(round($current_charge, 9)) - (int) ceil(round($previous_charge, 9)));
                break;

            case 'per_1k_tokens':
                if ($input_units === 0 && $output_units === 0 && $total_units > 0) {
                    $input_units = $total_units;
                }

                $input_rate = is_numeric($resolved_rule['input_rate'] ?? null) ? (float) $resolved_rule['input_rate'] : 0.0;
                $output_rate = is_numeric($resolved_rule['output_rate'] ?? null) ? (float) $resolved_rule['output_rate'] : 0.0;
                $raw_charge = ($input_units / 1000) * $input_rate;
                $raw_charge += ($output_units / 1000) * $output_rate;
                break;

            case 'flat':
                $raw_charge = is_numeric($resolved_rule['unit_rate'] ?? null) ? (float) $resolved_rule['unit_rate'] : (float) $total_units;
                break;

            case 'per_image':
            case 'per_video':
                $count = $unit_count > 0 ? $unit_count : max(1, $total_units);
                $unit_rate = is_numeric($resolved_rule['unit_rate'] ?? null) ? (float) $resolved_rule['unit_rate'] : 0.0;
                $raw_charge = $count * $unit_rate;
                break;

            default:
                $raw_charge = (float) $total_units;
                $billing_method = 'legacy_fallback';
                break;
        }

        $required_units = isset($duration_credits) ? $duration_credits : max(0, (int) ceil($raw_charge));

        return [
            'resolved_rule' => $resolved_rule,
            'billing_method' => $billing_method,
            'required_units' => $required_units,
            'billed_credits' => $required_units,
            'raw_charge' => $raw_charge,
            'used_legacy_fallback' => $billing_method === 'legacy_fallback',
            'normalized_usage' => $normalized_usage,
        ];
    }
}
