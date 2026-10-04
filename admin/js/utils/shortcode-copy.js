/**
 * AIPKit Utilities - Shortcode Clipboard Helper
 */
(function () {
	'use strict';

	/* ---------- internal ---------- */

    /**
     * Creates and displays a temporary "Copied!" toast message near the clicked element.
     * @param {HTMLElement} [pill] The element that was clicked (optional, for positioning).
     */
	function flashToast(pill) {
		const toast = document.createElement('div');
		toast.textContent = 'Copied!';
		Object.assign(toast.style, {
			position: 'absolute',
			fontSize: '12px',
			fontWeight: '600',
			color: 'var(--aipkit_status-success, #38A169)', // Use success color
			opacity: 1,
			transition: 'opacity .4s ease-out, transform .4s ease-out',
			zIndex: 1000005,
            padding: '3px 6px',
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            borderRadius: '3px',
            border: '1px solid var(--aipkit_status-success, #38A169)',
            transform: 'translateY(-5px)', // Start slightly above
			pointerEvents: 'none',
		});

		document.body.appendChild(toast);

		if (pill) {
			const { top, left, width } = pill.getBoundingClientRect();
			const topOffset = top + window.scrollY + 2;
			const desiredLeft = left + window.scrollX + width + 5;
			toast.style.top = `${topOffset}px`; // Position slightly above the pill
			toast.style.left = `${desiredLeft}px`; // Default: position to the right

			const toastRect = toast.getBoundingClientRect();
			const viewportPadding = 8;
			const viewportRight = window.innerWidth - viewportPadding;

			if (toastRect.right > viewportRight) {
				const altLeft = left + window.scrollX - toastRect.width - 5;
				const clampedLeft = Math.max(window.scrollX + viewportPadding, altLeft);
				toast.style.left = `${clampedLeft}px`;
			}
		} else {
            // Fallback positioning if no element provided
			toast.style.top = '50%';
			toast.style.left = '50%';
			toast.style.transform = 'translate(-50%, -50%)';
		}

        // Trigger fade-in/move animation
        requestAnimationFrame(() => {
             toast.style.opacity = '1';
             toast.style.transform = 'translateY(0)';
        });

		// Fade out and remove
		setTimeout(() => {
			toast.style.opacity = '0';
            toast.style.transform = 'translateY(-5px)'; // Move back up slightly on fade out
			toast.addEventListener('transitionend', () => toast.remove(), { once: true });
             // Fallback removal
             setTimeout(() => { if (toast.parentNode) toast.remove(); }, 500);
		}, 1000);
	}

    /* ---------- clipboard helper ---------- */

	/**
	 * Copy `text` to the clipboard and flash a “Copied!” toast next to the pill.
	 * @param {string} text The text to copy.
	 * @param {HTMLElement} [pill] The element triggering the copy (optional, for toast positioning).
	 */
	async function aipkit_copyShortcode(text, pill) {
        if (!text) return;

		try {
			// Modern async clipboard API
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(text);
			    flashToast(pill);
                return; // Success
            }
            throw new Error('Clipboard API not available'); // Fallback if API missing
		} catch (err) {
			// Fallback using temporary textarea
			console.warn('[AIPKit] Clipboard API failed or unavailable → falling back to temporary textarea.', err);
            try {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                // Make it non-editable and hidden
                textArea.style.position = "fixed";
                textArea.style.top = "-9999px";
                textArea.style.left = "-9999px";
                textArea.style.opacity = "0";
                textArea.setAttribute('readonly', '');
                document.body.appendChild(textArea);
                textArea.select();
                textArea.setSelectionRange(0, 99999); // For mobile devices
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);
                if (successful) {
                    flashToast(pill);
                } else {
                    throw new Error('document.execCommand("copy") failed.');
                }
            } catch(fallbackErr) {
                // Last‑ditch fallback: let the user copy manually
                console.error('[AIPKit] Fallback copy method also failed. Prompting user.', fallbackErr);
			    window.prompt('Please copy the shortcode manually:', text);
            }
		}
	}


	/* ---------- export to global ---------- */
	window.aipkit_copyShortcode = aipkit_copyShortcode;

})();
