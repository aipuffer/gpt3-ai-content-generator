<?php

// Silence direct access
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\aipkit_dashboard;
use WPAICG\AIPKit_Role_Manager;

$module_settings = aipkit_dashboard::get_module_settings();
$can_access_dashboard = AIPKit_Role_Manager::user_can_access_dashboard_shell();
$can_access_settings = AIPKit_Role_Manager::user_can_access_module('settings');

$nav_modules = array(
    'chat_bot' => array(
        'label'       => __('Chatbots', 'gpt3-ai-content-generator'),
        'icon'        => 'chat',
        'data_module' => 'chatbot',
    ),
    'content_writer' => array(
        'label'       => __('Content Writer', 'gpt3-ai-content-generator'),
        'icon'        => 'pen',
        'data_module' => 'content-writer',
    ),
    'autogpt' => array(
        'label'       => __('Automations', 'gpt3-ai-content-generator'),
        'icon'        => 'zap',
        'data_module' => 'autogpt',
    ),
    'ai_forms' => array(
        'label'       => __('AI Forms', 'gpt3-ai-content-generator'),
        'icon'        => 'form',
        'data_module' => 'ai-forms',
    ),
    'image_generator' => array(
        'label'       => __('Images', 'gpt3-ai-content-generator'),
        'icon'        => 'image',
        'data_module' => 'image-generator',
    ),
);

$utility_nav_modules = array(
    'sources' => array(
        'label'       => __('Knowledge Base', 'gpt3-ai-content-generator'),
        'icon'        => 'book',
        'data_module' => 'sources',
    ),
    'stats_viewer' => array(
        'label'       => __('Usage', 'gpt3-ai-content-generator'),
        'icon'        => 'chart',
        'data_module' => 'stats',
    ),
);

// One stroke icon set for every top-bar control.
$nav_icon = static function (string $name): void {
    $paths = array(
        'chat'     => '<path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 20.5l1.6-5A8.5 8.5 0 1 1 21 11.5z"></path>',
        'pen'      => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path>',
        'zap'      => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"></path>',
        'form'     => '<rect x="5" y="3" width="14" height="18" rx="2"></rect><path d="M9 8h6M9 12h6M9 16h4"></path>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="m21 16-5-5-9 9"></path>',
        'book'     => '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"></path><path d="M5 17a3 3 0 0 1 3-3h11"></path>',
        'chart'    => '<path d="M4 20V11M10 20V5M16 20v-6M21 20H3"></path>',
        'settings' => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"></path><circle cx="15" cy="6" r="2"></circle><circle cx="9" cy="12" r="2"></circle><circle cx="17" cy="18" r="2"></circle>',
        'spark'    => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"></path>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"></path>',
    );
    if (!isset($paths[$name])) {
        return;
    }
    echo '<svg class="aipkit_nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . wp_kses(
            $paths[$name],
            array(
                'path'   => array('d' => true),
                'rect'   => array('x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true),
                'circle' => array('cx' => true, 'cy' => true, 'r' => true),
            )
        )
        . '</svg>';
};

$is_nav_module_enabled = static function ($option_key) use ($module_settings) {
    return !isset($module_settings[$option_key]) || !empty($module_settings[$option_key]);
};

// Desktop tabs and compact links share permission, visibility and module attributes.
$render_module_link = static function (array $module, string $option_key, string $classes, bool $compact = false) use ($is_nav_module_enabled, $nav_icon): void {
    $module_slug = $module['data_module'];
    if (!AIPKit_Role_Manager::user_can_access_module($module_slug)) {
        return;
    }
    $is_enabled = $is_nav_module_enabled($option_key);
    ?>
    <a
        href="#"
        class="<?php echo esc_attr($classes . ($is_enabled ? '' : ' aipkit_module-tab--is-hidden')); ?>"
        data-module="<?php echo esc_attr($module_slug); ?>"
        data-option-key="<?php echo esc_attr($option_key); ?>"
        data-aipkit-open-module="<?php echo esc_attr($module_slug); ?>"
        <?php if (!$compact): ?>
            role="tab"
        <?php endif; ?>
        aria-label="<?php echo esc_attr($module['label']); ?>"
        title="<?php echo esc_attr($module['label']); ?>"
        <?php if (!$is_enabled): ?>
            hidden
            aria-hidden="true"
            tabindex="-1"
        <?php endif; ?>
    >
        <?php $nav_icon($module['icon']); ?>
        <span<?php if (!$compact): ?> class="aipkit_module-tab_label"<?php endif; ?>><?php echo esc_html($module['label']); ?></span>
    </a>
    <?php
};

