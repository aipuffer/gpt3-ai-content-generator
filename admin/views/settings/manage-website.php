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
<div id="aipkit_cloud_website" class="aipkit_cloud_website" data-aipkit-settings-autosave-exclude="true" data-mcp-enabled="<?php echo $aipkit_mcp_active ? '1' : '0'; ?>" data-cloud-attention="<?php echo $aipkit_mcp_pane_attention ? '1' : '0'; ?>">
    <input type="hidden" data-mcp-nonce value="<?php echo esc_attr(wp_create_nonce('aipkit_mcp_settings')); ?>">
    <p class="aipkit_cloud_notice aipkit_cloud_notice--info" data-mcp-feedback role="status" aria-live="polite" tabindex="-1" hidden></p>
    <?php
    $aipkit_mcp_rows = [];
    $aipkit_mcp_disconnected_hints = [];
    if (Schema::ready()) {
        $aipkit_mcp_retry = get_option('aipkit_mcp_revoke_retry', []);
        $aipkit_mcp_retry = is_array($aipkit_mcp_retry) ? array_slice(array_keys($aipkit_mcp_retry), -20) : [];
        $aipkit_mcp_where_retry = $aipkit_mcp_retry ? ' OR grant_id IN (' . implode(',', array_fill(0, count($aipkit_mcp_retry), '%s')) . ')' : '';
        // $aipkit_mcp_where_retry contains only generated %s placeholders; every grant ID is supplied separately to prepare().
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Display current assistant/approval state; Schema allowlists tables and IDs are prepared; caching would hide new approvals or revocations.
        $aipkit_mcp_rows = (array) $wpdb->get_results($wpdb->prepare("SELECT grant_id, client_name, client_hint, access_level, state, wp_user_id, wp_user_login, last_used_at, created_at FROM $aipkit_mcp_grants_table WHERE instance_id = %s AND (state != 'revoked'" . $aipkit_mcp_where_retry . ') ORDER BY created_at DESC, grant_id DESC LIMIT 20', array_merge([$aipkit_mcp_registration['instance_id'] ?? ''], $aipkit_mcp_retry)), ARRAY_A);
        // Apps disconnected from this site before, so Add an assistant can offer Reconnect instead of a fresh Set up.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Current assistant state; Schema allowlists the table and values are prepared.
        $aipkit_mcp_disconnected_hints = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT client_hint FROM $aipkit_mcp_grants_table WHERE instance_id = %s AND state = 'revoked'", $aipkit_mcp_registration['instance_id'] ?? ''));
        foreach ($aipkit_mcp_rows as &$aipkit_mcp_pane_grant) {
            $aipkit_mcp_pane_grant['unattended'] = \WPAICG\Mcp\Levels::unattended($aipkit_mcp_pane_grant['grant_id']);
        }
        unset($aipkit_mcp_pane_grant);
    }
    ?>

    <?php
    // Proper names everywhere (never "Chatgpt"); the tile colour follows the assistant.
    $aipkit_mcp_names = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];
    $aipkit_mcp_row_name = static function (array $aipkit_mcp_row) use ($aipkit_mcp_names): string {
        return $aipkit_mcp_row['client_name'] ?: ($aipkit_mcp_names[$aipkit_mcp_row['client_hint']] ?? __('Assistant', 'gpt3-ai-content-generator'));
    };
    $aipkit_mcp_live_rows = array_values(array_filter($aipkit_mcp_rows, static function (array $aipkit_mcp_row): bool { return $aipkit_mcp_row['state'] !== 'pending'; }));
    $aipkit_mcp_pending_rows = array_values(array_filter($aipkit_mcp_rows, static function (array $aipkit_mcp_row): bool { return $aipkit_mcp_row['state'] === 'pending'; }));
    $aipkit_mcp_connected_count = count(array_filter($aipkit_mcp_live_rows, static function (array $aipkit_mcp_row): bool { return in_array($aipkit_mcp_row['state'], ['active', 'paused'], true); }));
    // Each app's place in Add an assistant: connected (offered again only as "another account"), waiting (Continue
    // setup), disconnected before (Reconnect), or new (Set up).
    $aipkit_mcp_apps = [];
    foreach (['chatgpt', 'claude'] as $aipkit_mcp_app) {
        $aipkit_mcp_app_rows = array_filter($aipkit_mcp_rows, static function (array $aipkit_mcp_row) use ($aipkit_mcp_app): bool { return $aipkit_mcp_row['client_hint'] === $aipkit_mcp_app; });
        $aipkit_mcp_app_states = array_column($aipkit_mcp_app_rows, 'state');
        $aipkit_mcp_app_pending = array_values(array_filter($aipkit_mcp_app_rows, static function (array $aipkit_mcp_row): bool { return $aipkit_mcp_row['state'] === 'pending' && (int) $aipkit_mcp_row['wp_user_id'] === get_current_user_id() && (int) $aipkit_mcp_row['created_at'] > time() - 600; }));
        $aipkit_mcp_app_link = $aipkit_mcp_app_pending ? get_transient('aipkit_mcp_connect_' . $aipkit_mcp_app_pending[0]['grant_id']) : '';
        $aipkit_mcp_apps[$aipkit_mcp_app] = [
            'state' => $aipkit_mcp_app_link ? 'waiting' : (array_intersect(['active', 'paused'], $aipkit_mcp_app_states) ? 'connected' : (in_array($aipkit_mcp_app, $aipkit_mcp_disconnected_hints, true) ? 'disconnected' : 'new')),
            'link' => $aipkit_mcp_app_link,
            'since' => $aipkit_mcp_app_pending ? (int) $aipkit_mcp_app_pending[0]['created_at'] : 0,
        ];
    }
    // A second connection of the same app is named so the rows can be told apart: "ChatGPT · account 2".
    $aipkit_mcp_ordinals = [];
    $aipkit_mcp_oldest_first = array_reverse(array_filter($aipkit_mcp_live_rows, static function (array $aipkit_mcp_row): bool { return in_array($aipkit_mcp_row['state'], ['active', 'paused'], true); }));
    $aipkit_mcp_seen = [];
    foreach ($aipkit_mcp_oldest_first as $aipkit_mcp_row) {
        $aipkit_mcp_key = $aipkit_mcp_row['client_hint'] . '|' . $aipkit_mcp_row['client_name'];
        $aipkit_mcp_seen[$aipkit_mcp_key] = ($aipkit_mcp_seen[$aipkit_mcp_key] ?? 0) + 1;
        $aipkit_mcp_ordinals[$aipkit_mcp_row['grant_id']] = $aipkit_mcp_seen[$aipkit_mcp_key];
    }
    ?>

    <?php // One status card first: on (Pause) or paused (Resume), with Disconnect beside it. Disconnect asks first. ?>
    <?php if ($aipkit_mcp_active) : ?>
        <div class="aipkit_mcp_status <?php echo $aipkit_mcp_paused ? 'is-paused' : 'is-on'; ?>">
            <span class="aipkit_mcp_status_dot" aria-hidden="true"></span>
            <?php if ($aipkit_mcp_paused) : ?>
                <span class="aipkit_mcp_status_text"><strong><?php esc_html_e('Website changes are paused', 'gpt3-ai-content-generator'); ?></strong><span><?php esc_html_e('Assistants can still read, not change.', 'gpt3-ai-content-generator'); ?></span></span>
                <button type="button" class="aipkit_btn aipkit_btn-primary" data-mcp-action="resume"><?php esc_html_e('Resume', 'gpt3-ai-content-generator'); ?></button>
            <?php else : ?>
                <span class="aipkit_mcp_status_text"><strong><?php esc_html_e('Website management is on', 'gpt3-ai-content-generator'); ?></strong><span><?php echo esc_html($aipkit_mcp_connected_count > 0 ? sprintf(
                    /* translators: %s: number of connected assistants. */
                    _n('%s assistant connected', '%s assistants connected', $aipkit_mcp_connected_count, 'gpt3-ai-content-generator'),
                    number_format_i18n($aipkit_mcp_connected_count)
                ) : __('No assistant connected yet', 'gpt3-ai-content-generator')); ?></span></span>
                <button type="button" class="aipkit_btn" data-mcp-action="pause" title="<?php esc_attr_e('Pause to stop all website changes. Your AI features aren’t affected.', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Pause', 'gpt3-ai-content-generator'); ?></button>
            <?php endif; ?>
            <button type="button" class="aipkit_btn aipkit_mcp_btn_danger" data-mcp-action="disconnect" aria-label="<?php esc_attr_e('Turn off website management', 'gpt3-ai-content-generator'); ?>"
                data-confirm-title="<?php esc_attr_e('Turn off website management?', 'gpt3-ai-content-generator'); ?>"
                data-confirm-text="<?php esc_attr_e('Disconnect every assistant and stop website management. AI features and your Cloud account aren’t affected.', 'gpt3-ai-content-generator'); ?>"
                data-confirm-button="<?php esc_attr_e('Turn off', 'gpt3-ai-content-generator'); ?>"
                data-cancel-button="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Disconnect', 'gpt3-ai-content-generator'); ?></button>
        </div>
    <?php endif; ?>

    <?php // What needs the owner: publications to approve, then interrupted changes. ?>
    <?php if ($aipkit_mcp_pane_approvals) : ?>
        <div class="aipkit_mcp_status is-attention">
            <span class="aipkit_mcp_status_icon" aria-hidden="true">!</span>
            <?php /* translators: %s: number of assistant requests waiting for approval. */ ?>
            <span class="aipkit_mcp_status_text"><strong><?php echo esc_html(sprintf(_n('%s request is waiting for your approval', '%s requests are waiting for your approval', count($aipkit_mcp_pane_approvals), 'gpt3-ai-content-generator'), number_format_i18n(count($aipkit_mcp_pane_approvals)))); ?></strong></span>
            <a class="aipkit_btn aipkit_btn-primary" href="<?php echo esc_url(\WPAICG\Mcp\Screens::request_url($aipkit_mcp_pane_approvals[0]['operation_id'])); ?>"><?php esc_html_e('Review', 'gpt3-ai-content-generator'); ?></a>
        </div>
    <?php endif; ?>
    <?php if ($aipkit_mcp_pane_review > 0) : ?>
        <div class="aipkit_mcp_status is-attention">
            <span class="aipkit_mcp_status_icon" aria-hidden="true">!</span>
            <?php /* translators: %s: number of interrupted website changes. */ ?>
            <span class="aipkit_mcp_status_text"><strong><?php echo esc_html(sprintf(_n('%s change needs your review', '%s changes need your review', $aipkit_mcp_pane_review, 'gpt3-ai-content-generator'), number_format_i18n($aipkit_mcp_pane_review))); ?></strong><span><?php esc_html_e('A request was interrupted. Tell us whether it went through.', 'gpt3-ai-content-generator'); ?></span></span>
            <a class="aipkit_btn" href="<?php echo esc_url(\WPAICG\Mcp\Screens::review_url()); ?>"><?php esc_html_e('Review changes', 'gpt3-ai-content-generator'); ?></a>
        </div>
    <?php endif; ?>

    <?php if ($aipkit_mcp_rows) :
        // One row per assistant: name, what it does here, its safety level, then quiet Check and Disconnect.
        $aipkit_mcp_render_row = static function (array $aipkit_mcp_row) use ($aipkit_mcp_row_name, $aipkit_mcp_state_labels, $aipkit_mcp_ordinals): void {
            $aipkit_mcp_name = $aipkit_mcp_row_name($aipkit_mcp_row);
            $aipkit_mcp_ordinal = $aipkit_mcp_ordinals[$aipkit_mcp_row['grant_id']] ?? 1;
            if ($aipkit_mcp_ordinal > 1) {
                /* translators: 1: assistant name, such as "ChatGPT". 2: which connection of that assistant, such as 2. */
                $aipkit_mcp_name = sprintf(__('%1$s · account %2$s', 'gpt3-ai-content-generator'), $aipkit_mcp_name, number_format_i18n($aipkit_mcp_ordinal));
            }
            $aipkit_mcp_state = (string) $aipkit_mcp_row['state'];
            $aipkit_mcp_live = in_array($aipkit_mcp_state, ['active', 'paused', 'pending'], true);
            $aipkit_mcp_title = $aipkit_mcp_state === 'pending'
                /* translators: %s: assistant name, such as "ChatGPT". */
                ? sprintf(__('%s setup in progress', 'gpt3-ai-content-generator'), $aipkit_mcp_name)
                : $aipkit_mcp_name;
            $aipkit_mcp_parts = [];
            if ($aipkit_mcp_state !== 'active') { $aipkit_mcp_parts[] = $aipkit_mcp_state_labels[$aipkit_mcp_state] ?? $aipkit_mcp_state; }
            if ($aipkit_mcp_live) {
                /* translators: %s: WordPress username the assistant acts as. */
                $aipkit_mcp_parts[] = sprintf($aipkit_mcp_parts ? __('acts as %s', 'gpt3-ai-content-generator') : __('Acts as %s', 'gpt3-ai-content-generator'), $aipkit_mcp_row['wp_user_login']);
            }
            if ($aipkit_mcp_ordinal > 1 && (int) ($aipkit_mcp_row['created_at'] ?? 0) > 0) {
                /* translators: %s: date the assistant was connected, such as "Oct 18". */
                $aipkit_mcp_parts[] = sprintf(__('added %s', 'gpt3-ai-content-generator'), wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), (int) $aipkit_mcp_row['created_at']));
            }
            if ($aipkit_mcp_state === 'active' && (int) ($aipkit_mcp_row['last_used_at'] ?? 0) > 0) {
                /* translators: %s: time since an assistant last used the site, such as "2 hours". */
                $aipkit_mcp_parts[] = sprintf(__('used %s ago', 'gpt3-ai-content-generator'), human_time_diff((int) $aipkit_mcp_row['last_used_at']));
            }
            $aipkit_mcp_detail = implode(' · ', $aipkit_mcp_parts);
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
            $aipkit_mcp_hint = in_array($aipkit_mcp_row['client_hint'], ['chatgpt', 'claude'], true) ? $aipkit_mcp_row['client_hint'] : 'other';
            ?>
            <div class="aipkit_mcp_row" data-state="<?php echo esc_attr($aipkit_mcp_state); ?>">
                <span class="aipkit_mcp_tile aipkit_mcp_tile--<?php echo esc_attr($aipkit_mcp_hint); ?>" aria-hidden="true"></span>
                <span class="aipkit_mcp_row_text">
                    <strong><?php echo esc_html($aipkit_mcp_title); ?></strong>
                    <span><?php echo esc_html($aipkit_mcp_detail); ?></span>
                    <?php if ($aipkit_mcp_continue) : ?>
                        <a class="aipkit_mcp_status_link" href="<?php echo esc_url(add_query_arg('launch', '1', $aipkit_mcp_continue)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($aipkit_mcp_hint === 'chatgpt' ? __('Open ChatGPT plugins', 'gpt3-ai-content-generator') : ($aipkit_mcp_hint === 'claude' ? __('Open Claude setup', 'gpt3-ai-content-generator') : __('Open assistant setup', 'gpt3-ai-content-generator'))); ?> ↗</a>
                    <?php endif; ?>
                </span>
                <span class="aipkit_mcp_row_actions">
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
                    <?php
                    // Waiting setups keep Check in sight and Cancel under ⋯; a disconnect the bridge hasn't confirmed keeps
                    // Retry in sight and Check under ⋯; a live assistant keeps both under ⋯.
                    /* translators: %s: assistant name, e.g. ChatGPT. */
                    $aipkit_mcp_check = '<button type="button" class="{class}" data-mcp-action="recheck" data-mcp-grant="' . esc_attr($aipkit_mcp_row['grant_id']) . '" aria-label="' . esc_attr(sprintf(__('Check the %s connection', 'gpt3-ai-content-generator'), $aipkit_mcp_name)) . '"' . ($aipkit_mcp_state !== 'pending' ? ' role="menuitem"' : '') . '>' . esc_html($aipkit_mcp_live && $aipkit_mcp_state !== 'pending' ? __('Check connection', 'gpt3-ai-content-generator') : __('Check', 'gpt3-ai-content-generator')) . '</button>';
                    $aipkit_mcp_revoke_label = $aipkit_mcp_state === 'revoked' ? __('Retry', 'gpt3-ai-content-generator') : ($aipkit_mcp_state === 'pending' ? __('Cancel setup', 'gpt3-ai-content-generator') : sprintf(
                        /* translators: %s: assistant name, e.g. ChatGPT. */
                        __('Disconnect %s', 'gpt3-ai-content-generator'),
                        $aipkit_mcp_name
                    ));
                    $aipkit_mcp_revoke = '<button type="button" class="{class}" data-mcp-action="revoke" data-mcp-grant="' . esc_attr($aipkit_mcp_row['grant_id']) . '" aria-label="' . esc_attr(sprintf($aipkit_mcp_state === 'revoked'
                        /* translators: %s: assistant name, e.g. ChatGPT. */
                        ? __('Retry notifying the bridge that %s is disconnected', 'gpt3-ai-content-generator')
                        : ($aipkit_mcp_state === 'pending'
                            /* translators: %s: assistant name, e.g. ChatGPT. */
                            ? __('Cancel the %s setup', 'gpt3-ai-content-generator')
                            /* translators: %s: assistant name, e.g. ChatGPT. */
                            : __('Disconnect %s', 'gpt3-ai-content-generator')), $aipkit_mcp_name)) . '"' . ($aipkit_mcp_state !== 'revoked' ? ' role="menuitem"' : '') . '>' . esc_html($aipkit_mcp_revoke_label) . '</button>';
                    $aipkit_mcp_in_sight = $aipkit_mcp_state === 'pending' ? $aipkit_mcp_check : ($aipkit_mcp_state === 'revoked' ? $aipkit_mcp_revoke : '');
                    $aipkit_mcp_in_menu = $aipkit_mcp_state === 'pending' ? [$aipkit_mcp_revoke] : ($aipkit_mcp_state === 'revoked' ? [$aipkit_mcp_check] : [$aipkit_mcp_check, $aipkit_mcp_revoke]);
                    if ($aipkit_mcp_in_sight !== '') {
                        echo str_replace('{class}', 'aipkit_btn', $aipkit_mcp_in_sight); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
                    }
                    ?>
                    <span class="aipkit_mcp_menu">
                        <?php /* translators: %s: assistant name, e.g. ChatGPT. */ ?>
                        <button type="button" class="aipkit_mcp_menu_toggle" data-mcp-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="<?php echo esc_attr(sprintf(__('More actions for %s', 'gpt3-ai-content-generator'), $aipkit_mcp_name)); ?>"><span aria-hidden="true">···</span></button>
                        <span class="aipkit_mcp_menu_list" role="menu" data-mcp-menu hidden>
                            <?php foreach ($aipkit_mcp_in_menu as $aipkit_mcp_index => $aipkit_mcp_item) : ?>
                                <?php if ($aipkit_mcp_index > 0) : ?><span class="aipkit_mcp_menu_sep" aria-hidden="true"></span><?php endif; ?>
                                <?php echo str_replace('{class}', 'aipkit_mcp_menu_item' . (str_contains($aipkit_mcp_item, 'data-mcp-action="revoke"') ? ' is-danger' : ''), $aipkit_mcp_item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above. ?>
                            <?php endforeach; ?>
                        </span>
                    </span>
                </span>
            </div>
            <?php
        };
        ?>
        <section class="aipkit_settings_provider_panel_section">
            <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Assistants', 'gpt3-ai-content-generator'); ?></h3>
            <div class="aipkit_mcp_rows">
                <?php foreach ($aipkit_mcp_live_rows as $aipkit_mcp_row) { $aipkit_mcp_render_row($aipkit_mcp_row); } ?>
                <?php // Only the newest unfinished setup shows; older ones fold away with every control intact. ?>
                <?php if ($aipkit_mcp_pending_rows) { $aipkit_mcp_render_row($aipkit_mcp_pending_rows[0]); } ?>
                <?php if (count($aipkit_mcp_pending_rows) > 1) : ?>
                    <details class="aipkit_mcp_older">
                        <?php /* translators: %s: number of older unfinished assistant setups. */ ?>
                        <summary><?php echo esc_html(sprintf(_n('%s older unfinished setup', '%s older unfinished setups', count($aipkit_mcp_pending_rows) - 1, 'gpt3-ai-content-generator'), number_format_i18n(count($aipkit_mcp_pending_rows) - 1))); ?></summary>
                        <div class="aipkit_mcp_rows">
                            <?php foreach (array_slice($aipkit_mcp_pending_rows, 1) as $aipkit_mcp_row) { $aipkit_mcp_render_row($aipkit_mcp_row); } ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="aipkit_settings_provider_panel_section">
        <?php
        // Once an assistant is connected, adding another is the rarer task: one quiet line that opens the Set up rows.
        // Apps already connected aren't offered again there; a second account of them is its own quiet choice.
        $aipkit_mcp_to_add = $aipkit_mcp_rows ? array_keys(array_filter($aipkit_mcp_apps, static function (array $aipkit_mcp_app): bool { return $aipkit_mcp_app['state'] !== 'connected'; })) : array_keys($aipkit_mcp_apps);
        $aipkit_mcp_connected_apps = $aipkit_mcp_rows ? array_keys(array_filter($aipkit_mcp_apps, static function (array $aipkit_mcp_app): bool { return $aipkit_mcp_app['state'] === 'connected'; })) : [];
        $aipkit_mcp_app_names = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];
        ?>
        <?php if ($aipkit_mcp_rows) : ?>
            <details class="aipkit_settings_provider_fold aipkit_mcp_add">
                <summary><?php echo esc_html($aipkit_mcp_to_add ? __('Add an assistant', 'gpt3-ai-content-generator') : __('Connect another account', 'gpt3-ai-content-generator')); ?></summary>
                <div class="aipkit_settings_provider_fold_body">
        <?php else : ?>
            <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Connect an assistant', 'gpt3-ai-content-generator'); ?></h3>
            <p class="aipkit_settings_provider_option_help"><?php esc_html_e('Manage this site from ChatGPT or Claude: create content, update products and design pages without logging in.', 'gpt3-ai-content-generator'); ?></p>
        <?php endif; ?>
        <?php \WPAICG\Mcp\Settings::render_connection_check(); ?>
        <?php if (\WPAICG\Mcp\Settings::connection_issue() !== '') : ?>
            <?php \WPAICG\Mcp\Settings::render_connection_notice(); ?>
        <?php elseif (!$aipkit_mcp_cloud_ready) : ?>
            <?php // Cloud lives in this same panel: verifying happens on the Account tab, never in another panel. ?>
            <div class="aipkit_cloud_connect">
                <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><strong><?php esc_html_e('Verify your email to connect an assistant', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Website management needs a verified AI Puffer Cloud account. An account that’s already verified doesn’t need to verify again.', 'gpt3-ai-content-generator'); ?></p></div>
                <div class="aipkit_cloud_balance_actions">
                    <button type="button" class="aipkit_btn aipkit_btn-primary" data-cloud-tab-switch="credits"><?php esc_html_e('Verify email', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit_btn" data-mcp-action="view"><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>
        <?php else : ?>
            <form class="aipkit_cloud_connect" data-mcp-connect>
                <?php // The same Set up rows as first-run setup; the button carries which assistant to prepare. A setup that is
                // waiting is continued, and an app disconnected before is reconnected, instead of starting a second one. ?>
                <?php if ($aipkit_mcp_to_add) : ?>
                    <div class="aipkit_mcp_rows">
                        <?php foreach ($aipkit_mcp_to_add as $aipkit_mcp_client) :
                            $aipkit_mcp_app = $aipkit_mcp_apps[$aipkit_mcp_client];
                            $aipkit_mcp_client_name = $aipkit_mcp_app_names[$aipkit_mcp_client];
                            $aipkit_mcp_client_how = $aipkit_mcp_app['state'] === 'waiting'
                                /* translators: %s: time since the setup started, such as "4 minutes". */
                                ? sprintf(__('Setup started %s ago', 'gpt3-ai-content-generator'), human_time_diff($aipkit_mcp_app['since'] ?: time()))
                                : ($aipkit_mcp_app['state'] === 'disconnected' ? __('Disconnected', 'gpt3-ai-content-generator') : ($aipkit_mcp_client === 'chatgpt' ? __('Add AI Puffer as a custom plugin', 'gpt3-ai-content-generator') : __('Add AI Puffer as a connector', 'gpt3-ai-content-generator')));
                            ?>
                            <div class="aipkit_mcp_row">
                                <span class="aipkit_mcp_tile aipkit_mcp_tile--<?php echo esc_attr($aipkit_mcp_client); ?>" aria-hidden="true"></span>
                                <span class="aipkit_mcp_row_text"><strong><?php echo esc_html($aipkit_mcp_client_name); ?></strong><span><?php echo esc_html($aipkit_mcp_client_how); ?></span></span>
                                <?php if ($aipkit_mcp_app['state'] === 'waiting') : ?>
                                    <a class="aipkit_btn aipkit_btn-primary" href="<?php echo esc_url($aipkit_mcp_app['link']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Continue setup', 'gpt3-ai-content-generator'); ?></a>
                                <?php else : ?>
                                    <?php /* translators: %s: assistant name, such as "ChatGPT". */ ?>
                                    <button type="submit" class="aipkit_btn aipkit_btn-primary" name="client" value="<?php echo esc_attr($aipkit_mcp_client); ?>" aria-label="<?php echo esc_attr(sprintf($aipkit_mcp_app['state'] === 'disconnected' ? __('Reconnect %s', 'gpt3-ai-content-generator') : __('Set up %s', 'gpt3-ai-content-generator'), $aipkit_mcp_client_name)); ?>"><?php echo esc_html($aipkit_mcp_app['state'] === 'disconnected' ? __('Reconnect', 'gpt3-ai-content-generator') : __('Set up', 'gpt3-ai-content-generator')); ?></button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($aipkit_mcp_connected_apps) : ?>
                    <?php if ($aipkit_mcp_to_add) : ?>
                        <details class="aipkit_mcp_more_accounts">
                            <?php /* translators: %s: assistant name, such as "ChatGPT". */ ?>
                            <summary><?php echo esc_html(sprintf(__('Using a second %s account?', 'gpt3-ai-content-generator'), $aipkit_mcp_app_names[$aipkit_mcp_connected_apps[0]])); ?> <span><?php esc_html_e('Connect another account', 'gpt3-ai-content-generator'); ?></span></summary>
                    <?php endif; ?>
                    <p class="aipkit_cloud_notice aipkit_cloud_notice--info"><?php esc_html_e('To connect another account, sign in to that account in ChatGPT or Claude first. Reauthorizing an existing connector replaces its access to this website.', 'gpt3-ai-content-generator'); ?></p>
                    <div class="aipkit_mcp_rows">
                        <?php foreach ($aipkit_mcp_connected_apps as $aipkit_mcp_client) : ?>
                            <div class="aipkit_mcp_row">
                                <span class="aipkit_mcp_tile aipkit_mcp_tile--<?php echo esc_attr($aipkit_mcp_client); ?>" aria-hidden="true"></span>
                                <?php /* translators: %s: assistant name, such as "ChatGPT". */ ?>
                                <span class="aipkit_mcp_row_text"><strong><?php echo esc_html($aipkit_mcp_app_names[$aipkit_mcp_client]); ?></strong><span><?php echo esc_html(sprintf(__('A second %s account', 'gpt3-ai-content-generator'), $aipkit_mcp_app_names[$aipkit_mcp_client])); ?></span></span>
                                <?php /* translators: %s: assistant name, such as "ChatGPT". */ ?>
                                <button type="submit" class="aipkit_btn" name="client" value="<?php echo esc_attr($aipkit_mcp_client); ?>" aria-label="<?php echo esc_attr(sprintf(__('Add another %s account', 'gpt3-ai-content-generator'), $aipkit_mcp_app_names[$aipkit_mcp_client])); ?>"><?php esc_html_e('Add account', 'gpt3-ai-content-generator'); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($aipkit_mcp_to_add) : ?>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
                <p class="aipkit_settings_provider_option_help"><?php printf(
                    /* translators: %s: "Set it up manually" button. */
                    esc_html__('Using another MCP app? %s.', 'gpt3-ai-content-generator'),
                    '<button type="submit" class="aipkit_mcp_quiet" name="client" value="other">' . esc_html__('Set it up manually', 'gpt3-ai-content-generator') . '</button>'
                ); ?></p>
                <?php
                // One click connects: Trusted, as the current user. The two choices stay folded behind one line.
                $aipkit_mcp_connect_user = wp_get_current_user();
                ?>
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
                <p class="aipkit_settings_provider_option_help"><?php esc_html_e('The bridge receives this site’s address, your WordPress user, the permissions you choose, and records of each operation and its usage. Your assistant receives only the content you ask it to read. Keys stay private, and you can disconnect at any time here.', 'gpt3-ai-content-generator'); ?></p>
                <?php \WPAICG\Mcp\Settings::render_connection_terms(); ?>
            </form>
        <?php endif; ?>
        <?php if ($aipkit_mcp_rows) : ?>
                </div>
            </details>
        <?php endif; ?>
        <?php // The hook stays on a plain wrapper: the column's display rule would otherwise override [hidden]. ?>
        <?php
        $aipkit_mcp_pending_setup = null;
        foreach ($aipkit_mcp_rows as $aipkit_mcp_setup_row) {
            if ($aipkit_mcp_setup_row['state'] !== 'pending' || (int) $aipkit_mcp_setup_row['wp_user_id'] !== get_current_user_id()) { continue; }
            $aipkit_mcp_setup_link = get_transient('aipkit_mcp_connect_' . $aipkit_mcp_setup_row['grant_id']);
            if ($aipkit_mcp_setup_link) { $aipkit_mcp_pending_setup = ['url' => $aipkit_mcp_setup_link, 'client' => $aipkit_mcp_setup_row['client_hint']]; break; }
        }
        ?>
        <div data-mcp-handoff <?php echo $aipkit_mcp_pending_setup ? '' : 'hidden'; ?>>
            <div class="aipkit_cloud_connect">
                <div class="aipkit_cloud_notice aipkit_cloud_notice--info"><?php esc_html_e('Finish the approval in your assistant, in this browser, within 10 minutes. Then come back here and select Check next to the assistant.', 'gpt3-ai-content-generator'); ?></div>
                <div class="aipkit_cloud_balance_actions"><a class="aipkit_btn aipkit_btn-primary" data-mcp-connect-link href="<?php echo $aipkit_mcp_pending_setup ? esc_url(add_query_arg('launch', '1', $aipkit_mcp_pending_setup['url'])) : ''; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(($aipkit_mcp_pending_setup['client'] ?? '') === 'chatgpt' ? __('Open ChatGPT plugins', 'gpt3-ai-content-generator') : (($aipkit_mcp_pending_setup['client'] ?? '') === 'claude' ? __('Open Claude setup', 'gpt3-ai-content-generator') : __('Open assistant setup', 'gpt3-ai-content-generator'))); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>
                <?php \WPAICG\Mcp\Settings::render_assistant_instructions('aipkit_settings_provider_option_help', $aipkit_mcp_pending_setup['client'] ?? 'chatgpt'); ?>
            </div>
        </div>
    </section>

</div>
<?php endif; ?>
