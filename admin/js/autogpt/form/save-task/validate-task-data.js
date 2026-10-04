/**
 * AIPKit Automated Tasks - Save Task: Validate Task Data
 * Performs validation checks on the prepared data object.
 */
(function () {
  "use strict";

  /**
   * Validates the data object before submission.
   * @param {Object} data - The prepared data object.
   * @returns {string|true} An error message string if invalid, otherwise true.
   */
  function aipkit_save_task_validateData(data) {
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};
    const needsVectorEmbedding = (provider) =>
      provider === "local" || provider === "pinecone" || provider === "qdrant" || provider === "chroma";
    const validateVectorEmbedding = (
      provider,
      embeddingProvider,
      embeddingModel
    ) => {
      if (!needsVectorEmbedding(provider)) {
        return true;
      }
      if (!embeddingProvider) {
        return (
          texts.embedding_provider_required ||
          "Embedding provider is required for this vector provider."
        );
      }
      if (!embeddingModel) {
        return (
          texts.embedding_model_required ||
          "Embedding model is required for this vector provider."
        );
      }
      return true;
    };
    const validateVectorSource = (data, prefix = "") => {
      const provider = data[`${prefix}vector_store_provider`] || "openai";
      const sourceByProvider = {
        openai: data[`${prefix}openai_vector_store_ids`],
        google: data[`${prefix}google_file_search_store_names`],
        pinecone: data[`${prefix}pinecone_index_name`],
        qdrant: data[`${prefix}qdrant_collection_name`],
        chroma: data[`${prefix}chroma_collection_name`],
        local: data[`${prefix}local_store_id`],
      };
      const source = sourceByProvider[provider];
      const hasSource = Array.isArray(source)
        ? source.some((value) => String(value || "").trim() !== "")
        : String(source || "").trim() !== "";

      if (!hasSource) {
        return (
          texts.context_source_required ||
          "Please select a knowledge source before enabling context."
        );
      }

      return true;
    };
    const countManualTopics = (value) =>
      String(value || "")
        .trim()
        .split(/\r?\n/)
        .map((line) => line.split("|")[0]?.trim() || "")
        .filter(Boolean).length;

    if (!data.task_name)
      return texts.task_name_required || "Task name is required.";
    if (!data.task_category)
      return texts.task_category_required || "Task category is required.";
    if (!data.task_type)
      return texts.task_type_required || "Task type is required.";

    const credentialIssue =
      window.aipkit_autogpt_provider_setup?.getDataCredentialIssue?.(data) ||
      "";
    if (credentialIssue) {
      return credentialIssue;
    }

    const textProvider = data.task_type === "community_reply_comments"
      ? data.cc_ai_provider : data.ai_provider;
    const textModel = data.task_type === "community_reply_comments"
      ? data.cc_ai_model : data.ai_model;
    const catalog = window.aipkit_dashboard?.models;
    if (textProvider && textModel && catalog) {
      const key = String(textProvider).toLowerCase();
      const providerModels = catalog[key] || [];
      const models = Array.isArray(providerModels)
        ? providerModels : Object.values(providerModels).flat();
      const recommended = window.aipkit_dashboard?.recommendedModels?.[key] || [];
      if (![...models, ...recommended].some((model) => model?.id === textModel)) {
        return texts.model_unavailable || "The selected model is unavailable. Select an available model before saving.";
      }
    }

    // Content Indexing specific validation
    if (data.task_type === "content_indexing") {
      if (!data.target_store_id)
        return (
          texts.target_store_required ||
          "A target vector store/index must be selected."
        );
      if (!data.post_types || data.post_types.length === 0)
        return (
          texts.content_type_required ||
          "Please select content types for indexing."
        );
      if (
        data.index_existing_now_flag !== "1" &&
        data.only_new_updated_flag !== "1"
      ) {
        return (
          texts.indexing_behavior_required ||
          "Choose whether to index existing content, keep future content in sync, or both."
        );
      }
      if (
        needsVectorEmbedding(data.target_store_provider)
      ) {
        const validationResult = validateVectorEmbedding(
          data.target_store_provider,
          data.embedding_provider,
          data.embedding_model
        );
        if (validationResult !== true) {
          return validationResult;
        }
      }
    }

    // Content Writing specific validation
    if (data.task_type.startsWith("content_writing")) {
      const mode = data.cw_generation_mode || "bulk";

      if (mode === "bulk") {
        if (!countManualTopics(data.content_title_bulk))
          return (
            texts.content_title_required_cw_task ||
            "At least one topic is required for Bulk mode."
          );
      } else if (mode === "csv") {
        if (!data.content_title?.trim())
          return (
            texts.csv_required_cw_task || "Please upload a CSV file."
          );
      } else if (mode === "rss" && !data.rss_feeds?.trim()) {
        return (
          texts.rss_required_cw_task || "Please add at least one RSS feed."
        );
      } else if (mode === "url" && !data.url_list?.trim()) {
        return texts.url_required_cw_task || "Please add at least one URL.";
      } else if (mode === "gsheets") {
        if (!data.gsheets_sheet_id?.trim()) {
          return (
            texts.gsheets_id_required_cw_task ||
            "Please add a Google Sheet ID."
          );
        }
        if (!data.gsheets_credentials?.trim()) {
          return (
            texts.gsheets_credentials_required_cw_task ||
            "Please add Google Sheets credentials."
          );
        }
      } else if (mode === "single" && !data.content_title?.trim()) {
        return (
          texts.content_title_required_cw_task ||
          "Please add a topic."
        );
      }

      if (!data.custom_content_prompt?.trim()) {
        return "The Content Prompt cannot be empty.";
      }

      if (!data.ai_provider || !data.ai_model)
        return (
          texts.ai_config_required_cw_task ||
          "AI Provider and Model are required for Content Writing task."
        );

      if (data.enable_vector_store === "1") {
        const sourceValidationResult = validateVectorSource(data);
        if (sourceValidationResult !== true) {
          return sourceValidationResult;
        }
        const validationResult = validateVectorEmbedding(
          data.vector_store_provider,
          data.vector_embedding_provider,
          data.vector_embedding_model
        );
        if (validationResult !== true) {
          return validationResult;
        }
      }
    }

    // Content Enhancement specific validation
    if (data.task_type === "enhance_existing_content") {
      if (!data.post_types || data.post_types.length === 0) {
        return "Please select at least one post type to update.";
      }
      if (
        data.update_title !== "1" &&
        data.update_excerpt !== "1" &&
        data.update_content !== "1" &&
        data.update_meta !== "1"
      ) {
        return "Please select at least one field to update (Title, Excerpt, Content, or Meta Description).";
      }

      if (data.enable_vector_store === "1") {
        const sourceValidationResult = validateVectorSource(data);
        if (sourceValidationResult !== true) {
          return sourceValidationResult;
        }
        const validationResult = validateVectorEmbedding(
          data.vector_store_provider,
          data.vector_embedding_provider,
          data.vector_embedding_model
        );
        if (validationResult !== true) {
          return validationResult;
        }
      }
    }

    if (data.task_type === "community_reply_comments") {
      if (
        !Array.isArray(data.post_types_for_comments) ||
        data.post_types_for_comments.length === 0
      ) {
        return (
          texts.comment_post_type_required ||
          "Please select at least one content type to monitor."
        );
      }
      if (!data.cc_ai_provider || !data.cc_ai_model) {
        return (
          texts.comment_ai_config_required ||
          "AI Provider and Model are required for Comment Replies."
        );
      }
      if (!String(data.cc_custom_content_prompt || "").trim()) {
        return (
          texts.comment_prompt_required ||
          "The reply prompt cannot be empty."
        );
      }
    }

    return true; // All checks passed
  }

  window.aipkit_save_task_validateData = aipkit_save_task_validateData;
})();
