<?php


namespace WPAICG\SEO;

use WPAICG\SEO\Yoast\AIPKit_Yoast_Handler;
use WPAICG\SEO\RankMath\AIPKit_Rank_Math_Handler;
use WPAICG\SEO\AIOSEO\AIPKit_AIOSEO_Handler;
use WPAICG\SEO\Framework\AIPKit_Framework_Handler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AIPKit_SEO_Helper
 *
 * Main orchestrator for interacting with various SEO plugins.
 * Detects the active plugin and delegates actions to the appropriate handler.
 */
class AIPKit_SEO_Helper
{
    private static $active_plugin = null;
    private static $detected_plugins = null;
    private static $handler_instance = null;

    /**
     * Detects every supported SEO plugin currently active in WordPress load order.
     *
     * @return array<string, string> Map of plugin slug to display label.
     */
    private static function get_detected_plugins(): array
    {
        if (self::$detected_plugins !== null) {
            return self::$detected_plugins;
        }

        $plugins = [];
        if (defined('WPSEO_VERSION')) {
            $plugins['yoast'] = 'Yoast SEO';
        }
        if (defined('RANK_MATH_VERSION')) {
            $plugins['rank_math'] = 'Rank Math';
        }
        if (defined('AIOSEO_VERSION')) {
            $plugins['aioseo'] = 'All in One SEO';
        }
        if (defined('THE_SEO_FRAMEWORK_VERSION')) {
            $plugins['framework'] = 'The SEO Framework';
        }

        self::$detected_plugins = $plugins;
        return self::$detected_plugins;
    }

    /**
     * Detects the active SEO plugin. Caches the result for the request.
     * @return string The identifier for the active plugin ('yoast', 'rank_math', 'aioseo', 'framework', 'none').
     */
    private static function get_active_plugin(): string
    {
        if (self::$active_plugin !== null) {
            return self::$active_plugin;
        }

        $detected_plugins = self::get_detected_plugins();

        if (isset($detected_plugins['yoast'])) {
            self::$active_plugin = 'yoast';
        } elseif (isset($detected_plugins['rank_math'])) {
            self::$active_plugin = 'rank_math';
        } elseif (isset($detected_plugins['aioseo'])) {
            self::$active_plugin = 'aioseo';
        } elseif (isset($detected_plugins['framework'])) {
            self::$active_plugin = 'framework';
        } else {
            self::$active_plugin = 'none';
        }
        return self::$active_plugin;
    }

    /**
     * Returns the active SEO plugin identifier for integration logic.
     *
     * @return string The active plugin slug: yoast, rank_math, aioseo, framework, or none.
     */
    public static function get_active_plugin_slug(): string
    {
        return self::get_active_plugin();
    }

    /**
     * Returns every supported SEO plugin detected during the current request.
     *
     * @return array<string, string> Map of plugin slug to display label.
     */
    public static function get_detected_plugin_labels(): array
    {
        return self::get_detected_plugins();
    }

    /**
     * Returns notice data when multiple supported SEO plugins are active.
     *
     * @return array<string, mixed>
     */
    public static function get_multiple_active_plugins_notice_data(): array
    {
        $detected_plugins = self::get_detected_plugins();
        if (count($detected_plugins) < 2) {
            return [];
        }

        $active_profile = self::get_active_plugin_profile();
        $active_plugin = self::get_active_plugin();

        return [
            'active_plugin' => $active_plugin,
            'active_label' => isset($active_profile['label']) ? (string) $active_profile['label'] : ($detected_plugins[$active_plugin] ?? 'AIPKit SEO'),
            'detected_plugins' => $detected_plugins,
            'detected_labels' => array_values($detected_plugins),
        ];
    }

    /**
     * Returns the SEO audit profile and capability flags for the active SEO integration.
     *
     * @return array<string, mixed>
     */
    public static function get_active_plugin_profile(): array
    {
        $plugin = self::get_active_plugin();
        $profiles = [
            'yoast' => ['label' => 'Yoast SEO', 'logo_initials' => 'Y'],
            'rank_math' => ['label' => 'Rank Math', 'logo_initials' => 'RM'],
            'aioseo' => ['label' => 'All in One SEO', 'logo_initials' => 'AI'],
            'framework' => ['label' => 'The SEO Framework', 'logo_initials' => 'TSF'],
            'none' => ['label' => 'AIPKit SEO', 'logo_initials' => 'AI'],
        ];
        if (!isset($profiles[$plugin])) {
            $plugin = 'none';
        }

        return [
            'plugin' => $plugin,
            'profile' => $plugin === 'none' ? 'aipkit' : $plugin,
            'label' => $profiles[$plugin]['label'],
            'supports_meta_description' => true,
            'supports_focus_keyword' => in_array($plugin, ['yoast', 'rank_math', 'aioseo'], true),
            'supports_tags' => true,
            'supports_seo_slug' => true,
            'supports_native_score' => false,
            'supports_native_audit' => false,
            'uses_fallback_meta' => $plugin === 'none',
            'logo_url' => $plugin === 'none' ? '' : self::get_plugin_logo_url($plugin),
            'logo_initials' => $profiles[$plugin]['logo_initials'],
        ];
    }

