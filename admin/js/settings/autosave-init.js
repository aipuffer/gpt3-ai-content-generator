/**
 * AIPKit Settings - Autosave Initializer
 */
(function() {
    'use strict';

    /**
     * Attach event listeners to form elements for auto-saving.
     * Scoped to the settings container.
     */
    function aipkit_attachAutoSaveListeners() {
        const settingsContainer = document.getElementById('aipkit_settings_container');
        if (!settingsContainer) return;

        // Check if listener already attached
        const listenerAttr = 'data-autosave-listener-attached';
        if (settingsContainer.getAttribute(listenerAttr)) return;

        // Use event delegation for efficiency
        settingsContainer.addEventListener('change', function(event) {
            const target = event.target;
            // Trigger on change for selects, checkboxes, and ranges.
            if (target.matches('.aipkit_autosave_trigger')) {
                 if (target.tagName.toLowerCase() === 'select' || target.type === 'checkbox' || target.type === 'range') {
                    if (typeof window.aipkit_handleAutoSave === 'function') {
                        window.aipkit_handleAutoSave();
                    } else { console.error("Autosave Init: aipkit_handleAutoSave not found"); }
                }
            }
        });

        settingsContainer.addEventListener('blur', function(event) {
             const target = event.target;
             // Trigger on blur for text, password, number, and textarea fields.
             if (target.matches('.aipkit_autosave_trigger')) {
                 const tagName = target.tagName.toLowerCase();
                 if (tagName === 'input' || tagName === 'textarea') {
                     // Exclude range/checkbox inputs from blur, they trigger on change
                     if (target.type !== 'range' && target.type !== 'checkbox') {
                         if (typeof window.aipkit_handleAutoSave === 'function') {
                             window.aipkit_handleAutoSave();
                         } else { console.error("Autosave Init: aipkit_handleAutoSave not found"); }
                     }
                 }
             }
        }, true); // Use capture phase for blur to catch it reliably

        // Initial capture of form state to set the baseline
        if (typeof window.aipkit_updateLastSavedData === 'function') {
            window.aipkit_updateLastSavedData();
        } else { console.error("Autosave Init: aipkit_updateLastSavedData not found"); }

        settingsContainer.setAttribute(listenerAttr, 'true');
    }

    // Expose globally
    window.aipkit_attachAutoSaveListeners = aipkit_attachAutoSaveListeners;

})();
