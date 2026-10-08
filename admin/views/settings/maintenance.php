<?php
/**
 * Partial: settings backups. One action per row; the restore point says when it was saved,
 * and every restore saves the settings it replaces first, so it can be undone.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\WPAICG\Dashboard\Ajax\AIPKit_Settings_Restore_Point')) {
    require_once WPAICG_PLUGIN_DIR . 'classes/settings/restore-point.php';
}
$aipkit_restore_point = \WPAICG\Dashboard\Ajax\AIPKit_Settings_Restore_Point::summary();
?>
<?php // After a restore the page reloads; the script fills this in once, with Go back. ?>
<div
    class="aipkit_settings_backup_notice"
    data-aipkit-backup-notice
    <?php /* translators: %s: backup file name. */ ?>
    data-file-done="<?php esc_attr_e('Restored from %s.', 'gpt3-ai-content-generator'); ?>"
    <?php /* translators: %s: date and time the restore point was saved. */ ?>
    data-point-done="<?php esc_attr_e('Went back to your settings from %s.', 'gpt3-ai-content-generator'); ?>"
    data-point-done-undated="<?php esc_attr_e('Went back to the restore point.', 'gpt3-ai-content-generator'); ?>"
    role="status"
    hidden
>
    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
    <span class="aipkit_settings_backup_notice_text" data-aipkit-backup-notice-text></span>
    <button type="button" class="aipkit_settings_backup_notice_action" data-aipkit-settings-action="go-back-restore-point"><?php esc_html_e('Go back', 'gpt3-ai-content-generator'); ?></button>
</div>

<div class="aipkit_settings_list aipkit_settings_backup_list">
    <div class="aipkit_settings_list_row" id="aipkit_settings_backup_row">
        <span class="aipkit_settings_list_icon dashicons dashicons-download" aria-hidden="true"></span>
        <div class="aipkit_settings_list_copy">
            <span class="aipkit_settings_list_title"><?php esc_html_e('Download a backup', 'gpt3-ai-content-generator'); ?></span>
            <span class="aipkit_settings_list_meta"><?php esc_html_e('Your settings and API keys, as a file. Keep it private: anyone with it has your keys.', 'gpt3-ai-content-generator'); ?></span>
        </div>
        <div class="aipkit_settings_action_buttons">
            <button
                type="button"
                id="aipkit_settings_export_button"
                class="aipkit_btn aipkit_btn-secondary"
                data-aipkit-settings-action="export-backup"
                data-busy-label="<?php esc_attr_e('Downloading…', 'gpt3-ai-content-generator'); ?>"
                <?php /* translators: %s: backup file name. */ ?>
                data-done="<?php esc_attr_e('Downloaded %s.', 'gpt3-ai-content-generator'); ?>"
                data-failed="<?php esc_attr_e('The backup couldn’t be downloaded:', 'gpt3-ai-content-generator'); ?>"
            >
                <?php esc_html_e('Download', 'gpt3-ai-content-generator'); ?>
            </button>
        </div>
    </div>

    <div class="aipkit_settings_list_row" id="aipkit_settings_import_row">
        <span class="aipkit_settings_list_icon dashicons dashicons-upload" aria-hidden="true"></span>
        <div class="aipkit_settings_list_copy">
            <span class="aipkit_settings_list_title"><?php esc_html_e('Restore from a file', 'gpt3-ai-content-generator'); ?></span>
            <span class="aipkit_settings_list_meta"><?php esc_html_e('Replace these settings with a backup file, from this site or another.', 'gpt3-ai-content-generator'); ?></span>
        </div>
        <div class="aipkit_settings_action_buttons">
            <button
                type="button"
                id="aipkit_settings_import_trigger"
                class="aipkit_btn aipkit_btn-secondary"
                data-aipkit-settings-action="import-trigger"
                data-busy-label="<?php esc_attr_e('Restoring…', 'gpt3-ai-content-generator'); ?>"
                data-failed="<?php esc_attr_e('The file couldn’t be restored:', 'gpt3-ai-content-generator'); ?>"
            >
                <?php esc_html_e('Choose file…', 'gpt3-ai-content-generator'); ?>
            </button>
            <input
                type="file"
                id="aipkit_settings_import_file"
                class="aipkit_settings_hidden_file_input"
                accept=".json,application/json"
                hidden
            />
        </div>
    </div>

    <div class="aipkit_settings_list_row" id="aipkit_settings_restore_point_row">
        <span class="aipkit_settings_list_icon dashicons dashicons-backup" aria-hidden="true"></span>
        <div class="aipkit_settings_list_copy">
            <span class="aipkit_settings_list_title"><?php esc_html_e('Restore point', 'gpt3-ai-content-generator'); ?></span>
            <span class="aipkit_settings_list_meta" data-aipkit-restore-point-text role="status"><?php echo esc_html($aipkit_restore_point['text']); ?></span>
        </div>
        <div class="aipkit_settings_action_buttons">
            <button
                type="button"
                id="aipkit_settings_create_restore_point"
                class="aipkit_btn aipkit_btn-secondary"
                data-aipkit-settings-action="create-restore-point"
                data-busy-label="<?php esc_attr_e('Saving…', 'gpt3-ai-content-generator'); ?>"
                data-failed="<?php esc_attr_e('The restore point couldn’t be saved:', 'gpt3-ai-content-generator'); ?>"
            >
                <?php esc_html_e('Save now', 'gpt3-ai-content-generator'); ?>
            </button>
            <button
                type="button"
                id="aipkit_settings_restore_restore_point"
                class="aipkit_btn aipkit_btn-secondary"
                data-aipkit-settings-action="restore-restore-point"
                data-point-date="<?php echo esc_attr($aipkit_restore_point['date']); ?>"
                data-busy-label="<?php esc_attr_e('Restoring…', 'gpt3-ai-content-generator'); ?>"
                data-failed="<?php esc_attr_e('Couldn’t go back to the restore point:', 'gpt3-ai-content-generator'); ?>"
                <?php echo $aipkit_restore_point['saved'] ? '' : 'hidden'; ?>
            >
                <?php esc_html_e('Restore', 'gpt3-ai-content-generator'); ?>
            </button>
        </div>
    </div>
