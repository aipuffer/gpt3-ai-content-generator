<?php

namespace WPAICG\WP_AI_Client;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WPAICG\AIPKit_Providers;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Images\AIPKit_Image_Manager;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    exit;
}

class AIPKit_WP_AI_Client_Connectors
{
    private static bool $syncing = false;

    public static function register_hooks(): void
    {
        add_action('admin_footer', [self::class, 'maybe_render_banner']);
        add_action('wp_ajax_aipkit_wp_ai_client_set_mode', [self::class, 'ajax_set_mode']);
        add_action('wp_connectors_init', [self::class, 'customize_registry'], 1000);
        add_action('updated_option', [self::class, 'maybe_bridge_connector_key'], 10, 3);
        add_action('added_option', [self::class, 'maybe_bridge_added_connector_key'], 10, 2);
        add_filter('script_module_data_options-connectors-wp-admin', [self::class, 'mark_managed_connectors_connected'], 1000);
        add_filter('wpai_has_ai_credentials', [self::class, 'declare_credentials_present'], 1000, 1);
        add_filter('wpai_pre_has_valid_credentials_check', [self::class, 'declare_credentials_valid'], 1000, 1);

        foreach (array_keys(AIPKit_WP_AI_Client_Settings::providers()) as $connector_id) {
            add_filter('wpai_is_' . $connector_id . '_connector_configured', [self::class, 'declare_connector_configured'], 1000, 2);
        }
    }

    public static function customize_registry(\WP_Connector_Registry $registry): void
    {
        if (!AIPKit_WP_AI_Client_Settings::is_effectively_managed()) {
            return;
        }

        foreach (AIPKit_WP_AI_Client_Settings::providers() as $connector_id => $config) {
            try {
                if ($registry->is_registered($connector_id)) {
                    $registry->unregister($connector_id);
                }

                // AI Puffer keeps provider credentials in its own settings; exposing
                // them as connector credentials makes approval guards block internal calls.
                $auth = ['method' => 'none'];

                $registry->register($connector_id, [
                    'name' => ($config['name'] ?? ucwords($connector_id)) . ' via AI Puffer',
                    'description' => $config['description'] ?? __('AI provider managed by AI Puffer.', 'gpt3-ai-content-generator'),
                    'type' => 'ai_provider',
                    'authentication' => $auth,
                    'plugin' => [
                        'file' => plugin_basename(WPAICG_PLUGIN_DIR . 'gpt3-ai-content-generator.php'),
                        'is_active' => '__return_true',
                    ],
                ]);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    public static function maybe_render_banner(): void
    {
        if (!is_admin() || !\WPAICG\AIPKit_Role_Manager::user_can_manage_settings() || !self::is_connectors_screen()) {
            return;
        }

        if (!AIPKit_WP_AI_Client_Settings::is_supported()) {
            return;
        }

        $nonce = wp_create_nonce('aipkit_wp_ai_client_set_mode');
        $managed = AIPKit_WP_AI_Client_Settings::is_effectively_managed();
        if (!$managed && AIPKit_WP_AI_Client_Settings::is_banner_dismissed()) {
            return;
        }

        $configure_url = admin_url('admin.php?page=wpaicg&aipkit_module=settings');
        $learn_more_url = apply_filters('aipkit_wp_ai_client_learn_more_url', 'https://docs.aipower.org/wordpress-ai-connectors');
        $payload = [
            'nonce' => $nonce,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'settings' => $configure_url,
            'learnUrl' => $learn_more_url,
            'managed' => $managed,
            'title' => $managed
                ? __('Managed by AI Puffer.', 'gpt3-ai-content-generator')
                : __('AI Puffer can manage your connectors.', 'gpt3-ai-content-generator'),
            'sub' => $managed
                ? __('All WordPress AI requests run through AI Puffer.', 'gpt3-ai-content-generator')
                : __('Add usage logs, cost tracking, quotas, feature-level defaults, and more providers in one click.', 'gpt3-ai-content-generator'),
            'enable' => __('Enable', 'gpt3-ai-content-generator'),
            'dismiss' => __('Dismiss', 'gpt3-ai-content-generator'),
            'configure' => __('Configure', 'gpt3-ai-content-generator'),
            'stop' => __('Stop', 'gpt3-ai-content-generator'),
            'learnMore' => __('Learn more', 'gpt3-ai-content-generator'),
            'manageLead' => __('Need another provider? Add or edit providers in', 'gpt3-ai-content-generator'),
            'manageLink' => __('AI Puffer settings', 'gpt3-ai-content-generator'),
        ];
        ?>
        <style>
            .aipkit-wpai-banner {
                margin: 0 0 16px;
                padding: 12px 16px;
                display: flex;
                align-items: center;
                gap: 12px;
                background: #f0f4ff;
                border: 1px solid #d6deff;
                border-radius: 4px;
                color: #1e1e1e;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                font-size: 13px;
                line-height: 1.4;
            }
            .aipkit-wpai-banner.is-managed {
                background: #e8f8ef;
                border-color: #c3ecd3;
            }
            .aipkit-wpai-banner-icon {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                background: #2f5fff;
                color: #fff;
            }
            .aipkit-wpai-banner.is-managed .aipkit-wpai-banner-icon {
                background: #10b981;
            }
            .aipkit-wpai-banner-icon svg {
                width: 18px;
                height: 18px;
                display: block;
            }
            .aipkit-wpai-banner-text {
                flex: 1;
                min-width: 0;
            }
            .aipkit-wpai-banner-text strong {
                font-weight: 600;
            }
            .aipkit-wpai-banner-text span {
                color: #50575e;
            }
            .aipkit-wpai-banner-actions {
                display: flex;
                flex-shrink: 0;
                gap: 6px;
            }
            .aipkit-wpai-btn {
                appearance: none;
                border: 1px solid transparent;
                border-radius: 3px;
                padding: 4px 12px;
                font: inherit;
                font-size: 12px;
                font-weight: 500;
                cursor: pointer;
                text-decoration: none;
                transition: background 0.12s ease, border-color 0.12s ease;
            }
            .aipkit-wpai-btn-primary {
                background: #2f5fff;
                color: #fff;
            }
            .aipkit-wpai-btn-primary:hover {
                background: #2448cc;
                color: #fff;
            }
            .aipkit-wpai-banner.is-managed .aipkit-wpai-btn-primary {
                background: #10b981;
            }
            .aipkit-wpai-banner.is-managed .aipkit-wpai-btn-primary:hover {
                background: #0d9b6d;
            }
            .aipkit-wpai-btn-ghost {
                background: transparent;
                color: #50575e;
                border-color: transparent;
            }
            .aipkit-wpai-btn-ghost:hover {
                background: rgba(0, 0, 0, 0.04);
                color: #1e1e1e;
            }
            .aipkit-wpai-btn[disabled] {
                cursor: default;
                opacity: 0.5;
            }
        </style>
        <script>
        (function() {
            var D = <?php echo wp_json_encode($payload); ?>;

            function buildBanner() {
                var host = document.createElement('div');
                host.className = 'aipkit-wpai-banner' + (D.managed ? ' is-managed' : '');
                host.setAttribute('role', 'status');
                var iconSvg = D.managed
                    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="5 12 10 17 19 7"></polyline></svg>'
                    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>';

                host.innerHTML = [
                    '<span class="aipkit-wpai-banner-icon">', iconSvg, '</span>',
                    '<div class="aipkit-wpai-banner-text">',
                        '<strong></strong> <span class="aipkit-wpai-banner-sub"></span>',
                    '</div>',
                    '<div class="aipkit-wpai-banner-actions"></div>'
                ].join('');
                host.querySelector('strong').textContent = D.title;
                host.querySelector('.aipkit-wpai-banner-sub').textContent = D.sub;

                var actions = host.querySelector('.aipkit-wpai-banner-actions');
                function addAction(label, className, mode, href, newTab) {
                    var control = href ? document.createElement('a') : document.createElement('button');
                    control.className = 'aipkit-wpai-btn ' + className;
                    control.textContent = label;
                    if (href) {
                        control.href = href;
                        if (newTab) {
                            control.target = '_blank';
                            control.rel = 'noopener noreferrer';
                        }
                    } else {
                        control.type = 'button';
                        control.addEventListener('click', toggleMode(mode));
                    }
                    actions.appendChild(control);
                }

                if (D.managed) {
                    addAction(D.configure, 'aipkit-wpai-btn-primary', null, D.settings, false);
                    addAction(D.stop, 'aipkit-wpai-btn-ghost', 'observe', null, false);
                } else {
                    addAction(D.enable, 'aipkit-wpai-btn-primary', 'managed', null, false);
                    addAction(D.learnMore, 'aipkit-wpai-btn-ghost', null, D.learnUrl, true);
                    addAction(D.dismiss, 'aipkit-wpai-btn-ghost', 'dismiss', null, false);
                }

                return host;
            }

            function toggleMode(mode) {
                return function(event) {
                    var button = event.currentTarget;
                    button.disabled = true;
                    var body = new window.URLSearchParams();
                    body.set('action', 'aipkit_wp_ai_client_set_mode');
                    body.set('nonce', D.nonce);
                    body.set('mode', mode);
                    window.fetch(D.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: body.toString()
                    }).then(function(response) {
                        if (!response.ok) {
                            return response.json().then(function(payload) {
                                var message = payload && payload.data && payload.data.message ? payload.data.message : 'Unable to update connector management.';
                                throw new Error(message);
                            });
                        }
                        window.location.reload();
                    }).catch(function() {
                        button.disabled = false;
                    });
                };
            }

            function ensureBanner() {
                var page = document.querySelector('.connectors-page');
                var existing = document.querySelector('.aipkit-wpai-banner');
                if (existing && page && page.firstElementChild !== existing) {
                    page.insertBefore(existing, page.firstChild);
                    return;
                }
                if (existing) { return; }
                if (page) {
                    page.insertBefore(buildBanner(), page.firstChild);
                    return;
                }
                var header = document.querySelector('.boot-layout__stage header');
                if (header && header.parentNode) {
                    header.parentNode.insertBefore(buildBanner(), header.nextSibling);
                }
            }

            function tweakManagedPage() {
                if (!D.managed) { return; }
                var page = document.querySelector('.connectors-page');
                if (!page) { return; }

                var paragraphs = page.querySelectorAll('p');
                for (var i = 0; i < paragraphs.length; i++) {
                    var paragraph = paragraphs[i];
                    if (paragraph.dataset.aipkitReplaced) { continue; }
                    var text = (paragraph.textContent || '').toLowerCase();
                    if (text.indexOf('plugin directory') !== -1 || text.indexOf('search') !== -1) {
                        paragraph.innerHTML = '';
                        var link = document.createElement('a');
                        link.href = D.settings;
                        link.textContent = D.manageLink;
                        paragraph.appendChild(document.createTextNode(D.manageLead + ' '));
                        paragraph.appendChild(link);
                        paragraph.appendChild(document.createTextNode('.'));
                        paragraph.dataset.aipkitReplaced = '1';
                    }
                }

                var buttons = page.querySelectorAll('button');
                for (var j = 0; j < buttons.length; j++) {
                    var editButton = buttons[j];
                    if (editButton.dataset.aipkitIntercepted) { continue; }
                    if ((editButton.textContent || '').trim() === 'Edit') {
                        editButton.dataset.aipkitIntercepted = '1';
                        editButton.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            window.location.href = D.settings;
                        }, true);
                    }
                }
            }

            function run() {
                ensureBanner();
                tweakManagedPage();
            }

            var tries = 0;
            var interval = window.setInterval(function() {
                run();
                tries++;
                if (document.querySelector('.aipkit-wpai-banner') || tries > 40) {
                    window.clearInterval(interval);
                }
            }, 120);

            var app = document.getElementById('options-connectors-wp-admin-app') || document.getElementById('options-connectors-app');
            if (app && 'MutationObserver' in window) {
                new window.MutationObserver(run).observe(app, { childList: true, subtree: true });
            }
        })();
        </script>
        <?php
    }

