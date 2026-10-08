<?php

namespace WPAICG\Vector;

use WPAICG\AIPKit_Providers;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for Vector Store Provider Strategies.
 * Defines the contract for interacting with different vector database services.
 */
interface AIPKit_Vector_Provider_Strategy_Interface {

    /**
     * Connects to the vector store provider.
     * Specific connection parameters are handled by the concrete strategy.
     *
     * @param array $config Configuration array specific to the provider (e.g., API key, environment, URL).
     * @return bool|WP_Error True on successful connection, WP_Error on failure.
     */
    public function connect(array $config);

    /**
     * Creates an index (or collection/vector store) in the vector store if it does not already exist.
     *
     * @param string $index_name The name of the index/collection/vector store.
     * @param array  $index_config Provider-specific configuration for the index (e.g., dimension, metric type).
     * @return array|WP_Error The store object (array) on success (whether new or existing), WP_Error on failure.
     */
    public function create_index_if_not_exists(string $index_name, array $index_config);

    /**
     * Upserts (adds or updates) vectors into the specified index.
     * For OpenAI, this means adding files to a vector store batch.
     *
     * @param string $index_name The name of the index/collection (or vector_store_id for OpenAI).
     * @param array  $vectors    An array of vectors to upsert.
     *                           For OpenAI: ['file_ids' => [id1, id2], 'chunking_strategy' => (optional)].
     *                           For others: Each vector should be an object or associative array,
     *                           typically containing an 'id' (string), 'values' (array of floats - the embedding),
     *                           and optionally 'metadata' (associative array).
     * @return array|WP_Error An array with results (e.g., ['upserted_count' => int], or OpenAI batch object) or WP_Error on failure.
     */
    public function upsert_vectors(string $index_name, array $vectors);

    /**
     * Queries the index for vectors similar to the query_vector.
     *
     * @param string $index_name   The name of the index/collection.
     * @param array  $query_vector An array of floats representing the query embedding, or for OpenAI, an array like ['query_text' => 'search term'].
     * @param int    $top_k        The number of nearest neighbors to return.
     * @param array  $filter       Optional. Provider-specific metadata filter.
     * @return array|WP_Error An array of matching vectors with scores/distances, or WP_Error on failure.
     *                        Each result typically includes 'id', 'score', and 'metadata'.
     */
    public function query_vectors(string $index_name, array $query_vector, int $top_k, array $filter = []);

    /**
     * Deletes vectors from the specified index by their IDs.
     * For OpenAI, this means detaching a file from a vector store.
     *
     * @param string $index_name The name of the index/collection (or vector_store_id for OpenAI).
     * @param array  $vector_ids An array of vector IDs (or file_ids for OpenAI) to delete.
     * @return bool|WP_Error True on success, WP_Error on failure or if some IDs were not found/deleted.
     */
    public function delete_vectors(string $index_name, array $vector_ids);

    /**
     * Deletes an entire index (or collection / vector store).
     *
     * @param string $index_name The name of the index/collection to delete.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_index(string $index_name);

    /**
     * Lists available indexes (or collections / vector stores) for a given provider.
     *
     * @param int|null $limit The maximum number of items to return.
     * @param string|null $order The order of items ('asc' or 'desc').
     * @param string|null $after A cursor for use in pagination (fetch items after this ID).
     * @param string|null $before A cursor for use in pagination (fetch items before this ID).
     * @return array|WP_Error An array of index names or index detail objects,
     *                        or for paginated results, a structure like
     *                        ['data' => [], 'first_id' => null, 'last_id' => null, 'has_more' => false],
     *                        or WP_Error on failure.
     */
    public function list_indexes(?int $limit = 20, ?string $order = 'desc', ?string $after = null, ?string $before = null);


    /**
     * Describes an index (or collection / vector store), returning its configuration and status.
     *
     * @param string $index_name The name of the index/collection.
     * @return array|WP_Error An array containing index details, or WP_Error if not found or on failure.
     */
    public function describe_index(string $index_name);

