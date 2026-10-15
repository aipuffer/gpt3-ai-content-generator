<?php

/** Elementor documents and shared styles, saved through Elementor's document API. */

namespace WPAICG\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

final class Elementor
{
    private const PREFIX = 'ai-puffer/elementor-';
    private const MAX_BYTES = 200000;
    private const FIELDS = [
        'heading' => ['title', 'link', 'global_color'],
        'text-editor' => ['editor', 'global_color'],
        'button' => ['text', 'link', 'global_color'],
        'image' => ['image', 'caption', 'link'],
    ];
    /** Locks shared with native Elementor saves; no server or background polling. */
    private static $locks = [];
    private static $writing = false;

    public static function available(): bool
    {
        return defined('ELEMENTOR_VERSION') && class_exists('Elementor\\Plugin')
            && isset(\Elementor\Plugin::$instance->documents);
    }

    public static function register_hooks(): void
    {
        add_filter('elementor/document/save/data', [self::class, 'native_before_save'], PHP_INT_MAX, 2);
        add_action('elementor/document/after_save', [self::class, 'native_after_save'], PHP_INT_MAX);
    }

    public static function register_abilities(): void
    {
        if (!function_exists('wp_register_ability')) { return; }
        if (!self::available()) {
            return;
        }
        $id = ['type' => 'integer', 'minimum' => 1];
        $string = ['type' => 'string'];
        $version = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $definitions = [
            'list-documents' => [true, 'Find Elementor pages or saved library templates. Reads only accessible local documents.', ['kind' => ['type' => 'string','enum' => ['page','template']], 'query' => $string, 'page' => ['type' => 'integer','minimum' => 1]], []],
            'get-document' => [true, 'Read an Elementor page/template before editing. Returns version, stable widget IDs, editable fields and current values. Preserves other widgets/layouts. Use this instead of raw protected metadata.', ['post_id' => $id], ['post_id']],
            'create-document' => [false, 'Create a DRAFT Elementor page or saved library template. Optional source_id copies an accessible existing Elementor document. Otherwise widgets builds a simple container of core heading, text-editor, button or image widgets. Fields: title/editor/text, link URL, image attachment ID, caption, global_color ID. Never publishes.', ['title' => ['type' => 'string','minLength' => 1,'maxLength' => 200], 'kind' => ['type' => 'string','enum' => ['page','template']], 'source_id' => $id, 'widgets' => ['type' => 'array','maxItems' => 30,'items' => ['type' => 'object','properties' => ['widget' => ['type' => 'string','enum' => array_keys(self::FIELDS)],'fields' => ['type' => 'object']],'required' => ['widget','fields'],'additionalProperties' => false]]], ['title']],
            'update-document' => [false, 'Edit an existing Elementor document using its fresh get-document version. One call changes up to 20 fields on ONE page for one action. changes: element_id, field, value. Supported Heading title/link, Text Editor editor HTML, Button text/link, Image image attachment ID/caption/link, global_color binding. Keeps layout, other fields and publication status. Returns undo evidence.', ['post_id' => $id,'version' => $version,'changes' => ['type' => 'array','minItems' => 1,'maxItems' => 20,'items' => ['type' => 'object','properties' => ['element_id' => $string,'field' => $string,'value' => ['type' => ['string','integer']]],'required' => ['element_id','field','value'],'additionalProperties' => false]]], ['post_id','version','changes']],
            'undo-document' => [false, 'Undo a successful Elementor update from its operation_id. Supply its returned version. Restores only that operation’s fields; refuses if the document changed later. Same user permissions and approval policy apply.', ['operation_id' => ['type' => 'string','pattern' => '^[a-f0-9-]{36}$'],'version' => $version], ['operation_id','version']],
            'get-global-styles' => [true, 'Read Elementor active-kit global colors and typography IDs, values and version. These styles may affect multiple pages; not theme files or executable code.', [], []],
            'update-global-style' => [false, 'Change ONE existing active-kit global color or typography value. Use fresh get-global-styles version, style_id, field (color/font_family/font_weight), and value. Color uses #RGB/#RRGGBB/#RRGGBBAA. Affects every widget referencing this style. One kit-document save, one action. Does not change templates, code or other kit settings.', ['version' => $version,'style_id' => $string,'field' => ['type' => 'string','enum' => ['color','font_family','font_weight']],'value' => ['type' => 'string','maxLength' => 100]], ['version','style_id','field','value']],
        ];
        foreach ($definitions as $name => [$read, $description, $properties, $required]) {
            $schema = ['type' => 'object','additionalProperties' => false];
            if ($properties) {
                $schema['properties'] = $properties;
            }
            if ($required) {
                $schema['required'] = $required;
            }
            wp_register_ability(self::PREFIX . $name, [
                'label' => 'Elementor: ' . str_replace('-', ' ', $name), 'description' => $description,
                'category' => Abilities::CATEGORY, 'input_schema' => $schema,
                'meta' => ['public' => true,'annotations' => ['readonly' => $read,'destructive' => false]],
                'permission_callback' => static function ($input = []) use ($name): bool {
                    return self::can($name, (array) $input);
                },
                'execute_callback' => static function ($input = []) use ($name, $read) {
                    if ($read) {
                        return self::read($name, (array) $input);
                    }
                    $result = self::run(self::PREFIX . $name, (array) $input);
                    return $result['state'] === 'succeeded' ? json_decode($result['result']['data_json'], true)
                        : new \WP_Error($result['error']['code'], $result['error']['message']);
                },
            ]);
        }
    }

