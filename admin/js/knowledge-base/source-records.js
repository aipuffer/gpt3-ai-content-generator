/** Shared record labels, with explicit Knowledge Base and chatbot presentation policies. */
export function getSourceUpdatedMeta(log) {
  const timestamp = log.timestamp;
  const date = timestamp ? new Date(String(timestamp).replace(" ", "T") + "Z") : null;
  const exact = date && !Number.isNaN(date.getTime())
    ? date.toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        hour12: false,
      })
    : "—";
  const relativeTimestamp = log.timestamp;
  const relative = relativeTimestamp && typeof window.aipkit_formatRelativeDateTime === "function"
    ? window.aipkit_formatRelativeDateTime(relativeTimestamp)
    : "";
  const title = typeof window.aipkit_formatUtcDateTime === "function"
    ? window.aipkit_formatUtcDateTime(log.timestamp)
    : log.timestamp || exact;

  return {
    label: relative && relative !== "Invalid Date" && relative !== "N/A" ? relative : exact,
    title,
  };
}

function createSourceRecords({ __, normalizeProviderKey }, chatbotView) {
  function normalizeQaContent(content) {
    return content
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<\/p>/gi, "\n")
      .replace(/<[^>]+>/g, "")
      .replace(/\r\n?/g, "\n")
      .trim();
  }

  function isQaTextSource(log) {
    const content = String(log?.indexed_content || "").trim();
    if (!content) {
      return false;
    }

    const normalizedContent = normalizeQaContent(content);

    return /^Q\s*:\s*\S[\s\S]*\bA\s*:\s*\S/i.test(normalizedContent);
  }

  function looksLikeFileSource(log, message, vectorId) {
    const content = String(log?.indexed_content || "").toLowerCase();
    const title = String(log?.post_title || "").toLowerCase();
    const fileTitlePattern =
      /\.(pdf|txt|md|csv|json|html?|xhtml|docx|doc|xls|xlsx)\b/i;

    return (
      message.includes("file content submitted for indexing") ||
      message.includes("file content embedded and upserted") ||
      message.includes("file chunk embedded") ||
      message.includes("original filename:") ||
      message.includes("file uploaded") ||
      content.startsWith("chunked_upload:") ||
      /^chunk_\d+\s+of\s+\d+\s+for\s+/i.test(content) ||
      (fileTitlePattern.test(title) && !String(vectorId || "").startsWith("text_"))
    );
  }

  function getSourceType(log, providerKey) {
    const message = String(log?.message || "").toLowerCase();
    const provider = normalizeProviderKey((chatbotView && providerKey) || log?.provider || "");
    const vectorId = String(log?.file_id || "");
    const hasPostId = Boolean(log?.post_id);

    if (message.includes("wordpress post content submitted for indexing") || hasPostId) {
      return "site";
    }
    if (
      looksLikeFileSource(log, message, vectorId) ||
      ((provider === "pinecone" || provider === "qdrant" || (chatbotView && provider === "chroma")) &&
        vectorId.includes("file_") &&
        !vectorId.startsWith("wp_post_") &&
        !vectorId.startsWith("text_"))
    ) {
      return "file";
    }
    if (message.includes("text content submitted for indexing")) {
      return "text";
    }
    if (provider === "qdrant" && message.includes("points upserted to qdrant")) {
      return message.includes("post id:") ? "site" : "text";
    }
    if (provider === "chroma" && message.includes("chroma records upserted")) {
      if (chatbotView) {
        return message.includes("post id:") ? "site" : "text";
      }
      if (vectorId.startsWith("wp_post_")) {
        return "site";
      }
      return vectorId.includes("file_") ? "file" : "text";
    }
    if (provider === "pinecone") {
      if (vectorId.startsWith("wp_post_")) {
        return "site";
      }
      if (vectorId.startsWith("text_")) {
        return "text";
      }
    }
    return log?.indexed_content ? "text" : "na";
  }

  function getContentTypeMeta(log, providerKey) {
    const key = getSourceType(log, providerKey);
    const label = key === "site"
      ? __("Website", "gpt3-ai-content-generator")
      : key === "file"
        ? __("File", "gpt3-ai-content-generator")
        : key === "text"
          ? (isQaTextSource(log)
            ? __("Q&A", "gpt3-ai-content-generator")
            : __("Text", "gpt3-ai-content-generator"))
          : __("N/A", "gpt3-ai-content-generator");
    return { key, label };
  }

  function getStatusMeta(status) {
    const safeStatus = status || "";
    if (
      safeStatus === "indexed" ||
      safeStatus === "skipped_already_indexed" ||
      safeStatus === "success"
    ) {
      return {
        label: __("Ready", "gpt3-ai-content-generator"),
        className: "aipkit_status-success",
      };
    }
    if (safeStatus === "failed") {
      return {
        label: chatbotView ? __("Couldn't add", "gpt3-ai-content-generator") : safeStatus.replace(/_/g, " "),
        className: "aipkit_status-warning",
      };
    }
    if (safeStatus) {
      // The chatbot list names work still in progress plainly; other statuses keep their own words.
      const isAdding = ["processing", "queued", "pending", "in_progress"].includes(safeStatus);
      return {
        label: chatbotView && isAdding
          ? __("Adding...", "gpt3-ai-content-generator")
          : safeStatus.replace(/_/g, " "),
        className: "aipkit_status-info",
      };
    }
    return { label: "—", className: "aipkit_status-info" };
  }

  function getSourceDisplay(log) {
    if (log.post_title) {
      return String(log.post_title);
    }
    if (log.post_id) {
      return `${__("Post", "gpt3-ai-content-generator")} #${log.post_id}`;
    }
    if (log.indexed_content) {
      const indexedContent = String(log.indexed_content);
      if (chatbotView && isQaTextSource(log)) {
        const questionMatch = normalizeQaContent(indexedContent).match(
          /^Q\s*:\s*([\s\S]*?)\s+A\s*:/i
        );
        if (questionMatch?.[1]) {
          return questionMatch[1].trim();
        }
      }
      return indexedContent;
    }
    if (log.message) {
      return String(log.message);
    }
    if (log.file_id) {
      return String(log.file_id);
    }
    if (log.vector_store_name) {
      return String(log.vector_store_name);
    }
    return "—";
  }

  function getSourceChunkMeta(log) {
    const message = String(log?.message || "");
    const match = message.match(/\bchunk\s+(\d+)\s*\/\s*(\d+)\b/i);
    if (!match) {
      return null;
    }

    const current = parseInt(match[1], 10);
    const total = parseInt(match[2], 10);
    if (!Number.isFinite(current) || !Number.isFinite(total) || current < 1 || total <= 1) {
      return null;
    }

    return {
      current,
      total,
      label: `${__("Chunk", "gpt3-ai-content-generator")} ${current}/${total}`,
    };
  }

  return { isQaTextSource, getContentTypeMeta, getStatusMeta, getSourceDisplay, getSourceChunkMeta };
}

