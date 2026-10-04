<?php
/** Shared by the automation and AI form steps: what was created, where to see it, and the way on. */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="aipkit-setup__result" data-template-result hidden>
    <p class="aipkit-setup__result-text" data-result-message role="status"></p>
    <div class="aipkit-setup__row">
        <a class="aipkit-setup__btn" data-result-primary href="#" target="_blank" rel="noopener"></a>
        <a class="aipkit-setup__btn aipkit-setup__btn--ghost" data-result-secondary href="#" target="_blank" rel="noopener"></a>
        <button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="done"><?php esc_html_e('Continue', 'gpt3-ai-content-generator'); ?></button>
    </div>
</div>
<div><button type="button" class="aipkit-setup__link" data-action="done" data-template-skip><?php esc_html_e('Start from scratch instead', 'gpt3-ai-content-generator'); ?></button></div>
