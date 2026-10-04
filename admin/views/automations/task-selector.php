<?php
/** Automations task-family and content-source choices. */
if (!defined('ABSPATH')) {
    exit;
}

function aipkit_render_automation_task_selector(bool $aipkit_autogpt_is_pro): void
{
    $aipkit_autogpt_category_groups = [
        [
            'category' => 'content_creation',
            'family_label' => __('Create new content', 'gpt3-ai-content-generator'),
            'family_description' => __('Turn topics, feeds, URLs, or spreadsheets into WordPress posts.', 'gpt3-ai-content-generator'),
            'family_icon' => 'dashicons-edit-page',
        ],
        [
            'category' => 'content_enhancement',
            'family_label' => __('Rewrite existing content', 'gpt3-ai-content-generator'),
            'family_description' => __('Automatically improve your existing content.', 'gpt3-ai-content-generator'),
            'family_icon' => 'dashicons-update',
            'direct_task_type' => 'enhance_existing_content',
            'pro' => true,
        ],
        [
            'category' => 'knowledge_base',
            'family_label' => __('Build a knowledge base', 'gpt3-ai-content-generator'),
            'family_description' => __('Add WordPress content to your knowledge base and keep it updated.', 'gpt3-ai-content-generator'),
            'family_icon' => 'dashicons-database',
            'direct_task_type' => 'content_indexing',
        ],
        [
            'category' => 'community_engagement',
            'family_label' => __('Reply to comments', 'gpt3-ai-content-generator'),
            'family_description' => __('Generate replies when new comments are posted.', 'gpt3-ai-content-generator'),
            'family_icon' => 'dashicons-admin-comments',
            'direct_task_type' => 'community_reply_comments',
        ],
    ];
    ?>
    <div class="aipkit_cw_source_selector_wrapper aipkit_autogpt_intent_selector" data-aipkit-autogpt-category-selector data-template-ready="1" data-view="families">
        <div class="aipkit_autogpt_family_grid" data-aipkit-autogpt-family-grid>
            <?php foreach ($aipkit_autogpt_category_groups as $group) : ?>
                <div class="aipkit_autogpt_family_item" data-aipkit-autogpt-family-item="<?php echo esc_attr($group['category']); ?>">
                    <button
                        type="button"
                        class="aipkit_autogpt_family_card"
                        data-aipkit-autogpt-family="<?php echo esc_attr($group['category']); ?>"
                        <?php if (!empty($group['direct_task_type'])) : ?>
                            data-aipkit-autogpt-direct-task-type="<?php echo esc_attr($group['direct_task_type']); ?>"
                        <?php else : ?>
                            data-aipkit-autogpt-default-task-type="content_writing_bulk"
                        <?php endif; ?>
                        aria-pressed="false"
                    >
                        <span class="aipkit_autogpt_family_icon dashicons <?php echo esc_attr($group['family_icon']); ?>" aria-hidden="true"></span>
                        <span class="aipkit_autogpt_family_copy">
                            <span class="aipkit_autogpt_choice_title_row">
                                <span class="aipkit_autogpt_family_title"><?php echo esc_html($group['family_label']); ?></span>
                                <?php if (!empty($group['pro']) && !$aipkit_autogpt_is_pro) : ?>
                                    <span class="aipkit_autogpt_pro_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($group['family_description'])) : ?>
                                <span class="aipkit_autogpt_family_desc"><?php echo esc_html($group['family_description']); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="aipkit_autogpt_family_check" aria-hidden="true"></span>
                    </button>

                    <div class="aipkit_autogpt_family_panel" data-aipkit-autogpt-family-slot="<?php echo esc_attr($group['category']); ?>" hidden>
                        <?php if (empty($group['direct_task_type'])) : ?>
                            <div class="aipkit_cw_mode_section aipkit_autogpt_task_choices" data-aipkit-autogpt-task-choices="<?php echo esc_attr($group['category']); ?>">
                                <div class="aipkit_autogpt_compact_choice_row">
                                    <span class="aipkit_autogpt_compact_choice_label"><?php esc_html_e('Content source', 'gpt3-ai-content-generator'); ?></span>
                                    <div class="aipkit_autogpt_compact_choices" role="group" aria-label="<?php esc_attr_e('Content source', 'gpt3-ai-content-generator'); ?>">
                                        <button type="button" class="aipkit_cw_mode_card aipkit_autogpt_source_option" data-aipkit-entry-mode="manual" data-entry-view="batch" data-aipkit-batch-editor-toggle data-category="content_creation" data-task-type="content_writing_bulk" aria-pressed="false"><?php esc_html_e('Batch editor', 'gpt3-ai-content-generator'); ?></button>
                                        <button type="button" class="aipkit_cw_mode_card aipkit_autogpt_quick_paste_option" data-entry-view="paste" data-aipkit-paste-topics-toggle data-category="content_creation" data-task-type="content_writing_bulk" aria-pressed="false" aria-expanded="false" aria-controls="aipkit_task_cw_paste_importer"><?php esc_html_e('Quick paste', 'gpt3-ai-content-generator'); ?></button>
                                        <button type="button" class="aipkit_cw_mode_card aipkit_autogpt_source_option" data-aipkit-entry-mode="bulk" data-category="content_creation" data-task-type="content_writing_csv" aria-pressed="false"><?php esc_html_e('Import CSV', 'gpt3-ai-content-generator'); ?></button>
                                        <button type="button" class="aipkit_cw_mode_card aipkit_autogpt_source_option" data-aipkit-source-group="rss" data-category="content_creation" data-task-type="content_writing_rss" aria-pressed="false" <?php echo !$aipkit_autogpt_is_pro ? 'title="' . esc_attr__('This is a Pro feature.', 'gpt3-ai-content-generator') . '"' : ''; ?>><?php esc_html_e('RSS feed', 'gpt3-ai-content-generator'); ?></button>
                                        <button type="button" class="aipkit_cw_mode_card aipkit_autogpt_source_option" data-aipkit-source-group="url" data-category="content_creation" data-task-type="content_writing_url" aria-pressed="false" <?php echo !$aipkit_autogpt_is_pro ? 'title="' . esc_attr__('This is a Pro feature.', 'gpt3-ai-content-generator') . '"' : ''; ?>><?php esc_html_e('URL', 'gpt3-ai-content-generator'); ?></button>
                                        <button type="button" class="aipkit_cw_mode_card aipkit_autogpt_source_option" data-aipkit-source-group="spreadsheet" data-category="content_creation" data-task-type="content_writing_gsheets" aria-pressed="false" <?php echo !$aipkit_autogpt_is_pro ? 'title="' . esc_attr__('This is a Pro feature.', 'gpt3-ai-content-generator') . '"' : ''; ?>><?php esc_html_e('Spreadsheet', 'gpt3-ai-content-generator'); ?></button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php
}
