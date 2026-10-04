<?php

/**
 * AIPKit Role Manager Module - Admin View
 *
 * Allows administrators to assign module access permissions to different user roles.
 * @since NEXT_VERSION
 */

use WPAICG\AIPKit_Role_Manager;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$permission_groups = AIPKit_Role_Manager::get_permission_groups();
$roles = AIPKit_Role_Manager::get_editable_roles();
$permissions = AIPKit_Role_Manager::get_role_permissions();

$role_order = ['editor', 'author', 'contributor', 'subscriber'];
$sorted_roles = [];
unset($roles['administrator']);
foreach ($role_order as $role_key) {
    if (isset($roles[$role_key])) {
        $sorted_roles[$role_key] = $roles[$role_key];
        unset($roles[$role_key]);
    }
}
ksort($roles);
$sorted_roles = array_merge($sorted_roles, $roles);

$display_roles = [];
foreach ($sorted_roles as $role_slug => $role_name) {
    $display_roles[$role_slug] = translate_user_role($role_name);
}

$module_count = 0;
foreach ($permission_groups as $group) {
    $module_count += count($group['modules']);
}

$role_enabled_counts = [];
foreach (array_keys($sorted_roles) as $role_slug) {
    $enabled_count = 0;
    foreach ($permission_groups as $group) {
        foreach (array_keys($group['modules']) as $module_slug) {
            $allowed_roles = isset($permissions[$module_slug]) && is_array($permissions[$module_slug])
                ? $permissions[$module_slug]
                : ['administrator'];
            if (in_array($role_slug, $allowed_roles, true)) {
                $enabled_count++;
            }
        }
    }
    $role_enabled_counts[$role_slug] = $enabled_count;
}

$default_role_slug = (string) array_key_first($sorted_roles);
$default_comparison_roles = array_slice(array_keys($sorted_roles), 0, 5);
$nonce = wp_create_nonce('aipkit_role_manager_nonce');
/* translators: 1: enabled permission count, 2: total permission count. */
$enabled_count_format = __('%1$d of %2$d', 'gpt3-ai-content-generator');
/* translators: 1: visible role count, 2: total role count. */
$comparison_count_format = __('%1$d of %2$d roles shown', 'gpt3-ai-content-generator');

?>
<div
    class="aipkit_role_manager_container"
    id="aipkit_role_manager_container"
    data-module-count="<?php echo esc_attr((string) $module_count); ?>"
    data-role-count="<?php echo esc_attr((string) count($sorted_roles)); ?>"
