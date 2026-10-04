/**
AIPKit Content Writer - Attach Generate Button Listener
Encapsulates the logic for the primary Content Writer action button.
*/
(function () {
  "use strict";
  const formatApiError = (error) => {
    if (!error) {
      return "Error";
    }
    const message = error.message || "Error";
    const details = error.details || error.data?.details;
    const provider = details?.provider;
    const model = details?.model;
    if (provider || model) {
      return `${message} (${[provider, model].filter(Boolean).join(" \u00b7 ")})`;
    }
    return message;
  };

  /**
Attaches the main click event listener to the generate button.
@param {HTMLButtonElement} generateBtn - The generate button element.
@param {HTMLFormElement} form - The main content writer form.
@param {HTMLElement} indicatorsContainer - The container for status indicators.
@param {object} eventSourceInstanceRef - Reference object for the EventSource instance.
*/
  function aipkit_attachGenerateButtonListener(
    generateBtn,
    form,
    indicatorsContainer,
    eventSourceInstanceRef
  ) {
    if (!generateBtn) return;
    if (!generateBtn.dataset.listenerAttached) {
      generateBtn.addEventListener("click", async () => {
        if (generateBtn.dataset.aipkitStopMode === "true") {
          if (typeof window.aipkit_handleStopGeneration === "function") {
            window.aipkit_handleStopGeneration(eventSourceInstanceRef);
          }
          return;
        }
        if (typeof window.aipkit_setContentWriterStopRequested === "function") {
          window.aipkit_setContentWriterStopRequested(false);
        }
        // Start a fresh generation session: clear any previous conversation UUID
        try {
          delete window.aipkit_current_conversation_uuid;
          sessionStorage.removeItem("aipkit_current_conversation_uuid");
        } catch (e) {}
        if (typeof window.aipkit_clearContentWriterImagePreview === "function") {
          window.aipkit_clearContentWriterImagePreview();
        }
        delete window.aipkit_regenerateContentWriterImage;
        const imageDataHolder = document.getElementById(
          "aipkit_cw_image_data_holder"
        );
        if (imageDataHolder) {
          imageDataHolder.value = "";
        }
        // --- Handle Upgrade Action ---
        if (generateBtn.dataset.action === "upgrade") {
          if (window.aipkit_dashboard?.upgradeUrl) {
            window.open(
              window.aipkit_dashboard.upgradeUrl,
              "_blank",
              "noopener,noreferrer"
            );
          } else {
            console.error("Content Writer upgrade URL is unavailable.");
          }
          return;
        }

        // --- Handle fetch feeds action (RSS) ---
        if (generateBtn.dataset.action === "fetch_feeds") {
          if (typeof window.aipkit_handleRssFetchFromGenerateButton === "function") {
            window.aipkit_handleRssFetchFromGenerateButton(generateBtn);
          } else {
            console.error("Fetch feeds: Handler function not found.");
          }
          return;
        }

        const statusDiv = document.getElementById(
          "aipkit_content_writer_form_status"
        );
        const validationStatus = document.getElementById(
          "aipkit_cw_action_validation"
        );
        const requestedAction =
          generateBtn.dataset.aipkitRequestedAction || "";
        if (requestedAction) {
          delete generateBtn.dataset.aipkitRequestedAction;
        }
        const rawAction =
          requestedAction || generateBtn.dataset.action || "generate";
        const action = rawAction === "generate_single" ? "generate" : rawAction;
        const modeSelect = document.getElementById("aipkit_cw_mode_select");
        let activeMode = modeSelect ? modeSelect.value : "task";
        if (activeMode === "single") {
          activeMode = "task";
        }
        if (activeMode === "existing" || activeMode.indexOf("existing-") === 0) {
          if (
            typeof window.aipkit_runContentWriterExistingUpdate === "function"
          ) {
            window.aipkit_runContentWriterExistingUpdate();
          } else {
            const message =
              "Update panel is not ready yet. Please refresh the page and try again.";
            if (typeof window.aipkit_showAlertModal === "function") {
              window.aipkit_showAlertModal(message, { title: "Update existing" });
            } else {
              alert(message);
            }
          }
          return;
        }

        const getLines = (value) =>
          String(value || "")
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean);

        const parseBulkLine = (line) => {
          const parts = String(line || "")
            .split("|")
            .map((part) => part.trim());
          return {
            topic: parts[0] || "",
            keywords: parts[1] || "",
            category: parts[2] || "",
            authorLogin: parts[3] || "",
            type: parts[4] || "",
            schedule: parts[5] || "",
          };
        };

        const getSingleBulkRowData = () => {
          const rows = Array.from(
            form.querySelectorAll("[data-aipkit-bulk-row]")
          );
          for (const row of rows) {
            const topicField = row.querySelector('[data-bulk-field="topic"]');
            const topic = topicField ? topicField.value.trim() : "";
            if (!topic) continue;
            const keywords =
              row.querySelector('[data-bulk-field="keywords"]')?.value.trim() ||
              "";
            const category =
              row.querySelector('[data-bulk-field="category"]')?.value.trim() ||
              "";
            const authorSelect = row.querySelector(
              '[data-bulk-field="author"]'
            );
            const authorLogin = authorSelect ? authorSelect.value.trim() : "";
            const authorId =
              authorSelect?.selectedOptions?.[0]?.dataset?.userId || "";
            const type =
              row.querySelector('[data-bulk-field="type"]')?.value.trim() || "";
            const schedule =
              row
                .querySelector('[data-bulk-field="schedule"]')
                ?.value.trim() || "";
            return {
              topic,
              keywords,
              category,
              authorLogin,
              authorId,
              type,
              schedule,
            };
          }
          return null;
        };

        const applySingleRowOverrides = (rowData) => {
          if (!rowData || !rowData.topic) return;
          const titleInput = document.getElementById(
            "aipkit_content_writer_title"
          );
          const contentTitle = rowData.keywords
            ? `${rowData.topic} | ${rowData.keywords}`
            : rowData.topic;
          if (titleInput) {
            titleInput.value = contentTitle;
            titleInput.dispatchEvent(new Event("change", { bubbles: true }));
          }

          const postTypeSelect = form.elements["post_type"];
          if (postTypeSelect && rowData.type) {
            postTypeSelect.value = rowData.type;
            postTypeSelect.dispatchEvent(new Event("change", { bubbles: true }));
          }

          const categoriesSelect = form.elements["post_categories[]"];
          if (categoriesSelect && rowData.category) {
            Array.from(categoriesSelect.options).forEach((option) => {
              option.selected = option.value === rowData.category;
            });
            categoriesSelect.dispatchEvent(
              new Event("change", { bubbles: true })
            );
            if (typeof window.aipkit_initCategoryDropdowns === "function") {
              window.aipkit_initCategoryDropdowns(form);
            }
          }

          const authorSelect = form.elements["post_author"];
          if (authorSelect) {
            let authorValue = rowData.authorId || "";
            if (!authorValue && rowData.authorLogin) {
              const match = Array.from(authorSelect.options).find(
                (option) =>
                  option.dataset.login === rowData.authorLogin ||
                  option.value === rowData.authorLogin
              );
              if (match) {
                authorValue = match.value;
              }
            }
            if (authorValue) {
              authorSelect.value = authorValue;
              authorSelect.dispatchEvent(
                new Event("change", { bubbles: true })
              );
            }
          }
        };

        if (action !== "upgrade") {
          const validation =
            typeof window.aipkit_validateContentWriterInputs === "function"
              ? window.aipkit_validateContentWriterInputs(form, activeMode)
              : { isValid: true };
          if (!validation.isValid) {
            if (
              typeof window.aipkit_clearContentWriterValidationMessage ===
              "function"
            ) {
              window.aipkit_clearContentWriterValidationMessage(statusDiv);
              window.aipkit_clearContentWriterValidationMessage(
                validationStatus
              );
            }
            if (
              typeof window.aipkit_clearContentWriterTopicValidation ===
              "function"
            ) {
              window.aipkit_clearContentWriterTopicValidation();
            }
            if (
              typeof window.aipkit_clearContentWriterManualTopicValidation ===
              "function"
            ) {
              window.aipkit_clearContentWriterManualTopicValidation();
            }
            if (
              typeof window.aipkit_showContentWriterValidationError ===
              "function"
            ) {
              window.aipkit_showContentWriterValidationError(
                validationStatus,
                validation.message,
                validation.field
              );
            } else if (validationStatus) {
              validationStatus.textContent = validation.message || "";
              validationStatus.className =
                "aipkit_cw_status_badge aipkit_settings_message-error";
            }
            return;
          }
        }
        if (
          typeof window.aipkit_clearContentWriterValidationMessage === "function"
        ) {
          window.aipkit_clearContentWriterValidationMessage(statusDiv);
          window.aipkit_clearContentWriterValidationMessage(validationStatus);
          if (
            typeof window.aipkit_clearContentWriterTopicValidation ===
            "function"
          ) {
            window.aipkit_clearContentWriterTopicValidation();
          }
          if (
            typeof window.aipkit_clearContentWriterManualTopicValidation ===
            "function"
          ) {
            window.aipkit_clearContentWriterManualTopicValidation();
          }
        } else if (validationStatus) {
          validationStatus.textContent = "";
        }

        if (action === "generate" || action === "create_task") {
          const promptRoot =
            form.querySelector("[data-aipkit-cw-inline-prompts]") ||
            document.querySelector(
              "#aipkit_content_writer_container [data-aipkit-cw-inline-prompts]"
            );
          const promptRequirementApi =
            promptRoot?.aipkitPromptRequirementApi || null;
          const validatePromptRequirements =
            promptRequirementApi?.validate ||
            window.aipkit_validateContentWriterPromptRequirements;
          if (typeof validatePromptRequirements !== "function") {
            const message =
              "Prompt validation is still loading. Please try again.";
            if (
              typeof window.aipkit_showContentWriterValidationError ===
              "function"
            ) {
              window.aipkit_showContentWriterValidationError(
                validationStatus,
                message
              );
            } else if (validationStatus) {
              validationStatus.textContent = message;
              validationStatus.className =
                "aipkit_cw_status_badge aipkit_settings_message-error";
            }
            return;
          }
          const promptRequirements = validatePromptRequirements(
            form,
            activeMode
          );
          if (!promptRequirements.isValid) {
            const focusPromptRequirement =
              promptRequirementApi?.focus ||
              window.aipkit_focusContentWriterPromptRequirement;
            focusPromptRequirement?.(promptRequirements.firstIssue);
            return;
          }
        }

        // --- Reset UI ---
        if (typeof window.aipkit_clearContentWriterOutput === "function") {
          window.aipkit_clearContentWriterOutput();
        }

        const titleDisplay = document.getElementById(
          "aipkit_cw_generated_title_display"
        );
        const titleInput = document.getElementById(
          "aipkit_content_writer_title"
        );
        const applyResolvedKeywordResponse = function (payload) {
          if (!payload || !payload.resolved_focus_keyword) {
            return;
          }

          const resolvedKeywords = String(payload.resolved_keywords || "").trim();
          if (!resolvedKeywords) {
            return;
          }

          if (payload.resolved_keyword_source === "inline") {
            if (titleInput && payload.resolved_content_title) {
              titleInput.value = payload.resolved_content_title;
            }
            return;
          }

          const keywordField = form.elements["content_keywords"];
          if (keywordField) {
            keywordField.value = resolvedKeywords;
          }
        };

        // --- Handle Task Creation ---
        if (action === "create_task") {
          if (typeof window.aipkit_createContentWriterTask === "function") {
            await window.aipkit_createContentWriterTask(
              form,
              generateBtn,
              statusDiv
            );
            if (
              typeof window.aipkit_updateContentWriterActionState ===
              "function"
            ) {
              window.aipkit_updateContentWriterActionState();
            }
          } else {
            console.error(
              "Generate Button Listener: aipkit_createContentWriterTask function not found."
            );
            // Clear general status to avoid duplicate error messages
            if (statusDiv) {
              statusDiv.textContent = "";
              statusDiv.className = "aipkit_cw_status_badge";
            }
          }
          return; // Stop here for task creation
        }

        const bulkField = form.elements["content_title_bulk"];
        const bulkLines = getLines(bulkField ? bulkField.value : "");
        const manualEntryView =
          form
            .closest("#aipkit_content_writer_container")
            ?.querySelector(".aipkit_cw_task_entry_shell")?.dataset
            .taskEntryView || "single";
        const shouldUseSingle =
          action === "generate" &&
          activeMode === "task" &&
          manualEntryView === "single" &&
          bulkLines.length === 1;

        if (shouldUseSingle) {
          const rowData = getSingleBulkRowData() || parseBulkLine(bulkLines[0]);
          applySingleRowOverrides(rowData);
          if (typeof window.aipkit_cw_resetBatchQueue === "function") {
            window.aipkit_cw_resetBatchQueue();
          }
        }

        if (action === "generate" && !shouldUseSingle) {
          if (typeof window.aipkit_cw_handleBatchGeneration === "function") {
            window.aipkit_cw_handleBatchGeneration(form, activeMode);
          } else {
            console.error(
              "Generate Button Listener: aipkit_cw_handleBatchGeneration function not found."
            );
            const message =
              "Content Writer did not finish loading. Reload the page and try again.";
            if (typeof window.aipkit_showAlertModal === "function") {
              window.aipkit_showAlertModal(message, {
                title: "Content Writer unavailable",
                okText: "Close",
              });
            } else if (typeof window.aipkit_showError === "function") {
              window.aipkit_showError(message);
            } else {
              window.alert(message);
            }
          }
          return;
        }

        // --- Handle Single Content Generation ---
        const singleOutputWrapper = document.getElementById(
          "aipkit_cw_single_output_wrapper"
        );
        if (singleOutputWrapper) {
          singleOutputWrapper.style.display = "flex";
        }
        if (
          typeof window.aipkit_setContentWriterSinglePreviewState === "function"
        ) {
          window.aipkit_setContentWriterSinglePreviewState(true);
        }
        if (
          typeof window.aipkit_setContentWriterSingleRunState === "function"
        ) {
          window.aipkit_setContentWriterSingleRunState(true);
        }
        if (
          typeof window.aipkit_resetContentWriterStreamFollow === "function"
        ) {
          window.aipkit_resetContentWriterStreamFollow();
        }

        const customTitlePrompt = form.querySelector(
          "#aipkit_cw_custom_title_prompt"
        );

        const shouldGenerateTitle = customTitlePrompt?.value.trim() !== "";

        if (
          typeof window.aipkit_resetContentWriterSeoAuditState === "function"
        ) {
          window.aipkit_resetContentWriterSeoAuditState();
        }

        if (
          typeof window.aipkit_initializeCwGenerationChecklist === "function"
        ) {
          window.aipkit_initializeCwGenerationChecklist({
            title: shouldGenerateTitle,
          });
        } else if (
          typeof window.aipkit_resetGenerationStatusIndicators === "function"
        ) {
          window.aipkit_resetGenerationStatusIndicators();
        }
        if (
          typeof window.aipkit_prepareContentWriterOutputSkeletons ===
          "function"
        ) {
          window.aipkit_prepareContentWriterOutputSkeletons();
        }
        if (typeof window.aipkit_setContentWriterCanvasState === "function") {
          window.aipkit_setContentWriterCanvasState("loading", {
            title: shouldGenerateTitle ? "Generating title" : "Preparing draft",
            description: shouldGenerateTitle
              ? "Creating the working title before the manuscript starts."
              : "Opening the manuscript canvas and starting the draft pipeline.",
            hasContent: false,
          });
        }
        if (indicatorsContainer) {
          indicatorsContainer.hidden = false;
          indicatorsContainer.style.display = "";
        }

        // Set initial "skipped" status for all unchecked optional items
        const optionalSteps = {
          meta: "aipkit_cw_generate_meta_desc",
          keyword: "aipkit_cw_generate_focus_keyword",
          excerpt: "aipkit_cw_generate_excerpt",
          tags: "aipkit_cw_generate_tags",
        };

        for (const [step, checkboxId] of Object.entries(optionalSteps)) {
          const checkbox = document.getElementById(checkboxId);
          if (checkbox && !checkbox.checked) {
            if (typeof window.aipkit_updateCwGenerationStatus === "function") {
              window.aipkit_updateCwGenerationStatus(step, "skipped");
            }
          }
        }
        // Handle image separately as it has two checkboxes
        const generateImagesCheckbox = document.getElementById(
          "aipkit_cw_generate_images_enabled"
        );
        const generateFeaturedCheckbox = document.getElementById(
          "aipkit_cw_generate_featured_image"
        );
        if (
          generateImagesCheckbox &&
          generateFeaturedCheckbox &&
          !generateImagesCheckbox.checked &&
          !generateFeaturedCheckbox.checked
        ) {
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus("image", "skipped");
          }
        }

        if (shouldGenerateTitle) {
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus("title", "generating");
            window.aipkit_updateCwGenerationStatus("content", "pending");
            if (titleDisplay) titleDisplay.style.display = "none";
          }
          let titleRequestController = null;
          try {
            if (typeof window.aipkit_setContentWriterStopMode === "function") {
              window.aipkit_setContentWriterStopMode(true);
            }
            const formData = new FormData(form);
            const dataForTitle = Object.fromEntries(formData.entries());
            const openaiVsSelect = form.elements["openai_vector_store_ids[]"];
            dataForTitle.openai_vector_store_ids = openaiVsSelect
              ? Array.from(openaiVsSelect.selectedOptions).map((opt) => opt.value)
              : [];
            delete dataForTitle["openai_vector_store_ids[]"];
            const googleStoresSelect = form.elements["google_file_search_store_names[]"];
            dataForTitle.google_file_search_store_names = googleStoresSelect
              ? Array.from(googleStoresSelect.selectedOptions).map((option) => option.value)
              : [];
            delete dataForTitle["google_file_search_store_names[]"];
            const mainNonceInput = document.getElementById(
              "aipkit_content_writer_nonce"
            );
            dataForTitle._ajax_nonce = mainNonceInput
              ? mainNonceInput.value
              : "";

            // Attach conversation_uuid if we already have a conversation (e.g., after a prior run)
            if (window.aipkit_current_conversation_uuid) {
              dataForTitle.conversation_uuid = window.aipkit_current_conversation_uuid;
            }
            titleRequestController =
              typeof window.aipkit_beginContentWriterAbortableRequest ===
              "function"
                ? window.aipkit_beginContentWriterAbortableRequest("title")
                : new AbortController();
            const titleResponse = await window.aipkit_apiRequest(
              "aipkit_content_writer_generate_title",
              dataForTitle,
              {
                signal: titleRequestController.signal,
                abortMessage: "Title generation stopped",
              }
            );
            if (titleResponse && titleResponse.new_title) {
              applyResolvedKeywordResponse(titleResponse);
              // Persist conversation UUID from Title response so all subsequent steps share it
              if (titleResponse.conversation_uuid) {
                window.aipkit_current_conversation_uuid =
                  titleResponse.conversation_uuid;
                try {
                  sessionStorage.setItem(
                    "aipkit_current_conversation_uuid",
                    titleResponse.conversation_uuid
                  );
                } catch (e) {}
              }
              if (titleDisplay) {
                titleDisplay.textContent = titleResponse.new_title;
                titleDisplay.style.display = "block";
              }
              if (
                typeof window.aipkit_updateCwGenerationStatus === "function"
              ) {
                window.aipkit_updateCwGenerationStatus("title", "success");
              }
            } else {
              throw new Error(
                titleResponse.message || "Failed to generate new title."
              );
            }
          } catch (error) {
            if (error?.name === "AbortError" || error?.code === "aborted") {
              if (
                typeof window.aipkit_isContentWriterStopRequested ===
                  "function" &&
                window.aipkit_isContentWriterStopRequested() &&
                typeof window.aipkit_finalizeStream === "function"
              ) {
                window.aipkit_finalizeStream(eventSourceInstanceRef);
              }
              return;
            }
            console.error("Title Generation Error:", error);
            const formattedError = formatApiError(error);
            // Keep the floating form badge clear; the run surfaces carry the error.
            if (typeof window.aipkit_updateCwGenerationStatus === "function") {
              window.aipkit_updateCwGenerationStatus(
                "title",
                "error",
                formattedError
              );
            }
            if (typeof window.aipkit_setContentWriterCanvasState === "function") {
              window.aipkit_setContentWriterCanvasState("error", {
                title: "Title generation failed",
                description: formattedError,
                hasContent: false,
              });
            }
            if (typeof window.aipkit_finalizeStream === "function") {
              window.aipkit_finalizeStream(eventSourceInstanceRef);
            }
            return;
          } finally {
            if (
              typeof window.aipkit_finishContentWriterAbortableRequest ===
              "function"
            ) {
              window.aipkit_finishContentWriterAbortableRequest(
                titleRequestController
              );
            } else if (
              window.aipkit_cw_singleRequestController ===
              titleRequestController
            ) {
              window.aipkit_cw_singleRequestController = null;
            }
          }
        } else {
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus("title", "skipped");
          }
          if (titleDisplay && titleInput) {
            titleDisplay.textContent = titleInput.value.split("|")[0].trim();
            titleDisplay.style.display = "block";
          }
        }

        if (typeof window.aipkit_handleGenerateContentSSE === "function") {
          window.aipkit_handleGenerateContentSSE(eventSourceInstanceRef);
        } else {
          console.error(
            "Generate Button Listener: aipkit_handleGenerateContentSSE function not found."
          );
        }
      });
      generateBtn.dataset.listenerAttached = "true";
    }
  }
  window.aipkit_attachGenerateButtonListener =
    aipkit_attachGenerateButtonListener;
})();
