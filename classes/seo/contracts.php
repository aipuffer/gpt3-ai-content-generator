<?php

namespace WPAICG\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for SEO Plugin Handlers.
 * Defines a contract for all plugin-specific handler classes.
 */
interface AIPKit_SEO_Handler_Interface
{
    /**
     * Updates the meta description for a specific post.
     *
     * @param int $post_id The ID of the post.
     * @param string $description The new meta description.
     * @return bool True on success, false on failure.
     */
    public function update_meta_description(int $post_id, string $description): bool;

    /**
     * Updates the focus keyword(s) for a specific post.
     *
     * @param int $post_id The ID of the post.
     * @param string $keyword The new focus keyword or comma-separated keywords.
     * @return bool True on success, false on failure.
     */
    public function update_focus_keyword(int $post_id, string $keyword): bool;

    /**
     * Retrieves the focus keyword for a specific post.
     *
     * @param int $post_id The ID of the post.
     * @return string|null The focus keyword, or null if not found.
     */
    public function get_focus_keyword(int $post_id): ?string;
}

abstract class AIPKit_Base_SEO_Handler implements AIPKit_SEO_Handler_Interface
{
    protected const LOGIC_NAMESPACE = '';

    public function update_meta_description(int $post_id, string $description): bool
    {
        return (bool) $this->call_logic('update_meta_description_logic', [$post_id, $description], false);
    }

    public function update_focus_keyword(int $post_id, string $keyword): bool
    {
        return (bool) $this->call_logic('update_focus_keyword_logic', [$post_id, $keyword], false);
    }

    public function get_focus_keyword(int $post_id): ?string
    {
        return $this->call_logic('get_focus_keyword_logic', [$post_id], null);
    }

    /**
     * @param mixed $fallback
     * @return mixed
     */
    private function call_logic(string $function_name, array $args, $fallback)
    {
        $function = trim((string) static::LOGIC_NAMESPACE, '\\') . '\\' . $function_name;
        return function_exists($function) ? $function(...$args) : $fallback;
    }
}
