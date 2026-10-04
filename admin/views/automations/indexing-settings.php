<?php
/** Automations knowledge destination, source scope and indexing behavior. */
if (!defined('ABSPATH')) {
    exit;
}

function aipkit_render_automation_indexing_settings(array $aipkit_view_data): void
{
    $all_selectable_post_types = $aipkit_view_data['all_selectable_post_types'] ?? [];
    ?>
    <div id="aipkit_task_ci_source_settings" class="aipkit_ci_source_panel">
        <div class="aipkit_ci_source_grid">
            <section class="aipkit_ci_card aipkit_ci_card--destination">
                <div class="aipkit_ci_target_stack">
                    <div class="aipkit_ci_target_row">
                        <label class="aipkit_ci_target_label" for="aipkit_task_content_indexing_target_store_provider">
                            <?php esc_html_e('Provider', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <select
                            id="aipkit_task_content_indexing_target_store_provider"
                            name="target_store_provider"
                            class="aipkit_form-input aipkit_ci_target_select aipkit_autosave_trigger"
                        >
                            <option value="local"><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                            <option value="openai" selected>OpenAI</option>
                            <option value="google"><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                            <option value="pinecone">Pinecone</option>
                            <option value="qdrant">Qdrant</option>
                            <option value="chroma">Chroma</option>
                        </select>
                    </div>

                    <div class="aipkit_ci_target_row">
                        <label class="aipkit_ci_target_label" for="aipkit_task_content_indexing_target_store_id">
                            <?php esc_html_e('Store / Index', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <select
                            id="aipkit_task_content_indexing_target_store_id"
                            name="target_store_id"
                            class="aipkit_form-input aipkit_ci_target_select aipkit_autosave_trigger"
                        >
                            <option value=""><?php esc_html_e('-- Select Store/Index --', 'gpt3-ai-content-generator'); ?></option>
                            <?php // Options populated by JS. ?>
                        </select>
                    </div>

                    <div class="aipkit_ci_target_row aipkit_ci_target_row--model" id="aipkit_task_content_indexing_embedding_model_group" hidden>
                        <label class="aipkit_ci_target_label" for="aipkit_task_content_indexing_embedding_model_trigger">
                            <?php esc_html_e('Embedding Model', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <select
                            id="aipkit_task_content_indexing_embedding_model"
                            name="embedding_model"
                            class="aipkit_autosave_trigger"
                            data-aipkit-unified-model-source
                            hidden
                            aria-hidden="true"
                            tabindex="-1"
                        >
                            <option value=""><?php esc_html_e('-- Select Model --', 'gpt3-ai-content-generator'); ?></option>
                            <?php // Options and optgroups populated by JS. ?>
                        </select>
                        <?php
                        $aipkit_unified_model_selector_config = [
                            'trigger_id' => 'aipkit_task_content_indexing_embedding_model_trigger',
                            'source_id' => 'aipkit_task_content_indexing_embedding_model',
                            'initial_label' => __('Select model', 'gpt3-ai-content-generator'),
                            'class_name' => 'aipkit_autogpt_unified_model_selector',
                            'capability' => 'embeddings',
                            'search_placeholder' => __('Search embedding models...', 'gpt3-ai-content-generator'),
                            'empty_text' => __('No embedding models found', 'gpt3-ai-content-generator'),
                        ];
                        include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                        ?>
                    </div>
                </div>
            </section>

            <section class="aipkit_ci_card aipkit_ci_card--scope aipkit_ci_filter_row">
                <div class="aipkit_ci_card_header">
                    <label class="aipkit_ci_target_label" for="aipkit_task_content_indexing_post_types"><?php esc_html_e('Content types', 'gpt3-ai-content-generator'); ?></label>
                </div>
                <select id="aipkit_task_content_indexing_post_types" name="post_types[]" class="aipkit_form-input aipkit_ci_multi_select" data-aipkit-checklist-style="inline-checkboxes" multiple size="5">
                    <?php foreach ($all_selectable_post_types as $slug => $pt_obj): ?>
                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($pt_obj->label); ?></option>
                    <?php endforeach; ?>
                </select>
            </section>
            <section class="aipkit_ci_card aipkit_ci_card--scope aipkit_ci_filter_row aipkit_ci_categories_row">
                <div class="aipkit_ci_card_header">
                    <label class="aipkit_ci_target_label" for="aipkit_task_content_indexing_categories"><?php esc_html_e('Categories', 'gpt3-ai-content-generator'); ?></label>
                </div>
                <select id="aipkit_task_content_indexing_categories" name="indexing_categories[]" class="aipkit_form-input aipkit_ci_multi_select" data-aipkit-checklist-style="inline-checkboxes" data-aipkit-checklist-disclosure="dropdown" multiple size="5">
                    <?php
                    $indexing_categories = get_categories(['hide_empty' => false]);
                    foreach ($indexing_categories as $category): ?>
                        <option value="<?php echo esc_attr($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </section>
        </div>

        <section class="aipkit_ci_card aipkit_ci_card--behavior">
            <div class="aipkit_ci_option_list">
                <div class="aipkit_ci_option_card">
                    <label class="aipkit_ci_option_copy" for="aipkit_task_content_indexing_index_existing">
                        <span class="aipkit_ci_option_title"><?php esc_html_e('Queue all existing content now', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_ci_option_desc"><?php esc_html_e('Builds the initial knowledge base, then turns off automatically.', 'gpt3-ai-content-generator'); ?></span>
                    </label>
                    <label class="aipkit_switch aipkit_ci_option_switch" for="aipkit_task_content_indexing_index_existing">
                        <input type="checkbox" name="index_existing_now_flag" id="aipkit_task_content_indexing_index_existing" value="1" checked>
                        <span class="aipkit_switch_slider"></span>
                    </label>
                </div>

                <div class="aipkit_ci_option_card">
                    <label class="aipkit_ci_option_copy" for="aipkit_task_content_indexing_only_new_updated">
                        <span class="aipkit_ci_option_title"><?php esc_html_e('Auto-index new and updated content', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_ci_option_desc"><?php esc_html_e('Keeps the knowledge base current as content changes.', 'gpt3-ai-content-generator'); ?></span>
                    </label>
                    <label class="aipkit_switch aipkit_ci_option_switch" for="aipkit_task_content_indexing_only_new_updated">
                        <input type="checkbox" name="only_new_updated_flag" id="aipkit_task_content_indexing_only_new_updated" value="1" checked>
                        <span class="aipkit_switch_slider"></span>
                    </label>
                </div>
            </div>
        </section>
    </div>
<?php
}
