// UPDATED FILE
/**
 * AIPKit Settings - ElevenLabs Voice and Model Select Updaters
 */
(function() {
    'use strict';

    function aipkit_updateElevenLabsJsonData(type, items) {
        const list = Array.isArray(items) ? items : [];
        const json = JSON.stringify(list);
        const prefix = type === 'models'
            ? 'aipkit_elevenlabs_models_json_'
            : 'aipkit_elevenlabs_voices_json_';
        const selector = `[id^="${prefix}"]`;
        document.querySelectorAll(selector).forEach((element) => {
            if (type === 'models') {
                element.dataset.models = json;
            } else {
                element.dataset.voices = json;
            }
        });
    }

    /**
     * Update the ElevenLabs TTS voice select dropdown.
     * @param {Array} voices Array of voice objects [{ id: '...', name: '...' }, ...].
     * @param {string} selectElementId The ID of the <select> element. (Optional, defaults to main settings select)
     */
    function aipkit_updateElevenLabsVoices(voices, selectElementId = 'aipkit_elevenlabs_voice_id') {
        const normalizedVoices = Array.isArray(voices) ? voices : [];
        aipkit_updateElevenLabsJsonData('voices', normalizedVoices);
        const select = document.getElementById(selectElementId);
        if (!select) {
             console.warn(`ElevenLabs Sync (Voices): Select element #${selectElementId} not found.`);
             return;
        }
        if (
            typeof selectElementId === 'string' &&
            selectElementId.startsWith('aipkit_bot_') &&
            typeof window.aipkit_chatTts_updateElevenLabsVoiceSelect === 'function'
        ) {
            window.aipkit_chatTts_updateElevenLabsVoiceSelect(
                selectElementId,
                normalizedVoices,
                select.value || ''
            );
            return;
        }
        const oldValue = select.value;
        select.innerHTML = ''; // Clear existing options

        // Add a default blank option
        select.appendChild(new Option('-- Select a Voice (Optional) --', ''));

        if (!normalizedVoices.length) {
            if (oldValue && oldValue !== '') {
                select.appendChild(new Option(oldValue + '', oldValue, false, true));
                select.value = oldValue;
            }
            return;
        }

        let foundOldValue = false;
        normalizedVoices.sort((a, b) => (a.name || a.id || '').localeCompare(b.name || b.id || ''));

        normalizedVoices.forEach(v => {
            const voiceId = v.id || '';
            const voiceName = v.name || voiceId;
            if (!voiceId) return;
            const option = new Option(voiceName, voiceId);
            if (voiceId === oldValue) {
                option.selected = true;
                foundOldValue = true;
            }
            select.appendChild(option);
        });

        if (!foundOldValue && oldValue && oldValue !== '') {
             if (!select.querySelector(`option[value="${CSS.escape(oldValue)}"]`)) {
                 const oldOption = new Option(oldValue + '', oldValue, false, true);
                 select.appendChild(oldOption);
                 select.value = oldValue;
             }
         }
    }

    /**
     * Updates the ElevenLabs TTS *model* select dropdown.
     * @param {Array} models Array of model objects [{ id: '...', name: '...' }, ...].
     * @param {string} selectElementId The ID of the <select> element. (Optional, defaults to main settings select)
     */
    function aipkit_updateElevenLabsModelsSelect(models, selectElementId = 'aipkit_elevenlabs_tts_model_id') {
        const normalizedModels = Array.isArray(models) ? models : [];
        aipkit_updateElevenLabsJsonData('models', normalizedModels);
        const select = document.getElementById(selectElementId);
        if (!select) {
             console.warn(`ElevenLabs Sync (Models): Select element #${selectElementId} not found.`);
             return;
        }
        if (
            typeof selectElementId === 'string' &&
            selectElementId.startsWith('aipkit_bot_') &&
            typeof window.aipkit_chatTts_updateElevenLabsModelsSelect === 'function'
        ) {
            window.aipkit_chatTts_updateElevenLabsModelsSelect(
                selectElementId,
                normalizedModels,
                select.value || ''
            );
            return;
        }
        const oldValue = select.value;
        select.innerHTML = ''; // Clear existing options

        // Add a default blank option
        select.appendChild(new Option('-- Select a Model (Optional) --', ''));

        if (!normalizedModels.length) {
            if (oldValue && oldValue !== '') {
                select.appendChild(new Option(oldValue + '', oldValue, false, true));
                select.value = oldValue;
            }
            return;
        }

        let foundOldValue = false;
        // Sort models by name for better display
        normalizedModels.sort((a, b) => (a.name || a.id || '').localeCompare(b.name || b.id || ''));

        normalizedModels.forEach(m => {
            const modelId = m.id || '';
            const modelName = m.name || modelId;
            if (!modelId) return;
            const option = new Option(modelName, modelId);
            if (modelId === oldValue) {
                option.selected = true;
                foundOldValue = true;
            }
            select.appendChild(option);
        });

        if (!foundOldValue && oldValue && oldValue !== '') {
             if (!select.querySelector(`option[value="${CSS.escape(oldValue)}"]`)) {
                 const oldOption = new Option(oldValue + '', oldValue, false, true);
                 select.appendChild(oldOption);
                 select.value = oldValue;
             }
         }
    }


    // Expose globally
    window.aipkit_updateElevenLabsVoices = aipkit_updateElevenLabsVoices;
    window.aipkit_updateElevenLabsModelsSelect = aipkit_updateElevenLabsModelsSelect; // NEW

})();
