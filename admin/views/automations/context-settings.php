<?php
/** Shared Automations context renderer. */
if (!defined('ABSPATH')) {
    exit;
}

function aipkit_render_automation_context_settings(array $aipkit_autogpt_context_config, array $aipkit_view_data = []): void
{
    $openai_vector_stores = $aipkit_view_data['openai_vector_stores'] ?? [];
    $google_file_search_stores = $aipkit_view_data['google_file_search_stores'] ?? [];
    $pinecone_indexes = $aipkit_view_data['pinecone_indexes'] ?? [];
    $qdrant_collections = $aipkit_view_data['qdrant_collections'] ?? [];
    $chroma_collections = $aipkit_view_data['chroma_collections'] ?? [];
    $embedding_provider_options = \WPAICG\AIPKit_Providers::get_embedding_provider_map($aipkit_autogpt_context_config['embedding_context'] ?? 'autogpt_content_writing_ui');
    $feature_defaults = \WPAICG\AIPKit_Providers::get_new_feature_defaults();
    $default_embedding_provider_key = $feature_defaults['vector_embedding_provider'];
    $aipkit_autogpt_context_scope = isset($aipkit_autogpt_context_config['scope'])
        ? (string) $aipkit_autogpt_context_config['scope']
        : 'cw';
    $aipkit_autogpt_context_name_prefix = isset($aipkit_autogpt_context_config['name_prefix'])
        ? (string) $aipkit_autogpt_context_config['name_prefix']
        : '';
    $aipkit_autogpt_context_root_classes = isset($aipkit_autogpt_context_config['root_classes'])
        ? (string) $aipkit_autogpt_context_config['root_classes']
        : 'aipkit_cw_vector_section aipkit_task_cw_kb_section';
    $aipkit_autogpt_context_show_confidence = !empty($aipkit_autogpt_context_config['show_confidence_threshold']);
    $aipkit_autogpt_context_use_autosave = !empty($aipkit_autogpt_context_config['use_autosave_class']);
    $aipkit_autogpt_context_top_k_default = isset($aipkit_autogpt_context_config['top_k_default'])
        ? (int) $aipkit_autogpt_context_config['top_k_default']
        : 3;
    $aipkit_autogpt_context_confidence_default = isset($aipkit_autogpt_context_config['confidence_default'])
        ? (int) $aipkit_autogpt_context_config['confidence_default']
        : 20;
    $chroma_collections = isset($chroma_collections) && is_array($chroma_collections) ? $chroma_collections : [];
    
    $aipkit_autogpt_context_base = 'aipkit_task_' . $aipkit_autogpt_context_scope;
    $aipkit_autogpt_context_enable_id = $aipkit_autogpt_context_base . '_enable_vector_store';
    $aipkit_autogpt_context_provider_field_id = $aipkit_autogpt_context_base . '_vector_store_provider';
    $aipkit_autogpt_context_mode_control_id = $aipkit_autogpt_context_base . '_kb_mode_control';
    $aipkit_autogpt_context_source_row_id = $aipkit_autogpt_context_base . '_kb_source_row';
    $aipkit_autogpt_context_source_label_id = $aipkit_autogpt_context_base . '_kb_source_label';
    $aipkit_autogpt_context_openai_panel_id = $aipkit_autogpt_context_base . '_openai_vector_store_panel';
    $aipkit_autogpt_context_openai_select_id = $aipkit_autogpt_context_base . '_openai_vector_store_ids';
    $aipkit_autogpt_context_google_panel_id = $aipkit_autogpt_context_base . '_google_file_search_store_panel';
    $aipkit_autogpt_context_google_select_id = $aipkit_autogpt_context_base . '_google_file_search_store_names';
    $aipkit_autogpt_context_pinecone_select_id = $aipkit_autogpt_context_base . '_pinecone_index_name';
    $aipkit_autogpt_context_qdrant_select_id = $aipkit_autogpt_context_base . '_qdrant_collection_name';
    $aipkit_autogpt_context_chroma_select_id = $aipkit_autogpt_context_base . '_chroma_collection_name';
    $aipkit_autogpt_context_embedding_section_id = $aipkit_autogpt_context_base . '_kb_embedding_section';
    $aipkit_autogpt_context_embedding_provider_id = $aipkit_autogpt_context_base . '_vector_embedding_provider';
    $aipkit_autogpt_context_embedding_model_id = $aipkit_autogpt_context_base . '_vector_embedding_model';
    $aipkit_autogpt_context_top_k_id = $aipkit_autogpt_context_base . '_vector_store_top_k';
    $aipkit_autogpt_context_confidence_id = $aipkit_autogpt_context_base . '_vector_store_confidence_threshold';
    
    $aipkit_autogpt_context_autosave_class = $aipkit_autogpt_context_use_autosave ? ' aipkit_autosave_trigger' : '';
    ?>
    <div class="<?php echo esc_attr($aipkit_autogpt_context_root_classes); ?>">
        <input
            type="checkbox"
            id="<?php echo esc_attr($aipkit_autogpt_context_enable_id); ?>"
            name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'enable_vector_store'); ?>"
            class="<?php echo esc_attr('aipkit_task_' . $aipkit_autogpt_context_scope . '_vector_store_toggle' . $aipkit_autogpt_context_autosave_class); ?>"
            value="1"
            hidden
        >
        <input
            type="hidden"
            id="<?php echo esc_attr($aipkit_autogpt_context_provider_field_id); ?>"
            name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'vector_store_provider'); ?>"
            class="<?php echo esc_attr(($aipkit_autogpt_context_scope === 'cw' ? 'aipkit_task_cw_vector_store_provider_field' : '') . $aipkit_autogpt_context_autosave_class); ?>"
            value="<?php echo esc_attr($feature_defaults['vector_store_provider']); ?>"
        >
    
        <div class="aipkit_cw_kb_row aipkit_autogpt_question_row aipkit_cw_kb_row--mode">
            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="<?php echo esc_attr($aipkit_autogpt_context_mode_control_id); ?>">
                <?php esc_html_e('Knowledge source', 'gpt3-ai-content-generator'); ?>
            </label>
            <div class="aipkit_cw_kb_control">
                <select
                    id="<?php echo esc_attr($aipkit_autogpt_context_mode_control_id); ?>"
                    class="aipkit_form-input aipkit_cw_blended_chevron_select"
                >
                    <option value="off"><?php esc_html_e('Off', 'gpt3-ai-content-generator'); ?></option>
                    <option value="local"><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                    <option value="openai"><?php esc_html_e('OpenAI', 'gpt3-ai-content-generator'); ?></option>
                    <option value="pinecone"><?php esc_html_e('Pinecone', 'gpt3-ai-content-generator'); ?></option>
                    <option value="qdrant"><?php esc_html_e('Qdrant', 'gpt3-ai-content-generator'); ?></option>
                    <option value="chroma"><?php esc_html_e('Chroma', 'gpt3-ai-content-generator'); ?></option>
                    <option value="google"><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>
    
        <div class="aipkit_cw_kb_row aipkit_autogpt_question_row aipkit_cw_kb_row--source" id="<?php echo esc_attr($aipkit_autogpt_context_source_row_id); ?>" hidden>
            <label
                class="aipkit_cw_panel_label aipkit_autogpt_question"
                id="<?php echo esc_attr($aipkit_autogpt_context_source_label_id); ?>"
                for="<?php echo esc_attr($aipkit_autogpt_context_openai_select_id); ?>"
            >
                <?php esc_html_e('Vector stores', 'gpt3-ai-content-generator'); ?>
            </label>
            <div class="aipkit_cw_kb_control aipkit_cw_kb_control--selection">
                <div class="aipkit_cw_kb_selection_inline">
                    <div class="aipkit_cw_kb_source_fields">
                        <div class="<?php echo esc_attr('aipkit_task_' . $aipkit_autogpt_context_scope . '_vector_openai_field'); ?>" hidden>
                            <div
                                class="aipkit_popover_multiselect aipkit_vector_multiselect"
                                data-aipkit-vector-stores-dropdown
                                data-placeholder="<?php echo esc_attr__('Select stores', 'gpt3-ai-content-generator'); ?>"
                                data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                            >
                                <button
                                    type="button"
                                    class="aipkit_popover_multiselect_btn aipkit_vector_multiselect_btn"
                                    aria-expanded="false"
                                    aria-controls="<?php echo esc_attr($aipkit_autogpt_context_openai_panel_id); ?>"
                                >
                                    <span class="aipkit_popover_multiselect_label">
                                        <?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?>
                                    </span>
                                </button>
                                <div
                                    id="<?php echo esc_attr($aipkit_autogpt_context_openai_panel_id); ?>"
                                    class="aipkit_popover_multiselect_panel"
                                    role="menu"
                                    hidden
                                >
                                    <div class="aipkit_popover_multiselect_options"></div>
                                </div>
                            </div>
                            <select
                                id="<?php echo esc_attr($aipkit_autogpt_context_openai_select_id); ?>"
                                name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'openai_vector_store_ids[]'); ?>"
                                class="<?php echo esc_attr('aipkit_popover_multiselect_select' . $aipkit_autogpt_context_autosave_class); ?>"
                                multiple
                                size="3"
                                hidden
                                aria-hidden="true"
                                tabindex="-1"
                            >
                                <?php if (!empty($openai_vector_stores)) : ?>
                                    <?php foreach ($openai_vector_stores as $store) : ?>
                                        <option value="<?php echo esc_attr($store['id']); ?>">
                                            <?php echo esc_html($store['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="" disabled>
                                        <?php esc_html_e('No stores found', 'gpt3-ai-content-generator'); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
    
                        <div class="<?php echo esc_attr('aipkit_task_' . $aipkit_autogpt_context_scope . '_vector_google_field'); ?>" hidden>
                            <div
                                class="aipkit_popover_multiselect aipkit_vector_multiselect"
                                data-aipkit-vector-stores-dropdown
                                data-placeholder="<?php echo esc_attr__('Select stores', 'gpt3-ai-content-generator'); ?>"
                                data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                            >
                                <button type="button" class="aipkit_popover_multiselect_btn aipkit_vector_multiselect_btn" aria-expanded="false" aria-controls="<?php echo esc_attr($aipkit_autogpt_context_google_panel_id); ?>">
                                    <span class="aipkit_popover_multiselect_label"><?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?></span>
                                </button>
                                <div id="<?php echo esc_attr($aipkit_autogpt_context_google_panel_id); ?>" class="aipkit_popover_multiselect_panel" role="menu" hidden>
                                    <div class="aipkit_popover_multiselect_options"></div>
                                </div>
                            </div>
                            <select
                                id="<?php echo esc_attr($aipkit_autogpt_context_google_select_id); ?>"
                                name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'google_file_search_store_names[]'); ?>"
                                class="<?php echo esc_attr('aipkit_popover_multiselect_select' . $aipkit_autogpt_context_autosave_class); ?>"
                                multiple
                                size="3"
                                hidden
                                aria-hidden="true"
                                tabindex="-1"
                            >
                                <?php foreach ($google_file_search_stores as $store) : ?>
                                    <?php
                                    $aipkit_google_store_name = (string) ($store['resource_name'] ?? $store['id'] ?? '');
                                    $aipkit_google_store_label = (string) ($store['display_name'] ?? $store['name'] ?? $aipkit_google_store_name);
                                    if ($aipkit_google_store_name === '') {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($aipkit_google_store_name); ?>"><?php echo esc_html($aipkit_google_store_label); ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($google_file_search_stores)) : ?>
                                    <option value="" disabled><?php esc_html_e('No stores found', 'gpt3-ai-content-generator'); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
    
                        <div class="<?php echo esc_attr('aipkit_task_' . $aipkit_autogpt_context_scope . '_vector_pinecone_field'); ?>" hidden>
                            <select
                                id="<?php echo esc_attr($aipkit_autogpt_context_pinecone_select_id); ?>"
                                name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'pinecone_index_name'); ?>"
                                class="<?php echo esc_attr('aipkit_form-input aipkit_vector_settings_select' . $aipkit_autogpt_context_autosave_class); ?>"
                                aria-label="<?php esc_attr_e('Pinecone index', 'gpt3-ai-content-generator'); ?>"
                            >
                                <option value=""><?php esc_html_e('Select index', 'gpt3-ai-content-generator'); ?></option>
                                <?php if (!empty($pinecone_indexes)) : ?>
                                    <?php foreach ($pinecone_indexes as $index) : ?>
                                        <option value="<?php echo esc_attr($index['name']); ?>">
                                            <?php echo esc_html($index['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="" disabled>
                                        <?php esc_html_e('No indexes found', 'gpt3-ai-content-generator'); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
    
                        <div class="<?php echo esc_attr('aipkit_task_' . $aipkit_autogpt_context_scope . '_vector_qdrant_field'); ?>" hidden>
                            <select
                                id="<?php echo esc_attr($aipkit_autogpt_context_qdrant_select_id); ?>"
                                name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'qdrant_collection_name'); ?>"
                                class="<?php echo esc_attr('aipkit_form-input aipkit_vector_settings_select' . $aipkit_autogpt_context_autosave_class); ?>"
                                aria-label="<?php esc_attr_e('Qdrant collection', 'gpt3-ai-content-generator'); ?>"
                            >
                                <option value=""><?php esc_html_e('Select collection', 'gpt3-ai-content-generator'); ?></option>
                                <?php if (!empty($qdrant_collections)) : ?>
                                    <?php foreach ($qdrant_collections as $collection) : ?>
                                        <option value="<?php echo esc_attr($collection['name']); ?>">
                                            <?php echo esc_html($collection['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="" disabled>
                                        <?php esc_html_e('No collections found', 'gpt3-ai-content-generator'); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
    
                        <div class="<?php echo esc_attr('aipkit_task_' . $aipkit_autogpt_context_scope . '_vector_local_field'); ?>" hidden>
                            <select
                                id="<?php echo esc_attr($aipkit_autogpt_context_base . '_local_store_id'); ?>"
                                name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'local_store_id'); ?>"
                                class="<?php echo esc_attr('aipkit_form-input aipkit_vector_settings_select' . $aipkit_autogpt_context_autosave_class); ?>"
                                data-aipkit-local-store-select
                                aria-label="<?php esc_attr_e('site knowledge base', 'gpt3-ai-content-generator'); ?>"
                            >
                                <?php
                                if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class)) {
                                    require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
                                }
                                $aipkit_local_stores = \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::stores();
                                ?>
                                <?php if ($aipkit_local_stores) : ?>
                                    <option value=""><?php esc_html_e('Select knowledge base', 'gpt3-ai-content-generator'); ?></option>
                                    <?php foreach ($aipkit_local_stores as $aipkit_local_store) : ?>
                                        <option value="<?php echo esc_attr($aipkit_local_store['id']); ?>"><?php echo esc_html($aipkit_local_store['name']); ?></option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="" disabled><?php esc_html_e('No knowledge bases yet', 'gpt3-ai-content-generator'); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="<?php echo esc_attr('aipkit_task_' . $aipkit_autogpt_context_scope . '_vector_chroma_field'); ?>" hidden>
                            <select
                                id="<?php echo esc_attr($aipkit_autogpt_context_chroma_select_id); ?>"
                                name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'chroma_collection_name'); ?>"
                                class="<?php echo esc_attr('aipkit_form-input aipkit_vector_settings_select' . $aipkit_autogpt_context_autosave_class); ?>"
                                aria-label="<?php esc_attr_e('Chroma collection', 'gpt3-ai-content-generator'); ?>"
                            >
                                <option value=""><?php esc_html_e('Select collection', 'gpt3-ai-content-generator'); ?></option>
                                <?php if (!empty($chroma_collections)) : ?>
                                    <?php foreach ($chroma_collections as $collection) : ?>
                                        <?php
                                        $aipkit_chroma_collection_name = is_array($collection)
                                            ? ($collection['name'] ?? ($collection['collection_name'] ?? ($collection['id'] ?? '')))
                                            : (string) $collection;
                                        if ($aipkit_chroma_collection_name === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($aipkit_chroma_collection_name); ?>">
                                            <?php echo esc_html($aipkit_chroma_collection_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value="" disabled>
                                        <?php esc_html_e('No collections found', 'gpt3-ai-content-generator'); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    
        <div class="aipkit_cw_kb_inline_settings" data-aipkit-kb-inline-settings hidden>
            <div class="aipkit_cw_kb_inline_rows">
                <div id="<?php echo esc_attr($aipkit_autogpt_context_embedding_section_id); ?>" hidden>
                        <select
                            id="<?php echo esc_attr($aipkit_autogpt_context_embedding_provider_id); ?>"
                            name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'vector_embedding_provider'); ?>"
                            class="<?php echo esc_attr(ltrim($aipkit_autogpt_context_autosave_class)); ?>"
                            hidden
                            aria-hidden="true"
                            tabindex="-1"
                        >
                            <?php foreach ($embedding_provider_options as $provider_key => $provider_label) : ?>
                                <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($provider_key, $default_embedding_provider_key); ?>>
                                    <?php echo esc_html($provider_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
    
                        <div class="aipkit_popover_option_row aipkit_popover_option_row--force-divider">
                            <div class="aipkit_popover_option_main">
                                <div class="aipkit_cw_settings_option_text">
                                    <label class="aipkit_popover_option_label" for="<?php echo esc_attr($aipkit_autogpt_context_embedding_model_id . '_trigger'); ?>">
                                        <?php esc_html_e('Embedding model', 'gpt3-ai-content-generator'); ?>
                                    </label>
                                    <span class="aipkit_popover_option_helper">
                                        <?php esc_html_e('Provider and model for embeddings.', 'gpt3-ai-content-generator'); ?>
                                    </span>
                                </div>
                                <select
                                    id="<?php echo esc_attr($aipkit_autogpt_context_embedding_model_id); ?>"
                                    name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'vector_embedding_model'); ?>"
                                    class="<?php echo esc_attr(ltrim($aipkit_autogpt_context_autosave_class)); ?>"
                                    data-aipkit-unified-model-source
                                    hidden
                                    aria-hidden="true"
                                    tabindex="-1"
                                    disabled
                                >
                                    <option value=""><?php esc_html_e('Select provider first', 'gpt3-ai-content-generator'); ?></option>
                                </select>
                                <?php
                                $aipkit_unified_model_selector_config = [
                                    'trigger_id' => $aipkit_autogpt_context_embedding_model_id . '_trigger',
                                    'source_id' => $aipkit_autogpt_context_embedding_model_id,
                                    'initial_label' => __('Select provider first', 'gpt3-ai-content-generator'),
                                    'class_name' => 'aipkit_autogpt_unified_model_selector aipkit_autogpt_unified_model_selector--compact',
                                    'capability' => 'embeddings',
                                    'search_placeholder' => __('Search embedding models...', 'gpt3-ai-content-generator'),
                                    'empty_text' => __('No embedding models found', 'gpt3-ai-content-generator'),
                                ];
                                include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                                ?>
                            </div>
                        </div>
                </div>
    
                <div class="aipkit_popover_option_row">
                        <div class="aipkit_popover_option_main">
                            <div class="aipkit_cw_settings_option_text">
                                <label class="aipkit_popover_option_label" for="<?php echo esc_attr($aipkit_autogpt_context_top_k_id); ?>">
                                    <?php esc_html_e('Results limit', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <span class="aipkit_popover_option_helper">
                                    <?php esc_html_e('How many matches to use.', 'gpt3-ai-content-generator'); ?>
                                </span>
                            </div>
                            <span class="aipkit_cw_kb_number_control aipkit_autogpt_result_limit_control">
                                <button
                                    type="button"
                                    data-aipkit-result-limit-step="-1"
                                    aria-label="<?php esc_attr_e('Use fewer results', 'gpt3-ai-content-generator'); ?>"
                                >−</button>
                                <input
                                    type="number"
                                    id="<?php echo esc_attr($aipkit_autogpt_context_top_k_id); ?>"
                                    name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'vector_store_top_k'); ?>"
                                    class="<?php echo esc_attr('aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--compact' . $aipkit_autogpt_context_autosave_class); ?>"
                                    data-aipkit-result-limit-input
                                    value="<?php echo esc_attr($aipkit_autogpt_context_top_k_default); ?>"
                                    min="1"
                                    max="20"
                                    step="1"
                                >
                                <button
                                    type="button"
                                    data-aipkit-result-limit-step="1"
                                    aria-label="<?php esc_attr_e('Use more results', 'gpt3-ai-content-generator'); ?>"
                                >+</button>
                            </span>
                        </div>
                </div>
    
                <?php if ($aipkit_autogpt_context_show_confidence) : ?>
                    <div class="aipkit_popover_option_row">
                            <div class="aipkit_popover_option_main">
                                <div class="aipkit_cw_settings_option_text">
                                    <label class="aipkit_popover_option_label" for="<?php echo esc_attr($aipkit_autogpt_context_confidence_id); ?>">
                                        <?php esc_html_e('Confidence threshold', 'gpt3-ai-content-generator'); ?>
                                    </label>
                                    <span class="aipkit_popover_option_helper">
                                        <?php esc_html_e('Minimum confidence to include.', 'gpt3-ai-content-generator'); ?>
                                    </span>
                                </div>
                                <div class="aipkit_autogpt_confidence_control">
                                    <div class="aipkit_autogpt_confidence_header" aria-hidden="true">
                                        <span><?php esc_html_e('Broader', 'gpt3-ai-content-generator'); ?></span>
                                        <output
                                            id="<?php echo esc_attr($aipkit_autogpt_context_confidence_id . '_value'); ?>"
                                            class="aipkit_autogpt_confidence_value"
                                            for="<?php echo esc_attr($aipkit_autogpt_context_confidence_id); ?>"
                                            data-aipkit-confidence-value
                                        ><?php echo esc_html($aipkit_autogpt_context_confidence_default); ?>%</output>
                                        <span><?php esc_html_e('Stricter', 'gpt3-ai-content-generator'); ?></span>
                                    </div>
                                    <input
                                        type="range"
                                        id="<?php echo esc_attr($aipkit_autogpt_context_confidence_id); ?>"
                                        name="<?php echo esc_attr($aipkit_autogpt_context_name_prefix . 'vector_store_confidence_threshold'); ?>"
                                        class="<?php echo esc_attr('aipkit_form-input aipkit_autogpt_confidence_slider' . $aipkit_autogpt_context_autosave_class); ?>"
                                        value="<?php echo esc_attr($aipkit_autogpt_context_confidence_default); ?>"
                                        min="0"
                                        max="100"
                                        step="1"
                                        data-aipkit-confidence-slider
                                        data-recommended-value="<?php echo esc_attr($aipkit_autogpt_context_confidence_default); ?>"
                                    >
                                    <p
                                        class="aipkit_autogpt_confidence_warning"
                                        data-aipkit-confidence-warning
                                        role="status"
                                        aria-live="polite"
                                        hidden
                                    ></p>
                                </div>
                            </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
}
