import { bindChatbotSettingsAutosave } from "./state.js";
import { getChatbotProviderConfigured } from "./providers.js";

/** Audio provider field visibility. */
function aipkit_toggleSttModelFields(settingsArea) {
  if (!settingsArea) return;
  const sttProviderSelect = settingsArea.querySelector(".aipkit_stt_provider_select");
  if (!sttProviderSelect) return;
  const selectedSttProvider = sttProviderSelect.value;
  const sttModelFields = settingsArea.querySelectorAll(".aipkit_stt_model_field");
  sttModelFields.forEach((field) => {
    const isMatchingProvider = field.getAttribute("data-stt-provider") === selectedSttProvider;
    field.style.display = isMatchingProvider
      ? (field.classList.contains("aipkit_audio_settings_field") ? "flex" : "block")
      : "none";
  });
}

window.aipkit_toggleSttModelFields = aipkit_toggleSttModelFields;

/** Manual Cloud catalog changes update existing controls without changing saved choices. */
export function updateCloudAudioControls(panel) {
  const seed = panel.querySelector('[data-cloud-audio-models]');
  if (!seed) return;
  const __ = window.wp?.i18n?.__ || (text => text);
  let catalog = window.aipkit_cloudAudioModels;
  if (!Array.isArray(catalog)) {
    try { catalog = JSON.parse(seed.dataset.cloudAudioModels || '[]'); } catch { catalog = []; }
  }
  const fill = (select, entries, placeholder) => {
    if (!select) return;
    const saved = select.value;
    select.replaceChildren(new Option(placeholder, ''));
    for (const entry of entries) select.add(new Option(entry.name || entry.id, entry.id));
    if (saved && !entries.some(entry => entry.id === saved)) {
      const option = new Option(saved + ' — ' + __('Unavailable', 'gpt3-ai-content-generator'), saved);
      option.disabled = true;
      select.add(option);
    }
    select.value = saved;
    select.disabled = !entries.length;
  };
  for (const [kind, operation] of [['stt', 'transcribe'], ['tts', 'speech_generate']]) {
    const models = catalog.filter(model => model.operation === operation);
    const provider = panel.querySelector(`[name="${kind}_provider"]`);
    if (provider) {
      const saved = provider.value;
      let option = Array.from(provider.options).find(option => option.value === 'AIPufferCloud');
      if (!option) { option = new Option('', 'AIPufferCloud'); provider.add(option); }
      option.textContent = 'AI Puffer';
      option.disabled = false;
      provider.value = saved;
    }
    const modelSelect = panel.querySelector(`[name="${kind}_cloud_model_id"]`);
    fill(modelSelect, models, __('Select model', 'gpt3-ai-content-generator'));
    if (kind === 'tts') {
      const voices = models.find(model => model.id === modelSelect?.value)?.capabilities?.voices || [];
      fill(panel.querySelector('[name="tts_cloud_voice_id"]'), voices.map(id => ({ id, name: id.charAt(0).toUpperCase() + id.slice(1) })), __('Select voice', 'gpt3-ai-content-generator'));
    }
  }
}

/** Audio catalog dropdowns and per-bot data used by settings hydration. */
const catalogKinds = ["voices", "models"];
const catalogId = (kind, botId = "") => `aipkit_elevenlabs_${kind}_json_${botId}`;

function getHiddenAudioDataHost() {
  const voices = document.querySelector('[id^="aipkit_elevenlabs_voices_json_"]');
  if (voices && voices.parentNode) return voices.parentNode;
  const bots = document.getElementById("aipkit_available_bots_json");
  return bots && bots.parentNode ? bots.parentNode : document.body;
}

export function ensureChatbotAudioData(botId) {
  const id = String(botId || "").trim();
  if (!id) return;
  const host = getHiddenAudioDataHost();
  const existing = catalogKinds.map((kind) => document.getElementById(catalogId(kind, id)));
  const sources = catalogKinds.map((kind) => document.querySelector(`[id^="${catalogId(kind)}"]`));
  const payloads = sources.map((source, index) => source
    ? String(source.dataset[catalogKinds[index]] || "[]") : "[]");
  catalogKinds.forEach((kind, index) => {
    if (existing[index]) return;
    const node = document.createElement("div");
    node.id = catalogId(kind, id);
    node.className = "aipkit_hidden";
    node.dataset[kind] = payloads[index];
    host.appendChild(node);
  });
}

