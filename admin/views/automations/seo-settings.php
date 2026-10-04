<?php
/**
 * Automation SEO settings and paid-panel dispatch.
 */

if (!defined('ABSPATH')) {
    exit;
}

$aipkit_task_cw_paid_seo_path = defined('WPAICG_LIB_DIR') ? WPAICG_LIB_DIR . 'views/automations/smart-seo.php' : '';
$aipkit_task_cw_smart_seo_is_pro = class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan() && $aipkit_task_cw_paid_seo_path !== '' && file_exists($aipkit_task_cw_paid_seo_path);
if ($aipkit_task_cw_smart_seo_is_pro) {
    require_once $aipkit_task_cw_paid_seo_path;
}
$aipkit_task_cw_seo_profile = class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')
    ? \WPAICG\SEO\AIPKit_SEO_Helper::get_active_plugin_profile()
    : [
        'profile' => 'aipkit',
        'label' => __('AIPKit SEO', 'gpt3-ai-content-generator'),
        'logo_url' => '',
        'logo_initials' => 'AI',
    ];
$aipkit_task_cw_seo_profile_label = isset($aipkit_task_cw_seo_profile['label']) ? (string) $aipkit_task_cw_seo_profile['label'] : __('AIPKit SEO', 'gpt3-ai-content-generator');
$aipkit_task_cw_seo_profile_key = isset($aipkit_task_cw_seo_profile['profile']) ? (string) $aipkit_task_cw_seo_profile['profile'] : 'aipkit';
$aipkit_task_cw_seo_profile_logo_url = isset($aipkit_task_cw_seo_profile['logo_url']) ? (string) $aipkit_task_cw_seo_profile['logo_url'] : '';
$aipkit_task_cw_seo_profile_logo_initials = isset($aipkit_task_cw_seo_profile['logo_initials']) ? (string) $aipkit_task_cw_seo_profile['logo_initials'] : 'AI';
$aipkit_task_cw_has_seo_plugin = isset($aipkit_task_cw_seo_profile['plugin']) && (string) $aipkit_task_cw_seo_profile['plugin'] !== 'none';
$aipkit_task_cw_smart_seo_promo_description = $aipkit_task_cw_has_seo_plugin
    ? sprintf(
        /* translators: %s: active SEO plugin name. */
        __('Automatically rewrites content until it scores higher with %s.', 'gpt3-ai-content-generator'),
        $aipkit_task_cw_seo_profile_label
    )
    : __('Automatically rewrites content against standard SEO rules.', 'gpt3-ai-content-generator');
$aipkit_task_cw_seo_default_disabled_rules = class_exists('\\WPAICG\\ContentWriter\\SEO\\AIPKit_Content_Writer_SEO_Config')
    ? \WPAICG\ContentWriter\SEO\AIPKit_Content_Writer_SEO_Config::default_disabled_rules()
    : '[]';
$aipkit_task_cw_smart_seo_upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
?>

<div
    class="aipkit_cw_ai_row aipkit_autogpt_question_row aipkit_cw_seo_settings_row aipkit_cw_smart_seo_feature_card aipkit_task_cw_smart_seo_settings_row<?php echo $aipkit_task_cw_smart_seo_is_pro ? '' : ' is-pro-locked aipkit_task_cw_smart_seo_settings_row--promo'; ?>"
    data-aipkit-task-smart-seo-settings-row
    data-aipkit-seo-active-profile="<?php echo esc_attr($aipkit_task_cw_seo_profile_key); ?>"
    data-aipkit-seo-active-profile-label="<?php echo esc_attr($aipkit_task_cw_seo_profile_label); ?>"
    data-aipkit-seo-has-plugin="<?php echo $aipkit_task_cw_has_seo_plugin ? '1' : '0'; ?>"