</div>

<p class="aipkit_settings_backup_message" data-aipkit-backup-message role="status" hidden></p>
<p class="aipkit_settings_backup_scope_note"><?php esc_html_e('A backup has AI, Tools, Connections, Security and For developers. It leaves out who can use AI Puffer, chatbots, the knowledge base, licenses and your AI Puffer Cloud connection.', 'gpt3-ai-content-generator'); ?></p>

<?php // The warnings' words, kept here so they can be translated; the script fills in the file and the date. ?>
<template data-aipkit-backup-dialogs>
    <div
        data-aipkit-backup-file-dialog
        data-title="<?php esc_attr_e('Restore settings from this file?', 'gpt3-ai-content-generator'); ?>"
        data-message="<?php esc_attr_e('These settings are replaced with the ones in the file.', 'gpt3-ai-content-generator'); ?>"
        data-confirm="<?php esc_attr_e('Restore from file', 'gpt3-ai-content-generator'); ?>"
        data-cancel="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"
        <?php /* translators: %s: the site a backup came from, e.g. shop.example.com. */ ?>
        data-from="<?php esc_attr_e('From %s', 'gpt3-ai-content-generator'); ?>"
        <?php /* translators: %s: AI Puffer version, e.g. 2.4.96. */ ?>
        data-version="<?php esc_attr_e('AI Puffer %s', 'gpt3-ai-content-generator'); ?>"
        data-other-site="<?php esc_attr_e('From another site: its API keys and Security settings come with it.', 'gpt3-ai-content-generator'); ?>"
    >
        <div class="aipkit_settings_backup_details">
            <div class="aipkit_settings_backup_file">
                <span class="dashicons dashicons-media-default" aria-hidden="true"></span>
                <span class="aipkit_settings_backup_file_copy">
                    <span class="aipkit_settings_backup_file_name" data-aipkit-backup-file-name></span>
                    <span class="aipkit_settings_backup_file_meta" data-aipkit-backup-file-meta></span>
                    <span class="aipkit_settings_backup_file_warning" data-aipkit-backup-file-warning hidden></span>
                </span>
            </div>
            <div class="aipkit_settings_backup_scope">
                <p><strong><?php esc_html_e('Replaces', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('AI providers and keys, Tools, Connections, Security and For developers.', 'gpt3-ai-content-generator'); ?></p>
                <p><strong><?php esc_html_e('Stays as it is', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Who can use AI Puffer, chatbots, the knowledge base, licenses and your AI Puffer Cloud connection.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <p class="aipkit_settings_backup_safety"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Your current settings are saved as the restore point first, so you can go back.', 'gpt3-ai-content-generator'); ?></p>
        </div>
    </div>
    <div
        data-aipkit-backup-bad-file-dialog
        data-title="<?php esc_attr_e('This file can’t be used', 'gpt3-ai-content-generator'); ?>"
        data-message="<?php esc_attr_e('It isn’t an AI Puffer settings backup, or it’s damaged. Choose the .json file you downloaded here, from Backups.', 'gpt3-ai-content-generator'); ?>"
        data-too-big="<?php esc_attr_e('Backup files must be 5 MB or smaller. Choose the .json file you downloaded here, from Backups.', 'gpt3-ai-content-generator'); ?>"
        data-confirm="<?php esc_attr_e('Choose another file', 'gpt3-ai-content-generator'); ?>"
        data-cancel="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
    ></div>
    <div
        data-aipkit-backup-restore-dialog
        data-title="<?php esc_attr_e('Go back to the restore point?', 'gpt3-ai-content-generator'); ?>"
        <?php /* translators: %s: date and time the restore point was saved. */ ?>
        data-message="<?php esc_attr_e('Your settings return to how they were on %s.', 'gpt3-ai-content-generator'); ?>"
        data-message-undated="<?php esc_attr_e('Your settings return to how they were when the restore point was saved.', 'gpt3-ai-content-generator'); ?>"
        data-confirm="<?php esc_attr_e('Restore', 'gpt3-ai-content-generator'); ?>"
        data-cancel="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"
    >
        <p class="aipkit_settings_backup_safety"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e('Your settings right now become the restore point, so you can switch back.', 'gpt3-ai-content-generator'); ?></p>
    </div>
</template>
