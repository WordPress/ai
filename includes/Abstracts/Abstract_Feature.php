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
	 * Constructor.
	 *
	 * Loads feature metadata and initializes properties.
	 *
	 * @since 0.1.0
	 *
	 * @throws Invalid_Feature_Metadata_Exception If feature metadata is invalid.
	 */
	final public function __construct() {
		$metadata = $this->load_feature_metadata();

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
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	final public function is_enabled(): bool {
		$enabled = $this->enabled;

		/**
		 * Filters the enabled status for a specific feature.
		 *
		 * The dynamic portion of the hook name, `$this->id`, refers to the feature ID.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether the feature is enabled.
		 */
		$enabled = apply_filters( "ai_feature_{$this->id}_enabled", $enabled );

		/**
		 * Filters the enabled status across all features.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $enabled    Whether the feature is enabled.
		 * @param string $feature_id The feature identifier.
		 */
		return apply_filters( 'ai_feature_enabled', $enabled, $this->id );
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
