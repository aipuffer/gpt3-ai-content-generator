<?php
/** Shared website-management and Cloud-provider sheet. Account editing and credit details live in Usage. */

use WPAICG\Cloud\Connection;

if (!defined('ABSPATH')) {
    exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local values.

// The account response also serves the connect dialog; the sheet header follows its state attributes.
$aipkit_render_account_fields = static function (string $aipkit_cloud_notice = ''): void {
    $aipkit_cloud_display = Connection::display();
    $aipkit_cloud_is_error = in_array($aipkit_cloud_notice, ['unavailable', 'identity_check_unavailable', 'disconnect_failed', 'busy', 'forbidden', 'freemius_failed', 'installation_inactive', 'site_mismatch', 'account_unavailable', 'invalid_email', 'registration_wait', 'account_changed', 'verification_pending', 'verification_unavailable'], true);
    $aipkit_cloud_credits = $aipkit_cloud_display['connected'] && is_array($aipkit_cloud_display['credits']) ? $aipkit_cloud_display['credits'] : null;
    if ($aipkit_cloud_display['connected']) {
        $aipkit_cloud_state = !empty($aipkit_cloud_display['revoked']) ? 'revoked' : (($aipkit_cloud_display['status'] ?? '') === 'unavailable' ? 'unavailable' : ($aipkit_cloud_display['email_verified'] === true ? 'connected' : 'verify'));
    } else {
        $aipkit_cloud_state = $aipkit_cloud_display['recovery'] ? 'recovery' : (!empty($aipkit_cloud_display['confirm_email']) ? 'confirm' : 'signup');
    }
    $aipkit_cloud_labels = [
        'connected' => __('Connected', 'gpt3-ai-content-generator'),
        'unavailable' => __('Temporarily unavailable', 'gpt3-ai-content-generator'),
        'verify' => __('Verify email', 'gpt3-ai-content-generator'),
        'revoked' => __('Reconnect needed', 'gpt3-ai-content-generator'),
        'recovery' => __('Reconnect needed', 'gpt3-ai-content-generator'),
        'confirm' => __('Confirm email', 'gpt3-ai-content-generator'),
        'signup' => __('Not connected', 'gpt3-ai-content-generator'),
    ];
    $aipkit_cloud_sub = !empty($aipkit_cloud_display['registered']) || $aipkit_cloud_display['connected'] ? (string) $aipkit_cloud_display['email'] : '';
    ?>
    <section class="aipkit_cloud_connection<?php echo $aipkit_cloud_display['connected'] ? ' is-connected' : ''; ?>" id="aipkit_cloud_connection" data-aipkit-settings-autosave-exclude="true"
        data-cloud-state="<?php echo esc_attr($aipkit_cloud_state); ?>"
        data-cloud-label="<?php echo esc_attr($aipkit_cloud_labels[$aipkit_cloud_state]); ?>"
        data-cloud-sub="<?php echo esc_attr($aipkit_cloud_sub); ?>">
        <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html(Connection::connection_message($aipkit_cloud_notice)); ?></p>

        <?php if ($aipkit_cloud_display['connected']) :
            // Whole credits, as on the Usage page and the top-bar chip; fractions only below one credit.
            $aipkit_cloud_date = static function ($iso): string { return wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), strtotime((string) $iso)); };
            $aipkit_cloud_units = $aipkit_cloud_credits === null ? null : max(0, (int) ($aipkit_cloud_credits['available'] ?? 0));
            $aipkit_cloud_available = $aipkit_cloud_units === null ? '—' : ($aipkit_cloud_units >= 1000 || $aipkit_cloud_units === 0 ? number_format_i18n(intdiv($aipkit_cloud_units, 1000)) : Connection::format_credits($aipkit_cloud_units));
            $aipkit_cloud_refresh = $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null;
            ?>
            <?php // AI Puffer Cloud as an AI provider: the account in one line (balance and account live on Home), then the default model. ?>
            <div class="aipkit_cloud_pane" data-cloud-pane="credits">
                <?php if (!empty($aipkit_cloud_display['revoked'])) : ?>
                    <div class="aipkit_cloud_notice aipkit_cloud_notice--warning" role="alert">
                        <p><strong><?php esc_html_e('AI Puffer Cloud no longer accepts this site’s connection', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('This usually happens when the site was connected again from a copy. Disconnect here, then connect again. Your balance is kept.', 'gpt3-ai-content-generator'); ?></p>
                        <button type="submit" form="aipkit_cloud_account_form" class="aipkit_btn aipkit_cloud_btn aipkit_cloud_btn--primary" name="cloud_action" value="disconnect"
                data-confirm-title="<?php esc_attr_e('Disconnect AI Puffer Cloud?', 'gpt3-ai-content-generator'); ?>"
                data-confirm-text="<?php esc_attr_e('Tools that use AI Puffer stop answering and assistants lose access to this site. Your balance is kept. Connect Cloud again, then reconnect your assistants to restore access.', 'gpt3-ai-content-generator'); ?>"
                data-confirm-button="<?php esc_attr_e('Disconnect', 'gpt3-ai-content-generator'); ?>"
                data-cancel-button="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Disconnect and start again', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                    </div>
                <?php endif; ?>
                <?php echo Connection::email_recovery_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
                <section class="aipkit_settings_provider_panel_section">
                    <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Connection', 'gpt3-ai-content-generator'); ?></h3>
                    <div class="aipkit_cloud_connection_row">
                        <span class="aipkit_cloud_account_avatar" aria-hidden="true"><?php echo esc_html(function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr((string) $aipkit_cloud_display['email'], 0, 1)) : strtoupper(substr((string) $aipkit_cloud_display['email'], 0, 1))); ?></span>
                        <span class="aipkit_cloud_connection_text">
                            <strong data-cloud-email><?php echo esc_html((string) $aipkit_cloud_display['email']); ?></strong>
                            <span><?php
                                if ($aipkit_cloud_units === null) {
                                    esc_html_e('Balance unavailable right now', 'gpt3-ai-content-generator');
                                } elseif ($aipkit_cloud_refresh) {
                                    /* translators: 1: available AI credits. 2: date the free credits renew. */
                                    echo esc_html(sprintf(__('%1$s credits · renews %2$s', 'gpt3-ai-content-generator'), $aipkit_cloud_available, $aipkit_cloud_date($aipkit_cloud_refresh)));
                                } else {
                                    /* translators: %s: available AI credits. */
                                    echo esc_html(sprintf(__('%s credits', 'gpt3-ai-content-generator'), $aipkit_cloud_available));
                                }
                            ?></span>
                        </span>
                        <a class="aipkit_cloud_connection_link" href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=stats')); ?>"><?php esc_html_e('Credits and account', 'gpt3-ai-content-generator'); ?></a>
                    </div>
                    <?php if ($aipkit_cloud_credits !== null) :
                        $aipkit_cloud_allowance_note = Connection::allowance_message($aipkit_cloud_credits);
                        if ($aipkit_cloud_allowance_note !== '') : ?>
                            <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><?php echo esc_html($aipkit_cloud_allowance_note); ?></p></div>
                        <?php elseif ($aipkit_cloud_units === 0) : ?>
                            <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><strong><?php esc_html_e('Out of credits', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Tools using AI Puffer stop answering until your free credits renew or you buy more.', 'gpt3-ai-content-generator'); ?></p></div>
                        <?php endif;
                    endif; ?>
                </section>
                <?php // The footer's Disconnect and the model refresh submit this form. ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_panel_disconnect" id="aipkit_cloud_account_form" hidden>
                    <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                    <input type="hidden" name="action" value="aipkit_cloud_connection">
                </form>
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
            <?php // One account covers both, so the sign-up names both free allowances wherever it opens. ?>
            <div class="aipkit_cloud_hero">
                <span class="aipkit_cloud_hero_eyebrow"><?php esc_html_e('Free every month · no API key', 'gpt3-ai-content-generator'); ?></span>
                <div class="aipkit_cloud_hero_tiles">
                    <div class="aipkit_cloud_hero_tile">
                        <strong><?php echo esc_html(number_format_i18n(25)); ?></strong>
                        <span class="aipkit_cloud_hero_tile_label"><?php esc_html_e('AI credits', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_cloud_hero_text"><?php esc_html_e('OpenAI, Anthropic, Google and more in Chatbots, Content Writer, AI Forms and Automations.', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                    <div class="aipkit_cloud_hero_tile">
                        <strong><?php echo esc_html(number_format_i18n(2000)); ?></strong>
                        <span class="aipkit_cloud_hero_tile_label"><?php esc_html_e('website actions', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_cloud_hero_text"><?php esc_html_e('Let ChatGPT or Claude read posts, write drafts and publish with your approval.', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                </div>
                <p class="aipkit_cloud_hero_text"><?php esc_html_e('Verify your email to receive free AI credits. Connect an assistant to start your monthly website actions.', 'gpt3-ai-content-generator'); ?></p>
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
                <?php if (!$aipkit_cloud_display['registered'] && !$aipkit_cloud_display['confirm_email']) : ?>
                    <label class="aipkit_cloud_check"><input type="checkbox" name="cloud_marketing" value="yes"> <span><?php esc_html_e('Email me product news and tips (optional).', 'gpt3-ai-content-generator'); ?></span></label>
                <?php endif; ?>
                <?php echo Connection::privacy_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all values. ?>
                <?php if ($aipkit_cloud_display['confirm_email']) : ?>
                    <button class="aipkit_btn aipkit_cloud_btn aipkit_cloud_btn--primary" name="cloud_action" value="check_email" disabled><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                <?php endif; ?>
                <button class="aipkit_btn<?php echo $aipkit_cloud_display['confirm_email'] ? ' aipkit_cloud_btn' : ' aipkit_btn-primary'; ?>" name="cloud_action" value="connect" disabled><?php echo esc_html($aipkit_cloud_display['confirm_email'] ? __('Resend email', 'gpt3-ai-content-generator') : __('Connect for free', 'gpt3-ai-content-generator')); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
            </form>
        <?php endif; ?>
    </section>
    <?php
};
$aipkit_model_sync_timestamps = $aipkit_model_sync_timestamps ?? [];
// The default-model block of a provider sheet and of the AI Puffer Cloud panel. Cloud refreshes its models through
// its account form; every other provider syncs directly and shows when it last did.
$aipkit_render_model_block = static function (string $aipkit_provider, string $aipkit_slug, array $aipkit_model_field, bool $aipkit_connected, bool $aipkit_is_account) use (&$aipkit_model_sync_timestamps): void {
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
                        type="<?php echo $aipkit_is_account ? 'submit' : 'button'; ?>"
                        <?php if ($aipkit_is_account) : ?>form="aipkit_cloud_account_form" name="cloud_action" value="sync"<?php endif; ?>
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
                    <?php if (!$aipkit_is_account) : ?>
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
    <?php
};

if (!empty($aipkit_cloud_renderers_only)) {
    return;
}
if (!class_exists(Connection::class) || !Connection::allowed()) {
    return;
}

$aipkit_cloud_panel_connected = Connection::display()['connected'];
$current_provider = \WPAICG\AIPKit_Providers::get_current_provider();
$aipkit_cloud_panel_default = (string) $current_provider === 'AIPufferCloud';
$aipkit_cloud_model_data = \WPAICG\AIPKit_Providers::get_provider_data('AIPufferCloud');
$aipkit_cloud_model_field = [
    'name' => 'aipuffercloud_model',
    'label' => __('Default model', 'gpt3-ai-content-generator'),
    'description' => __('Used when a tool doesn’t choose its own model.', 'gpt3-ai-content-generator'),
    'value' => (string) ($aipkit_cloud_model_data['model'] ?? ''),
];
?>
<div
    class="aipkit-modal-overlay aipkit_settings_provider_modal aipkit_cloud_panel"
    id="aipkit_cloud_panel"
    data-aipkit-provider-modal="cloud"
    data-aipkit-provider-owner="AIPufferCloud"
    data-cloud-tab="credits"
    aria-hidden="true"
>
    <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="aipkit_cloud_panel_title">
        <div class="aipkit_settings_provider_panel_header">
            <span class="aipkit_settings_provider_logo" aria-hidden="true"><img src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" alt="" /></span>
            <div class="aipkit_settings_provider_panel_heading">
                <div class="aipkit_settings_provider_panel_title_row">
                    <?php // One sheet, two jobs: AI Puffer Cloud as an AI provider (from Settings › AI), or Manage my website (everywhere else). ?>
                    <h2 class="aipkit_settings_provider_panel_title" id="aipkit_cloud_panel_title" tabindex="-1" data-cloud-title-provider="<?php esc_attr_e('AI Puffer Cloud', 'gpt3-ai-content-generator'); ?>" data-cloud-title-website="<?php esc_attr_e('Manage my website', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('AI Puffer Cloud', 'gpt3-ai-content-generator'); ?></h2>
                    <span class="aipkit_cloud_panel_pill" data-cloud-panel-pill></span>
                </div>
                <p class="aipkit_settings_provider_panel_hint" data-cloud-panel-sub data-default-text="<?php esc_attr_e('AI models and website management, no API key.', 'gpt3-ai-content-generator'); ?>"></p>
            </div>
            <button type="button" class="aipkit_settings_provider_panel_close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </div>

        <div class="aipkit_settings_provider_panel_body aipkit_cloud_panel_body" id="aipkit_cloud_panel_body">
            <?php $aipkit_render_account_fields(); ?>

            <?php // Its own small settings scope: saving the model or the default never sends the AI page's fields. ?>
            <div class="aipkit_settings_scope aipkit_cloud_pane" data-aipkit-settings-page="cloud" data-cloud-pane="credits" data-cloud-connected-only <?php echo $aipkit_cloud_panel_connected ? '' : 'hidden'; ?>>
                <input type="hidden" name="provider" value="<?php echo esc_attr((string) $current_provider); ?>" data-aipkit-default-provider-input />
                <?php $aipkit_render_model_block('AIPufferCloud', 'aipuffercloud', $aipkit_cloud_model_field, $aipkit_cloud_panel_connected, true); ?>
                <?php // Make default stays inside this scope: autosave saves the scope that was last used. ?>
                <div class="aipkit_cloud_default_row">
                    <button type="button" class="aipkit_settings_provider_default_action aipkit_settings_provider_secondary_action"
                        data-aipkit-provider-set-default="AIPufferCloud"
                        <?php echo $aipkit_cloud_panel_default ? 'hidden' : ''; ?>><?php esc_html_e('Make default', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>

            <div class="aipkit_cloud_pane" data-cloud-pane="website">
                <?php (static function (): void {
                    $aipkit_mcp_part = 'pane';
                    include WPAICG_PLUGIN_DIR . 'admin/views/settings/manage-website.php';
                })(); ?>
            </div>
        </div>

        <?php // Provider footer as on every provider sheet (Disconnect AI Puffer Cloud, Done); the website sheet has none. ?>
        <div class="aipkit_settings_provider_modal_footer aipkit_cloud_panel_footer">
            <button type="submit" form="aipkit_cloud_account_form" class="aipkit_cloud_quiet_action aipkit_cloud_panel_disconnect" name="cloud_action" value="disconnect" data-cloud-connected-only data-cloud-provider-only <?php echo $aipkit_cloud_panel_connected ? '' : 'hidden'; ?>
                data-confirm-title="<?php esc_attr_e('Disconnect AI Puffer Cloud?', 'gpt3-ai-content-generator'); ?>"
                data-confirm-text="<?php esc_attr_e('Tools that use AI Puffer stop answering and assistants lose access to this site. Your balance is kept. Connect Cloud again, then reconnect your assistants to restore access.', 'gpt3-ai-content-generator'); ?>"
                data-confirm-button="<?php esc_attr_e('Disconnect', 'gpt3-ai-content-generator'); ?>"
                data-cancel-button="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Disconnect AI Puffer Cloud', 'gpt3-ai-content-generator'); ?></button>
            <span class="aipkit_cloud_panel_actions">
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
            </span>
        </div>
    </div>
</div>
