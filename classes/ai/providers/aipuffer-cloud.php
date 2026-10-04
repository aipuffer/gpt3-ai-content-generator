<?php
namespace WPAICG\Core\Providers;
use WPAICG\Cloud\Connection;
use WP_Error;
if (!defined('ABSPATH')) { exit; }

final class AIPufferCloudProviderStrategy extends BaseProviderStrategy
{
    private $completed = false;

    public function validate_stream_completion(): ?WP_Error
    {
        return $this->completed ? null : new WP_Error('cloud_outcome_unknown', $this->parse_error_response('{"code":"cloud_outcome_unknown"}', 504), Connection::request_error_data('cloud_outcome_unknown'));
    }
    /** Keep saved bot features intact and reject operations the Cloud adapter cannot serve. */
    public function validate_chatbot_features(array $settings, bool $has_image = false, bool $has_web_search = false): ?WP_Error
    {
        if ($has_web_search || ($has_image && !\WPAICG\AIPKit_Providers::model_supports_image_input('AIPufferCloud', (string) ($settings['model'] ?? '')))) {
            return new WP_Error('cloud_chatbot_feature_unavailable', __('The selected Cloud model cannot process images or web search. Turn off those features or choose another provider.', 'gpt3-ai-content-generator'));
        }
        return null;
    }


    public function build_api_url(string $operation, array $params) { return Connection::generation_endpoint(); }
    public function get_api_headers(string $api_key, string $operation): array { return Connection::generation_headers(); }
    public function get_request_options(string $operation): array { return array_merge(parent::get_request_options($operation), ['timeout' => 290, 'sslverify' => true]); }
    public function get_models(array $api_params) { return Connection::models(); }
    /**
     * Cloud embeddings: batches of up to 96 texts (8,000 bytes each) to the site's Cloud connection, billed
     * in credits. Vectors arrive as base64 little-endian float32 and are returned as float arrays, the
     * shape every other provider returns.
     */
    public function generate_embeddings($input, array $api_params, array $options = [])
    {
        $model = is_string($options['model'] ?? null) ? $options['model'] : '';
        $spec = null;
        foreach (Connection::embedding_models() as $candidate) { if ($candidate['id'] === $model) { $spec = $candidate; break; } }
        if (!$spec) { return new WP_Error('cloud_embedding_model_unavailable', __('Choose an AI Puffer Cloud embedding model, or sync Cloud models in Settings.', 'gpt3-ai-content-generator'), ['status' => 400]); }
        $dimensions = $options['dimensions'] ?? $spec['dimensions'];
        if (!is_int($dimensions) || !in_array($dimensions, $spec['supportedDimensions'] ?? [$spec['dimensions']], true)) {
            return new WP_Error('cloud_embedding_dimensions_unsupported', __('This Cloud embedding model does not support the store dimensions. Refresh Cloud models or choose a compatible store.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        $texts = is_array($input) ? array_values($input) : [$input];
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') { return new WP_Error('cloud_embedding_invalid_input', __('Embeddings need non-empty text.', 'gpt3-ai-content-generator'), ['status' => 400]); }
            if (strlen($text) > 8000) { return new WP_Error('cloud_embedding_input_too_large', __('A text chunk is too long for AI Puffer Cloud embeddings (8,000 bytes). Re-index to use smaller chunks.', 'gpt3-ai-content-generator'), ['status' => 400]); }
        }
        if (!$texts) { return new WP_Error('cloud_embedding_invalid_input', __('Embeddings need non-empty text.', 'gpt3-ai-content-generator'), ['status' => 400]); }
        $vectors = []; $tokens = 0; $bytes = $dimensions * 4;
        foreach (array_chunk($texts, 96) as $batch) {
            $operation_id = wp_generate_uuid4();
            $response = wp_remote_post(Connection::generation_endpoint(), array_merge(parent::get_request_options('embeddings'), [
                'method' => 'POST', 'timeout' => 90, 'sslverify' => true, 'headers' => Connection::generation_headers(),
                'body' => wp_json_encode(['operation' => 'embed', 'site' => Connection::site(), 'operationId' => $operation_id, 'model' => $model, 'dimensions' => $dimensions, 'input' => $batch]),
            ]));
            $uncertain = ['status' => 502, 'stop_batch' => true, 'outcome_unknown' => true, 'cloud_operation_id' => $operation_id];
            if (is_wp_error($response)) { return new WP_Error('cloud_outcome_unknown', $this->parse_error_response('{"code":"cloud_outcome_unknown"}', 504), array_merge(Connection::request_error_data('cloud_outcome_unknown'), $uncertain)); }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if ($code !== 200) {
                $error_data = array_merge(['status' => $code, 'status_code' => $code, 'cloud_operation_id' => $operation_id], $this->build_http_error_data_with_retry_after($response, $code));
                if ($code >= 500 || in_array($error_data['cloud_request_state'] ?? '', ['dispatched', 'reconciliation_pending', 'settled'], true) || in_array($error_data['provider_error_code'] ?? '', ['already_dispatched_or_final', 'idempotency_conflict', 'gateway_aborted', 'gateway_response_unverified'], true)) {
                    $error_data = array_merge($error_data, $uncertain);
                }
                return new WP_Error('cloud_embedding_failed', $this->parse_error_response($body, $code), $error_data);
            }
            $data = json_decode($body, true);
            // A valid receipt remains useful even when the vector payload cannot be used.
            if (is_array($data)) { Connection::record_receipt($data); }
            $invalid_response = static function () use ($uncertain): WP_Error {
                return new WP_Error('cloud_embedding_invalid_response', __('The Cloud embedding response could not be used. Credit usage may already be recorded. Check your credits before starting a new request.', 'gpt3-ai-content-generator'), $uncertain);
            };
            if (!is_array($data) || ($data['dimensions'] ?? null) !== $dimensions || !is_array($data['vectors'] ?? null) || count($data['vectors']) !== count($batch)) {
                return $invalid_response();
            }
            foreach ($data['vectors'] as $encoded) {
                $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($raw === false || strlen($raw) !== $bytes) { return $invalid_response(); }
                $vector = array_values(unpack('g*', $raw));
                if (count(array_filter($vector, 'is_finite')) !== $dimensions) { return $invalid_response(); }
                $vectors[] = $vector;
            }
            $tokens += max(0, (int) ($data['usage']['inputTokens'] ?? 0));
        }
        return ['embeddings' => $vectors, 'usage' => ['input_tokens' => $tokens, 'total_tokens' => $tokens, 'provider_raw' => ['model' => $model]]];
    }
    public function build_http_error_data_with_retry_after($response, int $status_code): array
    {
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : '';
        $data = array_merge(parent::build_http_error_data_with_retry_after($response, $status_code), Connection::request_error_data($code));
        if (is_string($body['requestId'] ?? null) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $body['requestId'])) { $data['cloud_request_id'] = $body['requestId']; }
        if (in_array($body['state'] ?? '', ['dispatched', 'reconciliation_pending', 'settled', 'released'], true)) { $data['cloud_request_state'] = $body['state']; }
        if (is_string($body['operationId'] ?? null) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $body['operationId'])) { $data['cloud_operation_id'] = $body['operationId']; }
        return $data;
    }

