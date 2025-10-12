<?php
/**
 * Feature Loader class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AI\Contracts\Feature;

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
	 */
	public function register_default_features(): void {
		// Register example feature (demonstrates the system).
		$class_name = 'WordPress\AI\Features\Example_Feature\Example_Feature';

		if ( class_exists( $class_name ) ) {
			$this->registry->register_feature( new $class_name() );
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
}