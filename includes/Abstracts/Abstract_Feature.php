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
 * 2. **Always register settings sections**: In register(), hook into 'ai_register_settings_sections'
 *    so your feature appears in the admin UI even when disabled.
 *
 * 3. **Check is_enabled() before functional hooks**: Only register functional hooks (like actions,
 *    filters, REST routes) if is_enabled() returns true. This allows users to disable features.
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
 *     public function register(): void {
 *         // Always register settings sections.
 *         add_action( 'ai_register_settings_sections', array( $this, 'register_settings_sections' ) );
 *
 *         // Only register functional hooks if enabled.
 *         if ( ! $this->is_enabled() ) {
 *             return;
 *         }
 *
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
	 * Whether the feature is enabled.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $enabled = true;

	/**
	 * Feature toggles service.
	 *
	 * @since 0.1.0
	 * @var \WordPress\AI\Admin\Settings\Feature_Toggles|null
	 */
	private $feature_toggles = null;

	/**
	 * Constructor.
	 *
	 * Loads feature metadata and initializes properties.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Feature_Toggles|null $feature_toggles Optional. Feature toggles service for checking enabled state.
	 *
	 * @throws \WordPress\AI\Exception\Invalid_Feature_Metadata_Exception If feature metadata is invalid.
	 */
	final public function __construct( ?\WordPress\AI\Admin\Settings\Feature_Toggles $feature_toggles = null ) {
		$this->feature_toggles = $feature_toggles;
		$metadata              = $this->load_feature_metadata();

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
	 * Falls back to default enabled state if service not available.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	final public function is_enabled(): bool {
		if ( null !== $this->feature_toggles ) {
			$enabled = $this->feature_toggles->is_feature_enabled( $this->id );
		} else {
			$enabled = $this->enabled;
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
	 * Registers the feature.
	 *
	 * Must be implemented by child classes to set up hooks and functionality.
	 *
	 * @since 0.1.0
	 */
	abstract public function register(): void;
}
