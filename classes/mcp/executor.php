<?php
/** One signed executor shared by REST and the WordPress AJAX transport. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Executor
{
    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route('aipkit/v1', '/mcp/execute', ['methods' => 'POST',
                'permission_callback' => '__return_true', 'callback' => [self::class, 'rest']]);
        });
        add_action('wp_ajax_aipkit_mcp_execute', [self::class, 'ajax']);
        add_action('wp_ajax_nopriv_aipkit_mcp_execute', [self::class, 'ajax']);
        add_action('admin_post_aipkit_mcp_confirm', [self::class, 'confirm']);
        add_action('admin_post_nopriv_aipkit_mcp_confirm', static function (): void { auth_redirect(); });
        add_action('admin_post_aipkit_mcp_resolve', [self::class, 'resolve']);
    }

    public static function rest(\WP_REST_Request $request): \WP_REST_Response
    {
        $out = self::dispatch($request->get_body(), static function ($name) use ($request) { return $request->get_header($name); });
        $response = new \WP_REST_Response($out['body'], $out['status']);
        $response->header('Cache-Control', 'no-store');
        if ($out['signature'] !== '') { $response->header('X-AIPKit-Outcome-Signature', $out['signature']); }
        return $response;
    }

    public static function ajax(): void
    {
        $raw = file_get_contents('php://input');
        $out = self::dispatch(is_string($raw) ? $raw : '', static function ($name) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve signed header bytes; Verifier validates shape, signature, MAC and freshness before execution.
            return isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? wp_unslash($_SERVER[$key]) : '';
        });
        nocache_headers();
        if ($out['signature'] !== '') { header('X-AIPKit-Outcome-Signature: ' . $out['signature']); }
        wp_send_json($out['body'], $out['status']);
    }

    public static function dispatch(string $raw, callable $header): array
    {
        // A diagnostic can only return a one-time challenge proof, never execute an operation.
        if (strlen($raw) <= 128) {
            $probe = json_decode($raw, true);
            if (is_array($probe) && array_keys($probe) === ['readiness']) { return Settings::readiness_response($probe['readiness'], $header); }
        }
        $out = ['state' => 'unknown', 'actions_used' => 0];
        $status = 503;
        $signature = '';
        $config = [];
        $authenticated = false;
        $operation_id = null;
        $before = get_current_user_id();
        try {
            if (strlen($raw) > 262144) { throw new \RuntimeException('request_too_large'); }
            $config = Store::config();
            // Preload a registered callback key before any write; never generate one here.
            BridgeClient::preload();
            $verified = Verifier::verify($raw, $header);
            if (empty($verified['ok'])) {
                $status = $verified['status'];
                $authenticated = !empty($verified['authenticated']);
                if ($authenticated) { $out = ['operation_id' => null, 'state' => 'failed', 'actions_used' => 0]; }
                // A delivery refusal cannot establish that an earlier delivery never ran.
                $out['error'] = ['code' => $verified['code'], 'message' => $verified['message']];
            } else {
                $authenticated = true;
                $body = $verified['body'];
                $operation_id = $body->operation_id;
                $grant = $verified['grant'];
                wp_set_current_user($body->acting_user);
                $out = self::execute($body, $grant);
                $status = 200;
            }
        } catch (\Throwable $error) {
            // A storage/transport failure never claims a website mutation failed to happen.
            $out = $operation_id !== null
                ? ['operation_id' => $operation_id] + Journal::uncertain('operation_interrupted')
                : ['state' => 'unknown', 'actions_used' => 0, 'error' => ['code' => 'site_unavailable', 'message' => 'Check this operation before trying a new request.']];
        } finally {
            wp_set_current_user($before);
        }
        $out = ['aipkit_agent' => 1] + $out;
        if ($authenticated) {
            try {
                $envelope = [];
                foreach (['Key', 'Grant', 'Time', 'Nonce', 'Signature', 'MAC'] as $name) {
                    $envelope[] = substr((string) $header('X-AIPKit-Agent-' . $name), 0, 200);
                }
                $canonical = implode("\n", ['aipkit-outcome-v2', $config['account_id'], $config['site_id'],
                    hash('sha256', implode("\n", $envelope)), hash('sha256', $raw),
                    hash('sha256', Canonical::encode(json_decode(wp_json_encode($out))))]);
                $signature = BridgeClient::sign_outcome($canonical);
            } catch (\Throwable $error) { $signature = ''; }
        }
        return ['body' => $out, 'status' => $status, 'signature' => $signature];
    }

    private static function execute(object $body, array $grant): array
    {
        // Internal renewal is not an assistant tool and spends no website action.
        // It may refresh only the already-authorized account/site binding.
        if ($body->tool === 'register_again') {
            if (!current_user_can('read') || !Identity::active() || $grant['state'] !== 'active'
                || get_object_vars($body->args) !== []) { return Operations::error('permission_denied'); }
            $config = Store::config();
            $result = BridgeClient::register(['account_id' => $config['account_id'], 'site_id' => $config['site_id']]);
            return ['operation_id' => $body->operation_id, 'state' => is_wp_error($result) ? 'failed' : 'succeeded', 'actions_used' => 0];
        }
        if ($body->tool === 'get_operation') {
            $id = $body->args->operation_id ?? '';
            if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)
                || (isset($body->args->fence) && !is_bool($body->args->fence))) { return Operations::error('invalid_input'); }
            $row = Journal::get($id);
            if (!$row && ($body->args->fence ?? false)) {
                Journal::fence($id, $body, $grant);
                $row = Journal::get($id);
            }
            if (!$row || $row['account_id'] !== $body->account_id || $row['site_id'] !== $body->site_id
                || $row['grant_id'] !== $grant['grant_id'] || $row['connection_id'] !== $body->connection_id
                || (int) $row['acting_user'] !== $body->acting_user) {
                return ['state' => 'succeeded', 'operation_id' => $body->operation_id, 'actions_used' => 0,
                    'result' => ['state' => 'unknown_here', 'operation_id' => $id, 'actions_used' => 0]];
            }
            $row = Journal::expire($row);
            if (isset($body->args->audit_completion)) {
                if (!is_object($body->args->audit_completion)) { return Operations::error('invalid_input'); }
                // Terminal rows are immutable. A duplicated completion reads the original outcome.
                if ($row['state'] === 'unknown') {
                    if (!Content::complete_audit($row, $body->args->audit_completion)) { return Operations::error('invalid_input'); }
                    $row = Journal::get($id) ?? $row;
                } elseif ($row['tool'] !== 'read_ability' || (json_decode($row['args'], true)['name'] ?? '') !== 'ai-puffer/audit-page') { return Operations::error('invalid_input'); }
            }
            if (isset($body->args->settled)) {
                if (!is_object($body->args->settled) || !Journal::acknowledge($row, $body->args->settled)) { return Operations::error('invalid_settlement_receipt'); }
            }
            $found = Journal::response($row);
            Journal::reported($id);
            if (isset($found['result'])) { $found['outcome'] = $found['result']; }
            return ['state' => 'succeeded', 'operation_id' => $body->operation_id, 'actions_used' => 0, 'result' => $found];
        }
        if (Registry::get($body->tool) === null) { return Operations::error('unsupported_tool'); }
        $row = Journal::get($body->operation_id);
        if ($row) {
            if (!Journal::same($row, $body, $grant)) { return ['operation_id' => $body->operation_id] + Journal::uncertain('idempotency_conflict'); }
            Journal::reported($body->operation_id);
            return Journal::response($row);
        }
        if (!Journal::receive($body, $grant)) {
            $row = Journal::get($body->operation_id);
            if (!$row || !Journal::same($row, $body, $grant)) { return ['operation_id' => $body->operation_id] + Journal::uncertain('operation_storage_unavailable'); }
            Journal::reported($body->operation_id);
            return Journal::response($row);
        }
        if (!Journal::lock(5)) {
            // This delivery inserted the row but has not called a handler. Close it atomically so a busy site
            // does not leave a never-started request looking like an uncertain website change.
            $response = ['state' => 'failed', 'actions_used' => 0,
                'error' => ['code' => 'site_busy', 'message' => 'The website is busy. Nothing ran and no action was charged. Try a new request shortly.']];
            if (!Journal::claim($body->operation_id, 'received') || !Journal::finish($body->operation_id, $response)) {
                return ['operation_id' => $body->operation_id] + Journal::uncertain('operation_storage_unavailable');
            }
            Journal::reported($body->operation_id);
            return ['operation_id' => $body->operation_id] + $response;
        }
        try {
            if (!Journal::claim($body->operation_id, 'received')) { return ['operation_id' => $body->operation_id] + Journal::uncertain('operation_storage_unavailable'); }
            $current = Store::grant($grant['grant_id']);
            if (!Identity::active() || !$current || $current['state'] !== 'active'
                || $current['instance_id'] !== Identity::registration()['instance_id']) {
                $response = Operations::error('site_not_authorized');
            } elseif (Registry::is_write($body->tool) && !empty(get_option('aipkit_mcp_settings', [])['paused'])) {
                $response = Operations::error('site_paused');
            } elseif (Registry::is_write($body->tool) && ($blocked = Journal::write_gate($body->operation_id)) !== null) {
                $response = $blocked;
            } else {
                Operations::$mutation_started = false;
                Operations::$operation_id = $body->operation_id;
                try { $response = Operations::run($body->tool, $body->args, $current); }
                catch (\Throwable $error) { $response = Operations::$mutation_started ? Journal::uncertain('operation_interrupted') : Operations::error('operation_interrupted'); }
                finally { Operations::$operation_id = ''; }
            }
            if ($response['state'] === 'pending_approval') {
                $response['approval_url'] = admin_url('admin-post.php?action=aipkit_mcp_confirm&operation_id=' . rawurlencode($body->operation_id));
            }
            // Keep preview bytes transient; recovery/history retains image identity and dimensions only.
            $recorded = $response;
            if ($body->tool === 'inspect_media') { unset($recorded['result']['image_preview']['data']); }
            if (!Journal::finish($body->operation_id, $recorded)) {
                $response = Journal::uncertain('operation_result_not_saved') + array_intersect_key($response, ['operation_diagnostics' => true]);
            }
            Journal::reported($body->operation_id);
            unset($response['local_error']);
            return ['operation_id' => $body->operation_id] + $response;
        } finally { Journal::unlock(); }
    }

    public static function confirm(): void
    {
        $id = isset($_REQUEST['operation_id']) && is_string($_REQUEST['operation_id']) ? sanitize_text_field(wp_unslash($_REQUEST['operation_id'])) : '';
        // Links handed to assistants keep this URL; the page itself lives in wp-admin.
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST') { wp_safe_redirect(Screens::request_url($id)); exit; }
        $row = Journal::get($id);
        if (!$row || !Registry::can_approve($row)) { wp_die(esc_html__('You cannot approve this request.', 'gpt3-ai-content-generator'), '', ['response' => 403]); }
        nocache_headers();
        check_admin_referer('aipkit_mcp_confirm_' . $id);
        $back = static function (string $notice = '') use ($id): void { wp_safe_redirect(Screens::request_url($id, $notice)); exit; };
        $decision = isset($_POST['decision']) && is_string($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
        if (!in_array($decision, ['approve', 'publish', 'decline'], true)) { $back('invalid'); }
        if (!Journal::lock()) { $back('busy'); }
        $notice = '';
        try {
            $grant = Store::grant($row['grant_id']);
            if (($blocked = Journal::write_gate($id)) !== null) {
                $notice = $blocked['error']['code'] === 'write_check_unavailable' ? 'write_check_unavailable' : 'review_first';
            } elseif (!Identity::active() || !$grant || $grant['state'] !== 'active'
                || $row['instance_id'] !== Identity::registration()['instance_id']
                || !Registry::allowed($row['tool'], $grant, json_decode((string) $row['args']))
                || !empty(get_option('aipkit_mcp_settings', [])['paused'])) {
                $notice = 'paused';
            } elseif (!Journal::claim($id, 'pending_approval')) {
                $notice = 'handled';
            } else {
                if ((int) $row['expires_at'] <= time()) { $response = ['state' => 'expired', 'actions_used' => 0]; }
                elseif ($decision === 'decline') { $response = ['state' => 'declined', 'actions_used' => 0]; }
                else {
                    Operations::$mutation_started = false;
                    // Runs as the acting user: their current role is the ceiling, not the approver's.
                    try { $response = Registry::as_actor($row, static function () use ($row) {
                        Operations::$operation_id = $row['operation_id'];
                        try { return call_user_func(Registry::get($row['tool'])['run'], json_decode($row['args'])); }
                        finally { Operations::$operation_id = ''; }
                    }); }
                    catch (\Throwable $error) { $response = Operations::$mutation_started ? ['state' => 'unknown', 'actions_used' => 0] : Operations::error('site_unavailable'); }
                }
                Journal::finish($id, $response);
            }
        } finally { Journal::unlock(); }
        // The page shows the recorded state: published, declined, expired, failed, or "couldn't confirm".
        $back($notice);
    }

    /** Recovery makes no website change and cannot be approved by an assistant. */
    public static function resolve(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST' || !\WPAICG\Cloud\Connection::allowed()) {
            wp_die('You cannot review this operation.', '', ['response' => 403]);
        }
        $id = isset($_POST['operation_id']) && is_string($_POST['operation_id']) ? sanitize_text_field(wp_unslash($_POST['operation_id'])) : '';
        check_admin_referer('aipkit_mcp_resolve_' . $id);
        $back = static function (string $notice): void { wp_safe_redirect(Screens::review_url($notice)); exit; };
        $config = Store::config();
        if (!Identity::active()) { $back('connect'); }
        if (!Journal::lock()) { $back('busy'); }
        $notice = 'recorded';
        try {
            $row = Journal::get($id);
            $decision = isset($_POST['decision']) && is_string($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
            $response = null;
            if (!$row || $row['account_id'] !== $config['account_id'] || $row['site_id'] !== $config['site_id']
                || !in_array($row['state'], ['received', 'executing', 'unknown'], true)) {
                $notice = 'changed';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Explicit attestation accepts only the exact unslashed literal yes; markup or other values must not become confirmation.
            } elseif (!isset($_POST['confirm_reviewed']) || !is_string($_POST['confirm_reviewed']) || wp_unslash($_POST['confirm_reviewed']) !== 'yes') {
                // Explicit owner attestation after reviewing the website, never inferred
                // from a missing row, timeout, or failed delivery.
                $notice = 'confirm_reviewed';
            } elseif ($decision === 'not_run') {
                // The lock rules out an executor still writing; the terminal row blocks every later delivery.
                $response = ['state' => 'failed', 'actions_used' => 0, 'error' => ['code' => 'owner_confirmed_not_run', 'message' => 'The website owner confirmed that this operation did not run.']];
            } elseif ($decision === 'succeeded') {
                $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
                $post = get_post($post_id);
                $args = json_decode($row['args']);
                if (in_array($row['tool'], ['upload_media', 'upload_image_file', 'update_media', 'fill_media_text'], true) || !empty(Registry::get((string) $row['tool'])['generic'])) {
                    // A generic change has no single post to point to; the owner's attestation is the record.
                    $response = ['state' => 'succeeded', 'actions_used' => 1, 'result' => ['reviewed' => true]];
                } elseif (!$post || !in_array($post->post_type, ['post', 'page'], true) || !current_user_can('edit_post', $post_id)
                    || !in_array($row['tool'], ['create_draft', 'publish_content', 'update_content'], true)
                    || ($row['tool'] === 'create_draft' && (int) $post->post_author !== (int) $row['acting_user'])
                    || ($row['tool'] === 'create_draft' && $post->post_type !== ($args->post_type ?? 'post'))
                    || ($row['tool'] === 'publish_content' && ($post->ID !== ($args->id ?? null)
                        || !in_array($post->post_status, isset($args->publish_at) ? ['future', 'publish'] : ['publish'], true)))
                    || ($row['tool'] === 'update_content' && $post->ID !== ($args->id ?? null))) {
                    $notice = 'pick_post';
                } else {
                    $response = ['state' => 'succeeded', 'actions_used' => 1, 'result' => ['id' => $post_id, 'status' => $post->post_status, 'version' => Operations::version($post)]];
                }
            } else {
                $notice = 'invalid';
            }
            if ($response !== null && !Journal::resolve($row, $response)) { $notice = 'changed'; }
        } finally { Journal::unlock(); }
        $back($notice);
    }
}
