/**
 * AIPKit - Vector Post Processor - Modal UI
 *
 * Handles setup and batch progress for adding selected content to a knowledge base.
 */
import { createActiveWorkCloseGuard } from "../../utils/ui/active-work-close-guard.js";

(function () {
  "use strict";

  const VPP_MODAL_ID = "aipkit_vpp_modal";
  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  const attrEscaper = window.aipkit_escapeAttribute || escaper;
  let currentPostIdsForModal = [];
  let currentPostTypeForModal = "";
  let isModalProcessing = false;

  const getTexts = () => window.aipkit_vpp_config?.text || {};

  const getPostTitleForLog = (postId) => {
    if (!postId) return "Post";
    const cache = window.aipkit_vpp_postTitles || {};
    if (cache[postId]) return cache[postId];

    const postRow = document.querySelector(`#post-${postId}`);
    const postTitleEl = postRow
      ? postRow.querySelector(".title .row-title") ||
        postRow.querySelector("strong a") ||
        postRow.querySelector(".title a") ||
        postRow.querySelector("a.row-title") ||
        postRow.querySelector(".column-title strong a") ||
        postRow.querySelector(".column-title .row-title")
      : null;
    const title = postTitleEl
      ? postTitleEl.textContent.trim()
      : `Post #${postId}`;

    cache[postId] = title;
    window.aipkit_vpp_postTitles = cache;
    return title;
  };

  const formatSelectedCount = (count) => {
    const texts = getTexts();
    const template =
      count === 1
        ? texts.items_selected_singular || "%d item selected"
        : texts.items_selected_plural || "%d items selected";
    return template.replace("%d", String(count));
  };

  const getProgressStatusLabel = (status) => {
    const texts = getTexts();
    const statusMap = {
      pending: texts.status_pending || "Waiting",
      processing: texts.status_processing || "Processing",
      success: texts.status_completed || "Ready",
      submitted: texts.status_submitted || "Submitted",
      error: texts.status_failed || "Failed",
      stopped: texts.status_stopped || "Stopped",
    };
    return statusMap[status] || status;
  };

  const getProgressIconClass = (status) => {
    const iconMap = {
      pending: "dashicons-minus",
      processing: "dashicons-update-alt",
      success: "dashicons-yes-alt",
      submitted: "dashicons-cloud-upload",
      error: "dashicons-warning",
      stopped: "dashicons-controls-pause",
    };
    return iconMap[status] || iconMap.pending;
  };

  const createProgressItem = (postId, title) => {
    const item = document.createElement("div");
    item.className =
      "aipkit_vpp_progress_item aipkit_vpp_progress_item--pending";
    item.dataset.postId = postId;
    item.setAttribute("role", "listitem");

    const icon = document.createElement("span");
    icon.className = `aipkit_vpp_progress_item_icon dashicons ${getProgressIconClass(
      "pending"
    )}`;
    icon.setAttribute("aria-hidden", "true");

    const main = document.createElement("div");
    main.className = "aipkit_vpp_progress_item_main";

    const titleEl = document.createElement("div");
    titleEl.className = "aipkit_vpp_progress_item_title";
    titleEl.textContent = title;

    const messageEl = document.createElement("div");
    messageEl.className = "aipkit_vpp_progress_item_message";
    messageEl.hidden = true;

    const statusEl = document.createElement("div");
    statusEl.className = "aipkit_vpp_progress_item_status";
    statusEl.textContent = getProgressStatusLabel("pending");

    main.appendChild(titleEl);
    main.appendChild(messageEl);
    item.appendChild(icon);
    item.appendChild(main);
    item.appendChild(statusEl);
    return item;
  };

  const renderSelectedItems = (postIds) => {
    const list = document.getElementById("aipkit_vpp_selected_items_list");
    if (!list) return;

    list.innerHTML = "";
    const fragment = document.createDocumentFragment();
    postIds.forEach((postId) => {
      const row = document.createElement("div");
      row.className = "aipkit_vpp_selected_item";

      const icon = document.createElement("span");
      icon.className = "dashicons dashicons-media-document";
      icon.setAttribute("aria-hidden", "true");

      const title = document.createElement("span");
      title.className = "aipkit_vpp_selected_item_title";
      title.textContent = getPostTitleForLog(postId);

      row.appendChild(icon);
      row.appendChild(title);
      fragment.appendChild(row);
    });
    list.appendChild(fragment);
  };

  /**
   * Creates and shows the modal for knowledge base selection and indexing.
   * @param {string[]} postIds Array of selected post IDs.
   * @param {string} postType The current post type.
   */
  function aipkit_vpp_showModal(postIds, postType) {
    if (document.getElementById(VPP_MODAL_ID)) {
      console.warn("VPP Modal UI: Modal already exists.");
      return;
    }

    currentPostIdsForModal = postIds;
    currentPostTypeForModal = postType;
    isModalProcessing = false;

    const texts = getTexts();
    const itemCount = postIds.length;
    const selectedCountText = formatSelectedCount(itemCount);

    const overlay = document.createElement("div");
    overlay.id = VPP_MODAL_ID;
    overlay.className = "aipkit-modal-overlay aipkit-active";

    const modalContent = document.createElement("div");
    modalContent.className =
      "aipkit-modal-content aipkit_vpp_modal_content";
    modalContent.setAttribute("role", "dialog");
    modalContent.setAttribute("aria-modal", "true");
    modalContent.setAttribute("aria-labelledby", "aipkit_vpp_modal_title");

    modalContent.innerHTML = `
      <div class="aipkit-modal-header aipkit_vpp_modal_header">
        <h2 id="aipkit_vpp_modal_title" class="aipkit-modal-title">${escaper(
          texts.modal_title || "Add to knowledge base"
        )}</h2>
        <button type="button" class="aipkit-modal-close-btn aipkit_vpp_close_btn" aria-label="${attrEscaper(
          texts.close || "Close"
        )}">
          <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
        </button>
      </div>

      <div class="aipkit-modal-body aipkit_vpp_modal_body">
        <div id="aipkit_vpp_status_message" class="aipkit_vpp_status_message" aria-live="polite"></div>

        <div id="aipkit_vpp_setup_view" class="aipkit_vpp_setup_view">
          <div class="aipkit_vpp_config_grid">
            <div class="aipkit_vpp_field">
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_vector_store_provider">${escaper(
                texts.provider_label || "Provider"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_vector_store_provider" class="aipkit_vpp_select">
                  <option value="local">Local</option>
                  <option value="openai">OpenAI</option>
                  <option value="google">Google</option>
                  <option value="pinecone">Pinecone</option>
                  <option value="qdrant">Qdrant</option>
                  <option value="chroma">Chroma</option>
                </select>
              </div>
            </div>

            <div id="aipkit_vpp_openai_options" class="aipkit_vpp_provider_panel aipkit_vpp_field">
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_openai_vector_store_id">${escaper(
                texts.target_label || "Index"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_openai_vector_store_id" class="aipkit_vpp_select">
                  <option value="">${escaper(
                    texts.loading_stores || "Loading stores..."
                  )}</option>
                </select>
              </div>
            </div>

            <div id="aipkit_vpp_local_options" class="aipkit_vpp_provider_panel aipkit_vpp_field" hidden>
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_local_store_select">${escaper(
                texts.local_target_label || "Knowledge base"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_local_store_select" class="aipkit_vpp_select"></select>
              </div>
            </div>

            <div id="aipkit_vpp_google_options" class="aipkit_vpp_provider_panel aipkit_vpp_field" hidden>
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_google_file_search_store_name">${escaper(
                texts.target_label || "Index"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_google_file_search_store_name" class="aipkit_vpp_select">
                  <option value="">${escaper(
                    texts.loading_stores || "Loading stores..."
                  )}</option>
                </select>
              </div>
            </div>

            <div id="aipkit_vpp_pinecone_options" class="aipkit_vpp_provider_panel aipkit_vpp_field" hidden>
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_pinecone_target_index_select">${escaper(
                texts.target_label || "Index"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_pinecone_target_index_select" class="aipkit_vpp_select">
                  <option value="">${escaper(
                    texts.loading_indexes || "Loading indexes..."
                  )}</option>
                </select>
              </div>
            </div>

            <div id="aipkit_vpp_qdrant_options" class="aipkit_vpp_provider_panel aipkit_vpp_field" hidden>
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_qdrant_target_collection_select">${escaper(
                texts.target_label || "Index"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_qdrant_target_collection_select" class="aipkit_vpp_select">
                  <option value="">${escaper(
                    texts.loading_indexes || "Loading collections..."
                  )}</option>
                </select>
              </div>
            </div>

            <div id="aipkit_vpp_chroma_options" class="aipkit_vpp_provider_panel aipkit_vpp_field" hidden>
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_chroma_target_collection_select">${escaper(
                texts.target_label || "Index"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_chroma_target_collection_select" class="aipkit_vpp_select">
                  <option value="">${escaper(
                    texts.loading_indexes || "Loading collections..."
                  )}</option>
                </select>
              </div>
            </div>

            <div id="aipkit_vpp_embedding_options" class="aipkit_vpp_provider_panel aipkit_vpp_field aipkit_vpp_field--wide" hidden>
              <label class="aipkit_vpp_field_label" for="aipkit_vpp_embedding_model_select">${escaper(
                texts.embedding_label || "Embedding model"
              )}</label>
              <div class="aipkit_vpp_select_wrap">
                <select id="aipkit_vpp_embedding_model_select" class="aipkit_vpp_select" data-aipkit-universal-model-combined="1" data-aipkit-universal-model-capability="embeddings">
                  <option value="">${escaper(
                    texts.select_model || "Select a model"
                  )}</option>
                </select>
              </div>
            </div>
          </div>

          <div id="aipkit_vpp_selected_items_panel" class="aipkit_vpp_selected_items_panel" hidden>
            <div id="aipkit_vpp_selected_items_list" class="aipkit_vpp_selected_items_list"></div>
          </div>
        </div>

        <div id="aipkit_vpp_progress_area" class="aipkit_vpp_progress_area" hidden>
          <div class="aipkit_vpp_progress_header">
            <span id="aipkit_vpp_progress_text" class="aipkit_vpp_progress_text"></span>
            <span id="aipkit_vpp_progress_percentage" class="aipkit_vpp_progress_percentage">0%</span>
          </div>
          <div class="aipkit_vpp_progress_bar_container" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <div class="aipkit_vpp_progress_bar"></div>
          </div>
          <div id="aipkit_vpp_progress_log" class="aipkit_vpp_progress_log" role="list"></div>
        </div>
      </div>

      <div class="aipkit-modal-footer aipkit_vpp_modal_footer">
        <div class="aipkit_vpp_footer_status">
          <button type="button" id="aipkit_vpp_selected_items_toggle" class="aipkit_vpp_selected_items_toggle" aria-expanded="false" aria-controls="aipkit_vpp_selected_items_panel">
            <span>${escaper(selectedCountText)}</span>
            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
          </button>
          <span id="aipkit_vpp_indexed_summary" class="aipkit_vpp_indexed_summary" hidden>${escaper(
            (texts.items_indexed_progress || "%1$d of %2$d items indexed")
              .replace("%1$d", "0")
              .replace("%2$d", String(itemCount))
          )}</span>
        </div>
        <div class="aipkit_vpp_footer_actions">
          <button type="button" id="aipkit_vpp_start_indexing_btn" class="aipkit_btn aipkit_btn-primary aipkit_vpp_start_btn">
            <span class="aipkit_btn-text">${escaper(
              texts.start_indexing || "Start indexing"
            )}</span>
          </button>
          <button type="button" id="aipkit_vpp_stop_indexing_btn" class="aipkit_btn aipkit_btn-danger aipkit_vpp_stop_btn" hidden>
            <span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
            <span class="aipkit_btn-text">${escaper(
              texts.stop || "Stop"
            )}</span>
          </button>
        </div>
      </div>
    `;

    overlay.appendChild(modalContent);
    document.body.appendChild(overlay);
    overlay.__aipkitRequestClose = createActiveWorkCloseGuard({
      modal: overlay,
      isBusy: () => isModalProcessing || Boolean(overlay.querySelector('.aipkit_vpp_progress_item--processing, .aipkit_vpp_progress_item--submitted')),
      getMessage: () => isModalProcessing
        ? texts.close_indexing_active || "Indexing is still in progress. Closing will stop submitting the remaining items. Requests already sent may still finish in the background."
        : texts.close_indexing_background || "Some sources are still processing. Closing this window will not cancel them. You can check their progress in Sources.",
      title: texts.close_indexing_title || "Close while indexing?",
      confirmText: texts.close_window || "Close window",
      cancelText: texts.keep_open || "Keep open",
      onClose: () => {
        window.aipkit_isIndexingStopped = true;
        overlay.dataset.aipkitVppClosed = "true";
        overlay.dispatchEvent(new CustomEvent('aipkit:vpp-closed'));
        overlay.classList.remove("aipkit-active");
        setTimeout(() => overlay.remove(), 200);
      },
    });
    renderSelectedItems(postIds);

    const providerSelect = document.getElementById(
      "aipkit_vpp_vector_store_provider"
    );
    if (providerSelect) {
      const defaults = window.aipkit_getNewFeatureDefaults?.() || {};
      providerSelect.value = Array.from(providerSelect.options).some(
        (option) => option.value === defaults.vector_store_provider
      ) ? defaults.vector_store_provider : "local";
    }

    if (typeof window.aipkit_initVppSelectPickers === "function") {
      window.aipkit_initVppSelectPickers(overlay);
    }

    modalContent.addEventListener("click", (event) => {
      const target = event.target;
      if (target.closest(".aipkit_vpp_close_btn")) {
        aipkit_vpp_closeModal();
      } else if (target.closest("#aipkit_vpp_start_indexing_btn")) {
        handleStartIndexingClick();
      } else if (target.closest("#aipkit_vpp_stop_indexing_btn")) {
        handleStopIndexingClick();
      } else if (target.closest("#aipkit_vpp_selected_items_toggle")) {
        toggleSelectedItems();
      }
    });

    if (providerSelect) {
      providerSelect.addEventListener(
        "change",
        handleProviderChangeInVppModal
      );
      handleProviderChangeInVppModal({ target: providerSelect });
    }
  }

  function toggleSelectedItems() {
    const toggle = document.getElementById("aipkit_vpp_selected_items_toggle");
    const panel = document.getElementById("aipkit_vpp_selected_items_panel");
    if (!toggle || !panel) return;

    const shouldOpen = toggle.getAttribute("aria-expanded") !== "true";
    toggle.setAttribute("aria-expanded", shouldOpen ? "true" : "false");
    panel.hidden = !shouldOpen;

    const icon = toggle.querySelector(".dashicons");
    if (icon) {
      icon.classList.toggle("dashicons-arrow-down-alt2", !shouldOpen);
      icon.classList.toggle("dashicons-arrow-up-alt2", shouldOpen);
    }
  }

  function handleStopIndexingClick() {
    window.aipkit_isIndexingStopped = true;
    const stopButton = document.getElementById("aipkit_vpp_stop_indexing_btn");
    const texts = getTexts();
    if (!stopButton) return;

    stopButton.disabled = true;
    const textSpan = stopButton.querySelector(".aipkit_btn-text");
    if (textSpan) textSpan.textContent = texts.stopping || "Stopping...";
  }

  function aipkit_vpp_closeModal() {
    const overlay = document.getElementById(VPP_MODAL_ID);
    overlay?.__aipkitRequestClose?.();
  }

  function aipkit_vpp_updateProgress(percentage, text) {
    const boundedPercentage = Math.max(0, Math.min(100, percentage));
    const roundedPercentage = Math.round(boundedPercentage);
    const progressBar = document.querySelector(
      `#${VPP_MODAL_ID} .aipkit_vpp_progress_bar`
    );
    const progressContainer = document.querySelector(
      `#${VPP_MODAL_ID} .aipkit_vpp_progress_bar_container`
    );
    const progressText = document.getElementById("aipkit_vpp_progress_text");
    const percentageText = document.getElementById(
      "aipkit_vpp_progress_percentage"
    );

    if (progressBar) progressBar.style.width = `${boundedPercentage}%`;
    if (progressContainer) {
      progressContainer.setAttribute("aria-valuenow", String(roundedPercentage));
    }
    if (progressText) progressText.textContent = text;
    if (percentageText) percentageText.textContent = `${roundedPercentage}%`;
  }

  function aipkit_vpp_updateFooterProgress(
    successCount,
    totalCount,
    templateOverride = ""
  ) {
    const summary = document.getElementById("aipkit_vpp_indexed_summary");
    if (!summary) return;

    const texts = getTexts();
    summary.textContent = (
      templateOverride ||
        texts.items_indexed_progress ||
        "%1$d of %2$d items indexed"
    )
      .replace("%1$d", String(successCount))
      .replace("%2$d", String(totalCount));
  }

  function aipkit_vpp_initProgressLog(postIds) {
    const logWrap = document.getElementById("aipkit_vpp_progress_log");
    if (!logWrap) return;

    logWrap.innerHTML = "";
    const fragment = document.createDocumentFragment();
    (postIds || []).forEach((postId) => {
      fragment.appendChild(
        createProgressItem(postId, getPostTitleForLog(postId))
      );
    });
    logWrap.appendChild(fragment);
    logWrap.scrollTop = 0;
  }

  function aipkit_vpp_updateProgressItem(
    postId,
    status,
    message = "",
    titleOverride = ""
  ) {
    const logWrap = document.getElementById("aipkit_vpp_progress_log");
    if (!logWrap) return;

    const selector = `.aipkit_vpp_progress_item[data-post-id="${postId}"]`;
    let item = logWrap.querySelector(selector);
    if (!item) {
      item = createProgressItem(
        postId,
        titleOverride || getPostTitleForLog(postId)
      );
      logWrap.appendChild(item);
    }

    item.className = `aipkit_vpp_progress_item aipkit_vpp_progress_item--${status}`;
    const icon = item.querySelector(".aipkit_vpp_progress_item_icon");
    const statusEl = item.querySelector(".aipkit_vpp_progress_item_status");
    const messageEl = item.querySelector(".aipkit_vpp_progress_item_message");

    if (icon) {
      icon.className = `aipkit_vpp_progress_item_icon dashicons ${getProgressIconClass(
        status
      )}`;
    }
    if (statusEl) statusEl.textContent = getProgressStatusLabel(status);
    if (messageEl) {
      messageEl.textContent = message || "";
      messageEl.hidden = !message;
    }

    item.scrollIntoView({ block: "nearest" });
  }

  function aipkit_vpp_updateStatus(message, type = "info") {
    const statusDiv = document.getElementById("aipkit_vpp_status_message");
    if (!statusDiv) return;

    if (type === "success") {
      statusDiv.textContent = "";
      statusDiv.className = "aipkit_vpp_status_message";
      return;
    }

    statusDiv.textContent = message;
    statusDiv.className = message
      ? `aipkit_vpp_status_message aipkit_message-${type}`
      : "aipkit_vpp_status_message";
  }

  function aipkit_vpp_resetModalAfterProcessing() {
    isModalProcessing = false;
    const texts = getTexts();
    const startButton = document.getElementById(
      "aipkit_vpp_start_indexing_btn"
    );
    const stopButton = document.getElementById("aipkit_vpp_stop_indexing_btn");

    if (startButton) startButton.hidden = true;
    if (stopButton) {
      stopButton.hidden = true;
      stopButton.disabled = false;
      const textSpan = stopButton.querySelector(".aipkit_btn-text");
      if (textSpan) textSpan.textContent = texts.stop || "Stop";
    }

    if (window.aipkit_isIndexingStopped) {
      document
        .querySelectorAll(
          `#${VPP_MODAL_ID} .aipkit_vpp_progress_item--pending`
        )
        .forEach((item) => {
          const postId = item.dataset.postId;
          if (postId) aipkit_vpp_updateProgressItem(postId, "stopped");
        });
    }
  }

  function handleProviderChangeInVppModal(event) {
    const provider = event.target.value;
    aipkit_vpp_updateStatus("");
    const openaiOptions = document.getElementById("aipkit_vpp_openai_options");
    const googleOptions = document.getElementById("aipkit_vpp_google_options");
    const pineconeOptions = document.getElementById(
      "aipkit_vpp_pinecone_options"
    );
    const qdrantOptions = document.getElementById("aipkit_vpp_qdrant_options");
    const chromaOptions = document.getElementById("aipkit_vpp_chroma_options");
    const localOptions = document.getElementById("aipkit_vpp_local_options");
    const embeddingOptions = document.getElementById(
      "aipkit_vpp_embedding_options"
    );

    if (
      ["openai", "pinecone", "qdrant", "chroma"].includes(provider) &&
      typeof window.aipkit_refreshVectorStoreProviderList === "function"
    ) {
      window.aipkit_refreshVectorStoreProviderList(provider);
    }

    [openaiOptions, googleOptions, pineconeOptions, qdrantOptions, chromaOptions, localOptions].forEach(
      (panel) => {
        if (panel) panel.hidden = true;
      }
    );
    if (embeddingOptions) embeddingOptions.hidden = true;

    if (provider === "local") {
      if (embeddingOptions) embeddingOptions.hidden = false;
      populateEmbeddingConfig();
      if (localOptions) localOptions.hidden = false;
      if (typeof window.aipkit_vpp_populateLocalStoresSelect === "function") {
        window.aipkit_vpp_populateLocalStoresSelect();
      }
    } else if (provider === "openai") {
      if (openaiOptions) openaiOptions.hidden = false;
      if (typeof window.aipkit_vpp_populateOpenAIStoresSelect === "function") {
        window.aipkit_vpp_populateOpenAIStoresSelect();
      }
    } else if (provider === "google") {
      if (googleOptions) googleOptions.hidden = false;
      if (typeof window.aipkit_vpp_refreshGoogleStoresFromApi === "function") {
        window.aipkit_vpp_refreshGoogleStoresFromApi();
      } else if (
        typeof window.aipkit_vpp_populateGoogleStoresSelect === "function"
      ) {
        window.aipkit_vpp_populateGoogleStoresSelect();
      }
    } else if (provider === "pinecone") {
      if (pineconeOptions) pineconeOptions.hidden = false;
      if (embeddingOptions) embeddingOptions.hidden = false;
      if (
        typeof window.aipkit_vpp_populatePineconeIndexesSelect === "function"
      ) {
        window.aipkit_vpp_populatePineconeIndexesSelect();
      }
      populateEmbeddingConfig();
    } else if (provider === "qdrant") {
      if (qdrantOptions) qdrantOptions.hidden = false;
      if (embeddingOptions) embeddingOptions.hidden = false;
      if (
        typeof window.aipkit_vpp_populateQdrantCollectionsSelect === "function"
      ) {
        window.aipkit_vpp_populateQdrantCollectionsSelect();
      }
      populateEmbeddingConfig();
    } else if (provider === "chroma") {
      if (chromaOptions) chromaOptions.hidden = false;
      if (embeddingOptions) embeddingOptions.hidden = false;
      if (
        typeof window.aipkit_vpp_populateChromaCollectionsSelect === "function"
      ) {
        window.aipkit_vpp_populateChromaCollectionsSelect();
      }
      populateEmbeddingConfig();
    }
  }

  function populateEmbeddingConfig() {
    if (
      typeof window.aipkit_vpp_populateEmbeddingConfigSelects === "function"
    ) {
      window.aipkit_vpp_populateEmbeddingConfigSelects();
    }
  }

  function getEmbeddingRequestData(texts) {
    const embeddingModelSelect = document.getElementById(
      "aipkit_vpp_embedding_model_select"
    );
    const selectedOption =
      embeddingModelSelect?.selectedOptions?.length > 0
        ? embeddingModelSelect.selectedOptions[0]
        : null;
    const embeddingModel = selectedOption?.dataset.model || "";
    const embeddingProvider = selectedOption?.dataset.provider || "";

    if (!embeddingProvider || !embeddingModel || selectedOption.disabled || embeddingModelSelect.disabled) {
      aipkit_vpp_updateStatus(
        texts.error_no_embedding_config ||
          "Please select an embedding model.",
        "error"
      );
      return null;
    }

    return {
      embedding_model: embeddingModel,
      embedding_provider: embeddingProvider,
    };
  }

  function handleStartIndexingClick() {
    if (isModalProcessing) return;
    window.aipkit_isIndexingStopped = false;

    const providerSelect = document.getElementById(
      "aipkit_vpp_vector_store_provider"
    );
    const provider = providerSelect?.value || "";
    const texts = getTexts();
    let targetId = "";
    let embeddingData = null;

    if (provider === "local") {
      embeddingData = getEmbeddingRequestData(texts);
      if (!embeddingData) return;
      targetId = document.getElementById("aipkit_vpp_local_store_select")?.value || "";
      if (!targetId) {
        aipkit_vpp_updateStatus(
          texts.error_no_local_store_selected || "Please select a knowledge base.",
          "error"
        );
        return;
      }
    } else if (provider === "openai") {
      targetId =
        document.getElementById("aipkit_vpp_openai_vector_store_id")?.value ||
        "";
      if (!targetId) {
        aipkit_vpp_updateStatus(
          texts.error_no_store_selected_vpp ||
            "Please select an existing OpenAI store.",
          "error"
        );
        return;
      }
    } else if (provider === "google") {
      targetId =
        document.getElementById("aipkit_vpp_google_file_search_store_name")
          ?.value || "";
      if (!targetId) {
        aipkit_vpp_updateStatus(
          texts.error_no_google_file_search_store_selected ||
            "Please select a Google store.",
          "error"
        );
        return;
      }
    } else if (provider === "pinecone") {
      targetId =
        document.getElementById("aipkit_vpp_pinecone_target_index_select")
          ?.value || "";
      if (!targetId) {
        aipkit_vpp_updateStatus(
          texts.error_no_pinecone_index_selected ||
            "Please select a Pinecone index.",
          "error"
        );
        return;
      }
      embeddingData = getEmbeddingRequestData(texts);
      if (!embeddingData) return;
    } else if (provider === "qdrant") {
      targetId =
        document.getElementById("aipkit_vpp_qdrant_target_collection_select")
          ?.value || "";
      if (!targetId) {
        aipkit_vpp_updateStatus(
          texts.error_no_qdrant_collection_selected ||
            "Please select a Qdrant collection.",
          "error"
        );
        return;
      }
      embeddingData = getEmbeddingRequestData(texts);
      if (!embeddingData) return;
    } else if (provider === "chroma") {
      targetId =
        document.getElementById("aipkit_vpp_chroma_target_collection_select")
          ?.value || "";
      if (!targetId) {
        aipkit_vpp_updateStatus(
          texts.error_no_chroma_collection_selected ||
            "Please select a Chroma collection.",
          "error"
        );
        return;
      }
      embeddingData = getEmbeddingRequestData(texts);
      if (!embeddingData) return;
    } else {
      aipkit_vpp_updateStatus(
        `Provider ${provider} is not supported for indexing.`,
        "error"
      );
      return;
    }

    isModalProcessing = true;
    aipkit_vpp_updateStatus("");

    const setupView = document.getElementById("aipkit_vpp_setup_view");
    const progressArea = document.getElementById("aipkit_vpp_progress_area");
    const selectedToggle = document.getElementById(
      "aipkit_vpp_selected_items_toggle"
    );
    const indexedSummary = document.getElementById(
      "aipkit_vpp_indexed_summary"
    );
    const startButton = document.getElementById(
      "aipkit_vpp_start_indexing_btn"
    );
    const stopButton = document.getElementById("aipkit_vpp_stop_indexing_btn");

    if (setupView) setupView.hidden = true;
    if (progressArea) progressArea.hidden = false;
    if (selectedToggle) selectedToggle.hidden = true;
    if (indexedSummary) indexedSummary.hidden = false;
    if (startButton) startButton.hidden = true;
    if (stopButton) {
      stopButton.hidden = false;
      stopButton.disabled = false;
      const textSpan = stopButton.querySelector(".aipkit_btn-text");
      if (textSpan) textSpan.textContent = texts.stop || "Stop";
    }

    aipkit_vpp_initProgressLog(currentPostIdsForModal);
    aipkit_vpp_updateFooterProgress(0, currentPostIdsForModal.length);
    aipkit_vpp_updateProgress(
      0,
      (texts.indexing_progress || "Indexing %1$d of %2$d...")
        .replace("%1$d", "1")
        .replace("%2$d", String(currentPostIdsForModal.length))
    );

    const embeddingProvider = embeddingData?.embedding_provider || "";
    const embeddingModel = embeddingData?.embedding_model || "";

    if (
      provider === "local" &&
      typeof window.aipkit_vpp_startIndexingLocal === "function"
    ) {
      window.aipkit_vpp_startIndexingLocal(
        currentPostIdsForModal,
        targetId,
        currentPostTypeForModal,
        embeddingData
      );
    } else if (
      provider === "openai" &&
      typeof window.aipkit_vpp_startIndexingOpenAI === "function"
    ) {
      window.aipkit_vpp_startIndexingOpenAI(
        currentPostIdsForModal,
        targetId,
        null,
        currentPostTypeForModal
      );
    } else if (
      provider === "google" &&
      typeof window.aipkit_vpp_startIndexingGoogle === "function"
    ) {
      window.aipkit_vpp_startIndexingGoogle(
        currentPostIdsForModal,
        targetId,
        currentPostTypeForModal
      );
    } else if (
      provider === "pinecone" &&
      typeof window.aipkit_vpp_startIndexingPinecone === "function"
    ) {
      window.aipkit_vpp_startIndexingPinecone(
        currentPostIdsForModal,
        targetId,
        embeddingProvider,
        embeddingModel,
        currentPostTypeForModal
      );
    } else if (
      provider === "qdrant" &&
      typeof window.aipkit_vpp_startIndexingQdrant === "function"
    ) {
      window.aipkit_vpp_startIndexingQdrant(
        currentPostIdsForModal,
        targetId,
        embeddingProvider,
        embeddingModel,
        currentPostTypeForModal
      );
    } else if (
      provider === "chroma" &&
      typeof window.aipkit_vpp_startIndexingChroma === "function"
    ) {
      window.aipkit_vpp_startIndexingChroma(
        currentPostIdsForModal,
        targetId,
        embeddingProvider,
        embeddingModel,
        currentPostTypeForModal
      );
    }
  }

  window.aipkit_vpp_showModal = aipkit_vpp_showModal;
  window.aipkit_vpp_closeModal = aipkit_vpp_closeModal;
  window.aipkit_vpp_updateProgress = aipkit_vpp_updateProgress;
  window.aipkit_vpp_updateFooterProgress = aipkit_vpp_updateFooterProgress;
  window.aipkit_vpp_initProgressLog = aipkit_vpp_initProgressLog;
  window.aipkit_vpp_updateProgressItem = aipkit_vpp_updateProgressItem;
  window.aipkit_vpp_updateStatus = aipkit_vpp_updateStatus;
  window.aipkit_vpp_resetModalAfterProcessing =
    aipkit_vpp_resetModalAfterProcessing;
})();
