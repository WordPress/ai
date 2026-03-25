<?php
/**
 * Settings page for the AI plugin.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Settings;

use WordPress\AI\Features\Registry;

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
	 * The wp-build settings page slug.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ai-wp-admin';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
	}

	/**
	 * Initializes the settings page hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Registers the admin menu item.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( ! function_exists( 'ai_ai_wp_admin_render_page' ) ) {
			return;
		}

		add_options_page(
			__( 'AI', 'ai' ),
			__( 'AI', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			'ai_ai_wp_admin_render_page', // @phpstan-ignore argument.type (function verified by function_exists above)
			2
		);
	}
}
