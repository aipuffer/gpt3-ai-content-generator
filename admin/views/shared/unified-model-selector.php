<?php
/**
 * Shared searchable model selector.
 *
 * Expected configuration in $aipkit_unified_model_selector_config:
 * - trigger_id (string)
 * - initial_label (string)
 * - source_id (string, optional)
 * - class_name (string, optional)
 * - context_label (string, optional)
 * - trigger_aria_label (string, optional)
 * - capability (string, optional)
 * - popover_placement (string 'auto'|'top', optional; defaults to 'auto')
 * - show_trigger_logo (bool, optional; defaults to true)
 * - show_provider_diagnostics (bool, optional; defaults to true)
 * - search_placeholder (string, optional)
 * - empty_text (string, optional)
 * - filters (array, optional; each item has value and label)
 * - filter_aria_label (string, optional)
 * - manage_url (string, optional)
 * - show_manage_link (bool, optional; defaults to true)
 */

if (!defined('ABSPATH')) {
    exit;
}

$aipkit_unified_model_selector_config = isset($aipkit_unified_model_selector_config) && is_array($aipkit_unified_model_selector_config)
    ? $aipkit_unified_model_selector_config
    : [];
$aipkit_unified_model_trigger_id = isset($aipkit_unified_model_selector_config['trigger_id'])
    ? (string) $aipkit_unified_model_selector_config['trigger_id']
    : 'aipkit_unified_model_trigger';
$aipkit_unified_model_popover_id = $aipkit_unified_model_trigger_id . '_popover';
$aipkit_unified_model_initial_label = isset($aipkit_unified_model_selector_config['initial_label'])
    ? (string) $aipkit_unified_model_selector_config['initial_label']
    : __('Select model', 'gpt3-ai-content-generator');
$aipkit_unified_model_source_id = isset($aipkit_unified_model_selector_config['source_id'])
    ? (string) $aipkit_unified_model_selector_config['source_id']
    : '';
$aipkit_unified_model_class_name = isset($aipkit_unified_model_selector_config['class_name'])
    ? trim((string) $aipkit_unified_model_selector_config['class_name'])
    : '';
$aipkit_unified_model_context_label = isset($aipkit_unified_model_selector_config['context_label'])
    ? (string) $aipkit_unified_model_selector_config['context_label']
    : '';
$aipkit_unified_model_trigger_aria_label = isset($aipkit_unified_model_selector_config['trigger_aria_label'])
    ? (string) $aipkit_unified_model_selector_config['trigger_aria_label']
    : '';
$aipkit_unified_model_capability = isset($aipkit_unified_model_selector_config['capability'])
    ? sanitize_key((string) $aipkit_unified_model_selector_config['capability'])
    : '';
$aipkit_unified_model_popover_placement = isset($aipkit_unified_model_selector_config['popover_placement'])
    && (string) $aipkit_unified_model_selector_config['popover_placement'] === 'top'
    ? 'top'
    : 'auto';
$aipkit_unified_model_show_trigger_logo = !isset($aipkit_unified_model_selector_config['show_trigger_logo'])
    || (bool) $aipkit_unified_model_selector_config['show_trigger_logo'];
$aipkit_unified_model_show_provider_diagnostics = !isset($aipkit_unified_model_selector_config['show_provider_diagnostics'])
    || (bool) $aipkit_unified_model_selector_config['show_provider_diagnostics'];
$aipkit_unified_model_search_placeholder = isset($aipkit_unified_model_selector_config['search_placeholder'])
    ? (string) $aipkit_unified_model_selector_config['search_placeholder']
    : __('Search models...', 'gpt3-ai-content-generator');
$aipkit_unified_model_empty_text = isset($aipkit_unified_model_selector_config['empty_text'])
    ? (string) $aipkit_unified_model_selector_config['empty_text']
    : __('No models found', 'gpt3-ai-content-generator');
$aipkit_unified_model_filters = isset($aipkit_unified_model_selector_config['filters'])
    && is_array($aipkit_unified_model_selector_config['filters'])
    ? array_values(array_filter(
        $aipkit_unified_model_selector_config['filters'],
        static function ($aipkit_filter): bool {
            return is_array($aipkit_filter)
                && isset($aipkit_filter['value'], $aipkit_filter['label'])
                && (string) $aipkit_filter['value'] !== '';
        }
    ))
    : [];
$aipkit_unified_model_filter_aria_label = isset($aipkit_unified_model_selector_config['filter_aria_label'])
    ? (string) $aipkit_unified_model_selector_config['filter_aria_label']
    : __('Filter models', 'gpt3-ai-content-generator');
$aipkit_unified_model_manage_url = isset($aipkit_unified_model_selector_config['manage_url'])
    ? (string) $aipkit_unified_model_selector_config['manage_url']
    : admin_url('admin.php?page=wpaicg&aipkit_module=settings&aipkit_settings_page=ai');
$aipkit_unified_model_show_manage_link = !isset($aipkit_unified_model_selector_config['show_manage_link'])
    || (bool) $aipkit_unified_model_selector_config['show_manage_link'];
?>
<div
    class="aipkit_unified_model_selector<?php echo $aipkit_unified_model_class_name !== '' ? ' ' . esc_attr($aipkit_unified_model_class_name) : ''; ?>"
    data-aipkit-unified-model-selector
    <?php echo $aipkit_unified_model_source_id !== '' ? 'data-aipkit-unified-model-source-id="' . esc_attr($aipkit_unified_model_source_id) . '"' : ''; ?>
    <?php echo $aipkit_unified_model_capability !== '' ? 'data-aipkit-model-capability="' . esc_attr($aipkit_unified_model_capability) . '"' : ''; ?>
    data-aipkit-popover-placement="<?php echo esc_attr($aipkit_unified_model_popover_placement); ?>"
    data-aipkit-provider-diagnostics="<?php echo $aipkit_unified_model_show_provider_diagnostics ? '1' : '0'; ?>"
    data-aipkit-show-manage-link="<?php echo $aipkit_unified_model_show_manage_link ? '1' : '0'; ?>"
