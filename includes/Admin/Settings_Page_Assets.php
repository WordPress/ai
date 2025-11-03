<?php
/**
 * Handles asset enqueuing for the settings page.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin;

/**
 * Manages scripts and styles for the admin settings page.
 *
 * @since 0.1.0
 */
class Settings_Page_Assets {
	/**
	 * Script handle for the settings page.
	 */
	public const SCRIPT_HANDLE = 'wp-ai-admin-settings';

	/**
	 * Style handle for the settings page.
	 */
	public const STYLE_HANDLE = 'wp-ai-admin-settings';

	/**
	 * Payload builder.
	 *
	 * @var \WordPress\AI\Admin\Settings_Payload_Builder
	 */
	private $payload_builder;

	/**
	 * Hook suffix for the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings_Payload_Builder $payload_builder Payload builder.
	 */
	public function __construct( Settings_Payload_Builder $payload_builder ) {
		$this->payload_builder = $payload_builder;
	}

	/**
	 * Sets the hook suffix for conditional enqueueing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Hook suffix from add_options_page().
	 */
	public function set_hook_suffix( string $hook_suffix ): void {
		$this->hook_suffix = $hook_suffix;
	}

	/**
	 * Enqueues assets when the settings page is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @param string $current_hook Current admin page hook.
	 */
	public function enqueue_assets( string $current_hook ): void {
		if ( $current_hook !== $this->hook_suffix ) {
			return;
		}

		$asset_path = AI_PLUGIN_DIR . 'build/index.asset.php';

		if ( file_exists( $asset_path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Local build manifest.
			// @phpstan-ignore-next-line Path is generated during build; guarded by file_exists above.
			$asset = require $asset_path;

			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				AI_PLUGIN_URL . 'build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			// Inject settings payload for React hydration.
			wp_add_inline_script(
				self::SCRIPT_HANDLE,
				sprintf(
					'window.wpAiExperimentsSettings = %s;',
					wp_json_encode( $this->payload_builder->build() )
				),
				'before'
			);

			$style_path = AI_PLUGIN_DIR . 'build/style-index.css';
			if ( file_exists( $style_path ) ) {
				wp_enqueue_style(
					self::STYLE_HANDLE,
					AI_PLUGIN_URL . 'build/style-index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		} else {
			// Ensure core components styles are present for fallback markup.
			wp_enqueue_style( 'wp-components' );
		}
	}
}
