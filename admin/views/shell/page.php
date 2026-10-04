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
        'icon'        => 'format-chat',
        'data_module' => 'chatbot',
    ),
    'content_writer' => array(
        'label'       => __('Content Writer', 'gpt3-ai-content-generator'),
        'icon'        => 'edit',
        'data_module' => 'content-writer',
    ),
    'autogpt' => array(
        'label'       => __('Automations', 'gpt3-ai-content-generator'),
        'icon_text'   => '⚡︎',
        'data_module' => 'autogpt',
    ),
    'ai_forms' => array(
        'label'       => __('AI Forms', 'gpt3-ai-content-generator'),
        'icon'        => 'feedback',
        'data_module' => 'ai-forms',
    ),
    'image_generator' => array(
        'label'       => __('Images', 'gpt3-ai-content-generator'),
        'icon'        => 'format-image',
        'data_module' => 'image-generator',
    ),
);

$utility_nav_modules = array(
    'sources' => array(
        'label'       => __('Knowledge Base', 'gpt3-ai-content-generator'),
        'icon'        => 'media-document',
        'data_module' => 'sources',
    ),
    'stats_viewer' => array(
        'label'       => __('Usage', 'gpt3-ai-content-generator'),
        'icon'        => 'chart-bar',
        'data_module' => 'stats',
    ),
);

$is_nav_module_enabled = static function ($option_key) use ($module_settings) {
    return !isset($module_settings[$option_key]) || !empty($module_settings[$option_key]);
};

// Desktop tabs and compact links share permission, visibility and module attributes.
$render_module_link = static function (array $module, string $option_key, string $classes, bool $compact = false) use ($is_nav_module_enabled): void {
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
        <?php if (!empty($module['icon_text'])): ?>
            <span class="aipkit_module-tab_icon-glyph" aria-hidden="true"><?php echo esc_html($module['icon_text']); ?></span>
        <?php else: ?>
            <span class="dashicons dashicons-<?php echo esc_attr($module['icon']); ?>" aria-hidden="true"></span>
        <?php endif; ?>
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

?>
<div class="wrap aipkit_wrap">
    <div class="aipkit_module-tabs_shell">
        <div class="<?php echo esc_attr($module_tabs_classes); ?>">
        <?php if ($can_access_dashboard): ?>
            <div class="aipkit_module-brand">
                <a
                    href="#"
                    class="aipkit_module-brand_home"
                    <?php if ($default_module_slug !== ''): ?>
                        data-module="<?php echo esc_attr($default_module_slug); ?>"
                        data-aipkit-open-module="<?php echo esc_attr($default_module_slug); ?>"
                        <?php if ($default_module_slug === 'settings'): ?>
                            data-aipkit-settings-page="modules"
                        <?php endif; ?>
                    <?php endif; ?>
                    aria-label="<?php echo esc_attr($brand_label); ?>"
                    title="<?php echo esc_attr($brand_label); ?>"
                >
                    <span class="aipkit_module-brand_logo" aria-hidden="true">
                        <img
                            src="<?php echo esc_url(WPAICG_LOGO_HEADER_URL); ?>"
                            alt=""
                        />
                    </span>
                </a>
                <span class="aipkit_module-brand_copy">
                    <a
                        href="#"
                        class="aipkit_module-brand_title aipkit_module-brand_home"
                        <?php if ($default_module_slug !== ''): ?>
                            data-module="<?php echo esc_attr($default_module_slug); ?>"
                            data-aipkit-open-module="<?php echo esc_attr($default_module_slug); ?>"
                            <?php if ($default_module_slug === 'settings'): ?>
                                data-aipkit-settings-page="modules"
                            <?php endif; ?>
                        <?php endif; ?>
                        aria-label="<?php echo esc_attr($brand_label); ?>"
                        title="<?php echo esc_attr($brand_label); ?>"
                    >
                        <?php esc_html_e('AI Puffer', 'gpt3-ai-content-generator'); ?>
                    </a>
                    <a
                        class="aipkit_module-brand_meta"
                        href="https://pufferworks.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <?php esc_html_e('By PufferWorks', 'gpt3-ai-content-generator'); ?>
                    </a>
                </span>
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
                <details class="aipkit_module-menu">
                    <summary
                        class="aipkit_module-menu_trigger"
                        aria-label="<?php esc_attr_e('Open navigation', 'gpt3-ai-content-generator'); ?>"
                        title="<?php esc_attr_e('Navigation', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                        <span class="aipkit_module-menu_trigger_label"><?php esc_html_e('Menu', 'gpt3-ai-content-generator'); ?></span>
                    </summary>
                    <nav class="aipkit_module-menu_panel" aria-label="<?php esc_attr_e('Compact navigation', 'gpt3-ai-content-generator'); ?>">
                        <span class="aipkit_module-menu_group_label"><?php esc_html_e('Create', 'gpt3-ai-content-generator'); ?></span>
                        <div class="aipkit_module-menu_group">
                            <?php foreach ($nav_modules as $option_key => $module): ?>
                                <?php $render_module_link($module, $option_key, 'aipkit_module-menu_link aipkit_module-link aipkit_module-tab--module', true); ?>
                            <?php endforeach; ?>
                        </div>

                        <span class="aipkit_module-menu_group_label aipkit_module-menu_group_label--manage"><?php esc_html_e('Manage', 'gpt3-ai-content-generator'); ?></span>
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
                                    <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                                    <span><?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </nav>
                </details>

                <?php foreach ($utility_nav_modules as $option_key => $module): ?>
                    <?php $render_module_link($module, $option_key, 'aipkit_module-tab aipkit_module-tab--utility aipkit_module-link'); ?>
                <?php endforeach; ?>

                <?php if ($can_access_settings): ?>
                <a
                    href="#"
                    class="aipkit_module-tab aipkit_module-tab--settings aipkit_module-tab--settings-control aipkit_module-link"
                    data-module="settings"
                    data-aipkit-open-module="settings"
                    role="tab"
                    aria-label="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                    title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                >
                    <svg class="aipkit_settings-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="m19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1 1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    <span class="aipkit_module-tab_label"><?php esc_html_e('Settings', 'gpt3-ai-content-generator'); ?></span>
                </a>
                <?php endif; ?>

                <?php
                // Show upgrade button only for non-pro users
                $is_pro_plan = class_exists('\\WPAICG\\aipkit_dashboard') ? \WPAICG\aipkit_dashboard::is_pro_plan() : false;
                if (!$is_pro_plan):
                ?>
                <a
                    href="<?php echo esc_url(admin_url('admin.php?page=wpaicg-pricing')); ?>"
                    class="aipkit_module-tab aipkit_module-tab--settings aipkit_upgrade_btn"
                    aria-label="<?php echo esc_attr__('Upgrade', 'gpt3-ai-content-generator'); ?>"
                    title="<?php echo esc_attr__('Upgrade', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="aipkit_upgrade_btn_icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                    </span>
                    <span class="aipkit_module-tab_label aipkit_upgrade_btn_label"><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></span>
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
