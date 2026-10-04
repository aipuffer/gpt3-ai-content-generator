import "./enhancer-utils.js";

export function createRowAssistantModalController(options) {
  let modalOverlay = null;
  let currentPostId = null;
  let currentTexts = {};
  let currentConfig = {};
  const isLongForm = options.variant === "long-form";

  const getEscapers = () => {
    const escaper = window.aipkit_escapeHtml || ((str) => str);
    return {
      escaper,
      attrEscaper: window.aipkit_escapeAttribute || escaper,
    };
  };

  const getText = (texts, key, fallback) => texts?.[key] || fallback;

  const getLoadingInfo = (config) => {
    const provider = config?.default_ai_provider || "";
    const model = config?.default_ai_model || "";
    return [provider, model].filter(Boolean).join(" · ");
  };

  const closeModal = () => {
    const overlay = modalOverlay;
    if (overlay) {
      overlay.classList.remove("aipkit-active");
      overlay.addEventListener(
        "transitionend",
        () => {
          overlay.remove();
          if (modalOverlay === overlay) {
            modalOverlay = null;
          }
        },
        { once: true }
      );

      setTimeout(() => {
        overlay.remove();
        if (modalOverlay === overlay) {
          modalOverlay = null;
        }
      }, 500);
    }

    const reset = window[options.resetFunctionName];
    if (typeof reset === "function") {
      reset();
    }
  };

  const renderLoadingState = () => {
    const modalBody = modalOverlay?.querySelector(".aipkit-modal-body");
    if (!modalBody) return;

    const { escaper } = getEscapers();
    const loadingInfo = getLoadingInfo(currentConfig);
    modalBody.innerHTML = `
      <div class="aipkit-row-assistant-loading" role="status" aria-live="polite">
        <span class="aipkit_spinner" aria-hidden="true"></span>
        <strong>${escaper(
          getText(
            currentTexts,
            options.loadingTextKey,
            options.defaultLoading
          )
        )}</strong>
        ${
          loadingInfo
            ? `<span class="aipkit-row-assistant-loading-info">${escaper(
                loadingInfo
              )}</span>`
            : ""
        }
      </div>
    `;
  };

  const regenerate = () => {
    const fetchSuggestions = window[options.fetchFunctionName];
    const handleResult = window[options.resultHandlerFunctionName];
    if (
      !currentPostId ||
      typeof fetchSuggestions !== "function" ||
      typeof handleResult !== "function"
    ) {
      updateModalWithError(
        getText(
          currentTexts,
          options.errorTextKey,
          options.defaultError
        ),
        currentTexts
      );
      return;
    }

    renderLoadingState();
    fetchSuggestions(currentPostId, handleResult, null);
  };

  const showLoadingModal = (postId, texts = {}, config = {}) => {
    if (modalOverlay) {
      closeModal();
    }

    currentPostId = postId;
    currentTexts = texts;
    currentConfig = config;

    const { escaper, attrEscaper } = getEscapers();
    modalOverlay = document.createElement("div");
    modalOverlay.className = "aipkit-modal-overlay";
    modalOverlay.addEventListener("click", (event) => {
      if (event.target === modalOverlay) {
        closeModal();
      }
    });

    const modalContent = document.createElement("div");
    modalContent.className =
      "aipkit-modal-content aipkit-modal-shell aipkit-modal-shell--compact aipkit-row-assistant-modal";
    if (isLongForm) {
      modalContent.classList.add("aipkit-row-assistant-modal--long-form");
    }
    modalContent.dataset.postId = postId;
    modalContent.innerHTML = `
      <div class="aipkit-modal-header aipkit-row-assistant-modal-header">
        <h3 class="aipkit-modal-title aipkit-modal-shell-title">${escaper(
          getText(texts, options.titleTextKey, options.defaultTitle)
        )}</h3>
        <button type="button" class="aipkit-modal-close-btn aipkit-row-assistant-close-btn" aria-label="${attrEscaper(
          texts.close || "Close"
        )}">
          <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
        </button>
      </div>
      <div class="aipkit-modal-body aipkit-modal-shell-body"></div>
    `;

    modalOverlay.appendChild(modalContent);
    document.body.appendChild(modalOverlay);
    modalContent
      .querySelector(".aipkit-row-assistant-close-btn")
      ?.addEventListener("click", closeModal);

    renderLoadingState();
    requestAnimationFrame(() => modalOverlay?.classList.add("aipkit-active"));
    return modalOverlay;
  };

  const updateModalWithSuggestions = (
    suggestions,
    texts = {},
    currentValue = ""
  ) => {
    const modalBody = modalOverlay?.querySelector(".aipkit-modal-body");
    if (!modalBody) return;

    currentTexts = texts;
    const { escaper, attrEscaper } = getEscapers();
    const currentLabel = getText(
      texts,
      options.currentLabelTextKey,
      options.defaultCurrentLabel
    );
    const emptyCurrent = getText(
      texts,
      options.emptyCurrentTextKey,
      options.defaultEmptyCurrent
    );
    const currentDisplayValue = currentValue || emptyCurrent;
    const radioName = `aipkit-row-assistant-${options.fieldName}-${currentPostId}`;

    modalBody.innerHTML = `
      <div class="aipkit-row-assistant-current">
        <span>${escaper(currentLabel)}</span>
        <strong class="aipkit-row-assistant-current-value${
          isLongForm && currentValue
            ? " aipkit-row-assistant-current-value--clamped"
            : ""
        }">${escaper(currentDisplayValue)}</strong>
        ${
          isLongForm && currentValue
            ? `<button type="button" class="aipkit-row-assistant-current-toggle" aria-expanded="false" hidden>${escaper(
                getText(texts, "show_more", "Show more")
              )}</button>`
            : ""
        }
      </div>
      <ul class="aipkit-suggestions-list" role="radiogroup" aria-label="${attrEscaper(
        getText(texts, options.suggestionsLabelTextKey, options.defaultTitle)
      )}">
        ${suggestions
          .map(
            (suggestion, index) => `
              <li ${options.dataAttribute}="${attrEscaper(suggestion)}">
                <label>
                  <input type="radio" name="${attrEscaper(
                    radioName
                  )}" value="${index}">
                  <span>${escaper(suggestion)}</span>
                </label>
              </li>`
          )
          .join("")}
      </ul>
      <div class="aipkit-modal-status" aria-live="polite"></div>
      <div class="aipkit-row-assistant-footer">
        <button type="button" class="aipkit-row-assistant-btn aipkit-row-assistant-btn--secondary aipkit-row-assistant-regenerate" data-aipkit-row-assistant-action="regenerate">
          <span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
          <span>${escaper(getText(texts, "regenerate", "Regenerate"))}</span>
        </button>
        <div class="aipkit-row-assistant-footer-primary">
          <button type="button" class="aipkit-row-assistant-btn aipkit-row-assistant-btn--secondary aipkit-row-assistant-cancel" data-aipkit-row-assistant-action="cancel">
            ${escaper(getText(texts, "cancel", "Cancel"))}
          </button>
          <button type="button" class="aipkit-row-assistant-btn aipkit-row-assistant-btn--primary aipkit-row-assistant-apply" data-aipkit-row-assistant-action="apply" disabled>
            ${escaper(
              getText(texts, options.applyTextKey, options.defaultApply)
            )}
          </button>
        </div>
      </div>
    `;

    const applyButton = modalBody.querySelector(
      ".aipkit-row-assistant-apply"
    );
    const suggestionList = modalBody.querySelector(
      ".aipkit-suggestions-list"
    );
    const currentValueElement = modalBody.querySelector(
      ".aipkit-row-assistant-current-value--clamped"
    );
    const currentValueToggle = modalBody.querySelector(
      ".aipkit-row-assistant-current-toggle"
    );

    if (currentValueElement && currentValueToggle) {
      requestAnimationFrame(() => {
        currentValueToggle.hidden =
          currentValueElement.scrollHeight <=
          currentValueElement.clientHeight + 1;
      });
      currentValueToggle.addEventListener("click", () => {
        const expanded = currentValueElement.classList.toggle(
          "aipkit-row-assistant-current-value--expanded"
        );
        currentValueToggle.setAttribute(
          "aria-expanded",
          expanded ? "true" : "false"
        );
        currentValueToggle.textContent = getText(
          texts,
          expanded ? "show_less" : "show_more",
          expanded ? "Show less" : "Show more"
        );
      });
    }

    suggestionList?.addEventListener("change", (event) => {
      if (!event.target.matches('input[type="radio"]')) return;
      suggestionList
        .querySelectorAll("li")
        .forEach((item) =>
          item.classList.toggle(
            "aipkit-selected",
            item.contains(event.target)
          )
        );
      applyButton.disabled = false;
    });

    modalBody
      .querySelector(".aipkit-row-assistant-regenerate")
      ?.addEventListener("click", regenerate);
    modalBody
      .querySelector(".aipkit-row-assistant-cancel")
      ?.addEventListener("click", closeModal);
    applyButton?.addEventListener("click", () => {
      const selectedItem = suggestionList?.querySelector(
        'input[type="radio"]:checked'
      )?.closest("li");
      const applySuggestion = window[options.applyFunctionName];
      if (!selectedItem || typeof applySuggestion !== "function") return;
      applySuggestion(selectedItem);
    });
  };

  const updateModalWithError = (error, texts = {}) => {
    const modalBody = modalOverlay?.querySelector(".aipkit-modal-body");
    if (!modalBody) return;

    currentTexts = texts;
    const { escaper } = getEscapers();
    const action = window.aipkit_assistantErrorAction(error);
    const errorMessage = window.aipkit_assistantErrorMessage(error);
    const label = action === "check" ? getText(texts, "check_status", "Check status")
      : action === "generate" ? getText(texts, "generate_again", "Generate again") : getText(texts, "retry", "Retry");
    modalBody.innerHTML = `
      <div class="aipkit-row-assistant-error" role="alert">
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <div>
          <strong>${escaper(
            getText(texts, options.errorTextKey, options.defaultError)
          )}</strong>
          <span>${escaper(errorMessage)}</span>
        </div>
      </div>
      <div class="aipkit-row-assistant-footer aipkit-row-assistant-footer--error">
        <button type="button" class="aipkit-row-assistant-btn aipkit-row-assistant-btn--secondary aipkit-row-assistant-cancel">
          ${escaper(getText(texts, "close", "Close"))}
        </button>
        ${action ? `<button type="button" class="aipkit-row-assistant-btn aipkit-row-assistant-btn--primary aipkit-row-assistant-regenerate">${escaper(label)}</button>` : ""}
      </div>
    `;
    modalBody
      .querySelector(".aipkit-row-assistant-cancel")
      ?.addEventListener("click", closeModal);
    modalBody
      .querySelector(".aipkit-row-assistant-regenerate")
      ?.addEventListener("click", async (event) => {
        if (action !== "check") { regenerate(); return; }
        const owner = modalOverlay;
        event.currentTarget.disabled = true;
        const checkedError = await window.aipkit_checkAssistantRequest(error);
        if (owner === modalOverlay && owner?.isConnected && owner.classList.contains("aipkit-active")) {
          updateModalWithError(checkedError, texts);
        }
      });
  };

  return {
    closeModal,
    showLoadingModal,
    updateModalWithSuggestions,
    updateModalWithError,
  };
}
