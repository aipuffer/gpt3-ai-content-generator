<?php
/**
 * Partial: Webhooks in For developers. A row opens its panel: the switch, the endpoints as rows, and the signing
 * secret. Each endpoint opens its own panel over it, with what it receives, a test send, and any send that failed.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (!class_exists('\WPAICG\Core\AIPKit_Event_Webhook_Status')) {
    require_once WPAICG_PLUGIN_DIR . 'classes/integrations/webhook-status.php';
}
$event_webhook_status = \WPAICG\Core\AIPKit_Event_Webhook_Status::class;
$event_webhook_settings = \WPAICG\Core\AIPKit_Event_Webhooks_Settings::get_settings();
$event_webhooks_enabled = (string) ($event_webhook_settings['enabled'] ?? '0') === '1';
$event_webhook_signing_secret = (string) ($event_webhook_settings['signing_secret'] ?? '');
$event_webhook_secret_mask = isset($aipkit_format_developer_credential_mask) && is_callable($aipkit_format_developer_credential_mask)
    ? $aipkit_format_developer_credential_mask($event_webhook_signing_secret)
    : '';
$event_webhook_endpoints = isset($event_webhook_settings['endpoints']) && is_array($event_webhook_settings['endpoints'])
    ? array_values(array_filter($event_webhook_settings['endpoints'], 'is_array'))
    : [];
$event_webhook_definitions = \WPAICG\Core\AIPKit_Event_Registry::get_definitions();
$event_webhook_field_key_map = \WPAICG\Core\AIPKit_Event_Webhooks_Settings::get_event_field_key_map();
$event_webhook_plain_labels = $event_webhook_status::event_labels();
$event_webhook_field_key_by_event_name = [];
foreach ($event_webhook_field_key_map as $field_key => $event_name) {
    $event_webhook_field_key_by_event_name[(string) $event_name] = (string) $field_key;
}
$event_webhook_module_labels = [
    'chatbot' => __('Chatbot', 'gpt3-ai-content-generator'),
    'content_writer' => __('Content Writer', 'gpt3-ai-content-generator'),
    'ai_forms' => __('AI Forms', 'gpt3-ai-content-generator'),
    'image_generator' => __('Images', 'gpt3-ai-content-generator'),
    'automated_tasks' => __('Automations', 'gpt3-ai-content-generator'),
    'knowledge_base' => __('Knowledge Base', 'gpt3-ai-content-generator'),
];
$event_webhook_groups = [];
foreach (array_keys($event_webhook_module_labels) as $module_key) {
    $event_webhook_groups[$module_key] = ['label' => $event_webhook_module_labels[$module_key], 'events' => []];
}
foreach ($event_webhook_definitions as $event_name => $definition) {
    $field_key = $event_webhook_field_key_by_event_name[$event_name] ?? '';
    if ($field_key === '') {
        continue;
    }
    $module_key = sanitize_key((string) ($definition['module'] ?? 'other'));
    if (!isset($event_webhook_groups[$module_key])) {
        $event_webhook_groups[$module_key] = [
            'label' => ucwords(str_replace('_', ' ', $module_key !== '' ? $module_key : 'other')),
            'events' => [],
        ];
    }
    $event_webhook_groups[$module_key]['events'][] = [
        'name' => (string) $event_name,
        'field_key' => $field_key,
        'label' => (string) ($event_webhook_plain_labels[$event_name] ?? ($definition['label'] ?? $event_name)),
    ];
}
$event_webhook_groups = array_filter($event_webhook_groups, static fn (array $group): bool => !empty($group['events']));
$event_webhook_event_names = [];
foreach ($event_webhook_groups as $group) {
    foreach ($group['events'] as $event_item) {
        $event_webhook_event_names[] = $event_item['name'];
    }
}
$event_webhook_event_total = count($event_webhook_event_names);

$event_webhook_host = static function (string $url): string {
    $host = $url !== '' ? (string) wp_parse_url($url, PHP_URL_HOST) : '';
    return $host !== '' ? $host : $url;
};
$event_webhook_hosts = [];
foreach ($event_webhook_endpoints as $endpoint) {
    $event_webhook_hosts[sanitize_key((string) ($endpoint['id'] ?? ''))] = $event_webhook_host((string) ($endpoint['url'] ?? ''));
}
$event_webhook_failures = $event_webhook_status::failures_by_endpoint($event_webhook_hosts);
$event_webhook_sent = $event_webhook_status::sent_times();
$event_webhook_failed_uuids = [];

// What a row and its panel say about an endpoint: failed, paused, sent, or not sent yet.
$event_webhook_state = static function (array $endpoint) use ($event_webhook_failures, $event_webhook_sent, $event_webhook_status): array {
    $id = sanitize_key((string) ($endpoint['id'] ?? ''));
    $failures = $id !== '' ? (array) ($event_webhook_failures[$id] ?? []) : [];
    if ($failures) {
        /* translators: %s: how long ago, e.g. "2 hours ago". */
        return ['kind' => 'failed', 'label' => sprintf(__('Failed %s', 'gpt3-ai-content-generator'), (string) $failures[0]['when']), 'failures' => $failures];
    }
    if ((string) ($endpoint['enabled'] ?? '0') !== '1') {
        return ['kind' => 'paused', 'label' => __('Paused', 'gpt3-ai-content-generator'), 'failures' => []];
    }
    if (!empty($event_webhook_sent[$id])) {
        /* translators: %s: how long ago, e.g. "5 min ago". */
        return ['kind' => 'sent', 'label' => sprintf(__('Sent %s', 'gpt3-ai-content-generator'), $event_webhook_status::ago((int) $event_webhook_sent[$id])), 'failures' => []];
    }
    return ['kind' => 'none', 'label' => __('Not sent yet', 'gpt3-ai-content-generator'), 'failures' => []];
};

