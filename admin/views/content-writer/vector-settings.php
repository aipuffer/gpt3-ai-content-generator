<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$feature_defaults = \WPAICG\AIPKit_Providers::get_new_feature_defaults();
$default_embedding_provider_key = $feature_defaults['vector_embedding_provider'];
?>

<div class="aipkit_cw_vector_section">
    <input
        type="checkbox"
        id="aipkit_cw_enable_vector_store"
        name="enable_vector_store"
        class="aipkit_cw_vector_store_toggle aipkit_autosave_trigger"
        value="1"
        hidden
    >
    <input
        type="hidden"
        id="aipkit_cw_vector_store_provider_hidden"
        name="vector_store_provider"
        class="aipkit_cw_vector_store_provider_field aipkit_autosave_trigger"
        value="<?php echo esc_attr($feature_defaults['vector_store_provider']); ?>"
    >

    <div class="aipkit_cw_kb_row aipkit_cw_kb_row--mode">
        <div class="aipkit_cw_panel_label_wrap">
            <label class="aipkit_cw_panel_label" for="aipkit_cw_kb_mode_control">
                <?php esc_html_e('Context', 'gpt3-ai-content-generator'); ?>
            </label>
        </div>
        <div class="aipkit_cw_kb_control">
            <select
                id="aipkit_cw_kb_mode_control"
                class="aipkit_form-input aipkit_cw_kb_mode_control aipkit_cw_blended_chevron_select"
                data-aipkit-cw-fit-selected
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

    <div class="aipkit_cw_kb_row aipkit_cw_kb_row--source" id="aipkit_cw_kb_source_row" hidden>
        <label
            class="aipkit_cw_panel_label"
            id="aipkit_cw_kb_source_label"
            for="aipkit_cw_openai_vector_store_ids"
        >
            <?php esc_html_e('Source', 'gpt3-ai-content-generator'); ?>
        </label>
        <div class="aipkit_cw_kb_control aipkit_cw_kb_control--selection">
            <div class="aipkit_cw_kb_selection_inline">
                <div class="aipkit_cw_kb_source_fields">
                    <div class="aipkit_cw_vector_openai_field" hidden>
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
                                aria-controls="aipkit_cw_openai_vector_store_panel"
                            >
                                <span class="aipkit_popover_multiselect_label">
                                    <?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?>
                                </span>
                            </button>
                            <div
                                id="aipkit_cw_openai_vector_store_panel"
                                class="aipkit_popover_multiselect_panel"
                                role="menu"
                                hidden
                            >
                                <div class="aipkit_popover_multiselect_options"></div>
                            </div>
                        </div>
                        <select
                            id="aipkit_cw_openai_vector_store_ids"
                            name="openai_vector_store_ids[]"
                            class="aipkit_popover_multiselect_select aipkit_autosave_trigger"
                            multiple
                            size="3"
                            hidden
                            aria-hidden="true"
                            tabindex="-1"
                        >
                            <?php if (!empty($openai_vector_stores)): ?>
                                <?php foreach ($openai_vector_stores as $store): ?>
                                    <option value="<?php echo esc_attr($store['id']); ?>">
                                        <?php echo esc_html($store['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>
                                    <?php esc_html_e('No stores found', 'gpt3-ai-content-generator'); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="aipkit_cw_vector_google_field" hidden>
                        <div
                            class="aipkit_popover_multiselect aipkit_vector_multiselect"
                            data-aipkit-vector-stores-dropdown
                            data-placeholder="<?php echo esc_attr__('Select stores', 'gpt3-ai-content-generator'); ?>"
                            data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                        >
                            <button type="button" class="aipkit_popover_multiselect_btn aipkit_vector_multiselect_btn" aria-expanded="false" aria-controls="aipkit_cw_google_file_search_store_panel">
                                <span class="aipkit_popover_multiselect_label"><?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?></span>
                            </button>
                            <div id="aipkit_cw_google_file_search_store_panel" class="aipkit_popover_multiselect_panel" role="menu" hidden>
                                <div class="aipkit_popover_multiselect_options"></div>
                            </div>
                        </div>
                        <select
                            id="aipkit_cw_google_file_search_store_names"
                            name="google_file_search_store_names[]"
                            class="aipkit_popover_multiselect_select aipkit_autosave_trigger"
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
                                <option value="" disabled><?php esc_html_e('No stores found', 'gpt3-ai-content-generator'); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="aipkit_cw_vector_pinecone_field" hidden>
                        <select
                            id="aipkit_cw_pinecone_index_name"
                            name="pinecone_index_name"
                            class="aipkit_form-input aipkit_vector_settings_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                            data-aipkit-cw-fit-selected
                            aria-label="<?php esc_attr_e('Pinecone index', 'gpt3-ai-content-generator'); ?>"
                        >
                            <option value=""><?php esc_html_e('Select index', 'gpt3-ai-content-generator'); ?></option>
                            <?php if (!empty($pinecone_indexes)): ?>
                                <?php foreach ($pinecone_indexes as $index): ?>
                                    <option value="<?php echo esc_attr($index['name']); ?>">
                                        <?php echo esc_html($index['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>
                                    <?php esc_html_e('No indexes found', 'gpt3-ai-content-generator'); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="aipkit_cw_vector_qdrant_field" hidden>
                        <select
                            id="aipkit_cw_qdrant_collection_name"
                            name="qdrant_collection_name"
                            class="aipkit_form-input aipkit_vector_settings_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                            data-aipkit-cw-fit-selected
                            aria-label="<?php esc_attr_e('Qdrant collection', 'gpt3-ai-content-generator'); ?>"
                        >
                            <option value=""><?php esc_html_e('Select', 'gpt3-ai-content-generator'); ?></option>
                            <?php if (!empty($qdrant_collections)): ?>
                                <?php foreach ($qdrant_collections as $collection): ?>
                                    <option value="<?php echo esc_attr($collection['name']); ?>">
                                        <?php echo esc_html($collection['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>
                                    <?php esc_html_e('No collections found', 'gpt3-ai-content-generator'); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="aipkit_cw_vector_local_field" hidden>
                        <select
                            id="aipkit_cw_local_store_id"
                            name="local_store_id"
                            class="aipkit_form-input aipkit_vector_settings_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                            data-aipkit-cw-fit-selected
                            data-aipkit-local-store-select
                            aria-label="<?php esc_attr_e('site knowledge base', 'gpt3-ai-content-generator'); ?>"
                        >
                            <?php
                            if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class)) {
                                require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
                            }
                            $local_stores = \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::stores();
                            ?>
                            <?php if ($local_stores): ?>
                                <option value=""><?php esc_html_e('Select', 'gpt3-ai-content-generator'); ?></option>
                                <?php foreach ($local_stores as $local_store): ?>
                                    <option value="<?php echo esc_attr($local_store['id']); ?>"><?php echo esc_html($local_store['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled><?php esc_html_e('No knowledge bases yet', 'gpt3-ai-content-generator'); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="aipkit_cw_vector_chroma_field" hidden>
                        <select
                            id="aipkit_cw_chroma_collection_name"
                            name="chroma_collection_name"
                            class="aipkit_form-input aipkit_vector_settings_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                            data-aipkit-cw-fit-selected
                            aria-label="<?php esc_attr_e('Chroma collection', 'gpt3-ai-content-generator'); ?>"
                        >
                            <option value=""><?php esc_html_e('Select', 'gpt3-ai-content-generator'); ?></option>
                            <?php if (!empty($chroma_collections)): ?>
                                <?php foreach ($chroma_collections as $collection): ?>
                                    <?php
                                    $collection_name = is_array($collection)
                                        ? ($collection['name'] ?? ($collection['collection_name'] ?? ($collection['id'] ?? '')))
                                        : (string) $collection;
                                    if ($collection_name === '') {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($collection_name); ?>">
                                        <?php echo esc_html($collection_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>
                                    <?php esc_html_e('No collections found', 'gpt3-ai-content-generator'); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <?php include __DIR__ . '/knowledge-base-display-settings.php'; ?>
            </div>
        </div>
    </div>
</div>
