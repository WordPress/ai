<?php
/**
 * Integration tests for the Stats_Provider_Registry class.
 *
 * @package WordPress\AI\Tests\Integration\Stats
 */

namespace WordPress\AI\Tests\Integration\Stats;

use WP_UnitTestCase;
use WordPress\AI\Stats\Stats_Provider;
use WordPress\AI\Stats\Stats_Provider_Registry;

/**
 * Stats_Provider_Registry test case.
 *
 * @since x.x.x
 */
class Stats_Provider_RegistryTest extends WP_UnitTestCase {

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_stats_providers' );
		remove_all_filters( 'wpai_jetpack_stats_provider_available' );
		parent::tearDown();
	}

	/**
	 * Registers the default Jetpack Stats provider.
	 *
	 * @since x.x.x
	 */
	public function test_registers_default_jetpack_provider(): void {
		$registry = new Stats_Provider_Registry();

		$this->assertArrayHasKey( 'jetpack-stats', $registry->get_providers() );
	}

	/**
	 * Returns null when no registered provider is available.
	 *
	 * @since x.x.x
	 */
	public function test_returns_null_when_no_provider_available(): void {
		add_filter( 'wpai_jetpack_stats_provider_available', '__return_false' );

		$registry = new Stats_Provider_Registry();

		$this->assertNull( $registry->get_active_provider() );
	}

	/**
	 * Additional providers can be registered via the filter.
	 *
	 * @since x.x.x
	 */
	public function test_additional_providers_can_be_registered_via_filter(): void {
		add_filter( 'wpai_jetpack_stats_provider_available', '__return_false' );
		add_filter(
			'wpai_stats_providers',
			static function ( array $providers ): array {
				$providers[] = new class() implements Stats_Provider {
					public function get_id(): string {
						return 'custom-provider';
					}

					public function is_available(): bool {
						return true;
					}

					public function get_search_queries( array $args = array() ) {
						return array();
					}

					public function get_post_traffic( int $post_id, array $args = array() ) {
						return array();
					}
				};

				return $providers;
			}
		);

		$registry = new Stats_Provider_Registry();
		$provider = $registry->get_active_provider();

		$this->assertInstanceOf( Stats_Provider::class, $provider );
		$this->assertSame( 'custom-provider', $provider->get_id() );
	}
}
