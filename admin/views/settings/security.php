<?php
/**
 * Partial: Security Settings Page
 */
if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\Core\Moderation\AIPKit_Global_Security_Settings;

$aipkit_security_settings = class_exists(AIPKit_Global_Security_Settings::class)
    ? AIPKit_Global_Security_Settings::get_settings()
    : array(
        'enable_ip_anonymization' => '0',
        'blocklists' => array(
            'banned_words' => '',
            'banned_words_message' => '',
            'banned_ips' => '',
            'banned_ips_message' => '',
        ),
    );
$aipkit_security_blocklists = isset($aipkit_security_settings['blocklists']) && is_array($aipkit_security_settings['blocklists'])
    ? $aipkit_security_settings['blocklists']
    : array();
$aipkit_ip_anonymization_enabled = isset($aipkit_security_settings['enable_ip_anonymization'])
    && (string) $aipkit_security_settings['enable_ip_anonymization'] === '1';
$aipkit_blocked_words = (string) ($aipkit_security_blocklists['banned_words'] ?? '');
$aipkit_blocked_words_message = (string) ($aipkit_security_blocklists['banned_words_message'] ?? '');
$aipkit_blocked_ips = (string) ($aipkit_security_blocklists['banned_ips'] ?? '');
$aipkit_blocked_ips_message = (string) ($aipkit_security_blocklists['banned_ips_message'] ?? '');
$aipkit_count_blocked = static function (string $value): int {
    $entries = array_filter(array_map('trim', preg_split('/[,\r\n]+/', $value) ?: []), 'strlen');
    return count(array_unique(array_map('strtolower', $entries)));
};
$aipkit_blocked_summary = static function (int $count): string {
    return $count > 0
        /* translators: %s: number of blocked words or IP addresses. */
        ? sprintf(_n('%s blocked', '%s blocked', $count, 'gpt3-ai-content-generator'), number_format_i18n($count))
        : __('None yet', 'gpt3-ai-content-generator');
};
?>

