<?php
/**
 * Feature Registry class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AI\Interfaces\Feature;
use WordPress\AI\Interfaces\Conditional_Feature;
use WordPress\AI\Feature_Collection;

/**
 * Central registry for managing feature registration and initialization.
 *
 * Provides a singleton instance that manages all registered features,
 * handles initialization, and allows third-party feature registration.
 *
 * @since 0.1.0
 */
final class Feature_Registry {
	/**
	 * Feature collection instance.
	 *
	 * @since 0.1.0
	 * @var Feature_Collection
	 */
	private $feature_collection;

	/**
	 * Whether features have been initialized.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Singleton instance.
	 *
	 * @since 0.1.0
	 * @var Feature_Registry|null
	 */
	private static $instance = null;

	/**
	 * Gets the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @return Feature_Registry The singleton instance.
	 */
	public static function instance(): Feature_Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->feature_collection = new Feature_Collection();
		$this->register_default_features();
	}

	/**
	 * Registers a feature.
	 *
	 * @since 0.1.0
	 *
	 * @param Feature $feature Feature instance to register.
	 * @return bool True if registered successfully, false if already exists.
	 */
	public function register_feature( Feature $feature ): bool {
		return $this->feature_collection->register_feature( $feature );
	}

	/**
	 * Gets a feature by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Feature identifier.
	 * @return Feature|null Feature instance or null if not found.
	 */
	public function get_feature( string $id ): ?Feature {
		return $this->feature_collection->get_feature( $id );
	}

	/**
	 * Gets all registered features.
	 *
	 * @since 0.1.0
	 *
	 * @return Feature[] Array of feature instances keyed by feature ID.
	 */
	public function get_all_features(): array {
		return $this->feature_collection->get_all_features();
	}

	/**
	 * Initializes all enabled features.
	 *
	 * Loops through all registered features and calls their register() method
	 * if they are enabled and meet requirements (for conditional features).
	 *
	 * @since 0.1.0
	 */
	public function initialize_features(): void {
		if ( $this->initialized ) {
			return;
		}

		foreach ( $this->feature_collection->get_all_features() as $feature ) {
			// Skip if feature is disabled.
			if ( ! $feature->is_enabled() ) {
				continue;
			}

			// Check conditional requirements.
			if ( $feature instanceof Conditional_Feature && ! $feature->meets_requirements() ) {
				continue;
			}

			// Register the feature.
			$feature->register();
		}

		$this->initialized = true;

		/**
		 * Fires after all features have been initialized.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ai_features_initialized' );
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

	/**
	 * Registers default features.
	 *
	 * This is where built-in features are registered. Third-party features
	 * should use the 'ai_register_features' action hook.
	 *
	 * @since 0.1.0
	 */
	private function register_default_features(): void {
		// Register example feature (demonstrates the system).
		$class_name = 'WordPress\AI\Features\Example_Feature\Example_Feature';

		if ( class_exists( $class_name ) ) {
			$this->register_feature( new $class_name() );
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
		do_action( 'ai_register_features', $this );
	}
}
