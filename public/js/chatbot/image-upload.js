/** Chatbot image selection, preview and upload state. */
(function () {
    "use strict";


    window.aipkitImageUploadConstants = {
        MAX_IMAGE_SIZE_MB: 20,
        MAX_IMAGE_SIZE_BYTES: (20 * 1024 * 1024),
        ALLOWED_IMAGE_TYPES: ['image/jpeg', 'image/png', 'image/webp'],
        ALLOWED_IMAGE_EXTENSIONS: ['.jpg', '.jpeg', '.png', '.webp']
    };


    window.aipkitImageUploadState = {
        currentImageFile: null,
        currentImageBase64: null
    };


    /**
     * Validates the selected image file.
     * @param {File} file - The image file to validate.
     * @returns {{isValid: boolean, error?: string}} - Validation result.
     */
    function aipkit_validateImage(file) {
        const constants = window.aipkitImageUploadConstants;
        if (!constants) {
            console.error("Image Upload Validate: Constants not loaded.");
            return { isValid: false, error: 'Internal configuration error (constants missing).' };
        }

        if (!file) {
            return { isValid: false, error: 'No file selected.' };
        }

        if (!constants.ALLOWED_IMAGE_TYPES.includes(file.type)) {
            const extensions = constants.ALLOWED_IMAGE_EXTENSIONS.join(', ');
            return { isValid: false, error: `Invalid file type. Please use ${extensions}.` };
        }

        if (file.size > constants.MAX_IMAGE_SIZE_BYTES) {
            return { isValid: false, error: `Image is too large. Max size: ${constants.MAX_IMAGE_SIZE_MB}MB.` };
        }

        return { isValid: true };
    }

    window.aipkit_validateImage = aipkit_validateImage;


    /**
     * Converts a file to a base64 string.
     * @param {File} file - The file to convert.
     * @returns {Promise<string>} - A promise that resolves with the base64 string.
     */
    function aipkit_convertFileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result); // result includes data:image/jpeg;base64,
            reader.onerror = error => reject(error);
            reader.readAsDataURL(file);
        });
    }

    window.aipkit_convertFileToBase64 = aipkit_convertFileToBase64;


    /**
     * Clears any displayed error messages near the target element.
     * @param {HTMLElement} targetElement
     */
    function aipkit_clearImageUploadError(targetElement) {
        let errorSibling;
        if (targetElement && targetElement.parentNode) {
            errorSibling = targetElement.parentNode.querySelector('.chat-image-upload-error'); // Existing CSS class
        }
        if (errorSibling) {
            errorSibling.remove();
        }
        // Fallback: Clear any other error messages with the class if targetElement is not specific enough
        document.querySelectorAll('.chat-image-upload-error').forEach(el => el.remove());
    }

    window.aipkit_clearImageUploadError = aipkit_clearImageUploadError;


    /**
     * Displays an error message in the UI.
     * @param {string} message - The error message to display.
     * @param {HTMLElement} targetElement - The element to display the error near (e.g., input field or preview area).
     */
    function aipkit_displayImageUploadError(message, targetElement) {
        if (typeof window.aipkit_clearImageUploadError === 'function') {
            window.aipkit_clearImageUploadError(targetElement);
        } else {
            console.error("Image Upload Display Error: clearImageUploadError function not found.");
        }

        const errorElement = document.createElement('div');
        errorElement.className = 'chat-image-upload-error'; // Existing CSS class
        errorElement.textContent = message;

        if (targetElement && targetElement.parentNode) {
            targetElement.parentNode.insertBefore(errorElement, targetElement.nextSibling);
        } else {
            // Fallback if targetElement is not in DOM or has no parent
            const chatInputArea = document.querySelector('.aipkit_chat_input'); // Query for a known chat UI element
            if (chatInputArea) {
                chatInputArea.appendChild(errorElement);
            } else {
                console.warn("Image Upload Display Error: Could not find a suitable place to display error message.");
            }
        }
    }

    window.aipkit_displayImageUploadError = aipkit_displayImageUploadError;


    /**
     * Resets the image upload state and clears the preview with a fade-out effect.
     * @param {HTMLElement} previewContainer - The container element for the preview.
     * @param {HTMLInputElement} fileInput - The file input element.
     */
    function aipkit_resetImageUpload(previewContainer, fileInput) {
        const state = window.aipkitImageUploadState;
        if (!state) {
            console.error("Image Upload Reset: State object not found.");
            return;
        }

        state.currentImageFile = null;
        state.currentImageBase64 = null;

        if (previewContainer) {
            if (previewContainer.hasChildNodes()) {
                previewContainer.classList.add('aipkit-image-preview-fade-out'); // Existing CSS class for fade
                setTimeout(() => {
                    if (previewContainer) {
                        previewContainer.innerHTML = '';
                        previewContainer.style.display = 'none';
                        previewContainer.classList.remove('aipkit-image-preview-fade-out');
                    }
                }, 300);
            } else {
                previewContainer.innerHTML = '';
                previewContainer.style.display = 'none';
            }
        }
        if (fileInput) {
            fileInput.value = ''; // Clear the file input
        }

        if (typeof window.aipkit_clearImageUploadError === 'function') {
            window.aipkit_clearImageUploadError(previewContainer || fileInput);
        } else {
            console.error("Image Upload Reset: clearImageUploadError function not found.");
        }
        document.dispatchEvent(new CustomEvent('chatImageReset'));
    }

    window.aipkit_resetImageUpload = aipkit_resetImageUpload;


    /**
     * Renders the image thumbnail preview.
     * @param {string} imageBase64 - The base64 string of the image (includes data URI scheme).
     * @param {HTMLElement} previewContainer - The container element for the preview.
     */
    function aipkit_renderImagePreview(imageBase64, previewContainer) {
        if (!previewContainer) return;

        previewContainer.innerHTML = ''; // Clear previous preview
        previewContainer.style.removeProperty('display');
        previewContainer.classList.remove('aipkit-image-preview-fade-out');

        const wrapper = document.createElement('div');
        wrapper.className = 'chat-image-thumbnail-wrapper'; // Existing CSS class

        const img = document.createElement('img');
        img.src = imageBase64;
        img.alt = 'Image preview';
        img.className = 'chat-image-thumbnail'; // Existing CSS class

        const removeButton = document.createElement('button');
        removeButton.className = 'chat-image-remove-button'; // Existing CSS class
        removeButton.innerHTML = '×';
        removeButton.setAttribute('aria-label', 'Remove image');
        removeButton.type = 'button';
        removeButton.onclick = () => {
            const fileInputEl = previewContainer.closest('.aipkit_chat_input')?.querySelector('.aipkit_chat_image_upload_input');
            if (typeof window.aipkit_resetImageUpload === 'function') {
                window.aipkit_resetImageUpload(previewContainer, fileInputEl);
            } else {
                console.error("Image Upload Render Preview: resetImageUpload function not found.");
            }
            document.dispatchEvent(new CustomEvent('chatImageRemoved'));
        };

        wrapper.appendChild(img);
        wrapper.appendChild(removeButton);
        previewContainer.appendChild(wrapper);
    }

    window.aipkit_renderImagePreview = aipkit_renderImagePreview;


    /**
     * Retrieves the current image data if an image has been processed.
     * @returns {{mime_type: string, base64_data: string}|null} - Image data or null.
     */
    function aipkit_getImageUploadData() {
        const state = window.aipkitImageUploadState;
        if (!state) {
            console.error("Image Upload Get Data: State object not found.");
            return null;
        }

        if (state.currentImageFile && state.currentImageBase64) {
            return {
                mime_type: state.currentImageFile.type,
                base64_data: state.currentImageBase64.split(',')[1] // Remove data URI scheme
            };
        }
        return null;
    }

    window.aipkit_getImageUploadData = aipkit_getImageUploadData;


    /**
     * Returns the array of allowed image extensions.
     * @returns {string[]} - Array of allowed extensions.
     */
    function aipkit_getAllowedImageExtensions() {
        const constants = window.aipkitImageUploadConstants;
        if (!constants) {
            console.error("Image Upload Get Extensions: Constants not loaded.");
            return ['.jpg', '.jpeg', '.png', '.webp']; // Fallback
        }
        return constants.ALLOWED_IMAGE_EXTENSIONS;
    }

    window.aipkit_getAllowedImageExtensions = aipkit_getAllowedImageExtensions;


    /**
     * Returns the maximum allowed image size in MB.
     * @returns {number} - Max size in MB.
     */
    function aipkit_getMaxImageSizeMB() {
        const constants = window.aipkitImageUploadConstants;
        if (!constants) {
            console.error("Image Upload Get Max Size: Constants not loaded.");
            return 20; // Fallback
        }
        return constants.MAX_IMAGE_SIZE_MB;
    }

    window.aipkit_getMaxImageSizeMB = aipkit_getMaxImageSizeMB;


    // The main init function that will be called by chatbot/instance.js
    function initializeImageUploadFeature(fileInputEl, previewContainerEl) {
        const state = window.aipkitImageUploadState;
        const constants = window.aipkitImageUploadConstants;

        if (!state || !constants) {
            console.error('Chat Image Upload Init: State or Constants not loaded.');
            return;
        }
        if (!fileInputEl || !previewContainerEl) {
            console.error('Chat Image Upload Init: File input or preview container not provided for initialization.');
            return;
        }
        if (typeof window.aipkit_validateImage !== 'function' ||
            typeof window.aipkit_convertFileToBase64 !== 'function' ||
            typeof window.aipkit_displayImageUploadError !== 'function' ||
            typeof window.aipkit_clearImageUploadError !== 'function' ||
            typeof window.aipkit_renderImagePreview !== 'function' ||
            typeof window.aipkit_resetImageUpload !== 'function') {
            console.error('Chat Image Upload Init: One or more helper functions are missing.');
            return;
        }

        fileInputEl.setAttribute('accept', constants.ALLOWED_IMAGE_EXTENSIONS.join(','));

        // Prevent duplicate listeners on the same fileInputEl
        if (fileInputEl.dataset.aipkitUploadListenerAttached === 'true') {
            return;
        }

        fileInputEl.addEventListener('change', async (event) => {
            window.aipkit_clearImageUploadError(previewContainerEl);
            const file = event.target.files[0];
            if (!file) {
                window.aipkit_resetImageUpload(previewContainerEl, fileInputEl);
                return;
            }

            const validation = window.aipkit_validateImage(file);
            if (!validation.isValid) {
                window.aipkit_displayImageUploadError(validation.error, previewContainerEl);
                window.aipkit_resetImageUpload(previewContainerEl, fileInputEl);
                document.dispatchEvent(new CustomEvent('chatImageValidationFailed', { detail: { error: validation.error } }));
                return;
            }

            try {
                const base64Full = await window.aipkit_convertFileToBase64(file);
                state.currentImageBase64 = base64Full; // Store full data URI
                state.currentImageFile = file;
                window.aipkit_renderImagePreview(state.currentImageBase64, previewContainerEl);

                document.dispatchEvent(new CustomEvent('chatImageReady', {
                    detail: {
                        base64_data: state.currentImageBase64.split(',')[1], // Send only base64 part
                        mime_type: state.currentImageFile.type
                    }
                }));
            } catch (error) {
                console.error('Error processing image:', error);
                window.aipkit_displayImageUploadError('Could not process image. Please try again.', previewContainerEl);
                window.aipkit_resetImageUpload(previewContainerEl, fileInputEl);
                document.dispatchEvent(new CustomEvent('chatImageProcessingFailed', { detail: { error: 'Could not process image.' } }));
            }
        });
        fileInputEl.dataset.aipkitUploadListenerAttached = 'true';
    }

    // Reconstruct the global window.chatImageUpload object
    window.chatImageUpload = {
        init: initializeImageUploadFeature,
        getImageData: function() {
            if (typeof window.aipkit_getImageUploadData === 'function') {
                return window.aipkit_getImageUploadData();
            }
            console.error("chatImageUpload: getImageData helper not found.");
            return null;
        },
        reset: function(previewContainerEl, fileInputEl) {
            if (typeof window.aipkit_resetImageUpload === 'function') {
                window.aipkit_resetImageUpload(previewContainerEl, fileInputEl);
            } else {
                console.error("chatImageUpload: resetUpload helper not found.");
            }
        },
        getAllowedImageExtensions: function() {
            if (typeof window.aipkit_getAllowedImageExtensions === 'function') {
                return window.aipkit_getAllowedImageExtensions();
            }
            console.error("chatImageUpload: getAllowedImageExtensions helper not found.");
            return [];
        },
        getMaxImageSizeMB: function() {
            if (typeof window.aipkit_getMaxImageSizeMB === 'function') {
                return window.aipkit_getMaxImageSizeMB();
            }
            console.error("chatImageUpload: getMaxImageSizeMB helper not found.");
            return 20; // Fallback
        }
    };
})();
