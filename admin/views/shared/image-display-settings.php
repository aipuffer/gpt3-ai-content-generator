<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Shared view partial configured by parent templates.

$aipkit_image_display_settings_id_prefix = isset($aipkit_image_display_settings_id_prefix)
    ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $aipkit_image_display_settings_id_prefix)
    : 'aipkit_cw';
if ($aipkit_image_display_settings_id_prefix === '') {
    $aipkit_image_display_settings_id_prefix = 'aipkit_cw';
}

$aipkit_image_display_settings_render_mode = isset($aipkit_image_display_settings_render_mode)
    ? (string) $aipkit_image_display_settings_render_mode
    : 'both';
$aipkit_image_display_settings_render_trigger = in_array($aipkit_image_display_settings_render_mode, ['both', 'trigger'], true);
$aipkit_image_display_settings_render_popover = in_array($aipkit_image_display_settings_render_mode, ['both', 'popover'], true);
$aipkit_image_display_settings_render_inline = $aipkit_image_display_settings_render_mode === 'inline';
$aipkit_image_display_settings_autosave_class = !empty($aipkit_image_display_settings_autosave) ? 'aipkit_autosave_trigger' : '';
$aipkit_image_display_settings_standard_fields = !empty($aipkit_image_display_settings_standard_fields);
$aipkit_image_display_settings_trigger_hidden_attr = !empty($aipkit_image_display_settings_trigger_hidden) ? 'hidden' : '';
$aipkit_image_display_settings_trigger_label = isset($aipkit_image_display_settings_trigger_label)
    ? (string) $aipkit_image_display_settings_trigger_label
    : __('Image settings', 'gpt3-ai-content-generator');
$aipkit_image_display_settings_popover_title = isset($aipkit_image_display_settings_popover_title)
    ? (string) $aipkit_image_display_settings_popover_title
    : __('Image settings', 'gpt3-ai-content-generator');
$aipkit_image_display_settings_labeled_trigger = !empty($aipkit_image_display_settings_labeled_trigger);
$aipkit_image_display_settings_excluded_common_fields = isset($aipkit_image_display_settings_excluded_common_fields)
    && is_array($aipkit_image_display_settings_excluded_common_fields)
    ? array_map('strval', $aipkit_image_display_settings_excluded_common_fields)
    : [];
$aipkit_image_display_settings_placement_extra_class = isset($aipkit_image_display_settings_placement_extra_class)
    ? sanitize_html_class((string) $aipkit_image_display_settings_placement_extra_class)
    : '';
$aipkit_image_display_settings_pixabay_orientation_helper = isset($aipkit_image_display_settings_pixabay_orientation_helper)
    ? (string) $aipkit_image_display_settings_pixabay_orientation_helper
    : __('Horizontal or vertical results.', 'gpt3-ai-content-generator');
$aipkit_image_display_settings_pixabay_type_helper = isset($aipkit_image_display_settings_pixabay_type_helper)
    ? (string) $aipkit_image_display_settings_pixabay_type_helper
    : __('Choose photo, illustration, or vector.', 'gpt3-ai-content-generator');
$aipkit_image_display_settings_pixabay_category_helper = isset($aipkit_image_display_settings_pixabay_category_helper)
    ? (string) $aipkit_image_display_settings_pixabay_category_helper
    : __('Narrow results to a topic.', 'gpt3-ai-content-generator');

$aipkit_image_display_settings_id = static function (string $suffix) use ($aipkit_image_display_settings_id_prefix): string {
    return $aipkit_image_display_settings_id_prefix . '_' . $suffix;
};

$aipkit_image_display_settings_attr = static function (array $attrs): string {
    $html = '';
foreach ($attrs as $name => $value) {
        if ($value === false || $value === null) {
            continue;
        }
        $html .= $value === true
            ? ' ' . esc_attr((string) $name)
            : ' ' . esc_attr((string) $name) . '="' . esc_attr((string) $value) . '"';
    }
    return $html;
};

$aipkit_image_display_settings_classes = static function (array $classes): string {
    $classes = array_values(array_filter(array_map('trim', $classes)));
    return implode(' ', $classes);
};

$aipkit_image_display_settings_option = static function (string $value, string $label, array $attrs = []): array {
    return [
        'value' => $value,
        'label' => $label,
        'attrs' => $attrs,
    ];
};

$aipkit_image_display_settings_default_option = [
    $aipkit_image_display_settings_option('', __('Default', 'gpt3-ai-content-generator'), ['selected' => true]),
];

