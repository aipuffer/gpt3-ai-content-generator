<?php
/**
 * For developers: WordPress AI, the REST API and webhooks. Each is a row, like AI and Connections,
 * and opens its side panel; keys and secrets stay masked until you ask to see them.
 */
if (!defined('ABSPATH')) {
    exit;
}

$aipkit_format_developer_credential_mask = static function (string $credential): string {
    $credential = trim($credential);
    if ($credential === '') {
        return '';
    }

    $prefix_length = strpos($credential, 'aipk_live_') === 0 ? 10 : 6;
    if (strlen($credential) <= $prefix_length + 4) {
        return substr($credential, 0, 3) . '••••••••••••';
    }

    return substr($credential, 0, $prefix_length) . '••••••••••••' . substr($credential, -4);
};
$aipkit_public_api_mask = $aipkit_format_developer_credential_mask((string) $public_api_key);
$aipkit_wpai_settings_class = '\\WPAICG\\WP_AI_Client\\AIPKit_WP_AI_Client_Settings';
$aipkit_wpai_available = class_exists($aipkit_wpai_settings_class)
    && $aipkit_wpai_settings_class::is_supported();

// The row and the panel header say the same thing: On, Off, or what needs a look.
$aipkit_render_dev_row = static function (string $key, string $title, string $icon, string $summary, array $summary_data = []): void {
    $modal_id = 'aipkit_settings_' . str_replace('-', '_', $key) . '_modal';
    ?>
    <div class="aipkit_settings_provider_card_main">
        <span class="aipkit_settings_provider_logo aipkit_settings_developer_logo" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
        <div class="aipkit_settings_provider_card_text">
            <div class="aipkit_settings_provider_card_title">
                <h4 class="aipkit_settings_provider_name"><?php echo esc_html($title); ?></h4>
            </div>
            <p class="aipkit_settings_provider_card_status">
                <span
                    class="aipkit_settings_provider_summary"
                    data-aipkit-developer-summary
                    <?php foreach ($summary_data as $attribute => $value) : ?>
                        data-<?php echo esc_attr($attribute); ?>="<?php echo esc_attr($value); ?>"
                    <?php endforeach; ?>
                ><?php echo esc_html($summary); ?></span>
            </p>
        </div>
        <button
            type="button"
            class="aipkit_settings_provider_card_action"
            data-aipkit-provider-settings-open="<?php echo esc_attr($key); ?>"
            aria-haspopup="dialog"
            aria-controls="<?php echo esc_attr($modal_id); ?>"
        >
            <span class="aipkit_settings_provider_card_manage">
                <?php /* translators: %s: a developer feature, e.g. REST API. */ ?>
                <span class="screen-reader-text"><?php echo esc_html(sprintf(__('Manage %s', 'gpt3-ai-content-generator'), $title)); ?></span>
                <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            </span>
        </button>
    </div>
    <?php
};