export function removeChatbotAudioData(botId) {
  const id = String(botId || "").trim();
  if (!id) return;
  catalogKinds.forEach((kind) => {
    const node = document.getElementById(catalogId(kind, id));
    if (node) node.remove();
  });
}

function appendSavedOption(select, value) {
  select.appendChild(new Option(value + "", value, false, true));
  select.value = value;
}

function updateElevenLabsSelect(kind, selectElementId, entries, currentSelectedValue) {
  const isVoice = kind === "voices";
  const label = isVoice ? "Voices" : "Models";
  const noun = isVoice ? "voice" : "model";
  const select = document.getElementById(selectElementId);
  if (!select) {
    console.warn(`AIPKit Chat TTS UI (ElevenLabs ${label}): Select element #${selectElementId} not found.`);
    return;
  }
  select.innerHTML = "";
  if (!entries || entries.length === 0) {
    select.appendChild(new Option(`(No ${kind} found - Sync ${kind} to load)`, ""));
    if (currentSelectedValue) appendSavedOption(select, currentSelectedValue);
    return;
  }
  select.appendChild(new Option(isVoice ? "-- Select Voice --" : "-- Select Model --", ""));
  entries.sort((a, b) => (a.name || a.id || "").localeCompare(b.name || b.id || ""));
  let foundOldValueInList = false;
  entries.forEach((entry) => {
    // Voice records pass through unchanged; model records require an ID and allow a missing name.
    const id = isVoice ? entry.id : entry.id || "";
    if (!isVoice && !id) return;
    const option = new Option(isVoice ? entry.name : entry.name || id, id);
    if (id === currentSelectedValue) {
      option.selected = true;
      foundOldValueInList = true;
    }
    select.appendChild(option);
  });
  if (currentSelectedValue && !foundOldValueInList) {
    const existing = Array.from(select.options).find((option) => option.value === currentSelectedValue);
    if (existing) {
      select.value = currentSelectedValue;
    } else {
      appendSavedOption(select, currentSelectedValue);
      console.warn(`AIPKit Chat TTS UI (ElevenLabs ${label}): Saved ${noun} ID "${currentSelectedValue}" not found in synced list for #${selectElementId}, added as manual.`);
    }
  } else if (!currentSelectedValue && select.options.length > 0) {
    select.value = "";
  }
}

window.aipkit_chatTts_updateElevenLabsVoiceSelect = function aipkit_chatTts_updateElevenLabsVoiceSelect(id, voices, saved) {
  updateElevenLabsSelect("voices", id, voices, saved);
};
window.aipkit_chatTts_updateElevenLabsModelsSelect = function aipkit_chatTts_updateElevenLabsModelsSelect(id, models, saved) {
  updateElevenLabsSelect("models", id, models, saved);
};

