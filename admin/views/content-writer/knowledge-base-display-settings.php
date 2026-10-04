<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
?>

<button
    type="button"
    class="aipkit_cw_context_options_trigger"
    id="aipkit_cw_kb_settings_trigger"
    data-aipkit-context-options-trigger
    aria-controls="aipkit_cw_context_options_modal"
    aria-haspopup="dialog"
    aria-expanded="false"
    aria-label="<?php esc_attr_e('Context options', 'gpt3-ai-content-generator'); ?>"
    title="<?php esc_attr_e('Context options', 'gpt3-ai-content-generator'); ?>"
>
    <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
</button>

<div
    class="aipkit-modal-overlay aipkit_cw_context_options_modal"
    id="aipkit_cw_context_options_modal"
    data-aipkit-context-options-modal
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content aipkit-modal-shell aipkit_cw_context_options_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_cw_context_options_modal_title"
    >
        <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_cw_context_options_modal_header">
            <div class="aipkit-modal-shell-intro">
                <h2 class="aipkit-modal-shell-title" id="aipkit_cw_context_options_modal_title">
                    <?php esc_html_e('Context options', 'gpt3-ai-content-generator'); ?>
                </h2>
            </div>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit-modal-shell-close"
                data-aipkit-context-options-close
                aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>
        <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_cw_context_options_modal_body">
            <div class="aipkit_popover_options_list">
                <div id="aipkit_cw_kb_embedding_section" hidden>
                    <div class="aipkit_popover_option_row aipkit_popover_option_row--force-divider aipkit_cw_context_embedding_row">
                        <div class="aipkit_popover_option_main">
                            <div class="aipkit_cw_settings_option_text">
                                <label class="aipkit_popover_option_label" for="aipkit_cw_vector_embedding_selection">
                                    <?php esc_html_e('Embedding', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <span class="aipkit_popover_option_helper">
                                    <?php esc_html_e('Provider and model used for embeddings.', 'gpt3-ai-content-generator'); ?>
                                </span>
                            </div>
                            <input
                                type="hidden"
                                id="aipkit_cw_vector_embedding_provider"
                                name="vector_embedding_provider"
                                value="<?php echo esc_attr($default_embedding_provider_key); ?>"
                            >
                            <input
                                type="hidden"
                                id="aipkit_cw_vector_embedding_model"
                                name="vector_embedding_model"
                                value=""
                            >
                            <select
                                id="aipkit_cw_vector_embedding_selection"
                                class="aipkit_autosave_trigger aipkit_popover_option_select aipkit_cw_context_options_control"
                                data-aipkit-universal-model-combined="1" data-aipkit-universal-model-capability="embeddings"
                                data-aipkit-provider-labels="<?php echo esc_attr(wp_json_encode($embedding_provider_options)); ?>"
                                aria-label="<?php esc_attr_e('Embedding provider and model', 'gpt3-ai-content-generator'); ?>"
                                disabled
                            >
                                <option value=""><?php esc_html_e('Loading embeddings...', 'gpt3-ai-content-generator'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="aipkit_popover_option_row">
                    <div class="aipkit_popover_option_main">
                        <div class="aipkit_cw_settings_option_text">
                            <label class="aipkit_popover_option_label" for="aipkit_cw_vector_store_top_k">
                                <?php esc_html_e('Results limit', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <span class="aipkit_popover_option_helper">
                                <?php esc_html_e('How many matches to use.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </div>
                        <input
                            type="number"
                            id="aipkit_cw_vector_store_top_k"
                            name="vector_store_top_k"
                            class="aipkit_form-input aipkit_autosave_trigger aipkit_popover_option_input aipkit_cw_context_options_control"
                            value="3"
                            min="1"
                            max="20"
                            step="1"
                        >
                    </div>
                </div>

                <div class="aipkit_popover_option_row">
                    <div class="aipkit_popover_option_main">
                        <div class="aipkit_cw_settings_option_text">
                            <label class="aipkit_popover_option_label" for="aipkit_cw_vector_store_confidence_threshold">
                                <?php esc_html_e('Confidence threshold', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <span class="aipkit_popover_option_helper">
                                <?php esc_html_e('Minimum confidence to include.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </div>
                        <input
                            type="number"
                            id="aipkit_cw_vector_store_confidence_threshold"
                            name="vector_store_confidence_threshold"
                            class="aipkit_form-input aipkit_autosave_trigger aipkit_popover_option_input aipkit_cw_context_options_control"
                            value="20"
                            min="0"
                            max="100"
                            step="1"
                        >
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