$aipkit_render_dev_panel_header = static function (string $key, string $title, string $hint, string $icon, bool $back = false): void {
    $title_id = 'aipkit_settings_' . str_replace('-', '_', $key) . '_title';
    ?>
    <div class="aipkit_settings_provider_panel_header">
        <?php if ($back) : ?>
            <button type="button" class="aipkit_settings_provider_panel_close aipkit_settings_developer_back" data-aipkit-developer-back aria-label="<?php esc_attr_e('Back', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>
        <?php endif; ?>
        <span class="aipkit_settings_provider_logo aipkit_settings_developer_logo" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
        <div class="aipkit_settings_provider_panel_heading">
            <div class="aipkit_settings_provider_panel_title_row">
                <h2 class="aipkit_settings_provider_panel_title" id="<?php echo esc_attr($title_id); ?>"><?php echo esc_html($title); ?></h2>
                <?php if (!$back) : ?>
                    <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--on"><?php esc_html_e('On', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--off"><?php esc_html_e('Off', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--error"><?php esc_html_e('Needs a look', 'gpt3-ai-content-generator'); ?></span>
                <?php endif; ?>
            </div>
            <p class="aipkit_settings_provider_panel_hint"><?php echo esc_html($hint); ?></p>
        </div>
        <?php if (!$back) : ?>
            <button type="button" class="aipkit_settings_provider_panel_close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        <?php endif; ?>
    </div>
    <?php
};

// A key or a secret: masked in a full-width field, with Show and Copy beside it, and a new one on request.
$aipkit_render_dev_credential = static function (string $input_id, string $mask, string $noun, string $help, string $new_label): void {
    ?>
    <div class="aipkit_settings_developer_field_row">
        <input
            type="text"
            id="<?php echo esc_attr($input_id); ?>"
            class="aipkit_form-input aipkit_settings_developer_credential_input"
            value="<?php echo esc_attr($mask); ?>"
            data-aipkit-developer-credential-input
            data-credential-mask="<?php echo esc_attr($mask); ?>"
            data-has-credential="<?php echo $mask !== '' ? 'true' : 'false'; ?>"
            readonly
            autocomplete="off"
            spellcheck="false"
        />
        <?php /* translators: %s: what is revealed, e.g. "API key". */ ?>
        <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_settings_developer_button" data-aipkit-developer-reveal data-aipkit-developer-reveal-label="<?php echo esc_attr(sprintf(__('Show %s', 'gpt3-ai-content-generator'), $noun)); ?>" <?php /* translators: %s: what is hidden, e.g. "API key". */ ?> data-aipkit-developer-hide-label="<?php echo esc_attr(sprintf(__('Hide %s', 'gpt3-ai-content-generator'), $noun)); ?>" aria-label="<?php echo esc_attr(sprintf(__('Show %s', 'gpt3-ai-content-generator'), $noun)); ?>">
            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
            <span data-aipkit-developer-reveal-text data-show="<?php esc_attr_e('Show', 'gpt3-ai-content-generator'); ?>" data-hide="<?php esc_attr_e('Hide', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Show', 'gpt3-ai-content-generator'); ?></span>
        </button>
        <?php /* translators: %s: what is copied, e.g. "API key". */ ?>
        <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_settings_developer_button" data-aipkit-developer-copy aria-label="<?php echo esc_attr(sprintf(__('Copy %s', 'gpt3-ai-content-generator'), $noun)); ?>">
            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
            <span data-aipkit-developer-copy-text data-copied="<?php esc_attr_e('Copied', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Copy', 'gpt3-ai-content-generator'); ?></span>
        </button>
    </div>
    <div class="aipkit_settings_developer_field_foot">
        <span class="aipkit_settings_provider_option_help"><?php echo esc_html($help); ?></span>
        <button type="button" class="aipkit_settings_developer_regenerate" data-aipkit-developer-regenerate><?php echo esc_html($new_label); ?></button>
    </div>
    <?php
};

$aipkit_render_dev_error = static function (): void {
    ?>
    <div class="aipkit_settings_provider_error" data-aipkit-developer-error role="alert" hidden>
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <div class="aipkit_settings_provider_error_content"><p data-aipkit-developer-error-text></p></div>
    </div>
    <?php
};

$aipkit_render_dev_footer = static function (string $left = ''): void {
    ?>
    <div class="aipkit_settings_provider_modal_footer">
        <?php echo $left; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped markup by the caller. ?>
        <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
    </div>
    <?php
};

$aipkit_rest_base = untrailingslashit(rest_url('aipkit/v1'));
$aipkit_rest_routes = [
    ['POST', '/generate', __('Write text', 'gpt3-ai-content-generator')],
    ['POST', '/images/generate', __('Make an image', 'gpt3-ai-content-generator')],
    ['POST', '/chat/{bot_id}/message', __('Chat with a chatbot', 'gpt3-ai-content-generator')],
    ['GET', '/chatbots/{bot_id}/embed-config', __('A chatbot’s embed settings', 'gpt3-ai-content-generator')],
    ['POST', '/embeddings', __('Make embeddings', 'gpt3-ai-content-generator')],
    ['POST', '/vector-stores/upsert', __('Add to a vector store', 'gpt3-ai-content-generator')],
    ['POST', '/automations/run', __('Run an automation', 'gpt3-ai-content-generator')],
    ['GET', '/logs', __('Read logs', 'gpt3-ai-content-generator')],
];
?>

<div class="aipkit_settings_provider_cards aipkit_settings_integration_cards aipkit_settings_developer_cards">
<?php if ($aipkit_wpai_available) :
    $aipkit_wpai_managed = $aipkit_wpai_settings_class::is_effectively_managed();
    $aipkit_wpai_logos = [
        'AIPufferCloud' => defined('WPAICG_LOGO_URL') ? WPAICG_LOGO_URL : '',
        'OpenAI' => 'openai.svg', 'Google' => 'google.svg', 'Claude' => 'anthropic.svg', 'OpenRouter' => 'openrouter.svg',
        'Azure' => 'azure.svg', 'DeepSeek' => 'deepseek.svg', 'xAI' => 'xai.svg', 'Ollama' => 'ollama.svg',
    ];
    $aipkit_wpai_offered = [];
    $aipkit_wpai_providers = method_exists($aipkit_wpai_settings_class, 'providers') && method_exists($aipkit_wpai_settings_class, 'provider_has_credentials')
        ? (array) $aipkit_wpai_settings_class::providers()
        : [];
    foreach ($aipkit_wpai_providers as $aipkit_connector_id => $aipkit_connector) {
        if ($aipkit_wpai_settings_class::provider_has_credentials((string) $aipkit_connector_id)) {
            $aipkit_logo = (string) ($aipkit_wpai_logos[(string) ($aipkit_connector['aipkit_provider'] ?? '')] ?? '');
            $aipkit_wpai_offered[] = [
                'name' => (string) ($aipkit_connector['name'] ?? $aipkit_connector_id),
                'logo' => $aipkit_logo !== '' && strpos($aipkit_logo, '://') === false ? WPAICG_PLUGIN_URL . 'admin/images/providers/' . $aipkit_logo : $aipkit_logo,
            ];
        }
    }
    $aipkit_wpai_summary_on = __('On · Other plugins’ AI requests go through AI Puffer', 'gpt3-ai-content-generator');
    $aipkit_wpai_summary_off = __('Off', 'gpt3-ai-content-generator');
    ?>
    <article
        id="aipkit_settings_wp_ai_client_row"
        class="aipkit_settings_provider_card aipkit_settings_integration_card aipkit_settings_developer_card"
        data-aipkit-developer-card="wpai"
        data-aipkit-provider-connected="<?php echo $aipkit_wpai_managed ? 'true' : 'false'; ?>"
        data-aipkit-wpai-control
        data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
        data-nonce="<?php echo esc_attr(wp_create_nonce('aipkit_wp_ai_client_set_mode')); ?>"
        data-mode="<?php echo esc_attr($aipkit_wpai_managed ? 'managed' : 'observe'); ?>"
    >
        <?php $aipkit_render_dev_row('dev-wpai', __('WordPress AI', 'gpt3-ai-content-generator'), 'wordpress-alt', $aipkit_wpai_managed ? $aipkit_wpai_summary_on : $aipkit_wpai_summary_off, ['on' => $aipkit_wpai_summary_on, 'off' => $aipkit_wpai_summary_off]); ?>
        <div class="aipkit-modal-overlay aipkit_settings_provider_modal" id="aipkit_settings_dev_wpai_modal" data-aipkit-provider-modal="dev-wpai" aria-hidden="true">
            <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="aipkit_settings_dev_wpai_title">
                <?php $aipkit_render_dev_panel_header('dev-wpai', __('WordPress AI', 'gpt3-ai-content-generator'), __('Let other plugins use your AI through WordPress.', 'gpt3-ai-content-generator'), 'wordpress-alt'); ?>
                <div class="aipkit_settings_provider_panel_body">
                    <?php $aipkit_render_dev_error(); ?>
                    <div class="aipkit_settings_provider_option_list">
                        <div class="aipkit_settings_provider_option is-inline">
                            <div class="aipkit_settings_provider_option_copy">
                                <label class="aipkit_settings_provider_option_label" for="aipkit_settings_wp_ai_client_toggle"><?php esc_html_e('Handle WordPress AI requests', 'gpt3-ai-content-generator'); ?></label>
                                <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Plugins that use WordPress’s built-in AI send their requests through AI Puffer.', 'gpt3-ai-content-generator'); ?></span>
                            </div>
                            <div class="aipkit_settings_provider_option_control">
                                <label class="aipkit_switch" for="aipkit_settings_wp_ai_client_toggle">
                                    <input type="checkbox" id="aipkit_settings_wp_ai_client_toggle" data-aipkit-wpai-toggle <?php checked($aipkit_wpai_managed, true); ?> />
                                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <section class="aipkit_settings_provider_panel_section">
                        <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('What you get', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_settings_developer_text"><?php esc_html_e('Your providers and keys, usage logs and costs in Usage, and the limits you set. Nothing changes for plugins that don’t use WordPress AI.', 'gpt3-ai-content-generator'); ?></p>
                    </section>
                    <section class="aipkit_settings_provider_panel_section">
                        <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Available through AI Puffer', 'gpt3-ai-content-generator'); ?></h3>
                        <?php if ($aipkit_wpai_offered) : ?>
                            <ul class="aipkit_settings_developer_list">
                                <?php foreach ($aipkit_wpai_offered as $aipkit_offered) : ?>
                                    <li class="aipkit_settings_developer_list_item">
                                        <span class="aipkit_settings_developer_provider_logo" aria-hidden="true"><?php if ($aipkit_offered['logo'] !== '') : ?><img src="<?php echo esc_url($aipkit_offered['logo']); ?>" alt="" /><?php endif; ?></span>
                                        <span><strong><?php echo esc_html($aipkit_offered['name']); ?></strong> <?php esc_html_e('via AI Puffer', 'gpt3-ai-content-generator'); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p class="aipkit_settings_developer_text"><?php esc_html_e('None yet. Connect a provider on the AI page and WordPress sees it here.', 'gpt3-ai-content-generator'); ?></p>
                        <?php endif; ?>
                        <span class="aipkit_settings_provider_option_help"><?php esc_html_e('When enabled, WordPress lists them under Settings › Connectors. Add one on the AI page.', 'gpt3-ai-content-generator'); ?></span>
                        <div class="aipkit_settings_developer_links">
                            <a class="aipkit_settings_provider_key_link" href="<?php echo esc_url(admin_url('options-connectors.php')); ?>"><span><?php esc_html_e('Open Connectors', 'gpt3-ai-content-generator'); ?></span><span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span></a>
                            <a class="aipkit_settings_provider_key_link" href="https://docs.aipower.org/wordpress-ai-connectors" target="_blank" rel="noopener noreferrer"><span><?php esc_html_e('Learn more', 'gpt3-ai-content-generator'); ?></span><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                        </div>
                    </section>
                </div>
                <?php $aipkit_render_dev_footer(); ?>
            </div>
        </div>
    </article>
<?php endif; ?>

<?php
$aipkit_rest_last4 = $public_api_key !== '' ? substr((string) $public_api_key, -4) : '';
/* translators: %s: last four characters of the API key. */
$aipkit_rest_summary_on = __('On · Key ends in %s', 'gpt3-ai-content-generator');
$aipkit_rest_summary = $public_api_enabled
    ? ($aipkit_rest_last4 !== '' ? sprintf($aipkit_rest_summary_on, $aipkit_rest_last4) : __('On', 'gpt3-ai-content-generator'))
    : __('Off', 'gpt3-ai-content-generator');
?>
    <article
        id="aipkit_settings_rest_api_row"
        class="aipkit_settings_provider_card aipkit_settings_integration_card aipkit_settings_developer_card"
        data-aipkit-developer-card="rest"
        data-aipkit-provider-connected="<?php echo $public_api_enabled ? 'true' : 'false'; ?>"
        data-aipkit-developer-credential="rest_api"
        data-enabled="<?php echo $public_api_enabled ? 'true' : 'false'; ?>"
    >
        <?php $aipkit_render_dev_row('dev-rest', __('REST API', 'gpt3-ai-content-generator'), 'rest-api', $aipkit_rest_summary, ['on' => $aipkit_rest_summary_on, 'on-plain' => __('On', 'gpt3-ai-content-generator'), 'off' => __('Off', 'gpt3-ai-content-generator')]); ?>
        <div class="aipkit-modal-overlay aipkit_settings_provider_modal" id="aipkit_settings_dev_rest_modal" data-aipkit-provider-modal="dev-rest" aria-hidden="true">
            <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="aipkit_settings_dev_rest_title">
                <?php $aipkit_render_dev_panel_header('dev-rest', __('REST API', 'gpt3-ai-content-generator'), __('Use your AI from apps and scripts outside WordPress.', 'gpt3-ai-content-generator'), 'rest-api'); ?>
                <div class="aipkit_settings_provider_panel_body">
                    <?php $aipkit_render_dev_error(); ?>
                    <div class="aipkit_settings_provider_option_list">
                        <div class="aipkit_settings_provider_option is-inline">
                            <div class="aipkit_settings_provider_option_copy">
                                <label class="aipkit_settings_provider_option_label" for="aipkit_public_api_enabled"><?php esc_html_e('Allow REST API requests', 'gpt3-ai-content-generator'); ?></label>
                                <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Apps and scripts outside WordPress can use your AI with the key below.', 'gpt3-ai-content-generator'); ?></span>
                            </div>
                            <div class="aipkit_settings_provider_option_control">
                                <label class="aipkit_switch" for="aipkit_public_api_enabled">
                                    <input type="checkbox" id="aipkit_public_api_enabled" name="public_api_enabled" value="1" data-aipkit-developer-enabled <?php checked($public_api_enabled); ?> />
                                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <section class="aipkit_settings_provider_panel_section" data-aipkit-developer-dependent <?php echo $public_api_enabled ? '' : 'hidden'; ?>>
                        <h3 class="aipkit_settings_provider_panel_section_title"><label for="aipkit_public_api_key"><?php esc_html_e('Your key', 'gpt3-ai-content-generator'); ?></label></h3>
                        <?php $aipkit_render_dev_credential('aipkit_public_api_key', $aipkit_public_api_mask, __('API key', 'gpt3-ai-content-generator'), __('Anyone with it can use your AI and its credits.', 'gpt3-ai-content-generator'), __('Make a new key', 'gpt3-ai-content-generator')); ?>
                    </section>
                    <section class="aipkit_settings_provider_panel_section" data-aipkit-developer-dependent <?php echo $public_api_enabled ? '' : 'hidden'; ?>>
                        <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('How to call it', 'gpt3-ai-content-generator'); ?></h3>
                        <label class="aipkit_settings_provider_option_label" for="aipkit_settings_rest_base_url"><?php esc_html_e('Base URL', 'gpt3-ai-content-generator'); ?></label>
                        <div class="aipkit_settings_developer_field_row">
                            <input type="text" id="aipkit_settings_rest_base_url" class="aipkit_form-input aipkit_settings_developer_credential_input" value="<?php echo esc_attr($aipkit_rest_base); ?>" readonly data-aipkit-settings-autosave-exclude="true" />
                            <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_settings_developer_button" data-aipkit-developer-copy-value="<?php echo esc_attr($aipkit_rest_base); ?>" aria-label="<?php esc_attr_e('Copy base URL', 'gpt3-ai-content-generator'); ?>">
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                <span data-aipkit-developer-copy-text data-copied="<?php esc_attr_e('Copied', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Copy', 'gpt3-ai-content-generator'); ?></span>
                            </button>
                        </div>
                        <span class="aipkit_settings_provider_option_label"><?php esc_html_e('Send the key as a header', 'gpt3-ai-content-generator'); ?></span>
                        <pre class="aipkit_settings_developer_code"><code>Authorization: Bearer <?php esc_html_e('your-key', 'gpt3-ai-content-generator'); ?></code></pre>
                    </section>
                    <section class="aipkit_settings_provider_panel_section" data-aipkit-developer-dependent <?php echo $public_api_enabled ? '' : 'hidden'; ?>>
                        <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('What you can call', 'gpt3-ai-content-generator'); ?></h3>
                        <ul class="aipkit_settings_developer_routes">
                            <?php foreach ($aipkit_rest_routes as [$aipkit_method, $aipkit_path, $aipkit_route_label]) : ?>
                                <li>
                                    <code class="aipkit_settings_developer_method is-<?php echo esc_attr(strtolower($aipkit_method)); ?>"><?php echo esc_html($aipkit_method); ?></code>
                                    <code class="aipkit_settings_developer_path"><?php echo esc_html($aipkit_path); ?></code>
                                    <span><?php echo esc_html($aipkit_route_label); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Automations use their separate server cron secret, configured in Automations.', 'gpt3-ai-content-generator'); ?></span>
                        <div class="aipkit_settings_developer_links">
                            <a class="aipkit_settings_provider_key_link" href="<?php echo esc_url($aipkit_rest_base); ?>" target="_blank" rel="noopener noreferrer"><span><?php esc_html_e('Every route, as JSON', 'gpt3-ai-content-generator'); ?></span><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                        </div>
                    </section>
                </div>
                <?php $aipkit_render_dev_footer(); ?>
            </div>
        </div>
    </article>

    <?php include __DIR__ . '/event-webhooks.php'; ?>
</div>