$aipkit_image_display_settings_common_options = [
    'canvas' => [
        $aipkit_image_display_settings_option('1024x1024', __('Square', 'gpt3-ai-content-generator'), ['selected' => true]),
        $aipkit_image_display_settings_option('1536x1024', __('Landscape', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('1024x1536', __('Portrait', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
    ],
    'canvas_default' => array_merge(
        $aipkit_image_display_settings_default_option,
        [
            $aipkit_image_display_settings_option('1024x1024', __('Square', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('1536x1024', __('Landscape', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('1024x1536', __('Portrait', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
        ]
    ),
    'quality' => array_merge(
        $aipkit_image_display_settings_default_option,
        [
            $aipkit_image_display_settings_option('low', __('Low', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('medium', __('Medium', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('high', __('High', 'gpt3-ai-content-generator')),
        ]
    ),
    'compression' => array_merge(
        $aipkit_image_display_settings_default_option,
        [
            $aipkit_image_display_settings_option('25', '25%'),
            $aipkit_image_display_settings_option('50', '50%'),
            $aipkit_image_display_settings_option('75', '75%'),
        ]
    ),
    'image_size' => array_merge(
        $aipkit_image_display_settings_default_option,
        [
            $aipkit_image_display_settings_option('1k', '1K'),
            $aipkit_image_display_settings_option('2k', '2K'),
            $aipkit_image_display_settings_option('4k', '4K'),
        ]
    ),
    'aspect_ratio_full' => array_merge(
        $aipkit_image_display_settings_default_option,
        [
            $aipkit_image_display_settings_option('1:1', __('Square', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('3:4', __('Portrait 3:4', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('4:3', __('Landscape 4:3', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('9:16', __('Vertical 9:16', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('16:9', __('Wide 16:9', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('2:3', '2:3'),
            $aipkit_image_display_settings_option('3:2', '3:2'),
            $aipkit_image_display_settings_option('4:5', '4:5'),
            $aipkit_image_display_settings_option('5:4', '5:4'),
            $aipkit_image_display_settings_option('21:9', '21:9'),
            $aipkit_image_display_settings_option('1:4', '1:4'),
            $aipkit_image_display_settings_option('4:1', '4:1'),
            $aipkit_image_display_settings_option('1:8', '1:8'),
            $aipkit_image_display_settings_option('8:1', '8:1'),
        ]
    ),
];

$aipkit_image_display_settings_row = static function (array $field) use (
    $aipkit_image_display_settings_attr,
    $aipkit_image_display_settings_autosave_class,
    $aipkit_image_display_settings_classes,
    $aipkit_image_display_settings_id,
    $aipkit_image_display_settings_standard_fields
): void {
    $type = $field['type'] ?? 'select';
    $suffix = (string) $field['id'];
    $control_id = $aipkit_image_display_settings_id($suffix);
    if ($aipkit_image_display_settings_standard_fields) {
        $common_classes = [
            'aipkit_form-input',
            $aipkit_image_display_settings_autosave_class,
            'aipkit_image_display_settings_control',
            $field['class'] ?? '',
        ];
    } else {
        $common_classes = $type === 'select'
            ? [
                $aipkit_image_display_settings_autosave_class,
                'aipkit_popover_option_select',
                'aipkit_popover_option_select--fit',
                $field['compact'] ?? true ? 'aipkit_cw_blended_chevron_select' : '',
                $field['class'] ?? '',
            ]
            : [
                'aipkit_form-input',
                $aipkit_image_display_settings_autosave_class,
                'aipkit_popover_option_input',
                $field['compact'] ?? true ? 'aipkit_popover_option_input--compact' : '',
                $field['class'] ?? '',
            ];
    }
    $attrs = [
        'id' => $control_id,
        'name' => $field['name'] ?? $suffix,
        'class' => $aipkit_image_display_settings_classes($common_classes),
    ];
    foreach (($field['attrs'] ?? []) as $name => $value) {
        $attrs[$name] = $value;
    }
    ?>
    <div class="aipkit_popover_option_row"<?php echo $aipkit_image_display_settings_attr($field['row_attrs'] ?? []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute helper escapes names and values. ?>>
        <div class="aipkit_popover_option_main">
            <div class="aipkit_cw_settings_option_text">
                <label class="aipkit_popover_option_label" for="<?php echo esc_attr($control_id); ?>"><?php echo esc_html($field['label']); ?></label>
                <span class="aipkit_popover_option_helper"><?php echo esc_html($field['helper']); ?></span>
            </div>
            <?php if ($type === 'select') : ?>
                <select<?php echo $aipkit_image_display_settings_attr($attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute helper escapes names and values. ?>>
                    <?php foreach (($field['options'] ?? []) as $option) : ?>
                        <option<?php echo $aipkit_image_display_settings_attr(array_merge(['value' => $option['value']], $option['attrs'] ?? [])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute helper escapes names and values. ?>><?php echo esc_html($option['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input<?php echo $aipkit_image_display_settings_attr(array_merge(['type' => $field['input_type'] ?? 'text'], $attrs)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute helper escapes names and values. ?> />
            <?php endif; ?>
        </div>
    </div>
    <?php
};

$aipkit_image_display_settings_provider = static function (string $suffix, array $fields, array $attrs = []) use (
    $aipkit_image_display_settings_attr,
    $aipkit_image_display_settings_id,
    $aipkit_image_display_settings_row
): void {
    $attrs = array_merge(['id' => $aipkit_image_display_settings_id($suffix), 'hidden' => true], $attrs);
    ?>
    <div<?php echo $aipkit_image_display_settings_attr($attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute helper escapes names and values. ?>>
        <?php foreach ($fields as $field) : ?>
            <?php $aipkit_image_display_settings_row($field); ?>
        <?php endforeach; ?>
    </div>
    <?php
};

$aipkit_image_display_settings_select = static function (
    string $id,
    string $label,
    string $helper,
    array $options,
    array $extra = []
): array {
    return array_merge([
        'id' => $id,
        'type' => 'select',
        'label' => $label,
        'helper' => $helper,
        'options' => $options,
    ], $extra);
};

$aipkit_image_display_settings_input = static function (
    string $id,
    string $label,
    string $helper,
    array $extra = []
): array {
    return array_merge([
        'id' => $id,
        'type' => 'input',
        'label' => $label,
        'helper' => $helper,
    ], $extra);
};

$aipkit_image_display_settings_provider_option = static function (string $option): array {
    return ['data-aipkit-image-provider-option' => $option];
};

$aipkit_image_display_settings_pixabay_categories = ['backgrounds', 'fashion', 'nature', 'science', 'education', 'feelings', 'health', 'people', 'religion', 'places', 'animals', 'industry', 'computer', 'food', 'sports', 'transportation', 'travel', 'buildings', 'business', 'music'];
$aipkit_image_display_settings_pixabay_category_options = [
    $aipkit_image_display_settings_option('', __('Any', 'gpt3-ai-content-generator'), ['selected' => true]),
];
foreach ($aipkit_image_display_settings_pixabay_categories as $cat) {
    $aipkit_image_display_settings_pixabay_category_options[] = $aipkit_image_display_settings_option($cat, ucfirst($cat));
}

$aipkit_image_display_settings_fields = [
    $aipkit_image_display_settings_input('image_count', __('Count', 'gpt3-ai-content-generator'), __('How many to insert.', 'gpt3-ai-content-generator'), [
        'name' => 'image_count',
        'input_type' => 'number',
        'attrs' => ['value' => '1', 'min' => '1', 'max' => '10'],
        'row_attrs' => ['id' => $aipkit_image_display_settings_id('image_display_count_field')],
    ]),
    $aipkit_image_display_settings_select('image_placement', __('Placement', 'gpt3-ai-content-generator'), __('Where images appear.', 'gpt3-ai-content-generator'), [
        $aipkit_image_display_settings_option('after_first_h2', __('After 1st H2', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('after_first_h3', __('After 1st H3', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('after_every_x_h2', __('Every X H2s', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('after_every_x_h3', __('Every X H3s', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('after_every_x_p', __('Every X paragraphs', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('at_end', __('End of content', 'gpt3-ai-content-generator')),
    ], [
        'name' => 'image_placement',
        'class' => $aipkit_image_display_settings_placement_extra_class,
        'row_attrs' => ['id' => $aipkit_image_display_settings_id('image_display_placement_field')],
    ]),
    $aipkit_image_display_settings_input('image_placement_param_x', __('X', 'gpt3-ai-content-generator'), __('Used with every-X placements.', 'gpt3-ai-content-generator'), [
        'name' => 'image_placement_param_x',
        'input_type' => 'number',
        'attrs' => ['value' => '2', 'min' => '1'],
        'row_attrs' => ['id' => $aipkit_image_display_settings_id('image_display_param_x_field'), 'hidden' => true],
    ]),
    $aipkit_image_display_settings_select('image_size', __('Display size', 'gpt3-ai-content-generator'), __('WordPress image size in the post.', 'gpt3-ai-content-generator'), [
        $aipkit_image_display_settings_option('large', __('Large', 'gpt3-ai-content-generator'), ['selected' => true]),
        $aipkit_image_display_settings_option('medium', __('Medium', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('thumbnail', __('Thumbnail', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('full', __('Full', 'gpt3-ai-content-generator')),
    ], [
        'name' => 'image_size',
        'row_attrs' => ['id' => $aipkit_image_display_settings_id('image_display_size_field')],
    ]),
    $aipkit_image_display_settings_select('image_alignment', __('Align', 'gpt3-ai-content-generator'), __('Image alignment.', 'gpt3-ai-content-generator'), [
        $aipkit_image_display_settings_option('none', __('None', 'gpt3-ai-content-generator'), ['selected' => true]),
        $aipkit_image_display_settings_option('left', __('Left', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('center', __('Center', 'gpt3-ai-content-generator')),
        $aipkit_image_display_settings_option('right', __('Right', 'gpt3-ai-content-generator')),
    ], [
        'name' => 'image_alignment',
        'row_attrs' => ['id' => $aipkit_image_display_settings_id('image_display_alignment_field')],
    ]),
];

$aipkit_image_display_settings_provider_fields = [
    'openai' => [
        $aipkit_image_display_settings_select('openai_canvas_size', __('Canvas size', 'gpt3-ai-content-generator'), __('Generated image dimensions.', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_common_options['canvas'], ['name' => 'openai_canvas_size', 'attrs' => $aipkit_image_display_settings_provider_option('canvas_size')]),
        $aipkit_image_display_settings_select('openai_quality', __('Quality', 'gpt3-ai-content-generator'), __('Controls generation cost and detail.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('low', __('Low', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('medium', __('Medium', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('high', __('High', 'gpt3-ai-content-generator')),
        ]), ['name' => 'openai_quality', 'attrs' => $aipkit_image_display_settings_provider_option('quality')]),
        $aipkit_image_display_settings_select('openai_output_format', __('Output format', 'gpt3-ai-content-generator'), __('Saved file format for generated images.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('png', 'PNG'),
            $aipkit_image_display_settings_option('jpeg', 'JPEG'),
            $aipkit_image_display_settings_option('webp', 'WebP'),
        ]), ['name' => 'openai_output_format', 'attrs' => $aipkit_image_display_settings_provider_option('output_format')]),
        $aipkit_image_display_settings_select('openai_output_compression', __('Compression', 'gpt3-ai-content-generator'), __('Only used for JPEG or WebP output.', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_common_options['compression'], ['name' => 'openai_output_compression', 'attrs' => $aipkit_image_display_settings_provider_option('output_compression'), 'row_attrs' => ['data-aipkit-openai-compression-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('openai_background', __('Background', 'gpt3-ai-content-generator'), __('Sets image background.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('opaque', __('Opaque', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('transparent', __('Transparent', 'gpt3-ai-content-generator'), ['data-aipkit-openai-transparent-background-option' => true, 'hidden' => true, 'disabled' => true]),
        ]), ['name' => 'openai_background', 'attrs' => $aipkit_image_display_settings_provider_option('background')]),
        $aipkit_image_display_settings_select('openai_moderation', __('Moderation', 'gpt3-ai-content-generator'), __('Prompt and image filtering strictness.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('low', __('Low', 'gpt3-ai-content-generator')),
        ]), ['name' => 'openai_moderation', 'attrs' => $aipkit_image_display_settings_provider_option('moderation')]),
    ],
    'azure' => [
        $aipkit_image_display_settings_select('azure_canvas_size', __('Canvas size', 'gpt3-ai-content-generator'), __('Generated image dimensions.', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_common_options['canvas_default'], ['name' => 'azure_canvas_size', 'attrs' => $aipkit_image_display_settings_provider_option('canvas_size')]),
        $aipkit_image_display_settings_select('azure_quality', __('Quality', 'gpt3-ai-content-generator'), __('Controls generation cost and detail.', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_common_options['quality'], ['name' => 'azure_quality', 'attrs' => $aipkit_image_display_settings_provider_option('quality')]),
        $aipkit_image_display_settings_select('azure_output_format', __('Output format', 'gpt3-ai-content-generator'), __('Saved file format for generated images.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('png', 'PNG'),
            $aipkit_image_display_settings_option('jpeg', 'JPEG'),
        ]), ['name' => 'azure_output_format', 'attrs' => $aipkit_image_display_settings_provider_option('output_format')]),
        $aipkit_image_display_settings_select('azure_output_compression', __('Compression', 'gpt3-ai-content-generator'), __('Only used for JPEG output.', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_common_options['compression'], ['name' => 'azure_output_compression', 'attrs' => $aipkit_image_display_settings_provider_option('output_compression'), 'row_attrs' => ['data-aipkit-azure-compression-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('azure_background', __('Background', 'gpt3-ai-content-generator'), __('Transparent output is saved as PNG.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('transparent', __('Transparent', 'gpt3-ai-content-generator')),
        ]), ['name' => 'azure_background', 'attrs' => $aipkit_image_display_settings_provider_option('background')]),
    ],
    'google' => [
        $aipkit_image_display_settings_select('google_aspect_ratio', __('Aspect ratio', 'gpt3-ai-content-generator'), __('Generated image shape.', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_common_options['aspect_ratio_full'], ['name' => 'google_aspect_ratio', 'attrs' => $aipkit_image_display_settings_provider_option('aspect_ratio'), 'row_attrs' => ['data-aipkit-google-aspect-ratio-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('google_image_size', __('Image size', 'gpt3-ai-content-generator'), __('Provider output resolution.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('512', '512'),
            $aipkit_image_display_settings_option('1k', '1K'),
            $aipkit_image_display_settings_option('2k', '2K'),
            $aipkit_image_display_settings_option('4k', '4K'),
        ]), ['name' => 'google_image_size', 'attrs' => $aipkit_image_display_settings_provider_option('image_size'), 'row_attrs' => ['data-aipkit-google-image-size-row' => true, 'hidden' => true]]),
    ],
    'openrouter' => [
        $aipkit_image_display_settings_select('openrouter_aspect_ratio', __('Aspect ratio', 'gpt3-ai-content-generator'), __('Values are limited to those supported by the selected OpenRouter image model.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('1:1', __('Square', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('1:2', '1:2'),
            $aipkit_image_display_settings_option('2:1', '2:1'),
            $aipkit_image_display_settings_option('2:3', '2:3'),
            $aipkit_image_display_settings_option('3:2', '3:2'),
            $aipkit_image_display_settings_option('3:4', __('Portrait 3:4', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('4:3', __('Landscape 4:3', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('4:5', '4:5'),
            $aipkit_image_display_settings_option('5:4', '5:4'),
            $aipkit_image_display_settings_option('9:16', __('Vertical 9:16', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('16:9', __('Wide 16:9', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('9:19.5', '9:19.5'),
            $aipkit_image_display_settings_option('19.5:9', '19.5:9'),
            $aipkit_image_display_settings_option('9:20', '9:20'),
            $aipkit_image_display_settings_option('20:9', '20:9'),
            $aipkit_image_display_settings_option('9:21', '9:21'),
            $aipkit_image_display_settings_option('21:9', '21:9'),
            $aipkit_image_display_settings_option('1:4', '1:4'),
            $aipkit_image_display_settings_option('4:1', '4:1'),
            $aipkit_image_display_settings_option('1:8', '1:8'),
            $aipkit_image_display_settings_option('8:1', '8:1'),
        ]), ['name' => 'openrouter_aspect_ratio', 'attrs' => $aipkit_image_display_settings_provider_option('aspect_ratio'), 'row_attrs' => ['data-aipkit-openrouter-aspect-ratio-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('openrouter_image_size', __('Resolution', 'gpt3-ai-content-generator'), __('Normalized output tier supported by the selected model.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('512', '512'),
            $aipkit_image_display_settings_option('1k', '1K'),
            $aipkit_image_display_settings_option('2k', '2K'),
            $aipkit_image_display_settings_option('4k', '4K'),
        ]), ['name' => 'openrouter_image_size', 'attrs' => $aipkit_image_display_settings_provider_option('image_size'), 'row_attrs' => ['data-aipkit-openrouter-resolution-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('openrouter_quality', __('Quality', 'gpt3-ai-content-generator'), __('Controls generation cost and detail when supported.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('low', __('Low', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('medium', __('Medium', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('high', __('High', 'gpt3-ai-content-generator')),
        ]), ['name' => 'openrouter_quality', 'attrs' => $aipkit_image_display_settings_provider_option('quality'), 'row_attrs' => ['data-aipkit-openrouter-quality-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('openrouter_output_format', __('Output format', 'gpt3-ai-content-generator'), __('Raster format saved to the WordPress Media Library.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('png', 'PNG'),
            $aipkit_image_display_settings_option('jpeg', 'JPEG'),
            $aipkit_image_display_settings_option('webp', 'WebP'),
        ]), ['name' => 'openrouter_output_format', 'attrs' => $aipkit_image_display_settings_provider_option('output_format'), 'row_attrs' => ['data-aipkit-openrouter-output-format-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('openrouter_output_compression', __('Compression', 'gpt3-ai-content-generator'), __('Only used for JPEG or WebP output.', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_common_options['compression'], ['name' => 'openrouter_output_compression', 'attrs' => $aipkit_image_display_settings_provider_option('output_compression'), 'row_attrs' => ['data-aipkit-openrouter-compression-row' => true, 'hidden' => true]]),
        $aipkit_image_display_settings_select('openrouter_background', __('Background', 'gpt3-ai-content-generator'), __('Transparent output requires PNG or WebP support.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('transparent', __('Transparent', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('opaque', __('Opaque', 'gpt3-ai-content-generator')),
        ]), ['name' => 'openrouter_background', 'attrs' => $aipkit_image_display_settings_provider_option('background'), 'row_attrs' => ['data-aipkit-openrouter-background-row' => true, 'hidden' => true]]),
    ],
    'xai' => [
        $aipkit_image_display_settings_select('xai_aspect_ratio', __('Aspect ratio', 'gpt3-ai-content-generator'), __('Generated image shape.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('auto', __('Auto', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('1:1', __('Square', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('16:9', __('Wide 16:9', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('9:16', __('Vertical 9:16', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('4:3', __('Landscape 4:3', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('3:4', __('Portrait 3:4', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('3:2', '3:2'),
            $aipkit_image_display_settings_option('2:3', '2:3'),
            $aipkit_image_display_settings_option('2:1', '2:1'),
            $aipkit_image_display_settings_option('1:2', '1:2'),
            $aipkit_image_display_settings_option('19.5:9', '19.5:9'),
            $aipkit_image_display_settings_option('9:19.5', '9:19.5'),
            $aipkit_image_display_settings_option('20:9', '20:9'),
            $aipkit_image_display_settings_option('9:20', '9:20'),
        ]), ['name' => 'xai_aspect_ratio', 'attrs' => $aipkit_image_display_settings_provider_option('aspect_ratio')]),
        $aipkit_image_display_settings_select('xai_resolution', __('Resolution', 'gpt3-ai-content-generator'), __('Provider output resolution.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('1k', '1K'),
            $aipkit_image_display_settings_option('2k', '2K'),
        ]), ['name' => 'xai_resolution', 'attrs' => $aipkit_image_display_settings_provider_option('resolution')]),
    ],
    'replicate' => [
        $aipkit_image_display_settings_select('replicate_aspect_ratio', __('Aspect ratio', 'gpt3-ai-content-generator'), __('Generated image shape.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('1:1', __('Square', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('16:9', __('Wide 16:9', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('21:9', '21:9'),
            $aipkit_image_display_settings_option('3:2', '3:2'),
            $aipkit_image_display_settings_option('2:3', '2:3'),
            $aipkit_image_display_settings_option('4:3', __('Landscape 4:3', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('3:4', __('Portrait 3:4', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('4:5', '4:5'),
            $aipkit_image_display_settings_option('5:4', '5:4'),
            $aipkit_image_display_settings_option('9:16', __('Vertical 9:16', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('1:2', '1:2'),
            $aipkit_image_display_settings_option('2:1', '2:1'),
            $aipkit_image_display_settings_option('3:1', '3:1'),
            $aipkit_image_display_settings_option('1:3', '1:3'),
        ]), ['name' => 'replicate_aspect_ratio', 'attrs' => $aipkit_image_display_settings_provider_option('aspect_ratio'), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'aspect_ratio', 'hidden' => true]]),
        $aipkit_image_display_settings_input('replicate_width', __('Width', 'gpt3-ai-content-generator'), __('Provider output width when supported.', 'gpt3-ai-content-generator'), ['name' => 'replicate_width', 'input_type' => 'number', 'attrs' => array_merge(['min' => '64', 'max' => '4096', 'step' => '1'], $aipkit_image_display_settings_provider_option('width')), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'width', 'hidden' => true]]),
        $aipkit_image_display_settings_input('replicate_height', __('Height', 'gpt3-ai-content-generator'), __('Provider output height when supported.', 'gpt3-ai-content-generator'), ['name' => 'replicate_height', 'input_type' => 'number', 'attrs' => array_merge(['min' => '64', 'max' => '4096', 'step' => '1'], $aipkit_image_display_settings_provider_option('height')), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'height', 'hidden' => true]]),
        $aipkit_image_display_settings_input('replicate_negative_prompt', __('Negative prompt', 'gpt3-ai-content-generator'), __('What the model should avoid.', 'gpt3-ai-content-generator'), ['name' => 'replicate_negative_prompt', 'compact' => false, 'attrs' => array_merge(['maxlength' => '1000'], $aipkit_image_display_settings_provider_option('negative_prompt')), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'negative_prompt', 'hidden' => true]]),
        $aipkit_image_display_settings_input('replicate_guidance', __('Guidance', 'gpt3-ai-content-generator'), __('Prompt adherence strength.', 'gpt3-ai-content-generator'), ['name' => 'replicate_guidance', 'input_type' => 'number', 'attrs' => array_merge(['min' => '0', 'max' => '30', 'step' => '0.1'], $aipkit_image_display_settings_provider_option('guidance')), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'guidance', 'hidden' => true]]),
        $aipkit_image_display_settings_input('replicate_num_inference_steps', __('Steps', 'gpt3-ai-content-generator'), __('Inference steps when supported.', 'gpt3-ai-content-generator'), ['name' => 'replicate_num_inference_steps', 'input_type' => 'number', 'attrs' => array_merge(['min' => '1', 'max' => '100', 'step' => '1'], $aipkit_image_display_settings_provider_option('num_inference_steps')), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'num_inference_steps', 'hidden' => true]]),
        $aipkit_image_display_settings_input('replicate_seed', __('Seed', 'gpt3-ai-content-generator'), __('Repeatable generation seed.', 'gpt3-ai-content-generator'), ['name' => 'replicate_seed', 'input_type' => 'number', 'attrs' => array_merge(['min' => '0', 'max' => '2147483647', 'step' => '1'], $aipkit_image_display_settings_provider_option('seed')), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'seed', 'hidden' => true]]),
        $aipkit_image_display_settings_select('replicate_output_format', __('Output format', 'gpt3-ai-content-generator'), __('Saved file format when supported.', 'gpt3-ai-content-generator'), array_merge($aipkit_image_display_settings_default_option, [
            $aipkit_image_display_settings_option('webp', 'WebP'),
            $aipkit_image_display_settings_option('png', 'PNG'),
            $aipkit_image_display_settings_option('jpg', 'JPG'),
            $aipkit_image_display_settings_option('jpeg', 'JPEG'),
        ]), ['name' => 'replicate_output_format', 'attrs' => $aipkit_image_display_settings_provider_option('output_format'), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'output_format', 'hidden' => true]]),
        $aipkit_image_display_settings_input('replicate_output_quality', __('Quality', 'gpt3-ai-content-generator'), __('Output compression quality.', 'gpt3-ai-content-generator'), ['name' => 'replicate_output_quality', 'input_type' => 'number', 'attrs' => array_merge(['min' => '0', 'max' => '100', 'step' => '1'], $aipkit_image_display_settings_provider_option('output_quality')), 'row_attrs' => ['data-aipkit-replicate-option-row' => 'output_quality', 'hidden' => true]]),
    ],
    'pexels' => [
        $aipkit_image_display_settings_select('pexels_orientation', __('Orientation', 'gpt3-ai-content-generator'), __('Landscape, portrait, or square results.', 'gpt3-ai-content-generator'), [
            $aipkit_image_display_settings_option('none', __('Any', 'gpt3-ai-content-generator'), ['selected' => true]),
            $aipkit_image_display_settings_option('landscape', __('Landscape', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('portrait', __('Portrait', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('square', __('Square', 'gpt3-ai-content-generator')),
        ], ['name' => 'pexels_orientation']),
        $aipkit_image_display_settings_select('pexels_size', __('Size', 'gpt3-ai-content-generator'), __('Filter results by image size.', 'gpt3-ai-content-generator'), [
            $aipkit_image_display_settings_option('none', __('Any', 'gpt3-ai-content-generator'), ['selected' => true]),
            $aipkit_image_display_settings_option('large', __('Large', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('medium', __('Medium', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('small', __('Small', 'gpt3-ai-content-generator')),
        ], ['name' => 'pexels_size']),
        $aipkit_image_display_settings_select('pexels_color', __('Color', 'gpt3-ai-content-generator'), __('Filter by dominant color.', 'gpt3-ai-content-generator'), [
            $aipkit_image_display_settings_option('', __('Any', 'gpt3-ai-content-generator'), ['selected' => true]),
            $aipkit_image_display_settings_option('red', __('Red', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('orange', __('Orange', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('yellow', __('Yellow', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('green', __('Green', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('turquoise', __('Turquoise', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('blue', __('Blue', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('violet', __('Violet', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('pink', __('Pink', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('brown', __('Brown', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('black', __('Black', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('gray', __('Gray', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('white', __('White', 'gpt3-ai-content-generator')),
        ], ['name' => 'pexels_color']),
    ],
    'pixabay' => [
        $aipkit_image_display_settings_select('pixabay_orientation', __('Orientation', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_pixabay_orientation_helper, [
            $aipkit_image_display_settings_option('all', __('All', 'gpt3-ai-content-generator'), ['selected' => true]),
            $aipkit_image_display_settings_option('horizontal', __('Horizontal', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('vertical', __('Vertical', 'gpt3-ai-content-generator')),
        ], ['name' => 'pixabay_orientation']),
        $aipkit_image_display_settings_select('pixabay_image_type', __('Type', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_pixabay_type_helper, [
            $aipkit_image_display_settings_option('all', __('All', 'gpt3-ai-content-generator'), ['selected' => true]),
            $aipkit_image_display_settings_option('photo', __('Photo', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('illustration', __('Illustration', 'gpt3-ai-content-generator')),
            $aipkit_image_display_settings_option('vector', __('Vector', 'gpt3-ai-content-generator')),
        ], ['name' => 'pixabay_image_type']),
        $aipkit_image_display_settings_select('pixabay_category', __('Category', 'gpt3-ai-content-generator'), $aipkit_image_display_settings_pixabay_category_helper, $aipkit_image_display_settings_pixabay_category_options, ['name' => 'pixabay_category']),
    ],
];
?>

<?php if ($aipkit_image_display_settings_render_trigger) : ?>
<button
    type="button"
    class="aipkit_cw_settings_icon_trigger<?php echo $aipkit_image_display_settings_labeled_trigger ? ' aipkit_cw_settings_icon_trigger--labeled' : ''; ?>"
    id="<?php echo esc_attr($aipkit_image_display_settings_id('image_display_settings_trigger')); ?>"
    data-aipkit-popover-target="<?php echo esc_attr($aipkit_image_display_settings_id('image_display_settings_popover')); ?>"
    data-aipkit-popover-placement="top"
    aria-controls="<?php echo esc_attr($aipkit_image_display_settings_id('image_display_settings_popover')); ?>"
    aria-expanded="false"
    aria-label="<?php echo esc_attr($aipkit_image_display_settings_trigger_label); ?>"
    title="<?php echo esc_attr($aipkit_image_display_settings_trigger_label); ?>"
    <?php echo esc_attr($aipkit_image_display_settings_trigger_hidden_attr); ?>
>
    <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
    <?php if ($aipkit_image_display_settings_labeled_trigger) : ?>
        <span class="aipkit_cw_settings_trigger_label"><?php echo esc_html($aipkit_image_display_settings_trigger_label); ?></span>
    <?php endif; ?>
</button>
<?php endif; ?>

<?php if ($aipkit_image_display_settings_render_inline) : ?>
<div class="aipkit_image_display_settings_inline" data-aipkit-image-inline-settings>
    <div class="aipkit_popover_options_list">
        <?php foreach ($aipkit_image_display_settings_fields as $field) : ?>
            <?php if (in_array((string) ($field['id'] ?? ''), $aipkit_image_display_settings_excluded_common_fields, true)) continue; ?>
            <?php $aipkit_image_display_settings_row($field); ?>
        <?php endforeach; ?>

        <div id="<?php echo esc_attr($aipkit_image_display_settings_id('image_provider_options_block')); ?>" hidden>
            <?php foreach (['openai', 'azure', 'google', 'openrouter', 'xai', 'replicate'] as $provider) : ?>
                <?php
                $aipkit_image_display_settings_provider(
                    $provider . '_options',
                    $aipkit_image_display_settings_provider_fields[$provider],
                    ['data-aipkit-image-provider-options' => $provider]
                );
                ?>
            <?php endforeach; ?>
            <?php $aipkit_image_display_settings_provider('pexels_options', $aipkit_image_display_settings_provider_fields['pexels']); ?>
            <?php $aipkit_image_display_settings_provider('pixabay_options', $aipkit_image_display_settings_provider_fields['pixabay']); ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($aipkit_image_display_settings_render_popover) : ?>
<div class="aipkit_model_settings_popover aipkit_cw_settings_popover" id="<?php echo esc_attr($aipkit_image_display_settings_id('image_display_settings_popover')); ?>" aria-hidden="true">
    <div class="aipkit_model_settings_popover_panel aipkit_cw_settings_popover_panel aipkit_cw_image_settings_popover_panel" role="dialog" aria-label="<?php echo esc_attr($aipkit_image_display_settings_popover_title); ?>">
        <div class="aipkit_model_settings_popover_header aipkit_cw_settings_sheet_header">
            <span class="aipkit_model_settings_popover_title"><?php echo esc_html($aipkit_image_display_settings_popover_title); ?></span>
        </div>
        <div class="aipkit_model_settings_popover_body aipkit_cw_settings_popover_body aipkit_cw_settings_sheet_body">
            <div class="aipkit_popover_options_list">
                <?php foreach ($aipkit_image_display_settings_fields as $field) : ?>
                    <?php if (in_array((string) ($field['id'] ?? ''), $aipkit_image_display_settings_excluded_common_fields, true)) continue; ?>
                    <?php $aipkit_image_display_settings_row($field); ?>
                <?php endforeach; ?>

                <div id="<?php echo esc_attr($aipkit_image_display_settings_id('image_provider_options_block')); ?>" hidden>
                    <?php foreach (['openai', 'azure', 'google', 'openrouter', 'xai', 'replicate'] as $provider) : ?>
                        <?php
                        $aipkit_image_display_settings_provider(
                            $provider . '_options',
                            $aipkit_image_display_settings_provider_fields[$provider],
                            ['data-aipkit-image-provider-options' => $provider]
                        );
                        ?>
                    <?php endforeach; ?>
                    <?php $aipkit_image_display_settings_provider('pexels_options', $aipkit_image_display_settings_provider_fields['pexels']); ?>
                    <?php $aipkit_image_display_settings_provider('pixabay_options', $aipkit_image_display_settings_provider_fields['pixabay']); ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