    private static function get_plugin_logo_url(string $plugin): string
    {
        $relative_path = '';

        if ($plugin === 'yoast') {
            $relative_path = 'wordpress-seo/packages/js/images/Yoast_SEO_Icon.svg';
        } elseif ($plugin === 'rank_math') {
            $relative_path = 'seo-by-rank-math/assets/admin/img/menu-icon.svg';
        } elseif ($plugin === 'aioseo') {
            $relative_path = self::find_first_plugin_asset('all-in-one-seo-pack/dist/Lite/assets/svg/aioseo.*.svg');
            if ($relative_path === '') {
                $relative_path = self::find_first_plugin_asset('all-in-one-seo-pack/dist/Lite/assets/svg/icon-logo.*.svg');
            }
        } elseif ($plugin === 'framework' && defined('WPAICG_PLUGIN_DIR') && defined('WP_PLUGIN_DIR')) {
            $relative_path = ltrim(str_replace(trailingslashit(WP_PLUGIN_DIR), '', WPAICG_PLUGIN_DIR . 'admin/images/seo/the-seo-framework.svg'), '/');
        }

        if ($relative_path === '') {
            return '';
        }

        $full_path = defined('WP_PLUGIN_DIR') ? trailingslashit(WP_PLUGIN_DIR) . $relative_path : '';
        if ($full_path !== '' && file_exists($full_path)) {
            return esc_url_raw(plugins_url($relative_path));
        }

        return '';
    }

    private static function find_first_plugin_asset(string $pattern): string
    {
        if (!defined('WP_PLUGIN_DIR')) {
            return '';
        }

        $matches = glob(trailingslashit(WP_PLUGIN_DIR) . $pattern);
        if (empty($matches) || !is_array($matches)) {
            return '';
        }

        $first_match = reset($matches);
        if (!is_string($first_match) || $first_match === '') {
            return '';
        }

        return ltrim(str_replace(trailingslashit(WP_PLUGIN_DIR), '', $first_match), '/');
    }

    /**
     * Returns the active audit profile key.
     *
     * @return string The audit profile key.
     */
    public static function get_active_audit_profile(): string
    {
        $profile = self::get_active_plugin_profile();
        return isset($profile['profile']) ? (string) $profile['profile'] : 'aipkit';
    }

    /**
     * Loads and instantiates the correct handler class for the active SEO plugin.
     * @return AIPKit_SEO_Handler_Interface|null The handler instance or null if none active/found.
     */
    private static function get_handler(): ?AIPKit_SEO_Handler_Interface
    {
        if (self::$handler_instance !== null) {
            return self::$handler_instance;
        }

        $plugin = self::get_active_plugin();
        if ($plugin === 'none') {
            return null;
        }

        $handler_class = null;
        $handler_path = null;

        switch ($plugin) {
            case 'yoast':
                $handler_class = AIPKit_Yoast_Handler::class;
                $handler_path = __DIR__ . '/yoast.php';
                break;
            case 'rank_math':
                $handler_class = AIPKit_Rank_Math_Handler::class;
                $handler_path = __DIR__ . '/rank-math.php';
                break;
            case 'aioseo':
                $handler_class = AIPKit_AIOSEO_Handler::class;
                $handler_path = __DIR__ . '/aioseo.php';
                break;
            case 'framework':
                $handler_class = AIPKit_Framework_Handler::class;
                $handler_path = __DIR__ . '/framework.php';
                break;
        }

        if ($handler_path && file_exists($handler_path)) {
            if (!class_exists($handler_class)) {
                require_once __DIR__ . '/contracts.php';
                require_once $handler_path;
            }
            if (class_exists($handler_class)) {
                self::$handler_instance = new $handler_class();
                return self::$handler_instance;
            }
        }

        return null;
    }

