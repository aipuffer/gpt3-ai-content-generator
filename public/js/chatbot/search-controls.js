/** Chatbot web-search and Google-grounding toggle state and presentation. */
(function() {
    'use strict';

    function updateSearchToggle(button, config, state, stateKey, textPrefix, label) {
        button.classList.toggle("aipkit_active", state[stateKey]);
        button.title = state[stateKey]
            ? config.text?.[textPrefix + "Active"] || label + " Active"
            : config.text?.[textPrefix + "Inactive"] || label + " Inactive";
        button.setAttribute("aria-pressed", state[stateKey].toString());
    }

    (function() {
        'use strict';

        /**
         * Applies a specific state to the provider Web Search toggle.
         * @param {object} elements - UI elements reference.
         * @param {object} config - Chatbot configuration object.
         * @param {object} state - Mutable state object.
         * @param {boolean} isActive - Whether web search should be active.
         */
        function aipkit_syncWebSearchToggleState(elements, config, state, isActive) {
            const { webSearchToggleButton } = elements;
            const allowProviderWebSearchTool =
                config.allowWebSearchTool &&
                (config.provider === "OpenAI" ||
                    config.provider === "Claude" ||
                    config.provider === "OpenRouter" ||
                    config.provider === "xAI");

            state.webSearchToolActive = allowProviderWebSearchTool && Boolean(isActive);

            if (!webSearchToggleButton || !allowProviderWebSearchTool) return;
            updateSearchToggle(webSearchToggleButton, config, state, "webSearchToolActive", "webSearch", "Web Search");
        }

        /**
         * Toggles the Web Search tool state for supported providers.
         * @param {object} elements - UI elements reference.
         * @param {object} config - Chatbot configuration object.
         * @param {object} state - Mutable state object.
         */
        function aipkit_toggleWebSearchAction(elements, config, state) {
            aipkit_syncWebSearchToggleState(elements, config, state, !state.webSearchToolActive);
        }

        window.aipkit_syncWebSearchToggleState = aipkit_syncWebSearchToggleState;
        window.aipkit_toggleWebSearchAction = aipkit_toggleWebSearchAction;

    })();

    (function() {
        'use strict';

        /**
         * Applies a specific state to the Google Search Grounding toggle.
         * @param {object} elements - UI elements reference.
         * @param {object} config - Chatbot configuration object.
         * @param {object} state - Mutable state object.
         * @param {boolean} isActive - Whether Google grounding should be active.
         */
        function aipkit_syncGoogleSearchGroundingToggleState(elements, config, state, isActive) {
            const { googleSearchGroundingToggleButton } = elements;
            const allowGoogleSearchGrounding = config.allowGoogleSearchGrounding && config.provider === "Google";

            state.googleSearchGroundingActive = allowGoogleSearchGrounding && Boolean(isActive);

            if (!googleSearchGroundingToggleButton || !allowGoogleSearchGrounding) return;
            updateSearchToggle(googleSearchGroundingToggleButton, config, state, "googleSearchGroundingActive", "googleSearchGrounding", "Google Search Grounding");
        }

        /**
         * Toggles the Google Search Grounding tool state.
         * @param {object} elements - UI elements reference.
         * @param {object} config - Chatbot configuration object.
         * @param {object} state - Mutable state object.
         */
        function aipkit_toggleGoogleSearchGroundingAction(elements, config, state) {
            aipkit_syncGoogleSearchGroundingToggleState(elements, config, state, !state.googleSearchGroundingActive);
        }

        window.aipkit_syncGoogleSearchGroundingToggleState = aipkit_syncGoogleSearchGroundingToggleState;
        window.aipkit_toggleGoogleSearchGroundingAction = aipkit_toggleGoogleSearchGroundingAction;

    })();
})();
