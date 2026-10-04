<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This partial only defines local rendering data.

use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;

$prompt_items = AIPKit_Content_Writer_Prompts::get_content_writer_prompt_items();
$prompt_editors = AIPKit_Content_Writer_Prompts::get_content_writer_prompt_inline_items();

$prompt_item_by_key = [];
foreach ($prompt_items as $prompt_item) {
    $prompt_item_by_key[(string) ($prompt_item['key'] ?? '')] = $prompt_item;
}

$prompt_labels = [
    'title' => __('Title', 'gpt3-ai-content-generator'),
    'content' => __('Content', 'gpt3-ai-content-generator'),
    'meta' => __('Meta description', 'gpt3-ai-content-generator'),
    'keyword' => __('Focus keyword', 'gpt3-ai-content-generator'),
    'excerpt' => __('Excerpt', 'gpt3-ai-content-generator'),
    'tags' => __('Tags', 'gpt3-ai-content-generator'),
];

$inline_prompt_items = [];
foreach ($prompt_editors as $prompt_editor) {
    $prompt_key = (string) ($prompt_editor['key'] ?? '');
    $prompt_item = $prompt_item_by_key[$prompt_key] ?? [];
    $prompt_editor['label'] = $prompt_labels[$prompt_key] ?? (string) ($prompt_item['label'] ?? '');
    $prompt_editor['toggle'] = [
        'id' => (string) ($prompt_item['field_id'] ?? ''),
        'name' => (string) ($prompt_item['field_name'] ?? ''),
        'checked' => !empty($prompt_item['checked']),
        'update_only' => !empty($prompt_item['update_only']),
    ];
    $inline_prompt_items[] = $prompt_editor;
}
?>

<input type="hidden" name="prompt_mode" id="aipkit_cw_prompt_mode_hidden_input" value="custom">
<input type="hidden" name="custom_title_prompt_update" id="aipkit_cw_custom_title_prompt_update" value="">
<input type="hidden" name="custom_content_prompt_update" id="aipkit_cw_custom_content_prompt_update" value="">
<input type="hidden" name="custom_meta_prompt_update" id="aipkit_cw_custom_meta_prompt_update" value="">
<input type="hidden" name="custom_keyword_prompt_update" id="aipkit_cw_custom_keyword_prompt_update" value="">
<input type="hidden" name="custom_excerpt_prompt_update" id="aipkit_cw_custom_excerpt_prompt_update" value="">
<input type="hidden" name="custom_tags_prompt_update" id="aipkit_cw_custom_tags_prompt_update" value="">

<div class="aipkit_cw_prompt_fields">
    <?php foreach ($inline_prompt_items as $inline_prompt_item) : ?>
        <?php $aipkit_cw_render_inline_prompt($inline_prompt_item); ?>
    <?php endforeach; ?>
</div>
