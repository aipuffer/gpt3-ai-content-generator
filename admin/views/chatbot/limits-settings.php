<?php
/**
 * Usage limits: guest and logged-in limits and when they start over as rows; limits by role and what
 * visitors see at the limit in side panels. The card around this file is the limits panel that saves them.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Core\TokenManager\Constants\CronHookConstant;

$bot_id = $initial_active_bot_id;
$bot_settings = $active_bot_settings;

$default_reset_period = BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
$default_limit_message = BotSettingsManager::get_default_token_limit_message();
$default_token_limit_actions = BotSettingsManager::get_default_token_limit_action_settings();
$guest_limit = $bot_settings['token_guest_limit'] ?? null;
$user_limit = $bot_settings['token_user_limit'] ?? null;
$reset_period = $bot_settings['token_reset_period'] ?? $default_reset_period;
$limit_message = $bot_settings['token_limit_message'] ?? $default_limit_message;
$limit_mode = $bot_settings['token_limit_mode'] ?? BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
$role_limits = $bot_settings['token_role_limits'] ?? [];
$limit_action_slots = [
    'primary' => [
        'title' => __('First button', 'gpt3-ai-content-generator'),
        'url_placeholder' => __('https://example.com/account', 'gpt3-ai-content-generator'),
    ],
    'secondary' => [
        'title' => __('Second button', 'gpt3-ai-content-generator'),
        'url_placeholder' => __('https://example.com/support', 'gpt3-ai-content-generator'),
    ],
];
$token_limit_action_options = [
    'none' => __('No button', 'gpt3-ai-content-generator'),
    'dashboard_usage' => __('Customer dashboard: Usage', 'gpt3-ai-content-generator'),
    'dashboard_credits' => __('Customer dashboard: Credits', 'gpt3-ai-content-generator'),
    'dashboard_purchases' => __('Customer dashboard: Purchases', 'gpt3-ai-content-generator'),
    'buy_credits' => __('Buy credits page', 'gpt3-ai-content-generator'),
    'custom_url' => __('Custom URL', 'gpt3-ai-content-generator'),
];

if (!in_array($limit_mode, ['general', 'role_based'], true)) {
    $limit_mode = BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
}

$guest_limit_value = ($guest_limit === null) ? '' : (string) $guest_limit;
$user_limit_value = ($user_limit === null) ? '' : (string) $user_limit;
$reset_period_options = [
    'never' => __('Never', 'gpt3-ai-content-generator'),
    'daily' => __('Daily', 'gpt3-ai-content-generator'),
    'weekly' => __('Weekly', 'gpt3-ai-content-generator'),
    'monthly' => __('Monthly', 'gpt3-ai-content-generator'),
];
$limit_mode_options = [
    'general' => __('Same for everyone', 'gpt3-ai-content-generator'),
    'role_based' => __('By role', 'gpt3-ai-content-generator'),
];
// A limit field: the number, empty for no limit.
$render_limit_amount = static function (string $field_id, string $name, string $value): void {
    ?>
    <input
        type="number"
        id="<?php echo esc_attr($field_id); ?>"
        name="<?php echo esc_attr($name); ?>"
        class="aipkit_look_input aipkit_look_input--amount"
        value="<?php echo esc_attr($value); ?>"
        min="0"
        step="1"
        placeholder="<?php esc_attr_e('Unlimited', 'gpt3-ai-content-generator'); ?>"
    />
    <?php
};
?>

<?php // The rows sit in one list; the side panels follow it. ?>
<div class="aipkit_card_rows">
<div class="aipkit_look_row">
    <span class="aipkit_feature_icon dashicons dashicons-admin-users" aria-hidden="true"></span>
    <span class="aipkit_look_row_copy">
        <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_guest_limit_panel"><?php esc_html_e('Guests', 'gpt3-ai-content-generator'); ?></label>
        <span class="aipkit_look_row_hint"><?php esc_html_e('Visitors who are not logged in.', 'gpt3-ai-content-generator'); ?></span>
    </span>
    <?php $render_limit_amount('aipkit_bot_' . $bot_id . '_token_guest_limit_panel', 'token_guest_limit', $guest_limit_value); ?>
</div>
<div class="aipkit_look_row aipkit_token_general_user_limit_field"<?php echo ($limit_mode === 'role_based') ? ' hidden' : ''; ?>>
    <span class="aipkit_feature_icon dashicons dashicons-groups" aria-hidden="true"></span>
    <span class="aipkit_look_row_copy">
        <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_user_limit_panel"><?php esc_html_e('Logged-in users', 'gpt3-ai-content-generator'); ?></label>
        <span class="aipkit_look_row_hint"><?php esc_html_e('The same limit for every role.', 'gpt3-ai-content-generator'); ?></span>
    </span>
    <?php $render_limit_amount('aipkit_bot_' . $bot_id . '_token_user_limit_panel', 'token_user_limit', $user_limit_value); ?>
</div>
<div class="aipkit_look_row">
    <span class="aipkit_feature_icon dashicons dashicons-update" aria-hidden="true"></span>
    <span class="aipkit_look_row_copy">
        <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_reset_period_panel"><?php esc_html_e('Starts over', 'gpt3-ai-content-generator'); ?></label>
        <span class="aipkit_look_row_hint"><?php esc_html_e('When used tokens go back to zero.', 'gpt3-ai-content-generator'); ?></span>
    </span>
    <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_reset_period_panel" name="token_reset_period" class="aipkit_look_select">
        <?php foreach ($reset_period_options as $reset_value => $reset_label) : ?>
            <option value="<?php echo esc_attr($reset_value); ?>" <?php selected($reset_period, $reset_value); ?>><?php echo esc_html($reset_label); ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php if (!wp_next_scheduled(CronHookConstant::CRON_HOOK)) : ?>
    <p class="aipkit_publish_tip aipkit_publish_tip--row">
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <span><?php esc_html_e('Limits cannot start over yet: WordPress has not scheduled the reset task.', 'gpt3-ai-content-generator'); ?></span>
    </p>
<?php endif; ?>
<?php
$render_feature_row([
    'key' => 'roles',
    'icon' => 'groups',
    'title' => __('Limits by role', 'gpt3-ai-content-generator'),
    'hint' => __('A different limit for each user role.', 'gpt3-ai-content-generator'),
    'state' => $limit_mode === 'role_based',
]);
$render_feature_row([
    'key' => 'limit',
    'icon' => 'dismiss',
    'title' => __('When someone reaches it', 'gpt3-ai-content-generator'),
    'hint' => __('What visitors see at their limit.', 'gpt3-ai-content-generator'),
]);
?>
</div>

<?php $render_drawer_start('roles', __('Limits by role', 'gpt3-ai-content-generator'), __('Give each role of logged-in users its own limit.', 'gpt3-ai-content-generator')); ?>
    <div class="aipkit_look_panel_rows">
        <div class="aipkit_look_panel_row">
            <span class="aipkit_look_row_copy">
                <span class="aipkit_look_row_title" id="aipkit_limits_mode_label"><?php esc_html_e('Logged-in users', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_look_row_hint"><?php esc_html_e('One limit, or one per role.', 'gpt3-ai-content-generator'); ?></span>
            </span>
            <span class="aipkit_look_row_control">
                <span class="aipkit_segmented" role="group" aria-labelledby="aipkit_limits_mode_label" data-aipkit-segmented-for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_mode_panel">
                    <?php foreach ($limit_mode_options as $mode_value => $mode_label) : ?>
                        <button type="button" class="aipkit_segmented_option" data-value="<?php echo esc_attr($mode_value); ?>" aria-pressed="<?php echo $limit_mode === $mode_value ? 'true' : 'false'; ?>"><?php echo esc_html($mode_label); ?></button>
                    <?php endforeach; ?>
                </span>
                <?php // The saved field; the row above shows On while it is By role. ?>
                <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_mode_panel" name="token_limit_mode" class="aipkit_token_limit_mode_select aipkit_look_saved_field" data-aipkit-feature-on-value="role_based" aria-hidden="true" tabindex="-1" hidden>
                    <?php foreach ($limit_mode_options as $mode_value => $mode_label) : ?>
                        <option value="<?php echo esc_attr($mode_value); ?>" <?php selected($limit_mode, $mode_value); ?>><?php echo esc_html($mode_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
        </div>
        <div class="aipkit_token_role_limits_container"<?php echo ($limit_mode === 'role_based') ? '' : ' hidden'; ?>>
            <?php
            foreach (get_editable_roles() as $role_slug => $role_info) :
                $role_field_id = 'aipkit_bot_' . $bot_id . '_token_role_' . $role_slug . '_panel';
                ?>
                <div class="aipkit_look_panel_row">
                    <label class="aipkit_look_row_title" for="<?php echo esc_attr($role_field_id); ?>"><?php echo esc_html(translate_user_role($role_info['name'])); ?></label>
                    <?php $render_limit_amount($role_field_id, 'token_role_limits[' . $role_slug . ']', (string) ($role_limits[$role_slug] ?? '')); ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php $render_drawer_end(); ?>

<?php $render_drawer_start('limit', __('When someone reaches it', 'gpt3-ai-content-generator'), __('What visitors see when they reach their limit.', 'gpt3-ai-content-generator')); ?>
    <div class="aipkit_look_panel_rows">
        <div class="aipkit_look_panel_row aipkit_look_panel_row--stacked">
            <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_message_panel"><?php esc_html_e('Message', 'gpt3-ai-content-generator'); ?></label>
            <input
                type="text"
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_message_panel"
                name="token_limit_message"
                class="aipkit_look_input"
                value="<?php echo esc_attr($limit_message); ?>"
                placeholder="<?php echo esc_attr($default_limit_message); ?>"
            />
        </div>
        <?php
        foreach ($limit_action_slots as $limit_action_slot => $limit_action_config) :
            $limit_action_type = $bot_settings['token_limit_' . $limit_action_slot . '_action_type'] ?? $default_token_limit_actions[$limit_action_slot . '_type'];
            $limit_action_label = $bot_settings['token_limit_' . $limit_action_slot . '_action_label'] ?? $default_token_limit_actions[$limit_action_slot . '_label'];
            $limit_action_url = $bot_settings['token_limit_' . $limit_action_slot . '_action_url'] ?? $default_token_limit_actions[$limit_action_slot . '_url'];
            $limit_action_show_label = $limit_action_type !== 'none';
            $limit_action_show_url = $limit_action_type === 'custom_url';
            $limit_action_layout = $limit_action_show_url ? 'type-label-url' : ($limit_action_show_label ? 'type-label' : 'type-only');
            $limit_action_field_id = 'aipkit_bot_' . $bot_id . '_token_limit_' . $limit_action_slot . '_action';
            ?>
            <?php // What the button opens sits beside its name; its label and link follow once there is a button. ?>
            <div
                class="aipkit_look_panel_row aipkit_publish_action"
                data-aipkit-limit-action-row="<?php echo esc_attr($limit_action_slot); ?>"
                data-aipkit-limit-action-layout="<?php echo esc_attr($limit_action_layout); ?>"
            >
                <span class="aipkit_look_row_copy">
                    <label class="aipkit_look_row_title" for="<?php echo esc_attr($limit_action_field_id); ?>_type_panel"><?php echo esc_html($limit_action_config['title']); ?></label>
                    <span class="aipkit_look_row_hint"><?php esc_html_e('Where it takes visitors.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_look_row_control">
                    <select id="<?php echo esc_attr($limit_action_field_id); ?>_type_panel" name="token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_type" class="aipkit_look_select">
                        <?php foreach ($token_limit_action_options as $action_value => $action_label) : ?>
                            <option
                                value="<?php echo esc_attr($action_value); ?>"
                                data-default-label="<?php echo esc_attr(BotSettingsManager::get_token_limit_action_default_label($action_value)); ?>"
                                <?php selected($limit_action_type, $action_value); ?>
                            ><?php echo esc_html($action_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <span class="aipkit_publish_action_fields">
                    <span class="aipkit_look_field" data-aipkit-limit-action-field="label"<?php echo $limit_action_show_label ? '' : ' hidden'; ?>>
                        <label class="aipkit_look_field_label" for="<?php echo esc_attr($limit_action_field_id); ?>_label_panel"><?php esc_html_e('Button label', 'gpt3-ai-content-generator'); ?></label>
                        <input
                            type="text"
                            id="<?php echo esc_attr($limit_action_field_id); ?>_label_panel"
                            name="token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_label"
                            class="aipkit_look_input"
                            value="<?php echo esc_attr($limit_action_label); ?>"
                            placeholder="<?php echo esc_attr(BotSettingsManager::get_token_limit_action_default_label($limit_action_type)); ?>"
                        />
                    </span>
                    <span class="aipkit_look_field" data-aipkit-limit-action-field="url"<?php echo $limit_action_show_url ? '' : ' hidden'; ?>>
                        <label class="aipkit_look_field_label" for="<?php echo esc_attr($limit_action_field_id); ?>_url_panel"><?php esc_html_e('Link', 'gpt3-ai-content-generator'); ?></label>
                        <input
                            type="url"
                            id="<?php echo esc_attr($limit_action_field_id); ?>_url_panel"
                            name="token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_url"
                            class="aipkit_look_input"
                            value="<?php echo esc_attr($limit_action_url); ?>"
                            placeholder="<?php echo esc_attr($limit_action_config['url_placeholder']); ?>"
                        />
                    </span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php $render_drawer_end(); ?>