    /**
     * Updates the SEO meta description for a post.
     * Delegates to the active SEO plugin's handler.
     *
     * @param int $post_id The ID of the post.
     * @param string $description The new meta description.
     * @return bool True on success, false on failure.
     */
    public static function update_meta_description(int $post_id, string $description): bool
    {
        $handler = self::get_handler();
        if ($handler) {
            return $handler->update_meta_description($post_id, $description);
        }
        // Fallback for no SEO plugin: save to our own meta key.
        return update_post_meta($post_id, '_aipkit_meta_description', sanitize_text_field($description));
    }

    /**
     * Retrieves the current meta description for the active SEO integration.
     */
    public static function get_meta_description(int $post_id): string
    {
        $meta_key = '_aipkit_meta_description';
        switch (self::get_active_plugin()) {
            case 'yoast':
                $meta_key = '_yoast_wpseo_metadesc';
                break;
            case 'rank_math':
                $meta_key = 'rank_math_description';
                break;
            case 'aioseo':
                $meta_key = '_aioseo_description';
                break;
            case 'framework':
                $meta_key = '_genesis_description';
                break;
        }

        return wp_strip_all_tags((string) get_post_meta($post_id, $meta_key, true));
    }

    /**
     * Updates the focus keyword for a post.
     *
     * @param int $post_id The ID of the post.
     * @param string $keyword The new focus keyword.
     * @return bool True on success, false on failure.
     */
    public static function update_focus_keyword(int $post_id, string $keyword): bool
    {
        $handler = self::get_handler();
        if ($handler) {
            return $handler->update_focus_keyword($post_id, $keyword);
        }
        return false;
    }

    /**
     * Retrieves the focus keyword for a specific post.
     *
     * @param int $post_id The ID of the post.
     * @return string|null The focus keyword, or null if not found/supported.
     */
    public static function get_focus_keyword(int $post_id): ?string
    {
        $handler = self::get_handler();
        if ($handler) {
            return $handler->get_focus_keyword($post_id);
        }
        return null;
    }

    /**
     * Updates the tags for a post, automatically detecting the correct non-hierarchical taxonomy.
     *
     * @param int $post_id The ID of the post.
     * @param string $tags_string A comma-separated string of tags.
     * @return bool True on success, false on failure.
     */
    public static function update_tags(int $post_id, string $tags_string): bool
    {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $tag_taxonomy = 'post_tag'; // Default

        // Check for WooCommerce product_tag first
        if ($post->post_type === 'product' && taxonomy_exists('product_tag') && is_object_in_taxonomy($post->post_type, 'product_tag')) {
            $tag_taxonomy = 'product_tag';
        }
        // If the default 'post_tag' is not associated, find the first non-hierarchical one
        elseif (!is_object_in_taxonomy($post->post_type, 'post_tag')) {
            $taxonomies = get_object_taxonomies($post->post_type, 'objects');
            if (!empty($taxonomies)) {
                foreach ($taxonomies as $taxonomy) {
                    if (!$taxonomy->hierarchical) {
                        $tag_taxonomy = $taxonomy->name;
                        break;
                    }
                }
            }
        }

        // Parse the comma-separated tags string into an array
        $tags_array = array_map('trim', explode(',', $tags_string));
        $tags_array = array_filter($tags_array, function($tag) {
            return !empty($tag);
        });

        $result = wp_set_object_terms($post_id, $tags_array, $tag_taxonomy, false);

        if (is_wp_error($result)) {
            return false;
        }

        $success = $result !== false;

        return $success;
    }


