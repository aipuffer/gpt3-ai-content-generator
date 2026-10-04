<?php
/** Shared in-context connection view; provider definitions come from Settings. */
if (!defined('ABSPATH')) { exit; }
?>
<div data-connect-chooser>
    <div class="aipkit_connect_choices">
        <?php foreach ($aipkit_provider_configs as $aipkit_name => $aipkit_config) :
            if (!empty($aipkit_config['requires_pro']) && !$is_pro) { continue; }
            $aipkit_is_cloud = $aipkit_name === 'AIPufferCloud';
            $aipkit_icon_url = $aipkit_is_cloud ? WPAICG_LOGO_URL : WPAICG_PLUGIN_URL . 'admin/images/providers/' . $aipkit_config['icon']; ?>
            <button type="button" class="aipkit_connect_choice<?php echo $aipkit_is_cloud ? ' aipkit_connect_choice--featured' : ''; ?>" data-connect-provider="<?php echo esc_attr($aipkit_name); ?>">
                <span class="aipkit_connect_choice_icon"><img src="<?php echo esc_url($aipkit_icon_url); ?>" alt="" width="24" height="24"></span>
                <span class="aipkit_connect_choice_text">
                    <span class="aipkit_connect_choice_name"><?php echo esc_html($aipkit_config['display_name']); ?><?php if ($aipkit_is_cloud) : ?><span class="aipkit_connect_badge"><?php esc_html_e('Free', 'gpt3-ai-content-generator'); ?></span><?php endif; ?></span>
                    <?php if ($aipkit_is_cloud) : ?><small><?php esc_html_e('No API key needed. Free monthly credits.', 'gpt3-ai-content-generator'); ?></small><?php endif; ?>
                    <span class="aipkit_connect_connected" data-connect-connected hidden><svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><?php esc_html_e('Connected', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <svg class="aipkit_connect_chevron" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M6 3.5L10.5 8 6 12.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <?php if ($aipkit_is_cloud) : ?><p class="aipkit_connect_divider"><span><?php esc_html_e('Or connect your own provider', 'gpt3-ai-content-generator'); ?></span></p><?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php foreach ($aipkit_provider_configs as $aipkit_name => $aipkit_config) :
    if (!empty($aipkit_config['requires_pro']) && !$is_pro) { continue; } ?>
    <section hidden data-connect-panel="<?php echo esc_attr($aipkit_name); ?>" data-connect-label="<?php echo esc_attr($aipkit_config['display_name']); ?>" data-connect-credential-type="<?php echo esc_attr($aipkit_config['credential_type']); ?>" data-connect-default-model="<?php echo esc_attr($aipkit_provider_data[$aipkit_name]['model'] ?? ''); ?>">
        <?php if ($aipkit_config['credential_type'] === 'account') : ?>
            <?php $aipkit_render_account_fields(); ?>
        <?php else : ?>
            <form data-connect-form>
                <label for="aipkit_connect_<?php echo esc_attr($aipkit_config['slug']); ?>"><?php echo esc_html($aipkit_config['credential_type'] === 'url' ? __('Server URL', 'gpt3-ai-content-generator') : __('API key', 'gpt3-ai-content-generator')); ?></label>
                <div class="aipkit_connect_input_wrap">
                    <input id="aipkit_connect_<?php echo esc_attr($aipkit_config['slug']); ?>" name="<?php echo esc_attr($aipkit_config['credential_key']); ?>" type="<?php echo esc_attr($aipkit_config['credential_type']); ?>" autocomplete="off" spellcheck="false" required placeholder="<?php echo esc_attr($aipkit_config['credential_placeholder']); ?>" value="<?php echo esc_attr($aipkit_provider_data[$aipkit_name][$aipkit_config['credential_key']] ?? ''); ?>">
                    <?php if ($aipkit_config['credential_type'] === 'password') : ?><button type="button" class="aipkit_connect_reveal" data-connect-reveal aria-pressed="false" aria-controls="aipkit_connect_<?php echo esc_attr($aipkit_config['slug']); ?>"><?php esc_html_e('Show', 'gpt3-ai-content-generator'); ?></button><?php endif; ?>
                </div>
                <a class="aipkit_connect_key_link" href="<?php echo esc_url($aipkit_config['key_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($aipkit_config['key_link']); ?><svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><path d="M6 3.5h6.5V10M12.5 3.5L4 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
                <?php if ($aipkit_name === 'Azure') : ?>
                    <label for="aipkit_connect_endpoint"><?php esc_html_e('Endpoint URL', 'gpt3-ai-content-generator'); ?></label>
                    <input id="aipkit_connect_endpoint" type="url" name="endpoint" required value="<?php echo esc_attr($azure_data['endpoint'] ?? ''); ?>">
                    <details><summary><?php esc_html_e('API versions', 'gpt3-ai-content-generator'); ?></summary>
                        <?php foreach ($aipkit_config['fields'] as $aipkit_field) :
                            if (strpos($aipkit_field['name'] ?? '', 'azure_api_version_') !== 0) { continue; }
                            $aipkit_field_name = substr($aipkit_field['name'], 6); ?>
                            <label for="aipkit_connect_<?php echo esc_attr($aipkit_field_name); ?>"><?php echo esc_html($aipkit_field['label']); ?></label>
                            <input id="aipkit_connect_<?php echo esc_attr($aipkit_field_name); ?>" name="<?php echo esc_attr($aipkit_field_name); ?>" value="<?php echo esc_attr($aipkit_field['value'] ?: $aipkit_field['default']); ?>" required>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
                <div class="aipkit_connect_actions"><button type="button" class="aipkit_btn" data-connect-close><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></button><button class="aipkit_btn aipkit_btn-primary" type="submit"><?php esc_html_e('Connect', 'gpt3-ai-content-generator'); ?></button></div>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