    public static function handles(string $name): bool
    {
        return self::available() && in_array($name, [self::PREFIX . 'create-document',self::PREFIX . 'update-document',self::PREFIX . 'undo-document',self::PREFIX . 'update-global-style'], true);
    }

    private static function can(string $name, array $input): bool
    {
        if (!self::available() || !current_user_can('edit_posts')) {
            return false;
        }
        if (strpos($name, 'global-style') !== false) {
            return current_user_can('edit_theme_options');
        }
        if ($name === 'create-document') {
            return current_user_can(($input['kind'] ?? 'page') === 'template' ? 'edit_posts' : 'edit_pages')
                && \Elementor\User::is_current_user_can_edit_post_type(($input['kind'] ?? 'page') === 'template' ? 'elementor_library' : 'page');
        }
        return !isset($input['post_id']) || current_user_can('edit_post', (int) $input['post_id']);
    }

    private static function fail(string $code, string $message): array
    {
        return ['state' => Operations::$mutation_started ? 'unknown' : 'failed','actions_used' => 0,
            'error' => ['code' => $code,'message' => $message]];
    }

    private static function deny(string $message): void
    {
        throw new \RuntimeException(esc_html($message));
    }
    private static function encode($value): string
    {
        return (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Raw stored state, not a cached/autosaved editor view. */
    private static function state(int $id, bool $kit = false): array
    {
        clean_post_cache($id);
        $post = get_post($id);
        if (
            !$post || !current_user_can('edit_post', $id) || !in_array($post->post_status, ['draft','pending','publish','private','future'], true)
            || (!$kit && !in_array($post->post_type, ['post','page','elementor_library'], true))
        ) {
            self::deny('You cannot edit this Elementor document.');
        }
        $document = \Elementor\Plugin::$instance->documents->get($id, false);
        if (!$document || !$document->is_editable_by_current_user() || (!$kit && !$document->is_built_with_elementor())) {
            self::deny('This document is not editable with Elementor.');
        }
        if (!$kit && $post->post_type === 'elementor_library' && !in_array($document->get_name(), ['page','section','container'], true)) {
            self::deny('Use a local saved template; Theme Builder and shared/global widgets are separate workflows.');
        }
        if (in_array($post->post_status, ['publish','private','future'], true) && !current_user_can($post->post_type === 'page' ? 'publish_pages' : 'publish_posts')) {
            self::deny('Publishing permission is required to change this live document.');
        }
        $raw = get_post_meta($id, '_elementor_data', true);
        $data = $raw === '' ? [] : json_decode((string) $raw, true);
        $settings = get_post_meta($id, '_elementor_page_settings', true);
        $settings = is_array($settings) ? $settings : [];
        if (!is_array($data) || strlen(self::encode([$data,$settings])) > self::MAX_BYTES) {
            self::deny('This Elementor document is malformed or exceeds the supported size.');
        }
        $version = hash('sha256', self::encode([$id,ELEMENTOR_VERSION,$post->post_status,$post->post_title,$post->post_modified_gmt,$data,$settings,get_post_meta($id, '_wp_page_template', true)]));
        return ['id' => $id,'post' => $post,'document' => $document,'elements' => $data,'settings' => $settings,'version' => $version];
    }

    private static function kit(): array
    {
        if (!current_user_can('edit_theme_options')) {
            self::deny('You cannot change shared Elementor styles.');
        }
        return self::state((int) \Elementor\Plugin::$instance->kits_manager->get_active_id(), true);
    }

    /** Bounded tree walk; duplicate IDs must never select a different matching widget. */
    private static function nodes(array &$elements, callable $visit, array &$seen = [], int $depth = 0): void
    {
        if ($depth > 30) {
            self::deny('This Elementor tree is too deep.');
        }
        foreach ($elements as &$node) {
            if (!is_array($node) || !is_string($node['id'] ?? null) || isset($seen[$node['id']]) || count($seen) >= 1000) {
                self::deny('This Elementor tree has invalid, duplicate or too many element IDs.');
            }
            $seen[$node['id']] = true;
            $visit($node);
            if (isset($node['elements'])) {
                if (!is_array($node['elements'])) {
                    self::deny('Invalid child elements.');
                }
                self::nodes($node['elements'], $visit, $seen, $depth + 1);
            }
        }
    }

    public static function read(string $name, array $input)
    {
        try {
            if (!self::can($name, $input)) {
                self::deny('You cannot read these Elementor documents.');
            }
            if ($name === 'list-documents') {
                $type = ($input['kind'] ?? 'page') === 'template' ? 'elementor_library' : 'page';
                $posts = get_posts(['post_type' => $type,'post_status' => ['draft','pending','publish','private','future'],'s' => $input['query'] ?? '',
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded paged native Elementor document discovery; this plugin-owned metadata is its supported lookup.
                    'posts_per_page' => 20,'paged' => $input['page'] ?? 1,'meta_key' => '_elementor_edit_mode','meta_value' => 'builder']);
                $items = [];
                foreach ($posts as $post) {
                    if (current_user_can('edit_post', $post->ID)) {
                        $items[] = ['post_id' => $post->ID,'title' => $post->post_title,'status' => $post->post_status];
                    }
                }
                return ['items' => $items,'page' => $input['page'] ?? 1,'has_more' => count($posts) === 20];
            }
            if ($name === 'get-global-styles') {
                $state = self::kit();
                $out = [];
                foreach (['system_colors','custom_colors','system_typography','custom_typography'] as $key) {
                    $out[$key] = $state['document']->get_settings($key) ?: [];
                }
                return ['post_id' => $state['id'],'version' => $state['version'],'styles' => $out,'scope' => 'Every widget using these shared styles.'];
            }
            $state = self::state((int) ($input['post_id'] ?? 0));
            $widgets = [];
            $elements = $state['elements'];
            self::nodes($elements, static function ($node) use (&$widgets): void {
                $type = $node['widgetType'] ?? $node['elType'] ?? '';
                $fields = self::FIELDS[$type] ?? [];
                $settings = $node['settings'] ?? [];
                $values = [];
                foreach ($fields as $field) {
                    $values[$field] = self::field_value($settings, $type, $field);
                }
                $widgets[] = ['element_id' => $node['id'],'type' => $type,'editable_fields' => $fields,'values' => $values,'children' => array_column($node['elements'] ?? [], 'id')];
            });
            return ['post_id' => $state['id'],'title' => $state['post']->post_title,'status' => $state['post']->post_status,'version' => $state['version'],
                'elementor_version' => ELEMENTOR_VERSION,'widgets' => $widgets,'edit_url' => admin_url('post.php?post=' . $state['id'] . '&action=elementor')];
        } catch (\Throwable $error) {
            return new \WP_Error('elementor_unavailable', $error->getMessage());
        }
    }

    private static function field_value(array $settings, string $type, string $field)
    {
        if ($field === 'image') {
            return (int) ($settings['image']['id'] ?? 0);
        }
        if ($field === 'global_color') {
            return $settings['__globals__'][self::color_field($type)] ?? null;
        }
        return $settings[$field] ?? null;
    }
    private static function color_field(string $type): string
    {
        return ['heading' => 'title_color','text-editor' => 'text_color','button' => 'button_text_color'][$type];
    }

    /** Typed field edits never accept metadata paths or document-wide replacement JSON. */
    private static function apply_field(array &$settings, string $type, string $field, $value): array
    {
        if (!in_array($field, self::FIELDS[$type] ?? [], true)) {
            self::deny('This widget field is not supported.');
        }
        $keys = [$field];
        if ($field === 'caption') {
            $keys[] = 'caption_source';
        }
        if ($field === 'link' && $type === 'image') {
            $keys[] = 'link_to';
        }
        if ($field === 'global_color') {
            $keys = ['__globals__'];
        }
        foreach ($keys as $key) {
            if (!empty($settings['__dynamic__'][$key])) {
                self::deny('This field uses a dynamic source. Edit its source instead of detaching it.');
            }
        }
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = ['exists' => array_key_exists($key, $settings),'value' => $settings[$key] ?? null];
        }
        if ($field === 'image') {
            if (!is_int($value) || $value < 1 || !wp_attachment_is_image($value) || !current_user_can('read_post', $value)) {
                self::deny('Choose an accessible existing image attachment.');
            }
            $settings['image'] = array_merge($settings['image'] ?? [], ['id' => $value,'url' => wp_get_attachment_url($value)]);
        } elseif ($field === 'link') {
            if (!is_string($value) || !self::url_allowed($value)) {
                self::deny('Use an http, https, mailto, tel, relative or fragment URL.');
            }
            $settings['link'] = array_merge(is_array($settings['link'] ?? null) ? $settings['link'] : [], ['url' => $value]);
            if ($type === 'image') {
                $settings['link_to'] = $value === '' ? 'none' : 'custom';
            }
        } elseif ($field === 'global_color') {
            if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', $value)) {
                self::deny('Choose a global color ID from get-global-styles.');
            }
            $kit = self::kit();
            $colors = array_merge($kit['document']->get_settings('system_colors') ?: [], $kit['document']->get_settings('custom_colors') ?: []);
            if (!in_array($value, array_column($colors, '_id'), true)) {
                self::deny('This global color does not exist.');
            }
            $settings['__globals__'][self::color_field($type)] = 'globals/colors?id=' . $value;
        } else {
            if (!is_string($value) || strlen($value) > 12000 || strpos($value, '<!--') !== false || preg_match('/\[[\w-]+\b/u', $value) || wp_kses_post($value) !== $value) {
                self::deny('Use plain text or supported sanitized HTML, without code or shortcodes.');
            }
            $settings[$field] = $value;
            if ($field === 'caption') {
                $settings['caption_source'] = 'custom';
            }
        }
        return $before;
    }

