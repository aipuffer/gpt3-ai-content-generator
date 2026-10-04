<?php

namespace WPAICG\Core;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/** Shared multipart uploads: native file transfer when available, WordPress HTTP otherwise. */
class AIPKit_HTTP_Multipart
{
    /**
     * @param array $files Field names mapped to path, filename and type descriptors.
     * @return array|WP_Error WordPress HTTP response shape.
     */
    public static function request(string $url, array $fields, array $files, array $args, bool $bypass_approval)
    {
        $args = array_replace(['method' => 'POST', 'timeout' => 120, 'redirection' => 0, 'sslverify' => true], $args);
        $headers = $args['headers'] ?? [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), ['content-type', 'content-length'], true)) {
                unset($headers[$name]);
            }
        }
        $size = 0;
        foreach ($files as $name => &$file) {
            if (!is_readable($file['path']) || !is_file($file['path'])) {
                return new WP_Error('aipkit_upload_unreadable', __('The upload file could not be read. Please upload it again.', 'gpt3-ai-content-generator'));
            }
            $size += (int) filesize($file['path']);
            $file['filename'] = self::quote_value(basename($file['filename']));
            $file['type'] = preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~', $file['type']) ? $file['type'] : 'application/octet-stream';
        }
        unset($file);

        if (AIPKit_HTTP_Request::has_curl() && class_exists('CURLFile')) {
            $body = $fields;
            foreach ($files as $name => $file) {
                $body[$name] = new \CURLFile($file['path'], $file['type'], $file['filename']);
            }
            $curl_headers = [];
            foreach ($headers as $name => $value) {
                $curl_headers[] = $name . ': ' . $value;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Native multipart keeps large files out of PHP memory.
            $handle = curl_init();
            if ($handle === false) {
                return new WP_Error('aipkit_upload_transport', __('Could not initialize the upload connection.', 'gpt3-ai-content-generator'));
            }
            try {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Native multipart keeps large files out of PHP memory.
                curl_setopt_array($handle, [
                    CURLOPT_URL => $url,
                    CURLOPT_CUSTOMREQUEST => $args['method'],
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $curl_headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => $args['connect_timeout'] ?? 15,
                    CURLOPT_TIMEOUT => $args['timeout'],
                    CURLOPT_USERAGENT => $args['user-agent'] ?? 'AI Puffer',
                    CURLOPT_SSL_VERIFYPEER => $args['sslverify'],
                    CURLOPT_SSL_VERIFYHOST => $args['sslverify'] ? 2 : 0,
                ]);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- Native multipart file transport.
                $response = curl_exec($handle);
                if ($response === false) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- Report a transport failure to the caller.
                    return new WP_Error('aipkit_upload_http_error', curl_error($handle));
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Normalize the transport response for shared API handling.
                return ['body' => $response, 'response' => ['code' => curl_getinfo($handle, CURLINFO_HTTP_CODE)], 'headers' => []];
            } finally {
                $handle = null;
            }
        }

        // Socket requests need a string body. Reserve space for encoding and transport copies.
        $memory_limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        $field_bytes = array_sum(array_map('strlen', array_map('strval', $fields)));
        if ($memory_limit > 0 && ($size + $field_bytes + 8192) * 3 > $memory_limit - memory_get_usage(true) - 16 * 1024 * 1024) {
            return new WP_Error('aipkit_upload_memory_limit', __('This file is too large for the available upload memory. Use a smaller file or ask your host to enable PHP cURL.', 'gpt3-ai-content-generator'), ['status' => 413]);
        }
        $boundary = 'aipkit-' . wp_generate_uuid4();
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . self::quote_value($name) . "\"\r\n\r\n" . $value . "\r\n";
        }
        foreach ($files as $name => $file) {
            $contents = file_get_contents($file['path']);
            if ($contents === false) {
                return new WP_Error('aipkit_upload_unreadable', __('The upload file could not be read. Please upload it again.', 'gpt3-ai-content-generator'));
            }
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . self::quote_value($name) . '"; filename="' . $file['filename'] . "\"\r\nContent-Type: " . $file['type'] . "\r\n\r\n" . $contents . "\r\n";
            unset($contents);
        }
        $body .= '--' . $boundary . "--\r\n";
        $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        $args['headers'] = $headers;
        $args['body'] = $body;
        $args['data_format'] = 'body';
        return AIPKit_HTTP_Request::request($url, $args, $bypass_approval);
    }

    private static function quote_value(string $value): string
    {
        return str_replace(["\r", "\n", '"', '\\'], '_', $value);
    }
}
