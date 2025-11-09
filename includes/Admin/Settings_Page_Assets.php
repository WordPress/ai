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
	public const SCRIPT_HANDLE = 'admin-settings';

	/**
	 * Style handle for the settings page.
	 */
	public const STYLE_HANDLE = 'admin-settings';

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

		\WordPress\AI\Asset_Loader::enqueue_script( self::SCRIPT_HANDLE, 'index' );

		wp_add_inline_script(
			\WordPress\AI\Asset_Loader::prefix_handle( self::SCRIPT_HANDLE ),
			sprintf(
				'window.wpAiExperimentsSettings = %s;',
				wp_json_encode( $this->payload_builder->build() )
			),
			'before'
		);

		\WordPress\AI\Asset_Loader::enqueue_style( self::STYLE_HANDLE, 'style-index', array( 'wp-components' ) );
	}
}
