<?php
/**
 * Integration tests for the AI request log personal data exporter and eraser.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Privacy
 */

namespace WordPress\AI\Tests\Integration\Includes\Privacy;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Repository;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Privacy\Request_Log_Personal_Data;

/**
 * Request_Log_Personal_Data test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Privacy\Request_Log_Personal_Data
 * @covers \WordPress\AI\Logging\AI_Request_Log_Repository::get_by_user
 * @covers \WordPress\AI\Logging\AI_Request_Log_Repository::count_by_user
 * @covers \WordPress\AI\Logging\AI_Request_Log_Repository::delete_by_user
 */
class Request_Log_Personal_DataTest extends WP_UnitTestCase {

	/**
	 * Schema instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Schema
	 */
	private AI_Request_Log_Schema $schema;

	/**
	 * Repository instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Repository
	 */
	private AI_Request_Log_Repository $repository;

	/**
	 * Instance under test.
	 *
	 * @var \WordPress\AI\Privacy\Request_Log_Personal_Data
	 */
	private Request_Log_Personal_Data $personal_data;

	/**
	 * Filter that makes matching queries fail, if one is active.
	 *
	 * @var callable|null
	 */
	private $failing_query_filter = null;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		// Force schema recreation in case a prior test's TRUNCATE broke the table state.
		delete_option( 'wpai_request_logs_schema_version' );

		$this->schema = new AI_Request_Log_Schema();
		$this->schema->maybe_upgrade_table();

		$this->repository    = new AI_Request_Log_Repository( $this->schema );
		$this->personal_data = new Request_Log_Personal_Data( $this->schema, $this->repository );

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
		global $wpdb;

		if ( null !== $this->failing_query_filter ) {
			remove_filter( 'query', $this->failing_query_filter );
			$this->failing_query_filter = null;
		}
		$wpdb->suppress_errors( false );

