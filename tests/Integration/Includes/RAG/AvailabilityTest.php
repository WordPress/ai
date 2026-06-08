<?php
/**
 * Integration tests for RAG availability checks.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_UnitTestCase;
use WordPress\AI\RAG\Availability;

/**
 * Availability test case.
 *
 * @since 1.1.0
 */
class AvailabilityTest extends WP_UnitTestCase {
	/**
	 * Cleans up filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_rag_memory_fallback_enabled' );
		delete_option( Availability::BACKEND_OPTION );

		parent::tearDown();
	}

	/**
	 * Tests MariaDB vector index version support detection.
	 *
	 * @dataProvider data_mariadb_versions
	 *
	 * @since 1.1.0
	 *
	 * @param string $version  Raw DB version.
	 * @param bool   $expected Expected support.
	 */
	public function test_is_supported_mariadb_version( string $version, bool $expected ): void {
		$availability = new Availability();

		$this->assertSame( $expected, $availability->is_supported_mariadb_version( $version ) );
	}

	/**
	 * Data provider for version checks.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function data_mariadb_versions(): array {
		return array(
			'mariadb 11.8'     => array( '11.8.0-MariaDB', true ),
			'mariadb 11.8.2'   => array( '11.8.2-MariaDB-ubu2404', true ),
			'mariadb 11.7'     => array( '11.7.2-MariaDB', false ),
			'prefixed mariadb' => array( '5.5.5-11.8.1-MariaDB', true ),
			'mysql 8'          => array( '8.0.36', false ),
			'empty'            => array( '', false ),
		);
	}

	/**
	 * Tests backend selection for supported MariaDB.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_prefers_mariadb_when_supported(): void {
		$availability = $this->create_availability_for_database_version( '11.8.2-MariaDB' );

		$this->assertSame( Availability::BACKEND_MARIADB, $availability->get_index_backend() );
	}

	/**
	 * Tests explicit backend selection.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_honors_backend_setting_when_available(): void {
		update_option( Availability::BACKEND_OPTION, Availability::BACKEND_MEMORY );

		$availability = $this->create_availability_for_database_version( '11.8.2-MariaDB' );

		$this->assertSame( Availability::BACKEND_MEMORY, $availability->get_index_backend() );
	}

	/**
	 * Tests fallback when the selected backend is unavailable.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_uses_default_when_setting_is_unavailable(): void {
		update_option( Availability::BACKEND_OPTION, Availability::BACKEND_MARIADB );

		$availability = $this->create_availability_for_database_version( '8.0.36' );

		$this->assertSame( Availability::BACKEND_MEMORY, $availability->get_index_backend() );
	}

	/**
	 * Tests backend selection for the compact fallback.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_uses_memory_fallback_without_mariadb(): void {
		$availability = $this->create_availability_for_database_version( '8.0.36' );

		$this->assertSame( Availability::BACKEND_MEMORY, $availability->get_index_backend() );
	}

	/**
	 * Tests backend selection when the fallback is disabled.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_returns_mariadb_when_memory_fallback_is_disabled(): void {
		add_filter( 'wpai_rag_memory_fallback_enabled', '__return_false' );

		$availability = $this->create_availability_for_database_version( '8.0.36' );

		$this->assertSame( Availability::BACKEND_MARIADB, $availability->get_index_backend() );
	}

	/**
	 * Creates an availability service with a fixed database version.
	 *
	 * @since 1.1.0
	 *
	 * @param string $version Database version.
	 * @return \WordPress\AI\RAG\Availability Availability service.
	 */
	private function create_availability_for_database_version( string $version ): Availability {
		return new class( $version ) extends Availability {
			/**
			 * Database version.
			 *
			 * @var string
			 */
			private string $version;

			/**
			 * Constructor.
			 *
			 * @param string $version Database version.
			 */
			public function __construct( string $version ) {
				$this->version = $version;
			}

			/**
			 * {@inheritDoc}
			 */
			protected function get_database_version(): string {
				return $this->version;
			}
		};
	}
}
