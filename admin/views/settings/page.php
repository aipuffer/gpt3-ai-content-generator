<?php
/**
 * AIPKit Global Settings Module
 */

use WPAICG\AIPKIT_AI_Settings;
use WPAICG\AIPKit_Providers;
use WPAICG\aipkit_dashboard;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

// Shared by the provider and integration credential cards.
$aipkit_format_credential_mask = static function (string $credential): string {
    $length = strlen($credential);
    $fixed_mask = str_repeat('•', 12);

    if ($length < 9) {
        return $fixed_mask;
    }

    $prefix_length = $length >= 16 ? 8 : 3;
    $suffix_length = $length >= 16 ? 4 : 3;

    return substr($credential, 0, $prefix_length)
        . $fixed_mask
        . substr($credential, -$suffix_length);
};

// --- Variable Definitions ---
$aipkit_options = get_option('aipkit_options', array());
$aipkit_options = is_array($aipkit_options) ? $aipkit_options : [];

// Force the "providers" array to exist
AIPKit_Providers::get_all_providers();
$current_provider = AIPKit_Providers::get_current_provider();
$main_provider_allowlist = AIPKit_Providers::get_main_provider_allowlist();

$ai_params = AIPKIT_AI_Settings::get_ai_parameters();
$all_api_keys = AIPKIT_AI_Settings::get_api_keys();
$public_api_key = $all_api_keys['public_api_key'] ?? '';
$public_api_enabled = (string) ($all_api_keys['public_api_enabled'] ?? '0') === '1';

$is_pro = class_exists('\WPAICG\aipkit_dashboard') && aipkit_dashboard::is_pro_plan();
$module_settings = aipkit_dashboard::get_module_settings();
$can_manage_modules = AIPKit_Role_Manager::user_can_manage_settings();

$openai_data     = AIPKit_Providers::get_provider_data('OpenAI');
$openrouter_data = AIPKit_Providers::get_provider_data('OpenRouter');
$google_data     = AIPKit_Providers::get_provider_data('Google');
$azure_data      = AIPKit_Providers::get_provider_data('Azure');
$claude_data     = AIPKit_Providers::get_provider_data('Claude');
$deepseek_data   = AIPKit_Providers::get_provider_data('DeepSeek');
$xai_data        = AIPKit_Providers::get_provider_data('xAI');
$ollama_data     = AIPKit_Providers::get_provider_data('Ollama');
$elevenlabs_data = AIPKit_Providers::get_provider_data('ElevenLabs');
$replicate_data  = AIPKit_Providers::get_provider_data('Replicate');
$pexels_data     = AIPKit_Providers::get_provider_data('Pexels');
$pixabay_data    = AIPKit_Providers::get_provider_data('Pixabay');
$pinecone_data   = AIPKit_Providers::get_provider_data('Pinecone');
$qdrant_data     = AIPKit_Providers::get_provider_data('Qdrant');
$chroma_data     = AIPKit_Providers::get_provider_data('Chroma');

$elevenlabs_voice_list = AIPKit_Providers::get_elevenlabs_voices();
$elevenlabs_model_list = AIPKit_Providers::get_elevenlabs_models();
$replicate_model_list  = AIPKit_Providers::get_replicate_models();
$pinecone_index_list   = AIPKit_Providers::get_pinecone_indexes();
$qdrant_collection_list = AIPKit_Providers::get_qdrant_collections();
$chroma_collection_list = AIPKit_Providers::get_chroma_collections();
$image_generator_settings = class_exists(AIPKit_Image_Settings_Ajax_Handler::class)
    ? AIPKit_Image_Settings_Ajax_Handler::get_settings()
    : [];
$replicate_image_settings = isset($image_generator_settings['replicate']) && is_array($image_generator_settings['replicate'])
    ? $image_generator_settings['replicate']
    : [];
$replicate_disable_safety_checker = array_key_exists('disable_safety_checker', $replicate_image_settings)
    ? (bool) $replicate_image_settings['disable_safety_checker']
    : true;

$temperature       = $ai_params['temperature'];
$top_p             = $ai_params['top_p'];

$openai_defaults     = AIPKit_Providers::get_provider_defaults('OpenAI');
$openrouter_defaults = AIPKit_Providers::get_provider_defaults('OpenRouter');
$google_defaults     = AIPKit_Providers::get_provider_defaults('Google');
$azure_defaults      = AIPKit_Providers::get_provider_defaults('Azure');
$claude_defaults     = AIPKit_Providers::get_provider_defaults('Claude');
$deepseek_defaults   = AIPKit_Providers::get_provider_defaults('DeepSeek');
$xai_defaults        = AIPKit_Providers::get_provider_defaults('xAI');
$ollama_defaults     = AIPKit_Providers::get_provider_defaults('Ollama');
$chroma_defaults     = AIPKit_Providers::get_provider_defaults('Chroma');

