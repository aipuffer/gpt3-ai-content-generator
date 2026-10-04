(function () {
  "use strict";

  function getHistoryElements(generatorWrapper) {
    const section = generatorWrapper?.querySelector(
      "[data-aipkit-image-history]"
    );
    return {
      section,
      grid: section?.querySelector("[data-aipkit-history-grid]"),
      empty: section?.querySelector("[data-aipkit-history-empty]"),
      loadMoreContainer: section?.querySelector(
        "[data-aipkit-history-load-more]"
      ),
      loadMoreButton: section?.querySelector(
        ".aipkit-image-history-load-more-btn"
      ),
      viewer: section?.querySelector("[data-aipkit-history-viewer]"),
    };
  }

  function notify(generatorWrapper, message, type = "info") {
    const resultsContainer = generatorWrapper?.querySelector(
      "#aipkit_public_image_results"
    );
    if (resultsContainer && window.aipkitImageResultUI?.notify) {
      window.aipkitImageResultUI.notify(resultsContainer, message, type);
      return;
    }
    if (type === "error") {
      console.error(message);
    }
  }

  function getHistoryState(section) {
    if (!section._aipkitHistoryState) {
      section._aipkitHistoryState = {
        loading: false,
        pendingRefresh: false,
        openMenu: null,
        viewerCard: null,
        viewerReturnFocus: null,
      };
    }
    return section._aipkitHistoryState;
  }

  function setBusy(elements, busy) {
    if (!elements.section) {
      return;
    }
    elements.section.setAttribute("aria-busy", busy ? "true" : "false");
    elements.section
      .querySelectorAll("[data-aipkit-history-filter]")
      .forEach((button) => {
        button.disabled = busy;
      });
    if (elements.loadMoreButton) {
      elements.loadMoreButton.disabled = busy;
      const label = elements.loadMoreButton.querySelector(
        ".aipkit-image-history-load-more-label"
      );
      const spinner = elements.loadMoreButton.querySelector(".aipkit_spinner");
      if (label) {
        label.hidden = busy;
      }
      if (spinner) {
        spinner.hidden = !busy;
      }
    }
  }

  function updateFilterButtons(section, filter) {
    section
      .querySelectorAll("[data-aipkit-history-filter]")
      .forEach((button) => {
        const active = button.dataset.aipkitHistoryFilter === filter;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-pressed", active ? "true" : "false");
      });
  }

  function updateEmptyState(elements) {
    const { section, grid, empty } = elements;
    if (!section || !grid || !empty) {
      return;
    }
    const hasItems = grid.children.length > 0;
    grid.hidden = !hasItems;
    empty.hidden = hasItems;
    empty.textContent =
      section.dataset.filter === "favorites"
        ? section.dataset.emptyFavorites ||
          "You have not favorited any media yet."
        : section.dataset.emptyAll ||
          "Your generated media will appear here.";
  }

  function resetDeleteConfirmation(button) {
    if (!button || button.dataset.confirming !== "true") {
      return;
    }
    const label = button.querySelector("span");
    const originalLabel = button.dataset.label || "Delete";
    button.dataset.confirming = "false";
    button.classList.remove("is-confirming");
    button.setAttribute("aria-label", originalLabel);
    button.setAttribute("title", originalLabel);
    if (label) {
      label.textContent = originalLabel;
    }
  }

  function closeOpenMenu(section, restoreFocus = false) {
    const state = getHistoryState(section);
    const menu = state.openMenu;
    if (!menu) {
      return;
    }
    menu.querySelectorAll('[data-aipkit-history-action="delete"]').forEach(
      resetDeleteConfirmation
    );
    menu.hidden = true;
    const card = menu.closest(".aipkit-image-history-item");
    const trigger = card?.querySelector("[data-aipkit-history-more]");
    card?.classList.remove("is-menu-open");
    trigger?.setAttribute("aria-expanded", "false");
    state.openMenu = null;
    if (restoreFocus && trigger?.isConnected) {
      trigger.focus();
    }
  }

  function openMenu(section, trigger) {
    const menuId = trigger.getAttribute("aria-controls");
    const menu = menuId ? document.getElementById(menuId) : null;
    if (!menu || !section.contains(menu)) {
      return;
    }
    const state = getHistoryState(section);
    if (state.openMenu === menu) {
      closeOpenMenu(section, true);
      return;
    }
    closeOpenMenu(section);
    state.openMenu = menu;
    menu.hidden = false;
    menu.closest(".aipkit-image-history-item")?.classList.add("is-menu-open");
    trigger.setAttribute("aria-expanded", "true");
    menu.querySelector('[role="menuitem"]')?.focus();
  }

  function updateHistoryUi(elements, data, filter, append) {
    const { section, grid, loadMoreContainer, loadMoreButton } = elements;
    if (!section || !grid || !loadMoreContainer || !loadMoreButton) {
      return;
    }
    closeOpenMenu(section);
    if (append) {
      if (data.html) {
        grid.insertAdjacentHTML("beforeend", data.html);
      }
    } else {
      grid.innerHTML = data.html || "";
    }

    section.dataset.filter = filter;
    updateEmptyState(elements);
    const page = Number(data.page || 1);
    const maxPages = Math.max(1, Number(data.max_pages || 1));
    loadMoreButton.dataset.currentPage = String(page);
    loadMoreButton.dataset.maxPages = String(maxPages);
    loadMoreContainer.hidden = !data.has_more;
    updateFilterButtons(section, filter);
  }

  function requestHistoryPage(
    generatorWrapper,
    { page = 1, filter = "all", append = false } = {}
  ) {
    const elements = getHistoryElements(generatorWrapper);
    if (
      !elements.section ||
      !elements.grid ||
      !elements.empty ||
      !elements.loadMoreContainer ||
      !elements.loadMoreButton
    ) {
      return Promise.resolve(false);
    }

    const state = getHistoryState(elements.section);
    if (state.loading) {
      if (!append && page === 1) {
        state.pendingRefresh = true;
      }
      return Promise.resolve(false);
    }

    const config = window.aipkit_image_generator_config_public || {};
    const ajaxUrl = config.ajaxUrl || window.aipkit_dashboard?.ajaxurl;
    const nonce = generatorWrapper.querySelector(
      "#aipkit_image_generator_public_nonce"
    )?.value;
    const normalizedFilter = filter === "favorites" ? "favorites" : "all";
    if (!ajaxUrl || !nonce) {
      notify(
        generatorWrapper,
        config.text?.historyLoadFailed || "Could not load image history.",
        "error"
      );
      return Promise.resolve(false);
    }

    const requestData = new FormData();
    requestData.append("action", "aipkit_load_more_image_history");
    requestData.append("_ajax_nonce", nonce);
    requestData.append("page", String(Math.max(1, Number(page || 1))));
    requestData.append("filter", normalizedFilter);
    requestData.append(
      "shortcode_mode",
      String(generatorWrapper.dataset.imageMode || "both").trim().toLowerCase()
    );

    state.loading = true;
    setBusy(elements, true);
    return fetch(ajaxUrl, {
      method: "POST",
      body: requestData,
      credentials: "same-origin",
    })
      .then((response) => response.json())
      .then((json) => {
        if (!json.success) {
          throw new Error(
            json.data?.message ||
              config.text?.historyLoadFailed ||
              "Could not load image history."
          );
        }
        updateHistoryUi(elements, json.data || {}, normalizedFilter, append);
        return true;
      })
      .catch((error) => {
        notify(
          generatorWrapper,
          error.message ||
            config.text?.historyLoadFailed ||
            "Could not load image history.",
          "error"
        );
        return false;
      })
      .finally(() => {
        state.loading = false;
        setBusy(elements, false);
        if (state.pendingRefresh) {
          state.pendingRefresh = false;
          requestHistoryPage(generatorWrapper, {
            page: 1,
            filter: elements.section.dataset.filter || normalizedFilter,
          });
        }
      });
  }

  function refreshImageHistory(generatorWrapper) {
    const section = generatorWrapper?.querySelector(
      "[data-aipkit-image-history]"
    );
    if (!section) {
      return Promise.resolve(false);
    }
    return requestHistoryPage(generatorWrapper, {
      page: 1,
      filter: section.dataset.filter || "all",
    });
  }

  function setFavoriteButtonState(button, favorite, pulse = false) {
    const config = window.aipkit_image_generator_config_public || {};
    const label = favorite
      ? config.text?.removeFavorite || "Remove from favorites"
      : config.text?.addFavorite || "Add to favorites";
    button.classList.toggle("is-favorite", favorite);
    button.setAttribute("aria-pressed", favorite ? "true" : "false");
    button.setAttribute("aria-label", label);
    button.setAttribute("title", label);
    if (pulse) {
      button.classList.remove("is-pulsing");
      window.requestAnimationFrame(() => button.classList.add("is-pulsing"));
      window.setTimeout(() => button.classList.remove("is-pulsing"), 180);
    }
  }

  function syncFavoriteState(section, attachmentId, favorite, pulse = false) {
    section
      .querySelectorAll(
        `.aipkit-image-history-favorite-btn[data-attachment-id="${attachmentId}"]`
      )
      .forEach((button) => setFavoriteButtonState(button, favorite, pulse));
    const viewer = section.querySelector("[data-aipkit-history-viewer]");
    const viewerFavorite = viewer?.querySelector(
      '[data-aipkit-history-viewer-action="favorite"]'
    );
    if (
      viewerFavorite &&
      Number(viewer?.dataset.attachmentId || 0) === Number(attachmentId)
    ) {
      setFavoriteButtonState(viewerFavorite, favorite, pulse);
    }
  }

  function setFavoriteBusy(section, attachmentId, busy) {
    const buttons = Array.from(
      section.querySelectorAll(
        `.aipkit-image-history-favorite-btn[data-attachment-id="${attachmentId}"]`
      )
    );
    const viewer = section.querySelector("[data-aipkit-history-viewer]");
    const viewerFavorite = viewer?.querySelector(
      '[data-aipkit-history-viewer-action="favorite"]'
    );
    if (
      viewerFavorite &&
      Number(viewer?.dataset.attachmentId || 0) === Number(attachmentId)
    ) {
      buttons.push(viewerFavorite);
    }
    buttons.forEach((favoriteButton) => {
      favoriteButton.disabled = busy;
      if (busy) {
        favoriteButton.setAttribute("aria-busy", "true");
      } else {
        favoriteButton.removeAttribute("aria-busy");
      }
    });
  }

  function toggleHistoryFavorite(generatorWrapper, button) {
    const attachmentId = Number(button.dataset.attachmentId || 0);
    if (
      !attachmentId ||
      button.disabled ||
      typeof window.aipkit_toggleGeneratedMediaFavorite !== "function"
    ) {
      return;
    }

    const section = generatorWrapper.querySelector(
      "[data-aipkit-image-history]"
    );
    if (!section) {
      return;
    }
    const config = window.aipkit_image_generator_config_public || {};
    const previousValue = button.getAttribute("aria-pressed") === "true";
    const nextValue = !previousValue;
    syncFavoriteState(section, attachmentId, nextValue, true);
    setFavoriteBusy(section, attachmentId, true);

    window
      .aipkit_toggleGeneratedMediaFavorite(
        generatorWrapper,
        attachmentId,
        nextValue
      )
      .then((favoriteState) => {
        syncFavoriteState(
          section,
          favoriteState.attachmentId,
          favoriteState.favorite
        );
        notify(
          generatorWrapper,
          favoriteState.favorite
            ? config.text?.favoriteAdded || "Added to favorites."
            : config.text?.favoriteRemoved || "Removed from favorites."
        );
        generatorWrapper.dispatchEvent(
          new CustomEvent("aipkit:image-favorite-changed", {
            detail: favoriteState,
          })
        );
      })
      .catch(() => {
        syncFavoriteState(section, attachmentId, previousValue, true);
        notify(
          generatorWrapper,
          config.text?.favoriteFailed || "Could not update favorite.",
          "error"
        );
      })
      .finally(() => {
        setFavoriteBusy(section, attachmentId, false);
      });
  }

  function downloadMedia(url, fileName) {
    return fetch(url, { credentials: "same-origin" })
      .then((response) => {
        if (!response.ok) {
          throw new Error("download_failed");
        }
        return response.blob();
      })
      .then((blob) => {
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = fileName || "generated-image.png";
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      });
  }

  function prepareDeleteConfirmation(button) {
    if (button.dataset.confirming === "true") {
      return true;
    }
    const confirmLabel = button.dataset.confirmLabel || "Click again to delete";
    const label = button.querySelector("span");
    button.dataset.confirming = "true";
    button.classList.add("is-confirming");
    button.setAttribute("aria-label", confirmLabel);
    button.setAttribute("title", confirmLabel);
    if (label) {
      label.textContent = confirmLabel;
    }
    return false;
  }

  function closeViewer(section) {
    const viewer = section.querySelector("[data-aipkit-history-viewer]");
    if (viewer?.open) {
      viewer.close();
    }
  }

  function deleteHistoryMedia(generatorWrapper, card, button) {
    const attachmentId = Number(card?.dataset.attachmentId || 0);
    const config = window.aipkit_image_generator_config_public || {};
    const ajaxUrl = config.ajaxUrl || window.aipkit_dashboard?.ajaxurl;
    const nonce = generatorWrapper.querySelector(
      "#aipkit_image_generator_public_nonce"
    )?.value;
    if (!attachmentId || !ajaxUrl || !nonce) {
      notify(
        generatorWrapper,
        config.text?.deleteFailed || "Could not delete this result.",
        "error"
      );
      return;
    }
    button.disabled = true;
    const request = new FormData();
    request.append("action", "aipkit_delete_generated_image");
    request.append("_ajax_nonce", nonce);
    request.append("attachment_id", String(attachmentId));
    fetch(ajaxUrl, {
      method: "POST",
      body: request,
      credentials: "same-origin",
    })
      .then((response) => response.json())
      .then((json) => {
        if (!json.success) {
          throw new Error(
            json.data?.message ||
              config.text?.deleteFailed ||
              "Could not delete this result."
          );
        }
        const elements = getHistoryElements(generatorWrapper);
        closeOpenMenu(elements.section);
        closeViewer(elements.section);
        elements.grid
          ?.querySelectorAll(
            `.aipkit-image-history-item[data-attachment-id="${attachmentId}"]`
          )
          .forEach((item) => item.remove());
        updateEmptyState(elements);
        notify(generatorWrapper, json.data?.message || "Deleted from history.");
        requestHistoryPage(generatorWrapper, {
          page: 1,
          filter: elements.section.dataset.filter || "all",
        });
      })
      .catch((error) => {
        button.disabled = false;
        resetDeleteConfirmation(button);
        notify(
          generatorWrapper,
          error.message ||
            config.text?.deleteFailed ||
            "Could not delete this result.",
          "error"
        );
      });
  }

  function getCardForElement(element) {
    return element?.closest(".aipkit-image-history-item") || null;
  }

  function openViewer(generatorWrapper, card, returnFocus) {
    const section = generatorWrapper.querySelector(
      "[data-aipkit-image-history]"
    );
    const viewer = section?.querySelector("[data-aipkit-history-viewer]");
    const mediaUrl = card?.dataset.mediaUrl || "";
    if (!viewer || !mediaUrl) {
      return;
    }
    if (typeof viewer.showModal !== "function") {
      window.open(mediaUrl, "_blank", "noopener,noreferrer");
      return;
    }
    const state = getHistoryState(section);
    const image = viewer.querySelector("[data-aipkit-history-viewer-media]");
    const caption = viewer.querySelector("[data-aipkit-history-viewer-caption]");
    const favorite = viewer.querySelector(
      '[data-aipkit-history-viewer-action="favorite"]'
    );
    const edit = viewer.querySelector(
      '[data-aipkit-history-viewer-action="edit"]'
    );
    const deleteButton = viewer.querySelector(
      '[data-aipkit-history-viewer-action="delete"]'
    );
    const prompt = card.dataset.prompt || "";
    const modelLabel = card.dataset.modelLabel || "";
    const thumbnailAlt = card.querySelector("img")?.alt || "Generated image";
    state.viewerCard = card;
    state.viewerReturnFocus = returnFocus;
    viewer.dataset.attachmentId = card.dataset.attachmentId || "";
    if (image) {
      image.src = mediaUrl;
      image.alt = thumbnailAlt;
    }
    if (caption) {
      caption.textContent = [prompt, modelLabel].filter(Boolean).join(" · ");
    }
    if (favorite) {
      favorite.dataset.attachmentId = card.dataset.attachmentId || "";
      setFavoriteButtonState(
        favorite,
        card.querySelector(".aipkit-image-history-favorite-btn")?.getAttribute(
          "aria-pressed"
        ) === "true"
      );
    }
    if (edit) {
      edit.hidden = card.dataset.editAllowed !== "1";
    }
    if (deleteButton) {
      deleteButton.disabled = false;
    }
    resetDeleteConfirmation(deleteButton);
    viewer.showModal();
    viewer.querySelector("[data-aipkit-history-viewer-close]")?.focus();
  }

  function handleMenuAction(generatorWrapper, button) {
    const section = generatorWrapper.querySelector(
      "[data-aipkit-image-history]"
    );
    const card = getCardForElement(button);
    const action = button.dataset.aipkitHistoryAction;
    if (!section || !card || !action) {
      return;
    }
    if (action === "download") {
      button.disabled = true;
      downloadMedia(card.dataset.mediaUrl || "", card.dataset.mediaName || "")
        .catch(() => {
          window.open(
            card.dataset.mediaUrl || "",
            "_blank",
            "noopener,noreferrer"
          );
        })
        .finally(() => {
          button.disabled = false;
          closeOpenMenu(section, true);
        });
      return;
    }
    if (action === "edit") {
      closeOpenMenu(section);
      window.aipkit_useImageAsEditSource?.(
        generatorWrapper,
        card.dataset.mediaUrl,
        card.dataset.mediaName,
        button
      );
      return;
    }
    if (action === "delete" && prepareDeleteConfirmation(button)) {
      deleteHistoryMedia(generatorWrapper, card, button);
    }
  }

  function handleViewerAction(generatorWrapper, button) {
    const section = generatorWrapper.querySelector(
      "[data-aipkit-image-history]"
    );
    const state = section ? getHistoryState(section) : null;
    const card = state?.viewerCard;
    const action = button.dataset.aipkitHistoryViewerAction;
    if (!section || !card || !action) {
      return;
    }
    if (action === "download") {
      button.disabled = true;
      downloadMedia(card.dataset.mediaUrl || "", card.dataset.mediaName || "")
        .catch(() => {
          window.open(
            card.dataset.mediaUrl || "",
            "_blank",
            "noopener,noreferrer"
          );
        })
        .finally(() => {
          button.disabled = false;
        });
      return;
    }
    if (action === "favorite") {
      toggleHistoryFavorite(generatorWrapper, button);
      return;
    }
    if (action === "edit") {
      closeViewer(section);
      window.aipkit_useImageAsEditSource?.(
        generatorWrapper,
        card.dataset.mediaUrl,
        card.dataset.mediaName,
        button
      );
      return;
    }
    if (action === "delete" && prepareDeleteConfirmation(button)) {
      deleteHistoryMedia(generatorWrapper, card, button);
    }
  }

  function handleMenuKeyboard(section, event) {
    const menu = event.target.closest("[data-aipkit-history-menu]");
    if (!menu || !section.contains(menu)) {
      return;
    }
    if (event.key === "Escape") {
      event.preventDefault();
      closeOpenMenu(section, true);
      return;
    }
    if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
      return;
    }
    const items = Array.from(
      menu.querySelectorAll('[role="menuitem"]:not(:disabled)')
    );
    if (!items.length) {
      return;
    }
    event.preventDefault();
    const currentIndex = items.indexOf(document.activeElement);
    let nextIndex = currentIndex;
    if (event.key === "Home") {
      nextIndex = 0;
    } else if (event.key === "End") {
      nextIndex = items.length - 1;
    } else if (event.key === "ArrowDown") {
      nextIndex = (currentIndex + 1 + items.length) % items.length;
    } else {
      nextIndex = (currentIndex - 1 + items.length) % items.length;
    }
    items[nextIndex].focus();
  }

  function initImageHistory(generatorWrapper) {
    const elements = getHistoryElements(generatorWrapper);
    if (
      !elements.section ||
      elements.section.dataset.aipkitHistoryReady === "true"
    ) {
      return;
    }
    const { section, viewer } = elements;
    getHistoryState(section);

    section.addEventListener("click", (event) => {
      const filterButton = event.target.closest(
        "[data-aipkit-history-filter]"
      );
      if (filterButton && section.contains(filterButton)) {
        event.preventDefault();
        const filter = filterButton.dataset.aipkitHistoryFilter;
        if (filter !== section.dataset.filter) {
          requestHistoryPage(generatorWrapper, { page: 1, filter });
        }
        return;
      }

      const loadMoreButton = event.target.closest(
        ".aipkit-image-history-load-more-btn"
      );
      if (loadMoreButton && section.contains(loadMoreButton)) {
        event.preventDefault();
        requestHistoryPage(generatorWrapper, {
          page: Number(loadMoreButton.dataset.currentPage || 1) + 1,
          filter: section.dataset.filter || "all",
          append: true,
        });
        return;
      }

      const favoriteButton = event.target.closest(
        ".aipkit-image-history-favorite-btn"
      );
      if (favoriteButton && section.contains(favoriteButton)) {
        event.preventDefault();
        event.stopPropagation();
        toggleHistoryFavorite(generatorWrapper, favoriteButton);
        return;
      }

      const moreButton = event.target.closest("[data-aipkit-history-more]");
      if (moreButton && section.contains(moreButton)) {
        event.preventDefault();
        event.stopPropagation();
        openMenu(section, moreButton);
        return;
      }

      const menuAction = event.target.closest("[data-aipkit-history-action]");
      if (menuAction && section.contains(menuAction)) {
        event.preventDefault();
        event.stopPropagation();
        handleMenuAction(generatorWrapper, menuAction);
        return;
      }

      const viewerAction = event.target.closest(
        "[data-aipkit-history-viewer-action]"
      );
      if (viewerAction && section.contains(viewerAction)) {
        event.preventDefault();
        handleViewerAction(generatorWrapper, viewerAction);
        return;
      }

      const closeButton = event.target.closest(
        "[data-aipkit-history-viewer-close]"
      );
      if (closeButton && section.contains(closeButton)) {
        event.preventDefault();
        closeViewer(section);
        return;
      }

      const viewTrigger = event.target.closest("[data-aipkit-history-view]");
      if (viewTrigger && section.contains(viewTrigger)) {
        event.preventDefault();
        openViewer(generatorWrapper, getCardForElement(viewTrigger), viewTrigger);
      }
    });

    section.addEventListener("keydown", (event) => {
      handleMenuKeyboard(section, event);
      if (event.key === "Escape" && getHistoryState(section).openMenu) {
        event.preventDefault();
        closeOpenMenu(section, true);
      }
    });

    document.addEventListener("pointerdown", (event) => {
      const state = getHistoryState(section);
      if (state.openMenu && !state.openMenu.contains(event.target)) {
        const trigger = state.openMenu
          .closest(".aipkit-image-history-item")
          ?.querySelector("[data-aipkit-history-more]");
        if (!trigger?.contains(event.target)) {
          closeOpenMenu(section);
        }
      }
    });

    if (viewer) {
      viewer.addEventListener("click", (event) => {
        if (event.target === viewer) {
          closeViewer(section);
        }
      });
      viewer.addEventListener("close", () => {
        const state = getHistoryState(section);
        const returnFocus = state.viewerReturnFocus;
        const media = viewer.querySelector("[data-aipkit-history-viewer-media]");
        resetDeleteConfirmation(
          viewer.querySelector('[data-aipkit-history-viewer-action="delete"]')
        );
        viewer.removeAttribute("data-attachment-id");
        if (media) {
          media.removeAttribute("src");
          media.alt = "";
        }
        state.viewerCard = null;
        state.viewerReturnFocus = null;
        if (returnFocus?.isConnected) {
          returnFocus.focus();
        }
      });
    }

    generatorWrapper.addEventListener(
      "aipkit:image-favorite-changed",
      (event) => {
        const attachmentId = Number(event.detail?.attachmentId || 0);
        if (!attachmentId) {
          return;
        }
        const favorite = Boolean(event.detail?.favorite);
        const hadMatchingCard = Boolean(
          section.querySelector(
            `.aipkit-image-history-item[data-attachment-id="${attachmentId}"]`
          )
        );
        syncFavoriteState(section, attachmentId, favorite);
        if (section.dataset.filter === "favorites" && !favorite) {
          section
            .querySelectorAll(
              `.aipkit-image-history-item[data-attachment-id="${attachmentId}"]`
            )
            .forEach((card) => card.remove());
          updateEmptyState(getHistoryElements(generatorWrapper));
        } else if (
          section.dataset.filter === "favorites" &&
          favorite &&
          !hadMatchingCard
        ) {
          refreshImageHistory(generatorWrapper);
        }
      }
    );
    section.dataset.aipkitHistoryReady = "true";
  }

  window.aipkit_initImageHistory = initImageHistory;
  window.aipkit_refreshImageHistory = refreshImageHistory;
})();
