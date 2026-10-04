/** Chatbot control events, menu state and listener registration. */
(function () {
  "use strict";

  function navigateMenu(event, menuItems) {
    if (menuItems.length === 0) {
      return;
    }

    const currentIndex = menuItems.indexOf(document.activeElement);
    let nextIndex = currentIndex;

    if (event.key === "ArrowDown" || event.key === "ArrowRight") {
      event.preventDefault();
      nextIndex =
        currentIndex < 0 ? 0 : (currentIndex + 1) % menuItems.length;
    } else if (event.key === "ArrowUp" || event.key === "ArrowLeft") {
      event.preventDefault();
      nextIndex =
        currentIndex <= 0 ? menuItems.length - 1 : currentIndex - 1;
    } else if (event.key === "Home") {
      event.preventDefault();
      nextIndex = 0;
    } else if (event.key === "End") {
      event.preventDefault();
      nextIndex = menuItems.length - 1;
    } else {
      return;
    }

    if (menuItems[nextIndex]) {
      menuItems[nextIndex].focus();
    }
  }

  function closeSearchMenus(elements, state) {
    if (
      typeof window.aipkit_chatUI_closeActiveDownloadMenu === "function"
    )
      window.aipkit_chatUI_closeActiveDownloadMenu();
    if (
      typeof window.aipkit_chatUI_closeActiveInputActionMenu ===
      "function"
    )
      window.aipkit_chatUI_closeActiveInputActionMenu(elements, state);
  }

  /**
   * AIPKit Public Chat - Close Download Menu Utility
   */
  (function() {
      'use strict';

      let isDownloadMenuOpenInternal = false; // Internal state, mirroring instance state.
      let activeDownloadMenuInternal = null;  // Internal state
      let activeDownloadTriggerInternal = null; // Internal state
      let closeDownloadMenuHandlerInternal = null; // Internal state
      let closeDownloadMenuKeyHandlerInternal = null; // Internal state

      /**
       * Closes the currently active download menu.
       * This function is now self-contained and will be globally exposed.
       * It uses its own internal state trackers for simplicity, assuming only one download menu can be open globally.
       * If multiple instances on a page need independent menu states, this will need further refactoring
       * or for the caller to manage state and pass it in. For now, this global approach mirrors original behavior.
       */
      function aipkit_chatUI_closeActiveDownloadMenu() {
          if (activeDownloadMenuInternal) {
               activeDownloadMenuInternal.classList.remove('aipkit_active');
               activeDownloadMenuInternal.setAttribute('aria-hidden', 'true');
               activeDownloadMenuInternal = null;
               isDownloadMenuOpenInternal = false;
          }
          if (activeDownloadTriggerInternal) {
              activeDownloadTriggerInternal.setAttribute('aria-expanded', 'false');
              activeDownloadTriggerInternal = null;
          }
          if (closeDownloadMenuHandlerInternal) {
              document.removeEventListener('click', closeDownloadMenuHandlerInternal, true);
              document.removeEventListener('click', closeDownloadMenuHandlerInternal, false);
              closeDownloadMenuHandlerInternal = null;
          }
          if (closeDownloadMenuKeyHandlerInternal) {
              document.removeEventListener('keydown', closeDownloadMenuKeyHandlerInternal, true);
              document.removeEventListener('keydown', closeDownloadMenuKeyHandlerInternal, false);
              closeDownloadMenuKeyHandlerInternal = null;
          }
      }

      // Expose globally. The state variables are kept internal to this IIFE.
      window.aipkit_chatUI_closeActiveDownloadMenu = aipkit_chatUI_closeActiveDownloadMenu;
      // Helper to update internal state if needed by other functions in this file later, or by attachEventListeners
      window.aipkit_chatUI_setActiveDownloadMenu = function(menu, isOpen, handler, triggerButton, keyHandler) {
          activeDownloadMenuInternal = menu;
          isDownloadMenuOpenInternal = isOpen;
          closeDownloadMenuHandlerInternal = handler;
          activeDownloadTriggerInternal = triggerButton || null;
          closeDownloadMenuKeyHandlerInternal = keyHandler || null;
      };
      window.aipkit_chatUI_getDownloadMenuState = function() {
          return {
              isOpen: isDownloadMenuOpenInternal,
              activeMenu: activeDownloadMenuInternal,
              activeTrigger: activeDownloadTriggerInternal,
              handler: closeDownloadMenuHandlerInternal,
              keyHandler: closeDownloadMenuKeyHandlerInternal
          };
      };

  })();

  /**
   * AIPKit Public Chat - Close Input Action Menu Utility
   */
  (function() {
      'use strict';

      /**
       * Closes the currently active input action menu for a specific instance.
       * This function is now self-contained and will be globally exposed.
       * It operates on the passed-in elements and state for a specific chat instance.
       * @param {object} instanceElements - UI elements for the specific chat instance.
       * @param {object} instanceState - Mutable state object for the specific chat instance.
       */
      function aipkit_chatUI_closeActiveInputActionMenu(instanceElements, instanceState) {
          if (!instanceElements || !instanceState) {
              return;
          }

          const { inputActionButton, inputActionMenu } = instanceElements; // Destructure for clarity
          if (instanceState.activeInputActionMenu && instanceState.activeInputActionTrigger) {
              if (inputActionMenu) { // Check if menu exists
                  inputActionMenu.classList.remove('aipkit-action-menu-open');
                  inputActionMenu.setAttribute('aria-hidden', 'true');
                   setTimeout(() => {
                      // Check again before hiding, in case it was quickly re-opened
                      if(inputActionMenu && !inputActionMenu.classList.contains('aipkit-action-menu-open')) {
                          inputActionMenu.setAttribute('hidden', 'hidden');
                      }
                  }, 200); // Duration of the transition
              }
              if (inputActionButton) { // Check if button exists
                  inputActionButton.classList.remove('aipkit_active');
                  inputActionButton.setAttribute('aria-expanded', 'false');
              }
              instanceState.activeInputActionMenu = null;
              instanceState.activeInputActionTrigger = null;
              instanceState.isActionMenuOpen = false; // Reset the flag in the instance's state

              if (instanceState.closeActionMenuHandler) {
                  document.removeEventListener('click', instanceState.closeActionMenuHandler, true);
                  document.removeEventListener('keydown', instanceState.closeActionMenuHandler, true);
                  instanceState.closeActionMenuHandler = null;
              }
          }
      }

      // Expose globally
      window.aipkit_chatUI_closeActiveInputActionMenu = aipkit_chatUI_closeActiveInputActionMenu;

  })();

  /**
   * AIPKit Public Chat - Setup Input Action Button Utility
   * MODIFIED: Calls the correct global close menu functions.
   * MODIFIED: Does not use `actions` parameter as it's only setting up the button's own click.
   * MODIFIED: Correctly uses `elements.imageUploadInput` and `elements.fileUploadInput`.
   * MODIFIED: Sets the correct icon and aria-label if only file upload is enabled.
   * MODIFIED: Correctly triggers fileUploadInput.click() for "Upload File" or "Upload PDF" menu item.
   */
  (function() {
      'use strict';

      /**
       * Sets up the input action button's icon and click behavior based on enabled features.
       * @param {object} elements - UI elements reference for the specific chat instance.
       * @param {object} config - Chatbot configuration object for the specific chat instance.
       * @param {object} state - Mutable state object for the specific chat instance.
       */
      function aipkit_chatUI_setupInputActionButton(elements, config, state) {
          const { inputActionButton, inputActionMenu, imageUploadInput, fileUploadInput } = elements; // Added fileUploadInput
          const { fileUploadEnabledUI, imageUploadEnabledUI } = config;
          const hideInputActionMenu = () => {
              if (!inputActionMenu) {
                  return;
              }
              inputActionMenu.classList.remove('aipkit-action-menu-open');
              inputActionMenu.setAttribute('aria-hidden', 'true');
              inputActionMenu.setAttribute('hidden', 'hidden');
          };

          if (!inputActionButton) {
              hideInputActionMenu();
              return;
          }

          // SVG definitions
          const attachmentSvg = `<svg xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-paperclip"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 7l-6.5 6.5a1.5 1.5 0 0 0 3 3l6.5 -6.5a3 3 0 0 0 -6 -6l-6.5 6.5a4.5 4.5 0 0 0 9 9l6.5 -6.5" /></svg>`;
          const imageUploadSvg = `<svg xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-photo-up"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 8h.01" /><path d="M12.5 21h-6.5a3 3 0 0 1 -3 -3v-12a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v6.5" /><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l3.5 3.5" /><path d="M14 14l1 -1c.679 -.653 1.473 -.829 2.214 -.526" /><path d="M19 22v-6" /><path d="M22 19l-3 -3l-3 3" /></svg>`;
          const fileUploadSvg = `<svg xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-file-upload"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M12 11v6" /><path d="M9.5 13.5l2.5 -2.5l2.5 2.5" /></svg>`;

          inputActionButton.onclick = null; // Clear previous listener

          const closeDownloadMenuFunc = window.aipkit_chatUI_closeActiveDownloadMenu;
          const closeInputActionMenuFunc = window.aipkit_chatUI_closeActiveInputActionMenu;

          if (typeof closeDownloadMenuFunc !== 'function' || typeof closeInputActionMenuFunc !== 'function') {
              console.error("AIPKit SetupInputActionButton: Missing global menu utility functions (closeDownloadMenu or closeInputActionMenu).");
              return;
          }

          let initialIconHtml = attachmentSvg;
          let initialAriaLabel = config.text?.attachTools || 'Attach or use tools';
          let initialHasPopup = 'true'; // Default to true if both enabled or menu needed

          if (fileUploadEnabledUI && !imageUploadEnabledUI) {
              initialIconHtml = fileUploadSvg;
              initialAriaLabel = config.text?.uploadFile || 'Upload File (TXT, PDF)'; // Generic file upload label
              initialHasPopup = 'false';
          } else if (!fileUploadEnabledUI && imageUploadEnabledUI) {
              initialIconHtml = imageUploadSvg;
              initialAriaLabel = config.text?.uploadImage || 'Upload Image';
              initialHasPopup = 'false';
          } else if (!fileUploadEnabledUI && !imageUploadEnabledUI) {
               // If neither is enabled, hide the button and menu entirely
              inputActionButton.hidden = true;
              hideInputActionMenu();
              return; // No further setup needed
          }

          inputActionButton.hidden = false;

          inputActionButton.innerHTML = initialIconHtml;
          inputActionButton.setAttribute('aria-label', initialAriaLabel);
          if (initialHasPopup === 'true') {
              inputActionButton.setAttribute('aria-haspopup', 'true');
              inputActionButton.setAttribute('aria-expanded', 'false');
          } else {
              inputActionButton.removeAttribute('aria-haspopup');
              inputActionButton.removeAttribute('aria-expanded');
          }

          // --- Logic for click action ---
          if (fileUploadEnabledUI && imageUploadEnabledUI) {
              // Both enabled: Show menu
              hideInputActionMenu();

              inputActionButton.onclick = (e) => {
                  e.stopPropagation();
                  closeDownloadMenuFunc(); // Close other menus
                  const isOpen = !state.isActionMenuOpen;
                  const openedWithKeyboard = e.detail === 0;

                  if (isOpen) {
                      if (inputActionMenu) {
                          inputActionMenu.removeAttribute('hidden');
                          requestAnimationFrame(() => {
                             if (inputActionMenu) inputActionMenu.classList.add('aipkit-action-menu-open');
                          });
                          inputActionMenu.setAttribute('aria-hidden', 'false');
                          if (openedWithKeyboard) {
                              requestAnimationFrame(() => {
                                  const firstMenuItem = inputActionMenu.querySelector('.aipkit_input_action_menu_item:not(:disabled)');
                                  if (firstMenuItem && typeof firstMenuItem.focus === 'function') {
                                      firstMenuItem.focus();
                                  }
                              });
                          }
                      }
                      inputActionButton.classList.add('aipkit_active');
                      inputActionButton.setAttribute('aria-expanded', 'true');
                      state.isActionMenuOpen = true;
                      state.activeInputActionMenu = inputActionMenu;
                      state.activeInputActionTrigger = inputActionButton;

                      // Add listeners to close menu when clicking outside or pressing Escape
                      setTimeout(() => {
                          state.closeActionMenuHandler = (event) => {
                              if (event.type === 'click' && (!state.activeInputActionMenu || !state.activeInputActionTrigger || state.activeInputActionMenu.contains(event.target) || state.activeInputActionTrigger.contains(event.target))) return;
                              if (event.type === 'keydown' && event.key !== 'Escape') return;
                              if (event.type === 'keydown') {
                                  event.preventDefault();
                                  event.stopPropagation();
                              }
                              closeInputActionMenuFunc(elements, state);
                              if (event.type === 'keydown' && event.key === 'Escape' && inputActionButton && typeof inputActionButton.focus === 'function') {
                                  inputActionButton.focus();
                              }
                          };
                          document.addEventListener('click', state.closeActionMenuHandler, { capture: true, once: false });
                          document.addEventListener('keydown', state.closeActionMenuHandler, { capture: true, once: false });
                      }, 0);
                  } else {
                      closeInputActionMenuFunc(elements, state);
                  }
              };
          } else if (fileUploadEnabledUI) {
              // Only File Upload enabled
              hideInputActionMenu();
              inputActionButton.onclick = async (e) => {
                  e.stopPropagation();
                  closeDownloadMenuFunc();
                  if (typeof window.aipkit_chatUI_prepareUploadsFeature === 'function') {
                      try {
                          await window.aipkit_chatUI_prepareUploadsFeature(elements, config, "file");
                      } catch (error) {
                          console.error('AIPKit InputActionButton: Failed to lazy-load file upload feature.', error);
                      }
                  }
                  if (fileUploadInput) {
                      fileUploadInput.click(); // Trigger the specific file input
                  } else {
                      console.warn('AIPKit InputActionButton: File upload input not found in elements.');
                      if (typeof window.aipkit_chatUI_showInlineNotice === 'function') {
                          window.aipkit_chatUI_showInlineNotice(
                              config.text?.uploadInitError || 'File upload feature not properly initialized.',
                              'error',
                              { elements, config },
                              true,
                              7000
                          );
                      }
                  }
              };
          } else if (imageUploadEnabledUI) {
              // Only Image Upload enabled
              hideInputActionMenu();
              inputActionButton.onclick = async (e) => {
                  e.stopPropagation();
                  closeDownloadMenuFunc();
                  if (typeof window.aipkit_chatUI_prepareUploadsFeature === 'function') {
                      try {
                          await window.aipkit_chatUI_prepareUploadsFeature(elements, config, "image");
                      } catch (error) {
                          console.error('AIPKit InputActionButton: Failed to lazy-load image upload feature.', error);
                      }
                  }
                  if (imageUploadInput) {
                      imageUploadInput.click();
                  } else {
                      console.warn('AIPKit InputActionButton: Image upload input not found in elements.');
                      if (typeof window.aipkit_chatUI_showInlineNotice === 'function') {
                          window.aipkit_chatUI_showInlineNotice(
                              config.text?.imageUploadInitError || 'Image upload feature not properly initialized.',
                              'error',
                              { elements, config },
                              true,
                              7000
                          );
                      }
                  }
              };
          }
      }

      window.aipkit_chatUI_setupInputActionButton = aipkit_chatUI_setupInputActionButton;

  })();

  /**
   * AIPKit Public Chat - Attach Action Button Listener
   */
  (function () {
    "use strict";

    /**
     * Attaches event listener to the main chat action button (Send/Clear/Stop).
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachActionButtonListener(
      elements,
      config,
      state,
      actions
    ) {
      const { actionButton } = elements;

      if (actionButton && actionButton.dataset.listenerAttached !== "true") {
        actionButton.addEventListener("click", () => {
          if (state.buttonState === "send" && !actionButton.disabled) {
            actions.sendMessageAction();
          } else if (state.buttonState === "clear") {
            actions.clearChatAction();
          } else if (state.buttonState === "stop") {
            actions.stopStreamAction();
          }
        });
        actionButton.dataset.listenerAttached = "true";
      }
    }

    window.aipkit_chatUI_attachActionButtonListener =
      aipkit_chatUI_attachActionButtonListener;
  })();

  /**
   * AIPKit Public Chat - Attach Input Field Listeners
   */
  (function () {
    "use strict";

    /**
     * Attaches event listeners to the chat input field (keydown and input).
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachInputFieldListeners(
      elements,
      config,
      state,
      actions
    ) {
      const { inputField, messagesEl, actionButton } = elements;

      if (inputField && inputField.dataset.listenerAttached !== "true") {
        inputField.addEventListener("keydown", (e) => {
          if (e.key === "Enter" && !state.isActionMenuOpen) {
            if (e.shiftKey) {
              // Allow Shift+Enter for new line
              setTimeout(() => {
                if (typeof window.aipkit_autoResizeTextarea === "function") {
                  window.aipkit_autoResizeTextarea(inputField);
                }
              }, 0);
            } else {
              e.preventDefault();
              if (state.buttonState === "send" && !actionButton.disabled) {
                actions.sendMessageAction();
              }
            }
          }
        });

        inputField.addEventListener("input", () => {
          const isEmpty = inputField.value.trim() === "";
          // Check if there are any messages other than the initial greeting
          const hasUserOrBotMessages =
            messagesEl.querySelectorAll(
              ".aipkit_chat_message:not(.aipkit_initial_greeting)"
            ).length > 0;

          if (!isEmpty) {
            state.buttonState = actions.setButtonStateAction(
              "send",
              false,
              state
            );
          } else {
            // If input is empty AND there are messages, show 'Clear'
            if (
              hasUserOrBotMessages &&
              state.buttonState !== "sending" &&
              state.buttonState !== "streaming" &&
              state.buttonState !== "stop"
            ) {
              state.buttonState = actions.setButtonStateAction(
                "clear",
                false,
                state
              );
            }
            // If input is empty AND no messages (or only initial), show disabled 'Send'
            else if (
              state.buttonState !== "sending" &&
              state.buttonState !== "streaming" &&
              state.buttonState !== "stop"
            ) {
              state.buttonState = actions.setButtonStateAction(
                "send",
                false,
                state
              );
            }
            // If currently sending/streaming, button state is handled by those processes
          }

          if (typeof window.aipkit_autoResizeTextarea === "function") {
            window.aipkit_autoResizeTextarea(inputField);
          }
        });
        inputField.dataset.listenerAttached = "true";
      }
    }

    window.aipkit_chatUI_attachInputFieldListeners =
      aipkit_chatUI_attachInputFieldListeners;
  })();

  /**
   * AIPKit Public Chat - Attach Fullscreen Button Listener
   */
  (function () {
    "use strict";

    /**
     * Attaches event listener to the fullscreen button.
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachFullscreenButtonListener(
      elements,
      config,
      state,
      actions
    ) {
      const { fullscreenButton } = elements;

      if (
        fullscreenButton &&
        typeof actions.toggleFullscreenAction === "function"
      ) {
        if (fullscreenButton.dataset.listenerAttached !== "true") {
          fullscreenButton.addEventListener(
            "click",
            actions.toggleFullscreenAction
          );
          fullscreenButton.dataset.listenerAttached = "true";
        }
      } else if (fullscreenButton) {
        console.warn(
          "AIPKit Chat Events: Fullscreen button exists, but toggleFullscreenAction action is missing."
        );
      }
    }

    window.aipkit_chatUI_attachFullscreenButtonListener =
      aipkit_chatUI_attachFullscreenButtonListener;
  })();

  /**
   * AIPKit Public Chat - Attach Web Search Toggle Listener
   */
  (function () {
    "use strict";

    /**
     * Attaches event listener to the provider Web Search toggle button.
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachWebSearchToggleListener(
      elements,
      config,
      state,
      actions
    ) {
      const { webSearchToggleButton } = elements;
      const allowProviderWebSearchTool =
        config.allowWebSearchTool &&
        (config.provider === "OpenAI" ||
          config.provider === "Claude" ||
          config.provider === "OpenRouter" ||
          config.provider === "xAI");

      if (webSearchToggleButton && allowProviderWebSearchTool) {
        if (webSearchToggleButton.dataset.listenerAttached !== "true") {
          webSearchToggleButton.addEventListener("click", (e) => {
            e.stopPropagation();
            // Ensure dependent close functions are available
            closeSearchMenus(elements, state);

            if (typeof window.aipkit_toggleWebSearchAction === "function") {
              window.aipkit_toggleWebSearchAction(elements, config, state);
            } else {
              console.error(
                "AIPKit Chat Events: aipkit_toggleWebSearchAction function not provided."
              );
            }
          });
          webSearchToggleButton.dataset.listenerAttached = "true";
        }
      } else if (webSearchToggleButton) {
        webSearchToggleButton.hidden = true;
        webSearchToggleButton.setAttribute("aria-hidden", "true");
      }
    }

    window.aipkit_chatUI_attachWebSearchToggleListener =
      aipkit_chatUI_attachWebSearchToggleListener;
  })();

  /**
   * AIPKit Public Chat - Attach Google Search Grounding Toggle Listener
   */
  (function () {
    "use strict";

    /**
     * Attaches event listener to the Google Search Grounding toggle button.
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachGoogleGroundingToggleListener(
      elements,
      config,
      state,
      actions
    ) {
      const { googleSearchGroundingToggleButton } = elements;
      const allowGoogleSearchGrounding =
        config.allowGoogleSearchGrounding && config.provider === "Google";

      if (googleSearchGroundingToggleButton && allowGoogleSearchGrounding) {
        if (
          googleSearchGroundingToggleButton.dataset.listenerAttached !== "true"
        ) {
          googleSearchGroundingToggleButton.addEventListener("click", (e) => {
            e.stopPropagation();
            // Ensure dependent close functions are available
            closeSearchMenus(elements, state);

            if (
              typeof window.aipkit_toggleGoogleSearchGroundingAction ===
              "function"
            ) {
              window.aipkit_toggleGoogleSearchGroundingAction(
                elements,
                config,
                state
              );
            } else {
              console.error(
                "AIPKit Chat Events: aipkit_toggleGoogleSearchGroundingAction function not provided."
              );
            }
          });
          googleSearchGroundingToggleButton.dataset.listenerAttached = "true";
        }
      } else if (googleSearchGroundingToggleButton) {
        googleSearchGroundingToggleButton.hidden = true;
        googleSearchGroundingToggleButton.setAttribute("aria-hidden", "true");
      }
    }

    window.aipkit_chatUI_attachGoogleGroundingToggleListener =
      aipkit_chatUI_attachGoogleGroundingToggleListener;
  })();

  /**
   * AIPKit Public Chat - Attach Download Menu Listeners
   */
  (function () {
    "use strict";

    /**
     * Attaches event listeners for the download button and its associated menu.
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachDownloadMenuListeners(
      elements,
      config,
      state,
      actions
    ) {
      const { downloadButton } = elements;

      if (!downloadButton || !config.enableDownload) {
        return;
      }

      const downloadWrapper = downloadButton.closest(".aipkit_download_wrapper");
      const downloadMenu = downloadWrapper
        ? downloadWrapper.querySelector(".aipkit_download_menu")
        : null;
      const pdfIsEnabled = config.pdfDownloadActive === true;
      const closeDownloadMenuFunc = window.aipkit_chatUI_closeActiveDownloadMenu;
      const setActiveDownloadMenuFunc = window.aipkit_chatUI_setActiveDownloadMenu;
      const getDownloadMenuStateFunc = window.aipkit_chatUI_getDownloadMenuState;

      if (downloadButton.dataset.ariaSetup !== "true") {
        downloadButton.setAttribute(
          "aria-haspopup",
          pdfIsEnabled && downloadMenu ? "menu" : "false"
        );
        downloadButton.setAttribute("aria-expanded", "false");
        if (downloadMenu && downloadMenu.id) {
          downloadButton.setAttribute("aria-controls", downloadMenu.id);
        }
        downloadButton.dataset.ariaSetup = "true";
      }

      if (downloadMenu && downloadMenu.dataset.ariaSetup !== "true") {
        downloadMenu.setAttribute("role", "menu");
        downloadMenu.setAttribute("aria-hidden", "true");
        const menuItems = downloadMenu.querySelectorAll(".aipkit_download_menu_item");
        menuItems.forEach((menuItem) => {
          if (!menuItem.hasAttribute("role")) {
            menuItem.setAttribute("role", "menuitem");
          }
        });
        downloadMenu.dataset.ariaSetup = "true";
      }

      const closeMenuAndFocusTrigger = (focusTrigger = false) => {
        if (typeof closeDownloadMenuFunc === "function") {
          closeDownloadMenuFunc();
        }
        if (focusTrigger && typeof downloadButton.focus === "function") {
          downloadButton.focus();
        }
      };

      const getEnabledMenuItems = () => {
        if (!downloadMenu) return [];
        return Array.from(
          downloadMenu.querySelectorAll(".aipkit_download_menu_item:not(:disabled)")
        );
      };

      const focusFirstMenuItem = () => {
        const menuItems = getEnabledMenuItems();
        if (menuItems.length > 0) {
          menuItems[0].focus();
        }
      };

      const openDownloadMenu = () => {
        if (!downloadMenu || !pdfIsEnabled) {
          return;
        }

        closeMenuAndFocusTrigger(false);
        downloadMenu.classList.add("aipkit_active");
        downloadMenu.setAttribute("aria-hidden", "false");
        downloadButton.setAttribute("aria-expanded", "true");

        if (typeof setActiveDownloadMenuFunc === "function") {
          const outsideClickHandler = (event) => {
            if (!downloadWrapper || !downloadWrapper.contains(event.target)) {
              closeMenuAndFocusTrigger(false);
            }
          };
          const escapeKeyHandler = (event) => {
            if (event.key !== "Escape") {
              return;
            }
            event.preventDefault();
            closeMenuAndFocusTrigger(true);
          };

          setActiveDownloadMenuFunc(
            downloadMenu,
            true,
            outsideClickHandler,
            downloadButton,
            escapeKeyHandler
          );
          document.addEventListener("click", outsideClickHandler, true);
          document.addEventListener("keydown", escapeKeyHandler, true);
        }

        requestAnimationFrame(focusFirstMenuItem);
      };

      const toggleDownloadMenu = () => {
        const downloadMenuState =
          typeof getDownloadMenuStateFunc === "function"
            ? getDownloadMenuStateFunc()
            : { isOpen: false, activeMenu: null };
        if (
          downloadMenuState.isOpen &&
          downloadMenuState.activeMenu === downloadMenu
        ) {
          closeMenuAndFocusTrigger(false);
        } else {
          openDownloadMenu();
        }
      };

      if (downloadButton.dataset.listenerAttached !== "true") {
        downloadButton.addEventListener("click", (e) => {
          e.stopPropagation();
          if (typeof window.aipkit_chatUI_closeActiveInputActionMenu === "function") {
            window.aipkit_chatUI_closeActiveInputActionMenu(elements, state);
          }

          if (pdfIsEnabled && downloadMenu) {
            toggleDownloadMenu();
            return;
          }

          if (typeof actions.downloadTranscriptTxtAction === "function") {
            actions.downloadTranscriptTxtAction();
          } else {
            console.error(
              "AIPKit Chat Events: downloadTranscriptTxtAction action not provided."
            );
          }
        });

        downloadButton.addEventListener("keydown", (event) => {
          if (!(pdfIsEnabled && downloadMenu)) {
            return;
          }
          if (event.key === "ArrowDown") {
            event.preventDefault();
            openDownloadMenu();
          } else if (event.key === "Escape") {
            event.preventDefault();
            closeMenuAndFocusTrigger(true);
          }
        });

        downloadButton.dataset.listenerAttached = "true";
      }

      if (downloadMenu && downloadMenu.dataset.listenerAttached !== "true") {
        downloadMenu.addEventListener("click", (e) => {
          const menuItem = e.target.closest(".aipkit_download_menu_item");
          if (!menuItem) {
            return;
          }

          const format = menuItem.getAttribute("data-format");
          if (format === "txt") {
            if (typeof actions.downloadTranscriptTxtAction === "function") {
              actions.downloadTranscriptTxtAction();
            } else {
              console.error(
                "AIPKit Chat Events: downloadTranscriptTxtAction action not provided."
              );
            }
          } else if (format === "pdf") {
            if (typeof actions.downloadTranscriptPdfAction === "function") {
              let printWindow = null;
              try {
                printWindow = window.open("", "_blank");
                if (printWindow) {
                  printWindow.opener = null;
                }
              } catch (error) {
                printWindow = null;
              }
              actions.downloadTranscriptPdfAction(printWindow);
            } else {
              console.error(
                "AIPKit Chat Events: downloadTranscriptPdfAction action not provided."
              );
            }
          }

          closeMenuAndFocusTrigger(false);
        });

        downloadMenu.addEventListener("keydown", (event) => {
          const menuItems = getEnabledMenuItems();
          if (event.key === "Escape") {
            event.preventDefault();
            closeMenuAndFocusTrigger(true);
            return;
          }

          if (event.key === "Tab") {
            closeMenuAndFocusTrigger(false);
            return;
          }

          navigateMenu(event, menuItems);
        });

        downloadMenu.dataset.listenerAttached = "true";
      }
    }

    window.aipkit_chatUI_attachDownloadMenuListeners =
      aipkit_chatUI_attachDownloadMenuListeners;
  })();

  /**
   * AIPKit Public Chat - Attach Input Action Menu Listener
   */
  (function () {
    "use strict";

    /**
     * Attaches event listener for clicks within the input action menu (PDF/Image).
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachInputActionMenuListener(
      elements,
      config,
      state,
      actions
    ) {
      const { inputActionMenu } = elements;

      if (
        inputActionMenu &&
        typeof window.aipkit_chatUI_setupInputActionButton === "function"
      ) {
        // The setupInputActionButton function manages showing/hiding the menu itself.
        // This listener is for clicks *inside* the menu.
        if (inputActionMenu.dataset.itemClickListenerAttached !== "true") {
          inputActionMenu.addEventListener("click", async (e) => {
            const menuItem = e.target.closest(".aipkit_input_action_menu_item");
            if (!menuItem) return;

            e.preventDefault(); // Prevent default button action if any
            const actionType = menuItem.dataset.aipkitUploadAction;

            if (actionType === "image") {
              if (typeof window.aipkit_chatUI_prepareUploadsFeature === "function") {
                try {
                  await window.aipkit_chatUI_prepareUploadsFeature(elements, config, "image");
                } catch (error) {
                  console.error(
                    "AIPKit Chat Events: Failed to lazy-load image upload feature.",
                    error
                  );
                }
              }
              if (elements.imageUploadInput) {
                elements.imageUploadInput.click(); // Trigger the hidden file input
              } else {
                console.warn(
                  "AIPKit Chat Events: Image upload input not found for menu item."
                );
                if (typeof window.aipkit_chatUI_showInlineNotice === "function") {
                  window.aipkit_chatUI_showInlineNotice(
                    config.text?.imageUploadInitError ||
                      "Image upload feature not properly initialized.",
                    "error",
                    { elements, config },
                    true,
                    7000
                  );
                }
              }
            } else if (actionType === "file") {
              if (typeof window.aipkit_chatUI_prepareUploadsFeature === "function") {
                try {
                  await window.aipkit_chatUI_prepareUploadsFeature(elements, config, "file");
                } catch (error) {
                  console.error(
                    "AIPKit Chat Events: Failed to lazy-load file upload feature.",
                    error
                  );
                }
              }
              if (elements.fileUploadInput) {
                elements.fileUploadInput.click(); // Trigger the specific file input
              } else {
                console.warn(
                  "AIPKit Chat Events: File upload input not found for menu item."
                );
                if (typeof window.aipkit_chatUI_showInlineNotice === "function") {
                  window.aipkit_chatUI_showInlineNotice(
                    config.text?.uploadInitError ||
                      "File upload feature not properly initialized.",
                    "error",
                    { elements, config },
                    true,
                    7000
                  );
                }
              }
            }

            // Ensure dependent close function is available
            if (
              typeof window.aipkit_chatUI_closeActiveInputActionMenu ===
              "function"
            )
              window.aipkit_chatUI_closeActiveInputActionMenu(elements, state);
          });
          inputActionMenu.dataset.itemClickListenerAttached = "true";
        }

        if (inputActionMenu.dataset.itemKeydownListenerAttached !== "true") {
          inputActionMenu.addEventListener("keydown", (event) => {
            const menuItems = Array.from(
              inputActionMenu.querySelectorAll(
                ".aipkit_input_action_menu_item:not(:disabled)"
              )
            );

            if (event.key === "Escape") {
              event.preventDefault();
              if (
                typeof window.aipkit_chatUI_closeActiveInputActionMenu ===
                "function"
              ) {
                window.aipkit_chatUI_closeActiveInputActionMenu(elements, state);
              }
              if (
                elements.inputActionButton &&
                typeof elements.inputActionButton.focus === "function"
              ) {
                elements.inputActionButton.focus();
              }
              return;
            }

            if (event.key === "Tab") {
              if (
                typeof window.aipkit_chatUI_closeActiveInputActionMenu ===
                "function"
              ) {
                window.aipkit_chatUI_closeActiveInputActionMenu(elements, state);
              }
              return;
            }

            navigateMenu(event, menuItems);
          });
          inputActionMenu.dataset.itemKeydownListenerAttached = "true";
        }
      }
    }

    window.aipkit_chatUI_attachInputActionMenuListener =
      aipkit_chatUI_attachInputActionMenuListener;
  })();

  /**
   * AIPKit Public Chat - Attach Voice Input Controls
   */
  (function () {
    "use strict";

    /**
     * Attaches listeners to the microphone and recording panel controls.
     * @param {object} elements UI elements reference.
     * @param {object} config Chatbot configuration object.
     * @param {object} state Mutable state object.
     */
    function aipkit_chatUI_attachVoiceInputButtonListener(
      elements,
      config,
      state
    ) {
      const {
        container,
        voiceInputButton,
        voiceRecordingPanel,
        voiceCancelButton,
        voiceConfirmButton,
      } = elements;

      if (!voiceInputButton || !config.enableVoiceInputUI) return;

      const resetStartingState = () => {
        state.isStartingRecording = false;
        voiceInputButton.disabled =
          config.requireConsentCompliance && !state.consentGiven;
        voiceInputButton.classList.remove("aipkit_starting");
        voiceInputButton.removeAttribute("aria-busy");
        voiceInputButton.setAttribute(
          "aria-label",
          config.text?.voiceInput || "Voice input"
        );
        voiceInputButton.title = config.text?.voiceInput || "Voice input";
      };

      const runVoiceAction = (action) => {
        if (typeof window.aipkit_chatUI_handleVoiceInputAction !== "function") {
          return false;
        }
        return window.aipkit_chatUI_handleVoiceInputAction(
          elements,
          config,
          state,
          state.consentUIInstance,
          action
        );
      };

      if (voiceInputButton.dataset.listenerAttached !== "true") {
        voiceInputButton.addEventListener("click", async () => {
          if (
            state.isStartingRecording ||
            state.isRecording ||
            state.isTranscribing
          ) {
            return;
          }

          state.voiceRecordingCancelled = false;
          state.isStartingRecording = true;
          voiceInputButton.disabled = true;
          voiceInputButton.classList.add("aipkit_starting");
          voiceInputButton.setAttribute("aria-busy", "true");
          voiceInputButton.setAttribute(
            "aria-label",
            config.text?.voiceStarting || "Starting microphone..."
          );
          voiceInputButton.title =
            config.text?.voiceStarting || "Starting microphone...";

          if (typeof window.aipkit_chatUI_prepareSttFeature === "function") {
            try {
              await window.aipkit_chatUI_prepareSttFeature();
            } catch (error) {
              console.error(
                "AIPKit Chat Events: Failed to lazy-load voice input feature.",
                error
              );
              resetStartingState();
              return;
            }
          }

          if (state.voiceRecordingCancelled) {
            resetStartingState();
            state.voiceRecordingCancelled = false;
            return;
          }

          if (!runVoiceAction("start")) {
            resetStartingState();
            console.error(
              "AIPKit Chat Events: Voice input action could not be started."
            );
          }
        });
        voiceInputButton.dataset.listenerAttached = "true";
      }

      if (
        voiceCancelButton &&
        voiceCancelButton.dataset.listenerAttached !== "true"
      ) {
        voiceCancelButton.addEventListener("click", () => {
          if (state.isStartingRecording || state.isRecording) {
            runVoiceAction("cancel");
          }
        });
        voiceCancelButton.dataset.listenerAttached = "true";
      }

      if (
        voiceConfirmButton &&
        voiceConfirmButton.dataset.listenerAttached !== "true"
      ) {
        voiceConfirmButton.addEventListener("click", () => {
          if (state.isRecording) runVoiceAction("confirm");
        });
        voiceConfirmButton.dataset.listenerAttached = "true";
      }

      if (
        voiceRecordingPanel &&
        voiceRecordingPanel.dataset.keyboardListenerAttached !== "true"
      ) {
        voiceRecordingPanel.addEventListener("keydown", (event) => {
          if (event.key !== "Escape") return;
          event.preventDefault();
          if (state.isStartingRecording || state.isRecording) {
            runVoiceAction("cancel");
          }
        });
        voiceRecordingPanel.dataset.keyboardListenerAttached = "true";
      }

      if (container && !container._aipkitVoicePopupCloseListenerAttached) {
        container.addEventListener("aipkit:popupClosed", () => {
          if (!state.isStartingRecording && !state.isRecording) return;
          state.voiceRecordingCancelled = true;
          if (!runVoiceAction("cancel")) {
            resetStartingState();
          }
        });
        container._aipkitVoicePopupCloseListenerAttached = true;
      }
    }

    window.aipkit_chatUI_attachVoiceInputButtonListener =
      aipkit_chatUI_attachVoiceInputButtonListener;
  })();

  /**
   * AIPKit Public Chat - Attach File Upload Input Listener
   */
  (function () {
    "use strict";

    /**
     * Attaches event listener to the general file upload input field.
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachFileUploadInputListener(
      elements,
      config,
      state,
      actions
    ) {
      const { fileUploadInput } = elements;

      if (fileUploadInput && config.fileUploadEnabledUI) {
        if (fileUploadInput.dataset.listenerAttached !== "true") {
          fileUploadInput.addEventListener("change", async (event) => {
            const file = event.target.files[0];
            if (
              file &&
              typeof window.aipkit_chatUI_prepareUploadsFeature === "function"
            ) {
              try {
                await window.aipkit_chatUI_prepareUploadsFeature(elements, config, "file");
              } catch (error) {
                console.error(
                  "AIPKit Chat Events: Failed to lazy-load upload feature.",
                  error
                );
              }
            }
            if (
              file &&
              window.aipkitChatFileUpload &&
              typeof window.aipkitChatFileUpload.processFile === "function"
            ) {
              const inputAreaEl =
                elements.inputArea ||
                fileUploadInput.closest(".aipkit_chat_input");
              await window.aipkitChatFileUpload.processFile(
                file,
                fileUploadInput,
                inputAreaEl,
                config
              );
            } else if (file) {
              console.error(
                "AIPKit Chat Events: aipkitChatFileUpload.processFile not found."
              );
              if (
                typeof window.aipkit_displayChatFileUploadStatus === "function"
              ) {
                window.aipkit_displayChatFileUploadStatus(
                  "File processing system error.",
                  "error",
                  elements.inputArea
                );
              }
            }
          });
          fileUploadInput.dataset.listenerAttached = "true";
        }
      }
    }

    window.aipkit_chatUI_attachFileUploadInputListener =
      aipkit_chatUI_attachFileUploadInputListener;
  })();

  /**
   * AIPKit Public Chat - Attach Play Button Listener (Delegated)
   */
  (function () {
    "use strict";

    /**
     * Attaches a delegated event listener to the messages container for TTS play buttons.
     * @param {object} elements - UI elements reference.
     * @param {object} config - Chatbot configuration object.
     * @param {object} state - Mutable state object.
     * @param {object} actions - Action functions object.
     */
    function aipkit_chatUI_attachPlayButtonListener(
      elements,
      config,
      state,
      actions
    ) {
      const { messagesEl } = elements;

      if (messagesEl && typeof actions.handlePlayAction === "function") {
        if (messagesEl.dataset.playListenerAttached !== "true") {
          messagesEl.addEventListener("click", function (event) {
            const playButton = event.target.closest(".aipkit_play_btn");
            if (playButton && !playButton.disabled) {
              actions.handlePlayAction(playButton); // Call the action from the passed `actions` object
            }
          });
          messagesEl.dataset.playListenerAttached = "true";
        }
      } else if (messagesEl) {
        console.warn(
          "AIPKit Chat Events: handlePlayAction function not provided or invalid during listener attachment."
        );
      }
    }

    window.aipkit_chatUI_attachPlayButtonListener =
      aipkit_chatUI_attachPlayButtonListener;
  })();

  /**
   * AIPKit Public Chat - Attach Core Event Listeners (Orchestrator)
   *
   * Orchestrates the attachment of all core event listeners for a chat instance
   * by calling modularized attachment functions.
   */
  (function () {
    "use strict";

    /**
     * Attaches core event listeners to the chat UI elements by delegating to modular functions.
     * @param {object} elements - UI elements reference for the specific chat instance.
     * @param {object} config - Chatbot configuration object for the specific chat instance.
     * @param {object} state - Mutable state object for the specific chat instance.
     * @param {object} actions - Action functions object for the specific chat instance.
     */
    function aipkit_chatUI_attachEventListeners(
      elements,
      config,
      state,
      actions
    ) {
      const requiredDependencies = [
        "aipkit_chatUI_attachActionButtonListener",
        "aipkit_chatUI_attachInputFieldListeners",
        "aipkit_chatUI_attachFullscreenButtonListener",
        "aipkit_chatUI_attachWebSearchToggleListener",
        "aipkit_chatUI_attachGoogleGroundingToggleListener",
        "aipkit_chatUI_attachDownloadMenuListeners",
      ];

      const optionalDependencies = [
        "aipkit_chatUI_attachInputActionMenuListener",
        "aipkit_chatUI_attachVoiceInputButtonListener",
        "aipkit_chatUI_attachFileUploadInputListener",
        "aipkit_chatUI_attachPlayButtonListener",
      ];

      let allRequiredDepsFound = true;
      requiredDependencies.forEach((dep) => {
        if (typeof window[dep] !== "function") {
          console.error(
            `AIPKit AttachEventListeners Orchestrator: Missing dependency function -> ${dep}.`
          );
          allRequiredDepsFound = false;
        }
      });

      optionalDependencies.forEach((dep) => {
        if (typeof window[dep] !== "function") {
          console.warn(
            `AIPKit AttachEventListeners Orchestrator: Optional dependency is unavailable -> ${dep}.`
          );
        }
      });

      if (!allRequiredDepsFound) {
        console.error(
          "AIPKit AttachEventListeners Orchestrator: Halting due to missing required dependencies. Some UI features may not work."
        );
        return;
      }

      // Call modularized listener attachment functions
      window.aipkit_chatUI_attachActionButtonListener(
        elements,
        config,
        state,
        actions
      );
      window.aipkit_chatUI_attachInputFieldListeners(
        elements,
        config,
        state,
        actions
      );
      window.aipkit_chatUI_attachFullscreenButtonListener(
        elements,
        config,
        state,
        actions
      );
      window.aipkit_chatUI_attachWebSearchToggleListener(
        elements,
        config,
        state,
        actions
      );
      window.aipkit_chatUI_attachGoogleGroundingToggleListener(
        elements,
        config,
        state,
        actions
      );
      window.aipkit_chatUI_attachDownloadMenuListeners(
        elements,
        config,
        state,
        actions
      );
      if (typeof window.aipkit_chatUI_attachInputActionMenuListener === "function") {
        window.aipkit_chatUI_attachInputActionMenuListener(
          elements,
          config,
          state,
          actions
        );
      }
      if (typeof window.aipkit_chatUI_attachVoiceInputButtonListener === "function") {
        window.aipkit_chatUI_attachVoiceInputButtonListener(
          elements,
          config,
          state
        );
      }
      if (typeof window.aipkit_chatUI_attachFileUploadInputListener === "function") {
        window.aipkit_chatUI_attachFileUploadInputListener(
          elements,
          config,
          state,
          actions
        );
      }
      if (typeof window.aipkit_chatUI_attachPlayButtonListener === "function") {
        window.aipkit_chatUI_attachPlayButtonListener(
          elements,
          config,
          state,
          actions
        );
      }
    }

    window.aipkit_chatUI_attachEventListeners =
      aipkit_chatUI_attachEventListeners;
  })();
})();
