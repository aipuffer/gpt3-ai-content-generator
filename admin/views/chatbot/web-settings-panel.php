<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
$supports_web_toggle_default = in_array($current_provider_for_this_bot, ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'xAI'], true);
$web_yes_no_options = ['1' => __('Yes', 'gpt3-ai-content-generator'), '0' => __('No', 'gpt3-ai-content-generator')];
$web_location_options = ['none' => __('Off', 'gpt3-ai-content-generator'), 'approximate' => __('Approximate', 'gpt3-ai-content-generator')];
$web_location_fields = [
    'country' => ['label' => __('Country', 'gpt3-ai-content-generator'), 'attributes' => ['placeholder' => __('US', 'gpt3-ai-content-generator'), 'maxlength' => 2]],
    'city' => ['label' => __('City', 'gpt3-ai-content-generator'), 'attributes' => ['placeholder' => __('London', 'gpt3-ai-content-generator')]],
    'region' => ['label' => __('Region', 'gpt3-ai-content-generator'), 'attributes' => ['placeholder' => __('California', 'gpt3-ai-content-generator')]],
    'timezone' => ['label' => __('Timezone', 'gpt3-ai-content-generator'), 'attributes' => ['placeholder' => __('America/Chicago', 'gpt3-ai-content-generator')]],
];
$web_location_providers = [
    'OpenAI' => [
        'slug' => 'openai', 'enabled' => $openai_web_search_enabled_val, 'location_type' => $openai_web_search_loc_type_val,
        'location' => ['country' => $openai_web_search_loc_country_val, 'city' => $openai_web_search_loc_city_val, 'region' => $openai_web_search_loc_region_val, 'timezone' => $openai_web_search_loc_timezone_val],
    ],
    'Claude' => [
        'slug' => 'claude', 'enabled' => $claude_web_search_enabled_val, 'location_type' => $claude_web_search_loc_type_val,
        'location' => ['country' => $claude_web_search_loc_country_val, 'city' => $claude_web_search_loc_city_val, 'region' => $claude_web_search_loc_region_val, 'timezone' => $claude_web_search_loc_timezone_val],
    ],
];