>
    <button
        type="button"
        id="<?php echo esc_attr($aipkit_unified_model_trigger_id); ?>"
        class="aipkit_unified_model_trigger"
        aria-expanded="false"
        aria-controls="<?php echo esc_attr($aipkit_unified_model_popover_id); ?>"
        aria-haspopup="dialog"
        <?php echo $aipkit_unified_model_trigger_aria_label !== '' ? 'aria-label="' . esc_attr($aipkit_unified_model_trigger_aria_label) . '"' : ''; ?>
        data-aipkit-unified-model-trigger
        <?php echo $aipkit_unified_model_context_label !== '' ? 'data-aipkit-model-context-label="' . esc_attr($aipkit_unified_model_context_label) . '"' : ''; ?>
    >
        <?php if ($aipkit_unified_model_show_trigger_logo) : ?>
            <span class="aipkit_unified_model_logo" data-aipkit-unified-model-logo aria-hidden="true"></span>
        <?php endif; ?>
        <span class="aipkit_unified_model_name" data-aipkit-unified-model-name><?php echo esc_html($aipkit_unified_model_initial_label); ?></span>
    </button>
    <div
        id="<?php echo esc_attr($aipkit_unified_model_popover_id); ?>"
        class="aipkit_unified_model_popover"
        data-aipkit-unified-model-popover
        role="dialog"
        aria-label="<?php esc_attr_e('Choose an AI model', 'gpt3-ai-content-generator'); ?>"
        hidden
    >
        <div class="aipkit_unified_model_panel" data-aipkit-unified-model-panel>
            <span class="aipkit_unified_model_sheet_handle" aria-hidden="true"></span>
            <div class="aipkit_unified_model_search_bar">
                <div class="aipkit_unified_model_search">
                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <input
                        type="search"
                        class="aipkit_unified_model_search_input"
                        placeholder="<?php echo esc_attr($aipkit_unified_model_search_placeholder); ?>"
                        aria-label="<?php echo esc_attr($aipkit_unified_model_search_placeholder); ?>"
                        autocomplete="off"
                        data-aipkit-unified-model-search
                    />
                    <kbd aria-hidden="true">⌘K</kbd>
                </div>
            </div>
            <div class="aipkit_unified_model_body">
                <div
                    class="aipkit_unified_model_providers"
                    role="listbox"
                    aria-label="<?php esc_attr_e('AI providers', 'gpt3-ai-content-generator'); ?>"
                    data-aipkit-unified-model-providers
                ></div>
                <div class="aipkit_unified_model_results">
                    <?php if ($aipkit_unified_model_show_provider_diagnostics) : ?>
                        <div
                            class="aipkit_unified_model_provider_notice"
                            data-aipkit-unified-model-provider-notice
                            role="status"
                            aria-live="polite"
                            hidden
                        >
                            <span class="aipkit_unified_model_provider_notice_icon" aria-hidden="true"></span>
                            <span class="aipkit_unified_model_provider_notice_text" data-aipkit-unified-model-provider-notice-text></span>
                            <button
                                type="button"
                                class="aipkit_unified_model_provider_notice_action"
                                data-aipkit-unified-model-provider-action
                                hidden
                            ></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($aipkit_unified_model_filters !== []) : ?>
                        <div
                            class="aipkit_unified_model_filters"
                            role="group"
                            aria-label="<?php echo esc_attr($aipkit_unified_model_filter_aria_label); ?>"
                            data-aipkit-unified-model-filters
                        >
                            <?php foreach ($aipkit_unified_model_filters as $aipkit_filter_index => $aipkit_filter) : ?>
                                <button
                                    type="button"
                                    class="aipkit_unified_model_filter<?php echo $aipkit_filter_index === 0 ? ' is-active' : ''; ?>"
                                    data-aipkit-unified-model-filter="<?php echo esc_attr((string) $aipkit_filter['value']); ?>"
                                    aria-pressed="<?php echo $aipkit_filter_index === 0 ? 'true' : 'false'; ?>"
                                ><?php echo esc_html((string) $aipkit_filter['label']); ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="aipkit_unified_model_list" role="list" data-aipkit-unified-model-list></div>
                    <div class="aipkit_unified_model_empty" data-aipkit-unified-model-empty hidden>
                        <?php echo esc_html($aipkit_unified_model_empty_text); ?>
                    </div>
                </div>
            </div>
            <div class="aipkit_unified_model_footer">
                <span class="aipkit_unified_model_keyboard_hint" aria-hidden="true">
                    <span>↑↓</span> <?php esc_html_e('navigate', 'gpt3-ai-content-generator'); ?>
                    <span>↵</span> <?php esc_html_e('select', 'gpt3-ai-content-generator'); ?>
                </span>
                <span class="aipkit_unified_model_summary" data-aipkit-unified-model-summary aria-live="polite"></span>
                <?php if ($aipkit_unified_model_show_manage_link) : ?>
                    <a
                        class="aipkit_unified_model_manage_link"
                        href="<?php echo esc_url($aipkit_unified_model_manage_url); ?>"
                        data-aipkit-open-module="settings"
                        data-aipkit-settings-page="ai"
                    >
                        <?php esc_html_e('Manage providers', 'gpt3-ai-content-generator'); ?>
                        <span aria-hidden="true">↗</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
