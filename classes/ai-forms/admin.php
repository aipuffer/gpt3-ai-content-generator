<?php


namespace WPAICG\AIForms\Admin;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles administrative setup tasks for the AI Forms module, like CPT registration.
 */
class AIPKit_AI_Form_Admin_Setup
{
    public const POST_TYPE = 'aipkit_ai_form'; // Post type slug for AI Forms

    /**
     * Register the custom post type for AI Forms.
     * Hooked to 'init'.
     */
    public function register_cpt()
    {
        $labels = array(
            'name'               => _x('AI Forms', 'post type general name', 'gpt3-ai-content-generator'),
            'singular_name'      => _x('AI Form', 'post type singular name', 'gpt3-ai-content-generator'),
            'menu_name'          => _x('AI Forms', 'admin menu', 'gpt3-ai-content-generator'),
            'name_admin_bar'     => _x('AI Form', 'add new on admin bar', 'gpt3-ai-content-generator'),
            'add_new'            => _x('Add New', 'ai_form', 'gpt3-ai-content-generator'),
            'add_new_item'       => __('Add New AI Form', 'gpt3-ai-content-generator'),
            'new_item'           => __('New AI Form', 'gpt3-ai-content-generator'),
            'edit_item'          => __('Edit AI Form', 'gpt3-ai-content-generator'),
            'view_item'          => __('View AI Form', 'gpt3-ai-content-generator'),
            'all_items'          => __('All AI Forms', 'gpt3-ai-content-generator'),
            'search_items'       => __('Search AI Forms', 'gpt3-ai-content-generator'),
            'parent_item_colon'  => __('Parent AI Forms:', 'gpt3-ai-content-generator'),
            'not_found'          => __('No AI Forms found.', 'gpt3-ai-content-generator'),
            'not_found_in_trash' => __('No AI Forms found in Trash.', 'gpt3-ai-content-generator')
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false, // Not publicly queryable unless explicitly set via shortcode
            'publicly_queryable' => false,
            'show_ui'            => false, // Will be managed within the AIPKit dashboard
            'show_in_menu'       => false, // Not a top-level menu item
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array('title', 'custom-fields'), // title for internal naming, custom-fields for settings
            'show_in_rest'       => false, // No REST API support by default
        );

        register_post_type(self::POST_TYPE, $args);
    }
}
