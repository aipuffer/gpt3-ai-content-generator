<?php


namespace WPAICG\AIForms\Frontend;

use WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler;
use WPAICG\Includes\AIPKit_Shared_Assets_Manager;
use WP_Error;
use WPAICG\Lib\AIForms\Shortcode as PaidShortcode;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Orchestrates rendering the [aipkit_ai_form] shortcode.
 * Delegates logic to validator, data-provider, and renderer functions.
 */
class AIPKit_AI_Form_Shortcode
{
    private $form_storage;

    public function __construct()
    {
        if (class_exists(AIPKit_AI_Form_Storage::class)) {
            $this->form_storage = new AIPKit_AI_Form_Storage();
        } else {
            $this->form_storage = null;
        }
    }

    private function ensure_public_assets_registered(): void
    {
        $version = defined('WPAICG_VERSION') ? (string) WPAICG_VERSION : '1.0.0';

        if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
            AIPKit_Shared_Assets_Manager::register($version);
        }

        if (!wp_style_is('aipkit-public-ai-forms', 'registered')) {
            wp_register_style(
                'aipkit-public-ai-forms',
                WPAICG_PLUGIN_URL . 'dist/css/public-ai-forms.bundle.css',
                [],
                $version
            );
        }

        if (!wp_script_is('aipkit-public-ai-forms-js', 'registered')) {
            wp_register_script(
                'aipkit-public-ai-forms-js',
                WPAICG_PLUGIN_URL . 'dist/js/public-ai-forms.bundle.js',
                ['wp-i18n'],
                $version,
                true
            );
        }
    }

    private function is_pro_plan_active(): bool
    {
        return class_exists(PaidShortcode::class) && PaidShortcode::is_available();
    }

    private function is_script_localized(string $handle, string $object_name): bool
    {
        $scripts = wp_scripts();
        if (!$scripts) {
            return false;
        }

        $data = $scripts->get_data($handle, 'data');
        return is_string($data) && strpos($data, "var {$object_name} =") !== false;
    }

    private function enqueue_public_assets(array $frontend_display_settings): void
    {
        $this->ensure_public_assets_registered();

        if (!wp_style_is('aipkit-public-ai-forms', 'enqueued')) {
            wp_enqueue_style('aipkit-public-ai-forms');
        }

        if (!wp_script_is('aipkit-public-ai-forms-js', 'enqueued')) {
            wp_enqueue_script('aipkit-public-ai-forms-js');
            wp_set_script_translations('aipkit-public-ai-forms-js', 'gpt3-ai-content-generator', WPAICG_PLUGIN_DIR . 'languages');
        }
        if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
            AIPKit_Shared_Assets_Manager::attach_public_asset_urls('aipkit-public-ai-forms-js');
        }

        if (!$this->is_script_localized('aipkit-public-ai-forms-js', 'aipkit_ai_forms_public_config')) {
            wp_localize_script('aipkit-public-ai-forms-js', 'aipkit_ai_forms_public_config', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'ajaxNonce' => wp_create_nonce('aipkit_frontend_chat_nonce'),
                'is_user_logged_in' => is_user_logged_in(),
                'is_pro_plan' => $this->is_pro_plan_active(),
                'allowed_models' => $frontend_display_settings['allowed_models'] ?? '',
                'text' => [
                    'processing' => __('Processing...', 'gpt3-ai-content-generator'),
                    'error' => __('An error occurred.', 'gpt3-ai-content-generator'),
                    'saveAsPost' => __('Save', 'gpt3-ai-content-generator'),
                ],
            ]);
        }

        if (
            !$this->is_script_localized('aipkit-public-ai-forms-js', 'aipkit_ai_forms_models') &&
            class_exists('\\WPAICG\\AIPKit_Providers')
        ) {
            $all_models = [
                'aipuffercloud' => \WPAICG\AIPKit_Providers::get_model_list('AIPufferCloud'),
                'openai' => \WPAICG\AIPKit_Providers::get_openai_models(),
                'google' => \WPAICG\AIPKit_Providers::get_google_models(),
                'claude' => \WPAICG\AIPKit_Providers::get_claude_models(),
                'openrouter' => \WPAICG\AIPKit_Providers::get_openrouter_models(),
                'azure' => \WPAICG\AIPKit_Providers::get_azure_deployments(),
            ];

            if (class_exists(PaidShortcode::class)) {
                $all_models = array_merge($all_models, PaidShortcode::get_model_lists());
            }

            $all_models['deepseek'] = \WPAICG\AIPKit_Providers::get_deepseek_models();
            $all_models['xai'] = \WPAICG\AIPKit_Providers::get_xai_models();
            wp_localize_script('aipkit-public-ai-forms-js', 'aipkit_ai_forms_models', $all_models);
        }
    }

    private function get_late_style_handles(): array
    {
        $handles = ['aipkit-public-ai-forms'];

        if (class_exists(PaidShortcode::class)) {
            $handles = array_merge($handles, PaidShortcode::get_style_handles());
        }

        return $handles;
    }

    private function get_late_script_handles(bool $include_pdf_download = false): array
    {
        $handles = ['aipkit-public-ai-forms-js'];

        if (class_exists(PaidShortcode::class)) {
            $handles = array_merge($handles, PaidShortcode::get_script_handles($include_pdf_download));
        }

        return $handles;
    }

    private function capture_printed_styles(array $handles): string
    {
        if (empty($handles)) {
            return '';
        }

        if (!did_action('wp_print_styles') && !did_action('wp_head')) {
            return '';
        }

        ob_start();
        wp_styles()->do_items($handles);
        return trim((string) ob_get_clean());
    }

    private function capture_printed_scripts(array $handles): string
    {
        if (empty($handles)) {
            return '';
        }

        if (!did_action('wp_print_footer_scripts') && !did_action('wp_footer')) {
            return '';
        }

        ob_start();
        wp_scripts()->do_items($handles);
        return trim((string) ob_get_clean());
    }

    private function render_late_asset_fallbacks(bool $include_pdf_download = false): array
    {
        return [
            'styles' => $this->capture_printed_styles($this->get_late_style_handles()),
            'scripts' => $this->capture_printed_scripts($this->get_late_script_handles($include_pdf_download)),
        ];
    }

    /**
     * Render the shortcode output.
     *
     * @param array $atts Shortcode attributes, expecting 'id' and optional 'theme', 'save_button'.
     * @return string HTML output for the AI form or an error message.
     */
    public function render_shortcode($atts)
    {
        if (!$this->form_storage) {
            return $this->handle_error(new WP_Error('storage_missing', '[AIPKit AI Form Error: Storage component missing.]'));
        }

        // Parse attributes with defaults
        $default_atts = [
            'id'    => 0,
            'theme' => 'light',
            'show_provider' => 'false',
            'show_model'    => 'false',
            'save_button'   => 'false',
            'pdf_download'  => 'false',
            'copy_button'   => 'false',
        ];
        $atts = shortcode_atts($default_atts, $atts, 'aipkit_ai_form');

        // Validate theme attribute
        $valid_themes = ['light', 'dark', 'custom'];
        $theme = in_array($atts['theme'], $valid_themes, true) ? $atts['theme'] : 'light';

        // Parse boolean flags for new attributes
        $show_provider = filter_var($atts['show_provider'], FILTER_VALIDATE_BOOLEAN);
        $show_model = filter_var($atts['show_model'], FILTER_VALIDATE_BOOLEAN);
        $show_save_button = filter_var($atts['save_button'], FILTER_VALIDATE_BOOLEAN);
        $show_pdf_download = filter_var($atts['pdf_download'], FILTER_VALIDATE_BOOLEAN) && $this->is_pro_plan_active();
        $show_copy_button = filter_var($atts['copy_button'], FILTER_VALIDATE_BOOLEAN);

        // 1. Validate ID Attribute
        $validation_result = Shortcode\validate_atts_logic($atts);
        if (is_wp_error($validation_result)) {
            return $this->handle_error($validation_result);
        }
        $form_id = $validation_result;

        // 2. Get Form Data
        $form_data = Shortcode\get_form_data_logic($this->form_storage, $form_id);
        if (is_wp_error($form_data)) {
            return $this->handle_error($form_data);
        }

        $frontend_display_settings = [];
        if (class_exists(AIPKit_AI_Form_Settings_Ajax_Handler::class)) {
            $all_settings = AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
            $frontend_display_settings = $all_settings['frontend_display'] ?? [];
        }
        $allowed_providers_str = $frontend_display_settings['allowed_providers'] ?? '';
        $this->enqueue_public_assets($frontend_display_settings);
        if (class_exists(PaidShortcode::class)) {
            PaidShortcode::enqueue_public_assets($show_pdf_download);
        }
        $late_asset_fallbacks = $this->render_late_asset_fallbacks($show_pdf_download);

        $custom_css = '';
        if ($theme === 'custom' && class_exists(AIPKit_AI_Form_Settings_Ajax_Handler::class)) {
            $settings = AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
            $custom_css = $settings['custom_theme']['custom_css'] ?? '';
        }

        // 6. Prepare data for the renderer
        $unique_form_html_id = 'aipkit-ai-form-' . esc_attr($form_id);
        $ajax_nonce = wp_create_nonce('aipkit_process_ai_form_' . $form_id);

        // 7. Render HTML, passing the new theme and display flags
        $form_html = Shortcode\render_form_html_logic($form_data, $unique_form_html_id, $ajax_nonce, $theme, $show_provider, $show_model, $show_save_button, $show_pdf_download, $show_copy_button, $custom_css, $allowed_providers_str);

        return implode("\n", array_filter([
            $late_asset_fallbacks['styles'] ?? '',
            $form_html,
            $late_asset_fallbacks['scripts'] ?? '',
        ]));
    }

    /**
     * Handles rendering errors, showing messages to admins only.
     *
     * @param WP_Error $error The error object.
     * @return string HTML error message or empty string.
     */
    private function handle_error(WP_Error $error): string
    {
        if (\WPAICG\AIPKit_Role_Manager::user_can_view_admin_notices()) {
            $message = $error->get_error_message();
            $code = $error->get_error_code();
            return '<p style="color:' . ($code === 'already_rendered' ? 'orange' : 'red') . '; font-style: italic; margin: 1em 0;">' . esc_html($message) . '</p>';
        }
        return ''; // Silently fail for regular users
    }
}

