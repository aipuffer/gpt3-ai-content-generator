<?php
if (!defined('ABSPATH')) { exit; }
use WPAICG\Mcp\{Identity,Store,Schema};
use WPAICG\Cloud\Connection;
if (!class_exists(Connection::class) || !class_exists(Identity::class) || !Connection::allowed()) { return; }
$aipkit_mcp_part = $aipkit_mcp_part ?? 'both';
$aipkit_mcp_active = Identity::active();
$aipkit_mcp_registration = Identity::registration();
$aipkit_mcp_paused = !empty(get_option('aipkit_mcp_settings', [])['paused']);
$aipkit_mcp_cloud = Connection::display();
global $wpdb;
$aipkit_mcp_grants_table = Schema::table('grants');
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Display current assistant/approval state; Schema allowlists tables and IDs are prepared; caching would hide new approvals or revocations.
$aipkit_mcp_connected = $aipkit_mcp_active && Schema::ready() && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $aipkit_mcp_grants_table WHERE instance_id = %s AND state IN ('active','paused')", $aipkit_mcp_registration['instance_id'] ?? '')) > 0;
$aipkit_mcp_status = $aipkit_mcp_active ? ($aipkit_mcp_connected ? ($aipkit_mcp_paused ? __('Changes paused', 'gpt3-ai-content-generator') : __('On', 'gpt3-ai-content-generator')) : __('Waiting for assistant', 'gpt3-ai-content-generator')) : __('Off', 'gpt3-ai-content-generator');
$aipkit_mcp_cloud_ready = $aipkit_mcp_cloud['connected'] && $aipkit_mcp_cloud['email_verified'] === true;
// The card's one-line summary, like the other connection cards. The most urgent state wins.
$aipkit_mcp_card_summary = '';
$aipkit_mcp_card_attention = false;
if ($aipkit_mcp_part !== 'pane' && $aipkit_mcp_active && Schema::ready()) {
    $aipkit_mcp_card_instance = $aipkit_mcp_registration['instance_id'] ?? '';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Display current assistant/approval state; Schema allowlists tables and IDs are prepared; caching would hide new approvals or revocations.
    $aipkit_mcp_card_grants = (array) $wpdb->get_results($wpdb->prepare("SELECT grant_id, client_name, client_hint, access_level, state, last_used_at FROM $aipkit_mcp_grants_table WHERE instance_id = %s AND state IN ('active','paused','pending') ORDER BY created_at DESC LIMIT 20", $aipkit_mcp_card_instance), ARRAY_A);
    foreach ($aipkit_mcp_card_grants as &$aipkit_mcp_card_grant) {
        $aipkit_mcp_card_grant['unattended'] = \WPAICG\Mcp\Levels::unattended($aipkit_mcp_card_grant['grant_id']);
    }
    unset($aipkit_mcp_card_grant);
    $aipkit_mcp_card_name = static function (array $aipkit_mcp_row): string {
        $aipkit_mcp_hints = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];
        return $aipkit_mcp_row['client_name'] ?: ($aipkit_mcp_hints[$aipkit_mcp_row['client_hint']] ?? __('Your assistant', 'gpt3-ai-content-generator'));
    };
    $aipkit_mcp_card_live = array_values(array_filter($aipkit_mcp_card_grants, static function (array $aipkit_mcp_row): bool { return $aipkit_mcp_row['state'] !== 'pending'; }));
    $aipkit_mcp_card_names = array_values(array_unique(array_map($aipkit_mcp_card_name, $aipkit_mcp_card_live)));
    $aipkit_mcp_operations_table = Schema::table('operations');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Display current assistant/approval state; Schema allowlists tables and IDs are prepared; caching would hide new approvals or revocations.
    $aipkit_mcp_card_approvals = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $aipkit_mcp_operations_table WHERE instance_id = %s AND acting_user = %d AND state = 'pending_approval' AND expires_at > %d", $aipkit_mcp_card_instance, get_current_user_id(), time()));
    if ($aipkit_mcp_card_approvals > 0) {
        $aipkit_mcp_card_attention = true;
        /* translators: %s: number of publications waiting for approval. */
        $aipkit_mcp_card_summary = sprintf(_n('%s publication waiting for your approval', '%s publications waiting for your approval', $aipkit_mcp_card_approvals, 'gpt3-ai-content-generator'), number_format_i18n($aipkit_mcp_card_approvals));
    } elseif ($aipkit_mcp_card_review = count(\WPAICG\Mcp\Screens::needs_review())) {
        $aipkit_mcp_card_attention = true;
        /* translators: %s: number of interrupted website changes. */
        $aipkit_mcp_card_summary = sprintf(_n('%s change needs your review', '%s changes need your review', $aipkit_mcp_card_review, 'gpt3-ai-content-generator'), number_format_i18n($aipkit_mcp_card_review));
    } elseif ($aipkit_mcp_connected && $aipkit_mcp_paused) {
        $aipkit_mcp_card_attention = true;
        /* translators: %s: assistant names, such as "ChatGPT and Claude". */
        $aipkit_mcp_card_summary = sprintf(__('Changes paused · %s can still read posts', 'gpt3-ai-content-generator'), wp_sprintf_l('%l', $aipkit_mcp_card_names));
    } elseif (!$aipkit_mcp_connected) {
        $aipkit_mcp_card_attention = true;
        $aipkit_mcp_card_pending = array_values(array_filter($aipkit_mcp_card_grants, static function (array $aipkit_mcp_row): bool { return $aipkit_mcp_row['state'] === 'pending'; }));
        $aipkit_mcp_card_summary = $aipkit_mcp_card_pending
            /* translators: %s: assistant name, such as "ChatGPT". */
            ? sprintf(__('Waiting for approval in %s', 'gpt3-ai-content-generator'), $aipkit_mcp_card_name($aipkit_mcp_card_pending[0]))
            : __('Waiting for an assistant to connect', 'gpt3-ai-content-generator');
    } else {
        $aipkit_mcp_card_access = [
            'read_only' => __('can read posts', 'gpt3-ai-content-generator'),
            'standard' => __('asks before publishing', 'gpt3-ai-content-generator'),
            'trusted' => __('publishes without asking', 'gpt3-ai-content-generator'),
            'content_editor' => __('can create drafts', 'gpt3-ai-content-generator'),
            'publisher' => __('asks before publishing', 'gpt3-ai-content-generator'),
        ];
        if (count($aipkit_mcp_card_live) === 1 && !empty($aipkit_mcp_card_live[0]['unattended'])) {
            $aipkit_mcp_card_access['trusted'] = __('runs supported changes without asking', 'gpt3-ai-content-generator');
        }
        $aipkit_mcp_card_parts = [wp_sprintf_l('%l', $aipkit_mcp_card_names)];
        if (count($aipkit_mcp_card_live) === 1 && isset($aipkit_mcp_card_access[$aipkit_mcp_card_live[0]['access_level']])) {
            $aipkit_mcp_card_parts[] = $aipkit_mcp_card_access[$aipkit_mcp_card_live[0]['access_level']];
        }
        $aipkit_mcp_card_last_used = max(array_map('intval', array_column($aipkit_mcp_card_live, 'last_used_at')) ?: [0]);
        if ($aipkit_mcp_card_last_used > 0) {
            /* translators: %s: time since an assistant last used the site, such as "2 hours". */
            $aipkit_mcp_card_parts[] = sprintf(__('used %s ago', 'gpt3-ai-content-generator'), human_time_diff($aipkit_mcp_card_last_used));
        }
        $aipkit_mcp_card_summary = implode(' · ', $aipkit_mcp_card_parts);
    }
}
// Plain words for each stored grant state; anything unexpected shows as stored. Safety levels come from the Registry.
$aipkit_mcp_state_labels = [
    'active'  => __('Connected', 'gpt3-ai-content-generator'),
    'paused'  => __('Connected, changes paused', 'gpt3-ai-content-generator'),
    'pending' => __('Waiting for approval in the assistant', 'gpt3-ai-content-generator'),
    'revoked' => __('Disconnected here. The bridge hasn’t confirmed it yet.', 'gpt3-ai-content-generator'),
];
// Settings renders the card in Connections and the pane in the AI Puffer Cloud panel's Website tab; a refresh
// renders both, and the script takes each from the same response.
$aipkit_mcp_pane_approvals = $aipkit_mcp_part !== 'card' ? \WPAICG\Mcp\Screens::pending_approvals() : [];
$aipkit_mcp_pane_review = $aipkit_mcp_part !== 'card' ? count(\WPAICG\Mcp\Screens::needs_review()) : 0;
$aipkit_mcp_pane_attention = $aipkit_mcp_pane_approvals || $aipkit_mcp_pane_review > 0 || ($aipkit_mcp_connected && $aipkit_mcp_paused);
?>
<?php if ($aipkit_mcp_part !== 'pane') : ?>
<div id="aipkit_manage_website" class="aipkit_settings_part aipkit_settings_integration_group" data-aipkit-settings-autosave-exclude="true">
<header class="aipkit_settings_integration_group_header"><h4 class="aipkit_settings_group_title"><?php esc_html_e('Website management', 'gpt3-ai-content-generator'); ?></h4></header>
<div class="aipkit_settings_provider_cards aipkit_settings_integration_cards">
<article class="aipkit_settings_provider_card" data-mcp-card data-aipkit-provider-connected="<?php echo $aipkit_mcp_connected ? 'true' : 'false'; ?>">
    <div class="aipkit_settings_provider_card_main">
        <span class="aipkit_settings_provider_logo<?php echo $aipkit_mcp_card_attention ? ' is-attention' : ''; ?>" aria-hidden="true"><span class="dashicons dashicons-admin-site-alt3"></span></span>
        <div class="aipkit_settings_provider_card_text">
            <div class="aipkit_settings_provider_card_title"><h4 class="aipkit_settings_provider_name"><?php esc_html_e('Manage my website', 'gpt3-ai-content-generator'); ?></h4></div>
            <p class="aipkit_settings_provider_card_status"><?php if ($aipkit_mcp_card_summary !== '') : ?><span class="aipkit_settings_provider_summary<?php echo $aipkit_mcp_card_attention ? ' is-attention' : ''; ?>"><?php echo esc_html($aipkit_mcp_card_summary); ?></span><?php else : ?><span class="aipkit_settings_provider_status aipkit_settings_provider_status--disconnected"><?php echo esc_html($aipkit_mcp_status); ?></span><span class="aipkit_settings_provider_tagline"><?php esc_html_e('Let ChatGPT or Claude read and publish posts.', 'gpt3-ai-content-generator'); ?></span><?php endif; ?></p>
        </div>
        <?php // The same AI Puffer Cloud panel as the AI provider card, opened on its Website tab. ?>
        <button type="button" class="aipkit_settings_provider_card_action" data-aipkit-provider-settings-open="cloud" data-aipkit-cloud-open="website" aria-haspopup="dialog" aria-controls="aipkit_cloud_panel"><span class="screen-reader-text"><?php esc_html_e('Manage my website', 'gpt3-ai-content-generator'); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
    </div>
