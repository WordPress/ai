<?php
/**
 * Integration tests for AI request logging manager.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;

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
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		delete_option( AI_Request_Log_Manager::OPTION_LOGGING_ENABLED );
		delete_option( AI_Request_Log_Manager::OPTION_RETENTION_DAYS );
		delete_option( AI_Request_Log_Manager::OPTION_MAX_ROWS );
	}

	protected function tearDown(): void {
		delete_option( AI_Request_Log_Manager::OPTION_LOGGING_ENABLED );
		delete_option( AI_Request_Log_Manager::OPTION_RETENTION_DAYS );
		delete_option( AI_Request_Log_Manager::OPTION_MAX_ROWS );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		parent::tearDown();
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
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
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
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$sql   = "SELECT operation, status, tokens_total, cost_estimate FROM {$table} WHERE log_id = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$log_id,
			),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->assertSame( 'completion', $row['operation'] );
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( 250, (int) $row['tokens_total'] );
		$this->assertGreaterThan( 0, (float) $row['cost_estimate'] );
	}

	public function test_log_persists_context_source_metadata(): void {
		$this->manager->set_logging_enabled( true );

		$log_id = $this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:responses',
				'status'    => 'success',
				'context'   => array(
					'source' => array(
						'type' => 'plugin',
						'slug' => 'ai',
						'name' => 'AI',
						'file' => 'ai/includes/Abilities/Title_Generation/Title_Generation.php',
					),
				),
			)
		);

		$log = $this->manager->get_log( (string) $log_id );

		$this->assertIsArray( $log );
		$this->assertIsArray( $log['context'] );
		$this->assertSame( 'plugin', $log['context']['source']['type'] );
		$this->assertSame( 'ai', $log['context']['source']['slug'] );
	}

	public function test_get_logs_search_matches_request_preview(): void {
		$this->manager->set_logging_enabled( true );

		$matching_id = $this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:images/generations',
				'status'    => 'success',
				'context'   => array(
					'input_preview' => 'Prompt: A llama sitting on a mountain',
				),
			)
		);

		// Non matching entry to ensure search filters correctly.
		$this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:images/generations',
				'status'    => 'success',
				'context'   => array(
					'input_preview' => 'Prompt: Sunset over the ocean',
				),
			)
		);

		$result = $this->manager->get_logs(
			array(
				'search' => 'llama',
			)
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $matching_id, $result['items'][0]['id'] );
	}

	public function test_get_logs_search_falls_back_for_short_terms(): void {
		$this->manager->set_logging_enabled( true );

		$matching_id = $this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:responses',
				'status'    => 'success',
				'context'   => array(
					'input_preview' => 'AI prompt about colors',
				),
			)
		);

		$result = $this->manager->get_logs(
			array(
				'search' => 'AI',
			)
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $matching_id, $result['items'][0]['id'] );
	}
}
