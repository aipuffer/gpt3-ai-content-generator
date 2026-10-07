<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bot_id = $initial_active_bot_id;
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
?>
<div
    class="aipkit_popover_options_list aipkit_context_layout"
    data-vector-provider="<?php echo esc_attr(($enable_vector_store === '1') ? $vector_store_provider : ''); ?>"
>
    <?php // Shown directly: whether answers use knowledge, where it is stored and how it is searched. Rows a storage doesn't use stay hidden. ?>
    <section class="aipkit_answer_style_group" aria-labelledby="aipkit_search_group_where">
        <h4 class="aipkit_answer_style_group_title" id="aipkit_search_group_where"><?php esc_html_e('Where', 'gpt3-ai-content-generator'); ?></h4>
        <div class="aipkit_answer_style_row aipkit_answer_style_row--switch">
            <label class="aipkit_answer_style_switch_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_vector_store_popover">
                <span class="aipkit_answer_style_copy">
                    <span class="aipkit_answer_style_title"><?php esc_html_e('Answer from knowledge', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_answer_style_hint"><?php esc_html_e('When off, it answers from the AI model only.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_switch">
                    <input
                        type="checkbox"
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_vector_store_popover"
                        name="enable_vector_store"
                        class="aipkit_vector_store_enable_select aipkit_vector_store_toggle_switch"
                        value="1"
                        <?php checked($enable_vector_store, '1'); ?>
                    />
                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                </span>
            </label>
        </div>
        <div
            class="aipkit_vector_store_settings_conditional_row aipkit_context_grid"
            data-vector-provider="<?php echo esc_attr(($enable_vector_store === '1') ? $vector_store_provider : ''); ?>"
            style="<?php echo ($enable_vector_store === '1') ? '' : 'display:none;'; ?>"
        >
            <div class="aipkit_answer_style_row aipkit_search_storage_row">
                <div class="aipkit_answer_style_copy">
                    <span class="aipkit_answer_style_title"><?php esc_html_e("Where it's stored", 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_answer_style_hint"><?php esc_html_e('The knowledge base your sources go into.', 'gpt3-ai-content-generator'); ?></span>
                </div>
                <?php
                $aipkit_knowledge_picker_config = ['id' => 'aipkit_bot_' . $bot_id . '_knowledge_picker'];
                include WPAICG_PLUGIN_DIR . 'admin/views/shared/knowledge-picker.php';
                unset($aipkit_knowledge_picker_config);
                ?>
            </div>
            <?php // The picker reads and writes these fields; saving and provider rules stay with them. ?>
            <div class="aipkit_knowledge_saved_fields" hidden>
        <div class="aipkit_popover_option_row aipkit_vector_store_provider_field">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_vector_store_provider_modal"
                >
                    <?php esc_html_e('Knowledge storage', 'gpt3-ai-content-generator'); ?>
                </label>
                <select
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_vector_store_provider_modal"
                    name="vector_store_provider"
                    class="aipkit_popover_option_select aipkit_vector_store_provider_select"
                >
                    <option value="local" <?php selected($vector_store_provider, 'local'); ?>><?php esc_html_e('Local', 'gpt3-ai-content-generator'); ?></option>
                    <option value="openai" <?php selected($vector_store_provider, 'openai'); ?>>OpenAI</option>
                    <option value="pinecone" <?php selected($vector_store_provider, 'pinecone'); ?>>Pinecone</option>
                    <option value="qdrant" <?php selected($vector_store_provider, 'qdrant'); ?>>Qdrant</option>
                    <option value="chroma" <?php selected($vector_store_provider, 'chroma'); ?>>Chroma</option>
                    <option value="google" <?php selected($vector_store_provider, 'google'); ?>><?php esc_html_e('Google', 'gpt3-ai-content-generator'); ?></option>
                    <option value="claude_files" <?php selected($vector_store_provider, 'claude_files'); ?>><?php esc_html_e('Anthropic Files', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>

        <div class="aipkit_popover_option_row aipkit_vector_store_openai_field" style="<?php echo ($enable_vector_store === '1' && $vector_store_provider === 'openai') ? '' : 'display:none;'; ?>">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openai_vector_store_ids_modal"

                >
                    <?php esc_html_e('Stores (max 2)', 'gpt3-ai-content-generator'); ?>
                </label>
                <div
                    class="aipkit_popover_multiselect"
                    data-aipkit-vector-stores-dropdown
                    data-placeholder="<?php echo esc_attr__('Select stores', 'gpt3-ai-content-generator'); ?>"
                    data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                >
                    <button
                        type="button"
                        class="aipkit_popover_multiselect_btn"
                        aria-expanded="false"
                        aria-controls="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openai_vector_store_panel"
                    >
                        <span class="aipkit_popover_multiselect_label">
                            <?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?>
                        </span>
                    </button>
                    <div
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openai_vector_store_panel"
                        class="aipkit_popover_multiselect_panel"
                        role="menu"
                        hidden
                    >
                        <div class="aipkit_popover_multiselect_options"></div>
                    </div>
                </div>
                <select
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openai_vector_store_ids_modal"
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
                            $store_name = $store['name'] ?? '';
                            if ($store_name === '') {
                                $store_name = sprintf(
                                    /* translators: %d is the vector store index. */
                                    __('Untitled store %d', 'gpt3-ai-content-generator'),
                                    $store_index
                                );
                            }
                            $file_count_total = $store['file_counts']['total'] ?? null;
                            $file_count_display = ($file_count_total !== null) ? " ({$file_count_total} " . _n('File', 'Files', (int) $file_count_total, 'gpt3-ai-content-generator') . ")" : ' (Files: N/A)';
                            $option_text = $store_name . $file_count_display;
                            echo '<option value="' . esc_attr($store_id_val) . '"' . selected(in_array($store_id_val, $openai_vector_store_ids_saved, true), true, false) . '>' . esc_html($option_text) . '</option>';
                            $store_index++;
                        }
                    }
                    $manual_index = 1;
                    foreach ($openai_vector_store_ids_saved as $saved_id) {
                        $found_in_list = false;
                        if (!empty($openai_vector_stores)) {
                            foreach ($openai_vector_stores as $store) {
                                if (($store['id'] ?? '') === $saved_id) { $found_in_list = true; break; }
                            }
                        }
                        if (!$found_in_list) {
                            $manual_label = $saved_id !== ''
                                ? $saved_id . ' ' . __('(missing)', 'gpt3-ai-content-generator')
                                : sprintf(
                                    /* translators: %d is the saved vector store index. */
                                    __('Store %d', 'gpt3-ai-content-generator'),
                                    $manual_index
                                );
                            echo '<option value="' . esc_attr($saved_id) . '" selected disabled="disabled" data-aipkit-preserved-selection="1">' . esc_html($manual_label) . '</option>';
                            $manual_index++;
                        }
                    }
                    if (empty($openai_vector_stores) && empty($openai_vector_store_ids_saved)) {
                        echo '<option value="" disabled>' . esc_html__('-- No Vector Stores Found --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_popover_option_row aipkit_vector_store_google_field" style="<?php echo ($enable_vector_store === '1' && $vector_store_provider === 'google') ? '' : 'display:none;'; ?>">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_google_file_search_store_names_modal"
                >
                    <?php esc_html_e('Stores', 'gpt3-ai-content-generator'); ?>
                </label>
                <div
                    class="aipkit_popover_multiselect"
                    data-aipkit-google-file-search-stores-dropdown
                    data-placeholder="<?php echo esc_attr__('Select stores', 'gpt3-ai-content-generator'); ?>"
                    data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                >
                    <button
                        type="button"
                        class="aipkit_popover_multiselect_btn"
                        aria-expanded="false"
                        aria-controls="aipkit_bot_<?php echo esc_attr($bot_id); ?>_google_file_search_store_panel"
                    >
                        <span class="aipkit_popover_multiselect_label"><?php esc_html_e('Select stores', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <div
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_google_file_search_store_panel"
                        class="aipkit_popover_multiselect_panel"
                        role="menu"
                        hidden
                    >
                        <div class="aipkit_popover_multiselect_options"></div>
                    </div>
                </div>
                <select
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_google_file_search_store_names_modal"
                    name="google_file_search_store_names[]"
                    class="aipkit_popover_multiselect_select"
                    multiple
                    size="3"
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <?php
                    $known_google_store_names = [];
                    foreach ($google_file_search_stores as $store) {
                        $store_resource_name = (string) ($store['resource_name'] ?? $store['id'] ?? '');
                        if ($store_resource_name === '') {
                            continue;
                        }
                        $known_google_store_names[] = $store_resource_name;
                        $store_label = (string) ($store['display_name'] ?? $store['name'] ?? $store_resource_name);
                        echo '<option value="' . esc_attr($store_resource_name) . '"' . selected(in_array($store_resource_name, $google_file_search_store_names_saved, true), true, false) . '>' . esc_html($store_label) . '</option>';
                    }
                    foreach ($google_file_search_store_names_saved as $saved_store_name) {
                        if ($saved_store_name !== '' && !in_array($saved_store_name, $known_google_store_names, true)) {
                            echo '<option value="' . esc_attr($saved_store_name) . '" selected disabled data-aipkit-preserved-selection="1">' . esc_html($saved_store_name . ' ' . __('(missing)', 'gpt3-ai-content-generator')) . '</option>';
                        }
                    }
                    if (empty($google_file_search_stores) && empty($google_file_search_store_names_saved)) {
                        echo '<option value="" disabled>' . esc_html__('-- No Stores Found --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_popover_option_row aipkit_vector_store_pinecone_field" style="<?php echo ($enable_vector_store === '1' && $vector_store_provider === 'pinecone') ? '' : 'display:none;'; ?>">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_pinecone_index_dropdown_btn"
                >
                    <?php esc_html_e('Index', 'gpt3-ai-content-generator'); ?>
                </label>
                <?php
                $pinecone_dropdown_placeholder = __('Select index', 'gpt3-ai-content-generator');
                $pinecone_dropdown_label = $pinecone_dropdown_placeholder;
                $pinecone_option_rows = [];

                if (!empty($pinecone_indexes)) {
                    foreach ($pinecone_indexes as $index) {
                        $index_name = is_array($index) ? ($index['name'] ?? '') : (string) $index;
                        if ($index_name === '') {
                            continue;
                        }
                        $pinecone_option_rows[] = [
                            'value' => $index_name,
                            'label' => $index_name,
                            'disabled' => false,
                        ];
                        if ($pinecone_index_name === $index_name) {
                            $pinecone_dropdown_label = $index_name;
                        }
                    }
                }

                if (!empty($pinecone_index_name)) {
                    $known_pinecone_names = [];
                    foreach ($pinecone_option_rows as $pinecone_option_row) {
                        $known_pinecone_names[] = isset($pinecone_option_row['value'])
                            ? (string) $pinecone_option_row['value']
                            : '';
                    }
                    if (!in_array((string) $pinecone_index_name, $known_pinecone_names, true)) {
                        $manual_label = (string) $pinecone_index_name . ' ' . __('(missing)', 'gpt3-ai-content-generator');
                        $pinecone_dropdown_label = $manual_label;
                        $pinecone_option_rows[] = [
                            'value' => $pinecone_index_name,
                            'label' => $manual_label,
                            'disabled' => true,
                            'preserved' => true,
                        ];
                    }
                }

                if (empty($pinecone_option_rows) && empty($pinecone_index_name)) {
                    $pinecone_option_rows[] = [
                        'value' => '',
                        'label' => __('-- No Indexes Found --', 'gpt3-ai-content-generator'),
                        'disabled' => true,
                    ];
                }
                ?>
                <div class="aipkit_popover_inline_controls">
                    <div
                        class="aipkit_popover_multiselect aipkit_vector_store_pinecone_dropdown"
                        data-aipkit-pinecone-index-dropdown
                        data-placeholder="<?php echo esc_attr($pinecone_dropdown_placeholder); ?>"
                    >
                        <button
                            type="button"
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_pinecone_index_dropdown_btn"
                            class="aipkit_popover_multiselect_btn"
                            aria-expanded="false"
                            aria-controls="aipkit_bot_<?php echo esc_attr($bot_id); ?>_pinecone_index_dropdown_panel"
                        >
                            <span class="aipkit_popover_multiselect_label">
                                <?php echo esc_html($pinecone_dropdown_label); ?>
                            </span>
                        </button>
                        <div
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_pinecone_index_dropdown_panel"
                            class="aipkit_popover_multiselect_panel aipkit_vector_store_pinecone_panel"
                            role="menu"
                            hidden
                        >
                            <div class="aipkit_popover_multiselect_options aipkit_vector_store_pinecone_options">
                                <?php foreach ($pinecone_option_rows as $option_row) : ?>
                                    <?php
                                    $option_value = isset($option_row['value']) ? (string) $option_row['value'] : '';
                                    $option_label = isset($option_row['label']) ? (string) $option_row['label'] : '';
                                    $option_disabled = !empty($option_row['disabled']);
                                    $option_preserved = !empty($option_row['preserved']);
                                    $option_checked = (
                                        (!$option_disabled || $option_preserved) &&
                                        $option_value !== '' &&
                                        (string) $pinecone_index_name === $option_value
                                    );
                                    ?>
                                    <label class="aipkit_popover_multiselect_item aipkit_vector_store_pinecone_item<?php echo $option_preserved ? ' aipkit_popover_multiselect_item--preserved' : ''; ?>">
                                        <span class="aipkit_vector_store_pinecone_item_label">
                                            <input
                                                type="<?php echo $option_preserved ? 'checkbox' : 'radio'; ?>"
                                                class="aipkit_vector_store_pinecone_radio"
                                                name="aipkit_pinecone_index_choice_<?php echo esc_attr($bot_id); ?>"
                                                value="<?php echo esc_attr($option_value); ?>"
                                                <?php checked($option_checked, true); ?>
                                                <?php disabled($option_disabled && !$option_preserved); ?>
                                                <?php if ($option_preserved) : ?> data-aipkit-preserved-selection="1"<?php endif; ?>
                                            />
                                            <?php if ($option_preserved) : ?>
                                                <span class="aipkit_popover_multiselect_copy">
                                                    <span class="aipkit_popover_multiselect_text"><?php echo esc_html($option_label); ?></span>
                                                    <span class="aipkit_popover_multiselect_hint"><?php esc_html_e('Unavailable. Uncheck to remove.', 'gpt3-ai-content-generator'); ?></span>
                                                </span>
                                            <?php else : ?>
                                                <span class="aipkit_popover_multiselect_text"><?php echo esc_html($option_label); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <select
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_pinecone_index_name_modal"
                        name="pinecone_index_name"
                        class="aipkit_popover_option_select aipkit_vector_store_pinecone_hidden_select"
                        hidden
                        aria-hidden="true"
                        tabindex="-1"
                    >
                        <?php foreach ($pinecone_option_rows as $option_row) : ?>
                            <?php
                            $option_value = isset($option_row['value']) ? (string) $option_row['value'] : '';
                            $option_label = isset($option_row['label']) ? (string) $option_row['label'] : '';
                            $option_disabled = !empty($option_row['disabled']);
                            $option_preserved = !empty($option_row['preserved']);
                            $option_selected = (
                                (!$option_disabled || $option_preserved) &&
                                $option_value !== '' &&
                                (string) $pinecone_index_name === $option_value
                            );
                            ?>
                            <option
                                value="<?php echo esc_attr($option_value); ?>"
                                <?php selected($option_selected, true); ?>
                                <?php disabled($option_disabled); ?>
                                <?php if ($option_preserved) : ?> data-aipkit-preserved-selection="1"<?php endif; ?>
                            >
                                <?php echo esc_html($option_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="aipkit_popover_option_row aipkit_vector_store_qdrant_field" style="<?php echo ($enable_vector_store === '1' && $vector_store_provider === 'qdrant') ? '' : 'display:none;'; ?>">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_qdrant_collection_names_modal"

                >
                    <?php esc_html_e('Collections', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_popover_option_actions">
                    <div
                        class="aipkit_popover_multiselect"
                        data-aipkit-qdrant-collections-dropdown
                        data-placeholder="<?php echo esc_attr__('Select collections', 'gpt3-ai-content-generator'); ?>"
                        data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                    >
                        <button
                            type="button"
                            class="aipkit_popover_multiselect_btn"
                            aria-expanded="false"
                            aria-controls="aipkit_bot_<?php echo esc_attr($bot_id); ?>_qdrant_collections_panel"
                        >
                            <span class="aipkit_popover_multiselect_label">
                                <?php esc_html_e('Select collections', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </button>
                        <div
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_qdrant_collections_panel"
                            class="aipkit_popover_multiselect_panel"
                            role="menu"
                            hidden
                        >
                            <div class="aipkit_popover_multiselect_options"></div>
                        </div>
                    </div>
                </div>
                <select
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_qdrant_collection_names_modal"
                    name="qdrant_collection_names[]"
                    class="aipkit_popover_multiselect_select"
                    multiple
                    size="3"
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <?php
                    if (!empty($qdrant_collections)) {
                        foreach ($qdrant_collections as $collection) {
                            $collection_name = is_array($collection) ? ($collection['name'] ?? '') : (string) $collection;
                            echo '<option value="' . esc_attr($collection_name) . '"' . selected(in_array($collection_name, $qdrant_collection_names, true), true, false) . '>' . esc_html($collection_name) . '</option>';
                        }
                    }
                    foreach ($qdrant_collection_names as $saved_name) {
                        if (!in_array($saved_name, array_map(function ($c) { return is_array($c) ? ($c['name'] ?? '') : (string) $c; }, $qdrant_collections), true)) {
                            echo '<option value="' . esc_attr($saved_name) . '" selected disabled="disabled" data-aipkit-preserved-selection="1">' . esc_html($saved_name . ' ' . __('(missing)', 'gpt3-ai-content-generator')) . '</option>';
                        }
                    }
                    if (empty($qdrant_collections) && empty($qdrant_collection_names)) {
                        echo '<option value="" disabled>' . esc_html__('-- No Collections Found --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_popover_option_row aipkit_vector_store_local_field" style="<?php echo ($enable_vector_store === '1' && $vector_store_provider === 'local') ? '' : 'display:none;'; ?>">
            <div class="aipkit_popover_option_main">
                <label class="aipkit_popover_option_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_local_store_ids_modal">
                    <?php esc_html_e('Knowledge bases', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_popover_option_actions">
                    <div
                        class="aipkit_popover_multiselect"
                        data-aipkit-local-stores-dropdown
                        data-placeholder="<?php echo esc_attr__('Select knowledge bases', 'gpt3-ai-content-generator'); ?>"
                        data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                    >
                        <button type="button" class="aipkit_popover_multiselect_btn" aria-expanded="false" aria-controls="aipkit_bot_<?php echo esc_attr($bot_id); ?>_local_stores_panel">
                            <span class="aipkit_popover_multiselect_label"><?php esc_html_e('Select knowledge bases', 'gpt3-ai-content-generator'); ?></span>
                        </button>
                        <div id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_local_stores_panel" class="aipkit_popover_multiselect_panel" role="menu" hidden>
                            <div class="aipkit_popover_multiselect_options"></div>
                        </div>
                    </div>
                </div>
                <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_local_store_ids_modal" name="local_store_ids[]" class="aipkit_popover_multiselect_select" multiple size="3" hidden aria-hidden="true" tabindex="-1">
                    <?php
                    $aipkit_local_ids = array_column($local_stores, 'id');
                    foreach ($local_stores as $aipkit_local_store) {
                        /* translators: 1: knowledge base name, 2: number of chunks. */
                        $aipkit_local_label = sprintf(__('%1$s (%2$s chunks)', 'gpt3-ai-content-generator'), $aipkit_local_store['name'], number_format_i18n((int) $aipkit_local_store['chunk_count']));
                        echo '<option value="' . esc_attr($aipkit_local_store['id']) . '"' . selected(in_array($aipkit_local_store['id'], $local_store_ids, true), true, false) . '>' . esc_html($aipkit_local_label) . '</option>';
                    }
                    foreach ($local_store_ids as $aipkit_saved_local) {
                        if (!in_array($aipkit_saved_local, $aipkit_local_ids, true)) {
                            echo '<option value="' . esc_attr($aipkit_saved_local) . '" selected disabled="disabled" data-aipkit-preserved-selection="1">' . esc_html($aipkit_saved_local . ' ' . __('(missing)', 'gpt3-ai-content-generator')) . '</option>';
                        }
                    }
                    if (!$local_stores && !$local_store_ids) {
                        echo '<option value="" disabled>' . esc_html__('-- No knowledge bases yet --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="aipkit_popover_option_row aipkit_vector_store_chroma_field" style="<?php echo ($enable_vector_store === '1' && $vector_store_provider === 'chroma') ? '' : 'display:none;'; ?>">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_chroma_collection_names_modal"

                >
                    <?php esc_html_e('Collections', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_popover_option_actions">
                    <div
                        class="aipkit_popover_multiselect"
                        data-aipkit-chroma-collections-dropdown
                        data-placeholder="<?php echo esc_attr__('Select collections', 'gpt3-ai-content-generator'); ?>"
                        data-selected-label="<?php echo esc_attr__('selected', 'gpt3-ai-content-generator'); ?>"
                    >
                        <button
                            type="button"
                            class="aipkit_popover_multiselect_btn"
                            aria-expanded="false"
                            aria-controls="aipkit_bot_<?php echo esc_attr($bot_id); ?>_chroma_collections_panel"
                        >
                            <span class="aipkit_popover_multiselect_label">
                                <?php esc_html_e('Select collections', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </button>
                        <div
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_chroma_collections_panel"
                            class="aipkit_popover_multiselect_panel"
                            role="menu"
                            hidden
                        >
                            <div class="aipkit_popover_multiselect_options"></div>
                        </div>
                    </div>
                </div>
                <select
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_chroma_collection_names_modal"
                    name="chroma_collection_names[]"
                    class="aipkit_popover_multiselect_select"
                    multiple
                    size="3"
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <?php
                    if (!empty($chroma_collections)) {
                        foreach ($chroma_collections as $collection) {
                            $collection_name = is_array($collection) ? ($collection['name'] ?? ($collection['collection_name'] ?? ($collection['id'] ?? ''))) : (string) $collection;
                            if ($collection_name === '') {
                                continue;
                            }
                            echo '<option value="' . esc_attr($collection_name) . '"' . selected(in_array($collection_name, $chroma_collection_names, true), true, false) . '>' . esc_html($collection_name) . '</option>';
                        }
                    }
                    $known_chroma_collection_names = array_map(function ($collection) { return is_array($collection) ? ($collection['name'] ?? ($collection['collection_name'] ?? ($collection['id'] ?? ''))) : (string) $collection; }, $chroma_collections);
                    foreach ($chroma_collection_names as $saved_name) {
                        if (!in_array($saved_name, $known_chroma_collection_names, true)) {
                            echo '<option value="' . esc_attr($saved_name) . '" selected disabled="disabled" data-aipkit-preserved-selection="1">' . esc_html($saved_name . ' ' . __('(missing)', 'gpt3-ai-content-generator')) . '</option>';
                        }
                    }
                    if (empty($chroma_collections) && empty($chroma_collection_names)) {
                        echo '<option value="" disabled>' . esc_html__('-- No Collections Found --', 'gpt3-ai-content-generator') . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>
            </div>
        </div>
    </section>

    <section class="aipkit_answer_style_group aipkit_vector_store_advanced_field" aria-labelledby="aipkit_search_group_how" style="<?php echo ($enable_vector_store === '1' && in_array($vector_store_provider, ['openai', 'google', 'pinecone', 'qdrant', 'chroma', 'local'], true)) ? '' : 'display:none;'; ?>">
        <h4 class="aipkit_answer_style_group_title" id="aipkit_search_group_how"><?php esc_html_e('How it searches', 'gpt3-ai-content-generator'); ?></h4>
        <?php
        $aipkit_search_presets = [
            'top_k' => [
                'class' => 'aipkit_vector_store_top_k_field',
                'name' => 'vector_store_top_k',
                'value' => $vector_store_top_k,
                'title' => __('How much it reads', 'gpt3-ai-content-generator'),
                'hint' => __('More gives fuller answers but uses more credits.', 'gpt3-ai-content-generator'),
                'field_label' => __('Results', 'gpt3-ai-content-generator'),
                'field_hint' => __('Matches to read, 1 to 20', 'gpt3-ai-content-generator'),
                'attributes' => ['min' => '1', 'max' => '20', 'step' => '1'],
                'options' => [
                    '3' => __('A little', 'gpt3-ai-content-generator'),
                    '6' => __('More', 'gpt3-ai-content-generator'),
                    '10' => __('A lot', 'gpt3-ai-content-generator'),
                ],
            ],
            'confidence_threshold' => [
                'class' => 'aipkit_vector_store_confidence_field',
                'name' => 'vector_store_confidence_threshold',
                'value' => $vector_store_confidence_threshold,
                'title' => __('How close a match', 'gpt3-ai-content-generator'),
                'hint' => __("Strict skips anything that doesn't clearly fit.", 'gpt3-ai-content-generator'),
                'field_label' => __('Minimum match', 'gpt3-ai-content-generator'),
                'field_hint' => __('Minimum match, 0 to 100', 'gpt3-ai-content-generator'),
                'attributes' => ['min' => '0', 'max' => '100', 'step' => '1'],
                'options' => [
                    '10' => __('Loose', 'gpt3-ai-content-generator'),
                    '20' => __('Normal', 'gpt3-ai-content-generator'),
                    '35' => __('Strict', 'gpt3-ai-content-generator'),
                ],
            ],
        ];
        foreach ($aipkit_search_presets as $aipkit_search_key => $aipkit_search_preset) :
            $aipkit_search_field_id = 'aipkit_bot_' . $bot_id . '_vector_store_' . $aipkit_search_key . '_modal';
            $aipkit_search_label_id = $aipkit_search_field_id . '_label';
            ?>
            <div class="aipkit_answer_style_row aipkit_answer_style_row--inline aipkit_popover_option_row <?php echo esc_attr($aipkit_search_preset['class']); ?>">
                <div class="aipkit_answer_style_copy">
                    <span class="aipkit_answer_style_title" id="<?php echo esc_attr($aipkit_search_label_id); ?>"><?php echo esc_html($aipkit_search_preset['title']); ?></span>
                    <span class="aipkit_answer_style_hint"><?php echo esc_html($aipkit_search_preset['hint']); ?></span>
                </div>
                <div class="aipkit_answer_style_control">
                    <div class="aipkit_segmented" role="group" aria-labelledby="<?php echo esc_attr($aipkit_search_label_id); ?>" data-aipkit-segmented-for="<?php echo esc_attr($aipkit_search_field_id); ?>">
                        <?php foreach ($aipkit_search_preset['options'] as $aipkit_search_value => $aipkit_search_option) : ?>
                            <button type="button" class="aipkit_segmented_option" data-value="<?php echo esc_attr((string) $aipkit_search_value); ?>" aria-pressed="false"><?php echo esc_html($aipkit_search_option); ?></button>
                        <?php endforeach; ?>
                        <button type="button" class="aipkit_segmented_option" data-custom aria-pressed="false"><?php esc_html_e('Custom', 'gpt3-ai-content-generator'); ?></button>
                    </div>
                    <label class="aipkit_answer_style_custom" data-aipkit-segmented-custom-field hidden>
                        <span class="screen-reader-text"><?php echo esc_html($aipkit_search_preset['field_label']); ?></span>
                        <input
                            type="number"
                            id="<?php echo esc_attr($aipkit_search_field_id); ?>"
                            name="<?php echo esc_attr($aipkit_search_preset['name']); ?>"
                            class="aipkit_form-input aipkit_popover_option_input"
                            <?php foreach ($aipkit_search_preset['attributes'] as $aipkit_search_attr => $aipkit_search_attr_value) : ?>
                                <?php echo esc_attr($aipkit_search_attr); ?>="<?php echo esc_attr($aipkit_search_attr_value); ?>"
                            <?php endforeach; ?>
                            value="<?php echo esc_attr((string) $aipkit_search_preset['value']); ?>"
                        />
                        <span class="aipkit_answer_style_custom_hint" aria-hidden="true"><?php echo esc_html($aipkit_search_preset['field_hint']); ?></span>
                    </label>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="aipkit_vector_store_embedding_config_row" style="<?php echo ($enable_vector_store === '1' && in_array($vector_store_provider, ['pinecone', 'qdrant', 'chroma'], true)) ? '' : 'display:none;'; ?>">
            <div class="aipkit_answer_style_row aipkit_answer_style_row--inline aipkit_search_embedding_row">
                <div class="aipkit_answer_style_copy">
                    <label class="aipkit_answer_style_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_vector_embedding_select_modal"><?php esc_html_e('Embedding model', 'gpt3-ai-content-generator'); ?></label>
                    <span class="aipkit_answer_style_hint"><?php esc_html_e("Keep the one your sources were added with. Another one can't find them until you add them again.", 'gpt3-ai-content-generator'); ?></span>
                </div>
                <div class="aipkit_answer_style_control aipkit_popover_inline_controls">
                    <select
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_vector_embedding_select_modal"
                        class="aipkit_popover_option_select aipkit_vector_embedding_select"
                        data-aipkit-universal-model-combined="1" data-aipkit-universal-model-capability="embeddings"
                    >
                        <?php
                        echo '<option value="" hidden ' . selected($vector_embedding_model, '', false) . '></option>';
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
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_vector_embedding_provider_modal"
                        name="vector_embedding_provider"
                        class="aipkit_popover_option_select aipkit_vector_embedding_provider_select aipkit_hidden"
                        aria-hidden="true"
                        tabindex="-1"
                    >
                        <?php if (!isset($embedding_provider_options[$vector_embedding_provider])): ?>
                            <option value="<?php echo esc_attr($vector_embedding_provider); ?>" selected><?php esc_html_e('Select a provider', 'gpt3-ai-content-generator'); ?></option>
                        <?php endif; ?>
                        <?php foreach ($embedding_provider_options as $provider_key => $provider_label): ?>
                            <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($vector_embedding_provider, $provider_key); ?>>
                                <?php echo esc_html($provider_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_vector_embedding_model_modal"
                        name="vector_embedding_model"
                        class="aipkit_popover_option_select aipkit_vector_embedding_model_select aipkit_hidden"
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
        </div>
    </section>

    <?php // Not part of knowledge: answers can also read the page the visitor is on, with knowledge on or off. ?>
    <section class="aipkit_answer_style_group" aria-labelledby="aipkit_search_group_page">
        <h4 class="aipkit_answer_style_group_title" id="aipkit_search_group_page"><?php esc_html_e('The page', 'gpt3-ai-content-generator'); ?></h4>
        <div class="aipkit_answer_style_row aipkit_answer_style_row--switch">
            <label class="aipkit_answer_style_switch_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_content_aware_enabled_popover">
                <span class="aipkit_answer_style_copy">
                    <span class="aipkit_answer_style_title"><?php esc_html_e("Use the page they're on", 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_answer_style_hint"><?php esc_html_e('Answers can use the page the visitor is looking at.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_switch">
                    <input
                        type="checkbox"
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_content_aware_enabled_popover"
                        name="content_aware_enabled"
                        class="aipkit_content_aware_enable_select"
                        value="1"
                        <?php checked($content_aware_enabled, '1'); ?>
                    />
                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                </span>
            </label>
        </div>
    </section>
    <div class="aipkit_popover_option_row aipkit_context_vector_notice_row">
        <?php
        $aipkit_notice_id = 'aipkit_vector_provider_notice_chatbot_' . (string) $bot_id;
        $aipkit_notice_class = 'aipkit_vector_provider_notice_chatbot';
        $aipkit_notice_context = __('use this knowledge storage', 'gpt3-ai-content-generator');
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/provider-key-notice.php';
        ?>
    </div>
</div>