$event_webhook_events_label = static function (int $selected) use ($event_webhook_event_total): string {
    if ($selected === 0) {
        return __('No events yet', 'gpt3-ai-content-generator');
    }
    if ($selected === $event_webhook_event_total) {
        return __('All events', 'gpt3-ai-content-generator');
    }
    /* translators: %d: number of events. */
    return sprintf(_n('%d event', '%d events', $selected, 'gpt3-ai-content-generator'), $selected);
};

$render_event_webhook_row = static function (array $endpoint, array $state, int $selected) use ($event_webhook_host, $event_webhook_events_label): void {
    $id = sanitize_key((string) ($endpoint['id'] ?? ''));
    $url = (string) ($endpoint['url'] ?? '');
    $name = trim((string) ($endpoint['name'] ?? ''));
    $host = $event_webhook_host($url);
    ?>
    <li>
        <button type="button" class="aipkit_settings_developer_endpoint_row" data-aipkit-event-webhook-row="<?php echo esc_attr($id); ?>" data-aipkit-provider-settings-open="dev-endpoint-<?php echo esc_attr($id); ?>" aria-haspopup="dialog">
            <span class="aipkit_settings_developer_endpoint_copy">
                <span class="aipkit_settings_developer_endpoint_name" data-aipkit-event-webhook-row-name><?php echo esc_html($name !== '' ? $name : ($host !== '' ? $host : __('New endpoint', 'gpt3-ai-content-generator'))); ?></span>
                <span class="aipkit_settings_developer_endpoint_meta" data-aipkit-event-webhook-row-meta><?php echo esc_html(implode(' · ', array_filter([$host, $event_webhook_events_label($selected)]))); ?></span>
            </span>
            <span class="aipkit_settings_developer_status" data-aipkit-webhook-status="<?php echo esc_attr($state['kind']); ?>"><?php echo esc_html($state['label']); ?></span>
            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
        </button>
    </li>
    <?php
};

