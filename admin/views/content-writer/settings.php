<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

// Load shared variables used by the partials
require_once __DIR__ . '/view-data.php';
include __DIR__ . '/prompt-field-renderer.php';

$content_length_options = [
    'short' => __('Short', 'gpt3-ai-content-generator'),
    'medium' => __('Medium', 'gpt3-ai-content-generator'),
    'long' => __('Long', 'gpt3-ai-content-generator'),
];

?>
<div class="aipkit_cw_inspector_stack">
    <section class="aipkit_cw_inspector_card aipkit_cw_inspector_card--run">
        <div class="aipkit_cw_inspector_card_body aipkit_cw_inspector_card_body--run" data-aipkit-cw-inline-prompts>
            <section
                class="aipkit_cw_inspector_disclosure aipkit_cw_inspector_disclosure--core"
                data-aipkit-cw-inspector-disclosure
                data-aipkit-cw-mobile-core-disclosure
            >
                <button
                    type="button"
                    class="aipkit_cw_inspector_disclosure_toggle aipkit_cw_inspector_disclosure_toggle--mobile-core"
                    data-aipkit-cw-inspector-disclosure-toggle
                    aria-expanded="true"
                    aria-controls="aipkit_cw_inspector_core_panel"
                >
                    <span><?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_cw_inspector_disclosure_icon" aria-hidden="true"></span>
                </button>
                <div
                    id="aipkit_cw_inspector_core_panel"
                    class="aipkit_cw_inspector_disclosure_panel aipkit_cw_inspector_disclosure_panel--core"
                    data-aipkit-cw-inspector-disclosure-panel
                >
                    <?php include __DIR__ . '/template-controls.php'; ?>
                    <?php include __DIR__ . '/ai-settings.php'; ?>
                    <div class="aipkit_cw_ai_row">
                        <div class="aipkit_cw_panel_label_wrap">
                            <label class="aipkit_cw_panel_label" for="aipkit_content_writer_content_length">
                                <?php esc_html_e('Length', 'gpt3-ai-content-generator'); ?>
                            </label>
                        </div>
                        <div class="aipkit_cw_ai_control aipkit_cw_ai_control--compact">
                            <select
                                id="aipkit_content_writer_content_length"
                                name="content_length"
                                class="aipkit_autosave_trigger aipkit_form-input aipkit_cw_blended_chevron_select"
                                data-aipkit-cw-fit-selected
                            >
                                <?php foreach ($content_length_options as $content_length_value => $content_length_label) : ?>
                                    <option value="<?php echo esc_attr($content_length_value); ?>" <?php selected($content_length_value, 'medium'); ?>>
                                        <?php echo esc_html($content_length_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php include __DIR__ . '/status-settings.php'; ?>
                </div>
            </section>

            <section
                class="aipkit_cw_inspector_disclosure aipkit_cw_inspector_disclosure--prompts"
                data-aipkit-cw-inspector-disclosure
                data-aipkit-cw-mode-section="prompts"
            >
                <div
                    class="aipkit_cw_existing_fields_heading"
                    data-aipkit-cw-existing-fields-heading
                    role="heading"
                    aria-level="3"
                    hidden
                >
                    <?php esc_html_e('Fields to update', 'gpt3-ai-content-generator'); ?>
                </div>
                <button
                    type="button"
                    class="aipkit_cw_inspector_disclosure_toggle"
                    data-aipkit-cw-inspector-disclosure-toggle
                    aria-expanded="false"
                    aria-controls="aipkit_cw_inspector_prompts_panel"
                >
                    <span><?php esc_html_e('Prompts', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_cw_inspector_disclosure_icon" aria-hidden="true"></span>
                </button>
                <div
                    id="aipkit_cw_inspector_prompts_panel"
                    class="aipkit_cw_inspector_disclosure_panel aipkit_cw_inspector_disclosure_panel--prompts"
                    data-aipkit-cw-inspector-disclosure-panel
                    hidden
                >
                    <?php include __DIR__ . '/prompts-settings.php'; ?>
                </div>
            </section>

            <section
                class="aipkit_cw_inspector_disclosure aipkit_cw_inspector_disclosure--seo"
                data-aipkit-cw-inspector-disclosure
                data-aipkit-cw-mode-section="seo"
            >
                <button
                    type="button"
                    class="aipkit_cw_inspector_disclosure_toggle"
                    data-aipkit-cw-inspector-disclosure-toggle
                    aria-expanded="false"
                    aria-controls="aipkit_cw_inspector_seo_panel"
                >
                    <span><?php esc_html_e('SEO', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_cw_inspector_disclosure_icon" aria-hidden="true"></span>
                </button>
                <div
                    id="aipkit_cw_inspector_seo_panel"
                    class="aipkit_cw_inspector_disclosure_panel"
                    data-aipkit-cw-inspector-disclosure-panel
                    hidden
                >
                    <?php include __DIR__ . '/seo-settings.php'; ?>
                </div>
            </section>

            <section
                class="aipkit_cw_inspector_disclosure aipkit_cw_inspector_disclosure--images"
                data-aipkit-cw-inspector-disclosure
                data-aipkit-cw-mode-section="images"
            >
                <div
                    class="aipkit_cw_existing_fields_heading"
                    data-aipkit-cw-existing-image-fields-heading
                    role="heading"
                    aria-level="3"
                    hidden
                >
                    <?php esc_html_e('Fields to update', 'gpt3-ai-content-generator'); ?>
                </div>
                <button
                    type="button"
                    class="aipkit_cw_inspector_disclosure_toggle"
                    data-aipkit-cw-inspector-disclosure-toggle
                    aria-expanded="false"
                    aria-controls="aipkit_cw_inspector_images_panel"
                >
                    <span><?php esc_html_e('Images', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_cw_inspector_disclosure_icon" aria-hidden="true"></span>
                </button>
                <div
                    id="aipkit_cw_inspector_images_panel"
                    class="aipkit_cw_inspector_disclosure_panel"
                    data-aipkit-cw-inspector-disclosure-panel
                    hidden
                >
                    <div class="aipkit_cw_inspector_card--media">
                        <?php include __DIR__ . '/image-settings.php'; ?>
                    </div>
                </div>
            </section>

            <section
                class="aipkit_cw_inspector_disclosure aipkit_cw_inspector_disclosure--context"
                data-aipkit-cw-inspector-disclosure
                data-aipkit-cw-mode-section="context"
            >
                <button
                    type="button"
                    class="aipkit_cw_inspector_disclosure_toggle"
                    data-aipkit-cw-inspector-disclosure-toggle
                    aria-expanded="false"
                    aria-controls="aipkit_cw_inspector_context_panel"
                >
                    <span><?php esc_html_e('Context', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_cw_inspector_disclosure_icon" aria-hidden="true"></span>
                </button>
                <div
                    id="aipkit_cw_inspector_context_panel"
                    class="aipkit_cw_inspector_disclosure_panel"
                    data-aipkit-cw-inspector-disclosure-panel
                    hidden
                >
                    <?php include __DIR__ . '/vector-settings.php'; ?>
                </div>
            </section>

            <section
                class="aipkit_cw_inspector_disclosure aipkit_cw_publishing_panel aipkit_post_settings_redesigned"
                data-aipkit-cw-inspector-disclosure
                data-aipkit-cw-mode-section="publishing"
            >
                <button
                    type="button"
                    class="aipkit_cw_inspector_disclosure_toggle"
                    data-aipkit-cw-inspector-disclosure-toggle
                    aria-expanded="false"
                    aria-controls="aipkit_cw_inspector_publishing_panel"
                >
                    <span><?php esc_html_e('Publishing', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_cw_inspector_disclosure_icon" aria-hidden="true"></span>
                </button>
                <div
                    id="aipkit_cw_inspector_publishing_panel"
                    class="aipkit_cw_inspector_disclosure_panel"
                    data-aipkit-cw-inspector-disclosure-panel
                    hidden
                >
                    <?php include __DIR__ . '/publishing-settings.php'; ?>
                </div>
            </section>

        </div>
    </section>
</div>
<?php include __DIR__ . '/prompt-editor-modal.php'; ?>
