<?php
/**
 * Partial: Knowledge Base Settings
 *
 * Module-specific settings stay inline so every sub-tab follows the same
 * interaction model. Semantic Search is rendered as its own workspace tab.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables only.

$kb_is_pro = isset($is_pro)
    ? (bool) $is_pro
    : (class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan());
$kb_upgrade_url = admin_url('admin.php?page=wpaicg-pricing');

$kb_general_settings = get_option('aipkit_training_general_settings', [
    'hide_user_uploads' => true,
]);
$kb_hide_user_uploads = (bool) ($kb_general_settings['hide_user_uploads'] ?? true);

$kb_render_paid_panel = null;
$kb_paid_settings_path = WPAICG_PLUGIN_DIR . 'lib/views/knowledge-base/indexing-settings.php';
if ($kb_is_pro && file_exists($kb_paid_settings_path)) {
    $kb_render_paid_panel = include $kb_paid_settings_path;
}

$kb_render_pro_gate = static function ($title, $description, $icon) use ($kb_upgrade_url) {
    ?>
    <div class="aipkit_settings_kb_pro_gate">
        <span class="aipkit_settings_kb_pro_icon" aria-hidden="true">
            <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
        </span>
        <div class="aipkit_settings_kb_pro_content">
            <div class="aipkit_settings_kb_pro_title">
                <h2><?php echo esc_html($title); ?></h2>
                <span class="aipkit_settings_kb_pro_badge aipkit_pro_badge">
                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                    <?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?>
                </span>
            </div>
            <p><?php echo esc_html($description); ?></p>
            <a class="aipkit_settings_kb_pro_cta aipkit_pro_upgrade_button" href="<?php echo esc_url($kb_upgrade_url); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
            </a>
        </div>
    </div>
    <?php
};
?>
<div
    class="aipkit_settings_knowledge_base_page"
    id="aipkit_settings_knowledge_base_page"
    data-indexing-nonce="<?php echo esc_attr(wp_create_nonce('aipkit_ai_training_settings_nonce')); ?>"
    data-indexing-is-pro="<?php echo $kb_is_pro ? '1' : '0'; ?>"
>
    <div class="aipkit_settings_kb_tabs" role="tablist" aria-label="<?php esc_attr_e('Knowledge Base settings', 'gpt3-ai-content-generator'); ?>">
        <?php
        $kb_setting_tabs = [
            'chunking' => __('Chunking', 'gpt3-ai-content-generator'),
            'batches' => __('Batches', 'gpt3-ai-content-generator'),
            'content-rules' => __('Content rules', 'gpt3-ai-content-generator'),
            'visibility' => __('Visibility', 'gpt3-ai-content-generator'),
        ];
        foreach ($kb_setting_tabs as $kb_tab_key => $kb_tab_label) :
            $kb_tab_is_active = $kb_tab_key === 'chunking';
            $kb_tab_is_pro = in_array($kb_tab_key, ['chunking', 'batches', 'content-rules'], true);
            ?>
            <button
                type="button"
                class="aipkit_settings_kb_tab<?php echo $kb_tab_is_active ? ' aipkit_active' : ''; ?>"
                id="aipkit_settings_kb_tab_<?php echo esc_attr(str_replace('-', '_', $kb_tab_key)); ?>"
                role="tab"
                aria-selected="<?php echo $kb_tab_is_active ? 'true' : 'false'; ?>"
                aria-controls="aipkit_settings_kb_panel_<?php echo esc_attr(str_replace('-', '_', $kb_tab_key)); ?>"
                data-aipkit-kb-tab="<?php echo esc_attr($kb_tab_key); ?>"
                <?php echo $kb_tab_is_active ? '' : 'tabindex="-1"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute. ?>
            >
                <span><?php echo esc_html($kb_tab_label); ?></span>
                <?php if ($kb_tab_is_pro && !$kb_is_pro) : ?>
                    <span class="aipkit_settings_kb_tab_pro aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <section
        class="aipkit_settings_kb_tab_panel"
        id="aipkit_settings_kb_panel_chunking"
        role="tabpanel"
        aria-labelledby="aipkit_settings_kb_tab_chunking"
        data-aipkit-kb-tab-panel="chunking"
        data-aipkit-kb-section="chunking"
        data-aipkit-settings-autosave-exclude="true"
    >
        <?php if (!$kb_is_pro) : ?>
            <?php $kb_render_pro_gate(
                __('Chunking controls', 'gpt3-ai-content-generator'),
                __('Fine-tune chunk size, overlap, and indexing strategy per provider.', 'gpt3-ai-content-generator'),
                'dashicons-admin-settings'
            ); ?>
        <?php else : ?>
            <?php if ($kb_render_paid_panel) { $kb_render_paid_panel('chunking', $kb_general_settings); } ?>
        <?php endif; ?>
    </section>

    <section
        class="aipkit_settings_kb_tab_panel"
        id="aipkit_settings_kb_panel_batches"
        role="tabpanel"
        aria-labelledby="aipkit_settings_kb_tab_batches"
        data-aipkit-kb-tab-panel="batches"
        data-aipkit-kb-section="embedding-batches"
        data-aipkit-settings-autosave-exclude="true"
        hidden
    >
        <?php if (!$kb_is_pro) : ?>
            <?php $kb_render_pro_gate(
                __('Embedding batches', 'gpt3-ai-content-generator'),
                __('Control how many embeddings are processed in each provider request.', 'gpt3-ai-content-generator'),
                'dashicons-database'
            ); ?>
        <?php else : ?>
            <?php if ($kb_render_paid_panel) { $kb_render_paid_panel('batches', $kb_general_settings); } ?>
        <?php endif; ?>
    </section>

    <section
        class="aipkit_settings_kb_tab_panel"
        id="aipkit_settings_kb_panel_content_rules"
        role="tabpanel"
        aria-labelledby="aipkit_settings_kb_tab_content_rules"
        data-aipkit-kb-tab-panel="content-rules"
        data-aipkit-kb-section="indexing-controls"
        data-aipkit-settings-autosave-exclude="true"
        hidden
    >
        <?php if (!$kb_is_pro) : ?>
            <?php $kb_render_pro_gate(
                __('Content rules', 'gpt3-ai-content-generator'),
                __('Choose which WordPress fields and labels are embedded as knowledge.', 'gpt3-ai-content-generator'),
                'dashicons-filter'
            ); ?>
        <?php else : ?>
            <?php
            if ($kb_render_paid_panel) { $kb_render_paid_panel('content-rules', $kb_general_settings); }
            ?>
        <?php endif; ?>
    </section>

    <section
        class="aipkit_settings_kb_tab_panel"
        id="aipkit_settings_kb_panel_visibility"
        role="tabpanel"
        aria-labelledby="aipkit_settings_kb_tab_visibility"
        data-aipkit-kb-tab-panel="visibility"
        data-aipkit-kb-section="visibility"
        hidden
    >
        <div class="aipkit_settings_kb_card aipkit_settings_kb_card--visibility">
            <div class="aipkit_settings_kb_section_intro">
                <h2><?php esc_html_e('Visibility', 'gpt3-ai-content-generator'); ?></h2>
                <p><?php esc_html_e('Control which sources appear in the Data table.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <div class="aipkit_settings_kb_rows aipkit_settings_kb_rows--visibility">
                <div class="aipkit_form-group aipkit_settings_simple_row" id="aipkit_settings_kb_show_uploads_row">
                    <label class="aipkit_form-label" for="aipkit_show_user_uploads">
                        <span><?php esc_html_e('Show user uploads in Data', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_form-label-helper"><?php esc_html_e('Include files uploaded by chatbot users in the Data table.', 'gpt3-ai-content-generator'); ?></span>
                    </label>
                    <label class="aipkit_switch" for="aipkit_show_user_uploads">
                        <input type="checkbox" id="aipkit_show_user_uploads" name="show_user_uploads" value="1" <?php checked(!$kb_hide_user_uploads); ?>>
                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
        </div>
    </section>
</div>
