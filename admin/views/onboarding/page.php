<?php
/**
 * First-run setup screen. Steps are toggled client-side (admin/js/onboarding/onboarding.js);
 * setup actions use aipkit_onboarding; account email changes use the Freemius handler.
 */
if (!defined('ABSPATH')) {
    exit;
}

$aipkit_setup = \WPAICG\Admin\Onboarding::view_data();
// Match renderPower's initial state so resumed setup does not flash other paths.
$aipkit_setup_saved_goals = (array) (\WPAICG\Admin\Onboarding::state()['goals'] ?? []);
$aipkit_setup_website = in_array('website', $aipkit_setup_saved_goals, true);
$aipkit_setup_address_blocked = \WPAICG\Mcp\Settings::connection_issue() !== '';
$aipkit_setup_connection_blocked = \WPAICG\Mcp\Settings::connection_blocked();
// Website first: with other tools picked too, a site that can't be reached goes straight to them.
$aipkit_setup_website_flow = $aipkit_setup_website && (count($aipkit_setup_saved_goals) === 1 || (!$aipkit_setup_address_blocked && !$aipkit_setup_connection_blocked && empty(\WPAICG\Admin\Onboarding::state()['website_later'])));
$aipkit_setup_other_goal = array_values(array_diff($aipkit_setup_saved_goals, ['website']))[0] ?? '';
$aipkit_setup_mcp_address = (string) (\WPAICG\Mcp\Store::config()['mcp_url'] ?? 'https://mcp.aipuffer.org/mcp');
$aipkit_setup_site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
$aipkit_setup_website_ready = $aipkit_setup['cloud']['connected'] && $aipkit_setup['cloud']['websiteReady'];
$aipkit_setup_icons = [
    'website' => 'M3 4h18v16H3zM3 8h18M7 6h.01M10 6h.01M8 12l-2 2 2 2M16 12l2 2-2 2M13 11l-2 6',
    'chatbot' => 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z',
    'write' => 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
    'forms' => 'M4 5h16v14H4zM8 9h8M8 13h5',
];
$aipkit_setup_goals = [
    'website' => [__('Manage your website with AI', 'gpt3-ai-content-generator'), __('Ask ChatGPT or Claude to create content, update products and design pages, without logging in to WordPress.', 'gpt3-ai-content-generator'), __('Website management', 'gpt3-ai-content-generator')],
    'chatbot' => [__('Add a chatbot to your website', 'gpt3-ai-content-generator'), __('Customer support around the clock, from your own content.', 'gpt3-ai-content-generator'), __('Chatbot', 'gpt3-ai-content-generator')],
    'forms' => [__('Add AI forms to your website', 'gpt3-ai-content-generator'), __('Calculators, quote builders and generators your visitors can use.', 'gpt3-ai-content-generator'), __('AI Forms', 'gpt3-ai-content-generator')],
    'write' => [__('Write and schedule content', 'gpt3-ai-content-generator'), __('Posts and product descriptions, one at a time or on a schedule.', 'gpt3-ai-content-generator'), __('Content Writer', 'gpt3-ai-content-generator')],
];
// The monthly allowance each card's tool runs on; paid plans show their own amounts.
$aipkit_setup_goal_offers = ['chatbot' => $aipkit_setup['cloud']['allowanceOffer']['credits'], 'website' => $aipkit_setup['cloud']['allowanceOffer']['actions']];
$aipkit_setup_writing_tools = static function (string $current): void {
    ?><div class="aipkit-setup__row" role="group" aria-label="<?php esc_attr_e('Writing tools', 'gpt3-ai-content-generator'); ?>"><?php
    foreach (['try-write' => __('Posts', 'gpt3-ai-content-generator'), 'try-products' => __('Product descriptions', 'gpt3-ai-content-generator'), 'try-auto' => __('Schedule content', 'gpt3-ai-content-generator')] as $step => $label) {
        ?><button type="button" class="aipkit-setup__btn aipkit-setup__btn--chip" data-action="writing-mode" data-writing-step="<?php echo esc_attr($step); ?>" aria-pressed="<?php echo $step === $current ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button><?php
    }
    ?></div><?php
};
$aipkit_setup_icon = static function (string $path, int $size = 22, string $stroke = 'currentColor', string $width = '1.8'): void {
    printf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="%2$s" stroke-width="%3$s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="%4$s"></path></svg>',
        (int) $size, esc_attr($stroke), esc_attr($width), esc_attr($path)
    );
};
$aipkit_setup_check = 'M5 12l5 5 9-10';
$aipkit_setup_arrow = 'M5 12h14M13 6l6 6-6 6';
?>
<div class="aipkit-setup" id="aipkit-setup" data-site-host="<?php echo esc_attr($aipkit_setup_site_host); ?>" data-website-address-blocked="<?php echo $aipkit_setup_address_blocked ? 'true' : 'false'; ?>" data-website-connection-blocked="<?php echo $aipkit_setup_connection_blocked ? 'true' : 'false'; ?>" data-step="<?php echo $aipkit_setup['resume'] ? 'connect' : 'welcome'; ?>" data-website="<?php echo $aipkit_setup_website_flow ? 'true' : 'false'; ?>" data-power="cloud" data-cloud-ready="<?php echo $aipkit_setup['cloud']['ready'] ? 'true' : 'false'; ?>" data-website-ready="<?php echo $aipkit_setup['cloud']['websiteReady'] ? 'true' : 'false'; ?>" data-cloud-pending="<?php echo $aipkit_setup['cloud']['pendingEmail'] ? 'true' : 'false'; ?>" data-cloud="<?php echo $aipkit_setup['cloud']['connected'] ? 'connected' : 'none'; ?>">
    <header class="aipkit-setup__bar">
        <div class="aipkit-setup__brand">
            <img class="aipkit-setup__logo" src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" width="34" height="34" alt="">
            <span><?php esc_html_e('AI Puffer', 'gpt3-ai-content-generator'); ?></span>
        </div>
        <?php // Only the picked steps show: Choose · Account · Website · {first tool} · Done (onboarding.js renderProgress). ?>
        <ol class="aipkit-setup__progress" <?php echo $aipkit_setup['resume'] ? '' : 'hidden'; ?> aria-label="<?php esc_attr_e('Setup progress', 'gpt3-ai-content-generator'); ?>">
            <?php
            $aipkit_setup_steps = [
                'goals' => [__('Choose', 'gpt3-ai-content-generator'), true],
                'account' => [$aipkit_setup_website ? __('Account', 'gpt3-ai-content-generator') : __('Connect', 'gpt3-ai-content-generator'), true],
                'website' => [__('Website', 'gpt3-ai-content-generator'), $aipkit_setup_website],
                'try' => [$aipkit_setup_other_goal !== '' ? $aipkit_setup_goals[$aipkit_setup_other_goal][2] : __('Start', 'gpt3-ai-content-generator'), $aipkit_setup_other_goal !== ''],
                'done' => [__('Done', 'gpt3-ai-content-generator'), true],
            ];
            $aipkit_setup_step_number = 0;
            foreach ($aipkit_setup_steps as $aipkit_step_key => [$aipkit_step_label, $aipkit_step_shown]) :
                if ($aipkit_step_shown) { $aipkit_setup_step_number++; }
                $aipkit_step_current = $aipkit_setup['resume'] && $aipkit_step_key === ($aipkit_setup_website_flow && $aipkit_setup_website_ready ? 'website' : 'account');
                $aipkit_step_done = $aipkit_setup['resume'] && ($aipkit_step_key === 'goals' || ($aipkit_step_key === 'account' && $aipkit_setup_website_flow && $aipkit_setup_website_ready)); ?>
                <li data-for="<?php echo esc_attr($aipkit_step_key); ?>" class="<?php echo $aipkit_step_current ? 'is-current' : ($aipkit_step_done ? 'is-done' : ''); ?>" <?php echo $aipkit_step_shown ? '' : 'hidden'; ?>><span class="aipkit-setup__dot"><?php echo $aipkit_step_done ? '✓' : (int) $aipkit_setup_step_number; ?></span><span data-progress-label><?php echo esc_html($aipkit_step_label); ?></span></li>
            <?php endforeach; ?>
        </ol>
        <button type="button" class="aipkit-setup__link" data-action="skip" aria-keyshortcuts="Escape" title="<?php esc_attr_e('Esc', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Skip setup', 'gpt3-ai-content-generator'); ?></button>
    </header>

    <main class="aipkit-setup__main">
        <div class="aipkit-setup__error" role="alert" hidden></div>

        <section class="aipkit-setup__step aipkit-setup__welcome" data-step="welcome" <?php echo $aipkit_setup['resume'] ? 'hidden' : ''; ?>>
            <img class="aipkit-setup__logo aipkit-setup__logo--welcome" src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" width="72" height="72" alt="">
            <h1><?php esc_html_e('Welcome to AI Puffer', 'gpt3-ai-content-generator'); ?></h1>
            <p class="aipkit-setup__lead"><?php esc_html_e('Pick what you need and we’ll set it up. It takes a minute.', 'gpt3-ai-content-generator'); ?></p>
            <button type="button" class="aipkit-setup__btn aipkit-setup__btn--lg" data-action="start"><?php esc_html_e('Get started', 'gpt3-ai-content-generator'); ?> <?php $aipkit_setup_icon($aipkit_setup_arrow, 18, 'currentColor', '2'); ?></button>
        </section>

        <section class="aipkit-setup__step" data-step="goals" hidden>
            <div class="aipkit-setup__head">
                <h1><?php esc_html_e('What would you like to do?', 'gpt3-ai-content-generator'); ?></h1>
                <p><?php esc_html_e('Pick one or more. We set them up in this order.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <?php // Website management spans the row; the three AI tools sit below it. Each card: icon, name, free allowance, one line. ?>
            <div class="aipkit-setup__goals">
                <?php foreach ($aipkit_setup_goals as $aipkit_goal => $aipkit_goal_text) : ?>
                    <button type="button" class="aipkit-setup__card aipkit-setup__card--goal<?php echo $aipkit_goal === 'website' ? ' aipkit-setup__card--wide' : ''; ?>" data-goal="<?php echo esc_attr($aipkit_goal); ?>" data-module-name="<?php echo esc_attr($aipkit_goal_text[2]); ?>" aria-pressed="false">
                        <span class="aipkit-setup__check"><?php $aipkit_setup_icon($aipkit_setup_check, 12, '#ffffff', '3.5'); ?></span>
                        <span class="aipkit-setup__card-title">
                            <span class="aipkit-setup__card-icon"><?php $aipkit_setup_icon($aipkit_setup_icons[$aipkit_goal], 20, '#3f5fe8'); ?></span>
                            <strong><?php echo esc_html($aipkit_goal_text[0]); ?></strong>
                            <?php if (isset($aipkit_setup_goal_offers[$aipkit_goal])) : ?>
                                <span class="aipkit-setup__goal-offer" data-goal-offer="<?php echo esc_attr($aipkit_goal); ?>"><?php echo esc_html($aipkit_setup_goal_offers[$aipkit_goal]); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="aipkit-setup__muted"><?php echo esc_html($aipkit_goal_text[1]); ?></span>
                        <?php if ($aipkit_goal === 'website') : ?>
                            <?php // Plugins tested with website management (aipower.org Works with); the rest stay in "+N". ?>
                            <span class="aipkit-setup__works">
                                <span class="aipkit-setup__works-label"><?php esc_html_e('Works with', 'gpt3-ai-content-generator'); ?></span>
                                <?php foreach (['WooCommerce', 'Elementor', 'Yoast SEO'] as $aipkit_works_name) : ?>
                                    <span class="aipkit-setup__tag"><?php echo esc_html($aipkit_works_name); ?></span>
                                <?php endforeach; ?>
                                <span class="aipkit-setup__tag">+6</span>
                            </span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php // The plan for the picked goals, in the order setup runs them (onboarding.js renderGoals). ?>
            <div class="aipkit-setup__nav aipkit-setup__plan-bar">
                <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="back"><?php esc_html_e('Back', 'gpt3-ai-content-generator'); ?></button>
                <p class="aipkit-setup__plan" data-goal-plan aria-live="polite"><?php esc_html_e('Pick what you need to see your setup.', 'gpt3-ai-content-generator'); ?></p>
                <button type="button" class="aipkit-setup__btn" data-action="save-goals"><?php esc_html_e('Continue', 'gpt3-ai-content-generator'); ?></button>
            </div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__narrow" data-step="connect" <?php echo $aipkit_setup['resume'] ? '' : 'hidden'; ?>>
            <div class="aipkit-setup__head">
                <?php // The website step's own heading follows its state: link, waiting (with a pill), connected (with a check). ?>
                <span class="aipkit-setup__pill" data-connect-pill role="status" hidden></span>
                <span class="aipkit-setup__head-check" data-connect-check hidden><?php $aipkit_setup_icon($aipkit_setup_check, 24, 'currentColor', '2.6'); ?></span>
                <h1 data-connect-title><?php echo esc_html($aipkit_setup_website_flow ? __('Connect your website', 'gpt3-ai-content-generator') : __('How should AI Puffer connect to AI?', 'gpt3-ai-content-generator')); ?></h1>
                <p data-connect-sub data-default="<?php esc_attr_e('You can change this later in Settings.', 'gpt3-ai-content-generator'); ?>"><?php echo esc_html($aipkit_setup_website_flow ? __('Verify your email, then connect your assistant.', 'gpt3-ai-content-generator') : __('You can change this later in Settings.', 'gpt3-ai-content-generator')); ?></p>
                <?php if ($aipkit_setup['account_change_pending']) : ?>
                    <p role="status"><?php esc_html_e('Check the new email address for final approval of the account change. After approving it, return here and reload to see the current account.', 'gpt3-ai-content-generator'); ?></p>
                <?php endif; ?>
            </div>
            <div class="aipkit-setup__flow" data-connection-flow>
                <div class="aipkit-setup__flow-step" data-flow-step="email">
                    <span class="aipkit-setup__flow-num" data-email-num aria-hidden="true"><?php echo $aipkit_setup_website_ready ? '✓' : '1'; ?></span>
                    <div class="aipkit-setup__flow-body">
                        <p class="aipkit-setup__flow-title" data-flow-email-title><?php echo esc_html($aipkit_setup_website_ready ? __('Account ready', 'gpt3-ai-content-generator') : ($aipkit_setup['cloud']['pendingEmail'] ? __('Confirm your email', 'gpt3-ai-content-generator') : __('Create your free account', 'gpt3-ai-content-generator'))); ?></p>
                        <p class="aipkit-setup__muted" data-flow-email-summary <?php echo $aipkit_setup_website_ready ? '' : 'hidden'; ?>><?php echo esc_html($aipkit_setup['cloud']['email']); ?></p>
                        <div data-flow-email-content <?php echo $aipkit_setup_website_flow && $aipkit_setup_website_ready ? 'hidden' : ''; ?>>
                            <div class="aipkit-setup__options" data-ai-options <?php echo $aipkit_setup_website_flow && $aipkit_setup_website_ready ? 'hidden' : ''; ?>>
                                <div class="aipkit-setup__option is-on" data-power-option="cloud">
                                    <?php // The free offer, as in Settings › AI and the connect window. ?>
                                    <button type="button" class="aipkit-setup__option-head" data-action="power" data-power="cloud">
                                        <span class="aipkit-setup__radio"></span>
                                        <span class="aipkit-setup__option-text">
                                            <span class="aipkit-setup__offer-title" data-ai-credit-title hidden><?php esc_html_e('Use AI Puffer credits', 'gpt3-ai-content-generator'); ?></span>
                                            <span class="aipkit-setup__muted" data-ai-credit-balance hidden></span>
                                            <span class="aipkit-setup__offer-eyebrow" data-cloud-offer-label><?php echo esc_html($aipkit_setup['cloud']['allowanceOffer']['label']); ?></span>
                                            <span class="aipkit-setup__offer-title" data-cloud-offer><?php echo esc_html($aipkit_setup['cloud']['allowanceOffer']['amounts']); ?></span>
                                            <span class="aipkit-setup__muted" data-cloud-offer-description><?php esc_html_e('No API key needed. Start free with top models from OpenAI, Anthropic and Google.', 'gpt3-ai-content-generator'); ?></span>
                                        </span>
                                    </button>
                                    <div class="aipkit-setup__option-body">
                                        <?php // One confirmation card for pending signup and connected accounts awaiting verification; buttons reuse existing account controls. ?>
                                        <div class="aipkit-setup__inbox" data-inbox role="status" aria-live="polite">
                                            <span class="aipkit-setup__inbox-icon" aria-hidden="true"><?php $aipkit_setup_icon('M3 6h18v12H3zM3 7l9 6 9-6', 24, 'currentColor', '1.8'); ?></span>
                                            <strong class="aipkit-setup__inbox-title"><?php esc_html_e('Check your inbox', 'gpt3-ai-content-generator'); ?></strong>
                                            <p class="aipkit-setup__muted aipkit-setup__inbox-full"><?php printf(
                                                /* translators: %s: email address the confirmation link was sent to. */
                                                esc_html__('We sent a link to %s. Open it, then come back here.', 'gpt3-ai-content-generator'),
                                                '<strong data-inbox-email>' . esc_html($aipkit_setup['cloud']['email']) . '</strong>'
                                            ); ?></p>
                                            <?php // Website setup: one row like the account step, with Change next to the address. ?>
                                            <p class="aipkit-setup__muted aipkit-setup__inbox-short"><?php printf(
                                                /* translators: %s: email address the confirmation link was sent to. */
                                                esc_html__('Link sent to %s', 'gpt3-ai-content-generator'),
                                                '<strong data-inbox-email>' . esc_html($aipkit_setup['cloud']['email']) . '</strong>'
                                            ); ?><button type="button" class="aipkit-setup__link" data-action="inbox-change" aria-label="<?php esc_attr_e('Use a different email', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Change', 'gpt3-ai-content-generator'); ?></button></p>
                                            <p class="aipkit-setup__inbox-note" data-inbox-note hidden></p>
                                            <button type="button" class="aipkit-setup__btn" data-action="inbox-confirm"><span class="aipkit-setup__inbox-full"><?php esc_html_e('I’ve confirmed my email', 'gpt3-ai-content-generator'); ?></span><span class="aipkit-setup__inbox-short"><?php esc_html_e('I’ve confirmed it', 'gpt3-ai-content-generator'); ?></span></button>
                                            <div class="aipkit-setup__row aipkit-setup__inbox-full">
                                                <button type="button" class="aipkit-setup__link" data-action="inbox-resend"><?php esc_html_e('Resend email', 'gpt3-ai-content-generator'); ?></button>
                                                <button type="button" class="aipkit-setup__link" data-action="inbox-change"><?php esc_html_e('Use a different email', 'gpt3-ai-content-generator'); ?></button>
                                            </div>
                                            <p class="aipkit-setup__inbox-fine aipkit-setup__inbox-full"><?php esc_html_e('We’ll check again when you come back to this tab.', 'gpt3-ai-content-generator'); ?></p>
                                            <p class="aipkit-setup__inbox-fine aipkit-setup__inbox-short"><?php printf(
                                                /* translators: %s: "Resend email" button. */
                                                esc_html__('Didn’t get it? %s. We’ll also check when you come back to this tab.', 'gpt3-ai-content-generator'),
                                                '<button type="button" class="aipkit-setup__link" data-action="inbox-resend">' . esc_html__('Resend email', 'gpt3-ai-content-generator') . '</button>'
                                            ); ?></p>
                                        </div>
                                        <button type="button" class="aipkit-setup__link aipkit-setup__inbox-back" data-action="inbox-back"><?php esc_html_e('← Back to Check your inbox', 'gpt3-ai-content-generator'); ?></button>
                                        <p class="aipkit-setup__cloud-message" data-cloud-message role="status" <?php echo $aipkit_setup['cloud']['message'] === '' || $aipkit_setup['cloud']['pendingEmail'] ? 'hidden' : ''; ?>><?php echo esc_html($aipkit_setup['cloud']['message']); ?></p>
                                        <?php echo \WPAICG\Cloud\Connection::account_email_html(true, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
                                        <div data-cloud-show="none">
                                            <label class="aipkit-setup__field" data-cloud-email-field <?php echo $aipkit_setup['registered'] ? 'hidden' : ''; ?>><?php esc_html_e('Email', 'gpt3-ai-content-generator'); ?>
                                                <input class="aipkit-setup__input" type="email" data-field="cloud_email" autocomplete="email" value="<?php echo esc_attr($aipkit_setup['cloud']['email']); ?>" required>
                                            </label>
                                            <div data-cloud-consent>
                                            <label class="aipkit-setup__consent aipkit-setup__consent-explicit"><input type="checkbox" data-field="consent"> <span><?php printf(
                                                /* translators: 1: AI Puffer terms link. 2: AI Puffer privacy policy link. */
                                                esc_html__('I agree to connect this site to AI Puffer Cloud under the %1$s and %2$s.', 'gpt3-ai-content-generator'),
                                                '<a href="' . esc_url(\WPAICG\Cloud\Connection::TERMS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('terms', 'gpt3-ai-content-generator') . '</a>',
                                                '<a href="' . esc_url(\WPAICG\Cloud\Connection::PRIVACY_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'gpt3-ai-content-generator') . '</a>'
                                            ); ?></span></label><p class="aipkit-setup__consent-implied"><?php printf(
                                                /* translators: 1: AI Puffer terms link. 2: AI Puffer privacy policy link. */
                                                esc_html__('By continuing you agree to the %1$s and %2$s.', 'gpt3-ai-content-generator'),
                                                '<a href="' . esc_url(\WPAICG\Cloud\Connection::TERMS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('terms', 'gpt3-ai-content-generator') . '</a>',
                                                '<a href="' . esc_url(\WPAICG\Cloud\Connection::PRIVACY_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'gpt3-ai-content-generator') . '</a>'
                                            ); ?> <button type="button" class="aipkit-setup__link" data-action="privacy-toggle" aria-expanded="false"><?php esc_html_e('What we collect', 'gpt3-ai-content-generator'); ?></button></p></div>
                                            <label class="aipkit-setup__consent aipkit-setup__consent--minor" data-cloud-marketing <?php echo $aipkit_setup['registered'] ? 'hidden' : ''; ?>><input type="checkbox" data-field="marketing"> <?php esc_html_e('Email me product news and tips (optional).', 'gpt3-ai-content-generator'); ?></label>
                                            <?php echo \WPAICG\Cloud\Connection::privacy_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all values. ?>
                                            <div class="aipkit-setup__cloud-actions">
                                                <button type="button" class="aipkit-setup__btn" data-action="check-email" <?php echo !$aipkit_setup['cloud']['pendingEmail'] ? 'hidden' : ''; ?> disabled><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?></button>
                                                <button type="button" class="aipkit-setup__btn<?php echo $aipkit_setup['cloud']['pendingEmail'] ? ' aipkit-setup__btn--ghost' : ''; ?>" data-action="cloud" disabled><?php echo esc_html($aipkit_setup['cloud']['pendingEmail'] ? __('Resend email', 'gpt3-ai-content-generator') : ($aipkit_setup_website_flow ? __('Continue', 'gpt3-ai-content-generator') : __('Connect for free', 'gpt3-ai-content-generator'))); ?></button>
                                            </div>
                                        </div>
                                        <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="retry-cloud" <?php echo !$aipkit_setup['cloud']['retry'] ? 'hidden' : ''; ?>><?php esc_html_e('Retry', 'gpt3-ai-content-generator'); ?></button>
                                    </div>
                                </div>

                                <div class="aipkit-setup__option" data-power-option="key" <?php echo $aipkit_setup_website_flow ? 'hidden' : ''; ?>>
                                    <button type="button" class="aipkit-setup__option-head" data-action="power" data-power="key">
                                        <span class="aipkit-setup__radio"></span>
                                        <span class="aipkit-setup__option-text">
                                            <span class="aipkit-setup__option-title"><?php esc_html_e('I have my own API key', 'gpt3-ai-content-generator'); ?></span>
                                            <span class="aipkit-setup__muted"><?php esc_html_e('OpenAI, Anthropic, Google, OpenRouter, DeepSeek or xAI. You pay the provider directly.', 'gpt3-ai-content-generator'); ?></span>
                                        </span>
                                    </button>
                                    <div class="aipkit-setup__option-body">
                                        <div class="aipkit-setup__key">
                                            <label><?php esc_html_e('Provider', 'gpt3-ai-content-generator'); ?>
                                                <select class="aipkit-setup__input" data-field="provider">
                                                    <?php foreach ($aipkit_setup['providers'] as $aipkit_provider => $aipkit_provider_label) : ?>
                                                        <option value="<?php echo esc_attr($aipkit_provider); ?>" data-has-key="<?php echo $aipkit_setup['has_provider_key'][$aipkit_provider] ? 'true' : 'false'; ?>" <?php selected($aipkit_setup['key_provider'], $aipkit_provider); ?>><?php echo esc_html($aipkit_provider_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label><?php esc_html_e('API key', 'gpt3-ai-content-generator'); ?>
                                                <input class="aipkit-setup__input" type="password" autocomplete="off" data-field="api_key" placeholder="<?php esc_attr_e('Paste your key', 'gpt3-ai-content-generator'); ?>">
                                            </label>
                                            <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="key"><?php esc_html_e('Check key', 'gpt3-ai-content-generator'); ?></button>
                                        </div>
                                        <div class="aipkit-setup__ok" data-key-message hidden><?php $aipkit_setup_icon($aipkit_setup_check, 18, 'currentColor', '2.4'); ?><span></span></div>
                                    </div>
                                </div>

                                <button type="button" class="aipkit-setup__option aipkit-setup__option-head" data-power-option="later" data-action="power" data-power="later" <?php echo $aipkit_setup_website_flow ? 'hidden' : ''; ?>>
                                    <span class="aipkit-setup__radio"></span>
                                    <span class="aipkit-setup__option-text">
                                        <span class="aipkit-setup__option-title aipkit-setup__option-title--sm"><?php esc_html_e('Set this up later', 'gpt3-ai-content-generator'); ?></span>
                                        <span class="aipkit-setup__muted"><?php esc_html_e('You can look around first. AI features will ask you to connect.', 'gpt3-ai-content-generator'); ?></span>
                                    </span>
                                </button>
                            </div>
                            <?php if (!$aipkit_setup['registered']) : ?>
                                <div class="aipkit-setup__updates" data-updates-optin <?php echo $aipkit_setup_website_flow ? 'hidden' : ''; ?>>
                                    <label class="aipkit-setup__consent"><input type="checkbox" data-field="updates"> <?php esc_html_e('Email me about security updates and new features (optional)', 'gpt3-ai-content-generator'); ?></label>
                                    <?php echo \WPAICG\Cloud\Connection::privacy_details_html(false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all values. ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="aipkit-setup__flow-step" data-flow-step="connect" <?php echo $aipkit_setup_website_flow ? '' : 'hidden'; ?>>
                    <span class="aipkit-setup__flow-num" data-connect-num aria-hidden="true">2</span>
                    <div class="aipkit-setup__flow-body">
                        <p class="aipkit-setup__flow-title" data-flow-connect-title><?php esc_html_e('Connect an assistant', 'gpt3-ai-content-generator'); ?></p>
                        <p class="aipkit-setup__muted" data-flow-connect-hint><?php echo esc_html($aipkit_setup_website_ready ? __('Checking your website…', 'gpt3-ai-content-generator') : __('Finish email verification to connect your assistant.', 'gpt3-ai-content-generator')); ?></p>
                        <div class="aipkit-setup__live-checks" data-connection-progress role="status" aria-live="polite" hidden>
                            <p class="aipkit-setup__muted" data-connection-progress-title><?php esc_html_e('Checking your website…', 'gpt3-ai-content-generator'); ?></p>
                            <ul class="aipkit-setup__checks" data-connection-progress-rows></ul>
                            <button type="button" class="aipkit-setup__link" data-action="check-connection"><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?></button>
                        </div>
                        <div data-onboarding-connection-check hidden></div>
                        <div data-assistant-setup <?php echo $aipkit_setup_website_flow && $aipkit_setup_website_ready && !$aipkit_setup_connection_blocked ? '' : 'hidden'; ?>>
                            <?php // Choose an assistant; after a click, one panel carries every state (waiting, not allowed yet, connected, failed, expired). ?>
                            <?php // One row per assistant, like the account row in step 1; other apps and the terms share one line of fine print. ?>
                            <form data-onboarding-assistant>
                                <div class="aipkit-setup__picks">
                                    <?php foreach ([
                                        'chatgpt' => [__('Connect ChatGPT', 'gpt3-ai-content-generator'), __('Opens ChatGPT in a new tab', 'gpt3-ai-content-generator')],
                                        'claude' => [__('Connect Claude', 'gpt3-ai-content-generator'), __('Opens Claude in a new tab', 'gpt3-ai-content-generator')],
                                    ] as $aipkit_client => [$aipkit_client_action, $aipkit_client_how]) : ?>
                                        <button type="submit" class="aipkit-setup__pick" data-assistant="<?php echo esc_attr($aipkit_client); ?>" disabled>
                                            <span class="aipkit-setup__app aipkit-setup__app--<?php echo esc_attr($aipkit_client); ?>" aria-hidden="true"></span>
                                            <span class="aipkit-setup__pick-text"><strong><?php echo esc_html($aipkit_client_action); ?></strong><span><?php echo esc_html($aipkit_client_how); ?></span></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php // What one click does, so the new tab and the copied address are expected. ?>
                                <div class="aipkit-setup__happens">
                                    <p><?php esc_html_e('What happens', 'gpt3-ai-content-generator'); ?></p>
                                    <ol>
                                        <li><?php esc_html_e('Your assistant opens with the AI Puffer address already copied.', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('You add it as a custom connector and approve access once.', 'gpt3-ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('This page shows when your assistant is connected.', 'gpt3-ai-content-generator'); ?></li>
                                    </ol>
                                </div>
                                <div class="aipkit-setup__pick-fine">
                                    <p><?php printf(
                                        /* translators: 1: "Set it up manually" button. 2: "Copy the connection address" button. */
                                        esc_html__('Using another MCP app? %1$s or %2$s.', 'gpt3-ai-content-generator'),
                                        '<button type="submit" class="aipkit-setup__link" data-assistant="other" disabled>' . esc_html__('Set it up manually', 'gpt3-ai-content-generator') . '</button>',
                                        '<button type="button" class="aipkit-setup__link" data-action="copy-assistant-setup" data-mcp-copy="' . esc_attr($aipkit_setup_mcp_address) . '">' . esc_html__('copy the connection address', 'gpt3-ai-content-generator') . '</button>'
                                    ); ?></p>
                                    <?php \WPAICG\Mcp\Settings::render_connection_terms('aipkit-setup__muted'); ?>
                                </div>
                            </form>
                            <div class="aipkit-setup__assist" data-assistant-panel role="status" aria-live="polite" hidden>
                                <p class="aipkit-setup__assist-title"><span class="aipkit-setup__assist-icon" aria-hidden="true" data-assistant-icon></span><span data-assistant-title></span><span class="aipkit-setup__assist-chip" data-assistant-chip hidden></span></p>
                                <p class="aipkit-setup__assist-text" data-assistant-text></p>
                                <?php
                                // ChatGPT and Claude: three short steps, with the address the Connect click already copied.
                                $aipkit_setup_address_step = static function () use ($aipkit_setup_mcp_address): void { ?>
                                    <span class="aipkit-setup__address"><code data-assistant-address><?php echo esc_html($aipkit_setup_mcp_address); ?></code><button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="copy-assistant-setup" data-mcp-copy="<?php echo esc_attr($aipkit_setup_mcp_address); ?>"><?php esc_html_e('Copy again', 'gpt3-ai-content-generator'); ?></button></span>
                                <?php };
                                foreach ([
                                    'chatgpt' => [
                                        [__('Add a new MCP server in ChatGPT', 'gpt3-ai-content-generator'), __('In Plugins, select + (Add), then Add custom MCP server. Name it AI Puffer.', 'gpt3-ai-content-generator')],
                                        [[__('Paste the address. It is already copied.', 'gpt3-ai-content-generator'), __('Copy this address and paste it.', 'gpt3-ai-content-generator')], __('Choose Server URL and leave Authentication set to OAuth.', 'gpt3-ai-content-generator')],
                                        [__('Approve AI Puffer when ChatGPT asks', 'gpt3-ai-content-generator'), __('Select I understand and want to continue, then Create. You can disconnect it anytime in Settings.', 'gpt3-ai-content-generator')],
                                    ],
                                    'claude' => [
                                        [__('Check the AI Puffer form in Claude', 'gpt3-ai-content-generator'), __('The tab we opened fills in the name and address. Select Continue.', 'gpt3-ai-content-generator')],
                                        [[__('Empty form? Paste the address. It is already copied.', 'gpt3-ai-content-generator'), __('Empty form? Copy this address and paste it.', 'gpt3-ai-content-generator')], __('Customize → Connectors → Add connector → Add custom connector.', 'gpt3-ai-content-generator')],
                                        [__('Approve AI Puffer when Claude asks', 'gpt3-ai-content-generator'), __('Sign in if asked. You can disconnect it anytime in Settings.', 'gpt3-ai-content-generator')],
                                    ],
                                ] as $aipkit_client => $aipkit_client_steps) : ?>
                                    <ol class="aipkit-setup__link-steps" data-assistant-steps="<?php echo esc_attr($aipkit_client); ?>" hidden>
                                        <?php foreach ($aipkit_client_steps as $aipkit_step_index => [$aipkit_step_title, $aipkit_step_text]) : ?>
                                            <?php if (is_array($aipkit_step_title)) : ?>
                                                <li><strong data-address-title data-copied="<?php echo esc_attr($aipkit_step_title[0]); ?>" data-manual="<?php echo esc_attr($aipkit_step_title[1]); ?>"><?php echo esc_html($aipkit_step_title[0]); ?></strong><span><?php echo esc_html($aipkit_step_text); ?></span><?php $aipkit_setup_address_step(); ?></li>
                                            <?php else : ?>
                                                <li><strong><?php echo esc_html($aipkit_step_title); ?></strong><span><?php echo esc_html($aipkit_step_text); ?></span></li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endforeach; ?>
                                <?php // Another MCP app keeps the full manual setup shared with Settings. ?>
                                <?php \WPAICG\Mcp\Settings::render_assistant_instructions('aipkit-setup__muted', 'chatgpt', true); ?>
                                <?php // One set of controls: inline while waiting; after a minute the same controls become a list of fixes. ?>
                                <div class="aipkit-setup__row aipkit-setup__fixes">
                                    <span class="aipkit-setup__fix" data-fix="open" hidden>
                                        <span class="aipkit-setup__fix-text"><strong><?php esc_html_e('It may be in another window', 'gpt3-ai-content-generator'); ?></strong><span><?php esc_html_e('Open it again; the address is copied for you.', 'gpt3-ai-content-generator'); ?></span></span>
                                        <a class="aipkit-setup__btn aipkit-setup__btn--ghost" data-assistant-link data-assistant-open target="_blank" rel="noopener noreferrer"></a>
                                    </span>
                                    <span class="aipkit-setup__fix" data-fix="guide" hidden>
                                        <span class="aipkit-setup__fix-text"><strong><?php esc_html_e('See each step with pictures', 'gpt3-ai-content-generator'); ?></strong><span><?php esc_html_e('A short guide on aipower.org.', 'gpt3-ai-content-generator'); ?></span></span>
                                        <a class="aipkit-setup__btn aipkit-setup__btn--ghost" data-assistant-guide href="https://aipower.org/docs/start/connect-chatgpt/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open guide', 'gpt3-ai-content-generator'); ?> ↗</a>
                                    </span>
                                    <span class="aipkit-setup__fix" data-fix="switch" hidden>
                                        <span class="aipkit-setup__fix-text"><strong data-assistant-fix-other></strong><span><?php esc_html_e('The same three steps.', 'gpt3-ai-content-generator'); ?></span></span>
                                        <button type="button" class="aipkit-setup__link" data-action="assistant-switch"></button>
                                    </span>
                                    <span class="aipkit-setup__fix" data-fix="check" data-assistant-handoff hidden>
                                        <span class="aipkit-setup__fix-text"><strong><?php esc_html_e('Already approved it?', 'gpt3-ai-content-generator'); ?></strong><span><?php esc_html_e('Check the connection now instead of waiting.', 'gpt3-ai-content-generator'); ?></span></span>
                                        <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="check-assistant"><?php esc_html_e('Check', 'gpt3-ai-content-generator'); ?></button>
                                    </span>
                                    <button type="button" class="aipkit-setup__btn" data-action="assistant-again" hidden></button>
                                    <button type="button" class="aipkit-setup__link" data-action="assistant-choose"><?php esc_html_e('Choose another assistant', 'gpt3-ai-content-generator'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php // Step 3: one read-only request confirms the connected assistant can use this site; it folds to one line once confirmed. ?>
                <div class="aipkit-setup__flow-step" data-flow-step="read" data-website-first-request hidden>
                    <span class="aipkit-setup__flow-num" data-read-num aria-hidden="true">3</span>
                    <div class="aipkit-setup__flow-body">
                        <p class="aipkit-setup__flow-title" data-read-title role="status" aria-live="polite" aria-atomic="true"><?php esc_html_e('Try it now', 'gpt3-ai-content-generator'); ?></p>
                        <p class="aipkit-setup__muted" data-website-hint><?php esc_html_e('In your assistant, select AI Puffer and send this. It only reads your site.', 'gpt3-ai-content-generator'); ?></p>
                        <div class="aipkit-setup__prompt aipkit-setup__prompt--copy" data-read-part>
                            <span data-website-prompt><?php echo esc_html(sprintf(
                                /* translators: %s: the current website address. */
                                __('Use AI Puffer to read my site at %s. Tell me its name and address. Do not change anything.', 'gpt3-ai-content-generator'), home_url('/')
                            )); ?></span>
                            <button type="button" class="aipkit-setup__prompt-copy" data-action="copy-website-prompt"><?php esc_html_e('Copy', 'gpt3-ai-content-generator'); ?></button>
                        </div>
                        <div class="aipkit-setup__row" data-read-part>
                            <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="copy-open-assistant"><?php esc_html_e('Copy prompt', 'gpt3-ai-content-generator'); ?></button>
                        </div>
                        <div class="aipkit-setup__prompt aipkit-setup__verify" data-read-part role="status" aria-live="polite">
                            <p data-website-verification></p>
                            <button type="button" class="aipkit-setup__link" data-action="retry-website-verification" hidden><?php esc_html_e('Try again', 'gpt3-ai-content-generator'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="aipkit-setup__nav">
                <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="back"><?php esc_html_e('Back', 'gpt3-ai-content-generator'); ?></button>
                <button type="button" class="aipkit-setup__btn" data-action="after-connect" <?php echo $aipkit_setup_website_flow ? 'hidden' : ''; ?> disabled><?php esc_html_e('Continue', 'gpt3-ai-content-generator'); ?></button>
                <span class="aipkit-setup__later-note" data-later-note><?php esc_html_e('It stays on your setup checklist', 'gpt3-ai-content-generator'); ?></span>
                <button type="button" class="aipkit-setup__link" data-action="website-later" <?php echo $aipkit_setup_website_flow ? '' : 'hidden'; ?>><?php esc_html_e('Do this later', 'gpt3-ai-content-generator'); ?></button>
            </div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__chat-step" data-step="try-chat" hidden>
            <div class="aipkit-setup__chat-copy">
                <span class="aipkit-setup__eyebrow"><?php esc_html_e('Your first chatbot', 'gpt3-ai-content-generator'); ?></span>
                <h1><?php esc_html_e('We made a chatbot for your site. Try it.', 'gpt3-ai-content-generator'); ?></h1>
                <p class="aipkit-setup__lead"><?php esc_html_e('Ask it anything. It’s the same chatbot your visitors will get, and you can change its look, instructions and model anytime in Chatbots.', 'gpt3-ai-content-generator'); ?></p>
                <ul class="aipkit-setup__ticks">
                    <li><?php $aipkit_setup_icon($aipkit_setup_check, 18, '#1d7a45', '2.4'); ?><span data-connection-label></span></li>
                    <li data-bot-visibility><?php $aipkit_setup_icon($aipkit_setup_check, 18, '#1d7a45', '2.4'); ?><span><?php esc_html_e('Not visible to visitors until you add it', 'gpt3-ai-content-generator'); ?></span></li>
                </ul>
                <div class="aipkit-setup__row">
                    <button type="button" class="aipkit-setup__btn" data-action="publish-bot"><?php esc_html_e('Add it to my site', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="done"><?php esc_html_e('I’ll do it later', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>
            <?php // The real chatbot, loaded when this step opens so it uses the connection chosen above. ?>
            <div class="aipkit-setup__chat">
                <div class="aipkit-setup__chat-status" data-chat-status>
                    <p data-chat-message role="status"><?php esc_html_e('Loading your chatbot…', 'gpt3-ai-content-generator'); ?></p>
                    <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="load-chat" hidden><?php esc_html_e('Try again', 'gpt3-ai-content-generator'); ?></button>
                </div>
                <div class="aipkit-setup__chat-widget" data-chat-widget></div>
            </div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__mid" data-step="try-write" hidden>
            <?php $aipkit_setup_writing_tools('try-write'); ?>
            <div class="aipkit-setup__head">
                <span class="aipkit-setup__eyebrow"><?php esc_html_e('Your first draft', 'gpt3-ai-content-generator'); ?></span>
                <h1><?php esc_html_e('What should your first article be about?', 'gpt3-ai-content-generator'); ?></h1>
            </div>
            <form class="aipkit-setup__row" data-draft-form>
                <input class="aipkit-setup__input aipkit-setup__input--lg" data-field="topic" maxlength="200" value="<?php esc_attr_e('10 easy ways to save energy at home', 'gpt3-ai-content-generator'); ?>" aria-label="<?php esc_attr_e('Article topic', 'gpt3-ai-content-generator'); ?>">
                <button type="submit" class="aipkit-setup__btn aipkit-setup__btn--lg"><?php esc_html_e('Write my first draft', 'gpt3-ai-content-generator'); ?></button>
            </form>
            <div class="aipkit-setup__ideas">
                <?php foreach ([__('10 easy ways to save energy at home', 'gpt3-ai-content-generator'), __('Beginner guide to home composting', 'gpt3-ai-content-generator'), __('How to choose a running shoe', 'gpt3-ai-content-generator')] as $aipkit_idea) : ?>
                    <button type="button" class="aipkit-setup__btn aipkit-setup__btn--chip" data-idea="<?php echo esc_attr($aipkit_idea); ?>"><?php echo esc_html($aipkit_idea); ?></button>
                <?php endforeach; ?>
            </div>
            <p class="aipkit-setup__muted" data-draft-wait hidden><?php esc_html_e('Writing your draft. This usually takes 20 to 60 seconds…', 'gpt3-ai-content-generator'); ?></p>
            <article class="aipkit-setup__draft" data-draft hidden>
                <span class="aipkit-setup__draft-meta" data-draft-meta></span>
                <h2 data-draft-title></h2>
                <p data-draft-excerpt></p>
                <div class="aipkit-setup__row">
                    <a class="aipkit-setup__btn" data-draft-edit href="#" target="_blank" rel="noopener"><?php esc_html_e('Open in editor', 'gpt3-ai-content-generator'); ?></a>
                    <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="done"><?php esc_html_e('Continue', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </article>
            <div><button type="button" class="aipkit-setup__link" data-action="done" data-draft-skip><?php esc_html_e('Skip this step', 'gpt3-ai-content-generator'); ?></button></div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__mid" data-step="try-products" hidden>
            <?php $aipkit_setup_writing_tools('try-products'); ?>
            <div class="aipkit-setup__head">
                <span class="aipkit-setup__eyebrow"><?php esc_html_e('Your first product description', 'gpt3-ai-content-generator'); ?></span>
                <h1><?php esc_html_e('Let’s write a product description', 'gpt3-ai-content-generator'); ?></h1>
                <p><?php echo $aipkit_setup['products']
                    ? esc_html__('Pick one of your products, or describe something else. Nothing is saved until you say so.', 'gpt3-ai-content-generator')
                    : esc_html__('You have no products yet, so describe one and we will write it for you.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <form class="aipkit-setup__panel aipkit-setup__panel--plain" data-product-form>
                <?php if ($aipkit_setup['products']) : ?>
                    <label class="aipkit-setup__field"><?php esc_html_e('Product', 'gpt3-ai-content-generator'); ?>
                        <select class="aipkit-setup__input" data-field="product_id">
                            <?php foreach ($aipkit_setup['products'] as $aipkit_product) : ?>
                                <option value="<?php echo (int) $aipkit_product['id']; ?>"<?php echo $aipkit_product['hasDescription'] ? ' data-has-description="1"' : ''; ?>><?php echo esc_html($aipkit_product['name']); ?></option>
                            <?php endforeach; ?>
                            <option value="0"><?php esc_html_e('Something else…', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </label>
                <?php endif; ?>
                <label class="aipkit-setup__field" data-product-name<?php echo $aipkit_setup['products'] ? ' hidden' : ''; ?>><?php esc_html_e('Product name', 'gpt3-ai-content-generator'); ?>
                    <input class="aipkit-setup__input" data-field="name" maxlength="200" placeholder="<?php esc_attr_e('Insulated water bottle, 750 ml', 'gpt3-ai-content-generator'); ?>">
                </label>
                <label class="aipkit-setup__field"><?php esc_html_e('Anything worth mentioning? (optional)', 'gpt3-ai-content-generator'); ?>
                    <textarea class="aipkit-setup__input aipkit-setup__textarea" data-field="details" rows="2" maxlength="500" placeholder="<?php esc_attr_e('Keeps drinks cold for 24 hours, stainless steel, dishwasher safe', 'gpt3-ai-content-generator'); ?>"></textarea>
                </label>
                <div class="aipkit-setup__row">
                    <button type="submit" class="aipkit-setup__btn"><?php esc_html_e('Write the description', 'gpt3-ai-content-generator'); ?></button>
                    <span class="aipkit-setup__muted" data-product-wait hidden><?php esc_html_e('Writing… this takes a few seconds.', 'gpt3-ai-content-generator'); ?></span>
                </div>
            </form>
            <article class="aipkit-setup__draft" data-product-result hidden>
                <span class="aipkit-setup__draft-meta" data-product-title></span>
                <p class="aipkit-setup__product-short" data-product-short hidden></p>
                <div class="aipkit-setup__product-body" data-product-description></div>
                <p class="aipkit-setup__muted" data-product-replaces hidden><?php esc_html_e('Saving replaces the description this product has now.', 'gpt3-ai-content-generator'); ?></p>
                <div class="aipkit-setup__row">
                    <button type="button" class="aipkit-setup__btn" data-action="save-product" hidden><?php esc_html_e('Save to product', 'gpt3-ai-content-generator'); ?></button>
                    <a class="aipkit-setup__btn aipkit-setup__btn--ghost" data-product-edit href="#" target="_blank" rel="noopener" hidden><?php esc_html_e('Open product', 'gpt3-ai-content-generator'); ?></a>
                    <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="copy-product"><?php esc_html_e('Copy text', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="done"><?php esc_html_e('Continue', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </article>
            <div><button type="button" class="aipkit-setup__link" data-action="done"><?php esc_html_e('Skip this step', 'gpt3-ai-content-generator'); ?></button></div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__mid" data-step="try-auto" hidden>
            <?php $aipkit_setup_writing_tools('try-auto'); ?>
            <div class="aipkit-setup__head">
                <h1><?php esc_html_e('Blog posts from your topics', 'gpt3-ai-content-generator'); ?></h1>
            </div>
            <form class="aipkit-setup__panel" data-template-options="auto_blog">
                <label class="aipkit-setup__field"><?php esc_html_e('Topics, one per line (up to 20)', 'gpt3-ai-content-generator'); ?>
                    <textarea class="aipkit-setup__input aipkit-setup__textarea" data-field="topics" rows="4"><?php echo esc_textarea(implode("\n", [__('10 easy ways to save energy at home', 'gpt3-ai-content-generator'), __('Beginner guide to home composting', 'gpt3-ai-content-generator'), __('How to choose a running shoe', 'gpt3-ai-content-generator')])); ?></textarea>
                </label>
                <fieldset class="aipkit-setup__choices">
                    <legend class="aipkit-setup__legend"><?php esc_html_e('What should happen to new posts?', 'gpt3-ai-content-generator'); ?></legend>
                    <label class="aipkit-setup__consent"><input type="radio" name="aipkit_setup_publish" value="" checked> <?php esc_html_e('Save as drafts for me to review', 'gpt3-ai-content-generator'); ?></label>
                    <label class="aipkit-setup__consent"><input type="radio" name="aipkit_setup_publish" value="yes"> <?php esc_html_e('Publish one a week, starting tomorrow at 9:00', 'gpt3-ai-content-generator'); ?></label>
                </fieldset>
                <div class="aipkit-setup__row">
                    <button type="submit" class="aipkit-setup__btn"><?php esc_html_e('Create automation', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </form>
            <?php include __DIR__ . '/template-result.php'; ?>
        </section>

        <section class="aipkit-setup__step aipkit-setup__mid" data-step="try-forms" hidden>
            <div class="aipkit-setup__head">
                <span class="aipkit-setup__eyebrow"><?php esc_html_e('Your first AI form', 'gpt3-ai-content-generator'); ?></span>
                <h1><?php esc_html_e('Pick a form to start with', 'gpt3-ai-content-generator'); ?></h1>
                <p><?php esc_html_e('Visitors fill it in and get an AI answer. You can change the fields and the prompt anytime.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <div class="aipkit-setup__templates" data-templates>
                <?php foreach ([
                    'form_names' => [__('Product name generator', 'gpt3-ai-content-generator'), __('Visitors describe a product and get name ideas.', 'gpt3-ai-content-generator'), 'M12 3l2.6 5.6L20 11l-5.4 2.4L12 19l-2.6-5.6L4 11l5.4-2.4z'],
                    'form_email' => [__('Email writer', 'gpt3-ai-content-generator'), __('Turns a few notes into a polished email.', 'gpt3-ai-content-generator'), 'M4 6h16v12H4zM4 7l8 6 8-6'],
                    'form_recipe' => [__('Recipe from ingredients', 'gpt3-ai-content-generator'), __('Suggests a recipe from what visitors have at home.', 'gpt3-ai-content-generator'), 'M6 13h12l-1 7H7zM8 13a4 4 0 1 1 8 0M12 3v2'],
                ] as $aipkit_template => $aipkit_template_text) : ?>
                    <button type="button" class="aipkit-setup__card" data-template="<?php echo esc_attr($aipkit_template); ?>">
                        <span class="aipkit-setup__card-icon"><?php $aipkit_setup_icon($aipkit_template_text[2], 22, '#3f5fe8'); ?></span>
                        <strong><?php echo esc_html($aipkit_template_text[0]); ?></strong>
                        <span class="aipkit-setup__muted"><?php echo esc_html($aipkit_template_text[1]); ?></span>
                        <span class="aipkit-setup__card-cta"><?php esc_html_e('Use this template', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php include __DIR__ . '/template-result.php'; ?>
        </section>

        <section class="aipkit-setup__step aipkit-setup__welcome" data-step="done" hidden>
            <span class="aipkit-setup__hero-icon aipkit-setup__hero-icon--green" data-done-hero><?php $aipkit_setup_icon($aipkit_setup_check, 36, '#1d7a45', '2.4'); ?></span>
            <h1 data-done-heading><?php esc_html_e('You’re all set', 'gpt3-ai-content-generator'); ?></h1>
            <span class="aipkit-setup__done-meter" data-done-meter aria-hidden="true" hidden></span>
            <?php // The one-sentence summary stays for screen readers; the checklist shows each step, its state and its own action. ?>
            <p class="aipkit-setup__sr" data-summary-lead></p>
            <?php
            $aipkit_setup_connections_url = admin_url('admin.php?page=wpaicg&aipkit_module=settings&aipkit_settings_page=connections');
            $aipkit_setup_done_tools = ['chatbot' => 'chatbot', 'forms' => 'ai-forms', 'write' => 'content-writer'];
            ?>
            <div class="aipkit-setup__done-rows">
                <?php // The free account, when Cloud runs the setup: its monthly allowance, or what is left to finish. ?>
                <div class="aipkit-setup__done-row" data-done-row="account" hidden>
                    <span class="aipkit-setup__done-tile" aria-hidden="true"><?php $aipkit_setup_icon('M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0', 18, '#3f5fe8'); ?></span>
                    <span class="aipkit-setup__done-text"><strong data-done-title><?php esc_html_e('Account ready', 'gpt3-ai-content-generator'); ?></strong><span data-done-status></span></span>
                    <a class="aipkit-setup__link" data-action="done-open" data-done-open href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=stats')); ?>"><?php esc_html_e('View usage', 'gpt3-ai-content-generator'); ?></a>
                    <a class="aipkit-setup__btn" data-action="website-connect" data-done-connect href="<?php echo esc_url($aipkit_setup_connections_url); ?>" hidden><?php esc_html_e('Finish', 'gpt3-ai-content-generator'); ?></a>
                </div>
                <?php // Website management: connected, or Connect now back to the website step (Settings when this site can't connect). ?>
                <div class="aipkit-setup__done-row" data-done-row="website" hidden>
                    <span class="aipkit-setup__done-tile" aria-hidden="true"><?php $aipkit_setup_icon($aipkit_setup_icons['website'], 18, '#3f5fe8'); ?></span>
                    <span class="aipkit-setup__done-text"><strong data-done-title><?php echo esc_html($aipkit_setup_goals['website'][2]); ?></strong><span data-done-status></span></span>
                    <a class="aipkit-setup__btn" data-action="website-connect" data-done-connect href="<?php echo esc_url($aipkit_setup_connections_url); ?>" hidden><?php esc_html_e('Connect now', 'gpt3-ai-content-generator'); ?></a>
                </div>
                <?php foreach ($aipkit_setup_done_tools as $aipkit_goal => $aipkit_module) : ?>
                    <div class="aipkit-setup__done-row" data-done-row="<?php echo esc_attr($aipkit_goal); ?>" hidden>
                        <span class="aipkit-setup__done-tile" aria-hidden="true"><?php $aipkit_setup_icon($aipkit_setup_icons[$aipkit_goal], 18, '#3f5fe8'); ?></span>
                        <span class="aipkit-setup__done-text"><strong><?php echo esc_html($aipkit_setup_goals[$aipkit_goal][2]); ?></strong><span data-done-status></span></span>
                        <?php /* translators: %s: tool name, such as "Chatbot". */ ?>
                        <a class="aipkit-setup__link" data-action="done-open" data-done-open href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=' . $aipkit_module)); ?>"><?php echo esc_html(sprintf(__('Open %s', 'gpt3-ai-content-generator'), $aipkit_setup_goals[$aipkit_goal][2])); ?></a>
                        <a class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="done-open" data-done-connect href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=settings&aipkit_settings_page=ai')); ?>" hidden><?php esc_html_e('Connect AI', 'gpt3-ai-content-generator'); ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="aipkit-setup__muted aipkit-setup__done-note" data-done-note hidden><?php esc_html_e('Open steps stay on your Dashboard until they are done.', 'gpt3-ai-content-generator'); ?></p>
            <?php
            // "Try next": tested cookbook recipes for the plugins this site runs, then WordPress basics to fill three.
            $aipkit_setup_cookbook = 'https://aipower.org/docs/cookbook/';
            $aipkit_setup_recipes = [];
            foreach ([
                ['WooCommerce', class_exists('WooCommerce'), 'woocommerce/change-a-price/', __('Change a product’s price', 'gpt3-ai-content-generator')],
                ['Yoast SEO', defined('WPSEO_VERSION'), 'yoast-seo/poor-scores/', __('Find posts with poor SEO scores', 'gpt3-ai-content-generator')],
                ['Elementor', defined('ELEMENTOR_VERSION'), 'elementor/draft-page/', __('Create an Elementor page', 'gpt3-ai-content-generator')],
                ['ACF', class_exists('ACF'), 'acf/custom-text-field/', __('Update a custom text field', 'gpt3-ai-content-generator')],
                ['WordPress', true, 'wordpress/notes-to-draft/', __('Turn notes into a post', 'gpt3-ai-content-generator')],
                ['WordPress', true, 'wordpress/edit-a-post/', __('Fix something in a post or page', 'gpt3-ai-content-generator')],
                ['WordPress', true, 'wordpress/blocks-page/', __('Build a page from blocks', 'gpt3-ai-content-generator')],
            ] as [$aipkit_recipe_plugin, $aipkit_recipe_active, $aipkit_recipe_path, $aipkit_recipe_title]) {
                if ($aipkit_recipe_active && count($aipkit_setup_recipes) < 3) { $aipkit_setup_recipes[] = [$aipkit_recipe_plugin, $aipkit_setup_cookbook . $aipkit_recipe_path, $aipkit_recipe_title]; }
            }
            ?>
            <div class="aipkit-setup__next" data-done-next hidden>
                <div class="aipkit-setup__next-head">
                    <strong data-done-next-title><?php esc_html_e('Try next in your assistant', 'gpt3-ai-content-generator'); ?></strong>
                    <a class="aipkit-setup__link" href="<?php echo esc_url($aipkit_setup_cookbook); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('All recipes', 'gpt3-ai-content-generator'); ?> ↗</a>
                </div>
                <?php // When the first-request check was skipped, the safe read-only start comes first. ?>
                <a class="aipkit-setup__next-item" data-done-first href="https://aipower.org/docs/start/first-requests/" target="_blank" rel="noopener noreferrer" hidden>
                    <span class="aipkit-setup__next-text"><strong><?php esc_html_e('Your first five requests', 'gpt3-ai-content-generator'); ?></strong><span><?php esc_html_e('Start here', 'gpt3-ai-content-generator'); ?></span></span>
                    <span class="aipkit-setup__next-go" aria-hidden="true">↗</span>
                </a>
                <?php foreach ($aipkit_setup_recipes as [$aipkit_recipe_plugin, $aipkit_recipe_url, $aipkit_recipe_title]) : ?>
                    <a class="aipkit-setup__next-item" data-done-recipe href="<?php echo esc_url($aipkit_recipe_url); ?>" target="_blank" rel="noopener noreferrer">
                        <span class="aipkit-setup__next-text"><strong><?php echo esc_html($aipkit_recipe_title); ?></strong><span><?php echo esc_html($aipkit_recipe_plugin); ?></span></span>
                        <span class="aipkit-setup__next-go" aria-hidden="true">↗</span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php // Connected website setup: Website activity beside the main Open ChatGPT button. ?>
            <div class="aipkit-setup__done-actions">
                <a class="aipkit-setup__btn aipkit-setup__btn--ghost aipkit-setup__btn--lg" data-action="done-open" data-done-activity href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=stats&aipkit_stats_tab=website')); ?>" hidden><?php esc_html_e('View website activity', 'gpt3-ai-content-generator'); ?></a>
                <button type="button" class="aipkit-setup__btn aipkit-setup__btn--lg" data-action="finish"></button>
            </div>
        </section>
    </main>
</div>
