/**
 * AIPKit Settings - Security UI
 */
(function () {
  "use strict";

  function parseEntries(value) {
    return String(value || "")
      .split(/[,\r\n]+/)
      .map(function (entry) {
        return entry.trim();
      })
      .filter(Boolean);
  }

  function uniqueEntries(entries, caseInsensitive) {
    var seen = new Set();

    return entries.filter(function (entry) {
      var comparisonValue = caseInsensitive ? entry.toLowerCase() : entry;
      if (seen.has(comparisonValue)) {
        return false;
      }
      seen.add(comparisonValue);
      return true;
    });
  }

  function isValidIpAddress(value) {
    if (value.includes(":")) {
      try {
        var parsedUrl = new URL("http://[" + value + "]/");
        return parsedUrl.hostname !== "";
      } catch (error) {
        return false;
      }
    }

    var octets = value.split(".");
    if (octets.length !== 4) {
      return false;
    }

    return octets.every(function (octet) {
      return (
        /^(0|[1-9]\d{0,2})$/.test(octet) &&
        Number(octet) >= 0 &&
        Number(octet) <= 255
      );
    });
  }

  function formatTemplate(template, value) {
    return String(template || "").replace("%s", function () {
      return String(value);
    });
  }

  function requestAutosave() {
    if (typeof window.aipkit_handleAutoSave === "function") {
      window.aipkit_handleAutoSave();
    }
  }

  function initSecurityChipEditor(editor) {
    if (!editor || editor.dataset.aipkitSecurityChipEditorBound === "true") {
      return;
    }

    var type = editor.dataset.aipkitSecurityChipEditor || "";
    var field = editor.closest(".aipkit_settings_security_field");
    var source = field
      ? field.querySelector(".aipkit_settings_security_source")
      : null;
    var chipList = editor.querySelector("[data-aipkit-security-chip-list]");
    var input = editor.querySelector(".aipkit_settings_security_chip_input");
    var errorMessage = field
      ? field.querySelector(".aipkit_settings_security_error")
      : null;

    if (!field || !source || !chipList || !input || !type) {
      return;
    }

    var isIpEditor = type === "ips";
    var validItems = uniqueEntries(
      parseEntries(source.value).map(function (entry) {
        return isIpEditor ? entry : entry.toLowerCase();
      }),
      !isIpEditor
    );
    var invalidItems = [];

    function syncSourceAndSave() {
      var nextValue = validItems.join(",");
      if (source.value === nextValue) {
        return;
      }

      source.value = nextValue;
      requestAutosave();
    }

    function createChip(value, invalid, index) {
      var chip = document.createElement("span");
      chip.className =
        "aipkit_settings_security_chip" +
        (invalid ? " aipkit_settings_security_chip--invalid" : "");

      var label = document.createElement("span");
      label.className = "aipkit_settings_security_chip_label";
      label.textContent = value;

      var removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "aipkit_settings_security_chip_remove";
      removeButton.dataset.aipkitSecurityChipKind = invalid
        ? "invalid"
        : "valid";
      removeButton.dataset.aipkitSecurityChipIndex = String(index);
      removeButton.setAttribute(
        "aria-label",
        formatTemplate(editor.dataset.removeLabel || "Remove %s", value)
      );
      removeButton.textContent = "×";

      chip.appendChild(label);
      chip.appendChild(removeButton);
      return chip;
    }

    function updateValidationMessage() {
      if (!errorMessage) {
        return;
      }

      if (!invalidItems.length) {
        errorMessage.hidden = true;
        errorMessage.textContent = "";
        input.setAttribute("aria-invalid", "false");
        return;
      }

      var template =
        invalidItems.length === 1
          ? editor.dataset.invalidIpMessage ||
            "%s is not a valid IP address."
          : editor.dataset.invalidIpCountMessage ||
            "%s entries are not valid IP addresses.";
      var templateValue =
        invalidItems.length === 1 ? invalidItems[0] : invalidItems.length;

      errorMessage.textContent = formatTemplate(template, templateValue);
      errorMessage.hidden = false;
      input.setAttribute("aria-invalid", "true");
    }

    function render() {
      chipList.innerHTML = "";

      validItems.forEach(function (value, index) {
        chipList.appendChild(createChip(value, false, index));
      });
      invalidItems.forEach(function (value, index) {
        chipList.appendChild(createChip(value, true, index));
      });

      editor.classList.toggle(
        "aipkit_settings_security_chip_editor--empty",
        validItems.length === 0 && invalidItems.length === 0
      );
      updateValidationMessage();
    }

    function addEntries(rawValue) {
      var candidates = parseEntries(rawValue);
      if (!candidates.length) {
        input.value = "";
        return;
      }

      var previousValidValue = validItems.join(",");

      candidates.forEach(function (candidate) {
        var normalized = isIpEditor ? candidate : candidate.toLowerCase();
        if (isIpEditor && !isValidIpAddress(normalized)) {
          if (!invalidItems.includes(normalized)) {
            invalidItems.push(normalized);
          }
          return;
        }

        var alreadyExists = validItems.some(function (item) {
          return isIpEditor
            ? item === normalized
            : item.toLowerCase() === normalized.toLowerCase();
        });
        if (!alreadyExists) {
          validItems.push(normalized);
        }
      });

      input.value = "";
      render();

      if (validItems.join(",") !== previousValidValue) {
        syncSourceAndSave();
      }
    }

    function removeEntry(kind, index) {
      if (kind === "invalid") {
        invalidItems.splice(index, 1);
        render();
        input.focus();
        return;
      }

      var previousValue = validItems.join(",");
      validItems.splice(index, 1);
      render();
      if (validItems.join(",") !== previousValue) {
        syncSourceAndSave();
      }
      input.focus();
    }

    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter" || event.key === ",") {
        event.preventDefault();
        addEntries(input.value);
        return;
      }

      if (
        event.key === "Backspace" &&
        input.value === "" &&
        (invalidItems.length || validItems.length)
      ) {
        event.preventDefault();
        if (invalidItems.length) {
          removeEntry("invalid", invalidItems.length - 1);
        } else {
          removeEntry("valid", validItems.length - 1);
        }
      }
    });

    input.addEventListener("blur", function () {
      addEntries(input.value);
    });

    input.addEventListener("paste", function (event) {
      var pastedText = event.clipboardData
        ? event.clipboardData.getData("text")
        : "";
      if (!pastedText || !/[,\r\n]/.test(pastedText)) {
        return;
      }

      event.preventDefault();
      addEntries(pastedText);
    });

    editor.addEventListener("click", function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      var removeButton = target.closest(
        ".aipkit_settings_security_chip_remove"
      );
      if (removeButton) {
        removeEntry(
          removeButton.dataset.aipkitSecurityChipKind || "valid",
          Number(removeButton.dataset.aipkitSecurityChipIndex || "0")
        );
        return;
      }

      if (target === editor || target === chipList) {
        input.focus();
      }
    });

    source.value = validItems.join(",");
    render();
    editor.dataset.aipkitSecurityChipEditorBound = "true";
  }

  function initSecuritySettingsUI() {
    var security = document.getElementById("aipkit_settings_security");
    if (!security) {
      return;
    }

    security
      .querySelectorAll("[data-aipkit-security-chip-editor]")
      .forEach(initSecurityChipEditor);
  }

  window.aipkit_initSecuritySettingsUI = initSecuritySettingsUI;
})();
