<?php
/**
 * AI provider cards and the provider settings modals they open.
 *
 * The provider schema below is the single source of truth for both surfaces.
 */

use WPAICG\Core\Moderation\AIPKit_Global_Security_Settings;
use WPAICG\Cloud\Connection;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local schema and renderer helpers.

$aipkit_render_account_fields = static function (string $aipkit_cloud_notice = ''): void {
    $aipkit_cloud_display = Connection::display();
    $aipkit_cloud_is_error = in_array($aipkit_cloud_notice, ['unavailable', 'busy', 'forbidden', 'freemius_failed', 'installation_inactive', 'site_mismatch', 'account_unavailable', 'invalid_email', 'registration_wait', 'account_changed', 'verification_pending', 'verification_unavailable'], true);
    ?>
    <section class="aipkit_cloud_connection" id="aipkit_cloud_connection" <?php echo $aipkit_cloud_display['connected'] ? '' : 'data-aipkit-settings-autosave-exclude="true"'; ?>>
        <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html(Connection::connection_message($aipkit_cloud_notice)); ?></p>

        <?php if ($aipkit_cloud_display['connected']) : ?>
            <?php echo Connection::account_email_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
            <?php echo Connection::email_recovery_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
        <?php elseif ($aipkit_cloud_display['recovery']) : ?>
            <div class="aipkit_cloud_notice aipkit_cloud_notice--warning">
                <p><?php esc_html_e('The saved connection cannot be used after a security-key or site-address change. Reset it, then connect again. Your Cloud balance is kept.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_footer">
                <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                <input type="hidden" name="action" value="aipkit_cloud_connection">
                <button class="aipkit_btn aipkit_cloud_btn aipkit_cloud_btn--primary" name="cloud_action" value="reset"><?php esc_html_e('Reset local connection', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
            </form>

        <?php else : ?>
            <div class="aipkit_cloud_intro">
                <p class="aipkit_cloud_intro_title"><?php esc_html_e('Use AI without an API key', 'gpt3-ai-content-generator'); ?></p>
                <ul class="aipkit_cloud_benefits">
                    <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Free credits every month to get started', 'gpt3-ai-content-generator'); ?></li>
                    <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Leading models from OpenAI, Google and more', 'gpt3-ai-content-generator'); ?></li>
                    <li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Use supported models in Chatbot, Content Writer, AI Forms, Automations and more', 'gpt3-ai-content-generator'); ?></li>
                </ul>
            </div>
            <?php echo Connection::account_email_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_connect">
                <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                <input type="hidden" name="action" value="aipkit_cloud_connection">
                <?php if (!$aipkit_cloud_display['registered']) : ?>
                    <label class="aipkit_settings_provider_model_label"><?php esc_html_e('Email', 'gpt3-ai-content-generator'); ?>
                        <input class="aipkit_form-input" type="email" name="cloud_email" autocomplete="email" value="<?php echo esc_attr($aipkit_cloud_display['email']); ?>" required>
                    </label>
                <?php endif; ?>
                <?php if ($aipkit_cloud_display['confirm_email']) : ?>
                    <div class="aipkit_cloud_notice aipkit_cloud_notice--info">
                        <p><?php echo esc_html(sprintf(
                            /* translators: %s: email address that must be confirmed. */
                            __('Check your inbox for a confirmation link sent to %s. Open it, then return here to check again.', 'gpt3-ai-content-generator'),
                            $aipkit_cloud_display['email']
                        )); ?></p>
                    </div>
                <?php endif; ?>
                <label class="aipkit_cloud_check"><input type="checkbox" name="cloud_consent" value="yes" required> <span><?php printf(
                    /* translators: 1: AI Puffer terms link. 2: AI Puffer privacy policy link. */
                    esc_html__('I agree to connect this site to AI Puffer Cloud under the %1$s and %2$s.', 'gpt3-ai-content-generator'),
                    '<a href="' . esc_url(Connection::TERMS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('terms', 'gpt3-ai-content-generator') . '</a>',
                    '<a href="' . esc_url(Connection::PRIVACY_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'gpt3-ai-content-generator') . '</a>'
                ); ?></span></label>
                <?php echo Connection::privacy_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all values. ?>
                <?php if (!$aipkit_cloud_display['registered'] && !$aipkit_cloud_display['confirm_email']) : ?>
                    <label class="aipkit_cloud_check"><input type="checkbox" name="cloud_marketing" value="yes"> <span><?php esc_html_e('Email me product news and tips (optional).', 'gpt3-ai-content-generator'); ?></span></label>
                <?php endif; ?>
                <?php if ($aipkit_cloud_display['confirm_email']) : ?>
                    <button class="aipkit_btn aipkit_cloud_btn aipkit_cloud_btn--primary" name="cloud_action" value="check_email" disabled><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                <?php endif; ?>
                <button class="aipkit_btn aipkit_cloud_btn<?php echo $aipkit_cloud_display['confirm_email'] ? '' : ' aipkit_cloud_btn--primary aipkit_cloud_btn--block'; ?>" name="cloud_action" value="connect" disabled><?php echo esc_html($aipkit_cloud_display['confirm_email'] ? __('Resend email', 'gpt3-ai-content-generator') : __('Connect', 'gpt3-ai-content-generator')); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
            </form>
        <?php endif; ?>
    </section>
    <?php
};
if (!empty($aipkit_account_fields_only)) {
    $aipkit_render_account_fields($aipkit_cloud_notice ?? '');
    return;
}

if (!empty($aipkit_connection_dialog_only)) {
    foreach (['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'Ollama', 'DeepSeek', 'xAI'] as $aipkit_provider_name) {
        $aipkit_data_name = strtolower($aipkit_provider_name) . '_data';
        $aipkit_defaults_name = strtolower($aipkit_provider_name) . '_defaults';
        $$aipkit_data_name = \WPAICG\AIPKit_Providers::get_provider_data($aipkit_provider_name);
        $$aipkit_defaults_name = \WPAICG\AIPKit_Providers::get_provider_defaults($aipkit_provider_name);
    }
    $temperature = 1;
    $top_p = 1;
    $is_pro = \WPAICG\aipkit_dashboard::is_pro_plan();
}

$aipkit_provider_data = [
    'AIPufferCloud' => \WPAICG\AIPKit_Providers::get_provider_data('AIPufferCloud'),
    'OpenAI' => $openai_data,
    'Google' => $google_data,
    'Claude' => $claude_data,
    'OpenRouter' => $openrouter_data,
    'Azure' => $azure_data,
    'Ollama' => $ollama_data,
    'DeepSeek' => $deepseek_data,
    'xAI' => $xai_data,
];

$aipkit_provider_defaults = [
    'OpenAI' => $openai_defaults,
    'Google' => $google_defaults,
    'Claude' => $claude_defaults,
    'OpenRouter' => $openrouter_defaults,
    'Azure' => $azure_defaults,
    'Ollama' => $ollama_defaults,
    'DeepSeek' => $deepseek_defaults,
    'xAI' => $xai_defaults,
];

$aipkit_security_settings = class_exists(AIPKit_Global_Security_Settings::class)
    ? AIPKit_Global_Security_Settings::get_settings()
    : [];
$aipkit_moderation_enabled = (string) ($aipkit_security_settings['openai_moderation_enabled'] ?? '0');
$aipkit_moderation_message = (string) ($aipkit_security_settings['openai_moderation_message']
    ?? __('Your message was flagged by the moderation system and could not be sent.', 'gpt3-ai-content-generator'));
$aipkit_model_sync_timestamps = \WPAICG\AIPKit_Providers::get_model_sync_timestamps();
$aipkit_model_sync_timestamps = is_array($aipkit_model_sync_timestamps) ? $aipkit_model_sync_timestamps : [];

$aipkit_common_sampling_fields = [
    [
        'id' => 'temperature',
        'type' => 'number',
        'name' => '',
        'global_name' => 'temperature',
        'label' => __('Temperature', 'gpt3-ai-content-generator'),
        'description' => __('Creativity level.', 'gpt3-ai-content-generator'),
        'value' => $temperature,
        'min' => '0',
        'max' => '2',
        'step' => '0.1',
    ],
    [
        'id' => 'top_p',
        'type' => 'number',
        'name' => '',
        'global_name' => 'top_p',
        'label' => __('Top P', 'gpt3-ai-content-generator'),
        'description' => __('Sampling diversity.', 'gpt3-ai-content-generator'),
        'value' => $top_p,
        'min' => '0',
        'max' => '1',
        'step' => '0.01',
    ],
];

$aipkit_endpoint_fields = static function (string $provider, string $slug) use ($aipkit_provider_data, $aipkit_provider_defaults): array {
    $data = $aipkit_provider_data[$provider] ?? [];
    $defaults = $aipkit_provider_defaults[$provider] ?? [];

    return [
        [
            'id' => 'base_url',
            'type' => 'text',
            'name' => $slug . '_base_url',
            'label' => __('Base URL', 'gpt3-ai-content-generator'),
            'description' => __('Custom API endpoint.', 'gpt3-ai-content-generator'),
            'value' => (string) ($data['base_url'] ?? ''),
            'default' => (string) ($defaults['base_url'] ?? ''),
            'monospace' => true,
            'reset' => true,
        ],
        [
            'id' => 'api_version',
            'type' => 'text',
            'name' => $slug . '_api_version',
            'label' => __('API version', 'gpt3-ai-content-generator'),
            'description' => __('Endpoint version.', 'gpt3-ai-content-generator'),
            'value' => (string) ($data['api_version'] ?? ''),
            'default' => (string) ($defaults['api_version'] ?? ''),
            'monospace' => true,
            'reset' => true,
        ],
    ];
};

$aipkit_build_model_field = static function (string $provider, string $slug) use ($aipkit_provider_data): array {
    return [
        'id' => 'model',
        'type' => 'model',
        'name' => $provider === 'Azure' ? 'azure_deployment' : $slug . '_model',
        'label' => $provider === 'Azure'
            ? __('Default deployment', 'gpt3-ai-content-generator')
            : __('Default model', 'gpt3-ai-content-generator'),
        'description' => $provider === 'Azure'
            ? __('Deployment used by default.', 'gpt3-ai-content-generator')
            : __('Model used by default.', 'gpt3-ai-content-generator'),
        'value' => (string) (($aipkit_provider_data[$provider] ?? [])['model'] ?? ''),
    ];
};

$aipkit_openrouter_fallback_options = [
    '' => __('No fallback', 'gpt3-ai-content-generator'),
];
foreach (\WPAICG\AIPKit_Providers::get_openrouter_models() as $aipkit_openrouter_model) {
    if (!is_array($aipkit_openrouter_model)) {
        continue;
    }
    $aipkit_openrouter_model_id = sanitize_text_field((string) ($aipkit_openrouter_model['id'] ?? ''));
    if ($aipkit_openrouter_model_id === '') {
        continue;
    }
    $aipkit_openrouter_model_name = sanitize_text_field((string) ($aipkit_openrouter_model['name'] ?? ''));
    $aipkit_openrouter_fallback_options[$aipkit_openrouter_model_id] = $aipkit_openrouter_model_name !== ''
        ? $aipkit_openrouter_model_name
        : $aipkit_openrouter_model_id;
}
for ($aipkit_fallback_position = 1; $aipkit_fallback_position <= 3; $aipkit_fallback_position++) {
    $aipkit_saved_fallback_model = sanitize_text_field((string) ($openrouter_data['fallback_model_' . $aipkit_fallback_position] ?? ''));
    if ($aipkit_saved_fallback_model !== '' && !isset($aipkit_openrouter_fallback_options[$aipkit_saved_fallback_model])) {
        $aipkit_openrouter_fallback_options[$aipkit_saved_fallback_model] = $aipkit_saved_fallback_model;
    }
}

$aipkit_paid_provider_fields = [];
$aipkit_paid_provider_view = WPAICG_PLUGIN_DIR . 'lib/views/settings/provider-options.php';
if ($is_pro && file_exists($aipkit_paid_provider_view)) {
    $aipkit_paid_provider_fields = include $aipkit_paid_provider_view;
}

$aipkit_provider_configs = [
    'OpenAI' => [
        'slug' => 'openai',
        'display_name' => __('OpenAI', 'gpt3-ai-content-generator'),
        'icon' => 'openai.svg',
        'accent' => '#10a37f',
        'credential_key' => 'api_key',
        'credential_name' => 'openai_api_key',
        'credential_type' => 'password',
        'credential_placeholder' => __('Paste your OpenAI API key', 'gpt3-ai-content-generator'),
        'key_url' => 'https://platform.openai.com/api-keys',
        'key_link' => __('Get your OpenAI API key', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('OpenAI', 'openai')],
            $aipkit_endpoint_fields('OpenAI', 'openai'),
            [[
                'id' => 'api_mode',
                'type' => 'select',
                'name' => 'openai_api_mode',
                'label' => __('Chat API', 'gpt3-ai-content-generator'),
                'description' => __('Choose the request format used for OpenAI-compatible chat endpoints.', 'gpt3-ai-content-generator'),
                'value' => \WPAICG\AIPKit_Providers::normalize_openai_api_mode($openai_data['api_mode'] ?? null),
                'options' => [
                    'responses' => __('Responses API (recommended)', 'gpt3-ai-content-generator'),
                    'chat_completions' => __('Chat Completions (compatibility)', 'gpt3-ai-content-generator'),
                ],
            ]],
            $aipkit_paid_provider_fields['OpenAI'] ?? [],
            [
                [
                    'id' => 'store_conversation',
                    'type' => 'toggle',
                    'name' => 'openai_store_conversation',
                    'label' => __('Store conversation', 'gpt3-ai-content-generator'),
                    'description' => __('Save chat history on OpenAI server.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openai_data['store_conversation'] ?? '0'),
                ],
                [
                    'id' => 'moderation',
                    'type' => 'toggle',
                    'name' => 'security[openai_moderation_enabled]',
                    'label' => __('Moderation', 'gpt3-ai-content-generator'),
                    'description' => __('Moderate user input.', 'gpt3-ai-content-generator'),
                    'value' => $aipkit_moderation_enabled,
                    'controls' => 'aipkit_settings_openai_moderation_message_row',
                ],
                [
                    'id' => 'moderation_message',
                    'type' => 'text',
                    'name' => 'security[openai_moderation_message]',
                    'label' => __('Moderation message', 'gpt3-ai-content-generator'),
                    'description' => __('Shown when a message is blocked.', 'gpt3-ai-content-generator'),
                    'value' => $aipkit_moderation_message,
                    'row_id' => 'aipkit_settings_openai_moderation_message_row',
                    'hidden' => $aipkit_moderation_enabled !== '1',
                ],
            ]
        ),
    ],
    'Claude' => [
        'slug' => 'claude',
        'display_name' => __('Anthropic', 'gpt3-ai-content-generator'),
        'icon' => 'anthropic.svg',
        'accent' => '#d97757',
        'credential_key' => 'api_key',
        'credential_name' => 'claude_api_key',
        'credential_type' => 'password',
        'credential_placeholder' => __('Paste your Anthropic API key', 'gpt3-ai-content-generator'),
        'key_url' => 'https://console.anthropic.com/settings/keys',
        'key_link' => __('Get your Anthropic API key', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('Claude', 'claude')],
            $aipkit_endpoint_fields('Claude', 'claude')
        ),
    ],
    'Google' => [
        'slug' => 'google',
        'display_name' => __('Google', 'gpt3-ai-content-generator'),
        'icon' => 'google.svg',
        'accent' => '#4285f4',
        'credential_key' => 'api_key',
        'credential_name' => 'google_api_key',
        'credential_type' => 'password',
        'credential_placeholder' => __('Paste your Google AI API key', 'gpt3-ai-content-generator'),
        'key_url' => 'https://aistudio.google.com/app/apikey',
        'key_link' => __('Get your Google AI API key', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('Google', 'google')],
            $aipkit_endpoint_fields('Google', 'google'),
            [[
                'id' => 'store_conversation',
                'type' => 'toggle',
                'name' => 'google_store_conversation',
                'label' => __('Store conversation', 'gpt3-ai-content-generator'),
                'description' => __('Save interaction history on Google servers.', 'gpt3-ai-content-generator'),
                'value' => (string) ($google_data['store_conversation'] ?? '0'),
            ]]
        ),
    ],
    'OpenRouter' => [
        'slug' => 'openrouter',
        'display_name' => __('OpenRouter', 'gpt3-ai-content-generator'),
        'icon' => 'openrouter.svg',
        'accent' => '#6366f1',
        'credential_key' => 'api_key',
        'credential_name' => 'openrouter_api_key',
        'credential_type' => 'password',
        'credential_placeholder' => __('Paste your OpenRouter API key', 'gpt3-ai-content-generator'),
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Credential link, not API transport.
        'key_url' => 'https://openrouter.ai/settings/keys',
        'key_link' => __('Get your OpenRouter API key', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('OpenRouter', 'openrouter')],
            $aipkit_endpoint_fields('OpenRouter', 'openrouter'),
            [
                [
                    'id' => 'allow_fallbacks',
                    'type' => 'toggle',
                    'name' => 'openrouter_allow_fallbacks',
                    'label' => __('Provider failover', 'gpt3-ai-content-generator'),
                    'description' => __('Allow another endpoint provider to serve the same text or image model when the preferred endpoint fails.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['allow_fallbacks'] ?? '1'),
                ],
                [
                    'id' => 'require_parameters',
                    'type' => 'toggle',
                    'name' => 'openrouter_require_parameters',
                    'label' => __('Strict parameter support', 'gpt3-ai-content-generator'),
                    'description' => __('For text requests, use only provider endpoints that support every parameter sent. This improves consistency but may reduce availability.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['require_parameters'] ?? '0'),
                ],
                [
                    'id' => 'data_collection',
                    'type' => 'select',
                    'name' => 'openrouter_data_collection',
                    'label' => __('Provider data collection', 'gpt3-ai-content-generator'),
                    'description' => __('For text requests, Deny routes only to providers that do not collect request data.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['data_collection'] ?? 'allow'),
                    'options' => [
                        'allow' => __('Allow', 'gpt3-ai-content-generator'),
                        'deny' => __('Deny', 'gpt3-ai-content-generator'),
                    ],
                ],
                [
                    'id' => 'zdr',
                    'type' => 'toggle',
                    'name' => 'openrouter_zdr',
                    'label' => __('Zero data retention (ZDR)', 'gpt3-ai-content-generator'),
                    'description' => __('For text requests, use only inference endpoints that retain no prompt data. Web search and other tools have separate data policies.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['zdr'] ?? '0'),
                ],
                [
                    'id' => 'fallback_model_1',
                    'type' => 'select',
                    'name' => 'openrouter_fallback_model_1',
                    'label' => __('Fallback model 1', 'gpt3-ai-content-generator'),
                    'description' => __('First text model to try when the selected model fails. The model ultimately used determines the price.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['fallback_model_1'] ?? ''),
                    'options' => $aipkit_openrouter_fallback_options,
                    'model_picker' => true,
                ],
                [
                    'id' => 'fallback_model_2',
                    'type' => 'select',
                    'name' => 'openrouter_fallback_model_2',
                    'label' => __('Fallback model 2', 'gpt3-ai-content-generator'),
                    'description' => __('Second model to try if the selected model and first fallback fail.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['fallback_model_2'] ?? ''),
                    'options' => $aipkit_openrouter_fallback_options,
                    'model_picker' => true,
                ],
                [
                    'id' => 'fallback_model_3',
                    'type' => 'select',
                    'name' => 'openrouter_fallback_model_3',
                    'label' => __('Fallback model 3', 'gpt3-ai-content-generator'),
                    'description' => __('Final model to try before returning the upstream error.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['fallback_model_3'] ?? ''),
                    'options' => $aipkit_openrouter_fallback_options,
                    'model_picker' => true,
                ],
            ]
        ),
    ],
    'Azure' => [
        'slug' => 'azure',
        'display_name' => __('Azure AI', 'gpt3-ai-content-generator'),
        'icon' => 'azure.svg',
        'accent' => '#0078d4',
        'credential_key' => 'api_key',
        'credential_name' => 'azure_api_key',
        'credential_type' => 'password',
        'credential_placeholder' => __('Paste your Azure AI API key', 'gpt3-ai-content-generator'),
        'key_url' => 'https://ai.azure.com/',
        'key_link' => __('Open Azure AI Foundry', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('Azure', 'azure')],
            [
                [
                    'id' => 'endpoint',
                    'type' => 'url',
                    'name' => 'azure_endpoint',
                    'label' => __('Endpoint URL', 'gpt3-ai-content-generator'),
                    'description' => __('Azure resource endpoint.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($azure_data['endpoint'] ?? ''),
                    'default' => '',
                    'monospace' => true,
                    'reset' => true,
                ],
                [
                    'id' => 'authoring_version',
                    'type' => 'text',
                    'name' => 'azure_api_version_authoring',
                    'label' => __('Authoring API version', 'gpt3-ai-content-generator'),
                    'description' => __('Deployment management version.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($azure_data['api_version_authoring'] ?? ''),
                    'default' => (string) ($azure_defaults['api_version_authoring'] ?? ''),
                    'monospace' => true,
                    'reset' => true,
                ],
                [
                    'id' => 'inference_version',
                    'type' => 'text',
                    'name' => 'azure_api_version_inference',
                    'label' => __('Inference API version', 'gpt3-ai-content-generator'),
                    'description' => __('Model request version.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($azure_data['api_version_inference'] ?? ''),
                    'default' => (string) ($azure_defaults['api_version_inference'] ?? ''),
                    'monospace' => true,
                    'reset' => true,
                ],
                [
                    'id' => 'images_version',
                    'type' => 'text',
                    'name' => 'azure_api_version_images',
                    'label' => __('Images API version', 'gpt3-ai-content-generator'),
                    'description' => __('Image request version.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($azure_data['api_version_images'] ?? ''),
                    'default' => (string) ($azure_defaults['api_version_images'] ?? ''),
                    'monospace' => true,
                    'reset' => true,
                ],
            ]
        ),
    ],
    'Ollama' => [
        'slug' => 'ollama',
        'display_name' => __('Ollama', 'gpt3-ai-content-generator'),
        'icon' => 'ollama.svg',
        'accent' => '#111827',
        'credential_key' => 'base_url',
        'credential_name' => 'ollama_base_url',
        'credential_type' => 'url',
        'credential_placeholder' => __('Enter your Ollama server URL', 'gpt3-ai-content-generator'),
        'key_url' => 'https://ollama.com/download',
        'key_link' => __('Get Ollama', 'gpt3-ai-content-generator'),
        'fields' => $aipkit_paid_provider_fields['Ollama'] ?? [],
        'requires_pro' => true,
        'pro_description' => __('Run models locally.', 'gpt3-ai-content-generator'),
    ],
    'DeepSeek' => [
        'slug' => 'deepseek',
        'display_name' => __('DeepSeek', 'gpt3-ai-content-generator'),
        'icon' => 'deepseek.svg',
        'accent' => '#4d6bfe',
        'credential_key' => 'api_key',
        'credential_name' => 'deepseek_api_key',
        'credential_type' => 'password',
        'credential_placeholder' => __('Paste your DeepSeek API key', 'gpt3-ai-content-generator'),
        'key_url' => 'https://platform.deepseek.com/api_keys',
        'key_link' => __('Get your DeepSeek API key', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('DeepSeek', 'deepseek')],
            $aipkit_endpoint_fields('DeepSeek', 'deepseek')
        ),
    ],
    'xAI' => [
        'slug' => 'xai',
        'display_name' => __('xAI', 'gpt3-ai-content-generator'),
        'icon' => 'xai.svg',
        'accent' => '#111827',
        'credential_key' => 'api_key',
        'credential_name' => 'xai_api_key',
        'credential_type' => 'password',
        'credential_placeholder' => __('Paste your xAI API key', 'gpt3-ai-content-generator'),
        'key_url' => 'https://console.x.ai/team/default/api-keys',
        'key_link' => __('Get your xAI API key', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('xAI', 'xai')],
            $aipkit_endpoint_fields('xAI', 'xai')
        ),
    ],
];

if (class_exists(Connection::class) && Connection::allowed()) {
    $aipkit_provider_configs = ['AIPufferCloud' => [
        'slug' => 'aipuffercloud', 'display_name' => __('AI Puffer Cloud', 'gpt3-ai-content-generator'),
        'icon' => '', 'accent' => '#f28c28', 'credential_key' => 'api_key',
        'credential_name' => '', 'credential_type' => 'account',
        'fields' => [$aipkit_build_model_field('AIPufferCloud', 'aipuffercloud')],
    ]] + $aipkit_provider_configs;
}

if (!empty($aipkit_connection_dialog_only)) {
    include WPAICG_PLUGIN_DIR . 'admin/views/shared/provider-connect.php';
    return;
}

$aipkit_get_model_field = static function (array $config): ?array {
    foreach ((array) ($config['fields'] ?? []) as $field) {
        if (($field['type'] ?? '') === 'model') {
            return $field;
        }
    }

    return null;
};

$aipkit_get_advanced_fields = static function (array $config) use ($aipkit_common_sampling_fields): array {
    if (($config['credential_type'] ?? '') === 'account') { return []; }
    $provider_fields = array_values(array_filter(
        (array) ($config['fields'] ?? []),
        static fn(array $field): bool => ($field['type'] ?? '') !== 'model'
    ));

    return array_merge($aipkit_common_sampling_fields, $provider_fields);
};
?>

<div
    class="aipkit_settings_provider_cards"
    data-aipkit-current-provider="<?php echo esc_attr((string) $current_provider); ?>"
>
    <input
        type="hidden"
        name="provider"
        value="<?php echo esc_attr((string) $current_provider); ?>"
        data-aipkit-default-provider-input
    />
    <input
        type="hidden"
        name="temperature"
        value="<?php echo esc_attr((string) $temperature); ?>"
        data-aipkit-global-setting-source="temperature"
    />
    <input
        type="hidden"
        name="top_p"
        value="<?php echo esc_attr((string) $top_p); ?>"
        data-aipkit-global-setting-source="top_p"
    />
    <?php foreach ($aipkit_provider_configs as $aipkit_provider => $aipkit_config) :
        $aipkit_account_provider = $aipkit_config['credential_type'] === 'account';
        $aipkit_slug = (string) $aipkit_config['slug'];
        if ($aipkit_account_provider) {
            $aipkit_icon_url = WPAICG_LOGO_URL;
        } else {
            $aipkit_icon_relative_path = 'admin/images/providers/' . $aipkit_config['icon'];
            $aipkit_icon_path = WPAICG_PLUGIN_DIR . $aipkit_icon_relative_path;
            $aipkit_icon_version = file_exists($aipkit_icon_path) ? filemtime($aipkit_icon_path) : false;
            $aipkit_icon_url = add_query_arg(
                'ver',
                (string) ($aipkit_icon_version ?: WPAICG_VERSION),
                WPAICG_PLUGIN_URL . $aipkit_icon_relative_path
            );
        }
        $aipkit_data = $aipkit_provider_data[$aipkit_provider] ?? [];
        $aipkit_credential = (string) ($aipkit_data[$aipkit_config['credential_key']] ?? '');
        $aipkit_connected = $aipkit_account_provider ? Connection::display()['connected'] : $aipkit_credential !== '';
        $aipkit_locked = !empty($aipkit_config['requires_pro']) && !$is_pro;
        $aipkit_can_be_default = !$aipkit_locked && ($aipkit_account_provider || in_array($aipkit_provider, (array) $main_provider_allowlist, true));
        $aipkit_is_default = $aipkit_can_be_default && $current_provider === $aipkit_provider;
        $aipkit_is_secret = $aipkit_config['credential_type'] === 'password';
        $aipkit_credential_mask = $aipkit_is_secret && $aipkit_connected
            ? $aipkit_format_credential_mask($aipkit_credential)
            : '';
        $aipkit_credential_input_name = $aipkit_is_secret && $aipkit_connected
            ? ''
            : (string) $aipkit_config['credential_name'];
        $aipkit_credential_input_value = $aipkit_is_secret && $aipkit_connected
            ? ''
            : $aipkit_credential;
        $aipkit_model_field = $aipkit_get_model_field($aipkit_config);
        $aipkit_advanced_fields = $aipkit_get_advanced_fields($aipkit_config);
        ?>
        <article
            id="aipkit_settings_provider_card_<?php echo esc_attr(sanitize_title($aipkit_provider)); ?>"
            class="aipkit_settings_provider_card<?php echo $aipkit_locked ? ' is-locked' : ''; ?>"
            data-aipkit-provider-card="<?php echo esc_attr($aipkit_provider); ?>"
            data-aipkit-provider-connected="<?php echo $aipkit_connected ? 'true' : 'false'; ?>"
            style="--aipkit-provider-accent: <?php echo esc_attr((string) $aipkit_config['accent']); ?>;"
        >
            <?php if ($aipkit_locked) : ?>
                <div class="aipkit_settings_provider_card_main">
                    <span class="aipkit_settings_provider_upgrade_logo" aria-hidden="true">
                        <img src="<?php echo esc_url($aipkit_icon_url); ?>" alt="" />
                        <span class="aipkit_settings_provider_upgrade_lock">
                            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        </span>
                    </span>
                    <div class="aipkit_settings_provider_card_text">
                        <div class="aipkit_settings_provider_card_title">
                            <h4 class="aipkit_settings_provider_name"><?php echo esc_html((string) $aipkit_config['display_name']); ?></h4>
                            <span class="aipkit_settings_provider_status aipkit_settings_provider_status--pro aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <p class="aipkit_settings_provider_card_status aipkit_settings_provider_upgrade_description"><?php echo esc_html((string) ($aipkit_config['pro_description'] ?? __('Available with AI Puffer Pro.', 'gpt3-ai-content-generator'))); ?></p>
                    </div>
                    <a
                        class="aipkit_settings_provider_card_action aipkit_settings_provider_upgrade_cta aipkit_pro_upgrade_button"
                        href="<?php echo esc_url(admin_url('admin.php?page=wpaicg-pricing')); ?>"
                    ><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></a>
                </div>
            <?php else :
                $aipkit_section_labels = [
                    'generation' => __('Generation', 'gpt3-ai-content-generator'),
                    'endpoint' => __('Endpoint', 'gpt3-ai-content-generator'),
                    'retention' => __('Retention and privacy', 'gpt3-ai-content-generator'),
                    'routing' => __('Routing and fallbacks', 'gpt3-ai-content-generator'),
                    'general' => __('Settings', 'gpt3-ai-content-generator'),
                ];
                $aipkit_grouped_fields = [];
                foreach ($aipkit_advanced_fields as $aipkit_config_field) {
                    $aipkit_config_field_id = (string) ($aipkit_config_field['id'] ?? '');

                    if (in_array($aipkit_config_field_id, ['temperature', 'top_p'], true)) {
                        $aipkit_section_key = 'generation';
                    } elseif (
                        in_array($aipkit_config_field_id, ['base_url', 'api_version', 'api_mode', 'endpoint', 'authoring_version', 'inference_version', 'images_version'], true)
                    ) {
                        $aipkit_section_key = 'endpoint';
                    } elseif (
                        in_array($aipkit_config_field_id, ['expiration_policy', 'store_conversation', 'moderation', 'moderation_message'], true)
                    ) {
                        $aipkit_section_key = 'retention';
                    } elseif (
                        in_array($aipkit_config_field_id, ['allow_fallbacks', 'require_parameters', 'fallback_model_1', 'fallback_model_2', 'fallback_model_3'], true)
                    ) {
                        $aipkit_section_key = 'routing';
                    } elseif (in_array($aipkit_config_field_id, ['data_collection', 'zdr'], true)) {
                        $aipkit_section_key = 'retention';
                    } else {
                        $aipkit_section_key = 'general';
                    }

                    $aipkit_grouped_fields[$aipkit_section_key][] = $aipkit_config_field;
                }
                $aipkit_modal_id = 'aipkit_settings_' . $aipkit_slug . '_modal';
                $aipkit_modal_title_id = $aipkit_modal_id . '_title';
                $aipkit_credential_id = 'aipkit_settings_' . $aipkit_slug . '_credential';
                $aipkit_credential_label = $aipkit_is_secret
                    ? __('API key', 'gpt3-ai-content-generator')
                    : __('Server URL', 'gpt3-ai-content-generator');
            ?>
            <div class="aipkit_settings_provider_card_main">
                <span class="aipkit_settings_provider_logo" aria-hidden="true">
                    <img src="<?php echo esc_url($aipkit_icon_url); ?>" alt="" />
                </span>
                <div class="aipkit_settings_provider_card_text">
                    <div class="aipkit_settings_provider_card_title">
                        <h4 class="aipkit_settings_provider_name"><?php echo esc_html((string) $aipkit_config['display_name']); ?></h4>
                        <?php if ($aipkit_can_be_default) : ?>
                            <span
                                class="aipkit_settings_provider_status aipkit_settings_provider_status--default"
                                data-aipkit-provider-default-status
                                <?php echo $aipkit_is_default ? '' : 'hidden'; ?>
                            ><?php esc_html_e('Default', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="aipkit_settings_provider_card_status">
                        <span class="aipkit_settings_provider_status aipkit_settings_provider_status--connected" <?php echo $aipkit_connected ? '' : 'hidden'; ?>><?php esc_html_e('Connected', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_settings_provider_status aipkit_settings_provider_status--disconnected" <?php echo $aipkit_connected ? 'hidden' : ''; ?>><?php esc_html_e('Not connected', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_settings_provider_status aipkit_settings_provider_status--invalid" data-aipkit-provider-invalid-status hidden><?php esc_html_e('Invalid key', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_settings_provider_status aipkit_settings_provider_status--sync-error" data-aipkit-provider-sync-error-status hidden><?php esc_html_e('Sync failed', 'gpt3-ai-content-generator'); ?></span>
                        <span
                            class="aipkit_settings_provider_summary"
                            data-aipkit-provider-summary
                            data-empty-label="<?php esc_attr_e('No model selected', 'gpt3-ai-content-generator'); ?>"
                            <?php echo $aipkit_connected ? '' : 'hidden'; ?>
                        ><?php echo esc_html((string) ($aipkit_model_field['value'] ?? '') ?: __('No model selected', 'gpt3-ai-content-generator')); ?></span>
                    </p>
                </div>
                <button
                    type="button"
                    class="aipkit_settings_provider_card_action"
                    data-aipkit-provider-settings-open="<?php echo esc_attr($aipkit_provider); ?>"
                    data-manage-label="<?php esc_attr_e('Manage', 'gpt3-ai-content-generator'); ?>"
                    data-connect-label="<?php esc_attr_e('Connect', 'gpt3-ai-content-generator'); ?>"
                    aria-haspopup="dialog"
                    aria-controls="<?php echo esc_attr($aipkit_modal_id); ?>"
                ><?php echo $aipkit_connected ? esc_html__('Manage', 'gpt3-ai-content-generator') : esc_html__('Connect', 'gpt3-ai-content-generator'); ?></button>
            </div>

            <div class="aipkit-modal-overlay aipkit_settings_provider_modal"
    id="<?php echo esc_attr($aipkit_modal_id); ?>"
    data-aipkit-provider-modal="<?php echo esc_attr($aipkit_provider); ?>" aria-hidden="true">
    <div class="aipkit-modal-content aipkit-modal-shell aipkit_settings_provider_modal_content"
        role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($aipkit_modal_title_id); ?>">
        <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_settings_provider_modal_header">
            <div class="aipkit-modal-shell-intro aipkit_settings_provider_modal_intro">
                <span class="aipkit_settings_provider_logo" aria-hidden="true"><img src="<?php echo esc_url($aipkit_icon_url); ?>" alt="" /></span>
                <h2 class="aipkit-modal-shell-title" id="<?php echo esc_attr($aipkit_modal_title_id); ?>"><?php echo esc_html((string) $aipkit_config['display_name']); ?></h2>
            </div>
            <button type="button" class="aipkit-modal-close-btn aipkit-modal-shell-close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </div>
        <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_settings_provider_modal_body">

                        <section class="aipkit_settings_provider_connection_fields">
                            <?php if ($aipkit_account_provider) : ?>
                                <?php $aipkit_render_account_fields(); ?>
                            <?php else : ?>
                            <div class="aipkit_settings_provider_field">
                                <div class="aipkit_settings_provider_field_head">
                                    <label class="aipkit_settings_provider_model_label" for="<?php echo esc_attr($aipkit_credential_id); ?>"><?php echo esc_html($aipkit_credential_label); ?></label>

                                </div>
                                <div class="aipkit_settings_provider_credential_row">
                                    <div class="aipkit_settings_provider_credential_wrap">
                                        <input
                                            type="<?php echo esc_attr((string) $aipkit_config['credential_type']); ?>"
                                            id="aipkit_settings_<?php echo esc_attr($aipkit_slug); ?>_credential"
                                            name="<?php echo esc_attr($aipkit_credential_input_name); ?>"
                                            class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_provider_credential<?php echo $aipkit_is_secret ? ' is-secret' : ''; ?><?php echo $aipkit_credential_mask !== '' ? ' is-visually-masked' : ''; ?>"
                                            value="<?php echo esc_attr($aipkit_credential_input_value); ?>"
                                            placeholder="<?php echo esc_attr((string) $aipkit_config['credential_placeholder']); ?>"
                                            data-aipkit-provider-credential="<?php echo esc_attr($aipkit_provider); ?>"
                                            data-aipkit-credential-name="<?php echo esc_attr((string) $aipkit_config['credential_name']); ?>"
                                            data-aipkit-has-credential="<?php echo $aipkit_connected ? 'true' : 'false'; ?>"
                                            autocomplete="off"
                                            autocorrect="off"
                                            autocapitalize="off"
                                            spellcheck="false"
                                            data-lpignore="true"
                                            data-1p-ignore="true"
                                            data-form-type="other"
                                            <?php echo $aipkit_credential_mask !== '' ? 'readonly' : ''; ?>
                                        />
                                        <?php if ($aipkit_is_secret) : ?>
                                            <span
                                                class="aipkit_settings_provider_credential_mask"
                                                data-aipkit-provider-credential-mask
                                                aria-hidden="true"
                                                <?php echo $aipkit_credential_mask === '' ? 'hidden' : ''; ?>
                                            ><?php echo esc_html($aipkit_credential_mask); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="aipkit_settings_provider_connected_actions" <?php echo $aipkit_connected ? '' : 'hidden'; ?>>
                                        <?php if ($aipkit_config['credential_type'] === 'password') : ?>
                                            <button
                                                type="button"
                                                class="aipkit_settings_icon_button"
                                                data-aipkit-provider-reveal
                                                data-reveal-label="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>"
                                                data-hide-label="<?php esc_attr_e('Hide API key', 'gpt3-ai-content-generator'); ?>"
                                                aria-label="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>"
                                                title="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>"
                                            >
                                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                            </button>
                                        <?php endif; ?>

                                    </div>
                                    <button
                                        type="button"
                                        class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_connect"
                                        data-aipkit-provider-connect="<?php echo esc_attr($aipkit_provider); ?>"
                                        <?php echo $aipkit_connected ? 'hidden' : ''; ?>
                                    >
                                        <?php esc_html_e('Connect', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                </div>
                                    <a
                                        class="aipkit_settings_provider_key_link"
                                        href="<?php echo esc_url((string) $aipkit_config['key_url']); ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <?php echo esc_html((string) $aipkit_config['key_link']); ?> <span aria-hidden="true">↗</span>
                                    </a>
                            </div>
                            <div
                                class="aipkit_settings_provider_error"
                                data-aipkit-provider-error
                                <?php /* translators: %s: AI provider display name. */ ?>
                                data-invalid-message="<?php echo esc_attr(sprintf(__('That key was rejected by %s. Check the key or generate a new one, then reconnect.', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name'])); ?>"
                                <?php /* translators: %s: AI provider display name. */ ?>
                                data-sync-message="<?php echo esc_attr(sprintf(__('We could not sync %s. Check the connection settings and try again.', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name'])); ?>"
                                role="alert"
                                hidden
                            >
                                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                <div class="aipkit_settings_provider_error_content">
                                    <p data-aipkit-provider-error-message></p>
                                    <details class="aipkit_settings_provider_error_details" data-aipkit-provider-error-details hidden>
                                        <summary><?php esc_html_e('View details', 'gpt3-ai-content-generator'); ?></summary>
                                        <p data-aipkit-provider-error-technical></p>
                                    </details>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($aipkit_model_field) :
                                $aipkit_model_field_id = 'aipkit_' . $aipkit_slug . '_' . ($aipkit_provider === 'Azure' ? 'deployment' : 'model');
                                ?>
                                <div
                                    class="aipkit_settings_provider_model_block"
                                    data-aipkit-provider-model-block
                                    <?php echo $aipkit_connected ? '' : 'hidden'; ?>
                                >
                                    <label
                                        class="aipkit_settings_provider_model_label"
                                        for="<?php echo esc_attr($aipkit_model_field_id); ?>_trigger"
                                    >
                                        <?php echo esc_html((string) $aipkit_model_field['label']); ?>
                                    </label>
                                    <div class="aipkit_settings_provider_model_control">
                                        <div class="aipkit_settings_provider_model_select_row">
                                            <select
                                                id="<?php echo esc_attr($aipkit_model_field_id); ?>"
                                                name="<?php echo esc_attr((string) $aipkit_model_field['name']); ?>"
                                                class="aipkit_settings_provider_model_select aipkit_autosave_trigger"
                                                data-aipkit-settings-provider-model="<?php echo esc_attr($aipkit_provider); ?>"
                                                hidden
                                                aria-hidden="true"
                                                tabindex="-1"
                                            >
                                                <?php \WPAICG\AIPKit_Provider_Model_List_Builder::render_model_options($aipkit_provider, (string) $aipkit_model_field['value']); ?>
                                            </select>
                                            <?php
                                            $aipkit_unified_model_selector_config = [
                                                'trigger_id' => $aipkit_model_field_id . '_trigger',
                                                'initial_label' => (string) $aipkit_model_field['value'] !== ''
                                                    ? (string) $aipkit_model_field['value']
                                                    : __('Select model', 'gpt3-ai-content-generator'),
                                                'source_id' => $aipkit_model_field_id,
                                                'class_name' => 'aipkit_settings_provider_unified_model_selector',
                                                'show_manage_link' => false,
                                            ];
                                            include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                                            unset($aipkit_unified_model_selector_config);
                                            ?>
                                            <button
                                                type="<?php echo $aipkit_account_provider ? 'submit' : 'button'; ?>"
                                                <?php if ($aipkit_account_provider) : ?>form="aipkit_cloud_account_form" name="cloud_action" value="sync"<?php endif; ?>
                                                id="aipkit_sync_<?php echo esc_attr($aipkit_slug); ?>_models"
                                                class="aipkit_sync_btn aipkit_settings_compact_sync_btn"
                                                data-provider="<?php echo esc_attr($aipkit_provider); ?>"
                                                aria-label="<?php esc_attr_e('Sync models', 'gpt3-ai-content-generator'); ?>"
                                                title="<?php esc_attr_e('Sync models', 'gpt3-ai-content-generator'); ?>"
                                                aria-busy="false"
                                            >
                                                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                            </button>
                                        </div>
                                        <?php if (!$aipkit_account_provider) : ?>
                                        <span
                                            class="aipkit_settings_provider_model_last_synced"
                                            data-aipkit-provider-last-synced="<?php echo esc_attr($aipkit_provider); ?>"
                                            data-synced-at="<?php echo esc_attr((string) ($aipkit_model_sync_timestamps[$aipkit_provider] ?? '')); ?>"
                                            aria-live="polite"
                                            hidden
                                        ></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>

                        <?php if ($aipkit_grouped_fields) : ?>
                            <details class="aipkit_settings_provider_advanced">
                                <summary>
                                    <?php esc_html_e('Advanced settings', 'gpt3-ai-content-generator'); ?>
                                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                </summary>
                                <div class="aipkit_settings_provider_modal_fields">
                                    <?php foreach ($aipkit_grouped_fields as $aipkit_section_key => $aipkit_section_fields) : ?>
                                        <section class="aipkit_settings_provider_modal_section aipkit_settings_provider_modal_section--<?php echo esc_attr($aipkit_section_key); ?>">
                                            <h3 class="aipkit_settings_provider_modal_section_title">
                                                <?php echo esc_html((string) ($aipkit_section_labels[$aipkit_section_key] ?? $aipkit_section_labels['general'])); ?>
                                            </h3>
                                            <div class="aipkit_settings_provider_modal_section_fields">
                                    <?php foreach ($aipkit_section_fields as $aipkit_field) :
                                        $aipkit_field_id = 'aipkit_' . $aipkit_slug . '_' . (string) $aipkit_field['id'];
                                        $aipkit_field_type = (string) $aipkit_field['type'];
                                        ?>
                                        <div
                                            class="aipkit_settings_provider_modal_row"
                                            data-aipkit-provider-field-id="<?php echo esc_attr((string) $aipkit_field['id']); ?>"
                                            <?php echo !empty($aipkit_field['row_id']) ? 'id="' . esc_attr((string) $aipkit_field['row_id']) . '"' : ''; ?>
                                            <?php echo !empty($aipkit_field['hidden']) ? 'hidden' : ''; ?>
                                        >
                                            <div class="aipkit_settings_provider_modal_copy">
                                                <label class="aipkit_settings_provider_modal_label" for="<?php echo esc_attr($aipkit_field_id); ?>"><?php echo esc_html((string) $aipkit_field['label']); ?></label>
                                                <span class="aipkit_settings_provider_modal_helper"><?php echo esc_html((string) $aipkit_field['description']); ?></span>
                                            </div>
                                            <div class="aipkit_settings_provider_modal_control">
                                                <?php if ($aipkit_field_type === 'select' && !empty($aipkit_field['model_picker'])) :
                                                    $aipkit_picker_value = (string) ($aipkit_field['value'] ?? '');
                                                    $aipkit_picker_options = (array) ($aipkit_field['options'] ?? []);
                                                    $aipkit_picker_label = isset($aipkit_picker_options[$aipkit_picker_value])
                                                        ? (string) $aipkit_picker_options[$aipkit_picker_value]
                                                        : ($aipkit_picker_value !== '' ? $aipkit_picker_value : __('No fallback', 'gpt3-ai-content-generator'));
                                                    ?>
                                                    <div class="aipkit_settings_provider_modal_input_wrap aipkit_settings_provider_modal_model_picker">
                                                        <select
                                                            id="<?php echo esc_attr($aipkit_field_id); ?>"
                                                            name="<?php echo esc_attr((string) $aipkit_field['name']); ?>"
                                                            class="aipkit_form-input aipkit_autosave_trigger"
                                                            data-aipkit-settings-provider-model="OpenRouter"
                                                            hidden
                                                            aria-hidden="true"
                                                            tabindex="-1"
                                                        >
                                                            <?php foreach ($aipkit_picker_options as $aipkit_option_value => $aipkit_option_label) : ?>
                                                                <option value="<?php echo esc_attr((string) $aipkit_option_value); ?>" <?php selected($aipkit_picker_value, (string) $aipkit_option_value); ?>><?php echo esc_html((string) $aipkit_option_label); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <?php
                                                        $aipkit_unified_model_selector_config = [
                                                            'trigger_id' => $aipkit_field_id . '_trigger',
                                                            'initial_label' => $aipkit_picker_label,
                                                            'source_id' => $aipkit_field_id,
                                                            'class_name' => 'aipkit_settings_provider_unified_model_selector',
                                                            'trigger_aria_label' => __('Choose an OpenRouter fallback model', 'gpt3-ai-content-generator'),
                                                            'show_provider_diagnostics' => false,
                                                            'show_manage_link' => false,
                                                        ];
                                                        include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                                                        unset($aipkit_unified_model_selector_config);
                                                        ?>
                                                        <button
                                                            type="button"
                                                            class="aipkit_settings_icon_button aipkit_settings_provider_reset"
                                                            data-aipkit-reset-target="<?php echo esc_attr($aipkit_field_id); ?>"
                                                            data-default-value=""
                                                            aria-label="<?php esc_attr_e('Clear fallback model', 'gpt3-ai-content-generator'); ?>"
                                                            title="<?php esc_attr_e('Clear fallback model', 'gpt3-ai-content-generator'); ?>"
                                                        >
                                                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                                        </button>
                                                    </div>
                                                <?php elseif ($aipkit_field_type === 'select') : ?>
                                                    <select id="<?php echo esc_attr($aipkit_field_id); ?>" name="<?php echo esc_attr((string) $aipkit_field['name']); ?>" class="aipkit_form-input aipkit_autosave_trigger">
                                                        <?php foreach ((array) ($aipkit_field['options'] ?? []) as $aipkit_option_value => $aipkit_option_label) : ?>
                                                            <option value="<?php echo esc_attr((string) $aipkit_option_value); ?>" <?php selected((string) $aipkit_field['value'], (string) $aipkit_option_value); ?>><?php echo esc_html((string) $aipkit_option_label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php elseif ($aipkit_field_type === 'toggle') : ?>
                                                    <label class="aipkit_switch" for="<?php echo esc_attr($aipkit_field_id); ?>">
                                                        <input
                                                            type="checkbox"
                                                            id="<?php echo esc_attr($aipkit_field_id); ?>"
                                                            name="<?php echo esc_attr((string) $aipkit_field['name']); ?>"
                                                            class="aipkit_autosave_trigger"
                                                            value="1"
                                                            <?php checked((string) $aipkit_field['value'], '1'); ?>
                                                            <?php echo !empty($aipkit_field['controls']) ? 'aria-controls="' . esc_attr((string) $aipkit_field['controls']) . '"' : ''; ?>
                                                        />
                                                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                                    </label>
                                                <?php else : ?>
                                                    <div class="aipkit_settings_provider_modal_input_wrap">
                                                        <input
                                                            type="<?php echo esc_attr($aipkit_field_type); ?>"
                                                            id="<?php echo esc_attr($aipkit_field_id); ?>"
                                                            class="aipkit_form-input aipkit_autosave_trigger<?php echo !empty($aipkit_field['monospace']) ? ' is-monospace' : ''; ?>"
                                                            value="<?php echo esc_attr((string) $aipkit_field['value']); ?>"
                                                            <?php echo !empty($aipkit_field['name']) ? 'name="' . esc_attr((string) $aipkit_field['name']) . '"' : ''; ?>
                                                            <?php echo !empty($aipkit_field['global_name']) ? 'data-aipkit-global-setting="' . esc_attr((string) $aipkit_field['global_name']) . '"' : ''; ?>
                                                            <?php echo isset($aipkit_field['min']) ? 'min="' . esc_attr((string) $aipkit_field['min']) . '"' : ''; ?>
                                                            <?php echo isset($aipkit_field['max']) ? 'max="' . esc_attr((string) $aipkit_field['max']) . '"' : ''; ?>
                                                            <?php echo isset($aipkit_field['step']) ? 'step="' . esc_attr((string) $aipkit_field['step']) . '"' : ''; ?>
                                                        />
                                                        <?php if (!empty($aipkit_field['suffix'])) : ?>
                                                            <span class="aipkit_settings_provider_modal_suffix"><?php echo esc_html((string) $aipkit_field['suffix']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($aipkit_field['reset'])) : ?>
                                                            <button
                                                                type="button"
                                                                class="aipkit_settings_icon_button aipkit_settings_provider_reset"
                                                                data-aipkit-reset-target="<?php echo esc_attr($aipkit_field_id); ?>"
                                                                data-default-value="<?php echo esc_attr((string) ($aipkit_field['default'] ?? '')); ?>"
                                                                aria-label="<?php esc_attr_e('Restore default value', 'gpt3-ai-content-generator'); ?>"
                                                                title="<?php esc_attr_e('Restore default value', 'gpt3-ai-content-generator'); ?>"
                                                            >
                                                                <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($aipkit_provider === 'OpenAI' && (string) $aipkit_field['id'] === 'api_mode') : ?>
                                            <div class="aipkit_settings_openai_api_mode_notice" data-aipkit-openai-api-mode-notice hidden>
                                                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                                <span>
                                                    <?php esc_html_e('Compatibility mode uses /chat/completions. OpenAI-hosted File Search, Web Search, conversation storage/state, and Moderation API are not used. External Knowledge stores remain available.', 'gpt3-ai-content-generator'); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                            </div>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>

        </div>
        <div class="aipkit_settings_provider_modal_footer">
            <div class="aipkit_settings_provider_modal_footer_actions">
                <?php if ($aipkit_can_be_default) : ?>
                    <button type="button" class="aipkit_settings_provider_default_action aipkit_settings_provider_secondary_action"
                        data-aipkit-provider-set-default="<?php echo esc_attr($aipkit_provider); ?>"
                        <?php echo $aipkit_is_default ? 'hidden' : ''; ?>><?php esc_html_e('Make default', 'gpt3-ai-content-generator'); ?></button>
                <?php endif; ?>
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
            </div>
        </div>
    </div>
</div>

            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