    public static function ajax_set_mode(): void
    {
        check_ajax_referer('aipkit_wp_ai_client_set_mode', 'nonce');
        if (!\WPAICG\AIPKit_Role_Manager::user_can_manage_settings()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : AIPKit_WP_AI_Client_Settings::MODE_OBSERVE;
        if ($mode === 'dismiss') {
            AIPKit_WP_AI_Client_Settings::set_banner_dismissed(true);
            wp_send_json_success(['dismissed' => true]);
        }

        AIPKit_WP_AI_Client_Settings::set_mode($mode);
        AIPKit_WP_AI_Client_Settings::set_banner_dismissed(false);

        wp_send_json_success(['mode' => AIPKit_WP_AI_Client_Settings::get_mode()]);
    }

    public static function maybe_bridge_connector_key(string $option, $old_value, $value): void
    {
        self::bridge_option_to_provider($option, $value);
    }

    public static function maybe_bridge_added_connector_key(string $option, $value): void
    {
        self::bridge_option_to_provider($option, $value);
    }

    public static function mark_managed_connectors_connected(array $data): array
    {
        if (!AIPKit_WP_AI_Client_Settings::is_effectively_managed()) {
            return $data;
        }

        if (empty($data['connectors']) || !is_array($data['connectors'])) {
            return $data;
        }

        foreach (array_keys(AIPKit_WP_AI_Client_Settings::providers()) as $connector_id) {
            if (empty($data['connectors'][$connector_id]['authentication'])) {
                continue;
            }
            if (!AIPKit_WP_AI_Client_Settings::provider_has_credentials($connector_id)) {
                continue;
            }
            $data['connectors'][$connector_id]['authentication']['isConnected'] = true;
            $data['connectors'][$connector_id]['authentication']['keySource'] = 'none';
        }

        return $data;
    }

    public static function declare_credentials_present($has_credentials): bool
    {
        if (!AIPKit_WP_AI_Client_Settings::is_effectively_managed()) {
            return (bool) $has_credentials;
        }

        if ($has_credentials) {
            return true;
        }

        foreach (array_keys(AIPKit_WP_AI_Client_Settings::providers()) as $connector_id) {
            if (AIPKit_WP_AI_Client_Settings::provider_has_credentials($connector_id)) {
                return true;
            }
        }

        return false;
    }

    public static function declare_credentials_valid($valid)
    {
        if (!AIPKit_WP_AI_Client_Settings::is_effectively_managed()) {
            return $valid;
        }

        return true;
    }

    public static function declare_connector_configured($configured, array $connector_data = []): bool
    {
        if (!AIPKit_WP_AI_Client_Settings::is_effectively_managed()) {
            return (bool) $configured;
        }

        $hook_name = current_filter();
        if (!is_string($hook_name)) {
            return (bool) $configured;
        }

        $prefix = 'wpai_is_';
        $suffix = '_connector_configured';
        if (substr($hook_name, 0, strlen($prefix)) !== $prefix || substr($hook_name, -strlen($suffix)) !== $suffix) {
            return (bool) $configured;
        }

        $connector_id = (string) substr($hook_name, strlen($prefix), -strlen($suffix));
        if ($connector_id === '') {
            return (bool) $configured;
        }

        return AIPKit_WP_AI_Client_Settings::provider_has_credentials($connector_id)
            ? true
            : (bool) $configured;
    }

    private static function bridge_option_to_provider(string $option, $value): void
    {
        if (self::$syncing || !AIPKit_WP_AI_Client_Settings::is_effectively_managed()) {
            return;
        }

        foreach (array_keys(AIPKit_WP_AI_Client_Settings::providers()) as $connector_id) {
            if ($option !== AIPKit_WP_AI_Client_Settings::connector_option_name($connector_id)) {
                continue;
            }

            self::$syncing = true;
            AIPKit_WP_AI_Client_Settings::sync_connector_key_to_provider($connector_id, is_scalar($value) ? (string) $value : '');
            self::$syncing = false;
            return;
        }
    }

    private static function is_connectors_screen(): bool
    {
        global $pagenow;

        if ($pagenow === 'options-connectors.php') {
            return true;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->id) && strpos((string) $screen->id, 'options-connectors') !== false) {
            return true;
        }

        return false;
    }
}

class AIPKit_WP_AI_Client_Availability implements ProviderAvailabilityInterface
{
    private string $connector_id;

    public function __construct(string $connector_id)
    {
        $this->connector_id = sanitize_key($connector_id);
    }

    public function isConfigured(): bool
    {
        return AIPKit_WP_AI_Client_Settings::is_effectively_managed()
            && AIPKit_WP_AI_Client_Settings::provider_has_credentials($this->connector_id);
    }
}

/** Model-row normalization shared by connector and route catalogs. */
trait AIPKit_WP_AI_Client_Model_Rows_Trait
{
    private static function normalize_model_row($row): ?array
    {
        if (is_string($row)) {
            $id = trim($row);
            return $id === '' ? null : ['id' => $id, 'name' => $id];
        }
        if (!is_array($row)) {
            return null;
        }

        $id = '';
        foreach (['id', 'model', 'name'] as $key) {
            if (!empty($row[$key]) && is_string($row[$key])) {
                $id = trim($row[$key]);
                break;
            }
        }
        if ($id === '') {
            return null;
        }

        $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : $id;
        return ['id' => $id, 'name' => $name !== '' ? $name : $id];
    }
}

class AIPKit_WP_AI_Client_Model_Directory implements ModelMetadataDirectoryInterface
{
    use AIPKit_WP_AI_Client_Model_Rows_Trait;

    private string $connector_id;
    private string $internal_provider;

    public function __construct(string $connector_id, string $internal_provider)
    {
        $this->connector_id = sanitize_key($connector_id);
        $this->internal_provider = $internal_provider;
    }

    public function listModelMetadata(): array
    {
        return array_values($this->build_model_map());
    }

    public function hasModelMetadata(string $modelId): bool
    {
        $models = $this->build_model_map();
        return isset($models[$modelId]);
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        $models = $this->build_model_map();
        if (!isset($models[$modelId])) {
            throw new InvalidArgumentException(sprintf('Unknown AI Puffer model "%s" for provider "%s".', esc_html($modelId), esc_html($this->connector_id)));
        }

        return $models[$modelId];
    }

    private function build_model_map(): array
    {
        if (!class_exists(AIPKit_Providers::class)) {
            return [];
        }

        $rows = [];
        foreach ($this->flatten_model_rows($this->text_model_rows()) as $row) {
            $this->merge_model_row($rows, $row, [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()]);
        }
        foreach ($this->flatten_model_rows($this->image_model_rows()) as $row) {
            $this->merge_model_row($rows, $row, [CapabilityEnum::imageGeneration()]);
        }
        $rows = $this->sort_rows_by_preference($rows);

        $metadata = [];
        foreach ($rows as $id => $row) {
            $metadata[$id] = new ModelMetadata(
                $id,
                $row['name'] ?: $id,
                $this->unique_capabilities($row['capabilities']),
                $this->supported_options($row['capabilities'], $id)
            );
        }

        return $metadata;
    }

    private function sort_rows_by_preference(array $rows): array
    {
        $preferred = array_flip($this->preferred_model_ids());
        uksort($rows, static function (string $a, string $b) use ($preferred): int {
            $a_rank = $preferred[$a] ?? 9999;
            $b_rank = $preferred[$b] ?? 9999;
            if ($a_rank !== $b_rank) {
                return $a_rank <=> $b_rank;
            }

            return strcasecmp($a, $b);
        });

        return $rows;
    }

