<?php
/**
 * Pieces shared by the Look and Features tabs: a row per feature that shows its state and opens a side
 * panel (or a sheet), and the side panel's frame. The chatbot page includes this once, before its tabs.
 *
 * Feature rows take: key, icon, title, hint; optional state (bool), change (bool), pro, locked, sheet
 * (title, description, content), hint_attributes and button_attributes (escaped by the caller).
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template helpers shared by the chatbot page's includes.

/**
 * One row per feature: its state and a chevron when it opens a side panel, or Upgrade when the plan locks it.
 * Features with nothing to set up are plain switches instead.
 */
$render_feature_row = static function (array $row) use ($pricing_url, $is_pro_plan): void {
    $key = (string) $row['key'];
    $is_locked = !empty($row['locked']);
    $state = $row['state'] ?? null;
    $sheet = $row['sheet'] ?? null;
    $hint_attributes = (string) ($row['hint_attributes'] ?? '');
    $button_attributes = (string) ($row['button_attributes'] ?? '');
    ?>
    <div class="aipkit_feature_row<?php echo $is_locked ? ' is-locked' : ''; ?>" data-aipkit-feature-row="<?php echo esc_attr($key); ?>">
        <?php if ($is_locked) : ?>
            <div class="aipkit_feature_row_main">
        <?php elseif ($button_attributes !== '') : ?>
            <button type="button" class="aipkit_feature_row_main"<?php echo $button_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>>
        <?php elseif (is_array($sheet)) : ?>
            <button
                type="button"
                class="aipkit_feature_row_main aipkit_builder_sheet_trigger"
                data-sheet-title="<?php echo esc_attr($sheet['title']); ?>"
                data-sheet-description="<?php echo esc_attr($sheet['description']); ?>"
                data-sheet-content="<?php echo esc_attr($sheet['content']); ?>"
            >
        <?php else : ?>
            <button
                type="button"
                class="aipkit_feature_row_main"
                data-aipkit-feature-open="<?php echo esc_attr($key); ?>"
                aria-haspopup="dialog"
                aria-expanded="false"
                aria-controls="aipkit_feature_drawer_<?php echo esc_attr($key); ?>"
            >
        <?php endif; ?>
            <span class="aipkit_feature_icon dashicons dashicons-<?php echo esc_attr($row['icon']); ?>" aria-hidden="true"></span>
            <span class="aipkit_feature_row_copy">
                <span class="aipkit_feature_row_title">
                    <?php echo esc_html($row['title']); ?>
                    <?php if (!$is_pro_plan && !empty($row['pro'])) : ?>
                        <span class="aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                    <?php endif; ?>
                </span>
                <span class="aipkit_feature_row_hint" data-aipkit-feature-hint<?php echo $hint_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>><?php echo esc_html($row['hint']); ?></span>
            </span>
            <?php if (!$is_locked) : ?>
                <?php if ($state !== null) : ?>
                    <span class="aipkit_feature_state" data-aipkit-feature-state data-state="<?php echo $state ? 'on' : 'off'; ?>">
                        <?php $state ? esc_html_e('On', 'gpt3-ai-content-generator') : esc_html_e('Off', 'gpt3-ai-content-generator'); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($row['change'])) : ?>
                    <span class="aipkit_feature_change"><?php esc_html_e('Change', 'gpt3-ai-content-generator'); ?></span>
                <?php else : ?>
                    <span class="aipkit_feature_chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                <?php endif; ?>
            <?php endif; ?>
        <?php if ($is_locked) : ?>
            </div>
            <a class="aipkit_feature_upgrade aipkit_pro_upgrade_button" href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
            </a>
        <?php else : ?>
            </button>
        <?php endif; ?>
    </div>
    <?php
};

// Side panels: settings open beside the preview, like adding knowledge, so the tab stays short.
$render_drawer_start = static function (string $key, string $title, string $hint): void {
    $drawer_id = 'aipkit_feature_drawer_' . $key;
    ?>
    <div
        class="aipkit_feature_drawer"
        id="<?php echo esc_attr($drawer_id); ?>"
        data-aipkit-feature-drawer="<?php echo esc_attr($key); ?>"
        role="dialog"
        aria-modal="true"
        aria-labelledby="<?php echo esc_attr($drawer_id); ?>_title"
        tabindex="-1"
        hidden
    >
        <div class="aipkit_feature_drawer_header">
            <div class="aipkit_feature_drawer_heading">
                <h3 class="aipkit_feature_drawer_title" id="<?php echo esc_attr($drawer_id); ?>_title"><?php echo esc_html($title); ?></h3>
                <p class="aipkit_feature_drawer_hint"><?php echo esc_html($hint); ?></p>
            </div>
            <button type="button" class="aipkit_feature_drawer_close" data-aipkit-feature-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>">
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>
        <div class="aipkit_feature_drawer_body">
    <?php
};
// The footer note: none by default, or a line that says something specific (Pro, apps). An action (label, url)
// takes Done's place, such as Upgrade. The note's slot stays, so Done keeps right and a status can sit beside it.
$render_drawer_end = static function (string $note = '', array $action = []): void {
    $note_text = $note;
    ?>
        </div>
        <div class="aipkit_feature_drawer_footer">
            <span class="aipkit_feature_drawer_note"><?php echo esc_html($note_text); ?></span>
            <?php if (!empty($action['url'])) : ?>
                <a class="aipkit_btn aipkit_btn-primary aipkit_pro_upgrade_button" href="<?php echo esc_url($action['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($action['label']); ?></a>
            <?php else : ?>
                <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-feature-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
