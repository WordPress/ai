<?php
/**
 * Feature Loader class.
 *
 * @package WordPress\AI\Features
 */

declare( strict_types=1 );

namespace WordPress\AI\Features;

use Throwable;
use WordPress\AI\Contracts\Feature;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates feature initialization and registration.
 *
 * This class is responsible for loading and initializing features from the registry.
 * It decouples the initialization logic from the registry itself.
 *
 * @since 0.6.0
 */
final class Loader {
	/**
	 * Feature registry instance.
	 *
	 * @since 0.6.0
	 * @var \WordPress\AI\Features\Registry
	 */
	private \WordPress\AI\Features\Registry $registry;

	/**
	 * Whether features have been initialized.
	 *
	 * @since 0.6.0
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 0.6.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry instance.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Registers features.
	 *
	 * Registers the default built-in features and fires the 'wpai_register_features' action hook for third-party usage.
	 *
	 * @since 0.6.0
	 */
	public function register_features(): void {
		$features = $this->get_default_features();

		foreach ( $features as $feature ) {
			$this->registry->register_feature( $feature );
		}

		/**
		 * Allows registration of custom features.
		 *
		 * Third-party developers can use this action to register their own features.
		 *
		 * Example:
		 * ```php
		 * add_action( 'wpai_register_features', function( $registry ) {
		 *     $registry->register_feature( new My_Custom_Feature() );
		 * } );
		 * ```
		 *
		 * @since 0.6.0
		 *
		 * @param \WordPress\AI\Features\Registry $registry The feature registry instance.
		 */
		do_action( 'wpai_register_features', $this->registry );
	}

	/**
	 * Gets default built-in features.
	 *
	 * @since 0.6.0
	 *
	 * @return array<\WordPress\AI\Contracts\Feature> Array of default feature instances.
	 */
	private function get_default_features(): array {
		$feature_classes = array(
			// Features start off as experiments until they pass the requirements to graduate to full features.
		);

		/**
		 * Filters the list of default feature classes.
		 *
		 * Allows developers to add, remove, or replace default feature classes.
		 *
		 * @since 0.6.0
		 *
		 * @param array<string, \WordPress\AI\Contracts\Feature|class-string<\WordPress\AI\Contracts\Feature>> $feature_classes Array of feature class names, keyed by ID.
		 */
		$items = apply_filters( 'wpai_default_feature_classes', $feature_classes );

		$features = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Attempted to register invalid feature. Default features must be class-strings.', 'ai' ),
					'0.6.0'
				);
				continue;
			}

			if ( ! is_a( $item, Feature::class, true ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Attempted to register invalid feature. All features must implement the Feature interface.', 'ai' ),
					'0.6.0'
				);

				continue;
			}

			try {
				$feature                        = new $item();
				$features[ $feature::get_id() ] = $feature;
			} catch ( Throwable $e ) {
				// Skip features that fail to instantiate.
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Feature class name, 2: Error message. */
						esc_html__( 'Failed to instantiate feature "%1$s": %2$s', 'ai' ),
						esc_html( $item ),
						esc_html( $e->getMessage() ),
					),
					'0.6.0'
				);

				continue;
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
	 * @since 0.6.0
	 */
	public function initialize_features(): void {
		if ( $this->initialized ) {
			return;
		}

		/**
		 * Filters whether to enable AI features.
		 *
		 * @since 0.6.0
		 *
		 * @param bool $enabled Whether to enable AI features.
		 */
		$features_enabled = (bool) apply_filters( 'wpai_features_enabled', true );

		if ( ! $features_enabled ) {
			$this->initialized = true;
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
		 * @since 0.6.0
		 */
		do_action( 'wpai_features_initialized' );

		$this->initialized = true;
	}

	/**
	 * Checks if features have been initialized.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True if initialized, false otherwise.
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}
}
