<?php

/**
 * Partial: AI Form Editor - Web Search Configuration
 * Contains provider-specific web search settings rendered in the modal.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This partial uses local renderer variables only.
$render_ai_form_web_search_location_fields = static function ($provider_key, $location_type_row_extra_class = '') {
    $field_prefix = 'aipkit_ai_form_' . $provider_key . '_web_search';
    $name_prefix = $provider_key . '_web_search';
    $location_type_row_class = trim('aipkit_ai_form_web_search_settings_row ' . $location_type_row_extra_class);
    $location_fields = [
        [
            'suffix' => 'country',
            'label' => __('Country', 'gpt3-ai-content-generator'),
            'helper' => __('Two-letter country code.', 'gpt3-ai-content-generator'),
            'placeholder' => __('US', 'gpt3-ai-content-generator'),
            'maxlength' => 2,
        ],
        [
            'suffix' => 'city',
            'label' => __('City', 'gpt3-ai-content-generator'),
            'helper' => __('City for approximate location.', 'gpt3-ai-content-generator'),
            'placeholder' => __('London', 'gpt3-ai-content-generator'),
        ],
        [
            'suffix' => 'region',
            'label' => __('Region', 'gpt3-ai-content-generator'),
            'helper' => __('State or region.', 'gpt3-ai-content-generator'),
            'placeholder' => __('California', 'gpt3-ai-content-generator'),
        ],
        [
            'suffix' => 'timezone',
            'label' => __('Timezone', 'gpt3-ai-content-generator'),
            'helper' => __('IANA timezone name.', 'gpt3-ai-content-generator'),
            'placeholder' => __('America/Chicago', 'gpt3-ai-content-generator'),
        ],
    ];
    ?>
        <div class="<?php echo esc_attr($location_type_row_class); ?>">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="<?php echo esc_attr($field_prefix . '_loc_type'); ?>">
                        <?php esc_html_e('User location', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Optional location signal.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select id="<?php echo esc_attr($field_prefix . '_loc_type'); ?>" name="<?php echo esc_attr($name_prefix . '_loc_type'); ?>" class="aipkit_form-input aipkit_ai_form_web_search_settings_control <?php echo esc_attr($field_prefix . '_loc_type_select'); ?>">
                    <option value="none" selected><?php esc_html_e('None', 'gpt3-ai-content-generator'); ?></option>
                    <option value="approximate"><?php esc_html_e('Approximate', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>
        <div class="<?php echo esc_attr($field_prefix . '_location_details'); ?>" style="display: none;">
            <?php foreach ($location_fields as $field): ?>
                <?php $field_id = $field_prefix . '_loc_' . $field['suffix']; ?>
                <div class="aipkit_ai_form_web_search_settings_row">
                    <div class="aipkit_ai_form_web_search_settings_row_main">
                        <div class="aipkit_ai_form_web_search_settings_copy">
                            <label class="aipkit_ai_form_web_search_settings_label" for="<?php echo esc_attr($field_id); ?>">
                                <?php echo esc_html($field['label']); ?>
                            </label>
                            <span class="aipkit_ai_form_web_search_settings_helper">
                                <?php echo esc_html($field['helper']); ?>
                            </span>
                        </div>
                        <input
                            type="text"
                            id="<?php echo esc_attr($field_id); ?>"
                            name="<?php echo esc_attr($name_prefix . '_loc_' . $field['suffix']); ?>"
                            class="aipkit_form-input aipkit_ai_form_web_search_settings_control"
                            placeholder="<?php echo esc_attr($field['placeholder']); ?>"
                            <?php if (isset($field['maxlength'])): ?>
                                maxlength="<?php echo esc_attr($field['maxlength']); ?>"
                            <?php endif; ?>
                        >
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php
};
?>
<div class="aipkit_ai_form_web_search_settings_list">
    <input
        type="checkbox"
        id="aipkit_ai_form_openai_web_search_enabled"
        name="openai_web_search_enabled"
        class="aipkit_ai_form_openai_web_search_toggle"
        value="1"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >

    <div class="aipkit_ai_form_openai_web_search_settings" style="display: none;">
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openai_web_search_context_size">
                        <?php esc_html_e('Search context', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Amount of web context.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select id="aipkit_ai_form_openai_web_search_context_size" name="openai_web_search_context_size" class="aipkit_form-input aipkit_ai_form_web_search_settings_control">
                    <option value="low"><?php esc_html_e('Low', 'gpt3-ai-content-generator'); ?></option>
                    <option value="medium" selected><?php esc_html_e('Medium', 'gpt3-ai-content-generator'); ?></option>
                    <option value="high"><?php esc_html_e('High', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>
        <?php $render_ai_form_web_search_location_fields('openai', 'aipkit_ai_form_openai_web_search_location_type_row aipkit_ai_form_web_search_settings_last_visible_row'); ?>
    </div>

    <input
        type="checkbox"
        id="aipkit_ai_form_claude_web_search_enabled"
        name="claude_web_search_enabled"
        class="aipkit_ai_form_claude_web_search_toggle"
        value="1"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >

    <div class="aipkit_ai_form_claude_web_search_settings" style="display: none;">
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_claude_web_search_max_uses">
                        <?php esc_html_e('Max uses', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Search calls allowed.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="number" id="aipkit_ai_form_claude_web_search_max_uses" name="claude_web_search_max_uses" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" min="1" max="20" step="1" value="5" inputmode="numeric">
            </div>
        </div>
        <?php $render_ai_form_web_search_location_fields('claude'); ?>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_claude_web_search_allowed_domains">
                        <?php esc_html_e('Allowed domains', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Limit search to these domains.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="text" id="aipkit_ai_form_claude_web_search_allowed_domains" name="claude_web_search_allowed_domains" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" placeholder="<?php esc_attr_e('example.com, docs.example.com', 'gpt3-ai-content-generator'); ?>">
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_claude_web_search_blocked_domains">
                        <?php esc_html_e('Blocked domains', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Exclude these domains.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="text" id="aipkit_ai_form_claude_web_search_blocked_domains" name="claude_web_search_blocked_domains" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" placeholder="<?php esc_attr_e('example.com, ads.example.org', 'gpt3-ai-content-generator'); ?>">
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_claude_web_search_cache_ttl">
                        <?php esc_html_e('Cache TTL', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Reuse search results briefly.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select id="aipkit_ai_form_claude_web_search_cache_ttl" name="claude_web_search_cache_ttl" class="aipkit_form-input aipkit_ai_form_web_search_settings_control">
                    <option value="none" selected><?php esc_html_e('None', 'gpt3-ai-content-generator'); ?></option>
                    <option value="5m"><?php esc_html_e('5 minutes', 'gpt3-ai-content-generator'); ?></option>
                    <option value="1h"><?php esc_html_e('1 hour', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>
    </div>

    <input
        type="checkbox"
        id="aipkit_ai_form_openrouter_web_search_enabled"
        name="openrouter_web_search_enabled"
        class="aipkit_ai_form_openrouter_web_search_toggle"
        value="1"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >

    <div class="aipkit_ai_form_openrouter_web_search_settings" style="display: none;">
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openrouter_web_search_engine">
                        <?php esc_html_e('Engine', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Search engine selection.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select id="aipkit_ai_form_openrouter_web_search_engine" name="openrouter_web_search_engine" class="aipkit_form-input aipkit_ai_form_web_search_settings_control">
                    <option value="auto" selected><?php esc_html_e('Auto', 'gpt3-ai-content-generator'); ?></option>
                    <option value="native"><?php esc_html_e('Native', 'gpt3-ai-content-generator'); ?></option>
                    <option value="exa"><?php esc_html_e('Exa', 'gpt3-ai-content-generator'); ?></option>
                    <option value="firecrawl"><?php esc_html_e('Firecrawl', 'gpt3-ai-content-generator'); ?></option>
                    <option value="parallel"><?php esc_html_e('Parallel', 'gpt3-ai-content-generator'); ?></option>
                    <option value="perplexity"><?php esc_html_e('Perplexity', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openrouter_web_search_max_results">
                        <?php esc_html_e('Max results', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Results to include.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="number" id="aipkit_ai_form_openrouter_web_search_max_results" name="openrouter_web_search_max_results" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" min="1" max="25" step="1" value="5" inputmode="numeric">
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openrouter_web_search_max_uses">
                        <?php esc_html_e('Max searches', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Maximum search calls per response.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="number" id="aipkit_ai_form_openrouter_web_search_max_uses" name="openrouter_web_search_max_uses" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" min="1" max="10" step="1" value="1" inputmode="numeric">
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openrouter_web_search_max_total_results">
                        <?php esc_html_e('Total results', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Maximum results across all searches.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="number" id="aipkit_ai_form_openrouter_web_search_max_total_results" name="openrouter_web_search_max_total_results" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" min="1" max="100" step="1" value="10" inputmode="numeric">
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openrouter_web_search_context_size">
                        <?php esc_html_e('Search depth', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Controls how much search context is returned.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select id="aipkit_ai_form_openrouter_web_search_context_size" name="openrouter_web_search_context_size" class="aipkit_form-input aipkit_ai_form_web_search_settings_control">
                    <option value="auto" selected><?php esc_html_e('Auto', 'gpt3-ai-content-generator'); ?></option>
                    <option value="low"><?php esc_html_e('Low', 'gpt3-ai-content-generator'); ?></option>
                    <option value="medium"><?php esc_html_e('Medium', 'gpt3-ai-content-generator'); ?></option>
                    <option value="high"><?php esc_html_e('High', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main aipkit_ai_form_web_search_settings_row_main--stacked">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openrouter_web_search_allowed_domains">
                        <?php esc_html_e('Allowed domains', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Optional comma-separated allowlist.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="text" id="aipkit_ai_form_openrouter_web_search_allowed_domains" name="openrouter_web_search_allowed_domains" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" placeholder="<?php esc_attr_e('example.com, docs.example.com', 'gpt3-ai-content-generator'); ?>">
            </div>
        </div>
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main aipkit_ai_form_web_search_settings_row_main--stacked">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <label class="aipkit_ai_form_web_search_settings_label" for="aipkit_ai_form_openrouter_web_search_excluded_domains">
                        <?php esc_html_e('Excluded domains', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Ignored when an allowlist is set.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input type="text" id="aipkit_ai_form_openrouter_web_search_excluded_domains" name="openrouter_web_search_excluded_domains" class="aipkit_form-input aipkit_ai_form_web_search_settings_control" placeholder="<?php esc_attr_e('spam.example, lowquality.example', 'gpt3-ai-content-generator'); ?>">
            </div>
        </div>
    </div>

    <input
        type="checkbox"
        id="aipkit_ai_form_xai_web_search_enabled"
        name="xai_web_search_enabled"
        class="aipkit_ai_form_xai_web_search_toggle"
        value="1"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >

    <div class="aipkit_ai_form_xai_web_search_settings" style="display: none;">
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main aipkit_ai_form_web_search_settings_row_main--stacked">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <span class="aipkit_ai_form_web_search_settings_label">
                        <?php esc_html_e('No additional options', 'gpt3-ai-content-generator'); ?>
                    </span>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('xAI web search does not have additional web settings or options.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <input
        type="checkbox"
        id="aipkit_ai_form_google_search_grounding_enabled"
        name="google_search_grounding_enabled"
        class="aipkit_ai_form_google_search_grounding_toggle"
        value="1"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >

    <div class="aipkit_ai_form_google_search_grounding_settings" style="display: none;">
        <div class="aipkit_ai_form_web_search_settings_row">
            <div class="aipkit_ai_form_web_search_settings_row_main aipkit_ai_form_web_search_settings_row_main--stacked">
                <div class="aipkit_ai_form_web_search_settings_copy">
                    <span class="aipkit_ai_form_web_search_settings_label">
                        <?php esc_html_e('No additional options', 'gpt3-ai-content-generator'); ?>
                    </span>
                    <span class="aipkit_ai_form_web_search_settings_helper">
                        <?php esc_html_e('Gemini decides when to search. Search sources are shown automatically.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="aipkit_ai_form_web_search_empty_state aipkit_form-help" style="display: none;">
        <?php esc_html_e('Web Search is not available for the selected provider or model.', 'gpt3-ai-content-generator'); ?>
    </div>
</div>
