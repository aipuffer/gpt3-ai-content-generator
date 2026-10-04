/** Optional chatbot sidebar: state, history list and conversation actions. */
(function () {
    "use strict";


    const SIDEBAR_STORAGE_KEY_PREFIX = 'aipkit_sidebar_state_';

    /**
     * Gets the localStorage key for storing sidebar state.
     * @param {string|number} botId The ID of the bot.
     * @returns {string} The storage key.
     */
    function aipkit_sidebar_getStorageKey(botId) {
        return `${SIDEBAR_STORAGE_KEY_PREFIX}${botId || 'default'}`;
    }

    window.aipkit_sidebar_getStorageKey = aipkit_sidebar_getStorageKey;


    const MOBILE_BREAKPOINT = 767;

    function ensureOverlayElement(sidebarEl, overlayLabel) {
        const container = sidebarEl ? sidebarEl.closest('.aipkit_chat_container') : null;
        if (!container || container.closest('#aipkit_admin_chat_preview_container')) {
            return null;
        }

        const bodyEl = sidebarEl.closest('.aipkit_chat_body') || container;
        let overlayEl = container.querySelector('.aipkit_sidebar_mobile_overlay');
        if (!overlayEl) {
            overlayEl = document.createElement('button');
            overlayEl.type = 'button';
            overlayEl.className = 'aipkit_sidebar_mobile_overlay';
            overlayEl.setAttribute('aria-label', overlayLabel);
            overlayEl.setAttribute('aria-hidden', 'true');
            overlayEl.tabIndex = -1;
            bodyEl.appendChild(overlayEl);
        } else {
            overlayEl.setAttribute('aria-label', overlayLabel);
        }
        return overlayEl;
    }

    /**
     * Manages the event listener for closing the sidebar when clicking outside on mobile.
     * @param {boolean} isSidebarOpen Current state of the sidebar.
     * @param {HTMLElement} sidebarEl The sidebar element.
     * @param {HTMLElement} sidebarToggleBtn The button that toggles the sidebar.
     * @param {function} clickHandler The click handler function to attach/remove. This handler should be defined in the orchestrator and passed in.
     * @param {object} stateRef A reference to an object that can store the handler, e.g., { mobileOverlayHandler: null }
     * @param {object} config Chatbot configuration.
     */
    function aipkit_sidebar_manageMobileOverlayListener(isSidebarOpen, sidebarEl, sidebarToggleBtn, clickHandler, stateRef, config) {
        if (!stateRef) {
            return;
        }
        const overlayLabel = config?.text?.sidebarOverlayClose || 'Close conversation sidebar';

        const isMobileView = window.innerWidth <= MOBILE_BREAKPOINT;
        const container = sidebarEl ? sidebarEl.closest('.aipkit_chat_container') : null;
        const overlayEl = isMobileView
            ? ensureOverlayElement(sidebarEl, overlayLabel)
            : (container ? container.querySelector('.aipkit_sidebar_mobile_overlay') : null);

        // Remove any existing listener first to avoid duplicates.
        if (stateRef.mobileOverlayHandler && stateRef.mobileOverlayTargetEl) {
            stateRef.mobileOverlayTargetEl.removeEventListener('click', stateRef.mobileOverlayHandler);
        }
        if (overlayEl) {
            overlayEl.classList.remove('aipkit-is-active');
            overlayEl.setAttribute('aria-hidden', 'true');
        }
        if (stateRef) {
            stateRef.mobileOverlayHandler = null;
            stateRef.mobileOverlayTargetEl = null;
        }

        if (isSidebarOpen && isMobileView && overlayEl) {
            stateRef.mobileOverlayHandler = clickHandler;
            stateRef.mobileOverlayTargetEl = overlayEl;
            overlayEl.classList.add('aipkit-is-active');
            overlayEl.setAttribute('aria-hidden', 'false');
            overlayEl.addEventListener('click', stateRef.mobileOverlayHandler);
        }
    }

    window.aipkit_sidebar_manageMobileOverlayListener = aipkit_sidebar_manageMobileOverlayListener;


    /**
     * Applies the current sidebar state to the DOM (CSS classes, ARIA attributes) and localStorage.
     * @param {boolean} isSidebarOpen Current state of the sidebar.
     * @param {HTMLElement} container The main chat container element.
     * @param {HTMLElement} sidebarToggleBtn The button that toggles the sidebar.
     * @param {string} sidebarStorageKey The localStorage key for this sidebar.
     * @param {function} manageMobileOverlayListenerFunc Function to manage mobile overlay listener.
     * @param {HTMLElement} sidebarEl The sidebar DOM element.
     * @param {object} stateRef A reference to an object that can store the mobile overlay handler.
     * @param {function} mobileClickHandler The actual click handler for the mobile overlay.
     * @param {boolean} isOpening Flag indicating if the sidebar is currently in the process of opening.
     * @param {object} config Chatbot configuration.
     */
    function aipkit_sidebar_applyCurrentState(isSidebarOpen, container, sidebarToggleBtn, sidebarStorageKey, manageMobileOverlayListenerFunc, sidebarEl, stateRef, mobileClickHandler, isOpening, config) {
        if (!container || !sidebarToggleBtn) return;

        const toggleButtons = Array.from(container.querySelectorAll('.aipkit_sidebar_toggle_btn'));
        const focusedToggle = toggleButtons.includes(document.activeElement);
        container.classList.toggle('aipkit-sidebar-state-open', isSidebarOpen);
        container.classList.toggle('aipkit-sidebar-state-closed', !isSidebarOpen);
        toggleButtons.forEach((button) => {
            button.setAttribute('aria-expanded', isSidebarOpen.toString());
        });
        if (sidebarEl) {
            sidebarEl.setAttribute('aria-hidden', (!isSidebarOpen).toString());
        }
        localStorage.setItem(sidebarStorageKey, isSidebarOpen ? 'open' : 'closed');

        if (typeof manageMobileOverlayListenerFunc === 'function' && sidebarEl) {
            manageMobileOverlayListenerFunc(isSidebarOpen, sidebarEl, sidebarToggleBtn, mobileClickHandler, stateRef, config);
        }

        if (focusedToggle) {
            const nextToggle = container.querySelector(
                isSidebarOpen
                    ? '.aipkit_sidebar_toggle_btn--sidebar'
                    : '.aipkit_sidebar_toggle_btn--main'
            );
            if (nextToggle) {
                requestAnimationFrame(() => nextToggle.focus());
            }
        }
    }

    window.aipkit_sidebar_applyCurrentState = aipkit_sidebar_applyCurrentState;


    /**
     * Toggles the sidebar open/closed state and calls functions to update UI and fetch data.
     * @param {object} state - Mutable state object containing isSidebarOpen.
     * @param {function} applyCurrentStateFunc - Reserved API argument; the caller applies DOM state after toggling.
     * @param {function} fetchConversationListFunc - Function to fetch conversations if opening.
     */
    function aipkit_sidebar_toggleSidebar(state, applyCurrentStateFunc, fetchConversationListFunc) {
        const wasOpen = state.isSidebarOpen;
        state.isSidebarOpen = !state.isSidebarOpen;
        const isOpening = !wasOpen && state.isSidebarOpen; // True if sidebar is being opened

        // Fetch list only when opening and if fetch function exists
        if (isOpening && typeof fetchConversationListFunc === 'function') {
            fetchConversationListFunc();
        }
    }

    window.aipkit_sidebar_toggleSidebar = aipkit_sidebar_toggleSidebar;


    /**
     * Handles the "New Chat" button click. Clears the chat and active state.
     * @param {object} actions - The main actions object from chatbot/instance.js.
     * @param {HTMLElement} sidebarContentEl - The sidebar content element.
     * @param {object} state - Mutable state object containing isSidebarOpen.
     * @param {function} toggleSidebarFunc - Function to toggle the sidebar (needs full context).
     */
    function aipkit_sidebar_handleNewChat(actions, sidebarContentEl, state, toggleSidebarFunc) {
        const botId = actions.config?.botId || 'unknown'; // Get botId from actions.config

        if (typeof actions.clearChatAction === 'function') {
            actions.clearChatAction(); // This handles resetting UUIDs and fresh session state

            const activeItems = sidebarContentEl ? sidebarContentEl.querySelectorAll('.aipkit_conversation_item.aipkit_active') : [];
            if (activeItems.length > 0) {
                activeItems.forEach((item) => item.classList.remove('aipkit_active'));
            }

            if (window.innerWidth <= MOBILE_BREAKPOINT && state.isSidebarOpen && typeof toggleSidebarFunc === 'function') {
                // The toggleSidebarFunc itself needs its full context from the orchestrator.
                // This call assumes toggleSidebarFunc is pre-bound or can access its needed state.
                toggleSidebarFunc();
            }
        } else {
            console.error(`AIPKit Sidebar (${botId}): clearChatAction is not a function in actions object.`);
        }
    }

    window.aipkit_sidebar_handleNewChat = aipkit_sidebar_handleNewChat;


    /**
     * Fetches the conversation list from the backend.
     * @param {HTMLElement} sidebarContentEl - The element to display the list or loading/error messages.
     * @param {object} config - Chatbot configuration, must include nonce and text labels.
     * @param {function} renderConversationListFunc - Function to render the fetched list.
     */
    function aipkit_sidebar_fetchConversationList(sidebarContentEl, config, renderConversationListFunc) {
        const botId = config.botId || 'unknown';
        const renderState = (message, type = 'muted', showSpinner = false) => {
            if (typeof window.aipkit_chatUI_renderUIState === 'function') {
                window.aipkit_chatUI_renderUIState(sidebarContentEl, {
                    message,
                    type,
                    showSpinner,
                    scope: 'sidebar'
                });
            } else if (sidebarContentEl) {
                sidebarContentEl.textContent = message;
            }
        };

        if (!sidebarContentEl || !config || !renderConversationListFunc) {
            console.error(`AIPKit Sidebar (${botId}): Missing elements or functions for fetchConversationList.`);
            if (sidebarContentEl) renderState(config.text?.historyLoadError || 'Error: Cannot load list.', 'error');
            return;
        }

        if (!config.nonce) { // Nonce is required to fetch user-specific list
            renderState(config.text.historyGuests || 'History unavailable.', 'muted');
            return;
        }

        renderState(config.text?.historyLoading || 'Loading conversations...', 'loading', true);

        window.aipkit_frontendApiRequest('aipkit_get_conversations_list', {}, config)
            .then(data => {
                if (typeof renderConversationListFunc === 'function') {
                    renderConversationListFunc(data.conversations || []);
                } else {
                    console.error(`AIPKit Sidebar (${botId}): renderConversationListFunc is not a function.`);
                    renderState(config.text?.historyRenderError || 'Error: Cannot display list.', 'error');
                }
            })
            .catch(error => {
                console.error(`AIPKit Sidebar (${botId}): Error fetching conversation list:`, error);
                renderState(
                    `${config.text?.historyLoadErrorPrefix || 'Error loading list'}: ${error.message || 'Unknown error'}`,
                    'error'
                );
            });
    }

    window.aipkit_sidebar_fetchConversationList = aipkit_sidebar_fetchConversationList;


  const BATCH_SIZE = 24;
  const deleteSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-x"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>`;

  function aipkit_sidebar_renderConversationList(
    conversations,
    sidebarContentEl,
    config,
    stateRef,
    loadConversationFunc,
    handleDeleteConversationFunc,
    toggleSidebarFunc
  ) {
    const botId = config?.botId || "unknown";

    if (
      !sidebarContentEl ||
      !config ||
      !stateRef ||
      typeof loadConversationFunc !== "function" ||
      typeof handleDeleteConversationFunc !== "function" ||
      typeof toggleSidebarFunc !== "function"
    ) {
      console.error(
        `AIPKit Sidebar (${botId}): Missing elements or functions for renderConversationList.`
      );
      return;
    }

    if (typeof stateRef.sidebarListCleanup === "function") {
      stateRef.sidebarListCleanup();
    }

    const sidebarEl = sidebarContentEl.closest(".aipkit_chat_sidebar");
    const searchSection = sidebarEl?.querySelector(".aipkit_sidebar_search");
    const searchInput = sidebarEl?.querySelector(".aipkit_sidebar_search_input");
    const newChatButton = sidebarEl?.querySelector(".aipkit_sidebar_new_chat_btn");
    const openConversationLabel =
      config.text?.openConversation || "Open conversation";
    const deleteConversationLabel =
      config.text?.deleteConversation || "Delete conversation";
    const loadingOlderLabel =
      config.text?.historyLoadingOlder || "Loading older conversations...";

    stateRef.sidebarConversations = Array.isArray(conversations)
      ? conversations.slice()
      : [];
    stateRef.sidebarSearchQuery = String(
      stateRef.sidebarSearchQuery || ""
    ).trim();

    let visibleCount = BATCH_SIZE;
    let isLoadingBatch = false;
    let loadObserver = null;
    let scrollHandler = null;

    const renderState = (message, type = "muted") => {
      if (typeof window.aipkit_chatUI_renderUIState === "function") {
        window.aipkit_chatUI_renderUIState(sidebarContentEl, {
          message,
          type,
          scope: "sidebar",
        });
      } else {
        sidebarContentEl.textContent = message;
      }
    };

    const getFilteredConversations = () => {
      const query = stateRef.sidebarSearchQuery.toLocaleLowerCase();
      if (!query) {
        return stateRef.sidebarConversations;
      }
      return stateRef.sidebarConversations.filter((conversation) => {
        const fallbackId = String(conversation?.id || "");
        const title = String(conversation?.title || fallbackId);
        return title.toLocaleLowerCase().includes(query);
      });
    };

    const createConversationItem = (conversation) => {
      const conversationId = String(conversation?.id || "");
      const conversationTitle = String(
        conversation?.title || conversationId.substring(0, 8)
      );
      const item = document.createElement("div");
      item.className = "aipkit_conversation_item";
      item.setAttribute("data-conversation-id", conversationId);

      const titleButton = document.createElement("button");
      titleButton.type = "button";
      titleButton.className = "aipkit_conversation_item_title_btn";
      titleButton.setAttribute(
        "aria-label",
        `${openConversationLabel}: ${conversationTitle}`
      );
      titleButton.title = conversationTitle;

      const titleSpan = document.createElement("span");
      titleSpan.className = "aipkit_conversation_item_title";
      titleSpan.textContent = conversationTitle;
      titleButton.appendChild(titleSpan);
      item.appendChild(titleButton);

      const deleteButton = document.createElement("button");
      deleteButton.type = "button";
      deleteButton.className = "aipkit_conversation_item_delete_btn";
      deleteButton.setAttribute(
        "aria-label",
        `${deleteConversationLabel}: ${conversationTitle}`
      );
      deleteButton.title = deleteConversationLabel;
      deleteButton.innerHTML = deleteSvg;
      item.appendChild(deleteButton);

      const spinner = document.createElement("span");
      spinner.className = "aipkit_conversation_item_spinner aipkit_spinner";
      spinner.setAttribute("aria-hidden", "true");
      item.appendChild(spinner);

      if (
        window.aipkit_current_conversation_uuid &&
        conversationId === window.aipkit_current_conversation_uuid
      ) {
        item.classList.add("aipkit_active");
      }

      if (
        stateRef.isDeletingId &&
        stateRef.isDeletingId === conversationId
      ) {
        item.classList.add("aipkit_conversation_item--deleting");
        item.setAttribute("aria-busy", "true");
        titleButton.disabled = true;
        deleteButton.disabled = true;
        deleteButton.setAttribute("aria-disabled", "true");
      }

      titleButton.addEventListener("click", () => {
        if (stateRef.isDeletingId === conversationId) {
          return;
        }
        loadConversationFunc(conversationId);
        if (window.innerWidth <= MOBILE_BREAKPOINT && stateRef.isSidebarOpen) {
          toggleSidebarFunc();
        }
      });

      deleteButton.addEventListener("click", (event) => {
        event.stopPropagation();
        if (stateRef.isDeletingId) {
          return;
        }
        handleDeleteConversationFunc(conversationId, item);
      });

      return item;
    };

    const createLoadingRow = () => {
      const loadingRow = document.createElement("div");
      loadingRow.className = "aipkit_sidebar_load_older";
      loadingRow.setAttribute("role", "status");

      const spinner = document.createElement("span");
      spinner.className = "aipkit_spinner";
      spinner.setAttribute("aria-hidden", "true");
      loadingRow.appendChild(spinner);

      const label = document.createElement("span");
      label.textContent = loadingOlderLabel;
      loadingRow.appendChild(label);
      return loadingRow;
    };

    const renderList = (options = {}) => {
      const previousScrollTop = sidebarContentEl.scrollTop;
      const filteredConversations = getFilteredConversations();
      const hasConversations = stateRef.sidebarConversations.length > 0;

      if (loadObserver) {
        loadObserver.disconnect();
        loadObserver = null;
      }

      if (searchSection) {
        searchSection.hidden = !hasConversations;
      }
      if (searchInput && searchInput.value !== stateRef.sidebarSearchQuery) {
        searchInput.value = stateRef.sidebarSearchQuery;
      }

      if (!hasConversations) {
        stateRef.sidebarSearchQuery = "";
        if (searchInput) {
          searchInput.value = "";
        }
        renderState(
          config.text?.historySidebarEmpty ||
            "No conversations yet — start one to see it here."
        );
      } else if (filteredConversations.length === 0) {
        renderState(
          config.text?.historySearchEmpty ||
            "No conversations match your search."
        );
      } else {
        sidebarContentEl.innerHTML = "";
        const renderCount = Math.min(
          visibleCount,
          filteredConversations.length
        );
        filteredConversations
          .slice(0, renderCount)
          .forEach((conversation) => {
            sidebarContentEl.appendChild(
              createConversationItem(conversation)
            );
          });

        if (renderCount < filteredConversations.length) {
          const loadingRow = createLoadingRow();
          sidebarContentEl.appendChild(loadingRow);

          if (typeof IntersectionObserver === "function") {
            loadObserver = new IntersectionObserver(
              (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                  loadNextBatch();
                }
              },
              { root: sidebarContentEl, rootMargin: "48px 0px", threshold: 0.01 }
            );
            loadObserver.observe(loadingRow);
          }
        }
      }

      if (options.preserveScroll) {
        sidebarContentEl.scrollTop = previousScrollTop;
      } else {
        sidebarContentEl.scrollTop = 0;
      }

      if (options.focusConversationId) {
        requestAnimationFrame(() => {
          const escapedId = window.CSS?.escape
            ? window.CSS.escape(options.focusConversationId)
            : options.focusConversationId.replace(/[^a-zA-Z0-9_-]/g, "");
          const focusTarget = sidebarContentEl.querySelector(
            `[data-conversation-id="${escapedId}"] .aipkit_conversation_item_title_btn`
          );
          if (focusTarget) {
            focusTarget.focus();
          } else if (searchInput && !searchSection?.hidden) {
            searchInput.focus();
          } else {
            newChatButton?.focus();
          }
        });
      }
    };

    const loadNextBatch = () => {
      const filteredConversations = getFilteredConversations();
      if (
        isLoadingBatch ||
        visibleCount >= filteredConversations.length
      ) {
        return;
      }

      isLoadingBatch = true;
      requestAnimationFrame(() => {
        visibleCount = Math.min(
          visibleCount + BATCH_SIZE,
          filteredConversations.length
        );
        isLoadingBatch = false;
        renderList({ preserveScroll: true });
      });
    };

    const searchHandler = () => {
      stateRef.sidebarSearchQuery = searchInput.value.trim();
      visibleCount = BATCH_SIZE;
      renderList();
    };

    if (searchInput) {
      searchInput.addEventListener("input", searchHandler);
    }

    if (typeof IntersectionObserver !== "function") {
      scrollHandler = () => {
        const distanceToBottom =
          sidebarContentEl.scrollHeight -
          sidebarContentEl.scrollTop -
          sidebarContentEl.clientHeight;
        if (distanceToBottom <= 48) {
          loadNextBatch();
        }
      };
      sidebarContentEl.addEventListener("scroll", scrollHandler, {
        passive: true,
      });
    }

    stateRef.sidebarListController = {
      removeConversation(conversationId, focusConversationId = "") {
        stateRef.sidebarConversations = stateRef.sidebarConversations.filter(
          (conversation) => String(conversation?.id || "") !== conversationId
        );
        const filteredLength = getFilteredConversations().length;
        visibleCount = Math.max(
          BATCH_SIZE,
          Math.min(visibleCount, filteredLength)
        );
        renderList({
          preserveScroll: true,
          focusConversationId,
        });
      },
    };

    stateRef.sidebarListCleanup = () => {
      loadObserver?.disconnect();
      if (searchInput) {
        searchInput.removeEventListener("input", searchHandler);
      }
      if (scrollHandler) {
        sidebarContentEl.removeEventListener("scroll", scrollHandler);
      }
    };

    renderList();
  }

  window.aipkit_sidebar_renderConversationList =
    aipkit_sidebar_renderConversationList;


    function showSidebarDeleteError(sidebarContentEl, message) {
        if (!sidebarContentEl || !message) {
            return;
        }

        const existingNotice = sidebarContentEl.querySelector('.aipkit_sidebar_inline_state');
        if (existingNotice) {
            existingNotice.remove();
        }

        let noticeEl = null;
        if (typeof window.aipkit_chatUI_createStateElement === 'function') {
            noticeEl = window.aipkit_chatUI_createStateElement({
                message,
                type: 'error',
                scope: 'sidebar'
            });
        } else {
            noticeEl = document.createElement('div');
            noticeEl.className = 'aipkit_ui_state aipkit_ui_state--error aipkit_ui_state--sidebar';
            noticeEl.textContent = message;
        }

        noticeEl.classList.add('aipkit_sidebar_inline_state');
        noticeEl.setAttribute('role', 'status');
        noticeEl.setAttribute('aria-live', 'polite');
        sidebarContentEl.prepend(noticeEl);

        setTimeout(() => {
            if (!noticeEl.isConnected) {
                return;
            }
            noticeEl.classList.add('aipkit_sidebar_inline_state--hiding');
            setTimeout(() => {
                if (noticeEl.isConnected) {
                    noticeEl.remove();
                }
            }, 220);
        }, 5000);
    }

    /**
     * Handles deleting a specific conversation.
     * @param {string} conversationUUID - The UUID of the conversation to delete.
     * @param {HTMLElement} itemElement - The DOM element representing the conversation in the list.
     * @param {HTMLElement} sidebarContentEl - The sidebar content element.
     * @param {object} config - Chatbot configuration.
     * @param {object} stateRef - Reference to the state object { isDeletingId }.
     * @param {object} actions - The main actions object from chatbot/instance.js.
     */
    function aipkit_sidebar_handleDeleteConversation(conversationUUID, itemElement, sidebarContentEl, config, stateRef, actions) {
        const botId = config.botId || 'unknown';

        if (!itemElement || stateRef.isDeletingId) return; // Already deleting or invalid item
        stateRef.isDeletingId = conversationUUID;

        const deleteBtn = itemElement.querySelector('.aipkit_conversation_item_delete_btn');
        const titleBtn = itemElement.querySelector('.aipkit_conversation_item_title_btn');

        itemElement.classList.add('aipkit_conversation_item--deleting');
        itemElement.setAttribute('aria-busy', 'true');
        if (titleBtn) titleBtn.disabled = true;
        if (deleteBtn) {
            deleteBtn.disabled = true;
            deleteBtn.setAttribute('aria-disabled', 'true');
        }

        const data = {
            bot_id: botId, // Pass bot_id for backend context
            conversation_uuid: conversationUUID
        };

        const fallbackFocusConversationId = (() => {
            const nextItem = itemElement.nextElementSibling;
            if (nextItem) {
                const nextConversationId = nextItem.getAttribute('data-conversation-id');
                if (nextConversationId) {
                    return nextConversationId;
                }
            }
            const prevItem = itemElement.previousElementSibling;
            if (prevItem) {
                const prevConversationId = prevItem.getAttribute('data-conversation-id');
                if (prevConversationId) {
                    return prevConversationId;
                }
            }
            return '';
        })();

        window.aipkit_frontendApiRequest('aipkit_delete_single_conversation', data, config)
            .then(response => {
                itemElement.classList.add('aipkit_conversation_item--removing');

                let hasRemoved = false;
                const removeItem = () => {
                    if (hasRemoved) {
                        return;
                    }
                    hasRemoved = true;

                    itemElement.removeEventListener('transitionend', handleTransitionEnd);
                    if (typeof stateRef.sidebarListController?.removeConversation === 'function') {
                        stateRef.sidebarListController.removeConversation(
                            conversationUUID,
                            fallbackFocusConversationId
                        );
                        return;
                    }

                    if (itemElement.parentNode) itemElement.remove();
                    const hasConversationItems = !!(sidebarContentEl && sidebarContentEl.querySelector('.aipkit_conversation_item'));
                    if (sidebarContentEl && !hasConversationItems) {
                        if (typeof window.aipkit_chatUI_renderUIState === 'function') {
                            window.aipkit_chatUI_renderUIState(sidebarContentEl, {
                                message: config.text?.historySidebarEmpty || config.text?.historyEmpty || 'No conversations yet — start one to see it here.',
                                type: 'muted',
                                scope: 'sidebar'
                            });
                        } else {
                            sidebarContentEl.textContent = config.text?.historySidebarEmpty || config.text?.historyEmpty || 'No conversations yet — start one to see it here.';
                        }
                    }
                };

                const handleTransitionEnd = (event) => {
                    if (event.target === itemElement && event.propertyName === 'opacity') {
                        removeItem();
                    }
                };
                itemElement.addEventListener('transitionend', handleTransitionEnd);
                setTimeout(removeItem, 350); // Fallback if transitionend does not fire

                // If the deleted conversation was the active one, clear the main chat
                if (window.aipkit_current_conversation_uuid === conversationUUID) {
                    if (typeof actions.clearChatAction === 'function') {
                        actions.clearChatAction();
                    } else {
                        console.error(`AIPKit Sidebar (${botId}): clearChatAction is not a function in actions object.`);
                    }
                }
            })
            .catch(error => {
                showSidebarDeleteError(
                    sidebarContentEl,
                    `${config.text?.historyDeleteErrorPrefix || 'Error deleting conversation'}: ${error.message || 'Unknown error'}`
                );
                itemElement.classList.remove('aipkit_conversation_item--deleting');
                itemElement.classList.remove('aipkit_conversation_item--removing');
                itemElement.removeAttribute('aria-busy');
                if (titleBtn) titleBtn.disabled = false;
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.removeAttribute('aria-disabled');
                    if (typeof deleteBtn.focus === 'function') {
                        deleteBtn.focus();
                    }
                }
            })
            .finally(() => {
                stateRef.isDeletingId = null;
            });
    }

    window.aipkit_sidebar_handleDeleteConversation = aipkit_sidebar_handleDeleteConversation;


  /**
   * Loads a selected conversation into the main chat view.
   * @param {string} conversationUUID - The UUID of the conversation to load.
   * @param {HTMLElement} sidebarContentEl - The sidebar content element.
   * @param {HTMLElement} messagesEl - The main messages display area.
   * @param {object} config - Chatbot configuration.
   * @param {object} stateRef - Reference to the state object { isSidebarOpen, isDeletingId }.
   * @param {object} actions - Core action functions { setButtonStateAction, focusInputAction }.
   * @param {function} toggleSidebarFunc - Function to toggle the sidebar.
   */
  function aipkit_sidebar_loadConversation(
    conversationUUID,
    sidebarContentEl,
    messagesEl,
    config,
    stateRef,
    actions,
    toggleSidebarFunc
  ) {
    const botId = config.botId || "unknown";
    const mainContainer = messagesEl
      ? messagesEl.closest(".aipkit_chat_container")
      : null;
    const startersContainer = mainContainer
      ? mainContainer.querySelector(".aipkit_conversation_starters")
      : null;
    const renderMessageState = (message, type = "muted", showSpinner = false) => {
      if (typeof window.aipkit_chatUI_renderUIState === "function") {
        window.aipkit_chatUI_renderUIState(messagesEl, {
          message,
          type,
          showSpinner,
          scope: "messages",
        });
      } else if (messagesEl) {
        messagesEl.textContent = message;
      }
    };

    if (!conversationUUID || stateRef.isDeletingId === conversationUUID) return;

    window.aipkit_chatUI_cancelSpeechInput?.(mainContainer);
    actions.stopStreamAction?.(false);

    if (
      mainContainer &&
      typeof window.aipkit_chatUI_setFormGateState === "function"
    ) {
      window.aipkit_chatUI_setFormGateState(mainContainer, false);
    }

    if (startersContainer) {
      startersContainer.classList.add("aipkit_hidden");
      startersContainer.classList.remove("aipkit_starters_ready");
    }

    sessionStorage.setItem(
      "aipkit_current_conversation_uuid",
      conversationUUID
    );
    window.aipkit_current_conversation_uuid = conversationUUID;
    window.aipkit_is_fresh_session = false;
    window.aipkit_current_openai_response_id = null; // Reset for loaded conversation
    sessionStorage.removeItem("aipkit_current_openai_response_id"); // Reset for loaded conversation
    window.aipkit_current_google_interaction_id = null;
    sessionStorage.removeItem("aipkit_current_google_interaction_id");

    window.aipkit_chatUI_restoreFileContextForConversation(conversationUUID, {
      invalidLogPrefix: `AIPKit Sidebar (${botId})`,
      errorMessage: "AIPKit Sidebar: Error handling localStorage for file context:",
    });

    const allItems = sidebarContentEl
      ? sidebarContentEl.querySelectorAll(".aipkit_conversation_item")
      : [];
    allItems.forEach((item) => {
      item.classList.remove("aipkit_active");
      if (item.getAttribute("data-conversation-id") === conversationUUID) {
        item.classList.add("aipkit_active");
      }
    });

    if (typeof window.aipkit_chatUI_clearMessages === "function") {
      window.aipkit_chatUI_clearMessages(messagesEl);
      renderMessageState("", "loading", true);
    } else {
      renderMessageState("", "loading", true);
    }

    window
      .aipkit_frontendApiRequest("aipkit_get_conversation_history", {}, config)
      .then((data) => {
        messagesEl.innerHTML = "";
        const history = data.history || [];

        if (history.length === 0) {
          if (
            typeof window.aipkit_chatUI_appendMessage === "function" &&
            config.text.initialGreeting
          ) {
            const greetingText = (config.text.initialGreeting || "").trim();
            const subgreetingText = (config.text.initialSubgreeting || "").trim();
            let initialGreetingText = greetingText;
            if (subgreetingText) {
              initialGreetingText = greetingText
                ? `${greetingText}\n\n${subgreetingText}`
                : subgreetingText;
            }
            if (typeof window.aipkit_generateClientMessageId === "function") {
              window.aipkit_chatUI_appendMessage(
                messagesEl,
                initialGreetingText,
                "bot",
                config,
                false,
                true,
                window.aipkit_generateClientMessageId(botId)
              );
            }
          } else {
            renderMessageState(config.text.historyEmpty || "No past messages.", "muted");
          }
        } else {
          let lastBotOpenAIResponseId = null;
          let lastBotGoogleInteractionId = null;
          history.forEach((msg) => {
            const role =
              msg.role === "assistant" || msg.role === "bot" ? "bot" : "user";
            let contentToDisplay = msg.content || "";

            if (
              msg.response_data &&
              msg.response_data.type === "image" &&
              msg.response_data.images &&
              msg.response_data.images.length > 0
            ) {
              contentToDisplay = {
                type: "image",
                images: msg.response_data.images,
                prompt: msg.request_payload?.prompt || msg.content,
              };
            }
            if (
              msg.response_data &&
              msg.response_data.type === "user_image_upload" &&
              msg.response_data.images &&
              msg.response_data.images.length > 0
            ) {
              contentToDisplay = {
                text: msg.content || "",
                user_image: msg.response_data.images[0],
              };
            }

            const historicalCitations = Array.isArray(msg.citations)
              ? msg.citations.filter(
                  (citation) =>
                    !(
                      msg.provider === "Google" &&
                      citation?.type === "url_citation"
                    )
                )
              : msg.citations || null;

            window.aipkit_chatUI_appendMessage(
              messagesEl,
              contentToDisplay,
              role,
              config,
              msg.role === "bot" && (msg.content || "").startsWith("Error:"),
              false,
              msg.message_id || null,
              false,
              null,
              historicalCitations,
              msg.feedback || null
            );

            if (
              (role === "bot" || role === "assistant") &&
              msg.openai_response_id
            ) {
              lastBotOpenAIResponseId = msg.openai_response_id;
            }
            if ((role === "bot" || role === "assistant") && msg.google_interaction_id) {
              lastBotGoogleInteractionId = msg.google_interaction_id;
            }
          });
          if (lastBotOpenAIResponseId) {
            window.aipkit_current_openai_response_id = lastBotOpenAIResponseId;
            sessionStorage.setItem(
              "aipkit_current_openai_response_id",
              lastBotOpenAIResponseId
            );
          }
          if (lastBotGoogleInteractionId) {
            window.aipkit_current_google_interaction_id =
              lastBotGoogleInteractionId;
            sessionStorage.setItem(
              "aipkit_current_google_interaction_id",
              lastBotGoogleInteractionId
            );
          }

          if (typeof window.aipkit_chatUI_scrollToBottom === "function") {
            window.aipkit_chatUI_scrollToBottom(messagesEl, true);
          }
        }

        if (
          data.pending_form &&
          typeof data.pending_form === "object" &&
          typeof window.aipkit_chatUI_renderChatForm === "function"
        ) {
          const formElement = window.aipkit_chatUI_renderChatForm(
            messagesEl,
            data.pending_form,
            config
          );
          if (
            mainContainer &&
            typeof window.aipkit_chatUI_setFormGateState === "function"
          ) {
            window.aipkit_chatUI_setFormGateState(
              mainContainer,
              true,
              formElement
            );
          }
        }

        if (typeof actions.setButtonStateAction === "function") {
          actions.setButtonStateAction("send", false);
        }
        if (typeof actions.focusInputAction === "function") {
          actions.focusInputAction();
        }

        if (mainContainer) {
          mainContainer.dispatchEvent(
            new CustomEvent("aipkit:historyLoaded", {
              detail: { conversationUUID: conversationUUID },
            })
          );
        }
      })
      .catch((error) => {
        renderMessageState(
          `${config.text?.historyLoadErrorPrefix || "Error loading history"}: ${
            error.message || "Unknown error"
          }`,
          "error"
        );
        if (typeof actions.setButtonStateAction === "function") {
          actions.setButtonStateAction("send", false);
        }
      });
  }

  window.aipkit_sidebar_loadConversation = aipkit_sidebar_loadConversation;


     // Keep behavior aligned with the sidebar media query.

    /**
     * Initializes the conversation sidebar functionality for a chat instance.
     * @param {HTMLElement} container - The main chat container element (.aipkit_chat_container).
     * @param {object} elements - Object containing references to UI elements.
     * @param {object} config - The chatbot configuration object.
     * @param {object} actions - Core action functions { clearChatAction, setButtonStateAction, focusInputAction }.
     */
    function aipkit_initConversationSidebar(container, elements, config, actions) {
        const { sidebarToggleBtn, sidebarEl, newChatBtn } = elements;
        const sidebarToggleButtons = Array.from(container.querySelectorAll('.aipkit_sidebar_toggle_btn'));
        const messagesEl = container.querySelector('.aipkit_chat_messages');
        const mainContentEl = container.querySelector('.aipkit_chat_main');
        const sidebarContentEl = sidebarEl ? sidebarEl.querySelector('.aipkit_sidebar_content') : null;

        if (!container || !sidebarToggleBtn || !sidebarEl || !sidebarContentEl || !messagesEl || !mainContentEl) {
            console.warn("AIPKit Sidebar (Orchestrator): Missing required elements. Sidebar init aborted.", {container, sidebarToggleBtn, sidebarEl, sidebarContentEl, messagesEl, mainContentEl});
            return;
        }

        const botId = config.botId;
        if (!botId) {
            console.error("AIPKit Sidebar (Orchestrator): Bot ID missing, cannot initialize.");
            sidebarToggleButtons.forEach((button) => {
                button.disabled = true;
            });
            if (newChatBtn) newChatBtn.disabled = true;
            if (typeof window.aipkit_chatUI_renderUIState === 'function') {
                window.aipkit_chatUI_renderUIState(sidebarContentEl, {
                    message: config.text?.configurationError || 'Configuration Error',
                    type: 'error',
                    scope: 'sidebar'
                });
            } else {
                sidebarContentEl.textContent = config.text?.configurationError || 'Configuration Error';
            }
            return;
        }

        // --- State specific to this sidebar instance ---
        const stateRef = {
            isSidebarOpen: false,
            isDeletingId: null,
            mobileOverlayHandler: null, // To store the specific handler for removal
            mobileOverlayTargetEl: null
        };
        // --- End State ---

        const clearActiveConversationState = () => {
            if (!sidebarContentEl) {
                return;
            }

            const activeItems = sidebarContentEl.querySelectorAll('.aipkit_conversation_item.aipkit_active');
            activeItems.forEach((item) => item.classList.remove('aipkit_active'));
        };

        const syncActiveConversationState = (conversationUUID = null) => {
            if (!sidebarContentEl) {
                return;
            }

            const targetConversationUUID = conversationUUID || null;
            const allItems = sidebarContentEl.querySelectorAll('.aipkit_conversation_item');
            let hasActiveMatch = false;

            allItems.forEach((item) => {
                const isActive = !!targetConversationUUID && item.getAttribute('data-conversation-id') === targetConversationUUID;
                item.classList.toggle('aipkit_active', isActive);
                if (isActive) {
                    hasActiveMatch = true;
                }
            });

            if (!hasActiveMatch) {
                clearActiveConversationState();
            }
        };

        const SIDEBAR_STORAGE_KEY = window.aipkit_sidebar_getStorageKey(botId);
        const storedState = localStorage.getItem(SIDEBAR_STORAGE_KEY);
        stateRef.isSidebarOpen = (storedState === 'open');

        if (!sidebarEl.id) {
            const safeBotId = String(botId).replace(/[^a-zA-Z0-9_-]/g, '_');
            sidebarEl.id = `aipkit_chat_sidebar_${safeBotId}`;
        }
        sidebarToggleButtons.forEach((button) => {
            button.setAttribute('aria-controls', sidebarEl.id);
            button.setAttribute('aria-expanded', stateRef.isSidebarOpen.toString());
        });
        sidebarEl.setAttribute('aria-hidden', (!stateRef.isSidebarOpen).toString());

        // --- Define click handler for mobile overlay (closes over stateRef and other instance-specifics) ---
        const handleMobileOverlayClickInstance = (event) => {
            if (stateRef.isSidebarOpen && window.innerWidth <= MOBILE_BREAKPOINT) {
                event.preventDefault();
                // Call the toggle function which will internally update state and call applyCurrentState
                toggleSidebarInstance();
            }
        };
        // --- End Mobile Overlay Click Handler ---

        // --- Define instance-specific wrapped functions for helpers ---
        const applyCurrentStateInstance = (isOpening) => window.aipkit_sidebar_applyCurrentState(stateRef.isSidebarOpen, container, sidebarToggleBtn, SIDEBAR_STORAGE_KEY, window.aipkit_sidebar_manageMobileOverlayListener, sidebarEl, stateRef, handleMobileOverlayClickInstance, isOpening, config);
        const fetchConversationListInstance = () => window.aipkit_sidebar_fetchConversationList(sidebarContentEl, config, (convs) => window.aipkit_sidebar_renderConversationList(convs, sidebarContentEl, config, stateRef, loadConversationInstance, handleDeleteConversationInstance, toggleSidebarInstance));
        const loadConversationInstance = (conversationUUID) => window.aipkit_sidebar_loadConversation(conversationUUID, sidebarContentEl, messagesEl, config, stateRef, actions, toggleSidebarInstance);
        const handleDeleteConversationInstance = (conversationUUID, itemElement) => window.aipkit_sidebar_handleDeleteConversation(conversationUUID, itemElement, sidebarContentEl, config, stateRef, actions);
        const toggleSidebarInstance = () => {
            window.aipkit_sidebar_toggleSidebar(
                stateRef,
                applyCurrentStateInstance, // Retain the public helper argument order.
                fetchConversationListInstance
            );
            // After state.isSidebarOpen is updated by toggleSidebar, call applyCurrentState
            applyCurrentStateInstance(stateRef.isSidebarOpen); // Pass the new state of isSidebarOpen
        };
        // --- End Instance-specific Functions ---

        // Initial state application
        applyCurrentStateInstance(false); // false for isOpening initially

        // Attach main toggle listener
        sidebarToggleButtons.forEach((button) => {
            button.addEventListener('click', toggleSidebarInstance);
        });

        // New Chat button listener
        if (newChatBtn && typeof window.aipkit_sidebar_handleNewChat === 'function' && typeof actions.clearChatAction === 'function') {
            newChatBtn.addEventListener('click', () => window.aipkit_sidebar_handleNewChat(actions, sidebarContentEl, stateRef, toggleSidebarInstance));
            newChatBtn.disabled = false;
        } else if (newChatBtn) {
            newChatBtn.disabled = true;
            console.warn(`AIPKit Sidebar (${botId}): New Chat button disabled, clearChatAction or handleNewChat helper missing.`);
        }

        // Mobile resize listener
        window.addEventListener('resize', () => {
            if (stateRef.isSidebarOpen && typeof window.aipkit_sidebar_manageMobileOverlayListener === 'function') {
                window.aipkit_sidebar_manageMobileOverlayListener(stateRef.isSidebarOpen, sidebarEl, sidebarToggleBtn, handleMobileOverlayClickInstance, stateRef, config);
            }
        });

        // Fetch initial conversation list if sidebar is open or if no nonce (for guest message)
        if ((stateRef.isSidebarOpen || !config.nonce)) {
            fetchConversationListInstance();
        }

        // Event listener for when a new message is received/sent to refresh list if new conversation started
        const handleMessageReceived = (event) => {
            if (!event.detail) {
                return;
            }

            if (event.detail.conversationUUID) {
                syncActiveConversationState(event.detail.conversationUUID);
            }

            if (event.detail.newConversation && stateRef.isSidebarOpen) {
                fetchConversationListInstance();
            }
        };

        const handleChatCleared = () => {
            clearActiveConversationState();
        };

        container.addEventListener('aipkit:messageReceived', handleMessageReceived);
        container.addEventListener('aipkit:chatCleared', handleChatCleared);
    }

    window.aipkit_initConversationSidebar = aipkit_initConversationSidebar;
})();
