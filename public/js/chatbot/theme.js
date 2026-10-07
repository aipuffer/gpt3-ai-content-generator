/**
 * AIPKit Public Chat - Apply Custom Accent
 *
 * Custom and named palette themes provide one color input. The widget's
 * structural surfaces stay neutral; only accent touchpoints are derived here.
 */
(function () {
  "use strict";

  const DIRECT_KEYS = new Set(["font_family", "bubble_border_radius"]);
  const DIMENSION_KEYS = new Set([
    "container_max_width",
    "popup_width",
    "container_height",
    "container_min_height",
    "container_max_height",
    "popup_height",
    "popup_min_height",
    "popup_max_height",
  ]);
  const DEFAULT_PRIMARY = "#0B5FFF";
  const DEFAULT_LEGACY_ACCENT = "#111111";

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function parseColor(value) {
    if (typeof value !== "string") return null;
    const normalized = value.trim().toLowerCase();

    if (/^#[0-9a-f]{3}$/i.test(normalized)) {
      const hex = normalized.slice(1);
      return {
        r: parseInt(hex[0] + hex[0], 16),
        g: parseInt(hex[1] + hex[1], 16),
        b: parseInt(hex[2] + hex[2], 16),
      };
    }

    if (/^#[0-9a-f]{6}$/i.test(normalized)) {
      const number = parseInt(normalized.slice(1), 16);
      return {
        r: (number >> 16) & 255,
        g: (number >> 8) & 255,
        b: number & 255,
      };
    }

    const rgbMatch = normalized.match(
      /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+)?\s*\)$/
    );
    if (!rgbMatch) return null;

    return {
      r: clamp(Number(rgbMatch[1]), 0, 255),
      g: clamp(Number(rgbMatch[2]), 0, 255),
      b: clamp(Number(rgbMatch[3]), 0, 255),
    };
  }

  function colorsEqual(colorA, colorB) {
    return !!(
      colorA &&
      colorB &&
      colorA.r === colorB.r &&
      colorA.g === colorB.g &&
      colorA.b === colorB.b
    );
  }

  function toHex(color) {
    const channel = (value) =>
      clamp(Math.round(value), 0, 255).toString(16).padStart(2, "0");
    return `#${channel(color.r)}${channel(color.g)}${channel(color.b)}`;
  }

  function toRgb(color) {
    return `${Math.round(color.r)}, ${Math.round(color.g)}, ${Math.round(color.b)}`;
  }

  function toRgba(color, alpha) {
    return `rgba(${toRgb(color)}, ${clamp(alpha, 0, 1)})`;
  }

  function mix(colorA, colorB, colorBWeight) {
    const weight = clamp(colorBWeight, 0, 1);
    return {
      r: colorA.r * (1 - weight) + colorB.r * weight,
      g: colorA.g * (1 - weight) + colorB.g * weight,
      b: colorA.b * (1 - weight) + colorB.b * weight,
    };
  }

  function toLinear(channel) {
    const value = channel / 255;
    return value <= 0.03928
      ? value / 12.92
      : Math.pow((value + 0.055) / 1.055, 2.4);
  }

  function luminance(color) {
    return (
      0.2126 * toLinear(color.r) +
      0.7152 * toLinear(color.g) +
      0.0722 * toLinear(color.b)
    );
  }

  function getAccentColor(settings) {
    const primary = parseColor(settings.primary_color);
    const legacyAccent = parseColor(settings.accent_color);
    const defaultPrimary = parseColor(DEFAULT_PRIMARY);
    const defaultLegacyAccent = parseColor(DEFAULT_LEGACY_ACCENT);

    if (
      legacyAccent &&
      !colorsEqual(legacyAccent, defaultLegacyAccent) &&
      (!primary || colorsEqual(primary, defaultPrimary))
    ) {
      return legacyAccent;
    }

    return primary || legacyAccent || defaultPrimary;
  }

  function buildAccentSettings(settings) {
    const accent = getAccentColor(settings);
    const accentLuminance = luminance(accent);
    const darkText = { r: 26, g: 29, b: 35 };
    const white = { r: 255, g: 255, b: 255 };
    const onAccent = accentLuminance > 0.55 ? darkText : white;
    const accentHover = mix(
      accent,
      accentLuminance > 0.55 ? darkText : white,
      accentLuminance > 0.55 ? 0.08 : 0.12
    );
    const selectionColor = accentLuminance > 0.55 ? darkText : accent;

    return {
      accent_color: toHex(accent),
      accent_rgb: toRgb(accent),
      selection_rgb: toRgb(selectionColor),
      on_accent_color: toHex(onAccent),
      on_accent_rgb: toRgb(onAccent),
      accent_hover_color: toHex(accentHover),
      accent_shadow_color: toRgba(accent, 0.3),
      header_avatar_bg_color: toRgba(onAccent, 0.18),
      header_status_text_color: toRgba(onAccent, 0.85),
    };
  }

  function buildFinalSettings(settings, options = {}) {
    const finalSettings = buildAccentSettings(settings);

    function copySettings(keys) {
      keys.forEach((key) => {
        const value = settings[key];
        if (value !== "" && value !== null && value !== undefined) {
          finalSettings[key] = value;
        }
      });
    }

    copySettings(DIRECT_KEYS);

    const dimensions = options.skipDimensionSync
      ? [...DIMENSION_KEYS].filter(key => (options.dimensionOverrides || []).includes(key))
      : DIMENSION_KEYS;
    copySettings(dimensions);

    if (finalSettings.container_height !== undefined) {
      finalSettings.popup_height = finalSettings.container_height;
    }
    if (finalSettings.container_min_height !== undefined) {
      finalSettings.popup_min_height = finalSettings.container_min_height;
    }
    if (finalSettings.container_max_height !== undefined) {
      finalSettings.popup_max_height = finalSettings.container_max_height;
    }

    return finalSettings;
  }

  function formatSettingValue(key, value) {
    if (key === "container_max_height" || key === "popup_max_height") {
      return `${value}vh`;
    }
    if (
      key === "container_max_width" ||
      key === "popup_width" ||
      key === "container_height" ||
      key === "container_min_height" ||
      key === "popup_height" ||
      key === "popup_min_height"
    ) {
      return `${value}px`;
    }
    if (key === "bubble_border_radius" && /^\d+$/.test(String(value))) {
      return Number(value) === 0 ? "0" : `${value}px`;
    }
    return value;
  }

  function aipkit_chatUI_applyCustomThemeStyles(
    chatContainerEl,
    customThemeSettings,
    options = {}
  ) {
    if (!chatContainerEl || !customThemeSettings || typeof customThemeSettings !== "object") {
      return;
    }

    const finalSettings = buildFinalSettings(customThemeSettings, options);
    const applyTo = (element) => {
      if (!element) return;
      Object.entries(finalSettings).forEach(([key, value]) => {
        if (value === "" || value === null || value === undefined) return;
        element.style.setProperty(
          `--aipkit-chat-${key.replace(/_/g, "-")}`,
          formatSettingValue(key, value)
        );
      });
    };

    applyTo(chatContainerEl);
    applyTo(chatContainerEl.closest(".aipkit_popup_wrapper"));
  }

  window.aipkit_chatUI_applyCustomThemeStyles =
    aipkit_chatUI_applyCustomThemeStyles;
})();
