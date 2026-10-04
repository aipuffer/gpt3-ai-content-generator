<?php

namespace WPAICG\AutoGPT\Cron\EventProcessor\Trigger;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$premium_logic_path = WPAICG_LIB_DIR . 'automations/content-enhancement-trigger.php';
if (file_exists($premium_logic_path)) {
    require_once $premium_logic_path;
}

/**
 * Safe fallback when the premium Rewrite Content trigger runtime is not present.
 *
 * @param int $task_id The ID of the task.
 * @param array $task_config The configuration of the task.
 * @param string|null $last_run_time The last time the task was run.
 * @return void
 */
if (!function_exists(__NAMESPACE__ . '\\trigger_content_enhancement_task_logic')) {
    function trigger_content_enhancement_task_logic(int $task_id, array $task_config, ?string $last_run_time = null): void
    {
        unset($task_id, $task_config, $last_run_time);
    }
}
