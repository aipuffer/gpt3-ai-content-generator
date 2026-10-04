<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div
    class="aipkit-modal-overlay aipkit_cw_prompt_editor_modal"
    id="aipkit_cw_prompt_editor_modal"
    data-aipkit-cw-prompt-editor-modal
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content aipkit-modal-shell aipkit_cw_prompt_editor_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_cw_prompt_editor_modal_title"
        aria-describedby="aipkit_cw_prompt_editor_modal_description"
    >
        <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_cw_prompt_editor_modal_header">
            <div class="aipkit-modal-shell-intro">
                <h2 class="aipkit-modal-shell-title" id="aipkit_cw_prompt_editor_modal_title">
                    <?php esc_html_e('Prompt editor', 'gpt3-ai-content-generator'); ?>
                </h2>
                <p class="aipkit-modal-shell-copy" id="aipkit_cw_prompt_editor_modal_description">
                    <?php esc_html_e('Edit the full prompt in a larger view.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit-modal-shell-close"
                data-aipkit-cw-prompt-editor-close
                aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>
        <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_cw_prompt_editor_modal_body">
            <textarea
                class="aipkit_cw_prompt_editor_modal_textarea"
                rows="14"
                data-aipkit-cw-prompt-editor-textarea
                aria-label="<?php esc_attr_e('Prompt', 'gpt3-ai-content-generator'); ?>"
            ></textarea>
        </div>
        <div class="aipkit-modal-footer aipkit_cw_prompt_editor_modal_footer">
            <span class="aipkit_cw_prompt_editor_count" data-aipkit-cw-prompt-editor-count aria-live="polite">
                <?php esc_html_e('0 characters', 'gpt3-ai-content-generator'); ?>
            </span>
            <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-cw-prompt-editor-close>
                <?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?>
            </button>
        </div>
    </div>
</div>
