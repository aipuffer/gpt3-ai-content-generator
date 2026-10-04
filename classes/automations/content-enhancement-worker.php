<?php

namespace WPAICG\AutoGPT\Cron\EventProcessor\Processor\ContentEnhancement;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$premium_logic_path = WPAICG_LIB_DIR . 'automations/content-enhancement-worker.php';
if (file_exists($premium_logic_path)) {
    require_once $premium_logic_path;
}

/**
 * Safe fallback when the premium Rewrite Content processor is not present.
 *
 * @param array $item The queue item from the database.
 * @param array $item_config The decoded item_config from the queue item.
 * @return array ['status' => 'success'|'error', 'message' => '...']
 */
if (!function_exists(__NAMESPACE__ . '\\process_enhancement_item_logic')) {
    function process_enhancement_item_logic(array $item, array $item_config): array
    {
        unset($item, $item_config);

        return ['status' => 'error', 'message' => __('This is a Pro feature.', 'gpt3-ai-content-generator')];
    }
}
