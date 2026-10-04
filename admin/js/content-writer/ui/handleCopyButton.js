// Updated for chunked output UI - copies from content area

/**
 * AIPKit Content Writer - Copy Button Handler
 * Manages the click event for the "Copy" button in the output area.
 */
(function () {
  "use strict";

  /**
   * Initializes the copy button event listener.
   * @param {HTMLButtonElement} copyBtn The copy button element.
   */
  function aipkit_handleCopyButton(copyBtn) {
    if (!copyBtn) {
      console.warn("Copy Button Handler: Button element not provided.");
      return;
    }

    if (!copyBtn.dataset.listenerAttached) {
      copyBtn.addEventListener("click", () => {
        // Get the title and content from the chunked UI
        const titleDisplay = document.getElementById(
          "aipkit_cw_generated_title_display"
        );
        const contentArea = document.getElementById(
          "aipkit_cw_generated_content_area"
        );

        let textToCopy = "";

        // Include title if visible
        if (titleDisplay && titleDisplay.style.display !== "none") {
          textToCopy += titleDisplay.textContent + "\n\n";
        }

        // Include content body
        if (contentArea) {
          textToCopy += contentArea.innerText;
        }

        if (textToCopy.trim()) {
          navigator.clipboard
            .writeText(textToCopy.trim())
            .then(() => {
              const textSpan = copyBtn.querySelector(".aipkit_btn-text");
              const icon = copyBtn.querySelector(".dashicons");
              const originalLabel =
                copyBtn.getAttribute("aria-label") || "Copy article";
              const originalTitle =
                copyBtn.getAttribute("title") || originalLabel;
              if (textSpan) {
                const originalText = textSpan.textContent;
                textSpan.textContent = "Copied";
                setTimeout(() => {
                  textSpan.textContent = originalText;
                }, 1500);
              }
              copyBtn.classList.add("is-copied");
              copyBtn.setAttribute("aria-label", "Copied");
              copyBtn.setAttribute("title", "Copied");
              if (icon) {
                icon.classList.remove("dashicons-admin-page");
                icon.classList.add("dashicons-yes");
              }
              setTimeout(() => {
                copyBtn.classList.remove("is-copied");
                copyBtn.setAttribute("aria-label", originalLabel);
                copyBtn.setAttribute("title", originalTitle);
                if (icon) {
                  icon.classList.remove("dashicons-yes");
                  icon.classList.add("dashicons-admin-page");
                }
              }, 1500);
            })
            .catch((err) => console.error("Failed to copy: ", err));
        }
      });
      copyBtn.dataset.listenerAttached = "true";
    }
  }

  window.aipkit_handleCopyButton = aipkit_handleCopyButton;
})();
