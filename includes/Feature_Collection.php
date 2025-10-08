<?php
/**
 * Feature Collection class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AI\Interfaces\Feature;

/**
 * Manages a collection of features.
 *
 * This class is responsible for storing and retrieving feature instances
 * in a WordPress-independent manner.
 *
 * @since 0.1.0
 */
class Feature_Collection {
	/**
	 * Registered features.
	 *
	 * @since 0.1.0
	 * @var Feature[]
	 */
	private $features = array();

	/**
	 * Registers a feature.
	 *
	 * @since 0.1.0
	 *
	 * @param Feature $feature Feature instance to register.
	 * @return bool True if registered successfully, false if already exists.
	 */
	public function register_feature( Feature $feature ): bool {
		$id = $feature->get_id();

		if ( isset( $this->features[ $id ] ) ) {
			return false;
		}

		$this->features[ $id ] = $feature;
		return true;
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
		return $this->features[ $id ] ?? null;
	}

	/**
	 * Gets all registered features.
	 *
	 * @since 0.1.0
	 *
	 * @return Feature[] Array of feature instances keyed by feature ID.
	 */
	public function get_all_features(): array {
		return $this->features;
	}

	/**
	 * Checks if a feature is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Feature identifier.
	 * @return bool True if registered, false otherwise.
	 */
	public function has_feature( string $id ): bool {
		return isset( $this->features[ $id ] );
	}
}
