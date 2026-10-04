<?php
/**
 * Partial: Event Webhooks Settings Section
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$event_webhook_settings = \WPAICG\Core\AIPKit_Event_Webhooks_Settings::get_settings();
$event_webhooks_enabled = (string) ($event_webhook_settings['enabled'] ?? '0') === '1' ? '1' : '0';
$event_webhook_signing_secret = (string) ($event_webhook_settings['signing_secret'] ?? '');
$event_webhook_secret_mask = isset($aipkit_format_developer_credential_mask) && is_callable($aipkit_format_developer_credential_mask)
    ? $aipkit_format_developer_credential_mask($event_webhook_signing_secret)
    : '';
$event_webhook_endpoints = isset($event_webhook_settings['endpoints']) && is_array($event_webhook_settings['endpoints'])
    ? array_values($event_webhook_settings['endpoints'])
    : [];
$event_webhook_definitions = \WPAICG\Core\AIPKit_Event_Registry::get_definitions();
$event_webhook_queue_store_class = \WPAICG\Core\AIPKit_Event_Queue_Store::class;
$event_webhook_field_key_map = \WPAICG\Core\AIPKit_Event_Webhooks_Settings::get_event_field_key_map();
$event_webhook_delivery_issues = class_exists($event_webhook_queue_store_class) && method_exists($event_webhook_queue_store_class, 'get_recent_failed_webhook_jobs')
    ? $event_webhook_queue_store_class::get_recent_failed_webhook_jobs(5)
    : [];
$event_webhook_field_key_by_event_name = [];
foreach ($event_webhook_field_key_map as $field_key => $event_name) {
    $event_webhook_field_key_by_event_name[(string) $event_name] = (string) $field_key;
}
$event_webhook_module_labels = [
    'chatbot' => __('Chatbot', 'gpt3-ai-content-generator'),
    'ai_forms' => __('AI Forms', 'gpt3-ai-content-generator'),
    'content_writer' => __('Content Writer', 'gpt3-ai-content-generator'),
    'automated_tasks' => __('Automated Tasks', 'gpt3-ai-content-generator'),
    'image_generator' => __('Image Generator', 'gpt3-ai-content-generator'),
    'knowledge_base' => __('Knowledge Base', 'gpt3-ai-content-generator'),
];
$event_webhook_groups = [];
foreach ($event_webhook_definitions as $event_name => $definition) {
    $field_key = $event_webhook_field_key_by_event_name[$event_name] ?? '';
    if ($field_key === '') {
        continue;
    }

    $module_key = sanitize_key((string) ($definition['module'] ?? 'other'));
    if (!isset($event_webhook_groups[$module_key])) {
        $event_webhook_groups[$module_key] = [
            'label' => $event_webhook_module_labels[$module_key]
                ?? ucwords(str_replace('_', ' ', $module_key !== '' ? $module_key : 'other')),
            'events' => [],
        ];
    }

    $event_webhook_groups[$module_key]['events'][] = [
        'name' => $event_name,
        'field_key' => $field_key,
        'definition' => $definition,
    ];
}

$event_webhook_group_order = [
    'chatbot',
    'content_writer',
    'ai_forms',
    'image_generator',
    'automated_tasks',
    'knowledge_base',
];

$ordered_event_webhook_groups = [];
foreach ($event_webhook_group_order as $module_key) {
    if (isset($event_webhook_groups[$module_key])) {
        $ordered_event_webhook_groups[$module_key] = $event_webhook_groups[$module_key];
        unset($event_webhook_groups[$module_key]);
    }
}

if (!empty($event_webhook_groups)) {
    $ordered_event_webhook_groups = array_merge($ordered_event_webhook_groups, $event_webhook_groups);
}

$event_webhook_groups = $ordered_event_webhook_groups;

$render_event_webhook_endpoint = static function ($index, array $endpoint = []) use ($event_webhook_groups): void {
    $endpoint_index = (string) $index;
    $endpoint_dom_index = sanitize_key($endpoint_index);
    $endpoint_id = sanitize_key((string) ($endpoint['id'] ?? ''));
    $endpoint_name = (string) ($endpoint['name'] ?? '');
    $endpoint_url = (string) ($endpoint['url'] ?? '');
    $endpoint_enabled = isset($endpoint['enabled']) && (string) $endpoint['enabled'] === '1';
    $endpoint_events = isset($endpoint['events']) && is_array($endpoint['events']) ? $endpoint['events'] : [];
    $endpoint_event_count = 0;
    $endpoint_selected_event_count = 0;
    foreach ($event_webhook_groups as $group) {
        if (empty($group['events']) || !is_array($group['events'])) {
            continue;
        }
        foreach ($group['events'] as $event_item) {
            $event_name = (string) ($event_item['name'] ?? '');
            $field_key = (string) ($event_item['field_key'] ?? '');
            if ($event_name === '' || $field_key === '') {
                continue;
            }
            $endpoint_event_count++;
            if (in_array($event_name, $endpoint_events, true)) {
                $endpoint_selected_event_count++;
            }
        }
    }
    if ($endpoint_selected_event_count === 0) {
        $endpoint_events_label = __('Select events', 'gpt3-ai-content-generator');
    } elseif ($endpoint_event_count > 0 && $endpoint_selected_event_count === $endpoint_event_count) {
        $endpoint_events_label = __('All events selected', 'gpt3-ai-content-generator');
    } else {
        $endpoint_events_label = sprintf(
            /* translators: %d: number of selected webhook events. */
            _n('%d event selected', '%d events selected', $endpoint_selected_event_count, 'gpt3-ai-content-generator'),
            number_format_i18n($endpoint_selected_event_count)
        );
    }
    $endpoint_events_modal_id = 'aipkit_event_webhook_endpoint_' . $endpoint_dom_index . '_events_modal';
    $endpoint_events_modal_title_id = $endpoint_events_modal_id . '_title';
    $endpoint_events_count_label = sprintf(
        /* translators: %d: number of selected webhook events. */
        _n('%d selected', '%d selected', $endpoint_selected_event_count, 'gpt3-ai-content-generator'),
        number_format_i18n($endpoint_selected_event_count)
    );
    ?>
    <article class="aipkit_settings_event_webhook_endpoint" data-aipkit-event-webhook-endpoint data-endpoint-index="<?php echo esc_attr($endpoint_index); ?>">
        <input
            type="hidden"
            name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][id]"
            value="<?php echo esc_attr($endpoint_id); ?>"
            class="aipkit_autosave_trigger"
            data-aipkit-endpoint-field="id"
        />
        <div class="aipkit_settings_event_webhook_endpoint_header">
            <div class="aipkit_settings_event_webhook_endpoint_heading">
                <strong class="aipkit_settings_event_webhook_endpoint_title" data-aipkit-event-webhook-endpoint-title>
                    <?php esc_html_e('Endpoint', 'gpt3-ai-content-generator'); ?>
                </strong>
                <span class="aipkit_settings_event_webhook_endpoint_index" data-aipkit-event-webhook-endpoint-number></span>
            </div>
            <div class="aipkit_settings_event_webhook_endpoint_actions">
                <label class="aipkit_settings_event_webhook_toggle" for="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_enabled">
                    <span><?php esc_html_e('Enabled', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_switch">
                        <input
                            type="checkbox"
                            id="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_enabled"
                            name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][enabled]"
                            value="1"
                            class="aipkit_autosave_trigger"
                            data-aipkit-endpoint-field="enabled"
                            <?php checked($endpoint_enabled); ?>
                        />
                        <span class="aipkit_switch_slider"></span>
                    </span>
                </label>
            </div>
        </div>

        <div class="aipkit_settings_event_webhook_endpoint_fields">
            <label class="aipkit_settings_event_webhook_field" for="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_name">
                <span class="aipkit_settings_event_webhook_field_label"><?php esc_html_e('Name', 'gpt3-ai-content-generator'); ?></span>
                <input
                    type="text"
                    id="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_name"
                    name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][name]"
                    value="<?php echo esc_attr($endpoint_name); ?>"
                    class="aipkit_form-input aipkit_autosave_trigger"
                    data-aipkit-endpoint-field="name"
                    placeholder="<?php esc_attr_e('Slack', 'gpt3-ai-content-generator'); ?>"
                />
            </label>
            <label class="aipkit_settings_event_webhook_field" for="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_url">
                <span class="aipkit_settings_event_webhook_field_label"><?php esc_html_e('Endpoint URL', 'gpt3-ai-content-generator'); ?></span>
                <input
                    type="url"
                    id="aipkit_event_webhook_endpoint_<?php echo esc_attr($endpoint_dom_index); ?>_url"
                    name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][url]"
                    value="<?php echo esc_attr($endpoint_url); ?>"
                    class="aipkit_form-input aipkit_autosave_trigger"
                    data-aipkit-endpoint-field="url"
                    placeholder="<?php esc_attr_e('https://example.com/webhooks/aipkit', 'gpt3-ai-content-generator'); ?>"
                />
            </label>
        </div>

        <div class="aipkit_settings_event_webhook_events">
            <span class="aipkit_settings_event_webhook_field_label"><?php esc_html_e('Subscribed events', 'gpt3-ai-content-generator'); ?></span>
            <div
                class="aipkit_settings_event_webhook_events_control"
                data-aipkit-event-webhook-events-control
                data-placeholder="<?php echo esc_attr__('Select events', 'gpt3-ai-content-generator'); ?>"
                data-all-label="<?php echo esc_attr__('All events selected', 'gpt3-ai-content-generator'); ?>"
                <?php /* translators: %d: Number of selected webhook events. */ ?>
                data-singular-label="<?php echo esc_attr__('%d event selected', 'gpt3-ai-content-generator'); ?>"
                <?php /* translators: %d: Number of selected webhook events. */ ?>
                data-plural-label="<?php echo esc_attr__('%d events selected', 'gpt3-ai-content-generator'); ?>"
                <?php /* translators: %d: Number of selected webhook events. */ ?>
                data-selected-singular-label="<?php echo esc_attr__('%d selected', 'gpt3-ai-content-generator'); ?>"
                <?php /* translators: %d: Number of selected webhook events. */ ?>
                data-selected-plural-label="<?php echo esc_attr__('%d selected', 'gpt3-ai-content-generator'); ?>"
            >
                <button
                    type="button"
                    class="aipkit_settings_event_webhook_events_btn"
                    aria-expanded="false"
                    aria-haspopup="dialog"
                    aria-controls="<?php echo esc_attr($endpoint_events_modal_id); ?>"
                    data-aipkit-event-webhook-events-toggle
                >
                    <span data-aipkit-event-webhook-events-label><?php echo esc_html($endpoint_events_label); ?></span>
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </button>

                <div
                    id="<?php echo esc_attr($endpoint_events_modal_id); ?>"
                    class="aipkit-modal-overlay aipkit_settings_event_webhook_events_modal"
                    data-aipkit-event-webhook-events-modal
                    aria-hidden="true"
                >
                    <div
                        class="aipkit-modal-content aipkit-modal-shell aipkit_settings_event_webhook_events_modal_content"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="<?php echo esc_attr($endpoint_events_modal_title_id); ?>"
                    >
                        <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_settings_event_webhook_events_modal_header">
                            <div class="aipkit-modal-shell-intro">
                                <h2 class="aipkit-modal-shell-title" id="<?php echo esc_attr($endpoint_events_modal_title_id); ?>">
                                    <?php esc_html_e('Subscribed events', 'gpt3-ai-content-generator'); ?>
                                </h2>
                                <p class="aipkit-modal-shell-copy"><?php esc_html_e('Choose which events this endpoint receives.', 'gpt3-ai-content-generator'); ?></p>
                            </div>
                            <button type="button" class="aipkit-modal-close-btn aipkit-modal-shell-close" data-aipkit-event-webhook-events-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>">
                                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                            </button>
                        </div>

                        <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_settings_event_webhook_events_modal_body">
                            <div class="aipkit_settings_event_webhook_events_tools">
                                <label class="aipkit_settings_event_webhook_events_search">
                                    <span class="screen-reader-text"><?php esc_html_e('Search events', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                    <input
                                        type="search"
                                        class="aipkit_form-input"
                                        placeholder="<?php esc_attr_e('Search events', 'gpt3-ai-content-generator'); ?>"
                                        data-aipkit-event-webhook-events-search
                                        autocomplete="off"
                                    />
                                </label>
                                <div class="aipkit_settings_event_webhook_events_actions">
                                    <button type="button" class="button aipkit_btn aipkit_btn-secondary" data-aipkit-event-webhook-events-select-all>
                                        <?php esc_html_e('Select all', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                    <button type="button" class="button aipkit_btn aipkit_btn-secondary" data-aipkit-event-webhook-events-clear>
                                        <?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                </div>
                            </div>

                            <div class="aipkit_settings_event_webhook_group_list">
                                <?php foreach ($event_webhook_groups as $group) : ?>
                                    <?php if (empty($group['events']) || !is_array($group['events'])) { continue; } ?>
                                    <section class="aipkit_settings_event_webhook_group" data-aipkit-event-webhook-events-group>
                                        <h3 class="aipkit_settings_event_webhook_group_title"><?php echo esc_html((string) ($group['label'] ?? '')); ?></h3>
                                        <div class="aipkit_settings_event_webhook_event_grid">
                                            <?php foreach ($group['events'] as $event_item) : ?>
                                                <?php
                                                $event_name = (string) ($event_item['name'] ?? '');
                                                $field_key = (string) ($event_item['field_key'] ?? '');
                                                $definition = isset($event_item['definition']) && is_array($event_item['definition'])
                                                    ? $event_item['definition']
                                                    : [];
                                                if ($event_name === '' || $field_key === '') {
                                                    continue;
                                                }
                                                $event_label = (string) ($definition['label'] ?? $event_name);
                                                $event_checkbox_id = 'aipkit_event_webhook_endpoint_' . $endpoint_dom_index . '_event_' . sanitize_key($field_key);
                                                $event_search_text = strtolower(implode(' ', [
                                                    (string) ($group['label'] ?? ''),
                                                    $event_label,
                                                    $event_name,
                                                ]));
                                                ?>
                                                <label
                                                    class="aipkit_settings_event_webhook_event_option"
                                                    for="<?php echo esc_attr($event_checkbox_id); ?>"
                                                    data-aipkit-event-webhook-event-option
                                                    data-search-text="<?php echo esc_attr($event_search_text); ?>"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        id="<?php echo esc_attr($event_checkbox_id); ?>"
                                                        name="event_webhooks[endpoints][<?php echo esc_attr($endpoint_index); ?>][events][<?php echo esc_attr($field_key); ?>]"
                                                        value="1"
                                                        data-aipkit-endpoint-field="event"
                                                        data-aipkit-event-field-key="<?php echo esc_attr($field_key); ?>"
                                                        <?php checked(in_array($event_name, $endpoint_events, true)); ?>
                                                    />
                                                    <span class="aipkit_settings_event_webhook_event_copy">
                                                        <span class="aipkit_settings_event_webhook_event_label"><?php echo esc_html($event_label); ?></span>
                                                        <code class="aipkit_settings_event_webhook_event_code"><?php echo esc_html($event_name); ?></code>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                                <p class="aipkit_settings_event_webhook_events_empty" data-aipkit-event-webhook-events-empty hidden>
                                    <?php esc_html_e('No events match your search.', 'gpt3-ai-content-generator'); ?>
                                </p>
                            </div>

                            <div class="aipkit_settings_event_webhook_events_modal_footer">
                                <div class="aipkit_settings_event_webhook_events_summary" aria-live="polite">
                                    <span data-aipkit-event-webhook-events-count><?php echo esc_html($endpoint_events_count_label); ?></span>
                                    <span class="aipkit_settings_event_webhook_events_saved" data-aipkit-event-webhook-events-saved hidden></span>
                                </div>
                                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_event_webhook_events_done_btn" data-aipkit-event-webhook-events-close>
                                    <?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="aipkit_settings_event_webhook_endpoint_footer">
            <button
                type="button"
                class="aipkit_btn aipkit_settings_event_webhook_delete_btn"
                data-aipkit-remove-event-webhook-endpoint
            >
                <?php esc_html_e('Delete endpoint', 'gpt3-ai-content-generator'); ?>
            </button>
        </div>
    </article>
    <?php
};

$render_event_webhook_issue = static function (array $issue = []): void {
    $job_uuid = sanitize_text_field((string) ($issue['job_uuid'] ?? ''));
    $event_name = sanitize_text_field((string) ($issue['event_name'] ?? ''));
    $target_summary = sanitize_text_field((string) ($issue['target_summary'] ?? __('Webhook endpoint', 'gpt3-ai-content-generator')));
    $error_message = sanitize_text_field((string) (($issue['error_message'] ?? '') ?: __('Webhook delivery failed.', 'gpt3-ai-content-generator')));
    $displayed_at = sanitize_text_field((string) ($issue['displayed_at'] ?? ''));
    ?>
    <article class="aipkit_settings_app_delivery_issue" data-aipkit-event-webhook-delivery-issue data-job-uuid="<?php echo esc_attr($job_uuid); ?>">
        <div class="aipkit_settings_app_delivery_issue_header">
            <div class="aipkit_settings_app_delivery_issue_heading">
                <strong><?php echo esc_html($target_summary); ?></strong>
                <span class="aipkit_settings_app_delivery_issue_meta">
                    <?php echo esc_html($event_name); ?>
                </span>
            </div>
            <span class="aipkit_settings_app_delivery_issue_status aipkit_settings_app_delivery_issue_status--failed">
                <?php esc_html_e('Failed', 'gpt3-ai-content-generator'); ?>
            </span>
        </div>
        <p class="aipkit_settings_app_delivery_issue_message"><?php echo esc_html($error_message); ?></p>
        <div class="aipkit_settings_app_delivery_issue_footer">
            <span class="aipkit_settings_app_delivery_issue_time"><?php echo esc_html($displayed_at); ?></span>
            <div class="aipkit_settings_app_delivery_issue_actions">
                <button type="button" class="button button-secondary aipkit_btn aipkit_btn-danger" data-aipkit-clear-event-webhook-delivery-issue data-job-uuid="<?php echo esc_attr($job_uuid); ?>">
                    <span class="aipkit_btn-text"><?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_spinner"></span>
                </button>
                <button type="button" class="button button-secondary aipkit_btn" data-aipkit-retry-event-webhook-delivery-issue data-job-uuid="<?php echo esc_attr($job_uuid); ?>">
                    <span class="aipkit_btn-text"><?php esc_html_e('Retry', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_spinner"></span>
                </button>
            </div>
        </div>
    </article>
    <?php
};
?>

<div
    id="aipkit_settings_event_webhooks_section"
    class="aipkit_settings_developer_credential"
    data-aipkit-developer-credential="webhook"
    data-enabled="<?php echo $event_webhooks_enabled === '1' ? 'true' : 'false'; ?>"
>
    <div class="aipkit_settings_developer_toggle_row" id="aipkit_settings_event_webhooks_enabled_row">
        <label class="aipkit_form-label" for="aipkit_event_webhooks_enabled">
            <?php esc_html_e('Event webhooks', 'gpt3-ai-content-generator'); ?>
            <span class="aipkit_form-label-helper"><?php esc_html_e('Send outbound events to external endpoints.', 'gpt3-ai-content-generator'); ?></span>
        </label>
        <label class="aipkit_switch" for="aipkit_event_webhooks_enabled">
            <input
                type="checkbox"
                id="aipkit_event_webhooks_enabled"
                name="event_webhooks[enabled]"
                value="1"
                data-aipkit-developer-enabled
                <?php checked($event_webhooks_enabled, '1'); ?>
            />
            <span class="aipkit_switch_slider"></span>
        </label>
    </div>

    <div class="aipkit_settings_developer_credential_body" id="aipkit_settings_event_webhooks_secret_row" data-aipkit-developer-dependent <?php if ($event_webhooks_enabled !== '1') : ?>hidden<?php endif; ?>>
        <label class="aipkit_settings_developer_field_label" for="aipkit_event_webhooks_signing_secret">
            <?php esc_html_e('Signing secret', 'gpt3-ai-content-generator'); ?>
        </label>
        <div class="aipkit_settings_developer_credential_row">
            <input
                type="text"
                id="aipkit_event_webhooks_signing_secret"
                class="aipkit_form-input aipkit_settings_developer_credential_input"
                value="<?php echo esc_attr($event_webhook_secret_mask); ?>"
                data-aipkit-developer-credential-input
                data-credential-mask="<?php echo esc_attr($event_webhook_secret_mask); ?>"
                data-has-credential="<?php echo $event_webhook_secret_mask !== '' ? 'true' : 'false'; ?>"
                readonly
                autocomplete="off"
                spellcheck="false"
            />
            <button type="button" class="button aipkit_btn aipkit_icon_btn aipkit_settings_developer_icon_btn" data-aipkit-developer-reveal data-aipkit-developer-reveal-label="<?php esc_attr_e('Reveal signing secret', 'gpt3-ai-content-generator'); ?>" data-aipkit-developer-hide-label="<?php esc_attr_e('Hide signing secret', 'gpt3-ai-content-generator'); ?>" aria-label="<?php esc_attr_e('Reveal signing secret', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Reveal signing secret', 'gpt3-ai-content-generator'); ?>">
                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
            </button>
            <button type="button" class="button aipkit_btn aipkit_icon_btn aipkit_settings_developer_icon_btn" data-aipkit-developer-copy aria-label="<?php esc_attr_e('Copy signing secret', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Copy signing secret', 'gpt3-ai-content-generator'); ?>">
                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
            </button>
            <button type="button" class="button aipkit_btn aipkit_icon_btn aipkit_settings_developer_icon_btn" data-aipkit-developer-regenerate aria-label="<?php esc_attr_e('Regenerate signing secret', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Regenerate signing secret', 'gpt3-ai-content-generator'); ?>">
                <span class="dashicons dashicons-update" aria-hidden="true"></span>
            </button>
        </div>
        <p class="aipkit_settings_developer_field_help"><?php esc_html_e('Used to verify that outgoing webhook requests came from AI Puffer.', 'gpt3-ai-content-generator'); ?></p>
    </div>

    <div class="aipkit_settings_developer_endpoints" id="aipkit_settings_event_webhooks_endpoints_row" data-aipkit-developer-dependent <?php if ($event_webhooks_enabled !== '1') : ?>hidden<?php endif; ?>>
        <div class="aipkit_settings_event_webhooks_main">
            <div class="aipkit_settings_event_webhooks_toolbar">
                <strong class="aipkit_settings_developer_endpoints_title"><?php esc_html_e('Endpoints', 'gpt3-ai-content-generator'); ?></strong>
                <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_settings_event_webhook_add_btn" id="aipkit_add_event_webhook_endpoint_btn">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('Add endpoint', 'gpt3-ai-content-generator'); ?>
                </button>
            </div>

            <div class="aipkit_settings_event_webhooks_endpoint_list<?php echo empty($event_webhook_endpoints) ? ' is-empty' : ''; ?>" id="aipkit_settings_event_webhooks_endpoint_list" data-aipkit-event-webhook-list>
                <?php if (!empty($event_webhook_endpoints)) : ?>
                    <?php foreach ($event_webhook_endpoints as $endpoint_index => $endpoint) : ?>
                        <?php $render_event_webhook_endpoint($endpoint_index, is_array($endpoint) ? $endpoint : []); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <template id="aipkit_event_webhook_endpoint_template">
        <?php $render_event_webhook_endpoint('__INDEX__'); ?>
    </template>

    <?php if (!empty($event_webhook_delivery_issues)) : ?>
        <div class="aipkit_settings_developer_delivery_issues" id="aipkit_settings_event_webhook_delivery_issues_row" data-aipkit-developer-dependent <?php if ($event_webhooks_enabled !== '1') : ?>hidden<?php endif; ?>>
            <div class="aipkit_form-label">
                <?php esc_html_e('Webhook delivery issues', 'gpt3-ai-content-generator'); ?>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Showing the 5 most recent failed webhook deliveries.', 'gpt3-ai-content-generator'); ?></span>
            </div>
            <div class="aipkit_settings_app_delivery_issues_main" id="aipkit_settings_event_webhook_delivery_issues_section">
                <div class="aipkit_settings_app_delivery_issue_list" data-aipkit-event-webhook-delivery-issue-list>
                    <?php foreach ($event_webhook_delivery_issues as $issue) : ?>
                        <?php $render_event_webhook_issue(is_array($issue) ? $issue : []); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
