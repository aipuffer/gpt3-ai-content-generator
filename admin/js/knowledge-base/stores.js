/** Store identity, status badges and detail formatting for Knowledge Base. */
export function createKnowledgeBaseStorePresentation({
  __, _n, sprintf, escaper, normalizeProviderKey,
}) {
  function getProviderStoreId(providerKey, store) {
    if (!store || typeof store !== "object") {
      return "";
    }
    if (providerKey === "openai") {
      return store.id || store.vector_store_id || "";
    }
    if (providerKey === "local") {
      return store.id || "";
    }
    if (providerKey === "pinecone") {
      return store.name || store.id || "";
    }
    if (providerKey === "qdrant" || providerKey === "chroma") {
      return store.name || store.id || store.collection_name || "";
    }
    return store.id || store.name || "";
  }

  function getProviderStoreName(providerKey, store) {
    const fallback = getProviderStoreId(providerKey, store);
    if (!store || typeof store !== "object") {
      return fallback || "—";
    }
    return store.name || store.vector_store_name || fallback || "—";
  }

  function getNumberValue(value) {
    const parsed =
      typeof value === "number" ? value : parseInt(String(value || ""), 10);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function formatStoreCount(value) {
    const parsed = getNumberValue(value);
    return parsed === null ? "" : parsed.toLocaleString();
  }

  function formatStoreBytes(value) {
    const bytes = getNumberValue(value);
    if (bytes === null || bytes < 0) {
      return "";
    }
    if (bytes === 0) {
      return `0 ${__("B", "gpt3-ai-content-generator")}`;
    }
    const units = ["B", "KB", "MB", "GB", "TB"];
    const unitIndex = Math.min(
      Math.floor(Math.log(bytes) / Math.log(1024)),
      units.length - 1
    );
    const amount = bytes / Math.pow(1024, unitIndex);
    const precision = amount >= 10 || unitIndex === 0 ? 0 : 1;
    return `${amount.toFixed(precision)} ${units[unitIndex]}`;
  }

  function formatStoreRegion(store) {
    if (!store || typeof store !== "object") {
      return "";
    }
    const serverless = store.spec?.serverless || null;
    const pod = store.spec?.pod || null;
    if (serverless?.cloud && serverless?.region) {
      return `${serverless.cloud}/${serverless.region}`;
    }
    if (serverless?.region) {
      return String(serverless.region);
    }
    if (pod?.environment) {
      return String(pod.environment);
    }
    return "";
  }

  function getQdrantVectorConfig(store) {
    const vectors = store?.config?.params?.vectors;
    if (!vectors || typeof vectors !== "object") {
      return {};
    }
    if (vectors.size || vectors.distance) {
      return {
        size: vectors.size,
        distance: vectors.distance,
      };
    }
    const firstVectorName = Object.keys(vectors).find(
      (key) => vectors[key] && typeof vectors[key] === "object"
    );
    if (!firstVectorName) {
      return {};
    }
    return {
      size: vectors[firstVectorName].size,
      distance: vectors[firstVectorName].distance,
    };
  }

  function titleCaseStoreStatus(value) {
    return String(value || "")
      .replace(/[_-]+/g, " ")
      .replace(/\s+/g, " ")
      .trim()
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function createStoreStatus(label, tone) {
    return { label, className: `aipkit_status-${tone}` };
  }

  function readyStoreStatus() {
    return createStoreStatus(__("Ready", "gpt3-ai-content-generator"), "success");
  }

  function getStoreStatusMeta(providerKey, store, fallbackMeta) {
    if (!store || typeof store !== "object") {
      return fallbackMeta;
    }

    if (providerKey === "local") {
      return readyStoreStatus();
    }

    if (providerKey === "openai" && store.status) {
      const status = normalizeProviderKey(store.status);
      if (status === "completed") {
        return readyStoreStatus();
      }
      if (status === "in_progress") {
        return createStoreStatus(__("In progress", "gpt3-ai-content-generator"), "info");
      }
      if (status === "expired") {
        return createStoreStatus(__("Expired", "gpt3-ai-content-generator"), "warning");
      }
      return createStoreStatus(titleCaseStoreStatus(store.status), "info");
    }

    if (providerKey === "google") {
      const failedCount = getNumberValue(store.failed_documents_count);
      const pendingCount = getNumberValue(store.pending_documents_count);
      if (failedCount && failedCount > 0) {
        return createStoreStatus(__("Needs attention", "gpt3-ai-content-generator"), "warning");
      }
      if (pendingCount && pendingCount > 0) {
        return createStoreStatus(__("Indexing", "gpt3-ai-content-generator"), "info");
      }
      return readyStoreStatus();
    }

    if (providerKey === "pinecone" && store.status) {
      if (typeof store.status === "object") {
        if (store.status.ready === true) {
          return readyStoreStatus();
        }
        if (store.status.state) {
          return createStoreStatus(titleCaseStoreStatus(store.status.state), "warning");
        }
      }
      if (typeof store.status === "string") {
        const status = normalizeProviderKey(store.status);
        return createStoreStatus(
          titleCaseStoreStatus(store.status),
          status === "ready" || status === "completed" ? "success" : "warning"
        );
      }
    }

    if (providerKey === "qdrant" && store.status) {
      const status = normalizeProviderKey(store.status);
      if (status === "green") {
        return readyStoreStatus();
      }
      if (status === "yellow") {
        return createStoreStatus(__("Optimizing", "gpt3-ai-content-generator"), "info");
      }
      if (status === "red") {
        return createStoreStatus(__("Needs attention", "gpt3-ai-content-generator"), "warning");
      }
      return createStoreStatus(titleCaseStoreStatus(store.status), "info");
    }

    if (providerKey === "chroma" && store.status) {
      const status = normalizeProviderKey(store.status);
      return createStoreStatus(
        status === "ready" || status === "completed" || status === "ok"
          ? __("Ready", "gpt3-ai-content-generator")
          : titleCaseStoreStatus(store.status),
        status === "ready" || status === "completed" || status === "ok"
          ? "success"
          : "info"
      );
    }

    return fallbackMeta;
  }

  function getStoreDetailItems(providerKey, store) {
    if (!store || typeof store !== "object") {
      return [];
    }

    if (providerKey === "local") {
      const items = [];
      const chunks = getNumberValue(store.chunk_count);
      if (chunks !== null) {
        items.push(
          // translators: %s is the formatted number of chunks.
          sprintf(_n("%s chunk", "%s chunks", chunks, "gpt3-ai-content-generator"), chunks.toLocaleString())
        );
      }
      const formattedBytes = formatStoreBytes(store.bytes);
      if (formattedBytes && chunks) {
        items.push(formattedBytes);
      }
      if (store.dimensions) {
        items.push(`${store.dimensions} ${__("dimensions", "gpt3-ai-content-generator")}`);
      }
      return items;
    }

    if (providerKey === "openai") {
      const counts = store.file_counts || {};
      const totalFiles = getNumberValue(counts.total);
      const failedFiles = getNumberValue(counts.failed);
      const inProgressFiles = getNumberValue(counts.in_progress);
      const bytes = store.usage_bytes ?? store.bytes;
      const items = [];

      if (totalFiles !== null) {
        items.push(
          `${totalFiles.toLocaleString()} ${_n(
            "file",
            "files",
            totalFiles,
            "gpt3-ai-content-generator"
          )}`
        );
      }
      if (failedFiles) {
        items.push(
          `${failedFiles.toLocaleString()} ${__("failed", "gpt3-ai-content-generator")}`
        );
      } else if (inProgressFiles) {
        items.push(
          `${inProgressFiles.toLocaleString()} ${__("processing", "gpt3-ai-content-generator")}`
        );
      }
      const formattedBytes = formatStoreBytes(bytes);
      if (formattedBytes) {
        items.push(formattedBytes);
      }
      return items;
    }

    if (providerKey === "google") {
      const items = [];
      const activeCount = getNumberValue(store.active_documents_count);
      const pendingCount = getNumberValue(store.pending_documents_count);
      const failedCount = getNumberValue(store.failed_documents_count);
      if (activeCount !== null) {
        items.push(
          `${activeCount.toLocaleString()} ${_n(
            "document",
            "documents",
            activeCount,
            "gpt3-ai-content-generator"
          )}`
        );
      }
      if (pendingCount) {
        items.push(`${pendingCount.toLocaleString()} ${__("processing", "gpt3-ai-content-generator")}`);
      }
      if (failedCount) {
        items.push(`${failedCount.toLocaleString()} ${__("failed", "gpt3-ai-content-generator")}`);
      }
      const formattedBytes = formatStoreBytes(store.size_bytes);
      if (formattedBytes) {
        items.push(formattedBytes);
      }
      if (store.embedding_model) {
        items.push(String(store.embedding_model).replace(/^models\//, ""));
      }
      return items;
    }

    if (providerKey === "pinecone") {
      const items = [];
      const dimension = formatStoreCount(store.dimension);
      const vectorCount = formatStoreCount(
        store.total_vector_count ?? store.totalVectorCount
      );
      const region = formatStoreRegion(store);

      if (dimension) {
        items.push(
          // translators: %s is the formatted vector dimension.
          sprintf(__("%s dim", "gpt3-ai-content-generator"), dimension)
        );
      }
      if (store.metric) {
        items.push(String(store.metric));
      }
      if (vectorCount) {
        items.push(
          // translators: %s is the formatted number of vectors.
          sprintf(__("%s vectors", "gpt3-ai-content-generator"), vectorCount)
        );
      }
      if (region) {
        items.push(region);
      }
      return items;
    }

    if (providerKey === "qdrant") {
      const items = [];
      const vectorConfig = getQdrantVectorConfig(store);
      const dimension = formatStoreCount(vectorConfig.size);
      const pointCount = formatStoreCount(
        store.vectors_count ?? store.points_count ?? store.indexed_vectors_count
      );

      if (dimension) {
        items.push(
          // translators: %s is the formatted vector dimension.
          sprintf(__("%s dim", "gpt3-ai-content-generator"), dimension)
        );
      }
      if (vectorConfig.distance) {
        items.push(String(vectorConfig.distance));
      }
      if (pointCount) {
        items.push(
          // translators: %s is the formatted number of points.
          sprintf(__("%s points", "gpt3-ai-content-generator"), pointCount)
        );
      }
      if (store.optimizer_status && normalizeProviderKey(store.optimizer_status) !== "ok") {
        items.push(titleCaseStoreStatus(store.optimizer_status));
      }
      return items;
    }

    if (providerKey === "chroma") {
      const items = [];
      const dimension = formatStoreCount(store.dimension);
      const vectorCount = formatStoreCount(
        store.total_vector_count ?? store.vectors_count ?? store.count
      );
      if (dimension) {
        items.push(
          // translators: %s is the formatted vector dimension.
          sprintf(__("%s dim", "gpt3-ai-content-generator"), dimension)
        );
      }
      if (vectorCount) {
        items.push(
          // translators: %s is the formatted number of records.
          sprintf(__("%s records", "gpt3-ai-content-generator"), vectorCount)
        );
      }
      if (store.database) {
        items.push(String(store.database));
      }
      return items;
    }

    return [];
  }

  function renderStoreDetails(providerKey, store) {
    const detailItems = getStoreDetailItems(providerKey, store).filter(Boolean);
    if (!detailItems.length) {
      return "";
    }
    const title = detailItems.join(" | ");
    return `<div class="aipkit_sources_store_details" title="${escaper(title)}">
      ${detailItems
        .map((item, index) => {
          return `${index ? '<span class="aipkit_sources_store_detail_sep" aria-hidden="true"></span>' : ""}
            <span class="aipkit_sources_store_detail">${escaper(item)}</span>`;
        })
        .join("")}
    </div>`;
  }
  return { getProviderStoreId, getProviderStoreName, getStoreStatusMeta, renderStoreDetails };
}