    public function parse_error_response($response_body, int $status_code): string {
        $error = is_string($response_body) ? json_decode($response_body, true) : null;
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : '';
        if (in_array($code, Connection::BILLING_CODES, true)) {
            Connection::note_billing_refusal($code);
            return Connection::billing_message($code);
        }
        $messages = [
            'image_input_unsupported' => __('The selected Cloud model does not support image analysis. Choose a compatible model.', 'gpt3-ai-content-generator'),
            'invalid_image_input' => __('The image could not be read. Upload a valid JPG, PNG, or WebP image.', 'gpt3-ai-content-generator'),
            'too_many_images' => __('Upload no more than four images per request.', 'gpt3-ai-content-generator'),
            'cloud_outcome_unknown' => __('The Cloud response was interrupted. Credit usage may still be processing. Refresh your credits before starting another request.', 'gpt3-ai-content-generator'),
            'invalid_json' => __('The Cloud request contains invalid JSON.', 'gpt3-ai-content-generator'),
            'invalid_request' => __('The Cloud request parameters are invalid.', 'gpt3-ai-content-generator'),
            'unsupported_generation_field' => __('The Cloud request contains unsupported parameters.', 'gpt3-ai-content-generator'),
            'already_dispatched_or_final' => __('This Cloud request has already been submitted. Do not submit it again as a new request.', 'gpt3-ai-content-generator'),
            'idempotency_conflict' => __('This operation ID was already used for a different Cloud request.', 'gpt3-ai-content-generator'),
            'invalid_reasoning' => __('This reasoning level is not supported by the selected Cloud model.', 'gpt3-ai-content-generator'),
            'invalid_operation_id' => __('The Cloud operation ID is invalid.', 'gpt3-ai-content-generator'),
            'invalid_gateway_request' => __('The Cloud generation parameters are invalid.', 'gpt3-ai-content-generator'),

            'gateway_refused' => __('The AI model is busy or unavailable right now. Please try again in a moment.', 'gpt3-ai-content-generator'),
            'site_limit' => __('This site has reached its Cloud spending limit. Review the limit in your Cloud account.', 'gpt3-ai-content-generator'),
            'catalog_price_changed' => __('Cloud model pricing or limits have changed. Refresh credits and models in Settings, then try again.', 'gpt3-ai-content-generator'),
            'catalog_model_unavailable' => __('This Cloud model is no longer available. Refresh credits and models in Settings, then choose another model.', 'gpt3-ai-content-generator'),
            'input_too_large' => __('This message or conversation is too long for the selected Cloud model. Start a new conversation or shorten the message.', 'gpt3-ai-content-generator'),
            'unauthorized' => __('Your Cloud connection is unavailable. Check your account connection in Settings.', 'gpt3-ai-content-generator'),
            'credential_unavailable' => __('Your Cloud connection is unavailable. Reconnect this website in Settings.', 'gpt3-ai-content-generator'),
            'account_paused' => __('Cloud spending is paused for this account. Contact AI Puffer support to review the account.', 'gpt3-ai-content-generator'),
            'provider_daily_limit' => __('Cloud has reached its daily service limit. Please try again later.', 'gpt3-ai-content-generator'),
            'provider_spending_paused' => __('Cloud generation is temporarily paused. Please try again later.', 'gpt3-ai-content-generator'),
            'gateway_aborted' => __('The Cloud request timed out. Its credit usage may still be processing; refresh your credits before starting another request.', 'gpt3-ai-content-generator'),
            'gateway_response_unverified' => __('Cloud could not verify the complete response. Its credit usage may still be processing; refresh your credits before starting another request.', 'gpt3-ai-content-generator'),
        ];
        return $messages[$code] ?? __('Cloud could not complete this request. Check your connection and credits in Settings.', 'gpt3-ai-content-generator');
    }
    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model) {
        $history[] = ['role' => 'user', 'content' => $user_message];
        $payload = $this->build_sse_payload($history, $instructions, $ai_params, $model);
        if (!is_wp_error($payload)) { $payload['stream'] = false; }
        return $payload;
    }
    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model) {
        $this->completed = false; $selected = null;
        $models = Connection::models();
        foreach ($models as $candidate) { if ($candidate['id'] === $model) { $selected = $candidate; break; } }
        // A saved model is a billable choice. Never substitute a different model or price.
        if (!$selected) { return new WP_Error('cloud_model_unavailable', __('This Cloud model is unavailable. Sync Cloud models in Settings and choose a model again.', 'gpt3-ai-content-generator'), ['status' => 400]); }
        foreach (['vector_store_tool_config', 'web_search_tool_config', 'google_file_search_tool_config', 'xai_web_search_tool_config'] as $feature) {
            if (!empty($ai_params[$feature])) {
                return new WP_Error('cloud_feature_unavailable', __('This Cloud model cannot use provider file search or web tools. Turn off those features or choose another provider.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
        }
        $plain = [];
        if (is_string($system_instruction) && $system_instruction !== '') { $plain[] = ['role' => 'system', 'content' => $system_instruction]; }
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'bot') { $message['role'] = 'assistant'; }
            if (!in_array($message['role'] ?? '', ['system', 'user', 'assistant'], true) || !is_string($message['content'] ?? null)) {
                return new WP_Error('cloud_text_only', __('The conversation contains unsupported content. Use plain text messages and the image upload field.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            if ($message['content'] === '' && $message['role'] === 'user' && !empty($ai_params['image_inputs'])) { $message['content'] = __('Describe this image.', 'gpt3-ai-content-generator'); }
            if ($message['content'] !== '') { $plain[] = ['role' => $message['role'], 'content' => $message['content']]; }
        }
        if (!empty($ai_params['image_inputs'])) {
            if (empty($selected['capabilities']['image_input'])) {
                return new WP_Error('image_input_unsupported', __('The selected Cloud model does not support image analysis.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            require_once dirname(__DIR__, 2) . '/chatbot/image-input.php';
            if (!is_array($ai_params['image_inputs'])) { return new WP_Error('invalid_image_input', __('Invalid image input.', 'gpt3-ai-content-generator')); }
            $images = \WPAICG\Chat\Core\Validation\ChatImageInputValidator::prepare_inline_images($ai_params['image_inputs']);
            if (is_wp_error($images)) { return $images; }
            $last = count($plain) - 1;
            if ($last < 0 || $plain[$last]['role'] !== 'user') {
                return new WP_Error('invalid_image_input', __('Add a message for the uploaded image.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            $parts = [['type' => 'text', 'text' => $plain[$last]['content']]];
            foreach ($images as $image) { $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['type'] . ';base64,' . $image['base64'], 'detail' => 'high']]; }
            $plain[$last]['content'] = $parts;
        }
        $effort = $ai_params['reasoning']['effort'] ?? '';
        if (!is_string($effort)) { return new WP_Error('invalid_reasoning', __('This reasoning level is not supported by the selected Cloud model.', 'gpt3-ai-content-generator')); }
        $levels = $selected['reasoning']['levels'] ?? [];
        $payload = ['operation' => 'generate', 'site' => Connection::site(), 'operationId' => $ai_params['cloud_operation_id'] ?? wp_generate_uuid4(),
            'model' => $model, 'price' => $selected['price']['id'], 'messages' => $plain, 'stream' => true,
            'maxOutput' => $this->max_output($ai_params, (int) $selected['price']['maxOutput'])];
        if (!is_string($payload['operationId']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $payload['operationId'])) {
            return new WP_Error('invalid_operation_id', __('The Cloud operation ID is invalid.', 'gpt3-ai-content-generator'));
        }
        if ($effort !== '' && !in_array($effort, $levels, true)) {
            return new WP_Error('invalid_reasoning', __('This reasoning level is not supported by the selected Cloud model.', 'gpt3-ai-content-generator'));
        }
        if ($levels && $effort !== '') { $payload['reasoning'] = $effort; }
        return $payload;
    }
    /**
     * Modules send their limit as max_completion_tokens (legacy callers: max_tokens). The model's own
     * output limit caps it. Without a setting, 4096 keeps the credit hold reasonable.
     */
    private function max_output(array $ai_params, int $model_max): int {
        $requested = (int) ($ai_params['max_completion_tokens'] ?? $ai_params['max_tokens'] ?? 4096);
        $requested = $requested > 0 ? $requested : 4096;
        return max(1, min(max(1, $model_max), $requested));
    }
    private function usage(array $usage): array { return ['prompt_tokens' => (int) ($usage['input'] ?? 0), 'completion_tokens' => (int) ($usage['output'] ?? 0), 'total_tokens' => (int) ($usage['input'] ?? 0) + (int) ($usage['output'] ?? 0)]; }
    public function parse_chat_response(array $decoded_response, array $request_data) {
        if (!is_string($decoded_response['content'] ?? null) || !is_array($decoded_response['usage'] ?? null)) { return new WP_Error('cloud_response_incomplete', $this->parse_error_response('', 503)); }
        Connection::record_receipt($decoded_response);
        return ['content' => $decoded_response['content'], 'usage' => $this->usage($decoded_response['usage'])];
    }
    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        $current_buffer .= $sse_chunk;
        $result = ['delta' => '', 'usage' => null, 'is_error' => false, 'is_warning' => false, 'is_done' => false];
        while (($end = strpos($current_buffer, "\n\n")) !== false) {
            $frame = substr($current_buffer, 0, $end); $current_buffer = substr($current_buffer, $end + 2);
            if (strpos($frame, 'data: ') !== 0) { continue; }
            $event = json_decode(substr($frame, 6), true);
            if (($event['type'] ?? '') === 'text' && is_string($event['text'] ?? null) && !$this->completed) { $result['delta'] .= $event['text']; }
            elseif (($event['type'] ?? '') === 'done' && is_array($event['usage'] ?? null) && !$this->completed) { Connection::record_receipt($event); $this->completed = true; $result['is_done'] = true; $result['usage'] = $this->usage($event['usage']); }
            else {
                $result['is_error'] = true;
                $result['delta'] = $this->parse_error_response(substr($frame, 6), 503);
                $status = is_int($event['status'] ?? null) && $event['status'] >= 400 && $event['status'] <= 599 ? $event['status'] : 503;
                $result['error_data'] = $this->build_http_error_data_with_retry_after(['body' => substr($frame, 6), 'headers' => []], $status);
                break;
            }
        }
        if (strlen($current_buffer) > 262144) { $result['is_error'] = true; $result['delta'] = $this->parse_error_response('', 503); }
        return $result;
    }
}