/** Catalog refreshes belong to one applied bot state, even when its ID is reused. */
function createChatbotAudioCatalogs({ builder, panel, getBotId }) {
  const details = {
    voices: { field: "voice", provider: "ElevenLabs", hook: "aipkit_chatTts_updateElevenLabsVoiceSelect" },
    models: { field: "model", provider: "ElevenLabsModels", hook: "aipkit_chatTts_updateElevenLabsModelsSelect" },
  };
  const selectFor = (kind) => panel.querySelector(`select[name="tts_elevenlabs_${details[kind].field}_id"]`);
  const dataFor = (kind, botId) => document.getElementById(catalogId(kind, botId));
  const status = panel.querySelector(".aipkit_tts_sync_status");
  let generation = 0;
  let pending = null;
  let statusTimer = null;
  const isCurrent = (request) => request.generation === generation &&
    request.botId === getBotId() && builder.isConnected !== false && panel.isConnected !== false;
  const cancelStatusTimer = () => {
    if (statusTimer !== null) clearTimeout(statusTimer);
    statusTimer = null;
  };
  const setStatus = (text, state, title) => {
    if (!status) return;
    status.textContent = text || "";
    status.classList.remove("success", "error", "loading");
    if (state) status.classList.add(state);
    if (title) status.setAttribute("title", title);
    else status.removeAttribute("title");
    status.style.display = text ? "inline-block" : "none";
  };
  const clearStatusSoon = (request, delay) => {
    if (!status) return;
    cancelStatusTimer();
    const timer = setTimeout(() => {
      if (statusTimer !== timer) return;
      statusTimer = null;
      if (isCurrent(request)) setStatus("");
    }, delay);
    statusTimer = timer;
  };
  const reset = () => {
    generation++;
    pending = null;
    cancelStatusTimer();
    if (status?.textContent) setStatus("");
  };
  const initialize = () => {
    const botId = getBotId();
    catalogKinds.forEach((kind) => {
      const select = selectFor(kind);
      const hook = details[kind].hook;
      if (!botId || !select || typeof window[hook] !== "function") return;
      const raw = dataFor(kind, botId)?.dataset?.[kind];
      try {
        window[hook](select.id, raw ? JSON.parse(raw) : [], select.value || "");
      } catch (error) {
        console.warn(`AIPKit Builder: Failed to parse ElevenLabs ${kind} data.`, error);
      }
    });
  };
  const hasData = (kind, botId) => {
    try {
      const raw = dataFor(kind, botId)?.dataset?.[kind];
      const data = raw ? JSON.parse(raw) : [];
      return Array.isArray(data) && data.length > 0;
    } catch (error) {
      return false;
    }
  };
  const refresh = (force = false) => {
    const toggle = panel.querySelector(".aipkit_tts_toggle_switch");
    const enabled = toggle && (toggle.tagName === "SELECT" ? toggle.value === "1" : toggle.checked);
    const provider = panel.querySelector('[name="tts_provider"]')?.value;
    const botId = getBotId();
    if (!enabled || provider !== "ElevenLabs" || !botId ||
        builder.isConnected === false || panel.isConnected === false) return Promise.resolve();
    const selects = catalogKinds.map(selectFor);
    if (!force && catalogKinds.every((kind, index) => hasData(kind, botId) && selects[index]?.options?.length > 1)) {
      return Promise.resolve();
    }
    if (typeof window.aipkit_apiRequest !== "function" ||
        catalogKinds.some((kind) => typeof window[details[kind].hook] !== "function") ||
        !selects.some(Boolean) || (pending && isCurrent(pending))) return Promise.resolve();
    const request = { botId, generation };
    pending = request;
    cancelStatusTimer();
    setStatus("Syncing...", "loading");
    const requests = catalogKinds.map((kind, index) => {
      const { provider, hook } = details[kind];
      return window.aipkit_apiRequest("aipkit_sync_models", { provider }).then((response) => {
        if (!isCurrent(request)) return;
        const entries = response?.models || [];
        const select = selects[index];
        if (select) window[hook](select.id, entries, select.value || "");
        const data = dataFor(kind, botId);
        if (data) data.dataset[kind] = JSON.stringify(entries);
      });
    });
    return Promise.allSettled(requests).then((results) => {
      const error = results.find((result) => result.status === "rejected");
      if (isCurrent(request)) {
        const message = error ? error.reason?.message || "Sync failed." : "Synced";
        setStatus(message, error ? "error" : "success", error ? message : undefined);
        clearStatusSoon(request, error ? 5000 : 2500);
      }
      if (error) throw error.reason;
      return results;
    }).finally(() => {
      if (pending === request) pending = null;
    });
  };
  return { initialize, refresh, reset };
}

/**
 * Audio settings, panel lifecycle, provider catalogs and saves.
 * Shared gated controls preserve stored realtime settings; execution stays in lib.
 */
