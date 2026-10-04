<?php
/**
 * Shared source editor modal used by Knowledge Base and chatbot sources.
 *
 * @package WPAICG
 */

defined('ABSPATH') || exit;
?>
<div
    class="aipkit-modal-overlay aipkit_sources_modal aipkit_builder_sources_editor_modal"
    id="aipkit_sources_editor_modal"
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content aipkit_sources_modal_panel aipkit_sources_editor_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_sources_editor_title"
        aria-describedby="aipkit_sources_editor_description"
    >
        <div class="aipkit-modal-header aipkit_sources_modal_header">
            <div class="aipkit_sources_modal_heading">
                <h2 class="aipkit-modal-title aipkit_sources_modal_title" id="aipkit_sources_editor_title">
                    <?php esc_html_e('Edit source', 'gpt3-ai-content-generator'); ?>
                </h2>
                <p class="aipkit_builder_modal_subtitle aipkit_sources_modal_subtitle aipkit_sources_editor_subtitle" id="aipkit_sources_editor_description">
                    <?php esc_html_e('Update the source text and save to retrain this entry.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit_sources_modal_close aipkit_sources_editor_close"
                aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>

        <div class="aipkit-modal-body aipkit_sources_modal_body aipkit_sources_editor_body">
            <div class="aipkit_sources_editor_panel aipkit_sources_editor_panel--text">
                <div class="aipkit_training_field">
                    <div class="aipkit_training_field_heading">
                        <label class="aipkit_training_field_label" for="aipkit_sources_editor_text">
                            <?php esc_html_e('Text', 'gpt3-ai-content-generator'); ?>
                        </label>
                    </div>
                    <textarea
                        id="aipkit_sources_editor_text"
                        class="aipkit_builder_textarea aipkit_sources_editor_textarea"
                        rows="8"
                    ></textarea>
                </div>
            </div>

            <div class="aipkit_sources_editor_panel aipkit_sources_editor_panel--qa" hidden>
                <div class="aipkit_training_field">
                    <div class="aipkit_training_field_heading">
                        <label class="aipkit_training_field_label" for="aipkit_sources_editor_question">
                            <?php esc_html_e('Question', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <span class="aipkit_training_field_help">
                            <?php esc_html_e('What visitors may ask.', 'gpt3-ai-content-generator'); ?>
                        </span>
                    </div>
                    <textarea
                        id="aipkit_sources_editor_question"
                        class="aipkit_builder_textarea aipkit_sources_editor_question"
                        rows="3"
                    ></textarea>
                </div>
                <div class="aipkit_training_field">
                    <div class="aipkit_training_field_heading">
                        <label class="aipkit_training_field_label" for="aipkit_sources_editor_answer">
                            <?php esc_html_e('Answer', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <span class="aipkit_training_field_help">
                            <?php esc_html_e('The response your chatbot gives.', 'gpt3-ai-content-generator'); ?>
                        </span>
                    </div>
                    <textarea
                        id="aipkit_sources_editor_answer"
                        class="aipkit_builder_textarea aipkit_sources_editor_answer"
                        rows="4"
                    ></textarea>
                </div>
            </div>

            <p class="aipkit_sources_editor_caption">
                <?php esc_html_e('Saving will re-index this entry in your vector store.', 'gpt3-ai-content-generator'); ?>
            </p>
        </div>

        <div class="aipkit_sources_modal_footer aipkit_sources_editor_footer">
            <div class="aipkit_builder_action_row aipkit_sources_editor_actions">
                <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_editor_cancel">
                    <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                </button>
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_sources_editor_save" disabled>
                    <?php esc_html_e('Save and retrain', 'gpt3-ai-content-generator'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
