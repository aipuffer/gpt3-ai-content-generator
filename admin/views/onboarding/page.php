<?php
/**
 * First-run setup screen. Steps are toggled client-side (admin/js/onboarding/onboarding.js);
 * setup actions use aipkit_onboarding; account email changes use the Freemius handler.
 */
if (!defined('ABSPATH')) {
    exit;
}

$aipkit_setup = \WPAICG\Admin\Onboarding::view_data();
$aipkit_setup_icons = [
    'chatbot' => 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z',
    'write' => 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
    'products' => 'M6 2l-2 5v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7l-2-5zM4 7h16M16 11a4 4 0 0 1-8 0',
    'auto' => 'M12 7v5l3 3M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9z',
    'forms' => 'M4 5h16v14H4zM8 9h8M8 13h5',
    'explore' => 'M12 3l2.6 5.6L20 11l-5.4 2.4L12 19l-2.6-5.6L4 11l5.4-2.4z',
];
$aipkit_setup_goals = [
    'chatbot' => [__('Add an AI chatbot', 'gpt3-ai-content-generator'), __('Answer visitor questions around the clock.', 'gpt3-ai-content-generator'), __('Chatbot', 'gpt3-ai-content-generator')],
    'write' => [__('Write blog posts and pages', 'gpt3-ai-content-generator'), __('Draft articles from a topic or keyword.', 'gpt3-ai-content-generator'), __('Content Writer', 'gpt3-ai-content-generator')],
    'products' => [__('Write product descriptions', 'gpt3-ai-content-generator'), __('Titles, descriptions and tags for your store.', 'gpt3-ai-content-generator'), __('WooCommerce', 'gpt3-ai-content-generator')],
    'auto' => [__('Publish on a schedule', 'gpt3-ai-content-generator'), __('Create and post content automatically.', 'gpt3-ai-content-generator'), __('Automations', 'gpt3-ai-content-generator')],
    'forms' => [__('Build AI forms and tools', 'gpt3-ai-content-generator'), __('Calculators, generators and helpers for visitors.', 'gpt3-ai-content-generator'), __('AI Forms', 'gpt3-ai-content-generator')],
    'explore' => [__('Just exploring', 'gpt3-ai-content-generator'), __('Keep every tool turned on.', 'gpt3-ai-content-generator'), __('All tools', 'gpt3-ai-content-generator')],
];
$aipkit_setup_icon = static function (string $path, int $size = 22, string $stroke = 'currentColor', string $width = '1.8'): void {
    printf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="%2$s" stroke-width="%3$s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="%4$s"></path></svg>',
        (int) $size, esc_attr($stroke), esc_attr($width), esc_attr($path)
    );
};
$aipkit_setup_check = 'M5 12l5 5 9-10';
$aipkit_setup_arrow = 'M5 12h14M13 6l6 6-6 6';
?>
<div class="aipkit-setup" id="aipkit-setup" data-step="welcome" data-power="cloud" data-cloud-ready="<?php echo $aipkit_setup['cloud']['ready'] ? 'true' : 'false'; ?>" data-cloud-pending="<?php echo $aipkit_setup['cloud']['pendingEmail'] ? 'true' : 'false'; ?>" data-cloud="<?php echo $aipkit_setup['cloud']['connected'] ? 'connected' : 'none'; ?>">
    <header class="aipkit-setup__bar">
        <div class="aipkit-setup__brand">
            <img class="aipkit-setup__logo" src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" width="34" height="34" alt="">
            <span><?php esc_html_e('AI Puffer', 'gpt3-ai-content-generator'); ?></span>
        </div>
        <ol class="aipkit-setup__progress" hidden aria-label="<?php esc_attr_e('Setup progress', 'gpt3-ai-content-generator'); ?>">
            <li data-for="goals"><span class="aipkit-setup__dot">1</span><span><?php esc_html_e('Your goals', 'gpt3-ai-content-generator'); ?></span></li>
            <li data-for="connect"><span class="aipkit-setup__dot">2</span><span><?php esc_html_e('Connect AI', 'gpt3-ai-content-generator'); ?></span></li>
            <li data-for="try"><span class="aipkit-setup__dot">3</span><span><?php esc_html_e('First result', 'gpt3-ai-content-generator'); ?></span></li>
        </ol>
        <button type="button" class="aipkit-setup__link" data-action="skip" aria-keyshortcuts="Escape" title="<?php esc_attr_e('Esc', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Skip setup', 'gpt3-ai-content-generator'); ?></button>
    </header>

    <main class="aipkit-setup__main">
        <div class="aipkit-setup__error" role="alert" hidden></div>

        <section class="aipkit-setup__step aipkit-setup__welcome" data-step="welcome">
            <img class="aipkit-setup__logo aipkit-setup__logo--welcome" src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" width="72" height="72" alt="">
            <h1><?php esc_html_e('Welcome to AI Puffer', 'gpt3-ai-content-generator'); ?></h1>
            <p class="aipkit-setup__lead"><?php esc_html_e('Answer two quick questions and we will set up only what you need. It takes less than a minute.', 'gpt3-ai-content-generator'); ?></p>
            <button type="button" class="aipkit-setup__btn aipkit-setup__btn--lg" data-action="start"><?php esc_html_e('Get started', 'gpt3-ai-content-generator'); ?> <?php $aipkit_setup_icon($aipkit_setup_arrow, 18, 'currentColor', '2'); ?></button>
        </section>

        <section class="aipkit-setup__step" data-step="goals" hidden>
            <div class="aipkit-setup__head">
                <h1><?php esc_html_e('What do you want to do with AI Puffer?', 'gpt3-ai-content-generator'); ?></h1>
                <p><?php esc_html_e('Pick one or more. We will show only these tools; you can turn others on anytime in Settings.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <div class="aipkit-setup__goals">
                <?php foreach ($aipkit_setup_goals as $aipkit_goal => $aipkit_goal_text) : ?>
                    <button type="button" class="aipkit-setup__card" data-goal="<?php echo esc_attr($aipkit_goal); ?>" data-module-name="<?php echo esc_attr($aipkit_goal_text[2]); ?>" aria-pressed="<?php echo $aipkit_goal === 'chatbot' ? 'true' : 'false'; ?>">
                        <span class="aipkit-setup__check"><?php $aipkit_setup_icon($aipkit_setup_check, 12, '#ffffff', '3.5'); ?></span>
                        <span class="aipkit-setup__card-icon"><?php $aipkit_setup_icon($aipkit_setup_icons[$aipkit_goal], 22, '#3f5fe8'); ?></span>
                        <strong><?php echo esc_html($aipkit_goal_text[0]); ?></strong>
                        <span class="aipkit-setup__muted"><?php echo esc_html($aipkit_goal_text[1]); ?></span>
                        <?php if ($aipkit_goal === 'products' && $aipkit_setup['woocommerce']) : ?>
                            <span class="aipkit-setup__pill aipkit-setup__pill--green"><?php esc_html_e('WooCommerce detected', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="aipkit-setup__nav">
                <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="back"><?php esc_html_e('Back', 'gpt3-ai-content-generator'); ?></button>
                <div class="aipkit-setup__nav-end">
                    <span class="aipkit-setup__muted" data-goal-hint></span>
                    <button type="button" class="aipkit-setup__btn" data-action="save-goals"><?php esc_html_e('Continue', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__narrow" data-step="connect" hidden>
            <div class="aipkit-setup__head">
                <h1><?php esc_html_e('How should AI Puffer connect to AI?', 'gpt3-ai-content-generator'); ?></h1>
                <p><?php esc_html_e('You can change this later in Settings.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <div class="aipkit-setup__options">
                <div class="aipkit-setup__option" data-power-option="cloud">
                    <button type="button" class="aipkit-setup__option-head" data-action="power" data-power="cloud">
                        <span class="aipkit-setup__radio"></span>
                        <span class="aipkit-setup__option-text">
                            <span class="aipkit-setup__option-title"><?php esc_html_e('AI Puffer Cloud', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit-setup__muted"><?php esc_html_e('No API key needed. Access a range of AI models with free credits every month.', 'gpt3-ai-content-generator'); ?></span>
                        </span>
                    </button>
                    <div class="aipkit-setup__option-body">
                        <p class="aipkit-setup__cloud-message" data-cloud-message role="status" <?php echo $aipkit_setup['cloud']['message'] === '' ? 'hidden' : ''; ?>><?php echo esc_html($aipkit_setup['cloud']['message']); ?></p>
                        <?php echo \WPAICG\Cloud\Connection::account_email_html(true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
                        <div data-cloud-show="none">
                            <label class="aipkit-setup__field" data-cloud-email-field <?php echo $aipkit_setup['registered'] ? 'hidden' : ''; ?>><?php esc_html_e('Email', 'gpt3-ai-content-generator'); ?>
                                <input class="aipkit-setup__input" type="email" data-field="cloud_email" autocomplete="email" value="<?php echo esc_attr($aipkit_setup['cloud']['email']); ?>" required>
                            </label>
                            <label class="aipkit-setup__consent"><input type="checkbox" data-field="consent"> <span><?php printf(
                                /* translators: 1: AI Puffer terms link. 2: AI Puffer privacy policy link. */
                                esc_html__('I agree to connect this site to AI Puffer Cloud under the %1$s and %2$s.', 'gpt3-ai-content-generator'),
                                '<a href="' . esc_url(\WPAICG\Cloud\Connection::TERMS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('terms', 'gpt3-ai-content-generator') . '</a>',
                                '<a href="' . esc_url(\WPAICG\Cloud\Connection::PRIVACY_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'gpt3-ai-content-generator') . '</a>'
                            ); ?></span></label>
                            <?php echo \WPAICG\Cloud\Connection::privacy_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all values. ?>
                            <label class="aipkit-setup__consent aipkit-setup__consent--minor" data-cloud-marketing <?php echo $aipkit_setup['registered'] ? 'hidden' : ''; ?>><input type="checkbox" data-field="marketing"> <?php esc_html_e('Email me product news and tips (optional).', 'gpt3-ai-content-generator'); ?></label>
                            <div class="aipkit-setup__cloud-actions">
                                <button type="button" class="aipkit-setup__btn" data-action="check-email" <?php echo !$aipkit_setup['cloud']['pendingEmail'] ? 'hidden' : ''; ?> disabled><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?></button>
                                <button type="button" class="aipkit-setup__btn<?php echo $aipkit_setup['cloud']['pendingEmail'] ? ' aipkit-setup__btn--ghost' : ''; ?>" data-action="cloud" disabled><?php echo esc_html($aipkit_setup['cloud']['pendingEmail'] ? __('Resend email', 'gpt3-ai-content-generator') : __('Connect', 'gpt3-ai-content-generator')); ?></button>
                            </div>
                        </div>
                        <div data-cloud-recovery><?php echo $aipkit_setup['cloud']['emailRecoveryHtml']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?></div>
                        <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="retry-cloud" <?php echo !$aipkit_setup['cloud']['retry'] ? 'hidden' : ''; ?>><?php esc_html_e('Retry', 'gpt3-ai-content-generator'); ?></button>
                    </div>
                </div>

                <div class="aipkit-setup__option" data-power-option="key">
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
                                        <option value="<?php echo esc_attr($aipkit_provider); ?>" data-has-key="<?php echo $aipkit_setup['has_provider_key'][$aipkit_provider] ? 'true' : 'false'; ?>"><?php echo esc_html($aipkit_provider_label); ?></option>
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

                <button type="button" class="aipkit-setup__option aipkit-setup__option-head" data-power-option="later" data-action="power" data-power="later">
                    <span class="aipkit-setup__radio"></span>
                    <span class="aipkit-setup__option-text">
                        <span class="aipkit-setup__option-title aipkit-setup__option-title--sm"><?php esc_html_e('Set this up later', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit-setup__muted"><?php esc_html_e('You can look around first. AI features will ask you to connect.', 'gpt3-ai-content-generator'); ?></span>
                    </span>
                </button>
            </div>
            <?php if (!$aipkit_setup['registered']) : ?>
                <div class="aipkit-setup__updates" data-updates-optin>
                    <label class="aipkit-setup__consent"><input type="checkbox" data-field="updates"> <?php esc_html_e('Email me about security updates and new features (optional)', 'gpt3-ai-content-generator'); ?></label>
                    <?php echo \WPAICG\Cloud\Connection::privacy_details_html(false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes all values. ?>
                </div>
            <?php endif; ?>
            <div class="aipkit-setup__nav">
                <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="back"><?php esc_html_e('Back', 'gpt3-ai-content-generator'); ?></button>
                <button type="button" class="aipkit-setup__btn" data-action="after-connect"><?php esc_html_e('Continue', 'gpt3-ai-content-generator'); ?></button>
            </div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__chat-step" data-step="try-chat" hidden>
            <div class="aipkit-setup__chat-copy">
                <span class="aipkit-setup__eyebrow"><?php esc_html_e('Your first chatbot', 'gpt3-ai-content-generator'); ?></span>
                <h1><?php esc_html_e('We made a chatbot for your site. Try it.', 'gpt3-ai-content-generator'); ?></h1>
                <p class="aipkit-setup__lead"><?php esc_html_e('It answers in a friendly tone. You can change its look, instructions and model anytime.', 'gpt3-ai-content-generator'); ?></p>
                <ul class="aipkit-setup__ticks">
                    <li><?php $aipkit_setup_icon($aipkit_setup_check, 18, '#1d7a45', '2.4'); ?><span data-connection-label></span></li>
                    <li data-bot-visibility><?php $aipkit_setup_icon($aipkit_setup_check, 18, '#1d7a45', '2.4'); ?><span><?php esc_html_e('Not visible to visitors until you add it', 'gpt3-ai-content-generator'); ?></span></li>
                </ul>
                <div class="aipkit-setup__row">
                    <button type="button" class="aipkit-setup__btn" data-action="publish-bot"><?php esc_html_e('Add it to my site', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="done"><?php esc_html_e('I’ll do it later', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>
            <div class="aipkit-setup__chat">
                <div class="aipkit-setup__chat-head">
                    <span class="aipkit-setup__chat-avatar"><?php $aipkit_setup_icon($aipkit_setup_icons['chatbot'], 18, '#ffffff', '2'); ?></span>
                    <span><strong><?php
                        /* translators: %s: site name. */
                        echo esc_html(sprintf(__('%s assistant', 'gpt3-ai-content-generator'), $aipkit_setup['site_name'] ?: __('Site', 'gpt3-ai-content-generator')));
                    ?></strong><small><?php esc_html_e('Preview', 'gpt3-ai-content-generator'); ?></small></span>
                </div>
                <div class="aipkit-setup__chat-log" data-chat-log aria-live="polite">
                    <div class="aipkit-setup__msg aipkit-setup__msg--bot"><?php esc_html_e('Hi! How can I help you today?', 'gpt3-ai-content-generator'); ?></div>
                </div>
                <form class="aipkit-setup__chat-form" data-chat-form>
                    <input class="aipkit-setup__input" data-field="message" maxlength="500" placeholder="<?php esc_attr_e('Ask your chatbot something…', 'gpt3-ai-content-generator'); ?>" aria-label="<?php esc_attr_e('Message', 'gpt3-ai-content-generator'); ?>">
                    <button type="submit" class="aipkit-setup__btn aipkit-setup__btn--icon" aria-label="<?php esc_attr_e('Send', 'gpt3-ai-content-generator'); ?>"><?php $aipkit_setup_icon($aipkit_setup_arrow, 18, 'currentColor', '2'); ?></button>
                </form>
            </div>
        </section>

        <section class="aipkit-setup__step aipkit-setup__mid" data-step="try-write" hidden>
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
            <span class="aipkit-setup__hero-icon aipkit-setup__hero-icon--green"><?php $aipkit_setup_icon($aipkit_setup_check, 36, '#1d7a45', '2.4'); ?></span>
            <h1><?php esc_html_e('You’re all set', 'gpt3-ai-content-generator'); ?></h1>
            <dl class="aipkit-setup__summary">
                <div><dt><?php esc_html_e('Your tools', 'gpt3-ai-content-generator'); ?></dt><dd data-summary-tools></dd></div>
                <div><dt><?php esc_html_e('AI connection', 'gpt3-ai-content-generator'); ?></dt><dd data-summary-connection></dd></div>
            </dl>
            <p class="aipkit-setup__muted" data-summary-note><?php esc_html_e('Other tools are turned off to keep things simple. Turn them on anytime in Settings.', 'gpt3-ai-content-generator'); ?></p>
            <button type="button" class="aipkit-setup__btn aipkit-setup__btn--lg" data-action="finish"></button>
        </section>
    </main>
</div>
