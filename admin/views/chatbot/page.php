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
$consent_feature_available = $is_pro_plan && class_exists('\\WPAICG\\Lib\\Addons\\AIPKit_Consent_Compliance');
$triggers_available = $is_pro_plan;
$pricing_url = admin_url('admin.php?page=wpaicg-pricing');
$apps_logo_base_url = defined('WPAICG_PLUGIN_URL')
    ? WPAICG_PLUGIN_URL . 'admin/images/apps/'
    : '';
// Each app's logo; the Notion and n8n files are only symbols, so their names show beside them.
$connected_apps_supported_destinations = [];
foreach ([
    'slack' => [__('Slack', 'gpt3-ai-content-generator'), false],
    'hubspot' => [__('HubSpot', 'gpt3-ai-content-generator'), false],
    'notion' => [__('Notion', 'gpt3-ai-content-generator'), true],
    'pipedrive' => [__('Pipedrive', 'gpt3-ai-content-generator'), false],
    'zapier' => [__('Zapier', 'gpt3-ai-content-generator'), false],
    'make' => [__('Make', 'gpt3-ai-content-generator'), false],
    'n8n' => [__('n8n', 'gpt3-ai-content-generator'), true],
] as $aipkit_app_slug => [$aipkit_app_name, $aipkit_app_symbol]) {
    $connected_apps_supported_destinations[$aipkit_app_slug] = [
        'slug' => $aipkit_app_slug,
        'name' => $aipkit_app_name,
        'logo_url' => $apps_logo_base_url . $aipkit_app_slug . '.svg',
        'symbol' => $aipkit_app_symbol,
    ];
}
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
// The Features row names the apps that get this chatbot's chats.
$connected_apps_summary_text = $is_pro_plan
    ? sanitize_text_field((string) ($active_chatbot_connected_apps['apps_summary'] ?? ''))
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
                <?php // Name and tabs head the settings frame and stay in view while the page scrolls. ?>
                <?php if ($active_bot_post) : ?>
                <div class="aipkit_chatbot_workspace_bar">
                <div class="aipkit_chatbot_workspace_header">
                    <div class="aipkit_chatbot_workspace_heading">
                        <?php // The bot name opens one menu: switch chatbots, then act on the open one. ?>
                        <div class="aipkit_widget_bot_actions aipkit_chatbot_identity" data-aipkit-bot-actions>
                            <h1 class="aipkit_chatbot_workspace_title">
                                <button
                                    type="button"
                                    class="aipkit_chatbot_identity_trigger"
                                    data-aipkit-bot-actions-toggle
                                    aria-haspopup="menu"
                                    aria-expanded="false"
                                    aria-controls="aipkit_widget_bot_actions_menu"
                                >
                                    <span
                                        class="aipkit_chatbot_identity_name"
                                        data-aipkit-bot-switcher-name
                                        data-empty-label="<?php esc_attr_e('No chatbots yet', 'gpt3-ai-content-generator'); ?>"
                                    ><?php echo esc_html($active_bot_post ? $active_bot_post->post_title : __('No chatbots yet', 'gpt3-ai-content-generator')); ?></span>
                                    <svg class="aipkit_chatbot_identity_chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"></path></svg>
                                </button>
                            </h1>
                            <div
                                class="aipkit_widget_bot_actions_menu aipkit_chatbot_identity_menu"
                                id="aipkit_widget_bot_actions_menu"
                                data-aipkit-bot-actions-menu
                                role="menu"
                                hidden
                            >
                                <p class="aipkit_chatbot_identity_menu_label"><?php esc_html_e('Your chatbots', 'gpt3-ai-content-generator'); ?></p>
                                <div class="aipkit_chatbot_identity_bots" data-aipkit-bot-switcher-list></div>
                                <button type="button" class="aipkit_widget_bot_actions_item aipkit_chatbot_identity_new aipkit_builder_new_bot_btn" role="menuitem">
                                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                    <span class="aipkit_widget_bot_actions_label"><?php esc_html_e('New chatbot', 'gpt3-ai-content-generator'); ?></span>
                                </button>
                                <p class="aipkit_chatbot_identity_menu_label aipkit_chatbot_identity_menu_label--section"><?php esc_html_e('This chatbot', 'gpt3-ai-content-generator'); ?></p>
                                <button
                                    type="button"
                                    class="aipkit_widget_bot_actions_item aipkit_chatbot_identity_shortcode"
                                    data-aipkit-shortcode-copy
                                    data-shortcode="<?php echo esc_attr($shortcode_text); ?>"
                                    role="menuitem"
                                >
                                    <span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
                                    <span class="aipkit_chatbot_identity_shortcode_copy">
                                        <span class="aipkit_widget_bot_actions_label"><?php esc_html_e('Copy shortcode', 'gpt3-ai-content-generator'); ?></span>
                                        <span class="aipkit_shortcode_text"><?php echo esc_html($shortcode_text); ?></span>
                                    </span>
                                </button>
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
                        <?php // Shown only while Popup and Every page are both on; CSS follows the switches without script. ?>
                        <span class="aipkit_chatbot_status"><?php esc_html_e('Live', 'gpt3-ai-content-generator'); ?></span>
                    </div>
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
                </div>
                <nav class="aipkit_chatbot_tabs" role="tablist" aria-label="<?php esc_attr_e('Chatbot settings', 'gpt3-ai-content-generator'); ?>" data-aipkit-chatbot-tabs>
                    <button
                        type="button"
                        class="aipkit_chatbot_tab is-active"
                        id="aipkit_chatbot_tab_answers"
                        role="tab"
                        aria-selected="true"
                        aria-controls="aipkit_chatbot_tab_panel_answers"
                        data-aipkit-chatbot-tab="answers"
                        tabindex="0"
                    >
                        <svg class="aipkit_chatbot_tab_icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"></path></svg>
                        <span><?php esc_html_e('Answers', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button
                        type="button"
                        class="aipkit_chatbot_tab"
                        id="aipkit_chatbot_tab_look"
                        role="tab"
                        aria-selected="false"
                        aria-controls="aipkit_chatbot_tab_panel_look"
                        data-aipkit-chatbot-tab="look"
                        tabindex="-1"
                    >
                        <svg class="aipkit_chatbot_tab_icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><circle cx="8" cy="10" r="1.2"></circle><circle cx="12" cy="7.5" r="1.2"></circle><circle cx="16" cy="10" r="1.2"></circle><path d="M12 21a3 3 0 0 1 0-6h2a3 3 0 0 0 0-6"></path></svg>
                        <span><?php esc_html_e('Look', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button
                        type="button"
                        class="aipkit_chatbot_tab"
                        id="aipkit_chatbot_tab_features"
                        role="tab"
                        aria-selected="false"
                        aria-controls="aipkit_chatbot_tab_panel_features"
                        data-aipkit-chatbot-tab="features"
                        tabindex="-1"
                    >
                        <svg class="aipkit_chatbot_tab_icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 4h4v2a2 2 0 1 0 4 0V4h3v6h-2a2 2 0 1 0 0 4h2v6h-6v-2a2 2 0 1 0-4 0v2H4v-6h2a2 2 0 1 0 0-4H4V4z"></path></svg>
                        <span><?php esc_html_e('Features', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button
                        type="button"
                        class="aipkit_chatbot_tab"
                        id="aipkit_chatbot_tab_publish"
                        role="tab"
                        aria-selected="false"
                        aria-controls="aipkit_chatbot_tab_panel_publish"
                        data-aipkit-chatbot-tab="publish"
                        tabindex="-1"
                    >
                        <svg class="aipkit_chatbot_tab_icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2M14 4c3-1 6-1 6-1s0 3-1 6l-7 7-5-5z"></path><circle cx="15" cy="9" r="1.5"></circle></svg>
                        <span><?php esc_html_e('Publish', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                </nav>
                </div>
                <?php endif; ?>
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
                            <section class="aipkit_builder_card aipkit_builder_card--settings aipkit_chatbot_settings_panel aipkit_chatbot_tab_panels" id="aipkit_chatbot_settings_panel">
                                <?php
                                $bot_id = $initial_active_bot_id;
                                $bot_settings = $active_bot_settings;
                                $active_bot_name_value = ($active_bot_post && isset($active_bot_post->post_title))
                                    ? (string) $active_bot_post->post_title
                                    : '';
                                ?>
                                <?php include __DIR__ . '/feature-panels.php'; ?>
                                <div
                                    class="aipkit_chatbot_tab_panel aipkit_chatbot_tab_panel--answers"
                                    id="aipkit_chatbot_tab_panel_answers"
                                    role="tabpanel"
                                    aria-labelledby="aipkit_chatbot_tab_answers"
                                    data-aipkit-chatbot-tab-panel="answers"
                                    tabindex="0"
                                >
                                    <?php // Rows open their settings beside the preview, as on the other tabs. ?>
                                    <div class="aipkit_answers" data-aipkit-feature-panels>
                                    <section class="aipkit_settings_card aipkit_feature_card aipkit_settings_card--ai-model" aria-labelledby="aipkit_answers_model_title">
                                        <div class="aipkit_settings_card_header">
                                            <h3 class="aipkit_settings_card_title" id="aipkit_answers_model_title"><?php esc_html_e('AI model', 'gpt3-ai-content-generator'); ?></h3>
                                            <p class="aipkit_settings_card_hint"><?php esc_html_e('The brain behind the answers.', 'gpt3-ai-content-generator'); ?></p>
                                        </div>
                                        <div class="aipkit_settings_card_body">
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
                                        </div>
                                    </section>
                                    <section class="aipkit_settings_card aipkit_settings_card--instructions" aria-labelledby="aipkit_answers_instructions_title">
                                        <div class="aipkit_settings_card_header">
                                            <h3 class="aipkit_settings_card_title" id="aipkit_answers_instructions_title"><?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?></h3>
                                            <p class="aipkit_settings_card_hint"><?php esc_html_e('Tell your chatbot who it is and how to help.', 'gpt3-ai-content-generator'); ?></p>
                                        </div>
                                        <div class="aipkit_settings_card_body">
                                            <div class="aipkit_builder_field aipkit_chatbot_quick_instructions">
                                                <label for="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_instructions" class="aipkit_builder_label screen-reader-text">
                                                    <?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?>
                                                </label>
                                                <textarea
                                                    id="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_instructions"
                                                    name="instructions"
                                                    class="aipkit_builder_textarea aipkit_form-input"
                                                    rows="4"
                                                    placeholder="<?php esc_attr_e('e.g., You are a helpful AI Assistant. Please be friendly.', 'gpt3-ai-content-generator'); ?>"
                                                ><?php echo esc_textarea($active_bot_instructions); ?></textarea>
                                                <?php // Shows in the field's corner while the pointer or focus is in it. ?>
                                                <button
                                                    type="button"
                                                    class="aipkit_instructions_expand aipkit_builder_instructions_expand"
                                                    aria-label="<?php esc_attr_e('Open larger editor', 'gpt3-ai-content-generator'); ?>"
                                                    title="<?php esc_attr_e('Open larger editor', 'gpt3-ai-content-generator'); ?>"
                                                >
                                                    <span class="dashicons dashicons-editor-expand" aria-hidden="true"></span>
                                                </button>
                                            </div>
                                            <?php // Answer style is a quiet line under the words that also shape tone and length; it opens its side panel. ?>
                                            <div class="aipkit_instructions_style" data-aipkit-feature-row="answer">
                                                <button
                                                    type="button"
                                                    class="aipkit_line_link"
                                                    data-aipkit-feature-open="answer"
                                                    aria-haspopup="dialog"
                                                    aria-expanded="false"
                                                    aria-controls="aipkit_feature_drawer_answer"
                                                >
                                                    <span class="aipkit_line_link_icon dashicons dashicons-admin-settings" aria-hidden="true"></span>
                                                    <span class="aipkit_line_link_text">
                                                        <span class="aipkit_line_link_label"><?php esc_html_e('Answer style:', 'gpt3-ai-content-generator'); ?></span>
                                                        <span data-aipkit-feature-hint><?php esc_html_e('Creativity, answer length and memory.', 'gpt3-ai-content-generator'); ?></span>
                                                    </span>
                                                    <span class="aipkit_line_link_action">
                                                        <span><?php esc_html_e('Change', 'gpt3-ai-content-generator'); ?></span>
                                                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </section>
                                    <?php
                                    // Knowledge sits with the model and instructions; Search settings opens a side panel.
                                    $aipkit_knowledge_search_settings = true;
                                    include WPAICG_PLUGIN_DIR . 'admin/views/chatbot/knowledge.php';
                                    ?>
                                    <?php $render_drawer_start('search', __('Search settings', 'gpt3-ai-content-generator'), __("What answers draw on and how knowledge is searched.", 'gpt3-ai-content-generator')); ?>
                                        <div class="aipkit_general_knowledge_section aipkit_settings_panel_body" data-aipkit-settings-panel="context">
                                            <?php include __DIR__ . '/context.php'; ?>
                                        </div>
                                    <?php $render_drawer_end(); ?>
                                    <?php $render_drawer_start('answer', __('Answer style', 'gpt3-ai-content-generator'), __('How it sounds. The defaults suit most sites.', 'gpt3-ai-content-generator')); ?>
                                        <?php include __DIR__ . '/response-settings.php'; ?>
                                    <?php $render_drawer_end(); ?>
                                    </div>
                                </div>
                                <div
                                    class="aipkit_chatbot_tab_panel aipkit_chatbot_tab_panel--look"
                                    id="aipkit_chatbot_tab_panel_look"
                                    role="tabpanel"
                                    aria-labelledby="aipkit_chatbot_tab_look"
                                    data-aipkit-chatbot-tab-panel="look"
                                    tabindex="0"
                                    hidden
                                >
                                    <?php include __DIR__ . '/display-settings.php'; ?>
                                </div>
                                <div
                                    class="aipkit_chatbot_tab_panel aipkit_chatbot_tab_panel--features"
                                    id="aipkit_chatbot_tab_panel_features"
                                    role="tabpanel"
                                    aria-labelledby="aipkit_chatbot_tab_features"
                                    data-aipkit-chatbot-tab-panel="features"
                                    tabindex="0"
                                    hidden
                                >
                                    <div class="aipkit_settings_panel_body aipkit_settings_card_stack" data-aipkit-settings-panel="chatbot">
                                        <?php
                                        $aipkit_behavior_section_keys = ['features'];
                                        include __DIR__ . '/behavior-settings.php';
                                        ?>
                                    </div>
                                </div>
                                <div
                                    class="aipkit_chatbot_tab_panel aipkit_chatbot_tab_panel--publish"
                                    id="aipkit_chatbot_tab_panel_publish"
                                    role="tabpanel"
                                    aria-labelledby="aipkit_chatbot_tab_publish"
                                    data-aipkit-chatbot-tab-panel="publish"
                                    tabindex="0"
                                    hidden
                                >
                                    <?php // Publish: where it shows and its one next step, then usage limits and other websites as rows with side panels. ?>
                                    <div class="aipkit_publish" data-aipkit-feature-panels>
                                        <?php
                                        // One choice drives the Popup and Every page fields that deployment saves.
                                        $aipkit_publish_placement = !$quick_popup_enabled ? 'inline' : ($quick_site_wide_enabled ? 'everywhere' : 'chosen');
                                        $aipkit_publish_placements = [
                                            'everywhere' => [__('Every page', 'gpt3-ai-content-generator'), __('A chat button on your whole site.', 'gpt3-ai-content-generator'), ['back', 'mid', 'front'], true],
                                            'chosen' => [__('Pages I choose', 'gpt3-ai-content-generator'), __('A chat button where you add it.', 'gpt3-ai-content-generator'), ['aside', 'front'], true],
                                            'inline' => [__('Inside a page', 'gpt3-ai-content-generator'), __('The chat sits in your content.', 'gpt3-ai-content-generator'), ['center'], false],
                                        ];
                                        ?>
                                        <section class="aipkit_settings_card" aria-labelledby="aipkit_publish_where_title" data-aipkit-placement>
                                            <div class="aipkit_settings_card_header">
                                                <h3 class="aipkit_settings_card_title" id="aipkit_publish_where_title"><?php esc_html_e('Where it shows', 'gpt3-ai-content-generator'); ?></h3>
                                                <p class="aipkit_settings_card_hint"><?php esc_html_e('Pick one. You can change it any time.', 'gpt3-ai-content-generator'); ?></p>
                                            </div>
                                            <div class="aipkit_publish_tiles" role="radiogroup" aria-labelledby="aipkit_publish_where_title">
                                                <?php foreach ($aipkit_publish_placements as $aipkit_placement_value => [$aipkit_placement_title, $aipkit_placement_hint, $aipkit_placement_pages, $aipkit_placement_button]) : ?>
                                                    <label class="aipkit_publish_tile">
                                                        <input type="radio" class="aipkit_placement_radio" name="aipkit_ui_publish_placement" value="<?php echo esc_attr($aipkit_placement_value); ?>" <?php checked($aipkit_publish_placement, $aipkit_placement_value); ?> />
                                                        <?php // A small picture: pages with the chat button, or the chat inside a page. ?>
                                                        <span class="aipkit_publish_art" aria-hidden="true">
                                                            <?php foreach ($aipkit_placement_pages as $aipkit_page_index => $aipkit_page_position) : ?>
                                                                <span class="aipkit_publish_page aipkit_publish_page--<?php echo esc_attr($aipkit_page_position); ?>">
                                                                    <?php if (!$aipkit_placement_button) : ?>
                                                                        <span class="aipkit_publish_block"></span>
                                                                    <?php elseif ($aipkit_page_position !== 'aside') : ?>
                                                                        <span class="aipkit_publish_dot"></span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </span>
                                                        <span class="aipkit_publish_tile_copy">
                                                            <span class="aipkit_publish_tile_title"><?php echo esc_html($aipkit_placement_title); ?></span>
                                                            <span class="aipkit_publish_tile_hint"><?php echo esc_html($aipkit_placement_hint); ?></span>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="aipkit_publish_live" data-aipkit-placement-note="everywhere">
                                                <span class="aipkit_publish_live_dot" aria-hidden="true"></span>
                                                <span class="aipkit_publish_live_text"><strong><?php esc_html_e('Live on every page.', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Visitors see the chat button.', 'gpt3-ai-content-generator'); ?></span>
                                                <a class="aipkit_publish_live_link" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php esc_html_e('View your site', 'gpt3-ai-content-generator'); ?>
                                                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                                </a>
                                            </div>
                                            <?php // Pages I choose and Inside a page: the two ways to add it, worded for each. ?>
                                            <div class="aipkit_publish_add" data-aipkit-placement-note="chosen inline">
                                                <p class="aipkit_publish_add_lead">
                                                    <span data-aipkit-placement-note="chosen"><?php esc_html_e('Add it to each page that should show the button.', 'gpt3-ai-content-generator'); ?></span>
                                                    <span data-aipkit-placement-note="inline"><?php esc_html_e('Put it where the chat should sit.', 'gpt3-ai-content-generator'); ?></span>
                                                </p>
                                                <div class="aipkit_publish_way">
                                                    <span class="aipkit_feature_icon dashicons dashicons-block-default" aria-hidden="true"></span>
                                                    <span class="aipkit_publish_way_copy">
                                                        <span class="aipkit_publish_way_title"><?php esc_html_e('In the block editor', 'gpt3-ai-content-generator'); ?></span>
                                                        <span class="aipkit_publish_way_hint">
                                                            <?php
                                                            echo wp_kses(
                                                                /* translators: %s: the block's name, AI Puffer Chatbot. */
                                                                sprintf(esc_html__('Add the %s block and pick this chatbot.', 'gpt3-ai-content-generator'), '<strong>' . esc_html__('AI Puffer Chatbot', 'gpt3-ai-content-generator') . '</strong>'),
                                                                ['strong' => []]
                                                            );
                                                            ?>
                                                        </span>
                                                    </span>
                                                </div>
                                                <div class="aipkit_publish_way">
                                                    <span class="aipkit_feature_icon dashicons dashicons-shortcode" aria-hidden="true"></span>
                                                    <span class="aipkit_publish_way_copy">
                                                        <span class="aipkit_publish_way_title"><?php esc_html_e('Anywhere else', 'gpt3-ai-content-generator'); ?></span>
                                                        <span class="aipkit_publish_shortcode">
                                                            <button
                                                                type="button"
                                                                class="aipkit_builder_shortcode_pill aipkit_publish_shortcode_value"
                                                                data-aipkit-shortcode-copy
                                                                data-shortcode="<?php echo esc_attr($shortcode_text); ?>"
                                                                title="<?php esc_attr_e('Click to copy shortcode', 'gpt3-ai-content-generator'); ?>"
                                                            >
                                                                <span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
                                                                <span class="aipkit_shortcode_text"><?php echo esc_html($shortcode_text); ?></span>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="aipkit_publish_shortcode_copy"
                                                                data-aipkit-shortcode-copy
                                                                data-shortcode="<?php echo esc_attr($shortcode_text); ?>"
                                                                title="<?php esc_attr_e('Copy shortcode', 'gpt3-ai-content-generator'); ?>"
                                                            >
                                                                <span class="aipkit_shortcode_text screen-reader-text"><?php echo esc_html($shortcode_text); ?></span>
                                                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                                                <?php esc_html_e('Copy', 'gpt3-ai-content-generator'); ?>
                                                            </button>
                                                        </span>
                                                        <span class="aipkit_publish_way_hint"><?php esc_html_e('Pages, posts, widgets and page builders.', 'gpt3-ai-content-generator'); ?></span>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php // Another website works alongside any choice above, so it is an "also" line, not a fourth choice. ?>
                                            <div class="aipkit_publish_also" data-aipkit-feature-row="embed">
                                                <button
                                                    type="button"
                                                    class="aipkit_line_link"
                                                    data-aipkit-feature-open="embed"
                                                    aria-haspopup="dialog"
                                                    aria-expanded="false"
                                                    aria-controls="aipkit_feature_drawer_embed"
                                                >
                                                    <span class="aipkit_line_link_icon dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                                                    <span class="aipkit_line_link_text">
                                                        <span class="aipkit_line_link_label"><?php esc_html_e('Also on another website', 'gpt3-ai-content-generator'); ?></span>
                                                        <span><?php esc_html_e('Shopify, Wix or any HTML page', 'gpt3-ai-content-generator'); ?></span>
                                                    </span>
                                                    <span class="aipkit_line_link_action">
                                                        <span><?php $embed_anywhere_active ? esc_html_e('Get the code', 'gpt3-ai-content-generator') : esc_html_e('Learn more', 'gpt3-ai-content-generator'); ?></span>
                                                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                                                    </span>
                                                </button>
                                            </div>
                                        </section>

                                        <?php
                                        $aipkit_behavior_section_keys = ['limits'];
                                        include __DIR__ . '/behavior-settings.php';
                                        ?>

                                        <?php $render_drawer_start('embed', __('On another website', 'gpt3-ai-content-generator'), __('Show this chatbot on a site outside WordPress.', 'gpt3-ai-content-generator')); ?>
                                            <?php if ($embed_anywhere_active) : ?>
                                                <?php // Which sites may use it comes first: until one is listed, any site can use the chatbot and its credits. ?>
                                                <div class="aipkit_panel_stack aipkit_embed_setup">
                                                    <section class="aipkit_panel_section" aria-labelledby="aipkit_embed_sites_title">
                                                        <h4 class="aipkit_panel_section_title" id="aipkit_embed_sites_title"><?php esc_html_e('1 · Which websites can use it', 'gpt3-ai-content-generator'); ?></h4>
                                                        <label class="aipkit_embed_hint" for="aipkit_embed_allowed_domains_<?php echo esc_attr($initial_active_bot_id); ?>"><?php esc_html_e('One address per line.', 'gpt3-ai-content-generator'); ?></label>
                                                        <textarea
                                                            id="aipkit_embed_allowed_domains_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                            name="embed_allowed_domains"
                                                            class="aipkit_look_input aipkit_publish_domains"
                                                            placeholder="<?php esc_attr_e('https://your-shop.com', 'gpt3-ai-content-generator'); ?>"
                                                        ><?php echo esc_textarea($embed_allowed_domains); ?></textarea>
                                                        <p class="aipkit_publish_tip" data-aipkit-embed-domains-note="open"<?php echo trim((string) $embed_allowed_domains) !== '' ? ' hidden' : ''; ?>>
                                                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                                            <span><?php echo wp_kses(__('<strong>Any website can use it now</strong>, and your credits. Add the address of the site you paste it on.', 'gpt3-ai-content-generator'), ['strong' => []]); ?></span>
                                                        </p>
                                                        <p class="aipkit_embed_ok" data-aipkit-embed-domains-note="listed"<?php echo trim((string) $embed_allowed_domains) === '' ? ' hidden' : ''; ?>>
                                                            <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                                            <span><?php esc_html_e('Only the websites listed here can use it.', 'gpt3-ai-content-generator'); ?></span>
                                                        </p>
                                                    </section>
                                                    <section class="aipkit_panel_section" aria-labelledby="aipkit_embed_code_title">
                                                        <h4 class="aipkit_panel_section_title" id="aipkit_embed_code_title"><?php esc_html_e('2 · Copy the code', 'gpt3-ai-content-generator'); ?></h4>
                                                        <span class="aipkit_publish_code">
                                                            <textarea
                                                                id="aipkit_embed_code_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                                class="aipkit_publish_code_text"
                                                                aria-labelledby="aipkit_embed_code_title"
                                                                readonly
                                                            ><?php echo esc_textarea($embed_code); ?></textarea>
                                                            <button
                                                                type="button"
                                                                class="aipkit_btn aipkit_btn-primary aipkit_copy_embed_code_btn"
                                                                data-target="aipkit_embed_code_<?php echo esc_attr($initial_active_bot_id); ?>"
                                                            >
                                                                <?php esc_html_e('Copy code', 'gpt3-ai-content-generator'); ?>
                                                            </button>
                                                        </span>
                                                        <p class="aipkit_embed_hint"><?php esc_html_e('It uses this chatbot’s look, knowledge and limits. Change them here and the other site follows.', 'gpt3-ai-content-generator'); ?></p>
                                                    </section>
                                                    <section class="aipkit_panel_section" aria-labelledby="aipkit_embed_paste_title">
                                                        <h4 class="aipkit_panel_section_title" id="aipkit_embed_paste_title"><?php esc_html_e('3 · Paste it on your site', 'gpt3-ai-content-generator'); ?></h4>
                                                        <div class="aipkit_embed_paste">
                                                            <p class="aipkit_embed_paste_lead"><?php echo wp_kses(__('Paste it just before <code>&lt;/body&gt;</code>, or in your site builder’s custom code box.', 'gpt3-ai-content-generator'), ['code' => []]); ?></p>
                                                            <?php
                                                            // Where each builder keeps its custom code box; menu names as the builders show them.
                                                            foreach ([
                                                                __('Shopify', 'gpt3-ai-content-generator') => __('Online Store → Themes → … → Edit code. Open theme.liquid and paste it just above </body>, then save.', 'gpt3-ai-content-generator'),
                                                                __('Wix', 'gpt3-ai-content-generator') => __('Settings → Advanced → Custom code → Add custom code. Paste it, choose All pages and Body - end, then apply.', 'gpt3-ai-content-generator'),
                                                                __('Webflow', 'gpt3-ai-content-generator') => __('Site settings → Custom code. Paste it in Footer code, save, then publish the site.', 'gpt3-ai-content-generator'),
                                                                __('Squarespace', 'gpt3-ai-content-generator') => __('Open Code injection (in Settings or Website tools), paste it in Footer, then save.', 'gpt3-ai-content-generator'),
                                                                __('Any HTML page', 'gpt3-ai-content-generator') => __('Paste it just above </body> on every page that should show the chat.', 'gpt3-ai-content-generator'),
                                                            ] as $aipkit_embed_builder => $aipkit_embed_steps) :
                                                                ?>
                                                                <details class="aipkit_embed_paste_item">
                                                                    <summary><?php echo esc_html($aipkit_embed_builder); ?><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>
                                                                    <p><?php echo esc_html($aipkit_embed_steps); ?></p>
                                                                </details>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </section>
                                                </div>
                                            <?php else : ?>
                                                <?php // Free plans see what Pro adds here; the code and the allowed websites come with Pro. ?>
                                                <div class="aipkit_panel_stack">
                                                    <div class="aipkit_embed_picture" aria-hidden="true">
                                                        <span class="aipkit_embed_picture_window">
                                                            <span class="aipkit_embed_picture_bar"><span class="aipkit_embed_picture_address">shop.example.com</span></span>
                                                            <span class="aipkit_embed_picture_line"></span>
                                                            <span class="aipkit_embed_picture_line aipkit_embed_picture_line--short"></span>
                                                            <span class="aipkit_embed_picture_chat">
                                                                <span class="aipkit_embed_picture_bubble"><?php esc_html_e('Need help? Ask me!', 'gpt3-ai-content-generator'); ?></span>
                                                                <span class="aipkit_embed_picture_button dashicons dashicons-format-chat"></span>
                                                            </span>
                                                        </span>
                                                    </div>
                                                    <div class="aipkit_panel_pro">
                                                        <span class="aipkit_panel_pro_title">
                                                            <?php esc_html_e('Your chatbot, on any website', 'gpt3-ai-content-generator'); ?>
                                                            <span class="aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                                                        </span>
                                                        <span class="aipkit_panel_pro_text"><?php esc_html_e('Copy one script tag into Shopify, Wix, Webflow or any HTML page. Nothing else to install.', 'gpt3-ai-content-generator'); ?></span>
                                                    </div>
                                                    <section class="aipkit_panel_section" aria-labelledby="aipkit_embed_gets_title">
                                                        <h4 class="aipkit_panel_section_title" id="aipkit_embed_gets_title"><?php esc_html_e('What you get', 'gpt3-ai-content-generator'); ?></h4>
                                                        <div class="aipkit_panel_list">
                                                            <?php
                                                            foreach ([
                                                                ['format-chat', __('The same chatbot', 'gpt3-ai-content-generator'), __('Same answers, knowledge and look as on this site. Change it here; it changes there.', 'gpt3-ai-content-generator')],
                                                                ['shield', __('Only your websites', 'gpt3-ai-content-generator'), __('Choose which addresses can use it, so nobody else spends your credits.', 'gpt3-ai-content-generator')],
                                                                ['chart-bar', __('The same limits', 'gpt3-ai-content-generator'), __('The usage limits you set here apply there too.', 'gpt3-ai-content-generator')],
                                                            ] as [$aipkit_embed_icon, $aipkit_embed_title, $aipkit_embed_text]) :
                                                                ?>
                                                                <div class="aipkit_panel_row">
                                                                    <div class="aipkit_panel_row_main">
                                                                        <span class="aipkit_rule_icon dashicons dashicons-<?php echo esc_attr($aipkit_embed_icon); ?>" aria-hidden="true"></span>
                                                                        <span class="aipkit_panel_row_copy">
                                                                            <span class="aipkit_panel_row_title"><?php echo esc_html($aipkit_embed_title); ?></span>
                                                                            <span class="aipkit_panel_row_meta"><?php echo esc_html($aipkit_embed_text); ?></span>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </section>
                                                    <p class="aipkit_embed_hint aipkit_embed_locked">
                                                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                                                        <span><?php esc_html_e('The code and allowed websites appear here after you upgrade.', 'gpt3-ai-content-generator'); ?></span>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        <?php
                                        $embed_anywhere_active
                                            ? $render_drawer_end()
                                            : $render_drawer_end(__('Included in every Pro plan.', 'gpt3-ai-content-generator'), ['label' => __('Upgrade to Pro', 'gpt3-ai-content-generator'), 'url' => $pricing_url]);
                                        ?>
                                    </div>
                                </div>
                            </section>
                            </div>
                        <?php endif; ?>

                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aipkit_chatbot-preview-column aipkit_chatbot_builder_right">
                <div class="aipkit_preview_deploy_bar">
                    <h2 class="aipkit_preview_heading"><?php esc_html_e('Preview', 'gpt3-ai-content-generator'); ?></h2>
                    <div class="aipkit_segmented aipkit_preview_device" role="group" aria-label="<?php esc_attr_e('Preview size', 'gpt3-ai-content-generator'); ?>" data-aipkit-preview-device>
                        <button type="button" class="aipkit_segmented_option" data-device="desktop" aria-pressed="true">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="12" rx="2"></rect><path d="M8 20h8M12 16v4"></path></svg>
                            <?php esc_html_e('Desktop', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_segmented_option" data-device="mobile" aria-pressed="false">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="7" y="3" width="10" height="18" rx="2"></rect><path d="M11 18h2"></path></svg>
                            <?php esc_html_e('Mobile', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                    <?php // Saved by deployment; Publish's "Where should it appear?" sets them, so they stay out of view here. ?>
                    <div class="aipkit_preview_deploy_fields" hidden>
                        <div class="aipkit_preview_deploy_item aipkit_preview_deploy_item--toggle">
                            <label class="aipkit_preview_deploy_toggle" for="aipkit_builder_top_popup_toggle">
                                <span class="aipkit_preview_deploy_toggle_copy">
                                    <span class="aipkit_preview_deploy_label">
                                        <?php esc_html_e('Popup', 'gpt3-ai-content-generator'); ?>
                                    </span>
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
                                        <?php esc_html_e('Every page', 'gpt3-ai-content-generator'); ?>
                                    </span>
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
                </div>
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
        <div class="aipkit_inline_settings_body">
            <?php
            $bot_id = $initial_active_bot_id;
            $bot_settings = $active_bot_settings;
            $conversation_starters = $bot_settings['conversation_starters'] ?? [];
            $conversation_starters_text = implode("\n", $conversation_starters);
            ?>
            <?php
            // Starter ideas a visitor might tap first; each adds itself as a question.
            $aipkit_starter_ideas = [
                __('What can you help me with?', 'gpt3-ai-content-generator'),
                __('How do I contact you?', 'gpt3-ai-content-generator'),
                __('What are your opening hours?', 'gpt3-ai-content-generator'),
                __('How long does shipping take?', 'gpt3-ai-content-generator'),
                __('What is your return policy?', 'gpt3-ai-content-generator'),
                __('Can I talk to a person?', 'gpt3-ai-content-generator'),
            ];
            ?>
            <?php // The list edits the saved field below it, one question per line. ?>
            <div
                class="aipkit_starter_editor"
                data-aipkit-starter-editor
                data-max="6"
                data-question-label="<?php /* translators: %d: position of a suggested question in the list. */ esc_attr_e('Question %d', 'gpt3-ai-content-generator'); ?>"
                data-move-label="<?php /* translators: %d: position of a suggested question in the list. */ esc_attr_e('Move question %d. Use the up and down arrow keys.', 'gpt3-ai-content-generator'); ?>"
                data-remove-label="<?php /* translators: %d: position of a suggested question in the list. */ esc_attr_e('Remove question %d', 'gpt3-ai-content-generator'); ?>"
            >
                <div class="aipkit_starter_editor_head">
                    <span class="aipkit_starter_editor_title"><?php esc_html_e('Questions', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_starter_editor_count" data-aipkit-starter-count aria-live="polite"></span>
                </div>
                <ol class="aipkit_starter_list" data-aipkit-starter-list></ol>
                <button type="button" class="aipkit_starter_add" data-aipkit-starter-add>
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <span class="aipkit_starter_add_label"><?php esc_html_e('Add a question', 'gpt3-ai-content-generator'); ?></span>
                </button>
                <div class="aipkit_starter_ideas" data-aipkit-starter-ideas>
                    <span class="aipkit_starter_ideas_title"><?php esc_html_e('Ideas', 'gpt3-ai-content-generator'); ?></span>
                    <div class="aipkit_starter_idea_chips">
                        <?php foreach ($aipkit_starter_ideas as $aipkit_starter_idea) : ?>
                            <button type="button" class="aipkit_starter_idea" data-aipkit-starter-idea="<?php echo esc_attr($aipkit_starter_idea); ?>">
                                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                <?php echo esc_html($aipkit_starter_idea); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <template data-aipkit-starter-template>
                    <li class="aipkit_starter_item">
                        <button type="button" class="aipkit_starter_handle" data-aipkit-starter-handle title="<?php esc_attr_e('Drag to reorder', 'gpt3-ai-content-generator'); ?>">
                            <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                        </button>
                        <input
                            type="text"
                            class="aipkit_starter_input"
                            data-aipkit-starter-input
                            placeholder="<?php esc_attr_e('Type a question', 'gpt3-ai-content-generator'); ?>"
                            autocomplete="off"
                            data-lpignore="true"
                            data-1p-ignore="true"
                            data-form-type="other"
                        />
                        <button type="button" class="aipkit_starter_remove" data-aipkit-starter-remove title="<?php esc_attr_e('Remove', 'gpt3-ai-content-generator'); ?>">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </li>
                </template>
                <textarea
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_conversation_starters"
                    name="conversation_starters"
                    class="aipkit_starter_source"
                    aria-hidden="true"
                    tabindex="-1"
                    hidden
                ><?php echo esc_textarea($conversation_starters_text); ?></textarea>
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
    <?php
    // The larger editor: the instructions, variables to add at the cursor, and templates to read before using.
    $aipkit_instruction_site = wp_strip_all_tags((string) get_bloginfo('name')) ?: __('this website', 'gpt3-ai-content-generator');
    $aipkit_instruction_variables = [
        '[date]' => [
            'label' => __("Today's date", 'gpt3-ai-content-generator'),
            'title' => __('The date when the chatbot answers.', 'gpt3-ai-content-generator'),
        ],
        '[username]' => [
            'label' => __("Visitor's username", 'gpt3-ai-content-generator'),
            'title' => __("The visitor's WordPress username; blank for visitors who aren't logged in.", 'gpt3-ai-content-generator'),
        ],
    ];
    $aipkit_instruction_templates = [
        'default' => [
            'icon' => 'smiley',
            'name' => __('Friendly helper', 'gpt3-ai-content-generator'),
            'hint' => __('The general start every new chatbot gets.', 'gpt3-ai-content-generator'),
            'text' => BotSettingsManager::DEFAULT_INSTRUCTIONS,
        ],
        'support' => [
            'icon' => 'format-chat',
            'name' => __('Customer support', 'gpt3-ai-content-generator'),
            'hint' => __('Answers from what it knows; offers your team when unsure.', 'gpt3-ai-content-generator'),
            /* translators: %s: site name. */
            'text' => sprintf(__('You are the friendly support assistant for %s. Answer questions using the knowledge you have been given. If you are not sure, say so and offer to connect the visitor with our team. Keep answers short and warm.', 'gpt3-ai-content-generator'), $aipkit_instruction_site),
        ],
        'sales' => [
            'icon' => 'tag',
            'name' => __('Sales assistant', 'gpt3-ai-content-generator'),
            'hint' => __('Helps visitors choose; never invents prices or offers.', 'gpt3-ai-content-generator'),
            /* translators: %s: site name. */
            'text' => sprintf(__('You are a helpful sales assistant for %s. Help visitors find the right product or service and answer questions about features, prices and availability using the knowledge you have been given. Suggest options that fit what they need, and never invent prices or offers.', 'gpt3-ai-content-generator'), $aipkit_instruction_site),
        ],
        'knowledge' => [
            'icon' => 'book',
            'name' => __('Knowledge only', 'gpt3-ai-content-generator'),
            'hint' => __("Answers only from what you add; says when it doesn't know.", 'gpt3-ai-content-generator'),
            /* translators: %s: site name. */
            'text' => sprintf(__("You answer questions about %s using only the knowledge you have been given. If the answer is not there, say you don't know and suggest contacting us. Never guess.", 'gpt3-ai-content-generator'), $aipkit_instruction_site),
        ],
        'bookings' => [
            'icon' => 'calendar-alt',
            'name' => __('Bookings', 'gpt3-ai-content-generator'),
            'hint' => __('Explains services and times; sends visitors to book.', 'gpt3-ai-content-generator'),
            /* translators: %s: site name. */
            'text' => sprintf(__("You help visitors of %s book an appointment. Explain the services, prices and opening hours from the knowledge you have been given, then send them to the booking page to choose a time. Today's date is [date].", 'gpt3-ai-content-generator'), $aipkit_instruction_site),
        ],
    ];
    // Variables in a template read as they do in the editor.
    $aipkit_mark_instruction_variables = static function (string $text) use ($aipkit_instruction_variables): string {
        $html = esc_html($text);
        foreach (array_keys($aipkit_instruction_variables) as $aipkit_variable) {
            $html = str_replace($aipkit_variable, '<mark>' . $aipkit_variable . '</mark>', $html);
        }
        return $html;
    };
    ?>
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
                        <?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?>
                    </h2>
                    <p class="aipkit_builder_modal_subtitle" id="aipkit_builder_instructions_description">
                        <?php esc_html_e('Who your chatbot is and how it answers.', 'gpt3-ai-content-generator'); ?>
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
            <div class="aipkit_instructions_layout">
                <div class="aipkit_instructions_write">
                    <div class="aipkit_instructions_editor">
                        <div class="aipkit_instructions_marks" aria-hidden="true"></div>
                        <textarea
                            id="aipkit_bot_<?php echo esc_attr($initial_active_bot_id); ?>_instructions_modal"
                            class="aipkit_builder_instructions_modal_textarea"
                            rows="14"
                            aria-label="<?php esc_attr_e('Instructions', 'gpt3-ai-content-generator'); ?>"
                            placeholder="<?php esc_attr_e('Describe who your chatbot is and how it should answer.', 'gpt3-ai-content-generator'); ?>"
                        ></textarea>
                    </div>
                    <div class="aipkit_instructions_variables">
                        <p class="aipkit_instructions_variables_head">
                            <span class="aipkit_instructions_section_label" id="aipkit_instructions_variables_label"><?php esc_html_e('Variables', 'gpt3-ai-content-generator'); ?></span>
                            <span><?php esc_html_e('Click to add at the cursor. Filled in when the chatbot answers.', 'gpt3-ai-content-generator'); ?></span>
                        </p>
                        <div class="aipkit_instructions_variables_list" role="group" aria-labelledby="aipkit_instructions_variables_label">
                            <?php foreach ($aipkit_instruction_variables as $aipkit_variable => $aipkit_variable_info) : ?>
                                <button
                                    type="button"
                                    class="aipkit_instructions_variable"
                                    data-aipkit-instruction-variable="<?php echo esc_attr($aipkit_variable); ?>"
                                    title="<?php echo esc_attr($aipkit_variable_info['title']); ?>"
                                >
                                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                    <mark><?php echo esc_html($aipkit_variable); ?></mark>
                                    <span><?php echo esc_html($aipkit_variable_info['label']); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="aipkit_instructions_variables_note">
                            <?php
                            printf(
                                /* translators: 1: the [date] variable, 2: today's date, such as "October 7, 2026". */
                                esc_html__('%1$s reads “%2$s” today.', 'gpt3-ai-content-generator'),
                                wp_kses('<mark>[date]</mark>', ['mark' => []]),
                                esc_html(wp_date(get_option('date_format', 'F j, Y')))
                            );
                            ?>
                        </p>
                    </div>
                </div>
                <div class="aipkit_instructions_templates" role="group" aria-labelledby="aipkit_instructions_templates_label">
                    <p class="aipkit_instructions_templates_head">
                        <span class="aipkit_instructions_section_label" id="aipkit_instructions_templates_label"><?php esc_html_e('Start from a template', 'gpt3-ai-content-generator'); ?></span>
                        <span><?php esc_html_e('Pick one, read it, then use it.', 'gpt3-ai-content-generator'); ?></span>
                    </p>
                    <?php foreach ($aipkit_instruction_templates as $aipkit_template_key => $aipkit_template) : ?>
                        <div
                            class="aipkit_instructions_template"
                            data-aipkit-instruction-template="<?php echo esc_attr($aipkit_template_key); ?>"
                            data-template-name="<?php echo esc_attr($aipkit_template['name']); ?>"
                            data-template-text="<?php echo esc_attr($aipkit_template['text']); ?>"
                        >
                            <button
                                type="button"
                                class="aipkit_instructions_template_head"
                                aria-expanded="false"
                                aria-controls="aipkit_instructions_template_<?php echo esc_attr($aipkit_template_key); ?>"
                            >
                                <span class="aipkit_instructions_template_icon dashicons dashicons-<?php echo esc_attr($aipkit_template['icon']); ?>" aria-hidden="true"></span>
                                <span class="aipkit_instructions_template_copy">
                                    <span class="aipkit_instructions_template_name"><?php echo esc_html($aipkit_template['name']); ?></span>
                                    <span class="aipkit_instructions_template_hint"><?php echo esc_html($aipkit_template['hint']); ?></span>
                                </span>
                                <span class="aipkit_instructions_template_chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                            </button>
                            <div class="aipkit_instructions_template_body" id="aipkit_instructions_template_<?php echo esc_attr($aipkit_template_key); ?>" hidden>
                                <p class="aipkit_instructions_template_text"><?php echo $aipkit_mark_instruction_variables($aipkit_template['text']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure; only <mark> is added. ?></p>
                                <p class="aipkit_instructions_template_note"><?php esc_html_e('Replaces your instructions. You can undo it right after.', 'gpt3-ai-content-generator'); ?></p>
                                <div class="aipkit_instructions_template_actions">
                                    <button type="button" class="aipkit_btn aipkit_btn-secondary" data-aipkit-instruction-template-cancel><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></button>
                                    <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-instruction-template-use><?php esc_html_e('Use this', 'gpt3-ai-content-generator'); ?></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="aipkit_instructions_footer">
                <span class="aipkit_builder_instructions_count"><?php esc_html_e('0 characters', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_instructions_undo" data-aipkit-instructions-undo hidden>
                    <span data-aipkit-instructions-undo-text></span>
                    <button type="button" class="aipkit_instructions_undo_btn" data-aipkit-instructions-undo-btn><?php esc_html_e('Undo', 'gpt3-ai-content-generator'); ?></button>
                </span>
                <span class="aipkit_instructions_saved"><?php esc_html_e('Saved when you close', 'gpt3-ai-content-generator'); ?></span>
                <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_builder_instructions_done"><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
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
                    <?php // One source's page leads back to the list. ?>
                    <button type="button" class="aipkit_known_back" data-aipkit-known-back hidden>
                        <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                        <span><?php esc_html_e('What it knows', 'gpt3-ai-content-generator'); ?></span>
                    </button>
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
                <?php // What it knows: counts that filter by status, kinds with their sizes, then the sources a page at a time; one source opens in their place. ?>
                <div class="aipkit_builder_sheet_section" data-sheet="sources" hidden>
                    <?php if ($active_bot_post) : ?>
                        <p id="aipkit_sources_status" class="aipkit_known_status" aria-live="polite"></p>
                        <div class="aipkit_known_view" data-aipkit-known-view="list">
                        <div class="aipkit_known_counts" data-aipkit-known-counts role="group" aria-label="<?php esc_attr_e('Filter by status', 'gpt3-ai-content-generator'); ?>" hidden>
                            <?php foreach (['indexed' => 'ready', 'processing' => 'adding', 'failed' => 'failed'] as $known_status => $known_tone) : ?>
                                <button type="button" class="aipkit_known_count is-<?php echo esc_attr($known_tone); ?>" data-aipkit-known-status="<?php echo esc_attr($known_status); ?>" aria-pressed="false" hidden>
                                    <span class="aipkit_known_count_dot" aria-hidden="true"></span>
                                    <span data-aipkit-known-count-label></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <select id="aipkit_chatbot_sources_status_filter" class="aipkit_sources_filter_select" aria-hidden="true" tabindex="-1" hidden>
                            <option value=""><?php esc_html_e('Any status', 'gpt3-ai-content-generator'); ?></option>
                            <option value="indexed"><?php esc_html_e('Ready', 'gpt3-ai-content-generator'); ?></option>
                            <option value="processing"><?php esc_html_e('Adding', 'gpt3-ai-content-generator'); ?></option>
                            <option value="failed"><?php esc_html_e("Couldn't add", 'gpt3-ai-content-generator'); ?></option>
                        </select>
                        <label class="aipkit_known_search">
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            <input
                                type="search"
                                id="aipkit_chatbot_sources_search"
                                class="aipkit_known_search_input"
                                placeholder="<?php esc_attr_e('Search what it knows', 'gpt3-ai-content-generator'); ?>"
                                aria-label="<?php esc_attr_e('Search what it knows', 'gpt3-ai-content-generator'); ?>"
                            >
                        </label>
                        <?php // Kind filter: buttons set the select the list reads; each shows how many it holds. ?>
                        <div class="aipkit_segmented aipkit_known_kinds" role="group" aria-label="<?php esc_attr_e('Filter by kind', 'gpt3-ai-content-generator'); ?>" data-aipkit-segmented-for="aipkit_chatbot_sources_type">
                            <?php foreach (['' => __('All', 'gpt3-ai-content-generator'), 'site' => __('Website', 'gpt3-ai-content-generator'), 'text' => __('Q&A and text', 'gpt3-ai-content-generator'), 'file' => __('Files', 'gpt3-ai-content-generator')] as $known_kind => $known_kind_label) : ?>
                                <button type="button" class="aipkit_segmented_option" data-value="<?php echo esc_attr($known_kind); ?>" aria-pressed="<?php echo $known_kind === '' ? 'true' : 'false'; ?>">
                                    <?php echo esc_html($known_kind_label); ?>
                                    <span class="aipkit_known_kind_count" data-aipkit-known-kind-count="<?php echo esc_attr($known_kind === '' ? 'all' : $known_kind); ?>"></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <select id="aipkit_chatbot_sources_type" class="aipkit_sources_type_filter" aria-hidden="true" tabindex="-1" hidden>
                            <option value=""><?php esc_html_e('All', 'gpt3-ai-content-generator'); ?></option>
                            <option value="site"><?php esc_html_e('Website', 'gpt3-ai-content-generator'); ?></option>
                            <option value="text"><?php esc_html_e('Q&A and text', 'gpt3-ai-content-generator'); ?></option>
                            <option value="file"><?php esc_html_e('Files', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                        <div class="aipkit_known_list" id="aipkit_known_list" role="list" aria-label="<?php esc_attr_e('What it knows', 'gpt3-ai-content-generator'); ?>"></div>
                        <div class="aipkit_known_more" data-aipkit-known-more hidden>
                            <span class="aipkit_known_shown" data-aipkit-known-shown></span>
                            <button type="button" class="aipkit_btn aipkit_btn-secondary" data-aipkit-known-more-button><?php esc_html_e('Show more', 'gpt3-ai-content-generator'); ?></button>
                        </div>
                        </div>
                        <div class="aipkit_known_view aipkit_known_source" data-aipkit-known-view="source" hidden></div>
                    <?php else : ?>
                        <p class="aipkit_known_status"><?php esc_html_e('Select a bot to manage sources.', 'gpt3-ai-content-generator'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($active_bot_post) : ?>
                <?php // Each button names the views it belongs to: the list, a source to read, or one being edited. ?>
                <div class="aipkit_builder_sheet_footer" data-aipkit-known-footer>
                    <button type="button" class="aipkit_btn aipkit_btn-secondary" data-aipkit-known-add data-aipkit-sheet-close data-aipkit-known-for="list">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <span><?php esc_html_e('Add source', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button type="button" class="aipkit_known_remove aipkit_sources_action_delete" data-aipkit-known-for="read edit" hidden>
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        <span><?php esc_html_e('Remove from knowledge', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <span class="aipkit_known_footer_gap" aria-hidden="true"></span>
                    <button type="button" class="aipkit_btn aipkit_btn-secondary" data-aipkit-known-back data-aipkit-known-for="edit" hidden><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-known-save data-aipkit-known-for="edit" hidden disabled><?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-sheet-close data-aipkit-known-for="list read"><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