// The field definitions below own labels, limits and provider-specific selector hooks.
$render_web_field = static function (string $name, string $label, $value, array $field = []) use ($bot_id): void {
    $field_id = 'aipkit_bot_' . $bot_id . '_' . $name . '_modal';
    $row_class = 'aipkit_popover_option_row aipkit_web_settings_field';
    if (!empty($field['row_class'])) {
        $row_class .= ' ' . $field['row_class'];
    }
    ?>
    <div class="<?php echo esc_attr($row_class); ?>" <?php if (isset($field['style'])) : ?>style="<?php echo esc_attr($field['style']); ?>"<?php endif; ?>>
        <div class="aipkit_popover_option_main">
            <label class="aipkit_popover_option_label" for="<?php echo esc_attr($field_id); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <?php if (isset($field['options'])) : ?>
                <select
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    class="aipkit_popover_option_select<?php echo !empty($field['select_class']) ? ' ' . esc_attr($field['select_class']) : ''; ?>"
                >
                    <?php foreach ($field['options'] as $option_value => $option_label) : ?>
                        <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>><?php echo esc_html($option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input
                    type="<?php echo esc_attr($field['type'] ?? 'text'); ?>"
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    class="aipkit_popover_option_input aipkit_popover_option_input--framed"
                    value="<?php echo esc_attr($value); ?>"
                    <?php foreach ($field['attributes'] ?? [] as $attribute => $attribute_value) : ?>
                        <?php echo esc_attr($attribute); ?>="<?php echo esc_attr($attribute_value); ?>"
                    <?php endforeach; ?>
                />
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>

<div class="aipkit_popover_options_list aipkit_popover_options_list--web aipkit_web_settings_panel">
    <div class="aipkit_web_settings_group aipkit_web_settings_group--common">
        <div class="aipkit_web_settings_grid aipkit_web_settings_grid--two">
            <?php
            $render_web_field('web_toggle_default_on', __('Search by default', 'gpt3-ai-content-generator'), $web_toggle_default_on_val, [
                'options' => $web_yes_no_options, 'select_class' => 'aipkit_popover_option_select--compact aipkit_web_toggle_default_on',
                'row_class' => 'aipkit_web_toggle_default_row', 'style' => $supports_web_toggle_default ? '' : 'display:none;',
            ]);
            $render_web_field('show_sources', __('Show sources', 'gpt3-ai-content-generator'), $show_sources_val, [
                'options' => $web_yes_no_options, 'select_class' => 'aipkit_popover_option_select--compact aipkit_show_sources_toggle',
                'row_class' => 'aipkit_show_sources_row', 'style' => $current_provider_for_this_bot === 'Google' ? 'display:none;' : '',
            ]);
            $render_web_field('sources_label', __('Sources title', 'gpt3-ai-content-generator'), $sources_label_val, [
                'row_class' => 'aipkit_sources_label_row', 'attributes' => ['placeholder' => __('Sources', 'gpt3-ai-content-generator')],
            ]);
            $render_web_field('searching_web_text', __('Searching message', 'gpt3-ai-content-generator'), $searching_web_text_val, [
                'row_class' => 'aipkit_searching_web_text_row', 'attributes' => ['placeholder' => __('Searching web...', 'gpt3-ai-content-generator')],
            ]);
            ?>
        </div>
    </div>

    <?php foreach ($web_location_providers as $web_provider_name => $web_provider) :
        $web_prefix = $web_provider['slug'] . '_web_search_';
        $web_provider_active = $current_provider_for_this_bot === $web_provider_name;
        $web_provider_enabled = $web_provider_active && $web_provider['enabled'] === '1';
    ?>
    <div class="aipkit_popover_option_group aipkit_web_modal_section_<?php echo esc_attr($web_provider['slug']); ?>" style="<?php echo $web_provider_active ? '' : 'display:none;'; ?>">
        <div class="aipkit_<?php echo esc_attr($web_prefix); ?>conditional_settings aipkit_web_provider_settings" style="<?php echo $web_provider_enabled ? '' : 'display:none;'; ?>">
            <div class="aipkit_web_settings_grid aipkit_web_settings_grid--two">
                <?php
                if ($web_provider_name === 'OpenAI') {
                    $render_web_field($web_prefix . 'context_size', __('Search depth', 'gpt3-ai-content-generator'), $openai_web_search_context_size_val, [
                        'options' => ['low' => __('Light', 'gpt3-ai-content-generator'), 'medium' => __('Balanced', 'gpt3-ai-content-generator'), 'high' => __('Deep', 'gpt3-ai-content-generator')],
                    ]);
                } else {
                    $render_web_field($web_prefix . 'max_uses', __('Search limit', 'gpt3-ai-content-generator'), $claude_web_search_max_uses_val, [
                        'type' => 'number', 'attributes' => ['min' => 1, 'max' => 20, 'step' => 1],
                    ]);
                }
                $render_web_field($web_prefix . 'loc_type', __('Location', 'gpt3-ai-content-generator'), $web_provider['location_type'], [
                    'options' => $web_location_options, 'select_class' => 'aipkit_' . $web_prefix . 'loc_type_select',
                ]);
                ?>
            </div>
            <div class="aipkit_<?php echo esc_attr($web_prefix); ?>location_details aipkit_web_location_details" style="<?php echo ($web_provider_enabled && $web_provider['location_type'] === 'approximate') ? '' : 'display:none;'; ?>">
                <div class="aipkit_web_settings_grid aipkit_web_settings_grid--two">
                    <?php foreach ($web_location_fields as $location_key => $location_field) {
                        $render_web_field($web_prefix . 'loc_' . $location_key, $location_field['label'], $web_provider['location'][$location_key], $location_field);
                    } ?>
                </div>
            </div>
            <?php if ($web_provider_name === 'Claude') : ?>
                <div class="aipkit_web_settings_grid aipkit_web_settings_grid--two">
                    <?php
                    $render_web_field('claude_web_search_allowed_domains', __('Allowed sites', 'gpt3-ai-content-generator'), $claude_web_search_allowed_domains_val, [
                        'attributes' => ['placeholder' => __('example.com, docs.example.com', 'gpt3-ai-content-generator')],
                    ]);
                    $render_web_field('claude_web_search_blocked_domains', __('Blocked sites', 'gpt3-ai-content-generator'), $claude_web_search_blocked_domains_val, [
                        'attributes' => ['placeholder' => __('example.com, ads.example.org', 'gpt3-ai-content-generator')],
                    ]);
                    $render_web_field('claude_web_search_cache_ttl', __('Cache results', 'gpt3-ai-content-generator'), $claude_web_search_cache_ttl_val, [
                        'options' => ['none' => __('No', 'gpt3-ai-content-generator'), '5m' => __('5 minutes', 'gpt3-ai-content-generator'), '1h' => __('1 hour', 'gpt3-ai-content-generator')],
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="aipkit_popover_option_group aipkit_web_modal_section_openrouter" style="<?php echo ($current_provider_for_this_bot === 'OpenRouter') ? '' : 'display:none;'; ?>">
        <div class="aipkit_openrouter_web_search_conditional_settings aipkit_web_provider_settings" style="<?php echo ($current_provider_for_this_bot === 'OpenRouter' && $openrouter_web_search_enabled_val === '1') ? '' : 'display:none;'; ?>">
            <p class="aipkit_web_settings_note">
                <?php esc_html_e('Availability depends on the selected model.', 'gpt3-ai-content-generator'); ?>
            </p>
            <div class="aipkit_web_settings_grid aipkit_web_settings_grid--two">
                <?php
                $render_web_field('openrouter_web_search_engine', __('Search provider', 'gpt3-ai-content-generator'), $openrouter_web_search_engine_val, [
                    'options' => [
                        'auto' => __('Auto', 'gpt3-ai-content-generator'), 'native' => __('Native', 'gpt3-ai-content-generator'),
                        'exa' => __('Exa', 'gpt3-ai-content-generator'), 'firecrawl' => __('Firecrawl', 'gpt3-ai-content-generator'),
                        'parallel' => __('Parallel', 'gpt3-ai-content-generator'), 'perplexity' => __('Perplexity', 'gpt3-ai-content-generator'),
                    ],
                ]);
                $web_result_limits = [
                    'max_results' => ['label' => __('Results', 'gpt3-ai-content-generator'), 'value' => $openrouter_web_search_max_results_val, 'max' => 25],
                    'max_uses' => ['label' => __('Max searches', 'gpt3-ai-content-generator'), 'value' => $openrouter_web_search_max_uses_val, 'max' => 10],
                    'max_total_results' => ['label' => __('Total results', 'gpt3-ai-content-generator'), 'value' => $openrouter_web_search_max_total_results_val, 'max' => 100],
                ];
                foreach ($web_result_limits as $result_key => $result_field) {
                    $render_web_field('openrouter_web_search_' . $result_key, $result_field['label'], $result_field['value'], [
                        'type' => 'number', 'attributes' => ['min' => 1, 'max' => $result_field['max'], 'step' => 1],
                    ]);
                }
                $render_web_field('openrouter_web_search_context_size', __('Search depth', 'gpt3-ai-content-generator'), $openrouter_web_search_context_size_val, [
                    'options' => ['auto' => __('Auto', 'gpt3-ai-content-generator'), 'low' => __('Low', 'gpt3-ai-content-generator'), 'medium' => __('Medium', 'gpt3-ai-content-generator'), 'high' => __('High', 'gpt3-ai-content-generator')],
                ]);
                $render_web_field('openrouter_web_search_allowed_domains', __('Allowed domains', 'gpt3-ai-content-generator'), $openrouter_web_search_allowed_domains_val, [
                    'row_class' => 'aipkit_web_settings_field--wide', 'attributes' => ['placeholder' => __('example.com, docs.example.com', 'gpt3-ai-content-generator')],
                ]);
                $render_web_field('openrouter_web_search_excluded_domains', __('Excluded domains', 'gpt3-ai-content-generator'), $openrouter_web_search_excluded_domains_val, [
                    'row_class' => 'aipkit_web_settings_field--wide', 'attributes' => ['placeholder' => __('spam.example, lowquality.example', 'gpt3-ai-content-generator')],
                ]);
                ?>
            </div>
            <p class="aipkit_web_settings_note">
                <?php esc_html_e('Use comma-separated domains. If allowed domains are set, excluded domains are ignored.', 'gpt3-ai-content-generator'); ?>
            </p>
        </div>
    </div>
</div>
