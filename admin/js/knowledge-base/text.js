const VECTOR_REQUESTS = {
  pinecone: {
    nonce: "aipkit_vector_store_pinecone_nonce_management",
    action: "aipkit_upsert_to_pinecone_index",
    target: "index_name",
  },
  qdrant: {
    nonce: "aipkit_vector_store_qdrant_nonce_management",
    action: "aipkit_upsert_to_qdrant_collection",
    target: "collection_name",
  },
  chroma: {
    nonce: "aipkit_vector_store_chroma_nonce_management",
    action: "aipkit_upsert_to_chroma_collection",
    target: "collection_name",
  },
};

function getVectorNonce(provider, getNonceValue, __) {
  const nonce = getNonceValue(VECTOR_REQUESTS[provider].nonce);
  if (nonce) return nonce;
  if (provider === "pinecone") {
    throw new Error(__("Pinecone nonce missing.", "gpt3-ai-content-generator"));
  }
  if (provider === "qdrant") {
    throw new Error(__("Qdrant nonce missing.", "gpt3-ai-content-generator"));
  }
  throw new Error(__("Chroma nonce missing.", "gpt3-ai-content-generator"));
}

/** Use the embedding model selected for this ingestion request. */
async function addLocalText(storeId, text, sourceType, getNonceValue, request, __, embedding) {
  const nonce = getNonceValue("aipkit_vector_store_local_nonce_management");
  if (!nonce) {
    throw new Error(__("site knowledge base nonce missing.", "gpt3-ai-content-generator"));
  }
  await request("aipkit_local_add_text", {
    _ajax_nonce: nonce,
    store_id: storeId,
    embedding_provider: embedding.provider,
    embedding_model: embedding.model,
    text_content: text,
    source_type: sourceType,
    vector_id: `text_${Date.now()}`,
  });
}

/** Text and Q&A requests for the shared Knowledge Base Sources module. */
export async function indexKnowledgeBaseText({
  textValue, target, embedding, generateQdrantPointId,
  getNonceValue, trainingApiRequest, pollGoogleFileSearchJob, __,
}) {
  const provider = target.providerKey;
  const sourceType = "text_entry_global_form";
  if (provider === "openai" || provider === "google") {
    const isGoogle = provider === "google";
    const nonce = getNonceValue(isGoogle
      ? "aipkit_google_file_search_nonce"
      : "aipkit_vector_store_nonce_openai");
    if (!nonce) {
      throw new Error(isGoogle
        ? __("Google File Search nonce missing.", "gpt3-ai-content-generator")
        : __("OpenAI vector store nonce missing.", "gpt3-ai-content-generator"));
    }
    const response = await trainingApiRequest(isGoogle
      ? "aipkit_add_text_to_google_file_search"
      : "aipkit_add_text_to_vector_store_openai", {
        _ajax_nonce: nonce,
        target_store_id: target.storeId,
        text_content: textValue,
        source_type: sourceType,
      });
    if (isGoogle && response?.job_id && response?.status !== "indexed") {
      pollGoogleFileSearchJob(response.job_id);
    }
    return;
  }

  if (provider === "local") {
    await addLocalText(target.storeId, textValue, sourceType, getNonceValue, trainingApiRequest, __, embedding);
    return;
  }
  if (!["pinecone", "qdrant", "chroma"].includes(provider)) return;
  const vectorId = provider === "chroma"
    ? typeof window.aipkit_generateUUIDv4 === "function"
      ? window.aipkit_generateUUIDv4()
      : `text_${Date.now()}`
    : generateQdrantPointId();
  const metadata = {
    source: sourceType,
    created_at: new Date().toISOString(),
    original_content: textValue,
    vector_id: vectorId,
  };
  const storeId = target.storeId;
  const request = VECTOR_REQUESTS[provider];
  const nonce = getVectorNonce(provider, getNonceValue, __);
  const payload = {
    _ajax_nonce: nonce,
    text_content: textValue,
    metadata: JSON.stringify(metadata),
    embedding_provider: embedding.provider,
    embedding_model: embedding.model,
    original_text_content: textValue,
    [request.target]: storeId,
    source_type: sourceType,
  };
  if (provider !== "pinecone") payload.source_context = "sources_module_training";
  await trainingApiRequest(request.action, payload);
}

/** Replacement requests for edited sources; the caller owns deletion and feedback. */
export function createKnowledgeBaseTextEditor({ getNonceValue, generateQdrantPointId, __ }) {
  return async (providerLabel, storeId, text, embeddingProvider, embeddingModel) => {
    const provider = providerLabel.toLowerCase();
    const sourceType = "text_entry_global_form";
    if (provider === "openai" || provider === "google") {
      const isGoogle = provider === "google";
      const nonce = getNonceValue(isGoogle
        ? "aipkit_google_file_search_nonce"
        : "aipkit_vector_store_nonce_openai");
      if (!nonce) {
        throw new Error(isGoogle
          ? __("Google File Search nonce missing.", "gpt3-ai-content-generator")
          : __("OpenAI nonce missing.", "gpt3-ai-content-generator"));
      }
      await window.aipkit_apiRequest(isGoogle
        ? "aipkit_add_text_to_google_file_search"
        : "aipkit_add_text_to_vector_store_openai", {
          _ajax_nonce: nonce,
          target_store_id: storeId,
          text_content: text,
          source_type: sourceType,
        });
      return;
    }
    if (provider === "local") {
      await addLocalText(storeId, text, sourceType, getNonceValue, window.aipkit_apiRequest, __, {provider: embeddingProvider, model: embeddingModel});
      return;
    }
    if (!embeddingProvider || !embeddingModel) {
      throw new Error(__("Select an embedding model.", "gpt3-ai-content-generator"));
    }
    if (!["pinecone", "qdrant", "chroma"].includes(provider)) {
      throw new Error(__("Unsupported provider for edit.", "gpt3-ai-content-generator"));
    }
    const request = VECTOR_REQUESTS[provider];
    const nonce = getVectorNonce(provider, getNonceValue, __);
    const vectorId = provider === "pinecone" ? `text_${Date.now()}` : generateQdrantPointId();
    const payload = {
      _ajax_nonce: nonce,
      text_content: text,
      metadata: JSON.stringify({
        source: sourceType,
        created_at: new Date().toISOString(),
        vector_id: vectorId,
      }),
      embedding_provider: embeddingProvider,
      embedding_model: embeddingModel,
      original_text_content: text,
      [request.target]: storeId,
    };
    if (provider !== "pinecone") payload.source_context = "sources_module_edit";
    await window.aipkit_apiRequest(request.action, payload);
  };
}
