<?php

namespace WPAICG\ContentWriter;

use DOMDocument;
use DOMElement;
use DOMNode;
use WP_Block_Type_Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts finished article HTML on the server, including during cron runs.
 * Unsupported markup stays in HTML blocks rather than losing content/styles.
 */
class AIPKit_Content_Writer_Block_Converter
{
    public static function normalize_format($format): string
    {
        return $format === 'gutenberg' ? 'gutenberg' : 'html';
    }

    public static function convert(string $content): string
    {
        $result = [];
        // Preserve existing blocks, and convert only the unstructured portions.
        foreach (parse_blocks($content) as $block) {
            if ($block['blockName'] !== null) {
                $result[] = serialize_block($block);
            } elseif (trim($block['innerHTML']) !== '') {
                $result[] = self::convert_html(wp_kses_post($block['innerHTML']));
            }
        }
        return implode("\n\n", $result);
    }

    private static function block(string $name, string $html, array $attributes = []): string
    {
        return get_comment_delimited_block_content('core/' . $name, $attributes, $html);
    }

    private static function convert_html(string $html): string
    {
        if (!class_exists(DOMDocument::class)) {
            return self::block('html', $html);
        }
        $previous_errors = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
            $body = $dom->getElementsByTagName('body')->item(0);
            if (!$loaded || !$body) {
                return self::block('html', $html);
            }
            return self::convert_children($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);
        }
    }

    private static function convert_children(DOMNode $parent): string
    {
        $blocks = [];
        $inline = '';
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE || ($node instanceof DOMElement && in_array($node->tagName, ['a', 'strong', 'em', 'b', 'i', 'u', 's', 'del', 'ins', 'code', 'br', 'span', 'mark', 'sub', 'sup', 'small', 'kbd', 'abbr'], true) && !$node->getElementsByTagName('img')->length)) {
                $inline .= self::html($node);
                continue;
            }
            if (trim($inline) !== '') {
                $blocks[] = self::block('paragraph', '<p>' . trim($inline) . '</p>');
            }
            $inline = '';
            if ($node instanceof DOMElement) {
                $blocks[] = self::convert_element($node);
            } elseif ($node->nodeType === XML_COMMENT_NODE) {
                $blocks[] = self::block('html', self::html($node));
            }
        }
        if (trim($inline) !== '') {
            $blocks[] = self::block('paragraph', '<p>' . trim($inline) . '</p>');
        }
        return implode("\n\n", array_filter($blocks, 'strlen'));
    }

    private static function convert_element(DOMElement $node): string
    {
        $tag = $node->tagName;
        $fallback = self::block('html', self::html($node));
        if (in_array($tag, ['div', 'section', 'article'], true) && !$node->hasAttributes()) {
            return self::convert_children($node);
        }
        if ($tag === 'img' || $tag === 'figure' || $tag === 'a' || ($tag === 'p' && $node->getElementsByTagName('img')->length)) {
            $image = self::image($node);
            if ($image !== null) {
                return $image;
            }
        }
        if ($tag === 'table' || ($tag === 'figure' && $node->getElementsByTagName('table')->length)) {
            return self::table($node) ?? $fallback;
        }
        if (($tag === 'p' || preg_match('/^h[1-6]$/', $tag)) && self::has_only_attributes($node, ['id', 'class'])) {
            $name = $tag === 'p' ? 'paragraph' : 'heading';
            $attrs = self::wrapper_attributes($node, $name);
            if ($name === 'heading') {
                $attrs['level'] = (int) substr($tag, 1);
            }
            return self::block($name, self::open_tag($tag, self::wrapper_html_attributes($name, $attrs)) . self::inner_html($node) . '</' . $tag . '>', $attrs);
        }
        if ($tag === 'ul' || $tag === 'ol') {
            return self::list_block($node) ?? $fallback;
        }
        if ($tag === 'blockquote' && self::has_only_attributes($node, ['id', 'class'])) {
            $attrs = self::wrapper_attributes($node, 'quote');
            $copy = $node->cloneNode(true);
            $citation = '';
            foreach (iterator_to_array($copy->childNodes) as $child) {
                if ($child instanceof DOMElement && $child->tagName === 'cite') {
                    if ($citation !== '' || $child->hasAttributes()) {
                        return $fallback;
                    }
                    $citation = self::html($child);
                    $copy->removeChild($child);
                }
            }
            $content = self::supports_inner_lists() ? self::convert_children($copy) : self::inner_html($copy);
            return self::block('quote', self::open_tag('blockquote', self::wrapper_html_attributes('quote', $attrs)) . $content . $citation . '</blockquote>', $attrs);
        }
        if ($tag === 'pre' && self::has_only_attributes($node, ['id', 'class'])) {
            $children = self::element_children($node);
            $is_code = count($children) === 1 && $children[0]->tagName === 'code' && !$children[0]->hasAttributes();
            $name = $is_code ? 'code' : 'preformatted';
            $attrs = self::wrapper_attributes($node, $name);
            return self::block($name, self::open_tag('pre', self::wrapper_html_attributes($name, $attrs)) . self::inner_html($node) . '</pre>', $attrs);
        }
        return $fallback;
    }

    private static function list_block(DOMElement $node, int $depth = 0): ?string
    {
        if ($depth > 32 || !self::has_only_attributes($node, ['id', 'class', 'start', 'reversed', 'type'])) {
            return null;
        }
        $attrs = self::wrapper_attributes($node, 'list');
        $html_attrs = self::wrapper_html_attributes('list', $attrs);
        if ($node->tagName === 'ol') {
            $attrs['ordered'] = true;
            foreach (['start', 'type', 'reversed'] as $key) {
                if ($node->hasAttribute($key)) {
                    $attrs[$key] = $key === 'start' ? (int) $node->getAttribute($key) : ($key === 'reversed' ? true : $node->getAttribute($key));
                    $html_attrs[$key] = $key === 'reversed' ? '' : (string) $attrs[$key];
                }
            }
        } elseif ($node->hasAttribute('start') || $node->hasAttribute('reversed') || $node->hasAttribute('type')) {
            return null;
        }
        $items = '';
        foreach ($node->childNodes as $item) {
            if ($item->nodeType === XML_TEXT_NODE && trim($item->textContent) === '') {
                continue;
            }
            if (!$item instanceof DOMElement || $item->tagName !== 'li' || $item->hasAttributes()) {
                return null;
            }
            $content = '';
            $has_nested_list = false;
            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->tagName, ['ul', 'ol'], true)) {
                    $nested = self::list_block($child, $depth + 1);
                    if ($nested === null) {
                        return null;
                    }
                    $content .= self::supports_inner_lists() ? $nested : self::html($child);
                    $has_nested_list = true;
                } else {
                    // A list item's editable text must precede its nested lists.
                    if ($has_nested_list && trim(self::html($child)) !== '') {
                        return null;
                    }
                    $content .= self::html($child);
                }
            }
            $item_html = '<li>' . trim($content) . '</li>';
            $items .= self::supports_inner_lists() ? self::block('list-item', $item_html) : $item_html;
        }
        return self::block('list', self::open_tag($node->tagName, $html_attrs) . $items . '</' . $node->tagName . '>', $attrs);
    }

    private static function image(DOMElement $node): ?string
    {
        $image = $node;
        $caption = '';
        $link = null;
        $attrs = [];
        if ($node->tagName === 'figure' || $node->tagName === 'p') {
            if (!self::has_only_attributes($node, ['id', 'class'])) {
                return null;
            }
            $children = self::element_children($node);
            if (count($children) === 2 && $node->tagName === 'figure' && $children[1]->tagName === 'figcaption' && self::has_only_attributes($children[1], ['class'])) {
                $caption_class = $children[1]->getAttribute('class');
                if ($caption_class !== '' && $caption_class !== 'wp-element-caption') {
                    return null;
                }
                $caption = self::inner_html(array_pop($children));
            }
            if (count($children) !== 1 || self::has_extra_content($node)) {
                return null;
            }
            $attrs = self::wrapper_attributes($node, 'image');
            $image = $children[0];
        }
        if ($image->tagName === 'a') {
            $link = $image;
            $children = self::element_children($link);
            if (count($children) !== 1 || self::has_extra_content($link) || !$link->getAttribute('href') || !self::has_only_attributes($link, ['href', 'target', 'rel', 'class'])) {
                return null;
            }
            $image = $children[0];
        }
        if ($image->tagName !== 'img' || !self::has_only_attributes($image, ['src', 'alt', 'title', 'class', 'width', 'height']) || !$image->getAttribute('src')) {
            return null;
        }
        $classes = preg_split('/\s+/', trim(($attrs['className'] ?? '') . ' ' . $image->getAttribute('class')));
        $custom_classes = [];
        foreach ($classes as $class) {
            if (preg_match('/^wp-image-(\d+)$/', $class, $match)) {
                $attrs['id'] = (int) $match[1];
            } elseif (preg_match('/^size-([\w-]+)$/', $class, $match)) {
                $attrs['sizeSlug'] = $match[1];
            } elseif (preg_match('/^align(none|left|center|right|wide|full)$/', $class, $match)) {
                $attrs['align'] = $match[1];
            } elseif ($class !== '' && $class !== 'wp-block-image') {
                if (in_array($class, preg_split('/\s+/', trim($image->getAttribute('class'))), true)) {
                    return null; // Do not move arbitrary image-specific styling to its wrapper.
                }
                $custom_classes[] = $class;
            }
        }
        unset($attrs['className']);
        if ($custom_classes) {
            $attrs['className'] = implode(' ', $custom_classes);
        }
        $img_attrs = ['src' => $image->getAttribute('src'), 'alt' => $image->getAttribute('alt')];
        if (!empty($attrs['id'])) {
            $img_attrs['class'] = 'wp-image-' . $attrs['id'];
        }
        $type = WP_Block_Type_Registry::get_instance()->get_registered('core/image');
        $style_dimensions = $type && ($type->attributes['width']['type'] ?? '') === 'string';
        $styles = [];
        foreach (['width', 'height'] as $dimension) {
            $value = $image->getAttribute($dimension);
            if ($value !== '') {
                if (!ctype_digit($value) || (int) $value < 1) {
                    return null;
                }
                $attrs[$dimension] = $style_dimensions ? $value . 'px' : (int) $value;
                if ($style_dimensions) {
                    $styles[$dimension] = $value . 'px';
                } else {
                    $img_attrs[$dimension] = $value;
                }
            }
        }
        if ($styles) {
            $styles['height'] = $styles['height'] ?? 'auto';
            $img_attrs['style'] = implode(';', array_map(static function ($key, $value) { return $key . ':' . $value; }, array_keys($styles), $styles));
        }
        if ($image->hasAttribute('title')) {
            $img_attrs['title'] = $image->getAttribute('title');
        }
        $html = self::open_tag('img', $img_attrs, true);
        if ($link) {
            $link_attrs = [];
            foreach ($link->attributes as $attribute) {
                $link_attrs[$attribute->name] = $attribute->value;
            }
            $html = self::open_tag('a', $link_attrs) . $html . '</a>';
        }
        $attrs['linkDestination'] = $link ? 'custom' : 'none';
        $wrapper = self::wrapper_html_attributes('image', $attrs);
        foreach (['align' => 'align', 'sizeSlug' => 'size-'] as $key => $prefix) {
            if (isset($attrs[$key])) {
                $wrapper['class'] = trim(($wrapper['class'] ?? '') . ' ' . $prefix . $attrs[$key]);
            }
        }
        if (isset($attrs['width']) || isset($attrs['height'])) {
            $wrapper['class'] = trim($wrapper['class'] . ' is-resized');
        }
        if ($caption !== '') {
            $html .= self::open_tag('figcaption', self::caption_attributes()) . $caption . '</figcaption>';
        }
        return self::block('image', self::open_tag('figure', $wrapper) . $html . '</figure>', $attrs);
    }

    private static function table(DOMElement $node): ?string
    {
        $table = $node;
        $caption = '';
        $attrs = ['hasFixedLayout' => false];
        if ($node->tagName === 'figure') {
            $children = self::element_children($node);
            if (count($children) === 2 && $children[1]->tagName === 'figcaption' && self::has_only_attributes($children[1], ['class'])) {
                if (!in_array($children[1]->getAttribute('class'), ['', 'wp-element-caption'], true)) {
                    return null;
                }
                $caption = self::inner_html(array_pop($children));
            }
            if (count($children) !== 1 || self::has_extra_content($node) || !self::has_only_attributes($node, ['id', 'class'])) {
                return null;
            }
            $attrs += self::wrapper_attributes($node, 'table');
            $table = $children[0];
        }
        if ($table->tagName !== 'table' || !self::has_only_attributes($table, ['class']) || !in_array($table->getAttribute('class'), ['', 'has-fixed-layout'], true)) {
            return null;
        }
        $attrs['hasFixedLayout'] = $table->getAttribute('class') === 'has-fixed-layout';
        $sections = ['thead' => '', 'tbody' => '', 'tfoot' => ''];
        foreach ($table->childNodes as $section) {
            if ($section->nodeType === XML_TEXT_NODE && trim($section->textContent) === '') {
                continue;
            }
            if (!$section instanceof DOMElement || $section->hasAttributes()) {
                return null;
            }
            if ($section->tagName === 'caption' && $caption === '') {
                $caption = self::inner_html($section);
                continue;
            }
            $section_name = $section->tagName === 'tr' ? 'tbody' : $section->tagName;
            if (!isset($sections[$section_name])) {
                return null;
            }
            if (self::has_extra_content($section)) {
                return null;
            }
            $rows = $section->tagName === 'tr' ? [$section] : self::element_children($section);
            foreach ($rows as $row) {
                if ($row->tagName !== 'tr' || $row->hasAttributes() || self::has_extra_content($row)) {
                    return null;
                }
                foreach (self::element_children($row) as $cell) {
                    if (!in_array($cell->tagName, ['td', 'th'], true) || !self::has_only_attributes($cell, ['scope', 'colspan', 'rowspan', 'data-align', 'class'])) {
                        return null;
                    }
                    $align = $cell->getAttribute('data-align');
                    if ($cell->getAttribute('class') !== ($align ? 'has-text-align-' . $align : '') || ($cell->tagName === 'td' && $cell->hasAttribute('scope'))) {
                        return null;
                    }
                }
                $sections[$section_name] .= self::html($row);
            }
        }
        $html = '';
        foreach ($sections as $tag => $rows) {
            if ($rows !== '') {
                $html .= '<' . $tag . '>' . $rows . '</' . $tag . '>';
            }
        }
        if ($html === '') {
            return null;
        }
        $html = self::open_tag('table', $attrs['hasFixedLayout'] ? ['class' => 'has-fixed-layout'] : []) . $html . '</table>';
        if ($caption !== '') {
            $html .= self::open_tag('figcaption', self::caption_attributes()) . $caption . '</figcaption>';
        }
        return self::block('table', self::open_tag('figure', self::wrapper_html_attributes('table', $attrs)) . $html . '</figure>', $attrs);
    }

    private static function supports_inner_lists(): bool
    {
        return WP_Block_Type_Registry::get_instance()->is_registered('core/list-item');
    }

    private static function wrapper_attributes(DOMElement $node, string $name): array
    {
        $attrs = [];
        $classes = array_diff(preg_split('/\s+/', trim($node->getAttribute('class'))), ['', 'wp-block-' . $name]);
        if ($classes) {
            $attrs['className'] = implode(' ', $classes);
        }
        if ($node->hasAttribute('id')) {
            $attrs['anchor'] = $node->getAttribute('id');
        }
        return $attrs;
    }

    private static function wrapper_html_attributes(string $name, array $attrs): array
    {
        $type = WP_Block_Type_Registry::get_instance()->get_registered('core/' . $name);
        $class = $type && ($type->supports['className'] ?? true) ? 'wp-block-' . $name : '';
        $class = trim($class . ' ' . ($attrs['className'] ?? ''));
        $html_attrs = $class !== '' ? ['class' => $class] : [];
        if (isset($attrs['anchor'])) {
            $html_attrs['id'] = $attrs['anchor'];
        }
        return $html_attrs;
    }

    private static function caption_attributes(): array
    {
        global $wp_version;
        return version_compare($wp_version, '6.1', '>=') ? ['class' => 'wp-element-caption'] : [];
    }

    private static function has_only_attributes(DOMElement $node, array $allowed): bool
    {
        foreach ($node->attributes as $attribute) {
            if (!in_array($attribute->name, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    private static function has_extra_content(DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement && trim(self::html($child)) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function element_children(DOMNode $node): array
    {
        return array_values(array_filter(iterator_to_array($node->childNodes), static function ($child) { return $child instanceof DOMElement; }));
    }

    private static function open_tag(string $tag, array $attributes, bool $void = false): string
    {
        $html = '<' . $tag;
        foreach ($attributes as $key => $value) {
            $html .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        return $html . ($void ? ' />' : '>');
    }

    private static function html(DOMNode $node): string
    {
        return (string) $node->ownerDocument->saveHTML($node);
    }

    private static function inner_html(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= self::html($child);
        }
        return $html;
    }
}
