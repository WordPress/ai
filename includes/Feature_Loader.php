<?php
/**
 * Feature Loader class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AI\Contracts\Feature;
use WordPress\AI\Exception\Invalid_Feature_Exception;
use WordPress\AI\Exception\Invalid_Feature_Metadata_Exception;
use Exception;

/**
 * Orchestrates feature initialization and registration.
 *
 * This class is responsible for loading and initializing features from the registry.
 * It decouples the initialization logic from the registry itself.
 *
 * @since 0.1.0
 */
class Feature_Loader {
	/**
	 * Feature registry instance.
	 *
	 * @since 0.1.0
	 * @var Feature_Registry
	 */
	private $registry;

	/**
	 * Whether features have been initialized.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Feature_Registry $registry The feature registry instance.
	 */
	public function __construct( Feature_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Registers default features.
	 *
	 * This is where built-in features are registered. Third-party features
	 * should use the 'ai_register_features' action hook.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $features Optional. Array of feature instances to register. Defaults to built-in features.
	 * @throws Invalid_Feature_Exception If a feature does not implement the Feature interface.
	 */
	public function register_default_features( array $features = array() ): void {
		// Use provided features or load defaults.
		if ( empty( $features ) ) {
			$features = $this->get_default_features();
		}

		// Register all features with type validation.
		foreach ( $features as $feature ) {
			// Skip invalid feature instances.
			if ( ! is_a( $feature, Feature::class ) ) {
				throw new Invalid_Feature_Exception(
					esc_html__( 'Attempted to register invalid feature. Must implement Feature interface.', 'ai' )
				);
			}

			$this->registry->register_feature( $feature );
		}

		/**
		 * Allows registration of custom features.
		 *
		 * Third-party developers can use this action to register their own features.
		 *
		 * Example:
		 * ```php
		 * add_action( 'ai_register_features', function( $registry ) {
		 *     $registry->register_feature( new My_Custom_Feature() );
		 * } );
		 * ```
		 *
		 * @since 0.1.0
		 *
		 * @param Feature_Registry $registry The feature registry instance.
		 */
		do_action( 'ai_register_features', $this->registry );
	}

	/**
	 * Gets default built-in features.
	 *
	 * @since 0.1.0
	 *
	 * @return Feature[] Array of default feature instances.
	 * @throws Invalid_Feature_Exception If a feature class does not exist (caught internally).
	 */
	private function get_default_features(): array {
		$feature_classes = array(
			'WordPress\AI\Features\Example_Feature\Example_Feature',
		);

		/**
		 * Filters the list of default feature classes or instances.
		 *
		 * Allows developers to add, remove, or replace default features.
		 * Can accept both class names (strings) and feature instances.
		 *
		 * @since 0.1.0
		 *
		 * @param array $feature_classes Array of feature class names or instances.
		 */
		$items = apply_filters( 'ai_default_feature_classes', $feature_classes );

		$features = array();
		foreach ( $items as $item ) {
			try {
				// Support both class names and pre-instantiated instances.
				if ( is_string( $item ) && class_exists( $item ) ) {
					$features[] = new $item();
				} elseif ( $item instanceof Feature ) {
					$features[] = $item;
				} elseif ( is_string( $item ) ) {
					// Class doesn't exist - throw exception.
					throw new Invalid_Feature_Exception(
						sprintf(
							/* translators: %s: Feature class name. */
							esc_html__( 'Feature class "%s" does not exist.', 'ai' ),
							esc_html( $item )
						)
					);
				}
			} catch ( Invalid_Feature_Metadata_Exception $e ) {
				// Skip features with invalid metadata.
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Feature class name, 2: Error message. */
						esc_html__( 'Failed to instantiate feature "%1$s": %2$s', 'ai' ),
						is_string( $item ) ? esc_html( $item ) : esc_html( get_class( $item ) ),
						esc_html( $e->getMessage() )
					),
					'0.1.0'
				);
			} catch ( Exception $e ) {
				// Skip features that fail to instantiate.
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Feature class name, 2: Error message. */
						esc_html__( 'Feature instantiation error for "%1$s": %2$s', 'ai' ),
						is_string( $item ) ? esc_html( $item ) : esc_html( get_class( $item ) ),
						esc_html( $e->getMessage() )
					),
					'0.1.0'
				);
			}
		}

		return $features;
	}

	/**
	 * Initializes all enabled features.
	 *
	 * Loops through all registered features and calls their register() method
	 * if they are enabled.
	 *
	 * @since 0.1.0
	 */
	public function initialize_features(): void {
		if ( $this->initialized ) {
			return;
		}

		foreach ( $this->registry->get_all_features() as $feature ) {
			// Skip if feature is disabled.
			if ( ! $feature->is_enabled() ) {
				continue;
			}

			// Register the feature.
			$feature->register();
		}

		/**
		 * Fires after all features have been initialized.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ai_features_initialized' );

		$this->initialized = true;
	}

	/**
	 * Checks if features have been initialized.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if initialized, false otherwise.
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}
}
