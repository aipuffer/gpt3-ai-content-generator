/**
 * Shared Prompt Library API helper for admin modules.
 */
(function () {
  "use strict";

  const ACTIONS = {
    list: "aipkit_list_prompt_library",
    create: "aipkit_create_prompt_library_item",
    update: "aipkit_update_prompt_library_item",
    remove: "aipkit_delete_prompt_library_item",
  };

  let cachedLibrary = null;
  let listRequestPromise = null;

  const getAjaxUrl = () =>
    window.aipkit_dashboard?.ajaxurl ||
    window.aipkit_post_enhancer?.ajaxurl ||
    window.ajaxurl ||
    "";

  const getNonce = () =>
    window.aipkit_dashboard?.nonce ||
    window.aipkit_post_enhancer?.nonce_prompt_library ||
    "";

  const postRequest = async (action, payload = {}) => {
    const ajaxUrl = getAjaxUrl();
    const nonce = getNonce();
    if (!ajaxUrl || !nonce) {
      throw new Error("Prompt library API is not configured.");
    }

    const params = new URLSearchParams();
    params.set("action", action);
    params.set("_ajax_nonce", nonce);
    Object.entries(payload).forEach(([key, value]) => {
      if (value === undefined || value === null) return;
      params.set(key, String(value));
    });

    const response = await fetch(ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: params.toString(),
      credentials: "same-origin",
    });

    let data;
    try {
      data = await response.json();
    } catch (error) {
      throw new Error("Prompt library API returned an invalid response.");
    }

    if (!response.ok || !data?.success) {
      const message =
        data?.data?.message || "Prompt library request failed.";
      throw new Error(message);
    }

    return data.data || {};
  };

  const mergeTypePayloadIntoCache = (typePayload) => {
    if (!typePayload || !typePayload.library || !typePayload.types) {
      return;
    }
    if (!cachedLibrary || !cachedLibrary.library || !cachedLibrary.types) {
      return;
    }

    const cacheTypes = new Set(cachedLibrary.types);
    typePayload.types.forEach((type) => {
      if (!cacheTypes.has(type)) {
        cachedLibrary.types.push(type);
      }
      cachedLibrary.library[type] = Array.isArray(typePayload.library[type])
        ? typePayload.library[type]
        : [];
      if (typePayload.counts && typePayload.counts[type]) {
        cachedLibrary.counts = cachedLibrary.counts || {};
        cachedLibrary.counts[type] = typePayload.counts[type];
      }
    });
  };

  const dispatchUpdatedEvent = (detail = {}) => {
    window.dispatchEvent(
      new CustomEvent("aipkit_prompt_library_updated", { detail })
    );
  };

  const list = async ({ force = false } = {}) => {
    if (!force && cachedLibrary) {
      return cachedLibrary;
    }
    if (!force && listRequestPromise) {
      return listRequestPromise;
    }

    listRequestPromise = postRequest(ACTIONS.list)
      .then((data) => {
        cachedLibrary = data;
        return data;
      })
      .finally(() => {
        listRequestPromise = null;
      });

    return listRequestPromise;
  };

  const create = async ({ promptType, label, prompt }) => {
    const data = await postRequest(ACTIONS.create, {
      prompt_type: promptType,
      label,
      prompt,
    });
    if (data.type_data) {
      mergeTypePayloadIntoCache(data.type_data);
    } else {
      cachedLibrary = null;
    }
    dispatchUpdatedEvent({
      action: "create",
      promptType,
      promptId: data.item?.id || "",
    });
    return data;
  };

  const update = async ({ promptId, promptType, label, prompt }) => {
    const payload = {
      prompt_id: promptId,
      prompt_type: promptType,
      label,
      prompt,
    };
    const data = await postRequest(ACTIONS.update, payload);
    if (data.type_data) {
      mergeTypePayloadIntoCache(data.type_data);
    } else {
      cachedLibrary = null;
    }
    dispatchUpdatedEvent({
      action: "update",
      promptType,
      promptId,
    });
    return data;
  };

  const remove = async ({ promptId, promptType }) => {
    const data = await postRequest(ACTIONS.remove, {
      prompt_id: promptId,
      prompt_type: promptType,
    });
    if (data.type_data) {
      mergeTypePayloadIntoCache(data.type_data);
    } else {
      cachedLibrary = null;
    }
    dispatchUpdatedEvent({
      action: "delete",
      promptType,
      promptId,
    });
    return data;
  };

  window.aipkit_prompt_library_api = {
    list,
    create,
    update,
    remove,
    clearCache: () => {
      cachedLibrary = null;
    },
    getCache: () => cachedLibrary,
  };
})();

