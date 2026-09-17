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
use WordPress\AI\Logging\REST\AI_Request_Log_Controller;

/**
 * AI_Request_Log_Manager test case.
 *
 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 */
	protected function tearDown(): void {
		delete_option( 'wpai_request_logs_schema_version' );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );
		remove_all_filters( 'wpai_request_log_retention_days' );

		parent::tearDown();
	}

	/**
	 * Tests that log persists an entry.
	 *
	 * @since 1.0.0
	 */
	public function test_log_persists_entry(): void {
		$log_id = $this->manager->log(
			array(
				'type'          => 'ai_client',
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
	 * @since 1.0.0
	 */
	public function test_log_persists_context_source_metadata(): void {
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
	 * @since 1.0.0
	 */
	public function test_get_logs_search_matches_request_preview(): void {
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
	 * @since 1.0.0
	 */
	public function test_get_logs_search_falls_back_for_short_terms(): void {
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
	 * @since 1.0.0
	 */
	public function test_timer_returns_non_negative_duration(): void {
		$timer    = $this->manager->start_timer();
		$duration = $this->manager->end_timer( $timer );

		$this->assertIsInt( $duration );
		$this->assertGreaterThanOrEqual( 0, $duration );
	}

	/**
	 * Tests that get_retention_days returns 0 (forever) by default.
	 *
	 * @since 1.0.0
	 */
	public function test_get_retention_days_defaults_to_forever(): void {
		$this->assertSame( 0, $this->manager->get_retention_days() );
	}

	/**
	 * Tests that get_retention_days can be overridden via the wpai_request_log_retention_days filter.
	 *
	 * @since 1.0.0
	 */
	public function test_get_retention_days_is_filterable(): void {
		add_filter(
			'wpai_request_log_retention_days',
			static function () {
				return 14;
			}
		);

		$this->assertSame( 14, $this->manager->get_retention_days() );
	}

	/**
	 * Tests that cleanup_old_logs delegates to repository.
	 *
	 * @since 1.0.0
	 */
	public function test_cleanup_old_logs_returns_count(): void {
		$this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:completions',
				'status'    => 'success',
			)
		);

		// Default retention is 0 (forever), so nothing should be deleted.
		$deleted = $this->manager->cleanup_old_logs();
		$this->assertSame( 0, $deleted );
	}

	/**
	 * Tests that purge_all_logs returns the deleted row count.
	 *
	 * This test uses TRUNCATE internally, so it must run last
	 * in the class to avoid breaking the transaction.
	 *
	 * @since 1.0.0
	 */
	public function test_purge_all_logs_returns_deleted_count(): void {
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 */
	public function test_accessors_return_correct_instances(): void {
		$this->assertInstanceOf( AI_Request_Log_Schema::class, $this->manager->get_schema() );
		$this->assertInstanceOf( AI_Request_Log_Repository::class, $this->manager->get_repository() );
	}

	/**
	 * Tests that every supported type can be recorded.
	 *
	 * @since 1.3.0
	 */
	public function test_log_accepts_every_supported_type(): void {
		foreach ( AI_Request_Log_Manager::get_types() as $type ) {
			$log_id = $this->manager->log(
				array(
					'type'      => $type,
					'operation' => 'example',
					'status'    => 'success',
				)
			);

			$this->assertIsString( $log_id, "Type \"{$type}\" should be accepted." );
		}
	}

	/**
	 * Tests that an unsupported type is rejected rather than written.
	 *
	 * A row carrying a type outside the supported set can never be filtered
	 * through the REST API, so it is refused at the point of writing.
	 *
	 * @since 1.3.0
	 */
	public function test_log_rejects_unsupported_type(): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Logging\AI_Request_Log_Manager::log' );

		$log_id = $this->manager->log(
			array(
				'type'      => 'not-a-real-type',
				'operation' => 'example',
				'status'    => 'success',
			)
		);

		$this->assertFalse( $log_id );
	}

	/**
	 * Tests that a missing type is rejected.
	 *
	 * @since 1.3.0
	 */
	public function test_log_rejects_missing_type(): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Logging\AI_Request_Log_Manager::log' );

		$this->assertFalse(
			$this->manager->log(
				array(
					'operation' => 'example',
					'status'    => 'success',
				)
			)
		);
	}

	/**
	 * Tests that a missing required field is rejected.
	 *
	 * @since 1.3.0
	 *
	 * @dataProvider data_incomplete_log_entries
	 *
	 * @param array<string, mixed> $data Log data missing a required field.
	 */
	public function test_log_rejects_missing_required_field( array $data ): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Logging\AI_Request_Log_Manager::log' );

		$this->assertFalse( $this->manager->log( $data ) );
	}

	/**
	 * Data provider for log entries missing a required field.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, array{array<string, mixed>}> Test data.
	 */
	public function data_incomplete_log_entries(): array {
		return array(
			'missing operation' => array(
				array(
					'type'   => 'mcp_tool',
					'status' => 'success',
				),
			),
			'empty operation'   => array(
				array(
					'type'      => 'mcp_tool',
					'operation' => '',
					'status'    => 'success',
				),
			),
			'missing status'    => array(
				array(
					'type'      => 'mcp_tool',
					'operation' => 'example',
				),
			),
			'empty status'      => array(
				array(
					'type'      => 'mcp_tool',
					'operation' => 'example',
					'status'    => '',
				),
			),
		);
	}

	/**
	 * Tests that the write action fires with the log identifier and stored row.
	 *
	 * @since 1.3.0
	 */
	public function test_log_fires_action_on_write(): void {
		$captured = array();

		// Removed by callback rather than with remove_all_actions(), since
		// wpai_request_logged is a public hook other code may listen on.
		$listener = static function ( $log_id, $data ) use ( &$captured ) {
			$captured[] = array( $log_id, $data );
		};

		add_action( 'wpai_request_logged', $listener, 10, 2 );

		$log_id = $this->manager->log(
			array(
				'type'      => 'mcp_tool',
				'operation' => 'example',
				'status'    => 'success',
			)
		);

		remove_action( 'wpai_request_logged', $listener, 10 );

		$this->assertCount( 1, $captured );
		$this->assertSame( $log_id, $captured[0][0] );
		$this->assertSame( 'mcp_tool', $captured[0][1]['type'] );
	}

	/**
	 * Tests that the action does not fire when the entry is rejected.
	 *
	 * @since 1.3.0
	 */
	public function test_log_does_not_fire_action_when_rejected(): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Logging\AI_Request_Log_Manager::log' );

		$fired = false;

		$listener = static function () use ( &$fired ) {
			$fired = true;
		};

		add_action( 'wpai_request_logged', $listener );

		$this->manager->log(
			array(
				'type'      => 'nope',
				'operation' => 'example',
				'status'    => 'success',
			)
		);

		remove_action( 'wpai_request_logged', $listener );

		$this->assertFalse( $fired );
	}

	/**
	 * Tests that the supported types cover the values the REST API advertises.
	 *
	 * @since 1.3.0
	 */
	public function test_get_types_matches_rest_collection_enum(): void {
		$controller = new AI_Request_Log_Controller( $this->manager );
		$params     = $controller->get_collection_params();

		$this->assertSame(
			array_merge( array( '' ), AI_Request_Log_Manager::get_types() ),
			$params['type']['enum']
		);
	}
}
