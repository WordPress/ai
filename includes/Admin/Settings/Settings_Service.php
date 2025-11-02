<?php
/**
 * Coordinates admin settings services for AI Experiments.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin\Settings;

use WordPress\AI\Admin\Admin_Settings_Page;

/**
 * Bootstraps registration of the settings toggle, sections, and admin page.
 *
 * @since 0.1.0
 */
class Settings_Service {
	/**
	 * Section ID for the global experiments toggle.
	 */
	public const TOGGLE_SECTION_ID = 'ai-experiments-toggle';

	/**
	 * Priority for the toggle section.
	 */
	public const TOGGLE_SECTION_PRIORITY = 5;

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
	 * Registry of settings sections.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Registry
	 */
	private $registry;

	/**
	 * Admin page controller.
	 *
	 * @var \WordPress\AI\Admin\Admin_Settings_Page
	 */
	private $page;

	/**
	 * Settings renderer.
	 *
	 * @var \WordPress\AI\Admin\Settings\Settings_Renderer
	 */
	private $renderer;

	/**
	 * Tracks whether sections have already been registered.
	 *
	 * @var bool
	 */
	private $sections_initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle   $toggle          Toggle service.
	 * @param \WordPress\AI\Admin\Settings\Feature_Toggles   $feature_toggles Feature toggles service.
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry        Settings registry.
	 * @param \WordPress\AI\Admin\Admin_Settings_Page        $page            Admin page controller.
	 * @param \WordPress\AI\Admin\Settings\Settings_Renderer $renderer        Settings renderer.
	 */
	public function __construct(
		Settings_Toggle $toggle,
		Feature_Toggles $feature_toggles,
		Settings_Registry $registry,
		Admin_Settings_Page $page,
		?Settings_Renderer $renderer = null
	) {
		$this->toggle          = $toggle;
		$this->feature_toggles = $feature_toggles;
		$this->registry        = $registry;
		$this->page            = $page;
		$this->renderer        = $renderer ?? new Settings_Renderer();
	}

	/**
	 * Registers WordPress hooks for the service.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter(
			'ai_features_enabled',
			array( $this->toggle, 'filter_features_enabled' )
		);

		add_action(
			'admin_init',
			array( $this, 'register_settings' )
		);

		add_action(
			'rest_api_init',
			array( $this, 'register_settings' )
		);

		add_action(
			'admin_menu',
			array( $this->page, 'register_menu' )
		);

		add_action(
			'ai_register_settings_sections',
			array( $this, 'register_default_sections' ),
			0
		);
	}

	/**
	 * Registers the settings option and triggers section registration.
	 *
	 * @since 0.1.0
	 */
	public function register_settings(): void {
		$this->toggle->register();
		$this->feature_toggles->register();

		if ( $this->sections_initialized ) {
			return;
		}

		/**
		 * Allows features to register their settings sections.
		 *
		 * @since 0.1.0
		 *
		 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry Settings registry.
		 */
		do_action( 'ai_register_settings_sections', $this->registry );

		$this->sections_initialized = true;
	}

	/**
	 * Registers the core plugin sections.
	 *
	 * @since 0.1.0
	 */
	public function register_default_sections(): void {
		if ( $this->registry->has_section( self::TOGGLE_SECTION_ID ) ) {
			return;
		}

		$this->registry->register_section(
			new Settings_Section(
				self::TOGGLE_SECTION_ID,
				__( 'Experimental Features', 'ai' ),
				__(
					'Enable or disable all experimental AI features globally. Individual features may expose additional controls when enabled.',
					'ai'
				),
				array( $this->renderer, 'render_toggle_section' ),
				self::TOGGLE_SECTION_PRIORITY
			)
		);
	}

	/**
	 * Returns the settings registry.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Admin\Settings\Settings_Registry
	 */
	public function get_registry(): Settings_Registry {
		return $this->registry;
	}

	/**
	 * Returns the toggle service.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Admin\Settings\Settings_Toggle
	 */
	public function get_toggle(): Settings_Toggle {
		return $this->toggle;
	}

	/**
	 * Returns the feature toggles service.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Admin\Settings\Feature_Toggles
	 */
	public function get_feature_toggles(): Feature_Toggles {
		return $this->feature_toggles;
	}
}
