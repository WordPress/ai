<?php
/**
 * Feature Registry and Collection classes.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AI\Contracts\Feature;

/**
 * Manages a collection of features.
 *
 * This class is responsible for storing and retrieving feature instances.
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

/**
 * Central registry for managing feature storage and retrieval.
 *
 * Provides a simple storage mechanism for registered features.
 * Feature initialization is handled by the Feature_Loader class.
 *
 * @since 0.1.0
 */
class Feature_Registry {
	/**
	 * Feature collection instance.
	 *
	 * @since 0.1.0
	 * @var Feature_Collection
	 */
	private $feature_collection;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->feature_collection = new Feature_Collection();
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
}
