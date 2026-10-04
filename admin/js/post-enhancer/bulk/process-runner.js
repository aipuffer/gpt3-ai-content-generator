/**
 * AIPKit Content Enhancer - Bulk Process Runner
 */
(function () {
  "use strict";

  const contentLengthOptions = [
    { key: "short", label: "Short", words: "600-800", tokens: 2000 },
    { key: "medium", label: "Medium", words: "1200-1600", tokens: 4000 },
    { key: "long", label: "Long", words: "2000-2500", tokens: 6000 },
  ];

  const resolveContentLengthOption = (rawValue) => {
    const numeric = parseInt(rawValue, 10);
    if (Number.isFinite(numeric)) {
      if (numeric >= 1 && numeric <= contentLengthOptions.length) {
        return contentLengthOptions[numeric - 1];
      }
      const tokenMatch = contentLengthOptions.find(
        (option) => option.tokens === numeric
      );
      if (tokenMatch) {
        return tokenMatch;
      }
    }
    return contentLengthOptions[1];
  };

  // A closed run must never update or continue inside a newly opened modal.
  const isCurrentRun = (state) => state === window.aipkit_enhancer_bulkState && state.modal?.isConnected && state.modal.classList.contains("aipkit-active");

  /** Processes the next post in this run's queue. */
  async function processNextPostInQueue(state) {
    if (!isCurrentRun(state)) return;
    if (
      !state.isRunning ||
      state.queue.length === 0
    ) {
      if (typeof window.aipkit_enhancer_stopBulkProcess === "function") {
        window.aipkit_enhancer_stopBulkProcess("complete");
      }
      return;
    }

    const postId = state.queue.shift();
    state.isProcessing = true;
    try {
      await processPostStepByStep(postId, state);
    } finally {
      state.isProcessing = false;
    }
    if (!isCurrentRun(state)) return;

    // Update progress bar after processing an item
    if (typeof window.aipkit_enhancer_updateProgressBar === "function") {
      window.aipkit_enhancer_updateProgressBar();
    }

    // Process the next item after a short delay
    if (state.isRunning) setTimeout(() => processNextPostInQueue(state), 200);
  }

  /**
   * Process a single post with step-by-step field enhancement
   * @param {number} postId The post ID to process
   * @param {object} state The run that owns this request and its counters.
   */
  async function processPostStepByStep(postId, state) {
    try {
  // Create a single conversation UUID for this post's bulk generation so all steps aggregate in one Admin Log record
  const convUuid = `enhancer-bulk-${postId}-${Date.now()}`;
      // Initialize step progress tracking
      if (typeof window.aipkit_enhancer_initStepProgress === "function") {
        window.aipkit_enhancer_initStepProgress(postId);
      }

      const nonce = window.aipkit_post_enhancer?.nonce_generate_title;
      if (!nonce) throw new Error("Security nonce not found.");

      const enhancementsConfig = state.enhancementsConfig;
      
      // Define the processing order (keyword first, then others)
      const fieldOrder = ['keyword', 'title', 'excerpt', 'content', 'meta', 'tags'];
      const fieldsToProcess = [];
      
      // Build list of fields to process
      fieldOrder.forEach(field => {
        if (enhancementsConfig[field] && enhancementsConfig[field].prompt) {
          fieldsToProcess.push(field);
        }
      });

      let processedFields = [];
      let failedFields = [];

      // Process each field individually
      for (const field of fieldsToProcess) {
        if (!state.isRunning || !isCurrentRun(state)) {
          break; // Stop if user cancelled
        }

        try {
          // Update UI to show current field processing
          if (typeof window.aipkit_enhancer_updateFieldStatus === "function") {
            window.aipkit_enhancer_updateFieldStatus(postId, field, 'processing');
          }

          // Prepare request data for single field
          const requestData = {
            _ajax_nonce: nonce,
            post_id: postId,
            field: field,
            enhancer_source: "post_enhancer",
            enhancements: JSON.stringify({
              [field]: enhancementsConfig[field],
              ai_provider: enhancementsConfig.ai_provider,
              ai_model: enhancementsConfig.ai_model,
              temperature: enhancementsConfig.temperature,
              top_p: enhancementsConfig.top_p,
              max_tokens: enhancementsConfig.max_tokens,
              reasoning_effort: enhancementsConfig.reasoning_effort,
              enable_vector_store: enhancementsConfig.enable_vector_store,
              vector_store_provider: enhancementsConfig.vector_store_provider,
              vector_store_top_k: enhancementsConfig.vector_store_top_k,
              openai_vector_store_ids: enhancementsConfig.openai_vector_store_ids,
              google_file_search_store_names: enhancementsConfig.google_file_search_store_names,
              pinecone_index_name: enhancementsConfig.pinecone_index_name,
              qdrant_collection_name: enhancementsConfig.qdrant_collection_name,
              chroma_collection_name: enhancementsConfig.chroma_collection_name,
              local_store_id: enhancementsConfig.local_store_id,
              vector_embedding_provider: enhancementsConfig.vector_embedding_provider,
              vector_embedding_model: enhancementsConfig.vector_embedding_model
            }),
            conversation_uuid: convUuid
          };

          // Make API call for this specific field
          const response = await window.aipkit_apiRequest(
            "aipkit_bulk_process_single_field",
            requestData,
            { timeout: 180000 } // 3 minutes timeout per field
          );
          if (!isCurrentRun(state)) return;

          // Update UI to show completion
          if (typeof window.aipkit_enhancer_updateFieldStatus === "function") {
            window.aipkit_enhancer_updateFieldStatus(postId, field, 'completed', response.message);
          }

          processedFields.push(field);

        } catch (error) {
          if (!isCurrentRun(state)) return;
          // Update UI to show error
          if (typeof window.aipkit_enhancer_updateFieldStatus === "function") {
            window.aipkit_enhancer_updateFieldStatus(postId, field, 'error', error.message || 'Processing failed', error.data?.generated_value);
          }
          
          failedFields.push(field);
          if (error.data?.stop_batch) {
            state.stopReason = error.message || 'Processing stopped.';
            window.aipkit_enhancer_stopBulkProcess?.('error');
            state.isRunning = false;
            break;
          }
          console.error(`Failed to process field ${field} for post ${postId}:`, error);
        }
      }

      // Process URL update if enabled and we have successful fields
      if (!isCurrentRun(state)) return;
      if (state.isRunning && enhancementsConfig.generate_seo_slug === '1' && processedFields.length > 0) {
        try {
          if (typeof window.aipkit_enhancer_updateFieldStatus === "function") {
            window.aipkit_enhancer_updateFieldStatus(postId, 'slug', 'processing');
          }

          const slugRequestData = {
            _ajax_nonce: nonce,
            post_id: postId,
            action_type: 'update_slug',
            enhancer_source: "post_enhancer"
          };

          await window.aipkit_apiRequest(
            "aipkit_bulk_update_seo_slug",
            slugRequestData,
            { timeout: 30000 } // 30 seconds for slug update
          );
          if (!isCurrentRun(state)) return;

          if (typeof window.aipkit_enhancer_updateFieldStatus === "function") {
            window.aipkit_enhancer_updateFieldStatus(postId, 'slug', 'completed', 'URL updated');
          }

        } catch (error) {
          if (!isCurrentRun(state)) return;
          if (typeof window.aipkit_enhancer_updateFieldStatus === "function") {
            window.aipkit_enhancer_updateFieldStatus(postId, 'slug', 'error', 'URL update failed');
          }
        }
      }

      // Show completion message
      if (typeof window.aipkit_enhancer_showPostCompletion === "function") {
        window.aipkit_enhancer_showPostCompletion(postId, processedFields, failedFields);
      }

      // Update counters
      if (failedFields.length > 0) {
        state.failed++;
      } else if (processedFields.length === fieldsToProcess.length) {
        state.completed++;
      }

    } catch (error) {
      if (!isCurrentRun(state)) return;
      state.failed++;
      
      if (typeof window.aipkit_enhancer_showStepError === "function") {
        window.aipkit_enhancer_showStepError(postId, error.message || "Unknown error");
      }
      
    }
  }

  /**
   * Starts the bulk processing of selected posts.
   * @param {string[]} postIds Array of post IDs to process.
   */
  function aipkit_enhancer_startBulkProcess(postIds) {
    const modal = document.querySelector(".aipkit-enhancer-bulk-modal-overlay");
    if (!modal) return;
    const previousRun = window.aipkit_enhancer_bulkState;
    if (previousRun?.modal === modal && (previousRun.isRunning || previousRun.isProcessing)) return;

    // Gather enhancement configuration
    const enhancementsConfig = {};
    const checkedFields = modal.querySelectorAll(
      'input[name="bulk_enhance_fields"]:checked'
    );

    if (checkedFields.length === 0) {
      if (typeof window.aipkit_enhancer_setModalStatus === "function") {
        window.aipkit_enhancer_setModalStatus(
          "Please select at least one field to update.",
          "error"
        );
      }
      return;
    }

    checkedFields.forEach((checkbox) => {
      const fieldType = checkbox.value;
      const promptTextarea = modal.querySelector(
        `#aipkit_bulk_prompt_${fieldType}`
      );
      if (promptTextarea) {
        enhancementsConfig[fieldType] = { prompt: promptTextarea.value };
      }
    });

    const aiProvider = modal.querySelector("#aipkit_bulk_ai_provider").value;
    const aiModel = modal.querySelector("#aipkit_bulk_ai_model").value;
    const temperature = modal.querySelector(
      "#aipkit_bulk_ai_temperature"
    ).value;
    const topP = modal.querySelector("#aipkit_bulk_ai_top_p")?.value || "1";
    const maxTokensInput = modal.querySelector(
      "#aipkit_bulk_content_max_tokens"
    );
    const contentLengthOption = resolveContentLengthOption(
      maxTokensInput ? maxTokensInput.value : ""
    );
    const reasoningEffort = modal.querySelector(
      "#aipkit_bulk_reasoning_effort"
    ).value;

    if (!aiProvider || !aiModel) {
      if (typeof window.aipkit_enhancer_setModalStatus === "function") {
        window.aipkit_enhancer_setModalStatus(
          "Please select an AI Provider and Model.",
          "error"
        );
      }
      return;
    }

    enhancementsConfig.ai_provider = aiProvider;
    enhancementsConfig.ai_model = aiModel;
    enhancementsConfig.temperature = temperature;
    enhancementsConfig.top_p = topP;
    enhancementsConfig.max_tokens = contentLengthOption.tokens;
    enhancementsConfig.reasoning_effort = reasoningEffort;

    const generateSeoSlugCheckbox = modal.querySelector(
      "#aipkit_bulk_generate_seo_slug"
    );
    if (generateSeoSlugCheckbox) {
      enhancementsConfig.generate_seo_slug = generateSeoSlugCheckbox.checked
        ? "1"
        : "0";
    }

    const enableVectorCheckbox = modal.querySelector(
      'input[name="enable_vector_store"]'
    );
    if (enableVectorCheckbox && enableVectorCheckbox.checked) {
      enhancementsConfig.enable_vector_store = "1";
      enhancementsConfig.vector_store_provider =
        modal.querySelector("#aipkit_bulk_vector_store_provider")?.value ||
        "openai";
      enhancementsConfig.vector_store_top_k =
        modal.querySelector("#aipkit_bulk_vector_store_top_k")?.value || 3;

      if (enhancementsConfig.vector_store_provider === "openai") {
        const openaiSelect = modal.querySelector(
          "#aipkit_bulk_openai_vector_store_ids"
        );
        if (openaiSelect) {
          enhancementsConfig.openai_vector_store_ids = Array.from(
            openaiSelect.selectedOptions
          ).map((opt) => opt.value);
        }
      } else if (enhancementsConfig.vector_store_provider === "google") {
        const googleSelect = modal.querySelector(
          "#aipkit_bulk_google_file_search_store_names"
        );
        if (googleSelect) {
          enhancementsConfig.google_file_search_store_names = Array.from(
            googleSelect.selectedOptions
          ).map((opt) => opt.value);
        }
      } else if (enhancementsConfig.vector_store_provider === "pinecone") {
        enhancementsConfig.pinecone_index_name =
          modal.querySelector("#aipkit_bulk_pinecone_index_name")?.value || "";
      } else if (enhancementsConfig.vector_store_provider === "qdrant") {
        enhancementsConfig.qdrant_collection_name =
          modal.querySelector("#aipkit_bulk_qdrant_collection_name")?.value ||
          "";
      } else if (enhancementsConfig.vector_store_provider === "chroma") {
        enhancementsConfig.chroma_collection_name =
          modal.querySelector("#aipkit_bulk_chroma_collection_name")?.value ||
          "";
      } else if (enhancementsConfig.vector_store_provider === "local") {
        enhancementsConfig.local_store_id =
          modal.querySelector("#aipkit_bulk_local_store_id")?.value || "";
      }

      if (
        enhancementsConfig.vector_store_provider === "pinecone" ||
        enhancementsConfig.vector_store_provider === "qdrant" ||
        enhancementsConfig.vector_store_provider === "chroma" ||
        enhancementsConfig.vector_store_provider === "local"
      ) {
        enhancementsConfig.vector_embedding_provider =
          modal.querySelector("#aipkit_bulk_vector_embedding_provider")
            ?.value || "openai";
        enhancementsConfig.vector_embedding_model =
          modal.querySelector("#aipkit_bulk_vector_embedding_model")?.value ||
          "";
      }
    } else {
      enhancementsConfig.enable_vector_store = "0";
    }

    if (typeof window.aipkit_enhancer_setModalStatus === "function") {
      window.aipkit_enhancer_setModalStatus("");
    }

    // Initialize state
    window.aipkit_enhancer_bulkState = {
      modal,
      queue: [...postIds],
      isRunning: true,
      isProcessing: false,
      completed: 0,
      failed: 0,
      total: postIds.length,
      enhancementsConfig: enhancementsConfig,
    };

    // Update UI
    modal.querySelector("#aipkit-enhancer-bulk-config").hidden = true;
    modal.querySelector("#aipkit-enhancer-bulk-progress").hidden = false;
    const startButton = modal.querySelector("#aipkit_bulk_enhancer_start_btn");
    if (startButton) {
      startButton.disabled = true;
      startButton.hidden = true;
    }
    modal.querySelector("#aipkit_bulk_enhancer_stop_btn").hidden = false;
    const cancelButton = modal.querySelector(
      "#aipkit_bulk_enhancer_cancel_btn"
    );
    if (cancelButton) {
      cancelButton.hidden = true;
    }
    modal.querySelector("#aipkit_bulk_enhancer_back_to_settings_btn").hidden =
      true;

    // Initialize progress display
    if (typeof window.aipkit_enhancer_updateProgressBar === "function") {
      window.aipkit_enhancer_updateProgressBar();
    }

    // Start the process
    processNextPostInQueue(window.aipkit_enhancer_bulkState);
  }

  window.aipkit_enhancer_startBulkProcess = aipkit_enhancer_startBulkProcess;
})();