export function createChatbotSourceRecords(dependencies) {
  return createSourceRecords(dependencies, true);
}

export function createKnowledgeBaseSourceRecords({ __, normalizeProviderKey }) {
  const records = createSourceRecords({ __, normalizeProviderKey }, false);

  function isUserUploadSource(log) {
    if (!log) {
      return false;
    }
    if (log.is_user_upload === true || log.is_user_upload === 1 || log.is_user_upload === "1") {
      return true;
    }

    const provider = normalizeProviderKey(log.provider || "");
    const vectorId = String(log.file_id || "");
    const batchId = String(log.batch_id || "");
    const storeName = String(log.vector_store_name || "");

    if (provider === "openai") {
      return storeName.startsWith("chat_file_");
    }
    if (provider === "pinecone") {
      return vectorId.startsWith("chatfile_") || batchId.startsWith("pinecone_chat_file_");
    }
    if (provider === "qdrant" || provider === "chroma") {
      const prefix = `${provider}_chat_file_`;
      return batchId.startsWith(prefix) || vectorId.startsWith(prefix);
    }
    return false;
  }

  function getIndexLabel(log) {
    if (log.vector_store_name) {
      return String(log.vector_store_name);
    }
    if (log.vector_store_id) {
      return String(log.vector_store_id);
    }
    return "—";
  }

  function getSourceDetailItems(log, providerLabel, indexLabel) {
    const items = [];
    if (providerLabel && providerLabel !== "—") {
      items.push({
        role: "provider",
        label: __("Provider", "gpt3-ai-content-generator"),
        value: providerLabel,
      });
    }
    if (indexLabel && indexLabel !== "—") {
      items.push({
        role: "index",
        label: __("Index", "gpt3-ai-content-generator"),
        value: indexLabel,
      });
    }
    if (log.embedding_model) {
      const modelLabel = String(log.embedding_model_label || log.embedding_model);
      items.push({
        role: "model",
        label: __("Model", "gpt3-ai-content-generator"),
        value: modelLabel,
      });
    }
    return items;
  }

  return { ...records, isUserUploadSource, getIndexLabel, getSourceDetailItems };
}
