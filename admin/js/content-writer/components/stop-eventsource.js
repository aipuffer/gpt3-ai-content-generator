/**
 * AIPKit Content Writer - Stop EventSource
 * Publicly exposed function to manually stop an active EventSource stream.
 */
(function() {
    'use strict';

    /**
     * Manually stops the current EventSource stream.
     * `eventSourceInstanceRef` is an object { current: EventSource | null }
     * expected to be managed by the main EventSource handler.
     */
    function aipkit_stopContentWriterEventSource(eventSourceInstanceRef) {
        if (eventSourceInstanceRef && eventSourceInstanceRef.current) {
            eventSourceInstanceRef.current.close(); // This will trigger its 'error' event. Let the error handler finalize the stream.
        }
    }

    window.aipkit_stopContentWriterEventSource = aipkit_stopContentWriterEventSource;
})();