$render_event_webhook_endpoint = static function ($index, array $endpoint = []) use ($event_webhook_groups, $event_webhook_event_names, $event_webhook_state, $event_webhook_events_label, $event_webhook_host, $event_webhook_sent, $event_webhook_status): void {
    $endpoint_index = (string) $index;
    $endpoint_dom_index = sanitize_key($endpoint_index);
    $endpoint_id = sanitize_key((string) ($endpoint['id'] ?? ''));
    $endpoint_name = (string) ($endpoint['name'] ?? '');
    $endpoint_url = (string) ($endpoint['url'] ?? '');
    $endpoint_enabled = $endpoint === [] || (isset($endpoint['enabled']) && (string) $endpoint['enabled'] === '1');
    $endpoint_events = isset($endpoint['events']) && is_array($endpoint['events']) ? $endpoint['events'] : [];
    $state = $endpoint === [] ? ['kind' => 'none', 'label' => __('Not sent yet', 'gpt3-ai-content-generator'), 'failures' => []] : $event_webhook_state($endpoint);
    $selected = count(array_intersect(array_map('strval', $endpoint_events), $event_webhook_event_names));
    $title_id = 'aipkit_event_webhook_endpoint_' . $endpoint_dom_index . '_title';
    $host = $event_webhook_host($endpoint_url);
    $failures = $state['failures'];
    /* translators: %s: how long ago, e.g. "5 min ago". */
    $sent_label = $endpoint_id !== '' && !empty($event_webhook_sent[$endpoint_id]) ? sprintf(__('Sent %s', 'gpt3-ai-content-generator'), $event_webhook_status::ago((int) $event_webhook_sent[$endpoint_id])) : '';
    ?>
    <div
        class="aipkit-modal-overlay aipkit_settings_provider_modal"
        data-aipkit-provider-modal="dev-endpoint-<?php echo esc_attr($endpoint_id !== '' ? $endpoint_id : '__ID__'); ?>"
        data-aipkit-event-webhook-endpoint
        data-aipkit-developer-parent="dev-webhooks"
        data-endpoint-index="<?php echo esc_attr($endpoint_index); ?>"
        data-sent-label="<?php echo esc_attr($sent_label); ?>"
        aria-hidden="true"
    >
        <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>">
            <div class="aipkit_settings_provider_panel_header">
                <button type="button" class="aipkit_settings_provider_panel_close aipkit_settings_developer_back" data-aipkit-developer-back aria-label="<?php esc_attr_e('Back', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>
                <span class="aipkit_settings_provider_logo aipkit_settings_developer_logo" aria-hidden="true"><span class="dashicons dashicons-randomize"></span></span>
                <div class="aipkit_settings_provider_panel_heading">
                    <div class="aipkit_settings_provider_panel_title_row">
                        <h2 class="aipkit_settings_provider_panel_title" id="<?php echo esc_attr($title_id); ?>" data-aipkit-event-webhook-endpoint-title data-untitled="<?php esc_attr_e('New endpoint', 'gpt3-ai-content-generator'); ?>"><?php echo esc_html($endpoint_name !== '' ? $endpoint_name : ($host !== '' ? $host : __('New endpoint', 'gpt3-ai-content-generator'))); ?></h2>
                        <span class="aipkit_settings_developer_status" data-aipkit-webhook-status="<?php echo esc_attr($state['kind']); ?>"><?php echo esc_html($state['label']); ?></span>
                    </div>
                    <p class="aipkit_settings_provider_panel_hint" data-aipkit-event-webhook-endpoint-hint><?php echo esc_html(implode(' · ', [__('Webhooks', 'gpt3-ai-content-generator'), $event_webhook_events_label($selected)])); ?></p>
                </div>
            </div>
            <div class="aipkit_settings_provider_panel_body">
                <input type="hidden" name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][id]" value="<?php echo esc_attr($endpoint_id); ?>" class="aipkit_autosave_trigger" data-aipkit-endpoint-field="id" />
                <?php if ($failures) : ?>
                    <div
                        class="aipkit_settings_provider_error aipkit_settings_developer_failure"
                        data-aipkit-webhook-failures="<?php echo esc_attr(wp_json_encode(array_column($failures, 'uuid'))); ?>"
                        data-chip-label="<?php echo esc_attr($state['label']); ?>"
                        <?php /* translators: %s: how long ago, e.g. "2 hours ago". */ ?>
                        data-chip-template="<?php esc_attr_e('Failed %s', 'gpt3-ai-content-generator'); ?>"
                        <?php /* translators: %d: number of failed sends. */ ?>
                        data-title-many="<?php esc_attr_e('The last %d sends didn’t go through', 'gpt3-ai-content-generator'); ?>"
                        data-title-one="<?php esc_attr_e('The last send didn’t go through', 'gpt3-ai-content-generator'); ?>"
                        data-retry-one="<?php esc_attr_e('Send it again', 'gpt3-ai-content-generator'); ?>"
                        data-retry-many="<?php esc_attr_e('Send them again', 'gpt3-ai-content-generator'); ?>"
                        data-done="<?php esc_attr_e('Sent. It went through this time.', 'gpt3-ai-content-generator'); ?>"
                        role="alert"
                    >
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <div class="aipkit_settings_provider_error_content">
                            <?php /* translators: %d: number of failed sends. */ ?>
                            <p class="aipkit_settings_developer_failure_title" data-aipkit-webhook-failure-title><?php echo esc_html(count($failures) > 1 ? sprintf(__('The last %d sends didn’t go through', 'gpt3-ai-content-generator'), count($failures)) : __('The last send didn’t go through', 'gpt3-ai-content-generator')); ?></p>
                            <p data-aipkit-webhook-failure-reason><?php echo esc_html((string) $failures[0]['reason']); ?></p>
                            <p class="aipkit_settings_developer_failure_when" data-aipkit-webhook-failure-when><?php echo esc_html(implode(' · ', array_filter([(string) $failures[0]['when'], (string) $failures[0]['event']]))); ?></p>
                            <div class="aipkit_settings_developer_failure_actions">
                                <button type="button" class="aipkit_settings_developer_failure_retry" data-aipkit-webhook-failure-retry><span class="dashicons dashicons-update" aria-hidden="true"></span><span data-aipkit-webhook-failure-retry-label><?php echo count($failures) > 1 ? esc_html__('Send them again', 'gpt3-ai-content-generator') : esc_html__('Send it again', 'gpt3-ai-content-generator'); ?></span></button>
                                <button type="button" class="aipkit_settings_developer_failure_dismiss" data-aipkit-webhook-failure-dismiss><?php esc_html_e('Dismiss', 'gpt3-ai-content-generator'); ?></button>
                            </div>
                            <details class="aipkit_settings_provider_error_details">
                                <summary><?php esc_html_e('View details', 'gpt3-ai-content-generator'); ?></summary>
                                <p data-aipkit-webhook-failure-details><?php echo esc_html((string) $failures[0]['details']); ?></p>
                            </details>
                        </div>
                    </div>
                <?php endif; ?>
                <p class="aipkit_settings_developer_result" data-aipkit-webhook-result role="status" hidden></p>

                <section class="aipkit_settings_provider_panel_section">
                    <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Where', 'gpt3-ai-content-generator'); ?></h3>
                    <div class="aipkit_settings_provider_field">
                        <label class="aipkit_settings_provider_option_label" for="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_name"><?php esc_html_e('Name', 'gpt3-ai-content-generator'); ?></label>
                        <input type="text" id="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_name" name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][name]" value="<?php echo esc_attr($endpoint_name); ?>" class="aipkit_form-input aipkit_autosave_trigger" data-aipkit-endpoint-field="name" placeholder="<?php esc_attr_e('CRM sync', 'gpt3-ai-content-generator'); ?>" />
                    </div>
                    <div class="aipkit_settings_provider_field">
                        <label class="aipkit_settings_provider_option_label" for="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_url"><?php esc_html_e('URL', 'gpt3-ai-content-generator'); ?></label>
                        <input type="url" id="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_url" name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][url]" value="<?php echo esc_attr($endpoint_url); ?>" class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_developer_mono" data-aipkit-endpoint-field="url" placeholder="<?php esc_attr_e('https://example.com/webhooks/aipuffer', 'gpt3-ai-content-generator'); ?>" />
                    </div>
                </section>

                <section class="aipkit_settings_provider_panel_section">
                    <div class="aipkit_settings_developer_section_head">
                        <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('What it receives', 'gpt3-ai-content-generator'); ?></h3>
                        <span class="aipkit_settings_developer_section_actions">
                            <button type="button" class="aipkit_settings_developer_text_button" data-aipkit-event-webhook-events-select-all><?php esc_html_e('All', 'gpt3-ai-content-generator'); ?></button>
                            <button type="button" class="aipkit_settings_developer_text_button" data-aipkit-event-webhook-events-clear><?php esc_html_e('None', 'gpt3-ai-content-generator'); ?></button>
                        </span>
                    </div>
                    <div class="aipkit_settings_developer_events">
                        <?php foreach ($event_webhook_groups as $group) : ?>
                            <fieldset class="aipkit_settings_developer_event_group">
                                <legend><?php echo esc_html((string) $group['label']); ?></legend>
                                <?php foreach ($group['events'] as $event_item) :
                                    $event_checkbox_id = 'aipkit_event_webhook_endpoint_' . $endpoint_dom_index . '_event_' . sanitize_key($event_item['field_key']);
                                    ?>
                                    <label class="aipkit_settings_developer_event" for="<?php echo esc_attr($event_checkbox_id); ?>">
                                        <input
                                            type="checkbox"
                                            id="<?php echo esc_attr($event_checkbox_id); ?>"
                                            name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][events][<?php echo esc_attr($event_item['field_key']); ?>]"
                                            value="1"
                                            data-aipkit-endpoint-field="event"
                                            data-aipkit-event-field-key="<?php echo esc_attr($event_item['field_key']); ?>"
                                            data-aipkit-event-name="<?php echo esc_attr($event_item['name']); ?>"
                                            <?php checked(in_array($event_item['name'], $endpoint_events, true)); ?>
                                        />
                                        <span class="aipkit_settings_developer_event_copy">
                                            <span><?php echo esc_html($event_item['label']); ?></span>
                                            <code><?php echo esc_html($event_item['name']); ?></code>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="aipkit_settings_developer_test">
                    <button
                        type="button"
                        class="aipkit_btn aipkit_btn-secondary aipkit_settings_developer_button"
                        data-aipkit-webhook-test
                        data-busy-label="<?php esc_attr_e('Sending…', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                        <span data-aipkit-webhook-test-label><?php esc_html_e('Send a test event', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <span class="aipkit_settings_provider_option_help"><?php esc_html_e('Sends a sample of the first event it receives, marked as a test.', 'gpt3-ai-content-generator'); ?></span>
                </div>
            </div>
            <div class="aipkit_settings_provider_modal_footer">
                <button type="button" class="aipkit_settings_provider_remove" data-aipkit-remove-event-webhook-endpoint><?php esc_html_e('Delete endpoint', 'gpt3-ai-content-generator'); ?></button>
                <label class="aipkit_settings_developer_sending" for="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_enabled">
                    <span><?php esc_html_e('Sending', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_switch">
                        <input type="checkbox" id="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_enabled" name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][enabled]" value="1" class="aipkit_autosave_trigger" data-aipkit-endpoint-field="enabled" <?php checked($endpoint_enabled); ?> />
                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                    </span>
                </label>
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
            </div>
        </div>
    </div>
    <?php
};

