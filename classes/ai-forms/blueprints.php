<?php

namespace WPAICG\Admin\Ajax\AIForms;

use WPAICG\AIForms\Admin\AIPKit_AI_Form_Ajax_Handler;
use WPAICG\AIPKit_Providers;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;
use WPAICG\Lib\AIForms\Blueprints as ProBlueprints;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the logic for generating an AI Form draft from a natural-language prompt.
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_generate_form_from_prompt_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    if (!class_exists(AIPKit_AI_Caller::class) || !class_exists(AIPKit_Providers::class)) {
        $handler_instance->send_wp_error(
            new WP_Error(
                'dependency_missing',
                __('AI form generation is not available because required components are missing.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            )
        );
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $post_data = wp_unslash($_POST);

    $generation_prompt = isset($post_data['generation_prompt'])
        ? AIPKit_Prompt_Sanitizer::sanitize($post_data['generation_prompt'])
        : '';
    $generation_prompt = aipkit_ai_forms_limit_text_length($generation_prompt, 4000);

    if ($generation_prompt === '') {
        $handler_instance->send_wp_error(
            new WP_Error(
                'generation_prompt_required',
                __('Describe the AI task you want the form to handle.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            )
        );
        return;
    }

    $selected_provider = sanitize_text_field((string) ($post_data['ai_provider'] ?? ''));
    if ($selected_provider !== 'AIPufferCloud' && !in_array($selected_provider, AIPKit_Providers::get_text_generation_providers(), true)) {
        $selected_provider = '';
    }
    $selected_model = isset($post_data['ai_model']) ? sanitize_text_field((string) $post_data['ai_model']) : '';

    $retry_plan = aipkit_ai_forms_build_generation_retry_plan($selected_provider, $selected_model);
    $attempts = $retry_plan['attempts'];
    $skipped = $retry_plan['skipped'];
    $is_pro = class_exists('\WPAICG\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();

    if ($attempts === []) {
        $handler_instance->send_wp_error(
            new WP_Error(
                'no_generation_provider_available',
                $selected_provider === 'AIPufferCloud'
                    ? __('Connect AI Puffer Cloud and select an available Cloud model before generating a form.', 'gpt3-ai-content-generator')
                    : __('No usable AI provider is configured for form generation. Add an API key for OpenAI, Google, Anthropic, OpenRouter, DeepSeek, or xAI. To use Azure or Ollama, select that provider and a model in the editor first.', 'gpt3-ai-content-generator'),
                [
                    'status' => 400,
                    'generation_skipped_providers' => $skipped,
                ]
            )
        );
        return;
    }

    $ai_caller = new AIPKit_AI_Caller();
    $failures = [];

    foreach ($attempts as $attempt) {
        $normalized_form = aipkit_ai_forms_try_generate_form_with_attempt(
            $ai_caller,
            $attempt,
            $generation_prompt,
            $is_pro
        );

        if (is_wp_error($normalized_form)) {
            $error_data = $normalized_form->get_error_data();
            $failures[] = [
                'provider' => $attempt['provider'],
                'model' => $attempt['model'],
                'message' => $normalized_form->get_error_message(),
                'code' => $error_data['provider_error_code'] ?? $normalized_form->get_error_code(),
            ];
            continue;
        }

        $notices = isset($normalized_form['notices']) && is_array($normalized_form['notices'])
            ? $normalized_form['notices']
            : [];
        unset($normalized_form['notices']);

        $message = __('Form draft generated. Review the fields and save when ready.', 'gpt3-ai-content-generator');
        if (!empty($notices)) {
            $message .= ' ' . implode(' ', array_map('sanitize_text_field', $notices));
        }

        wp_send_json_success([
            'message' => $message,
            'provider' => $attempt['provider'],
            'model' => $attempt['model'],
            'form' => $normalized_form,
            'notices' => $notices,
        ]);
    }

    $handler_instance->send_wp_error(
        new WP_Error(
            'generation_failed_all_providers',
            aipkit_ai_forms_build_generation_failure_message($failures),
            [
                'status' => 500,
                'generation_attempts' => $failures,
                'generation_skipped_providers' => $skipped,
            ]
        )
    );
}

/**
 * Build a fixed retry plan for form generation based on configured providers.
 *
 * Fallback models come from the shared provider defaults catalog so the retry
 * plan stays aligned with the rest of the plugin. Azure and Ollama use the
 * editor-selected model because deployment/model names are instance-specific.
 *
 * @param string $selected_provider
 * @param string $selected_model
 * @return array{attempts: array<int, array<string, string>>, skipped: array<int, array<string, string>>}
 */
function aipkit_ai_forms_build_generation_retry_plan(string $selected_provider, string $selected_model): array
{
    // Cloud is an explicit billable choice. A missing connection or model must not make the
    // form generator try a different provider with the site's own API key.
    if ($selected_provider === 'AIPufferCloud') {
        $ready = \WPAICG\Cloud\Connection::generation_ready() && $selected_model !== '';
        return [
            'attempts' => $ready ? [['provider' => 'AIPufferCloud', 'model' => $selected_model]] : [],
            'skipped' => $ready ? [] : [['provider' => 'AIPufferCloud', 'reason' => __('Connect Cloud and select a Cloud model before generating a form.', 'gpt3-ai-content-generator')]],
        ];
    }
    $provider_priority = ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'DeepSeek', 'xAI', 'Azure', 'Ollama'];
    $preferred_models = [];

    foreach (['OpenAI', 'Google', 'Claude', 'OpenRouter', 'DeepSeek', 'xAI'] as $provider_name) {
        $preferred_model = AIPKit_Providers::get_default_model_id($provider_name);
        if ($preferred_model !== '') {
            $preferred_models[$provider_name] = $preferred_model;
        }
    }

    $attempts = [];
    $skipped = [];

    foreach ($provider_priority as $provider) {
        $provider_config = AIPKit_Providers::get_provider_data($provider);
        $attempt = aipkit_ai_forms_build_generation_attempt(
            $provider,
            $provider_config,
            $selected_provider,
            $selected_model,
            $preferred_models
        );

        if (!empty($attempt['eligible'])) {
            $attempts[] = [
                'provider' => $provider,
                'model' => (string) ($attempt['model'] ?? ''),
            ];
            continue;
        }

        $skipped[] = [
            'provider' => $provider,
            'reason' => (string) ($attempt['reason'] ?? __('Provider is not available.', 'gpt3-ai-content-generator')),
        ];
    }

    return [
        'attempts' => $attempts,
        'skipped' => $skipped,
    ];
}

/**
 * Resolve a single provider attempt for form generation.
 *
 * @param string $provider
 * @param array $provider_config
 * @param string $selected_provider
 * @param string $selected_model
 * @param array<string, string> $preferred_models
 * @return array{eligible: bool, model?: string, reason?: string}
 */
function aipkit_ai_forms_build_generation_attempt(
    string $provider,
    array $provider_config,
    string $selected_provider,
    string $selected_model,
    array $preferred_models
): array {
    switch ($provider) {

        case 'OpenAI':
        case 'Google':
        case 'Claude':
        case 'OpenRouter':
        case 'DeepSeek':
        case 'xAI':
            if (empty($provider_config['api_key'])) {
                return [
                    'eligible' => false,
                    'reason' => __('API key is not configured.', 'gpt3-ai-content-generator'),
                ];
            }

            return [
                'eligible' => true,
                'model' => $preferred_models[$provider] ?? '',
            ];

        case 'Azure':
            if ($selected_provider !== 'Azure') {
                return [
                    'eligible' => false,
                    'reason' => __('Select Azure in the editor to use Azure as a fallback provider.', 'gpt3-ai-content-generator'),
                ];
            }
            if (empty($provider_config['api_key'])) {
                return [
                    'eligible' => false,
                    'reason' => __('Azure API key is not configured.', 'gpt3-ai-content-generator'),
                ];
            }
            if (empty($provider_config['endpoint'])) {
                return [
                    'eligible' => false,
                    'reason' => __('Azure endpoint is not configured.', 'gpt3-ai-content-generator'),
                ];
            }
            if ($selected_model === '') {
                return [
                    'eligible' => false,
                    'reason' => __('Select an Azure model in the editor first.', 'gpt3-ai-content-generator'),
                ];
            }

            return [
                'eligible' => true,
                'model' => $selected_model,
            ];

        case 'Ollama':
            if ($selected_provider !== 'Ollama') {
                return [
                    'eligible' => false,
                    'reason' => __('Select Ollama in the editor to use Ollama as a fallback provider.', 'gpt3-ai-content-generator'),
                ];
            }
            if (empty($provider_config['base_url'])) {
                return [
                    'eligible' => false,
                    'reason' => __('Ollama base URL is not configured.', 'gpt3-ai-content-generator'),
                ];
            }
            if ($selected_model === '') {
                return [
                    'eligible' => false,
                    'reason' => __('Select an Ollama model in the editor first.', 'gpt3-ai-content-generator'),
                ];
            }

            return [
                'eligible' => true,
                'model' => $selected_model,
            ];
    }

    return [
        'eligible' => false,
        'reason' => __('Provider is not supported for form generation.', 'gpt3-ai-content-generator'),
    ];
}

/**
 * Execute a single generation attempt and return a normalized form on success.
 *
 * @param AIPKit_AI_Caller $ai_caller
 * @param array{provider: string, model: string} $attempt
 * @param string $generation_prompt
 * @return array|WP_Error
 */
function aipkit_ai_forms_try_generate_form_with_attempt(
    AIPKit_AI_Caller $ai_caller,
    array $attempt,
    string $generation_prompt,
    bool $is_pro
) {
    $result = $ai_caller->make_standard_call(
        $attempt['provider'],
        $attempt['model'],
        [
            [
                'role' => 'user',
                'content' => aipkit_ai_forms_build_generation_prompt($generation_prompt, $is_pro),
            ],
        ],
        [
            'temperature' => 0.2,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'max_completion_tokens' => 2600,
        ],
        'You design structured AI form blueprints for a WordPress builder. Return JSON only with no markdown fences, prose, or explanation.'
    );

    if (is_wp_error($result)) {
        return $result;
    }

    $decoded_blueprint = aipkit_ai_forms_decode_generation_response((string) ($result['content'] ?? ''));
    if (is_wp_error($decoded_blueprint)) {
        return $decoded_blueprint;
    }

    return aipkit_ai_forms_normalize_generated_blueprint($decoded_blueprint, $generation_prompt, $is_pro);
}

/**
 * Build a readable summary when every available provider fails.
 *
 * @param array<int, array<string, string>> $failures
 * @return string
 */
function aipkit_ai_forms_build_generation_failure_message(array $failures): string
{
    if ($failures === []) {
        return __('Form generation failed and no provider attempt produced a valid result.', 'gpt3-ai-content-generator');
    }

    if (count($failures) === 1) {
        $failure = $failures[0];
        if (($failure['provider'] ?? '') === 'AIPufferCloud') {
            $code = (string) ($failure['code'] ?? '');
            if ($code === 'insufficient_funds') {
                return __('Not enough AI Puffer Cloud credits to generate a form draft. Add credits in Usage, choose another model, or drag fields into the form to build it manually.', 'gpt3-ai-content-generator');
            }
            if (in_array($code, \WPAICG\Cloud\Connection::BILLING_CODES, true)) {
                return \WPAICG\Cloud\Connection::billing_message($code);
            }
        }
        // One selected provider did not try "all available providers". Keep its
        // full guidance instead of cutting off the action the user needs to take.
        if (!empty($failure['message'])) {
            return (string) $failure['message'];
        }
    }

    $summary_parts = [];
    foreach (array_slice($failures, 0, 3) as $failure) {
        $message = aipkit_ai_forms_limit_text_length(
            preg_replace('/\s+/', ' ', (string) ($failure['message'] ?? '')) ?: '',
            110
        );
        $summary_parts[] = sprintf(
            '%1$s: %2$s',
            $failure['provider'] ?? __('Provider', 'gpt3-ai-content-generator'),
            $message !== '' ? $message : __('Unknown error.', 'gpt3-ai-content-generator')
        );
    }

    $summary = implode(' | ', $summary_parts);
    if (count($failures) > 3) {
        $summary .= ' | ...';
    }

    return sprintf(
        /* translators: %s: summary of provider failure details. */
        __('Form generation failed after trying all available providers. %s', 'gpt3-ai-content-generator'),
        $summary
    );
}

/**
 * Build the instruction payload sent to the AI model.
 *
 * @param string $generation_prompt
 * @return string
 */
function aipkit_ai_forms_build_generation_prompt(string $generation_prompt, bool $is_pro): string
{
    $schema_example = [
        'title' => 'Blog Brief Builder',
        'prompt_template' => "You are an expert writer.\n\nWrite a blog post using the following inputs:\n- Topic: {topic}\n- Audience: {audience}\n- Tone: {tone}",
        'rows' => [
            [
                'columns' => [
                    [
                        'width' => '100%',
                        'fields' => [
                            [
                                'type' => 'text-input',
                                'label' => 'Topic',
                                'field_id' => 'topic',
                                'required' => true,
                                'placeholder' => 'e.g. The future of AI search',
                                'help_text' => 'What should the AI focus on?',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'columns' => [
                    [
                        'width' => '50%',
                        'fields' => [
                            [
                                'type' => 'text-input',
                                'label' => 'Audience',
                                'field_id' => 'audience',
                                'required' => true,
                            ],
                        ],
                    ],
                    [
                        'width' => '50%',
                        'fields' => [
                            [
                                'type' => 'select',
                                'label' => 'Tone',
                                'field_id' => 'tone',
                                'required' => true,
                                'options' => [
                                    ['value' => 'professional', 'text' => 'Professional'],
                                    ['value' => 'casual', 'text' => 'Casual'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $pro_config = aipkit_ai_forms_blueprints_pro_available($is_pro)
        ? ProBlueprints::generation_config($schema_example)
        : [];
    $schema_example = $pro_config['schema'] ?? $schema_example;
    $schema_example_json = wp_json_encode($schema_example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $supported_field_types = $pro_config['field_types'] ?? 'text-input, textarea, select, checkbox, radio-button';

    $plan_rules = $pro_config['rules'] ?? [
        '- this site is on the free plan, so do not use file-upload, image-upload, audio-upload, or multi-step metadata.',
        '- if the user asks for files, images, audio, uploads, or multi-step flows, create a practical single-step text-based alternative instead.',
        '- for requested file/image/audio uploads on the free plan, use textarea fields that ask the user to paste a description, transcript, or relevant text.',
    ];

    return implode("\n\n", [
        'Create an AI form blueprint for a WordPress builder.',
        'This builder collects user input fields and injects them into an AI prompt template using placeholders like {field_id}.',
        'Return a single JSON object with exactly these keys: title, prompt_template, rows.',
        'Requirements:',
        '- rows must be an array of rows.',
        '- each row must have a columns array.',
        '- each column may include width and fields.',
        '- widths should use only 100%, 50%, 30%, 70%, or 33.33%.',
        '- supported field types for this site: ' . $supported_field_types . '.',
        implode("\n", $plan_rules),
        '- every field needs a user-friendly label and a unique snake_case field_id.',
        '- prompt_template must reference every field with its matching placeholder.',
        '- use select/radio/checkbox only when there are clear predefined choices.',
        '- keep the form practical and focused. Usually 3 to 8 fields is enough.',
        'Example response shape:',
        (string) $schema_example_json,
        'User request:',
        $generation_prompt,
    ]);
}

/**
 * Decode a JSON response from the AI model, tolerating wrappers and code fences.
 *
 * @param string $content
 * @return array|WP_Error
 */
function aipkit_ai_forms_decode_generation_response(string $content)
{
    $content = trim($content);
    if ($content === '') {
        return new WP_Error(
            'empty_generation_response',
            __('The AI returned an empty response while generating the form draft.', 'gpt3-ai-content-generator'),
            ['status' => 500]
        );
    }

    $cleaned = preg_replace('/^\s*```(?:json)?\s*/i', '', $content);
    $cleaned = is_string($cleaned) ? preg_replace('/\s*```\s*$/', '', $cleaned) : $content;
    $cleaned = is_string($cleaned) ? trim($cleaned) : $content;

    $decoded = json_decode((string) $cleaned, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $json_candidate = (string) substr($content, $start, ($end - $start) + 1);
        $decoded = json_decode($json_candidate, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return new WP_Error(
        'invalid_generation_response',
        __('The AI response could not be converted into a valid form draft.', 'gpt3-ai-content-generator'),
        ['status' => 500]
    );
}

/**
 * Normalize a generated blueprint into the stored AI Forms schema.
 *
 * @param array $blueprint
 * @param string $fallback_prompt
 * @return array|WP_Error
 */
function aipkit_ai_forms_normalize_generated_blueprint(array $blueprint, string $fallback_prompt, bool $is_pro)
{
    $is_pro = aipkit_ai_forms_blueprints_pro_available($is_pro);
    $raw_rows = $blueprint['rows'] ?? $blueprint['form_structure'] ?? $blueprint['structure'] ?? [];
    if (!is_array($raw_rows) || $raw_rows === []) {
        $top_level_fields = $blueprint['fields'] ?? [];
        if (is_array($top_level_fields) && $top_level_fields !== []) {
            $raw_rows = [
                [
                    'columns' => [
                        [
                            'width' => '100%',
                            'fields' => $top_level_fields,
                        ],
                    ],
                ],
            ];
        }
    }

    if (!is_array($raw_rows) || $raw_rows === []) {
        return new WP_Error(
            'invalid_generated_structure',
            __('The AI did not return any usable form fields.', 'gpt3-ai-content-generator'),
            ['status' => 500]
        );
    }

    $used_field_ids = [];
    $field_references = [];
    $normalized_rows = [];
    $notices = aipkit_ai_forms_detect_plan_notices($fallback_prompt, $is_pro);
    $row_counter = 0;
    $element_counter = 0;

    foreach ($raw_rows as $raw_row) {
        if (!is_array($raw_row)) {
            continue;
        }

        $row_counter++;
        if ($row_counter > 12) {
            break;
        }

        $raw_columns = $raw_row['columns'] ?? [];
        if (!is_array($raw_columns) || $raw_columns === []) {
            $row_fields = $raw_row['fields'] ?? $raw_row['elements'] ?? [];
            if (is_array($row_fields) && $row_fields !== []) {
                $raw_columns = [
                    [
                        'width' => '100%',
                        'fields' => $row_fields,
                    ],
                ];
            }
        }

        if (!is_array($raw_columns) || $raw_columns === []) {
            continue;
        }

        $normalized_columns = [];
        $column_index = 0;
        $column_count = min(count($raw_columns), 3);

        foreach ($raw_columns as $raw_column) {
            if (!is_array($raw_column)) {
                continue;
            }

            $column_index++;
            if ($column_index > 3) {
                break;
            }

            $raw_fields = $raw_column['fields'] ?? $raw_column['elements'] ?? [];
            if (!is_array($raw_fields) || $raw_fields === []) {
                continue;
            }

            $normalized_elements = [];
            foreach ($raw_fields as $raw_field) {
                if (!is_array($raw_field)) {
                    continue;
                }

                $element_counter++;
                if ($element_counter > 24) {
                    break 2;
                }

                $normalized_field = aipkit_ai_forms_normalize_generated_field(
                    $raw_field,
                    $element_counter,
                    $used_field_ids,
                    $is_pro,
                    $notices
                );

                $field_references[] = [
                    'label' => $normalized_field['label'],
                    'field_id' => $normalized_field['fieldId'],
                ];
                $normalized_elements[] = $normalized_field;
            }

            if ($normalized_elements === []) {
                continue;
            }

            $normalized_columns[] = [
                'internalId' => sprintf('col-%d-%d', $row_counter, count($normalized_columns) + 1),
                'width' => aipkit_ai_forms_normalize_column_width(
                    $raw_column['width'] ?? '',
                    $column_count
                ),
                'elements' => $normalized_elements,
            ];
        }

        if ($normalized_columns === []) {
            continue;
        }

        $normalized_row = [
            'internalId' => sprintf('row-%d', count($normalized_rows) + 1),
            'type' => 'layout-row',
            'columns' => $normalized_columns,
        ];

        $conversation_step = aipkit_ai_forms_normalize_generated_conversation_step(
            $raw_row,
            count($normalized_rows) + 1,
            $is_pro,
            $notices
        );
        if ($conversation_step !== null) {
            $normalized_row['aipkitConversationStep'] = $conversation_step;
        }

        $normalized_rows[] = $normalized_row;
    }

    if ($normalized_rows === [] || $field_references === []) {
        return new WP_Error(
            'invalid_generated_fields',
            __('The generated draft did not contain any usable fields.', 'gpt3-ai-content-generator'),
            ['status' => 500]
        );
    }

    $title = isset($blueprint['title']) ? sanitize_text_field((string) $blueprint['title']) : '';
    if ($title === '') {
        $title = aipkit_ai_forms_build_fallback_title($fallback_prompt);
    }

    $prompt_template = '';
    if (isset($blueprint['prompt_template'])) {
        $prompt_template = AIPKit_Prompt_Sanitizer::sanitize($blueprint['prompt_template']);
    } elseif (isset($blueprint['prompt'])) {
        $prompt_template = AIPKit_Prompt_Sanitizer::sanitize($blueprint['prompt']);
    }
    if ($prompt_template === '') {
        $prompt_template = aipkit_ai_forms_build_fallback_prompt_template($fallback_prompt, $field_references);
    }
    $prompt_template = aipkit_ai_forms_ensure_prompt_uses_fields($prompt_template, $field_references);

    $result = [
        'title' => $title,
        'prompt_template' => $prompt_template,
        'structure' => $normalized_rows,
    ];

    if (aipkit_ai_forms_blueprints_pro_available($is_pro)) {
        $result = ProBlueprints::complete_form($result, $fallback_prompt);
    }
    if (!empty($notices)) {
        $result['notices'] = array_values(array_unique($notices));
    }

    return $result;
}

/**
 * Normalize a generated field into a stored element definition.
 *
 * @param array $field
 * @param int $element_counter
 * @param array<int, string> $used_field_ids
 * @return array
 */
function aipkit_ai_forms_normalize_generated_field(
    array $field,
    int $element_counter,
    array &$used_field_ids,
    bool $is_pro,
    array &$notices
): array
{
    $raw_type = (string) ($field['type'] ?? $field['field_type'] ?? 'text-input');
    $type = aipkit_ai_forms_normalize_field_type($raw_type, $is_pro, $notices);
    $label = sanitize_text_field((string) ($field['label'] ?? $field['question'] ?? $field['name'] ?? ''));
    $label = aipkit_ai_forms_limit_text_length($label, 90);
    $required_raw = $field['required'] ?? false;
    $required = is_bool($required_raw)
        ? $required_raw
        : filter_var($required_raw, FILTER_VALIDATE_BOOLEAN);

    if ($label === '') {
        $label = sprintf(
            /* translators: %d: generated fallback field number. */
            __('Field %d', 'gpt3-ai-content-generator'),
            $element_counter
        );
    }

    $field_id = aipkit_ai_forms_build_unique_field_id(
        (string) ($field['field_id'] ?? $field['fieldId'] ?? $field['name'] ?? ''),
        $label,
        $element_counter,
        $used_field_ids
    );

    $normalized = [
        'internalId' => sprintf('el-%d', $element_counter),
        'type' => $type,
        'label' => $label,
        'fieldId' => $field_id,
        'required' => $required === true,
        'helpText' => aipkit_ai_forms_limit_text_length(
            sanitize_text_field((string) ($field['help_text'] ?? $field['helpText'] ?? '')),
            160
        ),
    ];

    if (in_array($type, ['text-input', 'textarea'], true)) {
        $normalized['placeholder'] = aipkit_ai_forms_limit_text_length(
            sanitize_text_field((string) ($field['placeholder'] ?? '')),
            140
        );
        return $normalized;
    }

    if (in_array($type, ['file-upload', 'image-upload'], true)) {
        return ProBlueprints::add_upload_help($normalized);
    }

    $normalized['options'] = aipkit_ai_forms_normalize_field_options($field['options'] ?? $field['choices'] ?? []);
    if ($normalized['options'] === []) {
        $normalized['options'] = [
            ['value' => 'option_1', 'text' => __('Option 1', 'gpt3-ai-content-generator')],
            ['value' => 'option_2', 'text' => __('Option 2', 'gpt3-ai-content-generator')],
        ];
    }

    return $normalized;
}

/**
 * Normalize field options from strings or key/value objects.
 *
 * @param mixed $options
 * @return array<int, array<string, string>>
 */
function aipkit_ai_forms_normalize_field_options($options): array
{
    if (!is_array($options)) {
        return [];
    }

    $normalized = [];
    $counter = 0;
    foreach ($options as $option) {
        $counter++;
        if ($counter > 8) {
            break;
        }

        $text = '';
        $value = '';

        if (is_string($option)) {
            $text = sanitize_text_field($option);
            $value = aipkit_ai_forms_sanitize_field_id($text !== '' ? $text : 'option_' . $counter);
        } elseif (is_array($option)) {
            $text = sanitize_text_field((string) ($option['text'] ?? $option['label'] ?? $option['value'] ?? ''));
            $value = sanitize_text_field((string) ($option['value'] ?? ''));
            if ($value === '') {
                $value = aipkit_ai_forms_sanitize_field_id($text !== '' ? $text : 'option_' . $counter);
            }
        }

        $text = aipkit_ai_forms_limit_text_length($text, 60);
        $value = aipkit_ai_forms_limit_text_length($value, 60);

        if ($text === '' || $value === '') {
            continue;
        }

        $normalized[] = [
            'value' => $value,
            'text' => $text,
        ];
    }

    return $normalized;
}

/**
 * Normalize a field type into a supported AI Forms element type.
 *
 * @param string $type
 * @return string
 */
function aipkit_ai_forms_normalize_field_type(string $type, bool $is_pro, array &$notices): string
{
    $normalized = strtolower(trim($type));
    $normalized = str_replace(['_', ' '], '-', $normalized);

    $map = [
        'text' => 'text-input',
        'input' => 'text-input',
        'text-input' => 'text-input',
        'textinput' => 'text-input',
        'short-text' => 'text-input',
        'textarea' => 'textarea',
        'long-text' => 'textarea',
        'paragraph' => 'textarea',
        'select' => 'select',
        'dropdown' => 'select',
        'radio' => 'radio-button',
        'radio-button' => 'radio-button',
        'radio-buttons' => 'radio-button',
        'checkbox' => 'checkbox',
        'checkboxes' => 'checkbox',
        'checkbox-group' => 'checkbox',
        'file' => 'file-upload',
        'file-upload' => 'file-upload',
        'document-upload' => 'file-upload',
        'pdf-upload' => 'file-upload',
        'image' => 'image-upload',
        'image-upload' => 'image-upload',
        'photo-upload' => 'image-upload',
        'picture-upload' => 'image-upload',
        'audio' => 'audio-upload',
        'audio-upload' => 'audio-upload',
        'voice-upload' => 'audio-upload',
    ];

    $mapped = $map[$normalized] ?? 'text-input';
    if (in_array($mapped, ['file-upload', 'image-upload'], true) && !aipkit_ai_forms_blueprints_pro_available($is_pro)) {
        $notices[] = __('Requested upload fields were converted to standard text fields because uploads require Pro.', 'gpt3-ai-content-generator');
        return 'textarea';
    }

    if ($mapped === 'audio-upload') {
        $notices[] = __('Audio upload is not available in Generate with AI yet, so it was converted to a text field.', 'gpt3-ai-content-generator');
        return 'textarea';
    }

    return $mapped;
}

/**
 * Normalize optional row-level multi-step metadata into the existing builder schema.
 *
 * @param array $raw_row
 * @param int $row_number
 * @param bool $is_pro
 * @param array<int, string> $notices
 * @return array<string, mixed>|null
 */
function aipkit_ai_forms_normalize_generated_conversation_step(array $raw_row, int $row_number, bool $is_pro, array &$notices): ?array
{
    $raw_step = $raw_row['conversation_step']
        ?? $raw_row['aipkitConversationStep']
        ?? $raw_row['step']
        ?? $raw_row['multi_step']
        ?? null;

    if (!is_array($raw_step)) {
        return null;
    }

    if (!aipkit_ai_forms_blueprints_pro_available($is_pro)) {
        $notices[] = __('Requested multi-step settings were removed because multi-step forms require Pro.', 'gpt3-ai-content-generator');
        return null;
    }

    return ProBlueprints::normalize_conversation_step($raw_step, $row_number);
}

/**
 * Detect Pro-only feature requests so free-plan generated alternatives can explain what changed.
 *
 * @param string $prompt
 * @param bool $is_pro
 * @return array<int, string>
 */
function aipkit_ai_forms_detect_plan_notices(string $prompt, bool $is_pro): array
{
    if ($is_pro) {
        return [];
    }

    $prompt_lc = strtolower($prompt);
    $notices = [];

    if (preg_match('/\b(upload|file|pdf|document|image|photo|picture|audio|voice|recording)\b/', $prompt_lc)) {
        $notices[] = __('Requested upload features were converted to text fields because uploads require Pro.', 'gpt3-ai-content-generator');
    }

    if (aipkit_ai_forms_prompt_mentions_multistep($prompt_lc)) {
        $notices[] = __('Requested multi-step features were omitted because multi-step forms require Pro.', 'gpt3-ai-content-generator');
    }

    return $notices;
}

/**
 * Detect whether the natural-language prompt asks for a guided or branched flow.
 *
 * @param string $prompt
 * @return bool
 */
function aipkit_ai_forms_prompt_mentions_multistep(string $prompt): bool
{
    return preg_match('/\b(multi[- ]?step|step[- ]?by[- ]?step|conditional|branch|branched|wizard)\b/i', $prompt) === 1;
}

/**
 * Normalize a generated column width into one supported by the builder.
 *
 * @param mixed $width
 * @param int $column_count
 * @return string
 */
function aipkit_ai_forms_normalize_column_width($width, int $column_count): string
{
    $raw = is_string($width) || is_numeric($width) ? trim((string) $width) : '';
    $raw = rtrim($raw, '%');

    if (is_numeric($raw)) {
        $numeric = round((float) $raw, 2);
        $allowed = [
            [100.0, '100%'],
            [70.0, '70%'],
            [50.0, '50%'],
            [30.0, '30%'],
            [33.33, '33.33%'],
            [33.0, '33.33%'],
        ];
        foreach ($allowed as [$allowed_numeric, $allowed_width]) {
            if (abs($numeric - $allowed_numeric) < 0.2) {
                return $allowed_width;
            }
        }
    }

    if ($column_count <= 1) {
        return '100%';
    }
    if ($column_count === 2) {
        return '50%';
    }

    return '33.33%';
}

/**
 * Build a unique snake_case field identifier.
 *
 * @param string $raw_field_id
 * @param string $label
 * @param int $index
 * @param array<int, string> $used_field_ids
 * @return string
 */
function aipkit_ai_forms_build_unique_field_id(
    string $raw_field_id,
    string $label,
    int $index,
    array &$used_field_ids
): string {
    $candidate = aipkit_ai_forms_sanitize_field_id($raw_field_id);
    if ($candidate === '') {
        $candidate = aipkit_ai_forms_sanitize_field_id($label);
    }
    if ($candidate === '') {
        $candidate = 'field_' . $index;
    }

    $base = $candidate;
    $suffix = 2;
    while (in_array($candidate, $used_field_ids, true)) {
        $candidate = $base . '_' . $suffix;
        $suffix++;
    }

    $used_field_ids[] = $candidate;
    return $candidate;
}

/**
 * Convert arbitrary text to a valid AI Forms field ID.
 *
 * @param string $value
 * @return string
 */
function aipkit_ai_forms_sanitize_field_id(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value);
    $value = is_string($value) ? trim($value, '_') : '';

    return is_string($value) ? $value : '';
}

/**
 * Ensure the generated prompt template references each field.
 *
 * @param string $prompt_template
 * @param array<int, array<string, string>> $field_references
 * @return string
 */
function aipkit_ai_forms_ensure_prompt_uses_fields(string $prompt_template, array $field_references): string
{
    $missing_fields = [];
    foreach ($field_references as $field_reference) {
        $placeholder = '{' . $field_reference['field_id'] . '}';
        if (strpos($prompt_template, $placeholder) === false) {
            $missing_fields[] = $field_reference;
        }
    }

    if ($missing_fields === []) {
        return $prompt_template;
    }

    $lines = [rtrim($prompt_template), '', 'Use these form inputs:'];
    foreach ($missing_fields as $field_reference) {
        $lines[] = sprintf('- %s: {%s}', $field_reference['label'], $field_reference['field_id']);
    }

    return trim(implode("\n", $lines));
}

/**
 * Build a fallback prompt template if the AI omits it.
 *
 * @param string $generation_prompt
 * @param array<int, array<string, string>> $field_references
 * @return string
 */
function aipkit_ai_forms_build_fallback_prompt_template(string $generation_prompt, array $field_references): string
{
    $lines = [
        'You are assisting with this task:',
        $generation_prompt,
        '',
        'Use the following form inputs:',
    ];

    foreach ($field_references as $field_reference) {
        $lines[] = sprintf('- %s: {%s}', $field_reference['label'], $field_reference['field_id']);
    }

    $lines[] = '';
    $lines[] = 'Return only the final result.';

    return implode("\n", $lines);
}

/**
 * Build a readable fallback title when the AI omits it.
 *
 * @param string $generation_prompt
 * @return string
 */
function aipkit_ai_forms_build_fallback_title(string $generation_prompt): string
{
    $normalized_prompt = preg_replace('/\s+/', ' ', $generation_prompt);
    $trimmed = trim(is_string($normalized_prompt) ? $normalized_prompt : $generation_prompt);
    if ($trimmed === '') {
        return __('AI Form Draft', 'gpt3-ai-content-generator');
    }

    $title = aipkit_ai_forms_limit_text_length($trimmed, 60);
    return $title !== '' ? $title : __('AI Form Draft', 'gpt3-ai-content-generator');
}

/**
 * Limit text length while preserving multibyte support when available.
 *
 * @param string $value
 * @param int $max_length
 * @return string
 */
function aipkit_ai_forms_limit_text_length(string $value, int $max_length): string
{
    if ($max_length <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max_length);
    }

    return (string) substr($value, 0, $max_length);
}

/** Paid blueprint extensions must be both installed and currently available. */
function aipkit_ai_forms_blueprints_pro_available(bool $is_pro): bool
{
    return $is_pro && class_exists(ProBlueprints::class) && ProBlueprints::is_available();
}
