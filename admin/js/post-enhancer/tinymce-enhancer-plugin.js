import "./editor-assistant.js";

(function () {
  "use strict";

  const getConfig = () => window.aipkit_post_enhancer || {};
  const getText = (key, fallback) => getConfig().text?.[key] || fallback;
  const showAssistantAlert = (editor, message, title = "Assistant") => {
    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(message, {
        title,
        confirmText: getText("ok", "OK"),
        variant: "warning",
        hideCancel: true,
      });
      return;
    }
    editor.windowManager.alert(message);
  };

  const prepareOutputHtml = (editor, responseText) => {
    const enableFormatting = getConfig().parse_html_formats !== undefined
      ? !!getConfig().parse_html_formats
      : true;
    let outputHtml = String(responseText || "");
    const looksLikeMarkdown = /(^\s{0,3}#{1,6}\s)|(^\s{0,3}[-*+]\s)|(```[\s\S]*?```)|(__|\*\*)|(_|\*)/m.test(outputHtml);

    if (looksLikeMarkdown && typeof window.aipkit_getMarkdownRenderer === "function") {
      try {
        const renderer = window.aipkit_getMarkdownRenderer();
        if (renderer) {
          outputHtml = renderer.render(outputHtml);
        }
      } catch (error) {
        console.warn("AIPKit: markdown render failed", error);
      }
    }

    if (!enableFormatting) {
      return editor.dom.encode(outputHtml.replace(/<[^>]*>/g, ""));
    }

    return window.aipkit_sanitizeAssistantHtml(outputHtml);
  };

  const callAIAssistant = async (editor, action, selectionSnapshot = {}) => {
    const selectedText = selectionSnapshot.text || "";
    if (!selectedText) {
      showAssistantAlert(
        editor,
        getText("select_text", "Please select some text to process.")
      );
      return;
    }

    const nonce = getConfig().nonce_process_text;
    if (!nonce || typeof window.aipkit_apiRequest !== "function") {
      showAssistantAlert(
        editor,
        getText("assistant_unavailable", "The Assistant request is not available.")
      );
      return;
    }

    const bookmark = selectionSnapshot.bookmark || null;

    const finalPrompt = typeof window.aipkit_formatEditorAssistantPrompt === "function"
      ? window.aipkit_formatEditorAssistantPrompt(action.prompt, selectedText)
      : String(action.prompt || "").replace(/\{selected_text\}|%s/g, selectedText);

    const acceptedResult = await window.aipkit_runEditorAssistantPreview({
      actionLabel: action.label,
      insertPosition: action.insert_position || "replace",
      generate: async () => {
        const data = await window.aipkit_apiRequest(
          "aipkit_process_enhancer_text",
          {
            _ajax_nonce: nonce,
            editor_context: "classic",
            text_to_process: selectedText,
            final_prompt: finalPrompt,
          }
        );
        if (!data?.text) {
          throw new Error("AI did not return any text.");
        }
        return data.text;
      },
    });

    if (acceptedResult === null) {
      return;
    }

    try {
      if (bookmark) {
        editor.selection.moveToBookmark(bookmark);
      }
      const outputHtml = prepareOutputHtml(editor, acceptedResult);
      const position = ["replace", "before", "after"].includes(action.insert_position)
        ? action.insert_position
        : "replace";
      editor.undoManager.transact(() => {
        if (position === "before") {
          editor.selection.collapse(true);
          editor.insertContent(outputHtml);
        } else if (position === "after") {
          editor.selection.collapse(false);
          editor.insertContent(outputHtml);
        } else {
          editor.selection.setContent(outputHtml);
        }
      });
    } catch (error) {
      showAssistantAlert(
        editor,
        error?.message || "Unable to apply the result.",
        getText("error", "Error")
      );
    }
  };

  const openAssistantMenu = (editor, anchor) => {
    const config = getConfig();
    let bookmark = null;
    try {
      bookmark = editor.selection.getBookmark(2, true);
    } catch (error) {}
    const selectionSnapshot = {
      text: editor.selection.getContent({ format: "text" }),
      bookmark,
    };

    window.aipkit_openEditorAssistantMenu({
      anchor,
      actions: config.actions || [],
      onAction: (action) => callAIAssistant(editor, action, selectionSnapshot),
      onCustomize: config.can_manage_actions &&
        typeof window.aipkit_openEnhancerActionsManager === "function"
        ? () => window.aipkit_openEnhancerActionsManager()
        : null,
    });
  };

  tinymce.PluginManager.add("aipkit_assistant", function (editor) {
    editor.addButton("aipkit_assistant_button", {
      text: "Assistant",
      icon: false,
      type: "button",
      classes: "aipkit-tinymce-assistant-button",
      tooltip: "Assistant",
      onclick: function (event) {
        const anchor = event?.target?.closest?.("button") ||
          document.querySelector(".aipkit-tinymce-assistant-button button") ||
          document.querySelector(".aipkit-tinymce-assistant-button");
        openAssistantMenu(editor, anchor);
      },
    });
  });
})();
