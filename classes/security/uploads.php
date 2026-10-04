<?php

namespace WPAICG\Includes;

use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Shared upload validation, allowed MIME types and effective size limits.
 */
class AIPKit_Upload_Utils
{
    /**
     * Gets a concise summary of the most relevant upload limit.
     *
     * @return array ['limit_bytes' => int_bytes, 'formatted' => string_human_readable]
     */
    public static function get_effective_upload_limit_summary(): array
    {
        $wp_max_bytes = wp_max_upload_size();
        $upload_max_filesize_bytes = wp_convert_hr_to_bytes(ini_get('upload_max_filesize'));
        $post_max_size_bytes = wp_convert_hr_to_bytes(ini_get('post_max_size'));

        // The smallest of these is usually the effective limit for a single file.
        $effective_limit_bytes = min($wp_max_bytes, $upload_max_filesize_bytes, $post_max_size_bytes);

        return [
            'limit_bytes' => $effective_limit_bytes,
            'formatted'   => size_format($effective_limit_bytes),
        ];
    }

    /**
     * Get allowed MIME types for vector store file uploads.
     *
     * @return array List of allowed MIME types.
     */
    public static function get_vector_upload_allowed_mime_types(): array
    {
        return apply_filters('aipkit_vector_upload_allowed_mime_types', [
            'text/plain',       // .txt
            'text/csv',         // .csv
            'application/json', // .json
            'text/json',        // .json (legacy)
            'application/pdf',  // .pdf
            'application/x-pdf',
            'text/html',        // .html
            'application/xhtml+xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
            'application/vnd.ms-excel',          // Seen on Windows / older browsers for .csv
            'application/csv',                   // Some environments
            'text/comma-separated-values',       // Alternative label
        ]);
    }

    /**
     * Get allowed MIME types for Content Writer CSV file uploads.
     *
     * @return array List of allowed MIME types.
     */
    public static function get_content_writer_allowed_mime_types(): array
    {
        // NOTE: Different browsers / OS combos report CSV MIME types inconsistently.
        // Common real-world values: text/csv, text/plain, application/vnd.ms-excel (legacy), application/csv,
        // text/comma-separated-values. We include several to reduce false negatives that caused 400 errors.
        return apply_filters('aipkit_content_writer_allowed_mime_types', [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',          // Seen on Windows / older browsers for .csv
            'application/csv',                   // Some environments
            'text/comma-separated-values',       // Alternative label
        ]);
    }

    /**
     * Validates an uploaded file against allowed MIME types and max size for vector stores.
     *
     * @param array $file_data $_FILES entry for the uploaded file.
     * @param array|null $allowed_mime_types Optional override for allowed MIME types.
     * @param int|null $max_size_bytes Optional override for max file size.
     * @return true|WP_Error True if valid, WP_Error otherwise.
     */
    public static function validate_upload_file(array $file_data, ?array $allowed_mime_types = null, ?int $max_size_bytes = null)
    {
        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', __('Error during file upload: Code ', 'gpt3-ai-content-generator') . $file_data['error']);
        }

        // Ensure the uploaded file is a genuine HTTP upload and readable
        $tmp_name = $file_data['tmp_name'] ?? '';
        if (empty($tmp_name) || !is_readable($tmp_name) || !function_exists('is_uploaded_file') || !is_uploaded_file($tmp_name)) {
            return new WP_Error('invalid_upload_source', __('Invalid uploaded file source.', 'gpt3-ai-content-generator'));
        }

        if ($allowed_mime_types === null) {
            $allowed_mime_types = self::get_vector_upload_allowed_mime_types();
        }

        // Precompute lowercase allowed types for comparisons
        $allowed_mime_types_lc = array_map('strtolower', $allowed_mime_types);