    private function preferred_model_ids(): array
    {
        $ids = [];
        if (class_exists(AIPKit_WP_AI_Client_Routes::class)) {
            foreach (AIPKit_WP_AI_Client_Routes::get_defaults() as $route) {
                $route_provider = isset($route['provider']) ? sanitize_key((string) $route['provider']) : '';
                if (AIPKit_WP_AI_Client_Settings::get_internal_provider($route_provider) !== $this->internal_provider) {
                    continue;
                }
                if (!empty($route['model']) && is_string($route['model'])) {
                    $ids[] = trim($route['model']);
                }
            }
        }

        $provider_data = AIPKit_Providers::get_provider_data($this->internal_provider);
        if (!empty($provider_data['model']) && is_string($provider_data['model'])) {
            $ids[] = trim($provider_data['model']);
        }

        if ($this->internal_provider === 'OpenAI') {
            $ids[] = AIPKit_Providers::get_default_openai_image_model();
        } elseif ($this->internal_provider === 'Google') {
            $ids[] = AIPKit_Providers::get_default_google_image_model();
        } elseif ($this->internal_provider === 'xAI') {
            $ids[] = AIPKit_Providers::get_default_xai_image_model();
        }

        return array_values(array_filter(array_unique($ids), static fn(string $id): bool => $id !== ''));
    }

    private function merge_model_row(array &$rows, $row, array $capabilities): void
    {
        $normalized = $this->normalize_model_row($row);
        if ($normalized === null) {
            return;
        }

        $id = $normalized['id'];
        if (!isset($rows[$id])) {
            $rows[$id] = [
                'name' => $normalized['name'],
                'capabilities' => [],
            ];
        }

        if ($rows[$id]['name'] === $id && $normalized['name'] !== $id) {
            $rows[$id]['name'] = $normalized['name'];
        }

        foreach ($capabilities as $capability) {
            $rows[$id]['capabilities'][] = $capability;
        }
    }

    private function flatten_model_rows(array $rows): array
    {
        $flat = [];
        foreach ($rows as $row) {
            if (is_array($row) && !isset($row['id']) && !isset($row['model']) && !isset($row['name'])) {
                foreach ($row as $nested_row) {
                    $flat[] = $nested_row;
                }
                continue;
            }
            $flat[] = $row;
        }

        return $flat;
    }

    private function text_model_rows(): array
    {
        switch ($this->internal_provider) {
            case 'AIPufferCloud':
                return AIPKit_WP_AI_Client_Settings::provider_has_credentials('aipuffercloud') ? \WPAICG\Cloud\Connection::models() : [];
            case 'OpenAI':
                return AIPKit_Providers::get_openai_models();
            case 'Google':
                return AIPKit_Providers::get_google_models();
            case 'Claude':
                return AIPKit_Providers::get_claude_models();
            case 'OpenRouter':
                return AIPKit_Providers::get_openrouter_models();
            case 'Azure':
                return AIPKit_Providers::get_azure_deployments();
            case 'DeepSeek':
                return AIPKit_Providers::get_deepseek_models();
            case 'xAI':
                return AIPKit_Providers::get_xai_models();
            case 'Ollama':
                return AIPKit_Providers::get_ollama_models();
            default:
                return [];
        }
    }

    private function image_model_rows(): array
    {
        switch ($this->internal_provider) {
            case 'AIPufferCloud':
                return AIPKit_WP_AI_Client_Settings::provider_has_credentials('aipuffercloud') ? \WPAICG\Cloud\Connection::media_models('image_generate') : [];
            case 'OpenAI':
                return AIPKit_Providers::get_openai_image_models();
            case 'Google':
                return AIPKit_Providers::get_google_image_models();
            case 'OpenRouter':
                return AIPKit_Providers::get_openrouter_image_models();
            case 'Azure':
                return AIPKit_Providers::get_azure_image_models();
            case 'xAI':
                return AIPKit_Providers::get_xai_image_models();
            default:
                return [];
        }
    }

    private function unique_capabilities(array $capabilities): array
    {
        $seen = [];
        $unique = [];
        foreach ($capabilities as $capability) {
            if (!is_object($capability)) {
                continue;
            }
            $value = (string) $capability->value;
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $unique[] = $capability;
        }

        return $unique;
    }

    private function supported_options(array $capabilities, string $model_id): array
    {
        $has_text = false;
        $has_image = false;
        foreach ($capabilities as $capability) {
            $value = is_object($capability) ? (string) $capability->value : '';
            if ($value === CapabilityEnum::TEXT_GENERATION) {
                $has_text = true;
            }
            if ($value === CapabilityEnum::IMAGE_GENERATION) {
                $has_image = true;
            }
        }

        $option_methods = ['customOptions'];
        if ($has_text) {
            $option_methods = array_merge($option_methods, [
                'candidateCount',
                'systemInstruction',
                'maxTokens',
                'temperature',
                'topP',
                'topK',
                'stopSequences',
                'presencePenalty',
                'frequencyPenalty',
                'outputMimeType',
                'outputSchema',
            ]);
        }
        if ($has_image) {
            $option_methods = array_merge($option_methods, [
                'candidateCount',
                'outputFileType',
                'outputMimeType',
                'outputMediaAspectRatio',
                'outputMediaOrientation',
            ]);
        }

        if ($this->internal_provider === 'AIPufferCloud') {
            $option_methods = $has_text ? ['candidateCount', 'systemInstruction', 'maxTokens'] : ['candidateCount'];
        }

        $supported = [];
        $seen = [];
        foreach ($option_methods as $method) {
            try {
                $option = OptionEnum::$method();
                if (isset($seen[$option->value])) {
                    continue;
                }
                $seen[$option->value] = true;
                $supported_values = ($has_text && $method === 'candidateCount') ? [1, 2, 3, 4] : null;
                if ($this->internal_provider === 'AIPufferCloud' && $has_image && $method === 'candidateCount') {
                    $supported_values = [1];
                }
                $supported[] = new SupportedOption($option, $supported_values);
            } catch (\Throwable $e) {
                continue;
            }
        }

        try {
            $input_modalities = [[ModalityEnum::text()]];
            if (($this->internal_provider === 'AIPufferCloud' ? \WPAICG\AIPKit_Providers::model_supports_image_input('AIPufferCloud', $model_id) : $this->provider_accepts_image_input())) {
                $input_modalities[] = [ModalityEnum::text(), ModalityEnum::image()];
            }
            $supported[] = new SupportedOption(OptionEnum::inputModalities(), $input_modalities);
        } catch (\Throwable $e) {
            // Older builds may not expose these enum values.
        }

        try {
            $output_modalities = [];
            if ($has_text) {
                $output_modalities[] = [ModalityEnum::text()];
            }
            if ($has_image) {
                $output_modalities[] = [ModalityEnum::image()];
            }
            if (!empty($output_modalities)) {
                $supported[] = new SupportedOption(OptionEnum::outputModalities(), $output_modalities);
            }
        } catch (\Throwable $e) {
            // Older builds may not expose these enum values.
        }

        return $supported;
    }

    private function provider_accepts_image_input(): bool
    {
        return in_array($this->internal_provider, ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'xAI'], true);
    }
}

class AIPKit_WP_AI_Client_Routes
{
    use AIPKit_WP_AI_Client_Model_Rows_Trait;

    public const PROVIDER_ID = 'aipuffer';

    public const ROUTE_TEXT = 'text_generation';
    public const ROUTE_FAST_TEXT = 'fast_text_generation';
    public const ROUTE_IMAGE = 'image_generation';

    public const MODEL_TEXT = 'aipuffer-default-text';
    public const MODEL_FAST_TEXT = 'aipuffer-fast-text';
    public const MODEL_IMAGE = 'aipuffer-default-image';

    private const ROUTE_MODELS = [
        self::MODEL_TEXT => self::ROUTE_TEXT,
        self::MODEL_FAST_TEXT => self::ROUTE_FAST_TEXT,
        self::MODEL_IMAGE => self::ROUTE_IMAGE,
    ];

    public static function get_defaults(): array
    {
        return self::inferred_defaults();
    }

    public static function get_route(string $route): array
    {
        $defaults = self::get_defaults();
        return $defaults[$route] ?? ['provider' => '', 'model' => ''];
    }

    public static function resolve_model_alias(string $model_id): ?array
    {
        $route = self::ROUTE_MODELS[$model_id] ?? '';
        if ($route === '') {
            return null;
        }

        $setting = self::get_route($route);
        return self::normalize_resolved_route($route, $setting);
    }

    public static function available_alias_models(): array
    {
        $models = [];
        foreach (self::ROUTE_MODELS as $alias => $route) {
            $resolved = self::resolve_model_alias($alias);
            if ($resolved === null || !self::route_has_credentials($resolved)) {
                continue;
            }

            $models[] = self::alias_metadata($alias, $route, $resolved);
        }

        return $models;
    }

    public static function get_alias_model_metadata(string $model_id): ModelMetadata
    {
        foreach (self::available_alias_models() as $model_metadata) {
            if ($model_metadata->getId() === $model_id) {
                return $model_metadata;
            }
        }

        throw new InvalidArgumentException(sprintf('Unknown AI Puffer route model "%s".', esc_html($model_id)));
    }

    public static function actual_model_metadata(array $resolved_route): ModelMetadata
    {
        $connector_id = sanitize_key((string) ($resolved_route['provider'] ?? ''));
        $model_id = sanitize_text_field((string) ($resolved_route['model'] ?? ''));
        $internal_provider = AIPKit_WP_AI_Client_Settings::get_internal_provider($connector_id) ?: '';

        if ($connector_id !== '' && $model_id !== '' && $internal_provider !== '') {
            try {
                $directory = new AIPKit_WP_AI_Client_Model_Directory($connector_id, $internal_provider);
                return $directory->getModelMetadata($model_id);
            } catch (\Throwable $e) {
                // Fall back to generic metadata below.
            }
        }

        $capabilities = $resolved_route['route'] === self::ROUTE_IMAGE
            ? [CapabilityEnum::imageGeneration()]
            : [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()];

        return new ModelMetadata(
            $model_id !== '' ? $model_id : 'aipuffer-model',
            $model_id !== '' ? $model_id : 'AI Puffer Model',
            $capabilities,
            self::generic_supported_options(
                $resolved_route['route'] === self::ROUTE_IMAGE,
                $resolved_route['route'] !== self::ROUTE_IMAGE && self::provider_accepts_image_input($internal_provider)
            )
        );
    }

