<?php
/**
 * Abstract Feature base class.
 *
 * @package WordPress\AI\Abstracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Abstracts;

use InvalidArgumentException;
use WordPress\AI\Contracts\Feature;
use WordPress\AI\Features\Feature_Category;
use WordPress\AI\Settings\Settings_Registration;

/**
 * Base implementation for features.
 *
 * Provides common functionality for all features including enable/disable state.
 *
 * @since 0.6.0
 */
abstract class Abstract_Feature implements Feature {
	/**
	 * Feature identifier.
	 *
	 * @since 0.6.0
	 * @var non-empty-string
	 */
	protected string $id;

	/**
	 * Feature label.
	 *
	 * @since 0.6.0
	 * @var non-empty-string
	 */
	protected string $label;

	/**
	 * Feature description.
	 *
	 * @since 0.6.0
	 * @var non-empty-string
	 */
	protected string $description;

	/**
	 * Feature category.
	 *
	 * @since 0.6.0
	 * @var non-empty-string
	 */
	protected string $category;

	/**
	 * Cache for this feature's enabled status.
	 *
	 * @since 0.6.0
	 * @var bool|null
	 */
	private ?bool $enabled_cache = null;

	/**
	 * The feature stability level.
	 * @var 'deprecated'|'experimental'|'stable'
	 */
	private string $stability;

	/**
	 * The image URL for feature showcase display.
	 *
	 * @since 0.8.0
	 * @var string
	 */
	protected string $image;

	/**
	 * Constructor.
	 *
	 * Loads feature metadata and initializes properties.
	 *
	 * @since 0.6.0
	 *
	 * @throws \InvalidArgumentException If feature metadata is invalid.
	 */
	final public function __construct() {
		$this->id = static::get_id();
		if ( empty( $this->id ) ) {
			throw new InvalidArgumentException(
				esc_html__( 'Invalid Feature id returned by ::get_id().', 'ai' )
			);
		}

		$metadata = $this->load_metadata();
		if ( empty( $metadata['label'] ) ) {
			throw new InvalidArgumentException(
				esc_html__( 'Feature label is required in load_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['description'] ) ) {
			throw new InvalidArgumentException(
				esc_html__( 'Feature description is required in load_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['category'] ) ) {
			$metadata['category'] = Feature_Category::OTHER;
		}

		$this->label       = $metadata['label'];
		$this->description = $metadata['description'];
		$this->category    = $metadata['category'];
		$this->stability   = $metadata['stability'] ?? 'experimental';
		$this->image       = $metadata['image'] ?? '';
	}

	/**
	 * Loads feature metadata.
	 *
	 * Must return an array with keys: label, description.
	 * Optionally includes: category, stability.
	 *
	 * @since 0.6.0
	 *
	 * @return array{
	 *  label: string,
	 *  description: string,
	 *  category?: string,
	 *  stability?: 'deprecated'|'experimental'|'stable',
	 *  image?: string,
	 * } Feature metadata.
	 */
	abstract protected function load_metadata(): array;

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_category(): string {
		return $this->category;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Features require both the global toggle and individual feature toggle to be enabled.
	 * Results are cached per instance to avoid redundant option lookups and filter calls.
	 */
	final public function is_enabled(): bool {
		// Return cached result if available.
		if ( null !== $this->enabled_cache ) {
			return $this->enabled_cache;
		}

		// Check global features toggle first.
		$global_enabled = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		if ( ! $global_enabled ) {
			$this->enabled_cache = false;
			return false;
		}

		// Check feature-specific option.
		$feature_enabled = (bool) get_option( "wpai_feature_{$this->id}_enabled", false );

		// @todo remove in v1.0
		$is_enabled = (bool) apply_filters_deprecated(
			"ai_experiments_experiment_{$this->id}_enabled",
			array( $feature_enabled ),
			'0.6.0',
			"wpai_feature_{$this->id}_enabled",
			esc_html__( 'This will be removed in v1.0', 'ai' )
		);

		/**
		 * Filters the enabled status for a specific feature.
		 *
		 * The dynamic portion of the hook name, `$this->id`, refers to the feature ID.
		 *
		 * @since 0.6.0
		 *
		 * @param bool $feature_enabled Whether the feature is enabled.
		 */
		$is_enabled = (bool) apply_filters( "wpai_feature_{$this->id}_enabled", $is_enabled );

		// Cache the result.
		$this->enabled_cache = $is_enabled;

		return $is_enabled;
	}

	/**
	 * {@inheritDoc}
	 */
	final public function get_stability(): string {
		return $this->stability;
	}

	/**
	 * Gets the image URL for feature showcase display.
	 *
	 * @since 0.8.0
	 *
	 * @return string The image URL, or empty string if not set.
	 */
	public function get_image(): string {
		return $this->image;
	}

	/**
	 * Registers feature-specific settings.
	 *
	 * Override this method in child classes to register custom settings options
	 * using WordPress Settings API (register_setting).
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Default implementation does nothing.
		// Child classes can override to register custom settings.
	}

	/**
	 * Gets the field definitions for feature-specific settings.
	 *
	 * Override this method in child classes to declare custom settings fields
	 * that will be rendered as a DataForm on the settings page. Each field
	 * should use the short option name (e.g. 'strategy'), not the full
	 * namespaced option name.
	 *
	 * @since 0.7.0
	 *
	 * @return array<int, array{
	 *   id: string,
	 *   label: string,
	 *   type: string,
	 *   default?: mixed,
	 *   elements?: list<array{value: string, label: string}>,
	 *   isValid?: array{min?: int, max?: int},
	 * }> Array of field definitions matching the DataForm Field shape.
	 */
	public function get_settings_fields(): array {
		return array();
	}

	/**
	 * Gets field definitions with fully resolved option names.
	 *
	 * Transforms the short field IDs from get_settings_fields() into
	 * full WordPress option names suitable for the REST API and frontend.
	 *
	 * @since 0.7.0
	 *
	 * @return array<int, array{
	 *   id: string,
	 *   label: string,
	 *   type: string,
	 *   default?: mixed,
	 *   elements?: list<array{value: string, label: string}>,
	 *   isValid?: array{min?: int, max?: int},
	 * }> Array of field definitions with full option names.
	 */
	public function get_settings_fields_metadata(): array {
		$fields = $this->get_settings_fields();
		foreach ( $fields as &$field ) {
			$field['id'] = $this->get_field_option_name( $field['id'] );
		}
		unset( $field );
		return $fields;
	}

	/**
	 * Gets the option name for a custom feature setting field.
	 *
	 * Generates a properly namespaced option name for feature-specific settings.
	 * Use this when registering and storing custom settings fields to ensure
	 * consistent naming across the plugin.
	 *
	 * @since 0.6.0
	 *
	 * @param string $option_name The base option name (e.g., 'api_key', 'temperature').
	 * @return string The fully namespaced option name.
	 */
	final protected function get_field_option_name( string $option_name ): string {
		return "wpai_feature_{$this->id}_field_{$option_name}";
	}

	/**
	 * {@inheritDoc}
	 *
	 * Must be implemented by child classes to set up hooks and functionality.
	 */
	abstract public function register(): void;
}
