<?php
/**
 * Partial: Module visibility settings.
 */

use WPAICG\AIPKit_Role_Manager;

if (!defined('ABSPATH')) {
    exit;
}

// Variables required: $module_settings, $can_manage_modules.

$aipkit_settings_modules = array(
    'chat_bot' => array(
        'label'       => __('Chatbots', 'gpt3-ai-content-generator'),
        'description' => __('Chat with visitors on your site.', 'gpt3-ai-content-generator'),
        'icon'        => 'format-chat',
        'data_module' => 'chatbot',
    ),
    'content_writer' => array(
        'label'       => __('Content Writer', 'gpt3-ai-content-generator'),
        'description' => __('Posts, pages and products.', 'gpt3-ai-content-generator'),
        'icon'        => 'edit',
        'data_module' => 'content-writer',
    ),
    'autogpt' => array(
        'label'       => __('Automations', 'gpt3-ai-content-generator'),
        'description' => __('Tasks that run on a schedule.', 'gpt3-ai-content-generator'),
        'icon_text'   => '⚡︎',
        'data_module' => 'autogpt',
    ),
    'ai_forms' => array(
        'label'       => __('AI Forms', 'gpt3-ai-content-generator'),
        'description' => __('Forms that answer with AI.', 'gpt3-ai-content-generator'),
        'icon'        => 'feedback',
        'data_module' => 'ai-forms',
    ),
    'sources' => array(
        'label'       => __('Knowledge Base', 'gpt3-ai-content-generator'),
        'description' => __('What your chatbots know.', 'gpt3-ai-content-generator'),
        'icon'        => 'media-document',
        'data_module' => 'sources',
    ),
    'image_generator' => array(
        'label'       => __('Images', 'gpt3-ai-content-generator'),
        'description' => __('Make and edit images.', 'gpt3-ai-content-generator'),
        'icon'        => 'format-image',
        'data_module' => 'image-generator',
    ),
    'stats_viewer' => array(
        'label'       => __('Usage', 'gpt3-ai-content-generator'),
        'description' => __('Costs and limits.', 'gpt3-ai-content-generator'),
        'icon'        => 'chart-bar',
        'data_module' => 'stats',
    ),
);

$aipkit_module_options = get_option('aipkit_options', array());
$aipkit_module_options = is_array($aipkit_module_options) ? $aipkit_module_options : array();
$aipkit_enhancer_settings = isset($aipkit_module_options['enhancer_settings']) && is_array($aipkit_module_options['enhancer_settings'])
    ? $aipkit_module_options['enhancer_settings']
    : array();
$aipkit_enhancer_editor_enabled = (string) ($aipkit_enhancer_settings['editor_integration'] ?? '1') === '1';
$aipkit_enhancer_list_enabled = (string) ($aipkit_enhancer_settings['show_list_button'] ?? '1') === '1';

$aipkit_training_settings = get_option(
    'aipkit_training_general_settings',
    array('show_index_button' => true)
);
$aipkit_training_settings = is_array($aipkit_training_settings) ? $aipkit_training_settings : array();
$aipkit_index_button_enabled = !array_key_exists('show_index_button', $aipkit_training_settings)
    || (bool) $aipkit_training_settings['show_index_button'];
$aipkit_indexing_nonce = wp_create_nonce('aipkit_ai_training_settings_nonce');
$aipkit_visitor_billing_enabled = class_exists(\WPAICG\Stats\AIPKit_Stats::class)
    && \WPAICG\Stats\AIPKit_Stats::visitor_billing_enabled();

