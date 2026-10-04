import { createSourceActionMenu } from "../knowledge-base/source-actions.js";
import { createKnowledgeBaseSourceRecords, getSourceUpdatedMeta } from "../knowledge-base/source-records.js";
import { createKnowledgeBaseStorePresentation } from "../knowledge-base/stores.js";
import { createKnowledgeBaseTextEditor, indexKnowledgeBaseText } from "../knowledge-base/text.js";
import { indexKnowledgeBaseWebsite } from "../knowledge-base/website.js";
import { createSourceEditor } from "../shared/source-editor.js";
import { createGoogleFileSearchJobPoller } from "../utils/google-file-search-job-poller.js";

(function () {
  "use strict";

  const i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
  const __ = i18n && typeof i18n.__ === "function" ? i18n.__ : (text) => text;
  const sprintf =
    i18n && typeof i18n.sprintf === "function"
      ? i18n.sprintf
      : (format, ...args) => format.replace(/%s/g, () => args.shift());
  const _n =
    i18n && typeof i18n._n === "function"
      ? i18n._n
      : (single, plural, count) => (count === 1 ? single : plural);
  const escaper =
    typeof window.aipkit_escapeHtml === "function"
      ? window.aipkit_escapeHtml
      : (value) => String(value ?? "");
  const ERROR_PREFIX = __("Error:", "gpt3-ai-content-generator");
  const TRAINING_STATUS_LABELS = {
    added: __("Added", "gpt3-ai-content-generator"),
    failed: __("Failed", "gpt3-ai-content-generator"),
    processing: __("Processing", "gpt3-ai-content-generator"),
    queued: __("Queued", "gpt3-ai-content-generator"),
    stopped: __("Stopped", "gpt3-ai-content-generator"),
  };

  const MAX_SOURCE_LENGTH = 60;
  const SOURCE_ACTION_SELECTOR =
    ".aipkit_sources_action_retrain, .aipkit_sources_action_edit, .aipkit_sources_action_view, .aipkit_sources_action_reason, .aipkit_sources_action_delete";
  const SOURCE_ROW_CONTROL_SELECTOR = `${SOURCE_ACTION_SELECTOR}, .aipkit_sources_action_menu_trigger, .aipkit_sources_row_checkbox`;
  const PROVIDER_INDEX_TERMS = {
    local: {
      singular: __("Knowledge base", "gpt3-ai-content-generator"),
      plural: __("Knowledge bases", "gpt3-ai-content-generator"),
      loading: __("Loading knowledge bases...", "gpt3-ai-content-generator"),
      empty: __("No knowledge bases yet.", "gpt3-ai-content-generator"),
    },
    openai: {
      singular: __("Vector store", "gpt3-ai-content-generator"),
      plural: __("Vector stores", "gpt3-ai-content-generator"),
      loading: __("Loading vector stores...", "gpt3-ai-content-generator"),
      empty: __("No vector stores found.", "gpt3-ai-content-generator"),
    },
    google: {
      singular: __("Store", "gpt3-ai-content-generator"),
      plural: __("Stores", "gpt3-ai-content-generator"),
      loading: __("Loading stores...", "gpt3-ai-content-generator"),
      empty: __("No stores found.", "gpt3-ai-content-generator"),
    },
    pinecone: {
      singular: __("Index", "gpt3-ai-content-generator"),
      plural: __("Indexes", "gpt3-ai-content-generator"),
      loading: __("Loading indexes...", "gpt3-ai-content-generator"),
      empty: __("No indexes found.", "gpt3-ai-content-generator"),
    },
    qdrant: {
      singular: __("Collection", "gpt3-ai-content-generator"),
      plural: __("Collections", "gpt3-ai-content-generator"),
      loading: __("Loading collections...", "gpt3-ai-content-generator"),
      empty: __("No collections found.", "gpt3-ai-content-generator"),
    },
    chroma: {
      singular: __("Collection", "gpt3-ai-content-generator"),
      plural: __("Collections", "gpt3-ai-content-generator"),
      loading: __("Loading collections...", "gpt3-ai-content-generator"),
      empty: __("No collections found.", "gpt3-ai-content-generator"),
    },
    default: {
      singular: __("Index", "gpt3-ai-content-generator"),
      plural: __("Indexes", "gpt3-ai-content-generator"),
      loading: __("Loading indexes...", "gpt3-ai-content-generator"),
      empty: __("No indexes found.", "gpt3-ai-content-generator"),
    },
  };

  const PROVIDER_PANEL_CONFIG = {
    local: {
      key: "local",
      listAction: "aipkit_local_list_stores",
      listKey: "stores",
      createAction: "aipkit_local_create_store",
      createKey: "store",
      deleteAction: "aipkit_local_delete_store",
      deleteParam: "store_id",
      deleteLabel: __("Delete knowledge base", "gpt3-ai-content-generator"),
      namePlaceholder: __("my-knowledge-base", "gpt3-ai-content-generator"),
      needsDimension: true,
      defaultDimension: 1536,
    },
    openai: {
      key: "openai",
      listAction: "aipkit_list_vector_stores_openai",
      listKey: "stores",
      createAction: "aipkit_create_vector_store_openai",
      createKey: "store",
      deleteAction: "aipkit_delete_vector_store_openai",
      deleteParam: "store_id",
      deleteLabel: __("Delete vector store", "gpt3-ai-content-generator"),
      namePlaceholder: __("my-knowledge-store", "gpt3-ai-content-generator"),
      needsDimension: false,
    },
    google: {
      key: "google",
      listAction: "aipkit_list_google_file_search_stores",
      listKey: "stores",
      createAction: "aipkit_create_google_file_search_store",
      createKey: "store",
      deleteAction: "aipkit_delete_google_file_search_store",
      deleteParam: "store_name",
      deleteLabel: __("Delete store", "gpt3-ai-content-generator"),
      namePlaceholder: __("my-google-knowledge-store", "gpt3-ai-content-generator"),
      needsDimension: false,
    },
    pinecone: {
      key: "pinecone",
      listAction: "aipkit_list_indexes_pinecone",
      listKey: "indexes",
      createAction: "aipkit_create_index_pinecone",
      createKey: "index",
      deleteAction: "aipkit_delete_index_pinecone",
      deleteParam: "index_name",
      deleteLabel: __("Delete index", "gpt3-ai-content-generator"),
      namePlaceholder: __("my-knowledge-index", "gpt3-ai-content-generator"),
      needsDimension: true,
      defaultDimension: 1536,
    },
    qdrant: {
      key: "qdrant",
      listAction: "aipkit_list_collections_qdrant",
      listKey: "collections",
      createAction: "aipkit_create_collection_qdrant",
      createKey: "collection",
      deleteAction: "aipkit_delete_collection_qdrant",
      deleteParam: "collection_name",
      deleteLabel: __("Delete collection", "gpt3-ai-content-generator"),
      namePlaceholder: __("my-knowledge-collection", "gpt3-ai-content-generator"),
      needsDimension: true,
      defaultDimension: 1536,
    },
    chroma: {
      key: "chroma",
      listAction: "aipkit_list_collections_chroma",
      listKey: "collections",
      createAction: "aipkit_create_collection_chroma",
      createKey: "collection",
      deleteAction: "aipkit_delete_collection_chroma",
      deleteParam: "collection_name",
      deleteLabel: __("Delete collection", "gpt3-ai-content-generator"),
      namePlaceholder: __("my-knowledge-collection", "gpt3-ai-content-generator"),
      needsDimension: false,
    },
  };

  const PROVIDER_NONCE_IDS = {
    local: "aipkit_vector_store_local_nonce_management",
    openai: "aipkit_vector_store_nonce_openai",
    google: "aipkit_google_file_search_nonce",
    pinecone: "aipkit_vector_store_pinecone_nonce_management",
    qdrant: "aipkit_vector_store_qdrant_nonce_management",
    chroma: "aipkit_vector_store_chroma_nonce_management",
  };

  const SOURCES_FILTERS_STORAGE_KEY = "aipkit_sources_filters_v1";
  const SOURCE_ROWS_PER_PAGE_OPTIONS = [10, 25, 50, 100];
  const DEFAULT_SOURCE_ROWS_PER_PAGE = 10;

  function normalizeRowsPerPage(value) {
    const parsed = parseInt(value, 10);
    return SOURCE_ROWS_PER_PAGE_OPTIONS.includes(parsed)
      ? parsed
      : DEFAULT_SOURCE_ROWS_PER_PAGE;
  }

  function loadStoredFilters() {
    try {
      const raw = window.localStorage.getItem(SOURCES_FILTERS_STORAGE_KEY);
      if (!raw) {
        return {};
      }
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function saveStoredFilters(nextFilters) {
    try {
      window.localStorage.setItem(
        SOURCES_FILTERS_STORAGE_KEY,
        JSON.stringify(nextFilters)
      );
    } catch (error) {
      // Local storage can be blocked; ignore quietly.
    }
  }

  function truncateText(text, maxLength) {
    if (!text) {
      return "";
    }
    return text.length > maxLength ? `${text.slice(0, maxLength)}...` : text;
  }

  function normalizeSourcePreview(value) {
    const decoder = document.createElement("textarea");
    decoder.innerHTML = String(value || "");

    return decoder.value
      .replace(/^\s{0,3}#{1,6}\s+/gm, "")
      .replace(/\s+/g, " ")
      .trim();
  }

  function normalizeProviderKey(value) {
    return String(value || "").trim().toLowerCase();
  }

  function getProviderConfig(providerKey) {
    return PROVIDER_PANEL_CONFIG[normalizeProviderKey(providerKey)] || null;
  }

  function getIndexTerms(providerKey) {
    const normalized = normalizeProviderKey(providerKey);
    return PROVIDER_INDEX_TERMS[normalized] || PROVIDER_INDEX_TERMS.default;
  }

  function getProviderStatusMap() {
    const map = window.aipkit_dashboard && window.aipkit_dashboard.providerStatus;
    return map && typeof map === "object" ? map : {};
  }

  function getProviderConfigured(providerKey) {
    const normalizedKey = normalizeProviderKey(providerKey);
    if (normalizedKey === "local") {
      // site knowledge bases live in this site's database: nothing to connect.
      return true;
    }
    const statusMap = getProviderStatusMap();
    if (!Object.prototype.hasOwnProperty.call(statusMap, normalizedKey)) {
      return null;
    }
    return Boolean(statusMap[normalizedKey]);
  }

  function getProviderStatusMeta(providerKey) {
    const configured = getProviderConfigured(providerKey);
    if (configured === true) {
      return {
        label: __("Connected", "gpt3-ai-content-generator"),
        className: "aipkit_status-success",
      };
    }
    if (configured === false) {
      return {
        label: __("Needs setup", "gpt3-ai-content-generator"),
        className: "aipkit_status-warning",
      };
    }
    return {
      label: __("Unknown", "gpt3-ai-content-generator"),
      className: "aipkit_status-info",
    };
  }

  const {
    getProviderStoreId, getProviderStoreName, getStoreStatusMeta, renderStoreDetails,
  } = createKnowledgeBaseStorePresentation({ __, _n, sprintf, escaper, normalizeProviderKey });

  function getSharedVectorStoreCacheKey(providerKey) {
    const normalizedKey = normalizeProviderKey(providerKey);
    if (normalizedKey === "openai") {
      return "openaiVectorStores";
    }
    if (normalizedKey === "google") {
      return "googleFileSearchStores";
    }
    if (normalizedKey === "pinecone") {
      return "pineconeIndexes";
    }
    if (normalizedKey === "qdrant") {
      return "qdrantCollections";
    }
    if (normalizedKey === "chroma") {
      return "chromaCollections";
    }
    if (normalizedKey === "local") {
      return "localVectorStores";
    }
    return "";
  }

  function syncSharedVectorStoreCache(providerKey, stores) {
    const cacheKey = getSharedVectorStoreCacheKey(providerKey);
    if (!cacheKey) {
      return;
    }

    if (!window.aipkit_chat_config || typeof window.aipkit_chat_config !== "object") {
      window.aipkit_chat_config = {};
    }
    const nextStores = Array.isArray(stores) ? stores : [];
    window.aipkit_chat_config[cacheKey] = nextStores;

    if (typeof window.aipkit_invalidateModuleCache === "function") {
      window.aipkit_invalidateModuleCache("chatbot");
    }

    window.dispatchEvent(
      new CustomEvent("aipkit:vector-store-list-updated", {
        detail: {
          provider: normalizeProviderKey(providerKey),
          stores: nextStores,
        },
      })
    );
  }

  const {
    isQaTextSource, getContentTypeMeta, getStatusMeta, getSourceDisplay,
    getSourceChunkMeta, isUserUploadSource, getIndexLabel, getSourceDetailItems,
  } = createKnowledgeBaseSourceRecords({ __, normalizeProviderKey });
  const { renderSourceAction, renderSourceTargetAttributes, renderSourceActionMenu } =
    createSourceActionMenu({ __, escaper });

  function getProviderLabel(providerValue) {
    const normalized = normalizeProviderKey(providerValue);
    if (!normalized) {
      return "—";
    }
    const labels = {
      local: "Local",
      openai: "OpenAI",
      google: "Google",
      pinecone: "Pinecone",
      qdrant: "Qdrant",
      chroma: "Chroma",
    };
    return labels[normalized] || String(providerValue);
  }

  function getProviderIconClass(providerValue) {
    const normalized = normalizeProviderKey(providerValue);
    if (!normalized) {
      return "";
    }
    const supported = ["local", "openai", "google", "pinecone", "qdrant", "chroma"];
    return supported.includes(normalized)
      ? `aipkit_settings_select_picker_icon--${normalized}`
      : "";
  }

  function renderSourceDetailItems(items) {
    if (!Array.isArray(items) || !items.length) {
      return "";
    }

    const title = items
      .map((item) => `${item.label || ""}: ${item.value || "—"}`)
      .join(" · ");
    const values = items
      .map((item, index) => {
        const value = item.value || "—";
        const role = item.role ? ` aipkit_sources_source_meta_value--${item.role}` : "";
        return `${index ? '<span class="aipkit_sources_source_meta_sep" aria-hidden="true"></span>' : ""}
          <span class="aipkit_sources_source_meta_value${role}">${escaper(
          truncateText(value, 46)
        )}</span>`;
      })
      .join("");

    return `<div class="aipkit_sources_source_meta_line" title="${escaper(title)}">
      <span class="aipkit_sources_source_meta_text">${values}</span>
    </div>`;
  }

  function renderSourcePreview(log, snippetText) {
    if (typeof window.aipkit_renderSourcePreview === "function") {
      return window.aipkit_renderSourcePreview(log, snippetText);
    }
    return `<div class="aipkit_sources_preview_content"><p>${escaper(
      snippetText || __("No indexed content is available.", "gpt3-ai-content-generator")
    )}</p></div>`;
  }

  function setStatusMessage(statusEl, message, tone) {
    if (!statusEl) {
      return;
    }
    const usesTrainingStyles = statusEl.classList.contains("aipkit_training_status");
    statusEl.textContent = message || "";
    if (usesTrainingStyles) {
      statusEl.classList.remove(
        "is-visible",
        "is-success",
        "is-error",
        "is-warning",
        "is-loading"
      );
      if (message) {
        statusEl.classList.add("is-visible");
      }
      if (tone) {
        statusEl.classList.add(`is-${tone}`);
      }
      return;
    }
    statusEl.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
    if (tone === "success") {
      statusEl.classList.add("aipkit_form-help-success");
    } else if (tone === "error") {
      statusEl.classList.add("aipkit_form-help-error");
    }
  }

  function renderEmpty(tableBody, paginationContainer, message) {
    if (!tableBody) {
      return;
    }
    tableBody.innerHTML = `<tr><td colspan="4" class="aipkit_text-center">${escaper(
      message
    )}</td></tr>`;
    if (paginationContainer) {
      paginationContainer.innerHTML = "";
    }
  }

  function renderRows(tableBody, logs, options = {}) {
    if (!tableBody) {
      return;
    }
    if (!Array.isArray(logs) || !logs.length) {
      renderEmpty(tableBody, null, __("No sources found.", "gpt3-ai-content-generator"));
      return;
    }

    const selectedLogIds =
      options.selectedLogIds instanceof Set ? options.selectedLogIds : new Set();
    const isBulkDeleting = Boolean(options.isBulkDeleting);
    const rows = logs
      .map((log) => {
        const contentType = getContentTypeMeta(log);
        const sourceTypeIcon =
          contentType.key === "site"
            ? "dashicons-admin-site-alt3"
            : contentType.key === "file"
              ? "dashicons-media-document"
              : "dashicons-format-chat";
        const statusMeta = getStatusMeta(log.status);
        const isProcessing = log.status === "processing" || log.status === "queued";
        const updatedMeta = getSourceUpdatedMeta(log);
        const providerLabel = getProviderLabel(log.provider);
        const indexLabel = getIndexLabel(log);
        const sourceDisplay = normalizeSourcePreview(getSourceDisplay(log)) || "—";
        const truncatedSource = truncateText(sourceDisplay, MAX_SOURCE_LENGTH);
        const chunkMeta = getSourceChunkMeta(log);
        const isUserUpload = isUserUploadSource(log);
        const sourceTitle = [
          sourceDisplay,
          chunkMeta ? chunkMeta.label : "",
          isUserUpload ? __("User upload", "gpt3-ai-content-generator") : "",
        ]
          .filter(Boolean)
          .join(" · ");
        const logId = String(log.id || "");
        const storeId = String(log.vector_store_id || "");
        const vectorId = String(log.file_id || "");
        const provider = String(log.provider || "");
        const isFailed = String(log.status || "").toLowerCase() === "failed";
        const isDeletable = Boolean(
          !isProcessing && provider && storeId && logId && (vectorId || isFailed)
        );
        const isSelected = isDeletable && selectedLogIds.has(logId);
        const selectionControl = isDeletable
          ? `<label class="aipkit_sources_row_select">
              <input
                type="checkbox"
                class="aipkit_sources_row_checkbox"
                data-provider="${escaper(provider)}"
                data-store-id="${escaper(storeId)}"
                data-vector-id="${escaper(vectorId)}"
                data-log-id="${escaper(logId)}"
                data-log-status="${escaper(log.status || "")}"
                aria-label="${escaper(__("Select source", "gpt3-ai-content-generator"))}"
                ${isSelected ? "checked" : ""}
                ${isBulkDeleting ? 'disabled aria-disabled="true"' : ""}
              />
            </label>`
          : '<span class="aipkit_sources_row_select_placeholder" aria-hidden="true"></span>';
        const sourceDetails = renderSourceDetailItems(
          getSourceDetailItems(log, providerLabel, indexLabel)
        );
        const snippet = log.indexed_content || "";
        const failureReason = isFailed ? String(log.message || "").trim() : "";
        const actions = [];

        if (snippet) {
          actions.push(
            renderSourceAction(
              `data-snippet="${escaper(snippet)}"
                data-log-id="${escaper(logId)}"`,
              "aipkit_sources_action_view",
              "dashicons-visibility",
              __("View", "gpt3-ai-content-generator"),
              isProcessing
            )
          );
        }

        if (
          contentType.key === "site" &&
          ["openai", "google", "pinecone", "qdrant", "chroma", "local"].includes(
            normalizeProviderKey(log.provider || "")
          ) &&
          log.post_id &&
          log.vector_store_id &&
          log.file_id
        ) {
          actions.push(
            renderSourceAction(
              `${renderSourceTargetAttributes(log)}
                data-post-id="${escaper(log.post_id || "")}"
                data-embedding-provider="${escaper(log.embedding_provider || "")}"
                data-embedding-model="${escaper(log.embedding_model || "")}"`,
              "aipkit_sources_action_retrain",
              "dashicons-update",
              isProcessing
                ? __("Updating...", "gpt3-ai-content-generator")
                : __("Update", "gpt3-ai-content-generator"),
              isProcessing
            )
          );
        }

        if (contentType.key === "text" && snippet && log.vector_store_id && log.file_id) {
          actions.push(
            renderSourceAction(
              `${renderSourceTargetAttributes(log)}
                data-embedding-provider="${escaper(log.embedding_provider || "")}"
                data-embedding-model="${escaper(log.embedding_model || "")}"
                data-source-kind="${isQaTextSource(log) ? "qa" : "text"}"
                data-content="${escaper(encodeURIComponent(snippet))}"`,
              "aipkit_sources_action_edit",
              "dashicons-edit",
              isProcessing
                ? __("Training...", "gpt3-ai-content-generator")
                : __("Edit", "gpt3-ai-content-generator"),
              isProcessing
            )
          );
        }

        if (isDeletable) {
          const deleteDividerClass = actions.length
            ? " aipkit_sources_action_menu_item--separated"
            : "";
          actions.push(
            renderSourceAction(
              `${renderSourceTargetAttributes(log)}
                data-log-status="${escaper(log.status || "")}"`,
              `aipkit_sources_action_menu_item--danger${deleteDividerClass} aipkit_sources_action_delete`,
              "dashicons-trash",
              __("Delete", "gpt3-ai-content-generator"),
              isProcessing
            )
          );
        }

        const actionMenu = renderSourceActionMenu(actions);

        return `<tr data-log-id="${escaper(logId)}"${isSelected ? ' class="is-selected"' : ""}>
          <td class="aipkit_sources_status_cell">
            <div class="aipkit_sources_status_wrap">
              ${selectionControl}
              <span class="aipkit_status-tag ${escaper(
                statusMeta.className
              )}">${escaper(statusMeta.label)}</span>
              ${
                failureReason
                  ? `<button type="button" class="aipkit_sources_status_reason aipkit_sources_action_reason"
                      data-reason="${escaper(failureReason)}"
                      data-log-id="${escaper(logId)}"
                      title="${escaper(__("View failure reason", "gpt3-ai-content-generator"))}"
                      aria-label="${escaper(__("View failure reason", "gpt3-ai-content-generator"))}"
                    ><span class="dashicons dashicons-warning" aria-hidden="true"></span></button>`
                  : ""
              }
            </div>
          </td>
          <td class="aipkit_sources_source_cell" title="${escaper(sourceTitle)}">
            <div class="aipkit_sources_source_identity">
              <span class="aipkit_sources_source_icon dashicons ${escaper(
                sourceTypeIcon
              )}" role="img" aria-label="${escaper(
                contentType.label
              )}" title="${escaper(contentType.label)}"></span>
              <div class="aipkit_sources_source_stack">
                <span class="aipkit_sources_source_title_line">
                  <span class="aipkit_sources_source_title">${escaper(
                    truncatedSource
                  )}</span>
                  ${
                    chunkMeta
                      ? `<span class="aipkit_sources_chunk_badge">${escaper(
                          chunkMeta.label
                        )}</span>`
                      : ""
                  }
                  ${
                    isUserUpload
                      ? `<span class="aipkit_sources_user_upload_badge">${escaper(
                          __("User upload", "gpt3-ai-content-generator")
                        )}</span>`
                      : ""
                  }
                </span>
                ${
                  sourceDetails
                    ? `<div class="aipkit_sources_source_meta">${sourceDetails}</div>`
                    : ""
                }
              </div>
            </div>
          </td>
          <td class="aipkit_sources_time_cell" title="${escaper(updatedMeta.title)}">
            <span class="aipkit_sources_time_value">${escaper(
              updatedMeta.label
            )}</span>
          </td>
          <td class="aipkit_actions_cell aipkit_sources_actions_cell">
            ${actionMenu}
          </td>
        </tr>`;
      })
      .join("");
    tableBody.innerHTML = rows;
  }

  function renderPagination(
    paginationData,
    paginationContainer,
    onPageChange,
    options = {}
  ) {
    if (!paginationContainer) {
      return;
    }
    paginationContainer.innerHTML = "";

    const totalLogs = Number(paginationData?.total_logs || 0);
    const totalPages = Number(paginationData?.total_pages || 0);
    const currentPage = Number(paginationData?.current_page || 1);
    const cursorMode = Boolean(paginationData?.cursor_mode);
    const hasPrevious = Boolean(paginationData?.has_previous) || currentPage > 1;
    const hasMore = Boolean(paginationData?.has_more);
    const itemCount = Number(paginationData?.item_count || 0);

    if ((!cursorMode && !totalLogs) || (cursorMode && !itemCount && currentPage <= 1)) {
      return;
    }

    if (!cursorMode) {
      const countSpan = document.createElement("span");
      countSpan.className = "aipkit_pagination-count";
      countSpan.textContent = `${totalLogs.toLocaleString()} ${_n(
        "source",
        "sources",
        totalLogs,
        "gpt3-ai-content-generator"
      )}`;
      paginationContainer.appendChild(countSpan);
    }

    const controlsSpan = document.createElement("span");
    controlsSpan.className = "aipkit_sources_pagination_controls";

    if (typeof options.onPerPageChange === "function") {
      const perPageLabel = document.createElement("label");
      perPageLabel.className = "aipkit_sources_per_page";
      perPageLabel.appendChild(
        document.createTextNode(__("Rows per page", "gpt3-ai-content-generator"))
      );

      const perPageSelect = document.createElement("select");
      perPageSelect.className = "aipkit_sources_per_page_select";
      SOURCE_ROWS_PER_PAGE_OPTIONS.forEach((value) => {
        const option = document.createElement("option");
        option.value = String(value);
        option.textContent = String(value);
        perPageSelect.appendChild(option);
      });
      perPageSelect.value = String(
        normalizeRowsPerPage(options.perPage || DEFAULT_SOURCE_ROWS_PER_PAGE)
      );
      perPageSelect.addEventListener("change", () => {
        options.onPerPageChange(normalizeRowsPerPage(perPageSelect.value));
      });

      perPageLabel.appendChild(perPageSelect);
      controlsSpan.appendChild(perPageLabel);
    }

    if ((cursorMode && (hasPrevious || hasMore)) || (!cursorMode && totalPages > 1)) {
      const linksSpan = document.createElement("span");
      linksSpan.className = "aipkit_pagination-links";

      const prevBtn = document.createElement("button");
      prevBtn.type = "button";
      prevBtn.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination_prev";
      prevBtn.textContent = __("Prev", "gpt3-ai-content-generator");
      prevBtn.disabled = cursorMode ? !hasPrevious : currentPage <= 1;
      prevBtn.addEventListener("click", () => onPageChange(currentPage - 1));

      const currentSpan = document.createElement("span");
      currentSpan.className = "aipkit_pagination-current";
      currentSpan.textContent = cursorMode
        ? `${__("Page", "gpt3-ai-content-generator")} ${currentPage}`
        : `${currentPage}/${totalPages}`;

      const nextBtn = document.createElement("button");
      nextBtn.type = "button";
      nextBtn.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination_next";
      nextBtn.textContent = __("Next", "gpt3-ai-content-generator");
      nextBtn.disabled = cursorMode ? !hasMore : currentPage >= totalPages;
      nextBtn.addEventListener("click", () => onPageChange(currentPage + 1));

      linksSpan.appendChild(prevBtn);
      linksSpan.appendChild(currentSpan);
      linksSpan.appendChild(nextBtn);
      controlsSpan.appendChild(linksSpan);
    }

    if (controlsSpan.childNodes.length) {
      paginationContainer.appendChild(controlsSpan);
    }
  }

  function aipkit_initSources() {
    const moduleContainer = document.getElementById("aipkit_sources_module_container");
    if (!moduleContainer) {
      return;
    }

    const tableBody = document.getElementById("aipkit_sources_table_body");
    const paginationContainer = document.getElementById("aipkit_sources_pagination");
    const statusEl = document.getElementById("aipkit_sources_status");
    const providerSelect = document.getElementById("aipkit_sources_provider_filter");
    const indexSelect = document.getElementById("aipkit_sources_index_filter");
    const searchInput = document.getElementById("aipkit_sources_search_input");
    const editorModal = document.getElementById("aipkit_sources_editor_modal");
    const sourceEditor = createSourceEditor({ editorModal, __ });
    const { editorTextarea, editorQuestion, editorAnswer, editorSave } = sourceEditor.fields;
    const viewModal = document.getElementById("aipkit_sources_view_modal");
    const viewPreview = viewModal
      ? viewModal.querySelector(".aipkit_sources_view_preview")
      : null;
    const viewClose = viewModal
      ? viewModal.querySelector(".aipkit_sources_view_close")
      : null;
    const viewCloseBtn = viewModal
      ? viewModal.querySelector(".aipkit_sources_view_close_btn")
      : null;
    const bulkBar = document.getElementById("aipkit_sources_bulk_bar");
    const bulkCount = document.getElementById("aipkit_sources_bulk_count");
    const bulkProgress = document.getElementById("aipkit_sources_bulk_progress");
    const bulkDeleteButton = bulkBar
      ? bulkBar.querySelector(".aipkit_sources_bulk_delete")
      : null;
    const bulkRetryButton = bulkBar
      ? bulkBar.querySelector(".aipkit_sources_bulk_retry")
      : null;
    const bulkClearButton = bulkBar
      ? bulkBar.querySelector(".aipkit_sources_bulk_clear")
      : null;
    const selectAllCheckbox = document.getElementById("aipkit_sources_select_all");
    const workspaceTabs = moduleContainer.querySelectorAll(
      ".aipkit_sources_workspace_tab"
    );
    const workspaceTools = moduleContainer.querySelectorAll(
      "[data-aipkit-sources-tools]"
    );
    const workspacePanels = moduleContainer.querySelectorAll(
      ".aipkit_sources_workspace_panel"
    );
    const trainingPanel = document.getElementById("aipkit_sources_training_panel");
    const trainingCloseButton = trainingPanel
      ? trainingPanel.querySelector(".aipkit_sources_training_close")
      : null;
    const trainingCancelButton = trainingPanel
      ? trainingPanel.querySelector(".aipkit_sources_training_cancel")
      : null;
    const trainingDiscardPrompt = trainingPanel
      ? trainingPanel.querySelector("#aipkit_sources_training_discard_prompt")
      : null;
    const trainingDiscardTitle = trainingDiscardPrompt
      ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_title")
      : null;
    const trainingDiscardMessage = trainingDiscardPrompt
      ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_message")
      : null;
    const trainingDiscardKeepButton = trainingDiscardPrompt
      ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_keep")
      : null;
    const trainingDiscardConfirmButton = trainingDiscardPrompt
      ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_confirm")
      : null;
    const trainingProviderSelect = document.getElementById(
      "aipkit_sources_training_provider"
    );
    const trainingStoreSelect = document.getElementById(
      "aipkit_sources_training_store"
    );
    const trainingStoreEmpty = document.getElementById(
      "aipkit_sources_training_store_empty"
    );
    const trainingRefreshStoresButton = document.getElementById(
      "aipkit_sources_training_refresh_stores"
    );
    const trainingCreateStoreButton = document.getElementById(
      "aipkit_sources_training_create_store"
    );
    const trainingCreateStoreSection = document.getElementById(
      "aipkit_sources_training_create_store_section"
    );
    const trainingCreateStorePanel = document.getElementById(
      "aipkit_sources_training_create_store_panel"
    );
    const trainingCreateStoreName = document.getElementById(
      "aipkit_sources_training_create_store_name"
    );
    const trainingCreateStoreDimensionRow = document.getElementById(
      "aipkit_sources_training_create_store_dimension_row"
    );
    const trainingCreateStoreDimension = document.getElementById(
      "aipkit_sources_training_create_store_dimension"
    );
    const trainingCreateStoreStatus = document.getElementById(
      "aipkit_sources_training_create_store_status"
    );
    const trainingCreateStoreSubmit = document.getElementById(
      "aipkit_sources_training_create_store_submit"
    );
    const storeProviderFilter = document.getElementById(
      "aipkit_sources_store_provider_filter"
    );
    const storesRefreshButton = document.getElementById(
      "aipkit_sources_stores_refresh"
    );
    const createStoreButton = document.getElementById("aipkit_sources_create_store");
    const storesTableBody = document.getElementById(
      "aipkit_sources_stores_table_body"
    );
    const storeModal = document.getElementById("aipkit_sources_store_modal");
    const storeModalClose = storeModal
      ? storeModal.querySelector(".aipkit_sources_store_modal_close")
      : null;
    const storeModalCancel = storeModal
      ? storeModal.querySelector(".aipkit_sources_store_modal_cancel")
      : null;
    const storeModalCreate = storeModal
      ? storeModal.querySelector(".aipkit_sources_store_modal_create")
      : null;
    const storeModalProvider = document.getElementById(
      "aipkit_sources_store_modal_provider"
    );
    const storeModalName = document.getElementById(
      "aipkit_sources_store_modal_name"
    );
    const storeModalDimensionRow = document.getElementById(
      "aipkit_sources_store_modal_dimension_row"
    );
    const storeModalDimension = document.getElementById(
      "aipkit_sources_store_modal_dimension"
    );
    const storeModalStatus = document.getElementById(
      "aipkit_sources_store_modal_status"
    );
    if (!tableBody || typeof window.aipkit_apiRequest !== "function") {
      return;
    }

    const storedFilters = loadStoredFilters();
    const storedIndexByProvider =
      storedFilters.indexByProvider && typeof storedFilters.indexByProvider === "object"
        ? { ...storedFilters.indexByProvider }
        : {};
    const storedEmbeddingByProvider =
      storedFilters.embeddingByProvider &&
      typeof storedFilters.embeddingByProvider === "object"
        ? { ...storedFilters.embeddingByProvider }
        : {};
    if (providerSelect && typeof storedFilters.provider === "string") {
      const providerExists = Array.from(providerSelect.options).some(
        (option) => option.value === storedFilters.provider
      );
      if (providerExists) {
        providerSelect.value = storedFilters.provider;
      }
    }
    if (typeof window.aipkit_initChatSelectPickers === "function") {
      window.aipkit_initChatSelectPickers();
    }

    let statusTimer = null;
    let providerStatusTimer = null;
    let searchTimer = null;
    const state = {
      page: 1,
      perPage: normalizeRowsPerPage(storedFilters.perPage),
      provider: providerSelect ? providerSelect.value : "",
      search: searchInput ? searchInput.value.trim() : "",
      indexByProvider: storedIndexByProvider,
      embeddingByProvider: storedEmbeddingByProvider,
      sourceLogsById: new Map(),
      pageCursors: [null],
    };
    const persistSourcePreferences = () => {
      saveStoredFilters({
        provider: state.provider,
        indexByProvider: state.indexByProvider,
        embeddingByProvider: state.embeddingByProvider,
        perPage: state.perPage,
      });
    };
    const providerState = {
      activeProvider: "",
      stores: {},
      syncTokens: {},
      syncErrors: {},
    };
    Object.keys(PROVIDER_PANEL_CONFIG).forEach((providerKey) => {
      const cacheKey = getSharedVectorStoreCacheKey(providerKey);
      const cachedStores = cacheKey ? window.aipkit_chat_config?.[cacheKey] : null;
      if (Array.isArray(cachedStores)) {
        providerState.stores[providerKey] = cachedStores;
      }
    });
    let trainingControls = null;
    const clearStatusSoon = () => {
      if (statusTimer) {
        window.clearTimeout(statusTimer);
      }
      statusTimer = window.setTimeout(() => {
        setStatusMessage(statusEl, "", "");
      }, 3000);
    };
    let openActionMenu = null;
    const selectedSources = new Map();
    let failedBulkSources = new Map();
    let isBulkDeleting = false;
    let bulkStatusTimer = null;
    const closeSourceActionMenu = () => {
      if (!openActionMenu) {
        return;
      }
      const { menu, panel, trigger } = openActionMenu;
      if (panel) {
        panel.hidden = true;
        panel.style.left = "";
        panel.style.top = "";
      }
      if (trigger) {
        trigger.setAttribute("aria-expanded", "false");
      }
      if (menu) {
        menu.classList.remove("is-open");
      }
      openActionMenu = null;
    };

    const positionSourceActionMenu = (trigger, panel) => {
      if (!trigger || !panel) {
        return;
      }
      const triggerRect = trigger.getBoundingClientRect();
      const panelWidth = panel.offsetWidth || 160;
      const panelHeight = panel.offsetHeight || 120;
      const edgeGap = 12;
      const verticalGap = 6;
      const maxLeft = Math.max(edgeGap, window.innerWidth - panelWidth - edgeGap);
      const left = Math.min(maxLeft, Math.max(edgeGap, triggerRect.right - panelWidth));
      let top = triggerRect.bottom + verticalGap;

      if (top + panelHeight > window.innerHeight - edgeGap) {
        top = Math.max(edgeGap, triggerRect.top - panelHeight - verticalGap);
      }

      panel.style.left = `${Math.round(left)}px`;
      panel.style.top = `${Math.round(top)}px`;
    };

    const openSourceActionMenu = (trigger) => {
      if (!trigger || trigger.disabled) {
        return;
      }
      const menu = trigger.closest(".aipkit_sources_actions");
      const panel = menu ? menu.querySelector(".aipkit_sources_action_menu_panel") : null;
      if (!menu || !panel) {
        return;
      }
      if (openActionMenu && openActionMenu.trigger === trigger) {
        closeSourceActionMenu();
        return;
      }

      closeSourceActionMenu();
      panel.hidden = false;
      menu.classList.add("is-open");
      trigger.setAttribute("aria-expanded", "true");
      positionSourceActionMenu(trigger, panel);
      openActionMenu = { menu, panel, trigger };
    };

    const formatSelectedCount = (count) =>
      sprintf(
        _n("%s selected", "%s selected", count, "gpt3-ai-content-generator"),
        count
      );

    const formatDeletedCount = (count) =>
      sprintf(
        _n(
          "Deleted %s source.",
          "Deleted %s sources.",
          count,
          "gpt3-ai-content-generator"
        ),
        count
      );

    const getVisibleSourceCheckboxes = () =>
      tableBody
        ? Array.from(tableBody.querySelectorAll(".aipkit_sources_row_checkbox"))
        : [];

    const syncSelectAllCheckbox = () => {
      if (!selectAllCheckbox) {
        return;
      }
      const checkboxes = getVisibleSourceCheckboxes().filter(
        (checkbox) => !checkbox.disabled
      );
      const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
      selectAllCheckbox.disabled = isBulkDeleting || !checkboxes.length;
      selectAllCheckbox.checked = Boolean(
        checkboxes.length && checkedCount === checkboxes.length
      );
      selectAllCheckbox.indeterminate = Boolean(
        checkedCount > 0 && checkedCount < checkboxes.length
      );
    };

    const setBulkBarState = (message = "", options = {}) => {
      if (!bulkBar) {
        return;
      }
      const count = selectedSources.size;
      const hasMessage = Boolean(message);
      if (!count && !hasMessage) {
        bulkBar.hidden = true;
        if (bulkProgress) {
          bulkProgress.textContent = "";
        }
        syncSelectAllCheckbox();
        return;
      }

      bulkBar.hidden = false;
      if (bulkCount) {
        bulkCount.textContent = Object.prototype.hasOwnProperty.call(
          options,
          "countLabel"
        )
          ? options.countLabel
          : formatSelectedCount(count);
      }
      if (bulkProgress) {
        bulkProgress.textContent = message;
      }
      if (bulkDeleteButton) {
        bulkDeleteButton.hidden = options.showDelete === false || !count;
        bulkDeleteButton.disabled = isBulkDeleting || !count;
      }
      if (bulkRetryButton) {
        bulkRetryButton.hidden = !options.showRetry;
        bulkRetryButton.disabled = isBulkDeleting || !failedBulkSources.size;
      }
      if (bulkClearButton) {
        bulkClearButton.hidden = Object.prototype.hasOwnProperty.call(
          options,
          "showClear"
        )
          ? !options.showClear
          : !count;
        bulkClearButton.disabled = isBulkDeleting;
      }
      syncSelectAllCheckbox();
    };

    const clearBulkBarSoon = () => {
      if (bulkStatusTimer) {
        window.clearTimeout(bulkStatusTimer);
      }
      bulkStatusTimer = window.setTimeout(() => {
        if (!isBulkDeleting && !selectedSources.size && !failedBulkSources.size) {
          setBulkBarState("");
        }
      }, 3000);
    };

    const getSourceFromCheckbox = (checkbox) => {
      if (!checkbox) {
        return null;
      }
      const provider = checkbox.dataset.provider || "";
      const storeId = checkbox.dataset.storeId || "";
      const vectorId = checkbox.dataset.vectorId || "";
      const logId = checkbox.dataset.logId || "";
      const logStatus = String(checkbox.dataset.logStatus || "").toLowerCase();
      if (!provider || !storeId || !logId || (!vectorId && logStatus !== "failed")) {
        return null;
      }
      return { provider, storeId, vectorId, logId };
    };

    const updateRowSelectedState = (checkbox) => {
      const row = checkbox ? checkbox.closest("tr") : null;
      if (row) {
        row.classList.toggle("is-selected", checkbox.checked);
      }
    };

    const setSourceSelection = (source, isSelected) => {
      if (!source || !source.logId) {
        return;
      }
      if (isSelected) {
        selectedSources.set(source.logId, source);
      } else {
        selectedSources.delete(source.logId);
        failedBulkSources.delete(source.logId);
      }
    };

    const clearBulkSelection = () => {
      selectedSources.clear();
      failedBulkSources.clear();
      if (bulkStatusTimer) {
        window.clearTimeout(bulkStatusTimer);
        bulkStatusTimer = null;
      }
      getVisibleSourceCheckboxes().forEach((checkbox) => {
        checkbox.checked = false;
        updateRowSelectedState(checkbox);
      });
      setBulkBarState("");
    };

    const setSourceRowsLocked = (isLocked) => {
      if (!tableBody) {
        return;
      }
      tableBody.querySelectorAll(SOURCE_ROW_CONTROL_SELECTOR).forEach((control) => {
        control.disabled = isLocked;
        if (isLocked) {
          control.setAttribute("aria-disabled", "true");
        } else {
          control.removeAttribute("aria-disabled");
        }
      });
      if (selectAllCheckbox) {
        selectAllCheckbox.disabled = isLocked;
      }
    };

    const getActiveIndexFilter = () => {
      if (!indexSelect || indexSelect.disabled) {
        return "";
      }
      return indexSelect.value || "";
    };

    const setIndexSelectVisible = (isVisible) => {
      if (!indexSelect) {
        return;
      }
      indexSelect.hidden = !isVisible;
    };

    const setIndexSelectDisabled = (isDisabled) => {
      if (!indexSelect) {
        return;
      }
      indexSelect.disabled = isDisabled;
    };

    const updateIndexSelectOptions = (providerKey, stores, options = {}) => {
      if (!indexSelect) {
        return;
      }
      const normalizedKey = normalizeProviderKey(providerKey);
      const previousStored = normalizedKey
        ? state.indexByProvider[normalizedKey] || ""
        : "";
      const terms = getIndexTerms(normalizedKey);
      const isVisible = Boolean(normalizedKey);
      setIndexSelectVisible(isVisible);

      indexSelect.innerHTML = "";
      if (!isVisible) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = terms.plural;
        indexSelect.appendChild(option);
        indexSelect.value = "";
        setIndexSelectDisabled(true);
        return;
      }

      if (options.loading) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = terms.loading;
        option.disabled = true;
        indexSelect.appendChild(option);
        indexSelect.value = "";
        setIndexSelectDisabled(true);
        return;
      }

      const list = Array.isArray(stores) ? stores : [];
      if (!list.length) {
        const emptyOption = document.createElement("option");
        emptyOption.value = "";
        emptyOption.textContent =
          options.emptyLabel || terms.empty;
        emptyOption.disabled = true;
        indexSelect.appendChild(emptyOption);
        indexSelect.value = "";
        setIndexSelectDisabled(true);
        return;
      }

      const allOption = document.createElement("option");
      allOption.value = "";
      allOption.textContent = sprintf(
        __("All %s", "gpt3-ai-content-generator"),
        terms.plural.toLowerCase()
      );
      indexSelect.appendChild(allOption);

      list.forEach((store) => {
        const storeId = getProviderStoreId(normalizedKey, store);
        const storeName = getProviderStoreName(normalizedKey, store);
        if (!storeId) {
          return;
        }
        const option = document.createElement("option");
        option.value = storeId;
        option.textContent = storeName || storeId;
        indexSelect.appendChild(option);
      });

      const storedIndex = state.indexByProvider[normalizedKey] || "";
      const hasStoredIndex = storedIndex
        ? list.some(
            (store) => getProviderStoreId(normalizedKey, store) === storedIndex
          )
        : false;
      if (storedIndex && !hasStoredIndex) {
        state.indexByProvider[normalizedKey] = "";
      }
      indexSelect.value = hasStoredIndex ? storedIndex : "";
      setIndexSelectDisabled(false);

      if (normalizedKey && previousStored !== state.indexByProvider[normalizedKey]) {
        persistSourcePreferences();
      }
    };

    const getProviderPanelStatusElement = () => {
      if (statusEl) {
        return statusEl;
      }
      return null;
    };

    const setProviderPanelStatus = (message, tone) => {
      const statusElement = getProviderPanelStatusElement();
      setStatusMessage(statusElement, message, tone);
    };

    const clearProviderPanelStatusSoon = () => {
      if (providerStatusTimer) {
        window.clearTimeout(providerStatusTimer);
      }
      providerStatusTimer = window.setTimeout(() => {
        setProviderPanelStatus("", "");
      }, 3000);
    };

    const closeViewModal = () => {
      if (!viewModal) {
        return;
      }
      viewModal.classList.remove("aipkit-active");
      viewModal.setAttribute("aria-hidden", "true");
      if (viewPreview) {
        viewPreview.innerHTML = "";
      }
    };

    const openViewModal = (snippetText, log = null) => {
      if (!viewModal || !viewPreview) {
        return;
      }
      viewPreview.innerHTML = renderSourcePreview(log, snippetText);
      viewModal.classList.add("aipkit-active");
      viewModal.setAttribute("aria-hidden", "false");
      if (viewClose) {
        viewClose.focus();
      }
    };

    const getLogForActionButton = (button) => {
      const row = button ? button.closest("tr[data-log-id]") : null;
      const logId = String(button?.dataset?.logId || row?.dataset?.logId || "");
      return logId && state.sourceLogsById instanceof Map
        ? state.sourceLogsById.get(logId) || null
        : null;
    };

    const getNonceValue = (nonceId) => {
      const element = document.getElementById(nonceId);
      return element ? element.value : "";
    };

    const getProviderNonceValue = (providerKey) => {
      const nonceId = PROVIDER_NONCE_IDS[normalizeProviderKey(providerKey)];
      if (!nonceId) {
        return "";
      }
      return getNonceValue(nonceId);
    };

    const getDefaultTrainingProvider = () => {
      const currentProvider = normalizeProviderKey(
        providerSelect ? providerSelect.value : ""
      );
      if (currentProvider && getProviderConfig(currentProvider)) {
        return currentProvider;
      }
      const firstConfigured = Object.keys(PROVIDER_PANEL_CONFIG).find(
        (providerKey) => getProviderConfigured(providerKey) !== false
      );
      return firstConfigured || "openai";
    };

    const populateTrainingStoreOptions = (providerKey, options = {}) => {
      if (!trainingStoreSelect) {
        return;
      }
      const normalizedKey = normalizeProviderKey(providerKey);
      const terms = getIndexTerms(normalizedKey);
      const previousValue = trainingStoreSelect.value || "";
      trainingStoreSelect.innerHTML = "";

      if (!normalizedKey || !getProviderConfig(normalizedKey)) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = __("Select provider", "gpt3-ai-content-generator");
        trainingStoreSelect.appendChild(option);
        trainingStoreSelect.disabled = true;
        if (trainingStoreEmpty) {
          trainingStoreEmpty.hidden = true;
        }
        return;
      }

      if (options.loading) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = terms.loading;
        trainingStoreSelect.appendChild(option);
        trainingStoreSelect.disabled = true;
        if (trainingStoreEmpty) {
          trainingStoreEmpty.hidden = true;
        }
        return;
      }

      const stores = Array.isArray(providerState.stores[normalizedKey])
        ? providerState.stores[normalizedKey]
        : [];
      if (!stores.length) {
        const option = document.createElement("option");
        option.value = "";
        option.textContent = terms.empty;
        trainingStoreSelect.appendChild(option);
        trainingStoreSelect.disabled = true;
        if (trainingStoreEmpty) {
          trainingStoreEmpty.hidden = false;
        }
        return;
      }

      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = sprintf(
        __("Select %s", "gpt3-ai-content-generator"),
        terms.singular.toLowerCase()
      );
      trainingStoreSelect.appendChild(placeholder);

      stores.forEach((store) => {
        const storeId = getProviderStoreId(normalizedKey, store);
        const storeName = getProviderStoreName(normalizedKey, store);
        if (!storeId) {
          return;
        }
        const option = document.createElement("option");
        option.value = storeId;
        option.textContent = storeName || storeId;
        trainingStoreSelect.appendChild(option);
      });

      const hasPrevious = previousValue
        ? stores.some(
            (store) => getProviderStoreId(normalizedKey, store) === previousValue
          )
        : false;
      trainingStoreSelect.value = hasPrevious
        ? previousValue
        : getProviderStoreId(normalizedKey, stores[0]);
      trainingStoreSelect.disabled = false;
      if (trainingStoreEmpty) {
        trainingStoreEmpty.hidden = true;
      }
    };

    const renderStoresTable = () => {
      if (!storesTableBody) {
        return;
      }

      const selectedProvider = normalizeProviderKey(
        storeProviderFilter ? storeProviderFilter.value : ""
      );
      const providerKeys = selectedProvider
        ? [selectedProvider]
        : Object.keys(PROVIDER_PANEL_CONFIG);
      const rows = [];

      providerKeys.forEach((providerKey) => {
        const config = getProviderConfig(providerKey);
        if (!config) {
          return;
        }
        const stores = Array.isArray(providerState.stores[providerKey])
          ? providerState.stores[providerKey]
          : [];
        const statusMeta = getProviderStatusMeta(providerKey);
        const providerConfigured = getProviderConfigured(providerKey);
        const providerSyncError = providerState.syncErrors[providerKey] || "";
        const providerSyncStatusMeta = providerSyncError
          ? {
              label: __("Connection failed", "gpt3-ai-content-generator"),
              className: "aipkit_status-warning",
            }
          : null;
        const providerIconClass = getProviderIconClass(providerKey);
        const providerIcon = providerIconClass
          ? `<span class="aipkit_settings_select_picker_icon ${escaper(
              providerIconClass
            )}" aria-hidden="true"></span>`
          : "";

        if (providerConfigured === false) {
          const settingsPage =
            providerKey === "openai" || providerKey === "google" ? "ai" : "integrations";
          rows.push(`<tr>
            <td><span class="aipkit_sources_store_provider">${providerIcon}<span>${escaper(
            getProviderLabel(providerKey)
          )}</span></span></td>
            <td class="aipkit_sources_store_name_cell">${escaper(
              __("Add credentials to load stores.", "gpt3-ai-content-generator")
            )}</td>
            <td><span class="aipkit_status-tag ${escaper(
              statusMeta.className
            )}">${escaper(statusMeta.label)}</span></td>
            <td class="aipkit_actions_cell">
              <button
                type="button"
                class="aipkit_sources_store_connect_btn"
                data-provider="${escaper(providerKey)}"
                data-aipkit-open-module="settings"
                data-aipkit-settings-page="${escaper(settingsPage)}"
              >${escaper(__("Connect", "gpt3-ai-content-generator"))}</button>
            </td>
          </tr>`);
          return;
        }

        if (providerSyncError && !stores.length) {
          rows.push(`<tr>
            <td><span class="aipkit_sources_store_provider">${providerIcon}<span>${escaper(
            getProviderLabel(providerKey)
          )}</span></span></td>
            <td class="aipkit_sources_store_name_cell">${escaper(
              providerSyncError
            )}</td>
            <td><span class="aipkit_status-tag ${escaper(
              providerSyncStatusMeta.className
            )}">${escaper(providerSyncStatusMeta.label)}</span></td>
            <td class="aipkit_actions_cell"><span class="aipkit_sources_actions_empty">—</span></td>
          </tr>`);
          return;
        }

        if (providerKey === "local" && !stores.length && !selectedProvider && Array.isArray(providerState.stores.local)) {
          // AI Puffer needs no setup, so offer the first knowledge base right here.
          rows.push(`<tr>
            <td><span class="aipkit_sources_store_provider">${providerIcon}<span>${escaper(
            getProviderLabel(providerKey)
          )}</span></span></td>
            <td class="aipkit_sources_store_name_cell">${escaper(
              __("No knowledge bases yet.", "gpt3-ai-content-generator")
            )}</td>
            <td><span class="aipkit_status-tag aipkit_status-success">${escaper(
              __("Ready", "gpt3-ai-content-generator")
            )}</span></td>
            <td class="aipkit_actions_cell">
              <button type="button" class="aipkit_sources_store_connect_btn aipkit_sources_store_create_local_btn">${escaper(
                __("Create", "gpt3-ai-content-generator")
              )}</button>
            </td>
          </tr>`);
          return;
        }

        stores.forEach((store) => {
          const storeId = getProviderStoreId(providerKey, store);
          if (!storeId) {
            return;
          }
          const storeName = getProviderStoreName(providerKey, store);
          const storeStatusMeta =
            providerSyncStatusMeta || getStoreStatusMeta(providerKey, store, statusMeta);
          const storeDetails = renderStoreDetails(providerKey, store);
          rows.push(`<tr data-provider="${escaper(providerKey)}" data-store-id="${escaper(
            storeId
          )}" data-store-name="${escaper(storeName)}">
            <td><span class="aipkit_sources_store_provider">${providerIcon}<span>${escaper(
            getProviderLabel(providerKey)
          )}</span></span></td>
            <td class="aipkit_sources_store_name_cell">
              <div class="aipkit_sources_store_name_stack">
                <span class="aipkit_sources_store_name">${escaper(storeName)}</span>
                ${storeDetails}
              </div>
            </td>
            <td><span class="aipkit_status-tag ${escaper(
              storeStatusMeta.className
            )}"${providerSyncError ? ` title="${escaper(providerSyncError)}"` : ""}>${escaper(
            storeStatusMeta.label
          )}</span></td>
            <td class="aipkit_actions_cell">
              <button
                type="button"
                class="aipkit_sources_store_delete_btn"
                data-provider="${escaper(providerKey)}"
                data-store-id="${escaper(storeId)}"
                data-store-name="${escaper(storeName)}"
                title="${escaper(config.deleteLabel || __("Delete", "gpt3-ai-content-generator"))}"
                aria-label="${escaper(config.deleteLabel || __("Delete", "gpt3-ai-content-generator"))}"
              >
                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
              </button>
            </td>
          </tr>`);
        });
      });

      if (!rows.length) {
        storesTableBody.innerHTML = `<tr><td colspan="4" class="aipkit_text-center">${escaper(
          selectedProvider
            ? sprintf(
                __("No %s found.", "gpt3-ai-content-generator"),
                getIndexTerms(selectedProvider).plural.toLowerCase()
              )
            : __("No stores found. Refresh providers or create a store.", "gpt3-ai-content-generator")
        )}</td></tr>`;
        return;
      }

      storesTableBody.innerHTML = rows.join("");
    };

    const initSettingsTab = () => {
      if (typeof window.aipkit_initKnowledgeBaseSettingsUI === "function") {
        window.aipkit_initKnowledgeBaseSettingsUI();
      }
      if (typeof window.aipkit_refreshSettingsSelectPickers === "function") {
        window.aipkit_refreshSettingsSelectPickers();
      }
    };

    const initSearchTab = () => {
      if (typeof window.aipkit_initSemanticSearchUI === "function") {
        window.aipkit_initSemanticSearchUI();
      }
      if (typeof window.aipkit_refreshSettingsSelectPickers === "function") {
        window.aipkit_refreshSettingsSelectPickers();
      }
    };

    const setWorkspaceTab = (tabKey) => {
      const nextTab = ["stores", "search", "settings"].includes(tabKey)
        ? tabKey
        : "data";
      moduleContainer.dataset.aipkitSourcesActiveTab = nextTab;
      workspaceTabs.forEach((tab) => {
        const isActive = tab.dataset.aipkitSourcesTab === nextTab;
        tab.classList.toggle("is-active", isActive);
        tab.setAttribute("aria-selected", isActive ? "true" : "false");
      });
      workspaceTools.forEach((tools) => {
        const isActive = tools.dataset.aipkitSourcesTools === nextTab;
        tools.classList.toggle("is-active", isActive);
        tools.hidden = !isActive;
      });
      workspacePanels.forEach((panel) => {
        const isActive = panel.id === `aipkit_sources_${nextTab}_panel`;
        panel.classList.toggle("is-active", isActive);
        panel.hidden = !isActive;
      });
      if (nextTab === "stores") {
        renderStoresTable();
        const hasLoadedAnyProvider = Object.keys(PROVIDER_PANEL_CONFIG).some(
          (providerKey) => Array.isArray(providerState.stores[providerKey])
        );
        if (!hasLoadedAnyProvider) {
          refreshStoreManagementProviders(storesRefreshButton);
        } else if (!Array.isArray(providerState.stores.local)) {
          // site knowledge bases are never in the hosted-store caches; load them on first view.
          syncProviderStores("local", { silent: true }).then(() => renderStoresTable());
        }
      } else if (nextTab === "settings") {
        initSettingsTab();
      } else if (nextTab === "search") {
        initSearchTab();
      }
    };

    const renderProviderStores = (providerKey, stores, options = {}) => {
      const normalizedKey = normalizeProviderKey(providerKey);
      const activeProvider = normalizeProviderKey(
        providerSelect ? providerSelect.value : ""
      );
      if (!normalizedKey) {
        updateIndexSelectOptions("", []);
        populateTrainingStoreOptions("", []);
        renderStoresTable();
        return;
      }
      if (normalizedKey === activeProvider) {
        updateIndexSelectOptions(normalizedKey, stores, {
          loading: options.loading === true,
          emptyLabel: options.emptyLabel,
        });
      }
      if (
        trainingProviderSelect &&
        normalizeProviderKey(trainingProviderSelect.value) === normalizedKey
      ) {
        populateTrainingStoreOptions(normalizedKey, {
          loading: options.loading === true,
        });
      }
      renderStoresTable();
    };

    const setButtonLoading = (button, isLoading, defaultLabel, loadingLabel) => {
      if (!button) {
        return;
      }
      const label = button.querySelector(".aipkit_btn_label");
      button.classList.toggle("is-loading", isLoading);
      if (isLoading) {
        button.disabled = true;
        button.setAttribute("aria-disabled", "true");
        button.setAttribute("aria-busy", "true");
        if (label) {
          label.textContent = loadingLabel;
        } else {
          button.textContent = loadingLabel;
        }
      } else {
        button.disabled = false;
        button.removeAttribute("aria-disabled");
        button.removeAttribute("aria-busy");
        if (label) {
          label.textContent = defaultLabel;
        } else {
          button.textContent = defaultLabel;
        }
      }
    };

    const syncProviderStores = async (providerKey, options = {}) => {
      const config = getProviderConfig(providerKey);
      if (!config) {
        return { success: false, skipped: true };
      }

      const normalizedKey = normalizeProviderKey(providerKey);
      const isSilent = options.silent === true;
      const shouldPropagateError = options.propagateError === true;
      const configured = getProviderConfigured(normalizedKey);
      const nonceValue = configured === false ? null : getProviderNonceValue(normalizedKey);
      if (configured === false || !nonceValue) {
        const message = configured === false
          ? __("Add provider credentials to load stores.", "gpt3-ai-content-generator")
          : __("Provider nonce missing.", "gpt3-ai-content-generator");
        if (!isSilent) {
          setProviderPanelStatus(message, "error");
          clearProviderPanelStatusSoon();
        }
        if (shouldPropagateError) {
          throw new Error(message);
        }
        return { success: false, skipped: true, message };
      }

      // Each refresh needs its own identity, even when started in the same millisecond.
      const token = {};
      providerState.syncTokens[normalizedKey] = token;
      delete providerState.syncErrors[normalizedKey];
      const terms = getIndexTerms(normalizedKey);

      renderProviderStores(normalizedKey, providerState.stores[normalizedKey], {
        loading: true,
      });
      if (!isSilent) {
        setProviderPanelStatus(
          sprintf(
            // translators: %s is the plural provider store type.
            __("Syncing %s...", "gpt3-ai-content-generator"),
            terms.plural.toLowerCase()
          ),
          ""
        );
      }

      const requestData = { _ajax_nonce: nonceValue };
      if (normalizedKey === "openai") {
        requestData.limit = 100;
        requestData.order = "desc";
      }

      const triggerButton = options.triggerButton || null;
      const triggerDefaultLabel = options.triggerDefaultLabel || __(
        "Refresh",
        "gpt3-ai-content-generator"
      );
      const triggerLoadingLabel = options.triggerLoadingLabel || __(
        "Refreshing...",
        "gpt3-ai-content-generator"
      );
      setButtonLoading(
        triggerButton,
        true,
        triggerDefaultLabel,
        triggerLoadingLabel
      );

      try {
        const response = await window.aipkit_apiRequest(
          config.listAction,
          requestData
        );
        if (providerState.syncTokens[normalizedKey] !== token) {
          return { success: false, skipped: true };
        }
        const stores = Array.isArray(response[config.listKey])
          ? response[config.listKey]
          : [];
        providerState.stores[normalizedKey] = stores;
        delete providerState.syncErrors[normalizedKey];
        syncSharedVectorStoreCache(normalizedKey, stores);
        if (providerState.activeProvider === normalizedKey) {
          renderProviderStores(normalizedKey, stores);
        }
        if (!isSilent) {
          setProviderPanelStatus(
            response.message ||
              sprintf(
                // translators: %s is the plural provider store type.
                __("%s synced.", "gpt3-ai-content-generator"),
                terms.plural
              ),
            "success"
          );
          clearProviderPanelStatusSoon();
        }
        return { success: true, stores };
      } catch (error) {
        if (providerState.syncTokens[normalizedKey] !== token) {
          return { success: false, skipped: true };
        }
        const errorMessage =
          error && error.message
            ? error.message
            : __("Sync failed.", "gpt3-ai-content-generator");
        providerState.syncErrors[normalizedKey] = errorMessage;
        if (!isSilent) {
          setProviderPanelStatus(
            `${ERROR_PREFIX} ${errorMessage}`,
            "error"
          );
          clearProviderPanelStatusSoon();
        }
        renderProviderStores(normalizedKey, providerState.stores[normalizedKey]);
        if (shouldPropagateError) {
          throw error;
        }
        return { success: false, message: errorMessage };
      } finally {
        setButtonLoading(
          triggerButton,
          false,
          triggerDefaultLabel,
          triggerLoadingLabel
        );
      }
    };

    const refreshStoreManagementProviders = async (triggerButton = null) => {
      const selectedProvider = normalizeProviderKey(
        storeProviderFilter ? storeProviderFilter.value : ""
      );
      const providerKeys = selectedProvider
        ? [selectedProvider]
        : Object.keys(PROVIDER_PANEL_CONFIG);
      const readyProviderKeys = providerKeys.filter(
        (providerKey) => getProviderConfigured(providerKey) !== false
      );

      if (!readyProviderKeys.length) {
        setProviderPanelStatus(
          selectedProvider
            ? __("Add provider credentials to refresh.", "gpt3-ai-content-generator")
            : __("No configured vector providers to refresh.", "gpt3-ai-content-generator"),
          "error"
        );
        clearProviderPanelStatusSoon();
        renderStoresTable();
        return;
      }

      setButtonLoading(
        triggerButton,
        true,
        __("Refresh", "gpt3-ai-content-generator"),
        __("Refreshing...", "gpt3-ai-content-generator")
      );
      try {
        const failures = [];
        for (const providerKey of readyProviderKeys) {
          const result = await syncProviderStores(providerKey, { silent: true });
          if (result && result.success === false && !result.skipped) {
            failures.push({
              providerKey,
              message: result.message || __("Refresh failed.", "gpt3-ai-content-generator"),
            });
          }
        }
        renderStoresTable();
        if (failures.length) {
          const firstFailure = failures[0];
          setProviderPanelStatus(
            `${getProviderLabel(firstFailure.providerKey)}: ${firstFailure.message}`,
            "error"
          );
          clearProviderPanelStatusSoon();
          return;
        }
        setProviderPanelStatus(
          selectedProvider
            ? sprintf(
                __("%s refreshed.", "gpt3-ai-content-generator"),
                getIndexTerms(selectedProvider).plural
              )
            : __("Stores refreshed.", "gpt3-ai-content-generator"),
          "success"
        );
        clearProviderPanelStatusSoon();
      } finally {
        setButtonLoading(
          triggerButton,
          false,
          __("Refresh", "gpt3-ai-content-generator"),
          __("Refreshing...", "gpt3-ai-content-generator")
        );
      }
    };

    const updateStoreCreationFields = (provider, name, dimensionRow, dimension) => {
      if (!provider) {
        return;
      }
      const providerKey = normalizeProviderKey(provider.value);
      const config = getProviderConfig(providerKey);
      if (name && config?.namePlaceholder) {
        name.placeholder = config.namePlaceholder;
      }
      if (dimensionRow) {
        dimensionRow.hidden = false;
      }
      if (dimension) {
        if (config?.needsDimension) {
          dimension.type = "number";
          dimension.disabled = false;
          dimension.min = "1";
          dimension.step = "1";
          dimension.value = String(config.defaultDimension || 1536);
          dimension.placeholder = String(config.defaultDimension || 1536);
        } else {
          dimension.type = "text";
          dimension.disabled = true;
          dimension.value = __("Not required", "gpt3-ai-content-generator");
          dimension.placeholder = __("Not required", "gpt3-ai-content-generator");
        }
      }
    };

    const updateStoreModalFields = () => updateStoreCreationFields(
      storeModalProvider, storeModalName, storeModalDimensionRow, storeModalDimension
    );

    const closeStoreModal = () => {
      if (!storeModal) {
        return;
      }
      storeModal.classList.remove("aipkit-active");
      storeModal.setAttribute("aria-hidden", "true");
      if (storeModalStatus) {
        setStatusMessage(storeModalStatus, "", "");
      }
    };

    const openStoreModal = (options = {}) => {
      if (!storeModal) {
        return;
      }
      const providerKey = normalizeProviderKey(
        options.provider ||
          (storeProviderFilter ? storeProviderFilter.value : "") ||
          getDefaultTrainingProvider()
      );
      if (storeModalProvider && getProviderConfig(providerKey)) {
        storeModalProvider.value = providerKey;
      }
      if (storeModalName) {
        storeModalName.value = "";
      }
      updateStoreModalFields();
      storeModal.classList.add("aipkit-active");
      storeModal.setAttribute("aria-hidden", "false");
      setTimeout(() => {
        if (storeModalName) {
          storeModalName.focus();
        }
      }, 0);
    };

    const createProviderStore = async ({ providerKey, name, dimension = 0 }) => {
      const config = getProviderConfig(providerKey);
      if (!config || !config.createAction) {
        throw new Error(__("Unsupported provider.", "gpt3-ai-content-generator"));
      }
      const nonceValue = getProviderNonceValue(providerKey);
      if (!nonceValue) {
        throw new Error(__("Provider nonce missing.", "gpt3-ai-content-generator"));
      }

      const requestData = {
        _ajax_nonce: nonceValue,
        name,
      };
      if (providerKey === "openai") {
        requestData.source_type = "sources_module_store_manager";
      }
      if (config.needsDimension) {
        requestData.dimension = dimension;
        requestData.metric = providerKey === "qdrant" ? "Cosine" : "cosine";
      }

      const response = await window.aipkit_apiRequest(
        config.createAction,
        requestData
      );
      const createdStore = response[config.createKey] || { name };
      const createdStoreId =
        getProviderStoreId(providerKey, createdStore) ||
        createdStore.id ||
        createdStore.name ||
        name;

      await syncProviderStores(providerKey, { silent: true });
      const stores = Array.isArray(providerState.stores[providerKey])
        ? providerState.stores[providerKey]
        : [];
      if (
        createdStoreId &&
        !stores.some(
          (store) => getProviderStoreId(providerKey, store) === createdStoreId
        )
      ) {
        providerState.stores[providerKey] = [...stores, createdStore];
        syncSharedVectorStoreCache(providerKey, providerState.stores[providerKey]);
      }
      renderProviderStores(providerKey, providerState.stores[providerKey] || []);

      return {
        createdStoreId,
      };
    };

    const readStoreCreationValues = (config, nameInput, dimensionInput, status, focusInvalid = false) => {
      const name = nameInput.value.trim();
      let message;
      let invalidInput;
      if (!config || !config.createAction) {
        message = __("Unsupported provider.", "gpt3-ai-content-generator");
      } else if (!name) {
        message = __("Enter a name.", "gpt3-ai-content-generator");
        invalidInput = nameInput;
      } else {
        const dimension = config.needsDimension && dimensionInput
          ? parseInt(dimensionInput.value, 10)
          : 0;
        if (!config.needsDimension || (Number.isFinite(dimension) && dimension > 0)) {
          return { name, dimension };
        }
        message = __("Enter a valid dimension.", "gpt3-ai-content-generator");
        invalidInput = dimensionInput;
      }
      setStatusMessage(status, message, "error");
      if (focusInvalid) {
        invalidInput?.focus();
      }
      return null;
    };

    const createProviderStoreFromModal = async () => {
      if (!storeModal || !storeModalProvider || !storeModalName) {
        return;
      }

      const providerKey = normalizeProviderKey(storeModalProvider.value);
      const config = getProviderConfig(providerKey);
      const values = readStoreCreationValues(
        config, storeModalName, storeModalDimension, storeModalStatus
      );
      if (!values) {
        return;
      }

      setButtonLoading(
        storeModalCreate,
        true,
        __("Create store", "gpt3-ai-content-generator"),
        __("Creating...", "gpt3-ai-content-generator")
      );
      setStatusMessage(storeModalStatus, __("Creating...", "gpt3-ai-content-generator"), "");
      try {
        await createProviderStore({
          providerKey,
          ...values,
        });
        closeStoreModal();
        setProviderPanelStatus(
          __("Store created.", "gpt3-ai-content-generator"),
          "success"
        );
        clearProviderPanelStatusSoon();
      } catch (error) {
        setStatusMessage(
          storeModalStatus,
          error?.message || __("Failed to create store.", "gpt3-ai-content-generator"),
          "error"
        );
      } finally {
        setButtonLoading(
          storeModalCreate,
          false,
          __("Create store", "gpt3-ai-content-generator"),
          __("Creating...", "gpt3-ai-content-generator")
        );
      }
    };

    const updateTrainingCreateStoreFields = () => updateStoreCreationFields(
      trainingProviderSelect, trainingCreateStoreName,
      trainingCreateStoreDimensionRow, trainingCreateStoreDimension
    );

    const setTrainingCreateStoreExpanded = (isExpanded, options = {}) => {
      if (
        !trainingCreateStoreSection ||
        !trainingCreateStorePanel ||
        !trainingCreateStoreButton
      ) {
        return;
      }
      trainingCreateStoreSection.classList.toggle("is-open", isExpanded);
      trainingCreateStorePanel.hidden = !isExpanded;
      trainingCreateStoreButton.setAttribute(
        "aria-expanded",
        isExpanded ? "true" : "false"
      );
      if (!isExpanded) {
        if (trainingCreateStoreStatus) {
          setStatusMessage(trainingCreateStoreStatus, "", "");
        }
        return;
      }
      updateTrainingCreateStoreFields();
      if (options.focus !== false) {
        window.setTimeout(() => trainingCreateStoreName?.focus(), 0);
      }
    };

    const createProviderStoreInline = async () => {
      if (
        !trainingProviderSelect ||
        !trainingCreateStoreName ||
        !trainingCreateStoreSubmit
      ) {
        return;
      }
      const providerKey = normalizeProviderKey(trainingProviderSelect.value);
      const config = getProviderConfig(providerKey);
      const terms = getIndexTerms(providerKey);
      const values = readStoreCreationValues(
        config, trainingCreateStoreName, trainingCreateStoreDimension, trainingCreateStoreStatus, true
      );
      if (!values) {
        return;
      }

      setButtonLoading(
        trainingCreateStoreSubmit,
        true,
        __("Create store", "gpt3-ai-content-generator"),
        __("Creating...", "gpt3-ai-content-generator")
      );
      setStatusMessage(
        trainingCreateStoreStatus,
        __("Creating...", "gpt3-ai-content-generator"),
        ""
      );
      try {
        const { createdStoreId } = await createProviderStore({
          providerKey,
          ...values,
        });
        populateTrainingStoreOptions(providerKey);
        if (trainingStoreSelect && createdStoreId) {
          trainingStoreSelect.value = createdStoreId;
        }
        trainingControls?.updateTargetState?.();
        setButtonLoading(
          trainingCreateStoreSubmit,
          false,
          __("Create store", "gpt3-ai-content-generator"),
          __("Creating...", "gpt3-ai-content-generator")
        );
        trainingCreateStoreSubmit.textContent = __(
          "Created ✓",
          "gpt3-ai-content-generator"
        );
        setStatusMessage(
          trainingCreateStoreStatus,
          // translators: %s: singular store type, such as Index or Collection.
          sprintf(__("%s created.", "gpt3-ai-content-generator"), terms.singular),
          "success"
        );
        window.setTimeout(() => {
          trainingCreateStoreName.value = "";
          trainingCreateStoreSubmit.textContent = __(
            "Create store",
            "gpt3-ai-content-generator"
          );
          setTrainingCreateStoreExpanded(false);
        }, 650);
      } catch (error) {
        setStatusMessage(
          trainingCreateStoreStatus,
          error?.message || __("Failed to create store.", "gpt3-ai-content-generator"),
          "error"
        );
      } finally {
        if (trainingCreateStoreSubmit.disabled) {
          setButtonLoading(
            trainingCreateStoreSubmit,
            false,
            __("Create store", "gpt3-ai-content-generator"),
            __("Creating...", "gpt3-ai-content-generator")
          );
        }
      }
    };

    const deleteProviderStore = async (details = {}) => {
      const providerKey = normalizeProviderKey(details.provider);
      const storeId = details.storeId || "";
      const config = getProviderConfig(providerKey);
      if (!config || !config.deleteAction || !storeId) {
        setProviderPanelStatus(
          __("Missing store details.", "gpt3-ai-content-generator"),
          "error"
        );
        clearProviderPanelStatusSoon();
        return;
      }
      const nonceValue = getProviderNonceValue(providerKey);
      if (!nonceValue) {
        setProviderPanelStatus(
          __("Provider nonce missing.", "gpt3-ai-content-generator"),
          "error"
        );
        clearProviderPanelStatusSoon();
        return;
      }

      const requestData = {
        _ajax_nonce: nonceValue,
        [config.deleteParam]: storeId,
      };
      try {
        await window.aipkit_apiRequest(config.deleteAction, requestData);
        providerState.stores[providerKey] = (
          providerState.stores[providerKey] || []
        ).filter((store) => getProviderStoreId(providerKey, store) !== storeId);
        syncSharedVectorStoreCache(providerKey, providerState.stores[providerKey]);
        if (
          trainingProviderSelect &&
          normalizeProviderKey(trainingProviderSelect.value) === providerKey &&
          trainingStoreSelect &&
          trainingStoreSelect.value === storeId
        ) {
          trainingStoreSelect.value = "";
        }
        renderProviderStores(providerKey, providerState.stores[providerKey] || []);
        setProviderPanelStatus(
          __("Store deleted.", "gpt3-ai-content-generator"),
          "success"
        );
        clearProviderPanelStatusSoon();
        fetchSources(1);
      } catch (error) {
        setProviderPanelStatus(
          error?.message || __("Failed to delete store.", "gpt3-ai-content-generator"),
          "error"
        );
        clearProviderPanelStatusSoon();
      }
    };

    const confirmProviderStoreDelete = (details = {}) => {
      const storeName = details.storeName || details.storeId || "";
      const message = sprintf(
        __(
          "This permanently deletes \"%s\" and its source records from your knowledge base. This cannot be undone.",
          "gpt3-ai-content-generator"
        ),
        storeName
      );
      const runDelete = () => deleteProviderStore(details);

      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(message, {
          title: __("Delete store", "gpt3-ai-content-generator"),
          confirmText: __("Delete", "gpt3-ai-content-generator"),
          cancelText: __("Cancel", "gpt3-ai-content-generator"),
          variant: "danger",
          onConfirm: runDelete,
        });
        return;
      }

      if (window.confirm(message)) {
        runDelete();
      }
    };

    const setActiveProvider = (providerKey) => {
      const normalizedKey = normalizeProviderKey(providerKey);
      providerState.activeProvider = normalizedKey;
      if (!normalizedKey) {
        updateIndexSelectOptions("", []);
      } else {
        const cachedStores = providerState.stores[normalizedKey];
        if (Array.isArray(cachedStores)) {
          renderProviderStores(normalizedKey, cachedStores);
        } else if (getProviderConfigured(normalizedKey)) {
          renderProviderStores(normalizedKey, [], { loading: true });
          syncProviderStores(normalizedKey, { silent: true });
        } else {
          const terms = getIndexTerms(normalizedKey);
          renderProviderStores(normalizedKey, [], {
            emptyLabel: sprintf(
              __("Add credentials to load %s.", "gpt3-ai-content-generator"),
              terms.plural.toLowerCase()
            ),
          });
        }
      }
    };

    const generateQdrantPointId = () => {
      if (typeof window.aipkit_generateUUIDv4 === "function") {
        return window.aipkit_generateUUIDv4();
      }
      if (window.crypto && typeof window.crypto.randomUUID === "function") {
        return window.crypto.randomUUID();
      }
      return Date.now();
    };

    const getSafeSelector = (value) => {
      if (typeof CSS !== "undefined" && CSS.escape) {
        return CSS.escape(String(value));
      }
      return String(value).replace(/[^a-zA-Z0-9_-]/g, "\\$&");
    };

    const deleteSourceEntry = (source) =>
      window.aipkit_apiRequest("aipkit_delete_vector_data_source_entry", {
        provider: source.provider,
        store_id: source.storeId,
        vector_id: source.vectorId,
        log_id: source.logId,
      });

    const getSourceRow = (logId) => {
      if (!tableBody || !logId) {
        return null;
      }
      return tableBody.querySelector(`tr[data-log-id="${getSafeSelector(logId)}"]`);
    };

    const markSourceRowDeleting = (logId) => {
      const row = getSourceRow(logId);
      if (!row) {
        return;
      }
      row.classList.add("is-deleting");
      row.querySelectorAll(SOURCE_ROW_CONTROL_SELECTOR).forEach((control) => {
        control.disabled = true;
        control.setAttribute("aria-disabled", "true");
      });
    };

    const runBulkDelete = async (sourcesToDelete = null) => {
      if (isBulkDeleting) {
        return;
      }
      const sources = Array.isArray(sourcesToDelete)
        ? sourcesToDelete
        : Array.from(selectedSources.values());
      if (!sources.length) {
        return;
      }

      const total = sources.length;
      let completedCount = 0;
      let deletedCount = 0;
      failedBulkSources = new Map();
      isBulkDeleting = true;
      closeSourceActionMenu();
      setSourceRowsLocked(true);
      setBulkBarState(
        sprintf(__("Deleting %s of %s...", "gpt3-ai-content-generator"), 0, total),
        {
          countLabel: formatSelectedCount(total),
          showDelete: false,
          showRetry: false,
          showClear: false,
        }
      );

      for (const source of sources) {
        markSourceRowDeleting(source.logId);
        try {
          await deleteSourceEntry(source);
          deletedCount += 1;
          selectedSources.delete(source.logId);
        } catch (error) {
          failedBulkSources.set(source.logId, {
            ...source,
            errorMessage:
              error && error.message
                ? error.message
                : __("Failed to delete source.", "gpt3-ai-content-generator"),
          });
        } finally {
          completedCount += 1;
          setBulkBarState(
            sprintf(
              __("Deleting %s of %s...", "gpt3-ai-content-generator"),
              completedCount,
              total
            ),
            {
              countLabel: formatSelectedCount(total),
              showDelete: false,
              showRetry: false,
              showClear: false,
            }
          );
        }
      }

      isBulkDeleting = false;

      if (failedBulkSources.size) {
        selectedSources.clear();
        failedBulkSources.forEach((source, logId) => {
          selectedSources.set(logId, source);
        });
        setBulkBarState(
          sprintf(
            __("Deleted %s of %s. %s failed.", "gpt3-ai-content-generator"),
            deletedCount,
            total,
            failedBulkSources.size
          ),
          {
            countLabel: formatSelectedCount(failedBulkSources.size),
            showDelete: false,
            showRetry: true,
            showClear: true,
          }
        );
        fetchSources(state.page || 1, { preserveSelection: true });
        return;
      }

      selectedSources.clear();
      setBulkBarState(formatDeletedCount(deletedCount), {
        countLabel: __("Complete", "gpt3-ai-content-generator"),
        showDelete: false,
        showRetry: false,
        showClear: false,
      });
      fetchSources(state.page || 1, { preserveSelection: true });
      clearBulkBarSoon();
    };

    const runSingleDelete = async (source) => {
      if (!source || !source.provider || !source.storeId || !source.logId) {
        setStatusMessage(
          statusEl,
          __("Missing source details.", "gpt3-ai-content-generator"),
          "error"
        );
        clearStatusSoon();
        return;
      }

      closeSourceActionMenu();
      const row = getSourceRow(source.logId);
      const rowButtons = row
        ? Array.from(row.querySelectorAll(SOURCE_ROW_CONTROL_SELECTOR))
        : [];
      rowButtons.forEach((button) => {
        button.disabled = true;
        button.setAttribute("aria-disabled", "true");
      });
      const deleteButton = row
        ? row.querySelector(".aipkit_sources_action_delete")
        : null;
      if (deleteButton) {
        deleteButton.textContent = __("Deleting...", "gpt3-ai-content-generator");
      }

      setStatusMessage(statusEl, __("Deleting...", "gpt3-ai-content-generator"), "");
      try {
        await deleteSourceEntry(source);
        setStatusMessage(statusEl, __("Deleted", "gpt3-ai-content-generator"), "success");
        clearStatusSoon();
        fetchSources(state.page || 1);
      } catch (error) {
        const errorMessage =
          error && error.message
            ? error.message
            : __("Failed to delete source.", "gpt3-ai-content-generator");
        setStatusMessage(
          statusEl,
          `${ERROR_PREFIX} ${errorMessage}`,
          "error"
        );
        clearStatusSoon();
        rowButtons.forEach((button) => {
          button.disabled = false;
          button.removeAttribute("aria-disabled");
        });
        if (deleteButton) {
          deleteButton.textContent = __("Delete", "gpt3-ai-content-generator");
        }
      }
    };

    const confirmSourceDelete = (request) => {
      if (!request) {
        return;
      }

      const runDelete = () => {
        if (request.mode === "bulk") {
          runBulkDelete(request.sources || []);
        } else if (request.source) {
          runSingleDelete(request.source);
        }
      };

      const isBulk = request.mode === "bulk";
      const sourceCount = isBulk && Array.isArray(request.sources)
        ? request.sources.length
        : 1;
      const title = isBulk
        ? sprintf(
            _n(
              "Delete %s source",
              "Delete %s sources",
              sourceCount,
              "gpt3-ai-content-generator"
            ),
            sourceCount
          )
        : __("Delete source", "gpt3-ai-content-generator");
      const message = isBulk
        ? sprintf(
            _n(
              "This permanently deletes the selected source from your knowledge base. This cannot be undone.",
              "This permanently deletes the selected %s sources from your knowledge base. This cannot be undone.",
              sourceCount,
              "gpt3-ai-content-generator"
            ),
            sourceCount
          )
        : __(
            "This permanently deletes the selected source from your knowledge base. This cannot be undone.",
            "gpt3-ai-content-generator"
          );

      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(message, {
          title,
          confirmText: __("Delete", "gpt3-ai-content-generator"),
          cancelText: __("Cancel", "gpt3-ai-content-generator"),
          variant: "danger",
          onConfirm: runDelete,
        });
        return;
      }

      if (window.confirm(message)) {
        runDelete();
      }
    };

    const upsertEditedText = createKnowledgeBaseTextEditor({
      getNonceValue,
      generateQdrantPointId,
      __,
    });

    const escaper =
      window.aipkit_escapeHtml ||
      ((value) => (value === null || value === undefined ? "" : String(value)));

    const googleFileSearchJobPoller = createGoogleFileSearchJobPoller({
      request: (action, data) => window.aipkit_apiRequest(action, data),
      getNonce: () => getNonceValue("aipkit_google_file_search_nonce"),
      onSettled: () => fetchSources(state.page || 1, { silent: true }),
    });
    const pollGoogleFileSearchJob = (jobId, options = {}) =>
      googleFileSearchJobPoller.poll(jobId, options);

    let sourceRefreshTimer;
    let sourceFetchGeneration = 0;
    let hasProcessingSources = false;
    const scheduleSourceRefresh = () => {
      window.clearTimeout(sourceRefreshTimer);
      if (!hasProcessingSources || !moduleContainer.isConnected) return;
      sourceRefreshTimer = window.setTimeout(() => {
        if (!moduleContainer.isConnected) return;
        if (document.visibilityState === 'hidden' || !moduleContainer.getClientRects().length) {
          scheduleSourceRefresh();
          return;
        }
        fetchSources(state.page || 1, { silent: true, preserveSelection: true });
      }, 10000);
    };

    const fetchSources = async (page = 1, options = {}) => {
      window.clearTimeout(sourceRefreshTimer);
      const generation = ++sourceFetchGeneration;
      state.page = page;
      if (page === 1) {
        state.pageCursors = [null];
      }
      const pageCursor = state.pageCursors[Math.max(0, page - 1)] || null;
      closeSourceActionMenu();
      if (!options.preserveSelection && !isBulkDeleting) {
        selectedSources.clear();
        failedBulkSources.clear();
        setBulkBarState("");
      }
      if (!options.silent) {
        setStatusMessage(statusEl, "", "");
        tableBody.innerHTML = `<tr><td colspan="4" class="aipkit_text-center">${escaper(
          __("Loading sources...", "gpt3-ai-content-generator")
        )}</td></tr>`;
        syncSelectAllCheckbox();
        if (paginationContainer) {
          paginationContainer.innerHTML = "";
        }
      }

      try {
        const response = await window.aipkit_apiRequest(
          "aipkit_get_global_vector_sources",
          {
            page: state.page,
            per_page: state.perPage,
            provider: state.provider,
            search: state.search,
            store_id: getActiveIndexFilter(),
            cursor_mode: "1",
            cursor_timestamp: pageCursor?.timestamp || "",
            cursor_id: pageCursor?.id || 0,
          }
        );

        if (generation !== sourceFetchGeneration || !moduleContainer.isConnected) return;
        const logs = Array.isArray(response.logs) ? response.logs : [];
        hasProcessingSources = logs.some((log) => ['processing', 'queued'].includes(String(log.status || '')));
        const nextCursor = response.pagination?.next_cursor || null;
        state.pageCursors = state.pageCursors.slice(0, page);
        if (nextCursor?.timestamp && Number(nextCursor?.id || 0) > 0) {
          state.pageCursors[page] = nextCursor;
        }
        state.sourceLogsById = new Map(
          logs
            .map((log) => [String(log?.id || ""), log])
            .filter(([logId]) => Boolean(logId))
        );

        renderRows(tableBody, logs, {
          selectedLogIds: new Set(selectedSources.keys()),
          isBulkDeleting,
        });
        logs.forEach((log) => {
          if (
            normalizeProviderKey(log?.provider || "") === "google" &&
            String(log?.status || "").toLowerCase() === "processing" &&
            log?.id
          ) {
            pollGoogleFileSearchJob(log.id);
          }
        });
        syncSelectAllCheckbox();
        renderPagination(response.pagination || {}, paginationContainer, fetchSources, {
          perPage: state.perPage,
          onPerPageChange: (nextPerPage) => {
            if (state.perPage === nextPerPage) {
              return;
            }
            state.perPage = nextPerPage;
            persistSourcePreferences();
            fetchSources(1);
          },
        });
      } catch (error) {
        if (generation !== sourceFetchGeneration || !moduleContainer.isConnected) return;
        const errorMessage =
          error && error.message
            ? error.message
            : __("Error loading sources.", "gpt3-ai-content-generator");
        if (!options.silent) {
          renderEmpty(tableBody, paginationContainer, errorMessage);
        }
        syncSelectAllCheckbox();
        if (!options.silent) {
          setStatusMessage(statusEl, errorMessage, "error");
        }
      } finally {
        if (generation === sourceFetchGeneration) scheduleSourceRefresh();
      }
    };

    const initTrainingCard = () => {
      const trainingToggle = document.getElementById(
        "aipkit_sources_training_toggle"
      );
      const trainingCard = document.getElementById(
        "aipkit_sources_training_card"
      );
      if (!trainingToggle || !trainingCard) {
        return null;
      }

      const trainingTabList = trainingCard.querySelector(
        '.aipkit_builder_tabs[data-aipkit-tabs="training"]'
      );
      const trainingTabPanels = trainingCard.querySelectorAll(
        ".aipkit_builder_tab_panel[data-aipkit-panel]"
      );
      const trainingTextInput = trainingCard.querySelector(
        ".aipkit_training_text_input"
      );
      const trainingQaQuestion = trainingCard.querySelector(
        "#aipkit_training_qa_question"
      );
      const trainingQaAnswer = trainingCard.querySelector(
        "#aipkit_training_qa_answer"
      );
      const trainingCommonQuestionsToggle = trainingCard.querySelector(
        "[data-aipkit-common-questions-toggle]"
      );
      const trainingCommonQuestionsPanel = trainingCard.querySelector(
        "[data-aipkit-common-questions-panel]"
      );
      const trainingFilesInput = trainingCard.querySelector(
        "#aipkit_training_files_input"
      );
      const trainingFilesButton = trainingCard.querySelector(
        ".aipkit_training_files_button"
      );
      const trainingFilesDropzone = trainingCard.querySelector(
        ".aipkit_builder_dropzone"
      );
      const trainingFileList = trainingCard.querySelector(
        "#aipkit_training_file_list"
      );
      const trainingFileQueue = trainingCard.querySelector(
        "[data-aipkit-training-file-queue]"
      );
      const trainingFileCount = trainingCard.querySelector(
        "[data-aipkit-training-file-count]"
      );
      const trainingActionButtons = trainingCard.querySelectorAll(
        ".aipkit_training_action_btn[data-training-action]"
      );
      const trainingFooterAddButton = trainingCard.querySelector(
        '.aipkit_training_action_btn[data-training-action="add"]'
      );
      const trainingActionRow = trainingCard.querySelector(
        ".aipkit_training_action_row"
      );
      const trainingStatus =
        trainingCard.querySelector("#aipkit_training_status") || statusEl;
      const trainingTargetControls = trainingCard.querySelector(
        ".aipkit_sources_training_target_controls"
      );
      const trainingStoreLabel = trainingCard.querySelector(
        "#aipkit_sources_training_store_label"
      );
      const embeddingRow = document.getElementById(
        "aipkit_sources_embedding_row"
      );
      const embeddingSelect = document.getElementById(
        "aipkit_sources_embedding_model"
      );
      const trainingWpStatusSelect = trainingCard.querySelector(
        "#aipkit_vs_wp_content_status"
      );
      const trainingWpStatus = trainingCard.querySelector(
        "#aipkit_vs_wp_content_messages_area"
      );
      const trainingWpTargetSelect = trainingCard.querySelector(
        "#aipkit_vs_global_target_select"
      );
      const trainingFileRows = new Map();
      const trainingSelectedFiles = new Map();
      let updateTrainingActionAvailability = () => {};
      let trainingStatusTimer = null;
      let websiteStatusTimer = null;
      let isTraining = false;
      let trainingStopRequested = false;
      let closeTrainingWhenStopped = false;
      let trainingAbortController = null;
      let trainingDismissBaseline = null;
      const trainingStoppedCode = "aipkit_training_stopped";

      const setTrainingStatus = (text, tone) => {
        if (!trainingStatus) {
          return;
        }
        const normalizedText = String(text || "")
          .replace(/\((\d+)\s*\/\s*(\d+)\)/g, "$1 of $2")
          .replace(/\b(\d+)\s*\/\s*(\d+)\b/g, "$1 of $2")
          .replace(/(?:\.{3}|…)+\s*$/, "")
          .trim();
        trainingStatus.textContent = normalizedText;
        trainingStatus.classList.remove(
          "is-visible",
          "is-success",
          "is-error",
          "is-warning",
          "is-loading"
        );
        if (normalizedText) {
          trainingStatus.classList.add("is-visible");
        }
        if (tone) {
          trainingStatus.classList.add(`is-${tone}`);
        }
        if (tone === "loading") {
          trainingStatus.setAttribute("aria-busy", "true");
        } else {
          trainingStatus.removeAttribute("aria-busy");
        }
      };

      const clearTrainingStatusSoon = (delay = 2500) => {
        if (trainingStatusTimer) {
          window.clearTimeout(trainingStatusTimer);
        }
        trainingStatusTimer = window.setTimeout(() => {
          setTrainingStatus("", "");
        }, delay);
      };

      const setWebsiteStatus = (text, tone = "") => {
        if (!trainingWpStatus) {
          return;
        }
        const toneValue =
          tone === "error" ? "error" : tone === "success" ? "success" : "";
        setStatusMessage(trainingWpStatus, text || "", toneValue);
      };

      const clearWebsiteStatusSoon = (delay = 2500) => {
        if (websiteStatusTimer) {
          window.clearTimeout(websiteStatusTimer);
        }
        websiteStatusTimer = window.setTimeout(() => {
          setWebsiteStatus("", "");
        }, delay);
      };

      const getTrainingTarget = () => {
        const providerKey = normalizeProviderKey(
          trainingProviderSelect ? trainingProviderSelect.value : ""
        );
        const storeId = trainingStoreSelect ? trainingStoreSelect.value || "" : "";
        if (!providerKey || !storeId) {
          return null;
        }
        return { providerKey, storeId };
      };

      const trainingFileQueueController =
        typeof window.aipkit_createKnowledgeBaseFileQueue === "function"
          ? window.aipkit_createKnowledgeBaseFileQueue({
              trainingFilesInput, trainingFilesButton, trainingFilesDropzone,
              trainingFileList, trainingFileQueue, trainingFileCount,
              trainingFileRows, trainingSelectedFiles,
              getIsTraining: () => isTraining,
              getActiveTrainingTabKey: () => getActiveTrainingTabKey(),
              updateTrainingActionLabel: (tabKey) => updateTrainingActionLabel(tabKey),
              updateTrainingActionAvailability: () => updateTrainingActionAvailability(),
              __, _n, sprintf,
            })
          : null;

      const updateTrainingFileQueue = () => {
        if (trainingFileQueueController) {
          trainingFileQueueController.updateTrainingFileQueue();
          return;
        }
        if (trainingFileQueue) trainingFileQueue.hidden = true;
        if (trainingFileCount) trainingFileCount.textContent = "";
        if (trainingFilesDropzone) trainingFilesDropzone.classList.remove("has-files");
        if (getActiveTrainingTabKey() === "files") updateTrainingActionLabel("files");
        updateTrainingActionAvailability();
      };

      const setCommonQuestionsOpen = (isOpen) => {
        if (!trainingCommonQuestionsToggle || !trainingCommonQuestionsPanel) {
          return;
        }
        trainingCommonQuestionsToggle.setAttribute(
          "aria-expanded",
          isOpen ? "true" : "false"
        );
        trainingCommonQuestionsPanel.hidden = !isOpen;
      };

      const appendCommonQuestion = (question) => {
        if (!trainingQaQuestion || !question) {
          return;
        }
        const currentValue = trainingQaQuestion.value.trim();
        trainingQaQuestion.value = currentValue
          ? `${currentValue}\n${question}`
          : question;
        trainingQaQuestion.focus();
        setCommonQuestionsOpen(false);
      };

      const embeddingUtils = window.aipkit_embedding_utils || {};
      const resolveModelsForProvider =
        typeof embeddingUtils.resolveModelsForProvider === "function"
          ? embeddingUtils.resolveModelsForProvider
          : () => [];
      const resolveProviderEntries =
        typeof embeddingUtils.resolveProviderEntries === "function"
          ? embeddingUtils.resolveProviderEntries
          : () => [];
      const populateModelSelect =
        typeof embeddingUtils.populateModelSelect === "function"
          ? embeddingUtils.populateModelSelect
          : null;

      const getEmbeddingModels = (providerKey) => {
        const map = window.aipkit_dashboard?.embeddingModels || {};
        return resolveModelsForProvider(map, providerKey);
      };

      const getEmbeddingModelDimension = (providerKey, modelId) => {
        if (!modelId) {
          return null;
        }
        const models = getEmbeddingModels(providerKey);
        const model = models.find((entry) => entry && entry.id === modelId);
        const candidate =
          model?.dimensions ||
          model?.dimension ||
          model?.output_dimensionality ||
          model?.outputDimensionality ||
          model?.output_dimension;
        const numericCandidate = candidate ? Number(candidate) : NaN;
        if (Number.isFinite(numericCandidate) && numericCandidate > 0) {
          return numericCandidate;
        }
        if (model && typeof model.name === "string") {
          const match = model.name.match(/\((\d{3,5})\)/);
          if (match) {
            return Number(match[1]);
          }
        }
        if (providerKey === "google") {
          return 3072;
        }
        return 1536;
      };

      const applyStoredEmbeddingSelection = (providerKey) => {
        if (!embeddingSelect) {
          return;
        }
        const normalizedKey = normalizeProviderKey(providerKey);
        const storedValue =
          normalizedKey && state.embeddingByProvider
            ? state.embeddingByProvider[normalizedKey]
            : "";
        if (!storedValue) {
          return;
        }
        const safeValue = getSafeSelector(storedValue);
        const option = embeddingSelect.querySelector(
          `option[value="${safeValue}"]`
        );
        if (option) {
          embeddingSelect.value = storedValue;
        }
      };

      const populateEmbeddingOptions = () => {
        if (!embeddingSelect) {
          return;
        }

        if (typeof populateModelSelect !== "function") {
          embeddingSelect.innerHTML = "";
          const option = document.createElement("option");
          option.value = "";
          option.textContent = __(
            "No embedding models found.",
            "gpt3-ai-content-generator"
          );
          embeddingSelect.appendChild(option);
          embeddingSelect.disabled = true;
          return;
        }

        const previousValue = embeddingSelect.value || "";
        const providerEntries = resolveProviderEntries(
          window.aipkit_dashboard?.embeddingProviderMap || {},
          window.aipkit_dashboard?.embeddingModels || {},
          embeddingUtils.defaultProviderMap || undefined
        );

        populateModelSelect(embeddingSelect, {
          grouped: true,
          providerEntries,
          modelsByProvider: window.aipkit_dashboard?.embeddingModels || {},
          includePlaceholder: true,
          placeholderText: __(
            "Select embedding model",
            "gpt3-ai-content-generator"
          ),
          emptyText: __("No embedding models found.", "gpt3-ai-content-generator"),
          selectedValue: previousValue,
          preserveUnknownSelected: false,
          autoSelectFirst: true,
          valueFormatter: (providerKey, model) => `${providerKey}::${model.id}`,
        });
      };

      const getEmbeddingSelection = () => {
        if (!embeddingSelect) {
          return null;
        }
        const selectedOption =
          embeddingSelect.options[embeddingSelect.selectedIndex];
        let providerKey = selectedOption ? selectedOption.dataset.provider || "" : "";
        let modelId = selectedOption ? selectedOption.dataset.model || "" : "";
        if (!providerKey || !modelId) {
          const rawValue = embeddingSelect.value || "";
          if (rawValue && rawValue.includes("::")) {
            const [providerCandidate, modelCandidate] = rawValue.split("::");
            providerKey = providerCandidate || "";
            modelId = modelCandidate || "";
          }
        }
        if (!providerKey || !modelId) {
          return null;
        }
        return {
          provider: providerKey,
          model: modelId,
          dimension: getEmbeddingModelDimension(providerKey, modelId),
        };
      };

      const getTrainingActionLabels = (tabKey) => {
        switch (tabKey || getActiveTrainingTabKey()) {
          case "qa":
            return {
              idle: __("Add source", "gpt3-ai-content-generator"),
              loading: __("Adding", "gpt3-ai-content-generator"),
            };
          case "text":
            return {
              idle: __("Add source", "gpt3-ai-content-generator"),
              loading: __("Adding", "gpt3-ai-content-generator"),
            };
          case "website":
            return {
              idle: __("Sync", "gpt3-ai-content-generator"),
              loading: __("Adding", "gpt3-ai-content-generator"),
            };
          case "files":
            return {
              idle: __("Add files", "gpt3-ai-content-generator"),
              loading: __("Uploading", "gpt3-ai-content-generator"),
            };
          default:
            return {
              idle: __("Add source", "gpt3-ai-content-generator"),
              loading: __("Adding", "gpt3-ai-content-generator"),
            };
        }
      };

      const getTrainingAddButton = () => trainingFooterAddButton;

      const setTrainingActionButtonText = (button, text) => {
        if (!button) {
          return;
        }
        const label = button.querySelector(".aipkit_btn_label");
        if (label) {
          label.textContent = text;
        } else {
          button.textContent = text;
        }
      };

      updateTrainingActionAvailability = () => {
        const button = getTrainingAddButton();
        if (!button || isTraining) {
          return;
        }
        const isFilesTab = getActiveTrainingTabKey() === "files";
        const hasReadyFile = Array.from(trainingSelectedFiles.values()).some(
          (entry) => entry && entry.state === "ready"
        );
        const isAvailable = !isFilesTab || hasReadyFile;
        button.disabled = !isAvailable;
        if (isAvailable) {
          button.removeAttribute("aria-disabled");
        } else {
          button.setAttribute("aria-disabled", "true");
        }
      };

      const updateTrainingActionLabel = (tabKey) => {
        const button = getTrainingAddButton(tabKey);
        if (!button || isTraining) {
          return;
        }
        setTrainingActionButtonText(button, getTrainingActionLabels(tabKey).idle);
      };

      const setTrainingRunningState = (isRunning) => {
        trainingCard.classList.toggle("is-training", isRunning);
        const labels = getTrainingActionLabels();
        const isWebsiteRun = isRunning && getActiveTrainingTabKey() === "website";
        const button = getTrainingAddButton();
        if (button) {
          button.classList.remove("aipkit_training_stop_btn");
        }
        if (button && isWebsiteRun) {
          button.classList.remove("is-loading");
          button.removeAttribute("aria-busy");
          button.disabled = false;
          button.removeAttribute("aria-disabled");
          button.classList.add("aipkit_training_stop_btn");
          setTrainingActionButtonText(
            button,
            __("Stop", "gpt3-ai-content-generator")
          );
        } else {
          setButtonLoading(
            button,
            isRunning,
            labels.idle,
            labels.loading
          );
          updateTrainingActionAvailability();
        }
      };

      const createTrainingStoppedError = () => {
        const error = new Error(
          __("Adding stopped.", "gpt3-ai-content-generator")
        );
        error.code = trainingStoppedCode;
        return error;
      };

      const throwIfTrainingStopped = () => {
        if (trainingStopRequested) {
          throw createTrainingStoppedError();
        }
      };

      const isTrainingStoppedError = (error) =>
        error?.code === trainingStoppedCode ||
        (trainingStopRequested &&
          (error?.name === "AbortError" || error?.code === "aborted"));

      const trainingApiRequest = (action, data) =>
        window.aipkit_apiRequest(action, data, {
          signal: trainingAbortController?.signal,
          abortMessage: __("Adding stopped.", "gpt3-ai-content-generator"),
        });

      const requestTrainingStop = (options = {}) => {
        if (!isTraining) {
          return;
        }
        trainingStopRequested = true;
        closeTrainingWhenStopped = Boolean(options.closeModal);
        if (trainingAbortController && !trainingAbortController.signal.aborted) {
          trainingAbortController.abort();
        }
        if (trainingCancelButton && closeTrainingWhenStopped) {
          trainingCancelButton.disabled = true;
          trainingCancelButton.setAttribute("aria-disabled", "true");
          trainingCancelButton.textContent = __(
            "Cancelling",
            "gpt3-ai-content-generator"
          );
        } else {
          const button = getTrainingAddButton();
          if (button) {
            button.disabled = true;
            button.setAttribute("aria-disabled", "true");
            setTrainingActionButtonText(button, __(
              "Stopping",
              "gpt3-ai-content-generator"
            ));
          }
        }
        setTrainingStatus(
          closeTrainingWhenStopped
            ? __("Cancelling", "gpt3-ai-content-generator")
            : __("Stopping", "gpt3-ai-content-generator"),
          "warning"
        );
        if (getActiveTrainingTabKey() === "website") {
          setWebsiteStatus("", "");
        }
      };

      const updateTrainingTargetState = () => {
        const target = getTrainingTarget();
        const providerKey = normalizeProviderKey(
          trainingProviderSelect ? trainingProviderSelect.value : target?.providerKey || ""
        );
        trainingActionButtons.forEach((button) => {
          button.disabled = false;
          button.removeAttribute("aria-disabled");
        });
        if (trainingStoreLabel) {
          const terms = getIndexTerms(providerKey);
          trainingStoreLabel.textContent =
            terms.singular || __("Vector store", "gpt3-ai-content-generator");
        }
        if (embeddingRow) {
          const shouldShow =
            providerKey === "local" || providerKey === "pinecone" ||
            providerKey === "qdrant" ||
            providerKey === "chroma";
          embeddingRow.hidden = !shouldShow;
          if (trainingTargetControls) {
            trainingTargetControls.classList.toggle(
              "has-embedding-model",
              shouldShow
            );
          }
          if (shouldShow) {
            applyStoredEmbeddingSelection(providerKey);
          }
        }
        if (trainingWpTargetSelect) {
          const storeId = target ? target.storeId : "";
          trainingWpTargetSelect.innerHTML = storeId
            ? `<option value="${escaper(storeId)}">${escaper(storeId)}</option>`
            : '<option value=""></option>';
          trainingWpTargetSelect.value = storeId;
        }
        updateTrainingActionAvailability();
      };

      const setActiveTrainingTab = (tabKey) => {
        if (!trainingTabList || !tabKey) {
          return;
        }
        const tabs = trainingTabList.querySelectorAll(
          ".aipkit_builder_tab[data-aipkit-tab]"
        );
        tabs.forEach((tab) => {
          const isActive = tab.dataset.aipkitTab === tabKey;
          tab.classList.toggle("is-active", isActive);
          tab.setAttribute("aria-selected", isActive ? "true" : "false");
        });
        trainingTabPanels.forEach((panel) => {
          const isActive = panel.dataset.aipkitPanel === tabKey;
          panel.classList.toggle("is-active", isActive);
          panel.hidden = !isActive;
        });
        if (trainingFooterAddButton) {
          trainingFooterAddButton.style.display = "";
        }
        updateTrainingActionLabel(tabKey);
        if (trainingActionRow) {
          trainingActionRow.classList.toggle(
            "aipkit_builder_action_row--end",
            false
          );
        }
        updateTrainingFileQueue();
        updateTrainingActionAvailability();
      };

      const getActiveTrainingTabKey = () => {
        const activeTab = trainingTabList
          ? trainingTabList.querySelector(".aipkit_builder_tab.is-active")
          : null;
        return activeTab ? activeTab.dataset.aipkitTab : "";
      };

      const getWebsitePostTypes = () => {
        const select = document.getElementById("aipkit_vs_wp_content_post_types");
        if (!select) {
          return [];
        }
        return Array.from(select.selectedOptions)
          .map((option) => option.value)
          .filter(Boolean);
      };

      const getWebsiteStatusValue = () => {
        return trainingWpStatusSelect
          ? trainingWpStatusSelect.value || "publish"
          : "publish";
      };

      const getTrainingDismissState = () => ({
        provider: trainingProviderSelect ? trainingProviderSelect.value || "" : "",
        store: trainingStoreSelect ? trainingStoreSelect.value || "" : "",
        embedding: embeddingSelect ? embeddingSelect.value || "" : "",
        postTypes: getWebsitePostTypes().slice().sort(),
        postStatus: getWebsiteStatusValue(),
        storeName: trainingCreateStoreName
          ? trainingCreateStoreName.value.trim()
          : "",
        storeDimension: trainingCreateStoreDimension
          ? trainingCreateStoreDimension.value.trim()
          : "",
      });

      const setTrainingDismissBaseline = () => {
        trainingDismissBaseline = getTrainingDismissState();
      };

      const hasTrainingSourceContent = () =>
        Boolean(
          (trainingTextInput && trainingTextInput.value.trim()) ||
            (trainingQaQuestion && trainingQaQuestion.value.trim()) ||
            (trainingQaAnswer && trainingQaAnswer.value.trim()) ||
            trainingSelectedFiles.size
        );

      const hasTrainingDraft = () => {
        if (isTraining || hasTrainingSourceContent()) {
          return true;
        }
        if (!trainingDismissBaseline) {
          return false;
        }
        return (
          JSON.stringify(getTrainingDismissState()) !==
          JSON.stringify(trainingDismissBaseline)
        );
      };

      const getTrainingDiscardCopy = () => {
        const activeTab = getActiveTrainingTabKey();
        if (isTraining && activeTab === "files") {
          return {
            title: __("Cancel file uploads?", "gpt3-ai-content-generator"),
            message: __(
              "The active upload will be cancelled. Files already added will remain in your knowledge base.",
              "gpt3-ai-content-generator"
            ),
          };
        }
        if (isTraining) {
          return {
            title: __("Cancel adding this source?", "gpt3-ai-content-generator"),
            message: __(
              "The active request will be cancelled. Any item already added will remain in your knowledge base.",
              "gpt3-ai-content-generator"
            ),
          };
        }
        if (trainingSelectedFiles.size) {
          return {
            title: __("Discard these files?", "gpt3-ai-content-generator"),
            message: __(
              "The files you selected have not been added yet and will be removed.",
              "gpt3-ai-content-generator"
            ),
          };
        }
        if (
          (trainingQaQuestion && trainingQaQuestion.value.trim()) ||
          (trainingQaAnswer && trainingQaAnswer.value.trim())
        ) {
          return {
            title: __("Discard this source?", "gpt3-ai-content-generator"),
            message: __(
              "The question and answer you entered have not been added yet and will be lost.",
              "gpt3-ai-content-generator"
            ),
          };
        }
        if (trainingTextInput && trainingTextInput.value.trim()) {
          return {
            title: __("Discard this source?", "gpt3-ai-content-generator"),
            message: __(
              "The text you entered has not been added yet and will be lost.",
              "gpt3-ai-content-generator"
            ),
          };
        }
        if (trainingCreateStoreName && trainingCreateStoreName.value.trim()) {
          return {
            title: __("Discard this source?", "gpt3-ai-content-generator"),
            message: __(
              "Your source and new store details have not been saved and will be lost.",
              "gpt3-ai-content-generator"
            ),
          };
        }
        return {
          title: __("Discard these changes?", "gpt3-ai-content-generator"),
          message: __(
            "Your source settings have not been saved and will be lost.",
            "gpt3-ai-content-generator"
          ),
        };
      };

      const setTrainingDiscardPromptOpen = (isOpen) => {
        if (!trainingDiscardPrompt) {
          return;
        }
        trainingDiscardPrompt.hidden = !isOpen;
        trainingPanel?.classList.toggle("is-confirming-discard", isOpen);
        trainingCard.inert = isOpen;
        if (isOpen) {
          const copy = getTrainingDiscardCopy();
          if (trainingDiscardTitle) {
            trainingDiscardTitle.textContent = copy.title;
          }
          if (trainingDiscardMessage) {
            trainingDiscardMessage.textContent = copy.message;
          }
          window.setTimeout(() => trainingDiscardKeepButton?.focus(), 0);
        } else {
          if (trainingDiscardKeepButton) {
            trainingDiscardKeepButton.disabled = false;
          }
          if (trainingDiscardConfirmButton) {
            trainingDiscardConfirmButton.disabled = false;
            trainingDiscardConfirmButton.textContent = __(
              "Discard",
              "gpt3-ai-content-generator"
            );
          }
        }
      };

      const clearTrainingDraft = () => {
        if (trainingTextInput) {
          trainingTextInput.value = "";
        }
        if (trainingQaQuestion) {
          trainingQaQuestion.value = "";
        }
        if (trainingQaAnswer) {
          trainingQaAnswer.value = "";
        }
        trainingSelectedFiles.clear();
        trainingFileRows.clear();
        if (trainingFileList) {
          trainingFileList.innerHTML = "";
        }
        if (trainingFilesInput) {
          trainingFilesInput.value = "";
        }
        if (trainingCreateStoreName) {
          trainingCreateStoreName.value = "";
        }
        if (trainingDismissBaseline) {
          const selectedTypes = new Set(trainingDismissBaseline.postTypes || []);
          trainingCard.querySelectorAll(".aipkit_wp_type_cb").forEach((checkbox) => {
            checkbox.checked = selectedTypes.has(checkbox.value);
          });
          const hiddenSelect = trainingCard.querySelector(
            "#aipkit_vs_wp_content_post_types"
          );
          if (hiddenSelect) {
            Array.from(hiddenSelect.options).forEach((option) => {
              option.selected = selectedTypes.has(option.value);
            });
          }
        }
        updateTrainingFileQueue();
      };

      const initTrainingWebsiteTypes = () => {
        const checkboxes = Array.from(
          trainingCard.querySelectorAll(".aipkit_wp_type_cb")
        );
        const hiddenSelect = trainingCard.querySelector(
          "#aipkit_vs_wp_content_post_types"
        );
        if (!checkboxes.length || !hiddenSelect) {
          return;
        }
        const syncHiddenSelect = () => {
          const checkedValues = checkboxes
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => checkbox.value);
          Array.from(hiddenSelect.options).forEach((option) => {
            option.selected = checkedValues.includes(option.value);
          });
          updateTrainingActionLabel("website");
        };
        checkboxes.forEach((checkbox) => {
          checkbox.addEventListener("change", syncHiddenSelect);
        });
        syncHiddenSelect();
      };

      const getEmbeddingOrWarn = () => {
        const embedding = getEmbeddingSelection();
        if (!embedding) {
          setTrainingStatus(
            __("Select an embedding model.", "gpt3-ai-content-generator"),
            "warning"
          );
          clearTrainingStatusSoon();
          return null;
        }
        return embedding;
      };

      const handleTrainingAction = async () => {
        if (isTraining) {
          requestTrainingStop();
          return;
        }
        const target = getTrainingTarget();
        if (!target) {
          setTrainingStatus(
            __("Select a provider and index first.", "gpt3-ai-content-generator"),
            "warning"
          );
          clearTrainingStatusSoon();
          return;
        }

        const activeTabKey = getActiveTrainingTabKey();
        let textValue = "";
        let filesToUpload = [];
        let websiteSettings = null;
        if (activeTabKey === "text") {
          textValue = trainingTextInput ? trainingTextInput.value.trim() : "";
          if (!textValue) {
            setTrainingStatus(
              __("Add text to train.", "gpt3-ai-content-generator"),
              "warning"
            );
            clearTrainingStatusSoon();
            return;
          }
        } else if (activeTabKey === "qa") {
          const question = trainingQaQuestion
            ? trainingQaQuestion.value.trim()
            : "";
          const answer = trainingQaAnswer ? trainingQaAnswer.value.trim() : "";
          if (!question || !answer) {
            setTrainingStatus(
              __("Add a question and answer to train.", "gpt3-ai-content-generator"),
              "warning"
            );
            clearTrainingStatusSoon();
            return;
          }
          textValue = `Q: ${question}\nA: ${answer}`;
        } else if (activeTabKey === "files") {
          if (!trainingFileQueueController || typeof window.aipkit_createKnowledgeBaseFileUploader !== "function") {
            setTrainingStatus(
              __("File uploads are unavailable. Please reload and try again.", "gpt3-ai-content-generator"),
              "error"
            );
            clearTrainingStatusSoon();
            return;
          }
          filesToUpload = Array.from(trainingSelectedFiles.values())
            .filter((entry) => entry && entry.state !== "success")
            .map((entry) => entry.file);
          if (!filesToUpload.length) {
            if (trainingFilesInput) {
              trainingFilesInput.click();
            }
            setTrainingStatus(
              __("Choose files to upload.", "gpt3-ai-content-generator"),
              "warning"
            );
            clearTrainingStatusSoon();
            return;
          }
        } else if (activeTabKey === "website") {
          const statusValue = getWebsiteStatusValue();
          const postTypes = getWebsitePostTypes();
          if (!postTypes.length) {
            setTrainingStatus(
              __("Select at least one post type.", "gpt3-ai-content-generator"),
              "warning"
            );
            clearTrainingStatusSoon();
            return;
          }
          websiteSettings = {
            statusValue,
            postTypes,
          };
        } else {
          setTrainingStatus(
            __("Select a training tab to continue.", "gpt3-ai-content-generator"),
            "warning"
          );
          clearTrainingStatusSoon();
          return;
        }

        let embedding = null;
        if (
          target.providerKey === "local" || target.providerKey === "pinecone" ||
          target.providerKey === "qdrant" ||
          target.providerKey === "chroma"
        ) {
          embedding = getEmbeddingOrWarn();
          if (!embedding) {
            return;
          }
        }

        trainingStopRequested = false;
        closeTrainingWhenStopped = false;
        trainingAbortController = new AbortController();
        isTraining = true;
        setTrainingRunningState(true);
        const activeAddButton = getTrainingAddButton(activeTabKey);
        trainingActionButtons.forEach((btn) => {
          if (activeTabKey === "website" && btn === activeAddButton) {
            return;
          }
          btn.disabled = true;
        });
        setTrainingStatus(__("Preparing", "gpt3-ai-content-generator"), "loading");

        try {
          if (activeTabKey === "files") {
            updateTrainingFileQueue();
            const uploadTrainingFile = window.aipkit_createKnowledgeBaseFileUploader({
              __, sprintf, TRAINING_STATUS_LABELS, normalizeProviderKey, getNonceValue,
              setTrainingFileStatus: trainingFileQueueController.setTrainingFileStatus,
              setTrainingStatus, fetchSources, pollGoogleFileSearchJob,
              getAbortSignal: () => trainingAbortController?.signal,
              getStopRequested: () => trainingStopRequested,
              createTrainingStoppedError,
            });
            const acceptedFileKeys = [];
            let failedFileCount = 0;
            let batchError = null;
            for (let index = 0; index < filesToUpload.length; index += 1) {
              throwIfTrainingStopped();
              const file = filesToUpload[index];
              const fileKey = trainingFileQueueController.getTrainingFileKey(file);
              setTrainingStatus(
                sprintf(
                  __("Uploading %1$d of %2$d", "gpt3-ai-content-generator"),
                  index + 1,
                  filesToUpload.length
                ),
                "loading"
              );
              trainingFileQueueController.setTrainingFileStatus(file, __("Uploading", "gpt3-ai-content-generator"), "loading");
              try {
                await uploadTrainingFile({ file, target, embedding });
                throwIfTrainingStopped();
                acceptedFileKeys.push(fileKey);
              } catch (error) {
                const stopped = isTrainingStoppedError(error);
                trainingFileQueueController.setTrainingFileStatus(
                  file,
                  stopped
                    ? TRAINING_STATUS_LABELS.stopped
                    : error?.message || TRAINING_STATUS_LABELS.failed,
                  stopped ? "warning" : "error"
                );
                if (stopped) {
                  throw createTrainingStoppedError();
                }
                failedFileCount += 1;
                if (error?.stop_batch) { batchError = error; break; }
              }
            }

            acceptedFileKeys.forEach((fileKey) => {
              trainingSelectedFiles.delete(fileKey);
              const row = trainingFileRows.get(fileKey);
              if (row) {
                row.remove();
              }
              trainingFileRows.delete(fileKey);
            });
            updateTrainingFileQueue();
            if (batchError) { setTrainingStatus(batchError.message, "error"); fetchSources(1); return; }
            if (failedFileCount > 0) {
              setTrainingStatus(
                sprintf(
                  _n(
                    "%s file could not be added. Retry it below.",
                    "%s files could not be added. Retry them below.",
                    failedFileCount,
                    "gpt3-ai-content-generator"
                  ),
                  failedFileCount.toLocaleString()
                ),
                "error"
              );
            } else {
              setTrainingStatus(
                __("Files added to knowledge base.", "gpt3-ai-content-generator"),
                "success"
              );
              setTrainingDismissBaseline();
              clearTrainingStatusSoon();
            }
            fetchSources(1);
            return;
          }

          if (activeTabKey === "website" && websiteSettings) {
            await indexKnowledgeBaseWebsite({
              websiteSettings, target, embedding,
              getNonceValue, trainingApiRequest, throwIfTrainingStopped,
              setTrainingStatus, setWebsiteStatus, clearTrainingStatusSoon,
              fetchSources, pollGoogleFileSearchJob, setTrainingDismissBaseline,
              __, _n, sprintf,
            });
            return;
          }

          await indexKnowledgeBaseText({
            textValue, target, embedding, generateQdrantPointId,
            getNonceValue, trainingApiRequest, pollGoogleFileSearchJob, __,
          });

          if (activeTabKey === "text" && trainingTextInput) {
            trainingTextInput.value = "";
          }
          if (activeTabKey === "qa") {
            if (trainingQaQuestion) {
              trainingQaQuestion.value = "";
            }
            if (trainingQaAnswer) {
              trainingQaAnswer.value = "";
            }
          }
          setTrainingStatus(
            __("Content added to knowledge base.", "gpt3-ai-content-generator"),
            "success"
          );
          setTrainingDismissBaseline();
          clearTrainingStatusSoon();
          fetchSources(1);
        } catch (error) {
          if (isTrainingStoppedError(error)) {
            setTrainingStatus(
              __("Adding stopped.", "gpt3-ai-content-generator"),
              "warning"
            );
            if (activeTabKey === "website") {
              setWebsiteStatus("", "");
              clearWebsiteStatusSoon();
            }
            clearTrainingStatusSoon();
            fetchSources(1);
          } else {
            setTrainingStatus(
              sprintf(
                __("Add data failed: %s", "gpt3-ai-content-generator"),
                error?.message || __("Add data failed.", "gpt3-ai-content-generator")
              ),
              "error"
            );
          }
        } finally {
          const shouldCloseTrainingModal = closeTrainingWhenStopped;
          isTraining = false;
          trainingStopRequested = false;
          closeTrainingWhenStopped = false;
          trainingAbortController = null;
          setTrainingRunningState(false);
          updateTrainingTargetState();
          updateTrainingFileQueue();
          if (trainingCancelButton) {
            trainingCancelButton.disabled = false;
            trainingCancelButton.removeAttribute("aria-disabled");
            trainingCancelButton.textContent = __(
              "Cancel",
              "gpt3-ai-content-generator"
            );
          }
          if (shouldCloseTrainingModal) {
            clearTrainingDraft();
            forceCloseTrainingModal();
          }
        }
      };

      const forceCloseTrainingModal = () => {
        if (!trainingPanel) {
          return;
        }
        setTrainingDiscardPromptOpen(false);
        setTrainingCreateStoreExpanded(false, { focus: false });
        trainingPanel.hidden = true;
        trainingPanel.classList.remove("is-open", "aipkit-active");
        trainingPanel.setAttribute("aria-hidden", "true");
        trainingToggle.setAttribute("aria-expanded", "false");
        document.body.classList.remove("aipkit-sources-training-modal-open");
      };

      const requestTrainingModalDismiss = () => {
        if (!trainingPanel || trainingPanel.hidden) {
          return;
        }
        if (!trainingDiscardPrompt?.hidden) {
          trainingDiscardKeepButton?.focus();
          return;
        }
        if (!hasTrainingDraft()) {
          forceCloseTrainingModal();
          return;
        }
        setTrainingDiscardPromptOpen(true);
      };

      const openTrainingModal = async () => {
        if (!trainingPanel) {
          return;
        }
        const filteredProviderKey = normalizeProviderKey(
          providerSelect ? providerSelect.value : ""
        );
        const currentProviderKey = normalizeProviderKey(
          trainingProviderSelect ? trainingProviderSelect.value : ""
        );
        const nextProvider = getProviderConfig(filteredProviderKey)
          ? filteredProviderKey
          : getProviderConfig(currentProviderKey)
          ? currentProviderKey
          : getDefaultTrainingProvider();
        if (trainingProviderSelect) {
          trainingProviderSelect.value = nextProvider;
        }
        populateTrainingStoreOptions(nextProvider);
        if (
          !Array.isArray(providerState.stores[nextProvider]) &&
          getProviderConfigured(nextProvider) !== false
        ) {
          populateTrainingStoreOptions(nextProvider, { loading: true });
          syncProviderStores(nextProvider, { silent: true }).then(() => {
            populateTrainingStoreOptions(nextProvider);
            updateTrainingTargetState();
          });
        }
        updateTrainingTargetState();
        populateEmbeddingOptions();
        applyStoredEmbeddingSelection(nextProvider);
        updateTrainingCreateStoreFields();
        setTrainingDiscardPromptOpen(false);
        setTrainingDismissBaseline();
        trainingPanel.hidden = false;
        trainingPanel.classList.add("is-open", "aipkit-active");
        trainingPanel.setAttribute("aria-hidden", "false");
        trainingToggle.setAttribute("aria-expanded", "true");
        document.body.classList.add("aipkit-sources-training-modal-open");
        window.setTimeout(() => {
          const activeTab = trainingPanel.querySelector(
            ".aipkit_builder_tab.is-active"
          );
          if (activeTab) {
            activeTab.focus();
          }
        }, 0);
      };

      trainingToggle.addEventListener("click", () => {
        if (trainingPanel && !trainingPanel.hidden) {
          requestTrainingModalDismiss();
          return;
        }
        openTrainingModal();
      });

      if (trainingCloseButton) {
        trainingCloseButton.addEventListener("click", requestTrainingModalDismiss);
      }
      if (trainingCancelButton) {
        trainingCancelButton.addEventListener("click", requestTrainingModalDismiss);
      }
      if (trainingPanel) {
        trainingPanel.addEventListener("keydown", (event) => {
          if (event.key === "Escape") {
            event.preventDefault();
            event.stopPropagation();
            if (!trainingDiscardPrompt?.hidden) {
              setTrainingDiscardPromptOpen(false);
              return;
            }
            requestTrainingModalDismiss();
          }
        });
      }
      if (trainingDiscardKeepButton) {
        trainingDiscardKeepButton.addEventListener("click", () => {
          setTrainingDiscardPromptOpen(false);
        });
      }
      if (trainingDiscardConfirmButton) {
        trainingDiscardConfirmButton.addEventListener("click", () => {
          if (isTraining) {
            trainingDiscardConfirmButton.disabled = true;
            trainingDiscardKeepButton.disabled = true;
            trainingDiscardConfirmButton.textContent = __(
              "Cancelling...",
              "gpt3-ai-content-generator"
            );
            requestTrainingStop({ closeModal: true });
            return;
          }
          clearTrainingDraft();
          forceCloseTrainingModal();
        });
      }
      if (trainingProviderSelect) {
        trainingProviderSelect.addEventListener("change", () => {
          const providerKey = normalizeProviderKey(trainingProviderSelect.value);
          populateTrainingStoreOptions(providerKey);
          updateTrainingTargetState();
          populateEmbeddingOptions();
          applyStoredEmbeddingSelection(providerKey);
          updateTrainingCreateStoreFields();
          if (trainingCreateStoreStatus) {
            setStatusMessage(trainingCreateStoreStatus, "", "");
          }
          if (
            !Array.isArray(providerState.stores[providerKey]) &&
            getProviderConfigured(providerKey) !== false
          ) {
            populateTrainingStoreOptions(providerKey, { loading: true });
            syncProviderStores(providerKey, { silent: true }).then(() => {
              populateTrainingStoreOptions(providerKey);
              updateTrainingTargetState();
            });
          }
        });
      }
      if (trainingStoreSelect) {
        trainingStoreSelect.addEventListener("change", () => {
          updateTrainingTargetState();
        });
      }
      if (trainingRefreshStoresButton) {
        trainingRefreshStoresButton.addEventListener("click", async () => {
          const providerKey = normalizeProviderKey(
            trainingProviderSelect ? trainingProviderSelect.value : ""
          );
          populateTrainingStoreOptions(providerKey, { loading: true });
          await syncProviderStores(providerKey, {
            triggerButton: trainingRefreshStoresButton,
            triggerDefaultLabel: __("Sync", "gpt3-ai-content-generator"),
            triggerLoadingLabel: __("Syncing...", "gpt3-ai-content-generator"),
          });
          populateTrainingStoreOptions(providerKey);
          updateTrainingTargetState();
        });
      }
      if (trainingCreateStoreButton) {
        trainingCreateStoreButton.addEventListener("click", () => {
          setTrainingCreateStoreExpanded(
            trainingCreateStoreButton.getAttribute("aria-expanded") !== "true"
          );
        });
      }
      if (trainingCreateStoreSubmit) {
        trainingCreateStoreSubmit.addEventListener(
          "click",
          createProviderStoreInline
        );
      }
      [trainingCreateStoreName, trainingCreateStoreDimension]
        .filter(Boolean)
        .forEach((field) => {
          field.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
              event.preventDefault();
              createProviderStoreInline();
            }
          });
        });

      if (trainingTabList) {
        const activeTab = trainingTabList.querySelector(
          ".aipkit_builder_tab.is-active"
        );
        if (activeTab) {
          setActiveTrainingTab(activeTab.dataset.aipkitTab);
        }
        trainingTabList
          .querySelectorAll(".aipkit_builder_tab[data-aipkit-tab]")
          .forEach((tab) => {
            tab.addEventListener("click", () => {
              setActiveTrainingTab(tab.dataset.aipkitTab);
            });
          });
      }

      trainingFileQueueController?.bindInputs();

      if (trainingCommonQuestionsToggle && trainingCommonQuestionsPanel) {
        trainingCommonQuestionsToggle.addEventListener("click", () => {
          setCommonQuestionsOpen(
            trainingCommonQuestionsToggle.getAttribute("aria-expanded") !== "true"
          );
        });
        trainingCommonQuestionsPanel
          .querySelectorAll("[data-aipkit-common-question]")
          .forEach((button) => {
            button.addEventListener("click", () => {
              appendCommonQuestion(button.dataset.aipkitCommonQuestion || "");
            });
          });
        document.addEventListener("click", (event) => {
          if (
            trainingCommonQuestionsToggle.getAttribute("aria-expanded") === "true" &&
            !trainingCommonQuestionsToggle.contains(event.target) &&
            !trainingCommonQuestionsPanel.contains(event.target)
          ) {
            setCommonQuestionsOpen(false);
          }
        });
      }

      trainingActionButtons.forEach((button) => {
        button.addEventListener("click", handleTrainingAction);
      });

      if (embeddingSelect) {
        embeddingSelect.addEventListener("change", () => {
          const providerKey = normalizeProviderKey(
            trainingProviderSelect ? trainingProviderSelect.value : ""
          );
          if (!providerKey) {
            return;
          }
          state.embeddingByProvider[providerKey] = embeddingSelect.value || "";
          persistSourcePreferences();
        });
      }

      initTrainingWebsiteTypes();
      if (trainingProviderSelect && !getProviderConfig(trainingProviderSelect.value)) {
        trainingProviderSelect.value = getDefaultTrainingProvider();
      }
      populateTrainingStoreOptions(
        trainingProviderSelect ? trainingProviderSelect.value : ""
      );
      updateTrainingTargetState();
      populateEmbeddingOptions();
      applyStoredEmbeddingSelection(
        trainingProviderSelect ? trainingProviderSelect.value : ""
      );

      return {
        updateTargetState: updateTrainingTargetState,
        close: requestTrainingModalDismiss,
      };
    };

    trainingControls = initTrainingCard();

    workspaceTabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        setWorkspaceTab(tab.dataset.aipkitSourcesTab);
      });
    });
    if (storeProviderFilter) {
      storeProviderFilter.addEventListener("change", renderStoresTable);
    }
    if (storesRefreshButton) {
      storesRefreshButton.addEventListener("click", () => {
        refreshStoreManagementProviders(storesRefreshButton);
      });
    }
    if (createStoreButton) {
      createStoreButton.addEventListener("click", () => {
        openStoreModal({
          provider: storeProviderFilter ? storeProviderFilter.value : "",
        });
      });
    }
    if (storeModalProvider) {
      storeModalProvider.addEventListener("change", updateStoreModalFields);
    }
    if (storeModalClose) {
      storeModalClose.addEventListener("click", closeStoreModal);
    }
    if (storeModalCancel) {
      storeModalCancel.addEventListener("click", closeStoreModal);
    }
    if (storeModal) {
      storeModal.addEventListener("keydown", (event) => {
        if (event.key === "Enter" && event.target !== storeModalCancel) {
          event.preventDefault();
          createProviderStoreFromModal();
        }
      });
    }
    if (storeModalCreate) {
      storeModalCreate.addEventListener("click", createProviderStoreFromModal);
    }
    if (storesTableBody) {
      storesTableBody.addEventListener("click", (event) => {
        if (event.target.closest(".aipkit_sources_store_create_local_btn")) {
          openStoreModal({ provider: "local" });
          return;
        }
        const connectButton = event.target.closest(
          ".aipkit_sources_store_connect_btn"
        );
        if (connectButton) {
          const providerKey = normalizeProviderKey(
            connectButton.dataset.provider || ""
          );
          if (providerKey && providerKey !== "openai") {
            window.__aipkitRequestedIntegrationCard = providerKey;
          }
          return;
        }

        const deleteButton = event.target.closest(".aipkit_sources_store_delete_btn");
        if (!deleteButton) {
          return;
        }
        confirmProviderStoreDelete({
          provider: deleteButton.dataset.provider,
          storeId: deleteButton.dataset.storeId,
          storeName: deleteButton.dataset.storeName,
        });
      });
    }

    if (providerSelect) {
      providerSelect.addEventListener("change", () => {
        state.provider = providerSelect.value;
        setActiveProvider(state.provider);
        trainingControls?.updateTargetState?.();
        persistSourcePreferences();
        fetchSources(1);
      });
    }

    const runSearch = () => {
      if (!searchInput) {
        return;
      }
      const nextValue = searchInput.value.trim();
      if (nextValue === state.search) {
        return;
      }
      state.search = nextValue;
      fetchSources(1);
    };

    const queueSearch = () => {
      if (!searchInput) {
        return;
      }
      if (searchTimer) {
        window.clearTimeout(searchTimer);
      }
      searchTimer = window.setTimeout(() => {
        runSearch();
      }, 300);
    };

    if (searchInput) {
      searchInput.addEventListener("input", queueSearch);
      searchInput.addEventListener("search", runSearch);
      searchInput.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          if (searchTimer) {
            window.clearTimeout(searchTimer);
          }
          runSearch();
        }
      });
    }

    if (indexSelect) {
      indexSelect.addEventListener("change", () => {
        const normalizedKey = normalizeProviderKey(
          providerSelect ? providerSelect.value : ""
        );
        if (!normalizedKey) {
          return;
        }
        state.indexByProvider[normalizedKey] = indexSelect.value || "";
        persistSourcePreferences();
        trainingControls?.updateTargetState?.();
        fetchSources(1);
      });
    }

    sourceEditor.bind();

    if (viewClose) {
      viewClose.addEventListener("click", closeViewModal);
    }
    if (viewCloseBtn) {
      viewCloseBtn.addEventListener("click", closeViewModal);
    }
    if (selectAllCheckbox) {
      selectAllCheckbox.addEventListener("change", () => {
        if (isBulkDeleting) {
          return;
        }
        failedBulkSources.clear();
        getVisibleSourceCheckboxes().forEach((checkbox) => {
          if (checkbox.disabled) {
            return;
          }
          checkbox.checked = selectAllCheckbox.checked;
          const source = getSourceFromCheckbox(checkbox);
          setSourceSelection(source, checkbox.checked);
          updateRowSelectedState(checkbox);
        });
        setBulkBarState("");
      });
    }

    if (bulkDeleteButton) {
      bulkDeleteButton.addEventListener("click", () => {
        if (!selectedSources.size || isBulkDeleting) {
          return;
        }
        confirmSourceDelete({
          mode: "bulk",
          sources: Array.from(selectedSources.values()),
        });
      });
    }

    if (bulkRetryButton) {
      bulkRetryButton.addEventListener("click", () => {
        if (!failedBulkSources.size || isBulkDeleting) {
          return;
        }
        runBulkDelete(Array.from(failedBulkSources.values()));
      });
    }

    if (bulkClearButton) {
      bulkClearButton.addEventListener("click", clearBulkSelection);
    }

    tableBody.addEventListener("change", (event) => {
      const checkbox = event.target.closest(".aipkit_sources_row_checkbox");
      if (!checkbox || isBulkDeleting) {
        return;
      }
      const source = getSourceFromCheckbox(checkbox);
      if (!source) {
        checkbox.checked = false;
        updateRowSelectedState(checkbox);
        setBulkBarState("");
        return;
      }
      setSourceSelection(source, checkbox.checked);
      updateRowSelectedState(checkbox);
      setBulkBarState("");
    });

    if (editorSave) {
      editorSave.addEventListener("click", async () => {
        if (
          !editorModal ||
          !editorTextarea ||
          !editorQuestion ||
          !editorAnswer ||
          editorSave.disabled
        ) {
          return;
        }
        const sourceKind = editorModal.dataset.sourceKind === "qa" ? "qa" : "text";
        const question = editorQuestion.value.trim();
        const answer = editorAnswer.value.trim();
        const newText =
          sourceKind === "qa"
            ? `Q: ${question}\nA: ${answer}`
            : editorTextarea.value.trim();
        if (!newText) {
          setStatusMessage(
            statusEl,
            sourceKind === "qa"
              ? __(
                  "Question and answer cannot be empty.",
                  "gpt3-ai-content-generator"
                )
              : __("Text cannot be empty.", "gpt3-ai-content-generator"),
            "error"
          );
          clearStatusSoon();
          return;
        }

        const providerLabel = editorModal.dataset.provider || "";
        const storeId = editorModal.dataset.storeId || "";
        const vectorId = editorModal.dataset.vectorId || "";
        const logId = editorModal.dataset.logId || "";
        const embeddingProvider = editorModal.dataset.embeddingProvider || "";
        const embeddingModel = editorModal.dataset.embeddingModel || "";

        if (!providerLabel || !storeId || !vectorId || !logId) {
          setStatusMessage(
            statusEl,
            __("Missing source details.", "gpt3-ai-content-generator"),
            "error"
          );
          clearStatusSoon();
          return;
        }

        sourceEditor.close();
        let rowButtons = [];
        if (tableBody && logId) {
          const safeId = getSafeSelector(logId);
          const row = tableBody.querySelector(`tr[data-log-id="${safeId}"]`);
          rowButtons = row
            ? Array.from(row.querySelectorAll(SOURCE_ROW_CONTROL_SELECTOR))
            : [];
          rowButtons.forEach((button) => {
            button.disabled = true;
            button.setAttribute("aria-disabled", "true");
            if (button.classList.contains("aipkit_sources_action_edit")) {
              button.textContent = __("Training...", "gpt3-ai-content-generator");
            }
          });
        }

        setStatusMessage(statusEl, __("Training...", "gpt3-ai-content-generator"), "");
        try {
          await window.aipkit_apiRequest("aipkit_delete_vector_data_source_entry", {
            provider: providerLabel,
            store_id: storeId,
            vector_id: vectorId,
            log_id: logId,
          });
          await upsertEditedText(
            providerLabel,
            storeId,
            newText,
            embeddingProvider,
            embeddingModel
          );
          setStatusMessage(statusEl, __("Trained", "gpt3-ai-content-generator"), "success");
          clearStatusSoon();
          fetchSources(state.page || 1);
        } catch (error) {
          const errorMessage =
            error && error.message
              ? error.message
              : __("Failed to save source.", "gpt3-ai-content-generator");
          setStatusMessage(
            statusEl,
            `${ERROR_PREFIX} ${errorMessage}`,
            "error"
          );
          clearStatusSoon();
          rowButtons.forEach((button) => {
            button.disabled = false;
            button.removeAttribute("aria-disabled");
            if (button.classList.contains("aipkit_sources_action_edit")) {
              button.textContent = __("Edit", "gpt3-ai-content-generator");
            }
          });
        }
      });
    }

    moduleContainer.addEventListener("click", async (event) => {
      const menuTrigger = event.target.closest(".aipkit_sources_action_menu_trigger");
      if (menuTrigger) {
        event.preventDefault();
        event.stopPropagation();
        openSourceActionMenu(menuTrigger);
        return;
      }

      if (event.target.closest(".aipkit_sources_action_menu_item")) {
        closeSourceActionMenu();
      }

      const editButton = event.target.closest(".aipkit_sources_action_edit");
      if (editButton) {
        sourceEditor.open(editButton);
        return;
      }

      const viewButton = event.target.closest(".aipkit_sources_action_view");
      if (viewButton) {
        const snippet = viewButton.dataset.snippet || "";
        if (!snippet) {
          setStatusMessage(
            statusEl,
            __("No preview available for this source.", "gpt3-ai-content-generator"),
            "error"
          );
          clearStatusSoon();
          return;
        }
        openViewModal(snippet, getLogForActionButton(viewButton));
        return;
      }

      const reasonButton = event.target.closest(".aipkit_sources_action_reason");
      if (reasonButton) {
        const reason = reasonButton.dataset.reason || "";
        if (!reason) {
          setStatusMessage(
            statusEl,
            __("No failure reason available.", "gpt3-ai-content-generator"),
            "error"
          );
          clearStatusSoon();
          return;
        }
        openViewModal(reason);
        return;
      }

      const deleteButton = event.target.closest(".aipkit_sources_action_delete");
      if (deleteButton) {
        const provider = deleteButton.dataset.provider || "";
        const storeId = deleteButton.dataset.storeId || "";
        const vectorId = deleteButton.dataset.vectorId || "";
        const logId = deleteButton.dataset.logId || "";
        if (!provider || !storeId || !logId) {
          setStatusMessage(
            statusEl,
            __("Missing source details.", "gpt3-ai-content-generator"),
            "error"
          );
          clearStatusSoon();
          return;
        }
        confirmSourceDelete({
          mode: "single",
          source: { provider, storeId, vectorId, logId },
        });
        return;
      }

      const retrainButton = event.target.closest(".aipkit_sources_action_retrain");
      if (retrainButton) {
        const provider = retrainButton.dataset.provider || "";
        const storeId = retrainButton.dataset.storeId || "";
        const vectorId = retrainButton.dataset.vectorId || "";
        const logId = retrainButton.dataset.logId || "";
        const postId = retrainButton.dataset.postId || "";
        const embeddingProvider = retrainButton.dataset.embeddingProvider || "";
        const embeddingModel = retrainButton.dataset.embeddingModel || "";
        if (!provider || !storeId || !vectorId || !logId || !postId) {
          setStatusMessage(
            statusEl,
            __("Missing source details.", "gpt3-ai-content-generator"),
            "error"
          );
          clearStatusSoon();
          return;
        }

        const row = retrainButton.closest("tr");
        const rowButtons = row
          ? Array.from(row.querySelectorAll(SOURCE_ROW_CONTROL_SELECTOR))
          : [];
        rowButtons.forEach((button) => {
          button.disabled = true;
          button.setAttribute("aria-disabled", "true");
        });
        retrainButton.textContent = __("Updating...", "gpt3-ai-content-generator");
        setStatusMessage(statusEl, __("Updating...", "gpt3-ai-content-generator"), "");

        try {
          const response = await window.aipkit_apiRequest("aipkit_reindex_vector_data_source_entry", {
            provider: provider,
            store_id: storeId,
            vector_id: vectorId,
            log_id: logId,
            post_id: postId,
            embedding_provider: embeddingProvider,
            embedding_model: embeddingModel,
          });
          setStatusMessage(statusEl, response.processing ? __("Submitted — processing in background", "gpt3-ai-content-generator") : __("Updated", "gpt3-ai-content-generator"), response.processing ? "info" : "success");
          clearStatusSoon();
          fetchSources(state.page || 1, { silent: true });
        } catch (error) {
          const errorMessage =
            error && error.message
              ? error.message
              : __("Failed to retrain source.", "gpt3-ai-content-generator");
          setStatusMessage(
            statusEl,
            `${ERROR_PREFIX} ${errorMessage}`,
            "error"
          );
          clearStatusSoon();
          rowButtons.forEach((button) => {
            button.disabled = false;
            button.removeAttribute("aria-disabled");
          });
          retrainButton.textContent = __("Update", "gpt3-ai-content-generator");
        }
      }
    });

    document.addEventListener("click", (event) => {
      if (
        openActionMenu &&
        !event.target.closest(".aipkit_sources_actions")
      ) {
        closeSourceActionMenu();
      }
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeSourceActionMenu();
      }
    });
    window.addEventListener("resize", closeSourceActionMenu, { passive: true });
    document.addEventListener("scroll", closeSourceActionMenu, {
      capture: true,
      passive: true,
    });

    setActiveProvider(providerSelect ? providerSelect.value : "");

    if (typeof window.aipkit_initContentWriterPopovers === "function") {
      window.aipkit_initContentWriterPopovers(moduleContainer);
    }
    if (typeof window.aipkit_initApiKeyToggles === "function") {
      window.aipkit_initApiKeyToggles("#aipkit_sources_module_container");
    }
    if (typeof window.aipkit_initKnowledgeBaseSettingsUI === "function") {
      window.aipkit_initKnowledgeBaseSettingsUI();
    }
    fetchSources(1);
  }

  window.aipkit_initSources = aipkit_initSources;
})();
