<?php
/**
 * First-run setup: goals -> AI connection -> first result. Shown once to new installs.
 */
namespace WPAICG\Admin;

use WPAICG\AIPKit_Providers;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Cloud\Connection;
use WPAICG\Core\AIPKit_AI_Caller;

if (!defined('ABSPATH')) {
    exit;
}

final class Onboarding
{
    public const OPTION = 'aipkit_onboarding';
    public const PAGE = 'aipkit-setup';
    private const NONCE = 'aipkit_onboarding';

    /** Goal -> modules it needs (keys of aipkit_dashboard::$default_module_settings) and where it lands. */
    private const GOALS = [
        'chatbot'  => ['modules' => ['chat_bot'], 'module' => 'chatbot'],
        'write'    => ['modules' => ['content_writer'], 'module' => 'content-writer'],
        'products' => ['modules' => ['content_writer'], 'module' => 'content-writer'],
        'auto'     => ['modules' => ['content_writer', 'autogpt'], 'module' => 'autogpt'],
        'forms'    => ['modules' => ['ai_forms'], 'module' => 'ai-forms'],
    ];
    /** Byok providers offered during setup (keys of AIPKit_Providers defaults). */
    public const KEY_PROVIDERS = ['OpenAI' => 'OpenAI', 'Claude' => 'Anthropic', 'Google' => 'Google', 'OpenRouter' => 'OpenRouter', 'DeepSeek' => 'DeepSeek', 'xAI' => 'xAI'];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_page'], 20);
        add_action('admin_init', [self::class, 'maybe_redirect']);
        add_action('wp_ajax_aipkit_onboarding', [self::class, 'handle']);
        add_filter('admin_body_class', [self::class, 'body_class']);
        add_filter('admin_title', [self::class, 'admin_title']);
        wpaicg_gacg_fs()->add_action('account_page_load_before_departure', [self::class, 'return_from_account_change']);
    }

    /** Called on activation: only a site that never had AI Puffer settings gets the setup flow. */
    public static function mark_fresh_install(bool $fresh): void
    {
        if ($fresh && get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, ['status' => 'pending', 'created' => time()], '', false);
        }
    }

    public static function state(): array
    {
        $state = get_option(self::OPTION, []);
        return is_array($state) ? $state : [];
    }

    private static function save(array $changes): void
    {
        update_option(self::OPTION, array_merge(self::state(), $changes), false);
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    /** Let the SDK finish validating the email callback before returning an unfinished setup to AI connection. */
    public static function return_from_account_change(): void
    {
        if (!AIPKit_Role_Manager::user_can_manage_settings() || wp_doing_ajax()
            || (self::state()['status'] ?? '') !== 'pending' || empty(self::state()['goals'])) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only, after the SDK handles the account action.
        $action = sanitize_key(wp_unslash($_GET['fs_action'] ?? ''));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only, after the SDK handles the account action.
        $state = sanitize_key(wp_unslash($_GET['state'] ?? ''));
        if ($action !== 'change_owner' || !in_array($state, ['owner_confirmed', 'candidate_confirmed'], true)) { return; }
        // This is an informational hint, not proof of a completed transfer.
        $pending = $state === 'owner_confirmed';
        wp_safe_redirect(($pending ? add_query_arg('aipkit_account_change', 'pending', self::url()) : self::url()) . '#connect');
        exit;
    }

    public static function add_page(): void
    {
        if (!AIPKit_Role_Manager::user_can_manage_settings()) { return; }
        // Hidden page (no menu entry): reached by the first-visit redirect or from Settings.
        add_submenu_page('', __('Set up AI Puffer', 'gpt3-ai-content-generator'), '', 'read', self::PAGE, [self::class, 'render']);
    }

    /**
     * New installs are sent to setup once, on their first visit to the plugin dashboard. Freemius's own
     * opt-in screen (shown on that same page until the user opts in or skips) comes first and is left alone.
     */
    public static function maybe_redirect(): void
    {
        if (wp_doing_ajax() || (self::state()['status'] ?? '') !== 'pending' || !AIPKit_Role_Manager::user_can_manage_settings()) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
        if ($page !== 'wpaicg' || isset($_GET['aipkit_module'])) { return; }
        if (function_exists('wpaicg_gacg_fs')) {
            $fs = wpaicg_gacg_fs();
            if (!$fs->is_registered() && !$fs->is_anonymous() && !$fs->is_pending_activation()) { return; }
        }
        wp_safe_redirect(self::url());
        exit;
    }

    public static function body_class($classes)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && strpos((string) $screen->id, self::PAGE) !== false ? $classes . ' aipkit-setup-screen' : $classes;
    }

    /** Hidden pages have no menu title for WordPress to use in the browser tab. */
    public static function admin_title($title)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, self::PAGE) === false) { return $title; }
        /* translators: %s: site name. */
        return sprintf(__('Set up AI Puffer ‹ %s', 'gpt3-ai-content-generator'), get_bloginfo('name'));
    }

    public static function render(): void
    {
        if (!AIPKit_Role_Manager::user_can_manage_settings()) {
            wp_die(esc_html__('You do not have permission to set up AI Puffer.', 'gpt3-ai-content-generator'), 403);
        }
        $css = WPAICG_PLUGIN_DIR . 'dist/css/admin-onboarding.bundle.css';
        $js = WPAICG_PLUGIN_DIR . 'dist/js/admin-onboarding.bundle.js';
        wp_enqueue_style('aipkit-onboarding', WPAICG_PLUGIN_URL . 'dist/css/admin-onboarding.bundle.css', [], file_exists($css) ? (string) filemtime($css) : WPAICG_VERSION);
        wp_enqueue_script('aipkit-onboarding', WPAICG_PLUGIN_URL . 'dist/js/admin-onboarding.bundle.js', ['wp-i18n'], file_exists($js) ? (string) filemtime($js) : WPAICG_VERSION, true);
        wp_set_script_translations('aipkit-onboarding', 'gpt3-ai-content-generator', WPAICG_PLUGIN_DIR . 'languages');
        // The chatbot step shows the real chatbot, with the same public bundle as the Chatbot module's preview.
        if (class_exists(\WPAICG\Includes\AIPKit_Shared_Assets_Manager::class)) {
            \WPAICG\Includes\AIPKit_Shared_Assets_Manager::register(WPAICG_VERSION);
        }
        $chat_css = WPAICG_PLUGIN_DIR . 'dist/css/public-main.bundle.css';
        $chat_js = WPAICG_PLUGIN_DIR . 'dist/js/public-main.bundle.js';
        wp_enqueue_style('aipkit-public-main-css', WPAICG_PLUGIN_URL . 'dist/css/public-main.bundle.css', ['dashicons'], file_exists($chat_css) ? (string) filemtime($chat_css) : WPAICG_VERSION);
        wp_enqueue_script('aipkit-public-main', WPAICG_PLUGIN_URL . 'dist/js/public-main.bundle.js', ['wp-i18n', 'aipkit_markdown-it'], file_exists($chat_js) ? (string) filemtime($chat_js) : WPAICG_VERSION, true);
        wp_set_script_translations('aipkit-public-main', 'gpt3-ai-content-generator', WPAICG_PLUGIN_DIR . 'languages');
        if (class_exists(\WPAICG\Includes\AIPKit_Shared_Assets_Manager::class)) {
            \WPAICG\Includes\AIPKit_Shared_Assets_Manager::attach_public_asset_urls('aipkit-public-main');
        }
        wp_localize_script('aipkit-onboarding', 'aipkitSetup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'syncNonce' => wp_create_nonce('aipkit_nonce'),
            'dashboardUrl' => admin_url('admin.php?page=wpaicg'),
            'cloudConnected' => !empty(Connection::display()['connected']),
            'goals' => array_values(array_intersect((array) (self::state()['goals'] ?? []), array_merge(array_keys(self::GOALS), ['explore']))),
        ]);
        include WPAICG_PLUGIN_DIR . 'admin/views/onboarding/page.php';
    }

    /** Values the view needs; never secrets. */
    public static function view_data(): array
    {
        $cloud = self::cloud_view_data();
        $provider_keys = [];
        foreach (array_keys(self::KEY_PROVIDERS) as $provider) {
            $provider_keys[$provider] = trim((string) (AIPKit_Providers::get_provider_data($provider)['api_key'] ?? '')) !== '';
        }
        return [
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only hint after the SDK's ownership callback.
            'account_change_pending' => sanitize_key(wp_unslash($_GET['aipkit_account_change'] ?? '')) === 'pending',
            'woocommerce' => class_exists('WooCommerce'),
            'cloud' => $cloud,
            'registered' => $cloud['registered'],
            'providers' => self::KEY_PROVIDERS,
            'has_provider_key' => $provider_keys,
            'products' => self::products(),
        ];
    }

    /** Cached connection state for both initial rendering and manual setup actions. */
    private static function cloud_view_data(bool $models_ready = true, bool $credits_ready = true): array
    {
        $display = Connection::display();
        $connected = !empty($display['connected']);
        $credits = $display['credits'];
        $has_credits = $credits_ready && is_array($credits) && (float) $credits['available'] > 0 && empty($credits['restricted']);
        $model = Connection::default_model();
        $models_ready = $models_ready && $model !== '' && in_array($model, array_column(Connection::models(), 'id'), true);
        $ready = $connected && $has_credits && $models_ready;
        $pending = !empty($display['confirm_email']);
        $message = '';
        if ($pending) {
            /* translators: %s: email address awaiting confirmation. */
            $message = sprintf(__('Check your inbox for a confirmation link sent to %s. Open it, then return here to check again.', 'gpt3-ai-content-generator'), $display['email']);
        } elseif ($connected) {
            if (!$credits_ready || !is_array($credits)) {
                $message = Connection::verification_message('balance_unavailable');
            } elseif (!$has_credits) {
                $message = Connection::allowance_message($credits);
                if ($message === '' && $display['email_verified'] === true) {
                    $message = __('You have no available credits. Add credits in Usage, use your own API key, or set this up later.', 'gpt3-ai-content-generator');
                }
            } elseif (!$models_ready) {
                $message = __('Cloud is connected, but its models could not be loaded. Try again.', 'gpt3-ai-content-generator');
            } else {
                $message = __('Connected to AI Puffer Cloud.', 'gpt3-ai-content-generator');
            }
        }
        return [
            'connected' => $connected, 'registered' => !empty($display['registered']), 'pendingEmail' => $pending,
            'email' => (string) ($display['email'] ?? ''), 'manageEmailUrl' => (string) ($display['manage_email_url'] ?? ''),
            'emailVerified' => $display['email_verified'] === true,
            'accountChangeMessage' => Connection::account_change_message(),
            'emailUpdate' => $display['email_update'] ?? null,
            'connectionDiagnostic' => \WPAICG\Cloud\ConnectionDiagnostics::report(),
            'emailRecoveryHtml' => Connection::email_recovery_html(), 'ready' => $ready, 'message' => $message,
            'retry' => $connected && !$ready && (!$credits_ready || !is_array($credits) || $display['email_verified'] === true || $has_credits),
        ];
    }

    public static function handle(): void
    {
        if (!AIPKit_Role_Manager::user_can_manage_settings() || !check_ajax_referer(self::NONCE, '_wpnonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Reload this page and try again.', 'gpt3-ai-content-generator')], 403);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        switch ($op) {
            case 'goals': self::op_goals(); break;
            case 'progress':
                $state = self::state();
                if (!empty($state['goals'])) {
                    Connection::record_onboarding([
                        'goals' => $state['goals'], 'power' => $state['power'] ?? 'later',
                        'results' => $state['results'] ?? [], 'saved' => $state['saved'] ?? [],
                        'status' => $state['status'] ?? 'pending',
                    ]);
                }
                wp_send_json_success();
                break;
            case 'cloud': self::op_cloud(); break;
            case 'cloud_state': wp_send_json_success(self::cloud_view_data()); break;
            case 'cloud_disconnect':
                if (Connection::transition('disconnect') !== 'disconnected') {
                    wp_send_json_error(['message' => __('Could not disconnect. Your connection has been preserved. Try again.', 'gpt3-ai-content-generator')], 400);
                }
                wp_send_json_success(self::cloud_view_data());
                break;
            case 'use_key': self::op_use_key(); break;
            case 'chat_widget': self::op_chat_widget(); break;
            case 'chat_result': self::record_result('chatbot'); wp_send_json_success(); break;
            case 'publish_bot': self::op_publish_bot(); break;
            case 'draft': self::op_draft(); break;
            case 'template': self::op_template(); break;
            case 'product': self::op_product(); break;
            case 'save_product': self::op_save_product(); break;
            case 'updates': self::op_updates(); break;
            case 'finish': self::op_finish('done'); break;
            case 'skip': self::op_finish('skipped'); break;
            default: wp_send_json_error(['message' => __('Unknown setup step.', 'gpt3-ai-content-generator')], 400);
        }
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- All ops run after handle()'s nonce check.
    private static function post(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    /** Keep the selected order for both the preview and the final destination. */
    private static function op_goals(): void
    {
        $raw = isset($_POST['goals']) && is_array($_POST['goals']) ? array_map('sanitize_key', wp_unslash($_POST['goals'])) : [];
        $goals = array_values(array_unique(array_intersect($raw, array_merge(array_keys(self::GOALS), ['explore']))));
        if (!$goals) { wp_send_json_error(['message' => __('Choose at least one option.', 'gpt3-ai-content-generator')], 400); }
        $explore = in_array('explore', $goals, true);
        if ($explore) { $goals = ['explore']; }
        if (class_exists('\\WPAICG\\aipkit_dashboard')) {
            $modules = array_fill_keys(array_keys(\WPAICG\aipkit_dashboard::$default_module_settings), $explore);
            $modules['stats_viewer'] = true;
            $modules['sources'] = true;
            if (!$explore) { foreach ($goals as $goal) { foreach (self::GOALS[$goal]['modules'] as $module) { $modules[$module] = true; } } }
            $opts = get_option('aipkit_options');
            $opts = is_array($opts) ? $opts : [];
            $opts['module_settings'] = $modules;
            update_option('aipkit_options', $opts, 'no');
        }
        self::save(['goals' => $goals]);
        wp_send_json_success(['goals' => $goals]);
    }

    /** One-click Cloud connect (same rules and consent as Settings), then make Cloud the default. */
    private static function op_cloud(): void
    {
        if (!class_exists(Connection::class)) { wp_send_json_error(['message' => __('AI Puffer Cloud is not available.', 'gpt3-ai-content-generator')], 400); }
        $consent = self::post('consent') === 'yes';
        $display = Connection::display();
        $connected = $display['connected'];
        // Confirmation returns through Freemius's own activation handler. Checking never resends email.
        if (self::post('check_email') === 'yes' && !$display['registered']) {
            wp_send_json_success(self::cloud_view_data());
        }
        $status = $connected ? 'connected' : Connection::transition('connect', $consent, self::post('marketing') === 'yes', self::post('email'), 'onboarding');
        if ($status === 'confirm_email') { self::save(['power' => 'cloud']); wp_send_json_success(self::cloud_view_data()); }
        if ($status !== 'connected') {
            wp_send_json_error(['status' => $status, 'connectionDiagnostic' => \WPAICG\Cloud\ConnectionDiagnostics::report(), 'message' => Connection::connection_message($status) ?: __('Could not connect to AI Puffer Cloud. Please try again.', 'gpt3-ai-content-generator')], 400);
        }
        self::save(['power' => 'cloud']);
        $credits_ready = !$connected || Connection::transition('verify') !== 'balance_unavailable';
        // Re-entry and an explicit retry refresh the catalog before making Cloud active.
        $synced = !$connected || Connection::transition('sync') === 'synced';
        $view = self::cloud_view_data($synced, $credits_ready);
        if (!$view['ready']) { wp_send_json_success($view); }
        $model = Connection::default_model();
        self::use_provider('AIPufferCloud', $model);
        wp_send_json_success($view + ['provider' => 'AIPufferCloud', 'model' => $model, 'label' => __('AI Puffer Cloud', 'gpt3-ai-content-generator')]);
    }

    /** After a successful model sync: make that provider (and its default model) the site default. */
    private static function op_use_key(): void
    {
        $provider = self::post('provider');
        if (!isset(self::KEY_PROVIDERS[$provider]) || empty(AIPKit_Providers::get_provider_data($provider)['api_key'])) {
            wp_send_json_error(['message' => __('Add a working API key first.', 'gpt3-ai-content-generator')], 400);
        }
        $connection = AIPKit_Providers::get_provider_connection_states()[strtolower($provider)] ?? [];
        if (empty($connection['last_success']) || !empty($connection['connection_changed'])) {
            wp_send_json_error(['message' => __('Check your API key before continuing.', 'gpt3-ai-content-generator')], 400);
        }
        $models = AIPKit_Providers::query_models([
            'catalog_keys' => [$provider], 'required_capabilities' => ['text_generation'],
            'statuses' => ['available'], 'include_bootstrap' => false,
        ]);
        $ids = array_column($models, 'id');
        $model = (string) (AIPKit_Providers::get_provider_data($provider)['model'] ?? '');
        if (!in_array($model, $ids, true)) { $model = AIPKit_Providers::get_default_model_id($provider); }
        if (!in_array($model, $ids, true)) {
            $recommended = array_values(array_filter($models, static fn($row) => !empty($row['recommended'])));
            $model = (string) ($recommended[0]['id'] ?? $ids[0] ?? '');
        }
        if ($model === '') { wp_send_json_error(['message' => __('No models are available for this key yet. Try Check key again.', 'gpt3-ai-content-generator')], 400); }
        self::use_provider($provider, $model);
        self::save(['power' => 'key']);
        wp_send_json_success(['provider' => $provider, 'model' => $model, 'label' => self::KEY_PROVIDERS[$provider] . ' · ' . $model]);
    }

    /** Site default provider/model and the default chatbot follow the choice made during setup. */
    private static function use_provider(string $provider, string $model): void
    {
        AIPKit_Providers::save_current_provider($provider);
        AIPKit_Providers::save_provider_data($provider, ['model' => $model]);
        $bot = self::default_bot_id();
        if ($bot) {
            update_post_meta($bot, '_aipkit_provider', $provider);
            update_post_meta($bot, '_aipkit_model', $model);
            \WPAICG\Chat\Storage\AIPKit_Bot_Settings_Initializer::initialize_feature_defaults($bot, $provider, true);
        }
        self::save(['provider' => $provider, 'model' => $model]);
    }

    private static function default_bot_id(): int
    {
        if (!class_exists('\\WPAICG\\Chat\\Storage\\DefaultBotSetup')) {
            $path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
            if (file_exists($path)) { require_once $path; }
        }
        return class_exists('\\WPAICG\\Chat\\Storage\\DefaultBotSetup') ? (int) \WPAICG\Chat\Storage\DefaultBotSetup::get_default_bot_id() : 0;
    }

    /** @return array{0: string, 1: string}|null The provider and model chosen during setup. */
    private static function chosen(): ?array
    {
        $state = self::state();
        return !empty($state['provider']) && !empty($state['model']) ? [$state['provider'], $state['model']] : null;
    }

    /** Save outcomes locally after successful actions; never store prompts or generated text here. */
    private static function record_result(string $goal, bool $saved = false): void
    {
        $state = self::state();
        $changes = ['results' => array_values(array_unique(array_merge($state['results'] ?? [], [$goal])))];
        if ($saved) { $changes['saved'] = array_values(array_unique(array_merge($state['saved'] ?? [], [$goal]))); }
        self::save($changes);
    }

    /** Try the default chatbot: the real one, inline, with the provider and model chosen during setup. */
    private static function op_chat_widget(): void
    {
        $bot = self::default_bot_id();
        if (!self::chosen() || !$bot) { wp_send_json_error(['message' => __('Connect AI first, then try your chatbot.', 'gpt3-ai-content-generator')], 400); }
        $html = (new \WPAICG\Chat\Frontend\Shortcode())->render_inline($bot);
        if (!is_string($html) || strpos($html, 'aipkit_chat_container') === false) {
            wp_send_json_error(['message' => __('Your chatbot could not be shown. Open it in Chatbots instead.', 'gpt3-ai-content-generator')], 500);
        }
        wp_send_json_success(['html' => $html]);
    }

    private static function op_publish_bot(): void
    {
        $bot = self::default_bot_id();
        if (!$bot) { wp_send_json_error(['message' => __('The chatbot could not be found.', 'gpt3-ai-content-generator')], 400); }
        if (class_exists('\\WPAICG\\Chat\\Storage\\SiteWideBotManager')) { (new \WPAICG\Chat\Storage\SiteWideBotManager())->ensure_site_wide_uniqueness($bot, true); }
        update_post_meta($bot, '_aipkit_popup_enabled', '1');
        update_post_meta($bot, '_aipkit_site_wide_enabled', '1');
        if (class_exists('\\WPAICG\\Chat\\Storage\\SiteWideBotManager')) { (new \WPAICG\Chat\Storage\SiteWideBotManager())->clear_site_wide_cache(); }
        self::record_result('chatbot', true);
        wp_send_json_success(['url' => home_url('/')]);
    }

    /** First article: generated with the chosen provider and saved as a draft post. */
    private static function op_draft(): void
    {
        if (!current_user_can('edit_posts')) { wp_send_json_error(['message' => __('You do not have permission to create posts.', 'gpt3-ai-content-generator')], 403); }
        $chosen = self::chosen();
        $topic = mb_substr(self::post('topic'), 0, 200);
        if (!$chosen || $topic === '') { wp_send_json_error(['message' => __('Connect AI first, then enter a topic.', 'gpt3-ai-content-generator')], 400); }
        if (function_exists('set_time_limit')) { @set_time_limit(300); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Bounded explicit action; disabled on some hosts. Provider requests retain their own timeouts.
        $instructions = __('You are an expert blog writer. Write in clear, friendly English. Output only the article body as HTML using <h2>, <h3>, <p> and <ul> tags. Do not include the title, <html>, <body> or markdown.', 'gpt3-ai-content-generator');
        /* translators: %s: article topic. */
        $prompt = sprintf(__('Write a helpful blog article of about 1,000 words titled "%s".', 'gpt3-ai-content-generator'), $topic);
        $result = (new AIPKit_AI_Caller(false, 'content_writer'))->make_standard_call($chosen[0], $chosen[1], [['role' => 'user', 'content' => $prompt]], ['max_completion_tokens' => 6000], $instructions);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
        $html = wp_kses_post(preg_replace('/^```(?:html)?\s*|\s*```$/', '', trim((string) ($result['content'] ?? ''))));
        if ($html === '') { wp_send_json_error(['message' => __('The draft came back empty. Please try again.', 'gpt3-ai-content-generator')], 400); }
        $post = wp_insert_post(['post_title' => $topic, 'post_content' => $html, 'post_status' => 'draft', 'post_type' => 'post'], true);
        if (is_wp_error($post)) { wp_send_json_error(['message' => $post->get_error_message()], 400); }
        self::record_result('write', true);
        $first = wp_strip_all_tags(preg_replace('/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', $html));
        wp_send_json_success([
            'title' => html_entity_decode($topic, ENT_QUOTES, 'UTF-8'),
            'excerpt' => html_entity_decode(wp_trim_words($first, 60, '…'), ENT_QUOTES, 'UTF-8'),
            'words' => str_word_count(wp_strip_all_tags($html)),
            'editUrl' => get_edit_post_link($post, 'raw'),
        ]);
    }

    /** Recent products, so the first description can be written for something real. */
    public static function products(int $limit = 20): array
    {
        if (!class_exists('WooCommerce')) { return []; }
        $products = get_posts(['post_type' => 'product', 'post_status' => ['publish', 'draft', 'pending', 'private'], 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC']);
        return array_values(array_map(static fn($product) => [
            'id' => $product->ID,
            'name' => $product->post_title !== '' ? $product->post_title : __('(untitled product)', 'gpt3-ai-content-generator'),
            'hasDescription' => trim(wp_strip_all_tags($product->post_content)) !== '',
        ], array_filter($products, static fn($product) => current_user_can('edit_post', $product->ID))));
    }

    /** Writes a product description. Nothing is saved to the product until the user asks for it. */
    private static function op_product(): void
    {
        $chosen = self::chosen();
        if (!$chosen) { wp_send_json_error(['message' => __('Connect AI first.', 'gpt3-ai-content-generator')], 400); }
        $id = (int) self::post('product_id');
        $product = $id ? get_post($id) : null;
        if ($id && (!$product || $product->post_type !== 'product')) {
            wp_send_json_error(['message' => __('That product could not be found.', 'gpt3-ai-content-generator')], 400);
        }
        if ($id && !current_user_can('edit_post', $id)) { wp_send_json_error(['message' => __('You do not have permission to edit this product.', 'gpt3-ai-content-generator')], 403); }
        $name = $product ? $product->post_title : mb_substr(self::post('name'), 0, 200);
        $details = mb_substr(self::post('details'), 0, 500);
        if ($name === '' && $details === '') { wp_send_json_error(['message' => __('Choose a product, or describe one.', 'gpt3-ai-content-generator')], 400); }
        $instructions = __('You write product descriptions for online shops. Be concrete and benefit-led, never invent specifications, prices or awards. Reply with JSON only: {"short":"one sentence","description":"<p>…</p>"}. The description is 120-180 words of HTML using <p> and at most one <ul>.', 'gpt3-ai-content-generator');
        /* translators: 1: product name. 2: extra details the user typed, or the product's current description. */
        $prompt = trim(sprintf(__('Product: %1$s%2$s', 'gpt3-ai-content-generator'), $name !== '' ? $name : __('(unnamed)', 'gpt3-ai-content-generator'), $details !== '' ? "\n" . __('Details: ', 'gpt3-ai-content-generator') . $details : ''));
        $result = (new AIPKit_AI_Caller(false, 'content_writer'))->make_standard_call($chosen[0], $chosen[1], [['role' => 'user', 'content' => $prompt]], ['max_completion_tokens' => 2000], $instructions);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
        $raw = trim((string) ($result['content'] ?? ''));
        $json = json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw), true);
        $description = wp_kses_post((string) ($json['description'] ?? $raw));
        $short = sanitize_text_field((string) ($json['short'] ?? ''));
        if (trim(wp_strip_all_tags($description)) === '') { wp_send_json_error(['message' => __('The description came back empty. Please try again.', 'gpt3-ai-content-generator')], 400); }
        $result_id = wp_generate_uuid4();
        if ($id) {
            set_transient('aipkit_setup_product_' . get_current_user_id() . '_' . $result_id, ['id' => $id, 'short' => $short, 'description' => $description], HOUR_IN_SECONDS);
        }
        self::record_result('products');
        wp_send_json_success([
            'result_id' => $result_id,
            'product_id' => $id,
            'name' => $name,
            'short' => $short,
            'description' => $description,
            'text' => trim(($short !== '' ? $short . "\n\n" : '') . wp_strip_all_tags(str_replace(['</p>', '</li>'], ["\n\n", "\n"], $description))),
            'replaces' => $product ? trim(wp_strip_all_tags($product->post_content)) !== '' : false,
        ]);
    }

    /** Saves the description that was just generated onto the product the user picked. */
    private static function op_save_product(): void
    {
        $result_id = self::post('result_id');
        $key = 'aipkit_setup_product_' . get_current_user_id() . '_' . $result_id;
        $saved = wp_is_uuid($result_id) ? get_transient($key) : false;
        $id = (int) ($saved['id'] ?? 0);
        $product = $id ? get_post($id) : null;
        if (!is_array($saved) || !$product || $product->post_type !== 'product' || $id !== (int) self::post('product_id')) {
            wp_send_json_error(['message' => __('This description is no longer available. Generate it again before saving.', 'gpt3-ai-content-generator')], 400);
        }
        if (!current_user_can('edit_post', $id)) { wp_send_json_error(['message' => __('You do not have permission to edit this product.', 'gpt3-ai-content-generator')], 403); }
        $update = ['ID' => $id, 'post_content' => $saved['description']];
        if (($saved['short'] ?? '') !== '' && trim(wp_strip_all_tags($product->post_excerpt)) === '') { $update['post_excerpt'] = $saved['short']; }
        $result = wp_update_post($update, true);
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
        delete_transient($key);
        self::record_result('products', true);
        wp_send_json_success([
            /* translators: %s: product name. */
            'message' => sprintf(__('Saved to “%s”.', 'gpt3-ai-content-generator'), get_the_title($id)),
            'editUrl' => get_edit_post_link($id, 'raw'),
        ]);
    }

    /** Ready-made AI Forms: [title, prompt template, fields]. Field IDs are the prompt's {placeholders}. */
    private static function form_templates(): array
    {
        return [
            'form_names' => [
                __('Product name generator', 'gpt3-ai-content-generator'),
                "Suggest 10 catchy, memorable names for this product. Style: {name_style}.\n\nProduct: {product}\n\nReturn a numbered list. After each name, add one short sentence explaining why it works.",
                [
                    ['type' => 'textarea', 'field_id' => 'product', 'label' => __('Describe your product', 'gpt3-ai-content-generator'), 'placeholder' => __('A reusable water bottle that keeps drinks cold for 24 hours', 'gpt3-ai-content-generator'), 'required' => true],
                    ['type' => 'select', 'field_id' => 'name_style', 'label' => __('Style', 'gpt3-ai-content-generator'), 'options' => [__('Playful', 'gpt3-ai-content-generator'), __('Professional', 'gpt3-ai-content-generator'), __('Short and punchy', 'gpt3-ai-content-generator')]],
                ],
            ],
            'form_email' => [
                __('Email writer', 'gpt3-ai-content-generator'),
                "Write a clear, well-structured email based on these notes. Tone: {tone}.\n\nNotes: {notes}\n\nInclude a subject line, then the email. Keep it concise.",
                [
                    ['type' => 'textarea', 'field_id' => 'notes', 'label' => __('What should the email say?', 'gpt3-ai-content-generator'), 'placeholder' => __('Ask my landlord to fix the heating before Friday', 'gpt3-ai-content-generator'), 'required' => true],
                    ['type' => 'select', 'field_id' => 'tone', 'label' => __('Tone', 'gpt3-ai-content-generator'), 'options' => [__('Friendly', 'gpt3-ai-content-generator'), __('Formal', 'gpt3-ai-content-generator'), __('Persuasive', 'gpt3-ai-content-generator')]],
                ],
            ],
            'form_recipe' => [
                __('Recipe from ingredients', 'gpt3-ai-content-generator'),
                "Suggest one tasty recipe that uses mainly these ingredients: {ingredients}.\nDiet: {diet}.\n\nGive the recipe a name, list the ingredients with amounts, then numbered steps. Mention the total cooking time.",
                [
                    ['type' => 'textarea', 'field_id' => 'ingredients', 'label' => __('What ingredients do you have?', 'gpt3-ai-content-generator'), 'placeholder' => __('Chicken, rice, peppers, garlic', 'gpt3-ai-content-generator'), 'required' => true],
                    ['type' => 'select', 'field_id' => 'diet', 'label' => __('Diet', 'gpt3-ai-content-generator'), 'options' => [__('No preference', 'gpt3-ai-content-generator'), __('Vegetarian', 'gpt3-ai-content-generator'), __('Vegan', 'gpt3-ai-content-generator'), __('Gluten-free', 'gpt3-ai-content-generator')]],
                ],
            ],
        ];
    }

    /** Creates a starter AI form or automation with the provider chosen during setup. */
    private static function op_template(): void
    {
        $chosen = self::chosen();
        if (!$chosen) { wp_send_json_error(['message' => __('Connect AI first.', 'gpt3-ai-content-generator')], 400); }
        $template = sanitize_key(self::post('template'));
        $result = isset(self::form_templates()[$template]) ? self::create_form($template, $chosen)
            : ($template === 'auto_blog' ? self::create_blog_task($chosen)
            : new \WP_Error('unknown_template', __('Unknown template.', 'gpt3-ai-content-generator')));
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
        self::save(['template' => $template]);
        self::record_result($template === 'auto_blog' ? 'auto' : 'forms', true);
        wp_send_json_success($result);
    }

    /** The form is saved like one built with the AI form generator, then placed on a draft page to preview. */
    private static function create_form(string $template, array $chosen)
    {
        if (!current_user_can('edit_pages')) { return new \WP_Error('page_permission', __('You do not have permission to create pages.', 'gpt3-ai-content-generator')); }
        if (!function_exists('\\WPAICG\\Admin\\Ajax\\AIForms\\aipkit_ai_forms_normalize_generated_blueprint')) {
            require_once WPAICG_PLUGIN_DIR . 'classes/ai-forms/blueprints.php';
        }
        if (!class_exists('\\WPAICG\\AIForms\\Storage\\AIPKit_AI_Form_Storage') || !function_exists('\\WPAICG\\Admin\\Ajax\\AIForms\\aipkit_ai_forms_normalize_generated_blueprint')) {
            return new \WP_Error('forms_unavailable', __('AI Forms is not available. Turn it on in Settings.', 'gpt3-ai-content-generator'));
        }
        [$title, $prompt, $fields] = self::form_templates()[$template];
        $blueprint = \WPAICG\Admin\Ajax\AIForms\aipkit_ai_forms_normalize_generated_blueprint(['title' => $title, 'prompt_template' => $prompt, 'fields' => $fields], $title, false);
        if (is_wp_error($blueprint)) { return $blueprint; }
        $settings = [
            'prompt_template' => $blueprint['prompt_template'],
            'form_structure' => wp_json_encode($blueprint['structure'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ai_provider' => $chosen[0],
            'ai_model' => $chosen[1],
        ];
        $storage = new \WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage();
        $retry_key = 'aipkit_setup_form_' . get_current_user_id() . '_' . $template;
        $form = (int) get_transient($retry_key);
        if (!$form || get_post_type($form) !== 'aipkit_ai_form' || !current_user_can('edit_post', $form)) {
            $form = $storage->create_form($blueprint['title'], $settings);
        } elseif (!$storage->save_form_settings($form, $settings)) {
            return new \WP_Error('form_save_failed', __('The form could not be updated. Please try again.', 'gpt3-ai-content-generator'));
        }
        if (is_wp_error($form)) { return $form; }
        $page = wp_insert_post(['post_title' => $blueprint['title'], 'post_content' => '<!-- wp:shortcode -->[aipkit_ai_form id=' . (int) $form . ']<!-- /wp:shortcode -->', 'post_status' => 'draft', 'post_type' => 'page'], true);
        if (is_wp_error($page)) {
            set_transient($retry_key, (int) $form, HOUR_IN_SECONDS);
            return new \WP_Error('preview_page_failed', __('The form was saved, but its preview page could not be created. Try again to create the page.', 'gpt3-ai-content-generator'));
        }
        delete_transient($retry_key);
        return [
            /* translators: %s: form name. */
            'message' => sprintf(__('“%s” is ready. We put it on a draft page so you can try it.', 'gpt3-ai-content-generator'), $blueprint['title']),
            'primary' => get_preview_post_link($page),
            'primaryLabel' => __('Try the form', 'gpt3-ai-content-generator'),
            'secondary' => admin_url('admin.php?page=wpaicg&aipkit_module=ai-forms'),
            'secondaryLabel' => __('Edit in AI Forms', 'gpt3-ai-content-generator'),
        ];
    }

    /** Saves a task through the same builders and scheduler the Automations screen uses. */
    private static function save_task(string $name, string $type, array $fields)
    {
        if (!function_exists('\\WPAICG\\AutoGPT\\Ajax\\Actions\\SaveTask\\save_task_to_database_logic')) {
            return new \WP_Error('automations_unavailable', __('Automations is not available. Turn it on in Settings.', 'gpt3-ai-content-generator'));
        }
        $fields['task_type'] = $type;
        $fields['task_status'] = 'active';
        $config = \WPAICG\AutoGPT\Ajax\Actions\SaveTask\build_task_config_writing_logic($fields);
        if (is_wp_error($config)) { return $config; }
        $config['task_type'] = $type;
        $task = \WPAICG\AutoGPT\Ajax\Actions\SaveTask\save_task_to_database_logic($name, $type, $config, 'active', 0);
        if (is_wp_error($task)) { return $task; }
        \WPAICG\AutoGPT\Ajax\Actions\SaveTask\finalize_task_save_logic((int) $task, $config, 'active', true);
        return (int) $task;
    }

    /**
     * Writes a post for each topic. Default: drafts to review. With "publish", posts go live one a week,
     * starting tomorrow at 9:00 site time (the Content Writer's smart schedule).
     */
    private static function create_blog_task(array $chosen)
    {
        $topics = isset($_POST['topics']) && is_string($_POST['topics']) ? sanitize_textarea_field(wp_unslash($_POST['topics'])) : '';
        $topics = implode("\n", array_slice(array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $topics)))), 0, 20));
        if ($topics === '') { return new \WP_Error('missing_topics', __('Add at least one topic.', 'gpt3-ai-content-generator')); }
        $publish = self::post('publish') === 'yes';
        $base = function_exists('\\WPAICG\\ContentWriter\\TemplateManagerMethods\\get_cw_base_template_config')
            ? \WPAICG\ContentWriter\TemplateManagerMethods\get_cw_base_template_config(get_current_user_id()) : [];
        $start = (new \DateTimeImmutable('tomorrow 09:00', wp_timezone()))->format('Y-m-d H:i');
        $task = self::save_task(__('Blog posts from my topics', 'gpt3-ai-content-generator'), 'content_writing_bulk', array_merge($base, [
            'ai_provider' => $chosen[0],
            'ai_model' => $chosen[1],
            'content_title_bulk' => $topics,
            'post_status' => $publish ? 'publish' : 'draft',
            'schedule_mode' => $publish ? 'smart' : 'immediate',
            'smart_schedule_start_datetime' => $publish ? $start : '',
            'smart_schedule_interval_value' => '7',
            'smart_schedule_interval_unit' => 'days',
            'task_frequency' => 'one-time',
        ]));
        if (is_wp_error($task)) { return $task; }
        $count = count(explode("\n", $topics));
        return [
            'message' => $publish
                /* translators: %d: number of topics. */
                ? sprintf(_n('Writing %d post now. It will be published tomorrow at 9:00.', 'Writing %d posts now. The first is published tomorrow at 9:00, then one a week.', $count, 'gpt3-ai-content-generator'), $count)
                /* translators: %d: number of topics. */
                : sprintf(_n('Writing %d post now. It will be saved as a draft for you to review.', 'Writing %d posts now. They will be saved as drafts for you to review.', $count, 'gpt3-ai-content-generator'), $count),
            'primary' => admin_url('admin.php?page=wpaicg&aipkit_module=autogpt'),
            'primaryLabel' => __('Watch progress', 'gpt3-ai-content-generator'),
            'secondary' => admin_url('edit.php?post_status=' . ($publish ? 'future' : 'draft')),
            'secondaryLabel' => $publish ? __('Scheduled posts', 'gpt3-ai-content-generator') : __('Drafts', 'gpt3-ai-content-generator'),
        ];
    }

    /**
     * The opt-in that replaced Freemius's activation screen, for sites not using Cloud: registers the site
     * for update emails with the current admin's name and email. Usage tracking stays off, as with Cloud.
     */
    private static function op_updates(): void
    {
        if (!function_exists('wpaicg_gacg_fs')) { wp_send_json_error(['message' => __('Updates sign-up is not available.', 'gpt3-ai-content-generator')], 400); }
        $fs = wpaicg_gacg_fs();
        if (!$fs->is_registered()) {
            try {
                \WPAICG\Cloud\Connection::disable_optional_registration_tracking();
                $fs->opt_in(false, false, false, false, false, false, true, true, [], false);
            }
            catch (\Throwable $error) { wp_send_json_error(['message' => __('Signing up for updates failed. You can do it later from the AI Puffer menu.', 'gpt3-ai-content-generator')], 400); }
        }
        self::save(['updates' => true]);
        wp_send_json_success(['registered' => (bool) $fs->is_registered(), 'confirm_email' => !$fs->is_registered() && (bool) $fs->is_pending_activation()]);
    }

    /** Leave setup: done (after the steps) or skipped. Lands in the first chosen tool. */
    private static function op_finish(string $status): void
    {
        $state = self::state();
        self::save(['status' => $status, 'finished' => time()]);
        $first = $state['goals'][0] ?? 'explore';
        $module = self::GOALS[$first]['module'] ?? '';
        wp_send_json_success(['url' => $module ? admin_url('admin.php?page=wpaicg&aipkit_module=' . $module) : admin_url('admin.php?page=wpaicg')]);
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
}
