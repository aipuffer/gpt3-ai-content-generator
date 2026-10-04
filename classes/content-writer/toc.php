<?php


namespace WPAICG\Utils;

use DOMDocument;
use DOMXPath;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AIPKit_TOC_Generator
 *
 * Generates a Table of Contents from HTML content by finding headings.
 */
class AIPKit_TOC_Generator
{
    /**
     * Generates a ToC and modifies the content with heading IDs.
     *
     * @param string $html_content The HTML content to parse.
     * @param array  $options Optional generation flags.
     * @return array An associative array containing ['toc' => string, 'content' => string].
     */
    public static function generate(string $html_content, array $options = []): array
    {
        if (empty(trim($html_content))) {
            return ['toc' => '', 'content' => $html_content];
        }

        $previous_errors = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            // Wrap content to ensure proper parsing of HTML fragments
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);
        }

        $xpath = new DOMXPath($dom);
        $headings = $xpath->query('//h2 | //h3 | //h4 | //h5 | //h6');
        $toc_items = [];
        $existing_ids = [];

        if ($headings->length === 0) {
            return ['toc' => '', 'content' => $html_content];
        }

        foreach ($headings as $heading) {
            $text = trim($heading->textContent);
            if (empty($text)) {
                continue;
            }

            // Generate a unique, URL-friendly ID
            $slug = sanitize_title($text);
            $original_slug = $slug;
            $counter = 1;
            while (in_array($slug, $existing_ids)) {
                $slug = $original_slug . '-' . $counter;
                $counter++;
            }
            $existing_ids[] = $slug;

            // Set the ID on the heading element
            $heading->setAttribute('id', $slug);

            // Add item to ToC list
            $toc_items[] = [
                'level' => (int) substr($heading->nodeName, 1),
                'text'  => $text,
                'slug'  => $slug,
            ];
        }

        // Build the ToC HTML.
        $toc_classes = ['aipkit-toc'];
        if (!empty($options['rank_math_compatible'])) {
            $toc_classes[] = 'wp-block-rank-math-toc-block';
        }
        $toc_html = '<nav class="' . esc_attr(implode(' ', $toc_classes)) . '" aria-label="' . esc_attr__('Table of contents', 'gpt3-ai-content-generator') . '"><ul class="aipkit-toc-list">';
        foreach ($toc_items as $item) {
            // Simple list for now, could be hierarchical later if needed
            $toc_html .= sprintf(
                '<li class="aipkit-toc-item aipkit-toc-level-%d"><a href="#%s">%s</a></li>',
                esc_attr($item['level']),
                esc_attr($item['slug']),
                esc_html($item['text'])
            );
        }
        $toc_html .= '</ul></nav>';

        // Get the modified HTML content
        $modified_content = $dom->saveHTML();

        return [
            'toc' => $toc_html,
            'content' => $modified_content,
        ];
    }
}
