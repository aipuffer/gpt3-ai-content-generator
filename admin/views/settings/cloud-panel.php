<?php
/**
 * The AI Puffer Cloud panel: one side panel for the Cloud account, AI credits and website management.
 *
 * Every entry point opens this panel on its own tab: the AI provider card on AI credits, Manage my website on
 * Website, and Credit details directly over Usage. Both pages include this same component.
 */

use WPAICG\Cloud\Connection;

if (!defined('ABSPATH')) {
    exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local values.

// One Cloud section for the AI Puffer Cloud panel and the in-context connect dialog. In the panel its Account and
// AI credits parts are tabs; the dialog shows them in order. The data-cloud-* attributes let the panel's header,
// tabs and footer follow every replacement of this section.
$aipkit_render_account_fields = static function (string $aipkit_cloud_notice = ''): void {
    $aipkit_cloud_display = Connection::display();
    $aipkit_cloud_is_error = in_array($aipkit_cloud_notice, ['unavailable', 'busy', 'forbidden', 'freemius_failed', 'installation_inactive', 'site_mismatch', 'account_unavailable', 'invalid_email', 'registration_wait', 'account_changed', 'verification_pending', 'verification_unavailable'], true);
    $aipkit_cloud_credits = $aipkit_cloud_display['connected'] && is_array($aipkit_cloud_display['credits']) ? $aipkit_cloud_display['credits'] : null;
    if ($aipkit_cloud_display['connected']) {
        $aipkit_cloud_state = !empty($aipkit_cloud_display['revoked']) ? 'revoked' : ($aipkit_cloud_display['email_verified'] === true ? 'connected' : 'verify');
    } else {
        $aipkit_cloud_state = $aipkit_cloud_display['recovery'] ? 'recovery' : (!empty($aipkit_cloud_display['confirm_email']) ? 'confirm' : 'signup');
    }
    $aipkit_cloud_labels = [
        'connected' => __('Connected', 'gpt3-ai-content-generator'),
        'verify' => __('Verify email', 'gpt3-ai-content-generator'),
        'revoked' => __('Reconnect needed', 'gpt3-ai-content-generator'),
        'recovery' => __('Reconnect needed', 'gpt3-ai-content-generator'),
        'confirm' => __('Confirm email', 'gpt3-ai-content-generator'),
        'signup' => __('Not connected', 'gpt3-ai-content-generator'),
    ];
    $aipkit_cloud_checked_at = $aipkit_cloud_display['connected'] ? (int) (Connection::credit_state()['checkedAt'] ?? 0) : 0;
    $aipkit_cloud_checked = $aipkit_cloud_checked_at > 0
        /* translators: %s: how long ago the balance was checked, such as "2 mins". */
        ? sprintf(__('Checked %s ago', 'gpt3-ai-content-generator'), human_time_diff($aipkit_cloud_checked_at))
        : '';
    $aipkit_cloud_sub = !empty($aipkit_cloud_display['registered']) || $aipkit_cloud_display['connected'] ? (string) $aipkit_cloud_display['email'] : '';
    ?>
    <section class="aipkit_cloud_connection<?php echo $aipkit_cloud_display['connected'] ? ' is-connected' : ''; ?>" id="aipkit_cloud_connection" data-aipkit-settings-autosave-exclude="true"
        data-cloud-state="<?php echo esc_attr($aipkit_cloud_state); ?>"
        data-cloud-label="<?php echo esc_attr($aipkit_cloud_labels[$aipkit_cloud_state]); ?>"
        data-cloud-sub="<?php echo esc_attr($aipkit_cloud_sub); ?>"
        data-cloud-checked="<?php echo esc_attr($aipkit_cloud_checked); ?>">
        <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html(Connection::connection_message($aipkit_cloud_notice)); ?></p>

        <?php if ($aipkit_cloud_display['connected']) :
            // Whole credits, as on the Usage page and the top-bar chip; fractions only below one credit.
            $aipkit_cloud_to_credits = static function ($units): int { return (int) floor(max(0, (float) $units) / 1000); };
            $aipkit_cloud_date = static function ($iso): string { return wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), strtotime((string) $iso)); };
            $aipkit_cloud_units = $aipkit_cloud_credits === null ? null : max(0, (int) ($aipkit_cloud_credits['available'] ?? 0));
            $aipkit_cloud_available = $aipkit_cloud_units === null ? '—' : ($aipkit_cloud_units >= 1000 || $aipkit_cloud_units === 0 ? number_format_i18n(intdiv($aipkit_cloud_units, 1000)) : Connection::format_credits($aipkit_cloud_units));
            $aipkit_cloud_refresh = $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null;
            $aipkit_cloud_has_included = $aipkit_cloud_credits !== null
                && ((int) ($aipkit_cloud_credits['included'] ?? 0) !== 0 || (int) ($aipkit_cloud_credits['includedAllowance']['total'] ?? 0) > 0);
            $aipkit_website_usage = \WPAICG\Mcp\Identity::active() ? \WPAICG\Mcp\Settings::usage_data() : null;
            ?>
            <div class="aipkit_cloud_pane" data-cloud-pane="account">
                <?php if (!empty($aipkit_cloud_display['revoked'])) : ?>
                    <div class="aipkit_cloud_notice aipkit_cloud_notice--warning" role="alert">
                        <p><strong><?php esc_html_e('AI Puffer Cloud no longer accepts this site’s connection', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('This usually happens when the site was connected again from a copy. Disconnect here, then connect again. Your balance is kept.', 'gpt3-ai-content-generator'); ?></p>
                        <button type="submit" form="aipkit_cloud_account_form" class="aipkit_btn aipkit_cloud_btn aipkit_cloud_btn--primary" name="cloud_action" value="disconnect"><?php esc_html_e('Disconnect and start again', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                    </div>
                <?php endif; ?>
                <?php echo Connection::email_recovery_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
                <div class="aipkit_cloud_tiles">
                    <div class="aipkit_cloud_tile">
                        <span class="aipkit_cloud_tile_label"><?php esc_html_e('AI credits', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_cloud_tile_value"><?php echo esc_html($aipkit_cloud_available); ?></span>
                        <?php /* translators: %s: date the free credits renew, e.g. "Oct 28". */ ?>
                        <span class="aipkit_cloud_tile_meta"><?php echo esc_html($aipkit_cloud_refresh ? sprintf(__('Renews %s', 'gpt3-ai-content-generator'), $aipkit_cloud_date($aipkit_cloud_refresh)) : __('Available', 'gpt3-ai-content-generator')); ?></span>
                        <button type="button" class="aipkit_cloud_tile_link" data-cloud-tab-switch="credits"><?php esc_html_e('Details', 'gpt3-ai-content-generator'); ?></button>
                    </div>
                    <div class="aipkit_cloud_tile" data-cloud-website-tile
                        data-cloud-off-text="<?php esc_attr_e('Off', 'gpt3-ai-content-generator'); ?>"
                        data-cloud-remaining-text="<?php esc_attr_e('Remaining this month', 'gpt3-ai-content-generator'); ?>"
                        data-cloud-off-meta="<?php esc_attr_e('Let ChatGPT or Claude manage posts', 'gpt3-ai-content-generator'); ?>"
                        data-cloud-details-text="<?php esc_attr_e('Details', 'gpt3-ai-content-generator'); ?>"
                        data-cloud-setup-text="<?php esc_attr_e('Set up', 'gpt3-ai-content-generator'); ?>">
                        <span class="aipkit_cloud_tile_label"><?php esc_html_e('Website actions', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_cloud_tile_value" data-cloud-website-value><?php echo esc_html(\WPAICG\Mcp\Identity::active() ? ($aipkit_website_usage ? number_format_i18n($aipkit_website_usage['remaining']) : '—') : __('Off', 'gpt3-ai-content-generator')); ?></span>
                        <span class="aipkit_cloud_tile_meta" data-cloud-website-meta><?php echo esc_html(\WPAICG\Mcp\Identity::active() ? __('Remaining this month', 'gpt3-ai-content-generator') : __('Let ChatGPT or Claude manage posts', 'gpt3-ai-content-generator')); ?></span>
                        <button type="button" class="aipkit_cloud_tile_link" data-cloud-tab-switch="website" data-cloud-website-link><?php echo esc_html(\WPAICG\Mcp\Identity::active() ? __('Details', 'gpt3-ai-content-generator') : __('Set up', 'gpt3-ai-content-generator')); ?></button>
                    </div>
                </div>
                <div class="aipkit_cloud_panel_group aipkit_cloud_panel_group--account">
                    <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Account', 'gpt3-ai-content-generator'); ?></h3>
                    <?php echo Connection::account_email_html(false, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
                </div>
            </div>
            <div class="aipkit_cloud_pane" data-cloud-pane="credits">
                <div class="aipkit_cloud_balance">
                    <p class="aipkit_cloud_balance_value"><strong><?php echo esc_html($aipkit_cloud_available); ?></strong> <span><?php esc_html_e('credits available', 'gpt3-ai-content-generator'); ?></span></p>
                    <div class="aipkit_cloud_balance_actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_purchase">
                            <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                            <input type="hidden" name="action" value="aipkit_cloud_connection">
                            <button type="submit" class="aipkit_btn aipkit_btn-primary" name="cloud_action" value="checkout"><?php esc_html_e('Buy credits', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                        </form>
                        <a class="aipkit_btn" href="<?php echo esc_url(add_query_arg('aipkit_stats_tab', 'requests', Connection::account_url())); ?>"><?php esc_html_e('See AI requests', 'gpt3-ai-content-generator'); ?></a>
                    </div>
                </div>
                <?php if ($aipkit_cloud_credits === null) : ?>
                    <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><strong><?php esc_html_e('Balance unavailable right now', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Your credits are safe. Use Refresh to try again.', 'gpt3-ai-content-generator'); ?></p></div>
                <?php else :
                    $aipkit_cloud_allowance_note = Connection::allowance_message($aipkit_cloud_credits);
                    if ($aipkit_cloud_allowance_note !== '') : ?>
                        <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><?php echo esc_html($aipkit_cloud_allowance_note); ?></p></div>
                    <?php elseif ($aipkit_cloud_units === 0) : ?>
                        <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><strong><?php esc_html_e('Out of credits', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Tools using AI Puffer stop answering until your free credits renew or you buy more.', 'gpt3-ai-content-generator'); ?></p></div>
                    <?php endif; ?>
                    <dl class="aipkit_cloud_breakdown">
                        <div>
                            <dt><?php esc_html_e('Free', 'gpt3-ai-content-generator'); ?></dt>
                            <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['free'] ?? 0))); ?>
                                <?php if (($aipkit_cloud_credits['allowance']['total'] ?? null) !== null) : ?>
                                    <small><?php echo esc_html(sprintf(
                                        /* translators: 1: monthly free credits, 2: renewal date. */
                                        __('%1$s/month · renews %2$s', 'gpt3-ai-content-generator'),
                                        number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['allowance']['total'])),
                                        $aipkit_cloud_refresh ? $aipkit_cloud_date($aipkit_cloud_refresh) : '—'
                                    )); ?></small>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <?php if ($aipkit_cloud_has_included) : ?>
                            <div>
                                <dt><?php esc_html_e('Included with plan', 'gpt3-ai-content-generator'); ?></dt>
                                <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['included'] ?? 0))); ?>
                                    <small><?php echo esc_html(sprintf(
                                        /* translators: 1: included monthly credits, 2: credit renewal date. */
                                        __('%1$s/month · renews %2$s', 'gpt3-ai-content-generator'),
                                        number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['includedAllowance']['total'] ?? 0)),
                                        !empty($aipkit_cloud_credits['includedAllowance']['nextRefresh']) ? $aipkit_cloud_date($aipkit_cloud_credits['includedAllowance']['nextRefresh']) : '—'
                                    )); ?></small>
                                </dd>
                            </div>
                        <?php endif; ?>
                        <div>
                            <dt><?php esc_html_e('Purchased', 'gpt3-ai-content-generator'); ?></dt>
                            <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits((int) ($aipkit_cloud_credits['purchased'] ?? 0) + (int) ($aipkit_cloud_credits['adjustments'] ?? 0)))); ?><small><?php esc_html_e('Never expire', 'gpt3-ai-content-generator'); ?></small></dd>
                        </div>
                        <?php if ((int) ($aipkit_cloud_credits['held'] ?? 0) > 0) : ?>
                            <div>
                                <dt><?php esc_html_e('In use', 'gpt3-ai-content-generator'); ?></dt>
                                <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['held']))); ?><small><?php esc_html_e('Running requests', 'gpt3-ai-content-generator'); ?></small></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
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
                <?php echo Connection::privacy_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all values. ?>
                <?php if (!$aipkit_cloud_display['registered'] && !$aipkit_cloud_display['confirm_email']) : ?>
                    <label class="aipkit_cloud_check"><input type="checkbox" name="cloud_marketing" value="yes"> <span><?php esc_html_e('Email me product news and tips (optional).', 'gpt3-ai-content-generator'); ?></span></label>
                <?php endif; ?>
                <?php if ($aipkit_cloud_display['confirm_email']) : ?>
                    <button class="aipkit_btn aipkit_cloud_btn aipkit_cloud_btn--primary" name="cloud_action" value="check_email" disabled><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                <?php endif; ?>
                <button class="aipkit_btn aipkit_cloud_btn<?php echo $aipkit_cloud_display['confirm_email'] ? '' : ' aipkit_cloud_btn--primary aipkit_cloud_btn--block'; ?>" name="cloud_action" value="connect" disabled><?php echo esc_html($aipkit_cloud_display['confirm_email'] ? __('Resend email', 'gpt3-ai-content-generator') : __('Connect for free', 'gpt3-ai-content-generator')); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
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
$aipkit_cloud_panel_tabs = [
    'account' => __('Account', 'gpt3-ai-content-generator'),
    'credits' => __('AI credits', 'gpt3-ai-content-generator'),
    'website' => __('Website', 'gpt3-ai-content-generator'),
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
                    <h2 class="aipkit_settings_provider_panel_title" id="aipkit_cloud_panel_title" tabindex="-1"><?php esc_html_e('AI Puffer Cloud', 'gpt3-ai-content-generator'); ?></h2>
                    <span class="aipkit_cloud_panel_pill" data-cloud-panel-pill></span>
                </div>
                <p class="aipkit_settings_provider_panel_hint" data-cloud-panel-sub data-default-text="<?php esc_attr_e('AI models and website management, no API key.', 'gpt3-ai-content-generator'); ?>"></p>
            </div>
            <button type="button" class="aipkit_settings_provider_panel_close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </div>

        <?php // Same tabs, labels and order from every entry point; they show once Cloud is connected. ?>
        <div class="aipkit_cloud_panel_tabs" role="tablist" aria-label="<?php esc_attr_e('AI Puffer Cloud', 'gpt3-ai-content-generator'); ?>" data-cloud-tabs <?php echo $aipkit_cloud_panel_connected ? '' : 'hidden'; ?>>
            <?php foreach ($aipkit_cloud_panel_tabs as $aipkit_cloud_tab_key => $aipkit_cloud_tab_label) : ?>
                <button type="button" class="aipkit_cloud_panel_tab" role="tab" id="aipkit_cloud_tab_<?php echo esc_attr($aipkit_cloud_tab_key); ?>" aria-controls="aipkit_cloud_panel_body" aria-selected="false" tabindex="-1" data-cloud-tab-switch="<?php echo esc_attr($aipkit_cloud_tab_key); ?>">
                    <span><?php echo esc_html($aipkit_cloud_tab_label); ?></span>
                    <span class="aipkit_cloud_panel_tab_dot" data-cloud-dot="<?php echo esc_attr($aipkit_cloud_tab_key); ?>" hidden></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="aipkit_settings_provider_panel_body aipkit_cloud_panel_body" id="aipkit_cloud_panel_body" role="tabpanel" aria-labelledby="aipkit_cloud_tab_credits">
            <?php $aipkit_render_account_fields(); ?>

            <?php // Its own small settings scope: saving the model or the default never sends the AI page's fields. ?>
            <div class="aipkit_settings_scope aipkit_cloud_pane" data-aipkit-settings-page="cloud" data-cloud-pane="credits" data-cloud-connected-only <?php echo $aipkit_cloud_panel_connected ? '' : 'hidden'; ?>>
                <input type="hidden" name="provider" value="<?php echo esc_attr((string) $current_provider); ?>" data-aipkit-default-provider-input />
                <?php $aipkit_render_model_block('AIPufferCloud', 'aipuffercloud', $aipkit_cloud_model_field, $aipkit_cloud_panel_connected, true); ?>
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

        <?php // The footer is the same on every tab: when the balance was checked with a refresh icon beside it, then Disconnect and Done. ?>
        <div class="aipkit_settings_provider_modal_footer aipkit_cloud_panel_footer">
            <span class="aipkit_cloud_panel_status" data-cloud-connected-only <?php echo $aipkit_cloud_panel_connected ? '' : 'hidden'; ?>>
                <span class="aipkit_cloud_panel_checked" data-cloud-panel-checked></span>
                <button type="submit" form="aipkit_cloud_account_form" class="aipkit_cloud_panel_refresh" name="cloud_action" value="refresh" aria-label="<?php esc_attr_e('Refresh balance', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Refresh', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
            </span>
            <span class="aipkit_cloud_panel_actions">
                <button type="submit" form="aipkit_cloud_account_form" class="aipkit_cloud_quiet_action aipkit_cloud_panel_disconnect" name="cloud_action" value="disconnect" data-cloud-connected-only <?php echo $aipkit_cloud_panel_connected ? '' : 'hidden'; ?>
                    data-confirm-title="<?php esc_attr_e('Disconnect AI Puffer Cloud?', 'gpt3-ai-content-generator'); ?>"
                    data-confirm-text="<?php esc_attr_e('Tools that use AI Puffer stop answering and assistants lose access to this site. Your balance is kept. Connect Cloud again, then reconnect your assistants to restore access.', 'gpt3-ai-content-generator'); ?>"
                    data-confirm-button="<?php esc_attr_e('Disconnect', 'gpt3-ai-content-generator'); ?>"
                    data-cancel-button="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Disconnect', 'gpt3-ai-content-generator'); ?></button>
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
            </span>
        </div>
    </div>
</div>