$aipkit_editor_tools = array(
    'index_button' => array(
        'label'       => __('Add to knowledge base', 'gpt3-ai-content-generator'),
        'description' => __('A button on the Posts and Pages lists.', 'gpt3-ai-content-generator'),
        'icon'        => 'list-view',
        'field_id'    => 'aipkit_settings_index_button',
        'name'        => 'show_index_button',
        'enabled'     => $aipkit_index_button_enabled,
        'class'       => 'aipkit_settings_index_button_toggle',
        'special'     => true,
    ),
    'content_assistant' => array(
        'label'       => __('Content Assistant', 'gpt3-ai-content-generator'),
        'description' => __('Improve several posts at once, from the Posts and Pages lists.', 'gpt3-ai-content-generator'),
        'icon'        => 'lightbulb',
        'field_id'    => 'aipkit_enhancer_list_button',
        'name'        => 'enhancer_list_button',
        'enabled'     => $aipkit_enhancer_list_enabled,
        'class'       => 'aipkit_autosave_trigger',
        'special'     => false,
    ),
    'editor_assistant' => array(
        'label'       => __('Editor assistant', 'gpt3-ai-content-generator'),
        'description' => __('Help while writing, in the Classic and Block editors.', 'gpt3-ai-content-generator'),
        'icon'        => 'edit-page',
        'field_id'    => 'aipkit_enhancer_editor_integration',
        'name'        => 'enhancer_editor_integration',
        'enabled'     => $aipkit_enhancer_editor_enabled,
        'class'       => 'aipkit_autosave_trigger',
        'special'     => false,
    ),
);
?>
<?php
$aipkit_menu_modules = array_filter($aipkit_settings_modules, static fn($module) => AIPKit_Role_Manager::user_can_access_module($module['data_module']));
?>
<div
    class="aipkit_settings_modules"
    id="aipkit_settings_modules"
    data-indexing-nonce="<?php echo esc_attr($aipkit_indexing_nonce); ?>"
    data-visitor-billing-nonce="<?php echo esc_attr(wp_create_nonce('aipkit_visitor_billing')); ?>"
