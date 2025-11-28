<?php
/**
 * Integration tests for AI request logging manager.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;

/**
 * @covers \WordPress\AI\Logging\AI_Request_Log_Manager
 */
class AI_Request_Log_ManagerTest extends WP_UnitTestCase {
	private AI_Request_Log_Manager $manager;

	protected function setUp(): void {
		parent::setUp();

		$this->manager = new AI_Request_Log_Manager();
		$this->manager->init();

		global $wpdb;
		$table = $wpdb->prefix . 'ai_request_logs';
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( AI_Request_Log_Manager::OPTION_LOGGING_ENABLED );
	}

	public function test_log_returns_false_when_logging_disabled(): void {
		$this->manager->set_logging_enabled( false );

		$result = $this->manager->log(
			array(
				'type'      => 'ui',
				'operation' => 'completion',
				'status'    => 'success',
			)
		);

		$this->assertFalse( $result );

		global $wpdb;
		$table = $wpdb->prefix . 'ai_request_logs';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, $count );
	}

	public function test_log_persists_entry_when_enabled(): void {
		$this->manager->set_logging_enabled( true );

		$log_id = $this->manager->log(
			array(
				'type'          => 'ui',
				'operation'     => 'completion',
				'provider'      => 'openai',
				'model'         => 'gpt-5-nano',
				'duration_ms'   => 120,
				'tokens_input'  => 200,
				'tokens_output' => 50,
				'status'        => 'success',
				'user_id'       => get_current_user_id(),
				'context'       => array( 'ability' => 'ai/example' ),
			)
		);

		$this->assertNotFalse( $log_id );
		$this->assertIsString( $log_id );

		global $wpdb;
		$table = $wpdb->prefix . 'ai_request_logs';
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT operation, status, tokens_total, cost_estimate FROM {$table} WHERE log_id = %s",
				$log_id
			),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->assertSame( 'completion', $row['operation'] );
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( 250, (int) $row['tokens_total'] );
		$this->assertGreaterThan( 0, (float) $row['cost_estimate'] );
	}
}
