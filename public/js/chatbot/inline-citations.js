(function () {
  "use strict";

  function trimString(value) {
    return typeof value === "string" ? value.trim() : "";
  }

  function normalizeUrl(value) {
    const rawValue = trimString(value);
    if (rawValue === "") {
      return "";
    }

    try {
      const parsed = new URL(rawValue);
      parsed.hash = "";
      return parsed.toString().replace(/\/$/, "").toLowerCase();
    } catch (error) {
      return rawValue.replace(/\/$/, "").toLowerCase();
    }
  }

  function normalizeDomain(value) {
    const rawValue = trimString(value);
    if (rawValue === "") {
      return "";
    }

    const withoutProtocol = rawValue
      .replace(/^https?:\/\//i, "")
      .replace(/^www\./i, "")
      .replace(/\/.*$/, "")
      .replace(/[)\],.;:!?]+$/, "")
      .trim()
      .toLowerCase();

    return withoutProtocol;
  }

  function getCitationUrl(citation) {
    if (!citation || typeof citation !== "object") {
      return "";
    }

    const value = citation.url || citation.uri || "";
    return trimString(value);
  }

  function buildCitationMatchers(citations) {
    const normalize =
      typeof window.aipkit_chatUI_normalizeCitations === "function"
        ? window.aipkit_chatUI_normalizeCitations
        : function (items) {
            return Array.isArray(items) ? items : [];
          };

    return normalize(citations)
      .map((citation, index) => {
        const url = getCitationUrl(citation);
        const normalizedUrl = normalizeUrl(url);
        const normalizedDomain = normalizeDomain(url);

        return {
          citation,
          index: index + 1,
          url,
          normalizedUrl,
          normalizedDomain,
        };
      })
      .filter((entry) => entry.normalizedUrl !== "" || entry.normalizedDomain !== "");
  }

  function findCitationMatchByUrlOrDomain(value, matchers) {
    const normalizedUrl = normalizeUrl(value);
    const normalizedDomain = normalizeDomain(value);

    return (
      matchers.find((entry) => entry.normalizedUrl !== "" && entry.normalizedUrl === normalizedUrl) ||
      matchers.find((entry) => entry.normalizedDomain !== "" && entry.normalizedDomain === normalizedDomain) ||
      null
    );
  }

  function createMarkerNode(match) {
    const supEl = document.createElement("sup");
    supEl.className = "aipkit_inline_citation";

    const linkEl = document.createElement("a");
    linkEl.className = "aipkit_inline_citation_ref";
    linkEl.href = match.url || "#";
    linkEl.textContent = `[${match.index}]`;

    if (match.url) {
      linkEl.target = "_blank";
      linkEl.rel = "noopener noreferrer";
    } else {
      linkEl.removeAttribute("href");
    }

    const title =
      (match.citation && (match.citation.document_title || match.citation.title || match.citation.website_title || match.citation.source_title)) ||
      match.normalizedDomain ||
      "";
    if (title) {
      linkEl.title = title;
      linkEl.setAttribute("aria-label", `Source ${match.index}: ${title}`);
    } else {
      linkEl.setAttribute("aria-label", `Source ${match.index}`);
    }

    supEl.appendChild(linkEl);
    return supEl;
  }

  function trimCitationParenthesesAroundNode(node) {
    if (!node || !node.parentNode) {
      return;
    }

    const previous = node.previousSibling;
    if (previous && previous.nodeType === Node.TEXT_NODE) {
      const updated = previous.textContent.replace(/\s*\(\s*$/, "");
      if (updated !== previous.textContent) {
        previous.textContent = updated;
      }
      if (previous.textContent === "") {
        previous.parentNode.removeChild(previous);
      }
    }

    const next = node.nextSibling;
    if (next && next.nodeType === Node.TEXT_NODE) {
      const updated = next.textContent.replace(/^\s*\)\s*/, "");
      if (updated !== next.textContent) {
        next.textContent = updated;
      }
      if (next.textContent.trim() === "") {
        next.parentNode.removeChild(next);
      }
    }
  }

  function replaceParentheticalCitationText(textNode, matchers) {
    if (
      !textNode ||
      !textNode.parentElement ||
      textNode.parentElement.closest(
        "a, pre, code, .aipkit_citations_widget, .aipkit_grounding_widget"
      )
    ) {
      return;
    }

    const text = textNode.textContent;
    if (typeof text !== "string" || text.indexOf("(") === -1) {
      return;
    }

    const pattern = /\(\s*((?:https?:\/\/)?(?:www\.)?[a-z0-9.-]+\.[a-z]{2,}(?:\/[^\s)]*)?)\s*\)/gi;
    let match;
    let lastIndex = 0;
    let changed = false;
    const fragment = document.createDocumentFragment();

    while ((match = pattern.exec(text)) !== null) {
      const found = findCitationMatchByUrlOrDomain(match[1], matchers);
      if (!found) {
        continue;
      }

      changed = true;
      const prefix = text.slice(lastIndex, match.index);
      if (prefix) {
        fragment.appendChild(document.createTextNode(prefix));
      }
      fragment.appendChild(createMarkerNode(found));
      lastIndex = pattern.lastIndex;
    }

    if (!changed) {
      return;
    }

    const suffix = text.slice(lastIndex);
    if (suffix) {
      fragment.appendChild(document.createTextNode(suffix));
    }

    textNode.parentNode.replaceChild(fragment, textNode);
  }

  function replaceRawCitationAnchors(container, matchers) {
    if (!container) {
      return;
    }

    const anchors = Array.from(container.querySelectorAll("a")).filter((anchor) => {
      return !anchor.closest(
        "pre, code, .aipkit_citations_widget, .aipkit_grounding_widget"
      );
    });

    anchors.forEach((anchor) => {
      const anchorText = (anchor.textContent || "").trim();
      const href = (anchor.getAttribute("href") || "").trim();
      const match = findCitationMatchByUrlOrDomain(href || anchorText, matchers);

      if (!match) {
        return;
      }

      const rawDomainText = normalizeDomain(anchorText);
      if (rawDomainText === "") {
        return;
      }

      if (
        rawDomainText !== match.normalizedDomain &&
        rawDomainText !== normalizeDomain(href)
      ) {
        return;
      }

      const markerNode = createMarkerNode(match);
      anchor.parentNode.replaceChild(markerNode, anchor);
      trimCitationParenthesesAroundNode(markerNode);
    });
  }

  function aipkit_chatUI_applyInlineCitationMarkers(bubble, citations) {
    if (!bubble || !Array.isArray(citations) || citations.length === 0) {
      return;
    }

    const matchers = buildCitationMatchers(citations);
    if (matchers.length === 0) {
      return;
    }

    replaceRawCitationAnchors(bubble, matchers);

    const walker = document.createTreeWalker(
      bubble,
      NodeFilter.SHOW_TEXT,
      null
    );
    const textNodes = [];
    let currentNode = walker.nextNode();
    while (currentNode) {
      textNodes.push(currentNode);
      currentNode = walker.nextNode();
    }

    textNodes.forEach((textNode) => {
      replaceParentheticalCitationText(textNode, matchers);
    });
  }

  window.aipkit_chatUI_applyInlineCitationMarkers =
    aipkit_chatUI_applyInlineCitationMarkers;
})();
