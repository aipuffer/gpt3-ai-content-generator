<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Shared view partial configured by parent templates.

$aipkit_feature_promo_class = isset($aipkit_feature_promo_class) ? (string) $aipkit_feature_promo_class : '';
$aipkit_feature_promo_dashicon = isset($aipkit_feature_promo_dashicon) ? sanitize_html_class((string) $aipkit_feature_promo_dashicon) : '';
$aipkit_feature_promo_title = isset($aipkit_feature_promo_title) ? (string) $aipkit_feature_promo_title : '';
$aipkit_feature_promo_subtitle = isset($aipkit_feature_promo_subtitle) ? (string) $aipkit_feature_promo_subtitle : '';
$aipkit_feature_promo_steps = isset($aipkit_feature_promo_steps) && is_array($aipkit_feature_promo_steps) ? $aipkit_feature_promo_steps : [];
$aipkit_feature_promo_cards = isset($aipkit_feature_promo_cards) && is_array($aipkit_feature_promo_cards) ? $aipkit_feature_promo_cards : [];
$aipkit_feature_promo_upgrade_url = isset($aipkit_feature_promo_upgrade_url) ? (string) $aipkit_feature_promo_upgrade_url : '#';
$aipkit_feature_promo_docs_url = isset($aipkit_feature_promo_docs_url) ? (string) $aipkit_feature_promo_docs_url : 'https://docs.aipower.org/';
$aipkit_feature_promo_minimal = !empty($aipkit_feature_promo_minimal);
$aipkit_feature_promo_show_pro_badge = !empty($aipkit_feature_promo_show_pro_badge);
$aipkit_feature_promo_show_docs_link = !isset($aipkit_feature_promo_show_docs_link) || !empty($aipkit_feature_promo_show_docs_link);
$aipkit_feature_promo_upgrade_label = isset($aipkit_feature_promo_upgrade_label)
    ? (string) $aipkit_feature_promo_upgrade_label
    : __('Upgrade', 'gpt3-ai-content-generator');
$aipkit_feature_promo_classes = trim(
    'aipkit_feature_promo '
    . $aipkit_feature_promo_class
    . ' aipkit_feature_promo--compact'
    . ($aipkit_feature_promo_minimal ? ' aipkit_feature_promo--minimal' : '')
);
?>
<div class="<?php echo esc_attr($aipkit_feature_promo_classes); ?>">
    <div class="aipkit_feature_promo_hero">
        <div class="aipkit_feature_promo_icon_ring">
            <span class="dashicons <?php echo esc_attr($aipkit_feature_promo_dashicon); ?>" aria-hidden="true"></span>
        </div>
        <div class="aipkit_feature_promo_copy">
            <div class="aipkit_feature_promo_heading">
                <h3 class="aipkit_feature_promo_title"><?php echo esc_html($aipkit_feature_promo_title); ?></h3>
                <?php if ($aipkit_feature_promo_show_pro_badge) : ?>
                    <span class="aipkit_feature_promo_badge aipkit_pro_badge">
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="aipkit_feature_promo_subtitle"><?php echo esc_html($aipkit_feature_promo_subtitle); ?></p>
        </div>
    </div>

    <?php if (!empty($aipkit_feature_promo_steps)) : ?>
        <div class="aipkit_feature_promo_steps">
            <?php foreach (array_values($aipkit_feature_promo_steps) as $aipkit_feature_promo_step_index => $aipkit_feature_promo_step) : ?>
                <?php if ($aipkit_feature_promo_step_index > 0) : ?>
                    <span class="aipkit_feature_promo_step_arrow" aria-hidden="true">&rarr;</span>
                <?php endif; ?>
                <div class="aipkit_feature_promo_step">
                    <span class="aipkit_feature_promo_step_text"><?php echo esc_html((string) $aipkit_feature_promo_step); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($aipkit_feature_promo_cards)) : ?>
        <div class="aipkit_feature_promo_cards">
            <?php foreach ($aipkit_feature_promo_cards as $aipkit_feature_promo_card) : ?>
                <?php
                $aipkit_feature_promo_card_icon = isset($aipkit_feature_promo_card['icon']) ? (string) $aipkit_feature_promo_card['icon'] : '';
                $aipkit_feature_promo_card_dashicon = isset($aipkit_feature_promo_card['dashicon']) ? sanitize_html_class((string) $aipkit_feature_promo_card['dashicon']) : '';
                $aipkit_feature_promo_card_color = isset($aipkit_feature_promo_card['color']) ? sanitize_hex_color((string) $aipkit_feature_promo_card['color']) : '';
                $aipkit_feature_promo_card_label = isset($aipkit_feature_promo_card['label']) ? (string) $aipkit_feature_promo_card['label'] : '';
                $aipkit_feature_promo_card_icon_classes = trim(
                    'aipkit_feature_promo_card_icon'
                    . ($aipkit_feature_promo_card_dashicon ? ' dashicons ' . $aipkit_feature_promo_card_dashicon : '')
                );
                ?>
                <div class="aipkit_feature_promo_card">
                    <span
                        class="<?php echo esc_attr($aipkit_feature_promo_card_icon_classes); ?>"
                        <?php if (!$aipkit_feature_promo_card_dashicon) : ?>
                            style="color:<?php echo esc_attr($aipkit_feature_promo_card_color ?: '#2563eb'); ?>"
                        <?php endif; ?>
                        aria-hidden="true"
                    ><?php echo $aipkit_feature_promo_card_dashicon ? '' : esc_html($aipkit_feature_promo_card_icon); ?></span>
                    <span class="aipkit_feature_promo_card_label"><?php echo esc_html($aipkit_feature_promo_card_label); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="aipkit_feature_promo_cta">
        <a class="aipkit_btn aipkit_feature_promo_btn aipkit_pro_upgrade_button" href="<?php echo esc_url($aipkit_feature_promo_upgrade_url); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo esc_html($aipkit_feature_promo_upgrade_label); ?>
        </a>
        <?php if ($aipkit_feature_promo_show_docs_link) : ?>
            <a class="aipkit_feature_promo_link" href="<?php echo esc_url($aipkit_feature_promo_docs_url); ?>" target="_blank" rel="noopener noreferrer">
                <span class="aipkit_feature_promo_link_label"><?php esc_html_e('Learn more', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_feature_promo_link_arrow" aria-hidden="true">&rarr;</span>
            </a>
        <?php endif; ?>
    </div>
</div>
