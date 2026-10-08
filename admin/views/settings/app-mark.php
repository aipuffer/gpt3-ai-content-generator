<?php
/**
 * Partial: an app's square mark, the same in the Pro app list and the free Apps row.
 * Returns a callable that prints the mark for an app slug.
 */
if (!defined('ABSPATH')) {
    exit;
}

return static function (string $slug): void {
    // Wordmarks become square marks by framing their symbol; two apps have no symbol in theirs, so they get their letter.
    $letters = ['pipedrive' => ['#017737', 'p', true], 'zapier' => ['#ff4f00', '✱', false]];
    $frames = ['slack' => '0 0 67 67', 'make' => '-11 0 182 182', 'hubspot' => '215 -1 96 96', 'notion' => '', 'n8n' => ''];
    // HubSpot's sprocket overlaps the letter beside it, so only the shapes in the sprocket's colour stay.
    $symbol_colors = ['hubspot' => '#ff5c35'];
    if (isset($letters[$slug])) {
        [$color, $letter, $round] = $letters[$slug];
        echo '<span class="aipkit_settings_app_letter' . ($round ? ' is-round' : '') . '" style="background:' . esc_attr($color) . ';">' . esc_html($letter) . '</span>';
        return;
    }
    $file = dirname(__DIR__, 3) . '/admin/images/apps/' . sanitize_key($slug) . '.svg';
    $svg = is_readable($file) ? (string) file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin asset.
    if ($svg === '') {
        return;
    }
    if (isset($symbol_colors[$slug])) {
        $color = $symbol_colors[$slug];
        $svg = (string) preg_replace_callback('/<(?:path|polygon|polyline|rect|circle|ellipse)\b[^>]*\/>/i', static function (array $shape) use ($color): string {
            return stripos($shape[0], $color) !== false ? $shape[0] : '';
        }, $svg);
    }
    $frame = (string) ($frames[$slug] ?? '');
    $start = strpos($svg, '<svg');
    $end = $start === false ? false : strpos($svg, '>', $start);
    if ($frame !== '' && $start !== false && $end !== false) {
        $root = preg_replace('/\s(width|height)="[^"]*"/', '', substr($svg, $start, $end - $start));
        $root = preg_replace('/viewBox="[^"]*"/', 'viewBox="' . $frame . '"', (string) $root);
        $svg = substr($svg, 0, $start) . $root . substr($svg, $end);
    }
    echo '<img src="' . esc_attr('data:image/svg+xml;base64,' . base64_encode($svg)) . '" alt="" />'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline image data.
};
