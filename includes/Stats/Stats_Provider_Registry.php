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
 * Resolves which registered Stats_Provider (if any) is available to query.
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
	 * @var array<string, \WordPress\AI\Stats\Stats_Provider>
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
		 * Filters the list of registered Stats_Provider instances.
		 *
		 * @since x.x.x
		 *
		 * @param array<int, \WordPress\AI\Stats\Stats_Provider> $providers Provider instances, tried in order.
		 */
		$providers = apply_filters( 'wpai_stats_providers', $default_providers );

		foreach ( $providers as $provider ) {
			if ( ! ( $provider instanceof Stats_Provider ) ) {
				continue;
			}

			$this->providers[ $provider->get_id() ] = $provider;
		}
	}

	/**
	 * Returns the first available provider, or null if none are available.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Stats\Stats_Provider|null The first available provider, or null.
	 */
	public function get_active_provider(): ?Stats_Provider {
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
	 * @return array<string, \WordPress\AI\Stats\Stats_Provider> Registered providers keyed by ID.
	 */
	public function get_providers(): array {
		return $this->providers;
	}
}
