<?php
/**
 * Settings page for the AI plugin.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Settings;

use WordPress\AI\Features\Registry;
use function WordPress\AI\get_settings_feature_metadata;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\has_valid_ai_credentials;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the admin settings page for the AI plugin.
 *
 * @since 0.1.0
 */
class Settings_Page {

	/**
	 * The settings page slug.
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ai-wp-admin';

	/**
	 * Initializes the settings page hooks.
	 *
	 * @since 0.7.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 * @return void
	 */
	public static function init( Registry $registry ): void {
		if ( function_exists( 'ai_ai_wp_admin_render_page' ) ) {
			add_action(
				'admin_menu',
				static function () {
					add_options_page(
						__( 'AI', 'ai' ),
						__( 'AI', 'ai' ),
						'manage_options',
						self::PAGE_SLUG,
						'ai_ai_wp_admin_render_page', // @phpstan-ignore argument.type
						2
					);
				}
			);

			// Expose credential status to the settings page script module.
			add_filter(
				'script_module_data_' . self::PAGE_SLUG,
				static function ( array $data ) use ( $registry ): array {
					$feature_metadata            = get_settings_feature_metadata( $registry );
					$data['hasCredentials']      = has_ai_credentials();
					$data['hasValidCredentials'] = has_valid_ai_credentials();
					$data['connectorsUrl']       = admin_url( 'options-connectors.php' );
					$data['featureGroups']       = $feature_metadata['groups'] ?? array();
					$data['features']            = $feature_metadata['features'] ?? array();
					return $data;
				}
			);
		} else {
			add_action(
				'admin_menu',
				static function () {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for admin page detection only, no data processing.
					if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
						return;
					}

					_doing_it_wrong(
						'initialize_features',
						esc_html__( 'AI settings page render function not found. Run npm run build:routes to generate build assets.', 'ai' ),
						'0.7.0'
					);
				}
			);
		}
	}
}