    public static function actual_provider_metadata(array $resolved_route): ProviderMetadata
    {
        $connector_id = sanitize_key((string) ($resolved_route['provider'] ?? ''));
        $config = AIPKit_WP_AI_Client_Settings::get_provider_config($connector_id) ?: [];
        $is_keyless = !empty($config['keyless']);

        return new ProviderMetadata(
            $connector_id !== '' ? $connector_id : self::PROVIDER_ID,
            ($config['name'] ?? __('AI Puffer', 'gpt3-ai-content-generator')) . ' via AI Puffer',
            $is_keyless && empty($config['hosted']) ? ProviderTypeEnum::server() : ProviderTypeEnum::cloud(),
            !empty($config['credentials_url']) ? $config['credentials_url'] : null,
            $is_keyless ? null : RequestAuthenticationMethod::apiKey(),
            $config['description'] ?? __('AI provider managed by AI Puffer.', 'gpt3-ai-content-generator')
        );
    }

    public static function provider_has_any_route(): bool
    {
        foreach (array_keys(self::ROUTE_MODELS) as $alias) {
            $route = self::resolve_model_alias($alias);
            if ($route !== null && self::route_has_credentials($route)) {
                return true;
            }
        }

        return false;
    }

    public static function route_labels(): array
    {
        return [
            self::ROUTE_TEXT => __('Default text model', 'gpt3-ai-content-generator'),
            self::ROUTE_IMAGE => __('Default image model', 'gpt3-ai-content-generator'),
            self::ROUTE_FAST_TEXT => __('Fast text model', 'gpt3-ai-content-generator'),
        ];
    }

    public static function model_alias_accepts_image_input(string $model_id): bool
    {
        $resolved = self::resolve_model_alias($model_id);
        if ($resolved === null) {
            return false;
        }

        return ($resolved['internal_provider'] ?? '') === 'AIPufferCloud'
            ? \WPAICG\AIPKit_Providers::model_supports_image_input('AIPufferCloud', (string) ($resolved['model'] ?? ''))
            : self::provider_accepts_image_input((string) ($resolved['internal_provider'] ?? ''));
    }

    private static function normalize_resolved_route(string $route, array $setting): ?array
    {
        $provider = sanitize_key((string) ($setting['provider'] ?? ''));
        $model = sanitize_text_field((string) ($setting['model'] ?? ''));
        if ($provider === '' || $model === '') {
            return null;
        }

        $internal_provider = AIPKit_WP_AI_Client_Settings::get_internal_provider($provider);
        if (!$internal_provider) {
            return null;
        }

        return [
            'route' => sanitize_key($route),
            'provider' => $provider,
            'internal_provider' => $internal_provider,
            'model' => $model,
        ];
    }

    private static function route_has_credentials(array $resolved_route): bool
    {
        return AIPKit_WP_AI_Client_Settings::provider_has_credentials((string) ($resolved_route['provider'] ?? ''));
    }

    private static function alias_metadata(string $alias, string $route, array $resolved): ModelMetadata
    {
        $actual = self::actual_model_metadata($resolved);
        $labels = self::route_labels();
        $provider_config = AIPKit_WP_AI_Client_Settings::get_provider_config((string) ($resolved['provider'] ?? '')) ?: [];
        $provider_name = (string) ($provider_config['name'] ?? $resolved['provider'] ?? 'AI Puffer');
        $name = sprintf(
            '%s: %s / %s',
            $labels[$route] ?? __('AI Puffer route', 'gpt3-ai-content-generator'),
            $provider_name,
            $actual->getName()
        );

        $is_image_route = $route === self::ROUTE_IMAGE;
        $capabilities = $is_image_route
            ? [CapabilityEnum::imageGeneration()]
            : [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()];

        return new ModelMetadata(
            $alias,
            $name,
            $capabilities,
            ($resolved['internal_provider'] ?? '') === 'AIPufferCloud'
                ? $actual->getSupportedOptions()
                : self::generic_supported_options(
                    $is_image_route,
                    !$is_image_route && self::provider_accepts_image_input((string) ($resolved['internal_provider'] ?? ''))
                )
        );
    }

    private static function inferred_defaults(): array
    {
        $text = self::infer_text_route(false);
        $image = self::infer_image_route();
        $fast = self::infer_text_route(true);

        return [
            self::ROUTE_TEXT => $text,
            self::ROUTE_IMAGE => $image,
            self::ROUTE_FAST_TEXT => $fast ?: $text,
        ];
    }

    private static function infer_text_route(bool $prefer_fast): array
    {
        $current_internal = class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_current_provider() : 'OpenAI';
        $current_connector = self::connector_for_internal_provider($current_internal);
        $ordered_connectors = array_values(array_unique(array_filter(array_merge(
            [$current_connector],
            ['openai', 'google', 'openrouter', 'anthropic', 'deepseek', 'xai', 'ollama', 'azure']
        ))));

        if ($current_internal === 'AIPufferCloud') {
            $ordered_connectors = ['aipuffercloud'];
        }

        foreach ($ordered_connectors as $connector_id) {
            $config = AIPKit_WP_AI_Client_Settings::get_provider_config($connector_id);
            if (!$config) {
                continue;
            }
            if (!AIPKit_WP_AI_Client_Settings::provider_has_credentials($connector_id)) {
                continue;
            }
            $models = self::normalize_model_rows(self::model_rows_for_text((string) ($config['aipkit_provider'] ?? '')));
            if (empty($models)) {
                continue;
            }

            $provider_data = AIPKit_Providers::get_provider_data((string) ($config['aipkit_provider'] ?? ''));
            $preferred_model = isset($provider_data['model']) ? sanitize_text_field((string) $provider_data['model']) : '';
            if ($prefer_fast) {
                $preferred_model = self::choose_fast_model($models, $preferred_model);
            }
            $model = self::model_or_first($models, $preferred_model);
            if ($model !== '') {
                return ['provider' => $connector_id, 'model' => $model];
            }
        }

        return ['provider' => '', 'model' => ''];
    }

    private static function infer_image_route(): array
    {
        $current_internal = class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_current_provider() : 'OpenAI';
        $ordered_connectors = $current_internal === 'AIPufferCloud'
            ? ['aipuffercloud']
            : ['openai', 'google', 'xai', 'openrouter', 'azure'];
        foreach ($ordered_connectors as $connector_id) {
            $config = AIPKit_WP_AI_Client_Settings::get_provider_config($connector_id);
            if (!$config) {
                continue;
            }
            if (!AIPKit_WP_AI_Client_Settings::provider_has_credentials($connector_id)) {
                continue;
            }

            $models = self::normalize_model_rows(self::model_rows_for_image((string) ($config['aipkit_provider'] ?? '')));
            if (empty($models)) {
                continue;
            }

            switch ((string) ($config['aipkit_provider'] ?? '')) {
                case 'OpenAI':
                    $preferred_model = AIPKit_Providers::get_default_openai_image_model();
                    break;
                case 'Google':
                    $preferred_model = AIPKit_Providers::get_default_google_image_model();
                    break;
                case 'xAI':
                    $preferred_model = AIPKit_Providers::get_default_xai_image_model();
                    break;
                default:
                    $preferred_model = '';
                    break;
            }
            $model = self::model_or_first($models, $preferred_model);
            if ($model !== '') {
                return ['provider' => $connector_id, 'model' => $model];
            }
        }

        return ['provider' => '', 'model' => ''];
    }

    private static function connector_for_internal_provider(string $internal_provider): string
    {
        foreach (AIPKit_WP_AI_Client_Settings::providers() as $connector_id => $config) {
            if (($config['aipkit_provider'] ?? '') === $internal_provider) {
                return $connector_id;
            }
        }

        return '';
    }

    private static function model_or_first(array $models, string $preferred_model): string
    {
        if ($preferred_model !== '') {
            foreach ($models as $model) {
                if (($model['id'] ?? '') === $preferred_model) {
                    return $preferred_model;
                }
            }
        }

        return (string) ($models[0]['id'] ?? '');
    }

    private static function choose_fast_model(array $models, string $fallback): string
    {
        $patterns = ['fast', 'flash-lite', 'flash', 'mini', 'nano', 'lite', 'haiku'];
        foreach ($patterns as $pattern) {
            foreach ($models as $model) {
                $haystack = strtolower((string) ($model['id'] ?? '') . ' ' . (string) ($model['name'] ?? ''));
                if (strpos($haystack, $pattern) !== false) {
                    return (string) $model['id'];
                }
            }
        }

        return $fallback;
    }

    private static function model_rows_for_text(string $internal_provider): array
    {
        switch ($internal_provider) {
            case 'AIPufferCloud':
                return AIPKit_WP_AI_Client_Settings::provider_has_credentials('aipuffercloud') ? \WPAICG\Cloud\Connection::models() : [];
            case 'OpenAI':
                return AIPKit_Providers::get_openai_models();
            case 'Google':
                return AIPKit_Providers::get_google_models();
            case 'Claude':
                return AIPKit_Providers::get_claude_models();
            case 'OpenRouter':
                return AIPKit_Providers::get_openrouter_models();
            case 'Azure':
                return AIPKit_Providers::get_azure_deployments();
            case 'DeepSeek':
                return AIPKit_Providers::get_deepseek_models();
            case 'xAI':
                return AIPKit_Providers::get_xai_models();
            case 'Ollama':
                return AIPKit_Providers::get_ollama_models();
            default:
                return [];
        }
    }

