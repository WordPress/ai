<?php
/**
 * Integration tests for the public request logging helper.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Integration;

use function WordPress\AI\log_ai_request;

/**
 * log_ai_request() test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\log_ai_request
 */
class Log_Ai_RequestTest extends WP_UnitTestCase {

	/**
	 * Manager instance shared with the integration.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		// Force schema recreation in case a prior test's TRUNCATE broke the table state.
		delete_option( 'wpai_request_logs_schema_version' );

		$this->manager = new AI_Request_Log_Manager();
		$this->manager->init();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		$this->set_shared_manager( null );
		delete_option( 'wpai_request_logs_schema_version' );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		parent::tearDown();
	}

	/**
	 * Sets the manager the logging integration shares.
	 *
	 * Logging_Integration::init() is guarded against running twice, so the
	 * shared manager is set directly to model both the active and inactive
	 * states of the experiment.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager|null $manager Manager instance, or null to model a disabled experiment.
	 */
	private function set_shared_manager( ?AI_Request_Log_Manager $manager ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $manager );
	}

	/**
	 * Tests that the helper returns false when the experiment is inactive.
	 *
	 * @since x.x.x
	 */
	public function test_returns_false_when_logging_is_inactive(): void {
		$this->set_shared_manager( null );

		$this->assertFalse( log_ai_request( array( 'type' => 'mcp_tool' ) ) );
	}

	/**
	 * Tests that the helper writes a row when logging is active.
	 *
	 * @since x.x.x
	 */
	public function test_writes_entry_when_logging_is_active(): void {
		$this->set_shared_manager( $this->manager );

		$log_id = log_ai_request(
			array(
				'type'      => 'mcp_tool',
				'operation' => 'example-tool',
				'status'    => 'success',
			)
		);

		$this->assertIsString( $log_id );

		$entry = $this->manager->get_log( $log_id );

		$this->assertIsArray( $entry );
		$this->assertSame( 'mcp_tool', $entry['type'] );
		$this->assertSame( 'example-tool', $entry['operation'] );
	}

	/**
	 * Tests that the helper rejects an unsupported type.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_unsupported_type(): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Logging\AI_Request_Log_Manager::log' );
		$this->set_shared_manager( $this->manager );

		$this->assertFalse(
			log_ai_request(
				array(
					'type'      => 'not-a-real-type',
					'operation' => 'example',
					'status'    => 'success',
				)
			)
		);
	}
}
