<?php
/**
 * Admin page controller for the AI Experiments settings screen.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin;

use WordPress\AI\Admin\Settings\Feature_Toggles;
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
	 * Section ID for the global experiments toggle.
	 */
	public const TOGGLE_SECTION_ID = 'ai-experiments-toggle';

	/**
	 * Default priority for the toggle section.
	 */
	private const TOGGLE_SECTION_PRIORITY = 5;

	/**
	 * Script handle for the settings page.
	 */
	private const SCRIPT_HANDLE = 'admin-settings';

	/**
	 * Style handle for the settings page.
	 */
	private const STYLE_HANDLE = 'admin-settings';

	/**
	 * Toggle service.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Toggle
	 */
	private $toggle;

	/**
	 * Feature toggles service.
	 *
	 * @var \WordPress\AI\Admin\Settings\Feature_Toggles
	 */
	private $feature_toggles;

	/**
	 * Settings registry.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Registry
	 */
	private $registry;

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
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle   $toggle          Toggle service.
	 * @param \WordPress\AI\Admin\Settings\Feature_Toggles   $feature_toggles Feature toggles service.
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry        Settings registry.
	 */
	public function __construct(
		Settings_Toggle $toggle,
		Feature_Toggles $feature_toggles,
		Settings_Registry $registry
	) {
		$this->toggle          = $toggle;
		$this->feature_toggles = $feature_toggles;
		$this->registry        = $registry;
	}

	/**
	 * Registers the submenu item under Settings.
	 *
	 * @since 0.1.0
	 */
	public function register_menu(): void {
		$this->hook_suffix = add_options_page(
			$this->get_page_title(),
			$this->get_page_title(),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		if ( ! $this->hook_suffix ) {
			return;
		}

		add_action(
			'admin_enqueue_scripts',
			array( $this, 'enqueue_assets' )
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

		$payload = $this->build_payload();
		?>
		<div class="wrap ai-experiments-settings">
			<h1><?php echo esc_html( $this->get_page_title() ); ?></h1>
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
	 * Enqueues the React application assets for the settings page.
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
			'ai_' . self::SCRIPT_HANDLE,
			sprintf(
				'window.wpAiExperimentsSettings = %s;',
				wp_json_encode( $this->build_payload() )
			),
			'before'
		);

		wp_enqueue_style( 'wp-components' );
		\WordPress\AI\Asset_Loader::enqueue_style( self::STYLE_HANDLE, 'style-index' );
	}

	/**
	 * Registers the default sections owned by the core plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry Settings registry.
	 */
	public function register_default_sections( Settings_Registry $registry ): void {
		if ( $registry->has_section( self::TOGGLE_SECTION_ID ) ) {
			return;
		}

		$registry->register_section(
			new Settings_Section(
				self::TOGGLE_SECTION_ID,
				__( 'Experimental Features', 'ai' ),
				__(
					'Enable or disable all experimental AI features globally. Individual features may expose additional controls when enabled.',
					'ai'
				),
				array( $this, 'render_toggle_section' ),
				self::TOGGLE_SECTION_PRIORITY
			)
		);
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

	/**
	 * Renders the global experiments toggle for the fallback UI.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle  $toggle  Toggle service.
	 * @param \WordPress\AI\Admin\Settings\Settings_Section $section Section metadata.
	 */
	public function render_toggle_section( Settings_Toggle $toggle, Settings_Section $section ): void {
		unset( $section );

		$option_name = Settings_Toggle::OPTION_KEY;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( Settings_Toggle::SETTINGS_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>" value="0" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $option_name ); ?>">
							<?php esc_html_e( 'Enable Experimental Features', 'ai' ); ?>
						</label>
					</th>
					<td>
						<label for="<?php echo esc_attr( $option_name ); ?>">
							<input
								type="checkbox"
								name="<?php echo esc_attr( $option_name ); ?>"
								id="<?php echo esc_attr( $option_name ); ?>"
								value="1"
								<?php checked( $toggle->is_enabled() ); ?>
							/>
							<?php esc_html_e( 'Allow experimental AI features to run on this site.', 'ai' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Returns the translated page title.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function get_page_title(): string {
		return __( 'AI Experiments', 'ai' );
	}

	/**
	 * Builds the settings payload shared with the React application.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Settings payload.
	 */
	private function build_payload(): array {
		$feature_toggles = $this->feature_toggles;
		$sections        = array_map(
			static function ( Settings_Section $section ) use ( $feature_toggles ): array {
				$feature_id      = $section->get_feature_id();
				$default_enabled = $section->get_default_enabled();
				$enabled         = $feature_id
					? $feature_toggles->is_feature_enabled( $feature_id, $default_enabled )
					: $default_enabled;

				return $section->to_array( $enabled );
			},
			$this->registry->get_sections()
		);

		return array(
			'toggle'         => $this->toggle->to_array(),
			'featureToggles' => $this->feature_toggles->to_array(),
			'sections'       => array_values( $sections ),
		);
	}
}
