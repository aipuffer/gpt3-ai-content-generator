<?php

namespace WPAICG\Vector\Providers;

use WPAICG\Vector\AIPKit_Vector_Base_Provider_Strategy;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Built-in knowledge base (shown to users as "Local"): chunks and their vectors live in this site's database and
 * are searched in PHP. Vectors are stored normalized, so similarity is a dot product (cosine).
 *
 * Accepts the same shapes as the Qdrant strategy: points `{id, vector|values, payload|metadata}`,
 * query `{vector}`, and Qdrant-style filters (`must` / `must_not` with `{key, match: {value}}`).
 */
class AIPKit_Vector_Local_Strategy extends AIPKit_Vector_Base_Provider_Strategy
{
    public const PROVIDER = 'Local';
    /** Scan batch size: bounds memory to ~2 MB of vectors at 512 dimensions. */
    private const BATCH = 1000;

    public static function max_chunks_per_store(): int { return max(1, (int) apply_filters('aipkit_local_store_max_chunks', 10000)); }
    public static function max_chunks_per_site(): int { return max(1, (int) apply_filters('aipkit_local_site_max_chunks', 25000)); }

    private function stores_table(): string { global $wpdb; return $wpdb->prefix . 'aipkit_vector_stores'; }
    private function vectors_table(): string { global $wpdb; return $wpdb->prefix . 'aipkit_vectors'; }

    public function connect(array $config) { return true; }

    /** Store ids are derived from the name (lowercase letters, digits, - and _), like a collection name. */
    /** Every local knowledge base on this site, newest first, for pickers in other modules. */
    public static function stores(): array
    {
        return (new self())->list_indexes();
    }