    private static function model_rows_for_image(string $internal_provider): array
    {
        switch ($internal_provider) {
            case 'AIPufferCloud':
                return AIPKit_WP_AI_Client_Settings::provider_has_credentials('aipuffercloud') ? \WPAICG\Cloud\Connection::media_models('image_generate') : [];
            case 'OpenAI':
                return AIPKit_Providers::get_openai_image_models();
            case 'Google':
                return AIPKit_Providers::get_google_image_models();
            case 'OpenRouter':
                return AIPKit_Providers::get_openrouter_image_models();
            case 'Azure':
                return AIPKit_Providers::get_azure_image_models();
            case 'xAI':
                return AIPKit_Providers::get_xai_image_models();
            default:
                return [];
        }
    }

    private static function normalize_model_rows(array $rows): array
    {
        $normalized = [];
        foreach (self::flatten_model_rows($rows) as $row) {
            $model = self::normalize_model_row($row);
            if ($model === null) {
                continue;
            }
            $normalized[$model['id']] = $model;
        }

        return array_values($normalized);
    }

    private static function flatten_model_rows(array $rows): array
    {
        $flat = [];
        foreach ($rows as $row) {
            if (is_array($row) && !isset($row['id']) && !isset($row['model']) && !isset($row['name'])) {
                foreach ($row as $nested_row) {
                    $flat[] = $nested_row;
                }
                continue;
            }
            $flat[] = $row;
        }

        return $flat;
    }

    private static function provider_accepts_image_input(string $internal_provider): bool
    {
        return in_array($internal_provider, ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'xAI'], true);
    }

    private static function generic_supported_options(bool $image, bool $accepts_image_input = false): array
    {
        $methods = $image
            ? ['candidateCount', 'outputFileType', 'outputMimeType', 'outputMediaAspectRatio', 'outputMediaOrientation']
            : ['candidateCount', 'systemInstruction', 'maxTokens', 'temperature', 'topP', 'topK', 'stopSequences', 'presencePenalty', 'frequencyPenalty', 'outputMimeType', 'outputSchema'];

        $supported = [];
        foreach ($methods as $method) {
            try {
                $supported_values = (!$image && $method === 'candidateCount') ? [1, 2, 3, 4] : null;
                $supported[] = new SupportedOption(OptionEnum::$method(), $supported_values);
            } catch (\Throwable $e) {
                continue;
            }
        }

        try {
            $input_modalities = [[ModalityEnum::text()]];
            if ($accepts_image_input) {
                $input_modalities[] = [ModalityEnum::text(), ModalityEnum::image()];
            }
            $supported[] = new SupportedOption(OptionEnum::inputModalities(), $input_modalities);
        } catch (\Throwable $e) {
            // Older builds may not expose these enum values.
        }

        try {
            $supported[] = new SupportedOption(
                OptionEnum::outputModalities(),
                [[$image ? ModalityEnum::image() : ModalityEnum::text()]]
            );
        } catch (\Throwable $e) {
            // Older builds may not expose these enum values.
        }

        return $supported;
    }
}

class AIPKit_WP_AI_Client_Route_Model_Directory implements ModelMetadataDirectoryInterface
{
    public function listModelMetadata(): array
    {
        return AIPKit_WP_AI_Client_Routes::available_alias_models();
    }

    public function hasModelMetadata(string $modelId): bool
    {
        foreach ($this->listModelMetadata() as $model_metadata) {
            if ($model_metadata->getId() === $modelId) {
                return true;
            }
        }

        return false;
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        return AIPKit_WP_AI_Client_Routes::get_alias_model_metadata($modelId);
    }
}

class AIPKit_WP_AI_Client_Route_Availability implements ProviderAvailabilityInterface
{
    public function isConfigured(): bool
    {
        return AIPKit_WP_AI_Client_Settings::is_effectively_managed()
            && AIPKit_WP_AI_Client_Routes::provider_has_any_route();
    }
}

class AIPKit_WP_AI_Client_Approval_Compatibility
{
    public static function enforce(string $connector_id): void
    {
        $connector_id = sanitize_key($connector_id);
        if ($connector_id === '' || !self::is_connector_approval_active()) {
            return;
        }

        if (!class_exists('\WordPress\AI\Connector_Approval\Approvals_Store')) {
            return;
        }

        $store = new \WordPress\AI\Connector_Approval\Approvals_Store();
        $gateway = self::gateway_caller();
        $caller = self::identify_original_caller() ?: $gateway;

        $caller_is_gateway = $caller['basename'] === $gateway['basename'];
        $gateway_approved = $store->is_approved($gateway['basename'], $connector_id);
        $caller_approved = $caller_is_gateway || $store->is_approved($caller['basename'], $connector_id);

        if (!$gateway_approved) {
            $store->record_pending($gateway, $connector_id);
            if (!$caller_is_gateway && !$caller_approved) {
                $store->record_pending($caller, $connector_id);
            }
            self::throw_not_approved($connector_id, $gateway['basename']);
        }

        if (!$caller_approved) {
            $store->record_pending($caller, $connector_id);
            self::throw_not_approved($connector_id, $caller['basename']);
        }
    }

