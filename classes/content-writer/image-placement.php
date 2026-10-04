<?php


namespace WPAICG\ContentWriter;

use SplQueue;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles injecting generated image HTML into post content based on placement rules.
 */
class AIPKit_Image_Injector
{
    /**
     * Main injection method that routes to specific placement logic.
     *
     * @param string $content The HTML content of the post.
     * @param array $images An array of image data items from the generation API.
     * @param string $placement The placement rule (e.g., 'after_first_h2', 'at_end').
     * @param int $param_x Optional parameter for 'every_x' placements.
     * @param string $alignment Optional alignment class ('none', 'left', 'center', 'right').
     * @param string $image_size Optional display size for injected images ('thumbnail', 'medium', 'large', 'full').
     * @return string The content with images injected.
     */
    public function inject_images(string $content, array $images, string $placement, int $param_x = 2, string $alignment = 'none', string $image_size = 'large'): string
    {
        if (empty($images) || empty(trim($content))) {
            return $content;
        }

        switch ($placement) {
            case 'after_first_h2':
                return $this->inject_after_first_heading($content, $images, 'h2', $alignment, $image_size);

            case 'after_first_h3':
                return $this->inject_after_first_heading($content, $images, 'h3', $alignment, $image_size);

            case 'after_every_x_h2':
                return $this->inject_after_every_x_element($content, $images, $param_x, 'h2', $alignment, $image_size);

            case 'after_every_x_h3':
                return $this->inject_after_every_x_element($content, $images, $param_x, 'h3', $alignment, $image_size);

            case 'after_every_x_p':
                return $this->inject_after_every_x_element($content, $images, $param_x, 'p', $alignment, $image_size);

            case 'at_end':
                return $this->inject_at_end($content, $images, $alignment, $image_size);

            default:
                // Fallback to after first h2 if placement is unknown
                return $this->inject_after_first_heading($content, $images, 'h2', $alignment, $image_size);
        }
    }

    /**
     * Injects the first available image after the first matching heading.
     *
     * @param string $content The HTML content.
     * @param array $images The array of image data.
     * @param string $heading_tag The heading tag ('h2' or 'h3').
     * @param string $alignment The alignment class.
     * @param string $image_size The image size.
     * @return string Modified content.
     */
    private function inject_after_first_heading(string $content, array $images, string $heading_tag, string $alignment, string $image_size): string
    {
        $image_html_array = array_filter(
            array_map(fn ($img) => $this->get_image_html($img, $alignment, $image_size), $images)
        );
        if (empty($image_html_array)) {
            return $content;
        }

        $first_image = array_shift($image_html_array);
        $closing_tag = '</' . $heading_tag . '>';
        $position = stripos($content, $closing_tag);
        if ($position !== false) {
            $content = substr_replace($content, $closing_tag . "\n\n" . $first_image . "\n\n", $position, strlen($closing_tag));
        } else {
            $content .= "\n\n" . $first_image;
        }

        if (!empty($image_html_array)) {
            $content .= "\n\n" . implode("\n\n", $image_html_array);
        }
        return $content;
    }

