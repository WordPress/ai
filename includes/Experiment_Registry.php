<?php
/**
 * Experiment Registry class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Contracts\Experiment;

/**
 * Central registry for managing experiment storage and retrieval.
 *
 * Provides a simple storage mechanism for registered experiments.
 * Experiment initialization is handled by the Experiment_Loader class.
 *
 * @since 0.1.0
 */
final class Experiment_Registry {
	/**
	 * Registered experiments.
	 *
	 * @since 0.1.0
	 * @var \WordPress\AI\Contracts\Experiment[]
	 */
	private array $experiments = array();

	/**
	 * Registers an experiment.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Contracts\Experiment $experiment Experiment instance to register.
	 * @return bool True if registered successfully, false if already exists or invalid.
	 */
	public function register_experiment( Experiment $experiment ): bool {
		$id = $experiment->get_id();

		// Validate experiment ID is not empty.
		if ( empty( $id ) ) {
			return false;
		}

		if ( $this->has_experiment( $id ) ) {
			return false;
		}

		$this->experiments[ $id ] = $experiment;
		return true;
	}

	/**
	 * Gets an experiment by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Experiment identifier.
	 * @return \WordPress\AI\Contracts\Experiment|null Experiment instance or null if not found.
	 */
	public function get_experiment( string $id ): ?Experiment {
		return $this->experiments[ $id ] ?? null;
	}

	/**
	 * Gets all registered experiments.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Contracts\Experiment[] Array of experiment instances keyed by experiment ID.
	 */
	public function get_all_experiments(): array {
		return $this->experiments;
	}

	/**
	 * Checks if an experiment is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Experiment identifier.
	 * @return bool True if registered, false otherwise.
	 */
	public function has_experiment( string $id ): bool {
		return isset( $this->experiments[ $id ] );
	}
}
