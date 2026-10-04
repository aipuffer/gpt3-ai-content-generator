<?php
/**
 * Developer settings: WordPress AI connectors, REST access and webhook dispatch.
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
?>

<?php if ($aipkit_wpai_available) :
    $aipkit_wpai_managed = $aipkit_wpai_settings_class::is_effectively_managed();
    ?>
    <section class="aipkit_settings_developer_section" aria-labelledby="aipkit_settings_developer_general_title">
        <h4 class="aipkit_settings_developer_section_title" id="aipkit_settings_developer_general_title">
            <?php esc_html_e('General', 'gpt3-ai-content-generator'); ?>
        </h4>
        <div
            class="aipkit_settings_developer_toggle_row"
            id="aipkit_settings_wp_ai_client_row"
            data-aipkit-wpai-control
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce('aipkit_wp_ai_client_set_mode')); ?>"
            data-mode="<?php echo esc_attr($aipkit_wpai_managed ? 'managed' : 'observe'); ?>"
        >
            <label class="aipkit_form-label" for="aipkit_settings_wp_ai_client_toggle">
                <?php esc_html_e('WordPress AI connectors', 'gpt3-ai-content-generator'); ?>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Register AI Puffer as WordPress’s AI client for other plugins to use.', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <label class="aipkit_switch" for="aipkit_settings_wp_ai_client_toggle">
                <input
                    type="checkbox"
                    id="aipkit_settings_wp_ai_client_toggle"
                    data-aipkit-wpai-toggle
                    <?php checked($aipkit_wpai_managed, true); ?>
                />
                <span class="aipkit_switch_slider"></span>
            </label>
        </div>
    </section>
<?php endif; ?>

<section class="aipkit_settings_developer_section" aria-labelledby="aipkit_settings_developer_rest_title">
    <h4 class="aipkit_settings_developer_section_title" id="aipkit_settings_developer_rest_title">
        <?php esc_html_e('REST API', 'gpt3-ai-content-generator'); ?>
    </h4>

    <div
        class="aipkit_settings_developer_credential"
        data-aipkit-developer-credential="rest_api"
        data-enabled="<?php echo $public_api_enabled ? 'true' : 'false'; ?>"
    >
        <div class="aipkit_settings_developer_toggle_row">
            <label class="aipkit_form-label" for="aipkit_public_api_enabled">
                <?php esc_html_e('REST API access', 'gpt3-ai-content-generator'); ?>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Allow external requests using an API key.', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <label class="aipkit_switch" for="aipkit_public_api_enabled">
                <input
                    type="checkbox"
                    id="aipkit_public_api_enabled"
                    name="public_api_enabled"
                    value="1"
                    data-aipkit-developer-enabled
                    <?php checked($public_api_enabled); ?>
                />
                <span class="aipkit_switch_slider"></span>
            </label>
        </div>

        <div class="aipkit_settings_developer_credential_body" data-aipkit-developer-dependent <?php if (!$public_api_enabled) : ?>hidden<?php endif; ?>>
            <label class="aipkit_settings_developer_field_label" for="aipkit_public_api_key">
                <?php esc_html_e('API key', 'gpt3-ai-content-generator'); ?>
            </label>
            <div class="aipkit_settings_developer_credential_row">
                <input
                    type="text"
                    id="aipkit_public_api_key"
                    class="aipkit_form-input aipkit_settings_developer_credential_input"
                    value="<?php echo esc_attr($aipkit_public_api_mask); ?>"
                    data-aipkit-developer-credential-input
                    data-credential-mask="<?php echo esc_attr($aipkit_public_api_mask); ?>"
                    data-has-credential="<?php echo $aipkit_public_api_mask !== '' ? 'true' : 'false'; ?>"
                    readonly
                    autocomplete="off"
                    spellcheck="false"
                />
                <button type="button" class="button aipkit_btn aipkit_icon_btn aipkit_settings_developer_icon_btn" data-aipkit-developer-reveal data-aipkit-developer-reveal-label="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>" data-aipkit-developer-hide-label="<?php esc_attr_e('Hide API key', 'gpt3-ai-content-generator'); ?>" aria-label="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>">
                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                </button>
                <button type="button" class="button aipkit_btn aipkit_icon_btn aipkit_settings_developer_icon_btn" data-aipkit-developer-copy aria-label="<?php esc_attr_e('Copy API key', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Copy API key', 'gpt3-ai-content-generator'); ?>">
                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                </button>
                <button type="button" class="button aipkit_btn aipkit_icon_btn aipkit_settings_developer_icon_btn" data-aipkit-developer-regenerate aria-label="<?php esc_attr_e('Regenerate API key', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Regenerate API key', 'gpt3-ai-content-generator'); ?>">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                </button>
            </div>
            <p class="aipkit_settings_developer_field_help"><?php esc_html_e('Regenerating invalidates the current key immediately.', 'gpt3-ai-content-generator'); ?></p>
        </div>
    </div>
</section>

<section class="aipkit_settings_developer_section" aria-labelledby="aipkit_settings_developer_webhooks_title">
    <h4 class="aipkit_settings_developer_section_title" id="aipkit_settings_developer_webhooks_title">
        <?php esc_html_e('Webhooks', 'gpt3-ai-content-generator'); ?>
    </h4>
    <?php include __DIR__ . '/event-webhooks.php'; ?>
</section>
