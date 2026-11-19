<?php
/** Native WP Recipe Maker card creation for managed Gutenberg drafts. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class RecipeCard
{
    public const BLOCK = 'wp-recipe-maker/recipe';

    public static function available(): bool
    {
        return defined('WPRM_POST_TYPE') && is_callable(['WPRM_Recipe_Saver', 'create_recipe'])
            && is_callable(['WPRM_Recipe_Sanitizer', 'sanitize'])
            && is_callable(['WPRM_Fallback_Recipe', 'replace_fallback_with_shortcode'])
            && is_callable(['WPRM_Recipe_Saver', 'update_recipes_in_post'])
            && \WP_Block_Type_Registry::get_instance()->is_registered(self::BLOCK);
    }

    public static function schema(): array
    {
        $text = ['type' => 'string', 'maxLength' => 300];
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'ingredients', 'instructions'],
            'description' => 'Create one native WP Recipe Maker card with the supplied measurements and ordered steps. Use with source_pattern_id or alone on an existing draft with its current version. Fills one recipe block in the pattern or an empty block in the draft. With no block, appends the card. Uses plain text. Keep amounts such as 1/2 as strings.',
            'properties' => [
                'name' => $text + ['minLength' => 1],
                'image_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional existing Media Library photograph for the recipe card.'],
                'ingredients' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => [
                    'type' => 'object', 'required' => ['name'], 'additionalProperties' => false,
                    'properties' => ['name' => $text + ['minLength' => 1], 'amount' => $text, 'unit' => $text, 'notes' => $text],
                ]],
                'instructions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000]],
            ],
        ];
    }

    public static function validate(array $recipe): void
    {
        if (!self::available()) { throw new \InvalidArgumentException('Activate WP Recipe Maker to create a recipe card.'); }
        $type = get_post_type_object(WPRM_POST_TYPE);
        if (!$type || !current_user_can($type->cap->create_posts)) {
            throw new \InvalidArgumentException('Recipe creation permission is required.');
        }
        $valid = rest_validate_value_from_schema($recipe, self::schema(), 'recipe');
        if (is_wp_error($valid)) { throw new \InvalidArgumentException('Check the recipe name, ingredients and numbered instructions against the offered recipe fields.'); }
        if (isset($recipe['image_id'])) {
            $id = $recipe['image_id'];
            if (!is_int($id) || !wp_attachment_is_image($id) || !current_user_can('read_post', $id)
                || !in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'], true)) {
                throw new \InvalidArgumentException('Choose an accessible Media Library photograph for the recipe card.');
            }
        }
        $fields = array_merge([$recipe['name']], $recipe['instructions'], array_column($recipe['ingredients'], 'name'));
        foreach ($fields as $field) {
            if (!is_string($field) || trim($field) === '') { throw new \InvalidArgumentException('Enter a recipe name, an ingredient name for every row and text for every step.'); }
        }
        foreach ($recipe['ingredients'] as $ingredient) { $fields = array_merge($fields, array_values($ingredient)); }
        foreach ($fields as $field) {
            if (!is_string($field) || wp_strip_all_tags($field) !== $field || Engines::unsafe_content($field)) {
                throw new \InvalidArgumentException('Use plain recipe text in ingredient fields and instructions.');
            }
        }
    }

    public static function prepare_slot(string $base, array $ranges, bool $pattern_copy): array
    {
        // A temporary native leaf keeps every original block path valid for text and photo edits.
        $marker = '<!-- wp:spacer {"anchor":"' . wp_generate_uuid4() . '"} /-->';
        $slot_attrs = [];
        $slots = array_filter($ranges, static function ($range) {
            return $range['block']['blockName'] === self::BLOCK;
        });
        if (count($slots) > 1) { throw new \InvalidArgumentException('Choose a layout with at most one recipe card.'); }
        if ($slots) {
            $slot = reset($slots);
            $slot_attrs = self::slot_attributes($slot['block'], $pattern_copy);
            $base = substr_replace($base, $marker, $slot['markup_start'], $slot['markup_end'] - $slot['markup_start']);
        } else {
            $base .= "\n\n" . $marker;
        }
        if (has_shortcode(\WPRM_Fallback_Recipe::replace_fallback_with_shortcode($base), 'wprm-recipe')) { throw new \InvalidArgumentException('The layout already contains a recipe shortcode.'); }
        return [$base, $marker, $slot_attrs];
    }

    /** Keep the selected template and alignment, and accept only native empty or fallback contents. */
    public static function slot_attributes(array $block, bool $pattern): array
    {
        $attrs = $block['attrs'];
        $id = $attrs['id'] ?? 0;
        $fallback = trim(\WPRM_Fallback_Recipe::replace_fallback_with_shortcode($block['innerHTML']));
        if (array_diff(array_keys($attrs), ['id', 'align', 'template', 'updated']) || $block['innerBlocks']
            || !is_int($id) || $id < 0 || (!$pattern && $id)
            || ($fallback !== '' && (!$id || $fallback !== '[wprm-recipe id="' . $id . '"]'))) {
            throw new \InvalidArgumentException('Use a single native recipe slot. An existing draft card cannot be overwritten.');
        }
        foreach (['template', 'align'] as $key) {
            if (isset($attrs[$key]) && (!is_string($attrs[$key]) || sanitize_key($attrs[$key]) !== $attrs[$key])) {
                throw new \InvalidArgumentException('Unsupported recipe block styling.');
            }
        }
        return array_intersect_key($attrs, array_flip(['align', 'template']));
    }

    public static function create(array $recipe): int
    {
        $steps = [];
        foreach ($recipe['instructions'] as $uid => $text) { $steps[] = ['uid' => $uid, 'text' => $text]; }
        $ingredients = $recipe['ingredients'];
        foreach ($ingredients as $uid => &$ingredient) { $ingredient['uid'] = $uid; }
        unset($ingredient);
        $native = \WPRM_Recipe_Sanitizer::sanitize([
            'image_id' => $recipe['image_id'] ?? 0, 'name' => $recipe['name'], 'post_status' => 'draft',
            'ingredients' => [['ingredients' => $ingredients]], 'instructions' => [['instructions' => $steps]],
        ]);
        $id = (int) \WPRM_Recipe_Saver::create_recipe($native);
        if (!$id || get_post_type($id) !== WPRM_POST_TYPE || get_post_status($id) !== 'draft'
            || (int) get_post_thumbnail_id($id) !== ($recipe['image_id'] ?? 0)
            || get_post_field('post_title', $id, 'raw') !== $native['name']
            || get_post_meta($id, 'wprm_ingredients', true) !== $native['ingredients']
            || get_post_meta($id, 'wprm_instructions', true) !== $native['instructions']) {
            throw new \RuntimeException('WP Recipe Maker could not confirm the saved draft recipe fields.');
        }
        return $id;
    }

    public static function markup(int $id, array $attrs): string
    {
        return get_comment_delimited_block_content(self::BLOCK, ['id' => $id] + $attrs, '[wprm-recipe id="' . $id . '"]');
    }
}
