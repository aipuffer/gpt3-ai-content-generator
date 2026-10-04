<?php

namespace WPAICG\Core\Providers;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/chat-completions.php';

// Shared registration; the implementation is included only when the paid library exists.
$aipkit_ollama_provider = defined('WPAICG_PLUGIN_DIR') ? WPAICG_PLUGIN_DIR . 'lib/ai/ollama.php' : null;
if ($aipkit_ollama_provider && file_exists($aipkit_ollama_provider)) {
    require_once $aipkit_ollama_provider;
}
