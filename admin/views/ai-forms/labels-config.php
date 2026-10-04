<?php
/** Shared label controls for the AI Form editor. */
if (!defined('ABSPATH')) {
    exit;
}

$aipkit_label_groups = [
    'buttons' => [
        'title' => __('Buttons', 'gpt3-ai-content-generator'),
        'fields' => [
            'generate_button' => __('Generate', 'gpt3-ai-content-generator'),
            'stop_button' => __('Stop', 'gpt3-ai-content-generator'),
            'download_button' => __('Download', 'gpt3-ai-content-generator'),
            'save_button' => __('Save', 'gpt3-ai-content-generator'),
            'copy_button' => __('Copy', 'gpt3-ai-content-generator'),
        ],
    ],
    'provider' => [
        'title' => __('Provider display', 'gpt3-ai-content-generator'),
        'fields' => [
            'provider_label' => __('Engine', 'gpt3-ai-content-generator'),
            'model_label' => __('Model', 'gpt3-ai-content-generator'),
        ],
    ],
];
?>
<div class="aipkit_popover_options_list aipkit_ai_form_labels_list">
    <?php foreach ($aipkit_label_groups as $aipkit_group_key => $aipkit_label_group): ?>
        <section class="aipkit_ai_form_labels_group" aria-labelledby="aipkit_ai_form_labels_<?php echo esc_attr($aipkit_group_key); ?>_title">
            <h6 class="aipkit_ai_form_labels_group_title" id="aipkit_ai_form_labels_<?php echo esc_attr($aipkit_group_key); ?>_title">
                <?php echo esc_html($aipkit_label_group['title']); ?>
            </h6>
            <div class="aipkit_ai_form_labels_group_fields">
                <?php foreach ($aipkit_label_group['fields'] as $aipkit_field_key => $aipkit_field_label): ?>
                    <div class="aipkit_popover_option_row aipkit_ai_form_label_field aipkit_ai_form_label_field--inline">
                        <div class="aipkit_popover_option_main">
                            <label class="aipkit_popover_option_label" for="aif_label_<?php echo esc_attr($aipkit_field_key); ?>"><?php echo esc_html($aipkit_field_label); ?></label>
                            <input type="text" id="aif_label_<?php echo esc_attr($aipkit_field_key); ?>" name="labels[<?php echo esc_attr($aipkit_field_key); ?>]" class="aipkit_popover_option_input" placeholder="<?php echo esc_attr($aipkit_field_label); ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if (!empty($is_pro)): ?>
        <?php aipkit_render_paid_ai_form_editor_panel('labels'); ?>
    <?php endif; ?>
</div>
