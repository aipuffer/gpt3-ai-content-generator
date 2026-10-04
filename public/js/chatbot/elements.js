/**
 * AIPKit Public Chat - DOM Element Finder
 *
 * Queries and returns references to core UI elements for a chat instance.
 * Creates missing upload controls only when enabled by the chatbot configuration.
 */
(function () {
  "use strict";

  function findWithin(parent, selector) {
    return parent ? parent.querySelector(selector) : null;
  }

  /**
   * Finds and returns core DOM elements for a chat instance.
   * Dynamically creates image and file upload elements if configured and not present.
   * @param {HTMLElement} container - The main chat container element (.aipkit_chat_container).
   * @param {object} config - The chatbot configuration object (for botId, imageUploadEnabledUI, fileUploadEnabledUI, error messages).
   * @returns {object|null} An object containing DOM element references, or null if essential elements are missing.
   */
  function aipkit_chatUI_findElements(container, config) {
    const botId = config.botId || "unknown";

    const headerEl = container.querySelector(".aipkit_chat_header");
    const mainContentEl = container.querySelector(".aipkit_chat_main");
    const messagesEl = findWithin(mainContentEl, ".aipkit_chat_messages");
    const inputArea = findWithin(mainContentEl, ".aipkit_chat_input");
    const inputField = findWithin(inputArea, ".aipkit_chat_input_field");
    const inputWrapper = findWithin(inputArea, ".aipkit_chat_input_wrapper");
    const actionsBar = findWithin(inputWrapper, ".aipkit_chat_input_actions_bar");

    // --- Image Upload related elements (dynamic creation if enabled) ---
    let imagePreviewContainer = container.querySelector(
      ".aipkit_chat_image_preview_container"
    ); // Search from container
    let imageUploadInput = container.querySelector(
      ".aipkit_chat_image_upload_input"
    );

    if (config.imageUploadEnabledUI && inputArea && inputWrapper) {
      // Check for wrapper
      if (!imagePreviewContainer) {
        imagePreviewContainer = document.createElement("div");
        imagePreviewContainer.className = "aipkit_chat_image_preview_container";
      }

      // Ensure the preview is inside the wrapper, before the textarea
      if (!inputWrapper.contains(imagePreviewContainer)) {
        const inputFieldEl = inputWrapper.querySelector(
          ".aipkit_chat_input_field"
        );
        if (inputFieldEl) {
          inputWrapper.insertBefore(imagePreviewContainer, inputFieldEl);
        } else {
          inputWrapper.appendChild(imagePreviewContainer); // Fallback
        }
      }

      if (!imageUploadInput) {
        imageUploadInput = document.createElement("input");
        imageUploadInput.type = "file";
        imageUploadInput.className = "aipkit_chat_image_upload_input";
        imageUploadInput.hidden = true;
        imageUploadInput.setAttribute("aria-hidden", "true");
        inputArea.appendChild(imageUploadInput); // Input can still be outside wrapper
      }
    }

    let fileUploadInput = findWithin(inputArea, ".aipkit_chat_file_upload_input");
    if (config.fileUploadEnabledUI && inputArea) {
      if (!fileUploadInput) {
        fileUploadInput = document.createElement("input");
        fileUploadInput.type = "file";
        fileUploadInput.className = "aipkit_chat_file_upload_input"; // Specific class for file input
        fileUploadInput.hidden = true;
        fileUploadInput.setAttribute("aria-hidden", "true");
        // The optional file-upload runtime configures the accept attribute.
        inputArea.appendChild(fileUploadInput);
      }
    }
    const inputActionButton = findWithin(
      actionsBar,
      ".aipkit_input_action_btn.aipkit_input_action_toggle"
    );
    const inputActionMenu = findWithin(inputArea, ".aipkit_input_action_menu");
    const voiceInputButton = findWithin(actionsBar, ".aipkit_voice_input_btn");
    const voiceRecordingPanel = findWithin(
      inputWrapper,
      ".aipkit_voice_recording_panel"
    );
    const voiceWaveformCanvas = findWithin(
      voiceRecordingPanel,
      ".aipkit_voice_waveform_canvas"
    );
    const voiceRecordingStatus = findWithin(
      voiceRecordingPanel,
      ".aipkit_voice_recording_status"
    );
    const voiceTranscribingIndicator = findWithin(
      voiceRecordingPanel,
      ".aipkit_voice_transcribing_indicator"
    );
    const voiceCancelButton = findWithin(
      voiceRecordingPanel,
      ".aipkit_voice_cancel_btn"
    );
    const voiceConfirmButton = findWithin(
      voiceRecordingPanel,
      ".aipkit_voice_confirm_btn"
    );
    const webSearchToggleButton = findWithin(actionsBar, ".aipkit_web_search_toggle");
    const googleSearchGroundingToggleButton = findWithin(
      actionsBar,
      ".aipkit_google_search_grounding_toggle"
    );
    const actionButton = findWithin(
      actionsBar,
      ".aipkit_chat_action_btn.aipkit_send_btn"
    );
    const sendIcon = findWithin(actionButton, ".aipkit_send_icon");
    const clearIcon = findWithin(actionButton, ".aipkit_clear_icon");
    const spinner = findWithin(actionButton, ".aipkit_spinner");
    let actionTimer = findWithin(actionsBar, ".aipkit_chat_action_timer");
    if (actionButton && !actionTimer) {
      actionTimer = document.createElement("span");
      actionTimer.className = "aipkit_chat_action_timer";
      actionTimer.setAttribute("aria-hidden", "true");
      actionTimer.hidden = true;
    }
    const actionButtonParent = actionButton?.parentElement;
    if (
      actionTimer &&
      actionButtonParent &&
      actionTimer.parentElement !== actionButtonParent
    ) {
      actionButtonParent.insertBefore(actionTimer, actionButton);
    }
    const startersContainer = findWithin(
      mainContentEl,
      ".aipkit_conversation_starters"
    );
    const sidebarToggleBtn =
      container.querySelector(".aipkit_sidebar_toggle_btn--main") ||
      container.querySelector(".aipkit_sidebar_toggle_btn");
    const fullscreenButton = findWithin(headerEl, ".aipkit_fullscreen_btn");
    const downloadButton = findWithin(headerEl, ".aipkit_download_btn");
    const sidebarEl = container.querySelector(".aipkit_chat_sidebar");
    const newChatBtn = findWithin(sidebarEl, ".aipkit_sidebar_new_chat_btn");

    // Validate essential elements
    if (
      !mainContentEl ||
      !messagesEl ||
      !inputField ||
      !actionButton ||
      !sendIcon ||
      !clearIcon ||
      !spinner
    ) {
      console.error(
        `AIPKit Chat FindElements (${botId}): Essential chat UI elements not found within container.`
      );
      return null;
    }

    // Warn for non-essential but expected elements if config implies they should exist
    if (config.enableStarters && !startersContainer)
      console.warn(
        `AIPKit Chat FindElements (${botId}): Starters enabled but container not found.`
      );
    if (config.enableSidebar && (!sidebarEl || !sidebarToggleBtn))
      console.warn(
        `AIPKit Chat FindElements (${botId}): Sidebar enabled but container or toggle button not found.`
      );
    if (config.inputActionButtonEnabled && !inputActionButton)
      console.warn(
        `AIPKit Chat FindElements (${botId}): Input Action Button features enabled but button element missing.`
      );
    if (
      config.allowWebSearchTool &&
      (config.provider === "OpenAI" ||
        config.provider === "Claude" ||
        config.provider === "OpenRouter" ||
        config.provider === "xAI") &&
      !webSearchToggleButton
    )
      console.warn(
        `AIPKit Chat FindElements (${botId}): Provider Web Search allowed but toggle button missing.`
      );
    if (
      config.allowGoogleSearchGrounding &&
      config.provider === "Google" &&
      !googleSearchGroundingToggleButton
    )
      console.warn(
        `AIPKit Chat FindElements (${botId}): Google Search Grounding allowed but toggle button missing.`
      );
    // No warning needed for image/file elements as they are now dynamically created if enabled.

    return {
      container,
      headerEl,
      mainContentEl,
      messagesEl,
      inputArea,
      inputField,
      inputWrapper,
      actionsBar,
      imageUploadInput,
      imagePreviewContainer,
      fileUploadInput, // Return new fileUploadInput
      inputActionButton,
      inputActionMenu,
      voiceInputButton,
      voiceRecordingPanel,
      voiceWaveformCanvas,
      voiceRecordingStatus,
      voiceTranscribingIndicator,
      voiceCancelButton,
      voiceConfirmButton,
      webSearchToggleButton,
      googleSearchGroundingToggleButton,
      actionButton,
      sendIcon,
      clearIcon,
      spinner,
      actionTimer,
      startersContainer,
      sidebarToggleBtn,
      fullscreenButton,
      downloadButton,
      sidebarEl,
      newChatBtn,
    };
  }

  window.aipkit_chatUI_findElements = aipkit_chatUI_findElements;
})();
