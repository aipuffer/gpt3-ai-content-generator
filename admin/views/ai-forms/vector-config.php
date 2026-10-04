<?php

/**
 * Partial: AI Form Editor - Vector & Context Configuration
 * Contains settings for enabling and configuring vector store context.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
// Variables passed from parent (index.php -> form-editor.php -> this):
// $openai_vector_stores, $google_file_search_stores, $pinecone_indexes, $qdrant_collections, $chroma_collections
$vector_embedding_provider = '';
$vector_embedding_model = '';
$embedding_provider_options = \WPAICG\AIPKit_Providers::get_embedding_provider_map('ai_forms_editor_ui');
$embedding_models_by_provider = \WPAICG\AIPKit_Providers::get_embedding_models_by_provider('ai_forms_editor_ui');
$aipkit_embedding_options_allowed_html = [
    'optgroup' => [
        'label' => true,
    ],
    'option' => [
        'value' => true,
        'data-provider' => true,
        'selected' => true,
        'hidden' => true,
        'disabled' => true,
    ],
];
if ($vector_embedding_provider === '' || !isset($embedding_provider_options[$vector_embedding_provider])) {
    $vector_embedding_provider = array_key_first($embedding_provider_options) ?: 'openai';
}
?>
<div class="aipkit_ai_form_knowledge_base_settings_list">
    <input
        type="checkbox"
        id="aipkit_ai_form_enable_vector_store"
        name="enable_vector_store"
        class="aipkit_vector_store_enable_select"
        value="1"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >
    <div class="aipkit_vector_store_settings_conditional_row" style="display:none;">
        <div class="aipkit_ai_form_knowledge_base_settings_row">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_vector_store_provider">
                        <?php esc_html_e('Vector provider', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Context source.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select
                    id="aipkit_ai_form_vector_store_provider"
                    name="vector_store_provider"
                    class="aipkit_ai_form_knowledge_base_settings_control aipkit_vector_store_provider_select"
                >
                    <option value="local"><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                    <option value="openai">OpenAI</option>
                    <option value="pinecone">Pinecone</option>
                    <option value="qdrant">Qdrant</option>
                    <option value="chroma">Chroma</option>
                    <option value="google"><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_openai_field" style="display:none;">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_openai_vector_store_ids">
                        <?php esc_html_e('Vector stores', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Select up to two stores.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <div
                    class="aipkit_popover_multiselect"
                    data-aipkit-vector-stores-dropdown
                    data-aipkit-dropdown-width="trigger"
                    data-aipkit-dropdown-align="start"
                    data-placeholder="<?php echo esc_attr__('Select stores', 'gpt3-ai-content-generator'); ?>"
                    data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                >
                    <button
                        type="button"
                        class="aipkit_popover_multiselect_btn"
                        aria-expanded="false"
                        aria-controls="aipkit_ai_form_openai_vector_store_panel"
                    >
                        <span class="aipkit_popover_multiselect_label">
                            <?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?>
                        </span>
                    </button>
                    <div
                        id="aipkit_ai_form_openai_vector_store_panel"
                        class="aipkit_popover_multiselect_panel"
                        role="menu"
                        hidden
                    >
                        <div class="aipkit_popover_multiselect_options"></div>
                    </div>
                </div>
                <select
                    id="aipkit_ai_form_openai_vector_store_ids"
                    name="openai_vector_store_ids[]"
                    class="aipkit_popover_multiselect_select"
                    multiple
                    size="3"
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <?php
                    if (!empty($openai_vector_stores)) {
                        $store_index = 1;
                        foreach ($openai_vector_stores as $store) {
                            $store_id_val = $store['id'] ?? '';
                            if ($store_id_val === '') {
                                continue;
                            }
                            $store_name = $store['name'] ?? '';
                            if ($store_name === '') {
                                $store_name = sprintf(
                                    /* translators: %d is the vector store index. */
                                    __('Untitled store %d', 'gpt3-ai-content-generator'),
                                    $store_index
                                );
                            }
                            $file_count_total = $store['file_counts']['total'] ?? null;
                            $file_count_display = ($file_count_total !== null)
                                ? " ({$file_count_total} " . _n('File', 'Files', (int) $file_count_total, 'gpt3-ai-content-generator') . ")"
                                : ' (Files: N/A)';
                            echo '<option value="' . esc_attr($store_id_val) . '">' . esc_html($store_name . $file_count_display) . '</option>';
                            $store_index++;
                        }
                    } else {
                        echo '<option value="" disabled>' . esc_html__('-- No Vector Stores Found --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_google_field" style="display:none;">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_google_file_search_store_names">
                        <?php esc_html_e('Stores', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Requires Google as the form provider.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <div
                    class="aipkit_popover_multiselect"
                    data-aipkit-vector-stores-dropdown
                    data-aipkit-dropdown-width="trigger"
                    data-aipkit-dropdown-align="start"
                    data-placeholder="<?php echo esc_attr__('Select stores', 'gpt3-ai-content-generator'); ?>"
                    data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                >
                    <button type="button" class="aipkit_popover_multiselect_btn" aria-expanded="false" aria-controls="aipkit_ai_form_google_file_search_store_panel">
                        <span class="aipkit_popover_multiselect_label"><?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <div id="aipkit_ai_form_google_file_search_store_panel" class="aipkit_popover_multiselect_panel" role="menu" hidden>
                        <div class="aipkit_popover_multiselect_options"></div>
                    </div>
                </div>
                <select
                    id="aipkit_ai_form_google_file_search_store_names"
                    name="google_file_search_store_names[]"
                    class="aipkit_popover_multiselect_select"
                    multiple
                    size="3"
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <?php foreach ($google_file_search_stores as $store): ?>
                        <?php
                        $google_store_name = (string) ($store['resource_name'] ?? $store['id'] ?? '');
                        $google_store_label = (string) ($store['display_name'] ?? $store['name'] ?? $google_store_name);
                        if ($google_store_name === '') {
                            continue;
                        }
                        ?>
                        <option value="<?php echo esc_attr($google_store_name); ?>"><?php echo esc_html($google_store_label); ?></option>
                    <?php endforeach; ?>
                    <?php if (empty($google_file_search_stores)): ?>
                        <option value="" disabled><?php esc_html_e('-- No Stores Found --', 'gpt3-ai-content-generator'); ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_pinecone_field" style="display:none;">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_pinecone_index_name">
                        <?php esc_html_e('Index', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Pinecone index to search.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select
                    id="aipkit_ai_form_pinecone_index_name"
                    name="pinecone_index_name"
                    class="aipkit_ai_form_knowledge_base_settings_control"
                >
                    <option value=""><?php esc_html_e('-- Select Index --', 'gpt3-ai-content-generator'); ?></option>
                    <?php if (!empty($pinecone_indexes)): ?>
                        <?php foreach ($pinecone_indexes as $index): ?>
                            <?php $index_name = is_array($index) ? ($index['name'] ?? '') : (string) $index; ?>
                            <?php if ($index_name !== ''): ?>
                                <option value="<?php echo esc_attr($index_name); ?>"><?php echo esc_html($index_name); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled><?php esc_html_e('-- No Indexes Found --', 'gpt3-ai-content-generator'); ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_local_field" style="display:none;">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_local_store_id">
                        <?php esc_html_e('Knowledge base', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Knowledge base to search.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select
                    id="aipkit_ai_form_local_store_id"
                    name="local_store_id"
                    class="aipkit_ai_form_knowledge_base_settings_control"
                    data-aipkit-local-store-select
                >
                    <?php
                    if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class)) {
                        require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
                    }
                    $local_stores = \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::stores();
                    if ($local_stores) {
                        echo '<option value="">' . esc_html__('-- Select knowledge base --', 'gpt3-ai-content-generator') . '</option>';
                        foreach ($local_stores as $local_store) {
                            echo '<option value="' . esc_attr($local_store['id']) . '">' . esc_html($local_store['name']) . '</option>';
                        }
                    } else {
                        echo '<option value="" disabled>' . esc_html__('-- No knowledge bases yet --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_qdrant_field" style="display:none;">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_qdrant_collection_name">
                        <?php esc_html_e('Collection', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Collection to search.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select
                    id="aipkit_ai_form_qdrant_collection_name"
                    name="qdrant_collection_name"
                    class="aipkit_ai_form_knowledge_base_settings_control"
                >
                    <option value=""><?php esc_html_e('-- Select Collection --', 'gpt3-ai-content-generator'); ?></option>
                    <?php
                    if (!empty($qdrant_collections)) {
                        foreach ($qdrant_collections as $collection) {
                            $collection_name = is_array($collection) ? ($collection['name'] ?? '') : (string) $collection;
                            if ($collection_name === '') {
                                continue;
                            }
                            echo '<option value="' . esc_attr($collection_name) . '">' . esc_html($collection_name) . '</option>';
                        }
                    } else {
                        echo '<option value="" disabled>' . esc_html__('-- No Collections Found --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_chroma_field" style="display:none;">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_chroma_collection_name">
                        <?php esc_html_e('Collection', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Collection to search.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select
                    id="aipkit_ai_form_chroma_collection_name"
                    name="chroma_collection_name"
                    class="aipkit_ai_form_knowledge_base_settings_control"
                >
                    <option value=""><?php esc_html_e('-- Select Collection --', 'gpt3-ai-content-generator'); ?></option>
                    <?php
                    if (!empty($chroma_collections)) {
                        foreach ($chroma_collections as $collection) {
                            $collection_name = is_array($collection)
                                ? ($collection['name'] ?? ($collection['collection_name'] ?? ($collection['id'] ?? '')))
                                : (string) $collection;
                            if ($collection_name === '') {
                                continue;
                            }
                            echo '<option value="' . esc_attr($collection_name) . '">' . esc_html($collection_name) . '</option>';
                        }
                    } else {
                        echo '<option value="" disabled>' . esc_html__('-- No Collections Found --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_embedding_config_row" style="display:none;">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_vector_embedding_select">
                        <?php esc_html_e('Embedding', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Model used for queries.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <select
                    id="aipkit_ai_form_vector_embedding_select"
                    class="aipkit_ai_form_knowledge_base_settings_control aipkit_vector_embedding_select"
                    data-aipkit-universal-model-combined="1" data-aipkit-universal-model-capability="embeddings"
                >
                    <?php
                    echo '<option value="">' . esc_html__('-- Select Embedding --', 'gpt3-ai-content-generator') . '</option>';
                    echo wp_kses(
                        \WPAICG\AIPKit_Providers::render_embedding_optgroup_options(
                            $embedding_provider_options,
                            $embedding_models_by_provider,
                            $vector_embedding_provider,
                            $vector_embedding_model,
                            [
                                'value_mode' => 'provider_model',
                                'include_manual_fallback' => true,
                            ]
                        ),
                        $aipkit_embedding_options_allowed_html
                    );
                    ?>
                </select>
                <select
                    id="aipkit_ai_form_vector_embedding_provider"
                    name="vector_embedding_provider"
                    class="aipkit_ai_form_knowledge_base_settings_control aipkit_vector_embedding_provider_select aipkit_hidden"
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <?php foreach ($embedding_provider_options as $provider_key => $provider_label): ?>
                        <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($vector_embedding_provider, $provider_key); ?>>
                            <?php echo esc_html($provider_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select
                    id="aipkit_ai_form_vector_embedding_model"
                    name="vector_embedding_model"
                    class="aipkit_ai_form_knowledge_base_settings_control aipkit_vector_embedding_model_select aipkit_hidden"
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <option value=""><?php esc_html_e('-- Select Model --', 'gpt3-ai-content-generator'); ?></option>
                    <?php
                    $current_embedding_list = isset($embedding_models_by_provider[$vector_embedding_provider]) && is_array($embedding_models_by_provider[$vector_embedding_provider])
                        ? $embedding_models_by_provider[$vector_embedding_provider]
                        : [];
                    if (!empty($current_embedding_list)) {
                        foreach ($current_embedding_list as $model) {
                            $model_id_val = $model['id'] ?? '';
                            $model_name_val = $model['name'] ?? $model_id_val;
                            echo '<option value="' . esc_attr($model_id_val) . '" ' . selected($vector_embedding_model, $model_id_val, false) . '>' . esc_html($model_name_val) . '</option>';
                        }
                    }
                    if (!empty($vector_embedding_model) && (empty($current_embedding_list) || !in_array($vector_embedding_model, array_column($current_embedding_list, 'id'), true))) {
                        echo '<option value="' . esc_attr($vector_embedding_model) . '" selected="selected">' . esc_html($vector_embedding_model) . '</option>';
                    }
                    if (empty($current_embedding_list) && empty($vector_embedding_model)) {
                        echo '<option value="" disabled>' . esc_html__('-- Select Provider --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_top_k_field">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_vector_store_top_k">
                        <?php esc_html_e('Limit', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Results to retrieve.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input
                    type="number"
                    id="aipkit_ai_form_vector_store_top_k"
                    name="vector_store_top_k"
                    class="aipkit_ai_form_knowledge_base_settings_control"
                    min="1"
                    max="20"
                    step="1"
                    value="3"
                    inputmode="numeric"
                />
            </div>
        </div>

        <div class="aipkit_ai_form_knowledge_base_settings_row aipkit_vector_store_confidence_field">
            <div class="aipkit_ai_form_knowledge_base_settings_row_main">
                <div class="aipkit_ai_form_knowledge_base_settings_copy">
                    <label class="aipkit_ai_form_knowledge_base_settings_label" for="aipkit_ai_form_vector_store_confidence_threshold">
                        <?php esc_html_e('Score threshold', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_knowledge_base_settings_helper">
                        <?php esc_html_e('Minimum similarity score.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <input
                    type="number"
                    id="aipkit_ai_form_vector_store_confidence_threshold"
                    name="vector_store_confidence_threshold"
                    class="aipkit_ai_form_knowledge_base_settings_control"
                    min="0"
                    max="100"
                    step="1"
                    value="20"
                    inputmode="numeric"
                />
            </div>
        </div>
    </div>
</div>
