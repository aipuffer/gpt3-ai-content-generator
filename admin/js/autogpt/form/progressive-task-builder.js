/**
 * AIPKit AutoGPT - Progressive task builder.
 *
 * Keeps the existing task form and task-specific controllers intact while
 * presenting them as a guided, outcome-first flow.
 */
(function () {
  "use strict";

  const STEP_ORDER = [
    "content",
    "ai",
    "fields",
    "images",
    "seo",
    "knowledge",
    "finish",
  ];
  let checklistSearchId = 0;
  const translate = (text) => {
    const translator = window.wp?.i18n?.__;
    return typeof translator === "function"
      ? translator(text, "gpt3-ai-content-generator")
      : text;
  };

  const asText = (value) => String(value ?? "").trim();

  const selectedOptions = (select) =>
    select
      ? Array.from(select.selectedOptions).filter((option) => option.value)
      : [];

  const selectedLabels = (select) =>
    selectedOptions(select).map((option) => asText(option.textContent));

  const countLines = (value) =>
    asText(value)
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean).length;

  const countManualTopics = (value) =>
    asText(value)
      .split(/\r?\n/)
      .map((line) => line.split("|")[0]?.trim() || "")
      .filter(Boolean).length;

  const formatCount = (count, singular, plural) =>
    `${count} ${count === 1 ? translate(singular) : translate(plural)}`;

  const getTaskTypes = () =>
    (window.aipkit_automated_tasks_config || {}).task_types || {};

  const getTaskDetails = (form) => {
    const type = form?.elements?.task_type?.value || "";
    return getTaskTypes()[type] || null;
  };

  const isProPlan = () =>
    Boolean(window.aipkit_dashboard && window.aipkit_dashboard.isProPlan);

  const isLockedTask = (details) => Boolean(details?.pro) && !isProPlan();

  const displayValue = (field, fallback = "") => {
    if (!field) return fallback;
    if (field.tagName === "SELECT") {
      return asText(field.selectedOptions?.[0]?.textContent) || fallback;
    }
    return asText(field.value) || fallback;
  };

  const setElementText = (root, selector, value) => {
    const element = root.querySelector(selector);
    if (element) element.textContent = value;
  };

  function showRecommendedSettingConfirm(message, options = {}) {
    const title = options.title || translate("Confirm setting");
    const confirmText = options.confirmText || translate("Use this setting");
    const cancelText = options.cancelText || translate("Keep recommended");
    const emphasisText = options.emphasisText || "";
    const questionText = options.questionText || "";
    const preferCancel = options.preferCancel === true;

    return new Promise((resolve) => {
      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(message, {
          title,
          confirmText,
          cancelText,
          emphasisText,
          questionText,
          preferCancel,
          onConfirm: () => resolve(true),
          onCancel: () => resolve(false),
        });
        return;
      }

      const prefix = title ? `${title}\n\n` : "";
      const emphasis = emphasisText ? `\n\n${emphasisText}` : "";
      const question = questionText ? `\n\n${questionText}` : "";
      resolve(window.confirm(`${prefix}${message}${emphasis}${question}`));
    });
  }

  function getReasoningConfirmMessage(label) {
    const normalized = asText(label).toLowerCase();
    if (normalized.includes("extra")) {
      return translate(
        "Extra high reasoning can significantly increase the time each post takes to generate."
      );
    }
    if (normalized.includes("high")) {
      return translate(
        "High reasoning can make each post take much longer to generate."
      );
    }
    if (normalized.includes("medium")) {
      return translate(
        "Medium reasoning can make each post take noticeably longer to generate."
      );
    }
    return translate(
      "Reasoning can make each post take longer to generate."
    );
  }

  function getTemperatureConfirmMessage(value) {
    if (value > 1) {
      return translate(
        "Higher creativity can make posts less predictable, add unusual phrasing, or drift from your instructions."
      );
    }
    return translate(
      "Lower creativity can make writing safer, but it may sound flatter or more repetitive."
    );
  }

  function createMultiSelectChecklist(select) {
    if (!select) return;
    if (select.dataset.aipkitChecklistReady === "1") {
      select._aipkitChecklistSync?.();
      return;
    }

    const optionCount = Array.from(select.options).filter(
      (option) => option.value
    ).length;
    const checklistStyle = select.dataset.aipkitChecklistStyle || "";
    const usesInlineCheckboxes = checklistStyle === "inline-checkboxes";
    const usesDisclosure = usesInlineCheckboxes && (optionCount > 6 || select.dataset.aipkitChecklistDisclosure === "dropdown");
    const isLarge = !usesInlineCheckboxes && optionCount > 8;
    const usesSearch = usesDisclosure ? optionCount > 12 : isLarge;
    const checklist = document.createElement("div");
    checklist.className = "aipkit_autogpt_checklist";
    if (usesInlineCheckboxes && !usesDisclosure) {
      checklist.classList.add("aipkit_autogpt_checklist--inline-checkboxes");
    } else if (usesDisclosure) {
      checklist.classList.add("aipkit_autogpt_checklist--disclosure-list");
    }
    checklist.setAttribute("role", "group");
    const associatedLabel = select.id
      ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`)
      : null;
    const accessibleLabel =
      select.getAttribute("aria-label") || asText(associatedLabel?.textContent);
    if (accessibleLabel) {
      checklist.setAttribute("aria-label", accessibleLabel);
    }

    const createChecklistSearch = () => {
      const input = document.createElement("input");
      const subject = accessibleLabel
        ? accessibleLabel.toLocaleLowerCase()
        : translate("options");
      const label = `${translate("Search")} ${subject}`;
      input.type = "search";
      input.id = select.id
        ? `${select.id}_search`
        : `aipkit_autogpt_checklist_search_${++checklistSearchId}`;
      input.className = "aipkit_form-input aipkit_autogpt_checklist_search";
      input.placeholder = `${label}…`;
      input.setAttribute("aria-label", label);
      return input;
    };

    const rows = Array.from(select.options)
      .filter((option) => option.value)
      .map((option) => {
        const label = document.createElement("label");
        label.className = "aipkit_autogpt_checklist_item";

        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.value = option.value;
        checkbox.checked = option.selected;
        checkbox.disabled = option.disabled;

        const text = document.createElement("span");
        const optionLabel = asText(option.textContent);
        text.textContent = optionLabel;
        text.title = optionLabel;

        checkbox.addEventListener("change", () => {
          option.selected = checkbox.checked;
          select.dispatchEvent(new Event("change", { bubbles: true }));
        });

        label.append(checkbox, text);
        checklist.appendChild(label);
        return { option, checkbox, label };
      });

    const sync = () => {
      rows.forEach(({ option, checkbox, label }) => {
        checkbox.checked = option.selected;
        checkbox.disabled = option.disabled;
        label.classList.toggle("is-selected", option.selected);
      });
    };

    let insertionTarget = checklist;
    let searchInput = null;
    let selectionCount = null;
    let disclosureTrigger = null;
    if (usesDisclosure) {
      select
        .closest(
          ".aipkit_ce_filter_row, .aipkit_ci_filter_row, .aipkit_cc_setting_row"
        )
        ?.classList.add("has-checklist-disclosure");

      const disclosure = document.createElement("div");
      disclosure.className = "aipkit_autogpt_checklist_disclosure";

      disclosureTrigger = document.createElement("button");
      disclosureTrigger.type = "button";
      disclosureTrigger.className = "aipkit_autogpt_checklist_disclosure_trigger";
      disclosureTrigger.setAttribute("aria-expanded", "false");

      const panel = document.createElement("div");
      panel.className = "aipkit_autogpt_checklist_disclosure_panel";
      panel.hidden = true;
      if (select.id) {
        panel.id = `${select.id}_checklist_panel`;
        disclosureTrigger.setAttribute("aria-controls", panel.id);
      }

      selectionCount = document.createElement("span");
      selectionCount.className = "aipkit_autogpt_checklist_disclosure_summary";
      disclosureTrigger.appendChild(selectionCount);

      if (usesSearch) {
        const toolbar = document.createElement("div");
        toolbar.className = "aipkit_autogpt_checklist_toolbar";

        searchInput = createChecklistSearch();
        toolbar.appendChild(searchInput);
        panel.appendChild(toolbar);
        checklist.classList.add("is-large");
      }

      panel.appendChild(checklist);
      disclosure.append(disclosureTrigger, panel);
      insertionTarget = disclosure;

      if (select.dataset.aipkitChecklistDisclosure === "dropdown") {
        const close = () => {
          disclosureTrigger.setAttribute("aria-expanded", "false");
          panel.hidden = true;
          disclosure.classList.remove("is-open");
        };
        document.addEventListener("click", (event) => {
          if (!disclosure.contains(event.target)) close();
        });
        disclosure.addEventListener("keydown", (event) => {
          if (event.key === "Escape") {
            close();
            disclosureTrigger.focus();
          }
        });
      }

      disclosureTrigger.addEventListener("click", () => {
        const isOpen = disclosureTrigger.getAttribute("aria-expanded") === "true";
        disclosureTrigger.setAttribute("aria-expanded", isOpen ? "false" : "true");
        panel.hidden = isOpen;
        disclosure.classList.toggle("is-open", !isOpen);
      });
    } else if (isLarge) {
      const shell = document.createElement("div");
      shell.className = "aipkit_autogpt_checklist_shell";

      const toolbar = document.createElement("div");
      toolbar.className = "aipkit_autogpt_checklist_toolbar";

      searchInput = createChecklistSearch();

      selectionCount = document.createElement("span");
      selectionCount.className = "aipkit_autogpt_checklist_count";

      toolbar.append(searchInput, selectionCount);
      checklist.classList.add("is-large");
      shell.append(toolbar, checklist);
      insertionTarget = shell;
    }

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const query = asText(searchInput.value).toLocaleLowerCase();
        rows.forEach(({ option, label }) => {
          const matches = asText(option.textContent)
            .toLocaleLowerCase()
            .includes(query);
          label.hidden = Boolean(query) && !matches && !option.selected;
        });
      });
    }

    const syncWithCount = () => {
      sync();
      if (selectionCount) {
        const total = rows.filter(({ option }) => option.selected).length;
        selectionCount.textContent = usesDisclosure
          ? `${total} ${translate("of")} ${optionCount} ${translate("selected")}`
          : total
            ? `${total} ${translate("selected")}`
            : translate("None selected");
        if (disclosureTrigger && accessibleLabel) {
          disclosureTrigger.setAttribute(
            "aria-label",
            `${accessibleLabel}: ${selectionCount.textContent}`
          );
        }
      }
    };

    select.addEventListener("change", syncWithCount);
    select.classList.add("aipkit_autogpt_native_multiselect");
    select.setAttribute("aria-hidden", "true");
    select.setAttribute("tabindex", "-1");
    select.insertAdjacentElement("afterend", insertionTarget);
    select.dataset.aipkitChecklistReady = "1";
    select._aipkitChecklistSync = syncWithCount;
    syncWithCount();
  }

  function enhanceMultiSelects(form) {
    form
      .querySelectorAll(
        ".aipkit_ci_multi_select, .aipkit_cc_multi_select, .aipkit_ce_multi_select"
      )
      .forEach(createMultiSelectChecklist);
  }

  function createSegmentedChoice(select) {
    if (!select) return;
    if (select.dataset.aipkitSegmentedReady === "1") {
      select._aipkitSegmentedSync?.();
      return;
    }

    const options = Array.from(select.options).filter((option) => option.value);
    if (options.length < 2) return;

    const group = document.createElement("div");
    group.className = "aipkit_autogpt_segmented_choice";
    group.setAttribute("role", "radiogroup");

    const associatedLabel = select.id
      ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`)
      : null;
    if (associatedLabel) {
      if (!associatedLabel.id) associatedLabel.id = `${select.id}_label`;
      group.setAttribute("aria-labelledby", associatedLabel.id);
    } else {
      group.setAttribute("aria-label", translate("Choose an option"));
    }

    const buttons = options.map((option) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "aipkit_autogpt_segmented_choice_btn";
      button.dataset.value = option.value;
      button.setAttribute("role", "radio");
      button.textContent = asText(option.textContent);
      group.appendChild(button);
      return { option, button };
    });

    const sync = () => {
      buttons.forEach(({ option, button }) => {
        const isSelected = select.value === option.value;
        button.textContent = asText(option.textContent);
        button.hidden = option.hidden;
        button.disabled = option.disabled;
        button.classList.toggle("is-selected", isSelected);
        button.setAttribute("aria-checked", isSelected ? "true" : "false");
        button.setAttribute(
          "tabindex",
          isSelected && !option.hidden && !option.disabled ? "0" : "-1"
        );
      });
    };

    const applyChoice = (index, focus = false) => {
      const choice = buttons[index];
      if (!choice || choice.option.disabled || choice.option.hidden) return;
      if (select.value !== choice.option.value) {
        select.value = choice.option.value;
        select.dispatchEvent(new Event("input", { bubbles: true }));
        select.dispatchEvent(new Event("change", { bubbles: true }));
      } else {
        sync();
      }
      if (focus) choice.button.focus();
    };

    const choose = (index, focus = false) => {
      const choice = buttons[index];
      if (!choice || choice.option.disabled || choice.option.hidden) return;
      applyChoice(index, focus);
    };

    buttons.forEach(({ button }, index) => {
      button.addEventListener("click", () => choose(index));
    });

    group.addEventListener("keydown", (event) => {
      const currentIndex = buttons.findIndex(
        ({ button }) => button === document.activeElement
      );
      if (currentIndex < 0) return;

      const availableIndexes = buttons
        .map(({ option }, index) =>
          option.hidden || option.disabled ? -1 : index
        )
        .filter((index) => index >= 0);
      if (!availableIndexes.length) return;

      const currentPosition = Math.max(
        0,
        availableIndexes.indexOf(currentIndex)
      );
      let nextPosition = currentPosition;
      if (["ArrowRight", "ArrowDown"].includes(event.key)) {
        nextPosition = (currentPosition + 1) % availableIndexes.length;
      } else if (["ArrowLeft", "ArrowUp"].includes(event.key)) {
        nextPosition =
          (currentPosition - 1 + availableIndexes.length) %
          availableIndexes.length;
      } else if (event.key === "Home") {
        nextPosition = 0;
      } else if (event.key === "End") {
        nextPosition = availableIndexes.length - 1;
      } else {
        return;
      }

      event.preventDefault();
      choose(availableIndexes[nextPosition], true);
    });

    select.addEventListener("change", sync);
    select.classList.add("aipkit_autogpt_segmented_native");
    select.setAttribute("aria-hidden", "true");
    select.setAttribute("tabindex", "-1");
    select.insertAdjacentElement("afterend", group);
    select.dataset.aipkitSegmentedReady = "1";
    select._aipkitSegmentedSync = sync;
    sync();
  }

  function enhanceSegmentedChoices(form) {
    form
      .querySelectorAll("[data-aipkit-segmented-select]")
      .forEach(createSegmentedChoice);
  }

  function createTemperatureSlider(slider) {
    if (!slider) return;
    if (slider.dataset.aipkitTemperatureReady === "1") {
      slider._aipkitTemperatureSync?.();
      return;
    }

    const control = slider.closest(".aipkit_autogpt_temperature_control");
    const output = control?.querySelector("[data-aipkit-temperature-value]");
    const minimum = Number.parseFloat(slider.min || "0");
    const maximum = Number.parseFloat(slider.max || "2");
    const labelForValue = (value) => {
      if (value < 0.7) return slider.dataset.labelFocused || translate("Focused");
      if (value > 1.3) return slider.dataset.labelCreative || translate("Creative");
      return slider.dataset.labelBalanced || translate("Balanced");
    };

    const sync = () => {
      const value = Number.parseFloat(slider.value || "1");
      const progress =
        maximum > minimum
          ? ((Math.min(maximum, Math.max(minimum, value)) - minimum) /
              (maximum - minimum)) *
            100
          : 50;
      const label = labelForValue(value);
      slider.style.setProperty("--aipkit-slider-progress", `${progress}%`);
      control?.style.setProperty("--aipkit-slider-progress", `${progress}%`);
      if (control) {
        control.dataset.aipkitSliderEdge =
          progress <= 0 ? "start" : progress >= 100 ? "end" : "middle";
      }
      slider.setAttribute("aria-valuetext", label);
      if (output) {
        output.textContent = label;
        output.value = label;
        output.title = `${label} (${slider.value})`;
      }
      const endpointLabels = control?.querySelectorAll(
        ".aipkit_autogpt_temperature_header > span"
      );
      if (endpointLabels?.[0]) {
        endpointLabels[0].hidden = label === (slider.dataset.labelFocused || translate("Focused"));
      }
      if (endpointLabels?.[1]) {
        endpointLabels[1].hidden = label === (slider.dataset.labelCreative || translate("Creative"));
      }
    };

    slider.addEventListener("input", sync);
    slider.addEventListener("change", (event) => {
      sync();
      const value = Number.parseFloat(slider.value || "1");
      const acceptedValue = Number.parseFloat(
        slider.dataset.aipkitAcceptedTemperature || "1"
      );

      if (!event.isTrusted || acceptedValue !== 1 || value === 1) {
        slider.dataset.aipkitAcceptedTemperature = String(value);
        return;
      }

      showRecommendedSettingConfirm(getTemperatureConfirmMessage(value), {
        title: translate("Are you sure?"),
        confirmText: translate("Use anyway"),
        cancelText: translate("Keep Balanced"),
        preferCancel: true,
        emphasisText: translate("Balanced is recommended for most posts."),
        questionText: translate(
          "Do you want to use this creativity level anyway?"
        ),
      }).then((confirmed) => {
        if (confirmed) {
          slider.dataset.aipkitAcceptedTemperature = String(value);
          return;
        }

        slider.value = String(acceptedValue);
        sync();
        slider.dispatchEvent(new Event("input", { bubbles: true }));
        slider.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });
    slider.dataset.aipkitTemperatureReady = "1";
    slider.dataset.aipkitAcceptedTemperature = String(slider.value || "1");
    slider._aipkitTemperatureSync = sync;
    sync();
  }

  function enhanceTemperatureSliders(form) {
    form
      .querySelectorAll("[data-aipkit-temperature-slider]")
      .forEach(createTemperatureSlider);
  }

  function createReasoningSlider(slider) {
    if (!slider) return;
    if (slider.dataset.aipkitReasoningReady === "1") {
      slider._aipkitReasoningSync?.();
      return;
    }

    const control = slider.closest(".aipkit_autogpt_reasoning_control");
    const select = control?.querySelector("[data-aipkit-reasoning-confirm-select]");
    const output = control?.querySelector("[data-aipkit-reasoning-value]");
    const startLabel = control?.querySelector("[data-aipkit-reasoning-start]");
    const endLabel = control?.querySelector("[data-aipkit-reasoning-end]");
    if (!select) return;

    const availableOptions = () =>
      Array.from(select.options).filter(
        (option) => option.value && !option.hidden && !option.disabled
      );

    const sync = () => {
      const options = availableOptions();
      const selectedIndex = Math.max(
        0,
        options.findIndex((option) => option.value === select.value)
      );
      const maximum = Math.max(0, options.length - 1);
      const selectedOption = options[selectedIndex] || null;
      const selectedLabel = asText(selectedOption?.textContent) || translate("Off");
      const progress = maximum > 0 ? (selectedIndex / maximum) * 100 : 0;

      slider.min = "0";
      slider.max = String(maximum);
      slider.step = "1";
      slider.value = String(selectedIndex);
      slider.disabled = options.length < 2;
      slider.style.setProperty("--aipkit-slider-progress", `${progress}%`);
      control?.style.setProperty("--aipkit-slider-progress", `${progress}%`);
      if (control) {
        control.dataset.aipkitSliderEdge =
          progress <= 0 ? "start" : progress >= 100 ? "end" : "middle";
      }
      slider.setAttribute("aria-valuetext", selectedLabel);

      if (startLabel) {
        startLabel.textContent = asText(options[0]?.textContent) || selectedLabel;
        startLabel.hidden = selectedIndex === 0;
      }
      if (endLabel) {
        endLabel.textContent =
          asText(options[options.length - 1]?.textContent) || selectedLabel;
        endLabel.hidden = selectedIndex === maximum;
      }
      if (output) {
        output.textContent = selectedLabel;
        output.value = selectedLabel;
      }
    };

    const commit = (value) => {
      select.value = value;
      select.dispatchEvent(new Event("input", { bubbles: true }));
      select.dispatchEvent(new Event("change", { bubbles: true }));
    };

    slider.addEventListener("input", () => {
      const options = availableOptions();
      const option = options[Number.parseInt(slider.value || "0", 10)];
      if (!option) return;
      select.value = option.value;
      select.dispatchEvent(new Event("input", { bubbles: true }));
      sync();
    });

    slider.addEventListener("change", () => {
      const nextValue = select.value;
      const acceptedValue = slider.dataset.aipkitAcceptedReasoning || "none";
      if (nextValue === acceptedValue) {
        sync();
        return;
      }

      if (nextValue === "none") {
        commit(nextValue);
        return;
      }

      const nextOption = Array.from(select.options).find(
        (option) => option.value === nextValue
      );
      const nextLabel = asText(nextOption?.textContent) || nextValue;
      const acceptedOption = Array.from(select.options).find(
        (option) => option.value === acceptedValue
      );
      const acceptedLabel =
        asText(acceptedOption?.textContent) || translate("Off");
      showRecommendedSettingConfirm(getReasoningConfirmMessage(nextLabel), {
        title: translate("Are you sure?"),
        confirmText: translate("Use reasoning"),
        cancelText:
          acceptedValue === "none"
            ? translate("Keep off")
            : `${translate("Keep")} ${acceptedLabel}`,
        preferCancel: true,
        emphasisText: translate("Off is recommended for most posts."),
        questionText: translate(
          "Do you want to use this reasoning level anyway?"
        ),
      }).then((confirmed) => {
        if (confirmed) {
          commit(nextValue);
          return;
        }

        commit(acceptedValue);
      });
    });

    const syncFromSelect = () => {
      slider.dataset.aipkitAcceptedReasoning = select.value || "none";
      sync();
    };
    select.addEventListener("change", syncFromSelect);
    select.classList.add("aipkit_autogpt_reasoning_native");
    select.setAttribute("aria-hidden", "true");
    select.setAttribute("tabindex", "-1");
    slider.dataset.aipkitReasoningReady = "1";
    slider.dataset.aipkitAcceptedReasoning = select.value || "none";
    slider._aipkitReasoningSync = sync;
    select._aipkitReasoningSliderSync = syncFromSelect;
    sync();
  }

  function enhanceReasoningSliders(form) {
    form
      .querySelectorAll("[data-aipkit-reasoning-slider]")
      .forEach(createReasoningSlider);
  }

  function createConfidenceSlider(slider) {
    if (!slider) return;
    if (slider.dataset.aipkitConfidenceReady === "1") {
      slider._aipkitConfidenceSync?.();
      return;
    }

    const control = slider.closest(".aipkit_autogpt_confidence_control");
    const output = control?.querySelector("[data-aipkit-confidence-value]");
    const warning = control?.querySelector("[data-aipkit-confidence-warning]");
    const minimum = Number.parseFloat(slider.min || "0");
    const maximum = Number.parseFloat(slider.max || "100");
    const recommended = Number.parseFloat(
      slider.dataset.recommendedValue || "20"
    );

    const warningForValue = (value) => {
      if (value === recommended) return "";
      if (value <= 10) {
        return translate(
          "Very low thresholds can include weak or irrelevant matches."
        );
      }
      if (value < recommended) {
        return translate("Lower thresholds can include less relevant matches.");
      }
      if (value >= 100) {
        return translate("A 100% threshold may return no matches.");
      }
      if (value >= 90) {
        return translate("Very high thresholds may return few or no matches.");
      }
      if (value >= 70) {
        return translate("High thresholds may return very few matches.");
      }
      return translate("Higher thresholds may return fewer matches.");
    };

    const sync = () => {
      const rawValue = Number.parseFloat(slider.value || String(recommended));
      const value = Math.min(maximum, Math.max(minimum, rawValue));
      const progress =
        maximum > minimum
          ? ((value - minimum) / (maximum - minimum)) * 100
          : 0;
      const valueLabel = `${value}%`;
      const warningText = warningForValue(value);

      slider.style.setProperty("--aipkit-slider-progress", `${progress}%`);
      control?.style.setProperty("--aipkit-slider-progress", `${progress}%`);
      slider.setAttribute("aria-valuetext", valueLabel);
      if (output) {
        output.textContent = valueLabel;
        output.value = valueLabel;
      }
      if (warning) {
        warning.textContent = warningText;
        warning.hidden = warningText === "";
      }
    };

    slider.addEventListener("input", sync);
    slider.addEventListener("change", sync);
    slider.dataset.aipkitConfidenceReady = "1";
    slider._aipkitConfidenceSync = sync;
    sync();
  }

  function enhanceConfidenceSliders(form) {
    form
      .querySelectorAll("[data-aipkit-confidence-slider]")
      .forEach(createConfidenceSlider);
  }

  function enhanceResultLimitSteppers(form) {
    form
      .querySelectorAll("[data-aipkit-result-limit-step]")
      .forEach((button) => {
        if (button.dataset.aipkitResultLimitListenerAttached === "1") return;
        button.addEventListener("click", () => {
          const control = button.closest(".aipkit_autogpt_result_limit_control");
          const input = control?.querySelector("[data-aipkit-result-limit-input]");
          if (!input) return;

          const direction = Number(button.dataset.aipkitResultLimitStep || 0);
          const current = Number(input.value || input.min || 1);
          const minimum = Number(input.min || 1);
          const maximum = input.max
            ? Number(input.max)
            : Number.POSITIVE_INFINITY;
          input.value = String(
            Math.min(maximum, Math.max(minimum, current + direction))
          );
          input.dispatchEvent(new Event("input", { bubbles: true }));
          input.dispatchEvent(new Event("change", { bubbles: true }));
        });
        button.dataset.aipkitResultLimitListenerAttached = "1";
      });
  }

  function syncSmartSeoControl(form) {
    const control = form.querySelector(
      "[data-aipkit-task-smart-seo-main-toggle]"
    );
    const enabled = control
      ? control.type === "checkbox"
        ? control.checked
        : control.value === "1"
      : false;
    const row = form.querySelector("[data-aipkit-task-smart-seo-settings-row]");
    const rulesAction = form.querySelector(
      "[data-aipkit-smart-seo-rules-action]"
    );
    row?.classList.toggle("is-enabled", enabled);
    if (rulesAction) rulesAction.hidden = !enabled;
  }

  function enhanceSmartSeoControl(form) {
    const control = form.querySelector(
      "[data-aipkit-task-smart-seo-main-toggle]"
    );
    if (
      control &&
      control.dataset.aipkitSmartSeoListenerAttached !== "1"
    ) {
      control.addEventListener("change", () => syncSmartSeoControl(form));
      control.dataset.aipkitSmartSeoListenerAttached = "1";
    }
    syncSmartSeoControl(form);
  }

  function createBuilderController(builder) {
    const form = builder.closest("form");
    const selector = builder.querySelector(
      "[data-aipkit-autogpt-category-selector]"
    );
    const taskTypeSelect = form.elements.task_type;
    const categorySelect = form.elements.task_category;
    const editorActions = builder.querySelector("#aipkit_autogpt_editor_actions");
    const saveButton = builder.querySelector("#aipkit_save_task_btn");
    const saveButtonText = saveButton?.querySelector(".aipkit_btn-text");
    const footerNext = builder.querySelector("[data-aipkit-builder-footer-next]");
    const footerPrevious = builder.querySelector(
      "[data-aipkit-builder-footer-previous]"
    );
    const footerProgressTrack = builder.querySelector(
      "[data-aipkit-builder-progress-track]"
    );
    const footerProgressFill = builder.querySelector(
      "[data-aipkit-builder-progress-fill]"
    );
    const quickCreateButton = builder.querySelector(
      "[data-aipkit-builder-quick-create]"
    );
    const contentSource = builder.querySelector("[data-aipkit-content-source]");
    const manualSourceAction = builder.querySelector(
      "[data-aipkit-manual-source-action]"
    );
    const contentQuestion = builder.querySelector("[data-aipkit-builder-question]");
    const contentFieldSections = builder.querySelectorAll(
      "[data-aipkit-autogpt-content-fields]"
    );

    const state = {
      mode: "create",
      current: "content",
      expanded: "content",
      completed: new Set(),
      unlocked: new Set(["content"]),
      defaultedTypes: new Set(),
      selectedType: "",
      contentStage: "intent",
    };
    let syncQuickCreateState = () => {};

    const stepElement = (key) =>
      builder.querySelector(`[data-aipkit-builder-step="${key}"]`);

    const setTypeQuestion = (showSourceQuestion = false) => {
      const typeStep = stepElement("content");
      const title = typeStep?.querySelector("[data-aipkit-builder-type-title]");
      const description = typeStep?.querySelector(
        "[data-aipkit-builder-type-description]"
      );
      if (title) {
        title.textContent = showSourceQuestion
          ? title.dataset.sourceTitle ||
            translate("Where should your topics come from?")
          : title.dataset.intentTitle ||
            translate("What do you want to automate?");
      }
      if (description) {
        description.textContent = showSourceQuestion
          ? description.dataset.sourceDescription ||
            translate("Choose a topic source to continue.")
          : description.dataset.intentDescription ||
            translate("Choose an outcome to get started.");
      }
    };

    const visibleSteps = () => {
      const type = taskTypeSelect?.value || "";
      if (!type) return STEP_ORDER;

      const ui = getTaskDetails(form)?.ui || {};
      const steps = ["content"];
      if (ui.supports_writing === true) steps.push("ai");
      if (
        type.startsWith("content_writing") ||
        type === "enhance_existing_content"
      ) {
        steps.push("fields");
      }
      if (ui.supports_images === true) steps.push("images");
      if (type.startsWith("content_writing")) steps.push("seo");
      if (ui.supports_context === true) steps.push("knowledge");
      steps.push("finish");
      return steps;
    };

    const contentStages = () =>
      taskTypeSelect?.value ? ["configure"] : ["intent"];

    const progressRoute = () => [
      ...contentStages().map((stage) => `content:${stage}`),
      ...visibleSteps().filter((key) => key !== "content"),
    ];

    const currentProgressKey = () =>
      state.current === "content"
        ? `content:${state.contentStage}`
        : state.current;

    const footerActionLabel = (targetStep) => {
      const labels = {
        ai: "Customize AI →",
        fields: "Customize content →",
        images: "Customize images →",
        seo: "Customize SEO →",
        knowledge: "Customize knowledge →",
        finish: "Review automation →",
      };
      return translate(labels[targetStep] || "Continue →");
    };

    const placeStepError = (key, error) => {
      if (!error) return;

      if (key === "content") {
        const selectedCategory = categorySelect?.value || "";
        const activeSlot = selectedCategory
          ? selector?.querySelector(
              `[data-aipkit-autogpt-family-slot="${CSS.escape(selectedCategory)}"]`
            )
          : null;

        if (taskTypeSelect?.value && activeSlot) {
          if (contentSource?.parentElement === activeSlot) {
            contentSource.insertAdjacentElement("afterend", error);
          } else {
            activeSlot.appendChild(error);
          }
        } else if (selector) {
          selector.insertAdjacentElement("beforebegin", error);
        }
        return;
      }

      const stepBody = stepElement(key)?.querySelector(
        ".aipkit_autogpt_builder_step_body"
      );
      if (!stepBody) return;
      const intro = stepBody.querySelector(
        ".aipkit_autogpt_phase_intro, .aipkit_autogpt_section_intro"
      );
      if (intro) intro.insertAdjacentElement("afterend", error);
      else stepBody.prepend(error);
    };

    const setError = (key, message = "") => {
      const error = builder.querySelector(`[data-aipkit-builder-error="${key}"]`);
      if (!error) return;
      if (message) placeStepError(key, error);
      error.textContent = message;
      error.hidden = !message;
      if (message) error.setAttribute("tabindex", "-1");
      else error.removeAttribute("tabindex");
    };

    const clearErrors = () => {
      STEP_ORDER.forEach((key) => setError(key));
    };

    const getSelectedIntentLabel = () => {
      const type = taskTypeSelect?.value || "";
      if (type.startsWith("content_writing")) {
        return translate("Create new content");
      }
      if (type === "enhance_existing_content") {
        return translate("Rewrite existing content");
      }
      if (type === "content_indexing") {
        return translate("Build a knowledge base");
      }
      if (type === "community_reply_comments") {
        return translate("Reply to comments");
      }
      return asText(getTaskDetails(form)?.label) || translate("Not selected");
    };

    const summarizeTypePath = () => {
      const type = taskTypeSelect?.value || "";
      if (type === "content_writing_bulk") {
        return translate("Starting from topics you type in");
      }
      if (type === "content_writing_csv") {
        return translate("Starting from a CSV file");
      }
      if (type === "content_writing_rss") {
        return translate("Watching RSS feeds for new topics");
      }
      if (type === "content_writing_url") {
        return translate("Using web pages as sources");
      }
      if (type === "content_writing_gsheets") {
        return translate("Syncing topics from Google Sheets");
      }
      if (type === "enhance_existing_content") {
        return translate("Improving selected posts or drafts");
      }
      if (type === "content_indexing") {
        return translate("Adding content AI can reuse");
      }
      if (type === "community_reply_comments") {
        return translate("Drafting replies for new comments");
      }
      return translate("Choose what AI Puffer should do.");
    };

    const summarizeSource = () => {
      const type = taskTypeSelect?.value || "";
      if (!type) return translate("Select an automation first.");

      if (type === "content_writing_bulk") {
        const count = countManualTopics(form.elements.content_title_bulk?.value);
        return count
          ? `${formatCount(count, "topic", "topics")} · ${translate("Manual entry")}`
          : translate("No topics added yet");
      }

      if (type === "content_writing_csv") {
        const csvValue = form.elements.content_title?.value || "";
        const count = countManualTopics(csvValue);
        return count
          ? `${formatCount(count, "topic", "topics")} · ${translate("CSV")}`
          : translate("No CSV uploaded yet");
      }

      if (type === "content_writing_rss") {
        const count = countLines(form.elements.rss_feeds?.value);
        return count
          ? `${formatCount(count, "feed", "feeds")} ${translate("connected")}`
          : translate("No feeds connected yet");
      }

      if (type === "content_writing_url") {
        const count = countLines(form.elements.url_list?.value);
        return count
          ? formatCount(count, "web page", "web pages")
          : translate("No web pages added yet");
      }

      if (type === "content_writing_gsheets") {
        const hasSheet = Boolean(asText(form.elements.gsheets_sheet_id?.value));
        const hasCredentials = Boolean(
          asText(form.elements.gsheets_credentials?.value)
        );
        if (hasSheet && hasCredentials) {
          return translate("Google Sheet connected");
        }
        if (hasSheet) return translate("Google Sheet · Credentials required");
        return translate("No spreadsheet connected yet");
      }

      if (type === "content_indexing") {
        const types = selectedLabels(
          form.querySelector("#aipkit_task_content_indexing_post_types")
        );
        const store = displayValue(
          form.elements.target_store_id,
          translate("No destination")
        );
        const provider = displayValue(form.elements.target_store_provider);
        if (!types.length) return translate("No content types selected yet");
        return [types.join(", "), provider, store].filter(Boolean).join(" · ");
      }

      if (type === "enhance_existing_content") {
        const types = selectedLabels(form.querySelector("#aipkit_task_ce_post_types"));
        return types.length
          ? types.join(", ")
          : translate("No content selected");
      }

      if (type === "community_reply_comments") {
        const types = selectedLabels(
          form.querySelector("#aipkit_task_comment_reply_post_types")
        );
        const action = displayValue(form.elements.reply_action);
        return types.length
          ? `${types.join(", ")} · ${action}`
          : translate("No comment areas selected yet");
      }

      return translate("Source not configured yet");
    };

    const getModelSummary = (scope) => {
      const model = form.querySelector(`#aipkit_task_${scope}_ai_model`);
      const modelName = displayValue(model);
      return modelName;
    };

    const summarizeWriting = () => {
      const type = taskTypeSelect?.value || "";
      if (!type) return translate("Recommended writing settings will be used.");

      if (type.startsWith("content_writing")) {
        const model = getModelSummary("cw") || translate("Default AI model");
        const length = displayValue(
          form.querySelector("#aipkit_task_cw_content_length"),
          translate("Medium")
        );
        const temperature = Number.parseFloat(
          form.querySelector("[data-aipkit-temperature-slider]")?.value || "1"
        );
        const style = temperature < 0.7
          ? translate("Focused")
          : temperature > 1.3
            ? translate("Creative")
            : translate("Balanced");
        return `${model} · ${length} · ${style}`;
      }

      if (type === "enhance_existing_content") {
        return getModelSummary("ce") || translate("Default AI model");
      }

      if (type === "community_reply_comments") {
        return getModelSummary("cc") || translate("Default AI model");
      }

      return translate("Recommended writing settings");
    };

    const summarizeSeo = () => {
      const settingsRow = form.querySelector(
        "[data-aipkit-task-smart-seo-settings-row]"
      );
      const pluginLabel =
        settingsRow?.dataset.aipkitSeoHasPlugin === "1"
          ? asText(settingsRow.dataset.aipkitSeoActiveProfileLabel)
          : "";
      const withPlugin = (summary) =>
        [pluginLabel, summary].filter(Boolean).join(" · ");

      const outputOptions = [
        form.elements.generate_seo_slug?.value === "1"
          ? translate("SEO URL")
          : "",
        form.elements.generate_toc?.value === "1"
          ? translate("Table of contents")
          : "",
      ].filter(Boolean);

      if (settingsRow?.classList.contains("is-pro-locked")) {
        return withPlugin(
          [translate("Available with Pro"), ...outputOptions].join(" · ")
        );
      }

      const control = form.querySelector(
        "[data-aipkit-task-smart-seo-main-toggle]"
      );
      const enabled = control
        ? control.type === "checkbox"
          ? control.checked
          : control.value === "1"
        : false;
      if (!enabled) {
        return withPlugin(
          outputOptions.length ? outputOptions.join(" · ") : translate("Off")
        );
      }

      const approach = asText(
        form.querySelector("[data-aipkit-smart-seo-trigger-value]")?.textContent
      );
      return withPlugin(
        [approach || translate("On"), ...outputOptions].join(" · ")
      );
    };

    const summarizePostContent = () => {
      const type = taskTypeSelect?.value || "";
      const promptScope =
        type === "enhance_existing_content" ? "rewrite" : "writing";
      const customInstructions = Array.from(
        form.querySelectorAll(
          `[data-aipkit-inline-prompts="${promptScope}"] [data-aipkit-inline-prompt-item]`
        )
      ).filter((item) => {
        const controlName = item.dataset.aipkitVisibilityControl || "";
        if (controlName && !form.elements[controlName]?.checked) return false;
        const textarea = item.querySelector(
          "[data-aipkit-inline-prompt-textarea]"
        );
        const value = asText(textarea?.value);
        return Boolean(value) && value !== asText(textarea?.defaultValue);
      }).length;

      const instructionSummary = customInstructions
        ? `${customInstructions} ${
            customInstructions === 1
              ? translate("custom instruction")
              : translate("custom instructions")
          }`
        : translate("Default instructions");

      if (type === "enhance_existing_content") {
        const selectedFields = Array.from(
          form.querySelectorAll(
            '[data-aipkit-inline-prompts="rewrite"] [data-aipkit-content-field]'
          )
        )
          .filter((field) => {
            const controlName = field.dataset.aipkitVisibilityControl || "";
            return Boolean(controlName && form.elements[controlName]?.checked);
          })
          .map((field) =>
            asText(
              field.querySelector(".aipkit_autogpt_content_field_label")
                ?.textContent
            )
          )
          .filter(Boolean);

        return `${
          selectedFields.length
            ? selectedFields.join(", ")
            : translate("No fields selected")
        } · ${instructionSummary}`;
      }

      const additions = [
        ["generate_meta_description", translate("Meta description")],
        ["generate_focus_keyword", translate("Focus keyword")],
        ["generate_excerpt", translate("Excerpt")],
        ["generate_tags", translate("Tags")],
      ]
        .filter(([name]) => Boolean(form.elements[name]?.checked))
        .map(([, label]) => label);

      const additionSummary = additions.length
        ? additions.join(", ")
        : translate("Title + article");

      return `${additionSummary} · ${instructionSummary}`;
    };

    const getContextScope = () =>
      taskTypeSelect?.value?.startsWith("content_writing") ? "cw" : "ce";

    const getContextProvider = (scope) =>
      asText(
        form.querySelector(`#aipkit_task_${scope}_vector_store_provider`)?.value
      ) || "openai";

    const getContextSource = (scope, provider) => {
      if (provider === "openai") {
        return selectedOptions(
          form.querySelector(`#aipkit_task_${scope}_openai_vector_store_ids`)
        );
      }

      const fieldSuffix = {
        local: "local_store_id",
        pinecone: "pinecone_index_name",
        qdrant: "qdrant_collection_name",
        chroma: "chroma_collection_name",
      }[provider];
      if (!fieldSuffix) return [];
      const field = form.querySelector(
        `#aipkit_task_${scope}_${fieldSuffix}`
      );
      return asText(field?.value) ? [field] : [];
    };

    const summarizeContext = () => {
      const type = taskTypeSelect?.value || "";
      if (!type) return translate("Off");

      const scope = getContextScope();
      const enabled = form.querySelector(
        `#aipkit_task_${scope}_enable_vector_store`
      )?.checked;
      if (!enabled) return translate("Off");

      const provider = getContextProvider(scope);
      const providerName = {
        local: "Local",
        openai: "OpenAI",
        pinecone: "Pinecone",
        qdrant: "Qdrant",
        chroma: "Chroma",
      }[provider] || provider;
      const sources = getContextSource(scope, provider);
      if (!sources.length) {
        return `${providerName} · ${translate("Choose a source")}`;
      }
      if (provider === "openai") {
        return `${providerName} · ${sources.length} ${
          sources.length === 1
            ? translate("knowledge source")
            : translate("knowledge sources")
        }`;
      }
      return `${providerName} · ${displayValue(sources[0])}`;
    };

    const summarizeImages = () => {
      const mode = form.querySelector("#aipkit_task_cw_image_mode_control");
      if (!mode || mode.value === "off") return translate("Off");

      const modeName = {
        content: translate("Content"),
        featured: translate("Featured"),
        both: translate("Content and featured"),
      }[mode.value] || displayValue(mode, translate("Images"));
      const sourceName = displayValue(
        form.querySelector("#aipkit_task_cw_image_selection")
      );
      return [modeName, sourceName].filter(Boolean).join(" · ");
    };

    const summarizeSchedule = () => {
      if (!taskTypeSelect?.value) return translate("Not configured yet.");
      const status = form.elements.task_status?.value === "paused"
        ? translate("Paused")
        : translate("Active");
      const type = taskTypeSelect?.value || "";
      const frequency = displayValue(
        form.elements.task_frequency,
        translate("Not scheduled")
      );
      const details = getTaskDetails(form);
      const isOneTime = ["one_time", "one_time_preferred"].includes(
        details?.ui?.frequency_policy
      ) && form.elements.task_frequency?.value === "one-time";
      const when = isOneTime ? translate("One-time") : frequency;

      if (type.startsWith("content_writing")) {
        const postStatus = {
          draft: translate("Draft"),
          publish: translate("Publish"),
          pending: translate("Pending review"),
          private: translate("Private"),
        }[form.elements.post_status?.value] || translate("Draft");
        return `${status} · ${when} · ${postStatus}`;
      }

      return `${status} · ${when}`;
    };

    const updateSummaries = () => {
      const type = taskTypeSelect?.value || "";
      const sourceSummary = summarizeSource();
      const writingSummary = summarizeWriting();
      const seoSummary = summarizeSeo();
      const fieldsSummary = summarizePostContent();
      const contextSummary = summarizeContext();
      const imagesSummary = summarizeImages();
      const scheduleSummary = summarizeSchedule();
      const ui = getTaskDetails(form)?.ui || {};

      setElementText(
        builder,
        '[data-aipkit-builder-summary="content"]',
        sourceSummary
      );
      setElementText(
        builder,
        '[data-aipkit-builder-summary="ai"]',
        writingSummary
      );
      setElementText(
        builder,
        '[data-aipkit-builder-summary="seo"]',
        seoSummary
      );
      setElementText(
        builder,
        '[data-aipkit-builder-summary="fields"]',
        fieldsSummary
      );
      setElementText(
        builder,
        '[data-aipkit-builder-summary="context"]',
        contextSummary
      );
      setElementText(
        builder,
        '[data-aipkit-builder-summary="images"]',
        imagesSummary
      );
      setElementText(
        builder,
        '[data-aipkit-builder-summary="finish"]',
        scheduleSummary
      );

      const aiEmpty = builder.querySelector("[data-aipkit-ai-empty]");
      if (aiEmpty) aiEmpty.hidden = ui.supports_writing === true;
      if (contentSource) {
        contentSource.hidden = !type || state.contentStage !== "configure";
      }
      if (manualSourceAction) {
        manualSourceAction.hidden =
          type !== "content_writing_bulk" ||
          state.contentStage !== "configure";
      }

      syncSmartSeoControl(form);
    };

    const syncStepNumbers = () => {
      visibleSteps().forEach((key, index) => {
        setElementText(
          builder,
          `[data-aipkit-builder-step="${key}"] .aipkit_autogpt_builder_step_number`,
          String(index + 1)
        );
      });
    };

    const renderContentStage = () => {
      if (state.expanded !== "content") return;

      const stages = contentStages();
      if (!stages.includes(state.contentStage)) {
        state.contentStage = stages[stages.length - 1] || "intent";
      }

      const isConfigure = state.contentStage === "configure";
      if (contentQuestion) contentQuestion.hidden = false;
      if (selector) selector.hidden = false;
      if (contentSource) {
        contentSource.hidden =
          !isConfigure || !asText(taskTypeSelect?.value);
      }

      showFamily(isConfigure ? categorySelect?.value || "" : "");

      builder.dataset.contentStage = state.contentStage;
    };

    const renderSteps = () => {
      const applicableSteps = visibleSteps();
      const visible = new Set(applicableSteps);
      if (state.expanded && !visible.has(state.expanded)) {
        state.expanded = visible.has(state.current) ? state.current : null;
      }
      const presented = new Set(
        state.mode === "edit"
          ? applicableSteps
          : applicableSteps.filter(
              (key) =>
                key === state.current ||
                state.unlocked.has(key) ||
                state.completed.has(key)
            )
      );

      STEP_ORDER.forEach((key) => {
        const step = stepElement(key);
        if (!step) return;

        const relevant = visible.has(key);
        const isPresented = relevant && presented.has(key);
        step.hidden = !isPresented;
        if (!isPresented) return;

        const toggle = step.querySelector("[data-aipkit-builder-step-toggle]");
        const body = step.querySelector(".aipkit_autogpt_builder_step_body");
        const isCurrent = key === state.current;
        const isExpanded = key === state.expanded;
        const isUnlocked = state.mode === "edit" || state.unlocked.has(key);
        const isComplete = state.completed.has(key) && !isCurrent;
        const status = isCurrent
          ? "current"
          : isComplete
            ? "complete"
            : isUnlocked
              ? "available"
              : "locked";

        step.dataset.state = status;
        if (body) body.hidden = !isExpanded;
        if (toggle) {
          toggle.disabled = !isUnlocked;
          toggle.setAttribute("aria-expanded", isExpanded ? "true" : "false");
        }
      });

      builder.dataset.mode = state.mode;
      builder.dataset.currentStep = state.current;
      builder.dataset.taskType = taskTypeSelect?.value || "";
      renderContentStage();
      syncStepNumbers();

      const details = getTaskDetails(form);
      const locked = isLockedTask(details);
      const route = progressRoute();
      const currentIndex = Math.max(0, route.indexOf(currentProgressKey()));
      const currentStepNumber = currentIndex + 1;
      const totalSteps = Math.max(1, route.length);
      const progressLabel = `${translate("Step")} ${currentStepNumber} ${translate("of")} ${totalSteps}`;
      const progressPercent = (currentStepNumber / totalSteps) * 100;
      const isFinish = state.current === "finish";
      if (editorActions) editorActions.style.display = "grid";
      if (footerPrevious) {
        footerPrevious.hidden = false;
        footerPrevious.disabled = currentIndex === 0;
        footerPrevious.setAttribute(
          "aria-disabled",
          currentIndex === 0 ? "true" : "false"
        );
        footerPrevious.textContent = translate("← Previous");
      }
      if (footerNext) {
        const isDecision =
          state.current === "content" && state.contentStage !== "configure";
        footerNext.hidden = isFinish || isDecision;
        footerNext.dataset.upgrade = locked ? "1" : "0";
        const footerNextText = footerNext.querySelector(".aipkit_btn-text");
        if (footerNextText) {
          const currentStepIndex = visibleSteps().indexOf(state.current);
          const nextStep = visibleSteps()[currentStepIndex + 1] || "";
          footerNextText.textContent = locked
            ? translate("Upgrade")
            : footerActionLabel(nextStep);
        }
        footerNext.classList.toggle("aipkit_pro_upgrade_button", locked);
      }
      if (footerProgressTrack) {
        footerProgressTrack.setAttribute("aria-valuemax", String(totalSteps));
        footerProgressTrack.setAttribute("aria-valuenow", String(currentStepNumber));
        footerProgressTrack.setAttribute("aria-valuetext", progressLabel);
      }
      if (footerProgressFill) {
        footerProgressFill.style.width = `${progressPercent}%`;
      }
      const cancelEditButton = builder.querySelector("#aipkit_cancel_edit_task_btn");
      if (cancelEditButton) cancelEditButton.hidden = state.mode !== "edit";

      if (saveButtonText) {
        saveButtonText.textContent =
          state.mode === "edit"
            ? translate("Save changes")
            : translate("Save task");
      }
      if (saveButton) {
        saveButton.dataset.action = "save";
        saveButton.hidden = locked || !isFinish;
      }

      updateSummaries();
      syncQuickCreateState();
    };

    const focusCurrentStep = (scrollPosition = null) => {
      const step = stepElement(state.current);
      if (!step) return;
      const header = step.querySelector(".aipkit_autogpt_builder_step_header");
      window.requestAnimationFrame(() => {
        header?.focus({ preventScroll: true });
        if (scrollPosition) {
          window.scrollTo(scrollPosition.left, scrollPosition.top);
        }
      });
    };

    const openStep = (key, { focus = true } = {}) => {
      if (!visibleSteps().includes(key)) return;
      if (state.mode !== "edit" && !state.unlocked.has(key)) return;
      const scrollPosition = {
        left: window.scrollX,
        top: window.scrollY,
      };
      state.current = key;
      state.expanded = key;
      clearErrors();
      renderSteps();
      if (focus) focusCurrentStep(scrollPosition);
    };

    const toggleStep = (key) => {
      if (!visibleSteps().includes(key)) return;
      if (state.mode !== "edit" && !state.unlocked.has(key)) return;

      // Editing should behave like direct navigation: selecting any completed
      // step makes it the current step so its footer actions and validation
      // state stay in sync. Previously this only expanded the panel, leaving
      // the footer anchored to the old step and keeping "Save changes" hidden
      // even after the Finish header was selected.
      if (state.mode === "edit" && state.current !== key) {
        openStep(key);
        return;
      }

      state.expanded = state.expanded === key ? null : key;
      clearErrors();
      renderSteps();
    };

    const showFamily = (category = "") => {
      if (!selector) return;
      const familyGrid = selector.querySelector("[data-aipkit-autogpt-family-grid]");
      const groups = selector.querySelectorAll("[data-aipkit-autogpt-task-choices]");
      const showingTasks = Boolean(category);

      selector.dataset.view = showingTasks ? "configured" : "families";
      if (familyGrid) familyGrid.hidden = false;
      groups.forEach((group) => {
        group.hidden = group.dataset.aipkitAutogptTaskChoices !== category;
      });
      setTypeQuestion(false);
    };

    const syncIntentSelection = () => {
      if (!selector) return;
      const selectedType = taskTypeSelect?.value || "";
      const selectedCategory = categorySelect?.value || "";
      selector.querySelectorAll("[data-aipkit-autogpt-family]").forEach((family) => {
        const directType = family.dataset.aipkitAutogptDirectTaskType || "";
        const isActive = directType
          ? directType === selectedType
          : Boolean(selectedType) &&
            family.dataset.aipkitAutogptFamily === selectedCategory;
        family.classList.toggle("is-active", isActive);
        family.setAttribute("aria-pressed", isActive ? "true" : "false");
        family
          .closest("[data-aipkit-autogpt-family-item]")
          ?.classList.toggle("is-active", isActive);
      });
      selector
        .querySelectorAll("[data-aipkit-autogpt-family-slot]")
        .forEach((slot) => {
          slot.hidden = slot.dataset.aipkitAutogptFamilySlot !== selectedCategory;
        });
      const activeSlot = selectedCategory
        ? selector.querySelector(
            `[data-aipkit-autogpt-family-slot="${CSS.escape(selectedCategory)}"]`
          )
        : null;
      if (
        activeSlot &&
        contentSource &&
        contentSource.parentElement !== activeSlot
      ) {
        activeSlot.appendChild(contentSource);
      }
    };

    const syncIntentSelector = () => {
      const category = categorySelect?.value || "";
      const directIntent = category
        ? selector?.querySelector(
            `[data-aipkit-autogpt-family="${CSS.escape(category)}"][data-aipkit-autogpt-direct-task-type]`
          )
        : null;
      showFamily(taskTypeSelect?.value || directIntent ? category : "");
      syncIntentSelection();
    };

    const applyTypeDefaults = () => {
      if (state.mode === "edit") return;
      const type = taskTypeSelect?.value || "";
      const details = getTaskDetails(form);
      if (!type || state.defaultedTypes.has(type)) return;

      const frequency = form.elements.task_frequency;
      if (
        frequency &&
        ["one_time", "one_time_preferred"].includes(details?.ui?.frequency_policy)
      ) {
        frequency.value = "one-time";
        frequency.dispatchEvent(new Event("change", { bubbles: true }));
      }

      if (form.elements.task_status) {
        form.elements.task_status.value = "active";
      }
      if (typeof window.aipkit_syncTaskStatusToggle === "function") {
        window.aipkit_syncTaskStatusToggle();
      }

      if (type === "enhance_existing_content") {
        const queueNow = form.elements.ce_enhance_existing_now_flag;
        if (queueNow) queueNow.checked = false;
      }

      if (type === "community_reply_comments") {
        if (form.elements.reply_action) form.elements.reply_action.value = "hold";
        if (form.elements.no_reply_to_replies) {
          form.elements.no_reply_to_replies.checked = true;
        }
      }

      if (type === "content_indexing") {
        if (form.elements.index_existing_now_flag) {
          form.elements.index_existing_now_flag.checked = true;
        }
        if (form.elements.only_new_updated_flag) {
          form.elements.only_new_updated_flag.checked = true;
        }
      }

      state.defaultedTypes.add(type);
    };

    const syncTaskPolicy = () => {
      const details = getTaskDetails(form);
      setElementText(
        builder,
        "[data-aipkit-builder-source-title]",
        details?.ui?.source_title || translate("Add a source")
      );
      setElementText(
        builder,
        "[data-aipkit-builder-source-description]",
        details?.ui?.source_description || ""
      );
      setElementText(
        builder,
        "[data-aipkit-builder-writing-title]",
        details?.ui?.writing_title || translate("AI")
      );
      setElementText(
        builder,
        "[data-aipkit-builder-context-title]",
        details?.ui?.context_title ||
          translate("Should we ground the writing in your own knowledge?")
      );
      setElementText(
        builder,
        "[data-aipkit-builder-schedule-title]",
        details?.ui?.schedule_title || translate("How should it run?")
      );

      const type = taskTypeSelect?.value || "";
      contentFieldSections.forEach((section) => {
        const sectionType = section.dataset.aipkitAutogptContentFields || "";
        section.hidden = sectionType === "content_writing"
          ? !type.startsWith("content_writing")
          : sectionType !== type;
      });

      const locked = isLockedTask(details);
      const sourceNext = builder.querySelector(
        '[data-aipkit-builder-step="source"] [data-aipkit-builder-next]'
      );
      if (sourceNext) {
        sourceNext.textContent = locked
          ? translate("Upgrade")
          : translate("Continue");
        sourceNext.dataset.upgrade = locked ? "1" : "0";
        sourceNext.classList.toggle("aipkit_pro_upgrade_button", locked);
      }

      applyTypeDefaults();
      syncIntentSelector();
      renderSteps();
    };

    const sourceValidation = () => {
      const type = taskTypeSelect?.value || "";
      const texts = (window.aipkit_automated_tasks_config || {}).text || {};

      if (type === "content_writing_bulk" && !countManualTopics(form.elements.content_title_bulk?.value)) {
        return texts.content_title_required_cw_task || translate("Please add at least one topic.");
      }
      if (type === "content_writing_csv" && !asText(form.elements.content_title?.value)) {
        return texts.csv_required_cw_task || translate("Please upload a CSV file.");
      }
      if (type === "content_writing_rss" && !asText(form.elements.rss_feeds?.value)) {
        return texts.rss_required_cw_task || translate("Please add at least one RSS feed.");
      }
      if (type === "content_writing_url" && !asText(form.elements.url_list?.value)) {
        return texts.url_required_cw_task || translate("Please add at least one URL.");
      }
      if (type === "content_writing_gsheets") {
        if (!asText(form.elements.gsheets_sheet_id?.value)) {
          return texts.gsheets_id_required_cw_task || translate("Please add a Google Sheet ID.");
        }
        if (!asText(form.elements.gsheets_credentials?.value)) {
          return texts.gsheets_credentials_required_cw_task || translate("Please add Google Sheets credentials.");
        }
      }
      if (type === "content_indexing") {
        if (!selectedOptions(form.querySelector("#aipkit_task_content_indexing_post_types")).length) {
          return texts.content_type_required || translate("Please select at least one content type.");
        }
        if (!asText(form.elements.target_store_id?.value)) {
          return texts.target_store_required || translate("Please select a destination.");
        }
        if (
          !form.elements.index_existing_now_flag?.checked &&
          !form.elements.only_new_updated_flag?.checked
        ) {
          return translate("Choose whether to index existing content, keep future content in sync, or both.");
        }
        const provider = form.elements.target_store_provider?.value;
        if (["pinecone", "qdrant", "chroma", "local"].includes(provider)) {
          const [embeddingProvider, embeddingModel] = asText(
            form.elements.embedding_model?.value
          ).split("::", 2);
          if (!embeddingProvider || !embeddingModel) {
            return texts.embedding_model_required || translate("Please select an embedding model.");
          }
        }
      }
      if (type === "enhance_existing_content") {
        if (!selectedOptions(form.querySelector("#aipkit_task_ce_post_types")).length) {
          return translate("Please select at least one content type to rewrite.");
        }
      }
      if (type === "community_reply_comments") {
        if (!selectedOptions(form.querySelector("#aipkit_task_comment_reply_post_types")).length) {
          return texts.comment_post_type_required || translate("Please select at least one content type to monitor.");
        }
      }
      return "";
    };

    const writingValidation = () => {
      const type = taskTypeSelect?.value || "";
      const texts = (window.aipkit_automated_tasks_config || {}).text || {};

      if (type.startsWith("content_writing")) {
        if (!asText(form.elements.ai_provider?.value) || !asText(form.elements.ai_model?.value)) {
          return texts.ai_config_required_cw_task || translate("Please select an AI model.");
        }
        if (!asText(form.elements.custom_content_prompt?.value)) {
          return translate("The content prompt cannot be empty.");
        }
      }
      if (type === "community_reply_comments") {
        if (!asText(form.elements.cc_ai_provider?.value) || !asText(form.elements.cc_ai_model?.value)) {
          return texts.comment_ai_config_required || translate("Please select an AI model.");
        }
        if (!asText(form.elements.cc_custom_content_prompt?.value)) {
          return texts.comment_prompt_required || translate("The reply prompt cannot be empty.");
        }
      }
      if (type === "enhance_existing_content") {
        if (!asText(form.elements.ce_ai_provider?.value) || !asText(form.elements.ce_ai_model?.value)) {
          return translate("Please select an AI model.");
        }
      }
      return "";
    };

    const contentFieldsValidation = () => {
      if (
        taskTypeSelect?.value === "enhance_existing_content" &&
        !form.querySelector('[name^="ce_update_"]:checked')
      ) {
        return translate("Choose at least one field to update.");
      }
      return "";
    };

    const contextValidation = () => {
      const type = taskTypeSelect?.value || "";
      if (
        !type.startsWith("content_writing") &&
        type !== "enhance_existing_content"
      ) {
        return "";
      }

      const scope = getContextScope();
      const enabled = form.querySelector(
        `#aipkit_task_${scope}_enable_vector_store`
      )?.checked;
      if (!enabled) return "";

      const provider = getContextProvider(scope);
      if (!getContextSource(scope, provider).length) {
        return (
          (window.aipkit_automated_tasks_config || {}).text
            ?.context_source_required ||
          translate("Please select a knowledge source before enabling context.")
        );
      }

      if (["pinecone", "qdrant", "chroma", "local"].includes(provider)) {
        const embeddingProvider = form.querySelector(
          `#aipkit_task_${scope}_vector_embedding_provider`
        );
        const embeddingModel = form.querySelector(
          `#aipkit_task_${scope}_vector_embedding_model`
        );
        if (!asText(embeddingProvider?.value)) {
          return translate("Please select an embedding provider.");
        }
        if (!asText(embeddingModel?.value)) {
          return translate("Please select an embedding model.");
        }
      }

      return "";
    };

    syncQuickCreateState = () => {
      const showQuickCreate =
        state.mode === "create" && state.current !== "finish";
      const isSubmitting = Boolean(
        window.aipkit_automated_tasks_form_state?.isSubmitting
      );

      if (quickCreateButton) {
        quickCreateButton.hidden = !showQuickCreate;
        quickCreateButton.disabled = isSubmitting;
        quickCreateButton.dataset.action = "save";
        quickCreateButton.setAttribute(
          "aria-disabled",
          isSubmitting ? "true" : "false"
        );
      }

    };

    const quickCreateIssue = () => {
      const type = taskTypeSelect?.value || "";
      if (!type) {
        return {
          step: "content",
          message: translate("Choose an automation to continue."),
        };
      }

      const sourceMessage = sourceValidation();
      if (sourceMessage) {
        return { step: "content", message: sourceMessage };
      }

      const writingMessage = writingValidation();
      if (writingMessage) {
        return { step: "ai", message: writingMessage };
      }

      const credentialIssue =
        window.aipkit_autogpt_provider_setup?.getTaskCredentialIssue?.(form);
      if (credentialIssue) {
        return credentialIssue;
      }

      const contentFieldsMessage = contentFieldsValidation();
      if (contentFieldsMessage) {
        return { step: "fields", message: contentFieldsMessage };
      }

      const contextMessage = contextValidation();
      if (contextMessage) {
        return { step: "knowledge", message: contextMessage };
      }

      return null;
    };

    const revealQuickCreateIssue = ({ step, message }) => {
      clearErrors();
      state.completed.delete(step);
      state.unlocked.add(step);
      state.current = step;
      state.expanded = step;
      if (step === "content") {
        state.contentStage = taskTypeSelect?.value ? "configure" : "intent";
      }
      setError(step, message);
      renderSteps();

      window.requestAnimationFrame(() => {
        const error = builder.querySelector(
          `[data-aipkit-builder-error="${step}"]`
        );
        error?.focus?.();
      });
    };

    const validationMessageForStep = (key) => {
      let message = "";
      const credentialIssue =
        window.aipkit_autogpt_provider_setup?.getTaskCredentialIssue?.(form);
      if (key === "content" && !taskTypeSelect?.value) {
        message = translate("Choose an automation to continue.");
      } else if (key === "content") {
        message = sourceValidation();
      } else if (key === "ai") {
        message = writingValidation();
      } else if (key === "fields") {
        message = contentFieldsValidation();
      } else if (key === "knowledge") {
        message = contextValidation();
      } else if (key === "finish") {
        message =
          sourceValidation() ||
          writingValidation() ||
          contentFieldsValidation() ||
          contextValidation() ||
          credentialIssue?.message ||
          "";
      }

      if (!message && credentialIssue?.step === key) {
        message = credentialIssue.message;
      }

      return message;
    };

    const syncVisibleStepErrors = () => {
      STEP_ORDER.forEach((key) => {
        const error = builder.querySelector(
          `[data-aipkit-builder-error="${key}"]`
        );
        if (!asText(error?.textContent)) return;
        const nextMessage = validationMessageForStep(key);
        if (asText(error.textContent) !== nextMessage) {
          setError(key, nextMessage);
        }
      });
    };

    const validateStep = (key) => {
      const message = validationMessageForStep(key);

      setError(key, message);
      if (message) {
        const error = builder.querySelector(`[data-aipkit-builder-error="${key}"]`);
        error?.focus?.();
        return false;
      }
      return true;
    };

    const validateEarlierSteps = () => {
      const steps = visibleSteps();
      const currentIndex = steps.indexOf(state.current);
      if (currentIndex <= 0) return true;

      for (const key of steps.slice(0, currentIndex)) {
        if (validateStep(key)) continue;
        state.completed.delete(key);
        state.expanded = key;
        renderSteps();
        return false;
      }
      return true;
    };

    const goNext = (button) => {
      const details = getTaskDetails(form);
      if (button.dataset.upgrade === "1" || isLockedTask(details)) {
        const upgradeUrl = window.aipkit_dashboard?.upgradeUrl;
        if (upgradeUrl) window.open(upgradeUrl, "_blank", "noopener");
        setError(
          state.current,
          translate("This automation is available on the Pro plan.")
        );
        return;
      }

      const reviewStep =
        state.expanded && state.expanded !== state.current
          ? state.expanded
          : "";
      if (reviewStep) {
        if (!validateStep(reviewStep)) {
          state.completed.delete(reviewStep);
          renderSteps();
          return;
        }
        state.completed.add(reviewStep);
        state.expanded = state.current;
        clearErrors();
        renderSteps();
        focusCurrentStep();
        return;
      }

      if (!validateEarlierSteps()) return;
      if (!validateStep(state.current)) return;
      state.completed.add(state.current);

      const steps = visibleSteps();
      const currentIndex = steps.indexOf(state.current);
      const next = steps[currentIndex + 1];
      if (!next) return;
      state.unlocked.add(next);
      openStep(next);
    };

    const goPrevious = () => {
      if (state.current === "content") {
        const stages = contentStages();
        const stageIndex = stages.indexOf(state.contentStage);
        if (stageIndex > 0) {
          state.contentStage = stages[stageIndex - 1];
          clearErrors();
          renderSteps();
        }
        return;
      }

      const steps = visibleSteps();
      const currentIndex = steps.indexOf(state.current);
      const previous = steps[currentIndex - 1];
      if (!previous) return;
      state.unlocked.add(previous);
      if (previous === "content") {
        state.contentStage = "configure";
      }
      openStep(previous);
    };

    const reset = ({ isEditing = false } = {}) => {
      state.mode = isEditing ? "edit" : "create";
      state.completed = new Set();
      state.unlocked = new Set(isEditing ? STEP_ORDER : ["content"]);
      state.expanded = "content";
      state.defaultedTypes = new Set();
      state.selectedType = taskTypeSelect?.value || "";
      state.contentStage = taskTypeSelect?.value ? "configure" : "intent";

      if (isEditing) {
        visibleSteps().forEach((key) => state.completed.add(key));
        state.current = "content";
        syncIntentSelector();
      } else {
        state.current = "content";
        showFamily(taskTypeSelect?.value ? categorySelect?.value || "" : "");
      }

      clearErrors();
      enhanceMultiSelects(form);
      enhanceSegmentedChoices(form);
      enhanceTemperatureSliders(form);
      enhanceReasoningSliders(form);
      enhanceConfidenceSliders(form);
      enhanceResultLimitSteppers(form);
      enhanceSmartSeoControl(form);
      syncTaskPolicy();
      renderSteps();
      window.setTimeout(updateSummaries, 160);
    };

    builder.addEventListener("click", (event) => {
      const quickCreate = event.target.closest(
        "[data-aipkit-builder-quick-create]"
      );
      if (quickCreate) {
        event.preventDefault();
        if (quickCreate.disabled || !saveButton) return;

        const details = getTaskDetails(form);
        if (isLockedTask(details)) {
          const upgradeUrl = window.aipkit_dashboard?.upgradeUrl;
          if (upgradeUrl) window.open(upgradeUrl, "_blank", "noopener");
          setError(
            state.current,
            translate("This automation is available on the Pro plan.")
          );
          return;
        }

        const issue = quickCreateIssue();
        if (issue) {
          revealQuickCreateIssue(issue);
          return;
        }

        form.dataset.aipkitQuickCreateSubmitting = "1";
        if (typeof form.requestSubmit === "function") {
          form.requestSubmit(saveButton);
        } else {
          saveButton.click();
        }
        return;
      }

      const family = event.target.closest("[data-aipkit-autogpt-family]");
      if (family) {
        const category = family.dataset.aipkitAutogptFamily || "";
        const directType =
          family.dataset.aipkitAutogptDirectTaskType || "";
        const defaultType =
          family.dataset.aipkitAutogptDefaultTaskType || "";
        if (categorySelect && category) {
          categorySelect.value = category;
          categorySelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (directType && taskTypeSelect) {
          taskTypeSelect.value = directType;
          taskTypeSelect.dispatchEvent(new Event("change", { bubbles: true }));
          state.contentStage = "configure";
          syncIntentSelection();
          renderSteps();
          return;
        }
        if (defaultType && taskTypeSelect) {
          taskTypeSelect.value = defaultType;
          taskTypeSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
        state.contentStage = taskTypeSelect?.value ? "configure" : "intent";
        syncIntentSelection();
        renderSteps();
        return;
      }

      const sourceOption = event.target.closest("[data-task-type]");
      if (sourceOption && sourceOption.closest("[data-aipkit-autogpt-task-choices]")) {
        state.contentStage = "configure";
        clearErrors();
        window.requestAnimationFrame(() => renderSteps());
        return;
      }

      const toggle = event.target.closest("[data-aipkit-builder-step-toggle]");
      if (toggle) {
        toggleStep(toggle.dataset.aipkitBuilderStepToggle || "");
        return;
      }

      const next = event.target.closest("[data-aipkit-builder-next]");
      if (next) {
        goNext(next);
        return;
      }

      if (event.target.closest("[data-aipkit-builder-footer-next]")) {
        goNext(event.target.closest("[data-aipkit-builder-footer-next]"));
        return;
      }

      if (
        event.target.closest("[data-aipkit-builder-previous]") ||
        event.target.closest("[data-aipkit-builder-footer-previous]")
      ) {
        goPrevious();
        return;
      }

    });

    const handleFormUpdate = () => {
      syncVisibleStepErrors();
      updateSummaries();
      syncQuickCreateState();
    };
    form.addEventListener("input", handleFormUpdate);
    form.addEventListener("change", handleFormUpdate);

    taskTypeSelect?.addEventListener("change", () => {
      const nextType = taskTypeSelect.value || "";
      if (state.mode === "create" && state.selectedType !== nextType) {
        state.completed = new Set();
        state.unlocked = new Set(["content"]);
        state.expanded = "content";
        state.defaultedTypes.delete(nextType);
        state.current = "content";
        clearErrors();
      }
      state.selectedType = nextType;
      syncTaskPolicy();
      enhanceMultiSelects(form);
      enhanceSegmentedChoices(form);
      enhanceTemperatureSliders(form);
      enhanceReasoningSliders(form);
      enhanceConfidenceSliders(form);
      enhanceResultLimitSteppers(form);
      enhanceSmartSeoControl(form);
    });

    const cancelButton = builder.querySelector("#aipkit_cancel_edit_task_btn");
    if (cancelButton) {
      cancelButton.addEventListener("click", () => {
        window.setTimeout(() => reset({ isEditing: false }), 0);
      });
    }

    enhanceMultiSelects(form);
    enhanceSegmentedChoices(form);
    enhanceTemperatureSliders(form);
    enhanceReasoningSliders(form);
    enhanceConfidenceSliders(form);
    enhanceResultLimitSteppers(form);
    enhanceSmartSeoControl(form);
    renderSteps();

    return {
      reset,
      sync: syncTaskPolicy,
      updateSummaries,
      openStep,
      revealIssue: revealQuickCreateIssue,
    };
  }

  function aipkit_initAutogptProgressiveBuilder(root = document) {
    const builder = root.querySelector?.("[data-aipkit-autogpt-builder]");
    if (!builder) return null;
    if (!builder._aipkitProgressiveController) {
      builder._aipkitProgressiveController = createBuilderController(builder);
    }
    window.aipkit_autogpt_progressive_builder =
      builder._aipkitProgressiveController;
    return builder._aipkitProgressiveController;
  }

  function aipkit_resetAutogptProgressiveBuilder(options = {}) {
    const controller =
      window.aipkit_autogpt_progressive_builder ||
      aipkit_initAutogptProgressiveBuilder(document);
    controller?.reset(options);
  }

  window.aipkit_initAutogptProgressiveBuilder =
    aipkit_initAutogptProgressiveBuilder;
  window.aipkit_resetAutogptProgressiveBuilder =
    aipkit_resetAutogptProgressiveBuilder;
})();
