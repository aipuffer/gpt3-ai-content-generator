(function () {
  "use strict";

  const STATUS_CLASS = "aipkit_chat_status";
  const GROUNDING_CLASS = "aipkit_grounding_widget";
  const CITATIONS_CLASS = "aipkit_citations_widget";

  function sanitizeCitationText(value) {
    const rawValue = typeof value === "string" ? value.trim() : "";
    if (rawValue === "") {
      return "";
    }

    const tempEl = document.createElement("div");
    tempEl.innerHTML = rawValue;
    return (tempEl.textContent || tempEl.innerText || "")
      .replace(/\s+/g, " ")
      .trim();
  }

  function getTextLabel(config, key, fallback) {
    const labels = config && config.text ? config.text : {};
    const value = labels[key];
    return typeof value === "string" && value.trim() !== ""
      ? value.trim()
      : fallback;
  }

  function shouldShowSources(config) {
    if (config && config.provider === "Google") {
      return true;
    }

    return !(
      config &&
      Object.prototype.hasOwnProperty.call(config, "showSources") &&
      config.showSources === false
    );
  }

  function removeMetaElement(element) {
    if (element && element.parentNode) {
      element.parentNode.removeChild(element);
    }
  }

  function insertMetaElement(messageEl, element) {
    if (!messageEl || !element) {
      return;
    }
    const anchor = messageEl.querySelector(".aipkit_message_actions");
    if (anchor && anchor.parentNode === messageEl) {
      messageEl.insertBefore(element, anchor);
      return;
    }
    messageEl.appendChild(element);
  }

  function renderIsolatedProviderContent(element, renderedContent) {
    if (!element) {
      return;
    }

    if (typeof element.attachShadow !== "function") {
      element.innerHTML = renderedContent;
      return;
    }

    element.textContent = "";
    const renderRoot =
      element.shadowRoot || element.attachShadow({ mode: "open" });
    renderRoot.innerHTML = renderedContent;
  }

  function upsertStatusWidget(messageEl, statusText) {
    if (!messageEl) {
      return;
    }

    let statusEl = messageEl.querySelector(`.${STATUS_CLASS}`);
    const normalizedText =
      typeof statusText === "string" ? statusText.trim() : "";

    if (normalizedText === "") {
      removeMetaElement(statusEl);
      return;
    }

    if (!statusEl) {
      statusEl = document.createElement("div");
      statusEl.className = STATUS_CLASS;
      statusEl.setAttribute("role", "status");
      statusEl.setAttribute("aria-live", "polite");
    }

    statusEl.textContent = normalizedText;
    insertMetaElement(messageEl, statusEl);
  }

  function upsertGroundingWidget(messageEl, groundingMetadata, config) {
    if (!messageEl) {
      return;
    }

    let groundingEl = messageEl.querySelector(`.${GROUNDING_CLASS}`);
    const showSources = shouldShowSources(config);
    const renderedContent =
      groundingMetadata &&
      groundingMetadata.searchEntryPoint &&
      typeof groundingMetadata.searchEntryPoint.renderedContent === "string"
        ? groundingMetadata.searchEntryPoint.renderedContent
        : "";

    if (!showSources || renderedContent === "") {
      removeMetaElement(groundingEl);
      return;
    }

    if (!groundingEl) {
      groundingEl = document.createElement("div");
      groundingEl.className = GROUNDING_CLASS;
    }

    // Google supplies compliant HTML and CSS with intentionally generic class
    // names. Keep it unchanged inside a shadow root so host/chat styles cannot
    // alter the Search Suggestions and Google's styles cannot leak outward.
    renderIsolatedProviderContent(groundingEl, renderedContent);
    insertMetaElement(messageEl, groundingEl);
  }

  function buildCitationKey(citation) {
    if (!citation || typeof citation !== "object") {
      return "";
    }

    const keyParts = [
      citation.type || "",
      citation.url || "",
      citation.document_title || citation.title || "",
      citation.document_index ?? "",
      citation.start_char_index ?? "",
      citation.end_char_index ?? "",
      citation.start_page_number ?? "",
      citation.end_page_number ?? "",
      citation.start_block_index ?? "",
      citation.end_block_index ?? "",
      citation.cited_text || "",
    ];

    return keyParts.join("|");
  }

  function isKnowledgeBaseCitation(citation) {
    if (!citation || typeof citation !== "object") {
      return false;
    }

    const sourceType =
      typeof citation.source_type === "string" ? citation.source_type : "";
    const citationType =
      typeof citation.type === "string" ? citation.type : "";
    const hasUrl =
      typeof citation.url === "string" && citation.url.trim() !== "";

    return (
      sourceType === "knowledge_base" ||
      citationType === "file_citation" ||
      citationType === "file_path" ||
      (!hasUrl &&
        (typeof citation.document_title === "string" ||
          citation.document_index !== undefined))
    );
  }

  function filterDisplayCitations(citations) {
    if (!Array.isArray(citations)) {
      return [];
    }

    return citations.filter((citation) => !isKnowledgeBaseCitation(citation));
  }

  function normalizeCitations(citations) {
    if (!Array.isArray(citations)) {
      return [];
    }

    const seen = new Set();
    const normalized = [];

    citations.forEach((citation) => {
      if (!citation || typeof citation !== "object") {
        return;
      }

      const key = buildCitationKey(citation);
      if (key && seen.has(key)) {
        return;
      }
      if (key) {
        seen.add(key);
      }
      normalized.push(citation);
    });

    return normalized;
  }

  function getCitationTitle(citation, index, config) {
    const fallback = `${getTextLabel(config, "source", "Source")} ${index + 1}`;
    const explicitTitle = [
      citation.document_title,
      citation.title,
      citation.website_title,
      citation.source_title,
    ].find((value) => typeof value === "string" && value.trim() !== "");

    if (explicitTitle) {
      return sanitizeCitationText(explicitTitle) || explicitTitle.trim();
    }

    if (typeof citation.url === "string" && citation.url.trim() !== "") {
      try {
        return new URL(citation.url).hostname;
      } catch (error) {
        return citation.url.trim();
      }
    }

    return fallback;
  }

  function getCitationLocation(citation) {
    if (!citation || typeof citation !== "object") {
      return "";
    }

    switch (citation.type) {
      case "char_location":
        if (
          citation.start_char_index !== undefined &&
          citation.end_char_index !== undefined
        ) {
          return `Chars ${citation.start_char_index}-${citation.end_char_index}`;
        }
        break;
      case "page_location":
        if (
          citation.start_page_number !== undefined &&
          citation.end_page_number !== undefined
        ) {
          return `Pages ${citation.start_page_number}-${citation.end_page_number}`;
        }
        break;
      case "content_block_location":
        if (
          citation.start_block_index !== undefined &&
          citation.end_block_index !== undefined
        ) {
          return `Blocks ${citation.start_block_index}-${citation.end_block_index}`;
        }
        break;
      default:
        break;
    }

    return "";
  }

  function upsertCitationsWidget(messageEl, citations, config) {
    if (!messageEl) {
      return;
    }

    let citationsEl = messageEl.querySelector(`.${CITATIONS_CLASS}`);
    const showSources = shouldShowSources(config);
    const normalizedCitations = filterDisplayCitations(
      normalizeCitations(citations)
    );

    if (!showSources || normalizedCitations.length === 0) {
      removeMetaElement(citationsEl);
      return;
    }

    if (!citationsEl) {
      citationsEl = document.createElement("details");
      citationsEl.className = CITATIONS_CLASS;
    }

    citationsEl.textContent = "";

    const count = normalizedCitations.length;
    const summaryEl = document.createElement("summary");
    summaryEl.className = "aipkit_citations_toggle";

    const toggleIconEl = document.createElement("span");
    toggleIconEl.className = "aipkit_citations_toggle_icon";
    toggleIconEl.setAttribute("aria-hidden", "true");
    toggleIconEl.textContent = "\u203A";
    summaryEl.appendChild(toggleIconEl);

    const toggleLabelEl = document.createElement("span");
    toggleLabelEl.className = "aipkit_citations_toggle_label";
    toggleLabelEl.textContent = `${getTextLabel(
      config,
      count === 1 ? "source" : "sources",
      count === 1 ? "Source" : "Sources"
    )} (${count})`;
    summaryEl.appendChild(toggleLabelEl);

    citationsEl.appendChild(summaryEl);

    const panelEl = document.createElement("div");
    panelEl.className = "aipkit_citations_panel";

    const listEl = document.createElement("ol");
    listEl.className = "aipkit_citations_list";

    normalizedCitations.forEach((citation, index) => {
      const itemEl = document.createElement("li");
      itemEl.className = "aipkit_citations_item";

      const headerEl = document.createElement("div");
      headerEl.className = "aipkit_citations_item_header";

      const title = getCitationTitle(citation, index, config);
      if (typeof citation.url === "string" && citation.url.trim() !== "") {
        const linkEl = document.createElement("a");
        linkEl.className = "aipkit_citations_item_link";
        linkEl.href = citation.url.trim();
        linkEl.target = "_blank";
        linkEl.rel = "noopener noreferrer";
        linkEl.textContent = title;
        headerEl.appendChild(linkEl);
      } else {
        const titleEl = document.createElement("span");
        titleEl.className = "aipkit_citations_item_title";
        titleEl.textContent = title;
        headerEl.appendChild(titleEl);
      }

      const location = getCitationLocation(citation);
      if (location !== "") {
        const locationEl = document.createElement("span");
        locationEl.className = "aipkit_citations_item_location";
        locationEl.textContent = location;
        headerEl.appendChild(locationEl);
      }

      itemEl.appendChild(headerEl);

      if (
        typeof citation.cited_text === "string" &&
        citation.cited_text.trim() !== ""
      ) {
        const excerptText = sanitizeCitationText(citation.cited_text);
        if (excerptText !== "") {
          const excerptEl = document.createElement("div");
          excerptEl.className = "aipkit_citations_item_excerpt";
          excerptEl.textContent = excerptText;
          itemEl.appendChild(excerptEl);
        }
      }

      listEl.appendChild(itemEl);
    });

    panelEl.appendChild(listEl);
    citationsEl.appendChild(panelEl);
    insertMetaElement(messageEl, citationsEl);
  }

  function aipkit_chatUI_upsertMessageMeta(messageEl, meta, config) {
    if (!messageEl || !meta || typeof meta !== "object") {
      return;
    }

    if (Object.prototype.hasOwnProperty.call(meta, "statusText")) {
      upsertStatusWidget(messageEl, meta.statusText);
    }
    if (Object.prototype.hasOwnProperty.call(meta, "groundingMetadata")) {
      upsertGroundingWidget(messageEl, meta.groundingMetadata, config);
    }
    if (Object.prototype.hasOwnProperty.call(meta, "citations")) {
      upsertCitationsWidget(messageEl, meta.citations, config);
    }
  }

  window.aipkit_chatUI_upsertMessageMeta = aipkit_chatUI_upsertMessageMeta;
  window.aipkit_chatUI_normalizeCitations = normalizeCitations;
  window.aipkit_chatUI_filterDisplayCitations = filterDisplayCitations;
})();
