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
    <section class="aipkit_cloud_connection<?php echo $aipkit_cloud_display['connected'] ? ' is-connected' : ''; ?>" id="aipkit_cloud_connection" data-aipkit-settings-autosave-exclude="true">
        <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html(Connection::connection_message($aipkit_cloud_notice)); ?></p>

        <?php if ($aipkit_cloud_display['connected']) :
            $aipkit_cloud_credits = is_array($aipkit_cloud_display['credits']) ? $aipkit_cloud_display['credits'] : null;
            $aipkit_cloud_available = $aipkit_cloud_credits['available'] ?? null;
            // Whole credits, as on the Usage page.
            $aipkit_cloud_to_credits = static function ($units): int { return (int) floor(max(0, (float) $units) / 1000); };
            $aipkit_cloud_allowance = $aipkit_cloud_credits['allowance']['total'] ?? null;
            $aipkit_cloud_refresh = $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null;
            ?>
            <div class="aipkit_cloud_panel_group aipkit_cloud_panel_group--credits">
                <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Credits', 'gpt3-ai-content-generator'); ?></h3>
                <div class="aipkit_cloud_panel_credits">
                    <div class="aipkit_cloud_panel_credits_top">
                        <div class="aipkit_cloud_panel_credits_copy">
                            <span class="aipkit_cloud_panel_credits_label"><?php esc_html_e('Credits left', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit_cloud_panel_credits_value"><?php
                                // Whole credits like the top-bar chip; fractions only below one credit.
                                $aipkit_cloud_units = $aipkit_cloud_available === null ? null : max(0, (int) $aipkit_cloud_available);
                                echo esc_html($aipkit_cloud_units === null ? '—' : ($aipkit_cloud_units >= 1000 ? number_format_i18n(intdiv($aipkit_cloud_units, 1000)) : Connection::format_credits($aipkit_cloud_units)));
                            ?></span>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_purchase">
                            <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                            <input type="hidden" name="action" value="aipkit_cloud_connection">
                            <button type="submit" class="aipkit_btn aipkit_cloud_btn" name="cloud_action" value="checkout"><?php esc_html_e('Buy credits', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                        </form>
                    </div>
                    <?php if ($aipkit_cloud_credits !== null) : ?>
                        <dl class="aipkit_cloud_panel_split">
                            <div class="aipkit_cloud_panel_split_item">
                                <dt><?php esc_html_e('Free this month', 'gpt3-ai-content-generator'); ?></dt>
                                <dd><?php echo esc_html($aipkit_cloud_allowance !== null
                                    /* translators: 1: free credits left, 2: free credits each month. */
                                    ? sprintf(__('%1$s of %2$s', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['free'] ?? 0)), number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_allowance)))
                                    : number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['free'] ?? 0))); ?></dd>
                                <?php if ($aipkit_cloud_refresh) : ?>
                                    <?php /* translators: %s: date the free credits renew, e.g. "Oct 28". */ ?>
                                    <small><?php echo esc_html(sprintf(__('Renews %s', 'gpt3-ai-content-generator'), wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), strtotime((string) $aipkit_cloud_refresh)))); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="aipkit_cloud_panel_split_item">
                                <dt><?php esc_html_e('Purchased', 'gpt3-ai-content-generator'); ?></dt>
                                <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits((int) ($aipkit_cloud_credits['purchased'] ?? 0) + (int) ($aipkit_cloud_credits['adjustments'] ?? 0)))); ?></dd>
                                <small><?php esc_html_e('Never expire', 'gpt3-ai-content-generator'); ?></small>
                            </div>
                        </dl>
                    <?php endif; ?>
                    <a class="aipkit_cloud_panel_credits_link" href="<?php echo esc_url(Connection::account_url()); ?>" data-aipkit-open-module="stats"><?php esc_html_e('See usage and history', 'gpt3-ai-content-generator'); ?></a>
                </div>
            </div>
            <div class="aipkit_cloud_panel_group aipkit_cloud_panel_group--account">
                <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Account', 'gpt3-ai-content-generator'); ?></h3>
                <?php echo Connection::email_recovery_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
                <?php echo Connection::account_email_html(false, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
            </div>
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
            <div class="aipkit_cloud_hero">
                <span class="aipkit_cloud_hero_eyebrow"><?php esc_html_e('Free every month', 'gpt3-ai-content-generator'); ?></span>
                <p class="aipkit_cloud_hero_title"><?php esc_html_e('25 credits, no API key', 'gpt3-ai-content-generator'); ?></p>
                <p class="aipkit_cloud_hero_text"><?php esc_html_e('Leading models from OpenAI, Anthropic, Google and more, in Chatbots, Content Writer, AI Forms and Automations.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <?php echo Connection::account_email_html(false, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
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
                <button class="aipkit_btn aipkit_cloud_btn<?php echo $aipkit_cloud_display['confirm_email'] ? '' : ' aipkit_cloud_btn--primary aipkit_cloud_btn--block'; ?>" name="cloud_action" value="connect" disabled><?php echo esc_html($aipkit_cloud_display['confirm_email'] ? __('Resend email', 'gpt3-ai-content-generator') : __('Get 25 free credits', 'gpt3-ai-content-generator')); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
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

$aipkit_endpoint_fields = static function (string $provider, string $slug) use ($aipkit_provider_data, $aipkit_provider_defaults): array {
    $data = $aipkit_provider_data[$provider] ?? [];
    $defaults = $aipkit_provider_defaults[$provider] ?? [];

    return [
        [
            'id' => 'base_url',
            'type' => 'text',
            'name' => $slug . '_base_url',
            'label' => __('Address', 'gpt3-ai-content-generator'),
            'description' => __('Change only for a proxy or a compatible service.', 'gpt3-ai-content-generator'),
            'group' => 'address',
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
            'description' => __('Leave as is unless your service needs another version.', 'gpt3-ai-content-generator'),
            'group' => 'address',
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
            ? __('The deployment you created in Azure AI Foundry.', 'gpt3-ai-content-generator')
            : __('Used when a tool doesn’t choose its own model.', 'gpt3-ai-content-generator'),
        'value' => (string) (($aipkit_provider_data[$provider] ?? [])['model'] ?? ''),
    ];
};

$aipkit_openrouter_fallback_options = [
    '' => __('None', 'gpt3-ai-content-generator'),
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
            [
                [
                    'id' => 'moderation',
                    'type' => 'toggle',
                    'name' => 'security[openai_moderation_enabled]',
                    'label' => __('Check messages for harmful content', 'gpt3-ai-content-generator'),
                    'description' => __('Uses OpenAI moderation before answering.', 'gpt3-ai-content-generator'),
                    'value' => $aipkit_moderation_enabled,
                    'controls' => 'aipkit_settings_openai_moderation_message_row',
                    'off_in_compatibility_mode' => true,
                    'group' => 'options',
                ],
                [
                    'id' => 'moderation_message',
                    'type' => 'text',
                    'name' => 'security[openai_moderation_message]',
                    'label' => __('Message shown instead', 'gpt3-ai-content-generator'),
                    'description' => __('Visitors see this when a message is blocked.', 'gpt3-ai-content-generator'),
                    'value' => $aipkit_moderation_message,
                    'row_id' => 'aipkit_settings_openai_moderation_message_row',
                    'follows' => true,
                    'hidden' => $aipkit_moderation_enabled !== '1',
                    'group' => 'options',
                ],
                [
                    'id' => 'store_conversation',
                    'type' => 'toggle',
                    'name' => 'openai_store_conversation',
                    'label' => __('Save chats on OpenAI', 'gpt3-ai-content-generator'),
                    'description' => __('Lets OpenAI keep the conversation so a chat can continue.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openai_data['store_conversation'] ?? '0'),
                    'off_in_compatibility_mode' => true,
                    'group' => 'options',
                ],
                [
                    'id' => 'api_mode',
                    'type' => 'toggle_value',
                    'name' => 'openai_api_mode',
                    'label' => __('Compatibility mode', 'gpt3-ai-content-generator'),
                    'description' => __('Uses Chat Completions, for OpenAI-compatible services. File search, web search, saved chats and moderation turn off.', 'gpt3-ai-content-generator'),
                    'value' => \WPAICG\AIPKit_Providers::normalize_openai_api_mode($openai_data['api_mode'] ?? null),
                    'on_value' => 'chat_completions',
                    'off_value' => 'responses',
                    // With the address it points at another service; the everyday options stay plain.
                    'group' => 'address',
                ],
            ],
            $aipkit_paid_provider_fields['OpenAI'] ?? []
        ),
        'tagline' => __('GPT models for chat, writing and images.', 'gpt3-ai-content-generator'),
        'groups' => [
            'address' => [
                'title' => __('Using an OpenAI-compatible service?', 'gpt3-ai-content-generator'),
                'hint' => __('For proxies and services that copy OpenAI’s API.', 'gpt3-ai-content-generator'),
            ],
        ],
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
        'tagline' => __('Claude models for chat and writing.', 'gpt3-ai-content-generator'),
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
                    'id' => 'fallback_model_1',
                    'type' => 'select',
                    'name' => 'openrouter_fallback_model_1',
                    'label' => __('First backup', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['fallback_model_1'] ?? ''),
                    'options' => $aipkit_openrouter_fallback_options,
                    'model_picker' => true,
                    'group' => 'backups',
                ],
                [
                    'id' => 'fallback_model_2',
                    'type' => 'select',
                    'name' => 'openrouter_fallback_model_2',
                    'label' => __('Second backup', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['fallback_model_2'] ?? ''),
                    'options' => $aipkit_openrouter_fallback_options,
                    'model_picker' => true,
                    'group' => 'backups',
                ],
                [
                    'id' => 'fallback_model_3',
                    'type' => 'select',
                    'name' => 'openrouter_fallback_model_3',
                    'label' => __('Third backup', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['fallback_model_3'] ?? ''),
                    'options' => $aipkit_openrouter_fallback_options,
                    'model_picker' => true,
                    'group' => 'backups',
                ],
                [
                    'id' => 'allow_fallbacks',
                    'type' => 'toggle',
                    'name' => 'openrouter_allow_fallbacks',
                    'label' => __('Try another provider for the same model', 'gpt3-ai-content-generator'),
                    'description' => __('If one host of a model is down, OpenRouter uses another.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['allow_fallbacks'] ?? '1'),
                    'group' => 'backups',
                ],
                [
                    'id' => 'data_collection',
                    'type' => 'toggle_value',
                    'name' => 'openrouter_data_collection',
                    'label' => __('Skip providers that collect data', 'gpt3-ai-content-generator'),
                    'description' => __('Text requests only go to providers that don’t keep your data.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['data_collection'] ?? 'allow'),
                    'on_value' => 'deny',
                    'off_value' => 'allow',
                    'group' => 'privacy',
                ],
                [
                    'id' => 'zdr',
                    'type' => 'toggle',
                    'name' => 'openrouter_zdr',
                    'label' => __('Zero data retention only', 'gpt3-ai-content-generator'),
                    'description' => __('Only hosts that store no prompt data. Web search and other tools have their own policies.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['zdr'] ?? '0'),
                    'group' => 'privacy',
                ],
                [
                    'id' => 'require_parameters',
                    'type' => 'toggle',
                    'name' => 'openrouter_require_parameters',
                    'label' => __('Only providers that support every setting', 'gpt3-ai-content-generator'),
                    'description' => __('More consistent answers, with fewer providers to choose from.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($openrouter_data['require_parameters'] ?? '0'),
                    'group' => 'privacy',
                ],
            ]
        ),
        'tagline' => __('Hundreds of models through one key.', 'gpt3-ai-content-generator'),
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
                'label' => __('Save chats on Google', 'gpt3-ai-content-generator'),
                'description' => __('Lets Google keep the conversation history.', 'gpt3-ai-content-generator'),
                'value' => (string) ($google_data['store_conversation'] ?? '0'),
                'group' => 'options',
            ]]
        ),
        'tagline' => __('Gemini models for chat, writing and images.', 'gpt3-ai-content-generator'),
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
        'key_link' => __('Get your Azure AI API key', 'gpt3-ai-content-generator'),
        'fields' => array_merge(
            [$aipkit_build_model_field('Azure', 'azure')],
            [
                [
                    'id' => 'endpoint',
                    'type' => 'url',
                    'name' => 'azure_endpoint',
                    'label' => __('Endpoint', 'gpt3-ai-content-generator'),
                    'description' => __('Your resource endpoint, from Azure AI Foundry.', 'gpt3-ai-content-generator'),
                    'value' => (string) ($azure_data['endpoint'] ?? ''),
                    'placeholder' => 'https://your-resource.openai.azure.com',
                    'required' => true,
                    'monospace' => true,
                    'group' => 'connection',
                ],
                [
                    'id' => 'authoring_version',
                    'type' => 'text',
                    'name' => 'azure_api_version_authoring',
                    'label' => __('Authoring', 'gpt3-ai-content-generator'),
                    'description' => __('Deployment management version.', 'gpt3-ai-content-generator'),
                    'group' => 'versions',
                    'value' => (string) ($azure_data['api_version_authoring'] ?? ''),
                    'default' => (string) ($azure_defaults['api_version_authoring'] ?? ''),
                    'monospace' => true,
                    'reset' => true,
                ],
                [
                    'id' => 'inference_version',
                    'type' => 'text',
                    'name' => 'azure_api_version_inference',
                    'label' => __('Inference', 'gpt3-ai-content-generator'),
                    'description' => __('Model request version.', 'gpt3-ai-content-generator'),
                    'group' => 'versions',
                    'value' => (string) ($azure_data['api_version_inference'] ?? ''),
                    'default' => (string) ($azure_defaults['api_version_inference'] ?? ''),
                    'monospace' => true,
                    'reset' => true,
                ],
                [
                    'id' => 'images_version',
                    'type' => 'text',
                    'name' => 'azure_api_version_images',
                    'label' => __('Images', 'gpt3-ai-content-generator'),
                    'description' => __('Image request version.', 'gpt3-ai-content-generator'),
                    'group' => 'versions',
                    'value' => (string) ($azure_data['api_version_images'] ?? ''),
                    'default' => (string) ($azure_defaults['api_version_images'] ?? ''),
                    'monospace' => true,
                    'reset' => true,
                ],
            ]
        ),
        'tagline' => __('Your own model deployments on Microsoft Azure.', 'gpt3-ai-content-generator'),
    ],
    'Ollama' => [
        'slug' => 'ollama',
        'display_name' => __('Ollama', 'gpt3-ai-content-generator'),
        'icon' => 'ollama.svg',
        'accent' => '#111827',
        'credential_key' => 'base_url',
        'credential_name' => 'ollama_base_url',
        'credential_type' => 'url',
        'credential_placeholder' => 'http://localhost:11434',
        'key_url' => 'https://ollama.com/download',
        'key_link' => __('Install Ollama', 'gpt3-ai-content-generator'),
        'credential_help' => __('Where Ollama runs: this computer, your server, or a tunnel to it. No API key needed.', 'gpt3-ai-content-generator'),
        'tagline' => __('Open models running on your own machine.', 'gpt3-ai-content-generator'),
        'fields' => $aipkit_paid_provider_fields['Ollama'] ?? [],
        'requires_pro' => true,
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
        'tagline' => __('DeepSeek models for chat and reasoning.', 'gpt3-ai-content-generator'),
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
        'tagline' => __('Grok models for chat and writing.', 'gpt3-ai-content-generator'),
    ],
];

