/**
 * Optional chatbot speech-to-text: microphone, waveform, conversion and input flow.
 * Public window APIs retain the existing lazy-loading and recorder ownership contracts.
 */
(function () {
    'use strict';

    window.aipkitSttState = {
        mediaRecorder: null,          // The MediaRecorder instance
        audioStream: null,            // The MediaStream object from getUserMedia
        activeVoiceInputButton: null, // Owns the single recorder across chatbot instances
        cancelRequested: false,       // Stops an in-flight microphone start safely
        recordingRequestId: 0,        // Invalidates an in-flight microphone permission request
        onRecordingStartCallback: () => {}, // Callback when recording starts
        onRecordingStopCallback: () => {}, // Callback when recording stops
        onRecordingErrorCallback: () => {}, // Callback on recording error
        onRecordingCancelCallback: () => {} // Callback after cancelled audio is discarded
    };

    /**
     * Checks for MediaRecorder API support.
     * @returns {boolean} True if supported, false otherwise.
     */
    function aipkit_isMediaRecorderSupported() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    }

    window.aipkit_isMediaRecorderSupported = aipkit_isMediaRecorderSupported;

    /**
     * Sets the callback functions for recording events.
     * @param {function} onStart Callback when recording starts.
     * @param {function} onStop Callback with audio Blob and mimeType when recording stops: onStop(audioBlob, mimeType).
     * @param {function} onError Callback on error: onError(error).
     * @param {function} onCancel Callback after a cancelled recording is discarded.
     */
    function aipkit_setRecordingCallbacks(onStart, onStop, onError, onCancel) {
        if (!window.aipkitSttState) {
            console.error("AIPKit STT Callbacks: State object not initialized.");
            return;
        }
        window.aipkitSttState.onRecordingStartCallback = typeof onStart === 'function' ? onStart : () => {};
        window.aipkitSttState.onRecordingStopCallback = typeof onStop === 'function' ? onStop : () => {};
        window.aipkitSttState.onRecordingErrorCallback = typeof onError === 'function' ? onError : () => {};
        window.aipkitSttState.onRecordingCancelCallback = typeof onCancel === 'function' ? onCancel : () => {};
    }

    window.aipkit_setRecordingCallbacks = aipkit_setRecordingCallbacks;

    const BAR_WIDTH = 3;
    const BAR_GAP = 3;
    const SAMPLE_INTERVAL_MS = 45;
    let audioContext = null;
    let analyserNode = null;
    let sourceNode = null;
    let sampleData = null;
    let canvas = null;
    let canvasContext = null;
    let resizeObserver = null;
    let animationFrameId = 0;
    let history = [];
    let barCount = 0;
    let displayWidth = 0;
    let displayHeight = 0;
    let lastSampleTime = 0;
    let smoothedLevel = 0;
    let activeColor = '';
    let idleColor = '';
    let resizeFallbackAttached = false;

    function prefersReducedMotion() {
        return (
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        );
    }

    function resolveColors() {
        if (!canvas) return;
        const styles = window.getComputedStyle(canvas);
        const currentColor = styles.color || '#6b6b6b';
        activeColor = styles
            .getPropertyValue('--aipkit-chat-voice-wave-active-color')
            .trim() || currentColor;
        idleColor = styles
            .getPropertyValue('--aipkit-chat-voice-wave-idle-color')
            .trim() || currentColor;
    }

    function resizeCanvas() {
        if (!canvas || !canvasContext) return;
        const rect = canvas.getBoundingClientRect();
        const nextWidth = Math.max(1, Math.floor(rect.width));
        const nextHeight = Math.max(1, Math.floor(rect.height));
        const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
        const backingWidth = Math.max(1, Math.floor(nextWidth * pixelRatio));
        const backingHeight = Math.max(1, Math.floor(nextHeight * pixelRatio));

        if (canvas.width !== backingWidth || canvas.height !== backingHeight) {
            canvas.width = backingWidth;
            canvas.height = backingHeight;
            canvasContext.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        }

        displayWidth = nextWidth;
        displayHeight = nextHeight;
        const nextBarCount = Math.max(
            12,
            Math.floor((displayWidth + BAR_GAP) / (BAR_WIDTH + BAR_GAP))
        );
        if (nextBarCount !== barCount) {
            const retained = history.slice(-nextBarCount);
            history = new Array(Math.max(0, nextBarCount - retained.length))
                .fill(0)
                .concat(retained);
            barCount = nextBarCount;
        }
        resolveColors();
    }

    function roundedBar(context, x, y, width, height) {
        const radius = Math.min(width / 2, height / 2);
        context.beginPath();
        context.moveTo(x + radius, y);
        context.lineTo(x + width - radius, y);
        context.quadraticCurveTo(x + width, y, x + width, y + radius);
        context.lineTo(x + width, y + height - radius);
        context.quadraticCurveTo(
            x + width,
            y + height,
            x + width - radius,
            y + height
        );
        context.lineTo(x + radius, y + height);
        context.quadraticCurveTo(x, y + height, x, y + height - radius);
        context.lineTo(x, y + radius);
        context.quadraticCurveTo(x, y, x + radius, y);
        context.closePath();
        context.fill();
    }

    function drawWaveform() {
        if (!canvasContext || displayWidth <= 0 || displayHeight <= 0) return;
        canvasContext.clearRect(0, 0, displayWidth, displayHeight);

        const step = BAR_WIDTH + BAR_GAP;
        const waveformWidth = Math.max(0, barCount * step - BAR_GAP);
        const startX = Math.max(0, (displayWidth - waveformWidth) / 2);
        const maxBarHeight = Math.max(6, displayHeight * 0.82);

        history.forEach((level, index) => {
            const barHeight = Math.max(3, 3 + level * (maxBarHeight - 3));
            const x = startX + index * step;
            const y = (displayHeight - barHeight) / 2;
            const isActive = level > 0.035;
            canvasContext.fillStyle = isActive ? activeColor : idleColor;
            canvasContext.globalAlpha = isActive
                ? Math.min(1, 0.56 + level * 0.44)
                : 0.42;
            roundedBar(canvasContext, x, y, BAR_WIDTH, barHeight);
        });

        canvasContext.globalAlpha = 1;
    }

    function readMicrophoneLevel() {
        if (!analyserNode || !sampleData) return 0;
        analyserNode.getByteTimeDomainData(sampleData);
        let squareTotal = 0;
        for (let index = 0; index < sampleData.length; index += 1) {
            const normalized = (sampleData[index] - 128) / 128;
            squareTotal += normalized * normalized;
        }
        const rms = Math.sqrt(squareTotal / sampleData.length);
        const targetLevel = Math.min(1, Math.max(0, (rms - 0.012) * 8));
        smoothedLevel = smoothedLevel * 0.66 + targetLevel * 0.34;
        return smoothedLevel;
    }

    function updateHistory(level) {
        if (prefersReducedMotion()) {
            history.fill(0);
            const center = Math.floor(history.length / 2);
            const spread = Math.min(4, Math.floor(history.length / 4));
            for (let offset = -spread; offset <= spread; offset += 1) {
                const weight = 1 - Math.abs(offset) / (spread + 1);
                history[center + offset] = level * weight;
            }
            return;
        }

        history.push(level);
        if (history.length > barCount) {
            history.shift();
        }
    }

    function animate(timestamp) {
        if (!canvas || !canvasContext) return;
        if (timestamp - lastSampleTime >= SAMPLE_INTERVAL_MS) {
            updateHistory(readMicrophoneLevel());
            lastSampleTime = timestamp;
        }
        drawWaveform();
        animationFrameId = window.requestAnimationFrame(animate);
    }

    function stopVisualizer() {
        if (animationFrameId) {
            window.cancelAnimationFrame(animationFrameId);
            animationFrameId = 0;
        }
        if (resizeObserver) {
            resizeObserver.disconnect();
            resizeObserver = null;
        }
        if (resizeFallbackAttached) {
            window.removeEventListener('resize', resizeCanvas);
            resizeFallbackAttached = false;
        }
        if (sourceNode) {
            try {
                sourceNode.disconnect();
            } catch {
                // The source may already have been disconnected by the browser.
            }
            sourceNode = null;
        }
        if (analyserNode) {
            try {
                analyserNode.disconnect();
            } catch {
                // The analyser may already have been disconnected by the browser.
            }
            analyserNode = null;
        }
        if (audioContext) {
            const contextToClose = audioContext;
            audioContext = null;
            contextToClose.close().catch(() => {});
        }
        sampleData = null;
        canvas = null;
        canvasContext = null;
        history = [];
        barCount = 0;
        displayWidth = 0;
        displayHeight = 0;
        lastSampleTime = 0;
        smoothedLevel = 0;
    }

    function startVisualizer(stream, waveformCanvas) {
        stopVisualizer();
        if (!stream || !waveformCanvas) return false;

        canvas = waveformCanvas;
        canvasContext = canvas.getContext('2d');
        if (!canvasContext) {
            stopVisualizer();
            return false;
        }

        if (typeof window.ResizeObserver === 'function') {
            resizeObserver = new ResizeObserver(resizeCanvas);
            resizeObserver.observe(canvas);
        } else {
            window.addEventListener('resize', resizeCanvas, { passive: true });
            resizeFallbackAttached = true;
        }
        resizeCanvas();
        drawWaveform();

        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            stopVisualizer();
            return false;
        }

        try {
            audioContext = new AudioContextClass();
            analyserNode = audioContext.createAnalyser();
            analyserNode.fftSize = 256;
            analyserNode.smoothingTimeConstant = 0.76;
            sourceNode = audioContext.createMediaStreamSource(stream);
            sourceNode.connect(analyserNode);
            sampleData = new Uint8Array(analyserNode.fftSize);
            if (audioContext.state === 'suspended') {
                audioContext.resume().catch(() => {});
            }
            animationFrameId = window.requestAnimationFrame(animate);
            return true;
        } catch {
            stopVisualizer();
            return false;
        }
    }

    window.aipkit_startVoiceRecordingVisualizer = startVisualizer;
    window.aipkit_stopVoiceRecordingVisualizer = stopVisualizer;

    /**
     * Requests microphone access and starts recording.
     * @param {object} options Recording UI options.
     * @param {HTMLCanvasElement|null} options.waveformCanvas Responsive waveform target.
     */
    async function aipkit_startRecording(options = {}) {
        if (!window.aipkitSttState) {
            console.error("AIPKit STT Start: State object not initialized.");
            return;
        }

        const state = window.aipkitSttState;

        if (typeof window.aipkit_isMediaRecorderSupported !== 'function' || !window.aipkit_isMediaRecorderSupported()) {
            console.error("AIPKit STT Start: MediaRecorder API not supported by this browser.");
            if (state.onRecordingErrorCallback) state.onRecordingErrorCallback(new Error("Recording not supported."));
            return;
        }

        if (state.mediaRecorder && state.mediaRecorder.state === "recording") {
            console.warn("AIPKit STT Start: Recording is already in progress.");
            return;
        }
        if (state.mediaRecorder && state.mediaRecorder.state === "paused") {
            state.mediaRecorder.resume();
            if (state.onRecordingStartCallback) state.onRecordingStartCallback(); // Indicate resumption
            return;
        }

        const requestId = state.recordingRequestId + 1;
        state.recordingRequestId = requestId;
        state.cancelRequested = false;
        let requestedStream = null;
        let recorder = null;

        try {
            requestedStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
            if (state.cancelRequested || requestId !== state.recordingRequestId) {
                requestedStream.getTracks().forEach(track => track.stop());
                return;
            }

            const preferredOptions = [
                'audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm',
                'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/mp3', 'audio/wav'
            ];
            let selectedMimeType = '';
            for (const type of preferredOptions) {
                if (MediaRecorder.isTypeSupported(type) && (!options.inputFormats?.length || options.inputFormats.includes(type.split(';')[0]))) {
                    selectedMimeType = type;
                    break;
                }
            }
            if (!selectedMimeType && !options.inputFormats?.length && MediaRecorder.isTypeSupported('')) {
                 selectedMimeType = '';
            } else if (!selectedMimeType) {
                 console.error("AIPKit STT Start: No suitable audio mimeType supported by MediaRecorder.");
                 throw new Error("No supported audio format found.");
            }

            const recorderOptions = selectedMimeType ? { mimeType: selectedMimeType } : {};
            if (options.maxBytes > 0 && options.maxSeconds > 0) {
                recorderOptions.audioBitsPerSecond = Math.min(64000, Math.floor(options.maxBytes * 6 / options.maxSeconds));
            }
            recorder = new MediaRecorder(requestedStream, recorderOptions);
            const audioChunks = [];
            const recordedMimeType = recorder.mimeType || selectedMimeType || 'application/octet-stream';
            let settled = false;
            let recordingTimer = null;
            let capturedBytes = 0;
            const callbacks = {
                start: state.onRecordingStartCallback, stop: state.onRecordingStopCallback,
                error: state.onRecordingErrorCallback, cancel: state.onRecordingCancelCallback,
            };

            function releaseRecordingResources() {
                if (recordingTimer !== null) window.clearTimeout(recordingTimer);
                if (state.mediaRecorder === recorder && typeof window.aipkit_stopVoiceRecordingVisualizer === 'function') {
                    window.aipkit_stopVoiceRecordingVisualizer();
                }
                requestedStream.getTracks().forEach(track => track.stop());
                if (state.audioStream === requestedStream) {
                    state.audioStream = null;
                }
                if (state.mediaRecorder === recorder) {
                    state.mediaRecorder = null;
                }
            }

            recorder._aipkitDiscardRecording = false;
            state.audioStream = requestedStream;
            state.mediaRecorder = recorder;
            if (typeof window.aipkit_startVoiceRecordingVisualizer === 'function') {
                window.aipkit_startVoiceRecordingVisualizer(
                    requestedStream,
                    options.waveformCanvas || null
                );
            }

            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    audioChunks.push(event.data);
                    capturedBytes += event.data.size;
                    if (options.maxBytes > 0 && capturedBytes >= options.maxBytes * 0.9 && recorder.state === 'recording') recorder.stop();
                }
            };

            recorder.onstop = () => {
                if (settled) return;
                settled = true;
                const shouldDiscard = recorder._aipkitDiscardRecording === true;
                releaseRecordingResources();

                if (shouldDiscard) {
                    if (callbacks.cancel) {
                        callbacks.cancel();
                    }
                } else if (audioChunks.length > 0) {
                    const audioBlob = new Blob(audioChunks, { type: recordedMimeType });
                    if (callbacks.stop) callbacks.stop(audioBlob, recordedMimeType);
                } else {
                    console.warn("AIPKit STT Start (onstop): Recording stopped, but no audio chunks received.");
                    if (callbacks.error) callbacks.error(new Error("No audio data recorded."));
                }
                if (requestId === state.recordingRequestId) {
                    state.cancelRequested = false;
                }
            };

            recorder.onerror = (event) => {
                 if (settled) return;
                 settled = true;
                 console.error("AIPKit STT Start (onerror): MediaRecorder error:", event.error);
                 const shouldDiscard = recorder._aipkitDiscardRecording === true;
                 if (!shouldDiscard && callbacks.error) {
                     callbacks.error(event.error);
                 } else if (shouldDiscard && callbacks.cancel) {
                     callbacks.cancel();
                 }
                 releaseRecordingResources();
                 if (requestId === state.recordingRequestId) {
                     state.cancelRequested = false;
                 }
            };

            recorder.start(250);
            if (options.maxSeconds > 0) recordingTimer = window.setTimeout(() => {
                if (recorder.state === 'recording') recorder.stop();
            }, Math.max(1, options.maxSeconds - 1) * 1000);
            if (callbacks.start) callbacks.start();

        } catch (err) {
            const requestWasCancelled =
                state.cancelRequested || requestId !== state.recordingRequestId;
            if (!requestWasCancelled) {
                console.error("AIPKit STT Start: Error accessing microphone or starting recorder:", err);
            }
            if (!requestWasCancelled && state.onRecordingErrorCallback) {
                state.onRecordingErrorCallback(err);
            }
            if (requestId === state.recordingRequestId && typeof window.aipkit_stopVoiceRecordingVisualizer === 'function') {
                window.aipkit_stopVoiceRecordingVisualizer();
            }
            if (requestedStream) {
                 requestedStream.getTracks().forEach(track => track.stop());
            }
            if (state.audioStream === requestedStream) {
                 state.audioStream = null;
            }
            if (state.mediaRecorder === recorder) {
                state.mediaRecorder = null;
            }
            if (requestId === state.recordingRequestId) {
                state.cancelRequested = false;
            }
        }
    }

    window.aipkit_startRecording = aipkit_startRecording;

    /**
     * Stops the current recording session.
     * The recorder's 'onstop' event handler will trigger the callback with the Blob.
     * @param {boolean} discardRecording Whether to discard the captured audio.
     * @returns {boolean} Whether an active recorder was asked to stop.
     */
    function aipkit_stopRecording(discardRecording = false) {
        if (!window.aipkitSttState) {
            console.error("AIPKit STT Stop: State object not initialized.");
            return false;
        }
        const state = window.aipkitSttState;
        state.cancelRequested = discardRecording;

        if (state.mediaRecorder && (state.mediaRecorder.state === "recording" || state.mediaRecorder.state === "paused")) {
            state.mediaRecorder._aipkitDiscardRecording = discardRecording;
            if (typeof window.aipkit_stopVoiceRecordingVisualizer === 'function') {
                window.aipkit_stopVoiceRecordingVisualizer();
            }
            state.mediaRecorder.stop();
            return true;
        } else {
            if (!discardRecording) {
                console.warn("AIPKit STT Stop: stopRecording called but no active/paused recording found.");
            } else {
                state.recordingRequestId += 1;
            }
            if (typeof window.aipkit_stopVoiceRecordingVisualizer === 'function') {
                window.aipkit_stopVoiceRecordingVisualizer();
            }
            if (state.audioStream) { // Ensure stream is cleaned up if somehow orphaned
                 state.audioStream.getTracks().forEach(track => track.stop());
                 state.audioStream = null;
            }
            return false;
        }
    }

    window.aipkit_stopRecording = aipkit_stopRecording;

  const GOOGLE_DIRECT_MIME_TYPES = new Set([
    "audio/wav",
    "audio/x-wav",
    "audio/mpeg",
    "audio/mp3",
    "audio/aiff",
    "audio/x-aiff",
    "audio/aac",
    "audio/flac",
    "audio/x-flac",
  ]);
  const TARGET_SAMPLE_RATE = 16000;

  function writeAscii(view, offset, value) {
    for (let index = 0; index < value.length; index += 1) {
      view.setUint8(offset + index, value.charCodeAt(index));
    }
  }

  function encodeMonoWav(samples, sampleRate) {
    const bytesPerSample = 2;
    const dataLength = samples.length * bytesPerSample;
    const buffer = new ArrayBuffer(44 + dataLength);
    const view = new DataView(buffer);

    writeAscii(view, 0, "RIFF");
    view.setUint32(4, 36 + dataLength, true);
    writeAscii(view, 8, "WAVE");
    writeAscii(view, 12, "fmt ");
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * bytesPerSample, true);
    view.setUint16(32, bytesPerSample, true);
    view.setUint16(34, 16, true);
    writeAscii(view, 36, "data");
    view.setUint32(40, dataLength, true);

    let offset = 44;
    for (let index = 0; index < samples.length; index += 1) {
      const sample = Math.max(-1, Math.min(1, samples[index]));
      view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true);
      offset += bytesPerSample;
    }

    return buffer;
  }

  function downmixAndResample(audioBuffer) {
    const sourceRate = audioBuffer.sampleRate;
    const targetLength = Math.max(
      1,
      Math.ceil((audioBuffer.length * TARGET_SAMPLE_RATE) / sourceRate)
    );
    const output = new Float32Array(targetLength);
    const channels = [];
    for (let channel = 0; channel < audioBuffer.numberOfChannels; channel += 1) {
      channels.push(audioBuffer.getChannelData(channel));
    }

    const sourceStep = sourceRate / TARGET_SAMPLE_RATE;
    for (let outputIndex = 0; outputIndex < targetLength; outputIndex += 1) {
      const sourcePosition = Math.min(
        audioBuffer.length - 1,
        outputIndex * sourceStep
      );
      const leftIndex = Math.floor(sourcePosition);
      const rightIndex = Math.min(audioBuffer.length - 1, leftIndex + 1);
      const fraction = sourcePosition - leftIndex;
      let mixedSample = 0;
      for (const channelData of channels) {
        mixedSample +=
          channelData[leftIndex] +
          (channelData[rightIndex] - channelData[leftIndex]) * fraction;
      }
      output[outputIndex] = mixedSample / channels.length;
    }

    return output;
  }

  async function decodeAudio(context, arrayBuffer) {
    return new Promise((resolve, reject) => {
      const result = context.decodeAudioData(arrayBuffer, resolve, reject);
      if (result && typeof result.then === "function") {
        result.then(resolve, reject);
      }
    });
  }

  async function aipkit_prepareGoogleSttAudio(audioBlob, mimeType) {
    const normalizedMime = String(mimeType || audioBlob?.type || "")
      .split(";")[0]
      .trim()
      .toLowerCase();
    if (GOOGLE_DIRECT_MIME_TYPES.has(normalizedMime)) {
      return { audioBlob, mimeType: normalizedMime };
    }

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass || !audioBlob || typeof audioBlob.arrayBuffer !== "function") {
      throw new Error(
        "This browser cannot prepare the recording for Google transcription."
      );
    }

    const context = new AudioContextClass();
    try {
      const encodedAudio = await audioBlob.arrayBuffer();
      const decodedAudio = await decodeAudio(context, encodedAudio);
      const samples = downmixAndResample(decodedAudio);
      return {
        audioBlob: new Blob([encodeMonoWav(samples, TARGET_SAMPLE_RATE)], {
          type: "audio/wav",
        }),
        mimeType: "audio/wav",
      };
    } catch (error) {
      throw new Error(
        error && error.message
          ? `The browser could not convert this recording for Google: ${error.message}`
          : "The browser could not convert this recording for Google."
      );
    } finally {
      if (typeof context.close === "function") {
        const closing = context.close();
        if (closing && typeof closing.catch === "function") {
          closing.catch(() => {});
        }
      }
    }
  }

  window.aipkit_prepareGoogleSttAudio = aipkit_prepareGoogleSttAudio;

    function insertTranscription(inputField, transcription) {
        const cleanText = String(transcription || '').trim();
        if (!inputField || !cleanText) {
            return false;
        }

        const currentValue = inputField.value || '';
        const selectionStart = Number.isInteger(inputField.selectionStart)
            ? inputField.selectionStart
            : currentValue.length;
        const selectionEnd = Number.isInteger(inputField.selectionEnd)
            ? inputField.selectionEnd
            : selectionStart;
        const textBefore = currentValue.slice(0, selectionStart);
        const textAfter = currentValue.slice(selectionEnd);
        const leadingSpace = textBefore && !/\s$/.test(textBefore) ? ' ' : '';
        const trailingSpace = textAfter && !/^\s/.test(textAfter) ? ' ' : '';
        const insertedText = `${leadingSpace}${cleanText}${trailingSpace}`;

        if (typeof inputField.setRangeText === 'function') {
            inputField.setRangeText(insertedText, selectionStart, selectionEnd, 'end');
        } else {
            inputField.value = `${textBefore}${insertedText}${textAfter}`;
        }

        return true;
    }

    /**
     * Sends audio blob to backend for transcription and updates input field.
     * @param {Blob} audioBlob The recorded audio data.
     * @param {string} mimeType The MIME type of the audioBlob.
     * @param {object} context Object containing:
     *  - {object} elements: References to UI elements { inputField, voiceInputButton }.
     *  - {object} config: The chatbot configuration.
     *  - {object} state: The chat instance's state.
     */
    function createSpeechWork(elements, state, cleanup) {
        const previous = state.speechWork;
        previous?.cancel();
        const conversation = window.aipkit_current_conversation_uuid;
        const controller = window.AbortController ? new window.AbortController() : null;
        const work = {
            signal: controller?.signal,
            current: () => state.speechWork === work && elements.inputField?.isConnected !== false
                && conversation === window.aipkit_current_conversation_uuid,
            cancel: () => {
                if (state.speechWork !== work) return;
                controller?.abort();
                if (window.aipkitSttState?.activeVoiceInputButton === elements.voiceInputButton) window.aipkit_stopRecording(true);
                cleanup(true);
                state.speechWork = null;
                if (elements.container?._aipkitCancelSpeech === work.cancel) delete elements.container._aipkitCancelSpeech;
            },
        };
        state.speechWork = work;
        if (elements.container) elements.container._aipkitCancelSpeech = work.cancel;
        return work;
    }
    window.aipkit_chatUI_cancelSpeechInput = (container, state) => {
        if (state?.speechWork) state.speechWork.cancel();
        else container?._aipkitCancelSpeech?.();
    };

    function aipkit_chatUI_transcribeAudio(audioBlob, mimeType, context) {
        const { elements, config, state } = context || {};
        const {
            inputField,
            inputWrapper,
            voiceInputButton,
            voiceRecordingPanel,
            voiceRecordingStatus,
            voiceTranscribingIndicator,
            voiceCancelButton,
            voiceConfirmButton,
            inputActionButton
        } = elements || {};
        let transcriptionInserted = false;
        let work;
        const showInlineNotice = (message, type = 'error', autoHide = true, autoHideMs = 7000) => {
            if (typeof window.aipkit_chatUI_showInlineNotice === 'function') {
                window.aipkit_chatUI_showInlineNotice(message, type, { elements, config }, autoHide, autoHideMs);
            }
        };
        const finishTranscription = (cancelled = false) => {
            if (!elements || !config || !state || (work && !cancelled && !work.current())) {
                return;
            }

            state.isStartingRecording = false;
            state.isRecording = false;
            state.isTranscribing = false;
            state.voiceRecordingCancelled = false;
            const sharedSttState = window.aipkitSttState;
            if (
                sharedSttState &&
                sharedSttState.activeVoiceInputButton === voiceInputButton
            ) {
                window.aipkit_stopVoiceRecordingVisualizer?.();
                sharedSttState.activeVoiceInputButton = null;
            }

            if (inputWrapper) {
                inputWrapper.classList.remove(
                    'aipkit_voice_recording_mode',
                    'aipkit_voice_transcribing_mode'
                );
            }
            if (voiceRecordingPanel) voiceRecordingPanel.hidden = true;
            if (voiceTranscribingIndicator) voiceTranscribingIndicator.hidden = true;
            if (voiceRecordingStatus) {
                voiceRecordingStatus.textContent = config.text?.voiceRecording || 'Listening...';
            }
            if (voiceCancelButton) {
                voiceCancelButton.hidden = false;
                voiceCancelButton.disabled = false;
            }
            if (voiceConfirmButton) {
                voiceConfirmButton.hidden = false;
                voiceConfirmButton.disabled = false;
            }

            const disabledForConsent =
                config.requireConsentCompliance && !state.consentGiven;
            if (voiceInputButton) {
                voiceInputButton.disabled = disabledForConsent;
                voiceInputButton.classList.remove('aipkit_starting', 'aipkit_loading');
                voiceInputButton.removeAttribute('aria-busy');
                voiceInputButton.setAttribute('aria-pressed', 'false');
                voiceInputButton.setAttribute('aria-label', config.text?.voiceInput || 'Voice input');
                voiceInputButton.title = config.text?.voiceInput || 'Voice input';
            }
            if (inputField) {
                inputField.disabled = disabledForConsent;
                inputField.dispatchEvent(new Event('input', { bubbles: true }));
                if (transcriptionInserted && !cancelled) {
                    try {
                        inputField.focus({ preventScroll: true });
                    } catch {
                        inputField.focus();
                    }
                }
            }
            if (inputActionButton) {
                inputActionButton.disabled = disabledForConsent;
            }
        };

        if (!audioBlob || !mimeType || !elements || !config || !state || !inputField) {
            console.error("AIPKit STT UI (Transcribe): Missing required parameters for transcribeAudio.", context);
            showInlineNotice(
                config?.text?.voiceConfigError || "Failed to process audio: Configuration error."
            );
            finishTranscription();
            return;
        }

        // Retire only this instance's recording before owning its transcription.
        state.speechWork = null;
        work = createSpeechWork(elements, state, finishTranscription);
        // Direct multipart upload (avoid large Base64 payload blocked by some security plugins like WordFence)
        state.isTranscribing = true;
        if (inputWrapper) {
            inputWrapper.classList.add(
                'aipkit_voice_recording_mode',
                'aipkit_voice_transcribing_mode'
            );
        }
        if (voiceRecordingPanel) voiceRecordingPanel.hidden = false;
        if (voiceTranscribingIndicator) voiceTranscribingIndicator.hidden = false;
        if (voiceRecordingStatus) {
            voiceRecordingStatus.textContent = config.text?.voiceTranscribing || 'Transcribing audio...';
        }
        if (voiceCancelButton) {
            voiceCancelButton.hidden = true;
            voiceCancelButton.disabled = true;
        }
        if (voiceConfirmButton) {
            voiceConfirmButton.hidden = true;
            voiceConfirmButton.disabled = true;
        }
        if (voiceInputButton) {
            voiceInputButton.disabled = true;
            voiceInputButton.classList.add('aipkit_loading');
            voiceInputButton.setAttribute('aria-busy', 'true');
            voiceInputButton.setAttribute('aria-label', config.text?.voiceTranscribing || 'Transcribing audio...');
            voiceInputButton.title = config.text?.voiceTranscribing || 'Transcribing audio...';
        }

        const prepareAudio = config.sttProvider === 'Google'
            ? window.aipkit_prepareGoogleSttAudio
            : null;
        const preparedAudioPromise = typeof prepareAudio === 'function'
            ? prepareAudio(audioBlob, mimeType)
            : Promise.resolve({ audioBlob, mimeType });

        preparedAudioPromise
            .then((preparedAudio) => {
                if (!work.current()) return null;
                const maxBytes = Number(config.sttLimits?.maxInputBytes || 0);
                if (maxBytes > 0 && preparedAudio.audioBlob.size > maxBytes) {
                    throw new Error(config.text?.voiceClipTooLarge || 'This recording is too large. Record a shorter clip.');
                }
                const preparedMimeType = preparedAudio.mimeType || mimeType;
                const mimeSubtype = preparedMimeType.split(';')[0].split('/')[1] || 'webm';
                const formatAliases = {
                    'mpeg': 'mp3',
                    'x-aiff': 'aiff',
                    'x-flac': 'flac',
                    'x-m4a': 'm4a',
                    'x-wav': 'wav'
                };
                const shortMimeType = formatAliases[mimeSubtype] || mimeSubtype;
                return window.aipkit_frontendApiRequest(
                    'aipkit_transcribe_audio',
                    {
                        audio_file: preparedAudio.audioBlob,
                        audio_format: shortMimeType
                    },
                    config,
                    { signal: work.signal }
                );
            })
            .then(response => {
                if (!work.current()) return;
                if (response && response.transcription) {
                    transcriptionInserted = insertTranscription(inputField, response.transcription);
                    if (typeof window.aipkit_autoResizeTextarea === 'function') {
                        window.aipkit_autoResizeTextarea(inputField);
                    }
                } else {
                    throw new Error('Invalid transcription response from server.');
                }
            })
            .catch(error => {
                if (!work.current()) return;
                if (window.aipkit_chatUI_showSpeechError) {
                    window.aipkit_chatUI_showSpeechError(error, { elements, config, isCurrent: work.current });
                    return;
                }
                console.error(`AIPKit STT UI (${config.botId}): Transcription AJAX error:`, error);
                showInlineNotice(
                    `${config.text?.voiceTranscriptionFailedPrefix || 'Transcription failed'}: ${error.message || 'Unknown error'}`
                );
            })
            .finally(finishTranscription);
    }

    window.aipkit_chatUI_transcribeAudio = aipkit_chatUI_transcribeAudio;

  /**
   * Handles a voice input action.
   * @param {object} elements References to UI elements.
   * @param {object} config The chatbot configuration.
   * @param {object} state The chat instance's state.
   * @param {object} consentUI The consent UI module instance.
   * @param {"start"|"cancel"|"confirm"} action Requested recording action.
   * @returns {boolean} Whether the action was handled.
   */
  function aipkit_chatUI_handleVoiceInputAction(
    elements,
    config,
    state,
    consentUI,
    action = "start"
  ) {
    const {
      inputField,
      inputWrapper,
      voiceInputButton,
      voiceRecordingPanel,
      voiceWaveformCanvas,
      voiceRecordingStatus,
      voiceTranscribingIndicator,
      voiceCancelButton,
      voiceConfirmButton,
      actionButton,
      inputActionButton,
    } = elements;
    const botId = config.botId;
    const sharedSttState = window.aipkitSttState || null;
    const voiceInputLabel = config.text?.voiceInput || "Voice input";

    const showInlineNotice = (
      message,
      type = "error",
      autoHide = true,
      autoHideMs = 7000
    ) => {
      if (typeof window.aipkit_chatUI_showInlineNotice === "function") {
        window.aipkit_chatUI_showInlineNotice(
          message,
          type,
          { elements, config },
          autoHide,
          autoHideMs
        );
      }
    };

    const releaseRecordingOwner = () => {
      if (
        sharedSttState &&
        sharedSttState.activeVoiceInputButton === voiceInputButton
      ) {
        sharedSttState.activeVoiceInputButton = null;
      }
    };

    const restoreInputControls = () => {
      const disabledForConsent =
        config.requireConsentCompliance && !state.consentGiven;
      inputField.disabled = disabledForConsent;
      voiceInputButton.disabled = disabledForConsent;
      if (inputActionButton) inputActionButton.disabled = disabledForConsent;
      inputField.dispatchEvent(new Event("input", { bubbles: true }));
    };

    const hideRecordingPanel = () => {
      inputWrapper.classList.remove(
        "aipkit_voice_recording_mode",
        "aipkit_voice_transcribing_mode"
      );
      voiceRecordingPanel.hidden = true;
      voiceTranscribingIndicator.hidden = true;
      voiceCancelButton.hidden = false;
      voiceConfirmButton.hidden = false;
      voiceCancelButton.disabled = false;
      voiceConfirmButton.disabled = false;
      voiceRecordingStatus.textContent =
        config.sttLimits?.maxSeconds
          ? (config.text?.voiceRecordingLimit || 'Listening… Up to %s seconds.').replace('%s', config.sttLimits.maxSeconds)
          : config.text?.voiceRecording || "Listening...";
    };

    const resetVoiceInputState = (releaseOwner = false, focusInput = false) => {
      state.isStartingRecording = false;
      state.isRecording = false;
      state.isTranscribing = false;
      state.voiceRecordingCancelled = false;
      if (sharedSttState?.activeVoiceInputButton === voiceInputButton) window.aipkit_stopVoiceRecordingVisualizer?.();
      hideRecordingPanel();
      voiceInputButton.classList.remove(
        "aipkit_starting",
        "aipkit_loading"
      );
      voiceInputButton.removeAttribute("aria-busy");
      voiceInputButton.setAttribute("aria-pressed", "false");
      voiceInputButton.setAttribute("aria-label", voiceInputLabel);
      voiceInputButton.title = voiceInputLabel;
      if (releaseOwner) releaseRecordingOwner();
      restoreInputControls();
      if (focusInput && !inputField.disabled) {
        try {
          inputField.focus({ preventScroll: true });
        } catch {
          inputField.focus();
        }
      }
    };

    const showRecordingPanel = () => {
      inputWrapper.classList.add("aipkit_voice_recording_mode");
      inputWrapper.classList.remove("aipkit_voice_transcribing_mode");
      voiceRecordingPanel.hidden = false;
      voiceTranscribingIndicator.hidden = true;
      voiceCancelButton.hidden = false;
      voiceConfirmButton.hidden = false;
      voiceRecordingStatus.textContent =
        config.sttLimits?.maxSeconds
          ? (config.text?.voiceRecordingLimit || 'Listening… Up to %s seconds.').replace('%s', config.sttLimits.maxSeconds)
          : config.text?.voiceRecording || "Listening...";
      voiceCancelButton.disabled = false;
      voiceConfirmButton.disabled = false;
      voiceCancelButton.setAttribute(
        "aria-label",
        config.text?.voiceCancelRecording || "Cancel recording"
      );
      voiceCancelButton.title =
        config.text?.voiceCancelRecording || "Cancel recording";
      voiceConfirmButton.setAttribute(
        "aria-label",
        config.text?.voiceFinishRecording || "Finish recording"
      );
      voiceConfirmButton.title =
        config.text?.voiceFinishRecording || "Finish recording";
      inputField.disabled = true;
      voiceInputButton.disabled = true;
      if (actionButton) actionButton.disabled = true;
      if (inputActionButton) inputActionButton.disabled = true;
      try {
        voiceConfirmButton.focus({ preventScroll: true });
      } catch {
        voiceConfirmButton.focus();
      }
    };

    const showTranscribingPanel = () => {
      inputWrapper.classList.add(
        "aipkit_voice_recording_mode",
        "aipkit_voice_transcribing_mode"
      );
      voiceRecordingPanel.hidden = false;
      voiceTranscribingIndicator.hidden = false;
      voiceCancelButton.hidden = true;
      voiceConfirmButton.hidden = true;
      voiceRecordingStatus.textContent =
        config.text?.voiceTranscribing || "Transcribing audio...";
      voiceCancelButton.disabled = true;
      voiceConfirmButton.disabled = true;
      inputField.disabled = true;
      voiceInputButton.disabled = true;
      if (actionButton) actionButton.disabled = true;
      if (inputActionButton) inputActionButton.disabled = true;
    };

    const requiredUiElements = [
      inputField,
      inputWrapper,
      voiceInputButton,
      voiceRecordingPanel,
      voiceWaveformCanvas,
      voiceRecordingStatus,
      voiceTranscribingIndicator,
      voiceCancelButton,
      voiceConfirmButton,
    ];
    if (requiredUiElements.some((element) => !element)) {
      console.error(
        "AIPKit STT (Handle Action): Recording panel elements are unavailable."
      );
      showInlineNotice(
        config.text?.voiceUnavailableScriptError ||
          "Voice input is currently unavailable."
      );
      return false;
    }

    if (action === "cancel") {
      state.voiceRecordingCancelled = true;
      voiceCancelButton.disabled = true;
      voiceConfirmButton.disabled = true;
      const stopScheduled =
        typeof window.aipkit_stopRecording === "function"
          ? window.aipkit_stopRecording(true)
          : false;
      if (!stopScheduled) {
        resetVoiceInputState(true, true);
      }
      return true;
    }

    if (action === "confirm") {
      if (!state.isRecording) return false;
      state.isRecording = false;
      state.isTranscribing = true;
      showTranscribingPanel();
      window.aipkit_stopRecording(false);
      return true;
    }

    if (
      consentUI &&
      typeof consentUI.showConsentBoxIfNeeded === "function" &&
      consentUI.showConsentBoxIfNeeded()
    ) {
      console.warn(
        `AIPKit Chat UI Main (${botId}): Cannot record, consent required and box shown.`
      );
      resetVoiceInputState();
      return false;
    }
    if (config.requireConsentCompliance && !state.consentGiven) {
      console.warn(
        `AIPKit Chat UI Main (${botId}): Consent required but not given (fallback check).`
      );
      resetVoiceInputState();
      return false;
    }

    if (
      typeof window.aipkit_startRecording !== "function" ||
      typeof window.aipkit_stopRecording !== "function" ||
      typeof window.aipkit_setRecordingCallbacks !== "function" ||
      typeof window.aipkit_isMediaRecorderSupported !== "function"
    ) {
      console.error(
        "AIPKit STT (Handle Action): Required recording functions are unavailable."
      );
      showInlineNotice(
        config.text?.voiceUnavailableScriptError ||
          "Voice input is currently unavailable."
      );
      resetVoiceInputState(true);
      return false;
    }

    if (!window.aipkit_isMediaRecorderSupported()) {
      const isHttps = window.location.protocol === "https:";
      const isLocalhost =
        window.location.hostname === "localhost" ||
        window.location.hostname === "127.0.0.1";
      showInlineNotice(
        !isHttps && !isLocalhost
          ? config.text?.voiceRequiresHttps ||
              "Voice recording requires a secure HTTPS connection."
          : config.text?.voiceBrowserUnsupported ||
              "Voice recording is not supported by this browser."
      );
      resetVoiceInputState(true);
      return false;
    }

    if (!sharedSttState) {
      showInlineNotice(
        config.text?.voiceUnavailableScriptError ||
          "Voice input is currently unavailable."
      );
      resetVoiceInputState();
      return false;
    }

    if (
      sharedSttState.activeVoiceInputButton &&
      sharedSttState.activeVoiceInputButton !== voiceInputButton
    ) {
      showInlineNotice(
        config.text?.voiceAlreadyActive ||
          "Voice recording is already active in another chatbot.",
        "info"
      );
      resetVoiceInputState();
      return false;
    }

    const work = createSpeechWork(elements, state, () => resetVoiceInputState(true));
    sharedSttState.activeVoiceInputButton = voiceInputButton;
    window.aipkit_setRecordingCallbacks(
      () => {
        if (!work.current()) return;
        state.isStartingRecording = false;
        state.isRecording = true;
        state.isTranscribing = false;
        voiceInputButton.classList.remove("aipkit_starting", "aipkit_loading");
        voiceInputButton.removeAttribute("aria-busy");
        voiceInputButton.setAttribute("aria-pressed", "true");
        showRecordingPanel();
      },
      (audioBlob, mimeType) => {
        if (!work.current()) return;
        state.isRecording = false;
        state.isTranscribing = true;
        showTranscribingPanel();
        if (typeof window.aipkit_chatUI_transcribeAudio === "function") {
          window.aipkit_chatUI_transcribeAudio(audioBlob, mimeType, {
            elements,
            config,
            state,
          });
        } else {
          console.error(
            "AIPKit STT (Handle Action): transcribeAudio UI function not found."
          );
          showInlineNotice(
            config.text?.voiceProcessingError ||
              "Error processing recorded audio."
          );
          resetVoiceInputState(true);
        }
      },
      (error) => {
        if (!work.current()) return;
        resetVoiceInputState(true);
        console.error(`AIPKit STT (${botId}): Recording error:`, error);
        showInlineNotice(
          `${config.text?.voiceRecordingFailedPrefix || "Recording failed"}: ${
            error.message || "Unknown error"
          }`
        );
      },
      () => { if (work.current()) resetVoiceInputState(true, true); }
    );

    window.aipkit_startRecording({ waveformCanvas: voiceWaveformCanvas,
      maxSeconds: config.sttLimits?.maxSeconds, maxBytes: config.sttLimits?.maxInputBytes, inputFormats: config.sttLimits?.inputFormats });
    return true;
  }

  window.aipkit_chatUI_handleVoiceInputAction =
    aipkit_chatUI_handleVoiceInputAction;
})();
