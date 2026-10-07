import './shared/provider-connect.js';
// Shared Admin Utilities (load first)
import "./utils/btn-utils.js";
import "../../public/js/shared/html.js";
import "./utils/shortcode-copy.js";
import "./shared/date-time.js";
import "./utils/ucfirst.js";
import "./utils/markdown-renderer.js";
import "./utils/ui/ui-range-handler.js";
import "./utils/ui/ui-api-key-toggle.js";
import "./utils/ui/ui-loading-state.js";
import "./utils/ui/ui-vector-status.js";
import "./utils/ui/ui-snippet-modal.js";
import "./utils/ui/ui-logs-pagination.js";
import "./utils/ui/ui-restore-default.js";
import "./utils/ui/ui-message-handler.js";
import "./utils/ui/ui-saving-indicator.js";
import "./utils/ui/ui-placeholder-copy.js";
import "./utils/ui/ui-provider-key-notice.js";
import "./utils/ui/ui-dismissible-notices.js";
import "./utils/ui/ui-cloud-announcement.js";
import "./utils/ui/ui-notice-stack.js";
import "./utils/ui/ui-cloud-credit-notice.js";
import "./utils/ui/ui-module-settings-tabs.js";
import "./utils/ai/new-model-selection.js";
import "./shared/model-selector-init.js";
import "./utils/embedding/embedding-utils.js";
import "./utils/prompt-library-api.js";
import "./utils/prompt-library-manager.js";
import "./content-writer/components/settings-popovers.js";
import "./content-writer/components/smart-seo-defaults.js";

// Shared admin navigation and module infrastructure
import "./knowledge-base/indexing-state.js";
import "./shared/api.js";
import "./shared/module-loader.js";
import "./shared/module-error.js";
import "./shared/navigation.js"; // Must be after its dependencies

// Module-specific bundles loaded by shared/module-loader.js
import "./usage/page.js";

// Settings
import "./settings/autosave-data.js";
import "./settings/autosave-logic.js";
import "./settings/autosave-init.js";
import "./settings/sync-openai.js";
import "./settings/sync-openrouter.js";
import "./settings/sync-google.js";
import "./settings/sync-google-file-search.js";
import "./settings/sync-azure.js";
import "./settings/sync-claude.js";
import "./settings/sync-deepseek.js";
import "./settings/sync-xai.js";
import "./settings/sync-ollama.js";
import "./settings/sync-elevenlabs.js";
import "./settings/sync-pinecone.js";
import "./settings/sync-qdrant.js";
import "./settings/sync-chroma.js";
import "./settings/sync-replicate.js";
import "./settings/sync-init.js";
import "./settings/select-popover-picker.js";
import "./settings/ai-provider-cards.js";
import "./settings/cloud-connection.js";
import "./settings/integration-cards.js";
import "./settings/navigation.js";
import "./settings/event-webhooks.js";
import "./settings/developer-settings.js";
import "./settings/actions.js";
import "./settings/wp-ai-client-control.js";
import "./settings/modules-ui.js";
import "./settings/security-ui.js";
import "./knowledge-base/settings.js";
import "./settings/semantic-search-ui.js";
import "./settings/page.js";

// Role Manager
import "./role-manager/page.js";

// Chat Admin specific features
import "./chatbot/providers.js";
import "./chatbot/model-config.js";
import "./chatbot/audio.js";
import "./chatbot/knowledge-settings.js";
import "./chatbot/knowledge-pickers.js";
import "./chatbot/appearance.js";
import "./autogpt/fields/reasoning-effort-shared.js";
import "./chatbot/select-picker.js";

import "./chatbot/preview.js";
import "./chatbot/page.js"; // Main chat admin orchestrator

// AI Forms Admin specific features
import "./ai-forms/structure.js";
import "./ai-forms/list.js";
import "./ai-forms/designer.js";
import "./ai-forms/prompt-validation.js";
import "./ai-forms/field-settings.js";
import "./ai-forms/editor.js";
import "./ai-forms/save.js";
import "./ai-forms/data-actions.js";
import "./ai-forms/settings.js";
import "./ai-forms/persistence.js";
import "./ai-forms/page.js";

// AutoGPT Admin specific features
import "./autogpt/shared/provider-setup-state.js";
import "./autogpt/shared/ai-model-populator.js";
import "./autogpt/shared/ai-setup-binder.js";
import "./autogpt/shared/ai-form-state.js";
import "./autogpt/form/render-task-sections.js";
import "./autogpt/tasks/content-indexing/populate-target-store-select.js";
import "./autogpt/tasks/content-indexing/populate-embedding-model-select.js";
import "./autogpt/tasks/content-indexing/handle-target-store-provider-change.js";
import "./autogpt/tasks/content-indexing/content-indexing-ui.js"; // Orchestrator last
// Modular content writing task UI
import "./autogpt/tasks/content-writing/handle-provider-model-select.js";
import "./autogpt/tasks/content-writing/handle-temperature-slider.js";
import "./autogpt/tasks/content-writing/handle-schedule-field-toggle.js";
import "./autogpt/tasks/content-writing/knowledge-base-ui.js";
import "./autogpt/tasks/content-writing/manual-entry-ui.js";
import "./autogpt/tasks/content-writing/content-writing-form-ui.js";
import "./autogpt/tasks/content-enhancement/promo-shims.js";
// Comment reply task UI
import "./autogpt/tasks/community-engagement/handle-provider-model-select.js";
import "./autogpt/tasks/community-engagement/comment-reply-form-ui.js";
import "./autogpt/ui/inline-prompt-settings.js";
import "./autogpt/ui/unified-model-selectors.js";

