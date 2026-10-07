<?php

namespace WPAICG\KnowledgeBase;

if (!defined('ABSPATH')) {
    exit;
}

/** File upload batches are sources; their embedding chunks are implementation details. */
class FileSourceGroups
{
    public static function is_file_chunk(array $log): bool
    {
        return !empty($log['batch_id'])
            && in_array($log['provider'] ?? '', ['Local', 'Pinecone', 'Qdrant', 'Chroma'], true)
            && strpos((string) ($log['message'] ?? ''), 'File chunk embedded (chunk ') === 0;
    }

    /** SQL expression shared by source counts and pagination. Never groups by filename alone. */
    public static function key_sql(): string
    {
        return "CASE WHEN COALESCE(batch_id, '') <> '' AND provider IN ('Local','Pinecone','Qdrant','Chroma') AND LEFT(message, 26) = 'File chunk embedded (chunk ' THEN CONCAT('file:', HEX(provider), ':', HEX(vector_store_id), ':', HEX(post_title), ':', HEX(batch_id)) ELSE CONCAT('row:', id) END";
    }

    /** All members of an upload, scoped by provider, store, batch and filename. */
    public static function chunks(array $log): array
    {
        if (!self::is_file_chunk($log)) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'aipkit_vector_data_source';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only the WordPress table prefix and fixed table name form the identifier; all filter values are prepared.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, file_id, message, indexed_content FROM {$table} WHERE provider = %s AND vector_store_id = %s AND batch_id = %s AND post_title = %s AND LEFT(message, 26) = 'File chunk embedded (chunk '", $log['provider'], $log['vector_store_id'], $log['batch_id'], $log['post_title']), ARRAY_A);
        foreach ($rows as &$row) {
            preg_match('/chunk (\d+)\/(\d+)/', $row['message'], $match);
            $row['number'] = (int) ($match[1] ?? 0);
        }
        unset($row);
        usort($rows, static function ($a, $b) { return $a['number'] <=> $b['number']; });
        return $rows;
    }
}