    private static function url_allowed(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (preg_match('/[\x00-\x20\\\\]/', $url) || strpos($url, '//') === 0) {
            return false;
        }
        return preg_match('#^(https?://|mailto:|tel:|/(?!/)|\#)#i', $url) === 1 && esc_url_raw($url, ['http','https','mailto','tel']) !== '';
    }

    /** Plan all changes and check conflicts before the first mutation. */
    private static function prepare(string $full_name, array $input): array
    {
        $name = substr($full_name, strlen(self::PREFIX));
        if (!self::can($name, $input)) {
            self::deny('Your WordPress user cannot make this Elementor change.');
        }
        if ($name === 'create-document') {
            $elements = [];
            if (isset($input['source_id'])) {
                $source = self::state((int)$input['source_id']);
                $elements = $source['elements'];
                $seen = [];
                self::nodes($elements, static function ($node): void {
                    if (!\Elementor\Plugin::$instance->elements_manager->create_element_instance($node)) {
                        self::deny('A source widget is unavailable. Restore its add-on before copying this template.');
                    }
                }, $seen);
            } else {
                $children = [];
                foreach ($input['widgets'] ?? [] as $item) {
                    $settings = [];
                    foreach ($item['fields'] as $field => $value) {
                        self::apply_field($settings, $item['widget'], (string)$field, $value);
                    }
                    $children[] = ['id' => substr(bin2hex(random_bytes(8)), 0, 7),'elType' => 'widget','widgetType' => $item['widget'],'settings' => $settings,'elements' => []];
                }
                if ($children) {
                    $elements = [['id' => substr(bin2hex(random_bytes(8)), 0, 7),'elType' => 'container','settings' => ['flex_direction' => 'column'],'elements' => $children]];
                }
            }
            return ['name' => $name,'input' => $input,'elements' => $elements];
        }
        if ($name === 'undo-document') {
            $row = Journal::get($input['operation_id']);
            $args = json_decode($row['args'] ?? '{}', true);
            $reply = json_decode($row['response'] ?? '{}', true);
            $data = json_decode($reply['result']['data_json'] ?? '{}', true);
            if (
                !$row || $row['state'] !== 'succeeded' || $row['instance_id'] !== (Identity::registration()['instance_id'] ?? '')
                || ((int)$row['acting_user'] !== get_current_user_id() && !current_user_can('manage_options'))
                || ($args['name'] ?? '') !== self::PREFIX . 'update-document' || !is_array($data['undo'] ?? null)
            ) {
                self::deny('Choose a successful Elementor page update from this site.');
            }
            $input['post_id'] = (int)$data['post_id'];
            $state = self::state($input['post_id']);
            if ($input['version'] !== $data['version']) {
                self::deny('Use the original update’s saved version for undo.');
            }
            $elements = $state['elements'];
            $undo = $data['undo'];
            $seen = [];
            self::nodes($elements, static function (&$node) use ($undo): void {
                if (isset($undo[$node['id']])) {
                    foreach ($undo[$node['id']] as $key => $value) {
                        if ($value['exists']) {
                            $node['settings'][$key] = $value['value'];
                        } else {
                            unset($node['settings'][$key]);
                        }
                    }
                }
            }, $seen);
            $plan = ['name' => $name,'state' => $state,'elements' => $elements,'undo' => [],'input' => $input];
        } elseif ($name === 'update-global-style') {
            $state = self::kit();
            $settings = $state['settings'];
            $color = $input['field'] === 'color';
            if ($color && !preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $input['value'])) {
                self::deny('Use a hexadecimal CSS color.');
            }
            if (!$color && ($input['field'] === 'font_weight' ? !preg_match('/^(?:[1-9]00|normal|bold)$/D', $input['value']) : !preg_match('/^[\pL\pN ,_-]{1,100}$/uD', $input['value']))) {
                self::deny('Use a valid font family or weight.');
            }
            $collections = $color ? ['system_colors','custom_colors'] : ['system_typography','custom_typography'];
            $found = 0;
            $changed = [];
            foreach ($collections as $key) {
                $items = $state['document']->get_settings($key) ?: [];
                foreach ($items as &$item) {
                    if (($item['_id'] ?? '') === $input['style_id']) {
                        $item[$color ? 'color' : 'typography_' . $input['field']] = $input['value'];
                        $found++;
                    }
                }unset($item);
                $changed[$key] = $items;
            }
            if ($found !== 1) {
                self::deny('Choose one existing unique global style ID.');
            }
            $plan = ['name' => $name,'state' => $state,'settings' => array_merge($settings, $changed),'input' => $input];
        } else {
            $state = self::state((int)$input['post_id']);
            $elements = $state['elements'];
            $undo = [];
            $changes = [];
            foreach ($input['changes'] as $change) {
                $key = $change['element_id'] . ':' . $change['field'];
                if (isset($changes[$key])) {
                    self::deny('Send each widget field only once.');
                }$changes[$key] = $change;
            }
            $matched = [];
            $seen = [];
            self::nodes($elements, static function (&$node) use (&$undo, &$matched, $changes): void {
                foreach ($changes as $key => $change) {
                    if ($node['id'] === $change['element_id']) {
                        $type = $node['widgetType'] ?? '';
                        $node['settings'] = (array)($node['settings'] ?? []);
                        $before = self::apply_field($node['settings'], $type, $change['field'], $change['value']);
                        $undo[$node['id']] = ($undo[$node['id']] ?? []) + $before;
                        $matched[$key] = true;
                    }
                }
            }, $seen);
            if (count($matched) !== count($changes)) {
                self::deny('A selected widget no longer exists. Read the document again.');
            }
            if (strlen(self::encode($undo)) > 50000) {
                self::deny('The recovery evidence is too large for one change. Edit fewer fields at a time.');
            }
            $plan = ['name' => $name,'state' => $state,'elements' => $elements,'undo' => $undo,'input' => $input];
        }
        if (!hash_equals($plan['state']['version'], $input['version'])) {
            self::deny('This document changed. Read it again before editing.');
        }
        self::editable_now($plan['state']);
        return $plan;
    }

    private static function editable_now(array $state): void
    {
        $lock = get_post_meta($state['id'], '_edit_lock', true);
        $parts = is_string($lock) ? explode(':', $lock) : [];
        if ((int)($parts[0] ?? 0) > time() - 150) {
            self::deny('This document is open in an editor. Save and close it before changing it through the assistant.');
        }
        $autosave = $state['document']->get_newer_autosave();
        if ($autosave) {
            self::deny('There is a newer Elementor autosave. Save or discard it in the editor first.');
        }
    }

    public static function preview(string $name, array $input): array
    {
        Operations::$mutation_started = false;
        try {
            $plan = self::prepare($name, $input);
            return ['state' => 'pending_approval','actions_used' => 0,'preview' => ['kind' => 'ability','title' => 'Elementor document change','name' => $name,
                'description' => 'Only the selected document fields change. Shared global styles affect their referencing pages.','risk' => 'publish',
                'input' => self::encode($input),'post_id' => $plan['state']['id'] ?? null]];
        } catch (\Throwable$error) {
            return self::fail('elementor_conflict', $error->getMessage());
        }
    }

    public static function run(string $name, array $input): array
    {
        Operations::$mutation_started = false;
        $id = 0;
        try {
            // Determine the lock target, then repeat the full plan while holding it.
            $plan = self::prepare($name, $input);
            $id = $plan['state']['id'] ?? 0;
            if ($id && !self::acquire($id)) {
                self::deny('This Elementor document is busy. Try again shortly.');
            }
            $plan = self::prepare($name, $input);
            self::$writing = true;
            if ($plan['name'] === 'create-document') {
                Operations::$mutation_started = true;
                $document = \Elementor\Plugin::$instance->documents->create(
                    ($input['kind'] ?? 'page') === 'template' ? 'page' : 'wp-page',
                    ['post_title' => $input['title'],'post_status' => 'draft','post_type' => ($input['kind'] ?? 'page') === 'template' ? 'elementor_library' : 'page','post_author' => get_current_user_id()]
                );
                if (is_wp_error($document) || !$document) {
                    self::deny('Elementor could not create the draft. Check the site before retrying.');
                }
                $id = (int)$document->get_main_id();
                $elements = $plan['elements'];
                $was = $document->is_saving();
                $document->set_is_saving(true);
                try {
                    $normal = $document->get_elements_raw_data($elements);
                } finally {
                    $document->set_is_saving($was);
                }
                if (!$document->save(['elements' => $elements])) {
                    self::deny('Elementor could not confirm saving the new draft.');
                }
                $saved = self::state($id);
                if ($saved['post']->post_status !== 'draft' || self::encode($saved['elements']) !== self::encode($normal)) {
                    self::deny('The saved draft differs from the prepared document. Inspect it before trying again.');
                }
                $result = ['post_id' => $id,'status' => $saved['post']->post_status,'version' => $saved['version'],'edit_url' => admin_url('post.php?post=' . $id . '&action=elementor')];
            } else {
                $state = $plan['state'];
                $document = $state['document'];
                $payload = isset($plan['elements']) ? ['elements' => $plan['elements']] : ['settings' => $plan['settings']];
                if (!current_user_can('unfiltered_html') && \Elementor\Utils::kses_post_deep($payload) !== $payload) {
                    self::deny('Saving with this role would alter other stored HTML. Use the native editor to review it first.');
                }
                $normal = null;
                if (isset($plan['elements'])) {
                    $was = $document->is_saving();
                    $document->set_is_saving(true);
                    try {
                        $normal = $document->get_elements_raw_data($plan['elements']);
                    } finally {
                        $document->set_is_saving($was);
                    }
                }
                if (isset($plan['elements']) && self::encode(json_decode(self::encode($normal), true)) !== self::encode($plan['elements'])) {
                    self::deny('Elementor would convert or remove stored widget data. Open and save it in the native editor first.');
                }
                // Recheck after native normalization/hooks, immediately before persistence.
                if (self::state($id, isset($plan['settings']))['version'] !== $input['version']) {
                    self::deny('This document changed during preparation. Read it again.');
                }
                Operations::$mutation_started = true;
                if (!$document->save($payload)) {
                    self::deny('Elementor did not confirm the save. Check the document before retrying.');
                }
                $saved = self::state($id, isset($plan['settings']));
                if ($saved['post']->post_status !== $state['post']->post_status || (isset($plan['elements']) && self::encode($saved['elements']) !== self::encode($normal))) {
                    self::deny('The native save differed from the prepared document. Review it in WordPress; do not repeat the change.');
                }
                if (isset($plan['settings'])) {
                    foreach (['system_colors','custom_colors','system_typography','custom_typography'] as $key) {
                        if (isset($plan['settings'][$key]) && self::encode($saved['settings'][$key] ?? []) !== self::encode($plan['settings'][$key])) {
                            self::deny('Shared styles could not be confirmed. Review Elementor before retrying.');
                        }
                    }
                }
                $result = ['post_id' => $id,'status' => $saved['post']->post_status,'version' => $saved['version'],'undo' => $plan['undo'] ?? [],
                    'saved' => true,'native_cache_invalidated' => true,'frontend_verification' => 'not_performed','edit_url' => admin_url('post.php?post=' . $id . '&action=elementor')];
            }
            return ['state' => 'succeeded','actions_used' => 1,'result' => ['data_json' => self::encode($result)]];
        } catch (\Throwable$error) {
            return self::fail(Operations::$mutation_started ? 'elementor_change_unconfirmed' : 'elementor_conflict', $error->getMessage());
        } finally {
            self::$writing = false;
            if ($id) {
                self::release($id);
            }
        }
    }

    private static function lock_name(int $id): string
    {
        global $wpdb;
        return 'aipkit_el_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id), 0, 40);
    }
    private static function acquire(int $id): bool
    {
        if (isset(self::$locks[$id])) {
            return true;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared advisory lock coordinates the actual document save; cached results cannot acquire locks.
        $ok = (string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name($id), 5)) === '1';
        if ($ok) {
            if (!self::$locks) {
                register_shutdown_function(static function (): void {
                    foreach (array_keys(self::$locks) as $locked) {
                        self::release((int)$locked);
                    }
                });
            }
            self::$locks[$id] = true;
        }
        return$ok;
    }
    private static function release(int $id): void
    {
        if (!isset(self::$locks[$id])) {
            return;
        }unset(self::$locks[$id]);
        global$wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release this request's prepared advisory lock.
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($id)));
    }
    public static function native_before_save(array $data, $document): array
    {
        if (self::$writing || !Identity::active()) {
            return$data;
        }
        $id = (int)$document->get_main_id();
        $before = get_post_meta($id, '_elementor_data', true);
        if (!self::acquire($id)) {
            self::deny('The assistant is updating this document. Retry the editor save shortly.');
        }
        wp_cache_delete($id, 'post_meta');
        if (get_post_meta($id, '_elementor_data', true) !== $before) {
            self::release($id);
            self::deny('The document changed while waiting to save. Reload it first.');
        }
        return$data;
    }
    public static function native_after_save($document): void
    {
        if (!self::$writing) {
            self::release((int)$document->get_main_id());
        }
    }
}
