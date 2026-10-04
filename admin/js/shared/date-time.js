/** Admin scheduling, relative-date and model-sync labels. */
import "../../../public/js/shared/timestamp.js";
(function () {
  "use strict";

  /**
   * Formats a future datetime string (assumed UTC) into a human-friendly relative format.
   * e.g., "in 39 minutes", "Today at 5:30 PM", "Tomorrow at 8:00 AM"
   * @param {string|null|undefined} dateTimeStringUTC A UTC datetime string like 'YYYY-MM-DD HH:MM:SS'.
   * @returns {string} The formatted string, or an empty string if input is invalid.
   */
  function aipkit_formatFriendlyFutureDate(dateTimeStringUTC) {
    if (!dateTimeStringUTC) return "";
    try {
      const targetDate = new Date(dateTimeStringUTC.replace(" ", "T") + "Z");
      if (isNaN(targetDate.getTime())) return ""; // Invalid date string

      const now = new Date();
      const diffSeconds = (targetDate.getTime() - now.getTime()) / 1000;

      if (diffSeconds < 0) {
        // It's in the past, maybe the cron is running late.
        return "In the past";
      }
      if (diffSeconds < 60) {
        return "in less than a minute";
      }
      if (diffSeconds < 3600) {
        // Less than 1 hour
        const minutes = Math.round(diffSeconds / 60);
        return `in ${minutes} minute${minutes !== 1 ? "s" : ""}`;
      }

      // From here on, we're dealing with more than an hour away.
      // We can show absolute time for clarity.

      const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      const tomorrow = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate() + 1
      );
      const targetDay = new Date(
        targetDate.getFullYear(),
        targetDate.getMonth(),
        targetDate.getDate()
      );

      if (targetDay.getTime() === today.getTime()) {
        return `Today at ${targetDate.toLocaleTimeString([], {
          hour: "numeric",
          minute: "numeric",
        })}`;
      }

      if (targetDay.getTime() === tomorrow.getTime()) {
        return `Tomorrow at ${targetDate.toLocaleTimeString([], {
          hour: "numeric",
          minute: "numeric",
        })}`;
      }

      // For dates further in the future
      return `on ${targetDate.toLocaleDateString([], {
        month: "short",
        day: "numeric",
      })}`;
    } catch (e) {
      console.error("Error formatting relative time:", dateTimeStringUTC, e);
      return dateTimeStringUTC; // Fallback to original string
    }
  }

  /**
   * Formats a UTC datetime string into a locale-specific string.
   * @param {string|null|undefined} utcDateTimeString UTC datetime string (e.g., 'YYYY-MM-DD HH:MM:SS').
   * @returns {string} Formatted date/time string or 'N/A'.
   */
  function aipkit_formatUtcDateTime(utcDateTimeString) {
    if (!utcDateTimeString) return "N/A";
    try {
      // Append 'Z' to ensure the date is parsed as UTC
      const date = new Date(utcDateTimeString.replace(" ", "T") + "Z");
      // Check if the date object is valid
      if (isNaN(date.getTime())) return "Invalid Date";
      return date.toLocaleString(); // Use browser's locale for formatting
    } catch (e) {
      console.error("Error formatting UTC date string:", utcDateTimeString, e);
      return utcDateTimeString; // Fallback to original string on error
    }
  }

  /**
   * Formats a UTC datetime string into a human-friendly relative time label.
   * e.g., "Just now", "3 hours ago", "yesterday".
   * @param {string|null|undefined} utcDateTimeString UTC datetime string (e.g., 'YYYY-MM-DD HH:MM:SS').
   * @param {{numeric?: "auto"|"always"}} [options] Relative-time wording preference.
   * @returns {string} Formatted relative time string or fallback date.
   */
  function aipkit_formatRelativeDateTime(utcDateTimeString, options = {}) {
    if (!utcDateTimeString) return "N/A";
    try {
      const date = new Date(utcDateTimeString.replace(" ", "T") + "Z");
      if (isNaN(date.getTime())) {
        return "Invalid Date";
      }

      const diffSeconds = Math.floor((Date.now() - date.getTime()) / 1000);
      if (Number.isNaN(diffSeconds) || diffSeconds < 0) {
        return date.toLocaleString();
      }
      const numeric = options.numeric === "always" ? "always" : "auto";
      const rtf =
        typeof Intl !== "undefined" && Intl.RelativeTimeFormat
          ? new Intl.RelativeTimeFormat(undefined, { numeric })
          : null;
      if (diffSeconds < 60) {
        if (numeric === "always") {
          return rtf ? rtf.format(-0, "minute") : "0 minutes ago";
        }
        return "Just now";
      }

      const ranges = [
        { unit: "year", seconds: 31536000 },
        { unit: "month", seconds: 2592000 },
        { unit: "week", seconds: 604800 },
        { unit: "day", seconds: 86400 },
        { unit: "hour", seconds: 3600 },
        { unit: "minute", seconds: 60 },
      ];
      for (const range of ranges) {
        if (diffSeconds >= range.seconds) {
          const value = Math.floor(diffSeconds / range.seconds);
          if (rtf) {
            return rtf.format(-value, range.unit);
          }
          return `${value} ${range.unit}${value !== 1 ? "s" : ""} ago`;
        }
      }

      return date.toLocaleString();
    } catch (e) {
      console.error("Error formatting relative date string:", utcDateTimeString, e);
      return utcDateTimeString;
    }
  }

  /**
   * Formats a successful provider-model sync timestamp consistently anywhere
   * the model selector is shown.
   * @param {number|string|null|undefined} unixTimestamp Unix timestamp in seconds.
   * @returns {string} A localized "Last synced" label or an empty string.
   */
  function aipkit_formatModelLastSynced(unixTimestamp) {
    const syncedAt = Number(unixTimestamp);
    if (!Number.isFinite(syncedAt) || syncedAt <= 0) {
      return "";
    }

    const __ = window.wp?.i18n?.__ || ((text) => text);
    const _n =
      window.wp?.i18n?._n ||
      ((single, plural, count) => (count === 1 ? single : plural));
    const sprintf =
      window.wp?.i18n?.sprintf ||
      ((template, value) => template.replace("%s", String(value)));
    const elapsedSeconds = Math.max(
      0,
      Math.floor(Date.now() / 1000) - syncedAt
    );

    if (elapsedSeconds < 60) {
      return __("Last synced just now", "gpt3-ai-content-generator");
    }
    if (elapsedSeconds < 3600) {
      return sprintf(
        __("Last synced %s min ago", "gpt3-ai-content-generator"),
        Math.floor(elapsedSeconds / 60)
      );
    }
    if (elapsedSeconds < 86400) {
      return sprintf(
        __("Last synced %s hr ago", "gpt3-ai-content-generator"),
        Math.floor(elapsedSeconds / 3600)
      );
    }
    if (elapsedSeconds < 604800) {
      const elapsedDays = Math.floor(elapsedSeconds / 86400);
      return sprintf(
        _n(
          "Last synced %s day ago",
          "Last synced %s days ago",
          elapsedDays,
          "gpt3-ai-content-generator"
        ),
        elapsedDays
      );
    }

    const syncDate = new Date(syncedAt * 1000);
    const formattedDate = new Intl.DateTimeFormat(undefined, {
      month: "short",
      day: "numeric",
      year:
        syncDate.getFullYear() === new Date().getFullYear()
          ? undefined
          : "numeric",
    }).format(syncDate);

    return sprintf(
      __("Last synced %s", "gpt3-ai-content-generator"),
      formattedDate
    );
  }

  // Expose globally
  window.aipkit_formatFriendlyFutureDate = aipkit_formatFriendlyFutureDate;
  window.aipkit_formatUtcDateTime = aipkit_formatUtcDateTime;
  window.aipkit_formatRelativeDateTime = aipkit_formatRelativeDateTime;
  window.aipkit_formatModelLastSynced = aipkit_formatModelLastSynced;
})();