    private static function is_connector_approval_active(): bool
    {
        if (!class_exists('\WordPress\AI\Connector_Approval\Http_Guard')) {
            return false;
        }

        global $wp_filter;
        if (empty($wp_filter['pre_http_request']) || !is_object($wp_filter['pre_http_request'])) {
            return false;
        }

        foreach ((array) $wp_filter['pre_http_request']->callbacks as $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $function = $callback['function'] ?? null;
                if (
                    is_array($function)
                    && is_object($function[0] ?? null)
                    && $function[0] instanceof \WordPress\AI\Connector_Approval\Http_Guard
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function identify_original_caller(): ?array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace,PHPCompatibility.FunctionUse.ArgumentFunctionsUsage.DEBUG_BACKTRACE_IGNORE_ARGS
        foreach ($frames as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            if ($file === '' || self::should_skip_frame($file)) {
                continue;
            }

            $extension = self::classify_file($file);
            if ($extension !== null) {
                return $extension;
            }
        }

        return null;
    }

    private static function should_skip_frame(string $file): bool
    {
        $normalized = wp_normalize_path($file);
        if ($normalized === wp_normalize_path(__FILE__)
            || $normalized === wp_normalize_path(__DIR__ . '/wordpress-ai-settings.php')
        ) {
            return true;
        }

        $skip_substrings = [
            '/wp-includes/option.php',
            '/wp-includes/class-wp-hook.php',
            '/wp-includes/plugin.php',
            '/wp-includes/connectors.php',
            '/wp-includes/class-wp-connector-registry.php',
            '/wp-includes/http.php',
            '/wp-includes/class-wp-http.php',
            '/wp-includes/class-http.php',
            '/wp-includes/class-wp-http-requests-hooks.php',
            '/wp-includes/Requests/',
            '/wp-includes/class-requests.php',
            '/wp-includes/ai-client/',
            '/wp-includes/php-ai-client/',
            '/wp-content/plugins/ai/includes/Connector_Approval/',
        ];

        foreach ($skip_substrings as $needle) {
            if (strpos($normalized, wp_normalize_path($needle)) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function classify_file(string $file): ?array
    {
        $normalized = wp_normalize_path($file);
        $plugin_base_dir = wp_normalize_path(WP_PLUGIN_DIR);
        $plugin_segment = self::match_slug($normalized, $plugin_base_dir);
        if ($plugin_segment !== null) {
            $plugin_relative = ltrim((string) substr($normalized, strlen(rtrim($plugin_base_dir, '/') . '/')), '/');
            $basename = self::resolve_plugin_basename($plugin_segment, $plugin_relative);
            return [
                'type' => 'plugin',
                'basename' => $basename,
                'name' => self::plugin_name($basename),
            ];
        }

        if (defined('WPMU_PLUGIN_DIR')) {
            $mu_segment = self::match_slug($normalized, wp_normalize_path(WPMU_PLUGIN_DIR));
            if ($mu_segment !== null) {
                return [
                    'type' => 'mu-plugin',
                    'basename' => $mu_segment,
                    'name' => $mu_segment,
                ];
            }
        }

        foreach ((array) get_theme_roots() as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $theme_root = wp_normalize_path(trailingslashit(WP_CONTENT_DIR . $root));
            $slug = self::match_slug($normalized, $theme_root);
            if ($slug === null) {
                continue;
            }
            $theme = wp_get_theme($slug);
            $name = $theme->exists() ? (string) $theme->get('Name') : $slug;

            return [
                'type' => 'theme',
                'basename' => $slug,
                'name' => $name !== '' ? $name : $slug,
            ];
        }

        return null;
    }

    private static function match_slug(string $file, string $base_dir): ?string
    {
        $base_dir = rtrim($base_dir, '/') . '/';
        if (strncmp($file, $base_dir, strlen($base_dir)) !== 0) {
            return null;
        }

        $relative = (string) substr($file, strlen($base_dir));
        if ($relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        return $segments[0] ?? null;
    }

    private static function resolve_plugin_basename(string $segment, string $relative_path = ''): string
    {
        if (pathinfo($segment, PATHINFO_EXTENSION) !== '') {
            return $segment;
        }

        $plugins = self::load_plugins();
        if ($relative_path !== '' && isset($plugins[$relative_path])) {
            return $relative_path;
        }

        $prefix = $segment . '/';
        foreach ($plugins as $plugin_basename => $_plugin_data) {
            if (strncmp((string) $plugin_basename, $prefix, strlen($prefix)) === 0) {
                return (string) $plugin_basename;
            }
        }

        return $segment;
    }

    private static function gateway_caller(): array
    {
        $basename = plugin_basename(WPAICG_PLUGIN_DIR . 'gpt3-ai-content-generator.php');
        return [
            'type' => 'plugin',
            'basename' => $basename,
            'name' => self::plugin_name($basename),
        ];
    }

    private static function plugin_name(string $basename): string
    {
        $plugins = self::load_plugins();
        if (isset($plugins[$basename]['Name']) && $plugins[$basename]['Name'] !== '') {
            return (string) $plugins[$basename]['Name'];
        }

        return $basename;
    }

    private static function load_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }

    private static function throw_not_approved(string $connector_id, string $caller_basename): void
    {
        throw new ClientException(
            sprintf(
                /* translators: 1: Connector ID. 2: Calling plugin/theme basename. */
                esc_html__('The "%1$s" AI connector has not been approved for use by "%2$s".', 'gpt3-ai-content-generator'),
                esc_html($connector_id),
                esc_html($caller_basename)
            ),
            403
        );
    }
}

class AIPKit_WP_AI_Client_Gateway_Model implements ModelInterface, TextGenerationModelInterface, ImageGenerationModelInterface
{
    private ModelMetadata $model_metadata;
    private ProviderMetadata $provider_metadata;
    private ModelConfig $config;
    private string $internal_provider;

    public function __construct(ModelMetadata $model_metadata, ProviderMetadata $provider_metadata, string $internal_provider)
    {
        $this->model_metadata = $model_metadata;
        $this->provider_metadata = $provider_metadata;
        $this->internal_provider = $internal_provider;
        $this->config = new ModelConfig();
    }

    public function metadata(): ModelMetadata
    {
        return $this->model_metadata;
    }

    public function providerMetadata(): ProviderMetadata
    {
        return $this->provider_metadata;
    }

    public function setConfig(ModelConfig $config): void
    {
        $this->config = $config;
    }

    public function getConfig(): ModelConfig
    {
        return $this->config;
    }

    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        if (!class_exists(AIPKit_AI_Caller::class)) {
            throw new RuntimeException('AI Puffer text caller is unavailable.');
        }

        $translated = $this->messages_to_aipkit($prompt);
        $params = $this->text_params_from_config();
        if (!empty($translated['image_inputs'])) {
            $params['image_inputs'] = $translated['image_inputs'];
        }
        $system_instruction = $this->system_instruction_from_config();
        $estimated_usage = $this->estimated_text_usage($translated['messages'], $params, $system_instruction);
        $this->enforce_connector_approval();

        $caller = new AIPKit_AI_Caller();
        $candidate_count = $this->text_candidate_count();
        $texts = [];
        $combined_usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
        ];
        $raw_results = [];
        $request_payload_log = null;

        for ($index = 0; $index < $candidate_count; $index++) {
            $result = $caller->make_standard_call(
                $this->internal_provider,
                $this->model_metadata->getId(),
                $translated['messages'],
                $params,
                $system_instruction
            );

            if (is_wp_error($result)) {
                throw new RuntimeException(esc_html($result->get_error_message()));
            }

            $text = isset($result['content']) ? (string) $result['content'] : '';
            $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];

            $texts[] = $text;
            $combined_usage = $this->combine_text_usage($combined_usage, $usage);
            $raw_results[] = $this->sanitize_raw_result($result);
            if ($request_payload_log === null && isset($result['request_payload_log'])) {
                $request_payload_log = $result['request_payload_log'];
            }
        }

        if ($combined_usage['total_tokens'] <= 0) {
            $combined_usage['total_tokens'] = (int) $estimated_usage['total_tokens'] * $candidate_count;
        }

        $this->record_usage($combined_usage, 'text_generation', [
            'usage_data' => $combined_usage,
            'fallback_units' => (int) $estimated_usage['total_tokens'] * $candidate_count,
        ]);
        $this->log_gateway_call($translated['messages'], implode("\n\n", $texts), $combined_usage, 'text_generation', $request_payload_log);

        return $this->build_text_result($texts, $combined_usage, ['responses' => $raw_results]);
    }

    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        if (!class_exists(AIPKit_Image_Manager::class)) {
            throw new RuntimeException('AI Puffer image manager is unavailable.');
        }

        $text = $this->prompt_text($prompt);
        if ($text === '') {
            throw new RuntimeException('Image generation requires a text prompt.');
        }

        $options = $this->image_options_from_config();
        $options['provider'] = $this->internal_provider;
        $options['model'] = $this->model_metadata->getId();
        $requested_image_count = max(1, (int) ($options['n'] ?? 1));
        $estimated_usage = [
            'unit_count' => $requested_image_count,
            'image_count' => $requested_image_count,
            'total_units' => $requested_image_count,
        ];
        $estimated_fallback_units = $requested_image_count * AIPKit_Image_Manager::TOKENS_PER_IMAGE;
        $this->enforce_connector_approval();

        $manager = new AIPKit_Image_Manager();
        $result = $manager->generate_image($text, $options, get_current_user_id() ?: null);
        if (is_wp_error($result)) {
            throw new RuntimeException(esc_html($result->get_error_message()));
        }

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $generated_image_count = is_array($result['images'] ?? null) ? count($result['images']) : $requested_image_count;
        $this->record_usage($usage, 'image_generation', [
            'usage_data' => array_merge($usage, [
                'unit_count' => max(1, $generated_image_count),
                'image_count' => max(1, $generated_image_count),
            ]),
            'fallback_units' => max(1, $generated_image_count) * AIPKit_Image_Manager::TOKENS_PER_IMAGE,
        ]);
        $this->log_gateway_call([['role' => 'user', 'content' => $text]], '[image generated]', $usage, 'image_generation', $options);

        return $this->build_image_result($result, $usage);
    }

    private function messages_to_aipkit(array $prompt): array
    {
        $messages = [];
        $image_inputs = [];

        foreach ($prompt as $message) {
            if (!$message instanceof Message) {
                continue;
            }

            $role = $message->getRole()->isModel() ? 'assistant' : 'user';
            $text = '';
            foreach ($message->getParts() as $part) {
                if (!$part instanceof MessagePart) {
                    continue;
                }
                if ($part->getText() !== null) {
                    $text .= (string) $part->getText();
                    continue;
                }
                $file = $part->getFile();
                if ($file instanceof File && $file->isImage()) {
                    $image_input = $this->file_to_image_input($file);
                    if ($image_input !== null) {
                        $image_inputs[] = $image_input;
                    }
                }
            }

            if ($text === '' && !empty($image_inputs) && $role === 'user') {
                $text = '[image attachment]';
            }
            if ($text !== '') {
                $messages[] = [
                    'role' => $role,
                    'content' => $text,
                ];
            }
        }

        return [
            'messages' => $messages,
            'image_inputs' => $image_inputs,
        ];
    }

    private function file_to_image_input(File $file): ?array
    {
        $base64 = $file->getBase64Data();
        if ($base64 === null || $base64 === '') {
            return null;
        }

        return [
            'type' => $file->getMimeType(),
            'base64' => $base64,
        ];
    }

    private function prompt_text(array $prompt): string
    {
        $chunks = [];
        foreach ($prompt as $message) {
            if (!$message instanceof Message) {
                continue;
            }
            foreach ($message->getParts() as $part) {
                if ($part instanceof MessagePart && $part->getText() !== null) {
                    $chunks[] = (string) $part->getText();
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function text_params_from_config(): array
    {
        $params = [];
        $map = [
            'temperature' => $this->config->getTemperature(),
            'top_p' => $this->config->getTopP(),
            'top_k' => $this->config->getTopK(),
            'presence_penalty' => $this->config->getPresencePenalty(),
            'frequency_penalty' => $this->config->getFrequencyPenalty(),
            'max_completion_tokens' => $this->config->getMaxTokens(),
        ];

        foreach ($map as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        $stop = $this->config->getStopSequences();
        if (is_array($stop) && !empty($stop)) {
            $params['stop_sequences'] = array_values(array_filter(array_map('strval', $stop)));
            $params['stop'] = implode("\n", $params['stop_sequences']);
        }

        $schema = $this->config->getOutputSchema();
        if (is_array($schema) && !empty($schema)) {
            $params['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'wp_ai_client_response',
                    'schema' => $schema,
                ],
            ];
        } elseif ($this->config->getOutputMimeType() === 'application/json') {
            $params['response_format'] = ['type' => 'json_object'];
        }

        return $params;
    }

    private function text_candidate_count(): int
    {
        $candidate_count = $this->config->getCandidateCount();
        if ($candidate_count === null) {
            return 1;
        }

        return max(1, min(4, (int) $candidate_count));
    }

    private function system_instruction_from_config(): ?string
    {
        $instruction = trim((string) ($this->config->getSystemInstruction() ?? ''));
        $schema = $this->config->getOutputSchema();
        if (is_array($schema) && !empty($schema)) {
            $schema_json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $json_instruction = 'Respond only with valid JSON that matches this JSON Schema: ' . $schema_json;
            $instruction = $instruction !== '' ? $instruction . "\n\n" . $json_instruction : $json_instruction;
        } elseif ($this->config->getOutputMimeType() === 'application/json') {
            $json_instruction = 'Respond only with valid JSON.';
            $instruction = $instruction !== '' ? $instruction . "\n\n" . $json_instruction : $json_instruction;
        }

        return $instruction !== '' ? $instruction : null;
    }

    private function image_options_from_config(): array
    {
        $options = [
            'n' => max(1, (int) ($this->config->getCandidateCount() ?? 1)),
        ];

        $orientation = $this->config->getOutputMediaOrientation();
        if ($orientation) {
            switch ((string) $orientation->value) {
                case 'landscape':
                    $options['size'] = '1792x1024';
                    break;
                case 'portrait':
                    $options['size'] = '1024x1792';
                    break;
                default:
                    $options['size'] = '1024x1024';
                    break;
            }
        }

        $aspect_ratio = $this->config->getOutputMediaAspectRatio();
        if (is_string($aspect_ratio) && $aspect_ratio !== '') {
            $options['aspect_ratio'] = $aspect_ratio;
        }

        $mime_type = $this->config->getOutputMimeType();
        if ($mime_type === 'image/jpeg') {
            $options['output_format'] = 'jpeg';
        } elseif ($mime_type === 'image/webp') {
            $options['output_format'] = 'webp';
        } elseif ($mime_type === 'image/png') {
            $options['output_format'] = 'png';
        }

        return $options;
    }

    private function build_text_result(array $texts, array $usage, array $raw_result): GenerativeAiResult
    {
        $candidates = [];
        foreach ($texts as $text) {
            $candidates[] = new Candidate(
                new ModelMessage([new MessagePart((string) $text)]),
                FinishReasonEnum::stop()
            );
        }

        return new GenerativeAiResult(
            wp_generate_uuid4(),
            $candidates,
            $this->token_usage($usage),
            $this->provider_metadata,
            $this->model_metadata,
            [
                'aipkit_provider' => $this->internal_provider,
                'aipkit_raw' => $raw_result,
            ]
        );
    }

    private function build_image_result(array $result, array $usage): GenerativeAiResult
    {
        $candidates = [];
        foreach (($result['images'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }

            $file = null;
            if (!empty($image['b64_json']) && is_string($image['b64_json'])) {
                $file = new File($image['b64_json'], $image['mime_type'] ?? ($this->config->getOutputMimeType() ?: 'image/png'));
            } elseif (!empty($image['url']) && is_string($image['url'])) {
                $file = new File($image['url'], $this->config->getOutputMimeType() ?: 'image/png');
            } elseif (!empty($image['media_library_url']) && is_string($image['media_library_url'])) {
                $file = new File($image['media_library_url'], $this->config->getOutputMimeType() ?: 'image/png');
            }

            if (!$file instanceof File) {
                continue;
            }

            $candidates[] = new Candidate(
                new ModelMessage([new MessagePart($file)]),
                FinishReasonEnum::stop()
            );
        }

        if (empty($candidates)) {
            throw new RuntimeException('AI Puffer did not return any generated images.');
        }

        return new GenerativeAiResult(
            wp_generate_uuid4(),
            $candidates,
            $this->token_usage($usage),
            $this->provider_metadata,
            $this->model_metadata,
            [
                'aipkit_provider' => $this->internal_provider,
                'aipkit_raw' => $this->sanitize_raw_result($result),
            ]
        );
    }

    private function token_usage(array $usage): TokenUsage
    {
        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($input + $output));

        return new TokenUsage(max(0, $input), max(0, $output), max(0, $total));
    }

    private function combine_text_usage(array $combined, array $usage): array
    {
        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? $usage['promptTokenCount'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? $usage['candidatesTokenCount'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? $usage['totalTokenCount'] ?? ($input + $output));

        $combined['input_tokens'] = max(0, (int) ($combined['input_tokens'] ?? 0)) + max(0, $input);
        $combined['output_tokens'] = max(0, (int) ($combined['output_tokens'] ?? 0)) + max(0, $output);
        $combined['total_tokens'] = max(0, (int) ($combined['total_tokens'] ?? 0)) + max(0, $total);

        return $combined;
    }

    private function enforce_connector_approval(): void
    {
        if (!AIPKit_WP_AI_Client_Settings::provider_has_credentials($this->connector_id())) {
            throw new RuntimeException('Connect the selected AI provider in AI Puffer Settings.');
        }
        if (!class_exists(AIPKit_WP_AI_Client_Approval_Compatibility::class)) {
            return;
        }

        AIPKit_WP_AI_Client_Approval_Compatibility::enforce($this->connector_id());
    }

    private function record_usage(array $usage, string $operation, array $usage_context = []): void
    {
        if (!class_exists(AIPKit_Token_Manager::class)) {
            return;
        }

        try {
            $fallback_units = isset($usage_context['fallback_units']) && is_numeric($usage_context['fallback_units'])
                ? max(0, (int) $usage_context['fallback_units'])
                : 0;
            $tokens = (int) ($usage['total_tokens'] ?? $usage['totalTokenCount'] ?? 0);
            if ($tokens <= 0) {
                $tokens = $fallback_units;
            }
            if ($tokens <= 0) {
                return;
            }

            $usage_data = isset($usage_context['usage_data']) && is_array($usage_context['usage_data'])
                ? $usage_context['usage_data']
                : $usage;
            $manager = new AIPKit_Token_Manager();
            $user_id = $this->current_user_id();
            $manager->record_token_usage(
                $user_id,
                $this->gateway_session_id($user_id),
                null,
                $tokens,
                'wp_ai_client',
                $this->wp_ai_client_usage_context($operation, $usage_data, $fallback_units)
            );
        } catch (\Throwable $e) {
            // Usage recording must not break generation.
        }
    }

    private function wp_ai_client_usage_context(string $operation, array $usage_data, int $fallback_units): array
    {
        $pricing_module = $operation === 'image_generation' ? 'image_generator' : 'chat';
        $pricing_operation = $operation === 'image_generation' ? 'generate' : 'chat';

        return [
            'provider' => $this->pricing_provider_key(),
            'model' => $this->model_metadata->getId(),
            'operation' => $operation,
            'pricing_module' => $pricing_module,
            'pricing_operation' => $pricing_operation,
            'usage_data' => $usage_data,
            'fallback_units' => max(0, $fallback_units),
        ];
    }

    private function pricing_provider_key(): string
    {
        switch ($this->internal_provider) {
            case 'OpenAI':
                return 'openai';
            case 'Google':
                return 'google';
            case 'Claude':
                return 'claude';
            case 'OpenRouter':
                return 'openrouter';
            case 'Azure':
                return 'azure';
            case 'DeepSeek':
                return 'deepseek';
            case 'xAI':
                return 'xai';
            case 'Ollama':
                return 'ollama';
            default:
                return sanitize_key($this->internal_provider);
        }
    }

    private function connector_id(): string
    {
        switch ($this->internal_provider) {
            case 'OpenAI':
                return 'openai';
            case 'Google':
                return 'google';
            case 'Claude':
                return 'anthropic';
            case 'OpenRouter':
                return 'openrouter';
            case 'Azure':
                return 'azure';
            case 'DeepSeek':
                return 'deepseek';
            case 'xAI':
                return 'xai';
            case 'Ollama':
                return 'ollama';
            default:
                return sanitize_key($this->internal_provider);
        }
    }

    private function estimated_text_usage(array $messages, array $params, ?string $system_instruction = null): array
    {
        $input_tokens = $this->estimated_text_token_count($messages, $system_instruction);
        $output_tokens = isset($params['max_completion_tokens']) && is_numeric($params['max_completion_tokens'])
            ? max(1, (int) $params['max_completion_tokens'])
            : 1000;

        return [
            'input_tokens' => $input_tokens,
            'output_tokens' => $output_tokens,
            'total_tokens' => $input_tokens + $output_tokens,
        ];
    }

    private function estimated_text_token_count(array $messages, ?string $system_instruction = null): int
    {
        $chunks = [];
        if (is_string($system_instruction) && $system_instruction !== '') {
            $chunks[] = $system_instruction;
        }
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_scalar($content)) {
                $chunks[] = (string) $content;
            }
        }

        $text = trim(wp_strip_all_tags(implode("\n", $chunks)));
        if ($text === '') {
            return 1;
        }

        $char_estimate = (int) ceil(strlen($text) / 4);
        $word_estimate = (int) ceil(str_word_count($text) * 1.35);

        return max(1, $char_estimate, $word_estimate);
    }

    private function current_user_id(): ?int
    {
        $user_id = get_current_user_id();
        return $user_id > 0 ? $user_id : null;
    }

    private function gateway_session_id(?int $user_id): ?string
    {
        if ($user_id) {
            return null;
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip === '') {
            return 'wp-ai-client-system';
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $fingerprint = $ip . '|' . $user_agent;

        return 'wp-ai-client-' . substr(hash_hmac('sha256', $fingerprint, wp_salt('auth')), 0, 40);
    }

    private function log_gateway_call(array $messages, string $response, array $usage, string $operation, $request_payload = null): void
    {
        if (!class_exists('\WPAICG\Chat\Storage\LogStorage')) {
            return;
        }

        try {
            $user_id = $this->current_user_id();
            $role = null;
            if ($user_id) {
                $user = get_userdata($user_id);
                $role = $user && !empty($user->roles) ? implode(',', (array) $user->roles) : null;
            }

            $storage = new \WPAICG\Chat\Storage\LogStorage();
            $conversation_uuid = wp_generate_uuid4();
            $session_id = $this->gateway_session_id($user_id);
            $timestamp = time();
            $common_log_data = [
                'bot_id' => null,
                'user_id' => $user_id,
                'session_id' => $session_id,
                'conversation_uuid' => $conversation_uuid,
                'module' => 'wp_ai_client',
                'is_guest' => $user_id ? 0 : 1,
                'user_wp_role' => $role,
                'ip_address' => null,
                'timestamp' => $timestamp,
            ];

            $storage->log_message(array_merge($common_log_data, [
                'message_role' => 'user',
                'message_content' => $this->log_prompt_content($messages),
                'message_id' => 'aipkit-wp-ai-client-user-' . wp_generate_uuid4(),
            ]));

            $storage->log_message(array_merge($common_log_data, [
                'message_role' => 'bot',
                'message_content' => $response,
                'ai_provider' => $this->internal_provider,
                'ai_model' => $this->model_metadata->getId(),
                'usage' => $usage,
                'message_id' => 'aipkit-wp-ai-client-bot-' . wp_generate_uuid4(),
                'request_payload' => [
                    'operation' => $operation,
                    'messages' => $messages,
                    'payload' => $request_payload,
                ],
                'response_data' => [
                    'type' => $operation,
                    'source' => 'wp_ai_client',
                ],
            ]));
        } catch (\Throwable $e) {
            // Logging must not break generation.
        }
    }

    private function log_prompt_content(array $messages): string
    {
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_scalar($content)) {
                $content = trim((string) $content);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_scalar($content)) {
                $content = trim((string) $content);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return '[WP AI Client request]';
    }

    private function sanitize_raw_result(array $result): array
    {
        unset($result['request_payload_log']['payload_sent']);
        return $result;
    }
}

abstract class AIPKit_WP_AI_Client_Provider_Base extends AbstractProvider
{
    protected static string $connector_id = '';

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        $config = AIPKit_WP_AI_Client_Settings::get_provider_config(static::$connector_id);
        $internal_provider = $config['aipkit_provider'] ?? '';

        return new AIPKit_WP_AI_Client_Gateway_Model($modelMetadata, $providerMetadata, $internal_provider);
    }

    protected static function createProviderMetadata(): ProviderMetadata
    {
        $config = AIPKit_WP_AI_Client_Settings::get_provider_config(static::$connector_id) ?: [];
        $is_keyless = !empty($config['keyless']);

        return new ProviderMetadata(
            static::$connector_id,
            ($config['name'] ?? ucwords(static::$connector_id)) . ' via AI Puffer',
            $is_keyless && empty($config['hosted']) ? ProviderTypeEnum::server() : ProviderTypeEnum::cloud(),
            !empty($config['credentials_url']) ? $config['credentials_url'] : null,
            $is_keyless ? null : RequestAuthenticationMethod::apiKey(),
            $config['description'] ?? 'AI provider managed by AI Puffer.'
        );
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new AIPKit_WP_AI_Client_Availability(static::$connector_id);
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        $config = AIPKit_WP_AI_Client_Settings::get_provider_config(static::$connector_id) ?: [];
        return new AIPKit_WP_AI_Client_Model_Directory(static::$connector_id, $config['aipkit_provider'] ?? '');
    }
}

class AIPKit_WP_AI_Client_Provider_AIPuffer extends AbstractProvider
{
    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        $resolved_route = AIPKit_WP_AI_Client_Routes::resolve_model_alias($modelMetadata->getId());
        if ($resolved_route === null) {
            throw new \WordPress\AiClient\Common\Exception\RuntimeException('AI Puffer route is not configured.');
        }

        return new AIPKit_WP_AI_Client_Gateway_Model(
            AIPKit_WP_AI_Client_Routes::actual_model_metadata($resolved_route),
            AIPKit_WP_AI_Client_Routes::actual_provider_metadata($resolved_route),
            (string) ($resolved_route['internal_provider'] ?? '')
        );
    }

    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            AIPKit_WP_AI_Client_Routes::PROVIDER_ID,
            __('AI Puffer Defaults', 'gpt3-ai-content-generator'),
            ProviderTypeEnum::server(),
            null,
            null,
            __('Feature-level defaults managed by AI Puffer.', 'gpt3-ai-content-generator')
        );
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new AIPKit_WP_AI_Client_Route_Availability();
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new AIPKit_WP_AI_Client_Route_Model_Directory();
    }
}

class AIPKit_WP_AI_Client_Provider_AIPufferCloud extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'aipuffercloud';
}