    /**
     * Generates an SEO-friendly slug and updates the post.
     *
     * @param int $post_id The ID of the post to update.
     * @return bool True on success, false on failure.
     */
    public static function update_post_slug_for_seo(int $post_id): bool
    {
        if ($post_id <= 0) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        // 1. Prioritize source for slug: Focus Keyword > Title
        $source_string = self::get_focus_keyword($post_id);
        if (empty(trim($source_string ?? ''))) {
            $source_string = $post->post_title;
        }
        if (empty(trim($source_string ?? ''))) {
            return false; // Nothing to generate slug from
        }

        // 2. Sanitize and prepare the string. Transliterate before stop-word removal so non-English
        // words are not split into stray letters like "s" or "t".
        $slug = remove_accents($source_string);
        $slug = function_exists('mb_strtolower') ? mb_strtolower($slug, 'UTF-8') : strtolower($slug);

        // List of common stop words
        $stop_words = [
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'he', 'i',
            'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the', 'to', 'was', 'were',
            'will', 'with', 'what', 'when', 'where', 'who', 'which', 'why', 'how', 'about',
            'above', 'after', 'below', 'into', 'out', 'over', 'under', 'again', 'further',
            'then', 'once', 'here', 'there', 'all', 'any', 'both', 'each', 'few', 'more',
            'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same',
            'so', 'than', 'too', 'very', 's', 't', 'can', 'just', 'don', 'should', 'now'
        ];
        $slug = preg_replace('/\b(' . implode('|', $stop_words) . ')\b/i', '', $slug);

        // Replace non-alphanumeric with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        // Remove multiple hyphens
        $slug = preg_replace('/-+/', '-', $slug);
        // Trim hyphens from start and end
        $slug = trim($slug, '-');

        if (empty($slug)) {
            // If everything was stripped, fall back to a sanitized title
            $slug = sanitize_title($post->post_title);
            if (empty($slug)) {
                return false; // Still nothing, can't proceed
            }
        }

        // 3. Ensure Optimal Length
        $slug_words = explode('-', $slug);
        if (count($slug_words) > 7) {
            $slug_words = array_slice($slug_words, 0, 7);
            $slug = implode('-', $slug_words);
        }
        $max_slug_length = 75;
        if (sanitize_key((string) (self::get_active_plugin_profile()['profile'] ?? '')) === 'rank_math') {
            $permalink_overhead = strlen(rtrim(home_url('/'), '/') . '//');
            $max_slug_length = max(1, 75 - $permalink_overhead);
        }
        // Final character trim
        $slug = (string) substr($slug, 0, $max_slug_length);
        $slug = trim($slug, '-'); // Trim again in case substr created a trailing hyphen

        if (empty($slug)) {
            return false;
        }

        // 4. Ensure Uniqueness
        $unique_slug = wp_unique_post_slug($slug, $post->ID, $post->post_status, $post->post_type, $post->post_parent);

        // 5. Check if a change is needed before updating
        if ($unique_slug === $post->post_name) {
            return true; // No change needed
        }

        // 6. Update the Post
        $update_result = wp_update_post([
            'ID'        => $post_id,
            'post_name' => $unique_slug
        ], true); // true to return WP_Error on failure

        if (is_wp_error($update_result)) {
            return false;
        }

        // Clear post cache after update
        clean_post_cache($post_id);

        return true;
    }

    /**
     * Gets all tags for a post as a comma-separated string.
     * @param int $post_id The ID of the post.
     * @return string A comma-separated string of tag names.
     */
    public static function get_tags_as_string(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
    
        $tag_taxonomy = 'post_tag'; // Default for posts
    
        if ($post->post_type === 'product' && taxonomy_exists('product_tag') && is_object_in_taxonomy($post->post_type, 'product_tag')) {
            $tag_taxonomy = 'product_tag';
        } elseif (!is_object_in_taxonomy($post->post_type, 'post_tag')) {
            $taxonomies = get_object_taxonomies($post->post_type, 'objects');
            if (!empty($taxonomies)) {
                foreach ($taxonomies as $taxonomy) {
                    if (!$taxonomy->hierarchical) {
                        $tag_taxonomy = $taxonomy->name;
                        break;
                    }
                }
            }
        }
    
        $tags = get_the_terms($post_id, $tag_taxonomy);
        if (is_wp_error($tags) || empty($tags)) {
            return '';
        }
    
        return implode(', ', wp_list_pluck($tags, 'name'));
    }

    /**
     * Gets all categories for a post as a comma-separated string.
     * @param int $post_id The ID of the post.
     * @return string A comma-separated string of category names.
     */
    public static function get_categories_as_string(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
    
        $cat_taxonomy = 'category'; // Default for posts
    
        if ($post->post_type === 'product' && taxonomy_exists('product_cat') && is_object_in_taxonomy($post->post_type, 'product_cat')) {
            $cat_taxonomy = 'product_cat';
        } elseif (!is_object_in_taxonomy($post->post_type, 'category')) {
            $taxonomies = get_object_taxonomies($post->post_type, 'objects');
            if (!empty($taxonomies)) {
                foreach ($taxonomies as $taxonomy) {
                    if ($taxonomy->hierarchical) {
                        $cat_taxonomy = $taxonomy->name;
                        break;
                    }
                }
            }
        }
    
        $categories = get_the_terms($post_id, $cat_taxonomy);
        if (is_wp_error($categories) || empty($categories)) {
            return '';
        }
    
        return implode(', ', wp_list_pluck($categories, 'name'));
    }
}