>
    <section class="aipkit_settings_part aipkit_settings_modules_section" aria-labelledby="aipkit_settings_modules_navigation_title">
        <h4 class="aipkit_settings_modules_section_title" id="aipkit_settings_modules_navigation_title">
            <?php esc_html_e('In the AI Puffer menu', 'gpt3-ai-content-generator'); ?>
        </h4>
        <p class="aipkit_settings_modules_section_hint"><?php esc_html_e('Turn a tool off to hide it from the menu. Nothing is deleted.', 'gpt3-ai-content-generator'); ?></p>
        <?php // One list, like the AI page's providers. ?>
        <div class="aipkit_settings_modules_list">
            <?php foreach ($aipkit_menu_modules as $aipkit_option_key => $aipkit_module): ?>
                <?php
                $aipkit_is_enabled = !isset($module_settings[$aipkit_option_key]) || !empty($module_settings[$aipkit_option_key]);
                $aipkit_field_id = 'aipkit_settings_module_' . $aipkit_option_key;
                ?>
                <div
                    class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_module_row"
                    data-module="<?php echo esc_attr($aipkit_module['data_module']); ?>"
                >
                    <div class="aipkit_settings_module_identity">
                        <?php if (!empty($aipkit_module['icon_text'])): ?>
                            <span class="aipkit_settings_module_icon aipkit_settings_module_icon--glyph" aria-hidden="true"><?php echo esc_html($aipkit_module['icon_text']); ?></span>
                        <?php else: ?>
                            <span class="aipkit_settings_module_icon dashicons dashicons-<?php echo esc_attr($aipkit_module['icon']); ?>" aria-hidden="true"></span>
                        <?php endif; ?>
                        <label class="aipkit_form-label aipkit_settings_module_copy" for="<?php echo esc_attr($aipkit_field_id); ?>">
                            <span class="aipkit_settings_module_title"><?php echo esc_html($aipkit_module['label']); ?></span>
                            <span class="aipkit_form-label-helper"><?php echo esc_html($aipkit_module['description']); ?></span>
                        </label>
                    </div>
                    <label class="aipkit_switch aipkit_settings_module_control" for="<?php echo esc_attr($aipkit_field_id); ?>">
                        <input
                            type="checkbox"
                            id="<?php echo esc_attr($aipkit_field_id); ?>"
                            class="aipkit_settings_module_toggle_input"
                            data-option-key="<?php echo esc_attr($aipkit_option_key); ?>"
                            data-module="<?php echo esc_attr($aipkit_module['data_module']); ?>"
                            <?php /* translators: %s: module name. */ ?>
                            aria-label="<?php echo esc_attr(sprintf(__('Enable %s', 'gpt3-ai-content-generator'), $aipkit_module['label'])); ?>"
                            <?php checked($aipkit_is_enabled); ?>
                            <?php disabled(!$can_manage_modules); ?>
                        />
                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="aipkit_settings_part aipkit_settings_modules_section" aria-labelledby="aipkit_settings_modules_editor_tools_title">
        <h4 class="aipkit_settings_modules_section_title" id="aipkit_settings_modules_editor_tools_title">
            <?php esc_html_e('In WordPress', 'gpt3-ai-content-generator'); ?>
        </h4>
        <p class="aipkit_settings_modules_section_hint"><?php esc_html_e('Extras on your posts, pages and editor.', 'gpt3-ai-content-generator'); ?></p>
        <div class="aipkit_settings_modules_list">
            <?php foreach ($aipkit_editor_tools as $aipkit_tool): ?>
                <div
                    class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_module_row"
                    <?php if ($aipkit_tool['special']): ?>
                        data-aipkit-settings-autosave-exclude="true"
                    <?php endif; ?>
                >
                    <div class="aipkit_settings_module_identity">
                        <span class="aipkit_settings_module_icon dashicons dashicons-<?php echo esc_attr($aipkit_tool['icon']); ?>" aria-hidden="true"></span>
                        <label class="aipkit_form-label aipkit_settings_module_copy" for="<?php echo esc_attr($aipkit_tool['field_id']); ?>">
                            <span class="aipkit_settings_module_title"><?php echo esc_html($aipkit_tool['label']); ?></span>
                            <span class="aipkit_form-label-helper"><?php echo esc_html($aipkit_tool['description']); ?></span>
                        </label>
                    </div>
                    <label class="aipkit_switch aipkit_settings_module_control" for="<?php echo esc_attr($aipkit_tool['field_id']); ?>">
                        <input
                            type="checkbox"
                            id="<?php echo esc_attr($aipkit_tool['field_id']); ?>"
                            name="<?php echo esc_attr($aipkit_tool['name']); ?>"
                            class="<?php echo esc_attr($aipkit_tool['class']); ?>"
                            value="1"
                            <?php /* translators: %s: editor or content tool name. */ ?>
                            aria-label="<?php echo esc_attr(sprintf(__('Enable %s', 'gpt3-ai-content-generator'), $aipkit_tool['label'])); ?>"
                            <?php if ($aipkit_tool['special']): ?>
                                data-saved-value="<?php echo esc_attr($aipkit_tool['enabled'] ? '1' : '0'); ?>"
                            <?php endif; ?>
                            <?php checked($aipkit_tool['enabled']); ?>
                            <?php disabled(!$can_manage_modules); ?>
                        />
                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php // Visitor billing adds to the Usage page, not the menu, so it has its own group, last. ?>
    <?php if ($can_manage_modules && isset($aipkit_menu_modules['stats_viewer'])) : ?>
        <section class="aipkit_settings_part aipkit_settings_modules_section" aria-labelledby="aipkit_settings_modules_usage_title">
            <h4 class="aipkit_settings_modules_section_title" id="aipkit_settings_modules_usage_title">
                <?php esc_html_e('In Usage', 'gpt3-ai-content-generator'); ?>
            </h4>
            <p class="aipkit_settings_modules_section_hint"><?php esc_html_e('Extras on the Usage page.', 'gpt3-ai-content-generator'); ?></p>
            <div class="aipkit_settings_modules_list">
                <div class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_module_row" data-aipkit-settings-autosave-exclude="true">
                    <div class="aipkit_settings_module_identity">
                        <span class="aipkit_settings_module_icon dashicons dashicons-groups" aria-hidden="true"></span>
                        <label class="aipkit_form-label aipkit_settings_module_copy" for="aipkit_settings_visitor_billing_toggle">
                            <span class="aipkit_settings_module_title"><?php esc_html_e('Visitor billing', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit_form-label-helper"><?php esc_html_e('Sell credits to visitors. Turning it on also turns on Usage; turning it off doesn’t stop existing sales.', 'gpt3-ai-content-generator'); ?></span>
                        </label>
                    </div>
                    <label class="aipkit_switch aipkit_settings_module_control" for="aipkit_settings_visitor_billing_toggle">
                        <input type="checkbox" id="aipkit_settings_visitor_billing_toggle" class="aipkit_settings_visitor_billing_toggle" aria-label="<?php esc_attr_e('Show visitor billing controls in Usage', 'gpt3-ai-content-generator'); ?>" <?php checked($aipkit_visitor_billing_enabled); ?> />
                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
