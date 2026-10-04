<?php
/**
 * Chatbot visitor limits and limit-message actions.
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
        'title' => __('Primary button', 'gpt3-ai-content-generator'),
        'url_placeholder' => __('https://example.com/account', 'gpt3-ai-content-generator'),
    ],
    'secondary' => [
        'title' => __('Secondary button', 'gpt3-ai-content-generator'),
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

$limits_primary_grid_class = 'aipkit_limits_primary_grid' . (($limit_mode === 'role_based') ? ' aipkit_limits_primary_grid--role-based' : '');

$guest_limit_value = ($guest_limit === null) ? '' : (string) $guest_limit;
$user_limit_value = ($user_limit === null) ? '' : (string) $user_limit;

?>

<div class="aipkit_popover_options_list aipkit_popover_options_list--limits">
  <div class="<?php echo esc_attr($limits_primary_grid_class); ?>">
    <div class="aipkit_popover_option_row aipkit_limits_primary_cell aipkit_limits_primary_cell--type">
      <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
        <label
          class="aipkit_popover_option_label"
          for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_mode_panel"
        >
          <?php esc_html_e('Limit type', 'gpt3-ai-content-generator'); ?>
        </label>
        <select
          id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_mode_panel"
          name="token_limit_mode"
          class="aipkit_popover_option_select aipkit_token_limit_mode_select"
        >
          <option value="general" <?php selected($limit_mode, 'general'); ?>>
            <?php esc_html_e('Same limit for all users', 'gpt3-ai-content-generator'); ?>
          </option>
          <option value="role_based" <?php selected($limit_mode, 'role_based'); ?>>
            <?php esc_html_e('Different limits by role', 'gpt3-ai-content-generator'); ?>
          </option>
        </select>
      </div>
    </div>

    <div class="aipkit_popover_option_row aipkit_limits_primary_cell aipkit_limits_primary_cell--guest">
      <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
        <label
          class="aipkit_popover_option_label"
          for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_guest_limit_panel"
        >
          <?php esc_html_e('Guest limit', 'gpt3-ai-content-generator'); ?>
        </label>
        <input
          type="number"
          id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_guest_limit_panel"
          name="token_guest_limit"
          class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--compact"
          value="<?php echo esc_attr($guest_limit_value); ?>"
          min="0"
          step="1"
          placeholder="<?php esc_attr_e('(Unlimited)', 'gpt3-ai-content-generator'); ?>"
        />
      </div>
    </div>

    <div class="aipkit_popover_option_row aipkit_limits_primary_cell aipkit_limits_primary_cell--user aipkit_token_general_user_limit_field" style="display: <?php echo ($limit_mode === 'general') ? 'block' : 'none'; ?>;">
      <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
        <label
          class="aipkit_popover_option_label"
          for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_user_limit_panel"
        >
          <?php esc_html_e('User limit', 'gpt3-ai-content-generator'); ?>
        </label>
        <input
          type="number"
          id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_user_limit_panel"
          name="token_user_limit"
          class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--compact"
          value="<?php echo esc_attr($user_limit_value); ?>"
          min="0"
          step="1"
          placeholder="<?php esc_attr_e('(Unlimited)', 'gpt3-ai-content-generator'); ?>"
        />
      </div>
    </div>

    <div class="aipkit_popover_option_row aipkit_limits_primary_cell aipkit_limits_primary_cell--reset aipkit_token_reset_period_row">
      <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
        <label
          class="aipkit_popover_option_label"
          for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_reset_period_panel"
        >
          <?php esc_html_e('Reset period', 'gpt3-ai-content-generator'); ?>
        </label>
        <select
          id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_reset_period_panel"
          name="token_reset_period"
          class="aipkit_popover_option_select"
        >
          <option value="never" <?php selected($reset_period, 'never'); ?>>
            <?php esc_html_e('Never', 'gpt3-ai-content-generator'); ?>
          </option>
          <option value="daily" <?php selected($reset_period, 'daily'); ?>>
            <?php esc_html_e('Daily', 'gpt3-ai-content-generator'); ?>
          </option>
          <option value="weekly" <?php selected($reset_period, 'weekly'); ?>>
            <?php esc_html_e('Weekly', 'gpt3-ai-content-generator'); ?>
          </option>
          <option value="monthly" <?php selected($reset_period, 'monthly'); ?>>
            <?php esc_html_e('Monthly', 'gpt3-ai-content-generator'); ?>
          </option>
        </select>
      </div>
    </div>
  </div>

  <div class="aipkit_popover_option_row aipkit_token_role_limits_container aipkit_limits_role_row" style="display: <?php echo ($limit_mode === 'role_based') ? 'block' : 'none'; ?>;">
    <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
      <span class="aipkit_popover_option_label">
        <?php esc_html_e('Role limits', 'gpt3-ai-content-generator'); ?>
      </span>
      <div class="aipkit_popover_role_limits">
        <?php
        $editable_roles = get_editable_roles();
        foreach ($editable_roles as $role_slug => $role_info) :
            $role_name = translate_user_role($role_info['name']);
            $role_limit_value = isset($role_limits[$role_slug]) ? $role_limits[$role_slug] : '';
            ?>
          <div class="aipkit_popover_role_limit_row">
            <span class="aipkit_popover_role_limit_label"><?php echo esc_html($role_name); ?></span>
            <input
              type="number"
              id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_role_<?php echo esc_attr($role_slug); ?>_panel"
              name="token_role_limits[<?php echo esc_attr($role_slug); ?>]"
              class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--framed aipkit_popover_option_input--compact"
              value="<?php echo esc_attr($role_limit_value); ?>"
              min="0"
              step="1"
              placeholder="<?php esc_attr_e('(Unlimited)', 'gpt3-ai-content-generator'); ?>"
            />
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="aipkit_popover_option_row aipkit_limits_message_row">
    <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
      <label
        class="aipkit_popover_option_label"
        for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_message_panel"
      >
        <?php esc_html_e('Message', 'gpt3-ai-content-generator'); ?>
      </label>
      <input
        type="text"
        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_message_panel"
        name="token_limit_message"
        class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--wide"
        value="<?php echo esc_attr($limit_message); ?>"
        placeholder="<?php echo esc_attr($default_limit_message); ?>"
      />
    </div>
  </div>

  <div class="aipkit_limits_actions_stack">
    <?php foreach ($limit_action_slots as $limit_action_slot => $limit_action_config) :
        $limit_action_type = $bot_settings['token_limit_' . $limit_action_slot . '_action_type'] ?? $default_token_limit_actions[$limit_action_slot . '_type'];
        $limit_action_label = $bot_settings['token_limit_' . $limit_action_slot . '_action_label'] ?? $default_token_limit_actions[$limit_action_slot . '_label'];
        $limit_action_url = $bot_settings['token_limit_' . $limit_action_slot . '_action_url'] ?? $default_token_limit_actions[$limit_action_slot . '_url'];
        $limit_action_show_label = $limit_action_type !== 'none';
        $limit_action_show_url = $limit_action_type === 'custom_url';
        $limit_action_layout = $limit_action_show_url ? 'type-label-url' : ($limit_action_show_label ? 'type-label' : 'type-only');
        ?>
    <div
      class="aipkit_popover_option_row aipkit_limits_message_row aipkit_limits_action_row"
      data-aipkit-limit-action-row="<?php echo esc_attr($limit_action_slot); ?>"
      data-aipkit-limit-action-layout="<?php echo esc_attr($limit_action_layout); ?>"
    >
      <div class="aipkit_limits_action_field aipkit_limits_action_field--type" data-aipkit-limit-action-field="type">
        <label
          class="aipkit_popover_option_label"
          for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_type_panel"
        >
          <?php echo esc_html($limit_action_config['title']); ?>
        </label>
        <select
          id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_type_panel"
          name="token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_type"
          class="aipkit_popover_option_select"
        >
          <?php foreach ($token_limit_action_options as $action_value => $action_label) : ?>
            <option
              value="<?php echo esc_attr($action_value); ?>"
              data-default-label="<?php echo esc_attr(BotSettingsManager::get_token_limit_action_default_label($action_value)); ?>"
              <?php selected($limit_action_type, $action_value); ?>
            >
              <?php echo esc_html($action_label); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div
        class="aipkit_limits_action_field aipkit_limits_action_field--label"
        data-aipkit-limit-action-field="label"
        <?php if (!$limit_action_show_label) : ?>hidden<?php endif; ?>
      >
        <label
          class="aipkit_popover_option_label"
          for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_label_panel"
        >
          <?php esc_html_e('Button label', 'gpt3-ai-content-generator'); ?>
        </label>
        <input
          type="text"
          id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_label_panel"
          name="token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_label"
          class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--wide"
          value="<?php echo esc_attr($limit_action_label); ?>"
          placeholder="<?php echo esc_attr(BotSettingsManager::get_token_limit_action_default_label($limit_action_type)); ?>"
        />
      </div>
      <div
        class="aipkit_limits_action_field aipkit_limits_action_field--url"
        data-aipkit-limit-action-field="url"
        <?php if (!$limit_action_show_url) : ?>hidden<?php endif; ?>
      >
        <label
          class="aipkit_popover_option_label"
          for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_url_panel"
        >
          <?php esc_html_e('Custom URL', 'gpt3-ai-content-generator'); ?>
        </label>
        <input
          type="url"
          id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_url_panel"
          name="token_limit_<?php echo esc_attr($limit_action_slot); ?>_action_url"
          class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--wide"
          value="<?php echo esc_attr($limit_action_url); ?>"
          placeholder="<?php echo esc_attr($limit_action_config['url_placeholder']); ?>"
        />
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!wp_next_scheduled(CronHookConstant::CRON_HOOK)) : ?>
    <div class="aipkit_popover_option_row aipkit_popover_option_row--notice">
      <div class="aipkit_popover_option_main">
        <span class="aipkit_popover_option_notice">
          <?php esc_html_e('Warning: WP Cron task for resets is not scheduled!', 'gpt3-ai-content-generator'); ?>
        </span>
      </div>
    </div>
  <?php endif; ?>
</div>