    public static function store_id(string $name): string
    {
        $id = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower(remove_accents($name))), '-');
        return substr($id !== '' ? $id : 'store', 0, 64);
    }

    public function get_store(string $store_id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . esc_sql($this->stores_table()) . " WHERE id = %s", $store_id), ARRAY_A);
        return $row ?: null;
    }

    public function create_index_if_not_exists(string $index_name, array $index_config)
    {
        global $wpdb;
        $id = self::store_id($index_name);
        $existing = $this->get_store($id);
        if ($existing) { return $this->format_store($existing); }
        $now = current_time('mysql', 1);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
        $inserted = $wpdb->insert($this->stores_table(), [
            'id' => $id,
            'name' => sanitize_text_field($index_name),
            'embedding_provider' => isset($index_config['embedding_provider']) ? sanitize_key((string) $index_config['embedding_provider']) : null,
            'embedding_model' => isset($index_config['embedding_model']) ? sanitize_text_field((string) $index_config['embedding_model']) : null,
            'dimensions' => absint($index_config['dimension'] ?? ($index_config['dimensions'] ?? 0)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$inserted) { return new WP_Error('local_store_create_failed', __('The knowledge base could not be created.', 'gpt3-ai-content-generator')); }
        return $this->format_store($this->get_store($id));
    }

    private function format_store(array $row): array
    {
        return [
            'id' => $row['id'], 'name' => $row['name'], 'provider' => self::PROVIDER,
            'embedding_provider' => $row['embedding_provider'], 'embedding_model' => $row['embedding_model'],
            'dimensions' => (int) $row['dimensions'], 'chunk_count' => (int) $row['chunk_count'], 'bytes' => (int) $row['bytes'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    /** Unit-length float32, little-endian. Returns null for an empty or zero vector. */
    public static function pack_vector(array $values): ?string
    {
        $sum = 0.0;
        foreach ($values as $value) { if (!is_numeric($value)) { return null; } $sum += $value * $value; }
        if ($values === [] || $sum <= 0.0) { return null; }
        $norm = sqrt($sum);
        return pack('g*', ...array_map(static fn($v) => (float) $v / $norm, array_values($values)));
    }

    public function upsert_vectors(string $index_name, array $vectors)
    {
        global $wpdb;
        $store = $this->get_store(self::store_id($index_name));
        if (!$store) { return new WP_Error('local_store_not_found', __('Knowledge base not found.', 'gpt3-ai-content-generator')); }
        $points = isset($vectors['points']) && is_array($vectors['points']) ? $vectors['points'] : $vectors;
        $dimensions = (int) $store['dimensions'];
        $rows = [];
        foreach ($points as $point) {
            if (!is_array($point)) { continue; }
            $values = $point['vector'] ?? ($point['values'] ?? null);
            $metadata = $point['payload'] ?? ($point['metadata'] ?? []);
            $metadata = is_array($metadata) ? $metadata : [];
            $content = (string) ($metadata['original_content'] ?? ($metadata['text_content'] ?? ''));
            if (!is_array($values) || $content === '') { return new WP_Error('local_invalid_point', __('Each chunk needs text and a vector.', 'gpt3-ai-content-generator')); }
            if ($dimensions === 0) { $dimensions = count($values); }
            if (count($values) !== $dimensions) {
                /* translators: 1: expected dimension, 2: actual dimension. */
                return new WP_Error('local_vector_dimension_mismatch', sprintf(__('Vector size does not match this knowledge base (expected %1$d, got %2$d). Re-index it with its original embedding model.', 'gpt3-ai-content-generator'), $dimensions, count($values)));
            }
            $packed = self::pack_vector($values);
            if ($packed === null) { return new WP_Error('local_invalid_point', __('Each chunk needs text and a vector.', 'gpt3-ai-content-generator')); }
            $vector_id = substr(sanitize_text_field((string) ($point['id'] ?? wp_generate_uuid4())), 0, 64);
            unset($metadata['original_content'], $metadata['text_content']);
            $rows[] = [
                'vector_id' => $vector_id !== '' ? $vector_id : wp_generate_uuid4(),
                'parent_id' => isset($metadata['parent_vector_id']) ? substr((string) $metadata['parent_vector_id'], 0, 191) : null,
                'post_id' => isset($metadata['post_id']) && is_numeric($metadata['post_id']) ? absint($metadata['post_id']) : null,
                'chunk_index' => absint($metadata['chunk_index'] ?? 0),
                'content' => $content,
                'metadata' => wp_json_encode($metadata),
                'embedding' => $packed,
            ];
        }
        if (!$rows) { return ['upserted' => 0]; }

        // Lock and replace in one transaction, after all incoming vectors passed validation.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('local_vector_transaction_failed', __('Could not begin the knowledge update.', 'gpt3-ai-content-generator'));
        }
        $committed = false;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . esc_sql($this->stores_table()) . " WHERE id = %s FOR UPDATE", $store['id']), ARRAY_A);
            if (!$locked || (int) $locked['dimensions'] !== (int) $store['dimensions']) {
                return new WP_Error('local_store_changed', __('The knowledge base changed during indexing. Please try again.', 'gpt3-ai-content-generator'));
            }
            $store = $locked;
            $parent_id = isset($vectors['replace_parent_id']) ? (string) $vectors['replace_parent_id'] : '';
            if ($parent_id !== '') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM " . esc_sql($this->vectors_table()) . " WHERE store_id = %s AND (parent_id = %s OR vector_id = %s)", $store['id'], $parent_id, $parent_id));
                if ($deleted === false || !$this->refresh_counts($store['id'])) {
                    return new WP_Error('local_vector_replace_failed', __('Could not replace the knowledge chunks. Existing content has been retained.', 'gpt3-ai-content-generator'));
                }
                $store = $this->get_store($store['id']);
            }
            // Limits count chunks that would be new; replacing existing chunks never hits them.
            $ids = array_column($rows, 'vector_id');
            $placeholders = implode(',', array_fill(0, count($ids), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One placeholder is built per bound value; table names are escaped and all values are prepared.
            $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($this->vectors_table()) . " WHERE store_id = %s AND vector_id IN ($placeholders)", $store['id'], ...$ids));
            $new = count($rows) - $existing;
            if ((int) $store['chunk_count'] + $new > self::max_chunks_per_store()) {
                /* translators: %s: chunk limit. */
                return new WP_Error('local_store_full', sprintf(__('This knowledge base is full (%s chunks). Remove content or use Pinecone or Qdrant for larger knowledge bases.', 'gpt3-ai-content-generator'), number_format_i18n(self::max_chunks_per_store())));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            $site_total = (int) $wpdb->get_var("SELECT COALESCE(SUM(chunk_count), 0) FROM " . esc_sql($this->stores_table()) . "");
            if ($site_total + $new > self::max_chunks_per_site()) {
                /* translators: %s: chunk limit. */
                return new WP_Error('local_site_full', sprintf(__('This site has reached the local knowledge base limit (%s chunks). Remove content or use Pinecone or Qdrant.', 'gpt3-ai-content-generator'), number_format_i18n(self::max_chunks_per_site())));
            }

            $now = current_time('mysql', 1);
            foreach ($rows as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                if ($wpdb->replace($this->vectors_table(), $row + ['store_id' => $store['id'], 'created_at' => $now], ['%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']) === false) {
                    return new WP_Error('local_vector_write_failed', __('Could not save the knowledge chunks. Existing content has been retained.', 'gpt3-ai-content-generator'));
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            if (!$this->refresh_counts($store['id'], $dimensions) || $wpdb->query('COMMIT') === false) {
                return new WP_Error('local_vector_write_failed', __('Could not save the knowledge chunks. Existing content has been retained.', 'gpt3-ai-content-generator'));
            }
            $committed = true;
            return ['upserted' => count($rows)];
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            if (!$committed) { $wpdb->query('ROLLBACK'); }
        }
    }

    private function refresh_counts(string $store_id, ?int $dimensions = null): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
        $stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS chunks, COALESCE(SUM(LENGTH(content) + LENGTH(embedding) + LENGTH(metadata)), 0) AS bytes FROM " . esc_sql($this->vectors_table()) . " WHERE store_id = %s", $store_id), ARRAY_A);
        if (!is_array($stats)) { return false; }
        $update = ['chunk_count' => (int) $stats['chunks'], 'bytes' => (int) $stats['bytes'], 'updated_at' => current_time('mysql', 1)];
        if ($dimensions !== null) { $update['dimensions'] = $dimensions; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
        return $wpdb->update($this->stores_table(), $update, ['id' => $store_id]) !== false;
    }

    /** Qdrant-style filter, or a flat `[key => value]` map. Only equality on metadata keys is supported. */
    private static function matches(array $metadata, array $filter): bool
    {
        $conditions = static function ($list) {
            $out = [];
            foreach (is_array($list) ? $list : [] as $condition) {
                if (is_array($condition) && isset($condition['key'])) { $out[(string) $condition['key']] = $condition['match']['value'] ?? null; }
            }
            return $out;
        };
        $must = isset($filter['must']) || isset($filter['must_not']) ? $conditions($filter['must'] ?? []) : $filter;
        foreach ($must as $key => $value) { if ((string) ($metadata[$key] ?? '') !== (string) $value) { return false; } }
        foreach ($conditions($filter['must_not'] ?? []) as $key => $value) { if ((string) ($metadata[$key] ?? '') === (string) $value) { return false; } }
        return true;
    }

    public function query_vectors(string $index_name, array $query_vector, int $top_k, array $filter = [])
    {
        global $wpdb;
        $store = $this->get_store(self::store_id($index_name));
        if (!$store) { return new WP_Error('local_store_not_found', __('Knowledge base not found.', 'gpt3-ai-content-generator')); }
        $values = $query_vector['vector'] ?? ($query_vector['query'] ?? $query_vector);
        if (!is_array($values) || count($values) !== (int) $store['dimensions']) {
            return new WP_Error('local_invalid_query_vector', __('The question was embedded with a different model than this knowledge base.', 'gpt3-ai-content-generator'));
        }
        $packed = self::pack_vector($values);
        if ($packed === null) { return []; }
        $query = array_values(unpack('g*', $packed));
        $dimensions = count($query);
        $top_k = max(1, min(50, $top_k));
        $threshold = isset($query_vector['score_threshold']) ? (float) $query_vector['score_threshold'] : null;
        $post_filter = is_array($filter['must'] ?? null) ? null : (isset($filter['post_id']) ? absint($filter['post_id']) : null);

        $best = []; // [row id => score], at most $top_k entries.
        $floor = -INF;
        $last = 0;
        do {
            if ($post_filter) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, metadata, embedding FROM " . esc_sql($this->vectors_table()) . " WHERE store_id = %s AND id > %d AND post_id = %d ORDER BY id LIMIT %d",
                    $store['id'], $last, $post_filter, self::BATCH
                ), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, metadata, embedding FROM " . esc_sql($this->vectors_table()) . " WHERE store_id = %s AND id > %d ORDER BY id LIMIT %d",
                    $store['id'], $last, self::BATCH
                ), ARRAY_A);
            }
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if ($filter && !$post_filter && !self::matches((array) json_decode((string) $row['metadata'], true), $filter)) { continue; }
                $vector = unpack('g*', $row['embedding']);
                if (count($vector) !== $dimensions) { continue; }
                $score = 0.0;
                for ($i = 0; $i < $dimensions; $i++) { $score += $vector[$i + 1] * $query[$i]; }
                if ($threshold !== null && $score < $threshold) { continue; }
                if (count($best) < $top_k) { $best[$last] = $score; $floor = min($best); }
                elseif ($score > $floor) { unset($best[array_search($floor, $best, true)]); $best[$last] = $score; $floor = min($best); }
            }
        } while (count($rows) === self::BATCH);

        if (!$best) { return []; }
        arsort($best);
        $ids = array_keys($best);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One placeholder is built per bound value; table names are escaped and all values are prepared.
        $details = $wpdb->get_results($wpdb->prepare("SELECT id, vector_id, content, metadata FROM " . esc_sql($this->vectors_table()) . " WHERE id IN ($placeholders)", ...$ids), OBJECT_K);
        $results = [];
        foreach ($best as $id => $score) {
            if (!isset($details[$id])) { continue; }
            $metadata = (array) json_decode((string) $details[$id]->metadata, true);
            $metadata['original_content'] = $details[$id]->content;
            $results[] = ['id' => $details[$id]->vector_id, 'score' => round($score, 6), 'metadata' => $metadata, 'collection' => $store['id']];
        }
        return $results;
    }

    /** Vector ids, or a filter (`['filter' => …]`, `post_id`, `parent_vector_id`). */
    public function delete_vectors(string $index_name, array $vector_ids)
    {
        global $wpdb;
        $store = $this->get_store(self::store_id($index_name));
        if (!$store) { return new WP_Error('local_store_not_found', __('Knowledge base not found.', 'gpt3-ai-content-generator')); }
        return $this->delete_transaction($store['id'], function () use ($wpdb, $store, $vector_ids): bool {
            $filter = $vector_ids['filter'] ?? null;
            if (is_array($filter)) {
                foreach (array_merge((array) ($filter['must'] ?? []), (array) ($filter['should'] ?? [])) as $condition) {
                    $key = $condition['key'] ?? ''; $value = $condition['match']['value'] ?? null;
                    $deleted = 0;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                    if ($key === 'post_id') { $deleted = $wpdb->delete($this->vectors_table(), ['store_id' => $store['id'], 'post_id' => absint($value)]); }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                    elseif ($key === 'parent_vector_id') { $deleted = $wpdb->delete($this->vectors_table(), ['store_id' => $store['id'], 'parent_id' => (string) $value]); }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                    elseif ($key === 'vector_id') { $deleted = $wpdb->delete($this->vectors_table(), ['store_id' => $store['id'], 'vector_id' => (string) $value]); }
                    elseif (is_string($key) && $key !== '') {
                        $like = '%' . $wpdb->esc_like('"' . $key . '":' . wp_json_encode($value)) . '%';
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM " . esc_sql($this->vectors_table()) . " WHERE store_id = %s AND metadata LIKE %s", $store['id'], $like));
                    }
                    if ($deleted === false) { return false; }
                }
            } else {
                foreach (array_filter(array_map('strval', $vector_ids)) as $vector_id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                    if ($wpdb->delete($this->vectors_table(), ['store_id' => $store['id'], 'vector_id' => $vector_id]) === false
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                        || $wpdb->delete($this->vectors_table(), ['store_id' => $store['id'], 'parent_id' => $vector_id]) === false) { return false; }
                }
            }
            return $this->refresh_counts($store['id']);
        });
    }

    public function delete_index(string $index_name)
    {
        global $wpdb;
        $id = self::store_id($index_name);
        return $this->delete_transaction($id, function () use ($wpdb, $id): bool {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            return $wpdb->delete($this->vectors_table(), ['store_id' => $id]) !== false
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                && $wpdb->delete($this->stores_table(), ['id' => $id]) !== false
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
                && $wpdb->delete($wpdb->prefix . 'aipkit_vector_data_source', ['provider' => self::PROVIDER, 'vector_store_id' => $id]) !== false;
        });
    }

    /** Keep the store, chunks and source records intact if any deletion fails. */
    private function delete_transaction(string $id, callable $delete)
    {
        global $wpdb;
        $error = new WP_Error('local_vector_delete_failed', __('Could not remove the knowledge data. Please try again.', 'gpt3-ai-content-generator'), ['status' => 500]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
        if ($wpdb->query('START TRANSACTION') === false) { return $error; }
        $committed = false;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            $wpdb->get_row($wpdb->prepare("SELECT id FROM " . esc_sql($this->stores_table()) . " WHERE id = %s FOR UPDATE", $id));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            if ($wpdb->last_error !== '' || !$delete() || $wpdb->query('COMMIT') === false) { return $error; }
            $committed = true;
            return true;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
            if (!$committed) { $wpdb->query('ROLLBACK'); }
        }
    }

    public function list_indexes(?int $limit = 20, ?string $order = 'desc', ?string $after = null, ?string $before = null)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned knowledge tables and transaction state; writes update store counts atomically.
        $rows = $wpdb->get_results("SELECT * FROM " . esc_sql($this->stores_table()) . " ORDER BY updated_at DESC", ARRAY_A);
        return array_map([$this, 'format_store'], $rows ?: []);
    }

    public function describe_index(string $index_name)
    {
        $store = $this->get_store(self::store_id($index_name));
        return $store ? $this->format_store($store) : new WP_Error('local_store_not_found', __('Knowledge base not found.', 'gpt3-ai-content-generator'));
    }

    public function upload_file_for_vector_store(string $file_path, string $original_filename, string $purpose = 'user_data')
    {
        return new WP_Error('not_applicable_local_file_upload', __('Files are extracted, chunked and embedded by the plugin, then added as chunks.', 'gpt3-ai-content-generator'));
    }
}
