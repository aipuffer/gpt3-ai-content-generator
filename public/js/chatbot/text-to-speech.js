/**
 * Optional chatbot text-to-speech: request, playback state and button lifecycle.
 * Public window APIs remain available to lazy loading, message actions and auto-play.
 */
(function() {
    'use strict';

    window.aipkitTtsState = {
        currentAudio: null,          // Stores the currently playing Audio object
        currentBlobUrl: null,        // Stores the currently active Blob URL for the audio
        currentlyPlayingButton: null // Tracks which button element triggered the current playback
    };

    let playback = null;
    let audioBindings = [];

    function isCurrentPlayback(work) {
        if (playback !== work) return false;
        if (work.button.isConnected === false || work.options.signal?.aborted ||
            (work.options.isCurrent && !work.options.isCurrent()) ||
            work.bubble.getAttribute('data-raw-text') !== work.text) {
            aipkit_tts_stopCurrentAudio();
            return false;
        }
        return true;
    }

    function ownsAudio(event) {
        const audio = window.aipkitTtsState?.currentAudio;
        return (!event?.target || event.target === audio) && (!playback || isCurrentPlayback(playback));
    }

    // Restore shared button presentation after a stop, playback error or request error.
    function resetPlaybackButton(button) {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.setAttribute('aria-pressed', 'false');
        const playLabel = button.getAttribute('data-play-label') || 'Play audio';
        button.setAttribute('title', playLabel);
        button.setAttribute('aria-label', playLabel);
        const originalIconHtml = button.getAttribute('data-original-icon-html');
        if (originalIconHtml) {
            button.innerHTML = originalIconHtml;
        }
        button.removeAttribute('data-original-icon-html');
    }

    /**
     * Stops any currently playing audio, revokes Blob URL, and resets the button state.
     * Accesses global state via `window.aipkitTtsState`.
     * @param {boolean} forceResetButton - If true, forces reset of button UI even if button not currently tracked.
     */
    function aipkit_tts_stopCurrentAudio(forceResetButton = true) {
        const state = window.aipkitTtsState;
        if (!state) {
            console.error("AIPKit TTS StopAudio: State object not found.");
            return;
        }

        const work = playback;
        playback = null;
        work?.options.signal?.removeEventListener('abort', work.cancel);
        if (forceResetButton && work && work.button !== state.currentlyPlayingButton) {
            work.button.classList.remove('aipkit_loading', 'aipkit_playing', 'aipkit_error');
            resetPlaybackButton(work.button);
        }
        const audio = state.currentAudio;
        state.currentAudio = null;
        if (audio) {
            for (const [event, handler] of audioBindings) audio.removeEventListener(event, handler);
            audioBindings = [];
            audio.pause();
            audio.src = '';
        }

        // Revoke the Blob URL if it exists
        if (state.currentBlobUrl) {
            URL.revokeObjectURL(state.currentBlobUrl);
            state.currentBlobUrl = null;
        }

        // Reset button state
        if (forceResetButton && state.currentlyPlayingButton) {
            state.currentlyPlayingButton.classList.remove('aipkit_loading', 'aipkit_playing', 'aipkit_error');
            resetPlaybackButton(state.currentlyPlayingButton);

            state.currentlyPlayingButton = null; // Clear tracked button
        }
    }

    window.aipkit_tts_stopCurrentAudio = aipkit_tts_stopCurrentAudio;

    const pauseIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" class="icon icon-tabler icon-tabler-player-pause-filled"><path d="M6 5.75A1.75 1.75 0 0 1 7.75 4h1.5A1.75 1.75 0 0 1 11 5.75v12.5A1.75 1.75 0 0 1 9.25 20h-1.5A1.75 1.75 0 0 1 6 18.25zM13 5.75A1.75 1.75 0 0 1 14.75 4h1.5A1.75 1.75 0 0 1 18 5.75v12.5A1.75 1.75 0 0 1 16.25 20h-1.5A1.75 1.75 0 0 1 13 18.25z"/></svg>`;

    function aipkit_tts_handleCanPlayThrough(event = null) {
        if (!ownsAudio(event)) return;
        const state = window.aipkitTtsState;
        if (!state || !state.currentAudio || !state.currentlyPlayingButton) return;

        state.currentlyPlayingButton.classList.remove('aipkit_loading');
        state.currentlyPlayingButton.classList.add('aipkit_playing');
        state.currentlyPlayingButton.disabled = false; // Re-enable button
        state.currentlyPlayingButton.removeAttribute('aria-busy');
        state.currentlyPlayingButton.setAttribute('aria-pressed', 'true');

        if (!state.currentlyPlayingButton.hasAttribute('data-original-icon-html')) {
            state.currentlyPlayingButton.setAttribute('data-original-icon-html', state.currentlyPlayingButton.innerHTML);
        }
        state.currentlyPlayingButton.innerHTML = pauseIconSvg;
        const pauseLabel = state.currentlyPlayingButton.getAttribute('data-pause-label') || 'Pause audio';
        state.currentlyPlayingButton.setAttribute('title', pauseLabel);
        state.currentlyPlayingButton.setAttribute('aria-label', pauseLabel);

        const audio = state.currentAudio;
        audio.play().catch(e => {
            if (window.aipkitTtsState?.currentAudio !== audio || !ownsAudio()) return;
            console.error("AIPKit TTS Handler: Error starting playback:", e);
            if (typeof window.aipkit_tts_handleAudioError === 'function') {
                window.aipkit_tts_handleAudioError(e); // Pass the event or error object
            }
        });
    }

    function aipkit_tts_handleAudioEnded(event = null) {
        if (!ownsAudio(event)) return;
        if (typeof window.aipkit_tts_stopCurrentAudio === 'function') {
            window.aipkit_tts_stopCurrentAudio(true);
        } else {
            console.error("AIPKit TTS Handler: stopCurrentAudio function not found for ended event.");
        }
    }

    function aipkit_tts_handleAudioPaused(event = null) {
        if (!ownsAudio(event)) return;
        const state = window.aipkitTtsState;
        if (!state || !state.currentAudio) return; // Only act if currentAudio is still tracked

        if (typeof window.aipkit_tts_stopCurrentAudio === 'function') {
            window.aipkit_tts_stopCurrentAudio(true);
        } else {
             console.error("AIPKit TTS Handler: stopCurrentAudio function not found for paused event.");
        }
    }

    function aipkit_tts_handleAudioError(eventOrError) {
        if (!ownsAudio(eventOrError)) return;
        const state = window.aipkitTtsState;
        if (!state) return;

        console.error("AIPKit TTS Handler: Error playing audio.", eventOrError);
        const buttonToReset = state.currentlyPlayingButton; // Capture button before state might be cleared by stopCurrentAudio

        if (typeof window.aipkit_tts_stopCurrentAudio === 'function') {
            window.aipkit_tts_stopCurrentAudio(false); // Stop audio, don't force button reset here
        }

        if (buttonToReset) {
            buttonToReset.classList.remove('aipkit_loading', 'aipkit_playing');
            buttonToReset.classList.add('aipkit_error');
            resetPlaybackButton(buttonToReset);
        }
        state.currentlyPlayingButton = null; // Ensure tracker is cleared by this handler too
    }

    // Expose handlers globally
    window.aipkit_tts_handleCanPlayThrough = aipkit_tts_handleCanPlayThrough;
    window.aipkit_tts_handleAudioEnded = aipkit_tts_handleAudioEnded;
    window.aipkit_tts_handleAudioPaused = aipkit_tts_handleAudioPaused;
    window.aipkit_tts_handleAudioError = aipkit_tts_handleAudioError;

    /**
     * Decodes base64, creates Blob URL, creates Audio object, adds listeners, and plays.
     * @param {string} base64Data The base64 encoded audio data.
     * @param {string} mimeType The MIME type of the audio (e.g., 'audio/mpeg').
     * @param {HTMLElement} buttonEl The button associated with this playback.
     */
    function aipkit_tts_playAudioFromBase64(base64Data, mimeType, buttonEl) {
        const state = window.aipkitTtsState;
        if (!state) {
            console.error("AIPKit TTS PlayFromBase64: State object not found.");
            if (typeof window.aipkit_tts_handleAudioError === 'function') window.aipkit_tts_handleAudioError(new Error("TTS State missing"));
            return;
        }

        try {
            const byteCharacters = atob(base64Data);
            const byteNumbers = new Array(byteCharacters.length);
            for (let i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            const byteArray = new Uint8Array(byteNumbers);
            const blob = new Blob([byteArray], { type: mimeType });

            if (state.currentAudio && typeof window.aipkit_tts_stopCurrentAudio === 'function') {
                window.aipkit_tts_stopCurrentAudio(true);
            }
            if (state.currentBlobUrl) URL.revokeObjectURL(state.currentBlobUrl);
            state.currentBlobUrl = URL.createObjectURL(blob);

            state.currentAudio = new Audio(state.currentBlobUrl);
            state.currentlyPlayingButton = buttonEl;

            audioBindings = [];
            for (const [event, name] of [['canplaythrough', 'CanPlayThrough'], ['ended', 'AudioEnded'], ['pause', 'AudioPaused'], ['error', 'AudioError']]) {
                const handler = window['aipkit_tts_handle' + name];
                if (typeof handler === 'function') {
                    audioBindings.push([event, handler]);
                    state.currentAudio.addEventListener(event, handler);
                }
            }

            state.currentAudio.load(); // Start loading the audio

        } catch (error) {
            console.error("AIPKit TTS PlayFromBase64: Error processing base64 or creating Blob URL:", error);
            if (typeof window.aipkit_tts_handleAudioError === 'function') {
                window.aipkit_tts_handleAudioError(error); // Trigger global error handler
            }
        }
    }

    window.aipkit_tts_playAudioFromBase64 = aipkit_tts_playAudioFromBase64;

    /**
     * Handles the click event on a play button or an auto-play trigger.
     * Fetches the base64 audio data and plays it using a Blob URL.
     * @param {HTMLElement} playButton The button element that was clicked or associated with auto-play.
     */
    async function aipkit_handlePlayAction(playButton, options = {}) {
        const state = window.aipkitTtsState;
        if (!state) {
            console.error("AIPKit TTS PlayAction: State object not found.");
            // Handle button reset if possible
            if (playButton) {
                playButton.classList.remove('aipkit_loading');
                playButton.classList.add('aipkit_error');
                playButton.disabled = false;
            }
            return;
        }

        const messageContainer = playButton.closest('.aipkit_chat_message');
        const bubble = messageContainer ? messageContainer.querySelector('.aipkit_chat_bubble') : null;
        let configHolder = playButton.closest('.aipkit_chat_container[data-config]');
        if (!configHolder) {
            configHolder = playButton.closest('.aipkit_popup_wrapper[data-config]');
        }
        const configAttr = configHolder ? configHolder.getAttribute('data-config') : null;
        let config = null;

        if (!bubble || !configHolder || !configAttr) {
            console.error("AIPKit TTS PlayAction: Could not find message bubble, config holder, or config attribute.");
            if (playButton) {
                playButton.classList.remove('aipkit_loading');
                playButton.classList.add('aipkit_error');
                playButton.disabled = false;
            }
            return;
        }
        try { config = JSON.parse(configAttr); }
        catch (e) { console.error("AIPKit TTS PlayAction: Failed to parse config.", e); return; }

        const textToPlay = bubble.getAttribute("data-raw-text") || "";
        const botId = config.botId;
        // TTS provider, voiceId, and modelId (for ElevenLabs/OpenAI) are now part of the config object
        // passed to aipkit_frontendApiRequest, which then passes it to aipkit_speech_manager->text_to_speech.

        if (!textToPlay) { console.warn("AIPKit TTS PlayAction: No text found to play."); return; }
        if (!botId) { console.error("AIPKit TTS PlayAction: Missing botId in config."); return; }


        if (options.signal?.aborted || (options.isCurrent && !options.isCurrent()) || playButton.isConnected === false) return;

        if (playButton === state.currentlyPlayingButton) {
            if (typeof window.aipkit_tts_stopCurrentAudio === 'function') window.aipkit_tts_stopCurrentAudio(true);
            return;
        }

        if (typeof window.aipkit_tts_stopCurrentAudio === 'function') window.aipkit_tts_stopCurrentAudio(true);
        const work = { button: playButton, bubble, text: textToPlay, options };
        work.cancel = () => { if (playback === work) aipkit_tts_stopCurrentAudio(); };
        playback = work;
        options.signal?.addEventListener('abort', work.cancel, { once: true });

        playButton.classList.remove('aipkit_error', 'aipkit_playing');
        playButton.classList.add('aipkit_loading');
        playButton.disabled = true;
        playButton.setAttribute('aria-busy', 'true');
        playButton.setAttribute('aria-pressed', 'false');
        state.currentlyPlayingButton = playButton;

        try {
            const data = { text: textToPlay }; // Backend will use bot_id from config to fetch TTS settings
            if (typeof window.aipkit_frontendApiRequest !== 'function') {
                console.error('AIPKit TTS PlayAction Error: aipkit_frontendApiRequest function not found.');
                throw new Error("Internal script error (TTS API).");
            }
            const response = await window.aipkit_frontendApiRequest("aipkit_generate_speech", data, config, { signal: options.signal });

            if (!isCurrentPlayback(work)) return;
            if (response && response.audio_data_base64 && response.mime_type) {
                if (typeof window.aipkit_tts_playAudioFromBase64 === 'function') {
                    window.aipkit_tts_playAudioFromBase64(response.audio_data_base64, response.mime_type, playButton);
                } else {
                     console.error('AIPKit TTS PlayAction Error: playAudioFromBase64 function not found.');
                     throw new Error("Internal script error (TTS Playback).");
                }
            } else {
                console.error("AIPKit TTS PlayAction: API response missing audio data or mime type. Response:", response);
                throw new Error("Invalid response received from speech generation.");
            }
        } catch (error) {
            if (!isCurrentPlayback(work)) return;
            if (window.aipkit_chatUI_showSpeechError) {
                window.aipkit_chatUI_showSpeechError(error, { container: configHolder, config,
                    isCurrent: () => playButton.isConnected !== false && (!options.isCurrent || options.isCurrent()) });
            }
            aipkit_tts_stopCurrentAudio(false);
            console.error("AIPKit TTS PlayAction: Error generating speech:", error);
            // Error state is typically handled by the audio element's error handler,
            // but if fetch itself fails, we need to reset the button here.
            if (playButton) {
                playButton.classList.remove('aipkit_loading', 'aipkit_playing');
                playButton.classList.add('aipkit_error');
                resetPlaybackButton(playButton);
            }
            state.currentlyPlayingButton = null; // Ensure tracker is cleared
        }
    }

    window.aipkit_handlePlayAction = aipkit_handlePlayAction;

})();
