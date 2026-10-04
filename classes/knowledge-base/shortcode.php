<?php

namespace WPAICG\Shortcodes;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Semantic_Search_Shortcode
 *
 * Handles the rendering of the [aipkit_semantic_search] shortcode.
 * Asset enqueueing is handled centrally by AIPKit_Shortcodes_Manager.
 */
class AIPKit_Semantic_Search_Shortcode
{
    /**
     * Render the shortcode output.
     *
     * @param array $atts Shortcode attributes (currently none supported).
     * @return string HTML output.
     */
    public function render_shortcode($atts = [])
    {
        // 1. Retrieve saved settings (handled by localization in AIPKit_Shortcodes_Manager)

        // 2. Render the basic HTML structure
        ob_start();
        ?>
        <div class="aipkit_semantic_search_wrapper">
            <form class="aipkit_semantic_search_form" onsubmit="return false;">
                <input type="search" class="aipkit_semantic_search_input" placeholder="<?php esc_attr_e('Search...', 'gpt3-ai-content-generator'); ?>" required>
                <button type="submit" class="aipkit_semantic_search_button">
                    <span class="aipkit_btn-text"><?php esc_html_e('Search', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_spinner" style="display:none;"></span>
                </button>
            </form>
            <div class="aipkit_semantic_search_results">
            </div>
        </div>
        <?php
        return ob_get_clean();

        // 3 & 4. Enqueue and Localize Assets
        // This is handled centrally in AIPKit_Shortcodes_Manager to avoid redundant enqueues/localizations
        // when multiple shortcodes are on a page. The manager's check `has_shortcode` will trigger it.
    }
}
