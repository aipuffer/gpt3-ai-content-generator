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
?>

<div class="aipkit_settings_security" id="aipkit_settings_security">
    <section class="aipkit_settings_security_section" aria-labelledby="aipkit_settings_security_privacy_title">
        <h4 class="aipkit_settings_security_section_title" id="aipkit_settings_security_privacy_title">
            <?php esc_html_e('Privacy', 'gpt3-ai-content-generator'); ?>
        </h4>
        <div class="aipkit_settings_security_toggle_row">
            <label class="aipkit_form-label aipkit_settings_security_toggle_copy" for="aipkit_settings_enable_ip_anonymization">
                <span class="aipkit_settings_security_field_title"><?php esc_html_e('IP anonymization', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Store anonymized IPs in logs.', 'gpt3-ai-content-generator'); ?></span>
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
    </section>

    <section class="aipkit_settings_security_section" aria-labelledby="aipkit_settings_security_words_title">
        <h4 class="aipkit_settings_security_section_title" id="aipkit_settings_security_words_title">
            <?php esc_html_e('Word filtering', 'gpt3-ai-content-generator'); ?>
        </h4>

        <div class="aipkit_settings_security_field">
            <label class="aipkit_form-label" for="aipkit_settings_blocked_words_input">
                <span class="aipkit_settings_security_field_title"><?php esc_html_e('Blocked words', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Block messages containing these words or phrases.', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <div
                class="aipkit_settings_security_chip_editor"
                data-aipkit-security-chip-editor="words"
                <?php /* translators: %s: blocked word or phrase. */ ?>
                data-remove-label="<?php esc_attr_e('Remove %s', 'gpt3-ai-content-generator'); ?>"
            >
                <div class="aipkit_settings_security_chip_list" data-aipkit-security-chip-list></div>
                <input
                    type="text"
                    id="aipkit_settings_blocked_words_input"
                    class="aipkit_settings_security_chip_input"
                    placeholder="<?php esc_attr_e('Add a word or phrase', 'gpt3-ai-content-generator'); ?>"
                    autocomplete="off"
                    aria-describedby="aipkit_settings_blocked_words_help"
                    data-lpignore="true"
                    data-1p-ignore="true"
                    data-form-type="other"
                />
            </div>
            <textarea
                id="aipkit_settings_blocked_words"
                name="security[blocklists][banned_words]"
                class="aipkit_settings_security_source"
                hidden
            ><?php echo esc_textarea($aipkit_blocked_words); ?></textarea>
            <p class="aipkit_settings_security_help" id="aipkit_settings_blocked_words_help">
                <?php esc_html_e('Press Enter or comma to add. Paste a comma- or newline-separated list to add several at once.', 'gpt3-ai-content-generator'); ?>
            </p>
        </div>

        <div class="aipkit_settings_security_field">
            <label class="aipkit_form-label" for="aipkit_settings_blocked_words_message">
                <span class="aipkit_settings_security_field_title"><?php esc_html_e('Blocked words message', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Message shown when a blocked word is detected.', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <input
                type="text"
                id="aipkit_settings_blocked_words_message"
                name="security[blocklists][banned_words_message]"
                class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_security_message"
                value="<?php echo esc_attr($aipkit_blocked_words_message); ?>"
                placeholder="<?php esc_attr_e('Sorry, your message could not be sent as it contains restricted words.', 'gpt3-ai-content-generator'); ?>"
                autocomplete="off"
                data-lpignore="true"
                data-1p-ignore="true"
                data-form-type="other"
            />
        </div>
    </section>

    <section class="aipkit_settings_security_section" aria-labelledby="aipkit_settings_security_ips_title">
        <h4 class="aipkit_settings_security_section_title" id="aipkit_settings_security_ips_title">
            <?php esc_html_e('IP filtering', 'gpt3-ai-content-generator'); ?>
        </h4>

        <div class="aipkit_settings_security_field">
            <label class="aipkit_form-label" for="aipkit_settings_blocked_ips_input">
                <span class="aipkit_settings_security_field_title"><?php esc_html_e('Blocked IPs', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Block requests from these IP addresses.', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <div
                class="aipkit_settings_security_chip_editor"
                data-aipkit-security-chip-editor="ips"
                <?php /* translators: %s: IP address. */ ?>
                data-remove-label="<?php esc_attr_e('Remove %s', 'gpt3-ai-content-generator'); ?>"
                <?php /* translators: %s: invalid IP address. */ ?>
                data-invalid-ip-message="<?php esc_attr_e('%s is not a valid IP address.', 'gpt3-ai-content-generator'); ?>"
                <?php /* translators: %s: number of invalid IP address entries. */ ?>
                data-invalid-ip-count-message="<?php esc_attr_e('%s entries are not valid IP addresses.', 'gpt3-ai-content-generator'); ?>"
            >
                <div class="aipkit_settings_security_chip_list" data-aipkit-security-chip-list></div>
                <input
                    type="text"
                    id="aipkit_settings_blocked_ips_input"
                    class="aipkit_settings_security_chip_input"
                    placeholder="<?php esc_attr_e('Add an IP address', 'gpt3-ai-content-generator'); ?>"
                    autocomplete="off"
                    aria-describedby="aipkit_settings_blocked_ips_help aipkit_settings_blocked_ips_error"
                    aria-invalid="false"
                    inputmode="text"
                    data-lpignore="true"
                    data-1p-ignore="true"
                    data-form-type="other"
                />
            </div>
            <textarea
                id="aipkit_settings_blocked_ips"
                name="security[blocklists][banned_ips]"
                class="aipkit_settings_security_source"
                hidden
            ><?php echo esc_textarea($aipkit_blocked_ips); ?></textarea>
            <p class="aipkit_settings_security_error" id="aipkit_settings_blocked_ips_error" role="alert" hidden></p>
            <p class="aipkit_settings_security_help" id="aipkit_settings_blocked_ips_help">
                <?php esc_html_e('Press Enter or comma to add. Paste a comma- or newline-separated list to add several at once.', 'gpt3-ai-content-generator'); ?>
            </p>
        </div>

        <div class="aipkit_settings_security_field">
            <label class="aipkit_form-label" for="aipkit_settings_blocked_ips_message">
                <span class="aipkit_settings_security_field_title"><?php esc_html_e('Blocked IP message', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Message shown when a blocked IP is detected.', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <input
                type="text"
                id="aipkit_settings_blocked_ips_message"
                name="security[blocklists][banned_ips_message]"
                class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_security_message"
                value="<?php echo esc_attr($aipkit_blocked_ips_message); ?>"
                placeholder="<?php esc_attr_e('Access from your IP address has been blocked.', 'gpt3-ai-content-generator'); ?>"
                autocomplete="off"
                data-lpignore="true"
                data-1p-ignore="true"
                data-form-type="other"
            />
        </div>
    </section>
</div>
