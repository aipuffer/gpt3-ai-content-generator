/**
 * AIPKit Content Writer - Smart SEO upgrade prompt
 * Shared visibility hook; paid execution is provided by lib.
 * Reveals the upgrade prompt after a single draft exists.
 */
(function () {
  "use strict";

  function isProPlan() {
    return Boolean(window.aipkit_dashboard && window.aipkit_dashboard.isProPlan);
  }

  function getFreeCard() {
    return document.getElementById("aipkit_cw_smart_seo_locked_card");
  }

  function hasGeneratedDraftContent() {
    if (typeof window.aipkit_hasContentWriterCanvasContent === "function") {
      return window.aipkit_hasContentWriterCanvasContent();
    }

    const contentArea = document.getElementById("aipkit_cw_generated_content_area");
    const title = document.getElementById("aipkit_cw_generated_title_display");

    return Boolean(
      String(contentArea?.textContent || "").trim() ||
        String(title?.textContent || "").trim() ||
        contentArea?.querySelector("img, h1, h2, h3, p, ul, ol, figure")
    );
  }

  function isScorePanelVisible() {
    const summary = document.getElementById("aipkit_cw_seo_audit_summary");
    return Boolean(
      summary &&
        !summary.hidden &&
        window.getComputedStyle(summary).display !== "none"
    );
  }

  function syncMetaVisibility() {
    if (typeof window.aipkit_updateMetaChunkVisibility === "function") {
      window.aipkit_updateMetaChunkVisibility();
    }
  }

  function setCardVisible(card, visible) {
    if (!card) {
      return;
    }

    if (visible) {
      card.hidden = false;
      card.style.display = "flex";
      window.requestAnimationFrame(() => {
        if (!card.hidden && window.getComputedStyle(card).display !== "none") {
          card.classList.add("is-visible");
        }
      });
    } else {
      card.hidden = true;
      card.style.display = "none";
      card.classList.remove("is-visible");
    }

    syncMetaVisibility();
  }

  function refresh(options = {}) {
    const forceHide = Boolean(options.forceHide);
    const hasDraft = hasGeneratedDraftContent();
    const scoreVisible = isScorePanelVisible();
    const pro = isProPlan();

    const showFreeCard = !forceHide && !pro && hasDraft && !scoreVisible;
    setCardVisible(getFreeCard(), showFreeCard);
    if (typeof window.aipkit_refreshContentWriterSmartSeoRunCard === "function") {
      window.aipkit_refreshContentWriterSmartSeoRunCard(
        !forceHide && pro && hasDraft && !scoreVisible,
        setCardVisible
      );
    }
  }

  window.aipkit_refreshContentWriterSmartSeoLockedCard = refresh;

})();
