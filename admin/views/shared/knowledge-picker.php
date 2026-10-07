<?php
/**
 * Shared knowledge picker: one control for where knowledge is stored, in the model picker's style.
 *
 * It reads and writes the standard vector store fields beside it (vector_store_provider and each
 * provider's store select), so saving stays with the screen that renders it.
 *
 * Expected configuration in $aipkit_knowledge_picker_config:
 * - id (string)
 */

if (!defined('ABSPATH')) {
    exit;
}

$aipkit_knowledge_picker_config = isset($aipkit_knowledge_picker_config) && is_array($aipkit_knowledge_picker_config)
    ? $aipkit_knowledge_picker_config
    : [];
$aipkit_knowledge_picker_id = isset($aipkit_knowledge_picker_config['id'])
    ? sanitize_html_class((string) $aipkit_knowledge_picker_config['id'])
    : 'aipkit_knowledge_picker';
$aipkit_knowledge_picker_popover_id = $aipkit_knowledge_picker_id . '_popover';
?>
<div
    class="aipkit_knowledge_picker"
    data-aipkit-knowledge-picker
    data-manage-url="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=sources')); ?>"
    data-connect-url="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=settings&aipkit_settings_page=integrations')); ?>"
>
    <button
        type="button"
        id="<?php echo esc_attr($aipkit_knowledge_picker_id); ?>"
        class="aipkit_knowledge_picker_trigger"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="<?php echo esc_attr($aipkit_knowledge_picker_popover_id); ?>"
        data-aipkit-knowledge-picker-trigger
    >
        <span class="aipkit_knowledge_picker_icon dashicons dashicons-database" aria-hidden="true"></span>
        <span class="aipkit_knowledge_picker_copy">
            <span class="aipkit_knowledge_picker_name" data-aipkit-knowledge-picker-name><?php esc_html_e('Choose where knowledge is stored', 'gpt3-ai-content-generator'); ?></span>
            <span class="aipkit_knowledge_picker_meta" data-aipkit-knowledge-picker-meta></span>
        </span>
        <span class="aipkit_knowledge_picker_chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
    </button>
    <div
        id="<?php echo esc_attr($aipkit_knowledge_picker_popover_id); ?>"
        class="aipkit_unified_model_popover aipkit_knowledge_picker_popover"
        data-aipkit-knowledge-picker-popover
        role="dialog"
        aria-label="<?php esc_attr_e('Choose where knowledge is stored', 'gpt3-ai-content-generator'); ?>"
        hidden
    >
        <div class="aipkit_unified_model_panel">
            <div class="aipkit_unified_model_search_bar">
                <div class="aipkit_unified_model_search">
                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <input
                        type="search"
                        class="aipkit_unified_model_search_input"
                        placeholder="<?php esc_attr_e('Search knowledge bases...', 'gpt3-ai-content-generator'); ?>"
                        aria-label="<?php esc_attr_e('Search knowledge bases', 'gpt3-ai-content-generator'); ?>"
                        autocomplete="off"
                        data-aipkit-knowledge-picker-search
                    />
                </div>
            </div>
            <div class="aipkit_unified_model_body">
                <div
                    class="aipkit_unified_model_providers"
                    role="listbox"
                    aria-label="<?php esc_attr_e('Knowledge providers', 'gpt3-ai-content-generator'); ?>"
                    data-aipkit-knowledge-picker-providers
                ></div>
                <div class="aipkit_unified_model_results">
                    <div class="aipkit_unified_model_list" role="list" data-aipkit-knowledge-picker-list></div>
                </div>
            </div>
            <div class="aipkit_unified_model_footer">
                <span class="aipkit_unified_model_summary" data-aipkit-knowledge-picker-hint aria-live="polite"></span>
                <a
                    class="aipkit_unified_model_manage_link"
                    href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=sources')); ?>"
                    data-aipkit-open-module="sources"
                >
                    <?php esc_html_e('Manage knowledge bases', 'gpt3-ai-content-generator'); ?>
                    <span aria-hidden="true">↗</span>
                </a>
            </div>
        </div>
    </div>
</div>
