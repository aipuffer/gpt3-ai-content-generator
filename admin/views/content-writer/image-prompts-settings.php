<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This partial only defines local rendering data.

use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;

$prompt_library = AIPKit_Content_Writer_Prompts::get_prompt_library();

$image_prompt_items = [
    [
        'key' => 'image',
        'label' => __('Content images', 'gpt3-ai-content-generator'),
        'row_id' => 'aipkit_cw_image_prompt_field',
        'button_id' => 'aipkit_cw_image_prompt_btn',
        'toggle' => [
            'id' => 'aipkit_cw_generate_images_enabled',
            'name' => 'generate_images_enabled',
            'checked' => false,
        ],
        'textarea' => [
            'id' => 'aipkit_cw_image_prompt',
            'name' => 'image_prompt',
            'value' => AIPKit_Content_Writer_Prompts::get_default_image_prompt(),
            'placeholder' => __('Describe the image to generate.', 'gpt3-ai-content-generator'),
        ],
        'library' => [
            'select_id' => 'aipkit_cw_image_prompt_library',
            'options' => $prompt_library['image'] ?? [],
            'default_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_prompt(),
        ],
        'placeholders' => ['{topic}', '{keywords}', '{excerpt}', '{post_title}'],
        'placeholders_prompt_type' => 'image',
    ],
    [
        'key' => 'featured_image',
        'label' => __('Featured image', 'gpt3-ai-content-generator'),
        'row_id' => 'aipkit_cw_featured_image_prompt_field',
        'button_id' => 'aipkit_cw_featured_image_prompt_btn',
        'toggle' => [
            'id' => 'aipkit_cw_generate_featured_image',
            'name' => 'generate_featured_image',
            'checked' => false,
        ],
        'textarea' => [
            'id' => 'aipkit_cw_featured_image_prompt',
            'name' => 'featured_image_prompt',
            'value' => AIPKit_Content_Writer_Prompts::get_default_featured_image_prompt(),
            'placeholder' => __('Leave blank to use the content image prompt.', 'gpt3-ai-content-generator'),
        ],
        'library' => [
            'select_id' => 'aipkit_cw_featured_image_prompt_library',
            'options' => $prompt_library['featured_image'] ?? [],
            'default_prompt' => AIPKit_Content_Writer_Prompts::get_default_featured_image_prompt(),
        ],
        'placeholders' => ['{topic}', '{post_title}', '{excerpt}', '{keywords}'],
        'placeholders_prompt_type' => 'featured_image',
    ],
];

$image_metadata_items = [
    [
        'key' => 'image_title',
        'label' => __('Image title', 'gpt3-ai-content-generator'),
        'toggle' => ['id' => 'aipkit_cw_generate_image_title', 'name' => 'generate_image_title'],
        'textarea' => ['id' => 'aipkit_cw_image_title_prompt', 'name' => 'image_title_prompt', 'value' => AIPKit_Content_Writer_Prompts::get_default_image_title_prompt(), 'placeholder' => __('Describe how to write the image title.', 'gpt3-ai-content-generator')],
        'library' => ['select_id' => 'aipkit_cw_image_title_prompt_library', 'options' => $prompt_library['image_title'] ?? [], 'update_options' => $prompt_library['image_title_update'] ?? [], 'default_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_title_prompt()],
        'placeholders_prompt_type' => 'image_title',
    ],
    [
        'key' => 'image_alt_text',
        'label' => __('Alt text', 'gpt3-ai-content-generator'),
        'toggle' => ['id' => 'aipkit_cw_generate_image_alt_text', 'name' => 'generate_image_alt_text'],
        'textarea' => ['id' => 'aipkit_cw_image_alt_text_prompt', 'name' => 'image_alt_text_prompt', 'value' => AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt(), 'placeholder' => __('Describe how to write the alt text.', 'gpt3-ai-content-generator')],
        'library' => ['select_id' => 'aipkit_cw_image_alt_text_prompt_library', 'options' => $prompt_library['image_alt_text'] ?? [], 'update_options' => $prompt_library['image_alt_text_update'] ?? [], 'default_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt()],
        'placeholders_prompt_type' => 'image_alt_text',
    ],
    [
        'key' => 'image_caption',
        'label' => __('Caption', 'gpt3-ai-content-generator'),
        'toggle' => ['id' => 'aipkit_cw_generate_image_caption', 'name' => 'generate_image_caption'],
        'textarea' => ['id' => 'aipkit_cw_image_caption_prompt', 'name' => 'image_caption_prompt', 'value' => AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt(), 'placeholder' => __('Describe how to write the caption.', 'gpt3-ai-content-generator')],
        'library' => ['select_id' => 'aipkit_cw_image_caption_prompt_library', 'options' => $prompt_library['image_caption'] ?? [], 'update_options' => $prompt_library['image_caption_update'] ?? [], 'default_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt()],
        'placeholders_prompt_type' => 'image_caption',
    ],
    [
        'key' => 'image_description',
        'label' => __('Description', 'gpt3-ai-content-generator'),
        'toggle' => ['id' => 'aipkit_cw_generate_image_description', 'name' => 'generate_image_description'],
        'textarea' => ['id' => 'aipkit_cw_image_description_prompt', 'name' => 'image_description_prompt', 'value' => AIPKit_Content_Writer_Prompts::get_default_image_description_prompt(), 'placeholder' => __('Describe how to write the image description.', 'gpt3-ai-content-generator')],
        'library' => ['select_id' => 'aipkit_cw_image_description_prompt_library', 'options' => $prompt_library['image_description'] ?? [], 'update_options' => $prompt_library['image_description_update'] ?? [], 'default_prompt' => AIPKit_Content_Writer_Prompts::get_default_image_description_prompt()],
        'placeholders_prompt_type' => 'image_description',
    ],
];

foreach ($image_metadata_items as &$image_metadata_item) {
    $image_metadata_item['placeholders'] = ['{topic}', '{keywords}', '{post_title}', '{excerpt}', '{file_name}'];
    $image_metadata_item['button_class'] = 'aipkit_cw_image_metadata_prompt_btn';
    $image_metadata_item['toggle']['checked'] = false;
    $image_metadata_item['toggle']['class'] = 'aipkit_cw_image_metadata_subtoggle';
}
unset($image_metadata_item);
?>

<div id="aipkit_cw_image_prompt_main_block" hidden>
    <?php foreach ($image_prompt_items as $image_prompt_item) : ?>
        <?php $aipkit_cw_render_inline_prompt($image_prompt_item); ?>
    <?php endforeach; ?>
</div>

<div class="aipkit_cw_prompt_field aipkit_cw_prompt_field--image-group" id="aipkit_cw_image_prompts_prompt_item" hidden>
    <button
        type="button"
        class="aipkit_cw_prompt_image_group_toggle"
        data-aipkit-cw-image-prompts-toggle
        aria-controls="aipkit_cw_image_prompts_panel"
        aria-expanded="false"
    >
        <span class="aipkit_cw_prompt_field_label_wrap">
            <span class="aipkit_cw_prompt_field_label"><?php esc_html_e('Prompts', 'gpt3-ai-content-generator'); ?></span>
        </span>
        <span class="aipkit_cw_prompt_image_group_chevron" aria-hidden="true"></span>
    </button>
    <div id="aipkit_cw_image_prompts_panel" class="aipkit_cw_prompt_image_group_panel" data-aipkit-cw-image-prompts-panel hidden>
        <div id="aipkit_cw_image_metadata_block" hidden>
            <?php foreach ($image_metadata_items as $image_metadata_item) : ?>
                <?php $aipkit_cw_render_inline_prompt($image_metadata_item); ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
