<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\AIPKit_Providers;
use WPAICG\aipkit_dashboard;
use WPAICG\AIPKIT_AI_Settings;

// Variable definitions
$is_pro = aipkit_dashboard::is_pro_plan();
$new_ai_selection = AIPKit_Providers::get_new_text_generation_selection();
$default_provider = strtolower($new_ai_selection['provider_key'] ?? 'openai');
$default_model = (string) ($new_ai_selection['model'] ?? '');
$ai_parameters = AIPKIT_AI_Settings::get_ai_parameters();
$default_temperature = $ai_parameters['temperature'] ?? 1.0;

$providers_for_select = AIPKit_Providers::get_text_generation_providers(true, true, true);

$available_post_types = get_post_types(['public' => true], 'objects');
unset($available_post_types['attachment']);

$current_user_id = get_current_user_id();
// Minimal safeguard: avoid loading thousands of users which can freeze the UI.
// Load up to a small cap and ensure current user is present.
$__aipkit_user_list_cap = 200;
$users_for_author = get_users([
    'orderby' => 'display_name',
    'order'   => 'ASC',
    'fields'  => ['ID', 'display_name', 'user_login'],
    'number'  => $__aipkit_user_list_cap,
]);
if ($current_user_id) {
    $has_current_user = false;
    foreach ($users_for_author as $u) {
        if ((int) $u->ID === (int) $current_user_id) { $has_current_user = true; break; }
    }
    if (!$has_current_user) {
        $u = get_user_by('id', $current_user_id);
        if ($u && isset($u->ID)) {
            $users_for_author[] = (object) [
                'ID' => (int) $u->ID,
                'display_name' => (string) $u->display_name,
                'user_login' => (string) $u->user_login,
            ];
        }
    }
}

$post_statuses = [
    'draft' => __('Draft', 'gpt3-ai-content-generator'),
    'publish' => __('Publish', 'gpt3-ai-content-generator'),
    'pending' => __('Pending Review', 'gpt3-ai-content-generator'),
    'private' => __('Private', 'gpt3-ai-content-generator'),
];

$wp_categories = get_categories(['hide_empty' => false]);
$task_frequencies = [
    'one-time' => __('One-time', 'gpt3-ai-content-generator'),
    'aipkit_five_minutes' => __('Every 5 Minutes', 'gpt3-ai-content-generator'),
    'aipkit_fifteen_minutes' => __('Every 15 Minutes', 'gpt3-ai-content-generator'),
    'aipkit_thirty_minutes' => __('Every 30 Minutes', 'gpt3-ai-content-generator'),
    'hourly' => __('Hourly', 'gpt3-ai-content-generator'),
    'twicedaily' => __('Twice Daily', 'gpt3-ai-content-generator'),
    'daily' => __('Daily', 'gpt3-ai-content-generator'),
    'weekly' => __('Weekly', 'gpt3-ai-content-generator'),
];

// --- Load Vector Store Data for UI ---
$openai_vector_stores = [];
$google_file_search_stores = [];
$pinecone_indexes = [];
$qdrant_collections = [];
$chroma_collections = [];
$embedding_provider_options = [];
$embedding_models_by_provider = [];
if (class_exists(AIPKit_Providers::class)) {
    $vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('content_writer_ui');
    $openai_vector_stores = isset($vector_store_localization['openai_vector_stores']) && is_array($vector_store_localization['openai_vector_stores'])
        ? $vector_store_localization['openai_vector_stores']
        : [];
    $google_file_search_stores = isset($vector_store_localization['google_file_search_stores']) && is_array($vector_store_localization['google_file_search_stores'])
        ? $vector_store_localization['google_file_search_stores']
        : [];
    $pinecone_indexes = isset($vector_store_localization['pinecone_indexes']) && is_array($vector_store_localization['pinecone_indexes'])
        ? $vector_store_localization['pinecone_indexes']
        : [];
    $qdrant_collections = isset($vector_store_localization['qdrant_collections']) && is_array($vector_store_localization['qdrant_collections'])
        ? $vector_store_localization['qdrant_collections']
        : [];
    $chroma_collections = isset($vector_store_localization['chroma_collections']) && is_array($vector_store_localization['chroma_collections'])
        ? $vector_store_localization['chroma_collections']
        : [];
    $embedding_provider_options = AIPKit_Providers::get_embedding_provider_map('content_writer_ui');
    $embedding_models_by_provider = AIPKit_Providers::get_embedding_models_by_provider('content_writer_ui');
}
// --- End Load Vector Store Data ---
