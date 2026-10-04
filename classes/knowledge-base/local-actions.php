<?php

namespace WPAICG\Dashboard\Ajax;

use WP_Error;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service;
use WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every action checks the nonce in check_any_module_access_permissions() first.

/**
 * Knowledge Base and chatbot Training actions for the built-in store (shown to users as "AI Puffer").
 * Same request shapes as the Qdrant handler: stores are named, text arrives as `text_content`.
 */
class AIPKit_Vector_Store_Local_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private const NONCE = 'aipkit_vector_store_local_nonce';

    private function strategy(): AIPKit_Vector_Local_Strategy
    {
        if (!class_exists(AIPKit_Vector_Local_Strategy::class)) { require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php'; }
        return new AIPKit_Vector_Local_Strategy();
    }

    private function allowed(): bool
    {
        $check = $this->check_any_module_access_permissions(['sources', 'chatbot'], self::NONCE);
        if (is_wp_error($check)) { $this->send_wp_error($check); return false; }
        return true;
    }

    private function field(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    private function log(array $data): void
    {
        global $wpdb;
        $data = wp_parse_args($data, ['user_id' => get_current_user_id(), 'timestamp' => current_time('mysql', 1), 'provider' => 'Local', 'status' => 'info', 'message' => '']);
        $columns = ['user_id', 'timestamp', 'provider', 'vector_store_id', 'vector_store_name', 'post_id', 'post_title', 'status', 'message', 'indexed_content', 'file_id', 'batch_id', 'embedding_provider', 'embedding_model'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned log table.
        $wpdb->insert($wpdb->prefix . 'aipkit_vector_data_source', array_intersect_key($data, array_flip($columns)));
    }

    /** Log callback for the shared file ingestion job. */
    public function _log_vector_data_source_entry(array $log_data): void
    {
        $this->log($log_data);
    }

    public function ajax_list_stores(): void
    {
        if (!$this->allowed()) { return; }
        wp_send_json_success(['stores' => $this->strategy()->list_indexes(), 'limits' => ['perStore' => AIPKit_Vector_Local_Strategy::max_chunks_per_store(), 'perSite' => AIPKit_Vector_Local_Strategy::max_chunks_per_site()]]);
    }

    /** Create a dimensioned store; each ingestion request supplies its embedding model. */
    public function ajax_create_store(): void
    {
        if (!$this->allowed()) { return; }
        $name = $this->field('name');
        if ($name === '') { $this->send_wp_error(new WP_Error('missing_name', __('Name your knowledge base.', 'gpt3-ai-content-generator'), ['status' => 400])); return; }
        $dimension = (int) $this->field('dimension');
        if ($dimension < 1 || $dimension > 65536) { $this->send_wp_error(new WP_Error('invalid_dimension', __('Enter a dimension between 1 and 65536.', 'gpt3-ai-content-generator'), ['status' => 400])); return; }
        $strategy = $this->strategy();
        if ($strategy->get_store(AIPKit_Vector_Local_Strategy::store_id($name))) {
            $this->send_wp_error(new WP_Error('store_exists', __('A knowledge base with this name already exists.', 'gpt3-ai-content-generator'), ['status' => 400])); return;
        }
        $store = $strategy->create_index_if_not_exists($name, ['dimension' => $dimension]);
        if (is_wp_error($store)) { $this->send_wp_error($store); return; }
        wp_send_json_success(['store' => $store, 'message' => __('Knowledge base created.', 'gpt3-ai-content-generator')]);
    }

    public function ajax_delete_store(): void
    {
        if (!$this->allowed()) { return; }
        $id = AIPKit_Vector_Local_Strategy::store_id($this->field('store_id'));
        $result = $this->strategy()->delete_index($id);
        if (is_wp_error($result)) { $this->send_wp_error($result); return; }
        wp_send_json_success(['message' => __('Knowledge base deleted.', 'gpt3-ai-content-generator')]);
    }

    /** Text or Q&A embedded with the requested model, replacing the same vector_id. */
    public function ajax_add_text(): void
    {
        if (!$this->allowed()) { return; }
        $strategy = $this->strategy();
        $store = $strategy->get_store(AIPKit_Vector_Local_Strategy::store_id($this->field('store_id')));
        $text = isset($_POST['text_content']) && is_string($_POST['text_content']) ? wp_kses_post(wp_unslash($_POST['text_content'])) : '';
        if (!$store) { $this->send_wp_error(new WP_Error('store_missing', __('Knowledge base not found.', 'gpt3-ai-content-generator'), ['status' => 404])); return; }
        if (trim(wp_strip_all_tags($text)) === '') { $this->send_wp_error(new WP_Error('empty_text', __('Add some text first.', 'gpt3-ai-content-generator'), ['status' => 400])); return; }
        $metadata = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Metadata must remain structured JSON; only arrays are accepted and the strategy stores them through wpdb, never as SQL or HTML.
        $raw_metadata = isset($_POST['metadata']) && is_string($_POST['metadata']) ? json_decode(wp_unslash($_POST['metadata']), true) : null;
        if (is_array($raw_metadata)) { $metadata = $raw_metadata; }
        $source = sanitize_key($this->field('source_type'));
        $metadata['source'] = $source !== '' ? $source : 'text_entry_global_form';
        if ($this->field('vector_id') !== '') { $metadata['vector_id'] = $this->field('vector_id'); }
        if (!class_exists(AIPKit_Vector_Text_Ingestion_Service::class)) { require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/ingestion.php'; }
        $service = new AIPKit_Vector_Text_Ingestion_Service(new AIPKit_Vector_Store_Manager(), new \WPAICG\Core\AIPKit_AI_Caller());
        $result = $service->ingest_text('Local', $store['id'], $text, sanitize_key($this->field('embedding_provider')), $this->field('embedding_model'), $metadata, []);
        $log = ['vector_store_id' => $store['id'], 'vector_store_name' => $store['name'], 'embedding_provider' => sanitize_key($this->field('embedding_provider')), 'embedding_model' => $this->field('embedding_model'), 'indexed_content' => $text];
        if (is_wp_error($result)) {
            $this->log($log + ['status' => 'failed', 'message' => 'Text indexing failed: ' . $result->get_error_message()]);
            $this->send_wp_error($result);
            return;
        }
        $this->log($log + ['status' => 'indexed', 'file_id' => $result['parent_vector_id'] ?? null, 'message' => sprintf('Text content indexed. Chunks: %d.', (int) ($result['total_chunks'] ?? 0))]);
        wp_send_json_success(['result' => $result, 'store' => $strategy->describe_index($store['id']), 'message' => __('Added to the knowledge base.', 'gpt3-ai-content-generator')]);
    }

    /** Pro: a file is extracted, chunked and embedded in steps (the browser repeats the request with job_id until it completes). */
    public function ajax_upload_file(): void
    {
        if (!$this->allowed()) { return; }
        if (!class_exists('\\WPAICG\\aipkit_dashboard') || !\WPAICG\aipkit_dashboard::is_pro_plan()) {
            $this->send_wp_error(new WP_Error('pro_feature_local_upload', __('File upload is a Pro feature. Please upgrade.', 'gpt3-ai-content-generator'), ['status' => 403])); return;
        }
        $job_file = WPAICG_LIB_DIR . 'knowledge-base/file-ingestion.php';
        if (!file_exists($job_file)) {
            $this->send_wp_error(new WP_Error('local_upload_missing', __('Some required files seem to be missing for file uploads. Please reinstall the Pro version of AI Puffer and try again.', 'gpt3-ai-content-generator'), ['status' => 500])); return;
        }
        require_once $job_file;
        if (!class_exists(AIPKit_Vector_Store_Manager::class)) { require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/manager.php'; }
        $job = \WPAICG\Lib\VectorStores\FileUpload\AIPKit_Vector_File_Ingestion_Job::class;
        $log = [$this, '_log_vector_data_source_entry'];
        if ($this->field('job_id') !== '') {
            $result = $job::process_job($this->field('job_id'), new AIPKit_Vector_Store_Manager(), new \WPAICG\Core\AIPKit_AI_Caller(), [], $log);
        } elseif (!isset($_FILES['file_to_upload'])) {
            $result = new WP_Error('no_file_local_upload', __('No file provided.', 'gpt3-ai-content-generator'), ['status' => 400]);
        } else {
            $result = $job::create_from_uploaded_file([
                'provider' => 'local',
                'embedding_provider_key' => sanitize_key($this->field('embedding_provider')),
                'embedding_model' => $this->field('embedding_model'),
                'target_id' => $this->field('store_id'),
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by AIPKit_Upload_Utils::validate_upload_file() in the job.
                'file_data' => $_FILES['file_to_upload'],
                'context' => $this->field('source_context') === 'chatbot_settings_file_upload' ? 'chatbot_settings' : 'admin',
                'user_id' => get_current_user_id(),
                'log_callback' => $log,
            ]);
        }
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            if (is_array($data) && isset($data['log_data']) && is_array($data['log_data'])) { $this->log($data['log_data']); }
            $this->send_wp_error($result);
            return;
        }
        if (isset($result['log_data']) && is_array($result['log_data'])) { $this->log($result['log_data']); }
        unset($result['log_data']);
        wp_send_json_success($result);
    }

    /** Remove one source (a post, text entry or file) by its parent vector id. */
    public function ajax_delete_source(): void
    {
        if (!$this->allowed()) { return; }
        $store_id = AIPKit_Vector_Local_Strategy::store_id($this->field('store_id'));
        $parent = $this->field('vector_id');
        if ($parent === '') { $this->send_wp_error(new WP_Error('missing_source', __('Choose what to remove.', 'gpt3-ai-content-generator'), ['status' => 400])); return; }
        $result = $this->strategy()->delete_vectors($store_id, ['filter' => ['should' => [
            ['key' => 'parent_vector_id', 'match' => ['value' => $parent]],
            ['key' => 'vector_id', 'match' => ['value' => $parent]],
        ]]]);
        if (is_wp_error($result)) { $this->send_wp_error($result); return; }
        wp_send_json_success(['store' => $this->strategy()->describe_index($store_id), 'message' => __('Removed from the knowledge base.', 'gpt3-ai-content-generator')]);
    }
}
