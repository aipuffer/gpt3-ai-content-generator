/** WordPress website indexing for the shared Knowledge Base Sources module. */
export async function indexKnowledgeBaseWebsite({
  websiteSettings, target, embedding,
  getNonceValue, trainingApiRequest, throwIfTrainingStopped,
  setTrainingStatus, setWebsiteStatus, clearTrainingStatusSoon,
  fetchSources, pollGoogleFileSearchJob, setTrainingDismissBaseline,
  __, _n, sprintf,
}) {
  const fetchBulkPostIds = async (postTypes, statusValue, storeId) => {
    const nonce = getNonceValue("aipkit_wp_content_fetch_nonce");
    if (!nonce) {
      throw new Error("WP content fetch nonce missing.");
    }
    const items = [];
    let page = 1;
    let totalPages = 1;
    do {
      throwIfTrainingStopped();
      const response = await trainingApiRequest(
        "aipkit_fetch_wp_content_for_indexing",
        {
          _ajax_nonce: nonce,
          post_types: postTypes,
          post_status: statusValue,
          target_store_id: storeId || "",
          paged: page,
        }
      );
      throwIfTrainingStopped();
      const posts = Array.isArray(response?.posts) ? response.posts : [];
      posts.forEach((post) => {
        items.push({
          id: String(post.id),
          title:
            post.title ||
            // translators: %s: WordPress post ID.
            sprintf(__("Post %s", "gpt3-ai-content-generator"), post.id),
        });
      });
      const total = parseInt(response?.pagination?.total_pages || 1, 10);
      totalPages = Number.isNaN(total) ? 1 : total;
      page += 1;
    } while (page <= totalPages);
    return items;
  };

  const formatTrainingItemStatus = (postId, position, total, titleMap) => {
    const fallbackTitle = sprintf(
      // translators: %s: WordPress post ID.
      __("Post %s", "gpt3-ai-content-generator"),
      postId
    );
    const title =
      (titleMap && titleMap.get(String(postId))) || fallbackTitle;
    return sprintf(
      // translators: 1: Post title, 2: Current item position, 3: Total items.
      __("Adding “%1$s” — %2$d of %3$d", "gpt3-ai-content-generator"),
      title,
      position + 1,
      total
    );
  };

  const { statusValue, postTypes } = websiteSettings;
  setTrainingStatus(
    __("Preparing website content", "gpt3-ai-content-generator"),
    "loading"
  );
  const bulkItems = await fetchBulkPostIds(
    postTypes,
    statusValue,
    target.storeId
  );
  const postIds = bulkItems.map((item) => item.id);
  const titleMap = new Map(
    bulkItems.map((item) => [String(item.id), item.title])
  );
  if (!postIds.length) {
    setWebsiteStatus(
      __("No published content found for these types.", "gpt3-ai-content-generator"),
      "error"
    );
    setTrainingStatus(
      __("Nothing to add.", "gpt3-ai-content-generator"),
      "warning"
    );
    clearTrainingStatusSoon();
    return;
  }

  setTrainingStatus(sprintf(
      // translators: %s: Number of website items being indexed.
      _n("Adding %s item", "Adding %s items", postIds.length, "gpt3-ai-content-generator"),
      postIds.length.toLocaleString()
    ), "loading");
  setWebsiteStatus("", "");
  throwIfTrainingStopped();

  const provider = target.providerKey;
  const isVector = ["pinecone", "qdrant", "chroma", "local"].includes(provider);
  if (provider === "openai" || provider === "google" || isVector) {
    const nonceId = provider === "openai"
      ? "aipkit_wp_content_index_nonce"
      : provider === "google" ? "aipkit_google_file_search_nonce" : "aipkit_index_posts_nonce";
    const nonce = getNonceValue(nonceId);
    if (!nonce) {
      if (provider === "openai") {
        throw new Error(__("WP content index nonce missing.", "gpt3-ai-content-generator"));
      }
      if (provider === "google") {
        throw new Error(__("Google File Search nonce missing.", "gpt3-ai-content-generator"));
      }
      throw new Error(__("Vector indexing nonce missing.", "gpt3-ai-content-generator"));
    }
    const action = provider === "openai"
      ? "aipkit_index_selected_wp_content"
      : provider === "google" ? "aipkit_index_wp_content_google_file_search" : "aipkit_index_posts_to_vector_store";
    for (let idx = 0; idx < postIds.length; idx += 1) {
      throwIfTrainingStopped();
      const postId = postIds[idx];
      setTrainingStatus(
        formatTrainingItemStatus(postId, idx, postIds.length, titleMap),
        "loading"
      );
      const payload = { _ajax_nonce: nonce, post_ids: [postId] };
      if (provider === "local") {
        payload.embedding_provider = embedding.provider;
        payload.embedding_model = embedding.model;
        payload.provider = provider;
        payload.target_store_id = target.storeId;
      } else if (isVector) {
        payload.provider = provider;
        payload[provider === "pinecone" ? "target_index_id" : "target_collection_name"] = target.storeId;
        payload.embedding_provider = embedding.provider;
        payload.embedding_model = embedding.model;
      } else {
        payload.target_store_id = target.storeId;
        if (provider === "openai") payload.provider = "openai";
      }
      const response = await trainingApiRequest(action, payload);
      if (provider === "google") {
        (Array.isArray(response?.jobs) ? response.jobs : []).forEach((job) => {
          if (job?.job_id) pollGoogleFileSearchJob(job.job_id);
        });
      }
      await fetchSources(1, { silent: true });
      throwIfTrainingStopped();
    }
  }
  throwIfTrainingStopped();

  setTrainingStatus(
    ['openai', 'google'].includes(target.providerKey)
      ? __("Content submitted. Indexing continues in the background; source statuses update automatically.", "gpt3-ai-content-generator")
      : __("Content added to knowledge base.", "gpt3-ai-content-generator"),
    ['openai', 'google'].includes(target.providerKey) ? "info" : "success"
  );
  setWebsiteStatus("", "");
  setTrainingDismissBaseline();
  clearTrainingStatusSoon();
  fetchSources(1);
  return;
}