        // Additional hardening: verify extension map (extended with CSV types)
        $ext = strtolower(pathinfo($file_data['name'] ?? '', PATHINFO_EXTENSION));
        $allowed_exts_map = [
            'text/plain' => ['txt', 'text', 'log', 'md'],
            'application/pdf' => ['pdf'],
            'application/x-pdf' => ['pdf'],
            'text/html' => ['html', 'htm'],
            'application/xhtml+xml' => ['xhtml', 'html'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            // CSV-specific mappings (fix): allow common CSV MIME types to map to .csv
            'text/csv' => ['csv'],
            'application/csv' => ['csv'],
            'application/vnd.ms-excel' => ['csv'],
        ];
        $allowed_exts = [];
        foreach ($allowed_mime_types as $mt) {
            if (isset($allowed_exts_map[$mt])) {
                $allowed_exts = array_merge($allowed_exts, $allowed_exts_map[$mt]);
            }
        }

        // Prefer WordPress filetype check first and accept if it validates
        $accepted_by_wp_filetype = false;
        if (function_exists('wp_check_filetype_and_ext')) {
            $ft = wp_check_filetype_and_ext($tmp_name, $file_data['name']);
            $ft_type = isset($ft['type']) ? strtolower((string) $ft['type']) : '';
            $ft_ext  = isset($ft['ext']) ? strtolower((string) $ft['ext']) : '';

            // If WP detected type is in allowed list and ext is either allowed or unknown, accept
            if (!empty($ft_type) && in_array($ft_type, $allowed_mime_types_lc, true)) {
                if (empty($allowed_exts) || empty($ft_ext) || in_array($ft_ext, $allowed_exts, true)) {
                    $accepted_by_wp_filetype = true;
                }
            }
            // Or, if type is unknown but extension alone is allowed, accept as well (helps with text/csv on some hosts)
            if (!$accepted_by_wp_filetype && !empty($ft_ext) && in_array($ft_ext, $allowed_exts, true)) {
                $accepted_by_wp_filetype = true;
            }
        }

        // Determine content-derived MIME type as a fallback path
        $file_mime_type = '';
        if (function_exists('mime_content_type') && is_readable($tmp_name)) {
            $file_mime_type = mime_content_type($tmp_name);
        } elseif (isset($file_data['type'])) {
            $file_mime_type = $file_data['type']; // Fallback to browser-sent type
        }

        // If WP accepted the filetype, skip content-derived rejection.
        if (!$accepted_by_wp_filetype) {
            if (empty($file_mime_type) || !in_array(strtolower($file_mime_type), $allowed_mime_types_lc, true)) {
                /* translators: %1$s is the detected MIME type, %2$s is the allowed types */
                return new WP_Error('invalid_file_type_vector', sprintf(__('Invalid file type: %1$s. Allowed types: %2$s', 'gpt3-ai-content-generator'), esc_html($file_mime_type ?: 'Unknown'), esc_html(implode(', ', $allowed_mime_types))));
            }
            // Extension check (skip if WP already accepted by type/ext above).
            if (!empty($ext) && !empty($allowed_exts) && !in_array($ext, $allowed_exts, true)) {
                /* translators: %1$s: file extension, %2$s: list of allowed extensions */
                return new WP_Error('invalid_file_extension_vector', sprintf(__('Invalid file extension: .%1$s. Allowed extensions: %2$s', 'gpt3-ai-content-generator'), esc_html($ext), esc_html(implode(', ', array_unique($allowed_exts)))));
            }
            // Cross-check with WordPress core detection (non-fatal if unknown but mismatch may be rejected)
            if (function_exists('wp_check_filetype_and_ext')) {
                $ft = wp_check_filetype_and_ext($tmp_name, $file_data['name']);
                $ft_type = isset($ft['type']) ? strtolower((string) $ft['type']) : '';
                $ft_ext  = isset($ft['ext']) ? strtolower((string) $ft['ext']) : '';
                if (!empty($ft_type) && !in_array($ft_type, $allowed_mime_types_lc, true)) {
                    /* translators: %1$s: detected file type, %2$s: list of allowed MIME types */
                    return new WP_Error('invalid_file_type_vector_core', sprintf(__('Invalid file type (WP check): %1$s. Allowed types: %2$s', 'gpt3-ai-content-generator'), esc_html($ft_type), esc_html(implode(', ', $allowed_mime_types))));
                }
                if (!empty($ft_ext) && !empty($allowed_exts) && !in_array($ft_ext, $allowed_exts, true)) {
                    /* translators: %1$s: detected file extension, %2$s: list of allowed extensions */
                    return new WP_Error('invalid_file_extension_vector_core', sprintf(__('Invalid file extension (WP check): .%1$s. Allowed extensions: %2$s', 'gpt3-ai-content-generator'), esc_html($ft_ext), esc_html(implode(', ', array_unique($allowed_exts)))));
                }
                // If both type and extension are present and contradict the content-derived $file_mime_type, reject
                if (!empty($ft_type) && !empty($file_mime_type) && strtolower($ft_type) !== strtolower($file_mime_type)) {
                    /* translators: %1$s: detected MIME type from content, %2$s: MIME type from extension */
                    return new WP_Error('filetype_mismatch_vector', sprintf(__('File type mismatch between content and extension: %1$s vs %2$s', 'gpt3-ai-content-generator'), esc_html($file_mime_type), esc_html($ft_type)));
                }
            }
        }

        // Check file size
        if ($max_size_bytes === null) {
            $upload_limits = self::get_effective_upload_limit_summary();
            $max_size_bytes = $upload_limits['limit_bytes'];
        }

        // Reject empty files explicitly
        if ((int) ($file_data['size'] ?? 0) === 0) {
            return new WP_Error('file_empty', __('Uploaded file is empty.', 'gpt3-ai-content-generator'));
        }

        if ($file_data['size'] > $max_size_bytes) {
            /* translators: %1$s is the actual file size, %2$s is the maximum allowed size */
            return new WP_Error('file_too_large_vector', sprintf(__('File is too large (%1$s). Maximum allowed size is %2$s.', 'gpt3-ai-content-generator'),size_format($file_data['size']),size_format($max_size_bytes)));
        }

        return true;
    }
}
