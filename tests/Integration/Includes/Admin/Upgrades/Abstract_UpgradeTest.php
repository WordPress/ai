<?php
/**
 * Integration tests for Abstract_Upgrade.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Upgrades
 */

namespace WordPress\AI\Tests\Integration\Admin\Upgrades;

use Error;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades\Abstract_Upgrade;

/**
 * Concrete upgrade implementation for testing Abstract_Upgrade.
 *
 * @since x.x.x
 */
class Testable_Abstract_Upgrade extends Abstract_Upgrade {

	/**
	 * Target upgrade version.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public static string $version = '1.0.0';

	/**
	 * Whether the upgrade routine was executed.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	public bool $upgraded = false;

	/**
	 * Optional throwable to throw during upgrade.
	 *
	 * @since x.x.x
	 *
	 * @var \Throwable|null
	 */
	public ?Throwable $throwable_to_throw = null;

	/**
	 * Performs the upgrade routine.
	 *
	 * @since x.x.x
	 *
	 * @throws \Throwable Throws when a throwable is set for testing.
	 */
	protected function upgrade(): void {
		if ( null !== $this->throwable_to_throw ) {
			throw $this->throwable_to_throw;
		}

		$this->upgraded = true;
	}
}

/**
 * Abstract_Upgrade test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades\Abstract_Upgrade
 *
 * @since x.x.x
 */
class Abstract_UpgradeTest extends WP_UnitTestCase {

	/**
	 * Tests constructor with valid database versions.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_valid_db_versions
	 *
	 * @param string $db_version Valid database version.
	 */
	public function test_constructor_accepts_valid_db_versions( string $db_version ): void {
		$upgrade = new Testable_Abstract_Upgrade( $db_version );

		$reflection = new ReflectionClass( Abstract_Upgrade::class );
		$property   = $reflection->getProperty( 'db_version' );
		$property->setAccessible( true );

		$this->assertSame( $db_version, $property->getValue( $upgrade ) );
	}

	/**
	 * Data provider for valid database versions.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Valid database versions.
	 */
	public function data_valid_db_versions(): array {
		return array(
			'empty version for new installs' => array( '' ),
			'standard semver'                => array( '0.5.0' ),
			'major release'                  => array( '1.0.0' ),
			'minor release'                  => array( '1.2.3' ),
			'prerelease version'             => array( '1.0.0-beta1' ),
		);
	}

	/**
	 * Tests constructor throws an exception for invalid database versions.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_db_versions
	 *
	 * @param string $invalid_version Invalid database version.
	 */
	public function test_constructor_throws_for_invalid_db_version( string $invalid_version ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid database version provided for upgrade.' );

		new Testable_Abstract_Upgrade( $invalid_version );
	}

	/**
	 * Data provider for invalid database versions.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Invalid database versions.
	 */
	public function data_invalid_db_versions(): array {
		return array(
			'zero version'     => array( '0.0.0' ),
			'negative version' => array( '-1.0.0' ),
		);
	}

	/**
	 * Tests that run() executes the upgrade routine when the database version is older.
	 *
	 * @since x.x.x
	 */
	public function test_run_executes_upgrade_when_db_version_is_older(): void {
		$upgrade = new Testable_Abstract_Upgrade( '0.9.0' );

		$result = $upgrade->run();

		$this->assertTrue( $result );
		$this->assertTrue( $upgrade->upgraded );
	}

	/**
	 * Tests that run() executes the upgrade routine when the database version is empty (initial upgrade).
	 *
	 * @since x.x.x
	 */
	public function test_run_executes_upgrade_when_db_version_is_empty(): void {
		$upgrade = new Testable_Abstract_Upgrade( '' );

		$result = $upgrade->run();

		$this->assertTrue( $result );
		$this->assertTrue( $upgrade->upgraded );
	}

	/**
	 * Tests that run() skips the upgrade routine when the database version matches the target version.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_upgrade_when_db_version_matches_target(): void {
		$upgrade = new Testable_Abstract_Upgrade( '1.0.0' );

		$result = $upgrade->run();

		$this->assertTrue( $result );
		$this->assertFalse( $upgrade->upgraded );
	}

	/**
	 * Tests that run() skips the upgrade routine when the database version is newer than the target version.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_upgrade_when_db_version_is_newer(): void {
		$upgrade = new Testable_Abstract_Upgrade( '1.1.0' );

		$result = $upgrade->run();

		$this->assertTrue( $result );
		$this->assertFalse( $upgrade->upgraded );
	}

	/**
	 * Tests that run() catches an Exception during upgrade and returns a WP_Error.
	 *
	 * @since x.x.x
	 */
	public function test_run_catches_exception_and_returns_wp_error(): void {
		$upgrade                     = new Testable_Abstract_Upgrade( '0.5.0' );
		$upgrade->throwable_to_throw = new Exception( 'Table migration failed.' );

		$result = $upgrade->run();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpai_upgrade_failed', $result->get_error_code() );
		$this->assertSame( 'Table migration failed.', $result->get_error_message() );
		$this->assertFalse( $upgrade->upgraded );
	}

	/**
	 * Tests that run() catches a Throwable (Error) during upgrade and returns a WP_Error.
	 *
	 * @since x.x.x
	 */
	public function test_run_catches_error_and_returns_wp_error(): void {
		$upgrade                     = new Testable_Abstract_Upgrade( '0.5.0' );
		$upgrade->throwable_to_throw = new Error( 'Fatal error in migration step.' );

		$result = $upgrade->run();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpai_upgrade_failed', $result->get_error_code() );
		$this->assertSame( 'Fatal error in migration step.', $result->get_error_message() );
		$this->assertFalse( $upgrade->upgraded );
	}
}
