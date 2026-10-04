<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div
    class="aipkit-modal-overlay aipkit_cw_advanced_options_modal"
    id="aipkit_cw_advanced_options_modal"
    data-aipkit-advanced-options-modal
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content aipkit-modal-shell aipkit_cw_advanced_options_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_cw_advanced_options_modal_title"
    >
        <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_cw_advanced_options_modal_header">
            <div class="aipkit-modal-shell-intro">
                <h2 class="aipkit-modal-shell-title" id="aipkit_cw_advanced_options_modal_title">
                    <?php esc_html_e('Advanced settings', 'gpt3-ai-content-generator'); ?>
                </h2>
            </div>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit-modal-shell-close"
                data-aipkit-advanced-options-close
                aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>
        <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_cw_advanced_options_modal_body">
            <?php include __DIR__ . '/ai-advanced-settings.php'; ?>
        </div>
    </div>
</div>
