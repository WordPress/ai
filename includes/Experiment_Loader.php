<?php
/**
 * Experiment Loader class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use Throwable;
use WordPress\AI\Contracts\Experiment;
use WordPress\AI\Exception\Invalid_Experiment_Exception;
use WordPress\AI\Exception\Invalid_Experiment_Metadata_Exception;

/**
 * Orchestrates experiment initialization and registration.
 *
 * This class is responsible for loading and initializing experiments from the registry.
 * It decouples the initialization logic from the registry itself.
 *
 * @since 0.1.0
 */
final class Experiment_Loader {
	/**
	 * Experiment registry instance.
	 *
	 * @since 0.1.0
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private \WordPress\AI\Experiment_Registry $registry;

	/**
	 * Whether experiments have been initialized.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Experiment_Registry $registry The experiment registry instance.
	 */
	public function __construct( Experiment_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Registers default experiments.
	 *
	 * This is where built-in experiments are registered. Third-party experiments
	 * should use the 'ai_experiments_register_experiments' action hook.
	 *
	 * @since 0.1.0
	 *
	 * @throws \WordPress\AI\Exception\Invalid_Experiment_Exception If an experiment does not implement the Experiment interface.
	 */
	public function register_default_experiments(): void {
		$experiments = $this->get_default_experiments();

		// Register all experiments with type validation.
		foreach ( $experiments as $experiment ) {
			// Skip invalid experiment instances.
			if ( ! $experiment instanceof Experiment ) {
				throw new Invalid_Experiment_Exception(
					esc_html__( 'Attempted to register invalid experiment. Must implement Experiment interface.', 'ai' )
				);
			}

			$this->registry->register_experiment( $experiment );
		}

		/**
		 * Allows registration of custom experiments.
		 *
		 * Third-party developers can use this action to register their own experiments.
		 *
		 * Example:
		 * ```php
		 * add_action( 'ai_experiments_register_experiments', function( $registry ) {
		 *     $registry->register_experiment( new My_Custom_Experiment() );
		 * } );
		 * ```
		 *
		 * @since 0.1.0
		 *
		 * @param \WordPress\AI\Experiment_Registry $registry The experiment registry instance.
		 */
		do_action( 'ai_experiments_register_experiments', $this->registry );
	}

	/**
	 * Gets default built-in experiments.
	 *
	 * @since 0.1.0
	 *
	 * @return array<\WordPress\AI\Contracts\Experiment> Array of default experiment instances.
	 * @throws \WordPress\AI\Exception\Invalid_Experiment_Exception If an experiment class does not exist (caught internally).
	 */
	private function get_default_experiments(): array {
		$experiment_classes = array(
			\WordPress\AI\Experiments\Title_Generation\Title_Generation::class,
		);

		/**
		 * Filters the list of default experiment classes or instances.
		 *
		 * Allows developers to add, remove, or replace default experiments.
		 * Can accept both class names (strings) and experiment instances.
		 *
		 * @since 0.1.0
		 *
		 * @param array $experiment_classes Array of experiment class names or instances.
		 */
		$items = apply_filters( 'ai_experiments_default_experiment_classes', $experiment_classes );

		$experiments = array();
		foreach ( $items as $item ) {
			try {
				// Support both class names and pre-instantiated instances.
				if ( is_string( $item ) && class_exists( $item ) ) {
					/** @var class-string<\WordPress\AI\Contracts\Experiment> $item */
					$experiments[] = new $item();
				} elseif ( $item instanceof Experiment ) {
					$experiments[] = $item;
				} elseif ( is_string( $item ) ) {
					// Class doesn't exist - throw exception.
					throw new Invalid_Experiment_Exception(
						sprintf(
							/* translators: %s: Experiment class name. */
							esc_html__( 'Experiment class "%s" does not exist.', 'ai' ),
							esc_html( $item )
						)
					);
				}
			} catch ( Invalid_Experiment_Metadata_Exception $e ) {
				// Skip experiments with invalid metadata.
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Experiment class name, 2: Error message. */
						esc_html__( 'Failed to instantiate experiment "%1$s": %2$s', 'ai' ),
						is_string( $item ) ? esc_html( $item ) : esc_html( (string) get_class( $item ) ),
						esc_html( $e->getMessage() )
					),
					'0.1.0'
				);
			} catch ( Throwable $t ) {
				// Skip experiments that fail to instantiate.
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Experiment class name, 2: Error message. */
						esc_html__( 'Experiment instantiation error for "%1$s": %2$s', 'ai' ),
						is_string( $item ) ? esc_html( $item ) : esc_html( (string) get_class( $item ) ),
						esc_html( $t->getMessage() )
					),
					'0.1.0'
				);
			}
		}

		return $experiments;
	}

	/**
	 * Initializes all enabled experiments.
	 *
	 * Loops through all registered experiments and calls their register() method
	 * if they are enabled.
	 *
	 * @since 0.1.0
	 */
	public function initialize_experiments(): void {
		if ( $this->initialized ) {
			return;
		}

		/**
		 * Filters whether to enable AI experiments.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether to enable AI experiments.
		 */
		$experiments_enabled = apply_filters( 'ai_experiments_enabled', true );

		if ( ! $experiments_enabled ) {
			$this->initialized = true;
			return;
		}

		foreach ( $this->registry->get_all_experiments() as $experiment ) {
			// Skip if experiment is disabled.
			if ( ! $experiment->is_enabled() ) {
				continue;
			}

			// Register the experiment.
			$experiment->register();
		}

		/**
		 * Fires after all experiments have been initialized.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ai_experiments_initialized' );

		$this->initialized = true;
	}

	/**
	 * Checks if experiments have been initialized.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if initialized, false otherwise.
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}
}
