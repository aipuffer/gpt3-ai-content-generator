<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Connected apps side panel. Free plans see what the apps do; Pro shows the apps that get this chatbot's
 * chats (from the paid add-on, when present). Apps are set up in Settings, so this panel only links there.
 */

// What a chatbot can send, in plain words.
$connected_app_events = [
	'chatbot.session_started'        => [ 'controls-play', __( 'A chat starts', 'gpt3-ai-content-generator' ) ],
	'chatbot.user_message_submitted' => [ 'format-chat', __( 'A visitor sends a message', 'gpt3-ai-content-generator' ) ],
	'chatbot.response_generated'     => [ 'admin-comments', __( 'The chatbot replies', 'gpt3-ai-content-generator' ) ],
	'chatbot.fb_submitted'           => [ 'thumbs-up', __( 'A visitor rates a reply', 'gpt3-ai-content-generator' ) ],
	'chatbot.form_submitted'         => [ 'feedback', __( 'A visitor fills in the chat form', 'gpt3-ai-content-generator' ) ],
];

// An app's logo; symbol-only logos get their name beside them.
$render_app_mark = static function ( array $app ): void {
	$is_symbol = ! empty( $app['symbol'] );
	?>
	<span class="aipkit_app_mark<?php echo $is_symbol ? ' aipkit_app_mark--symbol' : ''; ?>">
		<img src="<?php echo esc_url( (string) $app['logo_url'] ); ?>" alt="<?php echo $is_symbol ? '' : esc_attr( (string) $app['name'] ); ?>" loading="lazy" decoding="async" />
		<?php if ( $is_symbol ) : ?>
			<span><?php echo esc_html( (string) $app['name'] ); ?></span>
		<?php endif; ?>
	</span>
	<?php
};

$connected_app_ideas = [
	[ 'slack', __( 'Post it in a Slack channel', 'gpt3-ai-content-generator' ), __( 'When a chat starts', 'gpt3-ai-content-generator' ) ],
	[ 'hubspot', __( 'Add them as a HubSpot contact', 'gpt3-ai-content-generator' ), __( 'When a visitor fills in the chat form', 'gpt3-ai-content-generator' ) ],
	[ 'notion', __( 'Save it to a Notion database', 'gpt3-ai-content-generator' ), __( 'When a visitor rates a reply', 'gpt3-ai-content-generator' ) ],
];
$render_app_ideas = static function () use ( $connected_app_ideas, $connected_apps_supported_destinations, $render_app_mark ): void {
	?>
	<div class="aipkit_panel_list">
		<?php foreach ( $connected_app_ideas as [ $app_slug, $idea_title, $idea_when ] ) : ?>
			<div class="aipkit_panel_row">
				<div class="aipkit_panel_row_main">
					<span class="aipkit_app_logo"><?php $render_app_mark( $connected_apps_supported_destinations[ $app_slug ] ); ?></span>
					<span class="aipkit_panel_row_copy">
						<span class="aipkit_panel_row_title"><?php echo esc_html( $idea_title ); ?></span>
						<span class="aipkit_panel_row_meta"><?php echo esc_html( $idea_when ); ?></span>
					</span>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
};
?>
<div class="aipkit_general_apps_section aipkit_settings_panel_body" data-aipkit-settings-panel="connected_apps">
	<?php if ( $is_pro_plan ) : ?>
		<?php
		$connected_apps_view = defined('WPAICG_LIB_DIR') ? WPAICG_LIB_DIR . 'views/chatbot/connected-apps.php' : '';
		if ($connected_apps_view !== '' && is_file($connected_apps_view)) {
			include $connected_apps_view;
		}
		?>
	<?php else : ?>
		<div class="aipkit_apps aipkit_panel_stack">
			<div class="aipkit_panel_pro">
				<span class="aipkit_panel_pro_title">
					<?php esc_html_e( 'Part of AI Puffer Pro', 'gpt3-ai-content-generator' ); ?>
					<span class="aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e( 'Pro', 'gpt3-ai-content-generator' ); ?></span>
				</span>
				<span class="aipkit_panel_pro_text"><?php esc_html_e( 'Send what happens in your chats to the tools your team already uses. No code needed.', 'gpt3-ai-content-generator' ); ?></span>
			</div>
			<section class="aipkit_panel_section" aria-labelledby="aipkit_apps_works_with_title">
				<h4 class="aipkit_panel_section_title" id="aipkit_apps_works_with_title"><?php esc_html_e( 'Works with', 'gpt3-ai-content-generator' ); ?></h4>
				<div class="aipkit_apps_grid">
					<?php foreach ( $connected_apps_supported_destinations as $connected_app_destination ) : ?>
						<span class="aipkit_apps_tile"><?php $render_app_mark( $connected_app_destination ); ?></span>
					<?php endforeach; ?>
					<span class="aipkit_apps_tile aipkit_apps_tile--more"><?php esc_html_e( 'More apps through Zapier, Make or n8n', 'gpt3-ai-content-generator' ); ?></span>
				</div>
			</section>
			<section class="aipkit_panel_section" aria-labelledby="aipkit_apps_sends_title">
				<h4 class="aipkit_panel_section_title" id="aipkit_apps_sends_title"><?php esc_html_e( 'What it can send', 'gpt3-ai-content-generator' ); ?></h4>
				<ul class="aipkit_apps_events">
					<?php foreach ( $connected_app_events as [ $event_icon, $event_label ] ) : ?>
						<li><span class="dashicons dashicons-<?php echo esc_attr( $event_icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $event_label ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
			<section class="aipkit_panel_section" aria-labelledby="aipkit_apps_examples_title">
				<h4 class="aipkit_panel_section_title" id="aipkit_apps_examples_title"><?php esc_html_e( 'For example', 'gpt3-ai-content-generator' ); ?></h4>
				<?php $render_app_ideas(); ?>
			</section>
		</div>
	<?php endif; ?>
</div>
