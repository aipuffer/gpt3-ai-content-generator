<?php
/** Cloud connection controls and server-side credential custody. */
namespace WPAICG\Cloud;

use WPAICG\AIPKit_Role_Manager;
use RuntimeException;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class Connection
{
    public const OPTION = 'aipkit_cloud_connection';
    public const CONSENT_VERSION = 'cloud-connect-2026-10-04';
    public const TERMS_URL = 'https://aipower.org/terms-and-conditions/';
    public const PRIVACY_URL = 'https://aipower.org/privacy-policy/';
    private const LOCK = 'aipkit_cloud_connection_lock';
    private const LOCK_SECONDS = 180;
    private const STATUS = 'aipkit_cloud_status';
    private const ORIGIN = 'https://puffercloud.dev';
    private const PRODUCT = '11606';
    /** Last known balance and the last billing refusal (never the credential). */
    public const CREDITS = 'aipkit_cloud_credits';
    public const BILLING_CODES = ['insufficient_funds', 'site_limit', 'credit_deficit'];
    private const REFUSAL_REFRESH = 'aipkit_cloud_refusal_refresh';
    private const REFUSAL_REFRESH_SECONDS = 60;
    private const REGISTRATION = 'aipkit_cloud_registration';
    private const REGISTRATION_COOLDOWN = 'aipkit_cloud_registration_cooldown';
    private const BATCH_STOP_CODES = ['cloud_outcome_unknown', 'cloud_request_failed', 'already_dispatched_or_final', 'idempotency_conflict', 'gateway_aborted', 'gateway_response_unverified', 'insufficient_funds', 'site_limit', 'credit_deficit', 'account_paused', 'credential_unavailable', 'identity_check_unavailable', 'unauthorized', 'provider_daily_limit', 'provider_spending_paused', 'generation_rate_limit', 'generation_concurrency_limit', 'cloud_operation_rate_limit'];
    private const LOW_CREDITS = 5;
    private const REASONING_LEVELS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
    private static $state_cache_key = null;
    private static $state_cache = null;
    private static $models_cache_key = null;
    private static $models_cache = [];

    public static function allowed(): bool
    {
        return AIPKit_Role_Manager::user_can_manage_settings();
    }

    /**
     * The address Cloud knows this site by: the Freemius-registered URL, canonicalized by Cloud and
     * saved at connection time. Older saved connections fall back to the home URL they were made with.
     */
    public static function site(): string
    {
        try { $site = self::state()['site'] ?? ''; } catch (\Throwable $error) { $site = ''; }
        return is_string($site) && $site !== '' ? $site : untrailingslashit(home_url());
    }

    /** Server-only encrypted custody, excluded from normal settings exports. */
    public static function state(?array $value = null): array
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('cloud_storage_unavailable');
        }
        $key = hash('sha256', wp_salt('auth'), true);
        $aad = 'aipuffer-cloud-connection-v1:' . untrailingslashit(home_url());
        if ($value !== null) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt(wp_json_encode($value), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
            if ($cipher === false) {
                throw new RuntimeException('cloud_storage_unavailable');
            }
            $encoded = base64_encode($iv . $tag . $cipher);
            update_option(self::OPTION, $encoded, false);
            if (get_option(self::OPTION) !== $encoded) {
                throw new RuntimeException('cloud_storage_unavailable');
            }
            self::$state_cache_key = hash('sha256', serialize([$encoded, $key, $aad]));
            self::$state_cache = $value;
            return $value;
        }
        $stored = get_option(self::OPTION, '');
        if ($stored === '') {
            return [];
        }
        // Include ciphertext and encryption context so writes, resets, salt changes and
        // switch_to_blog() cannot reuse another connection's decrypted state.
        $cache_key = hash('sha256', serialize([$stored, $key, $aad]));
        if (self::$state_cache_key === $cache_key) {
            if (self::$state_cache === null) { throw new RuntimeException('cloud_storage_unreadable'); }
            return self::$state_cache;
        }
        self::$state_cache_key = $cache_key;
        self::$state_cache = null;
        $raw = is_string($stored) ? base64_decode($stored, true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('cloud_storage_unreadable');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        $decoded = $plain === false ? null : json_decode($plain, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('cloud_storage_unreadable');
        }
        self::$state_cache = $decoded;
        return $decoded;
    }

    /** POST JSON to Cloud. Errors keep Cloud's machine code (never its message) for the caller. */
    private static function post(string $path, array $body, array $headers, int $timeout, int $response_limit = 131072): array
    {
        $response = wp_remote_post(self::ORIGIN . $path, [
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body' => wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => $response_limit,
        ]);
        if (is_wp_error($response)) { throw new RuntimeException(in_array($body['operation'] ?? '', ['image_generate', 'image_edit', 'speech_generate', 'transcribe'], true) ? 'cloud_outcome_unknown' : 'cloud_unavailable'); }
        $result = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($result)) {
            $code = is_array($result) && is_string($result['code'] ?? null) && preg_match('/^[a-z_]{1,64}$/D', $result['code']) ? $result['code']
                : (in_array($body['operation'] ?? '', ['image_generate', 'image_edit', 'speech_generate', 'transcribe'], true) ? 'cloud_outcome_unknown' : 'cloud_unavailable');
            throw new RuntimeException(esc_html($code), (int) wp_remote_retrieve_response_code($response));
        }
        return $result;
    }

    private static function call(string $operation, int $timeout = 30): array
    {
        return self::post('/api/cloud', ['operation' => $operation, 'site' => self::site()], self::generation_headers(), $timeout);
    }

    /** Best-effort setup outcomes, sent only through an existing, consented Cloud connection. */
    public static function record_onboarding(array $progress): bool
    {
        if (!self::allowed()) { return false; }
        try {
            if (empty(self::state()['token'])) { return false; }
            $response = self::post('/api/cloud', ['operation' => 'onboarding', 'site' => self::site(), 'progress' => $progress], self::generation_headers(), 3);
            return ($response['status'] ?? '') === 'recorded';
        } catch (\Throwable $error) { return false; }
    }

    /** Explicit lookup only; callers must retain the original operation ID. Never polls or dispatches AI. */
    public static function request_status(string $operation_id): array
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $operation_id)) { throw new RuntimeException('invalid_operation_id'); }
        return self::post('/api/cloud', ['operation' => 'request_status', 'site' => self::site(), 'operationId' => $operation_id], self::generation_headers(), 30);
    }

    /** Store a completed provider receipt without changing visitor balances or quotas. */
    public static function record_receipt(array $receipt): void
    {
        $id = $receipt['requestId'] ?? '';
        $operation = $receipt['operation'] ?? '';
        $unit = $receipt['unit'] ?? '';
        $usage = $receipt['usage'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)
            || !in_array($operation, ['text_chat', 'embed', 'image_generate', 'image_edit', 'speech_generate', 'transcribe'], true)
            || !in_array($unit, ['token', 'image', 'character', 'second'], true)
            || !is_string($receipt['model'] ?? null) || !is_array($usage)) { return; }
        foreach (['input', 'output', 'cached'] as $key) {
            if (!isset($usage[$key]) || !is_int($usage[$key]) || $usage[$key] < 0) { return; }
        }
        $charged = $receipt['charged'] ?? null;
        if (!is_string($charged) || !preg_match('/^[0-9]{1,16}$/D', $charged)) { $charged = null; }
        if (!class_exists('\WPAICG\Core\TokenManager\Ledger\AIPKit_Ledger_Repository')) {
            require_once dirname(__DIR__) . '/usage/ledger.php';
        }
        $ledger = new \WPAICG\Core\TokenManager\Ledger\AIPKit_Ledger_Repository();
        $ledger->insert_entry([
            'entry_type' => 'request', 'idempotency_key' => 'cloud:' . $id,
            'user_id' => get_current_user_id() ?: null,
            'module' => $operation, 'operation' => $operation,
            'provider' => 'AIPufferCloud', 'model' => $receipt['model'],
            'usage_input_units' => $usage['input'], 'usage_output_units' => $usage['output'],
            'usage_total_units' => $usage['input'] + $usage['output'],
            'meta' => ['cloud_request_id' => $id, 'cloud_charged_units' => $charged,
                'usage_unit' => $unit, 'cached_units' => $usage['cached']],
        ]);
    }

    /** Stable Freemius IDs bind a credential to its owner; the editable email does not. */
    private static function freemius_identity(): ?array
    {
        try {
            $fs = wpaicg_gacg_fs();
            // Free sites retain the SDK's anonymous cache during opt-in; identity ignores tracking mode.
            if (!$fs->is_registered(true)) { return null; }
            $site = $fs->get_site();
            $user = $fs->get_user();
            $identity = ['product' => self::PRODUCT, 'user' => (string) ($user->id ?? ''), 'install' => (string) ($site->id ?? '')];
            foreach ($identity as $id) { if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) { return null; } }
            if ((string) ($site->user_id ?? '') !== $identity['user']) { return null; }
            return $identity;
        } catch (\Throwable $error) { return null; }
    }

    private static function connected_state(): array
    {
        $state = self::state();
        if (isset($state['token'], $state['identity']) && $state['identity'] !== self::freemius_identity()) {
            self::forget_changed_account();
            return [];
        }
        if (!empty($state['token']) && isset($state['email'])) {
            $user = wpaicg_gacg_fs()->get_user();
            $email = is_object($user) && is_string($user->email ?? null) && $user->email !== '' ? $user->email : $state['email'];
            if ($email !== $state['email']) {
                $state['email'] = $email;
                self::state($state);
                $credits = self::credit_state();
                $credits['emailVerified'] = null;
                update_option(self::CREDITS, $credits, false);
            }
        }
        return $state;
    }

    /** Freemius ownership changes must never keep the previous owner's Cloud wallet active. */
    public static function account_changed(): void
    {
        try {
            $state = self::state();
            if (!empty($state['token']) && (!isset($state['identity']) || $state['identity'] !== self::freemius_identity())) { self::forget_changed_account(); }
        } catch (\Throwable $error) { /* An unreadable connection already fails closed. */ }
    }

    /** Refresh once after a verified Freemius account sync, not during ordinary page loads. */
    public static function sync_verified_account($user): void
    {
        if (!self::allowed() || !is_object($user) || ($user->is_verified ?? null) !== true
            || !self::generation_ready() || (self::credit_state()['emailVerified'] ?? null) === true) { return; }
        self::transition('verify');
    }

    public static function forget_connection(): void
    {
        try {
            $state = self::state();
            if (!empty($state['token'])) {
                self::post('/api/cloud', ['operation' => 'disconnect', 'site' => $state['site'] ?? self::site()], ['Authorization' => 'Bearer ' . $state['token']], 3);
            }
        } catch (\Throwable $error) { /* Cloud independently rechecks ownership on the next request. */ }
        foreach ([self::OPTION, self::CREDITS, self::REFUSAL_REFRESH, 'aipkit_cloud_credit_packs'] as $option) { delete_option($option); }
    }

    private static function forget_changed_account(): void
    {
        $before = self::state()['identity']['user'] ?? null;
        $after = self::freemius_identity()['user'] ?? null;
        self::forget_connection();
        if ($before !== null && $after !== null && $before !== $after) {
            update_option(self::CREDITS, ['accountChanged' => true], false);
        }
    }

    public static function account_change_message(): string
    {
        return !empty(self::credit_state()['accountChanged'])
            ? __('This site’s account changed, so Cloud was disconnected. Connect with the account shown above to continue. Credits stay with their original account.', 'gpt3-ai-content-generator')
            : '';
    }

    public static function generation_ready(): bool
    {
        try { return !empty(self::connected_state()['token']) && is_array(self::state()['catalog']['models'] ?? null); }
        catch (\Throwable $error) { return false; }
    }

    public static function generation_endpoint(): string { return self::ORIGIN . '/api/cloud'; }
    /** Server transport only. Never include these headers in localization or logs. */
    public static function generation_headers(): array
    {
        $state = self::connected_state();
        if (empty($state['token'])) { throw new RuntimeException('cloud_not_connected'); }
        return ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $state['token']];
    }

    public static function models(): array
    {
        try { $state = self::connected_state(); } catch (\Throwable $error) { return []; }
        if (empty($state['token']) || !is_array($state['catalog']['models'] ?? null)) { return []; }
        // Ciphertext/context changes cover sync, reconnect, ownership and multisite switches.
        $cache_key = self::$state_cache_key . ':' . get_locale();
        if (self::$models_cache_key !== $cache_key) {
            self::$models_cache = array_map([self::class, 'label'], $state['catalog']['models']);
            self::$models_cache_key = $cache_key;
        }
        return self::$models_cache;
    }

    /** Embedding models Cloud offers this site: [['id' => 'aipuffer/embed-1', 'name' => …, 'dimensions' => 512]]. */
    public static function embedding_models(): array
    {
        if (!self::generation_ready()) { return []; }
        try { $embeddings = self::state()['catalog']['embeddings'] ?? []; } catch (\Throwable $error) { return []; }
        return is_array($embeddings) ? $embeddings : [];
    }

    /** Published Cloud image and speech operations. No local defaults are inferred. */
    public static function media_models(string $operation = ''): array
    {
        if (!self::generation_ready()) { return []; }
        try { $models = self::state()['catalog']['media'] ?? []; } catch (\Throwable $error) { return []; }
        if (!is_array($models)) { return []; }
        $models = $operation === '' ? $models : array_values(array_filter($models, static function ($model) use ($operation) {
            return ($model['operation'] ?? '') === $operation;
        }));
        // Catalog order also chooses new image defaults. Keep the cheapest published
        // generation model first without changing saved choices or other media operations.
        $images = array_values(array_filter($models, static function ($model): bool {
            return ($model['operation'] ?? '') === 'image_generate';
        }));
        usort($images, static function ($left, $right): int {
            $left_rate = is_numeric($left['creditsPerUnit'] ?? null) && $left['creditsPerUnit'] >= 0 ? (float) $left['creditsPerUnit'] : INF;
            $right_rate = is_numeric($right['creditsPerUnit'] ?? null) && $right['creditsPerUnit'] >= 0 ? (float) $right['creditsPerUnit'] : INF;
            return ($left_rate <=> $right_rate) ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });
        $image_index = 0;
        foreach ($models as $index => $model) {
            if (($model['operation'] ?? '') === 'image_generate') {
                $models[$index] = $images[$image_index++];
            }
        }
        return array_map(static function ($model) {
            $rate = (float) ($model['creditsPerUnit'] ?? 0);
            $unit = $model['unit'] ?? '';
            $amount = $unit === 'character' ? $rate * 1000 : ($unit === 'second' ? $rate * 60 : $rate);
            $number = number_format_i18n($amount, abs($amount - round($amount)) < 0.0001 ? 0 : 3);
            $suffix = $unit === 'character'
                ? sprintf(/* translators: %s: credits per 1,000 characters. */ __('%s credits / 1,000 characters', 'gpt3-ai-content-generator'), $number)
                : ($unit === 'second'
                    ? sprintf(/* translators: %s: credits per minute. */ __('%s credits / minute', 'gpt3-ai-content-generator'), $number)
                    : sprintf(/* translators: %s: credits per image. */ __('%s credits / image', 'gpt3-ai-content-generator'), $number));
            $model['name'] .= ' · ' . $suffix;
            return $model;
        }, $models);
    }

    public static function media_capabilities(string $operation, string $model_id): array
    {
        foreach (self::media_models($operation) as $model) {
            if (($model['id'] ?? '') === $model_id) { return $model['capabilities'] ?? []; }
        }
        return [];
    }

    /** One media request through the connected site's Cloud credential. */
    public static function media_request(string $operation, array $data): array
    {
        $model_id = $data['model'] ?? '';
        $selected = array_values(array_filter(self::media_models($operation), static function ($model) use ($model_id) {
            return ($model['id'] ?? '') === $model_id;
        }));
        if (count($selected) !== 1) { throw new RuntimeException('catalog_model_unavailable'); }
        $capabilities = $selected[0]['capabilities'] ?? [];
        if ($operation === 'speech_generate' && isset($capabilities['voices']) && !in_array($data['voice'] ?? '', $capabilities['voices'], true)) { throw new RuntimeException('invalid_media_request'); }
        if ($operation === 'speech_generate' && isset($capabilities['maxCharacters']) && (!is_string($data['text'] ?? null) || preg_match_all('/./us', trim($data['text'])) > $capabilities['maxCharacters'])) { throw new RuntimeException('invalid_media_request'); }
        if (isset($capabilities['maxPromptLength']) && (!is_string($data['prompt'] ?? null) || preg_match_all('/./us', $data['prompt']) > $capabilities['maxPromptLength'])) { throw new RuntimeException('invalid_media_request'); }
        if ($operation === 'transcribe' && isset($capabilities['inputFormats']) && !in_array($data['mime'] ?? '', $capabilities['inputFormats'], true)) { throw new RuntimeException('invalid_media_request'); }
        try {
            $result = self::post('/api/cloud', array_merge($data, ['operation' => $operation, 'site' => self::site(),
                'operationId' => $data['operationId'] ?? wp_generate_uuid4(), 'model' => $model_id]), self::generation_headers(), 120, 4400000);
            self::record_receipt($result);
            return $result;
        } catch (RuntimeException $error) {
            if (in_array($error->getMessage(), self::BILLING_CODES, true)) { self::note_billing_refusal($error->getMessage()); }
            throw $error;
        }
    }

    /**
     * Picker label and badge, built at read time (translated): the model's own name, "Gemini 3.8 Flash", and
     * example credits per chat reply, "≈9 credits", which the model picker shows as the badge.
     */
    private static function label(array $model): array
    {
        $family = \WPAICG\Core\Models\AIPKit_Model_Catalog::classify_publisher_family((string) $model['id'], 'AI Puffer');
        foreach ($family as $key => $value) {
            $model['family_' . $key] = $value;
        }
        $per_reply = (int) ($model['estimates']['chatReply'] ?? 0);
        if ($per_reply > 0) {
            /* translators: %s: example credits per chat reply. */
            $model['credits'] = sprintf(_n('≈%s credit', '≈%s credits', $per_reply, 'gpt3-ai-content-generator'), number_format_i18n($per_reply));
            $model['credits_description'] = __('Minimum 1 credit per successful request. Larger requests depend on input, output and reasoning tokens used.', 'gpt3-ai-content-generator');
            if (isset($model['estimates']['chatInput'], $model['estimates']['chatOutput'])) {
                /* translators: 1: example input tokens, 2: example output tokens. */
                $model['credits_description'] = sprintf(__('Minimum 1 credit per successful request. Example: %1$s input and %2$s output tokens, including reasoning. Larger requests cost more based on usage.', 'gpt3-ai-content-generator'), number_format_i18n($model['estimates']['chatInput']), number_format_i18n($model['estimates']['chatOutput']));
            }
        }
        return $model;
    }

    /**
     * Reasoning level for a module's saved setting, or '' to let Cloud use the model's lowest level.
     * Non-reasoning Cloud models never get one.
     */
    public static function reasoning_effort(string $model, $setting): string
    {
        $levels = [];
        foreach (self::models() as $candidate) { if ($candidate['id'] === $model) { $levels = $candidate['reasoning']['levels'] ?? []; break; } }
        $effort = is_string($setting) ? sanitize_key($setting) : '';
        if (in_array($effort, $levels, true)) { return $effort; }
        // Shared controls label minimal as low and max as xhigh.
        $aliases = ['low' => 'minimal', 'xhigh' => 'max'];
        $mapped = $aliases[$effort] ?? '';
        return $mapped !== '' && in_array($mapped, $levels, true) ? $mapped : '';
    }

    public static function credit_state(): array
    {
        $state = get_option(self::CREDITS, []);
        return is_array($state) ? $state : [];
    }

    private static function available_credit_units(): ?int
    {
        $units = self::credit_state()['credits']['available'] ?? null;
        return is_string($units) && preg_match('/^-?[0-9]{1,20}$/D', $units) ? (int) $units : null;
    }

    /** Display up to one decimal without rounding the usable balance up. */
    public static function format_credits($units): string
    {
        $units = max(0, (int) $units);
        if ($units > 0 && $units < 100) { return '<' . number_format_i18n(0.1, 1); }
        $tenths = intdiv($units, 100);
        return number_format_i18n($tenths / 10, $tenths % 10 === 0 ? 0 : 1);
    }

    /** Stores a validated balance and ends a recorded refusal once the balance shows it no longer applies. */
    private static function remember_credits(array $credits, ?bool $email_verified): void
    {
        $state = self::credit_state();
        $state['credits'] = $credits;
        $state['checkedAt'] = time();
        $state['emailVerified'] = $email_verified;
        $state['revoked'] = false;
        unset($state['accountChanged']);
        $problem = $state['problem'] ?? '';
        if (($problem === 'insufficient_funds' && (int) $credits['available'] > 0)
            || ($problem === 'credit_deficit' && empty($credits['restricted']))
            || ($problem === 'site_limit' && time() - (int) ($state['problemAt'] ?? 0) > DAY_IN_SECONDS)) {
            unset($state['problem'], $state['problemAt']);
        }
        update_option(self::CREDITS, $state, false);
    }

    /** Provider error metadata survives localized messages and optional automation substeps. */
    public static function request_error_data(string $code): array
    {
        if (!preg_match('/^[a-z_]{1,80}$/D', $code)) { return []; }
        return ['provider_error_code' => $code, 'stop_batch' => in_array($code, self::BATCH_STOP_CODES, true)];
    }

    /** Coalesce refusal-driven balance reads; manual refresh and generation stay available. */
    public static function note_billing_refusal(string $code): void
    {
        if (!in_array($code, self::BILLING_CODES, true)) { return; }
        $state = self::credit_state();
        $same_refusal = ($state['lastRefusalCode'] ?? '') === $code;
        if ($same_refusal && time() - (int) ($state['lastRefusalAt'] ?? 0) < self::REFUSAL_REFRESH_SECONDS) { return; }
        $refresh = time() - (int) get_option(self::REFUSAL_REFRESH, 0) >= self::REFUSAL_REFRESH_SECONDS
            && self::lock(self::REFUSAL_REFRESH, self::REFUSAL_REFRESH_SECONDS);
        if (!$refresh && $same_refusal) { return; }
        $state['problem'] = $code;
        $state['problemAt'] = time();
        $state['lastRefusalCode'] = $code;
        $state['lastRefusalAt'] = time();
        update_option(self::CREDITS, $state, false);
        try { $token = self::state()['token'] ?? ''; } catch (\Throwable $error) { $token = ''; }
        if ($refresh && $token !== '') { self::status(); }
        if ($code === 'insufficient_funds' && self::credits_exhausted()) { self::email_owner(); }
    }

    /** Whole credits available at the last check, or null when unknown. */
    public static function credits_left(): ?int
    {
        $units = self::available_credit_units();
        return $units !== null ? intdiv($units, 1000) : null;
    }

    public static function credits_low(): bool
    {
        $units = self::available_credit_units();
        return $units !== null && $units > 0 && $units < self::LOW_CREDITS * 1000;
    }

    /** An unactivated free allowance or an unaffordable request is not an exhausted balance. */
    public static function credits_exhausted(): bool
    {
        $allowance = self::credit_state()['credits']['allowance']['state'] ?? '';
        if (in_array($allowance, ['not_enrolled', 'verification_required', 'verification_unavailable'], true)) { return false; }
        $units = self::available_credit_units();
        return $units !== null && $units <= 0;
    }

    public static function account_url(): string
    {
        return admin_url('admin.php?page=wpaicg&aipkit_module=stats&aipkit_stats_tab=cloud');
    }

    public static function refresh_date(): string
    {
        $next = self::credit_state()['credits']['allowance']['nextRefresh'] ?? null;
        $time = is_string($next) ? strtotime($next) : false;
        return $time ? wp_date(get_option('date_format'), $time) : '';
    }

    /** Return the same actionable billing error for every request audience. */
    public static function billing_message(string $code): string
    {
        if ($code === 'site_limit') {
            return __('This site has reached its Cloud spending limit. Review the limit in your Cloud account.', 'gpt3-ai-content-generator');
        }
        if ($code === 'credit_deficit') {
            return __('A refunded or disputed Cloud credit purchase left this account with a credit deficit. Add credits or contact AI Puffer support to resolve it.', 'gpt3-ai-content-generator');
        }
        $date = self::refresh_date();
        return self::credits_exhausted()
            ? ($date !== ''
                /* translators: %s: date the free monthly credits refresh. */
                ? sprintf(__('AI Puffer Cloud credits have run out. Free credits refresh on %s.', 'gpt3-ai-content-generator'), $date)
                : __('AI Puffer Cloud credits have run out. Open Usage to add credits.', 'gpt3-ai-content-generator'))
            : __('Not enough Cloud credits left for this request. Reducing the output token limit may help. You can also choose a lower-cost model or add credits in Usage.', 'gpt3-ai-content-generator');
    }

    /** One email per week at most when credits run out. Disable with add_filter('aipkit_cloud_credit_email', '__return_false'). */
    private static function email_owner(): void
    {
        $state = self::credit_state();
        if (time() - (int) ($state['emailedAt'] ?? 0) < WEEK_IN_SECONDS || !apply_filters('aipkit_cloud_credit_email', true)) { return; }
        $state['emailedAt'] = time();
        update_option(self::CREDITS, $state, false);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $date = self::refresh_date();
        /* translators: %s: site name. */
        $subject = sprintf(__('[%s] Your AI Puffer Cloud credits have run out', 'gpt3-ai-content-generator'), $site);
        $lines = [
            /* translators: %s: site name. */
            sprintf(__('The AI features on %s that use AI Puffer Cloud (like your chatbot) can’t answer until credits are added.', 'gpt3-ai-content-generator'), $site),
            '',
            __('Review Cloud credits:', 'gpt3-ai-content-generator') . ' ' . self::account_url(),
        ];
        if ($date !== '') {
            /* translators: %s: date the free monthly credits refresh. */
            $lines[] = sprintf(__('Your free monthly credits refresh on %s.', 'gpt3-ai-content-generator'), $date);
        }
        $lines[] = '';
        $lines[] = __('You get this email at most once a week while credits are empty.', 'gpt3-ai-content-generator');
        wp_mail(get_option('admin_email'), $subject, implode("\n", $lines));
    }

    /** Cloud's catalog default model, or ''. */
    public static function default_model(): string
    {
        try { $default = self::state()['catalog']['defaultModel'] ?? ''; } catch (\Throwable $error) { return ''; }
        return is_string($default) ? $default : '';
    }

    /** Levels published for a Cloud model; [] when it does not reason or controls are unknown. */
    public static function reasoning_levels(string $model): array
    {
        foreach (self::models() as $candidate) { if ($candidate['id'] === $model) { return $candidate['reasoning']['levels'] ?? []; } }
        return [];
    }

    /** Freemius state that decides which consent text and connect path apply. Never secrets. */
    private static function freemius_display(): array
    {
        $display = ['registered' => false, 'confirm_email' => false, 'email' => (string) (get_option(self::REGISTRATION, [])['email'] ?? wp_get_current_user()->user_email), 'manage_email_url' => '', 'resend_email_url' => '', 'email_update' => null];
        try {
            $fs = wpaicg_gacg_fs();
            $display['registered'] = (bool) $fs->is_registered(true);
            $display['confirm_email'] = !$display['registered'] && (bool) $fs->is_pending_activation();
            $user = $display['registered'] ? $fs->get_user() : null;
            if (is_object($user) && is_string($user->email ?? null) && $user->email !== '') { $display['email'] = $user->email; }
            if ($display['registered']) {
                $display['manage_email_url'] = $fs->get_account_url();
                $display['resend_email_url'] = $fs->get_account_url('verify_email');
                if ($fs->should_handle_user_change()) {
                    $display['email_update'] = ['action' => $fs->get_ajax_action('update_email_address'), 'security' => $fs->get_ajax_security('update_email_address'), 'moduleId' => $fs->get_id(), 'verified' => ($user->is_verified ?? null) === true];
                }
            }
        } catch (\Throwable $error) { /* Freemius unavailable: connect reports it. */ }
        return $display;
    }

    /** The same account editor in setup, provider dialogs and Usage. */
    /**
     * @param bool $panel The Settings panel layout: avatar, a caption or verified line, "Change email", and
     *                    Disconnect below the card. Every hook the email editor uses stays the same.
     */
    public static function account_email_html(bool $onboarding = false, bool $panel = false): string
    {
        $display = self::display();
        $button = $onboarding ? 'aipkit-setup__btn' : 'aipkit_btn aipkit_btn-primary';
        $cancel = $onboarding ? 'aipkit-setup__btn aipkit-setup__btn--ghost' : 'aipkit_btn';
        ob_start();
        ?>
        <div class="aipkit_cloud_account_controls" data-cloud-account-controls data-aipkit-settings-autosave-exclude="true" <?php echo !$display['registered'] ? 'hidden' : ''; ?>>
            <div class="aipkit_cloud_account_row" data-cloud-account>
                <?php if ($panel) : ?>
                    <span class="aipkit_cloud_account_avatar" aria-hidden="true"><?php echo esc_html(function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($display['email'], 0, 1)) : strtoupper(substr($display['email'], 0, 1))); ?></span>
                    <span class="aipkit_cloud_account_who">
                        <?php if ($onboarding || !$display['connected']) : ?>
                            <span class="aipkit_cloud_account_caption" <?php echo $display['connected'] ? 'hidden' : ''; ?>><?php esc_html_e('Connect as', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                        <span data-cloud-email><?php echo esc_html($display['email']); ?></span>
                        <?php if ($onboarding || ($display['connected'] && $display['email_verified'] === true)) : ?>
                            <span class="aipkit_cloud_account_verified" <?php echo !$display['connected'] || $display['email_verified'] !== true ? 'hidden' : ''; ?>><?php esc_html_e('Email verified', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </span>
                <?php else : ?>
                    <span data-cloud-email><?php echo esc_html($display['email']); ?></span>
                <?php endif; ?>
                <button type="button" class="<?php echo $onboarding ? 'aipkit-setup__link' : 'button-link'; ?>" data-action="edit-email" aria-expanded="false" <?php echo !$display['email_update'] ? 'hidden' : ''; ?>><?php echo esc_html($panel ? __('Change email', 'gpt3-ai-content-generator') : __('Edit', 'gpt3-ai-content-generator')); ?></button>
                <a data-cloud-manage-email href="<?php echo esc_url($display['manage_email_url']); ?>" target="_blank" rel="noopener noreferrer" <?php echo $display['email_update'] || !$display['manage_email_url'] ? 'hidden' : ''; ?>><?php esc_html_e('Manage email', 'gpt3-ai-content-generator'); ?></a>
                <?php if ($onboarding) : ?>
                    <button type="button" class="aipkit-setup__link" data-action="disconnect-cloud" <?php echo !$display['connected'] ? 'hidden' : ''; ?>><?php esc_html_e('Disconnect', 'gpt3-ai-content-generator'); ?></button>
                <?php elseif ($display['connected'] && !$panel) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_account_disconnect" id="aipkit_cloud_account_form">
                        <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                        <input type="hidden" name="action" value="aipkit_cloud_connection">
                        <button type="submit" class="button-link aipkit_cloud_btn" name="cloud_action" value="disconnect"><?php esc_html_e('Disconnect', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                    </form>
                <?php endif; ?>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_email_editor" data-cloud-email-editor
                data-email-action="<?php echo esc_attr($display['email_update']['action'] ?? ''); ?>"
                data-email-security="<?php echo esc_attr($display['email_update']['security'] ?? ''); ?>"
                data-email-module="<?php echo esc_attr($display['email_update']['moduleId'] ?? ''); ?>" hidden>
                <label class="<?php echo $onboarding ? 'aipkit-setup__field' : 'aipkit_settings_provider_model_label'; ?>"><?php esc_html_e('Email', 'gpt3-ai-content-generator'); ?>
                    <input class="<?php echo $onboarding ? 'aipkit-setup__input' : 'aipkit_form-input'; ?>" type="email" data-field="account_email" autocomplete="email" required>
                </label>
                <fieldset data-email-ownership hidden>
                    <legend><?php printf(
                        /* translators: 1: current email; 2: new email. */
                        esc_html__('Are both %1$s and %2$s your email addresses?', 'gpt3-ai-content-generator'),
                        '<strong data-current-email>' . esc_html($display['email']) . '</strong>',
                        '<strong data-new-email></strong>'
                    ); ?></legend>
                    <label><input type="radio" name="email_ownership" value="both"><span><?php esc_html_e('Yes - both addresses are mine', 'gpt3-ai-content-generator'); ?></span></label>
                    <label><input type="radio" name="email_ownership" value="current"><span><strong data-new-email></strong> <?php esc_html_e('is my client’s email address', 'gpt3-ai-content-generator'); ?></span></label>
                    <label><input type="radio" name="email_ownership" value="new"><span><strong data-new-email></strong> <?php esc_html_e('is my email address', 'gpt3-ai-content-generator'); ?></span></label>
                </fieldset>
                <fieldset data-email-transfer hidden>
                    <legend><?php printf(
                        /* translators: 1: current email; 2: new email. */
                        esc_html__('Would you like to merge %1$s into %2$s?', 'gpt3-ai-content-generator'),
                        '<strong>' . esc_html($display['email']) . '</strong>',
                        '<strong data-new-email></strong>'
                    ); ?></legend>
                    <label><input type="radio" name="email_transfer" value="all"><span><?php esc_html_e('Yes - move all my data and assets from', 'gpt3-ai-content-generator'); ?> <strong><?php echo esc_html($display['email']); ?></strong> <?php esc_html_e('to', 'gpt3-ai-content-generator'); ?> <strong data-new-email></strong></span></label>
                    <label><input type="radio" name="email_transfer" value="plugin"><span><?php esc_html_e('No - only move this site’s data to', 'gpt3-ai-content-generator'); ?> <strong data-new-email></strong></span></label>
                </fieldset>
                <p><?php esc_html_e('Cloud credits stay with their original account. If this site moves to another account, you will need to reconnect Cloud.', 'gpt3-ai-content-generator'); ?></p>
                <p data-email-error role="alert" hidden></p>
                <div class="aipkit_cloud_email_actions">
                    <button type="submit" class="<?php echo esc_attr($button); ?>"><?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="<?php echo esc_attr($cancel); ?>" data-action="cancel-email"><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </form>
            <p class="aipkit_cloud_email_message" data-email-message role="status" hidden></p>
            <p class="aipkit_cloud_email_message" data-cloud-account-change role="status" <?php echo self::account_change_message() === '' ? 'hidden' : ''; ?>><?php echo esc_html(self::account_change_message()); ?></p>
        </div>
        <?php // The panel's model sync and its footer Disconnect submit this form; setup keeps its own Disconnect in the account row. ?>
        <?php if ($panel && !$onboarding && $display['connected']) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_panel_disconnect" id="aipkit_cloud_account_form" hidden>
                <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                <input type="hidden" name="action" value="aipkit_cloud_connection">
            </form>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /** Shared verification controls for Settings, Usage and setup. */
    public static function email_recovery_html(): string
    {
        $display = self::display();
        if (!$display['connected'] || $display['email_verified'] === true) { return ''; }
        ob_start();
        ?>
        <div class="aipkit_cloud_notice aipkit_cloud_notice--info aipkit_cloud_email_recovery">
            <p><?php echo esc_html($display['email_verified'] === false
                /* translators: %s: Freemius account email address. */
                ? sprintf(__('Verify %s to receive free monthly credits. Existing purchased credits remain available.', 'gpt3-ai-content-generator'), $display['email'])
                : __('Email verification could not be confirmed. Check again to receive free monthly credits. Existing purchased credits remain available.', 'gpt3-ai-content-generator')); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_footer">
                <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                <input type="hidden" name="action" value="aipkit_cloud_connection">
                <button type="submit" class="aipkit_btn aipkit_cloud_btn aipkit-setup__btn aipkit-setup__btn--ghost" name="cloud_action" value="verify"><?php esc_html_e('I’ve verified my email', 'gpt3-ai-content-generator'); ?></button>
                <?php if ($display['resend_email_url'] !== '') : ?>
                    <button type="button" class="aipkit_btn aipkit_cloud_btn aipkit-setup__btn aipkit-setup__btn--ghost" data-cloud-resend data-url="<?php echo esc_url($display['resend_email_url']); ?>"><?php esc_html_e('Resend email', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                <?php endif; ?>
            </form>
            <p data-cloud-resend-status role="status" aria-live="polite" hidden></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function verification_message(string $notice): string
    {
        $messages = [
            'invalid_email' => __('Enter a valid email address that you can access.', 'gpt3-ai-content-generator'),
            'registration_wait' => __('Please wait a minute before requesting another confirmation email.', 'gpt3-ai-content-generator'),
            'account_changed' => __('The account changed during connection. Please connect again.', 'gpt3-ai-content-generator'),
            'verification_pending' => __('Your email is still unverified. Open the confirmation link, then check again.', 'gpt3-ai-content-generator'),
            'verification_unavailable' => __('Email verification is unavailable right now. Please try again shortly.', 'gpt3-ai-content-generator'),
            'identity_verification_unavailable' => __('Your account could not be checked right now. Please try connecting again in a minute.', 'gpt3-ai-content-generator'),
            'balance_unavailable' => __('Your credits could not be checked. Please try again.', 'gpt3-ai-content-generator'),
        ];
        return $messages[$notice] ?? '';
    }

    /** Connection wording is shared by setup and the provider dialog. */
    public static function connection_message(string $status): string
    {
        $messages = [
            'freemius_failed' => __('We couldn’t connect this site to AI Puffer Cloud. Please try again. If the problem continues, contact support.', 'gpt3-ai-content-generator'),
            'unavailable' => __('We couldn’t connect this site to AI Puffer Cloud. Please try again. If the problem continues, contact support.', 'gpt3-ai-content-generator'),
            'installation_inactive' => __('This site’s account connection is inactive. Please try connecting again. If the problem continues, contact support.', 'gpt3-ai-content-generator'),
            'site_mismatch' => __('Your account has a different address for this site. Deactivate and reactivate AI Puffer, then connect again.', 'gpt3-ai-content-generator'),
            'account_unavailable' => __('This Cloud account is paused. Please contact AI Puffer support.', 'gpt3-ai-content-generator'),
            'busy' => __('Another connection action is in progress. Please wait before trying again.', 'gpt3-ai-content-generator'),
            'forbidden' => __('You cannot manage this Cloud connection.', 'gpt3-ai-content-generator'),
        ];
        $message = $messages[$status] ?? self::verification_message($status);
        if ($status === 'freemius_failed' && ConnectionDiagnostics::playground_failure()) {
            $message = __('Cloud connection is currently unavailable in this WordPress preview. Install AI Puffer on your site to claim your free credits.', 'gpt3-ai-content-generator');
        }
        return $message === '' ? '' : ConnectionDiagnostics::with_reference($message);
    }

    /** Processing disclosure stays accessible without putting service names in the controls. */
    public static function privacy_details_html(bool $diagnostics = true): string
    {
        ob_start(); ?>
        <details class="description">
            <summary><?php esc_html_e('What information do we collect?', 'gpt3-ai-content-generator'); ?></summary>
            <?php if ($diagnostics) : ?>
                <p><?php esc_html_e('When you connect to AI Puffer Cloud, we collect non-sensitive diagnostic data to help fix setup problems and improve the plugin. This includes connection results, error codes, response times, and your AI Puffer, WordPress and PHP versions. Reports are kept for 30 days.', 'gpt3-ai-content-generator'); ?></p>
            <?php endif; ?>
            <p><?php esc_html_e('To create and manage your account, AI Puffer uses Freemius for registration, email verification and licensing. This sends your name, email address and site URL. General usage tracking stays off, and marketing emails are optional.', 'gpt3-ai-content-generator'); ?></p>
            <p><a href="https://freemius.com/terms/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Freemius terms', 'gpt3-ai-content-generator'); ?></a> · <a href="https://freemius.com/privacy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Freemius privacy policy', 'gpt3-ai-content-generator'); ?></a></p>
        </details>
        <?php return ob_get_clean();
    }

    /** The same allowance explanation in Usage and setup. */
    public static function allowance_message(array $credits): string
    {
        $messages = [
            'restricted' => __('This account needs attention before more credits can be used. Please contact support.', 'gpt3-ai-content-generator'),
            'enrollment_daily_limit' => __('Today’s free-credit enrollment limit has been reached. Try again tomorrow. Purchased credits remain available.', 'gpt3-ai-content-generator'),
            'policy_unavailable' => __('Free-credit enrollment is temporarily unavailable. Try again later or contact support.', 'gpt3-ai-content-generator'),
            'paused' => __('Monthly free credits are paused.', 'gpt3-ai-content-generator'),
            'budget_exhausted' => __('Free credits are temporarily unavailable. Purchased credits keep working.', 'gpt3-ai-content-generator'),
        ];
        return $messages[!empty($credits['restricted']) ? 'restricted' : ($credits['allowance']['state'] ?? '')] ?? '';
    }

    /** Values safe to render. Never return the connection credential. */
    public static function display(bool $fresh = false): array
    {
        $display = ['status' => 'not_connected', 'connected' => false, 'recovery' => false, 'credits' => null, 'email_verified' => null, 'revoked' => false];
        if (!self::allowed()) {
            return $display;
        }
        $display += self::freemius_display();
        try {
            $state = self::connected_state();
            $display['connected'] = isset($state['token']);
            if ($display['connected']) {
                $display['status'] = 'connected';
                if ($fresh) {
                    $status = self::status();
                    $display['credits'] = $status['credits'];
                    $display['email_verified'] = $status['emailVerified'];
                    $display['revoked'] = !empty($status['revoked']);
                } else {
                    $saved = self::credit_state();
                    $display['credits'] = $saved['credits'] ?? null;
                    $display['email_verified'] = $saved['emailVerified'] ?? null;
                    $display['revoked'] = !empty($saved['revoked']);
                }
            }
        } catch (\Throwable $error) {
            $display['recovery'] = $error->getMessage() === 'cloud_storage_unreadable' && get_option(self::OPTION, '') !== '';
            $display['status'] = $display['recovery'] ? 'reconnect_required' : 'unavailable';
        }
        return $display;
    }

    /**
     * Balance and verification for explicit refreshes and billing refusals.
     */
    private static function status(bool $verify_email = false): array
    {
        try {
            $result = self::post('/api/cloud', ['operation' => 'status', 'site' => self::site(), 'verifyEmail' => $verify_email], self::generation_headers(), 20);
            $identity = $result['identity'] ?? null;
            if (is_array($identity)) {
                if ($identity !== self::freemius_identity()) {
                    self::forget_changed_account();
                    return ['credits' => null, 'emailVerified' => null, 'revoked' => true];
                }
                $state = self::state();
                if (!empty($state['token'])) { $state['identity'] = $identity; $state['email'] = self::freemius_display()['email']; self::state($state); }
            }
        }
        catch (\Throwable $error) {
            // Cloud no longer accepts this credential (revoked, replaced by a copy of this site, or reset).
            $revoked = $error->getMessage() === 'credential_unavailable';
            if ($revoked) {
                foreach ([self::OPTION, self::CREDITS, self::REFUSAL_REFRESH, 'aipkit_cloud_credit_packs'] as $option) { delete_option($option); }
            }
            return ['credits' => null, 'emailVerified' => null, 'revoked' => $revoked];
        }
        $status = ['credits' => self::validate_credits($result['credits'] ?? null), 'emailVerified' => is_bool($result['emailVerified'] ?? null) ? $result['emailVerified'] : null];
        if ($status['credits'] !== null) { self::remember_credits($status['credits'], $status['emailVerified']); }
        return $status;
    }

    /** Reject incomplete balances rather than displaying missing amounts as zero. */
    private static function validate_credits($value): ?array
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || ($value['currency'] ?? '') !== 'USD' || ($value['unitsPerDollar'] ?? null) !== 1000000) { return null; }
        foreach (['available', 'free', 'purchased', 'adjustments', 'held'] as $key) {
            if (!isset($value[$key]) || !is_string($value[$key]) || !preg_match('/^-?[0-9]{1,20}$/D', $value[$key])) { return null; }
        }
        if (isset($value['included']) && (!is_string($value['included']) || !preg_match('/^-?[0-9]{1,20}$/D', $value['included']))) { return null; }
        if (isset($value['includedAllowance'])) {
            $included = $value['includedAllowance'];
            if (!is_array($included) || !is_string($included['total'] ?? null) || !preg_match('/^[0-9]{1,20}$/D', $included['total'])
                || !array_key_exists('nextRefresh', $included)) { return null; }
            $date = $included['nextRefresh'];
            if ($date !== null && (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z$/D', $date) || strtotime($date) === false)) { return null; }
        }
        $allowance = $value['allowance'] ?? null;
        if (!is_array($allowance) || !in_array($allowance['state'] ?? '', ['not_enrolled', 'issued', 'suspended', 'restricted', 'paused', 'budget_exhausted', 'policy_unavailable', 'enrollment_daily_limit', 'verification_required', 'verification_unavailable'], true)
            || !array_key_exists('total', $allowance) || !array_key_exists('nextRefresh', $allowance) || !is_bool($value['restricted'] ?? null)) { return null; }
        if ($allowance['total'] !== null && (!is_string($allowance['total']) || !preg_match('/^[0-9]{1,20}$/D', $allowance['total']))) { return null; }
        foreach ([$allowance['nextRefresh'], $value['checkedAt'] ?? ''] as $date) {
            if ($date !== null && (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z$/D', $date) || strtotime($date) === false)) { return null; }
        }
        return $value;
    }

    /**
     * Atomic lock. add_option() is not atomic under concurrency, and a lock left by a request that
     * timed out or crashed expires instead of blocking every Cloud action (including reset) forever.
     */
    private static function lock(string $name = self::LOCK, int $seconds = self::LOCK_SECONDS): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic lock row; must bypass the options cache.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d", $name, time() - $seconds));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- INSERT IGNORE succeeds for exactly one request.
        $claimed = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time())) === 1;
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        return $claimed;
    }

    private static function unlock(): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => self::LOCK]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See lock().
    }

    /** Capability check is repeated here for every caller; HTTP also requires a nonce. */
    public static function transition(string $action, bool $consent = false, bool $marketing = false, string $email = '', string $source = 'settings'): string
    {
        if (!self::allowed() || !in_array($action, ['connect', 'disconnect', 'sync', 'refresh', 'balance', 'verify', 'reset'], true)) {
            return 'forbidden';
        }
        if (!self::lock()) {
            return 'busy';
        }
        try {
            if ($action === 'connect') {
                if (!$consent) { return 'unavailable'; }
                return ConnectionDiagnostics::run(static function () use ($marketing, $email): string {
                    $state = self::connected_state();
                    if (isset($state['token'])) { return 'unavailable'; }
                    return self::connect($marketing, $email);
                }, $source);
            }
            if ($action === 'reset') {
                try { self::state(); }
                catch (\Throwable $error) {
                    if ($error->getMessage() === 'cloud_storage_unreadable') {
                        delete_option(self::OPTION);
                        delete_option(self::CREDITS);
                        delete_option(self::REFUSAL_REFRESH);
                        delete_option('aipkit_cloud_credit_packs');
                        return get_option(self::OPTION, '') === '' ? 'reset' : 'unavailable';
                    }
                }
                return 'unavailable';
            }
            $state = self::connected_state();
            if ($action === 'verify') {
                if (!isset($state['token'])) { return 'confirm_email'; }
                $status = self::status(true);
                if ($status['credits'] === null) { return 'balance_unavailable'; }
                return $status['emailVerified'] === true ? 'verified' : ($status['emailVerified'] === false ? 'verification_pending' : 'verification_unavailable');
            }
            if ($action === 'balance') {
                if (!isset($state['token'])) { return 'unavailable'; }
                return self::status()['credits'] !== null ? 'refreshed' : 'balance_unavailable';
            }
            if ($action === 'refresh') {
                if (!isset($state['token'])) { return 'unavailable'; }
                $balance_ok = self::status()['credits'] !== null;
                $models_ok = self::sync_catalog($state) === 'synced';
                if ($balance_ok && $models_ok) { return 'refreshed'; }
                if ($balance_ok) { return 'models_unavailable'; }
                return $models_ok ? 'balance_unavailable' : 'unavailable';
            }
            if ($action === 'sync') {
                return self::sync_catalog($state);
            }
            if ($action === 'disconnect' && isset($state['token'])) {
                // A credential Cloud no longer accepts is already useless: remove it locally anyway.
                // Any other failure keeps the connection so a valid credential is never orphaned.
                try { self::call('disconnect'); }
                catch (\Throwable $error) { if ($error->getMessage() !== 'credential_unavailable') { throw $error; } }
                delete_option(self::OPTION);
                delete_option(self::CREDITS);
                delete_option(self::REFUSAL_REFRESH);
                delete_option('aipkit_cloud_credit_packs');
                return 'disconnected';
            }
            return 'unavailable';
        } catch (\Throwable $error) {
            return 'unavailable';
        } finally {
            self::unlock();
        }
    }

    /**
     * Custom registration consents to the service, not optional SDK telemetry. Set these before
     * opt_in() so both its request and a later email-confirmation callback retain that choice.
     * Premium licensing's essential permissions and the separate marketing choice are unchanged.
     */
    public static function disable_optional_registration_tracking(): void
    {
        \FS_Permission_Manager::instance(\wpaicg_gacg_fs())->update_permissions_tracking_flag([
            'site' => false,
            'diagnostic' => false,
            'extensions' => false,
        ]);
    }

    /**
     * One click: prove this installation to Cloud with the Freemius install secret. Sites that are not
     * registered with Freemius are registered first (explicit consent, usage tracking off).
     */
    private static function connect(bool $marketing, string $email): string
    {
        if (function_exists('set_time_limit')) { @set_time_limit(120); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Bounded explicit action; disabled on some hosts. Provider requests retain their own timeouts.
        try { $fs = wpaicg_gacg_fs(); } catch (\Throwable $error) { ConnectionDiagnostics::error('sdk_unavailable'); return 'freemius_failed'; }
        $registered = $fs->is_registered(true);
        if (!$registered) {
            // Registration email is explicit; changing it never edits the WordPress profile.
            if ($email === '') { $email = (string) (get_option(self::REGISTRATION, [])['email'] ?? wp_get_current_user()->user_email); }
            if (!is_email($email)) { return 'invalid_email'; }
            if (!self::lock(self::REGISTRATION_COOLDOWN, 60)) { return 'registration_wait'; }
            update_option(self::REGISTRATION, ['email' => $email], false);
            try {
                self::disable_optional_registration_tracking();
                ConnectionDiagnostics::sdk_result($fs->opt_in($email, false, false, false, false, false, true, $marketing, [], false));
            }
            catch (\Throwable $error) { ConnectionDiagnostics::error($error->getMessage()); return 'freemius_failed'; }
            if (!$fs->is_registered(true)) { return $fs->is_pending_activation() ? 'confirm_email' : 'freemius_failed'; }
        }
        if ($registered) {
            // Reinstall activation queues this update in cron. Explicit Connect must not depend on cron running.
            // Preserve tracking permissions and skip extension inventories for this connection-only update.
            // Cloud still independently verifies this install.
            $sync_timeout = static function (array $args, string $url): array {
                if (in_array(wp_parse_url($url, PHP_URL_HOST), ['api.freemius.com', 'wp.freemius.com'], true)) {
                    $args['timeout'] = min(10, (float) ($args['timeout'] ?? 10));
                }
                return $args;
            };
            add_filter('http_request_args', $sync_timeout, PHP_INT_MAX, 2);
            try { $fs->sync_install(['plugins' => [], 'themes' => []], true); }
            catch (\Throwable $error) { ConnectionDiagnostics::error($error->getMessage()); }
            finally { remove_filter('http_request_args', $sync_timeout, PHP_INT_MAX); }
        }
        $install = $fs->get_site();
        $install_id = is_object($install) ? (string) ($install->id ?? '') : '';
        $secret = is_object($install) ? (string) ($install->secret_key ?? '') : '';
        $url = is_object($install) && is_string($install->url ?? null) ? untrailingslashit($install->url) : '';
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $install_id) || strlen($secret) < 16 || $url === '') { ConnectionDiagnostics::error('installation_missing'); return 'freemius_failed'; }
        ConnectionDiagnostics::context($url, $install_id);
        try {
            ConnectionDiagnostics::stage('challenge');
            $reference = ConnectionDiagnostics::report()['reference'] ?? null;
            $c = self::post('/api/connect', ['operation' => 'challenge', 'install' => $install_id, 'site' => $url, 'acceptedConsentVersion' => self::CONSENT_VERSION, 'reference' => $reference], [], 20);
            if (!is_string($c['id'] ?? null) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $c['id'])
                || ($c['install'] ?? '') !== $install_id || ($c['product'] ?? '') !== self::PRODUCT
                || !is_string($c['site'] ?? null) || strcasecmp($c['site'], $url) !== 0
                || !is_string($c['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $c['nonce'])
                || !is_int($c['expiresAt'] ?? null) || !is_string($c['consentVersion'] ?? null) || !preg_match('/^[a-z0-9-]{1,64}$/D', $c['consentVersion'])) {
                return 'unavailable';
            }
            // Byte-identical to Cloud's JSON.stringify: no escaped slashes or Unicode.
            $message = wp_json_encode(['aipuffer-cloud-connect-v1', self::PRODUCT, $install_id, $c['site'], $c['id'], $c['nonce'], $c['expiresAt'], $c['consentVersion']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ConnectionDiagnostics::stage('connection');
            $result = self::post('/api/connect', ['operation' => 'connect', 'id' => $c['id'], 'signature' => hash_hmac('sha256', $message, $secret), 'consent' => true, 'acceptedConsentVersion' => $c['consentVersion'], 'reference' => $reference], [], 45);
        } catch (RuntimeException $error) {
            ConnectionDiagnostics::error($error->getMessage());
            return in_array($error->getMessage(), ['installation_inactive', 'site_mismatch', 'account_unavailable'], true) ? $error->getMessage() : 'unavailable';
        }
        if (($result['status'] ?? '') !== 'connected' || ($result['siteUrl'] ?? '') !== $c['site']
            || !is_string($result['token'] ?? null) || !preg_match('/^apc_site_[a-f0-9]{64}$/D', $result['token'])) {
            return 'unavailable';
        }
        $receipt = $result['consent'] ?? null;
        if (!is_array($receipt) || !is_string($receipt['version'] ?? null) || !preg_match('/^[a-z0-9-]{1,64}$/D', $receipt['version'])
            || !is_string($receipt['acceptedAt'] ?? null)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z$/D', $receipt['acceptedAt'])
            || strtotime($receipt['acceptedAt']) === false) {
            // A malformed success must not leave a usable connection without its consent receipt.
            try { self::post('/api/cloud', ['operation' => 'disconnect', 'site' => $c['site']], ['Authorization' => 'Bearer ' . $result['token']], 3); }
            catch (\Throwable $error) { /* Local adoption remains rejected if revocation is unavailable. */ }
            return 'unavailable';
        }
        $identity = $result['identity'] ?? null;
        if (!is_array($identity) || $identity !== self::freemius_identity()) {
            try { self::post('/api/cloud', ['operation' => 'disconnect', 'site' => $c['site']], ['Authorization' => 'Bearer ' . $result['token']], 3); } catch (\Throwable $error) {}
            return 'account_changed';
        }
        delete_option(self::REGISTRATION);
        delete_option(self::REGISTRATION_COOLDOWN);
        $state = ['identity' => $identity, 'email' => self::freemius_display()['email'], 'token' => $result['token'], 'site' => $c['site'], 'consent' => [
            'version' => $receipt['version'], 'accepted_at' => $receipt['acceptedAt'],
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'), 'accepted_by' => get_current_user_id(),
        ]];
        self::state($state);
        delete_option(self::CREDITS);
        delete_option(self::REFUSAL_REFRESH);
        delete_option('aipkit_cloud_credit_packs');
        try {
            $catalog = self::validate_catalog(self::call('models', 20));
            $catalog['syncedAt'] = time();
            self::state($state + ['catalog' => $catalog]);
        } catch (\Throwable $error) { /* Connected; model sync can be retried independently. */ }
        self::status();
        return 'connected';
    }

    /** Refresh the saved model list. Caller holds the lock. Never moves to an older catalog revision. */
    private static function sync_catalog(array $state): string
    {
        if (!isset($state['token'])) { return 'sync_failed'; }
        try {
            $catalog = self::validate_catalog(self::call('models'));
            $previous = $state['catalog'] ?? null;
            $prior_models = $previous['models'] ?? [];
            $next_models = $catalog['models'];
            // An older saved catalog can carry this removed display field at the same revision.
            foreach ($prior_models as &$model) { unset($model['purchasedCreditsOnly']); }
            unset($model);
            if ($previous && (strcmp(str_pad($catalog['revision'], 18, '0', STR_PAD_LEFT), str_pad($previous['revision'], 18, '0', STR_PAD_LEFT)) < 0
                || ($catalog['revision'] === $previous['revision'] && ($next_models !== $prior_models || $catalog['defaultModel'] !== $previous['defaultModel'])))) {
                return 'sync_failed';
            }
            if ($previous && strcmp(str_pad($catalog['mediaRevision'], 18, '0', STR_PAD_LEFT), str_pad($previous['mediaRevision'] ?? '0', 18, '0', STR_PAD_LEFT)) < 0) {
                return 'sync_failed';
            }
            $catalog['syncedAt'] = time();
            $state = self::state();
            $state['catalog'] = $catalog;
            self::state($state);
            return 'synced';
        } catch (\Throwable $error) { return 'sync_failed'; }
    }

    /** Remove events and options left by the former recurring checks on existing installations. */
    public static function remove_scheduled_sync(): void
    {
        if (get_option('aipkit_cloud_background_checks_removed')) { return; }
        wp_clear_scheduled_hook('aipkit_cloud_sync_models');
        delete_option('aipkit_cloud_used_at');
        delete_transient(self::STATUS);
        delete_transient('aipkit_cloud_credit_packs');
        update_option('aipkit_cloud_background_checks_removed', true, false);
    }

    /** Freemius calls this only on actual removal, after checking the other plugin edition. */
    public static function uninstall(): void
    {
        if (!defined('WP_FS__UNINSTALL_MODE') || !WP_FS__UNINSTALL_MODE) { return; }
        $deadline = microtime(true) + 10;
        if (!is_multisite()) {
            self::uninstall_site($deadline);
            return;
        }
        $offset = 0;
        do {
            $sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);
            foreach ($sites as $site_id) {
                switch_to_blog((int) $site_id);
                try { self::uninstall_site($deadline); }
                finally { restore_current_blog(); }
            }
            $offset += count($sites);
        } while (count($sites) === 100);
    }

    private static function uninstall_site(float $deadline): void
    {
        try {
            $timeout = min(3, (int) floor($deadline - microtime(true)));
            if ($timeout > 0 && !empty(self::state()['token'])) { self::call('disconnect', $timeout); }
        } catch (\Throwable $error) {
            // Removal cannot depend on network availability or an old credential still being readable.
        } finally {
            foreach ([self::OPTION, self::CREDITS, self::REFUSAL_REFRESH, 'aipkit_cloud_credit_packs', self::LOCK, self::REGISTRATION, self::REGISTRATION_COOLDOWN,
                'aipkit_cloud_used_at', 'aipkit_cloud_background_checks_removed', ConnectionDiagnostics::OPTION] as $option) {
                delete_option($option);
            }
            delete_transient(self::STATUS);
            delete_transient('aipkit_cloud_credit_packs');
            wp_clear_scheduled_hook('aipkit_cloud_sync_models');
            self::$state_cache_key = null;
            self::$state_cache = null;
        }
    }

    /** Authenticated settings actions; only safe view data reaches the browser. */
    public static function handle(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if (!self::allowed() || $method !== 'POST') {
            wp_send_json_error(['message' => __('You cannot manage this Cloud connection.', 'gpt3-ai-content-generator')], 403);
        }
        if (!check_ajax_referer('aipkit_cloud_connection', '_wpnonce', false)) {
            wp_send_json_error(['message' => __('Your session has expired. Reload Settings and try again.', 'gpt3-ai-content-generator')], 403);
        }
        $action = isset($_POST['cloud_action']) && is_string($_POST['cloud_action']) ? sanitize_key(wp_unslash($_POST['cloud_action'])) : '';
        if ($action === 'checkout') {
            try {
                $result = self::post('/api/cloud', ['operation' => 'checkout', 'site' => self::site()], self::generation_headers(), 30);
                $url = $result['url'] ?? '';
                if (!is_string($url) || wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || wp_parse_url($url, PHP_URL_HOST) !== 'checkout.freemius.com') { throw new RuntimeException('invalid_checkout'); }
            } catch (\Throwable $error) {
                if ($error->getMessage() === 'credit_buyer_unverified') {
                    wp_send_json_error(['message' => __('Verify your email before buying credits. Use Resend email or Manage email in the connection settings.', 'gpt3-ai-content-generator')], 403);
                }
                wp_send_json_error(['message' => __('Cloud checkout is unavailable. Refresh your connection and try again.', 'gpt3-ai-content-generator')], 503);
            }
            wp_send_json_success(['checkout_url' => $url]);
        }
        $consent = isset($_POST['cloud_consent']) && $_POST['cloud_consent'] === 'yes';
        $marketing = isset($_POST['cloud_marketing']) && $_POST['cloud_marketing'] === 'yes';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- transition() validates the unchanged candidate with is_email(); malformed addresses must be rejected, not silently repaired.
        $email = isset($_POST['cloud_email']) && is_string($_POST['cloud_email']) ? trim(wp_unslash($_POST['cloud_email'])) : '';
        if ($action === 'view') { $notice = ''; }
        elseif ($action === 'check_email' && !self::display()['registered']) { $notice = 'confirm_email'; }
        else { $notice = self::transition($action === 'check_email' ? 'connect' : $action, $consent, $marketing, $email); }
        if ($action === 'balance') {
            wp_send_json_success((self::display()['connected'] ? self::credit_view_response() : self::view_response()) + ['refreshed' => $notice === 'refreshed']);
        }
        wp_send_json_success(self::view_response($notice));
    }

    /** Cached credit UI only: no model synchronization or pack lookup. */
    private static function credit_view_response(string $aipkit_cloud_notice = ''): array
    {
        ob_start();
        include dirname(__DIR__, 2) . '/admin/views/usage/cloud-account.php';
        $usage_html = ob_get_clean();
        ob_start();
        CreditNotice::render(true);
        $credit_notice_html = ob_get_clean();
        return [
            'usageHtml' => $usage_html,
            'creditNoticeHtml' => $credit_notice_html,
            'navStatusHtml' => StatusChip::html(),
            'connected' => self::display()['connected'],
            'checkedAt' => (int) (self::credit_state()['checkedAt'] ?? 0),
        ];
    }

    /** Safe account views and catalogs for every manual connection or sync action. */
    public static function view_response(string $aipkit_cloud_notice = ''): array
    {
        ob_start();
        $aipkit_account_fields_only = true;
        include dirname(__DIR__, 2) . '/admin/views/settings/providers.php';
        $html = ob_get_clean();
        $states = \WPAICG\AIPKit_Providers::get_provider_connection_states();
        return self::credit_view_response($aipkit_cloud_notice) + [
            'html' => $html,
            'connectionDiagnostic' => ConnectionDiagnostics::report(),
            'emailVerified' => self::display()['email_verified'],
            'emailRecoveryHtml' => self::email_recovery_html(),
            'verificationMessage' => self::verification_message($aipkit_cloud_notice),
            'hasCredits' => (self::available_credit_units() ?? 0) > 0,
            'models' => self::models(),
            'newConfiguration' => \WPAICG\AIPKit_Providers::get_new_configuration_payload(),
            'selectedModel' => (string) (\WPAICG\AIPKit_Providers::get_provider_data('AIPufferCloud')['model'] ?? ''),
            'embeddingModels' => \WPAICG\AIPKit_Providers::get_embedding_models_by_provider('dashboard_ui')['aipuffercloud'] ?? [],
            'mediaModels' => self::media_models(),
            'providerStatus' => \WPAICG\AIPKit_Providers::get_provider_status_map(),
            'provider_states' => ['aipuffercloud' => $states['aipuffercloud'] ?? []],
        ];
    }

    /** Public history needs only a saved model name, never account or pricing details. */
    public static function model_display_name(string $model): string
    {
        try { $catalog = self::state()['catalog'] ?? []; } catch (\Throwable $error) { return ''; }
        foreach (['models', 'media', 'embeddings'] as $kind) {
            foreach ($catalog[$kind] ?? [] as $entry) {
                if (($entry['id'] ?? '') === $model && !empty($entry['name'])) {
                    return (string) $entry['name'];
                }
            }
        }
        return '';
    }

    public static function catalog(): ?array
    {
        if (!self::allowed()) { return null; }
        try { return self::state()['catalog'] ?? null; } catch (\Throwable $error) { return null; }
    }

    private static function validate_catalog(array $value): array
    {
        if (!isset($value['revision'], $value['models']) || !is_string($value['revision']) || !preg_match('/^(0|[1-9][0-9]{0,17})$/D', $value['revision'])
            || !is_array($value['models']) || count($value['models']) > 100 || !array_key_exists('defaultModel', $value)) {
            throw new RuntimeException('invalid_catalog');
        }
        $models = []; $ids = [];
        foreach ($value['models'] as $model) {
            if (!is_array($model) || !is_string($model['id'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._:-]*$/D', $model['id']) || strlen($model['id']) > 200
                || isset($ids[$model['id']]) || !is_string($model['name'] ?? null) || $model['name'] === '' || strlen($model['name']) > 1024
                || ($model['operation'] ?? '') !== 'text_chat' || !is_array($model['price'] ?? null)) { throw new RuntimeException('invalid_catalog'); }
            $price = $model['price'];
            if (!is_string($price['id'] ?? null) || !preg_match('/^catalog_[a-f0-9]{64}$/D', $price['id'])) { throw new RuntimeException('invalid_catalog'); }
            foreach (['inputRate', 'cachedRate', 'outputRate'] as $key) {
                if (!is_string($price[$key] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,12})$/D', $price[$key]) || (float) $price[$key] > 1000000000000) { throw new RuntimeException('invalid_catalog'); }
            }
            foreach (['maxInput', 'maxOutput'] as $key) {
                if (!is_int($price[$key] ?? null) || $price[$key] < 1 || $price[$key] > 10000000) { throw new RuntimeException('invalid_catalog'); }
            }
            if ((float) $price['cachedRate'] > (float) $price['inputRate']) { throw new RuntimeException('invalid_catalog'); }
            if (($price['minimumCharge'] ?? null) !== '1000') { throw new RuntimeException('invalid_catalog'); }
            // Reasoning levels are optional (older Cloud versions omit them); only known levels are kept.
            $reasoning = null;
            if (isset($model['reasoning']) && is_array($model['reasoning']) && is_array($model['reasoning']['levels'] ?? null)) {
                $reasoning = ['levels' => array_values(array_intersect(self::REASONING_LEVELS, $model['reasoning']['levels']))];
            }
            $estimates = null;
            if (is_array($model['estimates'] ?? null) && is_int($model['estimates']['chatReply'] ?? null) && is_int($model['estimates']['article'] ?? null)) {
                $estimates = ['chatReply' => max(1, $model['estimates']['chatReply']), 'article' => max(1, $model['estimates']['article'])];
                foreach (['chatInput' => 'maxInput', 'chatOutput' => 'maxOutput'] as $key => $limit) {
                    $tokens = $model['estimates'][$key] ?? null;
                    if (is_int($tokens) && $tokens > 0 && $tokens <= $price[$limit]) { $estimates[$key] = $tokens; }
                }
            }
            $ids[$model['id']] = true;
            $models[] = ['id' => $model['id'], 'name' => $model['name'], 'operation' => 'text_chat', 'price' => array_intersect_key($price, array_flip(['id', 'inputRate', 'cachedRate', 'outputRate', 'minimumCharge', 'maxInput', 'maxOutput'])), 'reasoning' => $reasoning, 'capabilities' => ['image_input' => ($model['capabilities']['image_input'] ?? false) === true], 'estimates' => $estimates];
        }
        if ($value['defaultModel'] !== null && (!is_string($value['defaultModel']) || !isset($ids[$value['defaultModel']]))) { throw new RuntimeException('invalid_catalog'); }
        // Embedding models (Cloud catalogs from 2026-09 on). Optional; a malformed entry is dropped, never
        // allowed to break chat. Only sizes the plugin can store are kept.
        $embeddings = [];
        foreach (is_array($value['embeddings'] ?? null) ? array_slice($value['embeddings'], 0, 10) : [] as $embedding) {
            if (!is_array($embedding) || !is_string($embedding['id'] ?? null) || !preg_match('/^aipuffer\/embed-[0-9]{1,4}$/D', $embedding['id'])
                || ($embedding['operation'] ?? '') !== 'embedding' || !in_array($embedding['dimensions'] ?? null, [512, 1536], true)
                || !is_string($embedding['name'] ?? null) || $embedding['name'] === '' || strlen($embedding['name']) > 200) { continue; }
            $supported = $embedding['supportedDimensions'] ?? [$embedding['dimensions']];
            if (!is_array($supported) || !$supported || array_diff($supported, [512, 1536]) || !in_array($embedding['dimensions'], $supported, true)) { continue; }
            $embeddings[] = ['supportedDimensions' => array_values($supported), 'id' => $embedding['id'], 'name' => sanitize_text_field($embedding['name']), 'dimensions' => $embedding['dimensions']];
        }
        $media_revision = $value['mediaRevision'] ?? '0';
        if (!is_string($media_revision) || !preg_match('/^(0|[1-9][0-9]{0,17})$/D', $media_revision)) { throw new RuntimeException('invalid_catalog'); }
        $media = [];
        $media_ids = [];
        $operations = ['image_generate' => 'image', 'image_edit' => 'image', 'speech_generate' => 'character', 'transcribe' => 'second'];
        foreach (is_array($value['media'] ?? null) ? array_slice($value['media'], 0, 24) : [] as $entry) {
            if (!is_array($entry) || !isset($operations[$entry['operation'] ?? ''])
                || !is_string($entry['id'] ?? null) || !preg_match('~^aipuffer/(image_generate|image_edit|speech_generate|transcribe)/[-a-z0-9._]+/[-a-z0-9._]+$~D', $entry['id'])
                || isset($media_ids[$entry['id']])
                || ($entry['unit'] ?? '') !== $operations[$entry['operation']]
                || !is_string($entry['name'] ?? null) || $entry['name'] === '' || strlen($entry['name']) > 120
                || !is_numeric($entry['creditsPerUnit'] ?? null) || (float) $entry['creditsPerUnit'] <= 0
                || !is_int($entry['maxQuantity'] ?? null) || $entry['maxQuantity'] < 1) { throw new RuntimeException('invalid_catalog'); }
            $capabilities = [];
            foreach (['maxCharacters', 'maxPromptLength', 'maxInputBytes', 'maxWidth', 'maxHeight', 'maxSeconds'] as $key) {
                $limit = $entry['capabilities'][$key] ?? null;
                if (is_int($limit) && $limit > 0 && $limit <= 10000000) { $capabilities[$key] = $limit; }
            }
            foreach (['voices', 'inputFormats'] as $key) {
                $values = $entry['capabilities'][$key] ?? null;
                if (is_array($values) && count($values) <= 100 && count(array_filter($values, static function ($v) { return is_string($v) && preg_match('~^[a-z0-9/_-]{1,80}$~D', $v); })) === count($values)) { $capabilities[$key] = array_values($values); }
            }
            $media_ids[$entry['id']] = true;
            $media[] = ['id' => $entry['id'], 'name' => sanitize_text_field($entry['name']), 'operation' => $entry['operation'],
                'unit' => $entry['unit'], 'creditsPerUnit' => (float) $entry['creditsPerUnit'], 'maxQuantity' => $entry['maxQuantity'], 'capabilities' => $capabilities];
        }
        return ['revision' => $value['revision'], 'mediaRevision' => $media_revision, 'defaultModel' => $value['defaultModel'], 'models' => $models,
            'embeddings' => $embeddings, 'media' => $media];
    }
}