if (class_exists(Connection::class) && Connection::allowed()) {
    $aipkit_provider_configs = ['AIPufferCloud' => [
        'slug' => 'aipuffercloud', 'display_name' => __('AI Puffer Cloud', 'gpt3-ai-content-generator'),
        'icon' => '', 'accent' => '#f28c28', 'credential_key' => 'api_key',
        'credential_name' => '', 'credential_type' => 'account',
        'tagline' => __('No API key needed. 25 free credits every month.', 'gpt3-ai-content-generator'),
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

// Groups in panel order; the folded ones sit at the end, closed.
$aipkit_option_groups = [
    'options' => ['title' => __('Options', 'gpt3-ai-content-generator'), 'hint' => ''],
    'backups' => ['title' => __('If the model fails', 'gpt3-ai-content-generator'), 'hint' => __('Backups are tried in order. The model that answers sets the price.', 'gpt3-ai-content-generator')],
    'privacy' => ['title' => __('Privacy and routing', 'gpt3-ai-content-generator'), 'hint' => ''],
    'address' => ['title' => __('Custom address', 'gpt3-ai-content-generator'), 'folded' => true],
    'versions' => ['title' => __('API versions', 'gpt3-ai-content-generator'), 'folded' => true],
];
$aipkit_get_field_groups = static function (array $config) use ($aipkit_option_groups): array {
    $groups = ['connection' => []];
    foreach ((array) ($config['fields'] ?? []) as $field) {
        if (($field['type'] ?? '') === 'model') {
            continue;
        }
        $group = (string) ($field['group'] ?? 'options');
        $groups[isset($aipkit_option_groups[$group]) || $group === 'connection' ? $group : 'options'][] = $field;
    }
    return $groups;
};

// One option row. Toggles read as a card with a switch; text and pickers stack under their label.
$aipkit_render_option_field = static function (array $field, string $slug): void {
    $field_id = 'aipkit_' . $slug . '_' . (string) $field['id'];
    $type = (string) $field['type'];
    $is_toggle = in_array($type, ['toggle', 'toggle_value'], true);
    $is_inline = $is_toggle || $type === 'number' || ($type === 'select' && empty($field['model_picker']));
    ?>
    <div
        class="aipkit_settings_provider_option<?php echo $is_inline ? ' is-inline' : ''; ?><?php echo !empty($field['follows']) ? ' is-follow-up' : ''; ?>"
        data-aipkit-provider-field-id="<?php echo esc_attr((string) $field['id']); ?>"
        <?php echo !empty($field['row_id']) ? 'id="' . esc_attr((string) $field['row_id']) . '"' : ''; ?>
        <?php echo !empty($field['off_in_compatibility_mode']) ? 'data-aipkit-off-in-compatibility-mode' : ''; ?>
        <?php echo !empty($field['hidden']) ? 'hidden' : ''; ?>
    >
        <div class="aipkit_settings_provider_option_copy">
            <label class="aipkit_settings_provider_option_label" for="<?php echo esc_attr($field_id); ?><?php echo !empty($field['model_picker']) ? '_trigger' : ''; ?>"><?php echo esc_html((string) $field['label']); ?></label>
            <?php if (!empty($field['description'])) : ?>
                <span class="aipkit_settings_provider_option_help"><?php echo esc_html((string) $field['description']); ?></span>
            <?php endif; ?>
            <?php if (!empty($field['off_in_compatibility_mode'])) : ?>
                <span class="aipkit_settings_provider_option_note" data-aipkit-compatibility-note hidden><?php esc_html_e('Off while compatibility mode is on.', 'gpt3-ai-content-generator'); ?></span>
            <?php endif; ?>
        </div>
        <div class="aipkit_settings_provider_option_control">
            <?php if ($type === 'toggle' || $type === 'toggle_value') :
                $on_value = (string) ($field['on_value'] ?? '1');
                $checked = $type === 'toggle' ? (string) $field['value'] === '1' : (string) $field['value'] === $on_value;
                ?>
                <?php if ($type === 'toggle_value') : ?>
                    <input type="hidden" name="<?php echo esc_attr((string) $field['name']); ?>" value="<?php echo esc_attr((string) $field['value']); ?>" data-aipkit-toggle-value-source="<?php echo esc_attr($field_id); ?>" />
                <?php endif; ?>
                <label class="aipkit_switch" for="<?php echo esc_attr($field_id); ?>">
                    <input
                        type="checkbox"
                        id="<?php echo esc_attr($field_id); ?>"
                        class="aipkit_autosave_trigger"
                        value="1"
                        <?php if ($type === 'toggle') : ?>
                            name="<?php echo esc_attr((string) $field['name']); ?>"
                        <?php else : ?>
                            data-aipkit-toggle-value-on="<?php echo esc_attr($on_value); ?>"
                            data-aipkit-toggle-value-off="<?php echo esc_attr((string) ($field['off_value'] ?? '')); ?>"
                        <?php endif; ?>
                        <?php checked($checked); ?>
                        <?php echo !empty($field['controls']) ? 'aria-controls="' . esc_attr((string) $field['controls']) . '"' : ''; ?>
                    />
                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                </label>
            <?php elseif ($type === 'select' && !empty($field['model_picker'])) :
                $picker_value = (string) ($field['value'] ?? '');
                $picker_options = (array) ($field['options'] ?? []);
                $picker_label = isset($picker_options[$picker_value]) ? (string) $picker_options[$picker_value] : ($picker_value !== '' ? $picker_value : __('None', 'gpt3-ai-content-generator'));
                ?>
                <div class="aipkit_settings_provider_option_picker">
                    <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr((string) $field['name']); ?>" class="aipkit_form-input aipkit_autosave_trigger" data-aipkit-settings-provider-model="OpenRouter" hidden aria-hidden="true" tabindex="-1">
                        <?php foreach ($picker_options as $option_value => $option_label) : ?>
                            <option value="<?php echo esc_attr((string) $option_value); ?>" <?php selected($picker_value, (string) $option_value); ?>><?php echo esc_html((string) $option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php
                    $aipkit_unified_model_selector_config = [
                        'trigger_id' => $field_id . '_trigger',
                        'initial_label' => $picker_label,
                        'source_id' => $field_id,
                        'class_name' => 'aipkit_settings_provider_unified_model_selector',
                        'trigger_aria_label' => (string) $field['label'],
                        'show_provider_diagnostics' => false,
                        'show_manage_link' => false,
                        'simple' => true,
                    ];
                    include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                    unset($aipkit_unified_model_selector_config);
                    ?>
                    <button type="button" class="aipkit_settings_icon_button aipkit_settings_provider_reset" data-aipkit-reset-target="<?php echo esc_attr($field_id); ?>" data-default-value="" aria-label="<?php esc_attr_e('Clear this backup', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Clear this backup', 'gpt3-ai-content-generator'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </div>
            <?php elseif ($type === 'select') : ?>
                <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr((string) $field['name']); ?>" class="aipkit_form-input aipkit_autosave_trigger">
                    <?php foreach ((array) ($field['options'] ?? []) as $option_value => $option_label) : ?>
                        <option value="<?php echo esc_attr((string) $option_value); ?>" <?php selected((string) $field['value'], (string) $option_value); ?>><?php echo esc_html((string) $option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <div class="aipkit_settings_provider_option_input">
                    <input
                        type="<?php echo esc_attr($type); ?>"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr((string) $field['name']); ?>"
                        class="aipkit_form-input aipkit_autosave_trigger<?php echo !empty($field['monospace']) ? ' is-monospace' : ''; ?>"
                        value="<?php echo esc_attr((string) $field['value']); ?>"
                        <?php echo !empty($field['placeholder']) ? 'placeholder="' . esc_attr((string) $field['placeholder']) . '"' : ''; ?>
                        <?php echo !empty($field['required']) ? 'required' : ''; ?>
                        <?php echo isset($field['min']) ? 'min="' . esc_attr((string) $field['min']) . '"' : ''; ?>
                        <?php echo isset($field['max']) ? 'max="' . esc_attr((string) $field['max']) . '"' : ''; ?>
                        <?php echo isset($field['step']) ? 'step="' . esc_attr((string) $field['step']) . '"' : ''; ?>
                    />
                    <?php if (!empty($field['suffix'])) : ?>
                        <span class="aipkit_settings_provider_option_suffix"><?php echo esc_html((string) $field['suffix']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($field['reset'])) : ?>
                        <button type="button" class="aipkit_settings_icon_button aipkit_settings_provider_reset" data-aipkit-reset-target="<?php echo esc_attr($field_id); ?>" data-default-value="<?php echo esc_attr((string) ($field['default'] ?? '')); ?>" aria-label="<?php esc_attr_e('Restore default value', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Restore default value', 'gpt3-ai-content-generator'); ?>">
                            <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
};

// A folded group names what it holds, so nobody opens it to check the defaults. Switches lead, as on or off.
$aipkit_fold_summary = static function (array $fields): string {
    $switches = [];
    $values = [];
    foreach ($fields as $field) {
        if (in_array($field['type'] ?? '', ['toggle', 'toggle_value'], true)) {
            $on = ($field['type'] === 'toggle') ? (string) $field['value'] === '1' : (string) $field['value'] === (string) ($field['on_value'] ?? '1');
            $switches[] = $on
                /* translators: %s: a setting's name, e.g. "Compatibility mode". */
                ? sprintf(__('%s on', 'gpt3-ai-content-generator'), (string) $field['label'])
                /* translators: %s: a setting's name, e.g. "Compatibility mode". */
                : sprintf(__('%s off', 'gpt3-ai-content-generator'), (string) $field['label']);
            continue;
        }
        $value = (string) ($field['value'] ?? '') !== '' ? (string) $field['value'] : (string) ($field['default'] ?? '');
        if ($value === '') {
            continue;
        }
        $host = wp_parse_url($value, PHP_URL_HOST);
        $values[] = is_string($host) && $host !== '' ? $host : $value;
    }
    return implode(' · ', array_merge($switches, $values));
};

$aipkit_provider_icon_url = static function (array $config): string {
    if (($config['credential_type'] ?? '') === 'account') {
        return WPAICG_LOGO_URL;
    }
    $relative_path = 'admin/images/providers/' . $config['icon'];
    $version = file_exists(WPAICG_PLUGIN_DIR . $relative_path) ? filemtime(WPAICG_PLUGIN_DIR . $relative_path) : false;
    return add_query_arg('ver', (string) ($version ?: WPAICG_VERSION), WPAICG_PLUGIN_URL . $relative_path);
};

// The label the model picker shows for the saved model: the selected option, or the first one, as a browser picks it.
$aipkit_model_label = static function (string $provider, string $value): string {
    $payload = \WPAICG\AIPKit_Provider_Model_List_Builder::get_model_options($provider, $value);
    $manual = is_array($payload['manual_option'] ?? null) ? $payload['manual_option'] : [];
    if ((string) ($manual['value'] ?? '') !== '') {
        return trim((string) ($manual['label'] ?? $manual['value']));
    }
    $first = null;
    foreach ((array) ($payload['groups'] ?? []) as $group) {
        foreach ((array) ($group['options'] ?? []) as $option) {
            if (!is_array($option) || (string) ($option['value'] ?? '') === '') {
                continue;
            }
            $first = $first ?? $option;
            if (!empty($option['selected'])) {
                return trim((string) ($option['label'] ?? $option['value']));
            }
        }
    }
    return $first ? trim((string) ($first['label'] ?? $first['value'])) : '';
};

// Each provider's row: connection, entitlement and the model name it shows. JS keeps the rows in step.
$aipkit_provider_rows = [];
$aipkit_provider_status = \WPAICG\AIPKit_Providers::get_provider_status_map();
foreach ($aipkit_provider_configs as $aipkit_provider => $aipkit_config) {
    $aipkit_locked = !empty($aipkit_config['requires_pro']) && !$is_pro;
    $aipkit_account_provider = $aipkit_config['credential_type'] === 'account';
    if ($aipkit_account_provider) {
        $aipkit_connected = (bool) Connection::display()['connected'];
    } elseif ($aipkit_config['credential_type'] === 'url') {
        // A server address has a default, so it proves nothing: Ollama counts once a model sync reached it.
        $aipkit_connected = !empty($aipkit_provider_status[$aipkit_config['slug']]);
    } else {
        $aipkit_connected = (string) ($aipkit_provider_data[$aipkit_provider][$aipkit_config['credential_key']] ?? '') !== '';
    }
    $aipkit_provider_rows[$aipkit_provider] = [
        'icon' => $aipkit_provider_icon_url($aipkit_config),
        'locked' => $aipkit_locked,
        'connected' => $aipkit_connected,
        'can_be_default' => !$aipkit_locked && ($aipkit_account_provider || in_array($aipkit_provider, (array) $main_provider_allowlist, true)),
        'model_label' => !$aipkit_locked && $aipkit_get_model_field($aipkit_config)
            ? $aipkit_model_label($aipkit_provider, (string) ($aipkit_get_model_field($aipkit_config)['value'] ?? ''))
            : '',
    ];
}

// The page state is rendered complete, so the first paint doesn't wait for the script.
$aipkit_ai_connected = array_filter($aipkit_provider_rows, static fn (array $item): bool => !$item['locked'] && $item['connected']);
$aipkit_ai_state = $aipkit_ai_connected ? 'ready' : 'empty';
?>

<div class="aipkit_settings_ai" data-aipkit-settings-ai data-state="<?php echo esc_attr($aipkit_ai_state); ?>">
    <div class="aipkit_settings_part aipkit_settings_ai_list_part">
    <h4 class="aipkit_settings_group_title"><?php esc_html_e('Providers', 'gpt3-ai-content-generator'); ?></h4>
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
    <?php foreach ($aipkit_provider_configs as $aipkit_provider => $aipkit_config) :
        $aipkit_account_provider = $aipkit_config['credential_type'] === 'account';
        $aipkit_slug = (string) $aipkit_config['slug'];
        $aipkit_icon_url = $aipkit_provider_rows[$aipkit_provider]['icon'];
        $aipkit_data = $aipkit_provider_data[$aipkit_provider] ?? [];
        $aipkit_credential = (string) ($aipkit_data[$aipkit_config['credential_key']] ?? '');
        $aipkit_connected = $aipkit_provider_rows[$aipkit_provider]['connected'];
        if ($aipkit_provider_rows[$aipkit_provider]['locked']) :
            ?>
            <div class="aipkit_settings_provider_card aipkit_settings_provider_card--locked" data-aipkit-provider-locked="<?php echo esc_attr($aipkit_provider); ?>">
                <div class="aipkit_settings_provider_card_main">
                    <span class="aipkit_settings_provider_logo" aria-hidden="true"><img src="<?php echo esc_url($aipkit_icon_url); ?>" alt="" /></span>
                    <div class="aipkit_settings_provider_card_text">
                        <div class="aipkit_settings_provider_card_title">
                            <h4 class="aipkit_settings_provider_name"><?php echo esc_html((string) $aipkit_config['display_name']); ?></h4>
                            <span class="aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <p class="aipkit_settings_provider_card_status"><span class="aipkit_settings_provider_tagline"><?php echo esc_html((string) ($aipkit_config['tagline'] ?? '')); ?></span></p>
                    </div>
                    <a class="aipkit_settings_provider_card_connect aipkit_settings_provider_card_upgrade" href="<?php echo esc_url(admin_url('admin.php?page=wpaicg-pricing')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></a>
                </div>
            </div>
            <?php
            continue;
        endif;
        $aipkit_can_be_default = $aipkit_provider_rows[$aipkit_provider]['can_be_default'];
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
        $aipkit_field_groups = $aipkit_get_field_groups($aipkit_config);
        ?>
        <article
            id="aipkit_settings_provider_card_<?php echo esc_attr(sanitize_title($aipkit_provider)); ?>"
            class="aipkit_settings_provider_card"
            data-aipkit-provider-card="<?php echo esc_attr($aipkit_provider); ?>"
            data-aipkit-provider-connected="<?php echo $aipkit_connected ? 'true' : 'false'; ?>"
            data-aipkit-provider-default="<?php echo $aipkit_is_default ? 'true' : 'false'; ?>"
            style="--aipkit-provider-accent: <?php echo esc_attr((string) $aipkit_config['accent']); ?>;"
        >
            <?php
                $aipkit_modal_id = 'aipkit_settings_' . $aipkit_slug . '_modal';
                $aipkit_modal_title_id = $aipkit_modal_id . '_title';
                $aipkit_credential_id = 'aipkit_settings_' . $aipkit_slug . '_credential';
                $aipkit_credential_label = $aipkit_is_secret
                    ? __('API key', 'gpt3-ai-content-generator')
                    : __('Server address', 'gpt3-ai-content-generator');
            ?>
            <div class="aipkit_settings_provider_card_main">
                <span class="aipkit_settings_provider_logo" aria-hidden="true">
                    <img src="<?php echo esc_url($aipkit_icon_url); ?>" alt="" />
                </span>
                <div class="aipkit_settings_provider_card_text">
                    <div class="aipkit_settings_provider_card_title">
                        <h4 class="aipkit_settings_provider_name"><?php echo esc_html((string) $aipkit_config['display_name']); ?></h4>
                        <?php if ($aipkit_account_provider) : ?>
                            <span class="aipkit_settings_provider_free"><?php esc_html_e('Free credits', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
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
                            class="aipkit_settings_provider_summary<?php echo $aipkit_provider_rows[$aipkit_provider]['model_label'] === '' ? ' is-empty' : ''; ?>"
                            data-aipkit-provider-summary
                            data-empty-label="<?php esc_attr_e('No model selected', 'gpt3-ai-content-generator'); ?>"
                            <?php echo $aipkit_connected ? '' : 'hidden'; ?>
                        ><?php echo esc_html($aipkit_provider_rows[$aipkit_provider]['model_label'] ?: __('No model selected', 'gpt3-ai-content-generator')); ?></span>
                        <span class="aipkit_settings_provider_tagline"><?php echo esc_html((string) ($aipkit_config['tagline'] ?? '')); ?></span>
                    </p>
                </div>
                <button
                    type="button"
                    class="aipkit_settings_provider_card_action"
                    data-aipkit-provider-settings-open="<?php echo esc_attr($aipkit_provider); ?>"
                    aria-haspopup="dialog"
                    aria-controls="<?php echo esc_attr($aipkit_modal_id); ?>"
                >
                    <span class="aipkit_settings_provider_card_manage">
                        <?php /* translators: %s: AI provider name. */ ?>
                        <span class="screen-reader-text"><?php echo esc_html(sprintf(__('Manage %s', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name'])); ?></span>
                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </span>
                    <span class="aipkit_settings_provider_card_connect">
                        <?php esc_html_e('Connect', 'gpt3-ai-content-generator'); ?>
                        <span class="screen-reader-text"><?php echo esc_html((string) $aipkit_config['display_name']); ?></span>
                    </span>
                </button>
            </div>

            <div
                class="aipkit-modal-overlay aipkit_settings_provider_modal"
                id="<?php echo esc_attr($aipkit_modal_id); ?>"
                data-aipkit-provider-modal="<?php echo esc_attr($aipkit_provider); ?>"
                aria-hidden="true"
            >
                <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($aipkit_modal_title_id); ?>">
                    <div class="aipkit_settings_provider_panel_header">
                        <span class="aipkit_settings_provider_logo" aria-hidden="true"><img src="<?php echo esc_url($aipkit_icon_url); ?>" alt="" /></span>
                        <div class="aipkit_settings_provider_panel_heading">
                            <div class="aipkit_settings_provider_panel_title_row">
                                <h2 class="aipkit_settings_provider_panel_title" id="<?php echo esc_attr($aipkit_modal_title_id); ?>"><?php echo esc_html((string) $aipkit_config['display_name']); ?></h2>
                                <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--on"><?php esc_html_e('Connected', 'gpt3-ai-content-generator'); ?></span>
                                <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--off"><?php esc_html_e('Not connected', 'gpt3-ai-content-generator'); ?></span>
                                <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--invalid"><?php esc_html_e('Key rejected', 'gpt3-ai-content-generator'); ?></span>
                                <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--error"><?php esc_html_e('Sync failed', 'gpt3-ai-content-generator'); ?></span>
                            </div>
                            <p class="aipkit_settings_provider_panel_hint"><?php echo esc_html((string) ($aipkit_config['tagline'] ?? '')); ?></p>
                        </div>
                        <button type="button" class="aipkit_settings_provider_panel_close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                    </div>

                    <div class="aipkit_settings_provider_panel_body">
                        <section class="aipkit_settings_provider_panel_section aipkit_settings_provider_connection_fields<?php echo $aipkit_account_provider ? ' aipkit_settings_cloud_fields' : ''; ?>">
                            <?php if (!$aipkit_account_provider) : ?>
                                <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Connection', 'gpt3-ai-content-generator'); ?></h3>
                            <?php endif; ?>
                            <?php if ($aipkit_account_provider) : ?>
                                <?php $aipkit_render_account_fields(); ?>
                            <?php else : ?>
                                <?php // The key always comes first, with Connect beside it and its get-a-key link; fields a provider also needs (Azure's endpoint) follow. ?>
                                <div class="aipkit_settings_provider_field">
                                    <label class="aipkit_settings_provider_option_label" for="<?php echo esc_attr($aipkit_credential_id); ?>"><?php echo esc_html($aipkit_credential_label); ?></label>
                                    <div class="aipkit_settings_provider_credential_row">
                                        <div class="aipkit_settings_provider_credential_wrap">
                                            <?php // A saved key shows masked; clicking the field shows it, and clicking away masks it again. ?>
                                            <input
                                                type="<?php echo esc_attr((string) $aipkit_config['credential_type']); ?>"
                                                id="<?php echo esc_attr($aipkit_credential_id); ?>"
                                                name="<?php echo esc_attr($aipkit_credential_input_name); ?>"
                                                class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_provider_credential<?php echo $aipkit_is_secret ? ' is-secret' : ''; ?><?php echo $aipkit_credential_mask !== '' ? ' is-visually-masked' : ''; ?>"
                                                value="<?php echo esc_attr($aipkit_credential_input_value); ?>"
                                                placeholder="<?php echo esc_attr((string) $aipkit_config['credential_placeholder']); ?>"
                                                data-aipkit-provider-credential="<?php echo esc_attr($aipkit_provider); ?>"
                                                data-aipkit-credential-name="<?php echo esc_attr((string) $aipkit_config['credential_name']); ?>"
                                                <?php if ($aipkit_config['credential_type'] === 'url') : ?>data-aipkit-provider-connection-field="<?php echo esc_attr((string) $aipkit_config['credential_key']); ?>"<?php endif; ?>
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
                                        <button
                                            type="button"
                                            class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_connect"
                                            data-aipkit-provider-connect="<?php echo esc_attr($aipkit_provider); ?>"
                                            <?php echo $aipkit_connected ? 'hidden' : ''; ?>
                                        ><?php esc_html_e('Connect', 'gpt3-ai-content-generator'); ?></button>
                                    </div>
                                    <?php if (!empty($aipkit_config['credential_help'])) : ?>
                                        <span class="aipkit_settings_provider_option_help"><?php echo esc_html((string) $aipkit_config['credential_help']); ?></span>
                                    <?php endif; ?>
                                    <?php // The get-a-key link always sits by the field; once a key is saved, how to replace it sits beside it. Disconnect is in the footer. ?>
                                    <div class="aipkit_settings_provider_field_links">
                                        <?php if ($aipkit_is_secret) : ?>
                                            <span class="aipkit_settings_provider_option_help" data-aipkit-provider-connected-only <?php echo $aipkit_connected ? '' : 'hidden'; ?>><?php esc_html_e('Paste a new key to replace it. It saves by itself.', 'gpt3-ai-content-generator'); ?></span>
                                        <?php endif; ?>
                                        <a class="aipkit_settings_provider_key_link" href="<?php echo esc_url((string) $aipkit_config['key_url']); ?>" target="_blank" rel="noopener noreferrer">
                                            <span><?php echo esc_html((string) $aipkit_config['key_link']); ?></span>
                                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                        </a>
                                    </div>
                                </div>
                                <?php foreach ($aipkit_field_groups['connection'] as $aipkit_field) {
                                    $aipkit_render_option_field($aipkit_field, $aipkit_slug);
                                } ?>
                                <div
                                    class="aipkit_settings_provider_error"
                                    data-aipkit-provider-error
                                    <?php /* translators: %s: AI provider display name. */ ?>
                                    data-invalid-message="<?php echo esc_attr(sprintf(__('%s rejected this key. It may be wrong, revoked or out of credit. Check it or create a new one, then paste it again.', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name'])); ?>"
                                    <?php /* translators: %s: AI provider display name. */ ?>
                                    data-sync-message="<?php echo esc_attr(sprintf(__('We couldn’t reach %s. Check the connection and try again.', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name'])); ?>"
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
                        </section>

                        <?php if ($aipkit_model_field) :
                            $aipkit_model_field_id = 'aipkit_' . $aipkit_slug . '_' . ($aipkit_provider === 'Azure' ? 'deployment' : 'model');
                            ?>
                            <section
                                class="aipkit_settings_provider_panel_section aipkit_settings_provider_model_block"
                                data-aipkit-provider-model-block
                                <?php echo $aipkit_connected ? '' : 'hidden'; ?>
                            >
                                <label class="aipkit_settings_provider_panel_section_title" for="<?php echo esc_attr($aipkit_model_field_id); ?>_trigger"><?php echo esc_html((string) $aipkit_model_field['label']); ?></label>
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
                                            'simple' => true,
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
                                            aria-label="<?php esc_attr_e('Refresh the model list', 'gpt3-ai-content-generator'); ?>"
                                            title="<?php esc_attr_e('Refresh the model list', 'gpt3-ai-content-generator'); ?>"
                                            aria-busy="false"
                                        >
                                            <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                    <div class="aipkit_settings_provider_model_meta">
                                        <span class="aipkit_settings_provider_option_help"><?php echo esc_html((string) $aipkit_model_field['description']); ?></span>
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
                            </section>
                        <?php endif; ?>

                        <?php foreach ($aipkit_option_groups as $aipkit_group_key => $aipkit_group) :
                            $aipkit_group_fields = $aipkit_field_groups[$aipkit_group_key] ?? [];
                            if (!$aipkit_group_fields) {
                                continue;
                            }
                            $aipkit_group = array_merge($aipkit_group, (array) ($aipkit_config['groups'][$aipkit_group_key] ?? []));
                            if (!empty($aipkit_group['folded'])) : ?>
                                <details
                                    class="aipkit_settings_provider_fold"
                                    data-aipkit-provider-connected-only
                                    <?php /* translators: %s: a setting's name, e.g. "Compatibility mode". */ ?>
                                    data-on-template="<?php esc_attr_e('%s on', 'gpt3-ai-content-generator'); ?>"
                                    <?php /* translators: %s: a setting's name, e.g. "Compatibility mode". */ ?>
                                    data-off-template="<?php esc_attr_e('%s off', 'gpt3-ai-content-generator'); ?>"
                                    <?php echo $aipkit_connected ? '' : 'hidden'; ?>
                                >
                                    <summary>
                                        <span class="aipkit_settings_provider_option_copy">
                                            <span class="aipkit_settings_provider_option_label"><?php echo esc_html((string) $aipkit_group['title']); ?></span>
                                            <span class="aipkit_settings_provider_option_help"><?php echo esc_html($aipkit_fold_summary($aipkit_group_fields)); ?></span>
                                        </span>
                                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                    </summary>
                                    <div class="aipkit_settings_provider_fold_body">
                                        <?php if (!empty($aipkit_group['hint'])) : ?>
                                            <p class="aipkit_settings_provider_option_help"><?php echo esc_html((string) $aipkit_group['hint']); ?></p>
                                        <?php endif; ?>
                                        <?php foreach ($aipkit_group_fields as $aipkit_field) {
                                            $aipkit_render_option_field($aipkit_field, $aipkit_slug);
                                        } ?>
                                    </div>
                                </details>
                            <?php else : ?>
                                <section class="aipkit_settings_provider_panel_section aipkit_settings_provider_options" data-aipkit-provider-connected-only <?php echo $aipkit_connected ? '' : 'hidden'; ?>>
                                    <h3 class="aipkit_settings_provider_panel_section_title"><?php echo esc_html((string) $aipkit_group['title']); ?></h3>
                                    <?php if (!empty($aipkit_group['hint'])) : ?>
                                        <p class="aipkit_settings_provider_option_help"><?php echo esc_html((string) $aipkit_group['hint']); ?></p>
                                    <?php endif; ?>
                                    <?php // One list, split by hairlines, rather than a box per option. ?>
                                    <div class="aipkit_settings_provider_option_list">
                                        <?php foreach ($aipkit_group_fields as $aipkit_field) {
                                            $aipkit_render_option_field($aipkit_field, $aipkit_slug);
                                        } ?>
                                    </div>
                                </section>
                            <?php endif;
                        endforeach; ?>
                    </div>

                    <div class="aipkit_settings_provider_modal_footer">
                        <?php // Disconnecting sits apart from the key, the same for every provider. Cloud submits its account form. ?>
                        <button
                            <?php if ($aipkit_account_provider) : ?>
                                type="submit"
                                form="aipkit_cloud_account_form"
                                name="cloud_action"
                                value="disconnect"
                            <?php else : ?>
                                type="button"
                                data-aipkit-provider-remove="<?php echo esc_attr($aipkit_provider); ?>"
                            <?php endif; ?>
                            class="aipkit_settings_provider_remove"
                            data-aipkit-provider-connected-only
                            <?php /* translators: %s: AI provider name. */ ?>
                            data-confirm-title="<?php echo esc_attr(sprintf(__('Disconnect %s?', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name'])); ?>"
                            <?php /* translators: %s: AI provider name. */ ?>
                            data-confirm-text="<?php echo esc_attr(sprintf(__('Tools that use %s stop answering until you connect it again.', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name'])); ?>"
                            data-confirm-button="<?php esc_attr_e('Disconnect', 'gpt3-ai-content-generator'); ?>"
                            data-cancel-button="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"
                            <?php echo $aipkit_connected ? '' : 'hidden'; ?>
                        ><?php
                            /* translators: %s: AI provider name. */
                            echo esc_html(sprintf(__('Disconnect %s', 'gpt3-ai-content-generator'), (string) $aipkit_config['display_name']));
                        ?></button>
                        <?php if ($aipkit_can_be_default) : ?>
                            <span class="aipkit_settings_provider_default_note" data-aipkit-provider-default-note <?php echo $aipkit_is_default ? '' : 'hidden'; ?>>
                                <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                <?php esc_html_e('Your default AI', 'gpt3-ai-content-generator'); ?>
                            </span>
                            <button type="button" class="aipkit_settings_provider_default_action aipkit_settings_provider_secondary_action"
                                data-aipkit-provider-set-default="<?php echo esc_attr($aipkit_provider); ?>"
                                <?php echo $aipkit_is_default ? 'hidden' : ''; ?>><?php esc_html_e('Make default', 'gpt3-ai-content-generator'); ?></button>
                        <?php endif; ?>
                        <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
    <div class="aipkit_settings_provider_list_footer">
        <button
            type="button"
            class="aipkit_settings_provider_list_defaults"
            data-aipkit-provider-settings-open="AnswerDefaults"
            aria-haspopup="dialog"
            aria-controls="aipkit_settings_answer_defaults_modal"
        >
            <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
            <span><?php esc_html_e('Advanced', 'gpt3-ai-content-generator'); ?></span>
        </button>
        <button
            type="button"
            id="aipkit_settings_sync_all_models"
            class="aipkit_settings_provider_list_refresh"
            data-aipkit-settings-action="sync-all-models"
        >
            <span class="dashicons dashicons-update" aria-hidden="true"></span>
            <span data-aipkit-button-label><?php esc_html_e('Sync models', 'gpt3-ai-content-generator'); ?></span>
        </button>
    </div>
</div>

    </div>

    <div class="aipkit-modal-overlay aipkit_settings_provider_modal" id="aipkit_settings_answer_defaults_modal" data-aipkit-provider-modal="AnswerDefaults" aria-hidden="true">
        <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="aipkit_settings_answer_defaults_title">
            <div class="aipkit_settings_provider_panel_header">
                <span class="aipkit_settings_provider_logo aipkit_settings_answer_defaults_logo" aria-hidden="true"><span class="dashicons dashicons-admin-settings"></span></span>
                <div class="aipkit_settings_provider_panel_heading">
                    <h2 class="aipkit_settings_provider_panel_title" id="aipkit_settings_answer_defaults_title"><?php esc_html_e('Advanced', 'gpt3-ai-content-generator'); ?></h2>
                    <p class="aipkit_settings_provider_panel_hint"><?php esc_html_e('For every provider, when a tool doesn’t set its own.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <button type="button" class="aipkit_settings_provider_panel_close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
            </div>
            <div class="aipkit_settings_provider_panel_body">
                <section class="aipkit_settings_provider_panel_section">
                    <div class="aipkit_settings_provider_option is-inline">
                        <div class="aipkit_settings_provider_option_copy">
                            <label class="aipkit_settings_provider_option_label" for="aipkit_settings_answer_temperature"><?php esc_html_e('Creativity', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Higher gives more varied answers, lower more focused ones. From 0 to 2.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <div class="aipkit_settings_provider_option_control">
                            <div class="aipkit_settings_provider_option_input">
                                <input type="number" id="aipkit_settings_answer_temperature" name="temperature" class="aipkit_form-input aipkit_autosave_trigger" value="<?php echo esc_attr((string) $temperature); ?>" min="0" max="2" step="0.1" />
                            </div>
                        </div>
                    </div>
                    <div class="aipkit_settings_provider_option is-inline">
                        <div class="aipkit_settings_provider_option_copy">
                            <label class="aipkit_settings_provider_option_label" for="aipkit_settings_answer_top_p"><?php esc_html_e('Top P', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Lower keeps to the likeliest words. Leave at 1 unless you need it. From 0 to 1.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <div class="aipkit_settings_provider_option_control">
                            <div class="aipkit_settings_provider_option_input">
                                <input type="number" id="aipkit_settings_answer_top_p" name="top_p" class="aipkit_form-input aipkit_autosave_trigger" value="<?php echo esc_attr((string) $top_p); ?>" min="0" max="1" step="0.01" />
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <div class="aipkit_settings_provider_modal_footer">
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
            </div>
        </div>
    </div>
</div>
