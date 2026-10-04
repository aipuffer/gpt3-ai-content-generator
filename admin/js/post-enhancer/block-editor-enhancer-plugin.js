import "./editor-assistant.js";

(function () {
  "use strict";

  const wp = window.wp;
  if (
    !wp ||
    !wp.richText ||
    !wp.components ||
    !wp.element ||
    !wp.i18n ||
    !wp.blockEditor ||
    !wp.blocks ||
    !wp.data ||
    !wp.hooks
  ) {
    console.error(
      "AIPKit Block Editor Enhancer: One or more required WordPress script dependencies are missing."
    );
    return;
  }

  const {
    registerFormatType,
    insert,
    getTextContent,
    slice,
    applyFormat,
    create: createRichText,
  } = wp.richText;
  const { ToolbarGroup, ToolbarButton } = wp.components;
  const { BlockControls } = wp.blockEditor;
  const { __ } = wp.i18n;
  const { createElement, Fragment, useEffect, useState } = wp.element;
  const { addFilter } = wp.hooks;
  let aipkit_assistant_processing = false;

  // Notices are reserved for validation and request failures. Successful
  // changes are already explicit because the user accepts them in the preview.
  const ASSIST_NOTICE_ID = "aipkit-assistant-notice";
  function replaceAssistantNotice(type, message) {
    try {
      wp.data.dispatch("core/notices").removeNotice(ASSIST_NOTICE_ID);
    } catch (e) {}
    wp.data.dispatch("core/notices").createNotice(type, message, {
      id: ASSIST_NOTICE_ID,
      isDismissible: true,
      type: 'snackbar',
    });
  }

  const VALID_INSERT_POSITIONS = ["replace", "after", "before"];

  function normalizeInsertPosition(insertPosition) {
    return VALID_INSERT_POSITIONS.includes(insertPosition) ? insertPosition : "replace";
  }

  function htmlToText(html) {
    if (!html || typeof html !== "string") {
      return "";
    }
    const wrapper = document.createElement("div");
    wrapper.innerHTML = html;
    return wrapper.textContent || "";
  }

  function getRichTextAttributeText(block, attributeKey, startOffset, endOffset) {
    const rawHtml = block?.attributes?.[attributeKey];
    if (typeof rawHtml !== "string") {
      return "";
    }
    const richValue = createRichText({ html: rawHtml });
    const max = richValue.text.length;
    const start = Number.isFinite(startOffset) ? Math.max(0, Math.min(startOffset, max)) : 0;
    const end = Number.isFinite(endOffset) ? Math.max(start, Math.min(endOffset, max)) : max;
    return getTextContent(slice(richValue, start, end));
  }

  function getWholeBlockText(block) {
    if (!block) {
      return "";
    }

    const blockType = typeof wp.blocks.getBlockType === "function"
      ? wp.blocks.getBlockType(block.name)
      : null;
    const textParts = [];

    if (blockType?.attributes && block.attributes) {
      Object.entries(blockType.attributes).forEach(([key, schema]) => {
        if (
          (schema?.source === "html" || schema?.source === "rich-text") &&
          typeof block.attributes[key] === "string"
        ) {
          const text = htmlToText(block.attributes[key]).trim();
          if (text) {
            textParts.push(text);
          }
        }
      });
    }

    if (!textParts.length && block.attributes) {
      Object.values(block.attributes).forEach((value) => {
        if (typeof value === "string") {
          const text = htmlToText(value).trim();
          if (text) {
            textParts.push(text);
          }
        }
      });
    }

    if (!textParts.length && typeof wp.blocks.getBlockContent === "function") {
      const text = htmlToText(wp.blocks.getBlockContent(block)).trim();
      if (text) {
        textParts.push(text);
      }
    }

    return textParts.join("\n");
  }

  function orderSelectionRange(beSelect, selectionStart, selectionEnd) {
    if (!selectionStart?.clientId || !selectionEnd?.clientId) {
      return null;
    }

    if (selectionStart.clientId === selectionEnd.clientId) {
      const startOffset = Number.isFinite(selectionStart.offset) ? selectionStart.offset : 0;
      const endOffset = Number.isFinite(selectionEnd.offset) ? selectionEnd.offset : startOffset;
      return startOffset <= endOffset
        ? { start: selectionStart, end: selectionEnd }
        : { start: selectionEnd, end: selectionStart };
    }

    const rootClientId = beSelect.getBlockRootClientId(selectionStart.clientId);
    if (rootClientId !== beSelect.getBlockRootClientId(selectionEnd.clientId)) {
      return { start: selectionStart, end: selectionEnd };
    }

    const blockOrder = beSelect.getBlockOrder(rootClientId) || [];
    const startIndex = blockOrder.indexOf(selectionStart.clientId);
    const endIndex = blockOrder.indexOf(selectionEnd.clientId);

    return startIndex <= endIndex
      ? { start: selectionStart, end: selectionEnd }
      : { start: selectionEnd, end: selectionStart };
  }

  function orderClientIdsByDocumentOrder(beSelect, clientIds) {
    if (!Array.isArray(clientIds) || clientIds.length < 2) {
      return Array.isArray(clientIds) ? clientIds : [];
    }

    const rootClientId = beSelect.getBlockRootClientId(clientIds[0]);
    if (!clientIds.every((clientId) => beSelect.getBlockRootClientId(clientId) === rootClientId)) {
      return clientIds;
    }

    const blockOrder = beSelect.getBlockOrder(rootClientId) || [];
    if (!clientIds.every((clientId) => blockOrder.includes(clientId))) {
      return clientIds;
    }

    return clientIds.slice().sort((a, b) => blockOrder.indexOf(a) - blockOrder.indexOf(b));
  }

  function getSelectionClientIds(beSelect, orderedRange) {
    if (typeof beSelect.getMultiSelectedBlockClientIds === "function") {
      const multiSelectedIds = beSelect.getMultiSelectedBlockClientIds();
      if (Array.isArray(multiSelectedIds) && multiSelectedIds.length) {
        return orderClientIdsByDocumentOrder(beSelect, multiSelectedIds);
      }
    }

    if (!orderedRange?.start?.clientId || !orderedRange?.end?.clientId) {
      if (typeof beSelect.getSelectedBlockClientIds === "function") {
        const selectedIds = beSelect.getSelectedBlockClientIds();
        if (Array.isArray(selectedIds) && selectedIds.length) {
          return orderClientIdsByDocumentOrder(beSelect, selectedIds);
        }
      }
      return [];
    }

    if (orderedRange.start.clientId === orderedRange.end.clientId) {
      if (typeof beSelect.getSelectedBlockClientIds === "function") {
        const selectedIds = beSelect.getSelectedBlockClientIds();
        if (Array.isArray(selectedIds) && selectedIds.length) {
          return orderClientIdsByDocumentOrder(beSelect, selectedIds);
        }
      }
      return [orderedRange.start.clientId];
    }

    const rootClientId = beSelect.getBlockRootClientId(orderedRange.start.clientId);
    if (rootClientId !== beSelect.getBlockRootClientId(orderedRange.end.clientId)) {
      return [];
    }

    const blockOrder = beSelect.getBlockOrder(rootClientId) || [];
    const startIndex = blockOrder.indexOf(orderedRange.start.clientId);
    const endIndex = blockOrder.indexOf(orderedRange.end.clientId);

    if (startIndex < 0 || endIndex < 0) {
      return [];
    }

    return blockOrder.slice(startIndex, endIndex + 1);
  }

  function getSelectedTextFromBlocks(beSelect, selectedClientIds, orderedRange) {
    if (!selectedClientIds.length) {
      return "";
    }

    const firstId = selectedClientIds[0];
    const lastId = selectedClientIds[selectedClientIds.length - 1];
    const hasRichTextRange = Boolean(
      orderedRange?.start?.attributeKey &&
      orderedRange?.end?.attributeKey &&
      Number.isFinite(orderedRange.start.offset) &&
      Number.isFinite(orderedRange.end.offset)
    );

    return selectedClientIds
      .map((clientId) => {
        const block = beSelect.getBlock(clientId);
        if (!block) {
          return "";
        }

        if (hasRichTextRange && clientId === firstId && clientId === lastId) {
          return orderedRange.start.attributeKey === orderedRange.end.attributeKey
            ? getRichTextAttributeText(
                block,
                orderedRange.start.attributeKey,
                orderedRange.start.offset,
                orderedRange.end.offset
              )
            : getWholeBlockText(block);
        }

        if (hasRichTextRange && clientId === firstId) {
          return getRichTextAttributeText(
            block,
            orderedRange.start.attributeKey,
            orderedRange.start.offset
          );
        }

        if (hasRichTextRange && clientId === lastId) {
          return getRichTextAttributeText(
            block,
            orderedRange.end.attributeKey,
            0,
            orderedRange.end.offset
          );
        }

        return getWholeBlockText(block);
      })
      .map((text) => text.trim())
      .filter(Boolean)
      .join("\n\n");
  }

  function getAssistantSelectionContext(value) {
    const fallbackText = value ? getTextContent(slice(value)) : "";
    const context = {
      selectedText: fallbackText,
      selectedClientIds: [],
      selectedClientId: null,
      rootClientId: undefined,
      selectionStart: null,
      selectionEnd: null,
      hasBlockSelection: false,
      hasRichTextRange: false,
      canSplitSelection: false,
    };

    try {
      const beSelect = wp.data.select("core/block-editor");
      const beDispatch = wp.data.dispatch("core/block-editor");
      const selectionStart = typeof beSelect.getSelectionStart === "function"
        ? beSelect.getSelectionStart()
        : null;
      const selectionEnd = typeof beSelect.getSelectionEnd === "function"
        ? beSelect.getSelectionEnd()
        : null;
      const orderedRange = orderSelectionRange(beSelect, selectionStart, selectionEnd);
      const selectedClientIds = getSelectionClientIds(beSelect, orderedRange);
      const selectedClientId = typeof beSelect.getSelectedBlockClientId === "function"
        ? beSelect.getSelectedBlockClientId()
        : selectedClientIds[0] || null;
      const firstClientId = selectedClientIds[0] || orderedRange?.start?.clientId || selectedClientId;
      const lastClientId = selectedClientIds[selectedClientIds.length - 1] || orderedRange?.end?.clientId || selectedClientId;
      const hasBlockSelection = Boolean(firstClientId && lastClientId && firstClientId !== lastClientId);
      const hasRichTextRange = Boolean(
        orderedRange?.start?.attributeKey &&
        orderedRange?.end?.attributeKey &&
        Number.isFinite(orderedRange.start.offset) &&
        Number.isFinite(orderedRange.end.offset)
      );
      const selectedText = hasBlockSelection
        ? getSelectedTextFromBlocks(beSelect, selectedClientIds, orderedRange)
        : fallbackText;

      context.selectedText = selectedText || fallbackText;
      context.selectedClientIds = selectedClientIds;
      context.selectedClientId = selectedClientId || firstClientId || null;
      context.rootClientId = firstClientId ? beSelect.getBlockRootClientId(firstClientId) : undefined;
      context.selectionStart = orderedRange?.start || null;
      context.selectionEnd = orderedRange?.end || null;
      context.hasBlockSelection = hasBlockSelection;
      context.hasRichTextRange = hasRichTextRange;
      context.canSplitSelection = hasRichTextRange &&
        typeof beDispatch.__unstableSplitSelection === "function";
    } catch (e) {}

    return context;
  }

  function getCurrentSelectionClientIds() {
    try {
      const beSelect = wp.data.select("core/block-editor");
      const selectionStart = typeof beSelect.getSelectionStart === "function"
        ? beSelect.getSelectionStart()
        : null;
      const selectionEnd = typeof beSelect.getSelectionEnd === "function"
        ? beSelect.getSelectionEnd()
        : null;
      const orderedRange = orderSelectionRange(beSelect, selectionStart, selectionEnd);
      return getSelectionClientIds(beSelect, orderedRange);
    } catch (e) {
      return [];
    }
  }

  function getCurrentMultiSelectionClientIds() {
    const selectedClientIds = getCurrentSelectionClientIds();
    return selectedClientIds.length > 1 ? selectedClientIds : [];
  }

  function getBlocksFromHtml(html) {
    if (typeof wp.blocks.rawHandler === "function") {
      return wp.blocks.rawHandler({ HTML: html });
    }
    return wp.blocks.parse(html);
  }

  function getBlocksFromPlainText(text) {
    const escaper = window.aipkit_escapeHtml || ((value) =>
      String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;"));
    const paragraphs = String(text || "")
      .trim()
      .split(/\n{2,}/)
      .map((part) => part.trim())
      .filter(Boolean)
      .map((part) => `<p>${escaper(part).replace(/\n/g, "<br>")}</p>`)
      .join("");

    return paragraphs ? getBlocksFromHtml(paragraphs) : [];
  }

  function handleBlockSelectionBlocks(blocks, selectionContext, insertPosition) {
    if (!Array.isArray(blocks) || !blocks.length) {
      return false;
    }

    const position = normalizeInsertPosition(insertPosition);
    const shouldHandleRangeReplace = position === "replace" &&
      selectionContext.canSplitSelection &&
      selectionContext.hasBlockSelection;
    const shouldHandleBlockSelection = selectionContext.hasBlockSelection;

    if (!shouldHandleRangeReplace && !shouldHandleBlockSelection) {
      return false;
    }

    try {
      const beSelect = wp.data.select("core/block-editor");
      const beDispatch = wp.data.dispatch("core/block-editor");
      const clientIds = selectionContext.selectedClientIds || [];
      const firstClientId = clientIds[0] || selectionContext.selectedClientId;
      const lastClientId = clientIds[clientIds.length - 1] || firstClientId;
      const rootClientId = firstClientId ? beSelect.getBlockRootClientId(firstClientId) : undefined;

      if (!firstClientId) {
        return false;
      }

      if (position === "replace") {
        if (selectionContext.canSplitSelection) {
          if (
            typeof beDispatch.selectionChange === "function" &&
            selectionContext.selectionStart &&
            selectionContext.selectionEnd
          ) {
            beDispatch.selectionChange({
              start: selectionContext.selectionStart,
              end: selectionContext.selectionEnd,
            });
          }
          beDispatch.__unstableSplitSelection(blocks);
        } else if (clientIds.length) {
          beDispatch.replaceBlocks(clientIds, blocks);
        } else {
          return false;
        }
      } else {
        const targetClientId = position === "before" ? firstClientId : lastClientId;
        const insertIndex = beSelect.getBlockIndex(targetClientId) + (position === "after" ? 1 : 0);
        beDispatch.insertBlocks(blocks, insertIndex, rootClientId);
      }

      return true;
    } catch (e) {
      console.warn("AIPKit: Failed to handle block selection, falling back.", e);
      return false;
    }
  }

  /**
   * Helper function to call the AI for text processing in the Block Editor.
   * @param {object} value - The editor's value object.
   * @param {function} onChange - The editor's onChange function.
   * @param {string} promptTemplate - The prompt template for the action.
   */
  async function callAIAssistantBlock(
    value,
    onChange,
    action,
    capturedSelectionContext = null
  ) {
    const promptTemplate = action?.prompt || "";
    const insertPosition = action?.insert_position || "replace";
    const selectionContext = capturedSelectionContext ||
      getAssistantSelectionContext(value);
    const selectedText = selectionContext.selectedText;
    if (!selectedText) {
      replaceAssistantNotice(
        "warning",
        __("Please select some text to process.", "gpt3-ai-content-generator")
      );
      return;
    }

    aipkit_assistant_processing = true;
    const nonce = window.aipkit_post_enhancer?.nonce_process_text;
    if (!nonce) {
      aipkit_assistant_processing = false;
      replaceAssistantNotice(
        "error",
        __("An error occurred: Security token missing.", "gpt3-ai-content-generator")
      );
      return;
    }

    const finalPrompt = typeof window.aipkit_formatEditorAssistantPrompt === "function"
      ? window.aipkit_formatEditorAssistantPrompt(promptTemplate, selectedText)
      : promptTemplate.replace(/\{selected_text\}|%s/g, selectedText);

    try {
      const acceptedResult = await window.aipkit_runEditorAssistantPreview({
        actionLabel: action?.label || __("Assistant", "gpt3-ai-content-generator"),
        insertPosition,
        generate: async () => {
          const data = await window.aipkit_apiRequest(
            "aipkit_process_enhancer_text",
            {
              _ajax_nonce: nonce,
              editor_context: "block",
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

      const enableFormatting = window.aipkit_post_enhancer?.parse_html_formats !== undefined
        ? !!window.aipkit_post_enhancer.parse_html_formats
        : true; // default on

      // For better readability: if the response is Markdown without HTML tags,
      // render to HTML first using markdown-it (if available), then normalize to text with newlines.
      let raw = acceptedResult;
      const looksLikeMarkdown = /(^\s{0,3}#{1,6}\s)|(^\s{0,3}[-*+]\s)|(```[\s\S]*?```)|(__|\*\*)|(_|\*)/m.test(raw);
      if (looksLikeMarkdown && typeof window.aipkit_getMarkdownRenderer === 'function') {
        try {
          const md = window.aipkit_getMarkdownRenderer();
          if (md) {
            raw = md.render(raw);
          }
        } catch (e) {
          console.warn('AIPKit: markdown render failed', e);
        }
      }

      // If the rendered HTML contains block-level elements (headings, paragraphs, lists, etc.),
      // insert them as proper blocks instead of plain text so headings are preserved.
      const parser = document.createElement("div");
      parser.innerHTML = raw;

      const containsBlockElements = /<\s*(h1|h2|h3|h4|h5|h6|p|ul|ol|li|blockquote|pre)\b/i.test(raw);
      if (containsBlockElements) {
        try {
          // Prefer rawHandler to map HTML into core blocks (paragraph, heading, etc.).
          const blocks = getBlocksFromHtml(raw);
          if (handleBlockSelectionBlocks(blocks, selectionContext, insertPosition)) {
            return;
          }
          const beSelect = wp.data.select('core/block-editor');
          const beDispatch = wp.data.dispatch('core/block-editor');
          const selectedClientId = beSelect.getSelectedBlockClientId();
          const rootClientId = selectedClientId ? beSelect.getBlockRootClientId(selectedClientId) : undefined;
          const currentIndex = selectedClientId != null ? beSelect.getBlockIndex(selectedClientId, rootClientId) : undefined;
          const insertIndexAfter = currentIndex != null ? currentIndex + 1 : undefined;
          const insertIndexBefore = currentIndex != null ? currentIndex : undefined;
          const selectedBlock = selectedClientId ? beSelect.getBlock(selectedClientId) : null;
          const isClassic = selectedBlock && selectedBlock.name === 'core/freeform';

          const position = normalizeInsertPosition(insertPosition);

          if (isClassic) {
            if (position === 'replace') {
              beDispatch.replaceBlocks(selectedClientId, blocks);
              return;
            }
            const insertIndex = position === 'before' ? insertIndexBefore : insertIndexAfter;
            beDispatch.insertBlocks(blocks, insertIndex, rootClientId);
            return;
          }

          if (position === 'replace' && selectedClientId) {
            beDispatch.replaceBlocks(selectedClientId, blocks);
            return;
          }

          const insertIndex = position === 'before' ? insertIndexBefore : insertIndexAfter;
          beDispatch.insertBlocks(blocks, insertIndex, rootClientId);
          return;
        } catch (e) {
          console.warn('AIPKit: Failed to insert blocks, falling back to inline text.', e);
        }
      }

      const formatMap = {
        STRONG: "core/bold",
        B: "core/bold",
        EM: "core/italic",
        I: "core/italic",
        U: "core/text-highlight", // Gutenberg lacks a core underline; map to highlight
      };

      const blockTags = new Set(["P","H1","H2","H3","H4","H5","H6","BLOCKQUOTE","PRE"]);

      let plain = "";
      const ranges = []; // { type, start, end }

      function ensureSingleNewline() {
        // Ensure exactly one newline at the end of `plain`
        let have = 0;
        for (let i = plain.length - 1; i >= 0 && plain[i] === '\n'; i--) have++;
        if (have === 0) {
          plain += "\n";
        } else if (have > 1) {
          // Reduce multiple trailing newlines to a single one
          plain = plain.slice(0, plain.length - (have - 1));
        }
      }

      function walk(node) {
        if (node.nodeType === Node.TEXT_NODE) {
          plain += node.nodeValue;
          return;
        }
        if (node.nodeType === Node.ELEMENT_NODE) {
          const tag = node.tagName;
          if (tag === 'BR') {
            plain += "\n";
            return;
          }
          const startBefore = plain.length;
          for (const child of Array.from(node.childNodes)) walk(child);
          const endAfter = plain.length;
          const fmt = formatMap[tag];
          if (fmt && startBefore !== endAfter) {
            ranges.push({ type: fmt, start: startBefore, end: endAfter });
          }
          if (tag === 'LI') {
            // Single line break after each list item to avoid run-on lines
            ensureSingleNewline();
          } else if (blockTags.has(tag)) {
            // Single newline between block-level elements (avoid double spacing)
            ensureSingleNewline();
          }
          return;
        }
      }

      // Walk regardless of enableFormatting to improve readability; formatting ranges only apply if enabled
      for (const child of Array.from(parser.childNodes)) walk(child);

      if (selectionContext.hasBlockSelection) {
        const blocks = getBlocksFromPlainText(plain);
        if (handleBlockSelectionBlocks(blocks, selectionContext, insertPosition)) {
          return;
        }
      }

      if (!value || typeof onChange !== "function") {
        throw new Error("Unable to apply Assistant output to this selection.");
      }

      const selectionStart = value.start;
      let newValue = insert(value, plain);

      if (enableFormatting && ranges.length) {
        // Apply formats relative to the inserted segment
        ranges.forEach((r) => {
          try {
            newValue = applyFormat(
              newValue,
              { type: r.type },
              selectionStart + r.start,
              selectionStart + r.end
            );
          } catch (e) {
            // Fail silently per range to avoid aborting others
            console.warn("AIPKit formatting apply failed", e);
          }
        });
      }

      onChange(newValue);
    } catch (error) {
      console.error("AIPKit Block Editor Enhancer Error:", error);
      replaceAssistantNotice(
        "error",
        __("An error occurred:", "gpt3-ai-content-generator") + " " + error.message
      );
    } finally {
      aipkit_assistant_processing = false;
    }
  }

  const FORMAT_TYPE_NAME = "aipkit/assistant-format";

  function useAssistantActionsRefresh() {
    const [, forceActionsRender] = useState(0);
    useEffect(() => {
      const refreshControls = () => {
        forceActionsRender((version) => version + 1);
      };
      window.addEventListener("aipkit:enhancerActionsUpdated", refreshControls);
      return () => {
        window.removeEventListener("aipkit:enhancerActionsUpdated", refreshControls);
      };
    }, []);
    return forceActionsRender;
  }

  function renderAssistantToolbarControls(
    onAction,
    forceActionsRender,
    captureSelectionContext
  ) {
    return createElement(
      Fragment,
      null,
      createElement(
        BlockControls,
        null,
        createElement(
          ToolbarGroup,
          { className: "aipkit-assistant-toolbar-group" },
          createElement(ToolbarButton, {
            icon: createElement("span", {
              className: "aipkit_assistant_symbol",
              "aria-hidden": true,
            }),
            label: __("Assistant", "gpt3-ai-content-generator"),
            isDisabled: aipkit_assistant_processing,
            className: "aipkit-content-assistant-menu",
            onClick: (event) => {
              const enhancerConfig = window.aipkit_post_enhancer || {};
              const selectionContext = typeof captureSelectionContext === "function"
                ? captureSelectionContext()
                : null;
              window.aipkit_openEditorAssistantMenu({
                anchor: event.currentTarget,
                actions: enhancerConfig.actions || [],
                onAction: (action) => onAction(action, selectionContext),
                onCustomize: enhancerConfig.can_manage_actions &&
                  typeof window.aipkit_openEnhancerActionsManager === "function"
                  ? () => window.aipkit_openEnhancerActionsManager({
                      onUpdated: () => forceActionsRender((version) => version + 1),
                    })
                  : null,
              });
            },
          })
        )
      )
    );
  }

  registerFormatType(FORMAT_TYPE_NAME, {
    title: __("Assistant", "gpt3-ai-content-generator"),
    tagName: "span",
    className: "aipkit-assistant-placeholder-format",
    edit: ({ value, onChange }) => {
      const forceActionsRender = useAssistantActionsRefresh();
      if (getCurrentMultiSelectionClientIds().length > 1) {
        return null;
      }
      return renderAssistantToolbarControls(
        (action, selectionContext) =>
          callAIAssistantBlock(value, onChange, action, selectionContext),
        forceActionsRender,
        () => getAssistantSelectionContext(value)
      );
    },
  });

  const MULTI_BLOCK_ASSISTANT_BLOCKS = new Set([
    "core/paragraph",
    "core/heading",
    "core/list",
    "core/list-item",
    "core/quote",
    "core/pullquote",
    "core/preformatted",
    "core/verse",
    "core/table",
    "core/freeform",
  ]);

  function AIPKitMultiBlockAssistantControls({ clientId }) {
    const forceActionsRender = useAssistantActionsRefresh();
    const [selectionKey, setSelectionKey] = useState(() => getCurrentMultiSelectionClientIds().join("|"));

    useEffect(() => {
      let lastSelectionKey = getCurrentMultiSelectionClientIds().join("|");
      setSelectionKey(lastSelectionKey);

      const unsubscribe = wp.data.subscribe(() => {
        const nextSelectionKey = getCurrentMultiSelectionClientIds().join("|");
        if (nextSelectionKey !== lastSelectionKey) {
          lastSelectionKey = nextSelectionKey;
          setSelectionKey(nextSelectionKey);
        }
      });

      return () => {
        if (typeof unsubscribe === "function") {
          unsubscribe();
        }
      };
    }, []);

    const selectedClientIds = selectionKey ? selectionKey.split("|").filter(Boolean) : [];
    if (selectedClientIds.length < 2 || selectedClientIds[0] !== clientId) {
      return null;
    }

    return renderAssistantToolbarControls(
      (action, selectionContext) =>
        callAIAssistantBlock(null, null, action, selectionContext),
      forceActionsRender,
      () => getAssistantSelectionContext(null)
    );
  }

  const withAIPKitMultiBlockAssistantControls = (BlockEdit) => (props) => {
    return createElement(
      Fragment,
      null,
      createElement(BlockEdit, props),
      MULTI_BLOCK_ASSISTANT_BLOCKS.has(props.name)
        ? createElement(AIPKitMultiBlockAssistantControls, { clientId: props.clientId })
        : null
    );
  };

  addFilter(
    "editor.BlockEdit",
    "aipkit/multi-block-assistant-controls",
    withAIPKitMultiBlockAssistantControls
  );
})();
