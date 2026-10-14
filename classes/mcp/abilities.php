<?php
/**
 * AI Puffer's own abilities, registered with the WordPress Abilities API so any assistant connected through website
 * management (or another Abilities client) can see what this site runs. Read-only, for administrators.
 */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Abilities
{
    public const CATEGORY = 'ai-puffer';

    public static function register_hooks(): void
    {
        if (!Engines::abilities_available()) { return; }
        add_action('wp_abilities_api_categories_init', [self::class, 'register_category']);
        add_action('wp_abilities_api_init', [self::class, 'register']);
    }

    public static function register_category(): void
    {
        if (!function_exists('wp_register_ability_category')) { return; }
        wp_register_ability_category(self::CATEGORY, ['label' => __('AI Puffer', 'gpt3-ai-content-generator'),
            'description' => __('Chatbots, AI forms and other AI Puffer features on this site.', 'gpt3-ai-content-generator')]);
    }

    public static function register(): void
    {
        if (!function_exists('wp_register_ability')) { return; }
        $read_only = ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true], 'public' => true];
        $list = ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'any'], 'default' => 'any', 'description' => 'Only items with this status.'],
        ], 'additionalProperties' => false, 'default' => []];
        $items = ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object']]]];
        wp_register_ability('ai-puffer/list-chatbots', [
            'label' => __('List chatbots', 'gpt3-ai-content-generator'),
            'description' => __('Chatbots configured in AI Puffer on this site: ID, name, status and when each was last changed. Does not include conversations.', 'gpt3-ai-content-generator'),
            'category' => self::CATEGORY, 'input_schema' => $list, 'output_schema' => $items, 'meta' => $read_only,
            'permission_callback' => [self::class, 'can_view'],
            'execute_callback' => static function ($input = null) { return self::items('aipkit_chatbot', $input); },
        ]);
        wp_register_ability('ai-puffer/list-ai-forms', [
            'label' => __('List AI forms', 'gpt3-ai-content-generator'),
            'description' => __('AI forms built with AI Puffer on this site: ID, name, status and when each was last changed. Does not include submissions.', 'gpt3-ai-content-generator'),
            'category' => self::CATEGORY, 'input_schema' => $list, 'output_schema' => $items, 'meta' => $read_only,
            'permission_callback' => [self::class, 'can_view'],
            'execute_callback' => static function ($input = null) { return self::items('aipkit_ai_form', $input); },
        ]);
    }

    public static function can_view(): bool
    {
        return current_user_can('manage_options');
    }

    private static function items(string $post_type, $input): array
    {
        $status = is_array($input) && in_array($input['status'] ?? 'any', ['publish', 'draft'], true) ? $input['status'] : ['publish', 'draft', 'pending', 'private'];
        $posts = get_posts(['post_type' => $post_type, 'post_status' => $status, 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']);
        return ['items' => array_map(static function (\WP_Post $post): array {
            return ['id' => (int) $post->ID, 'name' => $post->post_title, 'status' => $post->post_status, 'modified' => $post->post_modified_gmt];
        }, $posts)];
    }
}
