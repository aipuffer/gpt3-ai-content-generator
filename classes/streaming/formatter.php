<?php

namespace WPAICG\Core\Stream\Formatter;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Detects runtime capabilities that influence SSE buffering and shutdown behavior.
 *
 * @return array<string, bool|int>
 */
function get_sse_runtime_capabilities_logic(): array {
    return [
        'session_active'           => session_status() === PHP_SESSION_ACTIVE,
        'apache_setenv_available'  => function_exists('apache_setenv'),
        'implicit_flush_available' => function_exists('ob_implicit_flush'),
        'fastcgi_finish_available' => function_exists('fastcgi_finish_request'),
        'litespeed_finish_available' => function_exists('litespeed_finish_request'),
        'output_buffer_level'      => ob_get_level(),
    ];
}

/**
 * Applies capability-based mitigations that make SSE flushing more reliable.
 *
 * @return void
 */
function apply_sse_runtime_mitigations_logic(): void {
    $capabilities = get_sse_runtime_capabilities_logic();

    ignore_user_abort(true);

    if ($capabilities['session_active']) {
        session_write_close();
    }

    if ($capabilities['apache_setenv_available']) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }

    if ($capabilities['implicit_flush_available']) {
        @ob_implicit_flush(true);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/**
 * Emits the shared SSE response headers.
 *
 * @return void
 */
function send_sse_response_headers_logic(): void {
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0, no-transform');
    header('Pragma: no-cache');
    header('Content-Type: text/event-stream; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- This is an SSE endpoint which requires a long-running script.
    set_time_limit(0);
}

/**
 * Sends a one-time SSE comment preamble to encourage small-chunk flushing.
 *
 * @param \WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance The instance of the SSEResponseFormatter.
 * @return void
 */
function send_sse_preamble_logic(\WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance): void {
    if ($formatterInstance->get_preamble_sent_status()) {
        return;
    }

    // Prime intermediaries with an SSE comment so subsequent small chunks flush promptly.
    send_raw_logic(':' . str_repeat(' ', 4096) . "\n\n");
    $formatterInstance->set_preamble_sent_status(true);
}

/**
 * Finalizes the current SSE request using the best available runtime capability.
 *
 * @return void
 */
function finish_sse_request_logic(): void {
    $capabilities = get_sse_runtime_capabilities_logic();

    if ($capabilities['fastcgi_finish_available']) {
        fastcgi_finish_request();
        return;
    }

    if ($capabilities['litespeed_finish_available']) {
        litespeed_finish_request();
    }
}

/**
 * Sets the required HTTP headers for an SSE response.
 *
 * @param \WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance The instance of the SSEResponseFormatter.
 * @return void
 */
function set_sse_headers_logic(\WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance): void {
    if ($formatterInstance->get_headers_sent_status() || headers_sent()) {
        return;
    }

    apply_sse_runtime_mitigations_logic();
    send_sse_response_headers_logic();
    $formatterInstance->set_headers_sent_status(true);
    send_sse_preamble_logic($formatterInstance);
}

/**
 * Sends a standard SSE data event.
 *
 * @param \WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance The instance of the SSEResponseFormatter.
 * @param array|string $data Data to send (JSON-encoded if array).
 * @param string|null $id Optional event ID.
 * @return void
 */
function send_sse_data_logic(\WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance, $data, ?string $id = null): void {
    $json_data = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
    $output = '';
    if ($id !== null) {
        $output .= "id: {$id}\n";
    }
    $output .= "data: {$json_data}\n\n";
    $formatterInstance->send_raw_public_wrapper($output);
}

/**
 * Sends a custom SSE event.
 *
 * @param \WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance The instance of the SSEResponseFormatter.
 * @param string $event_type e.g. 'done', 'error', 'warning', 'message_start'
 * @param array|string $data Data for the event.
 * @param string|null $id Optional event ID.
 * @return void
 */
function send_sse_event_logic(\WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance, string $event_type, $data, ?string $id = null): void {
    $json_data = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
    $output = "event: {$event_type}\n";
    if ($id !== null) {
        $output .= "id: {$id}\n";
    }
    $output .= "data: {$json_data}\n\n";
    $formatterInstance->send_raw_public_wrapper($output);
}

/**
 * Sends an SSE 'error' or 'warning' event.
 *
 * @param \WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance The instance of the SSEResponseFormatter.
 * @param string $message The error or warning message.
 * @param bool $non_fatal True if it's a warning, false if it's a fatal error.
 * @param array<string, mixed> $extra_data Additional error payload for the client.
 * @return void
 */
function send_sse_error_logic(
    \WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance,
    string $message,
    bool $non_fatal = false,
    array $extra_data = []
): void {
    $event_type = $non_fatal ? 'warning' : 'error';
    $error_data = array_merge(['error' => $message], $extra_data);
    $error_id   = 'err-' . time();
    $formatterInstance->send_sse_event($event_type, $error_data, $error_id);
}

/**
 * Sends a final SSE 'done' event.
 *
 * @param \WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance The instance of the SSEResponseFormatter.
 * @return void
 */
function send_sse_done_logic(\WPAICG\Core\Stream\Formatter\SSEResponseFormatter $formatterInstance): void {
    $formatterInstance->send_sse_event('done', ['finished' => true]);
}

/**
 * Sends raw SSE output and flushes.
 *
 * @param string $output The raw output to send.
 * @return void
 */
function send_raw_logic(string $output): void {
    if (connection_status() === CONNECTION_NORMAL) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE output, not HTML
        echo $output;
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}

/**
 * Formats and sends Server-Sent Events (SSE) to the client.
 */
class SSEResponseFormatter {

    private $headers_sent = false;
    private $preamble_sent = false;

    public function set_sse_headers() {
        set_sse_headers_logic($this);
    }

    public function send_sse_data($data, ?string $id = null) {
        send_sse_data_logic($this, $data, $id);
    }

    public function send_sse_event(string $event_type, $data, ?string $id = null) {
        send_sse_event_logic($this, $event_type, $data, $id);
    }

    public function send_sse_error(string $message, bool $non_fatal = false, array $extra_data = []) {
        send_sse_error_logic($this, $message, $non_fatal, $extra_data);
    }

    public function send_sse_done() {
        send_sse_done_logic($this);
    }

    public function finish_request(): void {
        finish_sse_request_logic();
    }

    // Public wrapper for the private send_raw logic
    public function send_raw_public_wrapper(string $output): void {
        send_raw_logic($output);
    }

    // Getter and Setter for private property (needed by externalized logic)
    public function get_headers_sent_status(): bool {
        return $this->headers_sent;
    }

    public function set_headers_sent_status(bool $status): void {
        $this->headers_sent = $status;
    }

    public function get_preamble_sent_status(): bool {
        return $this->preamble_sent;
    }

    public function set_preamble_sent_status(bool $status): void {
        $this->preamble_sent = $status;
    }
}