$event_webhook_rows = [];
foreach ($event_webhook_endpoints as $endpoint) {
    $state = $event_webhook_state($endpoint);
    foreach ($state['failures'] as $failure) {
        $event_webhook_failed_uuids[$failure['uuid']] = true;
    }
    $selected = count(array_intersect(array_map('strval', (array) ($endpoint['events'] ?? [])), $event_webhook_event_names));
    $event_webhook_rows[] = [$endpoint, $state, $selected];
}
$event_webhook_failed_count = count($event_webhook_failed_uuids);
$event_webhook_endpoint_count = count($event_webhook_endpoints);
$event_webhook_summary_data = [
    'off' => __('Off', 'gpt3-ai-content-generator'),
    'none' => __('On · No endpoints yet', 'gpt3-ai-content-generator'),
    /* translators: %d: number of endpoints. */
    'one' => __('On · %d endpoint', 'gpt3-ai-content-generator'),
    /* translators: %d: number of endpoints. */
    'many' => __('On · %d endpoints', 'gpt3-ai-content-generator'),
    /* translators: %d: number of endpoints. */
    'count-one' => __('%d endpoint', 'gpt3-ai-content-generator'),
    /* translators: %d: number of endpoints. */
    'count-many' => __('%d endpoints', 'gpt3-ai-content-generator'),
    /* translators: %d: number of failed sends. */
    'failed-one' => __('%d failed send', 'gpt3-ai-content-generator'),
    /* translators: %d: number of failed sends. */
    'failed-many' => __('%d failed sends', 'gpt3-ai-content-generator'),
];
if (!$event_webhooks_enabled) {
    $event_webhook_summary = $event_webhook_summary_data['off'];
} elseif ($event_webhook_failed_count > 0) {
    /* translators: %d: number of endpoints. */
    $event_webhook_summary = sprintf(_n('%d endpoint', '%d endpoints', $event_webhook_endpoint_count, 'gpt3-ai-content-generator'), $event_webhook_endpoint_count)
        /* translators: %d: number of failed sends. */
        . ' · ' . sprintf(_n('%d failed send', '%d failed sends', $event_webhook_failed_count, 'gpt3-ai-content-generator'), $event_webhook_failed_count);
} elseif ($event_webhook_endpoint_count === 0) {
    $event_webhook_summary = $event_webhook_summary_data['none'];
} else {
    /* translators: %d: number of endpoints. */
    $event_webhook_summary = sprintf(_n('On · %d endpoint', 'On · %d endpoints', $event_webhook_endpoint_count, 'gpt3-ai-content-generator'), $event_webhook_endpoint_count);
}
$event_webhook_has_failures = $event_webhooks_enabled && $event_webhook_failed_count > 0;
?>