namespace WPAICG\AIForms\Frontend\Shortcode;

use WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Admin_Setup;
use WPAICG\Lib\AIForms\Shortcode as PaidShortcode;
use WP_Error;


/**
 * Validates the shortcode attributes and the existence of the form post.
 *
 * @param array $atts Raw shortcode attributes.
 * @param array &$rendered_form_ids Retained for existing callers; repeated forms are allowed.
 * @return int|WP_Error Valid form ID on success, WP_Error on failure.
 */
function validate_atts_logic(array $atts, array &$rendered_form_ids = [])
{
    $atts = shortcode_atts(['id' => 0], $atts, 'aipkit_ai_form');
    $form_id = absint($atts['id']);

    if (empty($form_id)) {
        return new WP_Error('invalid_id', '[AIPKit AI Form Error: Missing or invalid form ID.]');
    }

    if (!class_exists(AIPKit_AI_Form_Admin_Setup::class)) {
        return new WP_Error('internal_error', 'AI Form system component is missing.');
    }
    $form_post = get_post($form_id);
    if (!$form_post || $form_post->post_type !== AIPKit_AI_Form_Admin_Setup::POST_TYPE || $form_post->post_status !== 'publish') {
        return new WP_Error('not_found', sprintf('[AIPKit AI Form Error: Invalid or unpublished Form ID: %d]', $form_id));
    }

    return $form_id;
}