?>
<div class="aipkit_settings_main_container aipkit_admin_ui" id="aipkit_settings_container">
    <header class="aipkit_settings_module_header">
        <div class="aipkit_settings_header_copy">
            <div class="aipkit_settings_header_title_row">
                <h2 class="aipkit_container-title"><?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?></h2>
                <div
                    id="aipkit_settings_global_messages"
                    class="aipkit_settings_messages aipkit_global_status_area aipkit_settings_header_status"
                    role="status"
                    aria-live="polite"
                ></div>
            </div>
            <p class="aipkit_settings_header_hint"><?php esc_html_e('Configure AI defaults, integrations, apps, security, developer tools, and maintenance.', 'gpt3-ai-content-generator'); ?></p>
        </div>
    </header>
    <section class="aipkit_settings_pages_shell">
        <div class="aipkit_settings_page_nav_row">
            <nav
                class="aipkit_settings_page_nav"
                role="tablist"
                aria-label="<?php esc_attr_e('Settings sections', 'gpt3-ai-content-generator'); ?>"
            >
                <button
                    type="button"
                    class="aipkit_settings_page_nav_link is-active aipkit_active"
                    id="aipkit_settings_page_tab_ai"
                    role="tab"
                    aria-selected="true"
                    aria-controls="aipkit_settings_page_panel_ai"
                    data-aipkit-settings-page-link="ai"
                >
                    <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
                    <?php esc_html_e('AI', 'gpt3-ai-content-generator'); ?>
                </button>
                <button
                    type="button"
                    class="aipkit_settings_page_nav_link"
                    id="aipkit_settings_page_tab_modules"
                    role="tab"
                    aria-selected="false"
                    aria-controls="aipkit_settings_page_panel_modules"
                    data-aipkit-settings-page-link="modules"
                    tabindex="-1"
                >
                    <span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
                    <?php esc_html_e('Modules', 'gpt3-ai-content-generator'); ?>
                </button>
                <button
                    type="button"
                    class="aipkit_settings_page_nav_link"
                    id="aipkit_settings_page_tab_integrations"
                    role="tab"
                    aria-selected="false"
                    aria-controls="aipkit_settings_page_panel_integrations"
                    data-aipkit-settings-page-link="integrations"
                    tabindex="-1"
                >
                    <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    <?php esc_html_e('Integrations', 'gpt3-ai-content-generator'); ?>
                </button>
                <button
                    type="button"
                    class="aipkit_settings_page_nav_link"
                    id="aipkit_settings_page_tab_apps"
                    role="tab"
                    aria-selected="false"
                    aria-controls="aipkit_settings_page_panel_apps"
                    data-aipkit-settings-page-link="apps"
                    tabindex="-1"
                >
                    <span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
                    <span class="aipkit_settings_page_nav_link_label"><?php esc_html_e('Apps', 'gpt3-ai-content-generator'); ?></span>
                    <?php if (!$is_pro) : ?>
                        <span class="aipkit_settings_apps_upsell_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                    <?php endif; ?>
                </button>
                <button
                    type="button"
                    class="aipkit_settings_page_nav_link"
                    id="aipkit_settings_page_tab_security"
                    role="tab"
                    aria-selected="false"
                    aria-controls="aipkit_settings_page_panel_security"
                    data-aipkit-settings-page-link="security"
                    tabindex="-1"
                >
                    <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                    <?php esc_html_e('Security', 'gpt3-ai-content-generator'); ?>
                </button>
                <button
                    type="button"
                    class="aipkit_settings_page_nav_link"
                    id="aipkit_settings_page_tab_api"
                    role="tab"
                    aria-selected="false"
                    aria-controls="aipkit_settings_page_panel_api"
                    data-aipkit-settings-page-link="api"
                    tabindex="-1"
                >
                    <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                    <?php esc_html_e('Developers', 'gpt3-ai-content-generator'); ?>
                </button>
                <button
                    type="button"
                    class="aipkit_settings_page_nav_link"
                    id="aipkit_settings_page_tab_others"
                    role="tab"
                    aria-selected="false"
                    aria-controls="aipkit_settings_page_panel_others"
                    data-aipkit-settings-page-link="others"
                    tabindex="-1"
                >
                    <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
                    <?php esc_html_e('Others', 'gpt3-ai-content-generator'); ?>
                </button>
            </nav>
        </div>

        <div class="aipkit_settings_pages">
                <section
                    class="aipkit_settings_page_section"
                    id="aipkit_settings_page_panel_ai"
                    role="tabpanel"
                    aria-labelledby="aipkit_settings_page_tab_ai"
                    data-aipkit-settings-page="ai"
                >
                    <header class="aipkit_settings_page_header">
                        <h3 class="aipkit_settings_page_title"><?php esc_html_e('AI Settings', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_page_helper"><?php esc_html_e('Connect AI providers and configure their models and advanced options.', 'gpt3-ai-content-generator'); ?></p>
                    </header>

                    <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/providers.php'; ?>
                </section>

                <section
                    class="aipkit_settings_page_section"
                    id="aipkit_settings_page_panel_integrations"
                    role="tabpanel"
                    aria-labelledby="aipkit_settings_page_tab_integrations"
                    data-aipkit-settings-page="integrations"
                    hidden
                >
                    <header class="aipkit_settings_page_header">
                        <h3 class="aipkit_settings_page_title"><?php esc_html_e('Integrations', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_page_helper"><?php esc_html_e('Manage provider credentials and sync controls for connected services.', 'gpt3-ai-content-generator'); ?></p>
                    </header>

                    <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/integrations.php'; ?>
                </section>

                <section
                    class="aipkit_settings_page_section"
                    id="aipkit_settings_page_panel_security"
                    role="tabpanel"
                    aria-labelledby="aipkit_settings_page_tab_security"
                    data-aipkit-settings-page="security"
                    hidden
                >
                    <header class="aipkit_settings_page_header">
                        <h3 class="aipkit_settings_page_title"><?php esc_html_e('Security settings', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_page_helper"><?php esc_html_e('Manage global word and IP blocklists shared across supported modules.', 'gpt3-ai-content-generator'); ?></p>
                    </header>

                    <div class="aipkit_settings_simple_form aipkit_settings_simple_form--security">
                        <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/security.php'; ?>
                    </div>
                </section>

                <section
                    class="aipkit_settings_page_section"
                    id="aipkit_settings_page_panel_api"
                    role="tabpanel"
                    aria-labelledby="aipkit_settings_page_tab_api"
                    data-aipkit-settings-page="api"
                    hidden
                >
                    <header class="aipkit_settings_page_header">
                        <h3 class="aipkit_settings_page_title"><?php esc_html_e('Developer settings', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_page_helper"><?php esc_html_e('Manage REST API access and outbound event webhook endpoints for developer workflows.', 'gpt3-ai-content-generator'); ?></p>
                    </header>

                    <div class="aipkit_settings_simple_form aipkit_settings_simple_form--api">
                        <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/developer.php'; ?>
                    </div>
                </section>

                <section
                    class="aipkit_settings_page_section"
                    id="aipkit_settings_page_panel_apps"
                    role="tabpanel"
                    aria-labelledby="aipkit_settings_page_tab_apps"
                    data-aipkit-settings-page="apps"
                    hidden
                >
                    <header class="aipkit_settings_page_header">
                        <h3 class="aipkit_settings_page_title"><?php esc_html_e('Apps', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_page_helper"><?php esc_html_e('Manage reusable app connections and event-driven recipe automations.', 'gpt3-ai-content-generator'); ?></p>
                    </header>

                    <div class="aipkit_settings_simple_form aipkit_settings_simple_form--apps">
                        <?php include __DIR__ . '/apps.php'; ?>
                    </div>
                </section>

                <section
                    class="aipkit_settings_page_section"
                    id="aipkit_settings_page_panel_modules"
                    role="tabpanel"
                    aria-labelledby="aipkit_settings_page_tab_modules"
                    data-aipkit-settings-page="modules"
                    hidden
                >
                    <header class="aipkit_settings_page_header">
                        <h3 class="aipkit_settings_page_title"><?php esc_html_e('Modules', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_page_helper"><?php esc_html_e('Choose which tools appear in navigation and enable optional features.', 'gpt3-ai-content-generator'); ?></p>
                    </header>

                    <div class="aipkit_settings_simple_form aipkit_settings_simple_form--modules">
                        <?php include __DIR__ . '/modules.php'; ?>
                    </div>
                </section>

                <section
                    class="aipkit_settings_page_section"
                    id="aipkit_settings_page_panel_others"
                    role="tabpanel"
                    aria-labelledby="aipkit_settings_page_tab_others"
                    data-aipkit-settings-page="others"
                    hidden
                >
                    <header class="aipkit_settings_page_header">
                        <h3 class="aipkit_settings_page_title"><?php esc_html_e('Other settings', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_page_helper"><?php esc_html_e('Manage backups and maintenance actions.', 'gpt3-ai-content-generator'); ?></p>
                    </header>

                    <div class="aipkit_settings_simple_form aipkit_settings_simple_form--others">
                        <?php include __DIR__ . '/maintenance.php'; ?>
                    </div>
                </section>
        </div>
    </section>
</div>