$visible_nav_module_count = 0;
if ($can_access_dashboard) {
    foreach ($nav_modules as $option_key => $module) {
        if (
            $is_nav_module_enabled($option_key) &&
            AIPKit_Role_Manager::user_can_access_module($module['data_module'])
        ) {
            ++$visible_nav_module_count;
        }
    }
}

$default_module_slug = '';
$default_module_label = '';
if (
    isset($nav_modules['chat_bot']) &&
    $is_nav_module_enabled('chat_bot') &&
    AIPKit_Role_Manager::user_can_access_module($nav_modules['chat_bot']['data_module'])
) {
    $default_module_slug = $nav_modules['chat_bot']['data_module'];
    $default_module_label = $nav_modules['chat_bot']['label'];
}

if ($default_module_slug === '') {
    foreach ($nav_modules as $option_key => $module) {
        $module_slug = $module['data_module'];
        if ($is_nav_module_enabled($option_key) && AIPKit_Role_Manager::user_can_access_module($module_slug)) {
            $default_module_slug = $module_slug;
            $default_module_label = $module['label'];
            break;
        }
    }
}

if ($default_module_slug === '') {
    foreach ($utility_nav_modules as $option_key => $module) {
        $module_slug = $module['data_module'];
        if ($is_nav_module_enabled($option_key) && AIPKit_Role_Manager::user_can_access_module($module_slug)) {
            $default_module_slug = $module_slug;
            $default_module_label = $module['label'];
            break;
        }
    }
}

if ($default_module_slug === '' && $can_access_settings) {
    $default_module_slug = 'settings';
    $default_module_label = __('Settings', 'gpt3-ai-content-generator');
}

$brand_label = $default_module_label !== '' ? $default_module_label : __('AI Puffer', 'gpt3-ai-content-generator');
$module_tabs_classes = 'aipkit_module-tabs';
if ($visible_nav_module_count === 0) {
    $module_tabs_classes .= ' aipkit_module-tabs--modules-empty';
}

// Both brand links open the same accessible fallback module.
$render_brand_target = static function () use ($default_module_slug, $brand_label): void {
    if ($default_module_slug !== '') {
        echo ' data-module="' . esc_attr($default_module_slug) . '" data-aipkit-open-module="' . esc_attr($default_module_slug) . '"';
        if ($default_module_slug === 'settings') {
            echo ' data-aipkit-settings-page="modules"';
        }
    }
    echo ' aria-label="' . esc_attr($brand_label) . '" title="' . esc_attr($brand_label) . '"';
};