// Reasoning effort toggle fields
import "./autogpt/fields/toggleReasoningEffort-cw.js"; // Content Writing
import "./autogpt/fields/toggleReasoningEffort-cc.js"; // Community Engagement

// Form population logic
import "./autogpt/form/populate-form-common.js";
import "./autogpt/form/populate-form-content-indexing.js";
import "./autogpt/form/populate-form-content-writing.js";
import "./autogpt/form/populate-form-comment-reply.js";
import "./autogpt/form/set-content-writing-defaults.js";
import "./autogpt/form/set-comment-reply-defaults.js";

import "./autogpt/form/show-automated-task-form.js"; // This is now the orchestrator
import "./autogpt/form/hide-automated-task-form.js";
import "./autogpt/form/handle-task-type-change.js";
import "./autogpt/form/show-automated-task-form-status.js";
import "./autogpt/form/progressive-task-builder.js";
// -- Modular Save Task Logic --
import "./autogpt/form/save-task/extract-base-data.js";
import "./autogpt/form/save-task/handle-content-indexing.js";
import "./autogpt/form/save-task/handle-content-writing.js";
import "./autogpt/form/save-task/validate-task-data.js";
import "./autogpt/form/save-task/submit-task-request.js";
import "./autogpt/form/save-task/finalize-form-success.js";
import "./autogpt/form/handle-save-task.js";
import "./autogpt/form/init-automated-task-form.js";
import "./autogpt/automated-tasks-form-handler.js"; // Orchestrator
// -- List Logic --
import "./autogpt/list/ui/action-button-loading.js";
import "./autogpt/list/render-tasks-table.js";
import "./autogpt/list/render-list-pagination.js";
import "./autogpt/list/fetch-tasks.js";
import "./autogpt/list/actions/edit-task.js";
import "./autogpt/list/actions/delete-task.js";
import "./autogpt/list/actions/toggle-task-status.js";
import "./autogpt/list/actions/run-now-task.js";
import "./autogpt/list/handle-task-action-click.js";
import "./autogpt/list/handle-list-pagination-click.js";
import "./autogpt/list/show-automated-task-list-status.js";
import "./autogpt/list/init-automated-task-list.js";
import "./autogpt/automated-tasks-list-handler.js"; // Orchestrator
// -- Queue Logic --
import "./autogpt/queue/render-queue-table.js";
import "./autogpt/queue/render-queue-pagination.js";
import "./autogpt/queue/fetch-render-queue-items.js";
import "./autogpt/queue/handle-queue-item-action-click.js";
import "./autogpt/queue/actions/delete-selected-queue-items.js";
import "./autogpt/queue/handle-queue-pagination-click.js";
import "./autogpt/queue/init-automated-task-queue.js";
import "./autogpt/automated-tasks-queue-handler.js"; // Orchestrator
import "./autogpt/cron/server-cron-controls.js";
// -- Main AutoGPT Orchestrator --
import "./autogpt/autogpt-main.js";

// Content Writer Admin specific features
import "./content-writer/utils/status-manager.js"; // Load status utility early
import "./content-writer/autosave/autosave-logic.js";
import "./content-writer/autosave/autosave-init.js";
import "./content-writer/components/update-generation-status-indicator.js";
import "./content-writer/components/reset-generation-status-indicators.js";
import "./content-writer/components/create-content-writer-task.js";
import "./content-writer/components/generate-action-menu.js";
import "./content-writer/components/batch-generation.js";
import "./content-writer/components/template/state.js"; // Load state early
import "./content-writer/components/populate-models.js";
import "./content-writer/components/generate-count-label.js";
import "./content-writer/modes.js";
import "./content-writer/components/update-existing-content.js";
import "./content-writer/components/settings-popovers.js";
import "./content-writer/prompt-state.js";
import "./content-writer/prompt-editor.js";
import "./content-writer/components/bulk-editor.js";
import "./content-writer/ui/alert-modal.js";
import "./content-writer/ui/handleProviderModelDropdowns.js";
import "./content-writer/ui/handleCopyButton.js";
import "./content-writer/ui/handleClearButton.js";
import "./content-writer/ui/image-previews.js";
import "./content-writer/ui/output-chunk-handlers.js"; // Chunked output UI handlers
import "./content-writer/components/smart-seo-free-cta.js";
import "./content-writer/fields/toggleReasoningEffort.js";
import "./content-writer/components/advanced-settings-modal.js";
import "./content-writer/components/image-settings-handler.js";
import "./content-writer/components/vector-settings-handler.js";
import "./content-writer/components/post-settings-handler.js";
import "./content-writer/components/vector-store-multiselect.js";
import "./utils/ui/category-dropdown.js";
import "./content-writer/content-writer-ui.js";
import "./content-writer/components/reset-buttons.js";
import "./content-writer/components/finalize-stream.js";
import "./content-writer/components/handle-csv-upload.js";

