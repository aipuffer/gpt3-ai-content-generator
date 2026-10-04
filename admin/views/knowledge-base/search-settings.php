<?php
/**
 * Partial: Knowledge Base Semantic Search
 *
 * Renders the public semantic-search configuration directly in the Knowledge
 * Base workspace. Search is a feature in its own right, so it intentionally
 * lives outside the module's Settings sub-tabs.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables only.

$kb_search_options = get_option('aipkit_options', []);
$kb_search_options = is_array($kb_search_options) ? $kb_search_options : [];
$kb_search_settings = isset($kb_search_options['semantic_search']) && is_array($kb_search_options['semantic_search'])
    ? $kb_search_options['semantic_search']
    : [];

$kb_search_vector_provider = sanitize_key((string) ($kb_search_settings['vector_provider'] ?? 'pinecone'));
if (!in_array($kb_search_vector_provider, ['local', 'pinecone', 'qdrant', 'chroma'], true)) {
    $kb_search_vector_provider = 'pinecone';
}

$kb_search_target_id = (string) ($kb_search_settings['target_id'] ?? '');
$kb_search_embedding_provider = (string) ($kb_search_settings['embedding_provider'] ?? 'openai');
$kb_search_embedding_model = (string) ($kb_search_settings['embedding_model'] ?? '');
$kb_search_num_results = max(1, min(20, (int) ($kb_search_settings['num_results'] ?? 5)));
$kb_search_no_results_text = (string) ($kb_search_settings['no_results_text'] ?? __('No results found.', 'gpt3-ai-content-generator'));

$kb_search_pinecone_indexes = is_array($pinecone_index_list ?? null) ? $pinecone_index_list : [];
$kb_search_qdrant_collections = is_array($qdrant_collection_list ?? null) ? $qdrant_collection_list : [];
$kb_search_chroma_collections = is_array($chroma_collection_list ?? null) ? $chroma_collection_list : [];
// site knowledge bases: [{id, name}]; each searches with the embedding model it was created with.
$kb_search_local_stores = [];
if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class) && file_exists(WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php')) {
    require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
}
if (class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class)) {
    foreach ((new \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy())->list_indexes() as $kb_search_local_store) {
        $kb_search_local_stores[] = ['id' => $kb_search_local_store['id'], 'name' => $kb_search_local_store['name']];
    }
}
$kb_search_embedding_providers = [];
$kb_search_embedding_models = [];

if (class_exists('\\WPAICG\\AIPKit_Providers')) {
    if (empty($kb_search_pinecone_indexes)) {
        $kb_search_pinecone_indexes = \WPAICG\AIPKit_Providers::get_pinecone_indexes();
    }
    if (empty($kb_search_qdrant_collections)) {
        $kb_search_qdrant_collections = \WPAICG\AIPKit_Providers::get_qdrant_collections();
    }
    if (empty($kb_search_chroma_collections)) {
        $kb_search_chroma_collections = \WPAICG\AIPKit_Providers::get_chroma_collections();
    }
    $kb_search_embedding_providers = \WPAICG\AIPKit_Providers::get_embedding_provider_map('knowledge_base_settings_ui');
    $kb_search_embedding_models = \WPAICG\AIPKit_Providers::get_embedding_models_by_provider('knowledge_base_settings_ui');
}

if (!isset($kb_search_embedding_providers[$kb_search_embedding_provider])) {
    $kb_search_embedding_provider = array_key_first($kb_search_embedding_providers) ?: 'openai';
}

$kb_search_embedding_allowed_html = [
    'optgroup' => ['label' => true],
    'option' => [
        'value' => true,
        'data-provider' => true,
        'selected' => true,
        'hidden' => true,
        'disabled' => true,
    ],
];

$kb_search_current_targets = [];
if ($kb_search_vector_provider === 'local') {
    $kb_search_current_targets = $kb_search_local_stores;
} elseif ($kb_search_vector_provider === 'pinecone') {
    $kb_search_current_targets = $kb_search_pinecone_indexes;
} elseif ($kb_search_vector_provider === 'qdrant') {
    $kb_search_current_targets = $kb_search_qdrant_collections;
} else {
    $kb_search_current_targets = $kb_search_chroma_collections;
}

$kb_search_target_label = in_array($kb_search_vector_provider, ['qdrant', 'chroma'], true)
    ? __('Collection', 'gpt3-ai-content-generator')
    : ($kb_search_vector_provider === 'local' ? __('Knowledge base', 'gpt3-ai-content-generator') : __('Index', 'gpt3-ai-content-generator'));
?>
<div
    class="aipkit_settings_kb_search_page"
    id="aipkit_semantic_search_page"
    data-semantic-pinecone-indexes="<?php echo esc_attr(wp_json_encode($kb_search_pinecone_indexes ?: [])); ?>"
    data-semantic-qdrant-collections="<?php echo esc_attr(wp_json_encode($kb_search_qdrant_collections ?: [])); ?>"
    data-semantic-chroma-collections="<?php echo esc_attr(wp_json_encode($kb_search_chroma_collections ?: [])); ?>"
    data-semantic-local-stores="<?php echo esc_attr(wp_json_encode($kb_search_local_stores)); ?>"
>
    <section class="aipkit_settings_kb_search_card" aria-labelledby="aipkit_semantic_search_heading">
        <div class="aipkit_settings_kb_section_intro">
            <h2 id="aipkit_semantic_search_heading"><?php esc_html_e('Semantic search', 'gpt3-ai-content-generator'); ?></h2>
            <p><?php esc_html_e('Let visitors search your indexed content directly on your site.', 'gpt3-ai-content-generator'); ?></p>
        </div>

        <div class="aipkit_settings_kb_rows aipkit_settings_kb_rows--search">
            <div class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_kb_field">
                <label class="aipkit_form-label" for="aipkit_semantic_search_vector_provider">
                    <span><?php esc_html_e('Vector DB', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_form-label-helper"><?php esc_html_e('Search storage provider.', 'gpt3-ai-content-generator'); ?></span>
                </label>
                <select id="aipkit_semantic_search_vector_provider" name="semantic_search_vector_provider" class="aipkit_form-input aipkit_autosave_trigger">
                    <option value="local" <?php selected($kb_search_vector_provider, 'local'); ?>><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                    <option value="pinecone" <?php selected($kb_search_vector_provider, 'pinecone'); ?>><?php esc_html_e('Pinecone', 'gpt3-ai-content-generator'); ?></option>
                    <option value="qdrant" <?php selected($kb_search_vector_provider, 'qdrant'); ?>><?php esc_html_e('Qdrant', 'gpt3-ai-content-generator'); ?></option>
                    <option value="chroma" <?php selected($kb_search_vector_provider, 'chroma'); ?>><?php esc_html_e('Chroma', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>

            <div class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_kb_field">
                <label class="aipkit_form-label" id="aipkit_semantic_search_target_label" for="aipkit_semantic_search_target_id">
                    <span data-aipkit-semantic-target-label-text><?php echo esc_html($kb_search_target_label); ?></span>
                    <span class="aipkit_form-label-helper" data-aipkit-semantic-target-helper><?php echo esc_html($kb_search_vector_provider === 'local' ? __('Knowledge base to search.', 'gpt3-ai-content-generator') : __('Target index or collection.', 'gpt3-ai-content-generator')); ?></span>
                </label>
                <select id="aipkit_semantic_search_target_id" name="semantic_search_target_id" class="aipkit_form-input aipkit_autosave_trigger">
                    <option value=""><?php esc_html_e('Select a destination', 'gpt3-ai-content-generator'); ?></option>
                    <?php
                    $kb_search_target_found = false;
                    foreach ($kb_search_current_targets as $kb_search_item) {
                        $kb_search_item_name = is_array($kb_search_item)
                            ? ($kb_search_item['name'] ?? ($kb_search_item['id'] ?? ''))
                            : $kb_search_item;
                        if (empty($kb_search_item_name)) {
                            continue;
                        }
                        // site knowledge bases are saved by id and shown by name.
                        $kb_search_item_value = $kb_search_vector_provider === 'local' && is_array($kb_search_item) ? (string) $kb_search_item['id'] : $kb_search_item_name;
                        $kb_search_selected = selected($kb_search_target_id, $kb_search_item_value, false);
                        if ($kb_search_selected) {
                            $kb_search_target_found = true;
                        }
                        echo '<option value="' . esc_attr($kb_search_item_value) . '" ' . $kb_search_selected . '>' . esc_html($kb_search_item_name) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe fixed attribute.
                    }
                    if (!$kb_search_target_found && $kb_search_target_id !== '') {
                        echo '<option value="' . esc_attr($kb_search_target_id) . '" selected>' . esc_html($kb_search_target_id) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_kb_field" id="aipkit_semantic_search_embedding_row">
                <label class="aipkit_form-label" for="aipkit_semantic_search_embedding_model">
                    <span><?php esc_html_e('Embedding model', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_form-label-helper"><?php esc_html_e('Model used to search the index.', 'gpt3-ai-content-generator'); ?></span>
                </label>
                <select id="aipkit_semantic_search_embedding_model" name="semantic_search_embedding_model" class="aipkit_form-input aipkit_autosave_trigger" data-aipkit-universal-model-combined="1" data-aipkit-universal-model-capability="embeddings">
                    <?php
                    if (class_exists('\\WPAICG\\AIPKit_Providers')) {
                        echo wp_kses(
                            \WPAICG\AIPKit_Providers::render_embedding_optgroup_options(
                                $kb_search_embedding_providers,
                                $kb_search_embedding_models,
                                $kb_search_embedding_provider,
                                $kb_search_embedding_model,
                                [
                                    'value_mode' => 'model',
                                    'include_manual_fallback' => true,
                                ]
                            ),
                            $kb_search_embedding_allowed_html
                        );
                    }
                    ?>
                </select>
                <input type="hidden" id="aipkit_semantic_search_embedding_provider" name="semantic_search_embedding_provider" value="<?php echo esc_attr($kb_search_embedding_provider); ?>" class="aipkit_autosave_trigger">
            </div>

            <div class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_kb_field">
                <label class="aipkit_form-label" for="aipkit_semantic_search_num_results">
                    <span><?php esc_html_e('Number of results', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_form-label-helper"><?php esc_html_e('Maximum results returned.', 'gpt3-ai-content-generator'); ?></span>
                </label>
                <input type="number" id="aipkit_semantic_search_num_results" name="semantic_search_num_results" class="aipkit_form-input aipkit_autosave_trigger" value="<?php echo esc_attr($kb_search_num_results); ?>" min="1" max="20">
            </div>

            <div class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_kb_field">
                <label class="aipkit_form-label" for="aipkit_semantic_search_no_results_text">
                    <span><?php esc_html_e('No results text', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_form-label-helper"><?php esc_html_e('Message shown for empty searches.', 'gpt3-ai-content-generator'); ?></span>
                </label>
                <input type="text" id="aipkit_semantic_search_no_results_text" name="semantic_search_no_results_text" class="aipkit_form-input aipkit_autosave_trigger" value="<?php echo esc_attr($kb_search_no_results_text); ?>">
            </div>

            <div class="aipkit_form-group aipkit_settings_simple_row aipkit_settings_kb_field aipkit_settings_kb_shortcode_row">
                <span class="aipkit_form-label">
                    <span><?php esc_html_e('Shortcode', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_form-label-helper"><?php esc_html_e('Click to copy and embed the search form.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <button
                    type="button"
                    id="aipkit_semantic_search_shortcode_display"
                    class="aipkit_semantic_shortcode_snippet"
                    data-shortcode="[aipkit_semantic_search]"
                    aria-label="<?php esc_attr_e('Copy semantic search shortcode', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                    <span class="aipkit_semantic_shortcode_text">[aipkit_semantic_search]</span>
                    <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </section>
</div>
