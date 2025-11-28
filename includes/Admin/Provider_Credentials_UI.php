<?php
/**
 * Enhances the AI credentials settings screen.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin;

use WordPress\AI\Asset_Loader;

use function add_action;
use function wp_localize_script;

/**
 * Adds icons and tooltips to the credentials UI without modifying the upstream package.
 */
class Provider_Credentials_UI {
	private const SCREEN_HOOK = 'settings_page_wp-ai-client';

	/**
	 * Bootstraps the enhancements.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues inline styles/scripts for the credentials table.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( self::SCREEN_HOOK !== $hook ) {
			return;
		}

		Asset_Loader::enqueue_style(
			'provider_credentials',
			'admin/style-provider-credentials'
		);

		Asset_Loader::enqueue_script(
			'provider_credentials',
			'admin/provider-credentials'
		);

		wp_localize_script(
			'ai_provider_credentials',
			'aiProviderCredentialsConfig',
			array(
				'providers' => Provider_Metadata_Registry::get_metadata(),
			)
		);
	}
}
