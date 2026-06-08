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
}
