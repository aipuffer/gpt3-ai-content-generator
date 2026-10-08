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

// What a list to review shows: each name once, sorted, with its dimensions when the service reports them.
$integration_review_items = static function (array $items, array $keys) use ($normalize_synced_select_options): array {
    $dimensions = [];
    foreach ($items as $item) {
        $item = is_object($item) ? (array) $item : $item;
        if (!is_array($item)) {
            continue;
        }
        $name = '';
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && (string) $item[$key] !== '') {
                $name = trim(wp_strip_all_tags((string) $item[$key]));
                break;
            }
        }
        $config = isset($item['config']) && is_array($item['config']) ? $item['config'] : [];
        $dimension = $item['dimension'] ?? ($config['params']['vectors']['size'] ?? null);
        if ($name !== '' && is_numeric($dimension) && (int) $dimension > 0) {
            $dimensions[strtolower($name)] = (int) $dimension;
        }
    }
    return array_map(static function (array $option) use ($dimensions): array {
        return ['name' => $option['value'], 'dimension' => $dimensions[strtolower($option['value'])] ?? 0];
    }, $normalize_synced_select_options($items, $keys, $keys));
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
$storage_review_keys = ['name', 'index_name', 'collection_name', 'id'];

// Every service has the AI providers' panel: the key first, with Connect beside it and its get-a-key link;
// then anything else connecting needs (an endpoint); then what it holds or starts with; options; rare settings folded.
$integration_configs = [
    'pinecone' => [
        'display_name' => __('Pinecone', 'gpt3-ai-content-generator'),
        'icon' => 'pinecone.svg',
        'accent' => '#5b5ce2',
        'tagline' => __('Stores your knowledge base in Pinecone indexes.', 'gpt3-ai-content-generator'),
        'credential' => [
            'id' => 'aipkit_pinecone_api_key',
            'name' => 'pinecone_api_key',
            'value' => $current_pinecone_api_key,
            'placeholder' => __('Paste your Pinecone API key', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://app.pinecone.io/',
        'key_link' => __('Get your Pinecone API key', 'gpt3-ai-content-generator'),
        'connected' => $current_pinecone_api_key !== '',
        'sections' => [
            [
                'type' => 'review',
                'title' => __('Indexes', 'gpt3-ai-content-generator'),
                'items' => $integration_review_items(is_array($pinecone_index_list ?? null) ? $pinecone_index_list : [], $storage_review_keys),
                'keys' => $storage_review_keys,
                'show_items' => true,
                /* translators: %s: number of indexes. */
                'count_one' => _n('%s index', '%s indexes', 1, 'gpt3-ai-content-generator'),
                /* translators: %s: number of indexes. */
                'count_many' => _n('%s index', '%s indexes', 2, 'gpt3-ai-content-generator'),
                'empty' => __('No indexes yet', 'gpt3-ai-content-generator'),
                'help' => __('Choose one in the Knowledge Base or a chatbot.', 'gpt3-ai-content-generator'),
                'sync_provider' => 'PineconeIndexes',
                'sync_id' => 'aipkit_sync_pinecone_indexes_btn',
                'sync_label' => __('Refresh the index list', 'gpt3-ai-content-generator'),
            ],
        ],
    ],
    'qdrant' => [
        'display_name' => __('Qdrant', 'gpt3-ai-content-generator'),
        'icon' => 'qdrant.svg',
        'accent' => '#dc244c',
        'tagline' => __('Stores your knowledge base in Qdrant collections.', 'gpt3-ai-content-generator'),
        'credential' => [
            'id' => 'aipkit_qdrant_api_key',
            'name' => 'qdrant_api_key',
            'value' => $current_qdrant_api_key,
            'placeholder' => __('Paste your Qdrant API key', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://cloud.qdrant.io/',
        'key_link' => __('Get your Qdrant API key', 'gpt3-ai-content-generator'),
        'connected' => $current_qdrant_url !== '' && $current_qdrant_api_key !== '',
        'connection_fields' => [
            [
                'id' => 'url',
                'type' => 'url',
                'name' => 'qdrant_url',
                'label' => __('Endpoint', 'gpt3-ai-content-generator'),
                'description' => __('Your Qdrant Cloud or self-hosted endpoint.', 'gpt3-ai-content-generator'),
                'value' => $current_qdrant_url,
                'placeholder' => 'https://your-cluster.cloud.qdrant.io:6333',
                'required' => true,
                'monospace' => true,
            ],
        ],
        'sections' => [
            [
                'type' => 'review',
                'title' => __('Collections', 'gpt3-ai-content-generator'),
                'items' => $integration_review_items(is_array($qdrant_collection_list ?? null) ? $qdrant_collection_list : [], $storage_review_keys),
                'keys' => $storage_review_keys,
                'show_items' => true,
                /* translators: %s: number of collections. */
                'count_one' => _n('%s collection', '%s collections', 1, 'gpt3-ai-content-generator'),
                /* translators: %s: number of collections. */
                'count_many' => _n('%s collection', '%s collections', 2, 'gpt3-ai-content-generator'),
                'empty' => __('No collections yet', 'gpt3-ai-content-generator'),
                'help' => __('Choose one in the Knowledge Base or a chatbot.', 'gpt3-ai-content-generator'),
                'sync_provider' => 'QdrantCollections',
                'sync_id' => 'aipkit_sync_qdrant_collections_btn',
                'sync_label' => __('Refresh the collection list', 'gpt3-ai-content-generator'),
            ],
        ],
    ],
    'chroma' => [
        'display_name' => __('Chroma', 'gpt3-ai-content-generator'),
        'icon' => 'chroma.svg',
        'accent' => '#f59e0b',
        'tagline' => __('Stores your knowledge base in Chroma, hosted or on your own server.', 'gpt3-ai-content-generator'),
        'credential' => [
            'id' => 'aipkit_chroma_api_key',
            'name' => 'chroma_api_key',
            'value' => $current_chroma_api_key,
            'placeholder' => __('Paste your Chroma API key', 'gpt3-ai-content-generator'),
            'required' => false,
            'help' => __('Optional for a local server.', 'gpt3-ai-content-generator'),
        ],
        'key_url' => 'https://trychroma.com/',
        'key_link' => __('Get your Chroma API key', 'gpt3-ai-content-generator'),
        'connected' => $current_chroma_url !== '',
        'connection_fields' => [
            [
                'id' => 'url',
                'type' => 'url',
                'name' => 'chroma_url',
                'label' => __('Endpoint', 'gpt3-ai-content-generator'),
                'description' => __('Your Chroma Cloud or self-hosted endpoint.', 'gpt3-ai-content-generator'),
                'value' => $current_chroma_url,
                'placeholder' => 'https://api.trychroma.com',
                'required' => true,
                'monospace' => true,
            ],
        ],
        'sections' => [
            [
                'type' => 'review',
                'title' => __('Collections', 'gpt3-ai-content-generator'),
                'items' => $integration_review_items(is_array($chroma_collection_list ?? null) ? $chroma_collection_list : [], $storage_review_keys),
                'keys' => $storage_review_keys,
                'show_items' => true,
                /* translators: %s: number of collections. */
                'count_one' => _n('%s collection', '%s collections', 1, 'gpt3-ai-content-generator'),
                /* translators: %s: number of collections. */
                'count_many' => _n('%s collection', '%s collections', 2, 'gpt3-ai-content-generator'),
                'empty' => __('No collections yet', 'gpt3-ai-content-generator'),
                'help' => __('Choose one in the Knowledge Base or a chatbot.', 'gpt3-ai-content-generator'),
                'sync_provider' => 'ChromaCollections',
                'sync_id' => 'aipkit_sync_chroma_collections_btn',
                'sync_label' => __('Refresh the collection list', 'gpt3-ai-content-generator'),
            ],
        ],
        'fold' => [
            'title' => __('Tenant and database', 'gpt3-ai-content-generator'),
            'fields' => [
                [
                    'id' => 'tenant',
                    'type' => 'text',
                    'name' => 'chroma_tenant',
                    'label' => __('Tenant', 'gpt3-ai-content-generator'),
                    'description' => __('Keep default_tenant for a local server.', 'gpt3-ai-content-generator'),
                    'value' => (string) (($chroma_data['tenant'] ?? '') !== '' ? $chroma_data['tenant'] : ($chroma_defaults['tenant'] ?? 'default_tenant')),
                    'default' => (string) ($chroma_defaults['tenant'] ?? 'default_tenant'),
                    'monospace' => true,
                    'reset' => true,
                ],
                [
                    'id' => 'database',
                    'type' => 'text',
                    'name' => 'chroma_database',
                    'label' => __('Database', 'gpt3-ai-content-generator'),
                    'description' => __('Keep default_database for a local server.', 'gpt3-ai-content-generator'),
                    'value' => (string) (($chroma_data['database'] ?? '') !== '' ? $chroma_data['database'] : ($chroma_defaults['database'] ?? 'default_database')),
                    'default' => (string) ($chroma_defaults['database'] ?? 'default_database'),
                    'monospace' => true,
                    'reset' => true,
                ],
            ],
        ],
    ],
    'replicate' => [
        'display_name' => __('Replicate', 'gpt3-ai-content-generator'),
        'icon' => 'replicate.svg',
        'accent' => '#111827',
        'tagline' => __('More image models, run on Replicate.', 'gpt3-ai-content-generator'),
        'credential' => [
            'id' => 'aipkit_replicate_api_key',
            'name' => 'replicate_api_key',
            'value' => $current_replicate_api_key,
            'placeholder' => __('Paste your Replicate API key', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://replicate.com/account/api-tokens',
        'key_link' => __('Get your Replicate API key', 'gpt3-ai-content-generator'),
        'connected' => $current_replicate_api_key !== '',
        'sections' => [
            [
                'type' => 'review',
                'title' => __('Models', 'gpt3-ai-content-generator'),
                'items' => $integration_review_items(is_array($replicate_model_list ?? null) ? $replicate_model_list : [], ['id', 'name']),
                'keys' => ['id', 'name'],
                'show_items' => false,
                /* translators: %s: number of image models. */
                'count_one' => _n('%s image model', '%s image models', 1, 'gpt3-ai-content-generator'),
                /* translators: %s: number of image models. */
                'count_many' => _n('%s image model', '%s image models', 2, 'gpt3-ai-content-generator'),
                'empty' => __('No image models yet', 'gpt3-ai-content-generator'),
                'help' => __('Choose one where you make images.', 'gpt3-ai-content-generator'),
                'sync_provider' => 'Replicate',
                'sync_id' => 'aipkit_sync_replicate_models_btn',
                'sync_label' => __('Refresh the model list', 'gpt3-ai-content-generator'),
            ],
        ],
        'options' => [
            [
                'id' => 'disable_safety_checker',
                'type' => 'toggle',
                'name' => 'replicate_disable_safety_checker',
                'label' => __('Disable safety checker', 'gpt3-ai-content-generator'),
                'description' => __('Allow image requests without Replicate safety checks.', 'gpt3-ai-content-generator'),
                'value' => $replicate_disable_safety_checker ? '1' : '0',
            ],
        ],
    ],
    'elevenlabs' => [
        'display_name' => __('ElevenLabs', 'gpt3-ai-content-generator'),
        'icon' => 'elevenlabs.svg',
        'accent' => '#111827',
        'tagline' => __('Natural voices for spoken chatbot replies.', 'gpt3-ai-content-generator'),
        'credential' => [
            'id' => 'aipkit_elevenlabs_api_key',
            'name' => 'elevenlabs_api_key',
            'value' => $current_elevenlabs_api_key,
            'placeholder' => __('Paste your ElevenLabs API key', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://elevenlabs.io/app/settings/api-keys',
        'key_link' => __('Get your ElevenLabs API key', 'gpt3-ai-content-generator'),
        'connected' => $current_elevenlabs_api_key !== '',
        'sections' => [
            [
                'type' => 'picker',
                'title' => __('Default voice', 'gpt3-ai-content-generator'),
                'id' => 'aipkit_elevenlabs_voice_id',
                'name' => 'elevenlabs_voice_id',
                'value' => (string) ($elevenlabs_data['voice_id'] ?? ''),
                'options' => $elevenlabs_voice_options,
                'empty_label' => __('Choose a voice', 'gpt3-ai-content-generator'),
                'help' => __('New chatbots start with this voice.', 'gpt3-ai-content-generator'),
                'summary' => true,
                'sync_provider' => 'ElevenLabs',
                'sync_id' => 'aipkit_sync_elevenlabs_voices',
                'sync_label' => __('Refresh the voice list', 'gpt3-ai-content-generator'),
            ],
            [
                'type' => 'picker',
                'title' => __('Default model', 'gpt3-ai-content-generator'),
                'id' => 'aipkit_elevenlabs_tts_model_id',
                'name' => 'elevenlabs_model_id',
                'value' => (string) ($elevenlabs_data['model_id'] ?? ''),
                'options' => $elevenlabs_model_options,
                'empty_label' => __('Choose a model', 'gpt3-ai-content-generator'),
                'help' => __('New chatbots start with this model.', 'gpt3-ai-content-generator'),
                'sync_provider' => 'ElevenLabsModels',
                'sync_id' => 'aipkit_sync_elevenlabs_models_btn',
                'sync_label' => __('Refresh the model list', 'gpt3-ai-content-generator'),
            ],
        ],
    ],
    'pexels' => [
        'display_name' => __('Pexels', 'gpt3-ai-content-generator'),
        'icon' => 'pexels.svg',
        'accent' => '#05a081',
        'tagline' => __('Free stock photos for your posts.', 'gpt3-ai-content-generator'),
        'credential' => [
            'id' => 'aipkit_pexels_api_key',
            'name' => 'pexels_api_key',
            'value' => $current_pexels_api_key,
            'placeholder' => __('Paste your Pexels API key', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://www.pexels.com/api/new/',
        'key_link' => __('Get your Pexels API key', 'gpt3-ai-content-generator'),
        'connected' => $current_pexels_api_key !== '',
        'live_test' => true,
    ],
    'pixabay' => [
        'display_name' => __('Pixabay', 'gpt3-ai-content-generator'),
        'icon' => 'pixabay.svg',
        'accent' => '#00ab6c',
        'tagline' => __('Free stock images for your posts.', 'gpt3-ai-content-generator'),
        'credential' => [
            'id' => 'aipkit_pixabay_api_key',
            'name' => 'pixabay_api_key',
            'value' => $current_pixabay_api_key,
            'placeholder' => __('Paste your Pixabay API key', 'gpt3-ai-content-generator'),
            'required' => true,
        ],
        'key_url' => 'https://pixabay.com/api/docs/',
        'key_link' => __('Get your Pixabay API key', 'gpt3-ai-content-generator'),
        'connected' => $current_pixabay_api_key !== '',
        'live_test' => true,
    ],
];

$integration_groups = [
    'vector-databases' => [
        'label' => __('Knowledge storage', 'gpt3-ai-content-generator'),
        'description' => __('Where your knowledge base can be stored. Connect the one you use.', 'gpt3-ai-content-generator'),
        'providers' => ['pinecone', 'qdrant', 'chroma'],
    ],
    'media-generation' => [
        'label' => __('Images and voices', 'gpt3-ai-content-generator'),
        'description' => '',
        'providers' => ['replicate', 'elevenlabs'],
    ],
    'stock-photos' => [
        'label' => __('Stock photos', 'gpt3-ai-content-generator'),
        'description' => '',
        'providers' => ['pexels', 'pixabay'],
    ],
];

$integration_count_text = static function (array $section, int $count): string {
    return $count > 0 ? sprintf($count === 1 ? $section['count_one'] : $section['count_many'], number_format_i18n($count)) : (string) $section['empty'];
};

// A connected row says what the service holds or starts with, the way an AI row names its model.
$integration_summary = static function (array $config) use ($integration_count_text): string {
    foreach ((array) ($config['sections'] ?? []) as $section) {
        if ($section['type'] === 'review') {
            return $integration_count_text($section, count($section['items']));
        }
        if (!empty($section['summary']) && $section['value'] !== '') {
            foreach ($section['options'] as $option) {
                if ($option['value'] === $section['value']) {
                    return $option['label'];
                }
            }
            return $section['value'];
        }
    }
    return __('Connected', 'gpt3-ai-content-generator');
};

$integration_render_sync_button = static function (array $section): void {
    ?>
    <button
        type="button"
        id="<?php echo esc_attr((string) $section['sync_id']); ?>"
        class="aipkit_sync_btn aipkit_settings_compact_sync_btn"
        data-provider="<?php echo esc_attr((string) $section['sync_provider']); ?>"
        aria-label="<?php echo esc_attr((string) $section['sync_label']); ?>"
        title="<?php echo esc_attr((string) $section['sync_label']); ?>"
        aria-busy="false"
    >
        <span class="dashicons dashicons-update" aria-hidden="true"></span>
    </button>
    <?php
};

$integration_review_limit = 6;
// The page shows the services first and Stock photos on its own after Apps, so each part renders its own groups.
$integration_part = isset($aipkit_integration_part) ? (string) $aipkit_integration_part : 'all';
if ($integration_part === 'services') {
    unset($integration_groups['stock-photos']);
} elseif ($integration_part === 'stock-photos') {
    $integration_groups = array_intersect_key($integration_groups, ['stock-photos' => true]);
}
?>

<div class="aipkit_settings_integration_groups">
    <?php foreach ($integration_groups as $group_slug => $group) : ?>
        <section class="aipkit_settings_part aipkit_settings_integration_group" aria-labelledby="aipkit_settings_integration_group_<?php echo esc_attr($group_slug); ?>">
            <header class="aipkit_settings_integration_group_header">
                <h4 class="aipkit_settings_group_title" id="aipkit_settings_integration_group_<?php echo esc_attr($group_slug); ?>"><?php echo esc_html((string) $group['label']); ?></h4>
                <?php if ($group['description'] !== '') : ?>
                    <p><?php echo esc_html((string) $group['description']); ?></p>
                <?php endif; ?>
            </header>

            <div class="aipkit_settings_provider_cards aipkit_settings_integration_cards">
                <?php foreach ($group['providers'] as $integration_slug) :
                    $integration_config = $integration_configs[$integration_slug];
                    $is_connected = !empty($integration_config['connected']);
                    $is_live_test = !empty($integration_config['live_test']);
                    $credential = $integration_config['credential'];
                    $credential_value = (string) $credential['value'];
                    $has_credential = $credential_value !== '';
                    $credential_mask = $is_connected && $has_credential ? $aipkit_format_credential_mask($credential_value) : '';
                    $display_name = (string) $integration_config['display_name'];
                    $modal_key = 'integration-' . $integration_slug;
                    $modal_id = 'aipkit_settings_integration_' . $integration_slug . '_modal';
                    $modal_title_id = $modal_id . '_title';
                    $icon_url = WPAICG_PLUGIN_URL . 'admin/images/providers/' . $integration_config['icon'];
                    $sync_providers = array_column((array) ($integration_config['sections'] ?? []), 'sync_provider');
                    ?>
                    <article
                        id="aipkit_settings_integration_card_<?php echo esc_attr($integration_slug); ?>"
                        class="aipkit_settings_provider_card aipkit_settings_integration_card"
                        data-aipkit-integration-card="<?php echo esc_attr($integration_slug); ?>"
                        data-aipkit-integration-connected="<?php echo $is_connected ? 'true' : 'false'; ?>"
                        data-aipkit-provider-connected="<?php echo $is_connected ? 'true' : 'false'; ?>"
                        <?php echo $is_live_test ? 'data-aipkit-integration-live-test="true"' : ''; ?>
                        <?php echo $sync_providers ? 'data-aipkit-integration-sync="' . esc_attr(implode(' ', $sync_providers)) . '"' : ''; ?>
                        style="--aipkit-provider-accent: <?php echo esc_attr((string) $integration_config['accent']); ?>;"
                    >
                        <div class="aipkit_settings_provider_card_main">
                            <span class="aipkit_settings_provider_logo" aria-hidden="true"><img src="<?php echo esc_url($icon_url); ?>" alt="" /></span>
                            <div class="aipkit_settings_provider_card_text">
                                <div class="aipkit_settings_provider_card_title">
                                    <h4 class="aipkit_settings_provider_name"><?php echo esc_html($display_name); ?></h4>
                                </div>
                                <p class="aipkit_settings_provider_card_status">
                                    <span class="aipkit_settings_provider_status aipkit_settings_provider_status--connected" <?php echo $is_connected ? '' : 'hidden'; ?>><?php esc_html_e('Connected', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_settings_provider_status aipkit_settings_provider_status--disconnected" <?php echo $is_connected ? 'hidden' : ''; ?>><?php esc_html_e('Not connected', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_settings_provider_status aipkit_settings_provider_status--invalid" data-aipkit-integration-invalid-status hidden><?php esc_html_e('Invalid key', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_settings_provider_status aipkit_settings_provider_status--sync-error" data-aipkit-integration-sync-error-status hidden><?php esc_html_e('Sync failed', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_settings_provider_summary" data-aipkit-integration-summary data-connected-label="<?php esc_attr_e('Connected', 'gpt3-ai-content-generator'); ?>" <?php echo $is_connected ? '' : 'hidden'; ?>><?php echo esc_html($integration_summary($integration_config)); ?></span>
                                    <span class="aipkit_settings_provider_tagline"><?php echo esc_html((string) $integration_config['tagline']); ?></span>
                                </p>
                            </div>
                            <button
                                type="button"
                                class="aipkit_settings_provider_card_action"
                                data-aipkit-provider-settings-open="<?php echo esc_attr($modal_key); ?>"
                                aria-haspopup="dialog"
                                aria-controls="<?php echo esc_attr($modal_id); ?>"
                            >
                                <span class="aipkit_settings_provider_card_manage">
                                    <?php /* translators: %s: connected service name, e.g. Pinecone. */ ?>
                                    <span class="screen-reader-text"><?php echo esc_html(sprintf(__('Manage %s', 'gpt3-ai-content-generator'), $display_name)); ?></span>
                                    <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                                </span>
                                <span class="aipkit_settings_provider_card_connect">
                                    <?php esc_html_e('Connect', 'gpt3-ai-content-generator'); ?>
                                    <span class="screen-reader-text"><?php echo esc_html($display_name); ?></span>
                                </span>
                            </button>
                        </div>

                        <div
                            class="aipkit-modal-overlay aipkit_settings_provider_modal"
                            id="<?php echo esc_attr($modal_id); ?>"
                            data-aipkit-provider-modal="<?php echo esc_attr($modal_key); ?>"
                            aria-hidden="true"
                        >
                            <div class="aipkit-modal-content aipkit_settings_provider_panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($modal_title_id); ?>">
                                <div class="aipkit_settings_provider_panel_header">
                                    <span class="aipkit_settings_provider_logo" aria-hidden="true"><img src="<?php echo esc_url($icon_url); ?>" alt="" /></span>
                                    <div class="aipkit_settings_provider_panel_heading">
                                        <div class="aipkit_settings_provider_panel_title_row">
                                            <h2 class="aipkit_settings_provider_panel_title" id="<?php echo esc_attr($modal_title_id); ?>"><?php echo esc_html($display_name); ?></h2>
                                            <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--on"><?php esc_html_e('Connected', 'gpt3-ai-content-generator'); ?></span>
                                            <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--off"><?php esc_html_e('Not connected', 'gpt3-ai-content-generator'); ?></span>
                                            <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--invalid"><?php esc_html_e('Key rejected', 'gpt3-ai-content-generator'); ?></span>
                                            <span class="aipkit_settings_provider_pill aipkit_settings_provider_pill--error"><?php esc_html_e('Sync failed', 'gpt3-ai-content-generator'); ?></span>
                                        </div>
                                        <p class="aipkit_settings_provider_panel_hint"><?php echo esc_html((string) $integration_config['tagline']); ?></p>
                                    </div>
                                    <button type="button" class="aipkit_settings_provider_panel_close" data-aipkit-provider-modal-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                                </div>

                                <div class="aipkit_settings_provider_panel_body">
                                    <section class="aipkit_settings_provider_panel_section aipkit_settings_provider_connection_fields">
                                        <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Connection', 'gpt3-ai-content-generator'); ?></h3>
                                        <?php // The key always comes first, with Connect beside it and its get-a-key link; an endpoint follows. ?>
                                        <div class="aipkit_settings_provider_field">
                                            <label class="aipkit_settings_provider_option_label" for="<?php echo esc_attr((string) $credential['id']); ?>"><?php esc_html_e('API key', 'gpt3-ai-content-generator'); ?></label>
                                            <div class="aipkit_settings_provider_credential_row">
                                                <div class="aipkit_settings_provider_credential_wrap">
                                                    <?php // A saved key shows masked; clicking the field shows it, and clicking away masks it again. ?>
                                                    <input
                                                        type="password"
                                                        id="<?php echo esc_attr((string) $credential['id']); ?>"
                                                        name="<?php echo $credential_mask !== '' ? '' : esc_attr((string) $credential['name']); ?>"
                                                        class="aipkit_form-input<?php echo $is_live_test ? '' : ' aipkit_autosave_trigger'; ?> aipkit_settings_provider_credential aipkit_settings_integration_credential is-secret<?php echo $credential_mask !== '' ? ' is-visually-masked' : ''; ?>"
                                                        value=""
                                                        placeholder="<?php echo esc_attr((string) $credential['placeholder']); ?>"
                                                        data-aipkit-integration-credential
                                                        data-aipkit-integration-slug="<?php echo esc_attr($integration_slug); ?>"
                                                        data-aipkit-credential-name="<?php echo esc_attr((string) $credential['name']); ?>"
                                                        data-aipkit-has-credential="<?php echo $credential_mask !== '' ? 'true' : 'false'; ?>"
                                                        <?php echo !empty($credential['required']) ? 'data-aipkit-integration-required' : ''; ?>
                                                        autocomplete="off"
                                                        autocorrect="off"
                                                        autocapitalize="off"
                                                        spellcheck="false"
                                                        data-lpignore="true"
                                                        data-1p-ignore="true"
                                                        data-form-type="other"
                                                        <?php echo $is_live_test ? 'data-aipkit-settings-autosave-exclude="true"' : ''; ?>
                                                        <?php echo $credential_mask !== '' ? 'readonly' : ''; ?>
                                                    />
                                                    <span
                                                        class="aipkit_settings_provider_credential_mask"
                                                        data-aipkit-integration-credential-mask
                                                        aria-hidden="true"
                                                        <?php echo $credential_mask === '' ? 'hidden' : ''; ?>
                                                    ><?php echo esc_html($credential_mask); ?></span>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_connect"
                                                    data-aipkit-integration-connect
                                                    <?php echo $is_connected ? 'hidden' : ''; ?>
                                                ><?php esc_html_e('Connect', 'gpt3-ai-content-generator'); ?></button>
                                            </div>
                                            <?php if (!empty($credential['help'])) : ?>
                                                <span class="aipkit_settings_provider_option_help"><?php echo esc_html((string) $credential['help']); ?></span>
                                            <?php endif; ?>
                                            <?php // The get-a-key link always sits by the field; once a key is saved, how to replace it sits beside it. Disconnect is in the footer. ?>
                                            <div class="aipkit_settings_provider_field_links">
                                                <span class="aipkit_settings_provider_option_help" data-aipkit-integration-replace-help <?php echo $credential_mask !== '' ? '' : 'hidden'; ?>><?php esc_html_e('Paste a new key to replace it. It saves by itself.', 'gpt3-ai-content-generator'); ?></span>
                                                <a class="aipkit_settings_provider_key_link" href="<?php echo esc_url((string) $integration_config['key_url']); ?>" target="_blank" rel="noopener noreferrer">
                                                    <span><?php echo esc_html((string) $integration_config['key_link']); ?></span>
                                                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                                </a>
                                            </div>
                                        </div>
                                        <?php foreach ((array) ($integration_config['connection_fields'] ?? []) as $integration_field) {
                                            $aipkit_render_option_field($integration_field, $integration_slug);
                                        } ?>
                                        <div
                                            class="aipkit_settings_provider_error"
                                            data-aipkit-integration-error
                                            <?php /* translators: %s: connected service name, e.g. Pinecone. */ ?>
                                            data-invalid-message="<?php echo esc_attr(sprintf(__('%s rejected this key. It may be wrong, revoked or out of credit. Check it or create a new one, then paste it again.', 'gpt3-ai-content-generator'), $display_name)); ?>"
                                            <?php /* translators: %s: connected service name, e.g. Pinecone. */ ?>
                                            data-sync-message="<?php echo esc_attr(sprintf(__('We couldn’t reach %s. Check the connection and try again.', 'gpt3-ai-content-generator'), $display_name)); ?>"
                                            role="alert"
                                            hidden
                                        >
                                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                            <div class="aipkit_settings_provider_error_content">
                                                <p data-aipkit-integration-error-message></p>
                                                <details class="aipkit_settings_provider_error_details" data-aipkit-integration-error-details hidden>
                                                    <summary><?php esc_html_e('View details', 'gpt3-ai-content-generator'); ?></summary>
                                                    <p data-aipkit-integration-error-technical></p>
                                                </details>
                                            </div>
                                        </div>
                                    </section>

                                    <?php foreach ((array) ($integration_config['sections'] ?? []) as $integration_section) :
                                        $is_picker = $integration_section['type'] === 'picker';
                                        ?>
                                        <section class="aipkit_settings_provider_panel_section" data-aipkit-provider-connected-only <?php echo $is_connected ? '' : 'hidden'; ?>>
                                            <?php if ($is_picker) : ?>
                                                <label class="aipkit_settings_provider_panel_section_title" for="<?php echo esc_attr((string) $integration_section['id']); ?>"><?php echo esc_html((string) $integration_section['title']); ?></label>
                                            <?php else : ?>
                                                <h3 class="aipkit_settings_provider_panel_section_title"><?php echo esc_html((string) $integration_section['title']); ?></h3>
                                            <?php endif; ?>
                                            <div class="aipkit_settings_provider_model_control">
                                                <?php if ($is_picker) : ?>
                                                    <div class="aipkit_settings_provider_model_select_row">
                                                        <select
                                                            id="<?php echo esc_attr((string) $integration_section['id']); ?>"
                                                            name="<?php echo esc_attr((string) $integration_section['name']); ?>"
                                                            class="aipkit_form-input aipkit_autosave_trigger aipkit_settings_integration_select"
                                                            data-aipkit-empty-label="<?php echo esc_attr((string) $integration_section['empty_label']); ?>"
                                                            <?php echo !empty($integration_section['summary']) ? 'data-aipkit-integration-summary-source' : ''; ?>
                                                        >
                                                            <option value=""><?php echo esc_html((string) $integration_section['empty_label']); ?></option>
                                                            <?php foreach ($integration_section['options'] as $option) : ?>
                                                                <option value="<?php echo esc_attr((string) $option['value']); ?>" <?php selected((string) $integration_section['value'], (string) $option['value']); ?>><?php echo esc_html((string) $option['label']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <?php $integration_render_sync_button($integration_section); ?>
                                                    </div>
                                                <?php else :
                                                    // Lists to review: what the account holds, picked where it's used, so nothing here saves.
                                                    $review_items = $integration_section['items'];
                                                    $show_items = !empty($integration_section['show_items']);
                                                    ?>
                                                    <div
                                                        class="aipkit_settings_integration_review"
                                                        data-aipkit-integration-review="<?php echo esc_attr((string) $integration_section['sync_provider']); ?>"
                                                        data-keys="<?php echo esc_attr(implode(',', $integration_section['keys'])); ?>"
                                                        data-show-items="<?php echo $show_items ? 'true' : 'false'; ?>"
                                                        data-limit="<?php echo esc_attr((string) $integration_review_limit); ?>"
                                                        data-count-one="<?php echo esc_attr((string) $integration_section['count_one']); ?>"
                                                        data-count-many="<?php echo esc_attr((string) $integration_section['count_many']); ?>"
                                                        data-empty="<?php echo esc_attr((string) $integration_section['empty']); ?>"
                                                        <?php /* translators: %s: number of vector dimensions. */ ?>
                                                        data-dimensions="<?php esc_attr_e('%s dimensions', 'gpt3-ai-content-generator'); ?>"
                                                        data-more="<?php
                                                        /* translators: %s: how many more items the list holds. */
                                                        esc_attr_e('and %s more', 'gpt3-ai-content-generator');
                                                        ?>"
                                                    >
                                                        <div class="aipkit_settings_integration_review_head">
                                                            <span class="aipkit_settings_integration_review_count" data-aipkit-integration-review-count><?php echo esc_html($integration_count_text($integration_section, count($review_items))); ?></span>
                                                            <?php $integration_render_sync_button($integration_section); ?>
                                                        </div>
                                                        <ul class="aipkit_settings_integration_review_list" data-aipkit-integration-review-list <?php echo $show_items && $review_items ? '' : 'hidden'; ?>>
                                                            <?php if ($show_items) :
                                                                foreach (array_slice($review_items, 0, $integration_review_limit) as $review_item) : ?>
                                                                    <li>
                                                                        <span class="aipkit_settings_integration_review_name"><?php echo esc_html((string) $review_item['name']); ?></span>
                                                                        <?php if ($review_item['dimension'] > 0) : ?>
                                                                            <?php /* translators: %s: number of vector dimensions. */ ?>
                                                                            <span class="aipkit_settings_integration_review_meta"><?php echo esc_html(sprintf(__('%s dimensions', 'gpt3-ai-content-generator'), number_format_i18n($review_item['dimension']))); ?></span>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach;
                                                                if (count($review_items) > $integration_review_limit) : ?>
                                                                    <li class="aipkit_settings_integration_review_more"><?php
                                                                    /* translators: %s: how many more items the list holds. */
                                                                    echo esc_html(sprintf(__('and %s more', 'gpt3-ai-content-generator'), number_format_i18n(count($review_items) - $integration_review_limit)));
                                                                    ?></li>
                                                                <?php endif;
                                                            endif; ?>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="aipkit_settings_provider_model_meta">
                                                    <span class="aipkit_settings_provider_option_help"><?php echo esc_html((string) $integration_section['help']); ?></span>
                                                    <span
                                                        class="aipkit_settings_provider_model_last_synced"
                                                        data-aipkit-provider-last-synced="<?php echo esc_attr((string) $integration_section['sync_provider']); ?>"
                                                        data-synced-at="<?php echo esc_attr((string) ($aipkit_model_sync_timestamps[$integration_section['sync_provider']] ?? '')); ?>"
                                                        aria-live="polite"
                                                        hidden
                                                    ></span>
                                                </div>
                                            </div>
                                        </section>
                                    <?php endforeach; ?>

                                    <?php if (!empty($integration_config['options'])) : ?>
                                        <section class="aipkit_settings_provider_panel_section aipkit_settings_provider_options" data-aipkit-provider-connected-only <?php echo $is_connected ? '' : 'hidden'; ?>>
                                            <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Options', 'gpt3-ai-content-generator'); ?></h3>
                                            <div class="aipkit_settings_provider_option_list">
                                                <?php foreach ($integration_config['options'] as $integration_field) {
                                                    $aipkit_render_option_field($integration_field, $integration_slug);
                                                } ?>
                                            </div>
                                        </section>
                                    <?php endif; ?>

                                    <?php if (!empty($integration_config['fold'])) : ?>
                                        <details
                                            class="aipkit_settings_provider_fold"
                                            data-aipkit-provider-connected-only
                                            <?php /* translators: %s: a setting's name, e.g. "Compatibility mode". */ ?>
                                            data-on-template="<?php esc_attr_e('%s on', 'gpt3-ai-content-generator'); ?>"
                                            <?php /* translators: %s: a setting's name, e.g. "Compatibility mode". */ ?>
                                            data-off-template="<?php esc_attr_e('%s off', 'gpt3-ai-content-generator'); ?>"
                                            <?php echo $is_connected ? '' : 'hidden'; ?>
                                        >
                                            <summary>
                                                <span class="aipkit_settings_provider_option_copy">
                                                    <span class="aipkit_settings_provider_option_label"><?php echo esc_html((string) $integration_config['fold']['title']); ?></span>
                                                    <span class="aipkit_settings_provider_option_help"><?php echo esc_html($aipkit_fold_summary($integration_config['fold']['fields'])); ?></span>
                                                </span>
                                                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                            </summary>
                                            <div class="aipkit_settings_provider_fold_body">
                                                <?php foreach ($integration_config['fold']['fields'] as $integration_field) {
                                                    $aipkit_render_option_field($integration_field, $integration_slug);
                                                } ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </div>

                                <div class="aipkit_settings_provider_modal_footer">
                                    <?php // Disconnecting sits apart from the key, the same for every service. ?>
                                    <button
                                        type="button"
                                        class="aipkit_settings_provider_remove"
                                        data-aipkit-integration-remove
                                        data-aipkit-provider-connected-only
                                        <?php /* translators: %s: connected service name, e.g. Pinecone. */ ?>
                                        data-confirm-title="<?php echo esc_attr(sprintf(__('Disconnect %s?', 'gpt3-ai-content-generator'), $display_name)); ?>"
                                        <?php /* translators: %s: connected service name, e.g. Pinecone. */ ?>
                                        data-confirm-text="<?php echo esc_attr(sprintf(__('Tools that use %s stop working until you connect it again.', 'gpt3-ai-content-generator'), $display_name)); ?>"
                                        data-confirm-button="<?php esc_attr_e('Disconnect', 'gpt3-ai-content-generator'); ?>"
                                        data-cancel-button="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"
                                        <?php echo $is_connected ? '' : 'hidden'; ?>
                                    ><?php
                                        /* translators: %s: connected service name, e.g. Pinecone. */
                                        echo esc_html(sprintf(__('Disconnect %s', 'gpt3-ai-content-generator'), $display_name));
                                    ?></button>
                                    <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_settings_provider_done" data-aipkit-provider-modal-close><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
