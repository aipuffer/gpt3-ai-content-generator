<?php
/**
 * AIPKit Image Generator Module - Admin View.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="aipkit_container aipkit_module_image_generator aipkit_admin_ui" id="aipkit_image_generator_container">
    <div class="aipkit_container-body">
        <div class="aipkit_image_generator_workspace" id="aipkit_image_generator_workspace">
            <header class="aipkit_image_generator_page_header">
                <div class="aipkit_image_generator_header_copy">
                    <div class="aipkit_image_generator_header_title_row">
                        <h1 class="aipkit_image_generator_page_title"><?php esc_html_e('Image Generator', 'gpt3-ai-content-generator'); ?></h1>
                        <span id="aipkit_image_generator_status" class="aipkit_training_status aipkit_global_status_area" aria-live="polite"></span>
                    </div>
                    <p class="aipkit_image_generator_header_hint"><?php esc_html_e('Generate images and customize the frontend experience.', 'gpt3-ai-content-generator'); ?></p>
                </div>

                <div class="aipkit_image_generator_page_actions">
                    <div class="aipkit_image_generator_workspace_tools is-active" data-aipkit-image-generator-tools="generator">
                        <div class="aipkit_image_generator_top_bar">
                            <button
                                type="button"
                                id="aipkit_image_generator_default_shortcode_copy"
                                class="aipkit_image_generator_default_shortcode_copy"
                                data-shortcode='[aipkit_image_generator mode="both"]'
                                data-copied-label="<?php esc_attr_e('Copied', 'gpt3-ai-content-generator'); ?>"
                                title="<?php esc_attr_e('Copy shortcode', 'gpt3-ai-content-generator'); ?>"
                                aria-label="<?php esc_attr_e('Copy shortcode', 'gpt3-ai-content-generator'); ?>"
                            >
                                <span class="aipkit_image_generator_default_shortcode_content" aria-hidden="true">
                                    <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                                    <code class="aipkit_image_generator_default_shortcode_value">[aipkit_image_generator mode="both"]</code>
                                </span>
                                <span class="aipkit_image_generator_default_shortcode_success" aria-hidden="true">
                                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                    <?php esc_html_e('Copied', 'gpt3-ai-content-generator'); ?>
                                </span>
                                <span class="screen-reader-text aipkit_image_generator_default_shortcode_live" data-aipkit-shortcode-copy-live aria-live="polite"></span>
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="aipkit_image_generator_header_utility aipkit_image_generator_workspace_tab"
                        id="aipkit_image_generator_settings_tab"
                        aria-controls="aipkit_image_generator_settings_panel"
                        aria-selected="false"
                        data-aipkit-image-generator-tab="settings"
                        title="<?php esc_attr_e('Image Generator settings', 'gpt3-ai-content-generator'); ?>"
                        aria-label="<?php esc_attr_e('Image Generator settings', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                    </button>
                </div>
            </header>

            <div id="aipkit_image_generator_preview_panel" class="aipkit_image_generator_workspace_panel is-active" role="tabpanel" aria-label="<?php esc_attr_e('Image Generator preview', 'gpt3-ai-content-generator'); ?>">
                <div class="aipkit_image_generator_admin_preview_wrapper">
                    <?php
                    $aipkit_image_defaults = \WPAICG\AIPKit_Providers::get_new_feature_defaults();
                    // Visitor model restrictions do not limit the admin workspace.
                    echo do_shortcode(sprintf('[aipkit_image_generator history="true" mode="both" theme="light" allowed_models="" provider="%s" model="%s"]',
                        esc_attr($aipkit_image_defaults['image_provider']), esc_attr($aipkit_image_defaults['image_model'])));
                    ?>
                </div>
            </div>

            <div id="aipkit_image_generator_settings_panel" class="aipkit_image_generator_settings_panel aipkit_image_generator_workspace_panel" role="tabpanel" aria-labelledby="aipkit_image_generator_settings_tab" hidden>
                <button type="button" class="aipkit_image_generator_settings_back aipkit_image_generator_workspace_tab" data-aipkit-image-generator-tab="generator" aria-controls="aipkit_image_generator_preview_panel">
                    <?php esc_html_e('← Back to generator', 'gpt3-ai-content-generator'); ?>
                </button>
                <?php include __DIR__ . '/settings.php'; ?>
            </div>
        </div>
    </div>
</div>
