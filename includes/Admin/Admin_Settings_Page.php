<?php
/**
 * Admin page controller for the AI Experiments settings screen.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin;

use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;
use WordPress\AI\Admin\Settings\Settings_Toggle;

/**
 * Handles menu registration, asset loading, and fallback rendering.
 *
 * @since 0.1.0
 */
class Admin_Settings_Page {
	/**
	 * Menu slug for the settings page.
	 */
	private const MENU_SLUG = 'ai-experiments';

	/**
	 * Toggle service.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Toggle
	 */
	private $toggle;

	/**
	 * Settings registry.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Registry
	 */
	private $registry;

	/**
	 * Settings page assets handler.
	 *
	 * @var \WordPress\AI\Admin\Settings_Page_Assets
	 */
	private $assets;

	/**
	 * Settings payload builder.
	 *
	 * @var \WordPress\AI\Admin\Settings_Payload_Builder
	 */
	private $payload_builder;

	/**
	 * Hook suffix returned by add_options_page.
	 *
	 * @var string|false
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle           $toggle          Toggle service.
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry         $registry        Settings registry.
	 * @param \WordPress\AI\Admin\Settings_Page_Assets               $assets          Assets handler.
	 * @param \WordPress\AI\Admin\Settings_Payload_Builder           $payload_builder Payload builder.
	 */
	public function __construct(
		Settings_Toggle $toggle,
		Settings_Registry $registry,
		Settings_Page_Assets $assets,
		Settings_Payload_Builder $payload_builder
	) {
		$this->toggle          = $toggle;
		$this->registry        = $registry;
		$this->assets          = $assets;
		$this->payload_builder = $payload_builder;
	}

	/**
	 * Registers the submenu item under Settings.
	 *
	 * @since 0.1.0
	 */
	public function register_menu(): void {
		$this->hook_suffix = add_options_page(
			__( 'AI Experiments', 'ai' ),
			__( 'AI Experiments', 'ai' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		if ( ! $this->hook_suffix ) {
			return;
		}

		// Pass hook suffix to assets handler for conditional enqueueing.
		$this->assets->set_hook_suffix( $this->hook_suffix );

		add_action(
			'admin_enqueue_scripts',
			array( $this->assets, 'enqueue_assets' )
		);
	}

	/**
	 * Renders the settings page markup.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ai' ) );
		}

		$payload = $this->payload_builder->build();
		?>
		<div class="wrap ai-experiments-settings">
			<h1><?php esc_html_e( 'AI Experiments', 'ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Manage access to experimental AI functionality and review feature-specific settings.', 'ai' ); ?>
			</p>
			<div id="ai-experiments-settings-root" data-settings="<?php echo esc_attr( (string) wp_json_encode( $payload ) ); ?>"></div>
			<div class="ai-experiments-settings__fallback">
				<?php $this->render_sections(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders registered sections for the fallback experience.
	 *
	 * @since 0.1.0
	 */
	private function render_sections(): void {
		foreach ( $this->registry->get_sections() as $section ) {
			$this->render_section( $section );
		}
	}

	/**
	 * Renders an individual section.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Section $section Section metadata.
	 */
	private function render_section( Settings_Section $section ): void {
		$section_id = $section->get_id();
		?>
		<section
			id="ai-experiments-section-<?php echo esc_attr( $section_id ); ?>"
			class="ai-experiments-settings__section"
		>
			<h2><?php echo esc_html( $section->get_title() ); ?></h2>
			<?php if ( $section->get_description() ) : ?>
				<p><?php echo esc_html( $section->get_description() ); ?></p>
			<?php endif; ?>
			<div class="ai-experiments-settings__section-content">
				<?php call_user_func( $section->get_render_callback(), $this->toggle, $section ); ?>
			</div>
		</section>
		<?php
	}
}