class AIPKit_WP_AI_Client_Provider_OpenAI extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'openai';
}

class AIPKit_WP_AI_Client_Provider_Google extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'google';
}

class AIPKit_WP_AI_Client_Provider_Anthropic extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'anthropic';
}

class AIPKit_WP_AI_Client_Provider_OpenRouter extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'openrouter';
}

class AIPKit_WP_AI_Client_Provider_Azure extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'azure';
}

class AIPKit_WP_AI_Client_Provider_DeepSeek extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'deepseek';
}

class AIPKit_WP_AI_Client_Provider_xAI extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'xai';
}

class AIPKit_WP_AI_Client_Provider_Ollama extends AIPKit_WP_AI_Client_Provider_Base
{
    protected static string $connector_id = 'ollama';
}

class AIPKit_WP_AI_Client_Gateway
{
    private const PROVIDER_CLASSES = [
        'aipuffer' => AIPKit_WP_AI_Client_Provider_AIPuffer::class,
        'aipuffercloud' => AIPKit_WP_AI_Client_Provider_AIPufferCloud::class,
        'openai' => AIPKit_WP_AI_Client_Provider_OpenAI::class,
        'google' => AIPKit_WP_AI_Client_Provider_Google::class,
        'anthropic' => AIPKit_WP_AI_Client_Provider_Anthropic::class,
        'openrouter' => AIPKit_WP_AI_Client_Provider_OpenRouter::class,
        'azure' => AIPKit_WP_AI_Client_Provider_Azure::class,
        'deepseek' => AIPKit_WP_AI_Client_Provider_DeepSeek::class,
        'xai' => AIPKit_WP_AI_Client_Provider_xAI::class,
        'ollama' => AIPKit_WP_AI_Client_Provider_Ollama::class,
    ];