</article>
</div>
</div>
<?php endif; ?>
<?php if ($aipkit_mcp_part !== 'card') : ?>
<div id="aipkit_cloud_website" class="aipkit_cloud_website" data-aipkit-settings-autosave-exclude="true" data-cloud-attention="<?php echo $aipkit_mcp_pane_attention ? '1' : '0'; ?>">
    <input type="hidden" data-mcp-nonce value="<?php echo esc_attr(wp_create_nonce('aipkit_mcp_settings')); ?>">
    <p class="aipkit_cloud_notice aipkit_cloud_notice--info" data-mcp-feedback role="status" aria-live="polite" tabindex="-1" hidden></p>
    <?php
    $aipkit_mcp_rows = [];
    if (Schema::ready()) {
        $aipkit_mcp_retry = get_option('aipkit_mcp_revoke_retry', []);
        $aipkit_mcp_retry = is_array($aipkit_mcp_retry) ? array_slice(array_keys($aipkit_mcp_retry), -20) : [];
        $aipkit_mcp_where_retry = $aipkit_mcp_retry ? ' OR grant_id IN (' . implode(',', array_fill(0, count($aipkit_mcp_retry), '%s')) . ')' : '';
        // $aipkit_mcp_where_retry contains only generated %s placeholders; every grant ID is supplied separately to prepare().
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Display current assistant/approval state; Schema allowlists tables and IDs are prepared; caching would hide new approvals or revocations.
        $aipkit_mcp_rows = (array) $wpdb->get_results($wpdb->prepare("SELECT grant_id, client_name, client_hint, access_level, state, wp_user_id, wp_user_login FROM $aipkit_mcp_grants_table WHERE instance_id = %s AND (state != 'revoked'" . $aipkit_mcp_where_retry . ') ORDER BY created_at DESC LIMIT 20', array_merge([$aipkit_mcp_registration['instance_id'] ?? ''], $aipkit_mcp_retry)), ARRAY_A);
        foreach ($aipkit_mcp_rows as &$aipkit_mcp_pane_grant) {
            $aipkit_mcp_pane_grant['unattended'] = \WPAICG\Mcp\Levels::unattended($aipkit_mcp_pane_grant['grant_id']);
        }
        unset($aipkit_mcp_pane_grant);
    }
    ?>

    <?php // What needs the owner comes first: publications to approve, interrupted changes, then a pause. ?>
    <?php if ($aipkit_mcp_pane_approvals) : ?>
        <div class="aipkit_cloud_notice aipkit_cloud_notice--warning">
            <?php /* translators: %s: number of assistant requests waiting for approval. */ ?>
            <p><strong><?php echo esc_html(sprintf(_n('%s request is waiting for your approval', '%s requests are waiting for your approval', count($aipkit_mcp_pane_approvals), 'gpt3-ai-content-generator'), number_format_i18n(count($aipkit_mcp_pane_approvals)))); ?></strong></p>
            <div class="aipkit_cloud_balance_actions"><a class="aipkit_btn aipkit_btn-primary" href="<?php echo esc_url(\WPAICG\Mcp\Screens::request_url($aipkit_mcp_pane_approvals[0]['operation_id'])); ?>"><?php esc_html_e('Review', 'gpt3-ai-content-generator'); ?></a></div>
        </div>
    <?php endif; ?>
    <?php if ($aipkit_mcp_pane_review > 0) : ?>
        <div class="aipkit_cloud_notice aipkit_cloud_notice--warning">
            <?php /* translators: %s: number of interrupted website changes. */ ?>
            <p><strong><?php echo esc_html(sprintf(_n('%s change needs your review', '%s changes need your review', $aipkit_mcp_pane_review, 'gpt3-ai-content-generator'), number_format_i18n($aipkit_mcp_pane_review))); ?></strong> <?php esc_html_e('A request was interrupted. Tell us whether it went through.', 'gpt3-ai-content-generator'); ?></p>
            <div class="aipkit_cloud_balance_actions"><a class="aipkit_btn" href="<?php echo esc_url(\WPAICG\Mcp\Screens::review_url()); ?>"><?php esc_html_e('Review changes', 'gpt3-ai-content-generator'); ?></a></div>
        </div>
    <?php endif; ?>
    <?php if ($aipkit_mcp_active && $aipkit_mcp_paused) : ?>
        <div class="aipkit_cloud_notice aipkit_cloud_notice--warning">
            <p><strong><?php esc_html_e('Website changes are paused', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Assistants can still read posts, but they can’t change anything until you resume.', 'gpt3-ai-content-generator'); ?></p>
            <div class="aipkit_cloud_balance_actions"><button type="button" class="aipkit_btn aipkit_btn-primary" data-mcp-action="resume"><?php esc_html_e('Resume changes', 'gpt3-ai-content-generator'); ?></button></div>
        </div>
    <?php endif; ?>

    <?php if ($aipkit_mcp_rows) : ?>
        <section class="aipkit_settings_provider_panel_section">
            <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Assistants', 'gpt3-ai-content-generator'); ?></h3>
            <div class="aipkit_settings_provider_option_list">
                <?php foreach ($aipkit_mcp_rows as $aipkit_mcp_row) :
                    $aipkit_mcp_name = $aipkit_mcp_row['client_name'] ?: ucfirst($aipkit_mcp_row['client_hint'] ?: __('Assistant', 'gpt3-ai-content-generator'));
                    $aipkit_mcp_state = (string) $aipkit_mcp_row['state'];
                    $aipkit_mcp_detail = $aipkit_mcp_state_labels[$aipkit_mcp_state] ?? $aipkit_mcp_state;
                    $aipkit_mcp_live = in_array($aipkit_mcp_state, ['active', 'paused', 'pending'], true);
                    if ($aipkit_mcp_live) {
                        /* translators: %s: WordPress username the assistant acts as. */
                        $aipkit_mcp_detail .= ' · ' . sprintf(__('acts as %s', 'gpt3-ai-content-generator'), $aipkit_mcp_row['wp_user_login']);
                    }
                    // A level change the bridge hasn't confirmed yet: the site already uses the safer of the two.
                    $aipkit_mcp_level_pending = $aipkit_mcp_live ? \WPAICG\Mcp\Levels::pending((string) $aipkit_mcp_row['grant_id']) : null;
                    $aipkit_mcp_level_shown = $aipkit_mcp_level_pending ? (string) $aipkit_mcp_level_pending['target'] : (string) $aipkit_mcp_row['access_level'];
                    if ($aipkit_mcp_level_pending) {
                        /* translators: %s: safety level, such as "Read only". */
                        $aipkit_mcp_detail .= ' · ' . sprintf(__('%s not confirmed yet, select Check', 'gpt3-ai-content-generator'), \WPAICG\Mcp\Registry::level_label($aipkit_mcp_level_shown));
                    }
                    $aipkit_mcp_previous_trusted = $aipkit_mcp_live && !$aipkit_mcp_level_pending
                        && $aipkit_mcp_row['access_level'] === 'trusted' && empty($aipkit_mcp_row['unattended']);
                    if ($aipkit_mcp_previous_trusted) {
                        $aipkit_mcp_detail .= ' · ' . __('Previous approvals kept. Select Trusted to run supported changes and deletes without asking.', 'gpt3-ai-content-generator');
                    }
                    $aipkit_mcp_continue = $aipkit_mcp_state === 'pending' && (int) $aipkit_mcp_row['wp_user_id'] === get_current_user_id() ? get_transient('aipkit_mcp_connect_' . $aipkit_mcp_row['grant_id']) : '';
                    ?>
                    <div class="aipkit_settings_provider_option is-inline">
                        <div class="aipkit_settings_provider_option_copy">
                            <span class="aipkit_settings_provider_option_label"><?php echo esc_html($aipkit_mcp_name); ?></span>
                            <span class="aipkit_settings_provider_option_help"><?php echo esc_html($aipkit_mcp_detail); ?></span>
                            <?php if ($aipkit_mcp_continue) : ?>
                                <a class="aipkit_settings_provider_key_link" href="<?php echo esc_url($aipkit_mcp_continue); ?>" target="_blank" rel="noopener noreferrer"><span><?php esc_html_e('Continue connection', 'gpt3-ai-content-generator'); ?></span><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                            <?php endif; ?>
                        </div>
                        <div class="aipkit_settings_provider_option_control">
                            <div class="aipkit_settings_provider_field_links">
                                <?php if ($aipkit_mcp_live) : ?>
                                    <?php /* translators: %s: assistant name, e.g. ChatGPT. */ ?>
                                    <select class="aipkit_form-input aipkit_mcp_level_select" data-mcp-level data-mcp-grant="<?php echo esc_attr($aipkit_mcp_row['grant_id']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Safety level for %s', 'gpt3-ai-content-generator'), $aipkit_mcp_name)); ?>">
                                        <?php if ($aipkit_mcp_previous_trusted) : ?>
                                            <option value="" selected disabled><?php esc_html_e('Trusted · previous approvals', 'gpt3-ai-content-generator'); ?></option>
                                        <?php endif; ?>
                                        <?php if (!in_array($aipkit_mcp_level_shown, \WPAICG\Mcp\Registry::LEVELS, true)) : ?>
                                            <option value="<?php echo esc_attr($aipkit_mcp_level_shown); ?>" selected disabled><?php echo esc_html(\WPAICG\Mcp\Registry::level_label($aipkit_mcp_level_shown)); ?></option>
                                        <?php endif; ?>
                                        <?php foreach (\WPAICG\Mcp\Registry::LEVELS as $aipkit_mcp_level_option) : ?>
                                            <option value="<?php echo esc_attr($aipkit_mcp_level_option); ?>" <?php selected(!$aipkit_mcp_previous_trusted ? $aipkit_mcp_level_shown : '', $aipkit_mcp_level_option); ?> title="<?php echo esc_attr(\WPAICG\Mcp\Registry::level_help($aipkit_mcp_level_option)); ?>"><?php echo esc_html(\WPAICG\Mcp\Registry::level_label($aipkit_mcp_level_option)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <?php /* translators: %s: assistant name, e.g. ChatGPT. */ ?>
                                <button type="button" class="aipkit_settings_provider_secondary_action" data-mcp-action="recheck" data-mcp-grant="<?php echo esc_attr($aipkit_mcp_row['grant_id']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Check the %s connection', 'gpt3-ai-content-generator'), $aipkit_mcp_name)); ?>"><?php esc_html_e('Check', 'gpt3-ai-content-generator'); ?></button>
                                <?php /* translators: %s: assistant name, e.g. ChatGPT. */ ?>
                                <button type="button" class="aipkit_settings_provider_remove" data-mcp-action="revoke" data-mcp-grant="<?php echo esc_attr($aipkit_mcp_row['grant_id']); ?>" aria-label="<?php echo esc_attr(sprintf($aipkit_mcp_state === 'revoked' ? __('Retry notifying the bridge that %s is disconnected', 'gpt3-ai-content-generator') : __('Disconnect %s', 'gpt3-ai-content-generator'), $aipkit_mcp_name)); ?>"><?php echo esc_html($aipkit_mcp_state === 'revoked' ? __('Retry', 'gpt3-ai-content-generator') : __('Disconnect', 'gpt3-ai-content-generator')); ?></button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php $aipkit_mcp_recent = \WPAICG\Mcp\Activity::feed('', '', 1, 3)['items']; ?>
        <?php if ($aipkit_mcp_recent) : ?>
            <?php // The latest few actions; the full history lives in Usage › Activity › Website. ?>
            <section class="aipkit_settings_provider_panel_section">
                <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Recent activity', 'gpt3-ai-content-generator'); ?></h3>
                <div class="aipkit_settings_provider_option_list">
                    <?php foreach ($aipkit_mcp_recent as $aipkit_mcp_item) : ?>
                        <div class="aipkit_settings_provider_option is-inline">
                            <div class="aipkit_settings_provider_option_copy">
                                <span class="aipkit_settings_provider_option_label"><?php echo esc_html($aipkit_mcp_item['title']); ?></span>
                                <span class="aipkit_settings_provider_option_help"><?php echo esc_html($aipkit_mcp_item['assistant'] . ' · ' . sprintf(
                                    /* translators: %s: time since the action, such as "5 mins". */
                                    __('%s ago', 'gpt3-ai-content-generator'),
                                    human_time_diff($aipkit_mcp_item['created_at'])
                                )); ?></span>
                            </div>
                            <div class="aipkit_settings_provider_option_control">
                                <span class="aipkit_cloud_panel_pill<?php echo $aipkit_mcp_item['pill']['tone'] !== 'grey' ? ' is-' . esc_attr($aipkit_mcp_item['pill']['tone']) : ''; ?>"><?php echo esc_html($aipkit_mcp_item['pill']['label']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a class="aipkit_settings_provider_key_link" href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=stats&aipkit_stats_tab=website')); ?>"><span><?php esc_html_e('See all website activity', 'gpt3-ai-content-generator'); ?></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <section class="aipkit_settings_provider_panel_section">
        <h3 class="aipkit_settings_provider_panel_section_title"><?php echo esc_html($aipkit_mcp_rows ? __('Add an assistant', 'gpt3-ai-content-generator') : __('Connect an assistant', 'gpt3-ai-content-generator')); ?></h3>
        <?php // Once an assistant is connected, connecting another is the rarer task: it folds, as other sheets fold rarely changed groups. ?>
        <?php if ($aipkit_mcp_rows) : ?>
            <details class="aipkit_settings_provider_fold">
                <summary>
                    <span class="aipkit_settings_provider_option_copy">
                        <span class="aipkit_settings_provider_option_label"><?php esc_html_e('Connect another assistant', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_settings_provider_option_help"><?php esc_html_e('ChatGPT, Claude or another MCP assistant.', 'gpt3-ai-content-generator'); ?></span>
                    </span>
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </summary>
                <div class="aipkit_settings_provider_fold_body">
        <?php else : ?>
            <p class="aipkit_settings_provider_option_help"><?php esc_html_e('Let ChatGPT or Claude read posts, write drafts and ask before anything goes live.', 'gpt3-ai-content-generator'); ?></p>
        <?php endif; ?>
        <?php if (!$aipkit_mcp_cloud_ready) : ?>
            <?php // Cloud lives in this same panel: verifying happens on the Account tab, never in another panel. ?>
            <div class="aipkit_cloud_connect">
                <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><strong><?php esc_html_e('Verify your email to connect an assistant', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Website management needs a verified AI Puffer Cloud account. An account that’s already verified doesn’t need to verify again.', 'gpt3-ai-content-generator'); ?></p></div>
                <div class="aipkit_cloud_balance_actions">
                    <button type="button" class="aipkit_btn aipkit_btn-primary" data-cloud-tab-switch="account"><?php esc_html_e('Verify email', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit_btn" data-mcp-action="view"><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>
        <?php else : ?>
            <form class="aipkit_cloud_connect" data-mcp-connect>
                <div class="aipkit_settings_provider_option_list">
                    <div class="aipkit_settings_provider_option is-inline">
                        <div class="aipkit_settings_provider_option_copy">
                            <label class="aipkit_settings_provider_option_label" for="aipkit_mcp_client"><?php esc_html_e('Assistant', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_settings_provider_option_help"><?php esc_html_e('The app you’ll use to manage this site.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <div class="aipkit_settings_provider_option_control">
                            <select id="aipkit_mcp_client" class="aipkit_form-input" name="client"><option value="chatgpt">ChatGPT</option><option value="claude">Claude</option><option value="other"><?php esc_html_e('Other MCP assistant', 'gpt3-ai-content-generator'); ?></option></select>
                        </div>
                    </div>
                </div>
                <?php
                // One click connects: Trusted, as the current user. The two choices stay folded behind one line.
                $aipkit_mcp_connect_user = wp_get_current_user();
                ?>
                <p class="aipkit_settings_provider_option_help"><?php echo esc_html(\WPAICG\Mcp\Registry::level_help(\WPAICG\Mcp\Registry::DEFAULT_LEVEL)); ?></p>
                <details class="aipkit_mcp_connect_options">
                    <summary>
                        <span class="aipkit_settings_provider_option_help" data-mcp-connect-summary data-user-name="<?php echo esc_attr($aipkit_mcp_connect_user->display_name); ?>"><?php echo esc_html(sprintf(
                            /* translators: 1: safety level, such as "Standard". 2: WordPress user's display name. */
                            __('%1$s safety level · works as %2$s', 'gpt3-ai-content-generator'),
                            \WPAICG\Mcp\Registry::level_label(\WPAICG\Mcp\Registry::DEFAULT_LEVEL),
                            $aipkit_mcp_connect_user->display_name
                        )); ?></span>
                        <span class="aipkit_mcp_connect_options_toggle" aria-hidden="true"><span class="is-closed"><?php esc_html_e('Change', 'gpt3-ai-content-generator'); ?></span><span class="is-open"><?php esc_html_e('Hide', 'gpt3-ai-content-generator'); ?></span></span>
                    </summary>
                    <div class="aipkit_settings_provider_option_list">
                        <div class="aipkit_settings_provider_option is-inline">
                            <div class="aipkit_settings_provider_option_copy">
                                <label class="aipkit_settings_provider_option_label" for="aipkit_mcp_access"><?php esc_html_e('Safety level', 'gpt3-ai-content-generator'); ?></label>
                                <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Standard asks you before anything goes live. You can change it any time.', 'gpt3-ai-content-generator'); ?></span>
                            </div>
                            <div class="aipkit_settings_provider_option_control">
                                <select id="aipkit_mcp_access" class="aipkit_form-input" name="access_level">
                                    <?php foreach (\WPAICG\Mcp\Registry::LEVELS as $aipkit_mcp_level_option) : ?>
                                        <option value="<?php echo esc_attr($aipkit_mcp_level_option); ?>" <?php selected($aipkit_mcp_level_option, \WPAICG\Mcp\Registry::DEFAULT_LEVEL); ?>><?php echo esc_html(\WPAICG\Mcp\Registry::level_label($aipkit_mcp_level_option)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php if (current_user_can('manage_options')) :
                            $aipkit_mcp_act_as_users = get_users(['capability' => 'edit_posts', 'fields' => ['ID', 'user_login', 'display_name'], 'number' => 200, 'orderby' => 'login']);
                            ?>
                            <div class="aipkit_settings_provider_option is-inline">
                                <div class="aipkit_settings_provider_option_copy">
                                    <label class="aipkit_settings_provider_option_label" for="aipkit_mcp_act_as"><?php esc_html_e('Act as', 'gpt3-ai-content-generator'); ?></label>
                                    <span class="aipkit_settings_provider_option_help"><?php esc_html_e('It can never do more than this user.', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <div class="aipkit_settings_provider_option_control">
                                    <select id="aipkit_mcp_act_as" class="aipkit_form-input" name="act_as">
                                        <?php foreach ($aipkit_mcp_act_as_users as $aipkit_mcp_act_as_user) : ?>
                                            <option value="<?php echo esc_attr((string) $aipkit_mcp_act_as_user->ID); ?>" data-name="<?php echo esc_attr($aipkit_mcp_act_as_user->display_name); ?>" <?php selected((int) $aipkit_mcp_act_as_user->ID, get_current_user_id()); ?>><?php echo esc_html($aipkit_mcp_act_as_user->display_name . ' (' . $aipkit_mcp_act_as_user->user_login . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
                <label class="aipkit_cloud_check"><input type="checkbox" name="consent" value="yes" required> <span><?php printf(
                    /* translators: 1: AI Puffer terms link. 2: AI Puffer privacy policy link. */
                    esc_html__('I agree to connect this website through AI Puffer’s hosted bridge under the %1$s and %2$s.', 'gpt3-ai-content-generator'),
                    '<a href="' . esc_url('https://aipower.org/terms-and-conditions/') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('terms', 'gpt3-ai-content-generator') . '</a>',
                    '<a href="' . esc_url('https://aipower.org/privacy-policy/') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'gpt3-ai-content-generator') . '</a>'
                ); ?></span></label>
                <p class="aipkit_settings_provider_option_help"><?php esc_html_e('The bridge receives this site’s address, your WordPress user, the permissions you choose, and records of each operation and its usage. Your assistant receives only the content you ask it to read. Keys stay private, and you can disconnect at any time here.', 'gpt3-ai-content-generator'); ?></p>
                <div class="aipkit_cloud_balance_actions"><button type="submit" class="aipkit_btn aipkit_btn-primary"><?php esc_html_e('Connect assistant', 'gpt3-ai-content-generator'); ?></button></div>
            </form>
        <?php endif; ?>
        <?php if ($aipkit_mcp_rows) : ?>
                </div>
            </details>
        <?php endif; ?>
        <?php // The hook stays on a plain wrapper: the column's display rule would otherwise override [hidden]. ?>
        <div data-mcp-handoff hidden>
            <div class="aipkit_cloud_connect">
                <div class="aipkit_cloud_notice aipkit_cloud_notice--info"><?php esc_html_e('Finish the approval in your assistant, in this browser, within 10 minutes. Then come back here and select Check next to the assistant.', 'gpt3-ai-content-generator'); ?></div>
                <div class="aipkit_cloud_balance_actions"><a class="aipkit_btn aipkit_btn-primary" data-mcp-connect-link target="_blank" rel="noopener noreferrer"><?php esc_html_e('Continue connection', 'gpt3-ai-content-generator'); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>
            </div>
        </div>
    </section>

    <?php if ($aipkit_mcp_active) : ?>
        <section class="aipkit_settings_provider_panel_section">
            <?php \WPAICG\Mcp\Settings::render_usage('panel'); ?>
        </section>
        <?php if (!$aipkit_mcp_paused) : ?>
            <section class="aipkit_settings_provider_panel_section">
                <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Website changes', 'gpt3-ai-content-generator'); ?></h3>
                <div class="aipkit_settings_provider_option_list">
                    <div class="aipkit_settings_provider_option is-inline">
                        <div class="aipkit_settings_provider_option_copy">
                            <span class="aipkit_settings_provider_option_label"><?php esc_html_e('Changes are allowed', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Pause to stop all website changes. Your AI features aren’t affected.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <div class="aipkit_settings_provider_option_control">
                            <button type="button" class="aipkit_settings_provider_secondary_action" data-mcp-action="pause"><?php esc_html_e('Pause', 'gpt3-ai-content-generator'); ?></button>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        <div class="aipkit_cloud_quiet_row">
            <span><?php esc_html_e('Disconnect every assistant and stop website management. AI features and your Cloud account aren’t affected.', 'gpt3-ai-content-generator'); ?></span>
            <button type="button" class="aipkit_cloud_quiet_action" data-mcp-action="disconnect"><?php esc_html_e('Turn off', 'gpt3-ai-content-generator'); ?></button>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