>
    <?php if ($aipkit_task_cw_smart_seo_is_pro) : ?>
        <?php aipkit_render_paid_automation_seo_panel('toggle', [
            'label' => $aipkit_task_cw_seo_profile_label,
            'has_plugin' => $aipkit_task_cw_has_seo_plugin,
        ]); ?>
    <?php else : ?>
    <div class="aipkit_cw_panel_label_wrap">

            <span class="aipkit_autogpt_smart_seo_promo_copy">
                <span class="aipkit_autogpt_smart_seo_promo_icon" aria-hidden="true">&#10022;</span>
                <span class="aipkit_autogpt_smart_seo_promo_text">
                    <strong><?php esc_html_e('Smart SEO', 'gpt3-ai-content-generator'); ?></strong>
                    <span><?php echo esc_html($aipkit_task_cw_smart_seo_promo_description); ?></span>
                </span>
            </span>

    </div>
    <div class="aipkit_cw_ai_control aipkit_cw_ai_control--compact">
        <div class="aipkit_cw_seo_inline_actions">

                <a
                    class="aipkit_btn aipkit_btn-primary aipkit_autogpt_seo_inline_upgrade aipkit_pro_upgrade_button"
                    href="<?php echo esc_url($aipkit_task_cw_smart_seo_upgrade_url); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                </a>
                <input type="hidden" name="seo_score_improvement_enabled" value="0" data-aipkit-task-smart-seo-control>

        </div>
    </div>
    <?php endif; ?>
    <input type="hidden" name="seo_score_continue_until_target" value="1" data-aipkit-task-smart-seo-control>
    <input type="hidden" name="seo_score_target" value="100" data-aipkit-task-smart-seo-control>
    <input type="hidden" name="seo_score_max_passes" value="3" data-aipkit-task-smart-seo-control>
    <input type="hidden" name="seo_score_profile" value="auto" data-aipkit-task-smart-seo-control>
    <input type="hidden" name="seo_score_disabled_rules" value="<?php echo esc_attr($aipkit_task_cw_seo_default_disabled_rules); ?>" data-aipkit-task-smart-seo-control data-aipkit-smart-seo-disabled-rules>
</div>

<?php if ($aipkit_task_cw_smart_seo_is_pro) { aipkit_render_paid_automation_seo_panel('approach'); } ?>

<div class="aipkit_cw_ai_row aipkit_autogpt_question_row aipkit_autogpt_seo_output_row">
    <div class="aipkit_cw_panel_label_wrap">
        <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_generate_seo_slug">
            <?php esc_html_e('Optimize URL', 'gpt3-ai-content-generator'); ?>
        </label>
        <span class="aipkit_autogpt_question_helper">
            <?php esc_html_e('Create a concise, search-friendly URL.', 'gpt3-ai-content-generator'); ?>
        </span>
    </div>
    <div class="aipkit_cw_ai_control aipkit_cw_ai_control--compact">
        <select
            id="aipkit_task_cw_generate_seo_slug"
            name="generate_seo_slug"
            class="aipkit_autosave_trigger"
            data-aipkit-segmented-select
        >
            <option value="0" selected><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            <option value="1"><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
        </select>
    </div>
</div>

<div class="aipkit_cw_ai_row aipkit_autogpt_question_row aipkit_autogpt_seo_output_row">
    <div class="aipkit_cw_panel_label_wrap">
        <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_generate_toc">
            <?php esc_html_e('Table of contents', 'gpt3-ai-content-generator'); ?>
        </label>
        <span class="aipkit_autogpt_question_helper">
            <?php esc_html_e('Add navigation links to each post.', 'gpt3-ai-content-generator'); ?>
        </span>
    </div>
    <div class="aipkit_cw_ai_control aipkit_cw_ai_control--compact">
        <select
            id="aipkit_task_cw_generate_toc"
            name="generate_toc"
            class="aipkit_autosave_trigger"
            data-aipkit-segmented-select
        >
            <option value="0" selected><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            <option value="1"><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
        </select>
    </div>
</div>

<?php
if ($aipkit_task_cw_smart_seo_is_pro) {
    aipkit_render_paid_automation_seo_panel('popover', [
        'key' => $aipkit_task_cw_seo_profile_key,
        'label' => $aipkit_task_cw_seo_profile_label,
        'logo_url' => $aipkit_task_cw_seo_profile_logo_url,
        'logo_initials' => $aipkit_task_cw_seo_profile_logo_initials,
    ]);
}
?>
