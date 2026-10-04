/**
 * Shared AI Forms result actions: copy generated results and save a post draft.
 */
(function () {
    'use strict';

    function aipkitForms_showCopySuccessIcon(button) {
        const isFooter = button.classList.contains('aipkit-copy-results-footer-btn');
        const originalHtml = button.innerHTML;
        let originalWidth, originalMinWidth, originalHeight;

        // Keep the footer action from shrinking while its label becomes an icon.
        if (isFooter) {
            originalWidth = button.style.width;
            originalMinWidth = button.style.minWidth;
            originalHeight = button.style.height;
            const rect = button.getBoundingClientRect();
            if (rect.width > 0) {
                button.style.width = `${Math.ceil(rect.width)}px`;
                button.style.minWidth = `${Math.ceil(rect.width)}px`;
            }
            if (rect.height > 0) {
                button.style.height = `${Math.ceil(rect.height)}px`;
            }
        }

        const iconClass = isFooter ? 'aipkit-copy-success-svg' : 'aipkit-copy-success-icon';
        button.innerHTML = `<svg class="${iconClass}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5"></path></svg>`;
        if (isFooter) {
            button.classList.add('aipkit-copy-results-footer-btn--copied');
        }
        button.disabled = true;

        setTimeout(() => {
            button.innerHTML = originalHtml;
            if (isFooter) {
                button.classList.remove('aipkit-copy-results-footer-btn--copied');
                button.style.width = originalWidth;
                button.style.minWidth = originalMinWidth;
                button.style.height = originalHeight;
            }
            button.disabled = false;
        }, 1500);
    }

    /**
     * Handles the click event on the copy button. Extracts text content from
     * the results container and copies it to the clipboard.
     * @param {Event} event The click event.
     */
    function aipkitForms_handleCopyAction(event) {
        const button = event.currentTarget;
        const resultsDiv = button.closest('.aipkit-ai-form-results');
        if (!resultsDiv) return;

        // Clone the node to avoid manipulating the live DOM element (e.g., removing the button itself)
        const contentToCopy = resultsDiv.cloneNode(true);
        // Remove the button from the cloned node before getting text content
        contentToCopy.querySelectorAll('.aipkit-copy-results-btn, .aipkit-copy-results-footer-btn').forEach((copyButton) => {
            copyButton.remove();
        });

        // Use innerText to get a more "natural" representation of the text, like a user would see.
        const textToCopy = contentToCopy.innerText || contentToCopy.textContent;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(() => {
                aipkitForms_showCopySuccessIcon(button);
            }).catch(err => {
                console.error('Failed to copy text using Clipboard API: ', err);
                // Fallback for older browsers
                fallbackCopy(textToCopy, button);
            });
        } else {
            // Fallback for non-secure contexts or old browsers
            fallbackCopy(textToCopy, button);
        }
    }

    /**
     * Fallback copy method using a temporary textarea and execCommand.
     * @param {string} textToCopy The text to copy.
     * @param {HTMLElement} button The button that triggered the action.
     */
    function fallbackCopy(textToCopy, button) {
        const textArea = document.createElement("textarea");
        textArea.value = textToCopy;
        textArea.style.position = 'fixed'; // Prevent scrolling to bottom of page
        textArea.style.top = '-9999px';
        textArea.style.left = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            aipkitForms_showCopySuccessIcon(button);
        } catch (err) {
            console.error('Fallback copy failed: ', err);
            alert('Failed to copy content.');
        }
        document.body.removeChild(textArea);
    }


    window.aipkitForms_handleCopyAction = aipkitForms_handleCopyAction;

    async function aipkitForms_handleSaveAsPost(event) {
        const saveButton = event.currentTarget;
        const formWrapper = saveButton.closest(".aipkit-ai-form-wrapper");
        if (!formWrapper) return;
        const resultsDiv = formWrapper.querySelector(".aipkit-ai-form-results");
        const titleElement = formWrapper.querySelector("h5.aipkit-ai-form-title");
        const nonce = formWrapper.dataset.saveAsPostNonce;

        const contentContainer = resultsDiv
            ? resultsDiv.querySelector(".aipkit-ai-form-results-content")
            : null;

        if (!contentContainer || !titleElement) {
            console.error(
                "AI Forms Save: Missing results content container or title element."
            );
            return;
        }

        const config = window.aipkit_ai_forms_public_config || {};
        const __ =
            window.wp && window.wp.i18n && window.wp.i18n.__
                ? window.wp.i18n.__
                : (text) => text;

        const title = titleElement.textContent.trim();
        const content = contentContainer.innerHTML;

        const originalButtonHTML = saveButton.innerHTML;
        saveButton.disabled = true;
        saveButton.innerHTML =
            '<span class="aipkit_spinner" style="display:inline-block;"></span>';

        const data = {
            action: "aipkit_ai_form_save_as_post",
            _ajax_nonce: nonce,
            post_title: title,
            post_content: content,
        };

        try {
            // aipkit_apiRequest is not available on public side, use standard fetch
            const formData = new FormData();
            for (const key in data) {
                formData.append(key, data[key]);
            }

            const response = await fetch(config.ajaxUrl, {
                method: "POST",
                body: formData,
                credentials: "same-origin",
            });

            const jsonResponse = await response.json();

            if (!response.ok || !jsonResponse.success) {
                throw new Error(
                    jsonResponse.data?.message ||
                        __("Failed to save post.", "gpt3-ai-content-generator")
                );
            }

            saveButton.innerHTML =
                '<span class="dashicons dashicons-yes-alt" style="color: #38a569;"></span>';
            saveButton.disabled = true;

            let editLink = null;
            if (jsonResponse.data.edit_link) {
                editLink = document.createElement("a");
                editLink.href = jsonResponse.data.edit_link;
                editLink.innerHTML =
                    '<span class="dashicons dashicons-external"></span>';
                editLink.title = __("Edit Post", "gpt3-ai-content-generator");
                editLink.target = "_blank";
                editLink.rel = "noopener noreferrer";
                editLink.classList.add(
                    "aipkit_btn",
                    "aipkit_btn-icon",
                    "aipkit_btn-small",
                    "aipkit_btn-secondary"
                );
                editLink.style.marginLeft = "10px";
                saveButton.parentNode.insertBefore(editLink, saveButton.nextSibling);
            }

            setTimeout(() => {
                saveButton.innerHTML = originalButtonHTML;
                saveButton.disabled = false;
                if (editLink && editLink.parentNode) {
                    editLink.remove();
                }
            }, 5000);
        } catch (error) {
            console.error("Failed to save post:", error);
            alert(
                `${__("Error saving post:", "gpt3-ai-content-generator")} ${
                    error.message
                }`
            );
            saveButton.innerHTML = originalButtonHTML;
            saveButton.disabled = false;
        }
    }

    window.aipkitForms_handleSaveAsPost = aipkitForms_handleSaveAsPost;
})();
