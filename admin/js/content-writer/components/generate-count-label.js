/**
 * Keeps Content Writer's primary Generate label consistent across sources.
 * Counts are shown only when more than one item will be generated.
 */
(function () {
  "use strict";

  const i18n = window.wp?.i18n || {};
  const __ = typeof i18n.__ === "function" ? i18n.__ : (text) => text;
  const _n =
    typeof i18n._n === "function"
      ? i18n._n
      : (single, plural, count) => (count === 1 ? single : plural);
  const sprintf =
    typeof i18n.sprintf === "function"
      ? i18n.sprintf
      : (format, value) => format.replace(/%\d*\$?d/, String(value));

  const countNonEmptyLines = (value) =>
    String(value || "")
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean).length;

  const countValidUrls = (value) =>
    String(value || "")
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter((line) => {
        if (!line) return false;
        try {
          const url = new URL(line);
          return (
            (url.protocol === "http:" || url.protocol === "https:") &&
            Boolean(url.hostname)
          );
        } catch (_) {
          return false;
        }
      }).length;

  const getRssFeedCount = (container) =>
    countValidUrls(container.querySelector("#aipkit_cw_rss_feeds")?.value);

  function getSourceCount(container, mode) {
    if (mode === "task") {
      return countNonEmptyLines(
        container.querySelector("#aipkit_cw_bulk_topics")?.value
      );
    }

    if (mode === "csv") {
      return countNonEmptyLines(
        container.querySelector("#aipkit_cw_csv_data_holder")?.value
      );
    }

    if (mode === "rss") {
      const value = Number.parseInt(
        container.querySelector("[data-rss-items-count]")?.value || "0",
        10
      );
      return Number.isFinite(value) && value > 0 ? value : 0;
    }

    if (mode === "url") {
      return countValidUrls(
        container.querySelector("#aipkit_cw_url_list")?.value
      );
    }

    // Google Sheets currently verifies access but does not return a reliable
    // row count. Keep its primary action honest instead of estimating.
    return 0;
  }

  function isGoogleSheetsVerified(container) {
    return (
      container.querySelector(
        ".aipkit_cw_source_mode_shell--gsheets [data-gsheets-status]"
      )?.dataset.gsheetsStatus === "success"
    );
  }

  function updateSourceReadiness(button, container, mode, count) {
    const shell = button.closest(".aipkit_cw_action_shell");
    const disclosure = shell?.querySelector(".aipkit_cw_action_disclosure");
    const action = button.dataset.action || "generate";
    const isSourceNotReady =
      (action === "generate" &&
        ((mode === "task" && count === 0) ||
          (mode === "csv" && count === 0) ||
          (mode === "rss" && count === 0) ||
          (mode === "url" && count === 0) ||
          (mode === "gsheets" && !isGoogleSheetsVerified(container)))) ||
      (action === "fetch_feeds" &&
        mode === "rss" &&
        getRssFeedCount(container) === 0);
    const wasSourceNotReady = button.dataset.aipkitSourceNotReady === "true";

    shell?.classList.toggle("is-disabled", isSourceNotReady);
    if (isSourceNotReady) {
      button.dataset.aipkitSourceNotReady = "true";
      button.disabled = true;
      button.setAttribute("aria-disabled", "true");
    } else if (wasSourceNotReady) {
      delete button.dataset.aipkitSourceNotReady;
      button.disabled = false;
      button.removeAttribute("aria-disabled");
    }

    if (disclosure) {
      if (isSourceNotReady) {
        disclosure.dataset.aipkitSourceNotReady = "true";
        disclosure.disabled = true;
        disclosure.setAttribute("aria-disabled", "true");
        disclosure.setAttribute("aria-expanded", "false");
      } else if (disclosure.dataset.aipkitSourceNotReady === "true") {
        delete disclosure.dataset.aipkitSourceNotReady;
        disclosure.disabled = disclosure.style.display === "none";
        if (disclosure.disabled) {
          disclosure.setAttribute("aria-disabled", "true");
        } else {
          disclosure.removeAttribute("aria-disabled");
        }
      }
    }

    const menu = shell?.querySelector(".aipkit_cw_action_menu");
    if (menu && isSourceNotReady) {
      menu.hidden = true;
    }
  }

  function refreshGenerateLabel() {
    const container = document.getElementById("aipkit_content_writer_container");
    const button = document.getElementById(
      "aipkit_content_writer_generate_btn"
    );
    if (!container || !button) return;

    const mode =
      container.querySelector("#aipkit_cw_mode_select")?.value || "task";
    const count = getSourceCount(container, mode);
    updateSourceReadiness(button, container, mode, count);

    if (
      button.dataset.aipkitStopMode === "true" ||
      button.dataset.originalText ||
      button.getAttribute("aria-busy") === "true"
    ) {
      return;
    }

    const text = button.querySelector(".aipkit_btn-text");
    if (!text) return;

    const action = button.dataset.action || "generate";
    if (action === "fetch_feeds") {
      const feedCount = getRssFeedCount(container);
      text.textContent =
        feedCount > 0
          ? sprintf(
              _n(
                "Fetch %1$d feed",
                "Fetch %1$d feeds",
                feedCount,
                "gpt3-ai-content-generator"
              ),
              feedCount
            )
          : __("Fetch feeds", "gpt3-ai-content-generator");
      return;
    }
    if (action !== "generate") return;

    text.textContent =
      count > 1
        ? sprintf(
            __("Generate %1$d", "gpt3-ai-content-generator"),
            count
          )
        : __("Generate", "gpt3-ai-content-generator");
  }

  const relevantSelector = [
    "#aipkit_cw_mode_select",
    "#aipkit_cw_bulk_topics",
    "#aipkit_cw_csv_data_holder",
    "#aipkit_cw_rss_feeds",
    "#aipkit_cw_url_list",
    "[data-rss-items-count]",
  ].join(",");

  ["input", "change"].forEach((eventName) => {
    document.addEventListener(eventName, (event) => {
      if (event.target?.matches?.(relevantSelector)) {
        refreshGenerateLabel();
      }
    });
  });

  window.aipkit_refreshContentWriterGenerateLabel = refreshGenerateLabel;
})();
