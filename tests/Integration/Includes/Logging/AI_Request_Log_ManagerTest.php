<?php
/**
 * Integration tests for AI request logging manager.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Repository;
use WordPress\AI\Logging\AI_Request_Log_Schema;

/**
 * AI_Request_Log_Manager test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Logging\AI_Request_Log_Manager
 */
class AI_Request_Log_ManagerTest extends WP_UnitTestCase {

	/**
	 * Manager instance under test.
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

		delete_option( AI_Request_Log_Manager::OPTION_LOGGING_ENABLED );
		delete_option( AI_Request_Log_Manager::OPTION_RETENTION_DAYS );
		delete_option( AI_Request_Log_Manager::OPTION_MAX_ROWS );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		delete_option( AI_Request_Log_Manager::OPTION_LOGGING_ENABLED );
		delete_option( AI_Request_Log_Manager::OPTION_RETENTION_DAYS );
		delete_option( AI_Request_Log_Manager::OPTION_MAX_ROWS );
		delete_option( 'wpai_request_logs_schema_version' );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		parent::tearDown();
	}

	/**
	 * Tests that log returns false when logging is disabled.
	 *
	 * @since x.x.x
	 */
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

	/**
	 * Tests that log persists an entry when enabled.
	 *
	 * @since x.x.x
	 */
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
		$sql   = "SELECT operation, status, tokens_total FROM {$table} WHERE log_id = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	}

	/**
	 * Tests that context source metadata is persisted and retrievable.
	 *
	 * @since x.x.x
	 */
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

	/**
	 * Tests that search matches on request_preview content.
	 *
	 * @since x.x.x
	 */
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

	/**
	 * Tests that search falls back to LIKE for short terms.
	 *
	 * @since x.x.x
	 */
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

	/**
	 * Tests that start/end timer returns a non-negative millisecond duration.
	 *
	 * @since x.x.x
	 */
	public function test_timer_returns_non_negative_duration(): void {
		$timer    = $this->manager->start_timer();
		$duration = $this->manager->end_timer( $timer );

		$this->assertIsInt( $duration );
		$this->assertGreaterThanOrEqual( 0, $duration );
	}

	/**
	 * Tests that get_retention_days returns the default when no option is set.
	 *
	 * @since x.x.x
	 */
	public function test_get_retention_days_returns_default(): void {
		$this->assertSame( AI_Request_Log_Manager::DEFAULT_RETENTION_DAYS, $this->manager->get_retention_days() );
	}

	/**
	 * Tests that set_retention_days enforces a minimum of 1.
	 *
	 * @since x.x.x
	 */
	public function test_set_retention_days_enforces_minimum(): void {
		$this->manager->set_retention_days( 0 );
		$this->assertSame( 1, $this->manager->get_retention_days() );

		$this->manager->set_retention_days( -5 );
		$this->assertSame( 1, $this->manager->get_retention_days() );
	}

	/**
	 * Tests that get_max_rows returns the default when no option is set.
	 *
	 * @since x.x.x
	 */
	public function test_get_max_rows_returns_default(): void {
		$this->assertSame( AI_Request_Log_Manager::DEFAULT_MAX_ROWS, $this->manager->get_max_rows() );
	}

	/**
	 * Tests that set_max_rows enforces a minimum of 1000.
	 *
	 * @since x.x.x
	 */
	public function test_set_max_rows_enforces_minimum(): void {
		$this->manager->set_max_rows( 500 );
		$this->assertSame( 1000, $this->manager->get_max_rows() );
	}

	/**
	 * Tests that set_logging_enabled persists via add_option on first call.
	 *
	 * @since x.x.x
	 */
	public function test_set_logging_enabled_creates_option(): void {
		$this->manager->set_logging_enabled( false );
		$this->assertFalse( $this->manager->is_logging_enabled() );

		$this->manager->set_logging_enabled( true );
		$this->assertTrue( $this->manager->is_logging_enabled() );
	}

	/**
	 * Tests that cleanup_old_logs delegates to repository.
	 *
	 * @since x.x.x
	 */
	public function test_cleanup_old_logs_returns_count(): void {
		$this->manager->set_logging_enabled( true );
		$this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:completions',
				'status'    => 'success',
			)
		);

		// With a large retention and max_rows, nothing should be deleted.
		$deleted = $this->manager->cleanup_old_logs();
		$this->assertSame( 0, $deleted );
	}

	/**
	 * Tests that purge_all_logs returns the deleted row count.
	 *
	 * This test uses TRUNCATE internally, so it must run last
	 * in the class to avoid breaking the transaction.
	 *
	 * @since x.x.x
	 */
	public function test_purge_all_logs_returns_deleted_count(): void {
		$this->manager->set_logging_enabled( true );
		$this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:completions',
				'status'    => 'success',
			)
		);

		$deleted = $this->manager->purge_all_logs();

		$this->assertIsInt( $deleted );
	}

	/**
	 * Tests that get_summary delegates to repository.
	 *
	 * @since x.x.x
	 */
	public function test_get_summary_returns_expected_structure(): void {
		$summary = $this->manager->get_summary( 'all' );

		$this->assertArrayHasKey( 'total_requests', $summary );
		$this->assertArrayHasKey( 'total_tokens', $summary );
		$this->assertArrayHasKey( 'avg_duration_ms', $summary );
		$this->assertArrayHasKey( 'success_rate', $summary );
	}

	/**
	 * Tests that get_filter_options returns expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_get_filter_options_returns_expected_structure(): void {
		$options = $this->manager->get_filter_options();

		$this->assertArrayHasKey( 'types', $options );
		$this->assertArrayHasKey( 'providers', $options );
		$this->assertArrayHasKey( 'statuses', $options );
		$this->assertArrayHasKey( 'operations', $options );
	}

	/**
	 * Tests that accessor methods return the correct instances.
	 *
	 * @since x.x.x
	 */
	public function test_accessors_return_correct_instances(): void {
		$this->assertInstanceOf( AI_Request_Log_Schema::class, $this->manager->get_schema() );
		$this->assertInstanceOf( AI_Request_Log_Repository::class, $this->manager->get_repository() );
	}
}
