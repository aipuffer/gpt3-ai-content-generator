/**
 * AIPKit Content Assistant - bulk modal template.
 */
(function () {
  "use strict";

  function aipkit_enhancer_getBulkModalHtml(data) {
    const {
      postCount,
      providerOptions,
      promptItems,
      postType,
    } = data;

    const escapeHtml = (value) =>
      String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
    const escapeAttr = (value) =>
      escapeHtml(value).replace(/"/g, "&quot;").replace(/'/g, "&#39;");

    const buildPromptLibraryOptions = (options = [], defaultPrompt = "") =>
      `<option value="${escapeAttr(defaultPrompt)}">Default</option>` +
      options
        .map((option) => {
          if (!option?.label || !option?.prompt) return "";
          return `<option value="${escapeAttr(option.prompt)}">${escapeHtml(
            option.label
          )}</option>`;
        })
        .join("");

    const buildPlaceholderCodes = (tokens = []) =>
      tokens
        .map(
          (token) =>
            `<code class="aipkit-placeholder" role="button" tabindex="0" aria-label="Copy ${escapeAttr(
              token
            )}" title="Click to copy">${escapeHtml(token)}</code>`
        )
        .join(" ");

    const buildPromptPlaceholderFooter = (item) => {
      const baseTokens = Array.isArray(item?.placeholders)
        ? item.placeholders
        : [];
      const extraTokens =
        postType === "product" && Array.isArray(item?.placeholders_extra)
          ? item.placeholders_extra
          : [];
      const extraLabel = item?.placeholders_extra_label
        ? ` ${escapeHtml(item.placeholders_extra_label)}`
        : "";

      return `
        <span
          class="aipkit_cw_prompt_editor_placeholders aipkit_bulk_prompt_placeholders"
          data-prompt-type="${escapeAttr(
            item?.placeholders_prompt_type || item?.key || ""
          )}"
          data-aipkit-hide-placeholder-label="true"
          data-copy-title="Click to copy"
        >
          ${buildPlaceholderCodes(baseTokens)}
          ${
            extraTokens.length
              ? `<span class="aipkit-product-placeholders">${extraLabel} ${buildPlaceholderCodes(
                  extraTokens
                )}</span>`
              : ""
          }
        </span>
      `;
    };

    const buildPromptRow = (item) => {
      const toggle = item?.toggle || {};
      const textarea = item?.textarea || {};
      const library = item?.library || {};
      const key = item?.key || "";
      const editorId = `aipkit_bulk_${key}_prompt_editor`;
      const label =
        {
          meta: "Meta description",
          keyword: "Focus keyword",
        }[key] || item?.label || "";

      return `
        <div class="aipkit_enhancer_update_item" data-prompt-key="${escapeAttr(
          key
        )}">
          <div class="aipkit_enhancer_update_row">
            <div class="aipkit_enhancer_update_copy">
              <span class="aipkit_enhancer_update_label">${escapeHtml(label)}</span>
              <span class="aipkit_enhancer_update_desc">${escapeHtml(
                item?.description || ""
              )}</span>
            </div>
            <div class="aipkit_enhancer_update_controls">
              <label class="aipkit_switch">
                <input
                  type="checkbox"
                  id="${escapeAttr(toggle.id || "")}"
                  name="${escapeAttr(toggle.name || "")}"
                  value="${escapeAttr(toggle.value || key)}"
                >
                <span class="aipkit_switch_slider"></span>
              </label>
              <button
                type="button"
                class="aipkit_enhancer_prompt_edit_btn"
                data-aipkit-inline-prompt-toggle="${escapeAttr(editorId)}"
                aria-controls="${escapeAttr(editorId)}"
                aria-expanded="false"
                title="Customize instructions"
              >
                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                <span class="screen-reader-text">Customize ${escapeHtml(label)} instructions</span>
              </button>
            </div>
          </div>
          <div
            class="aipkit_enhancer_prompt_inline"
            id="${escapeAttr(editorId)}"
            hidden
          >
            <div class="aipkit_cw_prompt_editor">
              <div class="aipkit_cw_prompt_editor_toolbar">
                <span class="aipkit_cw_prompt_editor_title">${escapeHtml(
                  item?.flyout_title || `${label} instructions`
                )}</span>
                <select
                  id="${escapeAttr(library?.select_id || "")}"
                  class="aipkit_cw_prompt_template_select aipkit_cw_prompt_library_select"
                  data-aipkit-prompt-target="${escapeAttr(textarea?.id || "")}"
                  data-aipkit-prompt-library-label="Prompt library"
                  title="Load prompt"
                >
                  ${buildPromptLibraryOptions(
                    Array.isArray(library?.options) ? library.options : [],
                    library?.default_prompt || textarea?.value || ""
                  )}
                </select>
              </div>
              <textarea
                id="${escapeAttr(textarea?.id || "")}"
                class="aipkit_cw_prompt_editor_textarea"
                rows="${escapeAttr(Math.min(Number(textarea?.rows || 6), 7))}"
              >${escapeHtml(textarea?.value || "")}</textarea>
              <div class="aipkit_cw_prompt_editor_footer">
                ${buildPromptPlaceholderFooter(item)}
              </div>
            </div>
          </div>
        </div>
      `;
    };

    const promptRowsHtml = (Array.isArray(promptItems) ? promptItems : [])
      .map(buildPromptRow)
      .join("");

    return `
      <div class="aipkit-modal-overlay aipkit-enhancer-bulk-modal-overlay">
        <div
          id="aipkit_enhancer_bulk_modal"
          class="aipkit-modal-content aipkit-enhancer-bulk-modal-content aipkit_enhancer_bulk_modal"
          data-post-type="${escapeAttr(postType)}"
          data-post-count="${escapeAttr(postCount)}"
        >
          <div class="aipkit-modal-header aipkit_enhancer_modal_header">
            <h2 class="aipkit-modal-title">Content Assistant</h2>
            <button
              type="button"
              class="aipkit-modal-close-btn aipkit_enhancer_close_btn"
              aria-label="Close"
            >
              <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
          </div>

          <div
            id="aipkit_enhancer_persistence_feedback"
            class="aipkit_enhancer_persistence_feedback"
            aria-live="polite"
            aria-hidden="true"
            hidden
          >
            <div class="aipkit_enhancer_persistence_panel">
              <span
                class="aipkit_enhancer_persistence_spinner"
                aria-hidden="true"
              ></span>
              <span
                class="dashicons dashicons-warning aipkit_enhancer_persistence_error_icon"
                aria-hidden="true"
              ></span>
              <span class="aipkit_enhancer_persistence_message">Saving…</span>
              <button
                type="button"
                class="aipkit_enhancer_persistence_retry"
                hidden
              >
                Retry
              </button>
            </div>
          </div>

          <div class="aipkit-modal-body">
            <div id="aipkit-enhancer-bulk-config">
              <section class="aipkit_enhancer_config_section">
                <div class="aipkit_enhancer_primary_fields">
                  <div class="aipkit_enhancer_primary_field">
                    <label class="aipkit_enhancer_field_label" for="aipkit_enhancer_template_picker_btn">Template</label>
                    <div
                      class="aipkit_popover_multiselect aipkit_enhancer_template_dropdown"
                      id="aipkit_enhancer_template_picker_popover"
                    >
                        <button
                          type="button"
                          id="aipkit_enhancer_template_picker_btn"
                          class="aipkit_enhancer_select_button"
                          aria-controls="aipkit_enhancer_template_picker_popover_panel"
                          aria-expanded="false"
                        >
                          <span id="aipkit_enhancer_template_picker_label">Select a template</span>
                        </button>
                        <div
                          id="aipkit_enhancer_template_picker_popover_panel"
                          class="aipkit_enhancer_template_dropdown_panel"
                          role="dialog"
                          aria-label="Choose a template"
                          hidden
                        >
                          <div
                            class="aipkit_cw_template_picker_list"
                            id="aipkit_enhancer_template_picker_list"
                            role="listbox"
                            aria-label="Template list"
                          >
                            <div class="aipkit_cw_template_picker_empty">Loading templates…</div>
                          </div>
                          <div class="aipkit_cw_template_picker_footer">
                            <button
                              type="button"
                              id="aipkit_enhancer_new_template_btn"
                              class="aipkit_enhancer_text_button"
                            >
                              <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                              New template
                            </button>
                            <div
                              id="aipkit_enhancer_template_new_form"
                              class="aipkit_cw_template_row_form"
                              hidden
                            >
                              <label
                                class="aipkit_enhancer_template_form_label"
                                for="aipkit_enhancer_template_new_name"
                              >Template name</label>
                              <input
                                type="text"
                                id="aipkit_enhancer_template_new_name"
                                class="aipkit_form-input"
                                placeholder="Template name"
                                autocomplete="off"
                              >
                              <div class="aipkit_enhancer_template_form_actions">
                                <button
                                  type="button"
                                  id="aipkit_enhancer_template_new_cancel"
                                  class="aipkit_enhancer_template_form_button aipkit_enhancer_template_form_button--secondary"
                                >Cancel</button>
                                <button
                                  type="button"
                                  id="aipkit_enhancer_template_new_save"
                                  class="aipkit_enhancer_template_form_button aipkit_enhancer_template_form_button--primary"
                                >Save</button>
                              </div>
                            </div>
                          </div>
                        </div>
                        <select
                          id="aipkit_enhancer_template_select"
                          name="enhancer_template_id"
                          class="screen-reader-text"
                          tabindex="-1"
                          aria-hidden="true"
                        >
                          <option value="">Select a template</option>
                        </select>
                    </div>
                  </div>

                  <div class="aipkit_enhancer_primary_field">
                    <div class="aipkit_enhancer_model_control">
                      <div class="aipkit_enhancer_model_label_row">
                        <label
                          class="aipkit_enhancer_field_label"
                          for="aipkit_bulk_ai_model_trigger"
                        >Model</label>
                        <button
                          type="button"
                          id="aipkit_enhancer_advanced_settings_toggle"
                          class="aipkit_enhancer_advanced_settings_toggle"
                          aria-controls="aipkit_enhancer_advanced_settings"
                          aria-expanded="false"
                        >
                          <span>Advanced</span>
                          <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                      </div>
                      <div
                        class="aipkit_unified_model_selector aipkit_enhancer_unified_model_selector"
                        data-aipkit-unified-model-selector
                        data-aipkit-unified-model-source-id="aipkit_bulk_ai_selection"
                        data-aipkit-model-capability="text_generation"
                      >
                        <button
                          type="button"
                          id="aipkit_bulk_ai_model_trigger"
                          class="aipkit_unified_model_trigger"
                          aria-expanded="false"
                          aria-controls="aipkit_bulk_ai_model_trigger_popover"
                          data-aipkit-unified-model-trigger
                        >
                          <span
                            class="aipkit_unified_model_logo"
                            data-aipkit-unified-model-logo
                            aria-hidden="true"
                          ></span>
                          <span
                            class="aipkit_unified_model_name"
                            data-aipkit-unified-model-name
                          >Select model</span>
                        </button>
                        <div
                          id="aipkit_bulk_ai_model_trigger_popover"
                          class="aipkit_unified_model_popover"
                          data-aipkit-unified-model-popover
                          hidden
                        >
                          <div class="aipkit_unified_model_search">
                            <input
                              type="search"
                              class="aipkit_unified_model_search_input"
                              placeholder="Search models..."
                              aria-label="Search models"
                              data-aipkit-unified-model-search
                            >
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                          </div>
                          <div
                            class="aipkit_unified_model_list"
                            role="listbox"
                            data-aipkit-unified-model-list
                          ></div>
                          <div
                            class="aipkit_unified_model_empty"
                            data-aipkit-unified-model-empty
                            hidden
                          >No models found</div>
                        </div>
                      </div>
                      <select
                        id="aipkit_bulk_ai_selection"
                        class="screen-reader-text"
                        tabindex="-1"
                        aria-hidden="true"
                      >
                        <option value="">Select model</option>
                      </select>
                      <div class="aipkit_enhancer_model_state_controls" hidden>
                        <select id="aipkit_bulk_ai_provider">${providerOptions}</select>
                        <select id="aipkit_bulk_ai_model">
                          <option value="">Select a provider</option>
                        </select>
                      </div>
                    </div>
                  </div>
                </div>
                <div
                  id="aipkit_enhancer_advanced_settings"
                  class="aipkit_enhancer_advanced_settings"
                  hidden
                >
                  <div class="aipkit_enhancer_advanced_grid">
                      <div class="aipkit_enhancer_advanced_field">
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <label for="aipkit_bulk_ai_temperature">Temperature</label>
                          <span>Controls output variety.</span>
                        </div>
                        <input
                          type="number"
                          id="aipkit_bulk_ai_temperature"
                          name="ai_temperature"
                          min="0"
                          max="2"
                          step="0.1"
                          value="1"
                          inputmode="decimal"
                        >
                      </div>
                      <div class="aipkit_enhancer_advanced_field">
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <label for="aipkit_bulk_ai_top_p">Top P</label>
                          <span>Controls sampling diversity.</span>
                        </div>
                        <input
                          type="number"
                          id="aipkit_bulk_ai_top_p"
                          name="ai_top_p"
                          min="0"
                          max="1"
                          step="0.1"
                          value="1"
                          inputmode="decimal"
                        >
                      </div>
                      <div class="aipkit_enhancer_advanced_field">
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <label for="aipkit_bulk_content_max_tokens">Content length</label>
                          <span>Target length for generated content.</span>
                        </div>
                        <select id="aipkit_bulk_content_max_tokens" name="content_max_tokens">
                          <option value="1">Short</option>
                          <option value="2" selected>Medium</option>
                          <option value="3">Long</option>
                        </select>
                      </div>
                      <div class="aipkit_enhancer_advanced_field aipkit_enhancer_reasoning_effort_field">
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <label for="aipkit_bulk_reasoning_effort">Reasoning</label>
                          <span>More effort for hard tasks.</span>
                        </div>
                        <select id="aipkit_bulk_reasoning_effort" name="reasoning_effort">
                          <option value="none">None</option>
                          <option value="low">Low</option>
                          <option value="medium">Medium</option>
                          <option value="high">High</option>
                          <option value="xhigh">Extra high</option>
                          <option value="max">Max</option>
                        </select>
                      </div>
                  </div>

                  <section class="aipkit_enhancer_knowledge_section">
                    <div class="aipkit_enhancer_knowledge_toggle_row">
                      <label
                        class="aipkit_enhancer_knowledge_toggle_copy"
                        for="aipkit_bulk_enable_vector_store"
                      >
                        <span>Use knowledge base context</span>
                        <span>Ground output in your indexed content.</span>
                      </label>
                      <div class="aipkit_enhancer_knowledge_toggle_controls">
                        <label class="aipkit_switch">
                          <input
                            type="checkbox"
                            id="aipkit_bulk_enable_vector_store"
                            name="enable_vector_store"
                            value="1"
                            class="aipkit_toggle_switch"
                          >
                          <span class="aipkit_switch_slider"></span>
                        </label>
                      </div>
                    </div>

                    <div class="aipkit_cw_vector_store_settings_container" hidden>
                      <div
                        class="aipkit_enhancer_advanced_field aipkit_enhancer_knowledge_provider_control"
                        hidden
                      >
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <label for="aipkit_bulk_vector_store_provider">Provider</label>
                          <span>Choose the retrieval provider.</span>
                        </div>
                        <select
                          id="aipkit_bulk_vector_store_provider"
                          name="vector_store_provider"
                          aria-label="Knowledge base provider"
                        >
                          <option value="off" selected hidden>Off</option>
                          <option value="local">Local</option>
                          <option value="openai">OpenAI</option>
                          <option value="google">Google</option>
                          <option value="pinecone">Pinecone</option>
                          <option value="qdrant">Qdrant</option>
                          <option value="chroma">Chroma</option>
                        </select>
                      </div>

                      <div class="aipkit_enhancer_advanced_field aipkit_enhancer_knowledge_source_field">
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <span class="aipkit_enhancer_advanced_label">Source</span>
                          <span>Choose the retrieval source.</span>
                        </div>
                        <div class="aipkit_enhancer_kb_source_controls">
                          <div class="aipkit_cw_vector_openai_field" hidden>
                            <div
                              class="aipkit_popover_multiselect aipkit_enhancer_vector_multiselect"
                              data-aipkit-vector-stores-dropdown
                              data-placeholder="Select stores"
                              data-selected-label="selected"
                            >
                              <button
                                type="button"
                                class="aipkit_enhancer_select_button aipkit_enhancer_vector_multiselect_btn"
                                aria-expanded="false"
                                aria-controls="aipkit_bulk_openai_vector_store_panel"
                              >
                                <span class="aipkit_popover_multiselect_label">Select stores</span>
                              </button>
                              <div
                                id="aipkit_bulk_openai_vector_store_panel"
                                class="aipkit_popover_multiselect_panel aipkit_enhancer_vector_multiselect_panel"
                                role="menu"
                                hidden
                              >
                                <div class="aipkit_popover_multiselect_options"></div>
                              </div>
                            </div>
                            <select
                              id="aipkit_bulk_openai_vector_store_ids"
                              name="openai_vector_store_ids[]"
                              class="aipkit_popover_multiselect_select"
                              multiple
                              hidden
                              aria-hidden="true"
                              tabindex="-1"
                            ></select>
                          </div>
                          <div class="aipkit_cw_vector_google_field" hidden>
                            <div
                              class="aipkit_popover_multiselect aipkit_enhancer_vector_multiselect"
                              data-aipkit-vector-stores-dropdown
                              data-placeholder="Select stores"
                              data-selected-label="selected"
                            >
                              <button
                                type="button"
                                class="aipkit_enhancer_select_button aipkit_enhancer_vector_multiselect_btn"
                                aria-expanded="false"
                                aria-controls="aipkit_bulk_google_file_search_store_panel"
                              >
                                <span class="aipkit_popover_multiselect_label">Select stores</span>
                              </button>
                              <div
                                id="aipkit_bulk_google_file_search_store_panel"
                                class="aipkit_popover_multiselect_panel aipkit_enhancer_vector_multiselect_panel"
                                role="menu"
                                hidden
                              >
                                <div class="aipkit_popover_multiselect_options"></div>
                              </div>
                            </div>
                            <select
                              id="aipkit_bulk_google_file_search_store_names"
                              name="google_file_search_store_names[]"
                              class="aipkit_popover_multiselect_select"
                              multiple
                              hidden
                              aria-hidden="true"
                              tabindex="-1"
                            ></select>
                          </div>
                          <div class="aipkit_cw_vector_pinecone_field" hidden>
                            <select id="aipkit_bulk_pinecone_index_name" name="pinecone_index_name" aria-label="Pinecone index"></select>
                          </div>
                          <div class="aipkit_cw_vector_qdrant_field" hidden>
                            <select id="aipkit_bulk_qdrant_collection_name" name="qdrant_collection_name" aria-label="Qdrant collection"></select>
                          </div>
                          <div class="aipkit_cw_vector_local_field" hidden>
                            <select id="aipkit_bulk_local_store_id" name="local_store_id" aria-label="site knowledge base"></select>
                          </div>
                          <div class="aipkit_cw_vector_chroma_field" hidden>
                            <select id="aipkit_bulk_chroma_collection_name" name="chroma_collection_name" aria-label="Chroma collection"></select>
                          </div>
                        </div>
                      </div>

                      <div class="aipkit_enhancer_advanced_field">
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <label for="aipkit_bulk_vector_store_top_k">Results limit</label>
                          <span>How many matches to use.</span>
                        </div>
                        <input
                          type="number"
                          id="aipkit_bulk_vector_store_top_k"
                          name="vector_store_top_k"
                          value="3"
                          min="1"
                          max="20"
                          step="1"
                        >
                      </div>

                      <div class="aipkit_cw_vector_embedding_config_row aipkit_enhancer_advanced_field" hidden>
                        <div class="aipkit_enhancer_advanced_field_copy">
                          <label for="aipkit_bulk_vector_embedding_selection">Embedding model</label>
                          <span>Choose an embedding model.</span>
                        </div>
                        <select
                          id="aipkit_bulk_vector_embedding_selection"
                          class="aipkit_vector_embedding_select"
                          aria-label="Embedding model"
                        ></select>
                        <input
                          type="hidden"
                          id="aipkit_bulk_vector_embedding_provider"
                          name="vector_embedding_provider"
                          value="openai"
                        >
                        <input
                          type="hidden"
                          id="aipkit_bulk_vector_embedding_model"
                          name="vector_embedding_model"
                          value=""
                        >
                      </div>
                    </div>
                  </section>
                </div>
              </section>

              <section class="aipkit_enhancer_update_section" id="aipkit_bulk_prompt_section">
                <h3>What to update</h3>
                <div class="aipkit_enhancer_update_list">
                  ${promptRowsHtml}
                  <div class="aipkit_enhancer_update_item" data-prompt-key="seo_slug">
                    <div class="aipkit_enhancer_update_row">
                      <div class="aipkit_enhancer_update_copy">
                        <span class="aipkit_enhancer_update_label">Optimize URL</span>
                        <span class="aipkit_enhancer_update_desc">Generate a cleaner post slug</span>
                      </div>
                      <div class="aipkit_enhancer_update_controls">
                        <label class="aipkit_switch">
                          <input
                            type="checkbox"
                            id="aipkit_bulk_generate_seo_slug"
                            name="generate_seo_slug"
                            value="1"
                            class="aipkit_toggle_switch"
                          >
                          <span class="aipkit_switch_slider"></span>
                        </label>
                      </div>
                    </div>
                  </div>
                </div>
              </section>
            </div>

            <div id="aipkit-enhancer-bulk-progress" hidden>
              <div class="aipkit_enhancer_progress_header">
                <div id="aipkit-enhancer-progress-text" class="aipkit_enhancer_progress_text">Processing 1 of ${postCount}…</div>
                <div id="aipkit-enhancer-progress-stats" class="aipkit_enhancer_progress_stats">0%</div>
              </div>
              <div class="aipkit_enhancer_progress_track" aria-hidden="true">
                <div id="aipkit-enhancer-bulk-progress-bar" class="aipkit_enhancer_progress_bar"></div>
              </div>
              <div id="aipkit-enhancer-bulk-status-log" class="aipkit_enhancer_bulk_log"></div>
            </div>
          </div>

          <div class="aipkit-modal-footer">
            <div
              id="aipkit_enhancer_footer_status"
              class="aipkit_enhancer_footer_status"
              data-default-text="${postCount} item${postCount === 1 ? "" : "s"} selected"
              aria-live="polite"
            >${postCount} item${postCount === 1 ? "" : "s"} selected</div>
            <div class="aipkit_enhancer_footer_actions">
              <button
                type="button"
                id="aipkit_bulk_enhancer_back_to_settings_btn"
                class="aipkit_btn aipkit_enhancer_back_button"
                hidden
              >
                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                Back
              </button>
              <button
                type="button"
                id="aipkit_bulk_enhancer_stop_btn"
                class="aipkit_btn aipkit_btn-danger aipkit_enhancer_stop_button"
                hidden
              >
                <span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
                Stop
              </button>
              <button
                type="button"
                id="aipkit_bulk_enhancer_start_btn"
                class="aipkit_btn aipkit_btn-primary aipkit_enhancer_start_button"
              >
                <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                <span class="aipkit_btn-text">Start</span>
                <span class="aipkit_spinner" hidden></span>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  window.aipkit_enhancer_getBulkModalHtml = aipkit_enhancer_getBulkModalHtml;
})();
