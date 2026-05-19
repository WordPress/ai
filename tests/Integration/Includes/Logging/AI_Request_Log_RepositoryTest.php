<?php
/**
 * Integration tests for AI request log repository.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Repository;
use WordPress\AI\Logging\AI_Request_Log_Schema;

/**
 * AI_Request_Log_Repository test case.
 *
 * @since 1.0.0
 *
 * @covers \WordPress\AI\Logging\AI_Request_Log_Repository
 */
class AI_Request_Log_RepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository instance under test.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Repository
	 */
	private AI_Request_Log_Repository $repository;

	/**
	 * Schema instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Schema
	 */
	private AI_Request_Log_Schema $schema;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	protected function setUp(): void {
		parent::setUp();

		// Force schema recreation in case a prior test's TRUNCATE broke the table state.
		delete_option( 'wpai_request_logs_schema_version' );

		$this->schema = new AI_Request_Log_Schema();
		$this->schema->maybe_upgrade_table();

		$this->repository = new AI_Request_Log_Repository( $this->schema );

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
		$this->repository->invalidate_caches();
		parent::tearDown();
	}

	/**
	 * Inserts a sample log entry and returns its ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $overrides Optional field overrides.
	 * @return string The log ID.
	 */
	private function insert_log( array $overrides = array() ): string {
		$defaults = array(
			'type'          => 'ai_client',
			'operation'     => 'openai:completions',
			'provider'      => 'openai',
			'model'         => 'gpt-4o',
			'duration_ms'   => 150,
			'tokens_input'  => 100,
			'tokens_output' => 50,
			'status'        => 'success',
			'user_id'       => get_current_user_id(),
			'context'       => array(
				'input_preview'  => 'Test input prompt',
				'output_preview' => 'Test output response',
			),
		);

		$log_id = $this->repository->insert( array_merge( $defaults, $overrides ) );
		$this->assertIsString( $log_id );

		return $log_id;
	}

	/**
	 * Tests that insert returns a UUID string.
	 *
	 * @since 1.0.0
	 */
	public function test_insert_returns_uuid_string(): void {
		$log_id = $this->insert_log();

		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
			$log_id
		);
	}

	/**
	 * Tests that insert calculates token totals.
	 *
	 * @since 1.0.0
	 */
	public function test_insert_calculates_tokens_total(): void {
		$log_id = $this->insert_log(
			array(
				'tokens_input'  => 200,
				'tokens_output' => 100,
			)
		);

		$log = $this->repository->find( $log_id );

		$this->assertSame( 300, $log['tokens_total'] );
	}

	/**
	 * Tests that insert stores request and response previews from context.
	 *
	 * @since 1.0.0
	 */
	public function test_insert_stores_previews_from_context(): void {
		$log_id = $this->insert_log(
			array(
				'context' => array(
					'input_preview'  => 'The request preview text',
					'output_preview' => 'The response preview text',
				),
			)
		);

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT request_preview, response_preview FROM {$table} WHERE log_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$log_id
			),
			ARRAY_A
		);

		$this->assertSame( 'The request preview text', $row['request_preview'] );
		$this->assertSame( 'The response preview text', $row['response_preview'] );
	}

	/**
	 * Tests that the wpai_request_logged action fires after insert.
	 *
	 * @since 1.0.0
	 */
	public function test_insert_fires_action_hook(): void {
		$hook_fired = false;

		add_action(
			'wpai_request_logged',
			static function () use ( &$hook_fired ): void {
				$hook_fired = true;
			}
		);

		$this->insert_log();

		$this->assertTrue( $hook_fired );

		remove_all_actions( 'wpai_request_logged' );
	}

	/**
	 * Tests that find returns a formatted log entry.
	 *
	 * @since 1.0.0
	 */
	public function test_find_returns_formatted_log_entry(): void {
		$log_id = $this->insert_log();
		$log    = $this->repository->find( $log_id );

		$this->assertIsArray( $log );
		$this->assertSame( $log_id, $log['id'] );
		$this->assertArrayHasKey( 'timestamp', $log );
		$this->assertArrayHasKey( 'type', $log );
		$this->assertArrayHasKey( 'operation', $log );
		$this->assertArrayHasKey( 'provider', $log );
		$this->assertArrayHasKey( 'tokens_per_second', $log );
	}

	/**
	 * Tests that find returns null for a nonexistent ID.
	 *
	 * @since 1.0.0
	 */
	public function test_find_returns_null_for_nonexistent_id(): void {
		$this->assertNull( $this->repository->find( '00000000-0000-0000-0000-000000000000' ) );
	}

	/**
	 * Tests that find calculates tokens_per_second correctly.
	 *
	 * @since 1.0.0
	 */
	public function test_find_calculates_tokens_per_second(): void {
		$log_id = $this->insert_log(
			array(
				'tokens_input'  => 500,
				'tokens_output' => 500,
				'duration_ms'   => 2000,
			)
		);

		$log = $this->repository->find( $log_id );

		// 1000 tokens / 2 seconds = 500 tokens/sec.
		$this->assertNotNull( $log['tokens_per_second'] );
		$this->assertEqualsWithDelta( 500.0, $log['tokens_per_second'], 0.1 );
	}

	/**
	 * Tests that query returns all logs when no filters are applied.
	 *
	 * @since 1.0.0
	 */
	public function test_query_returns_all_logs(): void {
		$this->insert_log();
		$this->insert_log();

		$result = $this->repository->query();

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
	}

	/**
	 * Tests filtering by type.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_type(): void {
		$this->insert_log( array( 'type' => 'ai_client' ) );
		$this->insert_log( array( 'type' => 'ability' ) );

		$result = $this->repository->query( array( 'type' => 'ai_client' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'ai_client', $result['items'][0]['type'] );
	}

	/**
	 * Tests filtering by status.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_status(): void {
		$this->insert_log( array( 'status' => 'success' ) );
		$this->insert_log( array( 'status' => 'error', 'error_message' => 'timeout' ) );

		$result = $this->repository->query( array( 'status' => 'error' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'error', $result['items'][0]['status'] );
	}

	/**
	 * Tests filtering by provider.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_provider(): void {
		$this->insert_log( array( 'provider' => 'openai' ) );
		$this->insert_log( array( 'provider' => 'anthropic' ) );

		$result = $this->repository->query( array( 'provider' => 'anthropic' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'anthropic', $result['items'][0]['provider'] );
	}

	/**
	 * Tests filtering by a single operation.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_operation(): void {
		$this->insert_log( array( 'operation' => 'openai:completions' ) );
		$this->insert_log( array( 'operation' => 'openai:images/generations' ) );

		$result = $this->repository->query( array( 'operation' => 'openai:completions' ) );

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Tests filtering by multiple comma-separated operations.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_multiple_operations(): void {
		$this->insert_log( array( 'operation' => 'openai:completions' ) );
		$this->insert_log( array( 'operation' => 'openai:images/generations' ) );
		$this->insert_log( array( 'operation' => 'anthropic:messages' ) );

		$result = $this->repository->query( array( 'operation' => 'openai:completions,anthropic:messages' ) );

		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * Tests filtering by user_id.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_user_id(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );

		$this->insert_log( array( 'user_id' => $user_id ) );
		$this->insert_log( array( 'user_id' => 0 ) );

		$result = $this->repository->query( array( 'user_id' => $user_id ) );

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Tests filtering by tokens_filter 'none' for entries without tokens.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_tokens_filter_none(): void {
		$this->insert_log( array( 'tokens_input' => 100, 'tokens_output' => 50 ) );
		$this->insert_log( array( 'tokens_input' => 0, 'tokens_output' => 0 ) );

		$result = $this->repository->query( array( 'tokens_filter' => 'none' ) );

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Tests filtering by tokens_filter 'gt:N' format.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_tokens_filter_gt_prefix(): void {
		$this->insert_log( array( 'tokens_input' => 50, 'tokens_output' => 50 ) );    // total=100
		$this->insert_log( array( 'tokens_input' => 1000, 'tokens_output' => 500 ) ); // total=1500

		$result = $this->repository->query( array( 'tokens_filter' => 'gt:500' ) );

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Tests filtering by date range.
	 *
	 * @since 1.0.0
	 */
	public function test_query_filters_by_date_range(): void {
		$this->insert_log();

		$result = $this->repository->query(
			array(
				'date_from' => gmdate( 'Y-m-d 00:00:00' ),
				'date_to'   => gmdate( 'Y-m-d 23:59:59' ),
			)
		);

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Tests LIKE-based search fallback (fulltext is disabled in transactions).
	 *
	 * @since 1.0.0
	 */
	public function test_query_search_matches_operation(): void {
		$this->insert_log( array( 'operation' => 'openai:completions' ) );
		$this->insert_log( array( 'operation' => 'anthropic:messages' ) );

		$result = $this->repository->query( array( 'search' => 'completions' ) );

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Tests offset-based pagination.
	 *
	 * @since 1.0.0
	 */
	public function test_query_paginates_with_offset(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_log();
		}

		$result = $this->repository->query(
			array(
				'per_page' => 2,
				'page'     => 1,
			)
		);

		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 3, $result['pages'] );
	}

	/**
	 * Tests that ordering by a column works.
	 *
	 * @since 1.0.0
	 */
	public function test_query_orders_by_duration(): void {
		$this->insert_log( array( 'duration_ms' => 50 ) );
		$this->insert_log( array( 'duration_ms' => 200 ) );
		$this->insert_log( array( 'duration_ms' => 100 ) );

		$result = $this->repository->query(
			array(
				'orderby' => 'duration_ms',
				'order'   => 'ASC',
			)
		);

		$this->assertSame( 50, $result['items'][0]['duration_ms'] );
		$this->assertSame( 200, $result['items'][2]['duration_ms'] );
	}

	/**
	 * Tests that the result includes a next_cursor when rows exist.
	 *
	 * @since 1.0.0
	 */
	public function test_query_includes_next_cursor(): void {
		$this->insert_log();

		$result = $this->repository->query();

		$this->assertArrayHasKey( 'next_cursor', $result );
		$this->assertArrayHasKey( 'id', $result['next_cursor'] );
		$this->assertArrayHasKey( 'timestamp', $result['next_cursor'] );
	}

	/**
	 * Tests that an invalid orderby column falls back to timestamp.
	 *
	 * @since 1.0.0
	 */
	public function test_query_invalid_orderby_falls_back_to_timestamp(): void {
		$this->insert_log();

		$result = $this->repository->query( array( 'orderby' => 'invalid_column' ) );

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Tests that get_summary returns aggregated statistics.
	 *
	 * @since 1.0.0
	 */
	public function test_get_summary_returns_aggregated_stats(): void {
		$this->insert_log(
			array(
				'provider'      => 'openai',
				'tokens_input'  => 100,
				'tokens_output' => 50,
				'status'        => 'success',
			)
		);
		$this->insert_log(
			array(
				'provider'      => 'anthropic',
				'tokens_input'  => 200,
				'tokens_output' => 100,
				'status'        => 'error',
				'error_message' => 'rate limited',
			)
		);

		$summary = $this->repository->get_summary( 'all', true );

		$this->assertSame( 2, $summary['total_requests'] );
		$this->assertGreaterThan( 0, $summary['total_tokens'] );
		$this->assertArrayHasKey( 'by_type', $summary );
		$this->assertArrayHasKey( 'by_provider', $summary );
		$this->assertArrayHasKey( 'by_status', $summary );
		$this->assertArrayHasKey( 'success', $summary['by_status'] );
		$this->assertArrayHasKey( 'error', $summary['by_status'] );
	}

	/**
	 * Tests that get_summary caches results in transients.
	 *
	 * @since 1.0.0
	 */
	public function test_get_summary_caches_result(): void {
		$this->insert_log();

		// First call populates the cache.
		$first = $this->repository->get_summary( 'all', true );

		// Manually set the transient to a known value to verify the cache is used.
		$cached         = $first;
		$cached['test'] = 'cached_marker';
		set_transient( 'wpai_request_logs_summary_all', $cached, 300 );

		// Second call should return the cached result.
		$second = $this->repository->get_summary( 'all', false );

		$this->assertSame( 'cached_marker', $second['test'] );
	}

	/**
	 * Tests that get_summary returns zeroes for an empty table.
	 *
	 * @since 1.0.0
	 */
	public function test_get_summary_empty_table(): void {
		$summary = $this->repository->get_summary( 'all', true );

		$this->assertSame( 0, $summary['total_requests'] );
		$this->assertSame( 0, $summary['total_tokens'] );
	}

	/**
	 * Tests that get_filter_options returns distinct values from logs.
	 *
	 * @since 1.0.0
	 */
	public function test_get_filter_options_returns_distinct_values(): void {
		$this->insert_log( array( 'provider' => 'openai', 'status' => 'success' ) );
		$this->insert_log( array( 'provider' => 'anthropic', 'status' => 'error', 'error_message' => 'fail' ) );

		$options = $this->repository->get_filter_options( true );

		$this->assertContains( 'openai', $options['providers'] );
		$this->assertContains( 'anthropic', $options['providers'] );
		$this->assertContains( 'success', $options['statuses'] );
		$this->assertContains( 'error', $options['statuses'] );
	}

	/**
	 * Tests that filter options are sorted alphabetically.
	 *
	 * @since 1.0.0
	 */
	public function test_get_filter_options_sorted_alphabetically(): void {
		$this->insert_log( array( 'provider' => 'openai' ) );
		$this->insert_log( array( 'provider' => 'anthropic' ) );

		$options = $this->repository->get_filter_options( true );

		$this->assertSame( 'anthropic', $options['providers'][0] );
		$this->assertSame( 'openai', $options['providers'][1] );
	}

	/**
	 * Tests that cleanup_by_retention deletes old logs.
	 *
	 * @since 1.0.0
	 */
	public function test_cleanup_by_retention_deletes_old_logs(): void {
		$this->insert_log();

		// Insert an artificially old log directly.
		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'log_id'    => wp_generate_uuid4(),
				'timestamp' => gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) ),
				'type'      => 'ai_client',
				'operation' => 'openai:completions',
				'status'    => 'success',
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		$deleted = $this->repository->cleanup_by_retention( 30 );

		$this->assertSame( 1, $deleted );

		// The recent log should still exist.
		$remaining = $this->repository->query();
		$this->assertSame( 1, $remaining['total'] );
	}

	/**
	 * Tests that purge_all returns the row count and empties the table.
	 *
	 * This test must run last in the class because purge_all() uses
	 * TRUNCATE TABLE which auto-commits the transaction.
	 *
	 * @since 1.0.0
	 */
	public function test_purge_all_returns_count(): void {
		$this->insert_log();
		$this->insert_log();
		$this->insert_log();

		$deleted = $this->repository->purge_all();

		$this->assertSame( 3, $deleted );
	}

	/**
	 * Tests that invalidate_summary_cache clears cached summaries.
	 *
	 * @since 1.0.0
	 */
	public function test_invalidate_summary_cache_clears_transients(): void {
		$this->insert_log();
		$this->repository->get_summary( 'all', true );

		$this->repository->invalidate_summary_cache();

		// After invalidation, transient should be gone.
		$this->assertFalse( get_transient( 'wpai_request_logs_summary_all' ) );
	}

	/**
	 * Tests that invalidate_filter_cache clears the filter options transient.
	 *
	 * @since 1.0.0
	 */
	public function test_invalidate_filter_cache_clears_transient(): void {
		$this->insert_log();
		$this->repository->get_filter_options( true );

		$this->repository->invalidate_filter_cache();

		$this->assertFalse( get_transient( 'wpai_request_logs_filter_options' ) );
	}
}