?>
<div class="wrap aipkit_wrap">
    <div class="aipkit_module-tabs_shell">
        <div class="<?php echo esc_attr($module_tabs_classes); ?>">
        <?php if ($can_access_dashboard): ?>
            <div class="aipkit_module-brand">
                <a href="#" class="aipkit_module-brand_home aipkit_module-brand_logo"<?php $render_brand_target(); ?>>
                    <img src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" width="32" height="32" alt="" />
                </a>
                <a href="#" class="aipkit_module-brand_home aipkit_module-brand_title"<?php $render_brand_target(); ?>>
                    <?php esc_html_e('AI Puffer', 'gpt3-ai-content-generator'); ?>
                </a>
            </div>
        <?php endif; ?>

        <nav
            class="aipkit_module-tabs_list"
            role="tablist"
            aria-label="<?php esc_attr_e('Main navigation', 'gpt3-ai-content-generator'); ?>"
            <?php if ($visible_nav_module_count === 0): ?>
                aria-hidden="true"
            <?php endif; ?>
        >
            <?php if ($can_access_dashboard): ?>
                <?php foreach ($nav_modules as $option_key => $module): ?>
                    <?php $render_module_link($module, $option_key, 'aipkit_module-tab aipkit_module-link aipkit_module-tab--module'); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>

        <?php if ($can_access_dashboard): ?>
            <div class="aipkit_module-tabs_actions">
                <?php foreach ($utility_nav_modules as $option_key => $module): ?>
                    <?php $render_module_link($module, $option_key, 'aipkit_module-tab aipkit_module-tab--utility aipkit_module-link'); ?>
                <?php endforeach; ?>

                <?php if ($can_access_settings): ?>
                <a
                    href="#"
                    class="aipkit_module-tab aipkit_module-tab--utility aipkit_module-tab--settings aipkit_module-tab--settings-control aipkit_module-link"
                    data-module="settings"
                    data-aipkit-open-module="settings"
                    role="tab"
                    aria-label="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                    title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                >
                    <?php $nav_icon('settings'); ?>
                    <span class="aipkit_module-tab_label"><?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?></span>
                </a>
                <?php endif; ?>

                <details class="aipkit_module-menu">
                    <summary
                        class="aipkit_module-menu_trigger"
                        aria-label="<?php esc_attr_e('Open navigation', 'gpt3-ai-content-generator'); ?>"
                        title="<?php esc_attr_e('Navigation', 'gpt3-ai-content-generator'); ?>"
                    >
                        <?php $nav_icon('menu'); ?>
                    </summary>
                    <nav class="aipkit_module-menu_panel" aria-label="<?php esc_attr_e('Compact navigation', 'gpt3-ai-content-generator'); ?>">
                        <div class="aipkit_module-menu_section aipkit_module-menu_section--create">
                            <span class="aipkit_module-menu_group_label"><?php esc_html_e('Create', 'gpt3-ai-content-generator'); ?></span>
                            <div class="aipkit_module-menu_group">
                                <?php foreach ($nav_modules as $option_key => $module): ?>
                                    <?php $render_module_link($module, $option_key, 'aipkit_module-menu_link aipkit_module-link aipkit_module-tab--module', true); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="aipkit_module-menu_section">
                            <span class="aipkit_module-menu_group_label"><?php esc_html_e('Manage', 'gpt3-ai-content-generator'); ?></span>
                            <div class="aipkit_module-menu_group">
                                <?php foreach ($utility_nav_modules as $option_key => $module): ?>
                                    <?php $render_module_link($module, $option_key, 'aipkit_module-menu_link aipkit_module-tab--utility aipkit_module-link', true); ?>
                                <?php endforeach; ?>

                                <?php if ($can_access_settings): ?>
                                    <a
                                        href="#"
                                        class="aipkit_module-menu_link aipkit_module-link"
                                        data-module="settings"
                                        data-aipkit-open-module="settings"
                                        aria-label="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                                        title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                                    >
                                        <?php $nav_icon('settings'); ?>
                                        <span><?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </nav>
                </details>

                <span class="aipkit_module-tabs_divider" aria-hidden="true"></span>

                <?php \WPAICG\Cloud\StatusChip::render(); ?>

                <?php
                // Show upgrade button only for non-pro users
                $is_pro_plan = class_exists('\\WPAICG\\aipkit_dashboard') ? \WPAICG\aipkit_dashboard::is_pro_plan() : false;
                if (!$is_pro_plan):
                ?>
                <a
                    href="<?php echo esc_url(admin_url('admin.php?page=wpaicg-pricing')); ?>"
                    class="aipkit_upgrade_btn"
                    aria-label="<?php echo esc_attr__('Upgrade', 'gpt3-ai-content-generator'); ?>"
                    title="<?php echo esc_attr__('Upgrade', 'gpt3-ai-content-generator'); ?>"
                >
                    <?php $nav_icon('spark'); ?>
                    <span class="aipkit_upgrade_btn_label"><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></span>
                </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <?php \WPAICG\Cloud\Announcement::render(); ?>
    <?php \WPAICG\Cloud\CreditNotice::render(true); ?>

    <div class="aipkit_main-content" id="aipkit_module-container">
    </div>
</div>