    /**
     * Injects an image after every X matching element.
     *
     * @param string $content The HTML content.
     * @param array $images The array of image data.
     * @param int $x The number of matching elements to skip between insertions.
     * @param string $element_tag The element tag ('h2', 'h3' or 'p').
     * @param string $alignment The alignment class.
     * @param string $image_size The image size.
     * @return string Modified content.
     */
    private function inject_after_every_x_element(string $content, array $images, int $x, string $element_tag, string $alignment, string $image_size): string
    {
        if ($x <= 0) {
            return $this->inject_at_end($content, $images, $alignment, $image_size); // Fallback if X is invalid
        }

        $image_html_array = array_map(fn ($img) => $this->get_image_html($img, $alignment, $image_size), $images);
        $image_queue = new SplQueue();
        foreach ($image_html_array as $img_html) {
            $image_queue->enqueue($img_html);
        }

        $parts = preg_split('/(<\/' . $element_tag . '>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $new_content = '';
        $element_count = 0;

        foreach ($parts as $i => $part) {
            $new_content .= $part;
            // The delimiter is captured, so it's every other part starting from index 1
            if ($i > 0 && ($i % 2) !== 0) {
                $element_count++;
                if ($element_count % $x === 0 && !$image_queue->isEmpty()) {
                    $new_content .= "\n\n" . $image_queue->dequeue() . "\n\n";
                }
            }
        }

        // Add any remaining images at the end
        while (!$image_queue->isEmpty()) {
            $new_content .= "\n\n" . $image_queue->dequeue();
        }

        return $new_content;
    }

    /**
     * Appends all images to the end of the content.
     *
     * @param string $content The HTML content.
     * @param array $images The array of image data.
     * @param string $alignment The alignment class.
     * @param string $image_size The image size.
     * @return string Modified content.
     */
    private function inject_at_end(string $content, array $images, string $alignment, string $image_size): string
    {
        $image_html_array = array_map(fn ($img) => $this->get_image_html($img, $alignment, $image_size), $images);
        return $content . "\n\n" . implode("\n\n", $image_html_array);
    }

    /**
     * Generates a standard WordPress <img> tag.
     *
     * @param array|null $image_item The image data.
     * @param string $alignment The requested alignment ('none', 'left', 'center', 'right').
     * @param string $image_size The requested WP image size ('thumbnail', 'medium', 'large', 'full').
     * @return string The generated HTML or an empty string.
     */
    private function get_image_html(?array $image_item, string $alignment = 'none', string $image_size = 'large'): string
    {
        if (empty($image_item)) {
            return '';
        }

        if (empty($image_item['attachment_id'])) {
            $fallback_url = $image_item['media_library_url'] ?? ($image_item['url'] ?? ($image_item['src'] ?? ($image_item['image_url'] ?? null)));
            if (empty($fallback_url)) {
                return '';
            }
            $fallback_alt = $this->get_image_item_alt($image_item);
            if ($fallback_alt === '') {
                $fallback_alt = $image_item['revised_prompt'] ?? 'Image';
            }
            $class_list = [];
            if (in_array($alignment, ['left', 'right', 'center', 'none'], true)) {
                $class_list[] = 'align' . $alignment;
            }
            $class_list[] = 'aipkit_cw_external_image';
            $class_list[] = 'size-full';
            $final_classes = esc_attr(implode(' ', $class_list));

            // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- External URL fallback when attachment is missing.
            return sprintf('<img class="%s" src="%s" alt="%s" />',
                $final_classes,
                esc_url($fallback_url),
                esc_attr($fallback_alt)
            );
        }

        $attachment_id = absint($image_item['attachment_id']);
        $stored_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $item_alt = $this->get_image_item_alt($image_item);
        $fallback_alt = $item_alt !== '' ? $item_alt : ($image_item['revised_prompt'] ?? get_the_title($attachment_id) ?: 'Image');
        $alt_text = esc_attr($stored_alt ?: $fallback_alt);

        // Get image data (URL, width, height) for the specified size from WordPress
        $image_attributes = wp_get_attachment_image_src($attachment_id, $image_size);

        if (!$image_attributes) {
            // Fallback to full size if the requested size doesn't exist.
            $image_attributes = wp_get_attachment_image_src($attachment_id, 'full');
            if (!$image_attributes) {
                // Final fallback: use raw URL and no dimensions if something is wrong.
                $url = esc_url($image_item['media_library_url'] ?? '');
                $width_attr = '';
                $height_attr = '';
                $size_class = 'size-full';
            } else {
                list($url, $width, $height) = $image_attributes;
                $url = esc_url($url);
                $width_attr = sprintf(' width="%d"', $width);
                $height_attr = sprintf(' height="%d"', $height);
                $size_class = 'size-full';
            }
        } else {
            list($url, $width, $height) = $image_attributes;
            $url = esc_url($url);
            $width_attr = sprintf(' width="%d"', $width);
            $height_attr = sprintf(' height="%d"', $height);
            $size_class = 'size-' . esc_attr($image_size);
        }

        // --- Build Classes ---
        $class_list = [];

        // 1. Alignment class
        if (in_array($alignment, ['left', 'right', 'center', 'none'], true)) {
            $class_list[] = 'align' . $alignment;
        }

        // 2. WordPress image ID class
        $class_list[] = 'wp-image-' . $attachment_id;

        // 3. Size class
        $class_list[] = $size_class;

        $final_classes = esc_attr(implode(' ', $class_list));

        // Assemble the final <img> tag
        // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Reason: The image source is correctly retrieved using a WordPress function (e.g., `wp_get_attachment_image_url`). The `<img>` tag is constructed manually to build a custom HTML structure with specific wrappers, classes, or attributes that are not achievable with the standard `wp_get_attachment_image()` function.
        return sprintf('<img class="%s" src="%s" alt="%s"%s%s />',
            $final_classes,
            $url,
            $alt_text,
            $width_attr,
            $height_attr
        );
    }

    private function get_image_item_alt(array $image_item): string
    {
        foreach (['alt', 'alt_text', 'generated_alt_text', 'image_alt', 'seo_alt'] as $key) {
            if (!empty($image_item[$key]) && is_scalar($image_item[$key])) {
                return trim(wp_strip_all_tags((string) $image_item[$key]));
            }
        }

        return '';
    }
}
