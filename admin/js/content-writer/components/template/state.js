/**
 * AIPKit Content Writer - Template Handler State
 * Holds the state for the template management UI.
 * UPDATED: Added initialConfigForSelectedTemplate and currentTemplateModified.
 * UPDATED: Added pendingModelSelection for robust model application.
 */
(function() {
    'use strict';

    window.aipkit_cw_template_state = {
        currentTemplates: [], // Array of all fetched template objects
        currentTemplateId: null, // ID of the currently selected/applied template
        initialConfigForSelectedTemplate: null, // Config of the template when it was loaded
        currentTemplateModified: false, // Flag to track if the current form differs from initialConfig
        pendingModelSelection: null, // Stores the model ID to be selected after models are populated
        pendingImageModelSelection: null, // Stores the image model ID to be selected after models are populated
        isSavingOrDeleting: false // Flag to prevent concurrent operations
    };

})();