/**
 * AIPKit - Vector Post Processor - Main
 *
 * Adds the "Add to knowledge base" button to post list screens and handles its initial click.
 */
(function() {
    'use strict';

    const VPP_BUTTON_ID = 'aipkit_add_to_vector_store_btn';
    const MAX_OBSERVE_MS = 15000; // safety timeout for observer

    function showWarningDialog(message, title) {
        if (typeof window.aipkit_showConfirmModal !== 'function') {
            console.error(`Vector Post Processor: ${message}`);
            return;
        }

        window.aipkit_showConfirmModal(message, {
            title,
            confirmText: window.aipkit_vpp_config.text.ok || 'OK',
            variant: 'warning',
            hideCancel: true,
        });
    }

    /**
     * Handles the click event for the "Add to knowledge base" button.
     * Gathers selected post IDs and calls the function to show the modal.
     */
    function handleAddToVectorStoreClick() {
        const selectedPosts = document.querySelectorAll('#the-list input[type="checkbox"][name="post[]"]:checked');
        if (selectedPosts.length === 0) {
            showWarningDialog(
                window.aipkit_vpp_config.text.error_no_posts_selected || 'Please select at least one item to index.',
                window.aipkit_vpp_config.text.error_no_posts_selected_title || 'Select items'
            );
            return;
        }
        const postIds = Array.from(selectedPosts).map(cb => cb.value);
        if (typeof window.aipkit_vpp_showModal === 'function') {
            window.aipkit_vpp_showModal(postIds, window.aipkit_vpp_config.post_type);
        } else {
            console.error("Vector Post Processor Main: Modal show function (aipkit_vpp_showModal) not found.");
            showWarningDialog(
                window.aipkit_vpp_config.text.error_ui_unavailable || 'The indexing interface is not available. Reload the page and try again.',
                window.aipkit_vpp_config.text.error_ui_unavailable_title || 'Unable to open'
            );
        }
    }

    /**
     * Initializes the VPP main functionality.
     * Finds the button created by PHP and attaches the click listener.
     */
    function bindButtonIfPresent(){
        const button = document.getElementById(VPP_BUTTON_ID);
        if (button && !button.dataset.vppBound) {
            button.addEventListener('click', handleAddToVectorStoreClick);
            button.dataset.vppBound = '1';
            return true;
        }
        return !!(button && button.dataset.vppBound);
    }

    function init() {
        // Attempt immediate bind
        if (bindButtonIfPresent()) return;

        // Observe for late injection (e.g., header buttons script inserting after DOMContentLoaded)
        const target = document.querySelector('#wpbody-content') || document.body;
        const start = Date.now();
        if (!target) return; // Should not happen in admin
        const observer = new MutationObserver(() => {
            if (bindButtonIfPresent() || Date.now() - start > MAX_OBSERVE_MS) {
                observer.disconnect();
            }
        });
        observer.observe(target, {childList:true, subtree:true});

        // Fallback interval (in case mutation observer misses for any reason)
        const interval = setInterval(()=>{
            if (bindButtonIfPresent() || Date.now() - start > MAX_OBSERVE_MS) {
                clearInterval(interval);
            }
        }, 300);
    }

    if (document.readyState === 'loading' || document.readyState === 'interactive') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