// Modular SSE handler imports
import "./content-writer/stream/setupEventSourceConnection.js";
import "./content-writer/stream/handleStreamMessage.js";
import "./content-writer/stream/handleStreamDone.js";
import "./content-writer/stream/handleStreamError.js";
import "./content-writer/content-writer-eventsource-handler.js"; // Orchestrator after its components
import "./content-writer/components/stop-eventsource.js";
import "./content-writer/components/handle-generate-content-sse.js";
import "./content-writer/components/handle-stop-generation.js";
import "./content-writer/handlers/attachGenerateButtonListener.js";
import "./content-writer/components/seo-feature-gate.js";

// --- START: New Modular Template Handler Imports ---
import "./content-writer/components/template/deepEqual.js";
import "./content-writer/components/template/get-default-template-config.js";
import "./content-writer/components/template/get-form-config.js";
import "./content-writer/components/template/apply-template.js";
import "./content-writer/components/template/fetch-populate-templates.js";
import "./content-writer/components/template/save-template.js";
import "./content-writer/components/template/checkFormForModifications.js";
import "./content-writer/components/template/handle-save-as-template.js";
import "./content-writer/components/template/handle-rename-template.js";
import "./content-writer/components/template/handle-delete-template.js";
import "./content-writer/components/template/handle-reset-starter-templates.js";
import "./content-writer/components/template/template-picker.js";
import "./content-writer/components/template/initTemplateSelect.js";
import "./content-writer/components/template/bindFormChangeListeners.js";
import "./content-writer/components/template/init-template-handler.js"; // This is the new orchestrator
// --- END: New Modular Template Handler Imports ---

import "./content-writer/components/save-as-post-handler.js";
import "./content-writer/content-writer-main.js";

// Image Generator Admin specific features
import "./image-generator/image-generator-admin.js";

// Post Enhancer Admin specific features
import "./post-enhancer/post-title-fetcher.js";
import "./post-enhancer/post-title-modal.js";
import "./post-enhancer/post-title-updater.js";
import "./post-enhancer/post-excerpt-fetcher.js";
import "./post-enhancer/post-excerpt-modal.js";
import "./post-enhancer/post-excerpt-updater.js";
import "./post-enhancer/post-meta-desc-fetcher.js";
import "./post-enhancer/post-meta-desc-modal.js";
import "./post-enhancer/post-meta-desc-updater.js";
import "./post-enhancer/post-tags-fetcher.js";
import "./post-enhancer/post-tags-modal.js";
import "./post-enhancer/post-tags-updater.js";
import "./post-enhancer/handler-tags.js";
// --- Modularized Bulk Enhancer ---
import "./post-enhancer/bulk/process-state.js";
import "./post-enhancer/bulk/ui-log.js";
import "./post-enhancer/bulk/process-stop.js";
import "./post-enhancer/bulk/model-populator.js";
import "./post-enhancer/bulk/process-runner.js";
import "./post-enhancer/bulk/template-handler.js";
import "./post-enhancer/bulk/template-picker.js";
import "./post-enhancer/bulk/modal-html-template.js";
import "./post-enhancer/bulk/modal-ui.js";
import "./post-enhancer/bulk/prompt-inline-controls.js";
import "./post-enhancer/bulk/handlers.js";
// --- Main Initializer (must be last for this module) ---
import "./post-enhancer/enhancer-utils.js";
import "./post-enhancer/handler-title.js";
import "./post-enhancer/handler-excerpt.js";
import "./post-enhancer/handler-meta.js";
import "./post-enhancer/enhancer-bulk-button.js";
import "./post-enhancer/enhancer-events.js";
import "./post-enhancer/post-enhancer-init.js";

// Vector Post Processor Admin specific features
import "./vector-post-processor/utils/embedding-model-select.js";
import "./vector-post-processor/providers/openai.js";
import "./vector-post-processor/providers/google.js";
import "./vector-post-processor/providers/pinecone.js";
import "./vector-post-processor/providers/qdrant.js";
import "./vector-post-processor/providers/chroma.js";
import "./vector-post-processor/providers/local.js";
import "./vector-post-processor/ui/select-popover-picker.js";
import "./vector-post-processor/ui/vector-post-processor-modal-ui.js";
import "./vector-post-processor/main/vector-post-processor-main.js";

// init-kb-mode-selector removed: simplified UI defaults to existing KB with modal creation
