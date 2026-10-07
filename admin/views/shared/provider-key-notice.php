<?php
/**
 * Shared Partial: Provider setup notice.
 *
 * Expected variables:
 * - $aipkit_notice_id (string) Unique HTML id.
 * - $aipkit_notice_class (string, optional) Additional CSS classes.
 * - $aipkit_notice_context (string, optional) Phrase following "to", such as
 *   "use this chatbot".
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local values only.

$aipkit_provider_notice_id = isset($aipkit_notice_id)
    ? sanitize_html_class((string) $aipkit_notice_id)
    : '';
$aipkit_provider_notice_class = isset($aipkit_notice_class)
    ? trim((string) $aipkit_notice_class)
    : '';
$aipkit_provider_notice_context = isset($aipkit_notice_context)
    ? trim((string) $aipkit_notice_context)
    : __('continue', 'gpt3-ai-content-generator');

if ($aipkit_provider_notice_id === '') {
    return;
}

$aipkit_provider_notice_default_message = sprintf(
    /* translators: %s: feature context. */
    __('Connect an AI provider to %s.', 'gpt3-ai-content-generator'),
    $aipkit_provider_notice_context
);
$aipkit_provider_notice_settings_url = add_query_arg(
    [
        'page' => 'wpaicg',
        'aipkit_module' => 'settings',
        'aipkit_settings_page' => 'ai',
    ],
    admin_url('admin.php')
);
?>
<div
    id="<?php echo esc_attr($aipkit_provider_notice_id); ?>"
    class="aipkit_notification_bar aipkit_notification_bar--setup aipkit_provider_key_notice aipkit_provider_notice--hidden <?php echo esc_attr($aipkit_provider_notice_class); ?>"
    data-aipkit-provider-notice="1"
    <?php if (!empty($aipkit_notice_model_source)) : ?>data-aipkit-connection-model-source="<?php echo esc_attr($aipkit_notice_model_source); ?>"<?php endif; ?>
    data-aipkit-settings-url="<?php echo esc_url($aipkit_provider_notice_settings_url); ?>"
    data-message-default="<?php echo esc_attr($aipkit_provider_notice_default_message); ?>"
    data-action-default="<?php echo esc_attr__('Connect a provider', 'gpt3-ai-content-generator'); ?>"
>
    <span class="aipkit_notification_bar__icon" aria-hidden="true"><span class="dashicons dashicons-admin-plugins"></span></span>
    <div class="aipkit_notification_bar__content">
        <p>
            <strong class="aipkit_provider_notice_message"><?php echo esc_html($aipkit_provider_notice_default_message); ?></strong>
        </p>
    </div>
    <div class="aipkit_notification_bar__actions">
        <a
            href="<?php echo esc_url($aipkit_provider_notice_settings_url); ?>"
            class="aipkit_notification_bar__action is-primary aipkit_provider_notice_settings_link"
            data-aipkit-provider-action
            data-aipkit-settings-page="ai"
            data-aipkit-settings-card="OpenAI"
            data-aipkit-settings-card-kind="provider"
        >
            <?php esc_html_e('Connect a provider', 'gpt3-ai-content-generator'); ?>
        </a>
    </div>
    <button type="button" class="aipkit_notification_bar__close" data-aipkit-dismiss-provider-notice aria-label="<?php esc_attr_e('Dismiss provider notice', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
</div>
<?php
unset(
    $aipkit_provider_notice_id,
    $aipkit_provider_notice_class,
    $aipkit_provider_notice_context,
    $aipkit_provider_notice_default_message,
    $aipkit_provider_notice_settings_url,
    $aipkit_notice_model_source,
    $aipkit_notice_id,
    $aipkit_notice_class,
    $aipkit_notice_context
);
