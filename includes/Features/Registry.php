<?php
/**
 * Feature Registry class.
 *
 * @package WordPress\AI\Features
 */

declare( strict_types=1 );

namespace WordPress\AI\Features;

use WordPress\AI\Contracts\Feature;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Central registry for managing feature storage and retrieval.
 *
 * Provides a simple storage mechanism for registered features.
 * Feature initialization is handled by the Loader class.
 *
 * @since 0.6.0
 */
final class Registry {
	/**
	 * Registered features.
	 *
	 * @since 0.6.0
	 * @var \WordPress\AI\Contracts\Feature[]
	 */
	private array $features = array();

	/**
	 * Registers a feature.
	 *
	 * @since 0.6.0
	 *
	 * @param \WordPress\AI\Contracts\Feature $feature Feature instance to register.
	 * @return bool True if registered successfully, false if already exists or invalid.
	 */
	public function register_feature( Feature $feature ): bool {
		$id = $feature::get_id();

		if ( $this->has_feature( $id ) ) {
			return false;
		}

		$this->features[ $id ] = $feature;
		return true;
	}

	/**
	 * Gets a feature by ID.
	 *
	 * @since 0.6.0
	 *
	 * @param string $id Feature identifier.
	 * @return \WordPress\AI\Contracts\Feature|null Feature instance or null if not found.
	 */
	public function get_feature( string $id ): ?Feature {
		return $this->features[ $id ] ?? null;
	}

	/**
	 * Gets all registered features.
	 *
	 * @since 0.6.0
	 *
	 * @return \WordPress\AI\Contracts\Feature[] Array of feature instances keyed by feature ID.
	 */
	public function get_all_features(): array {
		return $this->features;
	}

	/**
	 * Gets registered features by stability.
	 *
	 * @since 0.8.0
	 *
	 * @param 'deprecated'|'experimental'|'stable' $stability The stability level to match.
	 * @return \WordPress\AI\Contracts\Feature[] Array of matching feature instances keyed by feature ID.
	 */
	public function get_features_by_stability( string $stability ): array {
		$features = array();

		foreach ( $this->features as $feature_id => $feature ) {
			if ( $stability !== $feature->get_stability() ) {
				continue;
			}

			$features[ $feature_id ] = $feature;
		}

		return $features;
	}

	/**
	 * Checks if a feature is registered.
	 *
	 * @since 0.6.0
	 *
	 * @param string $id Feature identifier.
	 * @return bool True if registered, false otherwise.
	 */
	public function has_feature( string $id ): bool {
		return isset( $this->features[ $id ] );
	}
}