const audioFeatures = [
  {
    selector: ".aipkit_audio_toggle_voice_input_row .aipkit_voice_input_toggle_switch",
    key: "enable_voice_input",
    values: ["stt_provider", "stt_openai_model_id", "stt_google_model_id", "stt_cloud_model_id"],
    toggles: [],
  },
  {
    selector: ".aipkit_audio_toggle_tts_row .aipkit_tts_toggle_switch",
    key: "tts_enabled",
    values: [
      "tts_auto_play", "tts_provider", "tts_google_voice_id", "tts_google_model_id",
      "tts_openai_voice_id", "tts_openai_model_id", "tts_elevenlabs_voice_id", "tts_elevenlabs_model_id", "tts_cloud_voice_id", "tts_cloud_model_id",
    ],
    toggles: ["tts_auto_play"],
  },
  {
    selector: ".aipkit_audio_toggle_realtime_row .aipkit_enable_realtime_voice_toggle",
    key: "enable_realtime_voice",
    values: [
      "direct_voice_mode", "input_audio_noise_reduction", "realtime_model", "realtime_voice",
      "turn_detection", "input_audio_format", "output_audio_format", "speed",
      "voice_engine", "live_voice", "live_backend_model", "live_max_seconds",
    ],
    toggles: ["direct_voice_mode", "input_audio_noise_reduction"],
  },
];
const audioChangeSelectors = audioFeatures.flatMap(({ selector, values }) => [
  selector.split(" ").pop(),
  ...values.map((name) => ["stt_provider", "tts_provider"].includes(name)
    ? `.aipkit_${name}_select` : `[name="${name}"]`),
]);

// One document delegate serves live panels without retaining retired panel closures.
const audioPanelClosers = new WeakMap();
let audioPanelEventsController = null;
let audioPanelCount = 0;
function registerAudioPanelEvents(panel, close, signal) {
  audioPanelClosers.set(panel, close);
  audioPanelCount++;
  signal.addEventListener("abort", () => {
    audioPanelClosers.delete(panel);
    if (--audioPanelCount === 0) {
      audioPanelEventsController.abort();
      audioPanelEventsController = null;
    }
  }, { once: true });
  if (audioPanelEventsController) return;
  audioPanelEventsController = new AbortController();
  const openPanels = () => document.querySelectorAll(".aipkit_builder_audio_settings_modal.is-open");
  document.addEventListener("click", (event) => {
    openPanels().forEach((panel) => {
      if (panel.classList.contains("aipkit_inline_settings_content") ||
          event.target.closest(".aipkit_audio_settings_config_btn") ||
          event.target.closest('[data-aipkit-inline-settings-target="aipkit_builder_audio_settings_modal"]') ||
          event.target.closest("#aipkit_builder_audio_settings_modal")) return;
      audioPanelClosers.get(panel)?.();
    });
  }, { signal: audioPanelEventsController.signal });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") openPanels().forEach((panel) => audioPanelClosers.get(panel)?.());
  }, { signal: audioPanelEventsController.signal });
}

