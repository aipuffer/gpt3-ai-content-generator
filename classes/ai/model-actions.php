<?php


namespace WPAICG\Dashboard\Ajax;

use WPAICG\AIPKit_Providers;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Core\AIPKit_Models_API;
use WPAICG\Core\Models\AIPKit_Model_Catalog;
use WPAICG\Core\Models\AIPKit_Model_Registry;
use WPAICG\Core\Providers\Google\FileSearch\GoogleFileSearchClient;
use WPAICG\Core\Providers\ProviderStrategyFactory;
use WPAICG\Speech\AIPKit_TTS_Provider_Strategy_Factory;
use WPAICG\Images\AIPKit_Image_Provider_Strategy_Factory;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for syncing AI models from providers.
 * Also handles syncing TTS voices AND models, and Vector Store indexes/collections.
 */
class ModelsAjaxHandler extends BaseDashboardAjaxHandler
{
    private $connection_candidate = null;
    private $connection_fields = [];

    /** Render the same provider definitions used by Settings, without exposing keys. */
    public function ajax_provider_connection()
    {
        $permission = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission)) { $this->send_wp_error($permission); return; }
        if (!AIPKit_Role_Manager::user_can_manage_settings()) {
            wp_send_json_error(['message' => __('Ask a site administrator to connect an AI provider.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_module_access_permissions() verifies the nonce above.
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'view';
        if ($operation === 'view') {
            $aipkit_connection_dialog_only = true;
            ob_start();
            include WPAICG_PLUGIN_DIR . 'admin/views/settings/providers.php';
            wp_send_json_success(['html' => ob_get_clean()]);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_module_access_permissions() verifies the nonce above.
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
        $allowed = ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'DeepSeek', 'xAI'];
        if (\WPAICG\aipkit_dashboard::is_pro_plan()) { $allowed[] = 'Ollama'; }
        if ($operation !== 'connect' || !in_array($provider, $allowed, true)) {
            wp_send_json_error(['message' => __('Invalid provider selection.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        $fields = $provider === 'Ollama' ? ['base_url'] : ['api_key'];
        if ($provider === 'Azure') {
            $fields = array_merge($fields, ['endpoint', 'api_version_authoring', 'api_version_inference', 'api_version_images']);
        }
        foreach ($fields as $field) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; each allowed field is sanitized by type on the next line.
            $value = isset($_POST[$field]) && is_string($_POST[$field]) ? trim(wp_unslash($_POST[$field])) : '';
            $this->connection_fields[$field] = in_array($field, ['base_url', 'endpoint'], true) ? esc_url_raw($value) : sanitize_text_field($value);
        }
        $credential = $provider === 'Ollama' ? 'base_url' : 'api_key';
        if ($this->connection_fields[$credential] === '' || ($provider === 'Azure' && $this->connection_fields['endpoint'] === '')) {
            wp_send_json_error(['message' => __('Complete the connection fields.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        $this->connection_candidate = array_merge(AIPKit_Providers::get_provider_data($provider), $this->connection_fields);
        $this->ajax_sync_models();
    }

    private $vector_store_manager;
    private $vector_store_registry;

    public function __construct()
    {
        $this->vector_store_manager = $this->create_vector_store_manager();
        $this->vector_store_registry = $this->create_vector_store_registry();

        // General dependencies for logic within this handler
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            }
        }
        if (!class_exists(\WPAICG\Core\AIPKit_Models_API::class)) {
            $models_api_path = WPAICG_PLUGIN_DIR . 'classes/ai/models.php';
            if (file_exists($models_api_path)) {
                require_once $models_api_path;
            }
        }
        if (!class_exists(\WPAICG\Speech\AIPKit_TTS_Provider_Strategy_Factory::class)) {
            $tts_factory_path = WPAICG_PLUGIN_DIR . 'classes/speech/text-to-speech.php';
            if (file_exists($tts_factory_path)) {
                require_once $tts_factory_path;
            }
        }
    }

    /**
     * Return a filtered, paginated view of the universal model registry.
     */
    public function ajax_get_model_catalog()
    {
        $permission_check = $this->check_any_module_access_permissions(
            AIPKit_Role_Manager::get_dashboard_access_modules()
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $providers = $this->get_request_list('providers', 'sanitize_text_field');
        $catalog_keys = $this->get_request_list('catalog_keys', 'sanitize_text_field');
        $kinds = $this->get_request_list('kinds', 'sanitize_key');
        $capabilities = $this->get_request_list('capabilities', 'sanitize_key');
        $resource_types = $this->get_request_list('resource_types', 'sanitize_key');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 100;
        $per_page = min(200, max(1, $per_page));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by check_any_module_access_permissions().
        $include_resources = !isset($_POST['include_resources']) || filter_var(wp_unslash($_POST['include_resources']), FILTER_VALIDATE_BOOLEAN);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $selected_provider = isset($_POST['selected_provider']) ? sanitize_text_field(wp_unslash($_POST['selected_provider'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $selected_model = isset($_POST['selected_model']) ? sanitize_text_field(wp_unslash($_POST['selected_model'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $selected_capability = isset($_POST['selected_capability']) ? sanitize_key(wp_unslash($_POST['selected_capability'])) : '';

        $models = AIPKit_Providers::query_models([
            'providers' => $providers,
            'catalog_keys' => $catalog_keys,
            'kinds' => $kinds,
            'required_capabilities' => $capabilities,
        ]);
        $resources = $include_resources
            ? AIPKit_Providers::query_provider_resources([
                'providers' => $providers,
                'catalog_keys' => $catalog_keys,
                'resource_types' => $resource_types,
            ])
            : [];

        if ($search !== '') {
            $models = array_values(array_filter($models, function (array $record) use ($search): bool {
                return $this->registry_record_matches_search($record, $search);
            }));
            $resources = array_values(array_filter($resources, function (array $record) use ($search): bool {
                return $this->registry_record_matches_search($record, $search);
            }));
        }

        $offset = ($page - 1) * $per_page;
        $model_total = count($models);
        $resource_total = count($resources);
        $facets = $this->build_registry_facets($models, $resources);
        $selection = ($selected_provider !== '' && $selected_model !== '')
            ? AIPKit_Providers::resolve_model_selection(
                $selected_provider,
                $selected_model,
                $selected_capability,
                true
            )
            : null;

        wp_send_json_success([
            'models' => array_slice($models, $offset, $per_page),
            'resources' => array_slice($resources, $offset, $per_page),
            'provider_states' => AIPKit_Providers::get_provider_connection_states(),
            'sync_timestamps' => AIPKit_Model_Registry::get_sync_timestamps(),
            'manifest' => AIPKit_Model_Registry::get_manifest_summary(),
            'facets' => $facets,
            'selection' => $selection,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'model_total' => $model_total,
                'model_pages' => max(1, (int) ceil($model_total / $per_page)),
                'resource_total' => $resource_total,
                'resource_pages' => max(1, (int) ceil($resource_total / $per_page)),
            ],
        ]);
    }

    /**
     * Return the connected, unlocked providers eligible for bulk model sync.
     */
    public function ajax_get_model_sync_targets()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $provider_states = AIPKit_Providers::get_provider_connection_states();
        $ready_targets = [];

        foreach (AIPKit_Model_Catalog::get_bulk_model_sync_targets() as $target) {
            if (!is_array($target)) {
                continue;
            }

            $provider = sanitize_text_field((string) ($target['provider'] ?? ''));
            $connection_provider = sanitize_text_field((string) ($target['connection_provider'] ?? $provider));
            $label = sanitize_text_field((string) ($target['label'] ?? $connection_provider));
            if ($provider === '' || $connection_provider === '' || $label === '') {
                continue;
            }

            $state = $provider_states[strtolower($connection_provider)] ?? [];
            if (empty($state['configured']) || !empty($state['locked'])) {
                continue;
            }

            $ready_targets[] = [
                'provider' => $provider,
                'label' => $label,
            ];
        }

        wp_send_json_success([
            'targets' => $ready_targets,
        ]);
    }

    /**
     * AJAX callback to sync models or voices from the selected provider.
     */
    public function ajax_sync_models()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
        if ($provider === 'AIPufferCloud' && class_exists('\\WPAICG\\Cloud\\Connection')) {
            if (\WPAICG\Cloud\Connection::transition('sync') !== 'synced') {
                wp_send_json_error(['message' => __('Cloud model sync failed. Check your connection in Settings.', 'gpt3-ai-content-generator')], 503);
                return;
            }
            wp_send_json_success(array_merge(\WPAICG\Cloud\Connection::view_response('synced'), [
                'message' => __('Cloud models synced.', 'gpt3-ai-content-generator'),
            ]));
            return;
        }
        $default_valid_providers = ['OpenAI', 'OpenRouter', 'Google', 'GoogleFileSearchStores', 'Azure', 'Claude', 'DeepSeek', 'xAI', 'xAIImage', 'ElevenLabs', 'ElevenLabsModels', 'OpenAIVectorStores', 'PineconeIndexes', 'QdrantCollections', 'ChromaCollections', 'Replicate'];
        $valid_providers = apply_filters('aipkit_sync_provider_allowlist', $default_valid_providers);
        if (!is_array($valid_providers) || empty($valid_providers)) {
            $valid_providers = $default_valid_providers;
        }
        $valid_providers = array_values(array_unique(array_filter(array_map(
            static function ($provider_name) {
                return sanitize_text_field((string) $provider_name);
            },
            $valid_providers
        ))));
        if (!in_array($provider, $valid_providers, true)) {
            wp_send_json_error(['message' => __('Invalid provider selection.', 'gpt3-ai-content-generator')]);
            return;
        }

        $provider_data_key = $provider;
        if ($provider === 'ElevenLabsModels') {
            $provider_data_key = 'ElevenLabs';
        } elseif ($provider === 'OpenAIVectorStores') {
            $provider_data_key = 'OpenAI';
        } elseif ($provider === 'GoogleFileSearchStores') {
            $provider_data_key = 'Google';
        } elseif ($provider === 'xAIImage') {
            $provider_data_key = 'xAI';
        } elseif ($provider === 'PineconeIndexes') {
            $provider_data_key = 'Pinecone';
        } elseif ($provider === 'QdrantCollections') {
            $provider_data_key = 'Qdrant';
        } elseif ($provider === 'ChromaCollections') {
            $provider_data_key = 'Chroma';
        }

        $provData = $this->connection_candidate ?? AIPKit_Providers::get_provider_data($provider_data_key);

        // Remap Azure 'endpoint' to 'azure_endpoint' for consistency with AI_Caller and strategy expectations.
        $api_params = [
            'verify_credentials'      => $this->connection_candidate !== null,
            'api_key'                 => $provData['api_key'] ?? '',
            'base_url'                => $provData['base_url'] ?? '',
            'url'                     => $provData['url'] ?? '', // For Qdrant/Chroma
            'tenant'                  => $provData['tenant'] ?? 'default_tenant',
            'database'                => $provData['database'] ?? 'default_database',
            'api_version'             => $provData['api_version'] ?? '',
            'api_version_authoring'   => $provData['api_version_authoring'] ?? '2023-03-15-preview',
            'api_version_inference'   => $provData['api_version_inference'] ?? '2024-02-01',
            'azure_endpoint'          => ($provider === 'Azure' || $provider_data_key === 'Azure') ? ($provData['endpoint'] ?? '') : '',
        ];


        if (empty($api_params['api_key']) && in_array($provider, ['OpenAI', 'OpenRouter', 'GoogleFileSearchStores', 'Azure', 'Claude', 'DeepSeek', 'xAI', 'xAIImage', 'ElevenLabs', 'ElevenLabsModels', 'PineconeIndexes', 'QdrantCollections', 'Replicate'], true)) {
            /* translators: %s: The provider name that was attempted to be used for model sync. */
            wp_send_json_error(['message' => sprintf(__('%s API key is required.', 'gpt3-ai-content-generator'), $provider_data_key)]);
            return;
        }
        if ($provider === 'QdrantCollections' && empty($api_params['url'])) {
            wp_send_json_error(['message' => __('Qdrant URL is required to sync collections.', 'gpt3-ai-content-generator')]);
            return;
        }
        if ($provider === 'ChromaCollections' && empty($api_params['url'])) {
            wp_send_json_error(['message' => __('Chroma URL is required to sync collections.', 'gpt3-ai-content-generator')]);
            return;
        }

        $result = null;

        switch ($provider) {
            case 'ElevenLabs':
                $strategy = AIPKit_TTS_Provider_Strategy_Factory::get_strategy('ElevenLabs');
                $result = is_wp_error($strategy) ? $strategy : $strategy->get_voices($api_params);
                break;
            case 'ElevenLabsModels':
                $strategy = AIPKit_TTS_Provider_Strategy_Factory::get_strategy('ElevenLabs');
                $result = (is_wp_error($strategy) || !method_exists($strategy, 'get_models'))
                    ? new WP_Error('tts_model_sync_not_supported', 'TTS Model sync not supported for ElevenLabs.')
                    : $strategy->get_models($api_params);
                break;
            case 'OpenAIVectorStores':
                if (!$this->vector_store_manager) {
                    $result = new WP_Error('vsm_missing', 'Vector Store Manager not available.');
                    break;
                }
                // Full sync pass for Dashboard autosync (no paging needed here)
                $result = $this->vector_store_manager->list_all_indexes('OpenAI', $api_params, 100, 'desc', null, null);
                break;
            case 'GoogleFileSearchStores':
                $api_params['api_version'] = 'v1beta';
                $result = (new GoogleFileSearchClient())->list_all_stores($api_params);
                break;
            case 'PineconeIndexes':
                if (!$this->vector_store_manager) {
                    $result = new WP_Error('vsm_missing', 'Vector Store Manager not available.');
                    break;
                }
                $result = $this->vector_store_manager->list_all_indexes('Pinecone', $api_params);
                break;
            case 'QdrantCollections':
                if (!$this->vector_store_manager) {
                    $result = new WP_Error('vsm_missing', 'Vector Store Manager not available.');
                    break;
                }
                $result = $this->vector_store_manager->list_all_indexes('Qdrant', $api_params);
                break;
            case 'ChromaCollections':
                if (!$this->vector_store_manager) {
                    $result = new WP_Error('vsm_missing', 'Vector Store Manager not available.');
                    break;
                }
                $result = $this->vector_store_manager->list_all_indexes('Chroma', $api_params);
                break;
            case 'Replicate':
                if (!class_exists(\WPAICG\Images\AIPKit_Image_Provider_Strategy_Factory::class)) {
                    $factory_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/provider-contracts.php';
                    if (file_exists($factory_path)) {
                        require_once $factory_path;
                    }
                }
                $strategy = \WPAICG\Images\AIPKit_Image_Provider_Strategy_Factory::get_strategy('Replicate');
                $result = is_wp_error($strategy) ? $strategy : $strategy->get_models($api_params);
                break;
            case 'xAIImage':
                if (!class_exists(AIPKit_Image_Provider_Strategy_Factory::class)) {
                    $factory_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/provider-contracts.php';
                    if (file_exists($factory_path)) {
                        require_once $factory_path;
                    }
                }
                $strategy = AIPKit_Image_Provider_Strategy_Factory::get_strategy('xAI');
                $result = (is_wp_error($strategy) || !method_exists($strategy, 'get_models'))
                    ? new WP_Error('xai_image_model_sync_not_supported', __('xAI image model sync is not available.', 'gpt3-ai-content-generator'))
                    : $strategy->get_models($api_params);
                break;
            default: // Handles OpenAI, OpenRouter, Google, Azure, DeepSeek, xAI
                $result = AIPKit_Models_API::get_models($provider, $api_params);
                break;
        }

        if (is_wp_error($result)) {
            if ($this->connection_candidate === null) {
                AIPKit_Model_Registry::mark_sync_error($provider_data_key, $provider, $result, $provData);
            }
            $error_data = $result->get_error_data();
            $status_code = isset($error_data['status']) ? (int)$error_data['status'] : 500;
            wp_send_json_error(['message' => $result->get_error_message()], $status_code);
            return;
        }

        $primary_catalog_map = [
            'OpenAI' => 'OpenAI',
            'OpenRouter' => 'OpenRouter',
            'Google' => 'Google',
            'Azure' => 'Azure',
            'Claude' => 'Claude',
            'DeepSeek' => 'DeepSeek',
            'xAI' => 'xAI',
            'ElevenLabs' => 'ElevenLabs',
            'ElevenLabsModels' => 'ElevenLabsModels',
            'xAIImage' => 'xAIImage',
            'OpenAIVectorStores' => 'OpenAIVectorStores',
            'GoogleFileSearchStores' => 'GoogleFileSearchStores',
            'PineconeIndexes' => 'PineconeIndexes',
            'QdrantCollections' => 'QdrantCollections',
            'ChromaCollections' => 'ChromaCollections',
            'Replicate' => 'Replicate',
            'Ollama' => 'Ollama',
        ];

        $primary_catalog_key = $primary_catalog_map[$provider] ?? '';
        $catalog_updates = [];
        $secondary_sync_errors = [];
        $secondary_sync_successes = [];
        $vector_registry_updates = [];
        $response_models = $result; // Default to raw result
        $extra_response_payload = [];
        $value_to_save = $result;

        if ($provider === 'OpenAIVectorStores') {
            $stores_payload = $result;
            if (is_array($stores_payload) && isset($stores_payload['data']) && is_array($stores_payload['data'])) {
                $stores_payload = $stores_payload['data'];
            }

            if (is_array($stores_payload) && $this->vector_store_registry) {
                $stores_payload = $this->vector_store_registry->reconcile_openai_store_list($stores_payload);
            }

            $active_stores = [];
            if (is_array($stores_payload)) {
                foreach ($stores_payload as $store_item) {
                    if (isset($store_item['status']) && $store_item['status'] === 'expired') {
                        continue;
                    }
                    $active_stores[] = $store_item;
                }
            }

            $vector_registry_updates['OpenAI'] = $active_stores;
            $value_to_save = $active_stores;
            $response_models = $active_stores;
            $extra_response_payload['stores'] = $active_stores;
        }

        if ($provider === 'GoogleFileSearchStores') {
            $stores_payload = is_array($result) ? $result : [];
            $vector_registry_updates['Google'] = $stores_payload;
            $value_to_save = $stores_payload;
            $response_models = $stores_payload;
            $extra_response_payload['stores'] = $stores_payload;
        }

        if ($primary_catalog_key !== '') {
            if ($provider !== 'OpenAIVectorStores') {
                $value_to_save = $result;
            }
            // OpenAI and Google have multiple model types, split them here
            if ($provider === 'OpenAI') {
                $openai_catalogs = AIPKit_Model_Registry::partition_openai_models($result);
                $chat_models = $openai_catalogs['OpenAI'];
                $value_to_save = AIPKit_Models_API::group_openai_models($chat_models);
                $response_models = $value_to_save; // Set response to the grouped models
                foreach ($openai_catalogs as $catalog_key => $catalog_rows) {
                    if ($catalog_key !== 'OpenAI') {
                        $catalog_updates[$catalog_key] = $catalog_rows;
                    }
                }
            } elseif ($provider === 'Google') {
                $google_catalogs = AIPKit_Model_Registry::partition_google_models($result);
                foreach (['GoogleImage', 'GoogleTTS'] as $curated_catalog_key) {
                    $google_catalogs[$curated_catalog_key] = AIPKit_Providers::merge_model_rows(
                        AIPKit_Model_Catalog::get_seed_rows($curated_catalog_key),
                        $google_catalogs[$curated_catalog_key]
                    );
                }
                $value_to_save = $google_catalogs['Google'];
                $response_models = $value_to_save; // Set response to just the chat models
                $extra_response_payload['image_models'] = $google_catalogs['GoogleImage'];
                $extra_response_payload['tts_models'] = $google_catalogs['GoogleTTS'];
                $extra_response_payload['embedding_models'] = $google_catalogs['GoogleEmbedding'];
                $extra_response_payload['video_models'] = $google_catalogs['GoogleVideo'];
                foreach ($google_catalogs as $catalog_key => $catalog_rows) {
                    if ($catalog_key !== 'Google') {
                        $catalog_updates[$catalog_key] = $catalog_rows;
                    }
                }
            } elseif ($provider === 'OpenRouter') {
                $existing_embedding_models = AIPKit_Model_Registry::get_legacy_model_list('OpenRouterEmbedding');
                $openrouter_embedding_models = is_array($existing_embedding_models) ? $existing_embedding_models : [];
                $existing_image_models = AIPKit_Model_Registry::get_legacy_model_list('OpenRouterImage');
                $openrouter_image_models = is_array($existing_image_models) ? $existing_image_models : [];

                $openrouter_strategy = ProviderStrategyFactory::get_strategy('OpenRouter');
                if (is_wp_error($openrouter_strategy)) {
                    $secondary_sync_errors['OpenRouterExtensions'] = $openrouter_strategy;
                } elseif (method_exists($openrouter_strategy, 'get_embedding_models')) {
                    $embedding_models_result = $openrouter_strategy->get_embedding_models($api_params);
                    if (!is_wp_error($embedding_models_result) && is_array($embedding_models_result)) {
                        $openrouter_embedding_models = $embedding_models_result;
                        $catalog_updates['OpenRouterEmbedding'] = $openrouter_embedding_models;
                        $secondary_sync_successes[] = 'OpenRouterEmbedding';
                    } elseif (is_wp_error($embedding_models_result)) {
                        $secondary_sync_errors['OpenRouterEmbedding'] = $embedding_models_result;
                    }
                }
                if (!is_wp_error($openrouter_strategy) && method_exists($openrouter_strategy, 'get_image_models')) {
                    $image_models_result = $openrouter_strategy->get_image_models($api_params);
                    if (!is_wp_error($image_models_result) && is_array($image_models_result)) {
                        $openrouter_image_models = $image_models_result;
                        $catalog_updates['OpenRouterImage'] = $openrouter_image_models;
                        $value_to_save = AIPKit_Providers::merge_model_rows($value_to_save, $openrouter_image_models);
                        $secondary_sync_successes[] = 'OpenRouterImage';
                    } elseif (is_wp_error($image_models_result)) {
                        $secondary_sync_errors['OpenRouterImage'] = $image_models_result;
                    }
                }
                $extra_response_payload['embedding_models'] = $openrouter_embedding_models;
                $extra_response_payload['image_models'] = $openrouter_image_models;
            } elseif ($provider === 'xAI') {
                if (!class_exists(AIPKit_Image_Provider_Strategy_Factory::class)) {
                    $factory_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/provider-contracts.php';
                    if (file_exists($factory_path)) {
                        require_once $factory_path;
                    }
                }
                $xai_image_models = AIPKit_Model_Registry::get_legacy_model_list('xAIImage');
                $xai_image_models = is_array($xai_image_models) ? $xai_image_models : [];
                if (class_exists(AIPKit_Image_Provider_Strategy_Factory::class)) {
                    $xai_image_strategy = AIPKit_Image_Provider_Strategy_Factory::get_strategy('xAI');
                    if (!is_wp_error($xai_image_strategy) && method_exists($xai_image_strategy, 'get_models')) {
                        $xai_image_models_result = $xai_image_strategy->get_models($api_params);
                        if (!is_wp_error($xai_image_models_result) && is_array($xai_image_models_result)) {
                            $xai_image_models = $xai_image_models_result;
                            $catalog_updates['xAIImage'] = $xai_image_models;
                            $secondary_sync_successes[] = 'xAIImage';
                        } elseif (is_wp_error($xai_image_models_result)) {
                            $secondary_sync_errors['xAIImage'] = $xai_image_models_result;
                        }
                    } elseif (is_wp_error($xai_image_strategy)) {
                        $secondary_sync_errors['xAIImage'] = $xai_image_strategy;
                    }
                }
                $extra_response_payload['image_models'] = $xai_image_models;
            } elseif ($provider === 'Ollama' && class_exists(\WPAICG\Lib\AI\AIPKit_Model_Actions::class)) {
                $value_to_save = \WPAICG\Lib\AI\AIPKit_Model_Actions::prepare_ollama_sync(
                    $result,
                    $api_params,
                    $catalog_updates,
                    $extra_response_payload
                );
                $response_models = $value_to_save;
            } elseif ($provider === 'Azure') {
                $chat_deployments = [];
                $image_deployments = [];
                $embedding_deployments = [];
                if (is_array($result)) {
                    foreach ($result as $deployment) {
                        $deployment_haystack = strtolower(trim(
                            (string) ($deployment['name'] ?? '') . ' ' .
                            (string) ($deployment['model'] ?? '') . ' ' .
                            (string) ($deployment['id'] ?? '')
                        ));
                        $is_embedding_deployment = strpos($deployment_haystack, 'embedding') !== false
                            || strpos($deployment_haystack, 'embed') !== false;
                        $is_image_deployment = strpos($deployment_haystack, 'gpt-image') !== false
                            || (
                                strpos($deployment_haystack, 'image') !== false
                                && strpos($deployment_haystack, 'embedding') === false
                                && strpos($deployment_haystack, 'embed') === false
                                && strpos($deployment_haystack, 'vision') === false
                            );

                        if ($is_image_deployment) {
                            $image_deployments[] = $deployment;
                        } elseif ($is_embedding_deployment) {
                            $embedding_deployments[] = $deployment;
                        } else {
                            $chat_deployments[] = $deployment;
                        }
                    }
                }
                $catalog_updates['AzureImage'] = $image_deployments;
                $catalog_updates['AzureEmbedding'] = $embedding_deployments;
                $value_to_save = $chat_deployments;
                
                // Return grouped models for dashboard display
                $grouped_models = [];
                if (!empty($chat_deployments)) {
                    $grouped_models['Chat Models'] = $chat_deployments;
                }
                if (!empty($embedding_deployments)) {
                    $grouped_models['Embedding Models'] = $embedding_deployments;
                }
                if (!empty($image_deployments)) {
                    $grouped_models['Image Models'] = $image_deployments;
                }
                $response_models = $grouped_models;
            } elseif ($provider === 'PineconeIndexes' && $this->vector_store_registry) {
                // Enrich with describe results to capture total_vector_count
                $pinecone_config = [
                    'api_key' => $api_params['api_key'] ?? ''
                ];
                $enriched = [];
                if ($this->vector_store_manager && is_array($value_to_save)) {
                    foreach ($value_to_save as $idx) {
                        $name = $idx['name'] ?? $idx['id'] ?? null;
                        if (!$name) continue;
                        $details = $this->vector_store_manager->describe_single_index('Pinecone', $name, $pinecone_config);
                        $enriched[] = is_wp_error($details) ? $idx : array_merge($idx, $details);
                    }
                }
                if (!empty($enriched)) {
                    $value_to_save = $enriched;
                }
                $vector_registry_updates['Pinecone'] = $value_to_save;
            } elseif ($provider === 'QdrantCollections' && $this->vector_store_registry) {
                // Enrich with describe results to capture vectors_count
                $qdrant_config = [
                    'url' => $api_params['url'] ?? '',
                    'api_key' => $api_params['api_key'] ?? ''
                ];
                $enriched = [];
                if ($this->vector_store_manager && is_array($value_to_save)) {
                    foreach ($value_to_save as $col) {
                        $name = $col['name'] ?? $col['id'] ?? null;
                        if (!$name) continue;
                        $details = $this->vector_store_manager->describe_single_index('Qdrant', $name, $qdrant_config);
                        $enriched[] = is_wp_error($details) ? $col : array_merge($col, $details);
                    }
                }
                if (!empty($enriched)) {
                    $value_to_save = $enriched;
                }
                $vector_registry_updates['Qdrant'] = $value_to_save;
            } elseif ($provider === 'ChromaCollections' && $this->vector_store_registry) {
                $chroma_config = [
                    'url' => $api_params['url'] ?? '',
                    'api_key' => $api_params['api_key'] ?? '',
                    'tenant' => $api_params['tenant'] ?? 'default_tenant',
                    'database' => $api_params['database'] ?? 'default_database',
                ];
                $enriched = [];
                if ($this->vector_store_manager && is_array($value_to_save)) {
                    foreach ($value_to_save as $collection) {
                        $name = $collection['name'] ?? $collection['id'] ?? null;
                        if (!$name) continue;
                        $details = $this->vector_store_manager->describe_single_index('Chroma', $name, $chroma_config);
                        $enriched[] = is_wp_error($details) ? $collection : array_merge($collection, $details);
                    }
                }
                if (!empty($enriched)) {
                    $value_to_save = $enriched;
                }
                $vector_registry_updates['Chroma'] = $value_to_save;
            }
            $catalog_updates[$primary_catalog_key] = $value_to_save;
        }

        $synced_at = time();
        $publish_result = AIPKit_Model_Registry::publish_catalogs(
            $provider_data_key,
            $catalog_updates,
            [
                'sync_scope' => $provider,
                'synced_at' => $synced_at,
                'connection' => $provData,
                'sync_scopes' => $secondary_sync_successes,
            ]
        );
        if (is_wp_error($publish_result)) {
            $this->send_wp_error($publish_result);
            return;
        }

        if ($this->connection_candidate !== null) {
            AIPKit_Providers::save_provider_data($provider_data_key, $this->connection_fields);
            $saved_connection = AIPKit_Providers::get_provider_data($provider_data_key);
            foreach ($this->connection_fields as $field => $value) {
                if (($saved_connection[$field] ?? '') !== $value) {
                    wp_send_json_error(['message' => __('The connection could not be saved. Please try again.', 'gpt3-ai-content-generator')], 500);
                    return;
                }
            }
        }

        if ($this->vector_store_registry) {
            foreach ($vector_registry_updates as $vector_provider => $vector_targets) {
                $this->vector_store_registry->update_registered_stores_for_provider(
                    $vector_provider,
                    is_array($vector_targets) ? $vector_targets : [],
                    false
                );
            }
        }

        $sync_warnings = [];
        foreach ($secondary_sync_errors as $sync_scope => $sync_error) {
            if (!is_wp_error($sync_error)) {
                continue;
            }
            AIPKit_Model_Registry::mark_sync_error(
                $provider_data_key,
                (string) $sync_scope,
                $sync_error,
                $provData
            );
            $sync_warnings[] = [
                'scope' => sanitize_text_field((string) $sync_scope),
                'code' => sanitize_key((string) $sync_error->get_error_code()),
                'message' => sanitize_text_field($sync_error->get_error_message()),
            ];
        }

        AIPKit_Providers::clear_model_caches();

        if ($primary_catalog_key !== '' && $provider !== 'OpenAIVectorStores') {
            $normalized_response_models = $primary_catalog_key === 'OpenRouter'
                ? AIPKit_Providers::get_openrouter_models()
                : AIPKit_Model_Registry::get_legacy_model_list($primary_catalog_key);
            if (is_array($normalized_response_models)) {
                $response_models = $normalized_response_models;
            }
        }

        $recommended_models = $this->get_recommended_models_for_response($provider);
        $provider_states = AIPKit_Providers::get_provider_connection_states();
        $provider_state = $provider_states[strtolower($provider_data_key)] ?? null;

        $success_message = empty($sync_warnings)
            ? sprintf(
                /* translators: %s: the provider name that was synced. */
                __('%s synced successfully.', 'gpt3-ai-content-generator'),
                $provider_data_key
            )
            : sprintf(
                /* translators: 1: provider name, 2: number of related catalog sync warnings */
                _n(
                    '%1$s models synced, but %2$d related catalog could not be refreshed.',
                    '%1$s models synced, but %2$d related catalogs could not be refreshed.',
                    count($sync_warnings),
                    'gpt3-ai-content-generator'
                ),
                $provider_data_key,
                count($sync_warnings)
            );
        $manifest = AIPKit_Model_Registry::get_manifest_summary();
        $catalog_revision = (string) ($manifest['providers'][$provider_data_key]['revision'] ?? $publish_result['revision'] ?? '');
        wp_send_json_success(
            array_merge(
                [
                    'message' => $success_message,
                    'models'  => $response_models,
                    'recommended_models' => $recommended_models,
                    'synced_at' => $synced_at,
                    'catalog_revision' => $catalog_revision,
                    'provider_state' => $provider_state,
                    'providerStatus' => AIPKit_Providers::get_provider_status_map(),
                    'navStatusHtml' => \WPAICG\Cloud\StatusChip::html(),
                    'newConfiguration' => AIPKit_Providers::get_new_configuration_payload(),
                    'warnings' => $sync_warnings,
                ],
                $extra_response_payload
            )
        );
    }

    /**
     * Builds a recommended-model payload for sync responses so UI grouping
     * can be rendered immediately without a page refresh.
     *
     * @return array<int, array{id:string,name:string}>
     */
    private function get_recommended_models_for_response(string $provider): array
    {
        $default_supported = ['OpenAI', 'OpenRouter', 'Google', 'Azure', 'Claude', 'DeepSeek', 'xAI', 'xAIImage'];
        $supported = apply_filters('aipkit_recommended_model_supported_providers', $default_supported);
        $supported = is_array($supported) ? $supported : $default_supported;
        if (!in_array($provider, $supported, true)) {
            return [];
        }

        $recommended = AIPKit_Providers::get_recommended_models($provider);
        return is_array($recommended) ? $recommended : [];
    }

    /**
     * Read a scalar, comma-separated, or array request value as a safe list.
     *
     * @param callable-string $sanitizer
     * @return array<int, string>
     */
    private function get_request_list(string $key, string $sanitizer): array
    {
        // The caller verifies the nonce; every scalar item is sanitized below.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = call_user_func($sanitizer, (string) $item);
            if ($item !== '') {
                $normalized[$item] = true;
            }
        }
        return array_keys($normalized);
    }

    /**
     * Match the stable, user-visible fields exposed by the model catalog API.
     *
     * @param array<string, mixed> $record
     */
    private function registry_record_matches_search(array $record, string $search): bool
    {
        $haystack = implode(' ', [
            (string) ($record['provider'] ?? ''),
            (string) ($record['id'] ?? ''),
            (string) ($record['canonical_id'] ?? ''),
            (string) ($record['name'] ?? ''),
            (string) ($record['family_label'] ?? ''),
            implode(' ', is_array($record['kinds'] ?? null) ? $record['kinds'] : []),
        ]);
        return stripos($haystack, $search) !== false;
    }

    /**
     * Return aggregate counts needed by provider and model-type navigation.
     *
     * @param array<int, array<string, mixed>> $models
     * @param array<int, array<string, mixed>> $resources
     * @return array<string, array<string, mixed>>
     */
    private function build_registry_facets(array $models, array $resources): array
    {
        $facets = [
            'providers' => [],
            'families' => [],
            'kinds' => [],
            'capabilities' => [],
            'resource_types' => [],
        ];

        foreach ($models as $model) {
            $provider = sanitize_text_field((string) ($model['provider'] ?? ''));
            if ($provider !== '') {
                if (!isset($facets['providers'][$provider])) {
                    $facets['providers'][$provider] = ['models' => 0, 'resources' => 0];
                }
                $facets['providers'][$provider]['models']++;
                $family_key = sanitize_key((string) ($model['family_key'] ?? 'other'));
                if (!isset($facets['families'][$provider])) {
                    $facets['families'][$provider] = [];
                }
                if (!isset($facets['families'][$provider][$family_key])) {
                    $facets['families'][$provider][$family_key] = [
                        'label' => sanitize_text_field((string) ($model['family_label'] ?? __('Other', 'gpt3-ai-content-generator'))),
                        'order' => (int) ($model['family_order'] ?? 999),
                        'collapsed' => !empty($model['family_collapsed']),
                        'models' => 0,
                    ];
                }
                $facets['families'][$provider][$family_key]['models']++;
            }
            foreach (($model['kinds'] ?? []) as $kind) {
                $kind = sanitize_key((string) $kind);
                if ($kind !== '') {
                    $facets['kinds'][$kind] = ($facets['kinds'][$kind] ?? 0) + 1;
                }
            }
            foreach (($model['capabilities'] ?? []) as $capability => $supported) {
                $capability = sanitize_key((string) $capability);
                if ($capability !== '' && $supported) {
                    $facets['capabilities'][$capability] = ($facets['capabilities'][$capability] ?? 0) + 1;
                }
            }
        }

        foreach ($resources as $resource) {
            $provider = sanitize_text_field((string) ($resource['provider'] ?? ''));
            if ($provider !== '') {
                if (!isset($facets['providers'][$provider])) {
                    $facets['providers'][$provider] = ['models' => 0, 'resources' => 0];
                }
                $facets['providers'][$provider]['resources']++;
            }
            $resource_type = sanitize_key((string) ($resource['resource_type'] ?? ''));
            if ($resource_type !== '') {
                $facets['resource_types'][$resource_type] = ($facets['resource_types'][$resource_type] ?? 0) + 1;
            }
        }

        ksort($facets['providers']);
        ksort($facets['families']);
        foreach ($facets['families'] as &$provider_families) {
            uasort($provider_families, static function (array $left, array $right): int {
                return ((int) $left['order'] <=> (int) $right['order'])
                    ?: strcasecmp((string) $left['label'], (string) $right['label']);
            });
        }
        unset($provider_families);
        ksort($facets['kinds']);
        ksort($facets['capabilities']);
        ksort($facets['resource_types']);
        return $facets;
    }
}
