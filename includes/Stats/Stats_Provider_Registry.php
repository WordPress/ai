<?php
/**
 * Stats Provider Registry.
 *
 * @package WordPress\AI\Stats
 */

declare( strict_types=1 );

namespace WordPress\AI\Stats;

use WordPress\AI\Stats\Providers\Jetpack_Stats_Provider;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves which registered Stats_Provider_Interface (if any) is available to query.
 *
 * Providers are tried in registration order; the first one that reports
 * itself as available wins. Additional providers (Site Kit, MonsterInsights,
 * Matomo, etc.) can be added via the `wpai_stats_providers` filter without
 * modifying this class.
 *
 * @since x.x.x
 */
final class Stats_Provider_Registry {

	/**
	 * Registered provider instances, keyed by provider ID.
	 *
	 * @since x.x.x
	 * @var array<string, \WordPress\AI\Stats\Stats_Provider_Interface>
	 */
	private array $providers = array();

	/**
	 * Constructor.
	 *
	 * Registers the default set of providers, then allows extension via filter.
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		$default_providers = array(
			new Jetpack_Stats_Provider(),
		);

		/**
		 * Filters the list of registered Stats_Provider_Interface instances.
		 *
		 * @since x.x.x
		 *
		 * @param array<int, \WordPress\AI\Stats\Stats_Provider_Interface> $providers Provider instances, tried in order.
		 */
		$providers = apply_filters( 'wpai_stats_providers', $default_providers );

		if ( ! is_array( $providers ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'The wpai_stats_providers filter must return an array of providers.', 'ai' ),
				'x.x.x'
			);

			$providers = $default_providers;
		}

		foreach ( $providers as $provider ) {
			if ( ! ( $provider instanceof Stats_Provider_Interface ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Stats providers must implement Stats_Provider_Interface.', 'ai' ),
					'x.x.x'
				);
				continue;
			}

			$id = $provider->get_id();

			if ( isset( $this->providers[ $id ] ) ) {
				_doing_it_wrong(
					__METHOD__,
					/* translators: %s: Stats provider ID. */
					esc_html( sprintf( __( 'A stats provider with the ID "%s" is already registered.', 'ai' ), $id ) ),
					'x.x.x'
				);
				continue;
			}

			$this->providers[ $id ] = $provider;
		}
	}

	/**
	 * Returns the first available provider, or null if none are available.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Stats\Stats_Provider_Interface|null The first available provider, or null.
	 */
	public function get_active_provider(): ?Stats_Provider_Interface {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_available() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Returns all registered providers, regardless of availability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WordPress\AI\Stats\Stats_Provider_Interface> Registered providers keyed by ID.
	 */
	public function get_providers(): array {
		return $this->providers;
	}
}
