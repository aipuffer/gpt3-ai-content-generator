// Uses shared localized prompt schema from aipkit_post_enhancer.prompt_items.
/**
 * AIPKit Content Enhancer - Bulk Modal UI Creation
 */
(function () {
  "use strict";

  /**
   * Creates and displays the modal for bulk content enhancement.
   * @param {string[]} postIds - An array of selected post IDs.
   * @param {string} postType - The post type from the current admin screen.
   */
  function aipkit_enhancer_createAndShowBulkModal(postIds, postType) {
    // Check for template function dependency
    if (typeof window.aipkit_enhancer_getBulkModalHtml !== "function") {
      console.error(
        "Bulk Modal UI: aipkit_enhancer_getBulkModalHtml function is not available."
      );
      alert("Error: Could not load modal interface.");
      return;
    }

    // Extract post titles from the current admin page
    const postTitles = {};
    postIds.forEach(postId => {
      // Try to find the post title from the admin post list table
      const postRow = document.querySelector(`tr#post-${postId}`);
      if (postRow) {
        // Try multiple selectors to find the post title
        const titleElement = postRow.querySelector('.row-title') || 
                           postRow.querySelector('strong a') || 
                           postRow.querySelector('.title a') ||
                           postRow.querySelector('a.row-title') ||
                           postRow.querySelector('.column-title strong a') ||
                           postRow.querySelector('.column-title .row-title');
        if (titleElement) {
          postTitles[postId] = titleElement.textContent.trim();
        }
      }
      
      // Fallback: if we can't find the title, use "Post #ID"
      if (!postTitles[postId]) {
        postTitles[postId] = `Post #${postId}`;
      }
    });

    // Store post titles in global state for later use
    window.aipkit_enhancer_postTitles = postTitles;

    const promptItems = Array.isArray(window.aipkit_post_enhancer?.prompt_items)
      ? window.aipkit_post_enhancer.prompt_items
      : [];

    const dashboardModels = window.aipkit_dashboard?.models;
    const providers = dashboardModels ? Object.keys(dashboardModels) : ["openai"];

    const escapeHtml = (value) =>
      String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
    const escapeAttr = (value) =>
      escapeHtml(value).replace(/"/g, "&quot;").replace(/'/g, "&#39;");

    const defaultProviderRaw = window.aipkit_post_enhancer?.default_ai_provider || "";
    const defaultProviderKey = String(defaultProviderRaw || "")
      .trim()
      .toLowerCase();

    const providerMap = {
      aipuffercloud: "AI Puffer Cloud",
      openai: "OpenAI",
      openrouter: "OpenRouter",
      google: "Google",
      azure: "Azure",
      claude: "Anthropic",
  deepseek: "DeepSeek",
  xai: "xAI",
  ollama: "Ollama",
    };
    const providerOptions = providers
      .map((p) => {
        const key = p.toLowerCase();
        const isDefault = defaultProviderKey && key === defaultProviderKey;
        return `<option value="${escapeAttr(key)}" ${isDefault ? "selected" : ""}>${escapeHtml(
          providerMap[key] || p.toUpperCase()
        )}</option>`;
      })
      .join("");

    // Generate HTML from the template function
    const modalHtml = window.aipkit_enhancer_getBulkModalHtml({
      postCount: postIds.length,
      providerOptions: providerOptions,
      promptItems,
      postType: postType,
    });

    // Inject modal into the body
    document.body.insertAdjacentHTML("beforeend", modalHtml);
    const modalOverlay = document.querySelector(
      ".aipkit-enhancer-bulk-modal-overlay"
    );

    // Attach event listeners using the handler function
    if (typeof window.aipkit_enhancer_attachBulkModalListeners === "function") {
      window.aipkit_enhancer_attachBulkModalListeners(modalOverlay, postIds);
    } else {
      console.error(
        "Bulk Modal UI: aipkit_enhancer_attachBulkModalListeners function is not available."
      );
    }

    const providerSelect = modalOverlay.querySelector("#aipkit_bulk_ai_provider");
    if (providerSelect && defaultProviderKey) {
      const hasDefault = Array.from(providerSelect.options).some(
        (option) => option.value === defaultProviderKey
      );
      if (hasDefault) {
        providerSelect.value = defaultProviderKey;
        providerSelect.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    // Show the modal with a transition
    requestAnimationFrame(() => {
      modalOverlay.classList.add("aipkit-active");
    });
  }

  window.aipkit_enhancer_createAndShowBulkModal =
    aipkit_enhancer_createAndShowBulkModal;
})();
