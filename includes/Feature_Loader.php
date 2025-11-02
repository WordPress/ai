<?php
/**
 * Feature Loader class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AI\Contracts\Feature;
use WordPress\AI\Exception\Invalid_Feature_Exception;

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
	 * @var \WordPress\AI\Feature_Registry
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
	 * @param \WordPress\AI\Feature_Registry $registry The feature registry instance.
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
	 * @throws \WordPress\AI\Exception\Invalid_Feature_Exception If a feature does not implement the Feature interface.
	 */
	public function register_default_features(): void {
		$features = $this->get_default_features();

		// Register all features with type validation.
		foreach ( $features as $feature ) {
			// Skip invalid feature instances.
			if ( ! $feature instanceof Feature ) {
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
		 * @param \WordPress\AI\Feature_Registry $registry The feature registry instance.
		 */
		do_action( 'ai_register_features', $this->registry );
	}

	/**
	 * Gets default built-in features.
	 *
	 * Feature toggles service is injected via filter if needed.
	 *
	 * @since 0.1.0
	 *
	 * @return array<\WordPress\AI\Contracts\Feature> Array of default feature instances.
	 */
	private function get_default_features(): array {
		/**
		 * Filters the feature toggles service for dependency injection.
		 *
		 * @since 0.1.0
		 *
		 * @param \WordPress\AI\Admin\Settings\Feature_Toggles|null $feature_toggles Feature toggles service or null.
		 */
		$feature_toggles = apply_filters( 'ai_feature_toggles_service', null );

		// Instantiate default features directly.
		$features = array(
			new \WordPress\AI\Features\Example_Feature\Example_Feature( $feature_toggles ),
		);

		/**
		 * Filters the list of default features.
		 *
		 * Allows developers to add, remove, or replace default features.
		 *
		 * @since 0.1.0
		 *
		 * @param array<\WordPress\AI\Contracts\Feature> $features Array of feature instances.
		 */
		return apply_filters( 'ai_default_features', $features );
	}

	/**
	 * Initializes all enabled features.
	 *
	 * Loops through all registered features and calls their register() method
	 * if they are enabled. Always calls register() for settings section registration,
	 * but features should internally check is_enabled() before registering functional hooks.
	 *
	 * @since 0.1.0
	 */
	public function initialize_features(): void {
		if ( $this->initialized ) {
			return;
		}

		/**
		 * Filters whether to enable AI features.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether to enable AI features.
		 */
		$features_enabled = apply_filters( 'ai_features_enabled', true );

		if ( ! $features_enabled ) {
			$this->initialized = true;
			return;
		}

		foreach ( $this->registry->get_all_features() as $feature ) {
			// Always register features so they can register settings sections.
			// Features should internally check is_enabled() for functional hooks.
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
