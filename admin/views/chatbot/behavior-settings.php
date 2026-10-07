<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (!defined('ABSPATH')) {
    exit;
}

$bot_id = $initial_active_bot_id;
// Each workspace tab names the sections it shows.
$behavior_section_keys = isset($aipkit_behavior_section_keys) && is_array($aipkit_behavior_section_keys)
    ? $aipkit_behavior_section_keys
    : ['features', 'limits'];
unset($aipkit_behavior_section_keys);

if (in_array('features', $behavior_section_keys, true)) :
    $is_web_on = in_array($current_provider_for_this_bot, ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'xAI'], true) && (
        ['OpenAI' => $openai_web_search_enabled_val, 'Google' => $google_search_grounding_enabled_val, 'Claude' => $claude_web_search_enabled_val,
            'OpenRouter' => $openrouter_web_search_enabled_val, 'xAI' => $xai_web_search_enabled_val ?? '0'][$current_provider_for_this_bot] ?? '0'
    ) === '1';
    $is_starters_on = (string) ($active_bot_settings['enable_conversation_starters'] ?? \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_STARTERS) === '1';
    $is_consent_on = $consent_feature_available && (string) ($active_bot_settings['enable_consent_compliance'] ?? '0') === '1';
    $is_uploads_on = ($file_upload_toggle_value ?? '0') === '1' || ($enable_image_upload ?? '0') === '1';
    $is_voice_on = ($enable_voice_input ?? '0') === '1' || ($tts_enabled ?? '0') === '1' || ($is_pro_plan && ($enable_realtime_voice ?? '0') === '1');
    $is_image_on = ($enable_image_generation ?? '0') === '1';

    ?>
    <div class="aipkit_features" data-aipkit-feature-panels>
        <?php // Starters, consent and the window switches share one interface-controls root with their saved fields. ?>
        <div class="aipkit_features_main aipkit_general_features_section" data-aipkit-interface-controls>
            <section class="aipkit_settings_card aipkit_feature_card" aria-labelledby="aipkit_features_popular_title">
                <div class="aipkit_settings_card_header">
                    <h3 class="aipkit_settings_card_title" id="aipkit_features_popular_title"><?php esc_html_e('Popular', 'gpt3-ai-content-generator'); ?></h3>
                    <p class="aipkit_settings_card_hint"><?php esc_html_e('What most chatbots turn on.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <div class="aipkit_feature_rows">
                    <?php
                    $render_feature_row([
                        'key' => 'starters',
                        'icon' => 'format-chat',
                        'title' => __('Suggested questions', 'gpt3-ai-content-generator'),
                        'hint' => __('Questions visitors can tap to start.', 'gpt3-ai-content-generator'),
                        'state' => $is_starters_on,
                    ]);
                    $render_feature_row([
                        'key' => 'consent',
                        'icon' => 'shield',
                        'title' => __('Privacy notice', 'gpt3-ai-content-generator'),
                        'hint' => __('Ask visitors to accept before chatting.', 'gpt3-ai-content-generator'),
                        'state' => $is_consent_on,
                        'pro' => true,
                        'locked' => !$consent_feature_available,
                    ]);
                    $render_feature_row([
                        'key' => 'web',
                        'icon' => 'admin-site-alt3',
                        'title' => __('Web search', 'gpt3-ai-content-generator'),
                        'hint' => __('Finds current info your pages don\'t have.', 'gpt3-ai-content-generator'),
                        'state' => $is_web_on,
                    ]);
                    $render_feature_row([
                        'key' => 'uploads',
                        'icon' => 'upload',
                        'title' => __('Uploads', 'gpt3-ai-content-generator'),
                        'hint' => __('Visitors share files, PDFs or photos.', 'gpt3-ai-content-generator'),
                        'state' => $is_uploads_on,
                    ]);
                    ?>
                </div>
            </section>

            <section class="aipkit_settings_card aipkit_feature_card" aria-labelledby="aipkit_features_window_title">
                <div class="aipkit_settings_card_header">
                    <h3 class="aipkit_settings_card_title" id="aipkit_features_window_title"><?php esc_html_e('Chat window', 'gpt3-ai-content-generator'); ?></h3>
                    <p class="aipkit_settings_card_hint"><?php esc_html_e('Small extras in the chat window.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <?php
                $aipkit_interface_part = 'window';
                include __DIR__ . '/interface-feature-settings.php';
                ?>
            </section>

            <section class="aipkit_settings_card aipkit_feature_card" aria-labelledby="aipkit_features_abilities_title">
                <div class="aipkit_settings_card_header">
                    <h3 class="aipkit_settings_card_title" id="aipkit_features_abilities_title"><?php esc_html_e('More abilities', 'gpt3-ai-content-generator'); ?></h3>
                    <p class="aipkit_settings_card_hint"><?php esc_html_e('These use more credits per answer.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <div class="aipkit_feature_rows">
                    <?php
                    $render_feature_row([
                        'key' => 'voice',
                        'icon' => 'microphone',
                        'title' => __('Voice', 'gpt3-ai-content-generator'),
                        'hint' => __('Speak to it, hear replies, or talk live.', 'gpt3-ai-content-generator'),
                        'state' => $is_voice_on,
                    ]);
                    $render_feature_row([
                        'key' => 'image',
                        'icon' => 'format-image',
                        'title' => __('Image replies', 'gpt3-ai-content-generator'),
                        'hint' => __('Creates pictures when asked.', 'gpt3-ai-content-generator'),
                        'state' => $is_image_on,
                    ]);
                    ?>
                </div>
            </section>

            <section class="aipkit_settings_card aipkit_feature_card" aria-labelledby="aipkit_features_automations_title">
                <div class="aipkit_settings_card_header">
                    <h3 class="aipkit_settings_card_title" id="aipkit_features_automations_title">
                        <?php esc_html_e('Automations', 'gpt3-ai-content-generator'); ?>
                        <?php if (!$is_pro_plan) : ?>
                            <span class="aipkit_general_settings_section_badge aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </h3>
                    <p class="aipkit_settings_card_hint"><?php esc_html_e('Connect the chat to your other tools.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <div class="aipkit_feature_rows">
                    <?php
                    $apps_default_summary = __('Send new chats to Slack, HubSpot, Notion and more.', 'gpt3-ai-content-generator');
                    $render_feature_row([
                        'key' => 'apps',
                        'icon' => 'admin-plugins',
                        'title' => __('Connected apps', 'gpt3-ai-content-generator'),
                        'hint' => $connected_apps_summary_text ?: $apps_default_summary,
                        'hint_attributes' => ' data-aipkit-connected-apps-section-summary data-default-summary="' . esc_attr($apps_default_summary) . '"'
                            /* translators: 1: number of apps, 2: their names, such as Slack, HubSpot. */
                            . ' data-format-one="' . esc_attr__('%1$d app: %2$s', 'gpt3-ai-content-generator') . '"'
                            /* translators: 1: number of apps, 2: their names, such as Slack, HubSpot. */
                            . ' data-format-many="' . esc_attr__('%1$d apps: %2$s', 'gpt3-ai-content-generator') . '"',
                    ]);
                    $rules_default_summary = __('When a visitor says something, reply or act automatically.', 'gpt3-ai-content-generator');
                    $render_feature_row([
                        'key' => 'rules',
                        'icon' => 'randomize',
                        'title' => __('Rules', 'gpt3-ai-content-generator'),
                        'hint' => $rules_default_summary,
                        'hint_attributes' => ' data-aipkit-rules-section-summary data-default-summary="' . esc_attr($rules_default_summary) . '"'
                            /* translators: %d: number of rules. */
                            . ' data-format-one="' . esc_attr__('%d rule', 'gpt3-ai-content-generator') . '"'
                            /* translators: %d: number of rules. */
                            . ' data-format-many="' . esc_attr__('%d rules', 'gpt3-ai-content-generator') . '"'
                            /* translators: %d: number of rules that are on. */
                            . ' data-format-on="' . esc_attr__('%d on', 'gpt3-ai-content-generator') . '"',
                    ]);
                    ?>
                </div>
            </section>

            <?php $render_drawer_start('starters', __('Suggested questions', 'gpt3-ai-content-generator'), __('Give visitors a first message to tap. Up to 6.', 'gpt3-ai-content-generator')); ?>
                <?php
                $aipkit_interface_part = 'starters';
                include __DIR__ . '/interface-feature-settings.php';
                ?>
            <?php $render_drawer_end(); ?>

            <?php if ($consent_feature_available) : ?>
                <?php $render_drawer_start('consent', __('Privacy notice', 'gpt3-ai-content-generator'), __('Visitors accept your terms before they can chat.', 'gpt3-ai-content-generator')); ?>
                    <?php
                    $aipkit_interface_part = 'consent';
                    include __DIR__ . '/interface-feature-settings.php';
                    ?>
                <?php $render_drawer_end(); ?>
            <?php endif; ?>

            <?php
            $aipkit_interface_part = 'hidden';
            include __DIR__ . '/interface-feature-settings.php';
            unset($aipkit_interface_part);
            ?>
        </div>

        <?php
        $tools_drawers = [
            'web' => [__('Web search', 'gpt3-ai-content-generator'), __('Let the chatbot look up current information online.', 'gpt3-ai-content-generator')],
            'uploads' => [__('Uploads', 'gpt3-ai-content-generator'), __('Let visitors add documents or photos to the chat.', 'gpt3-ai-content-generator')],
            'voice' => [__('Voice', 'gpt3-ai-content-generator'), __('Let visitors talk to your chatbot and hear it reply.', 'gpt3-ai-content-generator')],
            'image' => [__('Image replies', 'gpt3-ai-content-generator'), __('Let the chatbot create pictures when asked.', 'gpt3-ai-content-generator')],
        ];
        foreach ($tools_drawers as $aipkit_tools_part => $tools_drawer) :
            $render_drawer_start($aipkit_tools_part, $tools_drawer[0], $tools_drawer[1]);
            ?>
                <div class="aipkit_general_capabilities_section aipkit_settings_panel_body" data-aipkit-settings-panel="tools">
                    <?php include __DIR__ . '/tools-settings.php'; ?>
                </div>
            <?php
            $render_drawer_end();
        endforeach;
        unset($aipkit_tools_part);
        ?>

        <?php $render_drawer_start('rules', __('Rules', 'gpt3-ai-content-generator'), __('Reply or act automatically when something happens in a chat.', 'gpt3-ai-content-generator')); ?>
            <?php
            // Pro lists and edits this chatbot's rules; Free sees how a rule works, with examples.
            $rules_view = $triggers_available && defined('WPAICG_LIB_DIR') ? WPAICG_LIB_DIR . 'views/chatbot/trigger-builder.php' : '';
            if ($rules_view !== '' && is_file($rules_view)) :
                $triggers_json = $active_bot_settings['triggers_json'] ?? '[]';
                include $rules_view;
            else :
                ?>
                <div class="aipkit_panel_stack">
                    <div class="aipkit_panel_pro">
                        <span class="aipkit_panel_pro_title">
                            <?php esc_html_e('Part of AI Puffer Pro', 'gpt3-ai-content-generator'); ?>
                            <span class="aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                        </span>
                        <span class="aipkit_panel_pro_text"><?php esc_html_e('Set exact answers and actions for the moments that matter, without changing your instructions.', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                    <section class="aipkit_panel_section" aria-labelledby="aipkit_rules_how_title">
                        <h4 class="aipkit_panel_section_title" id="aipkit_rules_how_title"><?php esc_html_e('How a rule works', 'gpt3-ai-content-generator'); ?></h4>
                        <ol class="aipkit_rules_steps">
                            <?php
                            foreach ([
                                [__('When', 'gpt3-ai-content-generator'), __('A visitor sends a message, a chat starts, a form is sent or something goes wrong.', 'gpt3-ai-content-generator')],
                                [__('Only if', 'gpt3-ai-content-generator'), __('Optional checks: words in the message, the page, who they are.', 'gpt3-ai-content-generator')],
                                [__('Then', 'gpt3-ai-content-generator'), __('Reply, show a form, add instructions, block it or send to a webhook.', 'gpt3-ai-content-generator')],
                            ] as [$step_title, $step_hint]) :
                                ?>
                                <li class="aipkit_rules_step">
                                    <span class="aipkit_rules_step_title"><?php echo esc_html($step_title); ?></span>
                                    <span class="aipkit_rules_step_hint"><?php echo esc_html($step_hint); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                    <section class="aipkit_panel_section" aria-labelledby="aipkit_rules_examples_title">
                        <h4 class="aipkit_panel_section_title" id="aipkit_rules_examples_title"><?php esc_html_e('For example', 'gpt3-ai-content-generator'); ?></h4>
                        <div class="aipkit_panel_list">
                            <?php
                            foreach ([
                                ['format-chat', __('Answer price questions the same way every time', 'gpt3-ai-content-generator'), __('A visitor sends a message · if it mentions price or cost → reply', 'gpt3-ai-content-generator')],
                                ['feedback', __('Ask for contact details on the pricing page', 'gpt3-ai-content-generator'), __('A chat starts · if the page title contains “Pricing” → show a form', 'gpt3-ai-content-generator')],
                                ['shield', __('Stop messages that share phone numbers', 'gpt3-ai-content-generator'), __('A visitor sends message · if it looks like a phone number → block it', 'gpt3-ai-content-generator')],
                                ['admin-links', __('Tell your team when something breaks', 'gpt3-ai-content-generator'), __('Something goes wrong → send to a webhook', 'gpt3-ai-content-generator')],
                            ] as [$example_icon, $example_title, $example_summary]) :
                                ?>
                                <div class="aipkit_panel_row">
                                    <div class="aipkit_panel_row_main">
                                        <span class="aipkit_rule_icon dashicons dashicons-<?php echo esc_attr($example_icon); ?>" aria-hidden="true"></span>
                                        <span class="aipkit_panel_row_copy">
                                            <span class="aipkit_panel_row_title"><?php echo esc_html($example_title); ?></span>
                                            <span class="aipkit_panel_row_meta"><?php echo esc_html($example_summary); ?></span>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        <?php
        $is_pro_plan
            ? $render_drawer_end()
            : $render_drawer_end(__('Included in every Pro plan.', 'gpt3-ai-content-generator'), ['label' => __('Upgrade to Pro', 'gpt3-ai-content-generator'), 'url' => $pricing_url]);
        ?>

        <?php $render_drawer_start('apps', __('Connected apps', 'gpt3-ai-content-generator'), __('Send chats from this chatbot to your other tools.', 'gpt3-ai-content-generator')); ?>
            <?php include __DIR__ . '/connected-apps-settings.php'; ?>
        <?php
        // Free plans see what the apps do, with Upgrade where Done would be.
        $is_pro_plan
            ? $render_drawer_end(__('Set up, edit or pause apps in Settings.', 'gpt3-ai-content-generator'))
            : $render_drawer_end(__('Included in every Pro plan.', 'gpt3-ai-content-generator'), ['label' => __('Upgrade to Pro', 'gpt3-ai-content-generator'), 'url' => $pricing_url]);
        ?>
    </div>
<?php endif; ?>

<?php if (in_array('limits', $behavior_section_keys, true)) : ?>
    <?php // The card is the limits panel, so its rows and both side panels save together. ?>
    <section class="aipkit_settings_card aipkit_feature_card" id="aipkit_general_limits_panel" aria-labelledby="aipkit_general_limits_panel_title" data-aipkit-settings-panel="limits">
        <div class="aipkit_settings_card_header">
            <h3 class="aipkit_settings_card_title" id="aipkit_general_limits_panel_title"><?php esc_html_e('Usage limits', 'gpt3-ai-content-generator'); ?></h3>
            <p class="aipkit_settings_card_hint"><?php esc_html_e('Keep heavy use from spending all your credits.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <?php include __DIR__ . '/limits-settings.php'; ?>
    </section>
<?php endif; ?>
