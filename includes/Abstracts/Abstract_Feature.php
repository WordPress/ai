<?php
/**
 * Abstract Feature base class.
 *
 * @package WordPress\AI\Abstracts
 */

namespace WordPress\AI\Abstracts;

use WordPress\AI\Contracts\Feature;
use WordPress\AI\Exception\Invalid_Feature_Metadata_Exception;

/**
 * Base implementation for features.
 *
 * Provides common functionality for all features including enable/disable state.
 *
 * ## Rules for Creating Features:
 *
 * 1. **Implement load_feature_metadata()**: Return an array with 'id', 'label', and 'description'.
 *
 * 2. **Always register settings sections**: Use register_shared_hooks() to hook into
 *    'ai_register_settings_sections' so your feature appears in the admin UI even when disabled.
 *
 * 3. **Keep functional hooks behind the enablement check**: Put actions, filters, REST routes,
 *    etc. inside register_enabled_hooks() so they only run when the feature is enabled.
 *
 * 4. **Pass services as parameters**: Don't use global singletons or service locators. Accept
 *    services as method parameters (e.g., Settings_Registry passed to register_settings_sections()).
 *
 * 5. **Use Provides_Settings_Section trait**: To add a settings panel, use this trait and pass
 *    the registry as the first parameter to register_feature_settings_section().
 *
 * ## Example:
 *
 * ```php
 * class My_Feature extends Abstract_Feature {
 *     use Provides_Settings_Section;
 *
 *     protected function load_feature_metadata(): array {
 *         return array(
 *             'id'          => 'my-feature',
 *             'label'       => __( 'My Feature', 'ai' ),
 *             'description' => __( 'Description of my feature.', 'ai' ),
 *         );
 *     }
 *
 *     protected function register_shared_hooks(): void {
 *         add_action( 'ai_register_settings_sections', array( $this, 'register_settings_sections' ) );
 *     }
 *
 *     protected function register_enabled_hooks(): void {
 *         add_action( 'init', array( $this, 'my_hook' ) );
 *     }
 *
 *     public function register_settings_sections( Settings_Registry $registry ): void {
 *         $this->register_feature_settings_section(
 *             $registry, // Pass as parameter
 *             'my-feature',
 *             __( 'My Feature', 'ai' ),
 *             array( $this, 'render_settings' )
 *         );
 *     }
 *
 *     public function render_settings( Settings_Toggle $toggle, Settings_Section $section ): void {
 *         // Render settings UI.
 *     }
 * }
 * ```
 *
 * @since 0.1.0
 */
abstract class Abstract_Feature implements Feature {
	/**
	 * Feature identifier.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $id;

	/**
	 * Feature label.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $label;

	/**
	 * Feature description.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $description;

	/**
	 * Feature toggles service.
	 *
	 * @since 0.1.0
	 * @var \WordPress\AI\Admin\Settings\Feature_Toggles|null
	 */
	private $feature_toggles = null;

	/**
	 * Lazy factory for the feature toggles service.
	 *
	 * @since 0.1.0
	 * @var callable|null
	 */
	private $feature_toggles_factory = null;

	/**
	 * Constructor.
	 *
	 * Loads feature metadata and initializes properties.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Feature_Toggles|null $feature_toggles Optional. Feature toggles service for checking enabled state.
	 * @param callable|null                                     $feature_toggles_factory Optional. Factory that resolves a feature toggles service on demand.
	 *
	 * @throws \WordPress\AI\Exception\Invalid_Feature_Metadata_Exception If feature metadata is invalid.
	 */
	final public function __construct( ?\WordPress\AI\Admin\Settings\Feature_Toggles $feature_toggles = null, ?callable $feature_toggles_factory = null ) {
		$this->feature_toggles         = $feature_toggles;
		$this->feature_toggles_factory = $feature_toggles_factory;
		$metadata                      = $this->load_feature_metadata();

		if ( empty( $metadata['id'] ) ) {
			throw new Invalid_Feature_Metadata_Exception(
				esc_html__( 'Feature id is required in load_feature_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['label'] ) ) {
			throw new Invalid_Feature_Metadata_Exception(
				esc_html__( 'Feature label is required in load_feature_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['description'] ) ) {
			throw new Invalid_Feature_Metadata_Exception(
				esc_html__( 'Feature description is required in load_feature_metadata().', 'ai' )
			);
		}

		$this->id          = $metadata['id'];
		$this->label       = $metadata['label'];
		$this->description = $metadata['description'];
	}

	/**
	 * Loads feature metadata.
	 *
	 * Must return an array with keys: id, label, description.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	abstract protected function load_feature_metadata(): array;

	/**
	 * Gets the feature ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string Feature identifier.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Gets the feature label.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature label.
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Gets the feature description.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature description.
	 */
	public function get_description(): string {
		return $this->description;
	}
	/**
	 * Checks if feature is enabled.
	 *
	 * Uses injected Feature_Toggles service to check persisted toggle state.
	 * Falls back to the feature's default enabled state when no service exists.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	final public function is_enabled(): bool {
		$enabled = $this->is_enabled_by_default();

		$feature_toggles = $this->get_feature_toggles();

		if ( null !== $feature_toggles ) {
			$enabled = $feature_toggles->is_feature_enabled( $this->id, $enabled );
		}

		/**
		 * Filters the enabled status for a specific feature.
		 *
		 * The dynamic portion of the hook name, `$this->id`, refers to the feature ID.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether the feature is enabled.
		 */
		return (bool) apply_filters( "ai_feature_{$this->id}_enabled", $enabled );
	}

	/**
	 * Provides the default enabled state for the feature.
	 *
	 * Child classes can override this method to opt-out by default while still
	 * allowing the toggle service and filters to re-enable them.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the feature should default to enabled.
	 */
	protected function is_enabled_by_default(): bool {
		return true;
	}

	/**
	 * Resolves the feature toggles service, instantiating it on demand when provided as a factory.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Admin\Settings\Feature_Toggles|null
	 */
	private function get_feature_toggles(): ?\WordPress\AI\Admin\Settings\Feature_Toggles {
		if ( null !== $this->feature_toggles ) {
			return $this->feature_toggles;
		}

		if ( null === $this->feature_toggles_factory ) {
			return null;
		}

		$resolved = call_user_func( $this->feature_toggles_factory );
		if ( $resolved instanceof \WordPress\AI\Admin\Settings\Feature_Toggles ) {
				$this->feature_toggles         = $resolved;
			$this->feature_toggles_factory = null;
		}

		return $this->feature_toggles;
	}

	/**
	 * Registers the feature lifecycle.
	 *
	 * This method runs shared setup first, then conditionally registers functional hooks
	 * only when the feature is enabled.
	 *
	 * Child classes should override {@see Abstract_Feature::register_shared_hooks()}
	 * and {@see Abstract_Feature::register_enabled_hooks()} instead of this method.
	 *
	 * @since 0.1.0
	 */
	final public function register(): void {
		$this->register_shared_hooks();

		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->register_enabled_hooks();
	}

	/**
	 * Registers hooks that must always run regardless of enablement.
	 *
	 * Use this for admin settings sections or dependency checks that should execute
	 * even when the feature's functional hooks are disabled.
	 *
	 * @since 0.1.0
	 */
	protected function register_shared_hooks(): void {
		// Default no-op.
	}

	/**
	 * Registers hooks that should only run when the feature is enabled.
	 *
	 * Child classes must implement this to wire their functional behavior.
	 *
	 * @since 0.1.0
	 */
	abstract protected function register_enabled_hooks(): void;
}
