<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
?>

<div class="aipkit_cw_publishing_rows">
        <div class="aipkit_cw_publishing_row">
            <label class="aipkit_cw_panel_label" for="aipkit_content_writer_post_content_format">
                <?php esc_html_e('Content format', 'gpt3-ai-content-generator'); ?>
            </label>
            <div class="aipkit_cw_publishing_row_actions">
                <select
                    id="aipkit_content_writer_post_content_format"
                    name="post_content_format"
                    class="aipkit_post_settings_select aipkit_form-input aipkit_cw_publishing_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                    data-aipkit-cw-fit-selected
                >
                    <option value="gutenberg"><?php esc_html_e('Gutenberg', 'gpt3-ai-content-generator'); ?></option>
                    <option value="html"><?php esc_html_e('Classic', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>

        <div class="aipkit_cw_publishing_row">
            <label class="aipkit_cw_panel_label" for="aipkit_content_writer_post_type">
                <?php esc_html_e('Post type', 'gpt3-ai-content-generator'); ?>
            </label>
            <div class="aipkit_cw_publishing_row_actions">
                <select
                    id="aipkit_content_writer_post_type"
                    name="post_type"
                    class="aipkit_post_settings_select aipkit_form-input aipkit_cw_publishing_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                    data-aipkit-cw-fit-selected
                >
                    <?php foreach ($available_post_types as $pt_slug => $pt_obj): ?>
                        <option value="<?php echo esc_attr($pt_slug); ?>" <?php selected($pt_slug, 'post'); ?>>
                            <?php echo esc_html($pt_obj->label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="aipkit_cw_publishing_row">
            <label class="aipkit_cw_panel_label" for="aipkit_content_writer_post_author">
                <?php esc_html_e('Author', 'gpt3-ai-content-generator'); ?>
            </label>
            <div class="aipkit_cw_publishing_row_actions">
                <select
                    id="aipkit_content_writer_post_author"
                    name="post_author"
                    class="aipkit_post_settings_select aipkit_form-input aipkit_cw_publishing_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                    data-aipkit-cw-fit-selected
                >
                    <?php foreach ($users_for_author as $user): ?>
                        <option
                            value="<?php echo esc_attr($user->ID); ?>"
                            data-login="<?php echo esc_attr($user->user_login); ?>"
                            <?php selected($user->ID, $current_user_id); ?>
                        >
                            <?php echo esc_html($user->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="aipkit_cw_publishing_row">
            <label class="aipkit_cw_panel_label" for="aipkit_content_writer_categories">
                <?php esc_html_e('Categories', 'gpt3-ai-content-generator'); ?>
            </label>
            <div class="aipkit_cw_publishing_row_actions">
                <?php
                $aipkit_category_dropdown_config = [
                    'id'              => 'aipkit_content_writer_categories',
                    'panel_id'        => 'aipkit_cw_categories_panel',
                    'categories'      => $wp_categories,
                    'wrapper_classes' => 'aipkit_cw_publishing_categories',
                    'button_classes'  => 'aipkit_cw_blended_chevron_btn',
                    'select_classes'  => 'aipkit_autosave_trigger',
                ];
                require WPAICG_PLUGIN_DIR . 'admin/views/shared/category-dropdown.php';
                ?>
            </div>
        </div>

</div>
