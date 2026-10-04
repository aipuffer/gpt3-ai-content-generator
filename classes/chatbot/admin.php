<?php

namespace WPAICG\Chat\Admin;

// Removed unused DefaultBotSetup use statement

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles administrative setup tasks for the Chat module, like CPT registration.
 */
class AdminSetup {

    const POST_TYPE = 'aipkit_chatbot'; // Make constant accessible

    /**
     * Register the custom post type for Chatbots.
     */
    public function register_chatbot_post_type() {
        $labels = array(
            'name'               => _x('AIPKit Chatbots', 'post type general name', 'gpt3-ai-content-generator'),
            'singular_name'      => _x('AIPKit Chatbot', 'post type singular name', 'gpt3-ai-content-generator'),
            'menu_name'          => _x('AIPKit Chatbots', 'admin menu', 'gpt3-ai-content-generator'),
            'name_admin_bar'     => _x('AIPKit Chatbot', 'add new on admin bar', 'gpt3-ai-content-generator'),
            'add_new'            => _x('Add New', 'chatbot', 'gpt3-ai-content-generator'),
            'add_new_item'       => __('Add New AIPKit Chatbot', 'gpt3-ai-content-generator'),
            'new_item'           => __('New AIPKit Chatbot', 'gpt3-ai-content-generator'),
            'edit_item'          => __('Edit AIPKit Chatbot', 'gpt3-ai-content-generator'),
            'view_item'          => __('View AIPKit Chatbot', 'gpt3-ai-content-generator'),
            'all_items'          => __('All AIPKit Chatbots', 'gpt3-ai-content-generator'),
            'search_items'       => __('Search AIPKit Chatbots', 'gpt3-ai-content-generator'),
            'parent_item_colon'  => __('Parent AIPKit Chatbots:', 'gpt3-ai-content-generator'),
            'not_found'          => __('No chatbots found.', 'gpt3-ai-content-generator'),
            'not_found_in_trash' => __('No chatbots found in Trash.', 'gpt3-ai-content-generator')
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => false,
            'show_in_menu'       => false,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array('title', 'custom-fields'),
            'show_in_rest'       => false,
        );

        register_post_type(self::POST_TYPE, $args);
    }
}