export function createChatbotAudio({
  builder,
  audioSettingsModal,
  isToggleFieldOn,
  syncInlineSettingsPanelState,
  syncToolsEnabledOptionsFromFields,
  closeOtherAdvancedDetailPanels,
  mountInlineSettingsPanelForTrigger,
  registerAdvancedDetailPanelCloser,
}) {
  let activeAudioSettingsTrigger = null;

  const applyAudioSettingsFeatureFilter = () => {
    if (!audioSettingsModal) {
      return;
    }
    const activeFeature = (audioSettingsModal.dataset.activeAudioFeature || "").trim();
    const featureGroups = audioSettingsModal.querySelectorAll(
      ".aipkit_audio_feature_group"
    );
    if (!featureGroups.length) {
      return;
    }
    featureGroups.forEach((group) => {
      if (!activeFeature) {
        group.style.display = "";
        return;
      }
      group.style.display = group.classList.contains(
        `aipkit_audio_feature_group--${activeFeature}`
      )
        ? ""
        : "none";
    });
  };

  const closeAudioSettingsFlyout = () => {
    audioSettingsModal.classList.remove("is-open");
    audioSettingsModal.setAttribute("aria-hidden", "true");
    syncInlineSettingsPanelState(audioSettingsModal, false);
    delete audioSettingsModal.dataset.activeAudioFeature;
    if (activeAudioSettingsTrigger) {
      activeAudioSettingsTrigger.setAttribute("aria-expanded", "false");
      activeAudioSettingsTrigger = null;
    }
  };

  const updateAudioVisibility = () => {
    if (!audioSettingsModal) {
      return;
    }

    const getToolsToggleState = (selector) =>
      isToggleFieldOn(builder.querySelector(selector));
    const setAudioOptionsButtonState = (
      feature,
      { available = true, enabled = false } = {}
    ) => {
      builder
        .querySelectorAll(
          `.aipkit_audio_settings_config_btn[data-audio-feature="${feature}"]`
        )
        .forEach((button) => {
          button.style.display = available ? "" : "none";
          button.disabled = !available || !enabled;
          button.setAttribute(
            "aria-disabled",
            available && enabled ? "false" : "true"
          );
        });
    };

    const showStt = getToolsToggleState(
      ".aipkit_audio_toggle_voice_input_row .aipkit_voice_input_toggle_switch"
    );
    const showTts = getToolsToggleState(
      ".aipkit_audio_toggle_tts_row .aipkit_tts_toggle_switch"
    );
    const realtimeEnabledFromTools = getToolsToggleState(
      ".aipkit_audio_toggle_realtime_row .aipkit_enable_realtime_voice_toggle"
    );

    const sttConditionalRow = audioSettingsModal.querySelector(
      ".aipkit_stt_provider_conditional_row"
    );
    const sttProviderRow = audioSettingsModal.querySelector(
      ".aipkit_stt_provider_row"
    );
    const sttControlsHidden =
      sttProviderRow &&
      sttProviderRow.dataset.sttControlsHidden === "1";
    if (sttProviderRow) {
      sttProviderRow.style.display = showStt && !sttControlsHidden ? "block" : "none";
    }
    if (sttConditionalRow) {
      sttConditionalRow.style.display =
        showStt && !sttControlsHidden ? "grid" : "none";
    }
    if (showStt && sttControlsHidden && sttProviderRow) {
      const defaultProvider = sttProviderRow.dataset.sttDefaultProvider;
      const defaultModel = sttProviderRow.dataset.sttDefaultModel;
      const sttProviderSelect = audioSettingsModal.querySelector(
        ".aipkit_stt_provider_select"
      );
      const sttModelSelect = audioSettingsModal.querySelector(
        '[name="stt_openai_model_id"]'
      );
      const ensureSelectValue = (select, value) => {
        if (!select || !value) {
          return;
        }
        const exists = Array.from(select.options).some(
          (option) => option.value === value
        );
        if (!exists) {
          const newOption = new Option(value, value, true, true);
          select.add(newOption);
        }
        select.value = value;
      };
      ensureSelectValue(sttProviderSelect, defaultProvider);
      ensureSelectValue(sttModelSelect, defaultModel);
    }
    setAudioOptionsButtonState("stt", {
      available: !sttControlsHidden,
      enabled: showStt,
    });
    if (typeof window.aipkit_toggleSttModelFields === "function") {
      window.aipkit_toggleSttModelFields(audioSettingsModal);
    }

    const ttsConditional = audioSettingsModal.querySelector(
      ".aipkit_tts_conditional_settings"
    );
    const ttsProviderRow = audioSettingsModal.querySelector(
      ".aipkit_tts_provider_row"
    );
    const ttsAutoPlay = audioSettingsModal.querySelector(
      ".aipkit_tts_auto_play_container"
    );
    if (ttsProviderRow) {
      ttsProviderRow.style.display = showTts ? "block" : "none";
    }
    if (ttsConditional) {
      ttsConditional.style.display = showTts ? "grid" : "none";
    }
    if (ttsAutoPlay) {
      ttsAutoPlay.style.display = showTts ? "flex" : "none";
    }

    const ttsProviderSelect = audioSettingsModal.querySelector(
      ".aipkit_tts_provider_select"
    );
    const selectedTtsProvider = ttsProviderSelect ? ttsProviderSelect.value : "";
    audioSettingsModal.querySelectorAll(".aipkit_tts_field").forEach((field) => {
      const provider = field.getAttribute("data-provider") || "";
      field.style.display =
        showTts && provider === selectedTtsProvider ? "flex" : "none";
    });
    setAudioOptionsButtonState("tts", { enabled: showTts });

    const realtimeContainer = audioSettingsModal.querySelector(
      ".aipkit_realtime_voice_settings_container"
    );
    const directVoiceSelect = audioSettingsModal.querySelector(
      '[name="direct_voice_mode"]'
    );
    const realtimeToolsToggle = builder.querySelector(
      ".aipkit_audio_toggle_realtime_row .aipkit_enable_realtime_voice_toggle"
    );
    const forceVisible =
      realtimeContainer &&
      realtimeContainer.getAttribute("data-force-visible") === "1";
    const showRealtime = forceVisible
      ? true
      : realtimeEnabledFromTools;
    if (realtimeContainer) {
      realtimeContainer.style.display = showRealtime ? "block" : "none";
    }
    const openaiConfigured = getChatbotProviderConfigured("openai", builder.dataset.openaiApiKeySet === "true");
    audioSettingsModal.querySelectorAll('[data-aipkit-realtime-connect], [data-aipkit-realtime-admin-hint]').forEach((control) => {
      control.hidden = openaiConfigured;
    });
    const voiceEngine = audioSettingsModal.querySelector('[name="voice_engine"]')?.value || "realtime";
    audioSettingsModal.querySelectorAll(".aipkit_rt_dependent").forEach((row) => {
      row.style.display = showRealtime && (!row.dataset.voiceEngine || row.dataset.voiceEngine === voiceEngine) ? "" : "none";
    });
    if (directVoiceSelect) {
      const topModeSelectForDirectVoice = builder.querySelector(
        "[data-aipkit-top-mode-select]"
      );
      const rawDeployMode = topModeSelectForDirectVoice
        ? String(topModeSelectForDirectVoice.value || "").trim()
        : "inline";
      const deployMode =
        rawDeployMode === "popup" || rawDeployMode === "external"
          ? rawDeployMode
          : "inline";
      const externalPopupEnabled =
        topModeSelectForDirectVoice &&
        topModeSelectForDirectVoice.dataset.aipkitExternalPopupEnabled === "1";
      const popupEnabledForDirectVoice =
        deployMode === "popup" ||
        (deployMode === "external" && externalPopupEnabled);
      const realtimeAvailable =
        !!realtimeToolsToggle && !realtimeToolsToggle.disabled;
      directVoiceSelect.disabled = !(
        realtimeAvailable &&
        realtimeEnabledFromTools &&
        popupEnabledForDirectVoice
      );
    }
    setAudioOptionsButtonState("realtime", {
      enabled:
        realtimeEnabledFromTools &&
        Boolean(realtimeToolsToggle) &&
        !realtimeToolsToggle.disabled,
    });

    if (audioSettingsModal.classList.contains("is-open")) {
      const activeFeature = String(
        audioSettingsModal.dataset.activeAudioFeature || ""
      )
        .trim()
        .toLowerCase();
      const featureStillEnabled =
        (activeFeature === "stt" && showStt) ||
        (activeFeature === "tts" && showTts) ||
        (activeFeature === "realtime" && realtimeEnabledFromTools);
      if (activeFeature && !featureStillEnabled) {
        closeAudioSettingsFlyout();
      }
    }

    syncToolsEnabledOptionsFromFields();
    applyAudioSettingsFeatureFilter();
  };

  const bindPanel = () => {
    if (audioSettingsModal && builder.isConnected && audioSettingsModal.isConnected && !audioSettingsModal.dataset.bound) {
      const controller = new AbortController();
      const observer = new MutationObserver(() => {
        if (builder.isConnected && audioSettingsModal.isConnected) return;
        controller.abort();
        observer.disconnect();
        closeAudioSettingsFlyout();
        delete audioSettingsModal.dataset.bound;
      });
      const openAudioSettingsFlyout = (trigger) => {
        if (!audioSettingsModal || !trigger) {
          return;
        }
        closeOtherAdvancedDetailPanels(audioSettingsModal);
        mountInlineSettingsPanelForTrigger(audioSettingsModal, trigger);
        const featureKey = String(trigger.dataset.audioFeature || "")
          .trim()
          .toLowerCase();
        audioSettingsModal.dataset.activeAudioFeature = featureKey;
        audioSettingsModal.classList.add("is-open");
        audioSettingsModal.setAttribute("aria-hidden", "false");
        syncInlineSettingsPanelState(audioSettingsModal, true);
        if (activeAudioSettingsTrigger && activeAudioSettingsTrigger !== trigger) {
          activeAudioSettingsTrigger.setAttribute("aria-expanded", "false");
        }
        trigger.setAttribute("aria-expanded", "true");
        activeAudioSettingsTrigger = trigger;

        if (typeof window.aipkit_attachRangeValueHandlers === "function") {
          window.aipkit_attachRangeValueHandlers(
            "#aipkit_builder_audio_settings_modal"
          );
        }

        updateAudioVisibility();
        if (typeof window.aipkit_syncAudioTtsOnOpen === "function") {
          window.aipkit_syncAudioTtsOnOpen().catch(() => {});
        }
      };

      const unregister = registerAdvancedDetailPanelCloser(audioSettingsModal, closeAudioSettingsFlyout);
      controller.signal.addEventListener("abort", () => unregister?.(), { once: true });

      audioSettingsModal.addEventListener("click", (event) => {
        const connect = event.target.closest('[data-aipkit-realtime-connect]');
        if (!connect) return;
        event.preventDefault();
        window.aipkit_openProviderConnection?.(connect, null, { provider: "OpenAI" });
      }, { signal: controller.signal });
      window.addEventListener('aipkit:provider-status-updated', updateAudioVisibility, { signal: controller.signal });

      builder.addEventListener("click", (event) => {
        const configBtn = event.target.closest(
          ".aipkit_audio_settings_config_btn"
        );
        if (!configBtn) {
          return;
        }
        event.preventDefault();
        if (
          audioSettingsModal.classList.contains("is-open") &&
          activeAudioSettingsTrigger === configBtn
        ) {
          closeAudioSettingsFlyout();
          return;
        }
        openAudioSettingsFlyout(configBtn);
      }, { signal: controller.signal });

      registerAudioPanelEvents(audioSettingsModal, closeAudioSettingsFlyout, controller.signal);
      observer.observe(builder.ownerDocument.documentElement, { childList: true, subtree: true });

      audioSettingsModal.dataset.bound = "1";
    }

  };

  const bindPersistence = (persistence) => {
    if (!audioSettingsModal) return;
    const getAudioSettings = () => {
      const readToggle = field => !field ? null : field.tagName === "SELECT" ? field.value :
        field.type === "checkbox" ? field.checked ? "1" : "0" : field.value || "0";
      const settings = {};
      for (const { selector, key, values, toggles } of audioFeatures) {
        const enabled = readToggle(builder.querySelector(selector));
        if (enabled === null) continue;
        settings[key] = enabled;
        for (const name of values) {
          const field = audioSettingsModal.querySelector(`[name="${name}"]`);
          if (field) settings[name] = toggles.includes(name) ? readToggle(field) : field.value;
        }
      }
      return settings;
    };

    const audioCatalogs = createChatbotAudioCatalogs({
      builder, panel: audioSettingsModal, getBotId: persistence.getSelectedBuilderBotId,
    });
    const syncAudioFormatCombo = (combo, commit = false) => {
      const row = combo.closest(".aipkit_audio_format_field") || combo.closest(".aipkit_popover_option_row");
      const input = row?.querySelector('[name="input_audio_format"]');
      const output = row?.querySelector('[name="output_audio_format"]');
      const current = combo.querySelector('option[value="current"]');
      const value = combo.value || "";
      if (!input || !output || !current || (commit && !value)) return;
      if (commit) {
        if (value.startsWith("in:")) input.value = value.replace("in:", "");
        else if (value.startsWith("out:")) output.value = value.replace("out:", "");
      }
      current.textContent = `In: ${input.value} / Out: ${output.value}`;
      combo.value = "current";
      if (commit) {
        input.dispatchEvent(new Event("change", { bubbles: true }));
        output.dispatchEvent(new Event("change", { bubbles: true }));
      }
    };
    const initializeAudioSelects = () => {
      audioSettingsModal.querySelectorAll(".aipkit_audio_format_combo_select").forEach(combo => syncAudioFormatCombo(combo));
      audioCatalogs.initialize();
      updateCloudAudioControls(audioSettingsModal);
    };

    let disposed = false;
    const syncAudioUiState = () => {
      if (disposed || !builder.isConnected || !audioSettingsModal.isConnected) return;
      updateAudioVisibility();
      initializeAudioSelects();
    };
    const findField = name => audioSettingsModal.querySelector(`[name="${name}"]`) ||
      builder.querySelector(audioFeatures.find(feature => feature.key === name)?.selector || `[name="${name}"]`);
    bindChatbotSettingsAutosave({
      builder, panel: audioSettingsModal, boundKey: "audioSettingsAutosaveBound", persistence,
      action: "aipkit_update_chatbot_audio_settings", readSettings: getAudioSettings,
      isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
      canSave: settings => Object.keys(settings).length > 0,
      restoreSettings: settings => {
        for (const [name, value] of Object.entries(settings)) {
          const field = findField(name);
          if (!field) continue;
          if (field.type === "checkbox") field.checked = value === "1";
          else {
            // Catalog options can change while a draft is hidden.
            if (field.tagName === "SELECT" && value && !Array.from(field.options).some(option => option.value === value)) {
              field.add(new Option(value, value));
            }
            field.value = value;
          }
          const feature = audioFeatures.find(feature => feature.key === name);
          const toggle = feature && builder.querySelector(feature.selector);
          if (toggle && toggle !== field) {
            if (toggle.type === "checkbox") toggle.checked = value === "1";
            else toggle.value = value;
          }
        }
      },
      afterHydrate: () => { audioCatalogs.reset(); syncAudioUiState(); },
      interactionNodes: () => [audioSettingsModal, ...audioFeatures.map(({ selector }) =>
        builder.querySelector(selector)?.closest('[data-aipkit-tool-key]'))],
      bindEvents: ({ signal, isEditable, draft, save }) => {
        window.addEventListener('aipkit:cloud-connection-changed', () => {
          updateCloudAudioControls(audioSettingsModal);
          updateAudioVisibility();
        }, { signal });
        const syncCatalogsOnOpen = () => signal.aborted ? Promise.resolve() : audioCatalogs.refresh();
        window.aipkit_syncAudioTtsOnOpen = syncCatalogsOnOpen;
        signal.addEventListener("abort", () => {
          disposed = true;
          audioCatalogs.reset();
          if (window.aipkit_syncAudioTtsOnOpen === syncCatalogsOnOpen) delete window.aipkit_syncAudioTtsOnOpen;
        }, { once: true });
        builder.addEventListener("change", (event) => {
          if (!isEditable() || !event.target || !audioFeatures.some(({ selector }) => event.target.matches(selector))) return;
          updateAudioVisibility();
          save();
        }, { signal });
        audioSettingsModal.addEventListener("input", event => {
          if (isEditable() && event.target && audioChangeSelectors.some(selector => event.target.matches(selector))) draft();
        }, { signal });
        audioSettingsModal.addEventListener("change", (event) => {
          const target = event.target;
          if (!isEditable() || !target) return;
          if (target.matches(".aipkit_audio_format_combo_select")) {
            syncAudioFormatCombo(target, true);
            return;
          }

          if (audioChangeSelectors.some((selector) => target.matches(selector))) {
            if (target.matches('[name="tts_cloud_model_id"]')) updateCloudAudioControls(audioSettingsModal);
            updateAudioVisibility();
            save();
            if (target.matches(".aipkit_tts_provider_select")) {
              audioCatalogs.refresh(true).catch(() => {});
            }
          }
        }, { signal });
      },
    });

    return syncAudioUiState;
  };

  return { updateAudioVisibility, bindPanel, bindPersistence };
}
