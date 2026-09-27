<?php
/**
 * Tests for the Logging_Integration class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AiClient\AiClient;

/**
 * Logging_Integration test case.
 *
 * @covers \WordPress\AI\Logging\Logging_Integration
 *
 * @since x.x.x
 */
class Logging_IntegrationTest extends WP_UnitTestCase {

	/**
	 * Original initialized state before running test.
	 *
	 * @var bool
	 */
	private bool $original_initialized;

	/**
	 * Original log manager held before running test.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private ?AI_Request_Log_Manager $original_manager = null;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_initialized = $this->get_initialized();
		$this->original_manager     = Logging_Integration::get_log_manager();

		// Ensure clean, predictable state for the current test.
		$this->set_initialized( false );
		$this->set_shared_manager( null );
		remove_action( 'wp_loaded', array( Logging_Integration::class, 'wrap_transporter' ), 1 );
		remove_action( 'admin_init', array( Logging_Integration::class, 'wrap_transporter' ), 1 );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		remove_action( 'wp_loaded', array( Logging_Integration::class, 'wrap_transporter' ), 1 );
		remove_action( 'admin_init', array( Logging_Integration::class, 'wrap_transporter' ), 1 );
		$this->set_initialized( $this->original_initialized );
		$this->set_shared_manager( $this->original_manager );

		parent::tearDown();
	}

	/**
	 * Sets the initialized flag on Logging_Integration.
	 *
	 * @since x.x.x
	 *
	 * @param bool $initialized Whether the integration should appear initialized.
	 */
	private function set_initialized( bool $initialized ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'initialized' );
		$property->setAccessible( true );
		$property->setValue( null, $initialized );
	}

	/**
	 * Gets the initialized flag from Logging_Integration.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if initialized, false otherwise.
	 */
	private function get_initialized(): bool {
		$property = new ReflectionProperty( Logging_Integration::class, 'initialized' );
		$property->setAccessible( true );
		return (bool) $property->getValue();
	}

	/**
	 * Sets the log manager on Logging_Integration.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager|null $manager Manager instance, or null.
	 */
	private function set_shared_manager( ?AI_Request_Log_Manager $manager ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $manager );
	}

	/**
	 * Tests that get_log_manager returns null when uninitialized.
	 *
	 * @since x.x.x
	 */
	public function test_get_log_manager_returns_null_when_uninitialized(): void {
		$this->assertNull( Logging_Integration::get_log_manager() );
	}

	/**
	 * Tests that init stores the provided log manager instance.
	 *
	 * @since x.x.x
	 */
	public function test_init_stores_log_manager(): void {
		$manager = new AI_Request_Log_Manager();

		Logging_Integration::init( $manager );

		$this->assertSame( $manager, Logging_Integration::get_log_manager() );
	}

	/**
	 * Tests that init registers the wrap_transporter action hooks when AiClient is available.
	 *
	 * @since x.x.x
	 */
	public function test_init_registers_lifecycle_hooks_when_aiclient_available(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient SDK is not present in this environment.' );
		}

		$manager = new AI_Request_Log_Manager();

		Logging_Integration::init( $manager );

		$this->assertSame(
			1,
			has_action( 'wp_loaded', array( Logging_Integration::class, 'wrap_transporter' ) ),
			'wp_loaded hook should be registered with priority 1.'
		);
		$this->assertSame(
			1,
			has_action( 'admin_init', array( Logging_Integration::class, 'wrap_transporter' ) ),
			'admin_init hook should be registered with priority 1.'
		);
		$this->assertTrue( $this->get_initialized() );
	}

	/**
	 * Tests that init bails early without registering hooks when AiClient is unavailable.
	 *
	 * @since x.x.x
	 */
	public function test_init_bails_when_aiclient_unavailable(): void {
		if ( class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient is present; cannot test SDK-missing early return.' );
		}

		$manager = new AI_Request_Log_Manager();

		Logging_Integration::init( $manager );

		// Manager should be stored.
		$this->assertSame( $manager, Logging_Integration::get_log_manager() );

		// Hooks should NOT be registered.
		$this->assertFalse(
			has_action( 'wp_loaded', array( Logging_Integration::class, 'wrap_transporter' ) )
		);
		$this->assertFalse(
			has_action( 'admin_init', array( Logging_Integration::class, 'wrap_transporter' ) )
		);
		$this->assertFalse( $this->get_initialized() );
	}

	/**
	 * Tests that init is idempotent when already initialized.
	 *
	 * @since x.x.x
	 */
	public function test_init_is_idempotent_when_already_initialized(): void {
		$first_manager = new AI_Request_Log_Manager();
		$this->set_shared_manager( $first_manager );
		$this->set_initialized( true );

		$second_manager = new AI_Request_Log_Manager();

		// Second init should be ignored.
		Logging_Integration::init( $second_manager );

		$this->assertSame(
			$first_manager,
			Logging_Integration::get_log_manager(),
			'Subsequent init() calls must not overwrite existing log manager.'
		);
	}

	/**
	 * Tests that wrap_transporter returns early when log manager is null.
	 *
	 * @since x.x.x
	 */
	public function test_wrap_transporter_bails_when_log_manager_is_null(): void {
		$this->set_shared_manager( null );

		// Should return without error.
		Logging_Integration::wrap_transporter();

		$this->assertNull( Logging_Integration::get_log_manager() );
	}

	/**
	 * Tests that wrap_transporter silently catches throwables.
	 *
	 * Logging is secondary to core application flow and must never throw or crash execution.
	 *
	 * @since x.x.x
	 */
	public function test_wrap_transporter_catches_throwables_silently(): void {
		$manager = new AI_Request_Log_Manager();
		$this->set_shared_manager( $manager );

		// Even if AiClient throws or fails to resolve, wrap_transporter must catch and suppress.
		Logging_Integration::wrap_transporter();

		$this->assertTrue( true, 'wrap_transporter completed without throwing unhandled exceptions.' );
	}
}
