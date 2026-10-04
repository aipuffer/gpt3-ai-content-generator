<?php
/**
 * Shared searchable category multiselect.
 *
 * Expected configuration in $aipkit_category_dropdown_config:
 * - id: Hidden select ID.
 * - panel_id: Popover panel ID.
 * - categories: Array of WP_Term-like category objects.
 * - wrapper_classes: Additional wrapper classes.
 * - button_classes: Additional trigger classes.
 * - select_classes: Additional hidden select classes.
 * - name: Hidden select name.
 * - placeholder: Empty selection label.
 * - selected_label: Suffix used for three or more selections.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included template variables are local to the parent view.
$aipkit_category_dropdown_config = isset($aipkit_category_dropdown_config) && is_array($aipkit_category_dropdown_config)
    ? $aipkit_category_dropdown_config
    : [];
$aipkit_category_dropdown_defaults = [
    'id'                => '',
    'panel_id'          => '',
    'categories'        => [],
    'wrapper_classes'   => '',
    'button_classes'    => '',
    'select_classes'    => '',
    'name'              => 'post_categories[]',
    'placeholder'       => __('None', 'gpt3-ai-content-generator'),
    'selected_label'    => __('selected', 'gpt3-ai-content-generator'),
];
$aipkit_category_dropdown_args = wp_parse_args($aipkit_category_dropdown_config, $aipkit_category_dropdown_defaults);
$aipkit_category_dropdown_id = (string) $aipkit_category_dropdown_args['id'];
$aipkit_category_dropdown_panel_id = (string) $aipkit_category_dropdown_args['panel_id'];
$aipkit_category_dropdown_categories = is_array($aipkit_category_dropdown_args['categories'])
    ? $aipkit_category_dropdown_args['categories']
    : [];
$aipkit_category_dropdown_wrapper_classes = trim(
    'aipkit_popover_multiselect aipkit_post_multiselect aipkit_searchable_multiselect '
    . (string) $aipkit_category_dropdown_args['wrapper_classes']
);
$aipkit_category_dropdown_button_classes = trim(
    'aipkit_popover_multiselect_btn aipkit_post_multiselect_btn '
    . (string) $aipkit_category_dropdown_args['button_classes']
);
$aipkit_category_dropdown_select_classes = trim(
    'aipkit_popover_multiselect_select '
    . (string) $aipkit_category_dropdown_args['select_classes']
);
?>

<div
    class="<?php echo esc_attr($aipkit_category_dropdown_wrapper_classes); ?>"
    data-aipkit-category-dropdown
    data-placeholder="<?php echo esc_attr($aipkit_category_dropdown_args['placeholder']); ?>"
    data-selected-label="<?php echo esc_attr($aipkit_category_dropdown_args['selected_label']); ?>"
>
    <button
        type="button"
        class="<?php echo esc_attr($aipkit_category_dropdown_button_classes); ?>"
        aria-expanded="false"
        aria-controls="<?php echo esc_attr($aipkit_category_dropdown_panel_id); ?>"
        aria-haspopup="dialog"
    >
        <span class="aipkit_popover_multiselect_label">
            <?php echo esc_html($aipkit_category_dropdown_args['placeholder']); ?>
        </span>
    </button>
    <div
        id="<?php echo esc_attr($aipkit_category_dropdown_panel_id); ?>"
        class="aipkit_popover_multiselect_panel aipkit_searchable_multiselect_panel"
        role="dialog"
        aria-label="<?php echo esc_attr__('Categories', 'gpt3-ai-content-generator'); ?>"
        hidden
    >
        <div class="aipkit_searchable_multiselect_search">
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <input
                type="search"
                class="aipkit_searchable_multiselect_search_input"
                placeholder="<?php echo esc_attr__('Search categories', 'gpt3-ai-content-generator'); ?>"
                aria-label="<?php echo esc_attr__('Search categories', 'gpt3-ai-content-generator'); ?>"
                autocomplete="off"
            >
        </div>
        <div class="aipkit_popover_multiselect_options" data-aipkit-category-options></div>
        <div class="aipkit_searchable_multiselect_no_results" hidden>
            <?php esc_html_e('No categories found.', 'gpt3-ai-content-generator'); ?>
        </div>
    </div>
    <select
        id="<?php echo esc_attr($aipkit_category_dropdown_id); ?>"
        name="<?php echo esc_attr($aipkit_category_dropdown_args['name']); ?>"
        class="<?php echo esc_attr($aipkit_category_dropdown_select_classes); ?>"
        data-aipkit-category-select
        multiple
        size="3"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >
        <?php foreach ($aipkit_category_dropdown_categories as $aipkit_category_dropdown_category) : ?>
            <option value="<?php echo esc_attr($aipkit_category_dropdown_category->term_id); ?>">
                <?php echo esc_html($aipkit_category_dropdown_category->name); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<?php
unset(
    $aipkit_category_dropdown_config,
    $aipkit_category_dropdown_defaults,
    $aipkit_category_dropdown_args,
    $aipkit_category_dropdown_id,
    $aipkit_category_dropdown_panel_id,
    $aipkit_category_dropdown_categories,
    $aipkit_category_dropdown_wrapper_classes,
    $aipkit_category_dropdown_button_classes,
    $aipkit_category_dropdown_select_classes,
    $aipkit_category_dropdown_category
);
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