<article
    id="aipkit_settings_event_webhooks_section"
    class="aipkit_settings_provider_card aipkit_settings_integration_card aipkit_settings_developer_card<?php echo $event_webhook_has_failures ? ' has-provider-error' : ''; ?>"
    data-aipkit-developer-card="webhooks"
    data-aipkit-provider-connected="<?php echo $event_webhooks_enabled ? 'true' : 'false'; ?>"
    data-aipkit-developer-credential="webhook"
    data-enabled="<?php echo $event_webhooks_enabled ? 'true' : 'false'; ?>"
>
    <?php $aipkit_render_dev_row('dev-webhooks', __('Webhooks', 'gpt3-ai-content-generator'), 'randomize', $event_webhook_summary, $event_webhook_summary_data); ?>
    <div class="aipkit-modal-overlay aipkit_settings_provider_modal" id="aipkit_settings_dev_webhooks_modal" data-aipkit-provider-modal="dev-webhooks" aria-hidden="true">
        <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="aipkit_settings_dev_webhooks_title">
            <?php $aipkit_render_dev_panel_header('dev-webhooks', __('Webhooks', 'gpt3-ai-content-generator'), __('Send events to your own systems.', 'gpt3-ai-content-generator'), 'randomize'); ?>
            <div class="aipkit_settings_provider_panel_body">
                <?php $aipkit_render_dev_error(); ?>
                <div class="aipkit_settings_provider_option_list">
                    <div class="aipkit_settings_provider_option is-inline" id="aipkit_settings_event_webhooks_enabled_row">
                        <div class="aipkit_settings_provider_option_copy">
                            <label class="aipkit_settings_provider_option_label" for="aipkit_event_webhooks_enabled"><?php esc_html_e('Send events', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_settings_provider_option_help"><?php esc_html_e('When something happens in AI Puffer, it’s sent to your endpoints as JSON.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <div class="aipkit_settings_provider_option_control">
                            <label class="aipkit_switch" for="aipkit_event_webhooks_enabled">
                                <input type="checkbox" id="aipkit_event_webhooks_enabled" name="event_webhooks[enabled]" value="1" data-aipkit-developer-enabled <?php checked($event_webhooks_enabled); ?> />
                                <span class="aipkit_switch_slider" aria-hidden="true"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <section class="aipkit_settings_provider_panel_section" id="aipkit_settings_event_webhooks_endpoints_row" data-aipkit-developer-dependent <?php echo $event_webhooks_enabled ? '' : 'hidden'; ?>>
                    <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Endpoints', 'gpt3-ai-content-generator'); ?></h3>
                    <ul class="aipkit_settings_developer_endpoints" data-aipkit-event-webhook-rows>
                        <?php foreach ($event_webhook_rows as [$endpoint, $state, $selected]) {
                            $render_event_webhook_row($endpoint, $state, $selected);
                        } ?>
                        <li>
                            <button type="button" class="aipkit_settings_developer_endpoint_add" id="aipkit_add_event_webhook_endpoint_btn">
                                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                <span><?php esc_html_e('Add an endpoint', 'gpt3-ai-content-generator'); ?></span>
                            </button>
                        </li>
                    </ul>
                </section>

                <section class="aipkit_settings_provider_panel_section" id="aipkit_settings_event_webhooks_secret_row" data-aipkit-developer-dependent <?php echo $event_webhooks_enabled ? '' : 'hidden'; ?>>
                    <h3 class="aipkit_settings_provider_panel_section_title"><label for="aipkit_event_webhooks_signing_secret"><?php esc_html_e('Signing secret', 'gpt3-ai-content-generator'); ?></label></h3>
                    <?php $aipkit_render_dev_credential('aipkit_event_webhooks_signing_secret', $event_webhook_secret_mask, __('signing secret', 'gpt3-ai-content-generator'), __('Each request is signed with it, so you can check it came from this site.', 'gpt3-ai-content-generator'), __('Make a new secret', 'gpt3-ai-content-generator')); ?>
                    <details class="aipkit_settings_developer_howto">
                        <summary><?php esc_html_e('How to check a request', 'gpt3-ai-content-generator'); ?></summary>
                        <p class="aipkit_settings_developer_text"><?php esc_html_e('Each request has these headers. Sign the timestamp, a dot and the raw body with your secret using HMAC SHA-256, and compare.', 'gpt3-ai-content-generator'); ?></p>
                        <pre class="aipkit_settings_developer_code"><code>X-AIPKit-Event: chatbot.response_generated
X-AIPKit-Timestamp: 1791462033
X-AIPKit-Signature: sha256=hex(hmac_sha256(secret, timestamp + "." + body))</code></pre>
                    </details>
                </section>
            </div>
            <?php $aipkit_render_dev_footer(); ?>
        </div>
    </div>

    <div id="aipkit_settings_event_webhooks_endpoint_list" data-aipkit-event-webhook-list><?php
        foreach ($event_webhook_endpoints as $endpoint_index => $endpoint) {
            $render_event_webhook_endpoint($endpoint_index, $endpoint);
        }
    ?></div>

    <template id="aipkit_event_webhook_endpoint_template"><?php $render_event_webhook_endpoint('__INDEX__'); ?></template>
    <template data-aipkit-event-webhook-row-template><?php $render_event_webhook_row([], ['kind' => 'none', 'label' => __('Not sent yet', 'gpt3-ai-content-generator'), 'failures' => []], 0); ?></template>
    <?php // Words the script needs when it updates a row or the summary. ?>
    <script type="application/json" data-aipkit-event-webhook-words><?php echo wp_json_encode([
        'total' => $event_webhook_event_total,
        'noEvents' => __('No events yet', 'gpt3-ai-content-generator'),
        'allEvents' => __('All events', 'gpt3-ai-content-generator'),
        /* translators: %d: number of events. */
        'oneEvent' => __('%d event', 'gpt3-ai-content-generator'),
        /* translators: %d: number of events. */
        'manyEvents' => __('%d events', 'gpt3-ai-content-generator'),
        'webhooks' => __('Webhooks', 'gpt3-ai-content-generator'),
        'newEndpoint' => __('New endpoint', 'gpt3-ai-content-generator'),
        'paused' => __('Paused', 'gpt3-ai-content-generator'),
        'notSent' => __('Not sent yet', 'gpt3-ai-content-generator'),
        /* translators: %s: how long ago, e.g. "just now". */
        'sent' => __('Sent %s', 'gpt3-ai-content-generator'),
        'justNow' => __('just now', 'gpt3-ai-content-generator'),
        /* translators: %s: how long ago, e.g. "2 hours ago". */
        'failed' => __('Failed %s', 'gpt3-ai-content-generator'),
        'deleteTitle' => __('Delete this endpoint?', 'gpt3-ai-content-generator'),
        'deleteText' => __('It stops getting events. Its failed sends are kept until they expire.', 'gpt3-ai-content-generator'),
        'deleteButton' => __('Delete endpoint', 'gpt3-ai-content-generator'),
        'cancel' => __('Cancel', 'gpt3-ai-content-generator'),
    ]); ?></script>
</article>