		$this->repository->invalidate_caches();
		parent::tearDown();
	}

	/**
	 * Makes the queries that match a pattern fail, as a database error would.
	 *
	 * @since x.x.x
	 *
	 * @param string $pattern Regular expression matched against each query.
	 */
	private function fail_queries_matching( string $pattern ): void {
		global $wpdb;

		$wpdb->suppress_errors( true );

		$this->failing_query_filter = static function ( $query ) use ( $pattern ) {
			return preg_match( $pattern, (string) $query ) ? 'SELECT * FROM wpai_table_that_does_not_exist' : $query;
		};

		add_filter( 'query', $this->failing_query_filter );
	}

	/**
	 * Inserts log rows for a user.
	 *
	 * @since x.x.x
	 *
	 * @param int    $user_id User ID, or 0 for a request made without a logged-in user.
	 * @param int    $count   Number of rows to insert.
	 * @param string $label   Label written into the previews.
	 */
	private function insert_logs( int $user_id, int $count, string $label ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->repository->insert(
				array(
					'type'          => 'ai_client',
					'operation'     => 'openai:completions',
					'provider'      => 'openai',
					'model'         => 'gpt-4o',
					'tokens_input'  => 10,
					'tokens_output' => 5,
					'status'        => 'success',
					'user_id'       => $user_id,
					'context'       => array(
						'input_preview'  => "{$label} request {$i}",
						'output_preview' => "{$label} response {$i}",
					),
				)
			);
		}
	}

	/**
	 * Returns an export field value by name.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item  Export item.
	 * @param string               $name  Field name.
	 * @return string|null The value, or null if the field is absent.
	 */
	private function get_field( array $item, string $name ): ?string {
		foreach ( $item['data'] as $field ) {
			if ( $name === $field['name'] ) {
				return $field['value'];
			}
		}

		return null;
	}

	/**
	 * Tests that init registers the exporter and eraser while the log table exists.
	 *
	 * @since x.x.x
	 */
	public function test_registers_exporter_and_eraser_when_table_exists(): void {
		$this->personal_data->init();

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->assertArrayHasKey( Request_Log_Personal_Data::ID, $exporters );
		$this->assertArrayHasKey( Request_Log_Personal_Data::ID, $erasers );
		$this->assertIsCallable( $exporters[ Request_Log_Personal_Data::ID ]['callback'] );
		$this->assertIsCallable( $erasers[ Request_Log_Personal_Data::ID ]['callback'] );
	}

	/**
	 * Tests that the plugin registers the exporter and eraser on load.
	 *
	 * @since x.x.x
	 */
	public function test_plugin_registers_exporter_and_eraser(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->assertArrayHasKey( Request_Log_Personal_Data::ID, $exporters );
		$this->assertArrayHasKey( Request_Log_Personal_Data::ID, $erasers );
	}

	/**
	 * Tests that nothing is registered when the log table does not exist.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_when_table_missing(): void {
		$schema = $this->createMock( AI_Request_Log_Schema::class );
		$schema->method( 'table_exists' )->willReturn( false );

		$personal_data = new Request_Log_Personal_Data( $schema, $this->repository );

		$this->assertSame( array(), $personal_data->register_exporter( array() ) );
		$this->assertSame( array(), $personal_data->register_eraser( array() ) );
	}

	/**
	 * Tests that export pages through only the requesting user's rows.
	 *
	 * @since x.x.x
	 */
	public function test_export_paginates_only_requesting_users_rows(): void {
		$user_a = self::factory()->user->create( array( 'user_email' => 'a@example.com' ) );
		$user_b = self::factory()->user->create( array( 'user_email' => 'b@example.com' ) );

		$this->insert_logs( $user_a, Request_Log_Personal_Data::EXPORT_PER_PAGE + 5, 'user-a' );
		$this->insert_logs( $user_b, 3, 'user-b' );
		$this->insert_logs( 0, 2, 'guest' );

		$page_one = $this->personal_data->export( 'a@example.com', 1 );
		$page_two = $this->personal_data->export( 'a@example.com', 2 );

		$this->assertCount( Request_Log_Personal_Data::EXPORT_PER_PAGE, $page_one['data'] );
		$this->assertFalse( $page_one['done'] );
		$this->assertCount( 5, $page_two['data'] );
		$this->assertTrue( $page_two['done'] );

		$items    = array_merge( $page_one['data'], $page_two['data'] );
		$item_ids = array_unique( wp_list_pluck( $items, 'item_id' ) );
		$this->assertCount( Request_Log_Personal_Data::EXPORT_PER_PAGE + 5, $item_ids, 'Pages should not overlap.' );

		foreach ( $items as $item ) {
			$this->assertSame( Request_Log_Personal_Data::ID, $item['group_id'] );
			$this->assertStringStartsWith( 'user-a request ', (string) $this->get_field( $item, 'Request preview' ) );
			$this->assertStringStartsWith( 'user-a response ', (string) $this->get_field( $item, 'Response preview' ) );
		}

		$first = $page_one['data'][0];
		$this->assertSame( 'user-a request 0', $this->get_field( $first, 'Request preview' ) );
		$this->assertSame( 'openai:completions', $this->get_field( $first, 'Operation' ) );
		$this->assertSame( 'openai', $this->get_field( $first, 'Provider' ) );
		$this->assertSame( 'gpt-4o', $this->get_field( $first, 'Model' ) );
		$this->assertSame( 'success', $this->get_field( $first, 'Status' ) );
		$this->assertSame( '10', $this->get_field( $first, 'Input tokens' ) );
		$this->assertSame( '5', $this->get_field( $first, 'Output tokens' ) );
		$this->assertSame( '15', $this->get_field( $first, 'Total tokens' ) );
		$this->assertNotEmpty( $this->get_field( $first, 'Date (UTC)' ) );
	}

	/**
	 * Tests that the eraser removes only the requesting user's rows.
	 *
	 * @since x.x.x
	 */
	public function test_erase_removes_only_requesting_users_rows(): void {
		$user_a = self::factory()->user->create( array( 'user_email' => 'a@example.com' ) );
		$user_b = self::factory()->user->create( array( 'user_email' => 'b@example.com' ) );

		$this->insert_logs( $user_a, 4, 'user-a' );
		$this->insert_logs( $user_b, 3, 'user-b' );
		$this->insert_logs( 0, 2, 'guest' );

		$result = $this->personal_data->erase( 'a@example.com', 1 );

		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$result
		);

		$this->assertSame( 0, $this->repository->count_by_user( $user_a ) );
		$this->assertSame( 3, $this->repository->count_by_user( $user_b ) );

		global $wpdb;
		$table  = $this->schema->get_table_name();
		$guests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_id = 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 2, $guests, 'Rows without a user should be left alone.' );

		// A second pass finds nothing left to remove.
		$again = $this->personal_data->erase( 'a@example.com', 1 );
		$this->assertFalse( $again['items_removed'] );
		$this->assertTrue( $again['done'] );
	}

	/**
	 * Tests that the eraser invalidates the cached filter options.
	 *
	 * @since x.x.x
	 */
	public function test_erase_invalidates_caches(): void {
		$user_a = self::factory()->user->create( array( 'user_email' => 'a@example.com' ) );
		$this->insert_logs( $user_a, 1, 'user-a' );

		$this->assertContains( $user_a, $this->repository->get_filter_options()['user_ids'] );

		$this->personal_data->erase( 'a@example.com', 1 );

		$this->assertNotContains( $user_a, $this->repository->get_filter_options()['user_ids'] );
	}

	/**
	 * Tests a user who has an account but no log rows.
	 *
	 * @since x.x.x
	 */
	public function test_user_without_rows(): void {
		self::factory()->user->create( array( 'user_email' => 'empty@example.com' ) );
		$other = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );
		$this->insert_logs( $other, 2, 'other' );

		$export = $this->personal_data->export( 'empty@example.com', 1 );
		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );

		$erase = $this->personal_data->erase( 'empty@example.com', 1 );
		$this->assertFalse( $erase['items_removed'] );
		$this->assertFalse( $erase['items_retained'] );
		$this->assertSame( array(), $erase['messages'] );
		$this->assertTrue( $erase['done'] );

		$this->assertSame( 2, $this->repository->count_by_user( $other ) );
	}

	/**
	 * Tests an email address that matches no account.
	 *
	 * @since x.x.x
	 */
	public function test_email_without_account(): void {
		$this->insert_logs( 0, 2, 'guest' );

		$export = $this->personal_data->export( 'nobody@example.com', 1 );
		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );

		$erase = $this->personal_data->erase( 'nobody@example.com', 1 );
		$this->assertFalse( $erase['items_removed'] );
		$this->assertFalse( $erase['items_retained'] );
		$this->assertTrue( $erase['done'] );

		global $wpdb;
		$table = $this->schema->get_table_name();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 2, $total );
	}

	/**
	 * Tests that delete_by_user respects the row limit.
	 *
	 * @since x.x.x
	 */
	public function test_delete_by_user_respects_max_rows(): void {
		$user_a = self::factory()->user->create();
		$this->insert_logs( $user_a, 5, 'user-a' );

		$this->assertSame( 3, $this->repository->delete_by_user( $user_a, 3 ) );
		$this->assertSame( 2, $this->repository->count_by_user( $user_a ) );
		$this->assertSame( 2, $this->repository->delete_by_user( $user_a ) );
		$this->assertSame( 0, $this->repository->count_by_user( $user_a ) );
	}

	/**
	 * Tests that a failed read makes the export fail instead of reporting it done.
	 *
	 * @since x.x.x
	 */
	public function test_export_returns_error_when_query_fails(): void {
		$user_a = self::factory()->user->create( array( 'user_email' => 'a@example.com' ) );
		$this->insert_logs( $user_a, 2, 'user-a' );

		$this->fail_queries_matching( '/^SELECT log_id, /' );

		$export = $this->personal_data->export( 'a@example.com', 1 );

		$this->assertWPError( $export );
		$this->assertSame( 'wpai_request_log_query_failed', $export->get_error_code() );
	}

	/**
	 * Tests that a failed delete makes the erasure fail and leaves the rows in place.
	 *
	 * @since x.x.x
	 */
	public function test_erase_returns_error_when_delete_fails(): void {
		$user_a = self::factory()->user->create( array( 'user_email' => 'a@example.com' ) );
		$this->insert_logs( $user_a, 2, 'user-a' );

		$this->fail_queries_matching( '/^DELETE FROM \\S*wpai_request_logs WHERE user_id/' );

		$erase = $this->personal_data->erase( 'a@example.com', 1 );

		$this->assertWPError( $erase );
		$this->assertSame( 'wpai_request_log_query_failed', $erase->get_error_code() );

		remove_filter( 'query', $this->failing_query_filter );
		$this->assertSame( 2, $this->repository->count_by_user( $user_a ) );
	}

	/**
	 * Tests that a failed count makes the erasure fail instead of reporting it done.
	 *
	 * @since x.x.x
	 */
	public function test_erase_returns_error_when_count_fails(): void {
		$user_a = self::factory()->user->create( array( 'user_email' => 'a@example.com' ) );
		$this->insert_logs( $user_a, 2, 'user-a' );

		$this->fail_queries_matching( '/^SELECT COUNT\\(\\*\\) FROM \\S*wpai_request_logs WHERE user_id/' );

		$erase = $this->personal_data->erase( 'a@example.com', 1 );

		$this->assertWPError( $erase );
		$this->assertSame( 'wpai_request_log_query_failed', $erase->get_error_code() );
	}
}