    public static function register_hooks(): void
    {
        add_action('init', [self::class, 'register_providers'], 1000);
    }

    public static function register_providers(): void
    {
        if (!AIPKit_WP_AI_Client_Settings::is_effectively_managed()) {
            return;
        }

        try {
            $registry = AiClient::defaultRegistry();
        } catch (\Throwable $e) {
            return;
        }

        foreach (self::PROVIDER_CLASSES as $provider_id => $class_name) {
            if (!class_exists($class_name)) {
                continue;
            }

            try {
                self::replace_provider_if_registered($registry, $provider_id, $class_name);
                $registry->registerProvider($class_name);
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    private static function replace_provider_if_registered($registry, string $provider_id, string $class_name): void
    {
        if (!method_exists($registry, 'hasProvider') || !$registry->hasProvider($provider_id)) {
            return;
        }

        try {
            $existing_class = $registry->getProviderClassName($provider_id);
        } catch (\Throwable $e) {
            return;
        }

        if ($existing_class === $class_name) {
            return;
        }

        try {
            $reflection = new \ReflectionObject($registry);
            foreach (['registeredIdsToClassNames', 'registeredClassNamesToIds', 'providerAuthenticationInstances'] as $property_name) {
                if (!$reflection->hasProperty($property_name)) {
                    return;
                }
            }

            $ids_property = $reflection->getProperty('registeredIdsToClassNames');
            $classes_property = $reflection->getProperty('registeredClassNamesToIds');
            $auth_property = $reflection->getProperty('providerAuthenticationInstances');
            if (PHP_VERSION_ID < 80100) {
                $ids_property->setAccessible(true);
                $classes_property->setAccessible(true);
                $auth_property->setAccessible(true);
            }

            $ids = $ids_property->getValue($registry);
            $classes = $classes_property->getValue($registry);
            $auth = $auth_property->getValue($registry);

            unset($ids[$provider_id], $classes[$existing_class], $auth[$existing_class]);

            $ids_property->setValue($registry, $ids);
            $classes_property->setValue($registry, $classes);
            $auth_property->setValue($registry, $auth);
        } catch (\Throwable $e) {
            // If Core internals change, registerProvider() can still override by ID.
        }
    }
}

class AIPKit_WP_AI_Client_WordPress_AI_Compatibility
{
    private static bool $registered = false;

    public static function register_hooks(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_filter('wpai_preferred_text_models', [self::class, 'prefer_default_text_model'], 1000);
        add_filter('wpai_preferred_image_models', [self::class, 'prefer_default_image_model'], 1000);
        add_filter('wpai_preferred_vision_models', [self::class, 'prefer_default_vision_model'], 1000);
    }

    public static function prefer_default_text_model(array $preferred_models): array
    {
        return self::prepend_route_alias($preferred_models, AIPKit_WP_AI_Client_Routes::MODEL_TEXT);
    }

    public static function prefer_default_image_model(array $preferred_models): array
    {
        return self::prepend_route_alias($preferred_models, AIPKit_WP_AI_Client_Routes::MODEL_IMAGE);
    }

    public static function prefer_default_vision_model(array $preferred_models): array
    {
        if (!self::should_filter() || !AIPKit_WP_AI_Client_Routes::model_alias_accepts_image_input(AIPKit_WP_AI_Client_Routes::MODEL_TEXT)) {
            return $preferred_models;
        }

        return self::prepend_route_alias($preferred_models, AIPKit_WP_AI_Client_Routes::MODEL_TEXT);
    }

    private static function prepend_route_alias(array $preferred_models, string $model_alias): array
    {
        if (!self::should_filter() || !self::route_alias_is_available($model_alias)) {
            return $preferred_models;
        }

        return self::prepend_preferences($preferred_models, [
            [AIPKit_WP_AI_Client_Routes::PROVIDER_ID, $model_alias],
        ]);
    }

    private static function should_filter(): bool
    {
        return class_exists(AIPKit_WP_AI_Client_Settings::class)
            && class_exists(AIPKit_WP_AI_Client_Routes::class)
            && AIPKit_WP_AI_Client_Settings::is_effectively_managed();
    }

    private static function route_alias_is_available(string $model_alias): bool
    {
        try {
            AIPKit_WP_AI_Client_Routes::get_alias_model_metadata($model_alias);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function prepend_preferences(array $preferred_models, array $new_preferences): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($new_preferences, $preferred_models) as $preference) {
            $key = self::preference_key($preference);
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }
            $merged[] = $preference;
        }

        return $merged;
    }

    private static function preference_key($preference): string
    {
        if (is_array($preference) && count($preference) === 2) {
            $provider = sanitize_key((string) $preference[0]);
            $model = sanitize_text_field((string) $preference[1]);

            return $provider . '|' . $model;
        }

        if (is_string($preference)) {
            return 'model|' . sanitize_text_field($preference);
        }

        if (is_object($preference)) {
            return 'object|' . spl_object_hash($preference);
        }

        return '';
    }
}

class AIPKit_WP_AI_Client_Integration
{
    private static bool $registered = false;

    public static function register_hooks(): void
    {
        if (!class_exists(AIPKit_WP_AI_Client_Settings::class)
            || !AIPKit_WP_AI_Client_Settings::is_supported()
            || !class_exists(AIPKit_WP_AI_Client_Connectors::class)
        ) {
            return;
        }

        if (self::$registered) {
            return;
        }
        self::$registered = true;

        AIPKit_WP_AI_Client_Connectors::register_hooks();
        if (class_exists(AIPKit_WP_AI_Client_Gateway::class)) {
            AIPKit_WP_AI_Client_Gateway::register_hooks();
        }
        if (class_exists(AIPKit_WP_AI_Client_WordPress_AI_Compatibility::class)) {
            AIPKit_WP_AI_Client_WordPress_AI_Compatibility::register_hooks();
        }
    }
}
