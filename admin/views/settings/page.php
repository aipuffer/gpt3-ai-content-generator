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

$aipkit_settings_sections = [
    'ai' => [
        'label' => __('AI', 'gpt3-ai-content-generator'),
        'hint' => __('How this site talks to AI. Every tool uses it.', 'gpt3-ai-content-generator'),
        'icon' => 'lightbulb',
    ],
    'tools' => [
        'label' => __('Tools', 'gpt3-ai-content-generator'),
        'hint' => __('What shows in the AI Puffer menu, and in WordPress.', 'gpt3-ai-content-generator'),
        'icon' => 'screenoptions',
    ],
    'connections' => [
        'label' => __('Connections', 'gpt3-ai-content-generator'),
        'hint' => __('Other services AI Puffer uses, and the apps it sends things to.', 'gpt3-ai-content-generator'),
        'icon' => 'admin-links',
    ],
    'safety' => [
        'label' => __('Security', 'gpt3-ai-content-generator'),
        'hint' => __('Who can use AI Puffer, what visitors can’t send, and a copy of your settings.', 'gpt3-ai-content-generator'),
        'icon' => 'shield',
    ],
    'developers' => [
        'label' => __('For developers', 'gpt3-ai-content-generator'),
        'hint' => __('APIs and webhooks. Most sites never need these.', 'gpt3-ai-content-generator'),
        'icon' => 'editor-code',
    ],
    'help' => [
        'label' => __('Help', 'gpt3-ai-content-generator'),
        'hint' => __('Guides, support, and more from the team behind AI Puffer.', 'gpt3-ai-content-generator'),
        'icon' => 'sos',
    ],
];

$aipkit_render_section_start = static function (string $key) use ($aipkit_settings_sections): void {
    $section = $aipkit_settings_sections[$key];
    ?>
    <section class="aipkit_settings_section" id="aipkit_settings_section_<?php echo esc_attr($key); ?>" data-aipkit-settings-section="<?php echo esc_attr($key); ?>" aria-labelledby="aipkit_settings_section_<?php echo esc_attr($key); ?>_title" <?php echo $key === 'ai' ? '' : 'hidden'; ?>>
        <header class="aipkit_settings_section_header">
            <h3 class="aipkit_settings_section_title" id="aipkit_settings_section_<?php echo esc_attr($key); ?>_title"><?php echo esc_html($section['label']); ?></h3>
            <p class="aipkit_settings_section_hint"><?php echo esc_html($section['hint']); ?></p>
        </header>
    <?php
};
?>
<div class="aipkit_settings_main_container aipkit_admin_ui" id="aipkit_settings_container">
    <div class="aipkit_settings_layout">
        <aside class="aipkit_settings_menu_card">
            <div class="aipkit_settings_menu_head">
                <h2 class="aipkit_container-title"><?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?></h2>
                <div
                    id="aipkit_settings_global_messages"
                    class="aipkit_settings_messages aipkit_global_status_area"
                    role="status"
                    aria-live="polite"
                ></div>
            </div>
            <nav class="aipkit_settings_jump" aria-label="<?php esc_attr_e('Settings sections', 'gpt3-ai-content-generator'); ?>">
                <?php foreach ($aipkit_settings_sections as $aipkit_section_key => $aipkit_section) : ?>
                    <?php if ($aipkit_section_key === 'help') : ?>
                        <span class="aipkit_settings_jump_divider" aria-hidden="true"></span>
                    <?php endif; ?>
                    <a
                        class="aipkit_settings_jump_link<?php echo $aipkit_section_key === 'ai' ? ' is-active' : ''; ?>"
                        href="#aipkit_settings_section_<?php echo esc_attr($aipkit_section_key); ?>"
                        data-aipkit-settings-jump="<?php echo esc_attr($aipkit_section_key); ?>"
                        <?php echo $aipkit_section_key === 'ai' ? 'aria-current="page"' : ''; ?>
                    >
                        <span class="dashicons dashicons-<?php echo esc_attr($aipkit_section['icon']); ?>" aria-hidden="true"></span>
                        <span><?php echo esc_html($aipkit_section['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="aipkit_settings_sections">
            <?php $aipkit_render_section_start('ai'); ?>
                <div class="aipkit_settings_scope" data-aipkit-settings-page="ai">
                    <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/providers.php'; ?>
                </div>
            </section>

            <?php $aipkit_render_section_start('tools'); ?>
                <div class="aipkit_settings_scope aipkit_settings_simple_form aipkit_settings_simple_form--modules" data-aipkit-settings-page="modules">
                    <?php include __DIR__ . '/modules.php'; ?>
                </div>
            </section>

            <?php $aipkit_render_section_start('connections'); ?>
                <div class="aipkit_settings_scope" data-aipkit-settings-page="integrations">
                    <?php $aipkit_integration_part = 'services'; include WPAICG_PLUGIN_DIR . 'admin/views/settings/integrations.php'; ?>
                </div>
                <div class="aipkit_settings_scope aipkit_settings_part aipkit_settings_simple_form aipkit_settings_simple_form--apps" data-aipkit-settings-page="apps">
                    <h4 class="aipkit_settings_group_title"><?php esc_html_e('Apps', 'gpt3-ai-content-generator'); ?></h4>
                    <?php include __DIR__ . '/apps.php'; ?>
                </div>
                <?php // Stock photos stay for the sites that use them, after the services and apps worth reaching for first. ?>
                <div class="aipkit_settings_scope" data-aipkit-settings-page="stock-photos">
                    <?php $aipkit_integration_part = 'stock-photos'; include WPAICG_PLUGIN_DIR . 'admin/views/settings/integrations.php'; ?>
                </div>
                <?php unset($aipkit_integration_part); ?>
            </section>

            <?php $aipkit_render_section_start('safety'); ?>
                <div class="aipkit_settings_scope aipkit_settings_part aipkit_settings_simple_form aipkit_settings_simple_form--security" data-aipkit-settings-page="security">
                    <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/security.php'; ?>
                </div>
                <?php // Backups keep the site safe from a bad change, so they close Security; old links to them land here. ?>
                <div class="aipkit_settings_scope aipkit_settings_part aipkit_settings_simple_form aipkit_settings_simple_form--others" id="aipkit_settings_backups" data-aipkit-settings-page="others">
                    <h4 class="aipkit_settings_group_title"><?php esc_html_e('Backups', 'gpt3-ai-content-generator'); ?></h4>
                    <?php include __DIR__ . '/maintenance.php'; ?>
                </div>
            </section>

            <?php $aipkit_render_section_start('developers'); ?>
                <div class="aipkit_settings_scope aipkit_settings_part aipkit_settings_simple_form aipkit_settings_simple_form--api" data-aipkit-settings-page="api">
                    <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/developer.php'; ?>
                </div>
            </section>

            <?php $aipkit_render_section_start('help'); ?>
                <?php include __DIR__ . '/help.php'; ?>
            </section>
        </div>
    </div>
</div>
