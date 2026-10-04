/**
 * AIPKit AutoGPT - Main Initializer
 *
 * Orchestrates initialization of features within the AutoGPT module.
 */
(function() {
    'use strict';

    function createEmptyWorkspaceController(autogptContainer) {
        const state = {
            tasksLoaded: false,
            queueLoaded: false,
            taskCount: 0,
            queueCount: 0,
            loadFailed: false,
        };
        let resolveReady;
        let isReady = false;
        const whenReady = new Promise((resolve) => {
            resolveReady = resolve;
        });

        const formWrapper = autogptContainer.querySelector('#aipkit_automated_task_form_wrapper');
        const taskListWrapper = autogptContainer.querySelector('#aipkit_automated_task_list_wrapper');
        const queueWrapper = autogptContainer.querySelector('#aipkit_automated_task_queue_wrapper');
        const addTaskButton = autogptContainer.querySelector('#aipkit_add_new_task_btn');
        const emptyWorkspace = autogptContainer.querySelector('#aipkit_autogpt_empty_workspace');
        const emptyCreateButton = emptyWorkspace?.querySelector('[data-aipkit-autogpt-empty-create]');

        const isFormVisible = () =>
            Boolean(formWrapper) && window.getComputedStyle(formWrapper).display !== 'none';

        const setWorkspaceState = (workspaceState) => {
            autogptContainer.dataset.workspaceState = workspaceState;
            if (!isReady && workspaceState !== 'checking') {
                isReady = true;
                resolveReady(workspaceState);
            }
        };

        const hideEmptyWorkspace = () => {
            if (emptyWorkspace) emptyWorkspace.hidden = true;
        };

        const showOverview = () => {
            hideEmptyWorkspace();
            if (taskListWrapper) taskListWrapper.style.display = 'block';
            if (queueWrapper) queueWrapper.style.display = 'block';
            if (addTaskButton) addTaskButton.style.display = 'inline-flex';
        };

        const showEmptyWorkspace = () => {
            if (formWrapper) formWrapper.style.display = 'none';
            if (taskListWrapper) taskListWrapper.style.display = 'none';
            if (queueWrapper) queueWrapper.style.display = 'none';
            if (emptyWorkspace) emptyWorkspace.hidden = false;
        };

        const openWizard = () => {
            if (typeof window.aipkit_form_showAutomatedTaskForm !== 'function') {
                showOverview();
                return;
            }
            hideEmptyWorkspace();
            window.aipkit_form_showAutomatedTaskForm(
                formWrapper,
                taskListWrapper,
                addTaskButton
            );
        };

        const sync = () => {
            if (isFormVisible()) {
                setWorkspaceState('editor');
                autogptContainer.setAttribute('aria-busy', 'false');
                hideEmptyWorkspace();
                return;
            }

            if (state.loadFailed) {
                setWorkspaceState('overview');
                autogptContainer.setAttribute('aria-busy', 'false');
                showOverview();
                return;
            }

            const hasKnownContent =
                (state.tasksLoaded && state.taskCount > 0) ||
                (state.queueLoaded && state.queueCount > 0);

            if (hasKnownContent) {
                setWorkspaceState('overview');
                autogptContainer.setAttribute('aria-busy', 'false');
                hideEmptyWorkspace();
                showOverview();
                return;
            }

            if (!state.tasksLoaded || !state.queueLoaded) return;

            const isEmpty = state.taskCount === 0 && state.queueCount === 0;
            setWorkspaceState(isEmpty ? 'empty' : 'overview');
            autogptContainer.setAttribute('aria-busy', 'false');

            if (!isEmpty) {
                hideEmptyWorkspace();
                showOverview();
                return;
            }

            showEmptyWorkspace();
        };

        const report = (kind, count) => {
            const normalizedCount = Math.max(0, Number(count) || 0);
            if (kind === 'tasks') {
                state.tasksLoaded = true;
                state.taskCount = normalizedCount;
            } else if (kind === 'queue') {
                state.queueLoaded = true;
                state.queueCount = normalizedCount;
            }
            sync();
        };

        emptyCreateButton?.addEventListener('click', openWizard);

        return {
            whenReady,
            contains: (element) => Boolean(element) && autogptContainer.contains(element),
            reportTasks: (count) => report('tasks', count),
            reportQueue: (count) => report('queue', count),
            reportLoadError: () => {
                state.loadFailed = true;
                sync();
            },
            handleFormShown: () => {
                setWorkspaceState('editor');
                autogptContainer.setAttribute('aria-busy', 'false');
                hideEmptyWorkspace();
            },
            handleFormHidden: sync,
            handleTaskSaved: () => {
                state.tasksLoaded = true;
                state.taskCount = Math.max(1, state.taskCount);
                sync();
            },
        };
    }

    /**
     * Main initializer for the AutoGPT module.
     * Called by the main dashboard loader when the 'autogpt' module is loaded.
     */
    function aipkit_initAutogpt() {

        const autogptContainer = document.getElementById('aipkit_autogpt_container');

        if (!autogptContainer) {
            return;
        }

        const emptyWorkspaceController = createEmptyWorkspaceController(autogptContainer);
        window.aipkit_autogpt_empty_workspace_controller = emptyWorkspaceController;

        const formatCronNextRun = () => {
            if (!autogptContainer) return;
            const timestampElements = autogptContainer.querySelectorAll(
                '#aipkit_autogpt_cron_status [data-aipkit-cron-timestamp]'
            );
            timestampElements.forEach((timestampElement) => {
                const rawValue = timestampElement.dataset.aipkitCronTimestamp;
                const timestamp = rawValue ? parseInt(rawValue, 10) : 0;
                if (!timestamp || Number.isNaN(timestamp)) {
                    return;
                }

                const date = new Date(timestamp * 1000);
                const formatted = date.toLocaleString(undefined, {
                    month: "short",
                    day: "numeric",
                    hour: "2-digit",
                    minute: "2-digit",
                });

                timestampElement.textContent = formatted;
                timestampElement.title = date.toLocaleString();
            });
        };

        formatCronNextRun();

        if (typeof window.aipkit_initServerCronControls === 'function') {
            window.aipkit_initServerCronControls(autogptContainer);
        }

        // Initialize Content Indexing Task Form UI specific logic
        if (typeof window.aipkit_initContentIndexingTaskFormUI === 'function') {
            try {
                window.aipkit_initContentIndexingTaskFormUI();
            } catch (e) {
                console.error("AIPKit AutoGPT: Error initializing Content Indexing Task Form UI:", e);
            }
        } else {
            console.warn("AIPKit AutoGPT: Content Indexing Task Form UI initializer (aipkit_initContentIndexingTaskFormUI) not found.");
        }
        // Initialize other task type UI handlers here in the future

        // Initialize Automated Tasks GENERAL Form Handler (must come after task-specific UI inits if they expose functions form handler might need immediately)
        if (typeof window.aipkit_initAutomatedTaskForm === 'function') {
            try {
                window.aipkit_initAutomatedTaskForm();
            } catch (e) {
                console.error("AIPKit AutoGPT: Error initializing Automated Tasks Form Handler:", e);
            }
        } else {
            console.warn("AIPKit AutoGPT: Automated Tasks Form Handler initializer (aipkit_initAutomatedTaskForm) not found.");
        }

        // Initialize Automated Tasks List Handler
        if (typeof window.aipkit_initAutomatedTaskList === 'function') {
            try {
                window.aipkit_initAutomatedTaskList();
            } catch (e) {
                emptyWorkspaceController.reportLoadError();
                console.error("AIPKit AutoGPT: Error initializing Automated Tasks List Handler:", e);
            }
        } else {
            emptyWorkspaceController.reportLoadError();
            console.warn("AIPKit AutoGPT: Automated Tasks List Handler initializer (aipkit_initAutomatedTaskList) not found.");
        }
        
        // Initialize Automated Tasks Queue Handler
        if (typeof window.aipkit_initAutomatedTaskQueue === 'function') {
            try {
                window.aipkit_initAutomatedTaskQueue();
            } catch (e) {
                emptyWorkspaceController.reportLoadError();
                console.error("AIPKit AutoGPT: Error initializing Automated Tasks Queue Handler:", e);
            }
        } else {
            emptyWorkspaceController.reportLoadError();
            console.warn("AIPKit AutoGPT: Automated Tasks Queue Handler initializer (aipkit_initAutomatedTaskQueue) not found.");
        }

        if (typeof window.aipkit_initAutogptInlinePrompts === 'function') {
            try {
                window.aipkit_initAutogptInlinePrompts(autogptContainer);
            } catch (e) {
                console.error("AIPKit AutoGPT: Error initializing inline prompts:", e);
            }
        }

        if (typeof window.aipkit_initAutogptUnifiedModelSelectors === 'function') {
            try {
                window.aipkit_initAutogptUnifiedModelSelectors(autogptContainer);
            } catch (e) {
                console.error("AIPKit AutoGPT: Error initializing model selectors:", e);
            }
        }

        if (typeof window.aipkit_initAutogptProgressiveBuilder === 'function') {
            try {
                window.aipkit_initAutogptProgressiveBuilder(autogptContainer);
            } catch (e) {
                console.error("AIPKit AutoGPT: Error initializing progressive task builder:", e);
            }
        }

        if (typeof window.aipkit_initApiKeyToggles === 'function') {
            window.aipkit_initApiKeyToggles('#aipkit_autogpt_container');
        } else {
            console.warn("AIPKit AutoGPT: aipkit_initApiKeyToggles not found.");
        }

        return emptyWorkspaceController.whenReady;
    }

    window.aipkit_initAutogpt = aipkit_initAutogpt;

})();