<div class="aipkit_settings_list aipkit_settings_security" id="aipkit_settings_security">
    <?php if (\WPAICG\AIPKit_Role_Manager::user_can_manage_role_manager()) : ?>
        <a class="aipkit_settings_list_row aipkit_settings_list_row--link" href="<?php echo esc_url(admin_url('admin.php?page=aipkit-role-manager')); ?>">
            <span class="aipkit_settings_list_icon dashicons dashicons-groups" aria-hidden="true"></span>
            <span class="aipkit_settings_list_copy">
                <span class="aipkit_settings_list_title"><?php esc_html_e('Who can use AI Puffer', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_settings_list_meta"><?php esc_html_e('Choose which WordPress roles can open each tool.', 'gpt3-ai-content-generator'); ?></span>
            </span>
            <span class="aipkit_settings_list_chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
        </a>
    <?php endif; ?>

    <?php
    // Both blocklists open the same side panel as the rest of Settings: the list to block, then the reply visitors see.
    $aipkit_blocklist_panels = [
        'words' => [
            'icon' => 'format-chat',
            'title' => __('Blocked words', 'gpt3-ai-content-generator'),
            'hint' => __('Visitors can’t send messages that contain these, in chatbots, AI forms and image prompts.', 'gpt3-ai-content-generator'),
            'list_label' => __('Words and phrases', 'gpt3-ai-content-generator'),
            'add' => __('Add a word or phrase', 'gpt3-ai-content-generator'),
            'value' => $aipkit_blocked_words,
            'field' => 'banned_words',
            'message' => $aipkit_blocked_words_message,
            // The reply visitors get when this is left empty, as the moderation check words it.
            'default_reply' => __('Sorry, your message could not be sent as it contains prohibited words.', 'gpt3-ai-content-generator'),
            /* translators: %s: blocked word or phrase. */
            'remove' => __('Remove %s', 'gpt3-ai-content-generator'),
        ],
        'ips' => [
            'icon' => 'dismiss',
            'title' => __('Blocked IP addresses', 'gpt3-ai-content-generator'),
            'hint' => __('Visitors from these addresses can’t use chatbots, AI forms or image prompts.', 'gpt3-ai-content-generator'),
            'list_label' => __('IP addresses', 'gpt3-ai-content-generator'),
            'add' => __('Add an IP address', 'gpt3-ai-content-generator'),
            'value' => $aipkit_blocked_ips,
            'field' => 'banned_ips',
            'message' => $aipkit_blocked_ips_message,
            'default_reply' => __('Access from your IP address has been blocked.', 'gpt3-ai-content-generator'),
            /* translators: %s: IP address. */
            'remove' => __('Remove %s', 'gpt3-ai-content-generator'),
        ],
    ];
    foreach ($aipkit_blocklist_panels as $aipkit_blocklist_key => $aipkit_blocklist) :
        $aipkit_blocklist_modal = 'aipkit_settings_security_' . $aipkit_blocklist_key . '_modal';
        $aipkit_blocklist_input = 'aipkit_settings_blocked_' . $aipkit_blocklist_key . '_input';
        ?>
        <div class="aipkit_settings_security_panel_row" data-aipkit-security-group="<?php echo esc_attr($aipkit_blocklist_key); ?>">
            <button
                type="button"
                class="aipkit_settings_list_row aipkit_settings_list_row--button"
                data-aipkit-provider-settings-open="security-<?php echo esc_attr($aipkit_blocklist_key); ?>"
                aria-haspopup="dialog"
                aria-controls="<?php echo esc_attr($aipkit_blocklist_modal); ?>"
            >
                <span class="aipkit_settings_list_icon dashicons dashicons-<?php echo esc_attr($aipkit_blocklist['icon']); ?>" aria-hidden="true"></span>
                <span class="aipkit_settings_list_copy">
                    <span class="aipkit_settings_list_title"><?php echo esc_html($aipkit_blocklist['title']); ?></span>
                    <span class="aipkit_settings_list_meta" data-aipkit-security-summary><?php echo esc_html($aipkit_blocked_summary($aipkit_count_blocked($aipkit_blocklist['value']))); ?></span>
                </span>
                <span class="aipkit_settings_list_chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            </button>

            <div class="aipkit-modal-overlay aipkit_settings_provider_modal" id="<?php echo esc_attr($aipkit_blocklist_modal); ?>" data-aipkit-provider-modal="security-<?php echo esc_attr($aipkit_blocklist_key); ?>" aria-hidden="true">
                <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($aipkit_blocklist_modal); ?>_title">
                    <div class="aipkit_settings_provider_panel_header">
                        <span class="aipkit_settings_provider_logo aipkit_settings_answer_defaults_logo" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr($aipkit_blocklist['icon']); ?>"></span></span>
                        <div class="aipkit_settings_provider_panel_heading">
                            <h2 class="aipkit_settings_provider_panel_title" id="<?php echo esc_attr($aipkit_blocklist_modal); ?>_title"><?php echo esc_html($aipkit_blocklist['title']); ?></h2>
                            <p class="aipkit_settings_provider_panel_hint"><?php echo esc_html($aipkit_blocklist['hint']); ?></p>
                        </div>
                        <button type="button" class="aipkit_settings_provider_panel_close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                    </div>
                    <div class="aipkit_settings_provider_panel_body">
                        <section class="aipkit_settings_provider_panel_section aipkit_settings_security_field">
                            <label class="aipkit_settings_provider_panel_section_title" for="<?php echo esc_attr($aipkit_blocklist_input); ?>"><?php echo esc_html($aipkit_blocklist['list_label']); ?></label>
                            <div
                                class="aipkit_settings_security_chip_editor"
                                data-aipkit-security-chip-editor="<?php echo esc_attr($aipkit_blocklist_key); ?>"
                                data-remove-label="<?php echo esc_attr($aipkit_blocklist['remove']); ?>"
                                <?php if ($aipkit_blocklist_key === 'ips') : ?>
                                    <?php /* translators: %s: invalid IP address. */ ?>
                                    data-invalid-ip-message="<?php esc_attr_e('%s is not a valid IP address.', 'gpt3-ai-content-generator'); ?>"
                                    <?php /* translators: %s: number of invalid IP address entries. */ ?>
                                    data-invalid-ip-count-message="<?php esc_attr_e('%s entries are not valid IP addresses.', 'gpt3-ai-content-generator'); ?>"
                                <?php endif; ?>
                            >
                                <div class="aipkit_settings_security_chip_list" data-aipkit-security-chip-list></div>
                                <input
                                    type="text"
                                    id="<?php echo esc_attr($aipkit_blocklist_input); ?>"
                                    class="aipkit_settings_security_chip_input"
                                    placeholder="<?php echo esc_attr($aipkit_blocklist['add']); ?>"
                                    autocomplete="off"
                                    aria-describedby="aipkit_settings_blocked_<?php echo esc_attr($aipkit_blocklist_key); ?>_help<?php echo $aipkit_blocklist_key === 'ips' ? ' aipkit_settings_blocked_ips_error' : ''; ?>"
                                    <?php echo $aipkit_blocklist_key === 'ips' ? 'aria-invalid="false"' : ''; ?>
                                    data-lpignore="true"
                                    data-1p-ignore="true"
                                    data-form-type="other"
                                />
                            </div>
                            <textarea
                                id="aipkit_settings_blocked_<?php echo esc_attr($aipkit_blocklist_key); ?>"
                                name="security[blocklists][<?php echo esc_attr($aipkit_blocklist['field']); ?>]"
                                class="aipkit_settings_security_source"
                                hidden
                            ><?php echo esc_textarea($aipkit_blocklist['value']); ?></textarea>
                            <?php if ($aipkit_blocklist_key === 'ips') : ?>
                                <p class="aipkit_settings_security_error" id="aipkit_settings_blocked_ips_error" role="alert" hidden></p>
                            <?php endif; ?>
                            <p class="aipkit_settings_provider_option_help" id="aipkit_settings_blocked_<?php echo esc_attr($aipkit_blocklist_key); ?>_help"><?php esc_html_e('Press Enter to add. Paste a list to add several.', 'gpt3-ai-content-generator'); ?></p>
                        </section>

                        <section class="aipkit_settings_provider_panel_section">
                            <label class="aipkit_settings_provider_panel_section_title" for="aipkit_settings_blocked_<?php echo esc_attr($aipkit_blocklist_key); ?>_message"><?php esc_html_e('Reply shown instead', 'gpt3-ai-content-generator'); ?></label>
                            <textarea
                                id="aipkit_settings_blocked_<?php echo esc_attr($aipkit_blocklist_key); ?>_message"
                                name="security[blocklists][<?php echo esc_attr($aipkit_blocklist['field']); ?>_message]"
                                class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_security_message"
                                rows="3"
                                placeholder="<?php echo esc_attr($aipkit_blocklist['default_reply']); ?>"
                                autocomplete="off"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-form-type="other"
                            ><?php echo esc_textarea($aipkit_blocklist['message']); ?></textarea>
                            <p class="aipkit_settings_provider_option_help"><?php esc_html_e('Leave it empty to use the reply shown.', 'gpt3-ai-content-generator'); ?></p>
                        </section>
                    </div>
                    <div class="aipkit_settings_provider_modal_footer">
                        <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="aipkit_settings_list_row">
        <span class="aipkit_settings_list_icon dashicons dashicons-hidden" aria-hidden="true"></span>
        <label class="aipkit_settings_list_copy" for="aipkit_settings_enable_ip_anonymization">
            <span class="aipkit_settings_list_title"><?php esc_html_e('Anonymize visitor IPs', 'gpt3-ai-content-generator'); ?></span>
            <span class="aipkit_settings_list_meta"><?php esc_html_e('Store shortened IP addresses in logs.', 'gpt3-ai-content-generator'); ?></span>
        </label>
            <label class="aipkit_switch" for="aipkit_settings_enable_ip_anonymization">
                <input
                    type="checkbox"
                    id="aipkit_settings_enable_ip_anonymization"
                    name="security[enable_ip_anonymization]"
                    class="aipkit_autosave_trigger"
                    value="1"
                    aria-label="<?php esc_attr_e('Enable IP anonymization', 'gpt3-ai-content-generator'); ?>"
                    <?php checked($aipkit_ip_anonymization_enabled); ?>
                />
                <span class="aipkit_switch_slider" aria-hidden="true"></span>
            </label>
    </div>
</div>
