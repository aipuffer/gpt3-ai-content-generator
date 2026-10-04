/**
 * AIPKit Content Writer - Reset Buttons UI
 * Helper function to reset the state of Generate and Stop buttons.
 * UPDATED: Also resets the generation status indicators.
 */
(function() {
    'use strict';

    const timerState = {
        intervalId: null,
        startTime: null,
        elapsedMs: 0
    };
    const previewViewportState = {
        rafId: 0
    };
    const singlePreviewLayoutState = {
        actionShellParent: null,
        actionShellNextSibling: null
    };

    function getSinglePreviewRefs() {
        const container = document.getElementById('aipkit_content_writer_container');
        if (!container) return null;
        return {
            container,
            actionShell: container.querySelector('.aipkit_cw_action_shell'),
            singleRunActions: container.querySelector('#aipkit_cw_single_run_actions'),
            outputCanvas: container.querySelector('#aipkit_content_writer_output_display')
        };
    }

    function updateSinglePreviewCanvasViewportHeight() {
        const refs = getSinglePreviewRefs();
        if (!refs || !refs.container) {
            return;
        }

        if (!refs.container.classList.contains('aipkit_cw_single-preview-active')) {
            refs.container.style.removeProperty('--aipkit-cw-single-preview-canvas-height');
            return;
        }

        const canvas = refs.outputCanvas;
        if (!canvas || canvas.offsetParent === null) {
            return;
        }

        const viewportHeight =
            window.visualViewport?.height ||
            window.innerHeight ||
            document.documentElement.clientHeight ||
            0;
        const canvasTop = canvas.getBoundingClientRect().top;
        if (!viewportHeight || !Number.isFinite(canvasTop)) {
            return;
        }

        const bottomGap = 24;
        const availableHeight = Math.max(
            240,
            Math.floor(viewportHeight - canvasTop - bottomGap)
        );

        refs.container.style.setProperty(
            '--aipkit-cw-single-preview-canvas-height',
            `${availableHeight}px`
        );
    }

    function scheduleSinglePreviewCanvasViewportHeightUpdate() {
        if (previewViewportState.rafId) {
            cancelAnimationFrame(previewViewportState.rafId);
        }

        previewViewportState.rafId = requestAnimationFrame(() => {
            previewViewportState.rafId = 0;
            updateSinglePreviewCanvasViewportHeight();
        });
    }

    function rememberActionShellPosition(actionShell) {
        if (!actionShell || singlePreviewLayoutState.actionShellParent) {
            return;
        }
        singlePreviewLayoutState.actionShellParent = actionShell.parentNode;
        singlePreviewLayoutState.actionShellNextSibling = actionShell.nextSibling;
    }

    function moveGenerateControlsToSinglePreview() {
        const refs = getSinglePreviewRefs();
        if (!refs || !refs.actionShell || !refs.singleRunActions) {
            return;
        }
        rememberActionShellPosition(refs.actionShell);
        if (refs.actionShell.parentNode !== refs.singleRunActions) {
            refs.singleRunActions.appendChild(refs.actionShell);
        }
    }

    function restoreGenerateControlsFromSinglePreview() {
        const refs = getSinglePreviewRefs();
        const { actionShellParent, actionShellNextSibling } = singlePreviewLayoutState;
        if (
            !refs ||
            !refs.actionShell ||
            !refs.singleRunActions ||
            !actionShellParent ||
            refs.actionShell.parentNode !== refs.singleRunActions
        ) {
            return;
        }
        if (
            actionShellNextSibling &&
            actionShellNextSibling.parentNode === actionShellParent
        ) {
            actionShellParent.insertBefore(refs.actionShell, actionShellNextSibling);
        } else {
            actionShellParent.appendChild(refs.actionShell);
        }
    }

    function closeTransientUi(container) {
        if (!container) return;
        if (typeof window.aipkit_closeContentWriterInlinePromptEditor === 'function') {
            window.aipkit_closeContentWriterInlinePromptEditor();
        }
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
        );
    }

    function aipkit_setContentWriterSingleRunState(isActive) {
        const refs = getSinglePreviewRefs();
        if (!refs || !refs.container) return;
        refs.container.classList.toggle('aipkit_cw_single-run-active', Boolean(isActive));
        if (
            isActive &&
            refs.container.classList.contains('aipkit_cw_single-preview-active')
        ) {
            moveGenerateControlsToSinglePreview();
            scheduleSinglePreviewCanvasViewportHeightUpdate();
            return;
        }
        restoreGenerateControlsFromSinglePreview();
        scheduleSinglePreviewCanvasViewportHeightUpdate();
    }

    function aipkit_setContentWriterSinglePreviewState(isActive) {
        const refs = getSinglePreviewRefs();
        if (!refs || !refs.container) return;
        refs.container.classList.toggle('aipkit_cw_single-preview-active', Boolean(isActive));
        if (isActive) {
            closeTransientUi(refs.container);
            scheduleSinglePreviewCanvasViewportHeightUpdate();
            return;
        }
        refs.container.style.removeProperty('--aipkit-cw-single-preview-canvas-height');
        aipkit_setContentWriterSingleRunState(false);
    }

    function formatElapsedTime(elapsedMs) {
        const totalSeconds = Math.floor(elapsedMs / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${minutes}:${String(seconds).padStart(2, '0')}`;
    }

    function getTimerElement() {
        return document.getElementById('aipkit_cw_session_timer');
    }

    function startTimer() {
        if (timerState.intervalId) return;
        const timerEl = getTimerElement();
        if (!timerEl) return;
        const sessionCard = document.getElementById('aipkit_cw_status_display_container');
        if (sessionCard) {
            sessionCard.dataset.actionsTitle = 'Generated';
            sessionCard.dataset.actionsHint = '';
        }
        timerState.startTime = Date.now();
        timerState.elapsedMs = 0;
        const updateTimer = () => {
            const elapsedMs = Date.now() - timerState.startTime;
            timerState.elapsedMs = elapsedMs;
            timerEl.textContent = formatElapsedTime(elapsedMs);
            timerEl.hidden = false;
        };
        updateTimer();
        timerState.intervalId = window.setInterval(updateTimer, 1000);
    }

    function stopTimer() {
        if (timerState.startTime) {
            timerState.elapsedMs = Math.max(
                timerState.elapsedMs,
                Date.now() - timerState.startTime
            );
        }
        if (timerState.intervalId) {
            clearInterval(timerState.intervalId);
            timerState.intervalId = null;
        }
        timerState.startTime = null;
        const timerEl = getTimerElement();
        if (timerEl) {
            timerEl.textContent = '';
            timerEl.hidden = true;
        }
        const sessionCard = document.getElementById('aipkit_cw_status_display_container');
        if (sessionCard && timerState.elapsedMs > 0) {
            sessionCard.dataset.actionsTitle = 'Generated';
            sessionCard.dataset.actionsHint = `Finished in ${formatElapsedTime(
                timerState.elapsedMs
            )}`;
        }
    }

    function resetCompletionTime() {
        timerState.elapsedMs = 0;
        const timerEl = getTimerElement();
        if (timerEl) {
            timerEl.textContent = '';
            timerEl.hidden = true;
        }
        const sessionCard = document.getElementById('aipkit_cw_status_display_container');
        if (sessionCard) {
            sessionCard.dataset.actionsTitle = 'Generated';
            sessionCard.dataset.actionsHint = '';
        }
    }

    function aipkit_setContentWriterStopMode(isActive) {
        const generateBtn = document.getElementById('aipkit_content_writer_generate_btn');
        if (!generateBtn) return;
        const actionShell = generateBtn.closest('.aipkit_cw_action_shell');
        const toggleBtn = actionShell ? actionShell.querySelector('.aipkit_cw_action_disclosure') : null;
        const menu = actionShell ? actionShell.querySelector('.aipkit_cw_action_menu') : null;
        const menuItems = menu
            ? Array.from(menu.querySelectorAll('.aipkit_cw_action_menu_option'))
            : [];
        const spinner = generateBtn.querySelector('.aipkit_spinner');

        if (isActive) {
            generateBtn.dataset.aipkitStopMode = 'true';
            if (actionShell) {
                actionShell.classList.add('is-primary-only');
                actionShell.classList.add('is-stop-mode');
            }
            startTimer();
            if (typeof window.aipkit_setButtonText === 'function') {
                window.aipkit_setButtonText(generateBtn, 'Stop');
            } else {
                const label = generateBtn.querySelector('.aipkit_btn-text');
                if (label) {
                    label.textContent = 'Stop';
                } else {
                    generateBtn.textContent = 'Stop';
                }
            }
            generateBtn.disabled = false;
            generateBtn.removeAttribute('aria-disabled');
            generateBtn.style.display = 'inline-flex';
            if (spinner) spinner.style.display = 'none';
            if (toggleBtn) {
                toggleBtn.style.display = 'none';
                toggleBtn.disabled = true;
            }
            if (menu) {
                menu.hidden = true;
            }
            if (menuItems.length) {
                menuItems.forEach((item) => {
                    item.disabled = true;
                    item.setAttribute('aria-disabled', 'true');
                });
            }
            return;
        }

        if (generateBtn.dataset.aipkitStopMode) {
            delete generateBtn.dataset.aipkitStopMode;
        }
        stopTimer();
        aipkit_setContentWriterSingleRunState(false);
        if (spinner) {
            spinner.style.display = 'none';
        }
        if (actionShell) {
            actionShell.classList.remove('is-primary-only');
            actionShell.classList.remove('is-stop-mode');
        }
        if (toggleBtn) {
            toggleBtn.style.display = '';
            toggleBtn.disabled = false;
            toggleBtn.removeAttribute('aria-disabled');
        }
        if (menuItems.length) {
            menuItems.forEach((item) => {
                item.disabled = false;
                item.removeAttribute('aria-disabled');
            });
        }
    }

    function aipkit_isContentWriterSingleRunActive() {
        const refs = getSinglePreviewRefs();
        const isContainerActive = Boolean(
            refs?.container?.classList.contains('aipkit_cw_single-run-active')
        );
        const isButtonInStopMode = Boolean(
            refs?.actionShell?.querySelector('#aipkit_content_writer_generate_btn')?.dataset
                ?.aipkitStopMode === 'true'
        );

        return isContainerActive || isButtonInStopMode;
    }

    /**
     * Resets the Generate and Stop buttons to their initial state.
     * Relies on `aipkit_setLoadingStateOpenAI` being available globally.
     */
    function aipkit_resetContentWriterButtons() {
        const generateBtn = document.getElementById('aipkit_content_writer_generate_btn');
        const currentAction = generateBtn?.dataset?.action || 'generate';
        const labelMap = {
            generate: 'Generate',
            upgrade: 'Upgrade',
            update: 'Update'
        };
        const defaultLabel = labelMap[currentAction] || 'Generate';

        if (generateBtn) {
            stopTimer();
            if (generateBtn.dataset.aipkitStopMode) {
                delete generateBtn.dataset.aipkitStopMode;
            }
            const actionShell = generateBtn.closest('.aipkit_cw_action_shell');
            if (actionShell) {
                actionShell.classList.remove('is-stop-mode');
            }
            if (typeof window.aipkit_setContentWriterStopRequested === 'function') {
                window.aipkit_setContentWriterStopRequested(false);
            }
            delete window.aipkit_cw_singleRequestStep;
            aipkit_setContentWriterSingleRunState(false);
            if (typeof window.aipkit_setLoadingStateOpenAI === 'function') {
                window.aipkit_setLoadingStateOpenAI(generateBtn, false, defaultLabel);
            } else {
                generateBtn.disabled = false;
                generateBtn.textContent = defaultLabel; // Fallback
            }
            generateBtn.style.display = 'inline-flex';
        }

        // Keep status indicators visible after generation completes
        // Only reset the indicators when explicitly requested, not when buttons are reset
        // This allows users to see the generation status even after completion
        if (typeof window.aipkit_updateContentWriterActionState === 'function') {
            window.aipkit_updateContentWriterActionState();
        }
        if (typeof window.aipkit_syncContentWriterSessionCardState === 'function') {
            window.aipkit_syncContentWriterSessionCardState();
        }
    }

    window.aipkit_setContentWriterStopMode = aipkit_setContentWriterStopMode;
    window.aipkit_resetContentWriterButtons = aipkit_resetContentWriterButtons;
    window.aipkit_setContentWriterSinglePreviewState = aipkit_setContentWriterSinglePreviewState;
    window.aipkit_setContentWriterSingleRunState = aipkit_setContentWriterSingleRunState;
    window.aipkit_cw_isSingleRunActive = aipkit_isContentWriterSingleRunActive;
    window.aipkit_resetContentWriterCompletionTime = resetCompletionTime;

    window.addEventListener('resize', scheduleSinglePreviewCanvasViewportHeightUpdate, { passive: true });
    window.addEventListener('scroll', scheduleSinglePreviewCanvasViewportHeightUpdate, { passive: true });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', scheduleSinglePreviewCanvasViewportHeightUpdate, { passive: true });
        window.visualViewport.addEventListener('scroll', scheduleSinglePreviewCanvasViewportHeightUpdate, { passive: true });
    }
})();
