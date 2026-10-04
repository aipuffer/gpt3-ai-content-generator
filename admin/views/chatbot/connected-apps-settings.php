<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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
		<div class="aipkit_chatbot_connected_apps_upsell">
			<p class="aipkit_chatbot_connected_apps_intro_text">
				<?php esc_html_e( 'Send chatbot sessions, questions, responses, and feedback to Slack, HubSpot, Notion, Pipedrive, Make, and n8n.', 'gpt3-ai-content-generator' ); ?>
			</p>
			<div class="aipkit_chatbot_connected_apps_logo_grid" aria-label="<?php esc_attr_e( 'Supported app destinations', 'gpt3-ai-content-generator' ); ?>">
				<?php foreach ( $connected_apps_supported_destinations as $connected_app_destination ) : ?>
					<span
						class="aipkit_chatbot_connected_apps_logo_item"
						title="<?php echo esc_attr( (string) $connected_app_destination['name'] ); ?>"
					>
						<img
							class="aipkit_chatbot_connected_apps_logo"
							src="<?php echo esc_url( (string) $connected_app_destination['logo_url'] ); ?>"
							alt="<?php echo esc_attr( (string) $connected_app_destination['name'] ); ?>"
							loading="lazy"
							decoding="async"
						/>
					</span>
				<?php endforeach; ?>
				<a
					href="<?php echo esc_url( $pricing_url ); ?>"
					class="aipkit_btn aipkit_btn-primary aipkit_chatbot_connected_apps_grid_cta aipkit_pro_upgrade_button"
					target="_blank"
					rel="noopener noreferrer"
				>
					<span class="aipkit_btn-text"><?php esc_html_e( 'Upgrade', 'gpt3-ai-content-generator' ); ?></span>
				</a>
			</div>
		</div>
	<?php endif; ?>
</div>
