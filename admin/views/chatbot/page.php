<?php
/**
 * AIPKit Chatbot Module - Admin View
 *
 * Layout-only rebuild based on the provided reference UI.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local view variables here do not create public globals.

use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Chat\Storage\DefaultBotSetup;
use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Chat\Utils\AIPKit_SVG_Icons;
use WPAICG\aipkit_dashboard; // Required for addon status checks
use WPAICG\AIPKit_Providers;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;

// Instantiate the storage classes
$bot_storage = new BotStorage();
$default_setup = new DefaultBotSetup();

DefaultBotSetup::ensure_default_chatbot();

// Fetch all bot settings up front so switching can be state-driven client-side.
$all_chatbots = [];
$all_chatbot_settings_by_id = [];
$all_chatbots_with_settings = $bot_storage->get_chatbots_with_settings();
if (!empty($all_chatbots_with_settings)) {
    foreach ($all_chatbots_with_settings as $bot_entry_with_settings) {
        $bot_post = $bot_entry_with_settings['post'] ?? null;
        if (!$bot_post instanceof \WP_Post) {
            continue;
        }

        $all_chatbots[] = $bot_post;
        $all_chatbot_settings_by_id[$bot_post->ID] = is_array($bot_entry_with_settings['settings'] ?? null)
            ? $bot_entry_with_settings['settings']
            : [];
    }
}

// These variables are defined by the AJAX loader and sanitized there.
$force_active_bot_id = isset($force_active_bot_id) ? intval($force_active_bot_id) : 0;
$force_active_tab = isset($force_active_tab) ? sanitize_key($force_active_tab) : '';

// Get the ID of the default bot
$default_bot_id = $default_setup->get_default_bot_id();

// Separate the default bot and sort the others alphabetically
$default_bot_post = null;
$other_bots_posts = [];
if (!empty($all_chatbots)) {
    foreach ($all_chatbots as $bot_post) {
        if ($bot_post->ID === $default_bot_id) {
            $default_bot_post = $bot_post;
        } else {
            $other_bots_posts[] = $bot_post;
        }
    }
    usort($other_bots_posts, function ($a, $b) {
        return strcmp($a->post_title, $b->post_title);
    });
}

// Combine all bots into one list for the dropdown
$all_bots_ordered_entries = [];
if ($default_bot_post) {
    $all_bots_ordered_entries[] = ['post' => $default_bot_post];
}
foreach ($other_bots_posts as $bot_post) {
    $all_bots_ordered_entries[] = ['post' => $bot_post];
}

// Determine the initial active bot
$initial_active_bot_id = 0;
if ($force_active_tab === 'create') {
    $initial_active_bot_id = 0;
} elseif ($force_active_bot_id > 0) {
    $initial_active_bot_id = $force_active_bot_id;
} elseif ($default_bot_post) {
    $initial_active_bot_id = $default_bot_post->ID;
} elseif (!empty($other_bots_posts)) {
    $initial_active_bot_id = $other_bots_posts[0]->ID;
}

// Find the active bot post
$active_bot_post = null;
if ($initial_active_bot_id) {
    foreach ($all_bots_ordered_entries as $bot_entry) {
        if ($bot_entry['post']->ID === $initial_active_bot_id) {
            $active_bot_post = $bot_entry['post'];
            break;
        }
    }
}

// If a forced/stored bot ID no longer exists, gracefully fall back.
if (!$active_bot_post) {
    if ($default_bot_post instanceof \WP_Post) {
        $active_bot_post = $default_bot_post;
        $initial_active_bot_id = (int) $default_bot_post->ID;
    } elseif (!empty($other_bots_posts) && $other_bots_posts[0] instanceof \WP_Post) {
        $active_bot_post = $other_bots_posts[0];
        $initial_active_bot_id = (int) $other_bots_posts[0]->ID;
    } else {
        $initial_active_bot_id = 0;
    }
}

// Always initialize a bot ID variable for downstream partials/panels.
$bot_id = (int) $initial_active_bot_id;
$is_pro_plan = class_exists('\\WPAICG\\aipkit_dashboard') && aipkit_dashboard::is_pro_plan();

$build_inline_bot_switch_state_payload = static function (
    int $bot_id,
    string $bot_name,
    array $settings,
    int $default_bot_id
) use ($is_pro_plan): array {
    $popup_enabled = (string) ($settings['popup_enabled'] ?? BotSettingsManager::DEFAULT_POPUP_ENABLED) === '1';
    $raw_deploy_mode = isset($settings['deploy_mode']) ? sanitize_key((string) $settings['deploy_mode']) : '';
    $deploy_mode = in_array($raw_deploy_mode, ['inline', 'popup', 'external'], true)
        ? $raw_deploy_mode
        : ($popup_enabled ? 'popup' : 'inline');
    if (!$is_pro_plan && $deploy_mode === 'external') {
        $deploy_mode = $popup_enabled ? 'popup' : 'inline';
    }

    $conversation_starters = $settings['conversation_starters'] ?? [];
    if (!is_array($conversation_starters)) {
        $conversation_starters = is_scalar($conversation_starters) && (string) $conversation_starters !== ''
            ? [(string) $conversation_starters]
            : [];
    }

    $triggers_json = $settings['triggers_json'] ?? '[]';
    if (is_array($triggers_json)) {
        $triggers_json = wp_json_encode($triggers_json) ?: '[]';
    } elseif (is_string($triggers_json)) {
        $triggers_json = trim($triggers_json) !== '' ? trim($triggers_json) : '[]';
    } else {
        $triggers_json = '[]';
    }
    $settings['triggers_json'] = $triggers_json;
    $settings['deploy_mode'] = $deploy_mode;
    if (!$is_pro_plan) {
        unset($settings['embed_allowed_domains']);
    }

    return [
        'bot_id' => $bot_id,
        'bot_name' => $bot_name,
        'is_default' => ($bot_id === $default_bot_id),
        'settings' => $settings,
        'deploy_mode' => $deploy_mode,
        'shortcode' => sprintf('[aipkit_chatbot id=%d]', $bot_id),
        'embed_code' => class_exists('\WPAICG\Lib\Chat\ShortcodeFeatures')
            ? \WPAICG\Lib\Chat\ShortcodeFeatures::embed_code($bot_id)
            : '',
        'embed_allowed_domains' => $is_pro_plan && isset($settings['embed_allowed_domains'])
            ? (string) $settings['embed_allowed_domains']
            : '',
        'conversation_starters_text' => implode("\n", array_map('strval', $conversation_starters)),
        'triggers_json' => $triggers_json,
        'connected_apps' => class_exists('\WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes')
            && method_exists('\WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes', 'get_chatbot_connected_apps_payload')
            ? \WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes::get_chatbot_connected_apps_payload($bot_id)
            : [
                'count' => 0,
                'summary' => '',
                'recipes' => [],
            ],
    ];
};

$inline_bot_switch_states = [];
$inline_bot_switch_order = [];
foreach ($all_bots_ordered_entries as $bot_entry_for_state) {
    $bot_post_for_state = $bot_entry_for_state['post'] ?? null;
    if (!$bot_post_for_state instanceof \WP_Post) {
        continue;
    }

    $bot_id_for_state = (int) $bot_post_for_state->ID;
    if ($bot_id_for_state <= 0) {
        continue;
    }

    $inline_bot_switch_order[] = $bot_id_for_state;
    $inline_bot_switch_states[(string) $bot_id_for_state] = $build_inline_bot_switch_state_payload(
        $bot_id_for_state,
        (string) $bot_post_for_state->post_title,
        $all_chatbot_settings_by_id[$bot_id_for_state] ?? [],
        (int) $default_bot_id
    );
}

$inline_bot_switch_payload = [
    'bots' => $inline_bot_switch_states,
    'order' => $inline_bot_switch_order,
    'default_bot_id' => (int) $default_bot_id,
];

$active_bot_settings = ($active_bot_post instanceof \WP_Post && isset($all_chatbot_settings_by_id[$active_bot_post->ID]))
    ? $all_chatbot_settings_by_id[$active_bot_post->ID]
    : [];
$active_bot_instructions = $active_bot_settings['instructions'] ?? '';
$saved_theme = $active_bot_settings['theme'] ?? BotSettingsManager::DEFAULT_THEME;
$saved_greeting = $active_bot_settings['greeting'] ?? '';
$saved_subgreeting = $active_bot_settings['subgreeting'] ?? '';
$aipkit_hide_custom_theme = false;
$available_themes = [
    'light'   => __('Light', 'gpt3-ai-content-generator'),
    'dark'    => __('Dark', 'gpt3-ai-content-generator'),
    'chatgpt' => __('ChatGPT', 'gpt3-ai-content-generator'),
];
if (!$aipkit_hide_custom_theme || $saved_theme === 'custom') {
    $available_themes['custom'] = __('Custom', 'gpt3-ai-content-generator');
}
$custom_theme_presets = class_exists(BotSettingsManager::class)
    ? BotSettingsManager::get_custom_theme_presets()
    : [];
$selected_theme_preset_key = '';
$selected_theme_preset_label = '';
if ($saved_theme === 'custom' && !empty($custom_theme_presets)) {
    $preset_label_map = [];
    $preset_color_map = [];
    foreach ($custom_theme_presets as $preset) {
        if (!is_array($preset)) {
            continue;
        }
        $preset_key = isset($preset['key']) ? sanitize_key((string) $preset['key']) : '';
        if ($preset_key === '') {
            continue;
        }
        $preset_label_map[$preset_key] = isset($preset['label']) ? (string) $preset['label'] : '';
        $preset_color_map[$preset_key] = [
            'primary' => isset($preset['primary']) ? strtolower(trim((string) $preset['primary'])) : '',
            'secondary' => isset($preset['secondary']) ? strtolower(trim((string) $preset['secondary'])) : '',
        ];
    }

    $stored_theme_preset_key = isset($active_bot_settings['theme_preset_key'])
        ? sanitize_key((string) $active_bot_settings['theme_preset_key'])
        : '';
    if ($stored_theme_preset_key !== '' && isset($preset_label_map[$stored_theme_preset_key])) {
        $selected_theme_preset_key = $stored_theme_preset_key;
        $selected_theme_preset_label = $preset_label_map[$stored_theme_preset_key];
    } else {
        // Backward compatibility for bots saved before explicit preset keys.
        $saved_custom_theme_settings = isset($active_bot_settings['custom_theme_settings']) && is_array($active_bot_settings['custom_theme_settings'])
            ? $active_bot_settings['custom_theme_settings']
            : [];
        $saved_custom_primary = isset($saved_custom_theme_settings['primary_color'])
            ? strtolower(trim((string) $saved_custom_theme_settings['primary_color']))
            : '';
        $saved_custom_secondary = isset($saved_custom_theme_settings['secondary_color'])
            ? strtolower(trim((string) $saved_custom_theme_settings['secondary_color']))
            : '';

        if ($saved_custom_primary !== '' && $saved_custom_secondary !== '') {
            foreach ($preset_color_map as $preset_key => $preset_colors) {
                if (
                    $preset_colors['primary'] !== '' &&
                    $preset_colors['secondary'] !== '' &&
                    $saved_custom_primary === $preset_colors['primary'] &&
                    $saved_custom_secondary === $preset_colors['secondary']
                ) {
                    $selected_theme_preset_key = $preset_key;
                    $selected_theme_preset_label = $preset_label_map[$preset_key] ?? '';
                    break;
                }
            }
        }
    }
}
$popup_enabled = $active_bot_settings['popup_enabled'] ?? BotSettingsManager::DEFAULT_POPUP_ENABLED;
$popup_enabled = in_array($popup_enabled, ['0', '1'], true) ? $popup_enabled : BotSettingsManager::DEFAULT_POPUP_ENABLED;
$site_wide_enabled = $active_bot_settings['site_wide_enabled'] ?? BotSettingsManager::DEFAULT_SITE_WIDE_ENABLED;
$site_wide_enabled = in_array($site_wide_enabled, ['0', '1'], true) ? $site_wide_enabled : BotSettingsManager::DEFAULT_SITE_WIDE_ENABLED;
$raw_deploy_mode = isset($active_bot_settings['deploy_mode'])
    ? sanitize_key((string) $active_bot_settings['deploy_mode'])
    : '';
$deploy_mode = in_array($raw_deploy_mode, ['inline', 'popup', 'external'], true)
    ? $raw_deploy_mode
    : (($popup_enabled === '1') ? 'popup' : 'inline');
$quick_popup_enabled = ($popup_enabled === '1' || $deploy_mode === 'popup' || $deploy_mode === 'external');
$quick_deploy_mode = ($deploy_mode === 'external') ? 'external' : ($quick_popup_enabled ? 'popup' : 'inline');
$quick_site_wide_enabled = ($quick_deploy_mode === 'popup' && $site_wide_enabled === '1');
$shortcode_text = $active_bot_post
    ? sprintf('[aipkit_chatbot id=%d]', absint($initial_active_bot_id))
    : '';
$embed_anywhere_active = $is_pro_plan;
$embed_allowed_domains = $is_pro_plan ? ($active_bot_settings['embed_allowed_domains'] ?? '') : '';
$embed_code = class_exists('\WPAICG\Lib\Chat\ShortcodeFeatures')
    ? \WPAICG\Lib\Chat\ShortcodeFeatures::embed_code(absint($initial_active_bot_id))
    : '';
$embed_docs_url = 'https://docs.aipower.org/chatbots#external-embed';
$consent_feature_available = $is_pro_plan && class_exists('\\WPAICG\\Lib\\Addons\\AIPKit_Consent_Compliance');
$triggers_available = $is_pro_plan;
$pricing_url = admin_url('admin.php?page=wpaicg-pricing');
$apps_logo_base_url = defined('WPAICG_PLUGIN_URL')
    ? WPAICG_PLUGIN_URL . 'admin/images/apps/'
    : '';
$connected_apps_supported_destinations = [
    [
        'name' => __('Slack', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'slack.svg',
    ],
    [
        'name' => __('HubSpot', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'hubspot.svg',
    ],
    [
        'name' => __('Notion', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'notion.svg',
    ],
    [
        'name' => __('Pipedrive', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'pipedrive.svg',
    ],
    [
        'name' => __('Zapier', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'zapier.svg',
    ],
    [
        'name' => __('Make', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'make.svg',
    ],
    [
        'name' => __('n8n', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'n8n.svg',
    ],
];
$connected_apps_store_class = '\WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes';
$active_chatbot_connected_apps = (
    $initial_active_bot_id > 0
    && class_exists($connected_apps_store_class)
    && method_exists($connected_apps_store_class, 'get_chatbot_connected_apps_payload')
)
    ? $connected_apps_store_class::get_chatbot_connected_apps_payload($initial_active_bot_id)
    : [
        'count' => 0,
        'summary' => '',
        'recipes' => [],
    ];
$connected_apps_summary_text = $is_pro_plan
    ? sanitize_text_field((string) ($active_chatbot_connected_apps['summary'] ?? ''))
    : '';
$post_types_args = ['public' => true];
$all_selectable_post_types = get_post_types($post_types_args, 'objects');
$all_selectable_post_types = array_filter($all_selectable_post_types, function ($post_type_obj) {
    return $post_type_obj->name !== 'attachment';
});
$popup_position = $active_bot_settings['popup_position'] ?? 'bottom-right';
$popup_position = in_array($popup_position, ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true)
    ? $popup_position
    : 'bottom-right';
$popup_delay = isset($active_bot_settings['popup_delay'])
    ? absint($active_bot_settings['popup_delay'])
    : BotSettingsManager::DEFAULT_POPUP_DELAY;
$popup_icon_type = $active_bot_settings['popup_icon_type'] ?? BotSettingsManager::DEFAULT_POPUP_ICON_TYPE;
$popup_icon_type = in_array($popup_icon_type, ['default', 'custom'], true)
    ? $popup_icon_type
    : BotSettingsManager::DEFAULT_POPUP_ICON_TYPE;
$popup_icon_style = $active_bot_settings['popup_icon_style'] ?? BotSettingsManager::DEFAULT_POPUP_ICON_STYLE;
$popup_icon_style = in_array($popup_icon_style, ['circle', 'square', 'none'], true)
    ? $popup_icon_style
    : BotSettingsManager::DEFAULT_POPUP_ICON_STYLE;
$popup_icon_value = $active_bot_settings['popup_icon_value'] ?? BotSettingsManager::DEFAULT_POPUP_ICON_VALUE;
$popup_icon_size = $active_bot_settings['popup_icon_size'] ?? BotSettingsManager::DEFAULT_POPUP_ICON_SIZE;
$allowed_icon_sizes = ['small', 'medium', 'large', 'xlarge'];
$popup_icon_size = in_array($popup_icon_size, $allowed_icon_sizes, true)
    ? $popup_icon_size
    : BotSettingsManager::DEFAULT_POPUP_ICON_SIZE;
$allowed_default_icons = ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'];
if ($popup_icon_type === 'default' && !in_array($popup_icon_value, $allowed_default_icons, true)) {
    $popup_icon_value = BotSettingsManager::DEFAULT_POPUP_ICON_VALUE;
}
$saved_header_avatar_url = $active_bot_settings['header_avatar_url'] ?? '';
$saved_header_avatar_type = $active_bot_settings['header_avatar_type'] ?? BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE;
if (!in_array($saved_header_avatar_type, ['inherit', 'default', 'custom'], true)) {
    $saved_header_avatar_type = $saved_header_avatar_url !== '' ? 'custom' : BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE;
}
$saved_header_avatar_value = $active_bot_settings['header_avatar_value'] ?? BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
if ($saved_header_avatar_type === 'custom') {
    if ($saved_header_avatar_url === '' && !empty($saved_header_avatar_value)) {
        $saved_header_avatar_url = $saved_header_avatar_value;
    }
} elseif ($saved_header_avatar_type === 'default') {
    if (!in_array($saved_header_avatar_value, $allowed_default_icons, true)) {
        $saved_header_avatar_value = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
    }
    $saved_header_avatar_url = '';
} else {
    $saved_header_avatar_value = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
    $saved_header_avatar_url = '';
}
$saved_header_online_text = $active_bot_settings['header_online_text'] ?? __('Online', 'gpt3-ai-content-generator');
$popup_label_enabled = $active_bot_settings['popup_label_enabled'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_ENABLED;
$popup_label_enabled = in_array($popup_label_enabled, ['0', '1'], true)
    ? $popup_label_enabled
    : BotSettingsManager::DEFAULT_POPUP_LABEL_ENABLED;
$popup_label_text = trim((string) ($active_bot_settings['popup_label_text'] ?? ''));
if ($popup_label_text === '') {
    $popup_label_text = BotSettingsManager::DEFAULT_POPUP_LABEL_TEXT;
}
$popup_label_mode = $active_bot_settings['popup_label_mode'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_MODE;
$popup_label_mode = in_array($popup_label_mode, ['on_delay', 'until_open', 'until_dismissed', 'always'], true)
    ? $popup_label_mode
    : BotSettingsManager::DEFAULT_POPUP_LABEL_MODE;
$popup_label_delay_seconds = isset($active_bot_settings['popup_label_delay_seconds'])
    ? absint($active_bot_settings['popup_label_delay_seconds'])
    : BotSettingsManager::DEFAULT_POPUP_LABEL_DELAY_SECONDS;
$popup_label_auto_hide_seconds = isset($active_bot_settings['popup_label_auto_hide_seconds'])
    ? absint($active_bot_settings['popup_label_auto_hide_seconds'])
    : BotSettingsManager::DEFAULT_POPUP_LABEL_AUTO_HIDE_SECONDS;
$popup_label_dismissible = $active_bot_settings['popup_label_dismissible'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_DISMISSIBLE;
$popup_label_dismissible = in_array($popup_label_dismissible, ['0', '1'], true)
    ? $popup_label_dismissible
    : BotSettingsManager::DEFAULT_POPUP_LABEL_DISMISSIBLE;
$popup_label_frequency = $active_bot_settings['popup_label_frequency'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_FREQUENCY;
$popup_label_frequency = in_array($popup_label_frequency, ['once_per_visitor', 'once_per_session', 'always'], true)
    ? $popup_label_frequency
    : BotSettingsManager::DEFAULT_POPUP_LABEL_FREQUENCY;
$popup_label_show_on_mobile = $active_bot_settings['popup_label_show_on_mobile'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_MOBILE;
$popup_label_show_on_mobile = in_array($popup_label_show_on_mobile, ['0', '1'], true)
    ? $popup_label_show_on_mobile
    : BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_MOBILE;
$popup_label_show_on_desktop = $active_bot_settings['popup_label_show_on_desktop'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_DESKTOP;
$popup_label_show_on_desktop = in_array($popup_label_show_on_desktop, ['0', '1'], true)
    ? $popup_label_show_on_desktop
    : BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_DESKTOP;
$popup_label_version = $active_bot_settings['popup_label_version'] ?? '';
$popup_label_size = $active_bot_settings['popup_label_size'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE;
$popup_label_size = in_array($popup_label_size, $allowed_icon_sizes, true)
    ? $popup_label_size
    : BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE;
$default_popup_icons = [];
if (class_exists(AIPKit_SVG_Icons::class)) {
    $default_popup_icons = [
        'chat-bubble' => AIPKit_SVG_Icons::get_chat_bubble_svg(),
        'spark' => AIPKit_SVG_Icons::get_spark_svg(),
        'openai' => AIPKit_SVG_Icons::get_openai_svg(),
        'plus' => AIPKit_SVG_Icons::get_plus_svg(),
        'question-mark' => AIPKit_SVG_Icons::get_question_mark_svg(),
    ];
}
$popup_icons = $default_popup_icons;
$quick_header_avatar_url = '';
$quick_header_avatar_icon_html = '';
$quick_header_avatar_initial = 'A';
if ($active_bot_post && isset($active_bot_post->post_title)) {
    $quick_header_avatar_title = trim(wp_strip_all_tags((string) $active_bot_post->post_title));
    if ($quick_header_avatar_title !== '') {
        $quick_header_avatar_initial = strtoupper(substr($quick_header_avatar_title, 0, 1));
    }
}
if ($saved_header_avatar_type === 'custom' && $saved_header_avatar_url !== '') {
    $quick_header_avatar_url = $saved_header_avatar_url;
} elseif ($saved_header_avatar_type === 'inherit' && $popup_icon_type === 'custom' && $popup_icon_value !== '') {
    $quick_header_avatar_url = $popup_icon_value;
} elseif ($saved_header_avatar_type === 'inherit' && isset($popup_icons[$popup_icon_value])) {
    $quick_header_avatar_icon_html = $popup_icons[$popup_icon_value];
} elseif (isset($popup_icons[$saved_header_avatar_value])) {
    $quick_header_avatar_icon_html = $popup_icons[$saved_header_avatar_value];
} elseif (isset($popup_icons[BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE])) {
    $quick_header_avatar_icon_html = $popup_icons[BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE];
}

// Web & Grounding settings values (used in model settings sheet).
$current_provider_for_this_bot = $active_bot_settings['provider'] ?? 'OpenAI';
$openai_web_search_enabled_val = $active_bot_settings['openai_web_search_enabled']
    ?? BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_ENABLED;
$openai_web_search_context_size_val = $active_bot_settings['openai_web_search_context_size']
    ?? BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE;
$openai_web_search_loc_type_val = $active_bot_settings['openai_web_search_loc_type']
    ?? BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE;
$openai_web_search_loc_country_val = $active_bot_settings['openai_web_search_loc_country'] ?? '';
$openai_web_search_loc_city_val = $active_bot_settings['openai_web_search_loc_city'] ?? '';
$openai_web_search_loc_region_val = $active_bot_settings['openai_web_search_loc_region'] ?? '';
$openai_web_search_loc_timezone_val = $active_bot_settings['openai_web_search_loc_timezone'] ?? '';
$claude_web_search_enabled_val = $active_bot_settings['claude_web_search_enabled']
    ?? BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_ENABLED;
$claude_web_search_max_uses_val = isset($active_bot_settings['claude_web_search_max_uses'])
    ? absint($active_bot_settings['claude_web_search_max_uses'])
    : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES;
$claude_web_search_max_uses_val = max(1, min($claude_web_search_max_uses_val, 20));
$claude_web_search_loc_type_val = $active_bot_settings['claude_web_search_loc_type']
    ?? BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE;
$claude_web_search_loc_country_val = $active_bot_settings['claude_web_search_loc_country'] ?? '';
$claude_web_search_loc_city_val = $active_bot_settings['claude_web_search_loc_city'] ?? '';
$claude_web_search_loc_region_val = $active_bot_settings['claude_web_search_loc_region'] ?? '';
$claude_web_search_loc_timezone_val = $active_bot_settings['claude_web_search_loc_timezone'] ?? '';
$claude_web_search_allowed_domains_val = $active_bot_settings['claude_web_search_allowed_domains'] ?? '';
$claude_web_search_blocked_domains_val = $active_bot_settings['claude_web_search_blocked_domains'] ?? '';
$claude_web_search_cache_ttl_val = $active_bot_settings['claude_web_search_cache_ttl']
    ?? BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL;
$openrouter_web_search_enabled_val = $active_bot_settings['openrouter_web_search_enabled']
    ?? BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED;
$openrouter_web_search_engine_val = $active_bot_settings['openrouter_web_search_engine']
    ?? BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;
if (!in_array($openrouter_web_search_engine_val, ['auto', 'native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)) {
    $openrouter_web_search_engine_val = BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;
}
$openrouter_web_search_max_results_val = isset($active_bot_settings['openrouter_web_search_max_results'])
    ? absint($active_bot_settings['openrouter_web_search_max_results'])
    : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS;
$openrouter_web_search_max_results_val = max(1, min($openrouter_web_search_max_results_val, 25));
$openrouter_web_search_max_uses_val = isset($active_bot_settings['openrouter_web_search_max_uses'])
    ? absint($active_bot_settings['openrouter_web_search_max_uses'])
    : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES;
$openrouter_web_search_max_uses_val = max(1, min($openrouter_web_search_max_uses_val, 10));
$openrouter_web_search_max_total_results_val = isset($active_bot_settings['openrouter_web_search_max_total_results'])
    ? absint($active_bot_settings['openrouter_web_search_max_total_results'])
    : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS;
$openrouter_web_search_max_total_results_val = max(1, min($openrouter_web_search_max_total_results_val, 100));
$openrouter_web_search_context_size_val = $active_bot_settings['openrouter_web_search_context_size']
    ?? BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
if (!in_array($openrouter_web_search_context_size_val, ['auto', 'low', 'medium', 'high'], true)) {
    $openrouter_web_search_context_size_val = BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
}
$openrouter_web_search_allowed_domains_val = $active_bot_settings['openrouter_web_search_allowed_domains']
    ?? BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ALLOWED_DOMAINS;
$openrouter_web_search_excluded_domains_val = $active_bot_settings['openrouter_web_search_excluded_domains']
    ?? BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_EXCLUDED_DOMAINS;
$xai_web_search_enabled_val = $active_bot_settings['xai_web_search_enabled']
    ?? BotSettingsManager::DEFAULT_XAI_WEB_SEARCH_ENABLED;
$web_toggle_default_on_val = $active_bot_settings['web_toggle_default_on']
    ?? BotSettingsManager::DEFAULT_WEB_TOGGLE_DEFAULT_ON;
$show_sources_val = $active_bot_settings['show_sources']
    ?? BotSettingsManager::DEFAULT_SHOW_SOURCES;
$show_sources_val = in_array($show_sources_val, ['0', '1'], true)
    ? $show_sources_val
    : BotSettingsManager::DEFAULT_SHOW_SOURCES;
$sources_label_val = isset($active_bot_settings['sources_label'])
    ? sanitize_text_field((string) $active_bot_settings['sources_label'])
    : BotSettingsManager::DEFAULT_SOURCES_LABEL;
$searching_web_text_val = isset($active_bot_settings['searching_web_text'])
    ? sanitize_text_field((string) $active_bot_settings['searching_web_text'])
    : BotSettingsManager::DEFAULT_SEARCHING_WEB_TEXT;
$google_search_grounding_enabled_val = $active_bot_settings['google_search_grounding_enabled']
    ?? BotSettingsManager::DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED;

// Conversations settings values (used in model settings sheet).
$openai_conversation_state_enabled_val = $active_bot_settings['openai_conversation_state_enabled']
    ?? BotSettingsManager::DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED;
$openai_conversation_state_enabled_val = in_array($openai_conversation_state_enabled_val, ['0', '1'], true)
    ? $openai_conversation_state_enabled_val
    : BotSettingsManager::DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED;
$google_conversation_state_enabled_val = $active_bot_settings['google_conversation_state_enabled']
    ?? BotSettingsManager::DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED;
$google_conversation_state_enabled_val = in_array($google_conversation_state_enabled_val, ['0', '1'], true)
    ? $google_conversation_state_enabled_val
    : BotSettingsManager::DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED;
$openrouter_session_stickiness_val = $active_bot_settings['openrouter_session_stickiness']
    ?? BotSettingsManager::DEFAULT_OPENROUTER_SESSION_STICKINESS;
$openrouter_session_stickiness_val = in_array($openrouter_session_stickiness_val, ['0', '1'], true)
    ? $openrouter_session_stickiness_val
    : BotSettingsManager::DEFAULT_OPENROUTER_SESSION_STICKINESS;
$saved_max_messages = isset($active_bot_settings['max_messages'])
    ? absint($active_bot_settings['max_messages'])
    : BotSettingsManager::DEFAULT_MAX_MESSAGES;
$saved_max_messages = max(1, min($saved_max_messages, 1024));
$enable_image_upload = $active_bot_settings['enable_image_upload']
    ?? BotSettingsManager::DEFAULT_ENABLE_IMAGE_UPLOAD;
$enable_image_upload = in_array($enable_image_upload, ['0', '1'], true)
    ? $enable_image_upload
    : BotSettingsManager::DEFAULT_ENABLE_IMAGE_UPLOAD;
$enable_vector_store = $active_bot_settings['enable_vector_store']
    ?? BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE;
$enable_vector_store = in_array($enable_vector_store, ['0', '1'], true)
    ? $enable_vector_store
    : BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE;
$enable_file_upload = $active_bot_settings['enable_file_upload']
    ?? BotSettingsManager::DEFAULT_ENABLE_FILE_UPLOAD;
$enable_file_upload = in_array($enable_file_upload, ['0', '1'], true)
    ? $enable_file_upload
    : BotSettingsManager::DEFAULT_ENABLE_FILE_UPLOAD;
$content_aware_enabled = $active_bot_settings['content_aware_enabled']
    ?? BotSettingsManager::DEFAULT_CONTENT_AWARE_ENABLED;
$content_aware_enabled = in_array($content_aware_enabled, ['0', '1'], true)
    ? $content_aware_enabled
    : BotSettingsManager::DEFAULT_CONTENT_AWARE_ENABLED;
$vector_store_provider = $active_bot_settings['vector_store_provider']
    ?? BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
$allowed_vector_store_providers = ['local', 'openai', 'pinecone', 'qdrant', 'chroma', 'claude_files', 'google'];
if (!in_array($vector_store_provider, $allowed_vector_store_providers, true)) {
    $vector_store_provider = BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
}
$google_file_search_store_names_saved = [];
if (!empty($active_bot_settings['google_file_search_store_names'])) {
    $google_file_search_store_names_saved = is_array($active_bot_settings['google_file_search_store_names'])
        ? $active_bot_settings['google_file_search_store_names']
        : (json_decode((string) $active_bot_settings['google_file_search_store_names'], true) ?: []);
}
$openai_vector_store_ids_saved = [];
if (isset($active_bot_settings['openai_vector_store_ids'])) {
    if (is_array($active_bot_settings['openai_vector_store_ids'])) {
        $openai_vector_store_ids_saved = $active_bot_settings['openai_vector_store_ids'];
    } elseif (is_string($active_bot_settings['openai_vector_store_ids'])) {
        $decoded_ids = json_decode($active_bot_settings['openai_vector_store_ids'], true);
        if (is_array($decoded_ids)) {
            $openai_vector_store_ids_saved = $decoded_ids;
        }
    }
}
$pinecone_index_name = $active_bot_settings['pinecone_index_name'] ?? BotSettingsManager::DEFAULT_PINECONE_INDEX_NAME;
$vector_embedding_provider = $active_bot_settings['vector_embedding_provider'] ?? BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER;
$default_embedding_provider_map = AIPKit_Providers::get_default_embedding_provider_map();
$embedding_provider_options = AIPKit_Providers::get_embedding_provider_map('chatbot_ui');
$allowed_embedding_providers = array_values(array_unique(array_map('sanitize_key', array_keys($embedding_provider_options))));
if (empty($allowed_embedding_providers)) {
    $allowed_embedding_providers = array_keys($default_embedding_provider_map);
}
$default_embedding_provider_key = isset($embedding_provider_options[BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER])
    ? BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER
    : (array_key_first($embedding_provider_options) ?: BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER);
if ($vector_embedding_provider !== '' && $vector_embedding_provider !== 'aipuffercloud' && !in_array($vector_embedding_provider, $allowed_embedding_providers, true)) {
    $vector_embedding_provider = $default_embedding_provider_key;
}
$vector_embedding_model = $active_bot_settings['vector_embedding_model'] ?? BotSettingsManager::get_default_model_id('OpenAIEmbedding');
$qdrant_collection_names = [];
if (!empty($active_bot_settings['qdrant_collection_names']) && is_array($active_bot_settings['qdrant_collection_names'])) {
    $qdrant_collection_names = $active_bot_settings['qdrant_collection_names'];
} elseif (!empty($active_bot_settings['qdrant_collection_name'])) {
    $qdrant_collection_names = [$active_bot_settings['qdrant_collection_name']];
}
$chroma_collection_names = [];
if (!empty($active_bot_settings['chroma_collection_names']) && is_array($active_bot_settings['chroma_collection_names'])) {
    $chroma_collection_names = $active_bot_settings['chroma_collection_names'];
} elseif (!empty($active_bot_settings['chroma_collection_name'])) {
    $chroma_collection_names = [$active_bot_settings['chroma_collection_name']];
}
$vector_store_top_k = isset($active_bot_settings['vector_store_top_k'])
    ? absint($active_bot_settings['vector_store_top_k'])
    : BotSettingsManager::DEFAULT_VECTOR_STORE_TOP_K;
$vector_store_top_k = max(1, min($vector_store_top_k, 20));
$vector_store_confidence_threshold = $active_bot_settings['vector_store_confidence_threshold']
    ?? BotSettingsManager::DEFAULT_VECTOR_STORE_CONFIDENCE_THRESHOLD;
$vector_store_confidence_threshold = max(0, min(absint($vector_store_confidence_threshold), 100));
$openai_vector_stores = [];
$google_file_search_stores = [];
$pinecone_indexes = [];
$qdrant_collections = [];
$chroma_collections = [];
// Built-in knowledge bases (shown to users as "AI Puffer") and the ones this bot uses.
$local_stores = [];
if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class) && file_exists(WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php')) {
    require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/provider-contracts.php';
    require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
}
if (class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class)) {
    $local_stores = (new \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy())->list_indexes();
}
$local_store_ids = is_array($active_bot_settings['local_store_ids'] ?? null) ? $active_bot_settings['local_store_ids'] : [];
$embedding_models_by_provider = [];
$openai_provider_data = [];
$pinecone_provider_data = [];
$qdrant_provider_data = [];
$chroma_provider_data = [];
$google_provider_data = [];
$azure_provider_data = [];
$claude_provider_data = [];
$xai_provider_data = [];
if (class_exists(AIPKit_Vector_Store_Registry::class)) {
    $openai_vector_stores = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('OpenAI');
    $google_file_search_stores = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('Google');
}
if (class_exists(AIPKit_Providers::class)) {
    $pinecone_indexes = AIPKit_Providers::get_pinecone_indexes();
    $qdrant_collections = AIPKit_Providers::get_qdrant_collections();
    $chroma_collections = AIPKit_Providers::get_chroma_collections();
    $embedding_models_by_provider = AIPKit_Providers::get_embedding_models_by_provider('chatbot_ui');
    $openai_provider_data = AIPKit_Providers::get_provider_data('OpenAI');
    $pinecone_provider_data = AIPKit_Providers::get_provider_data('Pinecone');
    $qdrant_provider_data = AIPKit_Providers::get_provider_data('Qdrant');
    $chroma_provider_data = AIPKit_Providers::get_provider_data('Chroma');
    $google_provider_data = AIPKit_Providers::get_provider_data('Google');
    $azure_provider_data = AIPKit_Providers::get_provider_data('Azure');
    $claude_provider_data = AIPKit_Providers::get_provider_data('Claude');
    $xai_provider_data = AIPKit_Providers::get_provider_data('xAI');
}
$openai_api_key = $openai_provider_data['api_key'] ?? '';
$pinecone_api_key = $pinecone_provider_data['api_key'] ?? '';
$qdrant_url = $qdrant_provider_data['url'] ?? '';
$qdrant_api_key = $qdrant_provider_data['api_key'] ?? '';
$chroma_url = $chroma_provider_data['url'] ?? '';
$google_api_key = $google_provider_data['api_key'] ?? '';
$azure_api_key = $azure_provider_data['api_key'] ?? '';
$claude_api_key = $claude_provider_data['api_key'] ?? '';
$xai_api_key = $xai_provider_data['api_key'] ?? '';
$image_triggers = $active_bot_settings['image_triggers']
    ?? BotSettingsManager::DEFAULT_IMAGE_TRIGGERS;
$chat_image_model_id = $active_bot_settings['chat_image_model_id']
    ?? BotSettingsManager::get_default_model_id('OpenAIImage');
$enable_image_generation = $active_bot_settings['enable_image_generation']
    ?? BotSettingsManager::DEFAULT_ENABLE_IMAGE_GENERATION;
$enable_image_generation = in_array($enable_image_generation, ['0', '1'], true)
    ? $enable_image_generation
    : BotSettingsManager::DEFAULT_ENABLE_IMAGE_GENERATION;
$replicate_model_list = AIPKit_Providers::get_replicate_models();
$openrouter_image_model_list = AIPKit_Providers::get_openrouter_image_models();
$xai_image_model_list = AIPKit_Providers::get_xai_image_models();
$available_image_models = [
    'OpenAI' => AIPKit_Providers::get_openai_image_models(),
    'Azure' => AIPKit_Providers::get_azure_image_models(),
    'Google' => AIPKit_Providers::get_google_image_models(),
];
if (class_exists('\\WPAICG\\Cloud\\Connection')) {
    $cloud_image_models = \WPAICG\Cloud\Connection::media_models('image_generate');
    if ($cloud_image_models) { $available_image_models['AIPufferCloud'] = $cloud_image_models; }
}
if (isset($openrouter_image_model_list) && is_array($openrouter_image_model_list) && !empty($openrouter_image_model_list)) {
    $available_image_models['OpenRouter'] = $openrouter_image_model_list;
}
if (isset($xai_image_model_list) && is_array($xai_image_model_list) && !empty($xai_image_model_list)) {
    $available_image_models['xAI'] = $xai_image_model_list;
}
if (isset($replicate_model_list) && is_array($replicate_model_list) && !empty($replicate_model_list)) {
    $available_image_models['Replicate'] = $replicate_model_list;
}
// Audio settings values (used in audio settings panel).
$enable_voice_input = $active_bot_settings['enable_voice_input']
    ?? BotSettingsManager::DEFAULT_ENABLE_VOICE_INPUT;
$enable_voice_input = in_array($enable_voice_input, ['0', '1'], true)
    ? $enable_voice_input
    : BotSettingsManager::DEFAULT_ENABLE_VOICE_INPUT;
$stt_provider = $active_bot_settings['stt_provider']
    ?? BotSettingsManager::DEFAULT_STT_PROVIDER;
$allowed_stt_providers = ['', 'OpenAI', 'Google', 'Azure', 'AIPufferCloud'];
if (!in_array($stt_provider, $allowed_stt_providers, true)) {
    $stt_provider = BotSettingsManager::DEFAULT_STT_PROVIDER;
}
$stt_openai_model_id = $active_bot_settings['stt_openai_model_id']
    ?? BotSettingsManager::get_default_model_id('OpenAISTT');
$openai_stt_models = AIPKit_Providers::get_openai_stt_models();
$stt_google_model_id = $active_bot_settings['stt_google_model_id']
    ?? AIPKit_Providers::normalize_google_stt_model('');
$stt_cloud_model_id = $active_bot_settings['stt_cloud_model_id'] ?? (\WPAICG\Cloud\Connection::media_models('transcribe')[0]['id'] ?? '');
$google_stt_models = AIPKit_Providers::get_google_stt_models();

$tts_enabled = $active_bot_settings['tts_enabled']
    ?? BotSettingsManager::DEFAULT_TTS_ENABLED;
$tts_enabled = in_array($tts_enabled, ['0', '1'], true)
    ? $tts_enabled
    : BotSettingsManager::DEFAULT_TTS_ENABLED;
$tts_provider = $active_bot_settings['tts_provider']
    ?? BotSettingsManager::DEFAULT_TTS_PROVIDER;
$tts_providers = ['Google', 'OpenAI', 'ElevenLabs', 'AIPufferCloud'];
if ($tts_provider !== '' && !in_array($tts_provider, $tts_providers, true)) {
    $tts_provider = BotSettingsManager::DEFAULT_TTS_PROVIDER;
}
$tts_google_voice_id = AIPKit_Providers::normalize_google_tts_voice($active_bot_settings['tts_google_voice_id'] ?? '');
$tts_google_model_id = AIPKit_Providers::normalize_google_tts_model($active_bot_settings['tts_google_model_id'] ?? '');
$tts_openai_voice_id = $active_bot_settings['tts_openai_voice_id'] ?? BotSettingsManager::get_default_model_id('OpenAIVoices');
$tts_openai_model_id = $active_bot_settings['tts_openai_model_id']
    ?? BotSettingsManager::get_default_model_id('OpenAITTS');
$tts_cloud_voice_id = $active_bot_settings['tts_cloud_voice_id'] ?? 'alloy';
$tts_cloud_model_id = $active_bot_settings['tts_cloud_model_id'] ?? (\WPAICG\Cloud\Connection::media_models('speech_generate')[0]['id'] ?? '');
$tts_elevenlabs_voice_id = $active_bot_settings['tts_elevenlabs_voice_id'] ?? '';
$tts_elevenlabs_model_id = $active_bot_settings['tts_elevenlabs_model_id']
    ?? BotSettingsManager::get_default_model_id('ElevenLabsModels');
$tts_auto_play = $active_bot_settings['tts_auto_play']
    ?? BotSettingsManager::DEFAULT_TTS_AUTO_PLAY;
$tts_auto_play = in_array($tts_auto_play, ['0', '1'], true)
    ? $tts_auto_play
    : BotSettingsManager::DEFAULT_TTS_AUTO_PLAY;

$google_tts_voices = AIPKit_Providers::get_google_tts_voices();
$google_tts_models = AIPKit_Providers::get_google_tts_models();
$elevenlabs_tts_voices = AIPKit_Providers::get_elevenlabs_voices();
$elevenlabs_tts_models = AIPKit_Providers::get_elevenlabs_models();
$openai_tts_models = AIPKit_Providers::get_openai_tts_models();
$openai_tts_voices = AIPKit_Providers::get_openai_tts_voices();

$enable_realtime_voice = $active_bot_settings['enable_realtime_voice']
    ?? BotSettingsManager::DEFAULT_ENABLE_REALTIME_VOICE;
$enable_realtime_voice = in_array($enable_realtime_voice, ['0', '1'], true)
    ? $enable_realtime_voice
    : BotSettingsManager::DEFAULT_ENABLE_REALTIME_VOICE;
$direct_voice_mode = $active_bot_settings['direct_voice_mode']
    ?? BotSettingsManager::DEFAULT_DIRECT_VOICE_MODE;
$direct_voice_mode = in_array($direct_voice_mode, ['0', '1'], true)
    ? $direct_voice_mode
    : BotSettingsManager::DEFAULT_DIRECT_VOICE_MODE;
$realtime_model = $active_bot_settings['realtime_model']
    ?? BotSettingsManager::get_default_model_id('OpenAIRealtime');
$realtime_voice = $active_bot_settings['realtime_voice']
    ?? BotSettingsManager::get_default_model_id('OpenAIRealtimeVoices');
$turn_detection = $active_bot_settings['turn_detection']
    ?? BotSettingsManager::DEFAULT_TURN_DETECTION;
$speed = isset($active_bot_settings['speed'])
    ? floatval($active_bot_settings['speed'])
    : BotSettingsManager::DEFAULT_SPEED;
$speed = max(0.25, min($speed, 1.5));
$input_audio_format = $active_bot_settings['input_audio_format']
    ?? BotSettingsManager::DEFAULT_INPUT_AUDIO_FORMAT;
$output_audio_format = $active_bot_settings['output_audio_format']
    ?? BotSettingsManager::DEFAULT_OUTPUT_AUDIO_FORMAT;
$input_audio_noise_reduction = $active_bot_settings['input_audio_noise_reduction']
    ?? BotSettingsManager::DEFAULT_INPUT_AUDIO_NOISE_REDUCTION;
$input_audio_noise_reduction = in_array($input_audio_noise_reduction, ['0', '1'], true)
    ? $input_audio_noise_reduction
    : BotSettingsManager::DEFAULT_INPUT_AUDIO_NOISE_REDUCTION;

$realtime_models = array_values(array_filter(array_map(
    static function ($model): string {
        return is_array($model) ? sanitize_text_field((string) ($model['id'] ?? '')) : '';
    },
    AIPKit_Providers::get_openai_realtime_models()
)));
$realtime_voices = array_column(AIPKit_Providers::get_openai_realtime_voices(), 'id');
$direct_voice_mode_disabled = !($quick_popup_enabled && $enable_realtime_voice === '1');

// Provider/model data for AI selection.
$allowed_main_providers = class_exists(AIPKit_Providers::class)
    ? AIPKit_Providers::get_main_provider_allowlist(true)
    : ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'DeepSeek', 'xAI'];
if (!is_array($allowed_main_providers) || empty($allowed_main_providers)) {
    $allowed_main_providers = ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'DeepSeek', 'xAI'];
}
// Backward-compatible alias used by shared provider/model partials.
$providers = $allowed_main_providers;
$default_main_provider = $allowed_main_providers[0] ?? 'OpenAI';
$is_pro = class_exists('\\WPAICG\\aipkit_dashboard') && aipkit_dashboard::is_pro_plan();
$rt_disabled_by_plan = !$is_pro_plan;
$rt_controls_disabled = $rt_disabled_by_plan;
$rt_force_visible = $rt_controls_disabled;

$can_enable_file_upload = false;
if (class_exists(aipkit_dashboard::class)) {
    if ($is_pro_plan) {
        $can_enable_file_upload = true;
    }
}
$file_upload_toggle_value = ($can_enable_file_upload && $enable_file_upload === '1') ? '1' : '0';

$saved_provider = isset($active_bot_settings['provider'])
    ? sanitize_text_field((string) $active_bot_settings['provider'])
    : $default_main_provider;
$saved_model = $active_bot_settings['model'] ?? '';
if (class_exists(AIPKit_Providers::class)) {
    // Normalize legacy lowercase values against the current allowlist.
    $allowlist_by_lower = [];
    foreach ($allowed_main_providers as $provider_name) {
        if (!is_string($provider_name)) {
            continue;
        }
        $provider_name = sanitize_text_field($provider_name);
        if ($provider_name === '') {
            continue;
        }
        $allowlist_by_lower[strtolower($provider_name)] = $provider_name;
    }
    $saved_provider_lookup = strtolower($saved_provider);
    if (isset($allowlist_by_lower[$saved_provider_lookup])) {
        $saved_provider = $allowlist_by_lower[$saved_provider_lookup];
    }

    $saved_provider = AIPKit_Providers::normalize_main_provider((string) $saved_provider, $default_main_provider);
} elseif (!in_array($saved_provider, $allowed_main_providers, true)) {
    $saved_provider = $default_main_provider;
}

// Preview placeholder content
$preview_placeholder_key = $active_bot_post ? 'previewLoading' : 'previewPlaceholderSelect';
$preview_placeholder_text = $active_bot_post
    ? __('Loading preview...', 'gpt3-ai-content-generator')
    : __('Select a bot to see the preview.', 'gpt3-ai-content-generator');

$aipkit_notice_id = 'aipkit_provider_notice_chatbot';
$aipkit_notice_context = __('use this chatbot', 'gpt3-ai-content-generator');
include WPAICG_PLUGIN_DIR . 'admin/views/shared/provider-key-notice.php';

?>

<div
    class="aipkit_chatbot_module_container aipkit_chatbot_builder aipkit_admin_ui"
    data-aipkit-chatbot-layout="next"
    data-active-bot-id="<?php echo esc_attr($initial_active_bot_id); ?>"
    data-default-bot-id="<?php echo esc_attr($default_bot_id); ?>"
    data-openai-api-key-set="<?php echo esc_attr(!empty($openai_api_key) ? 'true' : 'false'); ?>"
    data-pinecone-api-key-set="<?php echo esc_attr(!empty($pinecone_api_key) ? 'true' : 'false'); ?>"
    data-qdrant-api-key-set="<?php echo esc_attr(!empty($qdrant_api_key) ? 'true' : 'false'); ?>"
    data-qdrant-url-set="<?php echo esc_attr(!empty($qdrant_url) ? 'true' : 'false'); ?>"
    data-chroma-url-set="<?php echo esc_attr(!empty($chroma_url) ? 'true' : 'false'); ?>"
    data-google-api-key-set="<?php echo esc_attr(!empty($google_api_key) ? 'true' : 'false'); ?>"
    data-azure-api-key-set="<?php echo esc_attr(!empty($azure_api_key) ? 'true' : 'false'); ?>"
    data-claude-api-key-set="<?php echo esc_attr(!empty($claude_api_key) ? 'true' : 'false'); ?>"
    data-xai-api-key-set="<?php echo esc_attr(!empty($xai_api_key) ? 'true' : 'false'); ?>"
    data-model-settings-title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
    data-model-settings-description="<?php esc_attr_e('Configure model settings and behavior for this chatbot.', 'gpt3-ai-content-generator'); ?>"
>
    <div class="aipkit_chatbot_builder_layout">
            <div class="aipkit_chatbot_builder_left">
                <div id="aipkit_chatbot_main_tab_content_container">
                    <div class="aipkit_tab-content aipkit_active">
                        <div class="aipkit_chatbot-settings-area aipkit_builder_settings_area">
                            <form
                                class="aipkit_chatbot_settings_form"
                                data-bot-id="<?php echo esc_attr($initial_active_bot_id); ?>"
                                onsubmit="return false;"
                            >
                            <?php include WPAICG_PLUGIN_DIR . 'admin/views/shared/vector-store-nonce-fields.php'; ?>
                            <?php if ($active_bot_post) : ?>
                            <div class="aipkit_chatbot_core_panel">
                            <div
                                id="aipkit_chatbot_main_overlay"
                                class="aipkit_chatbot_settings_overlay aipkit_chatbot_main_overlay"
                                aria-hidden="true"
                                hidden
                            >
                                <span
                                    class="aipkit_chatbot_settings_overlay_spinner"
                                    aria-hidden="true"
                                ></span>
                            </div>
                            <div class="aipkit_hidden aipkit_model_status_slot" aria-hidden="true">
                                <span class="aipkit_model_sync_status"></span>
                                <span
                                    id="aipkit_chatbot_global_save_status_container"
                                    class="aipkit_save_status_container aipkit_builder_save_status"
                                    aria-live="polite"
                                ></span>
                            </div>
                            <section class="aipkit_widget_designer" data-aipkit-widget-designer aria-label="<?php esc_attr_e('Chatbot design', 'gpt3-ai-content-generator'); ?>">
                                <div class="aipkit_widget_designer_header">
                                    <h1 class="aipkit_widget_designer_title"><?php esc_html_e('Your chatbot', 'gpt3-ai-content-generator'); ?></h1>
                                    <div class="aipkit_widget_bot_manager" data-aipkit-bot-manager>
                                        <div
                                            class="aipkit_widget_bot_switcher"
                                            <?php echo count($all_bots_ordered_entries) < 2 ? 'hidden' : ''; ?>
                                        >
                                            <label for="aipkit_chatbot_builder_bot_select" class="screen-reader-text">
                                                <?php esc_html_e('Select chatbot', 'gpt3-ai-content-generator'); ?>
                                            </label>
                                            <select
                                                id="aipkit_chatbot_builder_bot_select"
                                                name="aipkit_chatbot_builder_bot_select"
                                                class="aipkit_builder_bot_select_input aipkit_widget_bot_select_input"
                                                aria-label="<?php esc_attr_e('Select chatbot', 'gpt3-ai-content-generator'); ?>"
                                                <?php echo empty($all_bots_ordered_entries) ? 'disabled' : ''; ?>
                                            >
                                                <?php if (empty($all_bots_ordered_entries)) : ?>
                                                    <option value="">
                                                        <?php esc_html_e('No chatbots yet', 'gpt3-ai-content-generator'); ?>
                                                    </option>
                                                <?php else : ?>
                                                    <?php foreach ($all_bots_ordered_entries as $bot_entry_for_select) : ?>
                                                        <?php $bot_post_for_select = $bot_entry_for_select['post']; ?>
                                                        <option
                                                            value="<?php echo esc_attr($bot_post_for_select->ID); ?>"
                                                            <?php selected($initial_active_bot_id, $bot_post_for_select->ID); ?>
                                                        >
                                                            <?php echo esc_html($bot_post_for_select->post_title); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <button
                                            type="button"
                                            class="aipkit_widget_bot_icon_btn aipkit_widget_bot_new_btn aipkit_builder_new_bot_btn"
                                            aria-label="<?php esc_attr_e('New chatbot', 'gpt3-ai-content-generator'); ?>"
                                            title="<?php esc_attr_e('New chatbot', 'gpt3-ai-content-generator'); ?>"
                                        >
                                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                        </button>
                                        <div class="aipkit_widget_bot_actions" data-aipkit-bot-actions>
                                            <button
                                                type="button"
                                                class="aipkit_widget_bot_icon_btn aipkit_widget_bot_actions_trigger"
                                                data-aipkit-bot-actions-toggle
                                                aria-label="<?php esc_attr_e('Chatbot actions', 'gpt3-ai-content-generator'); ?>"
                                                title="<?php esc_attr_e('Chatbot actions', 'gpt3-ai-content-generator'); ?>"
                                                aria-haspopup="menu"
                                                aria-expanded="false"
                                                aria-controls="aipkit_widget_bot_actions_menu"
                                            >
                                                <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
                                            </button>
                                            <div
                                                class="aipkit_widget_bot_actions_menu"
                                                id="aipkit_widget_bot_actions_menu"
                                                data-aipkit-bot-actions-menu
                                                role="menu"
                                                hidden
                                            >
                                                <button type="button" class="aipkit_widget_bot_actions_item aipkit_widget_bot_duplicate_btn" data-aipkit-bot-action="duplicate" role="menuitem">
                                                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                                    <span class="aipkit_widget_bot_actions_label"><?php esc_html_e('Duplicate', 'gpt3-ai-content-generator'); ?></span>
                                                </button>
                                                <button type="button" class="aipkit_widget_bot_actions_item aipkit_widget_bot_reset_btn" data-aipkit-bot-action="reset" role="menuitem">
                                                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                                    <span class="aipkit_widget_bot_actions_label"><?php esc_html_e('Restore defaults', 'gpt3-ai-content-generator'); ?></span>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="aipkit_widget_bot_actions_item aipkit_widget_bot_actions_item--danger aipkit_widget_bot_delete_btn"
                                                    data-aipkit-bot-action="delete"
                                                    role="menuitem"
                                                    <?php echo ((string) $initial_active_bot_id === (string) $default_bot_id) ? 'disabled aria-disabled="true"' : 'aria-disabled="false"'; ?>
                                                >
                                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                                    <span class="aipkit_widget_bot_actions_label"><?php esc_html_e('Delete', 'gpt3-ai-content-generator'); ?></span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="aipkit_widget_appearance">
                                    <div class="aipkit_widget_appearance_fields">
                                <div
                                    class="aipkit_widget_profile_row"
                                    data-aipkit-popup-only-control
                                    <?php echo $quick_popup_enabled ? '' : 'hidden'; ?>
                                >
                                    <button
                                        type="button"
                                        class="aipkit_widget_avatar_btn"
                                        data-aipkit-avatar-quick-upload
                                        aria-label="<?php esc_attr_e('Change chat photo', 'gpt3-ai-content-generator'); ?>"
                                    >
                                        <span
                                            class="aipkit_widget_avatar_preview"
                                            data-aipkit-avatar-quick-preview
                                            data-avatar-initial="<?php echo esc_attr($quick_header_avatar_initial); ?>"
                                        >
                                            <?php if ($quick_header_avatar_url !== '') : ?>
                                                <img src="<?php echo esc_url($quick_header_avatar_url); ?>" alt="" class="aipkit_widget_avatar_img" />
                                            <?php elseif ($quick_header_avatar_icon_html !== '') : ?>
                                                <span class="aipkit_widget_avatar_icon" aria-hidden="true">
                                                    <?php
                                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                    echo $quick_header_avatar_icon_html;
                                                    ?>
                                                </span>
                                            <?php else : ?>
                                                <span class="aipkit_widget_avatar_initial" aria-hidden="true"><?php echo esc_html($quick_header_avatar_initial); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="aipkit_widget_avatar_edit_badge" aria-hidden="true">
                                            <span class="dashicons dashicons-edit"></span>
                                        </span>
                                    </button>
                                    <span class="aipkit_widget_profile_copy">
                                        <span class="aipkit_widget_profile_label"><?php esc_html_e('Chat photo', 'gpt3-ai-content-generator'); ?></span>
                                        <span class="aipkit_widget_profile_status" data-aipkit-avatar-link-status>
                                            <?php
                                            if ($saved_header_avatar_type === 'inherit') {
                                                esc_html_e('Matches widget icon', 'gpt3-ai-content-generator');
                                            } elseif ($saved_header_avatar_type === 'custom') {
                                                esc_html_e('Custom image', 'gpt3-ai-content-generator');
                                            } else {
                                                esc_html_e('Separate icon', 'gpt3-ai-content-generator');
                                            }
                                            ?>
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        class="aipkit_widget_avatar_use_widget"
                                        data-aipkit-avatar-use-widget
                                        <?php echo ($saved_header_avatar_type === 'inherit') ? 'hidden' : ''; ?>
                                    >
                                        <?php esc_html_e('Use widget icon', 'gpt3-ai-content-generator'); ?>
                                    </button>
                                </div>
                                <div
                                    class="aipkit_widget_launcher_message"
                                    data-aipkit-popup-only-control
                                    <?php echo $quick_popup_enabled ? '' : 'hidden'; ?>
                                >
                                    <div class="aipkit_widget_launcher_label_row">
                                        <label
                                            class="aipkit_widget_designer_label"
                                            for="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_popup_label_text_main"
                                        >
                                            <?php esc_html_e('Welcome message', 'gpt3-ai-content-generator'); ?>
                                        </label>
                                        <label
                                            class="aipkit_widget_launcher_toggle"
                                            for="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_popup_label_enabled_main"
                                        >
                                            <span><?php esc_html_e('Show', 'gpt3-ai-content-generator'); ?></span>
                                            <span class="aipkit_switch aipkit_widget_launcher_switch">
                                                <input
                                                    type="checkbox"
                                                    id="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_popup_label_enabled_main"
                                                    class="aipkit_popup_hint_toggle_checkbox"
                                                    <?php checked($popup_label_enabled, '1'); ?>
                                                />
                                                <span class="aipkit_switch_slider"></span>
                                            </span>
                                        </label>
                                    </div>
                                    <input
                                        type="text"
                                        id="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_popup_label_text_main"
                                        name="popup_label_text"
                                        class="aipkit_widget_launcher_input aipkit_form-input"
                                        value="<?php echo esc_attr($popup_label_text); ?>"
                                        maxlength="60"
                                        placeholder="<?php esc_attr_e('Need help? Ask me!', 'gpt3-ai-content-generator'); ?>"
                                        <?php disabled($popup_label_enabled !== '1'); ?>
                                    >
                                </div>
                                <?php include __DIR__ . '/widget-colors.php'; ?>
                                <?php if (!empty($popup_icons)) : ?>
                                    <div
                                        class="aipkit_widget_icon_block"
                                        data-aipkit-popup-only-control
                                        <?php echo $quick_popup_enabled ? '' : 'hidden'; ?>
                                    >
                                        <span class="aipkit_widget_designer_label"><?php esc_html_e('Widget icon', 'gpt3-ai-content-generator'); ?></span>
                                        <div class="aipkit_widget_icon_choices" data-aipkit-widget-icon-quick>
                                            <?php foreach ($popup_icons as $icon_key => $svg_html) : ?>
                                                <?php
                                                $quick_icon_id = 'aipkit_bot_' . absint($initial_active_bot_id) . '_quick_widget_icon_' . sanitize_key($icon_key);
                                                $quick_icon_checked = ($popup_icon_type !== 'custom' && $popup_icon_value === $icon_key);
                                                ?>
                                                <label class="aipkit_widget_icon_choice" for="<?php echo esc_attr($quick_icon_id); ?>" title="<?php echo esc_attr(ucwords(str_replace('-', ' ', $icon_key))); ?>">
                                                    <input
                                                        type="radio"
                                                        id="<?php echo esc_attr($quick_icon_id); ?>"
                                                        name="aipkit_widget_icon_quick"
                                                        value="<?php echo esc_attr($icon_key); ?>"
                                                        <?php checked($quick_icon_checked); ?>
                                                    />
                                                    <span class="aipkit_widget_icon_choice_visual" aria-hidden="true">
                                                        <?php
                                                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                        echo $svg_html;
                                                        ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php
                                            $quick_custom_icon_url = ($popup_icon_type === 'custom' && !empty($popup_icon_value)) ? $popup_icon_value : '';
                                            ?>
                                            <button
                                                type="button"
                                                class="aipkit_widget_icon_choice aipkit_widget_icon_upload_btn<?php echo ($popup_icon_type === 'custom') ? ' is-selected' : ''; ?>"
                                                data-aipkit-widget-icon-upload
                                                aria-pressed="<?php echo ($popup_icon_type === 'custom') ? 'true' : 'false'; ?>"
                                                aria-label="<?php esc_attr_e('Upload widget icon', 'gpt3-ai-content-generator'); ?>"
                                                title="<?php esc_attr_e('Upload widget icon', 'gpt3-ai-content-generator'); ?>"
                                            >
                                                <span class="aipkit_widget_icon_choice_visual aipkit_widget_icon_choice_visual--custom" data-aipkit-widget-icon-custom-visual aria-hidden="true">
                                                    <?php if ($quick_custom_icon_url !== '') : ?>
                                                        <img src="<?php echo esc_url($quick_custom_icon_url); ?>" alt="" class="aipkit_widget_icon_custom_img" />
                                                    <?php else : ?>
                                                        <span class="dashicons dashicons-plus-alt2"></span>
                                                    <?php endif; ?>
                                                </span>
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                    </div>
                                </div>
                            </section>
	                            <div class="aipkit_chatbot_quick_setup aipkit_chatbot_primary_settings">
	                                <div class="aipkit_builder_ai_model aipkit_chatbot_model_config aipkit_chatbot_quick_model">
                                    <?php
                                    $is_next_layout = true;
                                    include WPAICG_PLUGIN_DIR . 'admin/views/chatbot/models.php';
                                    ?>
                                    <input
                                        type="hidden"
                                        id="aipkit_builder_top_mode_select"
                                        name="deploy_mode"
                                        value="<?php echo esc_attr($quick_deploy_mode); ?>"
                                        data-aipkit-top-mode-select
                                        data-aipkit-external-popup-enabled="<?php echo esc_attr($quick_popup_enabled ? '1' : '0'); ?>"
                                    />
                                    <input
                                        type="hidden"
                                        name="popup_enabled"
                                        value="<?php echo esc_attr($quick_popup_enabled ? '1' : '0'); ?>"
                                        data-aipkit-popup-enabled-input
                                    />
                                    <input
                                        type="hidden"
                                        name="site_wide_enabled"
                                        value="<?php echo esc_attr($quick_site_wide_enabled ? '1' : '0'); ?>"
                                        data-aipkit-site-wide-enabled-input
                                    />
                                </div>
                                <div class="aipkit_builder_field aipkit_chatbot_quick_instructions">
                                    <label for="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_instructions" class="aipkit_builder_label">
                                        <?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?>
                                    </label>
                                    <div class="aipkit_builder_textarea_wrap">
                                        <textarea
                                            id="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_instructions"
                                            name="instructions"
                                            class="aipkit_builder_textarea aipkit_form-input"
                                            rows="3"
                                            placeholder="<?php esc_attr_e('e.g., You are a helpful AI Assistant. Please be friendly.', 'gpt3-ai-content-generator'); ?>"
                                        ><?php echo esc_textarea($active_bot_instructions); ?></textarea>
                                        <button
                                            type="button"
                                            class="aipkit_builder_icon_btn aipkit_builder_textarea_expand aipkit_builder_instructions_expand"
                                            aria-label="<?php esc_attr_e('Expand instructions editor', 'gpt3-ai-content-generator'); ?>"
                                        >
                                            <span class="dashicons dashicons-editor-expand"></span>
                                        </button>
	                                    </div>
	                                </div>
                            </div>
                            <div class="aipkit_chatbot_advanced_entry">
                                <button
                                    type="button"
                                    class="aipkit_chatbot_advanced_trigger"
                                    data-aipkit-advanced-drawer-open
                                    aria-haspopup="dialog"
                                    aria-controls="aipkit_chatbot_advanced_drawer"
                                    aria-expanded="false"
                                >
                                    <span class="aipkit_chatbot_advanced_trigger_label">
                                        <span class="aipkit_chatbot_advanced_trigger_icon" aria-hidden="true">
                                            <span class="dashicons dashicons-admin-settings"></span>
                                        </span>
                                        <span class="aipkit_chatbot_advanced_trigger_text">
                                            <span class="aipkit_chatbot_advanced_trigger_title"><?php esc_html_e('Chatbot settings', 'gpt3-ai-content-generator'); ?></span>
                                            <span class="aipkit_chatbot_advanced_trigger_description"><?php esc_html_e('Behavior, knowledge, and display', 'gpt3-ai-content-generator'); ?></span>
                                        </span>
                                    </span>
                                    <span class="dashicons dashicons-arrow-right-alt2 aipkit_chatbot_advanced_trigger_chevron" aria-hidden="true"></span>
                                </button>
                            </div>
                            </div>
                            <?php include WPAICG_PLUGIN_DIR . 'admin/views/chatbot/knowledge.php'; ?>
                            <div
                                class="aipkit_builder_sheet_overlay aipkit_chatbot_advanced_drawer"
                                id="aipkit_chatbot_advanced_drawer"
                                data-aipkit-advanced-drawer
                                aria-hidden="true"
                            >
                                <div
                                    class="aipkit_builder_sheet_panel aipkit_chatbot_advanced_panel"
                                    role="dialog"
                                    aria-modal="true"
                                    aria-labelledby="aipkit_chatbot_advanced_drawer_title"
                                >
                                    <div class="aipkit_builder_sheet_header aipkit_chatbot_advanced_drawer_header">
                                        <div class="aipkit_chatbot_advanced_drawer_intro">
                                            <h2 class="aipkit_builder_sheet_title aipkit_chatbot_advanced_drawer_title" id="aipkit_chatbot_advanced_drawer_title">
                                                <?php esc_html_e('Chatbot settings', 'gpt3-ai-content-generator'); ?>
                                            </h2>
                                        </div>
                                        <button
                                            type="button"
                                            class="aipkit_builder_sheet_close aipkit_chatbot_advanced_drawer_close"
                                            data-aipkit-advanced-drawer-close
                                            aria-label="<?php esc_attr_e('Close chatbot settings', 'gpt3-ai-content-generator'); ?>"
                                        >
                                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                    <div class="aipkit_builder_sheet_body aipkit_chatbot_advanced_drawer_body">
                            <section class="aipkit_builder_card aipkit_builder_card--settings aipkit_chatbot_settings_panel aipkit_chatbot_settings_panel--drawer" id="aipkit_chatbot_settings_panel">
                                <div
                                    id="aipkit_chatbot_settings_overlay"
                                    class="aipkit_chatbot_settings_overlay"
                                    aria-hidden="true"
                                    hidden
                                >
                                    <span
                                        class="aipkit_chatbot_settings_overlay_spinner"
                                        aria-hidden="true"
                                    ></span>
                                </div>
                                <?php
                                $bot_id = $initial_active_bot_id;
                                $bot_settings = $active_bot_settings;
                                $token_limit_mode_value = $active_bot_settings['token_limit_mode']
                                    ?? BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
                                $token_limit_mode_value = is_scalar($token_limit_mode_value)
                                    ? sanitize_key((string) $token_limit_mode_value)
                                    : BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
                                if (!in_array($token_limit_mode_value, ['general', 'role_based'], true)) {
                                    $token_limit_mode_value = BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
                                }
                                $token_guest_limit_value = $active_bot_settings['token_guest_limit'] ?? '';
                                $token_guest_limit_value = is_scalar($token_guest_limit_value)
                                    ? trim((string) $token_guest_limit_value)
                                    : '';
                                $token_user_limit_value = $active_bot_settings['token_user_limit'] ?? '';
                                $token_user_limit_value = is_scalar($token_user_limit_value)
                                    ? trim((string) $token_user_limit_value)
                                    : '';
                                $token_guest_summary_value = $token_guest_limit_value !== ''
                                    ? $token_guest_limit_value
                                    : __('Unlimited', 'gpt3-ai-content-generator');
                                $token_user_summary_value = $token_user_limit_value !== ''
                                    ? $token_user_limit_value
                                    : __('Unlimited', 'gpt3-ai-content-generator');
                                $quota_summary_text = $token_limit_mode_value === 'role_based'
                                    ? __('Role-based quota', 'gpt3-ai-content-generator')
                                    : sprintf(
                                        /* translators: 1: guest token limit summary, 2: user token limit summary. */
                                        __('Guests %1$s · Users %2$s', 'gpt3-ai-content-generator'),
                                        $token_guest_summary_value,
                                        $token_user_summary_value
                                    );
                                $limits_summary_text = $quota_summary_text;
                                $limits_summary_fallback = $limits_summary_text;
                                $rules_count = 0;
                                if ($triggers_available) {
                                    $saved_triggers_json = $active_bot_settings['triggers_json'] ?? '[]';
                                    if (is_array($saved_triggers_json)) {
                                        $rules_count = count($saved_triggers_json);
                                    } elseif (is_string($saved_triggers_json) && $saved_triggers_json !== '') {
                                        $decoded_rules = json_decode($saved_triggers_json, true);
                                        if (is_array($decoded_rules)) {
                                            if (isset($decoded_rules['triggers']) && is_array($decoded_rules['triggers'])) {
                                                $rules_count = count($decoded_rules['triggers']);
                                            } elseif (isset($decoded_rules['rules']) && is_array($decoded_rules['rules'])) {
                                                $rules_count = count($decoded_rules['rules']);
                                            } else {
                                                $rules_count = count($decoded_rules);
                                            }
                                        }
                                    }
                                }
                                $rules_summary_fallback = '';
                                $rules_summary_text = $rules_count > 0
                                    ? sprintf(
                                        /* translators: %d: number of chatbot rules. */
                                        _n('%d rule', '%d rules', $rules_count, 'gpt3-ai-content-generator'),
                                        $rules_count
                                    )
                                    : $rules_summary_fallback;
                                $active_bot_name_value = ($active_bot_post && isset($active_bot_post->post_title))
                                    ? (string) $active_bot_post->post_title
                                    : '';
                                ?>
                                <div class="aipkit_settings_panel_body" data-aipkit-settings-panel="chatbot">
                                    <div class="aipkit_builder_field aipkit_chatbot_response_settings">
                                        <?php include __DIR__ . '/behavior-settings.php'; ?>
                                    </div>
                                </div>
                                <div class="aipkit_chatbot_settings_section_heading">
                                    <?php esc_html_e('Display', 'gpt3-ai-content-generator'); ?>
                                </div>
                                <?php include __DIR__ . '/display-settings.php'; ?>
                                <div class="aipkit_chatbot_settings_section_heading">
                                    <?php esc_html_e('Publish', 'gpt3-ai-content-generator'); ?>
                                </div>
                                <div class="aipkit_settings_panel_body" data-aipkit-settings-panel="embed">
                                    <div class="aipkit_popover_options_list aipkit_interface_options aipkit_display_settings_rows">
                                        <div
                                            class="aipkit_interface_feature_row aipkit_interface_feature_row--expandable aipkit_display_settings_row aipkit_display_settings_row--shortcode"
                                            data-aipkit-inline-settings-row
                                            data-aipkit-static-inline-settings-row
                                        >
                                            <div class="aipkit_interface_feature_label">
                                                <span class="aipkit_display_settings_icon" aria-hidden="true">
                                                    <span class="dashicons dashicons-shortcode"></span>
                                                </span>
                                                <span class="aipkit_interface_feature_text">
                                                    <span class="aipkit_interface_feature_title aipkit_popover_option_label">
                                                        <?php esc_html_e('WordPress shortcode', 'gpt3-ai-content-generator'); ?>
                                                    </span>
                                                    <span class="aipkit_interface_feature_hint">
                                                        <?php esc_html_e('Add this chatbot to any page, post, or widget.', 'gpt3-ai-content-generator'); ?>
                                                    </span>
                                                </span>
                                            </div>
                                            <div class="aipkit_interface_feature_action">
                                                <button
                                                    type="button"
                                                    class="aipkit_popover_option_btn aipkit_display_settings_toggle aipkit_interface_feature_expand_btn"
                                                    data-aipkit-inline-settings-toggle
                                                    data-aipkit-static-inline-settings-toggle
                                                    aria-expanded="false"
                                                    aria-controls="aipkit_embed_shortcode_panel"
                                                    aria-label="<?php esc_attr_e('Toggle WordPress shortcode settings', 'gpt3-ai-content-generator'); ?>"
                                                >
                                                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div
                                                id="aipkit_embed_shortcode_panel"
                                                class="aipkit_interface_feature_inline_panel aipkit_display_inline_panel"
                                                hidden
                                            >
                                                <div class="aipkit_embed_shortcode_controls">
                                                    <button
                                                        type="button"
                                                        class="aipkit_shortcode_pill aipkit_builder_shortcode_pill aipkit_builder_shortcode_pill--embed aipkit_embed_shortcode_value"
                                                        data-shortcode="<?php echo esc_attr($shortcode_text); ?>"
                                                        title="<?php esc_attr_e('Click to copy shortcode', 'gpt3-ai-content-generator'); ?>"
                                                    >
                                                        <span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
                                                        <span class="aipkit_shortcode_text"><?php echo esc_html($shortcode_text); ?></span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="aipkit_shortcode_pill aipkit_builder_shortcode_pill aipkit_embed_copy_shortcode_btn"
                                                        data-shortcode="<?php echo esc_attr($shortcode_text); ?>"
                                                        title="<?php esc_attr_e('Copy shortcode', 'gpt3-ai-content-generator'); ?>"
                                                    >
                                                        <span class="aipkit_shortcode_text screen-reader-text"><?php echo esc_html($shortcode_text); ?></span>
                                                        <span class="aipkit_embed_copy_visible"><?php esc_html_e('Copy', 'gpt3-ai-content-generator'); ?></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div
                                            class="aipkit_interface_feature_row aipkit_interface_feature_row--expandable aipkit_display_settings_row aipkit_display_settings_row--external"
                                            data-aipkit-inline-settings-row
                                            data-aipkit-static-inline-settings-row
                                        >
                                            <div class="aipkit_interface_feature_label">
                                                <span class="aipkit_display_settings_icon" aria-hidden="true">
                                                    <span class="dashicons dashicons-editor-code"></span>
                                                </span>
                                                <span class="aipkit_interface_feature_text">
                                                    <span class="aipkit_interface_feature_title aipkit_popover_option_label">
                                                        <?php esc_html_e('External embed', 'gpt3-ai-content-generator'); ?>
                                                        <?php if (!$embed_anywhere_active) : ?>
                                                            <span class="aipkit_embed_method_badge aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="aipkit_interface_feature_hint">
                                                        <?php esc_html_e('Add this chatbot to another website.', 'gpt3-ai-content-generator'); ?>
                                                    </span>
                                                </span>
                                            </div>
                                            <div class="aipkit_interface_feature_action">
                                                <button
                                                    type="button"
                                                    class="aipkit_popover_option_btn aipkit_display_settings_toggle aipkit_interface_feature_expand_btn"
                                                    data-aipkit-inline-settings-toggle
                                                    data-aipkit-static-inline-settings-toggle
                                                    aria-expanded="false"
                                                    aria-controls="aipkit_embed_external_panel"
                                                    aria-label="<?php esc_attr_e('Toggle external embed settings', 'gpt3-ai-content-generator'); ?>"
                                                >
                                                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <div
                                                id="aipkit_embed_external_panel"
                                                class="aipkit_interface_feature_inline_panel aipkit_display_inline_panel"
                                                hidden
                                            >
                                                <?php if ($embed_anywhere_active) : ?>
                                                    <div class="aipkit_builder_external_stack">
                                                        <div class="aipkit_builder_external_field">
                                                            <div class="aipkit_builder_external_field_header">
                                                                <label
                                                                    class="aipkit_builder_external_label"
                                                                    for="aipkit_embed_code_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                                >
                                                                    <?php esc_html_e('Embed code', 'gpt3-ai-content-generator'); ?>
                                                                </label>
                                                                <button
                                                                    type="button"
                                                                    class="aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_copy_embed_code_btn"
                                                                    data-target="aipkit_embed_code_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                                >
                                                                    <?php esc_html_e('Copy code', 'gpt3-ai-content-generator'); ?>
                                                                </button>
                                                            </div>
                                                            <textarea
                                                                id="aipkit_embed_code_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                                class="aipkit_builder_external_textarea aipkit_builder_external_textarea--code"
                                                                readonly
                                                            ><?php echo esc_textarea($embed_code); ?></textarea>
                                                        </div>
                                                        <div class="aipkit_builder_external_field">
                                                            <label
                                                                class="aipkit_builder_external_label"
                                                                for="aipkit_embed_allowed_domains_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                            >
                                                                <?php esc_html_e('Allowed websites', 'gpt3-ai-content-generator'); ?>
                                                            </label>
                                                            <textarea
                                                                id="aipkit_embed_allowed_domains_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                                name="embed_allowed_domains"
                                                                class="aipkit_builder_external_textarea aipkit_builder_external_textarea--domains"
                                                                placeholder="<?php esc_attr_e('https://example.com', 'gpt3-ai-content-generator'); ?>"
                                                            ><?php echo esc_textarea($embed_allowed_domains); ?></textarea>
                                                            <p class="aipkit_builder_external_hint">
                                                                <?php esc_html_e('Leave blank to allow any website.', 'gpt3-ai-content-generator'); ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                <?php else : ?>
                                                    <div class="aipkit_embed_locked_panel">
                                                        <p class="aipkit_embed_locked_text">
                                                            <?php esc_html_e('Use one script snippet to show this chatbot on another website.', 'gpt3-ai-content-generator'); ?>
                                                        </p>
                                                        <div class="aipkit_embed_promo_cta">
                                                            <a
                                                                href="<?php echo esc_url($pricing_url); ?>"
                                                                class="aipkit_embed_promo_btn aipkit_pro_upgrade_button"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                                                            </a>
                                                            <a
                                                                href="<?php echo esc_url($embed_docs_url); ?>"
                                                                class="aipkit_embed_promo_btn aipkit_embed_promo_btn--secondary"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                <?php esc_html_e('Learn more', 'gpt3-ai-content-generator'); ?>
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aipkit_chatbot-preview-column aipkit_chatbot_builder_right">
                <div class="aipkit_preview_deploy_bar" aria-label="<?php esc_attr_e('Deployment', 'gpt3-ai-content-generator'); ?>">
                    <div class="aipkit_preview_deploy_item aipkit_preview_deploy_item--shortcode">
                        <button
                            type="button"
                            class="aipkit_shortcode_pill aipkit_builder_shortcode_pill aipkit_builder_shortcode_pill--preview"
                            data-shortcode="<?php echo esc_attr($shortcode_text); ?>"
                            title="<?php esc_attr_e('Click to copy shortcode', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="aipkit_shortcode_text"><?php echo esc_html($shortcode_text); ?></span>
                            <span class="aipkit_preview_shortcode_copy" aria-hidden="true">
                                <span class="dashicons dashicons-clipboard"></span>
                            </span>
                        </button>
                    </div>
                    <div class="aipkit_preview_deploy_item aipkit_preview_deploy_item--toggle">
                        <label class="aipkit_preview_deploy_toggle" for="aipkit_builder_top_popup_toggle">
                            <span class="aipkit_preview_deploy_toggle_copy">
                                <span class="aipkit_preview_deploy_label">
                                    <?php esc_html_e('Popup', 'gpt3-ai-content-generator'); ?>
                                </span>
                                <span class="aipkit_preview_deploy_state aipkit_preview_deploy_state--on" aria-hidden="true"><?php esc_html_e('Enabled', 'gpt3-ai-content-generator'); ?></span>
                                <span class="aipkit_preview_deploy_state aipkit_preview_deploy_state--off" aria-hidden="true"><?php esc_html_e('Disabled', 'gpt3-ai-content-generator'); ?></span>
                            </span>
                            <span class="aipkit_switch aipkit_preview_deploy_switch">
                                <input
                                    type="checkbox"
                                    id="aipkit_builder_top_popup_toggle"
                                    data-aipkit-popup-toggle
                                    <?php checked($quick_popup_enabled, true); ?>
                                />
                                <span class="aipkit_switch_slider"></span>
                            </span>
                        </label>
                    </div>
                    <div
                        class="aipkit_preview_deploy_item aipkit_preview_deploy_item--toggle aipkit_builder_popup_scope_row"
                    >
                        <label class="aipkit_preview_deploy_toggle" for="aipkit_builder_top_site_wide_toggle">
                            <span class="aipkit_preview_deploy_toggle_copy">
                                <span class="aipkit_preview_deploy_label">
                                    <?php esc_html_e('Site-wide', 'gpt3-ai-content-generator'); ?>
                                </span>
                                <span class="aipkit_preview_deploy_state aipkit_preview_deploy_state--on" aria-hidden="true"><?php esc_html_e('Enabled', 'gpt3-ai-content-generator'); ?></span>
                                <span class="aipkit_preview_deploy_state aipkit_preview_deploy_state--off" aria-hidden="true"><?php esc_html_e('Disabled', 'gpt3-ai-content-generator'); ?></span>
                            </span>
                            <span class="aipkit_switch aipkit_preview_deploy_switch">
                                <input
                                    type="checkbox"
                                    id="aipkit_builder_top_site_wide_toggle"
                                    data-aipkit-site-wide-toggle
                                    <?php checked($quick_site_wide_enabled, true); ?>
                                />
                                <span class="aipkit_switch_slider"></span>
                            </span>
                        </label>
                    </div>
                </div>
                <h2 class="aipkit_preview_heading"><?php esc_html_e('Live preview', 'gpt3-ai-content-generator'); ?></h2>
                <section class="aipkit_chatbot_shell aipkit_chatbot_shell--preview">
                    <div class="aipkit_chatbot_shell_body aipkit_chatbot_shell_body--preview">
                        <div class="aipkit_builder_preview_frame">
                            <div id="aipkit_admin_chat_preview_container">
                                <p class="aipkit_preview_placeholder" data-key="<?php echo esc_attr($preview_placeholder_key); ?>">
                                    <?php echo esc_html($preview_placeholder_text); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
    </div>
    <?php if ($active_bot_post) : ?>
        <div
            class="aipkit_inline_starters_panel"
            id="aipkit_starters_panel"
            aria-hidden="true"
            role="dialog"
        >
        <div class="aipkit_inline_settings_body aipkit_settings_starters_body">
            <?php
            $bot_id = $initial_active_bot_id;
            $bot_settings = $active_bot_settings;
            $conversation_starters = $bot_settings['conversation_starters'] ?? [];
            $conversation_starters_text = implode("\n", $conversation_starters);
            ?>
            <div class="aipkit_popover_options_list">
                <div class="aipkit_popover_option_row">
                    <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
                        <label
                            class="aipkit_popover_option_label"
                            for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_conversation_starters"

                        >
                            <?php esc_html_e('Conversation starters (max 6)', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <textarea
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_conversation_starters"
                            name="conversation_starters"
                            class="aipkit_popover_option_textarea"
                            rows="4"
                        ><?php echo esc_textarea($conversation_starters_text); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <?php if ($consent_feature_available) : ?>
            <div
                class="aipkit_inline_consent_panel"
                id="aipkit_consent_panel"
                aria-hidden="true"
                role="dialog"
            >
                <div class="aipkit_inline_settings_body aipkit_settings_consent_body">
                    <?php
                    $bot_id = $initial_active_bot_id;
                    $bot_settings = $active_bot_settings;
                    $consent_title = $bot_settings['consent_title'] ?? __('Consent Required', 'gpt3-ai-content-generator');
                    $consent_message = $bot_settings['consent_message'] ?? __('Before starting the conversation, please agree to our Terms of Service and Privacy Policy.', 'gpt3-ai-content-generator');
                    $consent_button = $bot_settings['consent_button'] ?? __('I Agree', 'gpt3-ai-content-generator');
                    ?>
                    <div class="aipkit_popover_options_list aipkit_consent_fields_grid">
                        <div class="aipkit_popover_option_row aipkit_consent_field">
                            <div class="aipkit_popover_option_main">
                                <label
                                    class="aipkit_popover_option_label"
                                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_consent_title"
                                >
                                    <?php esc_html_e('Title', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <input
                                    type="text"
                                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_consent_title"
                                    name="consent_title"
                                    class="aipkit_popover_option_input aipkit_popover_option_input--framed"
                                    value="<?php echo esc_attr($consent_title); ?>"
                                    placeholder="<?php esc_attr_e('Consent Required', 'gpt3-ai-content-generator'); ?>"
                                    autocomplete="off"
                                    data-lpignore="true"
                                    data-1p-ignore="true"
                                    data-form-type="other"
                                />
                            </div>
                        </div>
                        <div class="aipkit_popover_option_row aipkit_consent_field">
                            <div class="aipkit_popover_option_main">
                                <label
                                    class="aipkit_popover_option_label"
                                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_consent_button"
                                >
                                    <?php esc_html_e('Button label', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <input
                                    type="text"
                                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_consent_button"
                                    name="consent_button"
                                    class="aipkit_popover_option_input aipkit_popover_option_input--framed"
                                    value="<?php echo esc_attr($consent_button); ?>"
                                    placeholder="<?php esc_attr_e('I Agree', 'gpt3-ai-content-generator'); ?>"
                                    autocomplete="off"
                                    data-lpignore="true"
                                    data-1p-ignore="true"
                                    data-form-type="other"
                                />
                            </div>
                        </div>
                        <div class="aipkit_popover_option_row aipkit_consent_field aipkit_consent_field--full">
                            <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked">
                                <label
                                    class="aipkit_popover_option_label"
                                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_consent_message"
                                >
                                    <?php esc_html_e('Message', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <textarea
                                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_consent_message"
                                    name="consent_message"
                                    class="aipkit_popover_option_textarea"
                                    rows="4"
                                ><?php echo esc_textarea($consent_message); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <div
        class="aipkit-modal-overlay aipkit_builder_instructions_modal"
        id="aipkit_builder_instructions_modal"
        aria-hidden="true"
    >
        <div
            class="aipkit-modal-content"
            role="dialog"
            aria-modal="true"
            aria-labelledby="aipkit_builder_instructions_title"
            aria-describedby="aipkit_builder_instructions_description"
        >
            <div class="aipkit-modal-header">
                <div>
                    <h2 class="aipkit-modal-title" id="aipkit_builder_instructions_title">
                        <?php esc_html_e('Agent Instructions', 'gpt3-ai-content-generator'); ?>
                    </h2>
                    <p class="aipkit_builder_modal_subtitle" id="aipkit_builder_instructions_description">
                        <?php esc_html_e('Define how your agent should behave. Changes are saved automatically when you close this dialog.', 'gpt3-ai-content-generator'); ?>
                    </p>
                </div>
                <button
                    type="button"
                    class="aipkit-modal-close-btn aipkit_builder_instructions_close"
                    aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="aipkit-modal-body">
                <div class="aipkit_builder_field">
                    <textarea
                        id="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_instructions_modal"
                        class="aipkit_builder_textarea aipkit_builder_textarea_large aipkit_builder_instructions_modal_textarea"
                        rows="14"
                        aria-label="<?php esc_attr_e('Agent instructions', 'gpt3-ai-content-generator'); ?>"
                    ></textarea>
                </div>
                <div class="aipkit_builder_modal_meta">
                    <span class="aipkit_builder_char_count aipkit_builder_instructions_count">
                        <?php esc_html_e('0 characters', 'gpt3-ai-content-generator'); ?>
                    </span>
                    <span class="aipkit_builder_key_hint">
                        <?php esc_html_e('Press ESC to close', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div
        class="aipkit_builder_sheet_overlay"
        id="aipkit_builder_sheet"
        aria-hidden="true"
    >
        <div
            class="aipkit_builder_sheet_panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="aipkit_builder_sheet_title"
            aria-describedby="aipkit_builder_sheet_description"
        >
            <div class="aipkit_builder_sheet_header">
                <div>
                    <div class="aipkit_builder_sheet_title_row">
                        <h3 class="aipkit_builder_sheet_title" id="aipkit_builder_sheet_title">
                            <?php esc_html_e('Sheet', 'gpt3-ai-content-generator'); ?>
                        </h3>
                    </div>
                    <p class="aipkit_builder_sheet_description" id="aipkit_builder_sheet_description">
                        <?php esc_html_e('Settings will appear here.', 'gpt3-ai-content-generator'); ?>
                    </p>
                </div>
                <button
                    type="button"
                    class="aipkit_builder_sheet_close"
                    aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="aipkit_builder_sheet_body">
                <div class="aipkit_builder_sheet_section" data-sheet="placeholder">
                    <p class="aipkit_builder_help_text">
                        <?php esc_html_e('This panel will contain the selected settings section.', 'gpt3-ai-content-generator'); ?>
                    </p>
                </div>
                <div class="aipkit_builder_sheet_section aipkit_builder_sheet_section--rules" data-sheet="triggers" hidden>
                    <span class="aipkit_popover_status_inline aipkit_triggers_status" aria-live="polite"></span>
                    <?php if ($triggers_available && $active_bot_post && $bot_id > 0) : ?>
                        <?php
                        $triggers_json = $active_bot_settings['triggers_json'] ?? '[]';
                        $trigger_builder_view_path = defined('WPAICG_LIB_DIR')
                            ? WPAICG_LIB_DIR . 'views/chatbot/trigger-builder.php'
                            : '';
                        if (!empty($trigger_builder_view_path) && file_exists($trigger_builder_view_path)) {
                            include $trigger_builder_view_path;
                        } else {
                            echo '<p class="aipkit_builder_help_text">' . esc_html__('Rules builder UI is not available.', 'gpt3-ai-content-generator') . '</p>';
                        }
                        ?>
                        <textarea
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_triggers_json"
                            name="triggers_json"
                            class="aipkit_trigger_hidden_textarea"
                            aria-hidden="true"
                            tabindex="-1"
                        ><?php echo esc_textarea($triggers_json); ?></textarea>
                        <p class="aipkit_builder_help_text">
                            <?php esc_html_e('Use the UI above to configure rules.', 'gpt3-ai-content-generator'); ?>
                            <a href="<?php echo esc_url('https://docs.aipower.org/chatbots#rules'); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Learn More', 'gpt3-ai-content-generator'); ?>
                            </a>
                        </p>
                    <?php elseif ($triggers_available) : ?>
                        <p class="aipkit_builder_help_text">
                            <?php esc_html_e('Create or select a chatbot to configure rules.', 'gpt3-ai-content-generator'); ?>
                        </p>
                    <?php else : ?>
                        <div class="aipkit_rules_promo">
                            <div class="aipkit_rules_promo_hero">
                                <span class="aipkit_rules_promo_hero_icon" aria-hidden="true">
                                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                </span>
                                <div class="aipkit_rules_promo_hero_text">
                                    <h3 class="aipkit_rules_promo_hero_title"><?php esc_html_e('Automate your chatbot with Rules', 'gpt3-ai-content-generator'); ?></h3>
                                    <p class="aipkit_rules_promo_hero_desc"><?php esc_html_e('Build event-driven workflows that respond to messages, show forms, call webhooks, and more — no code required.', 'gpt3-ai-content-generator'); ?></p>
                                </div>
                            </div>

                            <div class="aipkit_rules_promo_how">
                                <div class="aipkit_rules_promo_step">
                                    <span class="aipkit_rules_promo_step_num">1</span>
                                    <span class="aipkit_rules_promo_step_label"><?php esc_html_e('Choose a trigger', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <span class="aipkit_rules_promo_step_arrow" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                </span>
                                <div class="aipkit_rules_promo_step">
                                    <span class="aipkit_rules_promo_step_num">2</span>
                                    <span class="aipkit_rules_promo_step_label"><?php esc_html_e('Set conditions', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                                <span class="aipkit_rules_promo_step_arrow" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                </span>
                                <div class="aipkit_rules_promo_step">
                                    <span class="aipkit_rules_promo_step_num">3</span>
                                    <span class="aipkit_rules_promo_step_label"><?php esc_html_e('Pick an action', 'gpt3-ai-content-generator'); ?></span>
                                </div>
                            </div>

                            <div class="aipkit_rules_promo_grid" role="list">
                                <div class="aipkit_rules_promo_card" role="listitem">
                                    <span class="aipkit_rules_promo_card_icon aipkit_rules_promo_card_icon--triggers" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    </span>
                                    <p class="aipkit_rules_promo_card_title"><?php esc_html_e('Trigger Events', 'gpt3-ai-content-generator'); ?></p>
                                    <ul class="aipkit_rules_promo_card_list">
                                        <li><?php esc_html_e('Message received', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Session started', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Form submitted', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('System error', 'gpt3-ai-content-generator'); ?></li>
                                    </ul>
                                </div>

                                <div class="aipkit_rules_promo_card" role="listitem">
                                    <span class="aipkit_rules_promo_card_icon aipkit_rules_promo_card_icon--actions" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/></svg>
                                    </span>
                                    <p class="aipkit_rules_promo_card_title"><?php esc_html_e('Action Types', 'gpt3-ai-content-generator'); ?></p>
                                    <ul class="aipkit_rules_promo_card_list">
                                        <li><?php esc_html_e('Send reply / Show form', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Inject context', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Block message', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Set variable / Call webhook', 'gpt3-ai-content-generator'); ?></li>
                                    </ul>
                                </div>

                                <div class="aipkit_rules_promo_card" role="listitem">
                                    <span class="aipkit_rules_promo_card_icon aipkit_rules_promo_card_icon--conditions" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                    </span>
                                    <p class="aipkit_rules_promo_card_title"><?php esc_html_e('Condition Groups', 'gpt3-ai-content-generator'); ?></p>
                                    <ul class="aipkit_rules_promo_card_list">
                                        <li><?php esc_html_e('Text / keyword matching', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('User role & auth state', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Page & context filters', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Regex & numeric operators', 'gpt3-ai-content-generator'); ?></li>
                                    </ul>
                                </div>

                                <div class="aipkit_rules_promo_card" role="listitem">
                                    <span class="aipkit_rules_promo_card_icon aipkit_rules_promo_card_icon--webhooks" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                    </span>
                                    <p class="aipkit_rules_promo_card_title"><?php esc_html_e('External Workflows', 'gpt3-ai-content-generator'); ?></p>
                                    <ul class="aipkit_rules_promo_card_list">
                                        <li><?php esc_html_e('Notify Slack on demo requests', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Send leads to Make / Zapier', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Tickets, emails & follow-ups', 'gpt3-ai-content-generator'); ?></li>
                                    </ul>
                                </div>
                            </div>

                            <div class="aipkit_rules_promo_cta">
                                <a
                                    class="aipkit_rules_promo_btn aipkit_pro_upgrade_button"
                                    href="<?php echo esc_url($pricing_url); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                                </a>
                                <a
                                    class="aipkit_rules_promo_btn aipkit_rules_promo_btn--secondary"
                                    href="<?php echo esc_url('https://docs.aipower.org/chatbots#rules'); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <?php esc_html_e('Learn More', 'gpt3-ai-content-generator'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="aipkit_builder_sheet_section" data-sheet="sources" hidden>
                    <?php if ($active_bot_post) : ?>
                        <div class="aipkit_sources_sheet_toolbar">
                            <div class="aipkit_sources_toolbar">
                                <div class="aipkit_sources_toolbar_group aipkit_sources_toolbar_group--search">
                                    <input
                                        type="search"
                                        id="aipkit_chatbot_sources_search"
                                        name="aipkit_chatbot_sources_search"
                                        class="aipkit_popover_option_input aipkit_sources_search_input"
                                        placeholder="<?php esc_attr_e('Search knowledge', 'gpt3-ai-content-generator'); ?>"
                                        aria-label="<?php esc_attr_e('Search knowledge', 'gpt3-ai-content-generator'); ?>"
                                    >
                                </div>
                                <div class="aipkit_sources_toolbar_group aipkit_sources_toolbar_group--right">
                                    <select id="aipkit_chatbot_sources_type" name="aipkit_chatbot_sources_type" class="aipkit_popover_select aipkit_sources_type_filter" aria-label="<?php esc_attr_e('Filter by source type', 'gpt3-ai-content-generator'); ?>">
                                        <option value=""><?php esc_html_e('All types', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="site"><?php esc_html_e('Website', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="text"><?php esc_html_e('Text & Q&A', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="file"><?php esc_html_e('Files', 'gpt3-ai-content-generator'); ?></option>
                                    </select>
                                    <select id="aipkit_chatbot_sources_status_filter" name="aipkit_chatbot_sources_status_filter" class="aipkit_popover_select aipkit_sources_filter_select" aria-label="<?php esc_attr_e('Filter by status', 'gpt3-ai-content-generator'); ?>">
                                        <option value=""><?php esc_html_e('All statuses', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="indexed"><?php esc_html_e('Ready', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="processing"><?php esc_html_e('Processing', 'gpt3-ai-content-generator'); ?></option>
                                        <option value="failed"><?php esc_html_e('Failed', 'gpt3-ai-content-generator'); ?></option>
                                    </select>
                                    <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_refresh_btn">
                                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                        <span><?php esc_html_e('Refresh', 'gpt3-ai-content-generator'); ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p id="aipkit_sources_status" class="aipkit_form-help"></p>
                        <div class="aipkit_data-table aipkit_sources_table">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Item', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Updated', 'gpt3-ai-content-generator'); ?></th>
                                        <th class="aipkit_actions_cell_header"><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="aipkit_sources_table_body">
                                    <tr>
                                        <td colspan="4" class="aipkit_text-center">
                                            <?php esc_html_e('Train content to view it here.', 'gpt3-ai-content-generator'); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="aipkit_sources_pagination" class="aipkit_logs_pagination_container"></div>
                    <?php else : ?>
                        <p class="aipkit_builder_help_text">
                            <?php esc_html_e('Select a bot to manage sources.', 'gpt3-ai-content-generator'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php require WPAICG_PLUGIN_DIR . 'admin/views/shared/source-editor-modal.php'; ?>

    <?php if ($active_bot_post && !$aipkit_hide_custom_theme) : ?>
        <?php
        $bot_id = $initial_active_bot_id;
        $bot_settings = $active_bot_settings;
        include __DIR__ . '/custom-theme-modal.php';
        ?>
    <?php endif; ?>
</div>

<div id="aipkit_available_bots_json" class="aipkit_hidden" data-bots="<?php
    $bot_list_for_filter = [];
    if (!empty($all_bots_ordered_entries)) {
        foreach ($all_bots_ordered_entries as $bot_entry_filter) {
            $bot_list_for_filter[] = ['id' => $bot_entry_filter['post']->ID, 'title' => $bot_entry_filter['post']->post_title];
        }
    }
    echo esc_attr(wp_json_encode($bot_list_for_filter));
?>"></div>

<script type="application/json" id="aipkit_chatbot_switch_state_json"><?php
    $inline_bot_switch_payload_json = wp_json_encode(
        $inline_bot_switch_payload,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* encoded payload for an application/json script tag.
    echo $inline_bot_switch_payload_json !== false ? $inline_bot_switch_payload_json : '{}';
?></script>

<?php
$elevenlabs_voices_cached = AIPKit_Providers::get_elevenlabs_voices();
$elevenlabs_models_cached = AIPKit_Providers::get_elevenlabs_models();
?>
<?php foreach ($all_bots_ordered_entries as $bot_entry_for_json) : ?>
    <?php $bot_id_for_json = $bot_entry_for_json['post']->ID; ?>
    <div
        id="aipkit_elevenlabs_voices_json_<?php echo esc_attr($bot_id_for_json); ?>"
        class="aipkit_hidden"
        data-voices="<?php echo esc_attr(wp_json_encode($elevenlabs_voices_cached ?: [])); ?>"
    ></div>
    <div
        id="aipkit_elevenlabs_models_json_<?php echo esc_attr($bot_id_for_json); ?>"
        class="aipkit_hidden"
        data-models="<?php echo esc_attr(wp_json_encode($elevenlabs_models_cached ?: [])); ?>"
    ></div>
<?php endforeach; ?>
