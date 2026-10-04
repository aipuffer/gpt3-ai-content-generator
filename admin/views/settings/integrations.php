<?php
/**
 * Partial: Integration provider cards.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local schema and renderer helpers.

$normalize_synced_select_options = static function (array $items, ?array $value_keys = null, ?array $label_keys = null): array {
    $options = [];
    $value_keys = $value_keys ?: ['id', 'name', 'model', 'index_name', 'collection_name'];
    $label_keys = $label_keys ?: ['name', 'id', 'model', 'index_name', 'collection_name'];

    foreach ($items as $item) {
        $value = '';
        $label = '';

        if (is_array($item) || is_object($item)) {
            foreach ($value_keys as $key) {
                $candidate = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
                if (is_scalar($candidate) && (string) $candidate !== '') {
                    $value = trim(wp_strip_all_tags((string) $candidate));
                    break;
                }
            }

            foreach ($label_keys as $key) {
                $candidate = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
                if (is_scalar($candidate) && (string) $candidate !== '') {
                    $label = trim(wp_strip_all_tags((string) $candidate));
                    break;
                }
            }
        } elseif (is_scalar($item)) {
            $value = trim(wp_strip_all_tags((string) $item));
            $label = $value;
        }

        if ($value === '' && $label !== '') {
            $value = $label;
        }
        if ($label === '' && $value !== '') {
            $label = $value;
        }
        if ($value === '' || $label === '') {
            continue;
        }

        $dedupe_key = strtolower($value);
        if (!isset($options[$dedupe_key])) {
            $options[$dedupe_key] = [
                'value' => $value,
                'label' => $label,
            ];
        }
    }

    $options = array_values($options);
    usort($options, static function (array $a, array $b): int {
        return strcasecmp($a['label'], $b['label']);
    });

    return $options;
};

$current_elevenlabs_api_key = (string) ($elevenlabs_data['api_key'] ?? '');
$current_replicate_api_key = (string) ($replicate_data['api_key'] ?? '');
$current_pinecone_api_key = (string) ($pinecone_data['api_key'] ?? '');
$current_qdrant_url = (string) ($qdrant_data['url'] ?? '');
$current_qdrant_api_key = (string) ($qdrant_data['api_key'] ?? '');
$current_chroma_url = (string) ($chroma_data['url'] ?? '');
$current_chroma_api_key = (string) ($chroma_data['api_key'] ?? '');
$current_pexels_api_key = (string) ($pexels_data['api_key'] ?? '');
$current_pixabay_api_key = (string) ($pixabay_data['api_key'] ?? '');

$elevenlabs_voice_options = $normalize_synced_select_options(
    is_array($elevenlabs_voice_list ?? null) ? $elevenlabs_voice_list : [],
    ['id', 'name'],
    ['name', 'id']
);
$elevenlabs_model_options = $normalize_synced_select_options(
    is_array($elevenlabs_model_list ?? null) ? $elevenlabs_model_list : [],
    ['id', 'name'],
    ['name', 'id']
);
$replicate_model_options = $normalize_synced_select_options(
    is_array($replicate_model_list ?? null) ? $replicate_model_list : []
);
$pinecone_index_options = $normalize_synced_select_options(
    is_array($pinecone_index_list ?? null) ? $pinecone_index_list : []
);
$qdrant_collection_options = $normalize_synced_select_options(
    is_array($qdrant_collection_list ?? null) ? $qdrant_collection_list : []
);
$chroma_collection_options = $normalize_synced_select_options(
    is_array($chroma_collection_list ?? null) ? $chroma_collection_list : [],
    ['name', 'collection_name', 'id'],
    ['name', 'collection_name', 'id']
);

$integration_configs = [
    'elevenlabs' => [
        'display_name' => __('ElevenLabs', 'gpt3-ai-content-generator'),
        'icon' => 'elevenlabs.svg',
        'accent' => '#111827',
        'credential' => [
            'id' => 'aipkit_elevenlabs_api_key',
            'name' => 'elevenlabs_api_key',
            'value' => $current_elevenlabs_api_key,
            'label' => __('API key', 'gpt3-ai-content-generator'),
            'description' => __('Required for voices and text-to-speech models.', 'gpt3-ai-content-generator'),
            'placeholder' => __('e.g., sk_1234567890abcdef', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://elevenlabs.io/app/settings/api-keys',
        'key_link' => __('Get your ElevenLabs API key', 'gpt3-ai-content-generator'),
        'connected' => $current_elevenlabs_api_key !== '',
        'fields' => [
            [
                'type' => 'select',
                'id' => 'aipkit_elevenlabs_voice_id',
                'name' => 'elevenlabs_voice_id',
                'label' => __('Voice', 'gpt3-ai-content-generator'),
                'description' => __('Default voice used for speech output.', 'gpt3-ai-content-generator'),
                'value' => (string) ($elevenlabs_data['voice_id'] ?? ''),
                'options' => $elevenlabs_voice_options,
                'empty_label' => __('Select a voice (optional)', 'gpt3-ai-content-generator'),
                'sync_id' => 'aipkit_sync_elevenlabs_voices',
                'sync_provider' => 'ElevenLabs',
                'sync_label' => __('Sync voices', 'gpt3-ai-content-generator'),
            ],
            [
                'type' => 'select',
                'id' => 'aipkit_elevenlabs_tts_model_id',
                'name' => 'elevenlabs_model_id',
                'label' => __('Model', 'gpt3-ai-content-generator'),
                'description' => __('Default text-to-speech model.', 'gpt3-ai-content-generator'),
                'value' => (string) ($elevenlabs_data['model_id'] ?? ''),
                'options' => $elevenlabs_model_options,
                'empty_label' => __('Select a model (optional)', 'gpt3-ai-content-generator'),
                'sync_id' => 'aipkit_sync_elevenlabs_models_btn',
                'sync_provider' => 'ElevenLabsModels',
                'sync_label' => __('Sync models', 'gpt3-ai-content-generator'),
            ],
        ],
    ],
    'replicate' => [
        'display_name' => __('Replicate', 'gpt3-ai-content-generator'),
        'icon' => 'replicate.svg',
        'accent' => '#111827',
        'credential' => [
            'id' => 'aipkit_replicate_api_key',
            'name' => 'replicate_api_key',
            'value' => $current_replicate_api_key,
            'label' => __('API key', 'gpt3-ai-content-generator'),
            'description' => __('Connect Replicate image models.', 'gpt3-ai-content-generator'),
            'placeholder' => __('e.g., r8_AbCdEf123456', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://replicate.com/account/api-tokens',
        'key_link' => __('Get your Replicate API key', 'gpt3-ai-content-generator'),
        'connected' => $current_replicate_api_key !== '',
        'fields' => [
            [
                'type' => 'select',
                'id' => 'aipkit_replicate_model',
                'label' => __('Available model', 'gpt3-ai-content-generator'),
                'description' => __('Review models available to this account.', 'gpt3-ai-content-generator'),
                'value' => '',
                'options' => $replicate_model_options,
                'empty_label' => __('Select a synced model', 'gpt3-ai-content-generator'),
                'sync_id' => 'aipkit_sync_replicate_models_btn',
                'sync_provider' => 'Replicate',
                'sync_label' => __('Sync models', 'gpt3-ai-content-generator'),
            ],
            [
                'type' => 'toggle',
                'id' => 'aipkit_replicate_disable_safety_checker',
                'name' => 'replicate_disable_safety_checker',
                'label' => __('Disable safety checker', 'gpt3-ai-content-generator'),
                'description' => __('Allow image requests without Replicate safety checks.', 'gpt3-ai-content-generator'),
                'checked' => (bool) $replicate_disable_safety_checker,
            ],
        ],
    ],
    'pinecone' => [
        'display_name' => __('Pinecone', 'gpt3-ai-content-generator'),
        'icon' => 'pinecone.svg',
        'accent' => '#5b5ce2',
        'credential' => [
            'id' => 'aipkit_pinecone_api_key',
            'name' => 'pinecone_api_key',
            'value' => $current_pinecone_api_key,
            'label' => __('API key', 'gpt3-ai-content-generator'),
            'description' => __('Connect Pinecone vector indexes.', 'gpt3-ai-content-generator'),
            'placeholder' => __('e.g., pcsk_AbCdEf123456', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://app.pinecone.io/',
        'key_link' => __('Get your Pinecone API key', 'gpt3-ai-content-generator'),
        'connected' => $current_pinecone_api_key !== '',
        'fields' => [
            [
                'type' => 'select',
                'id' => 'aipkit_pinecone_default_index',
                'label' => __('Index', 'gpt3-ai-content-generator'),
                'description' => __('Review indexes available to this account.', 'gpt3-ai-content-generator'),
                'value' => '',
                'options' => $pinecone_index_options,
                'empty_label' => __('Select a synced index', 'gpt3-ai-content-generator'),
                'sync_id' => 'aipkit_sync_pinecone_indexes_btn',
                'sync_provider' => 'PineconeIndexes',
                'sync_label' => __('Sync indexes', 'gpt3-ai-content-generator'),
            ],
        ],
    ],
    'qdrant' => [
        'display_name' => __('Qdrant', 'gpt3-ai-content-generator'),
        'icon' => 'qdrant.svg',
        'accent' => '#dc244c',
        'url' => [
            'id' => 'aipkit_qdrant_url',
            'name' => 'qdrant_url',
            'value' => $current_qdrant_url,
            'label' => __('Endpoint URL', 'gpt3-ai-content-generator'),
            'description' => __('Your Qdrant Cloud or self-hosted endpoint.', 'gpt3-ai-content-generator'),
            'placeholder' => __('https://example-cluster.eu-central.aws.cloud.qdrant.io:6333', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'credential' => [
            'id' => 'aipkit_qdrant_api_key',
            'name' => 'qdrant_api_key',
            'value' => $current_qdrant_api_key,
            'label' => __('API key', 'gpt3-ai-content-generator'),
            'description' => __('Required for Qdrant Cloud connections.', 'gpt3-ai-content-generator'),
            'placeholder' => __('e.g., eyJhbGciOiJIUzI1NiJ9...', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://cloud.qdrant.io/',
        'key_link' => __('Get your Qdrant API key', 'gpt3-ai-content-generator'),
        'connected' => $current_qdrant_url !== '' && $current_qdrant_api_key !== '',
        'fields' => [
            [
                'type' => 'select',
                'id' => 'aipkit_qdrant_default_collection',
                'name' => 'qdrant_default_collection',
                'label' => __('Collection', 'gpt3-ai-content-generator'),
                'description' => __('Default collection for Qdrant operations.', 'gpt3-ai-content-generator'),
                'value' => (string) ($qdrant_data['default_collection'] ?? ''),
                'options' => $qdrant_collection_options,
                'empty_label' => __('Select a collection', 'gpt3-ai-content-generator'),
                'sync_id' => 'aipkit_sync_qdrant_collections_btn',
                'sync_provider' => 'QdrantCollections',
                'sync_label' => __('Sync collections', 'gpt3-ai-content-generator'),
            ],
        ],
    ],
    'chroma' => [
        'display_name' => __('Chroma', 'gpt3-ai-content-generator'),
        'icon' => 'chroma.svg',
        'accent' => '#f59e0b',
        'url' => [
            'id' => 'aipkit_chroma_url',
            'name' => 'chroma_url',
            'value' => $current_chroma_url,
            'label' => __('Endpoint URL', 'gpt3-ai-content-generator'),
            'description' => __('Your Chroma Cloud or self-hosted endpoint.', 'gpt3-ai-content-generator'),
            'placeholder' => __('https://api.trychroma.com', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'credential' => [
            'id' => 'aipkit_chroma_api_key',
            'name' => 'chroma_api_key',
            'value' => $current_chroma_api_key,
            'label' => __('API key', 'gpt3-ai-content-generator'),
            'description' => __('Optional for local servers; required for authenticated hosts.', 'gpt3-ai-content-generator'),
            'placeholder' => __('e.g., ck_AbCdEf123456', 'gpt3-ai-content-generator'),
            'required' => false,
        ],
        'key_url' => 'https://trychroma.com/',
        'key_link' => __('Get your Chroma API key', 'gpt3-ai-content-generator'),
        'connected' => $current_chroma_url !== '',
        'fields' => [
            [
                'type' => 'text',
                'id' => 'aipkit_chroma_tenant',
                'name' => 'chroma_tenant',
                'label' => __('Tenant', 'gpt3-ai-content-generator'),
                'description' => __('Use default_tenant for local Chroma.', 'gpt3-ai-content-generator'),
                'value' => (string) ($chroma_data['tenant'] ?? ($chroma_defaults['tenant'] ?? 'default_tenant')),
                'placeholder' => __('default_tenant', 'gpt3-ai-content-generator'),
            ],
            [
                'type' => 'text',
                'id' => 'aipkit_chroma_database',
                'name' => 'chroma_database',
                'label' => __('Database', 'gpt3-ai-content-generator'),
                'description' => __('Use default_database for local Chroma.', 'gpt3-ai-content-generator'),
                'value' => (string) ($chroma_data['database'] ?? ($chroma_defaults['database'] ?? 'default_database')),
                'placeholder' => __('default_database', 'gpt3-ai-content-generator'),
            ],
            [
                'type' => 'select',
                'id' => 'aipkit_chroma_default_collection',
                'name' => 'chroma_default_collection',
                'label' => __('Collection', 'gpt3-ai-content-generator'),
                'description' => __('Default collection for Chroma operations.', 'gpt3-ai-content-generator'),
                'value' => (string) ($chroma_data['default_collection'] ?? ''),
                'options' => $chroma_collection_options,
                'empty_label' => __('Select a collection', 'gpt3-ai-content-generator'),
                'sync_id' => 'aipkit_sync_chroma_collections_btn',
                'sync_provider' => 'ChromaCollections',
                'sync_label' => __('Sync collections', 'gpt3-ai-content-generator'),
            ],
        ],
    ],
    'pexels' => [
        'display_name' => __('Pexels', 'gpt3-ai-content-generator'),
        'icon' => 'pexels.svg',
        'accent' => '#05a081',
        'credential' => [
            'id' => 'aipkit_pexels_api_key',
            'name' => 'pexels_api_key',
            'value' => $current_pexels_api_key,
            'label' => __('API key', 'gpt3-ai-content-generator'),
            'description' => __('Connect the Pexels stock photo library.', 'gpt3-ai-content-generator'),
            'placeholder' => __('e.g., 563492ad6f91700001000001...', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://www.pexels.com/api/new/',
        'key_link' => __('Get your Pexels API key', 'gpt3-ai-content-generator'),
        'connected' => $current_pexels_api_key !== '',
        'live_test' => true,
        'fields' => [],
    ],
    'pixabay' => [
        'display_name' => __('Pixabay', 'gpt3-ai-content-generator'),
        'icon' => 'pixabay.svg',
        'accent' => '#00ab6c',
        'credential' => [
            'id' => 'aipkit_pixabay_api_key',
            'name' => 'pixabay_api_key',
            'value' => $current_pixabay_api_key,
            'label' => __('API key', 'gpt3-ai-content-generator'),
            'description' => __('Connect the Pixabay stock media library.', 'gpt3-ai-content-generator'),
            'placeholder' => __('e.g., 12345678-a1b2c3d4e5f6...', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://pixabay.com/api/docs/',
        'key_link' => __('Get your Pixabay API key', 'gpt3-ai-content-generator'),
        'connected' => $current_pixabay_api_key !== '',
        'live_test' => true,
        'fields' => [],
    ],
];

$integration_groups = [
    'vector-databases' => [
        'label' => __('Vector databases', 'gpt3-ai-content-generator'),
        'description' => __('Store your knowledge base embeddings. Connect the one you use.', 'gpt3-ai-content-generator'),
        'providers' => ['pinecone', 'qdrant', 'chroma'],
    ],
    'media-generation' => [
        'label' => __('Media generation', 'gpt3-ai-content-generator'),
        'description' => '',
        'providers' => ['replicate', 'elevenlabs'],
    ],
    'stock-photos' => [
        'label' => __('Stock photos', 'gpt3-ai-content-generator'),
        'description' => '',
        'providers' => ['pexels', 'pixabay'],
    ],
];
?>

<div class="aipkit_settings_integration_groups">
    <?php foreach ($integration_groups as $group_slug => $group) : ?>
        <section class="aipkit_settings_integration_group" aria-labelledby="aipkit_settings_integration_group_<?php echo esc_attr($group_slug); ?>">
            <header class="aipkit_settings_integration_group_header">
                <h4 id="aipkit_settings_integration_group_<?php echo esc_attr($group_slug); ?>"><?php echo esc_html((string) $group['label']); ?></h4>
                <?php if ($group['description'] !== '') : ?>
                    <p><?php echo esc_html((string) $group['description']); ?></p>
                <?php endif; ?>
            </header>

            <div class="aipkit_settings_integration_cards">
                <?php foreach ($group['providers'] as $integration_slug) :
                    $integration_config = $integration_configs[$integration_slug];
        $is_connected = !empty($integration_config['connected']);
        $credential = $integration_config['credential'];
        $credential_value = (string) $credential['value'];
        $credential_mask = $is_connected && $credential_value !== ''
            ? $aipkit_format_credential_mask($credential_value)
            : '';
        $panel_id = 'aipkit_settings_integration_panel_' . $integration_slug;
        ?>
        <article
            id="aipkit_settings_integration_card_<?php echo esc_attr($integration_slug); ?>"
            class="aipkit_settings_provider_card aipkit_settings_integration_card"
            data-aipkit-integration-card="<?php echo esc_attr($integration_slug); ?>"
            data-aipkit-integration-connected="<?php echo $is_connected ? 'true' : 'false'; ?>"
            <?php echo !empty($integration_config['live_test']) ? 'data-aipkit-integration-live-test="true"' : ''; ?>
            style="--aipkit-provider-accent: <?php echo esc_attr((string) $integration_config['accent']); ?>;"
        >
            <button
                type="button"
                class="aipkit_settings_integration_summary"
                data-aipkit-integration-toggle
                aria-expanded="false"
                aria-controls="<?php echo esc_attr($panel_id); ?>"
            >
                <span class="aipkit_settings_provider_logo" aria-hidden="true">
                    <img src="<?php echo esc_url(WPAICG_PLUGIN_URL . 'admin/images/providers/' . $integration_config['icon']); ?>" alt="" />
                </span>
                <span class="aipkit_settings_provider_name"><?php echo esc_html((string) $integration_config['display_name']); ?></span>
                <span class="aipkit_settings_provider_status aipkit_settings_provider_status--connected" <?php echo $is_connected ? '' : 'hidden'; ?>><?php esc_html_e('Connected', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_settings_provider_status aipkit_settings_provider_status--disconnected" <?php echo $is_connected ? 'hidden' : ''; ?>><?php esc_html_e('Not connected', 'gpt3-ai-content-generator'); ?></span>
                <span class="dashicons dashicons-arrow-down-alt2 aipkit_settings_integration_chevron" aria-hidden="true"></span>
            </button>

            <div class="aipkit_settings_integration_body" id="<?php echo esc_attr($panel_id); ?>" data-aipkit-integration-panel hidden>
                <div class="aipkit_settings_integration_connection_fields">
                <?php if (!empty($integration_config['url'])) :
                    $url_field = $integration_config['url'];
                    ?>
                    <div class="aipkit_settings_integration_field">
                        <label class="aipkit_settings_integration_field_label" for="<?php echo esc_attr((string) $url_field['id']); ?>">
                            <span><?php echo esc_html((string) $url_field['label']); ?></span>
                            <span class="aipkit_form-label-helper"><?php echo esc_html((string) $url_field['description']); ?></span>
                        </label>
                        <input
                            type="url"
                            id="<?php echo esc_attr((string) $url_field['id']); ?>"
                            name="<?php echo esc_attr((string) $url_field['name']); ?>"
                            class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_integration_text_input"
                            value="<?php echo esc_attr((string) $url_field['value']); ?>"
                            placeholder="<?php echo esc_attr((string) $url_field['placeholder']); ?>"
                            <?php echo !empty($url_field['required']) ? 'data-aipkit-integration-required' : ''; ?>
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                        />
                    </div>
                <?php endif; ?>

                <div class="aipkit_settings_integration_field">
                    <label class="aipkit_settings_integration_field_label" for="<?php echo esc_attr((string) $credential['id']); ?>">
                        <span><?php echo esc_html((string) $credential['label']); ?></span>
                        <span class="aipkit_form-label-helper"><?php echo esc_html((string) $credential['description']); ?></span>
                    </label>
                    <div class="aipkit_settings_integration_credential_row">
                        <div class="aipkit_settings_provider_credential_wrap">
                            <input
                                type="password"
                                id="<?php echo esc_attr((string) $credential['id']); ?>"
                                name="<?php echo $is_connected ? '' : esc_attr((string) $credential['name']); ?>"
                                class="aipkit_form-input<?php echo empty($integration_config['live_test']) ? ' aipkit_autosave_trigger' : ''; ?> aipkit_settings_provider_credential aipkit_settings_integration_credential is-secret<?php echo $credential_mask !== '' ? ' is-visually-masked' : ''; ?>"
                                value=""
                                placeholder="<?php echo esc_attr((string) $credential['placeholder']); ?>"
                                data-aipkit-integration-credential
                                data-aipkit-integration-slug="<?php echo esc_attr($integration_slug); ?>"
                                data-aipkit-credential-name="<?php echo esc_attr((string) $credential['name']); ?>"
                                data-aipkit-has-credential="<?php echo $is_connected ? 'true' : 'false'; ?>"
                                <?php echo !empty($credential['required']) ? 'data-aipkit-integration-required' : ''; ?>
                                autocomplete="off"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-form-type="other"
                                <?php echo !empty($integration_config['live_test']) ? 'data-aipkit-settings-autosave-exclude="true"' : ''; ?>
                                <?php echo $credential_mask !== '' ? 'readonly' : ''; ?>
                            />
                            <span
                                class="aipkit_settings_provider_credential_mask"
                                data-aipkit-integration-credential-mask
                                aria-hidden="true"
                                <?php echo $credential_mask === '' ? 'hidden' : ''; ?>
                            ><?php echo esc_html($credential_mask); ?></span>
                        </div>

                        <div class="aipkit_settings_integration_connected_actions" <?php echo $is_connected && $credential_value !== '' ? '' : 'hidden'; ?>>
                            <button
                                type="button"
                                class="aipkit_settings_icon_button"
                                data-aipkit-integration-reveal
                                data-reveal-label="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>"
                                data-hide-label="<?php esc_attr_e('Hide API key', 'gpt3-ai-content-generator'); ?>"
                                aria-label="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>"
                                title="<?php esc_attr_e('Reveal API key', 'gpt3-ai-content-generator'); ?>"
                            >
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                            </button>
                        </div>

                        <button
                            type="button"
                            class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_connect aipkit_settings_integration_connect"
                            data-aipkit-integration-connect
                            <?php echo $is_connected ? 'hidden' : ''; ?>
                        >
                            <?php esc_html_e('Connect', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                    <a
                        href="<?php echo esc_url((string) $integration_config['key_url']); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="aipkit_settings_provider_key_link"
                    >
                        <?php echo esc_html((string) $integration_config['key_link']); ?>
                        <span aria-hidden="true">↗</span>
                    </a>
                </div>
                </div>

                <?php if (!empty($integration_config['fields'])) : ?>
                    <div class="aipkit_settings_integration_connected_fields" data-aipkit-integration-connected-fields <?php echo $is_connected ? '' : 'hidden'; ?>>
                    <?php foreach ($integration_config['fields'] as $field) : ?>
                        <div class="aipkit_settings_integration_option_row">
                            <label class="aipkit_settings_integration_field_label" for="<?php echo esc_attr((string) $field['id']); ?>">
                                <span><?php echo esc_html((string) $field['label']); ?></span>
                                <span class="aipkit_form-label-helper"><?php echo esc_html((string) $field['description']); ?></span>
                            </label>

                            <?php if ($field['type'] === 'select') : ?>
                                <div class="aipkit_settings_integration_option_control">
                                    <select
                                        id="<?php echo esc_attr((string) $field['id']); ?>"
                                        <?php echo !empty($field['name']) ? 'name="' . esc_attr((string) $field['name']) . '"' : ''; ?>
                                        class="aipkit_form-input<?php echo !empty($field['name']) ? ' aipkit_autosave_trigger' : ''; ?>"
                                        data-aipkit-empty-label="<?php echo esc_attr((string) $field['empty_label']); ?>"
                                    >
                                        <option value=""><?php echo esc_html((string) $field['empty_label']); ?></option>
                                        <?php foreach ($field['options'] as $option) : ?>
                                            <option value="<?php echo esc_attr((string) $option['value']); ?>" <?php selected((string) $field['value'], (string) $option['value']); ?>>
                                                <?php echo esc_html((string) $option['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button
                                        type="button"
                                        id="<?php echo esc_attr((string) $field['sync_id']); ?>"
                                        class="aipkit_sync_btn aipkit_settings_compact_sync_btn"
                                        data-provider="<?php echo esc_attr((string) $field['sync_provider']); ?>"
                                        aria-label="<?php echo esc_attr((string) $field['sync_label']); ?>"
                                        title="<?php echo esc_attr((string) $field['sync_label']); ?>"
                                        aria-busy="false"
                                    >
                                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                    </button>
                                </div>
                            <?php elseif ($field['type'] === 'text') : ?>
                                <input
                                    type="text"
                                    id="<?php echo esc_attr((string) $field['id']); ?>"
                                    name="<?php echo esc_attr((string) $field['name']); ?>"
                                    class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_integration_text_input"
                                    value="<?php echo esc_attr((string) $field['value']); ?>"
                                    placeholder="<?php echo esc_attr((string) $field['placeholder']); ?>"
                                    autocomplete="off"
                                    autocorrect="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                />
                            <?php else : ?>
                                <label class="aipkit_switch aipkit_settings_integration_toggle" for="<?php echo esc_attr((string) $field['id']); ?>">
                                    <input
                                        type="checkbox"
                                        id="<?php echo esc_attr((string) $field['id']); ?>"
                                        name="<?php echo esc_attr((string) $field['name']); ?>"
                                        class="aipkit_autosave_trigger"
                                        value="1"
                                        <?php checked(!empty($field['checked'])); ?>
                                        aria-label="<?php echo esc_attr((string) $field['label']); ?>"
                                    />
                                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                </label>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
