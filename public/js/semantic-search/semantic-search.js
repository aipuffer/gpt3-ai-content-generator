/**
 * AIPKit - Frontend Semantic Search
 *
 * Handles the AJAX search functionality for the [aipkit_semantic_search] shortcode.
 */
import "../shared/guest-identity.js";
import "../shared/frontend-request.js";

(function () {
  "use strict";

  function handleSemanticSearch(event) {
    event.preventDefault();
    const form = event.target;
    const input = form.querySelector(".aipkit_semantic_search_input");
    const button = form.querySelector(".aipkit_semantic_search_button");
    const wrapper = form.closest(".aipkit_semantic_search_wrapper");
    const resultsContainer = wrapper.querySelector(
      ".aipkit_semantic_search_results"
    );

    if (!input || !button || !wrapper || !resultsContainer) {
      console.error("Semantic Search: Missing required form elements.");
      return;
    }

    const query = input.value.trim();
    if (!query) {
      return; // Don't search for empty strings
    }

    const config = window.aipkit_semantic_search_config || {};
    const texts = config.text || {};

    // Show loading state
    button.disabled = true;
    const spinner = button.querySelector(".aipkit_spinner");
    if (spinner) spinner.style.display = "inline-block";
    resultsContainer.innerHTML = `<div class="aipkit-search-loading"><span class="aipkit_spinner"></span></div>`;

    const data = {
      query: query,
      // Nonce is added by the API request handler from the main config object
    };

    window
      .aipkit_frontendApiRequest("aipkit_perform_semantic_search", data, config)
      .then((response) => {
        resultsContainer.innerHTML =
          response.html ||
          `<p>${config.settings.no_results_text || "No results found."}</p>`;
      })
      .catch((error) => {
        console.error("Semantic Search Error:", error);
        resultsContainer.innerHTML = `<p class="aipkit-search-error">${
          texts.error || "An error occurred while searching."
        }</p>`;
      })
      .finally(() => {
        button.disabled = false;
        if (spinner) spinner.style.display = "none";
      });
  }

  function initSemanticSearch() {
    const searchForms = document.querySelectorAll(
      ".aipkit_semantic_search_form"
    );
    searchForms.forEach((form) => {
      if (form.dataset.listenerAttached === "true") return;
      form.addEventListener("submit", handleSemanticSearch);
      form.dataset.listenerAttached = "true";
    });
  }

  // Initialize on DOM load and also after AJAX loads if using a page builder
  document.addEventListener("DOMContentLoaded", initSemanticSearch);
  // You might need a custom event to re-trigger this for page builders
  document.addEventListener("aipkit:contentLoaded", initSemanticSearch);
})();