    /**
     * Uploads a file to the provider, typically for later use in a vector store.
     * This is particularly relevant for OpenAI.
     *
     * @param string $file_path Absolute path to the file on the server.
     * @param string $original_filename The original filename with extension.
     * @param string $purpose Purpose of the file (e.g., 'user_data', 'batch').
     * @return array|WP_Error Provider-specific file object on success, WP_Error on failure.
     */
    public function upload_file_for_vector_store(string $file_path, string $original_filename, string $purpose = 'user_data');
}

/**
 * Abstract Base class for Vector Store Provider Strategies.
 * Implements the AIPKit_Vector_Provider_Strategy_Interface and can provide common helper methods.
 */
abstract class AIPKit_Vector_Base_Provider_Strategy implements AIPKit_Vector_Provider_Strategy_Interface {

    protected $client; // Stores the initialized client for the provider
    protected $is_connected = false; // Credentials initialized; each remote request still authenticates.

    /** Initialize an operation; remote providers override this to avoid a separate probe. */
    public function configure(array $config) {
        return $this->connect($config);
    }

    /** Dimension discovery does not require live point counts or usage statistics. */
    public function describe_index_for_embeddings(string $index_name) {
        return $this->describe_index($index_name);
    }

    /**
     * Common helper to parse JSON, returning a WP_Error on failure.
     * @param string $json_string The JSON string to decode.
     * @param string $context Context for error messages (e.g., "Pinecone API").
     * @return array|WP_Error Decoded array or WP_Error.
     */
    public function decode_json(string $json_string, string $context) {
        if (trim($json_string) === '') {
            return [];
        }
        $decoded = json_decode($json_string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            /* translators: %1$s: The context of the API call (e.g., "OpenAI Models"), %2$s: The specific JSON error message from PHP. */
            $error_message = sprintf(__('Failed to parse JSON response from %1$s. Error: %2$s', 'gpt3-ai-content-generator'), $context, json_last_error_msg());
            return new WP_Error('json_decode_error', $error_message);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Common helper to parse API errors. Can be overridden by specific strategies.
     * @param mixed $response_body Raw or decoded response body.
     * @param int $status_code HTTP status code.
     * @param string $context Provider context (e.g., "Pinecone API").
     * @return string User-friendly error message.
     */
    public function parse_error_response($response_body, int $status_code, string $context): string {
        /* translators: %1$s: The context of the error (e.g., "OpenAI Image"), %2$d: The HTTP status code. */
        $message = sprintf(__('An unknown error occurred with %1$s (HTTP %2$d).', 'gpt3-ai-content-generator'), $context, $status_code);
        $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

        if (is_array($decoded)) {
            if (!empty($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            } elseif (!empty($decoded['status']['error'])) {
                $status_error = $decoded['status']['error'];
                $message = is_string($status_error)
                    ? $status_error
                    : (is_array($status_error) && !empty($status_error['message'])
                        ? $status_error['message']
                        : $message);
            } elseif (!empty($decoded['message'])) {
                $message = $decoded['message'];
            } elseif (!empty($decoded['detail'])) {
                 $message = is_string($decoded['detail']) ? $decoded['detail'] : wp_json_encode($decoded['detail']);
            }
        } elseif (is_string($response_body) && strlen($response_body) < 500 && strlen($response_body) > 0) {
             $message = $response_body;
        }
        return trim($message);
    }

    // Abstract methods from the interface must be implemented by concrete classes
    /**
     * @return bool|\WP_Error
     */
    abstract public function connect(array $config);
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function create_index_if_not_exists(string $index_name, array $index_config);
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function upsert_vectors(string $index_name, array $vectors);
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function query_vectors(string $index_name, array $query_vector, int $top_k, array $filter = []);
    /**
     * @return bool|\WP_Error
     */
    abstract public function delete_vectors(string $index_name, array $vector_ids);
    /**
     * @return bool|\WP_Error
     */
    abstract public function delete_index(string $index_name);
    // Provide a default implementation or declare as abstract. For now, a default that returns not implemented.
    /**
     * @return mixed[]|\WP_Error
     */
    public function list_indexes(?int $limit = 20, ?string $order = 'desc', ?string $after = null, ?string $before = null) {
        return new WP_Error('not_implemented_in_base', __('Listing indexes with pagination is not implemented in the base strategy.', 'gpt3-ai-content-generator'));
    }
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function describe_index(string $index_name);

    /**
     * Uploads a file to the provider, typically for later use in a vector store.
     * This is particularly relevant for OpenAI. Other providers might return 'not_applicable'.
     *
     * @param string $file_path Absolute path to the file on the server.
     * @param string $original_filename The original filename with extension.
     * @param string $purpose Purpose of the file (e.g., 'user_data', 'batch').
     * @return array|WP_Error Provider-specific file object on success, WP_Error on failure or if not applicable.
     */
    abstract public function upload_file_for_vector_store(string $file_path, string $original_filename, string $purpose = 'user_data');
}

/**
 * Factory for creating Vector Store Provider Strategy instances.
 */
class AIPKit_Vector_Provider_Strategy_Factory {

    /** @var array<string, AIPKit_Vector_Provider_Strategy_Interface> */
    private static $instances = [];

    /**
     * Get the strategy instance for a given Vector Store provider.
     *
     * @param string $provider Provider name (e.g., 'Pinecone', 'Qdrant', 'OpenAI', 'Chroma').
     * @return AIPKit_Vector_Provider_Strategy_Interface|WP_Error The strategy instance or WP_Error if unsupported.
     */
    public static function get_strategy(string $provider) {
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        if (
            class_exists(AIPKit_Providers::class)
            && !AIPKit_Providers::provider_supports_capability($provider, 'vector_stores')
        ) {
            return new WP_Error(
                'vector_provider_not_supported',
                sprintf(
                    /* translators: %s: The provider name. */
                    __('Vector stores are not supported by %s in this integration.', 'gpt3-ai-content-generator'),
                    esc_html($provider)
                ),
                ['status' => 501]
            );
        }

        $strategies_map = [
            'Pinecone' => ['pinecone.php', \WPAICG\Vector\Providers\AIPKit_Vector_Pinecone_Strategy::class],
            'Qdrant' => ['qdrant.php', \WPAICG\Vector\Providers\AIPKit_Vector_Qdrant_Strategy::class],
            'OpenAI' => ['openai.php', \WPAICG\Vector\Providers\AIPKit_Vector_OpenAI_Strategy::class],
            'Chroma' => ['chroma.php', \WPAICG\Vector\Providers\AIPKit_Vector_Chroma_Strategy::class],
            'Local' => ['local.php', \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class],
        ];

        if (!isset($strategies_map[$provider])) {
            /* translators: %s is the vector store provider name */
            return new WP_Error('unsupported_vector_provider', sprintf(__('Vector store provider "%s" is not supported.', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        [$strategy_path, $class_name] = $strategies_map[$provider];
        $strategy_file = __DIR__ . '/providers/' . $strategy_path;

        if (!class_exists($class_name)) {
            if (file_exists($strategy_file)) {
                require_once $strategy_file;
            } else {
                /* translators: %s is the vector store provider name */
                return new WP_Error('vector_strategy_file_not_found', sprintf(__('Vector Strategy file not found for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
            }
        }

        if (class_exists($class_name)) {
            self::$instances[$provider] = new $class_name();
        } else {
            /* translators: %s is the vector store provider name */
            return new WP_Error('vector_strategy_instantiation_failed', sprintf(__('Failed to load Vector Store strategy for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        return self::$instances[$provider];
    }
}