/**
 * Logic for retrieving AI Form data.
 *
 * @param AIPKit_AI_Form_Storage $form_storage The instance of the storage class.
 * @param int $form_id The ID of the AI Form post.
 * @return array|WP_Error Form data array or WP_Error on failure.
 */
function get_form_data_logic(AIPKit_AI_Form_Storage $form_storage, int $form_id)
{
    return $form_storage->get_form_data($form_id);
}

/**
 * Renders the full HTML structure for the AI form.
 *
 * @param array $form_data The form's configuration data.
 * @param string $unique_form_html_id The unique HTML ID for the form wrapper.
 * @param string $ajax_nonce The nonce for the AJAX submission.
 * @param string $theme The theme for the form ('light', 'dark', 'custom').
 * @param bool $show_provider Whether to show the provider dropdown.
 * @param bool $show_model Whether to show the model dropdown.
 * @param bool $show_save_button Whether to show the save button after generation.
 * @param bool $show_pdf_download Whether to show the PDF download button after generation.
 * @param bool $show_copy_button Whether to show the copy button after generation.
 * @param string $custom_css Custom CSS rules to apply for the 'custom' theme.
 * @param string $allowed_providers_str Comma-separated string of allowed providers.
 * @return string The rendered HTML string for the form.
 */
function render_form_html_logic(
    array $form_data,
    string $unique_form_html_id,
    string $ajax_nonce,
    string $theme = 'light',
    bool $show_provider = true,
    bool $show_model = true,
    bool $show_save_button = false,
    bool $show_pdf_download = false,
    bool $show_copy_button = false,
    string $custom_css = '',
    string $allowed_providers_str = ''
): string {
    ob_start();
    $labels = $form_data['labels'] ?? [];
    $save_as_post_nonce = wp_create_nonce('aipkit_ai_form_save_as_post_nonce');
    $selection_flags = (int) $show_provider . (int) $show_model;
    $selection_policy = $selection_flags . ':' . wp_hash('ai_form_selection|' . $form_data['id'] . '|' . $selection_flags);
    $conversation_ui_preset = class_exists(PaidShortcode::class)
        ? PaidShortcode::get_conversation_ui_preset($form_data)
        : 'full';
    ?>
    <div 
        class="aipkit-ai-form-wrapper aipkit-theme-<?php echo esc_attr($theme); ?>" 
        id="<?php echo esc_attr($unique_form_html_id); ?>" 
        data-form-id="<?php echo esc_attr($form_data['id']); ?>"
        data-selection-policy="<?php echo esc_attr($selection_policy); ?>"
        data-nonce="<?php echo esc_attr($ajax_nonce); ?>"
        data-ai-provider="<?php echo esc_attr($form_data['ai_provider'] ?? 'OpenAI'); ?>"
        data-show-provider="<?php echo $show_provider ? 'true' : 'false'; ?>"
        data-show-model="<?php echo $show_model ? 'true' : 'false'; ?>"
        data-show-save-button="<?php echo $show_save_button ? 'true' : 'false'; ?>"
        data-pdf-download-enabled="<?php echo $show_pdf_download ? 'true' : 'false'; ?>"
        data-show-copy-button="<?php echo $show_copy_button ? 'true' : 'false'; ?>"
        data-save-as-post-nonce="<?php echo esc_attr($save_as_post_nonce); ?>"
        data-aipkit-conversation-ui-preset="<?php echo esc_attr($conversation_ui_preset); ?>"
        <?php echo apply_filters('aipkit_ai_forms_frontend_wrapper_attributes', '', $form_data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Extension attributes must be escaped by the provider. ?>
        <?php foreach ($labels as $key => $value) : ?>
            data-label-<?php echo esc_attr(str_replace('_', '-', $key)); ?>="<?php echo esc_attr($value); ?>"
        <?php endforeach; ?>
    >
        
        <?php if ($theme === 'custom' && !empty($custom_css)): ?>
            <style type="text/css">
                <?php echo wp_strip_all_tags($custom_css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reason: wp_strip_all_tags is used for sanitization. Escaping CSS would break it. ?>
            </style>
        <?php endif; ?>

        <?php if (!empty($form_data['title'])): ?>
            <h5 class="aipkit-ai-form-title"><?php echo esc_html($form_data['title']); ?></h5>
        <?php endif; ?>

        <form class="aipkit-ai-form-main" data-form-id="<?php echo esc_attr($form_data['id']); ?>">
            <?php if ($show_provider || $show_model) : ?>
                <div class="aipkit-ai-form-config-container">
                    <?php if ($show_provider) : ?>
                        <div class="aipkit_form-group">
                            <label class="aipkit_form-label" for="aipkit-aiform-provider-<?php echo esc_attr($form_data['id']); ?>"><?php echo esc_html($labels['provider_label']); ?></label>
                            <select id="aipkit-aiform-provider-<?php echo esc_attr($form_data['id']); ?>" name="aipkit_form_field[ai_provider]" class="aipkit_form-input aipkit_aiform_provider_select">
                                <?php
                                $all_providers = \WPAICG\AIPKit_Providers::get_text_generation_providers(false);
                                if (class_exists(PaidShortcode::class)) {
                                    $all_providers = PaidShortcode::add_providers($all_providers);
                                }

                                $allowed_providers = !empty($allowed_providers_str) ? array_map('trim', explode(',', $allowed_providers_str)) : [];
                                $providers_to_show = !empty($allowed_providers) ? array_intersect($all_providers, $allowed_providers) : $all_providers;

                        foreach ($providers_to_show as $provider_name) {
                            echo '<option value="' . esc_attr($provider_name) . '"' . selected($form_data['ai_provider'], $provider_name, false) . '>' . esc_html(\WPAICG\AIPKit_Providers::get_provider_display_name((string) $provider_name)) . '</option>';
                        }
                        ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($show_model) : ?>
                         <div class="aipkit_form-group">
                            <label class="aipkit_form-label" for="aipkit-aiform-model-<?php echo esc_attr($form_data['id']); ?>"><?php echo esc_html($labels['model_label']); ?></label>
                            <select id="aipkit-aiform-model-<?php echo esc_attr($form_data['id']); ?>" name="aipkit_form_field[ai_model]" class="aipkit_form-input aipkit_aiform_model_select" data-provider="<?php echo esc_attr($form_data['ai_provider']); ?>" data-current-value="<?php echo esc_attr($form_data['ai_model']); ?>">
                                 <option value=""><?php esc_html_e('Loading models...', 'gpt3-ai-content-generator'); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                 <hr class="aipkit_hr">
            <?php endif; ?>

            <?php
            if (!empty($form_data['structure']) && is_array($form_data['structure'])) {
                $is_new_structure = isset($form_data['structure'][0]['type']) && $form_data['structure'][0]['type'] === 'layout-row';

                if ($is_new_structure) {
                    foreach ($form_data['structure'] as $row) {
                        if (!isset($row['columns']) || !is_array($row['columns'])) {
                            continue;
                        }
                        $row_open_tag = class_exists(PaidShortcode::class)
                            ? PaidShortcode::get_row_open_tag($row)
                            : '<div class="aipkit-form-row">';
                        echo $row_open_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The paid helper escapes each attribute; the shared fallback is static markup.
                        foreach ($row['columns'] as $column) {
                            if (!isset($column['elements']) || !is_array($column['elements'])) {
                                continue;
                            }
                            $width_style = isset($column['width']) ? 'flex-basis: ' . esc_attr($column['width']) . ';' : '';
                            echo '<div class="aipkit-form-column" style="' . esc_attr($width_style) . '">';
                            foreach ($column['elements'] as $element) {
                                \WPAICG\AIForms\Frontend\Shortcode\Renderer\render_field_logic($element, $form_data['id']);
                            }
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                } else {
                    foreach ($form_data['structure'] as $element) {
                        \WPAICG\AIForms\Frontend\Shortcode\Renderer\render_field_logic($element, $form_data['id']);
                    }
                }
            } else {
                ?>
                <div class="aipkit_form-group">
                    <label for="aipkit_user_input_<?php echo esc_attr($form_data['id']); ?>" class="aipkit_form-label"><?php esc_html_e('Your Input:', 'gpt3-ai-content-generator'); ?></label>
                    <textarea
                        id="aipkit_user_input_<?php echo esc_attr($form_data['id']); ?>"
                        name="aipkit_form_field[user_input]"
                        class="aipkit_form-input"
                        rows="4"
                        placeholder="<?php esc_attr_e('Enter your text here...', 'gpt3-ai-content-generator'); ?>"
                    ></textarea>
                </div>
                <?php
            }
    ?>

            <button type="submit" class="aipkit_btn aipkit_btn-primary">
                <span class="aipkit_btn-text"><?php echo esc_html($labels['generate_button']); ?></span>
                <span class="aipkit_spinner" style="display:none; margin-left: 5px;"></span>
            </button>
        </form>

        <div class="aipkit-ai-form-results" style="display: none;">
             <?php // Content will be injected here by JS?>
        </div>
        <?php do_action('aipkit_ai_forms_frontend_after_results', $form_data); ?>
    </div>
    <?php
    return ob_get_clean();
}

namespace WPAICG\AIForms\Frontend\Shortcode\Renderer;

use WPAICG\Lib\AIForms\Shortcode as PaidShortcode;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Renders the HTML for a single form field based on its configuration.
 *
 * @param array $element The configuration array for the form element.
 * @param int $form_id The ID of the parent form.
 * @return void Echos the HTML for the field.
 */
function render_field_logic(array $element, int $form_id): void
{
    $field_id_attr = 'aipkit_form_field_' . esc_attr($form_id) . '_' . esc_attr($element['fieldId']);
    $field_name_attr = 'aipkit_form_field[' . esc_attr($element['fieldId']) . ']';
    $is_required = !empty($element['required']);
    $required_attr = $is_required ? 'required' : '';
    $help_text = $element['helpText'] ?? '';

    switch ($element['type']) {
        case 'text-input':
        case 'textarea':
        case 'select':
            echo '<div class="aipkit_form-group">';
            echo '<label for="' . esc_attr($field_id_attr) . '" class="aipkit_form-label">';
            echo esc_html($element['label']);
            if (!empty($element['required'])) {
                echo ' <span class="aipkit-required-indicator" aria-hidden="true">*</span>';
            }
            echo '</label>';
            switch ($element['type']) {
                case 'text-input':
                    echo '<input type="text" id="' . esc_attr($field_id_attr) . '" name="' . esc_attr($field_name_attr) . '" class="aipkit_form-input" placeholder="' . esc_attr($element['placeholder'] ?? '') . '" ' . esc_attr($required_attr) . '>';
                    break;
                case 'textarea':
                    echo '<textarea id="' . esc_attr($field_id_attr) . '" name="' . esc_attr($field_name_attr) . '" class="aipkit_form-input" rows="4" placeholder="' . esc_attr($element['placeholder'] ?? '') . '" ' . esc_attr($required_attr) . '></textarea>';
                    break;
                case 'select':
                    echo '<select id="' . esc_attr($field_id_attr) . '" name="' . esc_attr($field_name_attr) . '" class="aipkit_form-input" ' . esc_attr($required_attr) . '>';
                    if (!empty($element['placeholder'])) {
                        echo '<option value="">' . esc_html($element['placeholder']) . '</option>';
                    }
                    if (!empty($element['options']) && is_array($element['options'])) {
                        foreach ($element['options'] as $option) {
                            echo '<option value="' . esc_attr($option['value']) . '">' . esc_html($option['text']) . '</option>';
                        }
                    }
                    echo '</select>';
                    break;
            }
            if (!empty($help_text)) {
                echo '<p class="aipkit_form-help">' . wp_kses_post($help_text) . '</p>';
            }
            echo '</div>'; // .aipkit_form-group
            break;

        case 'checkbox':
            echo '<fieldset class="aipkit_form-group aipkit-checkbox-group">';
            echo '<legend class="aipkit_form-label">' . esc_html($element['label']);
            if (!empty($element['required'])) {
                echo ' <span class="aipkit-required-indicator" aria-hidden="true">*</span>';
            }
            echo '</legend>';
            if (!empty($element['options']) && is_array($element['options'])) {
                foreach ($element['options'] as $index => $option) {
                    $checkbox_id   = esc_attr($field_id_attr . '_' . $index);
                    $checkbox_name = $field_name_attr . '[]'; // Corrected: Do not escape brackets in the name attribute.
                    $required_data_attr = $is_required ? ' data-is-required="true"' : '';
                    echo '<div class="aipkit-checkbox-item">';
                    echo '<input type="checkbox" id="' . $checkbox_id . '" name="' . $checkbox_name . '" value="' . esc_attr($option['value']) . '" class="aipkit_form-input-checkbox"' . $required_data_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reason: $checkbox_id and $checkbox_name are already escaped/safe at their definitions.
                    echo '<label for="' . $checkbox_id . '">' . esc_html($option['text']) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reason: $checkbox_id is already escaped at its definition.
                    echo '</div>';
                }
            }
            if (!empty($help_text)) {
                echo '<p class="aipkit_form-help">' . wp_kses_post($help_text) . '</p>';
            }
            echo '</fieldset>';
            break;

        case 'radio-button':
            echo '<fieldset class="aipkit_form-group aipkit-radio-group">';
            echo '<legend class="aipkit_form-label">' . esc_html($element['label']);
            if (!empty($element['required'])) {
                echo ' <span class="aipkit-required-indicator" aria-hidden="true">*</span>';
            }
            echo '</legend>';
            if (!empty($element['options']) && is_array($element['options'])) {
                foreach ($element['options'] as $index => $option) {
                    $radio_id = esc_attr($field_id_attr . '_' . $index);
                    echo '<div class="aipkit-radio-item">';
                    echo '<input type="radio" id="' . $radio_id . '" name="' . esc_attr($field_name_attr) . '" value="' . esc_attr($option['value']) . '" ' . esc_attr($required_attr) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reason: $radio_id is already escaped at its definition.
                    echo '<label for="' . $radio_id . '">' . esc_html($option['text']) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reason: $radio_id is already escaped at its definition.
                    echo '</div>';
                }
            }
            if (!empty($help_text)) {
                echo '<p class="aipkit_form-help">' . wp_kses_post($help_text) . '</p>';
            }
            echo '</fieldset>';
            break;

        case 'file-upload':
        case 'image-upload':
            if (!class_exists(PaidShortcode::class) || !PaidShortcode::render_upload_field($element, $form_id)) {
                echo '<div class="aipkit_form-group">';
                echo '<label class="aipkit_form-label">' . esc_html($element['label']) . '</label>';
                echo '<p class="aipkit_form-help"><em>' . ($element['type'] === 'image-upload'
                    ? esc_html__('Image upload is a paid feature.', 'gpt3-ai-content-generator')
                    : esc_html__('File upload is a paid feature.', 'gpt3-ai-content-generator')) . '</em></p>';
                echo '</div>';
            }
            break;
    }
}