>
    <div class="aipkit_container-header">
        <div class="aipkit_container-header-left">
            <div class="aipkit_role_manager_header_copy">
                <div class="aipkit_role_manager_header_title_row">
                    <div class="aipkit_container-title"><?php esc_html_e('Role Manager', 'gpt3-ai-content-generator'); ?></div>
                    <div id="aipkit_role_manager_messages" class="aipkit_settings_messages aipkit_role_manager_header_status"></div>
                </div>
                <p class="aipkit_role_manager_header_hint"><?php esc_html_e('Control which WordPress roles can access each AI Puffer workspace and tool.', 'gpt3-ai-content-generator'); ?></p>
            </div>
        </div>
        <div class="aipkit_role_manager_view_switch" role="group" aria-label="<?php esc_attr_e('Role Manager view', 'gpt3-ai-content-generator'); ?>">
            <button
                type="button"
                class="aipkit_role_manager_view_button is-active"
                data-role-manager-view="roles"
                aria-pressed="true"
                aria-label="<?php esc_attr_e('Role view', 'gpt3-ai-content-generator'); ?>"
                title="<?php esc_attr_e('Role view', 'gpt3-ai-content-generator'); ?>"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg>
            </button>
            <button
                type="button"
                class="aipkit_role_manager_view_button"
                data-role-manager-view="comparison"
                aria-pressed="false"
                aria-label="<?php esc_attr_e('Grid view', 'gpt3-ai-content-generator'); ?>"
                title="<?php esc_attr_e('Grid view', 'gpt3-ai-content-generator'); ?>"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>
            </button>
        </div>
    </div>

    <div class="aipkit_container-body">
        <form id="aipkit_role_manager_form">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($nonce); ?>">

            <div class="aipkit_role_manager_workspace">
                <div class="aipkit_role_manager_roles_view" data-role-manager-panel="roles">
                    <aside class="aipkit_role_manager_sidebar" aria-label="<?php esc_attr_e('WordPress roles', 'gpt3-ai-content-generator'); ?>">
                        <div class="aipkit_role_manager_sidebar_header">
                            <h2><?php esc_html_e('Roles', 'gpt3-ai-content-generator'); ?></h2>
                            <label class="aipkit_compact_search aipkit_role_manager_search">
                                <span class="screen-reader-text"><?php esc_html_e('Search roles', 'gpt3-ai-content-generator'); ?></span>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                <input type="search" class="aipkit_form-input" data-role-search placeholder="<?php esc_attr_e('Search roles', 'gpt3-ai-content-generator'); ?>" autocomplete="off">
                            </label>
                        </div>

                        <div class="aipkit_role_manager_role_list" role="tablist" aria-orientation="vertical">
                            <?php foreach ($sorted_roles as $role_slug => $role_name):
                                $display_name = $display_roles[$role_slug];
                                $is_active_role = $role_slug === $default_role_slug;
                            ?>
                                <button
                                    type="button"
                                    class="aipkit_role_manager_role_item<?php echo $is_active_role ? ' is-active' : ''; ?>"
                                    role="tab"
                                    id="aipkit_role_tab_<?php echo esc_attr($role_slug); ?>"
                                    aria-controls="aipkit_role_panel_<?php echo esc_attr($role_slug); ?>"
                                    aria-selected="<?php echo $is_active_role ? 'true' : 'false'; ?>"
                                    tabindex="<?php echo $is_active_role ? '0' : '-1'; ?>"
                                    data-role-selector="<?php echo esc_attr($role_slug); ?>"
                                    data-role-name="<?php echo esc_attr($display_name); ?>"
                                    title="<?php echo esc_attr($display_name); ?>"
                                >
                                    <span class="aipkit_role_manager_role_name"><?php echo esc_html($display_name); ?></span>
                                    <span
                                        class="aipkit_role_manager_role_count"
                                        data-role-enabled-count="<?php echo esc_attr($role_slug); ?>"
                                    ><?php echo esc_html(sprintf($enabled_count_format, $role_enabled_counts[$role_slug], $module_count)); ?></span>
                                </button>
                            <?php endforeach; ?>
                            <p class="aipkit_role_manager_no_roles" data-role-search-empty hidden><?php esc_html_e('No roles found.', 'gpt3-ai-content-generator'); ?></p>
                        </div>
                    </aside>

                    <div class="aipkit_role_manager_details">
                        <?php foreach ($sorted_roles as $role_slug => $role_name):
                            $display_name = $display_roles[$role_slug];
                            $is_active_role = $role_slug === $default_role_slug;
                        ?>
                            <section
                                class="aipkit_role_manager_role_panel"
                                id="aipkit_role_panel_<?php echo esc_attr($role_slug); ?>"
                                role="tabpanel"
                                aria-labelledby="aipkit_role_tab_<?php echo esc_attr($role_slug); ?>"
                                data-role-panel="<?php echo esc_attr($role_slug); ?>"
                                <?php if (!$is_active_role): ?>hidden<?php endif; ?>
                            >
                                <div class="aipkit_role_manager_detail_header">
                                    <div class="aipkit_role_manager_detail_copy">
                                        <h2 title="<?php echo esc_attr($display_name); ?>"><?php echo esc_html($display_name); ?></h2>
                                        <p><?php esc_html_e('Control which tools this role can access.', 'gpt3-ai-content-generator'); ?></p>
                                    </div>
                                    <div class="aipkit_role_manager_bulk_actions" aria-label="<?php esc_attr_e('Bulk permission actions', 'gpt3-ai-content-generator'); ?>">
                                        <button type="button" class="aipkit_text_action" data-role-bulk="enable" data-role="<?php echo esc_attr($role_slug); ?>"><?php esc_html_e('Enable all', 'gpt3-ai-content-generator'); ?></button>
                                        <button type="button" class="aipkit_text_action" data-role-bulk="clear" data-role="<?php echo esc_attr($role_slug); ?>"><?php esc_html_e('Clear all', 'gpt3-ai-content-generator'); ?></button>
                                    </div>
                                </div>

                                <div class="aipkit_role_manager_permission_groups">
                                    <?php foreach ($permission_groups as $group_key => $group): ?>
                                        <section class="aipkit_role_manager_permission_group" aria-labelledby="aipkit_role_<?php echo esc_attr($role_slug); ?>_group_<?php echo esc_attr($group_key); ?>">
                                            <div class="aipkit_role_manager_permission_group_header">
                                                <h3 id="aipkit_role_<?php echo esc_attr($role_slug); ?>_group_<?php echo esc_attr($group_key); ?>"><?php echo esc_html($group['label']); ?></h3>
                                            </div>

                                            <div class="aipkit_role_manager_permission_list">
                                                <?php foreach ($group['modules'] as $module_slug => $module):
                                                    $allowed_roles = isset($permissions[$module_slug]) && is_array($permissions[$module_slug])
                                                        ? $permissions[$module_slug]
                                                        : ['administrator'];
                                                    $is_checked = in_array($role_slug, $allowed_roles, true);
                                                    $checkbox_id = 'aipkit_perm_role_' . $module_slug . '_' . $role_slug;
                                                    $checkbox_name = 'permissions[' . $module_slug . '][' . $role_slug . ']';
                                                    $checkbox_label = sprintf(
                                                        /* translators: 1: role name, 2: module name */
                                                        __('Allow %1$s to access %2$s', 'gpt3-ai-content-generator'),
                                                        $display_name,
                                                        $module['label']
                                                    );
                                                ?>
                                                    <label class="aipkit_role_manager_permission_row" for="<?php echo esc_attr($checkbox_id); ?>">
                                                        <span class="aipkit_role_manager_permission_copy">
                                                            <span class="aipkit_role_manager_module_name"><?php echo esc_html($module['label']); ?></span>
                                                        </span>
                                                        <span class="aipkit_switch aipkit_switch-compact">
                                                            <input
                                                                type="checkbox"
                                                                class="aipkit_role_permission_input"
                                                                id="<?php echo esc_attr($checkbox_id); ?>"
                                                                name="<?php echo esc_attr($checkbox_name); ?>"
                                                                value="1"
                                                                data-role="<?php echo esc_attr($role_slug); ?>"
                                                                data-module="<?php echo esc_attr($module_slug); ?>"
                                                                data-permission-view="roles"
                                                                aria-label="<?php echo esc_attr($checkbox_label); ?>"
                                                                <?php checked($is_checked); ?>
                                                            >
                                                            <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="aipkit_role_manager_comparison_view" data-role-manager-panel="comparison" hidden>
                    <div class="aipkit_role_manager_comparison_groups">
                        <?php foreach ($permission_groups as $group_index => $group): ?>
                            <section class="aipkit_role_manager_comparison_group">
                                <div class="aipkit_role_manager_comparison_group_header">
                                    <h2><?php echo esc_html($group['label']); ?></h2>
                                    <?php if ($group_index === array_key_first($permission_groups)): ?>
                                        <details class="aipkit_role_manager_role_filter" data-role-filter>
                                            <summary>
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z"/></svg>
                                                <span data-comparison-count><?php echo esc_html(sprintf($comparison_count_format, count($default_comparison_roles), count($sorted_roles))); ?></span>
                                            </summary>
                                            <div class="aipkit_role_manager_filter_popover">
                                                <div class="aipkit_role_manager_filter_header">
                                                    <strong><?php esc_html_e('Roles shown', 'gpt3-ai-content-generator'); ?></strong>
                                                    <div>
                                                        <button type="button" class="aipkit_btn aipkit_btn-neutral aipkit_btn-compact" data-comparison-filter-action="all"><?php esc_html_e('Show all', 'gpt3-ai-content-generator'); ?></button>
                                                        <button type="button" class="aipkit_btn aipkit_btn-neutral aipkit_btn-compact" data-comparison-filter-action="reset"><?php esc_html_e('Reset', 'gpt3-ai-content-generator'); ?></button>
                                                    </div>
                                                </div>
                                                <label class="aipkit_compact_search aipkit_role_manager_filter_search">
                                                    <span class="screen-reader-text"><?php esc_html_e('Search roles', 'gpt3-ai-content-generator'); ?></span>
                                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                                    <input type="search" class="aipkit_form-input" data-comparison-role-search placeholder="<?php esc_attr_e('Search roles', 'gpt3-ai-content-generator'); ?>" autocomplete="off">
                                                </label>
                                                <div class="aipkit_role_manager_filter_roles">
                                                    <?php foreach ($sorted_roles as $role_slug => $role_name):
                                                        $display_name = $display_roles[$role_slug];
                                                        $is_default_visible = in_array($role_slug, $default_comparison_roles, true);
                                                    ?>
                                                        <label data-comparison-filter-row data-role-name="<?php echo esc_attr($display_name); ?>" title="<?php echo esc_attr($display_name); ?>">
                                                            <input
                                                                type="checkbox"
                                                                class="aipkit_checkbox-compact"
                                                                data-comparison-role-filter="<?php echo esc_attr($role_slug); ?>"
                                                                data-default-visible="<?php echo $is_default_visible ? 'true' : 'false'; ?>"
                                                                <?php checked($is_default_visible); ?>
                                                            >
                                                            <span><?php echo esc_html($display_name); ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                    <p data-comparison-filter-empty hidden><?php esc_html_e('No roles found.', 'gpt3-ai-content-generator'); ?></p>
                                                </div>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </div>

                                <div class="aipkit_role_manager_comparison_scroll aipkit_thin_scrollbar" data-comparison-scroll tabindex="0" aria-label="<?php echo esc_attr(sprintf('%s: %s', __('Role permissions table', 'gpt3-ai-content-generator'), $group['label'])); ?>">
                                    <table class="aipkit_role_manager_comparison_table">
                                        <thead>
                                            <tr>
                                                <th class="aipkit_role_manager_access_column" scope="col"><?php esc_html_e('Access', 'gpt3-ai-content-generator'); ?></th>
                                                <?php foreach ($sorted_roles as $role_slug => $role_name):
                                                    $display_name = $display_roles[$role_slug];
                                                    $is_default_visible = in_array($role_slug, $default_comparison_roles, true);
                                                ?>
                                                    <th
                                                        class="aipkit_role_manager_comparison_role_header"
                                                        scope="col"
                                                        data-comparison-role="<?php echo esc_attr($role_slug); ?>"
                                                        title="<?php echo esc_attr($display_name); ?>"
                                                        <?php if (!$is_default_visible): ?>hidden<?php endif; ?>
                                                    >
                                                        <span class="aipkit_role_manager_comparison_role_name">
                                                            <span><?php echo esc_html($display_name); ?></span>
                                                        </span>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($group['modules'] as $module_slug => $module): ?>
                                                <tr>
                                                    <th class="aipkit_role_manager_access_column" scope="row">
                                                        <span class="aipkit_role_manager_module_name"><?php echo esc_html($module['label']); ?></span>
                                                    </th>
                                                    <?php foreach ($sorted_roles as $role_slug => $role_name):
                                                        $display_name = $display_roles[$role_slug];
                                                        $is_default_visible = in_array($role_slug, $default_comparison_roles, true);
                                                        $allowed_roles = isset($permissions[$module_slug]) && is_array($permissions[$module_slug])
                                                            ? $permissions[$module_slug]
                                                            : ['administrator'];
                                                        $is_checked = in_array($role_slug, $allowed_roles, true);
                                                        $checkbox_id = 'aipkit_perm_compare_' . $module_slug . '_' . $role_slug;
                                                        $checkbox_name = 'permissions[' . $module_slug . '][' . $role_slug . ']';
                                                        $checkbox_label = sprintf(
                                                            /* translators: 1: role name, 2: module name */
                                                            __('Allow %1$s to access %2$s', 'gpt3-ai-content-generator'),
                                                            $display_name,
                                                            $module['label']
                                                        );
                                                    ?>
                                                        <td
                                                            class="aipkit_role_manager_comparison_cell"
                                                            data-comparison-role="<?php echo esc_attr($role_slug); ?>"
                                                            <?php if (!$is_default_visible): ?>hidden<?php endif; ?>
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                class="aipkit_role_permission_input aipkit_checkbox-compact"
                                                                id="<?php echo esc_attr($checkbox_id); ?>"
                                                                name="<?php echo esc_attr($checkbox_name); ?>"
                                                                value="1"
                                                                data-role="<?php echo esc_attr($role_slug); ?>"
                                                                data-module="<?php echo esc_attr($module_slug); ?>"
                                                                data-permission-view="comparison"
                                                                aria-label="<?php echo esc_attr($checkbox_label); ?>"
                                                                <?php checked($is_checked); ?>
                                                            >
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                    <p class="aipkit_role_manager_comparison_hint"><?php esc_html_e('Scroll horizontally past the pinned Access column. Hover a shortened role name to see it in full.', 'gpt3-ai-content-generator'); ?></p>
                </div>
            </div>
        </form>
    </div>
</div>
