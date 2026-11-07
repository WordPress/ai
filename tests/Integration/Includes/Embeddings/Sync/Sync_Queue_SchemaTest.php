<?php
/**
 * Tests for the sync queue schema.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Sync_Queue;
use WordPress\AI\Embeddings\Sync\Sync_Queue_Schema;

/**
 * Sync_Queue_Schema test case.
 *
 * Does real DDL per test, so isolation is explicit (see Embedding_SchemaTest::reset_storage()).
 *
 * @since 1.5.0
 *
 * @covers \WordPress\AI\Embeddings\Sync\Sync_Queue_Schema
 */
class Sync_Queue_SchemaTest extends WP_UnitTestCase {

	/**
	 * Schema under test.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue_Schema
	 */
	private Sync_Queue_Schema $schema;

	/**
	 * Set up test case.
	 *
	 * @since 1.5.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new Sync_Queue_Schema();
		$this->schema->drop_table();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.5.0
	 */
	protected function tearDown(): void {
		$this->schema->drop_table();

		parent::tearDown();
	}

	/**
	 * Tests that reads never create the table and the first write does.
	 *
	 * @since 1.5.0
	 */
	public function test_reads_do_not_create_table_and_first_write_does(): void {
		$queue = new Sync_Queue( $this->schema );

		$this->assertSame( array(), $queue->claim_due( 10 ) );
		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 0,
			),
			$queue->count_by_status()
		);
		$this->assertNull( $queue->get_next_available_at() );
		$this->assertFalse( $this->schema->table_exists() );

		$queue->enqueue( 'post', 1 );

		$this->assertTrue( $this->schema->table_exists() );
	}

	/**
	 * Tests that re-running the upgrade on an existing table is a clean no-op.
	 *
	 * Covers the case where the version option is lost but the table remains
	 * (e.g. a test-suite reinstall), which must not make dbDelta re-add the primary key.
	 *
	 * @since x.x.x
	 */
	public function test_upgrade_on_existing_table_without_version_is_clean(): void {
		global $wpdb, $EZSQL_ERROR; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

		$this->schema->maybe_upgrade_table();
		$this->assertTrue( $this->schema->table_exists() );

		delete_option( Sync_Queue_Schema::SCHEMA_VERSION_OPTION );
		$this->assertFalse( $this->schema->is_version_current() );

		// dbDelta() runs several queries and each resets last_error, so collect every error instead.
		$wpdb->last_error = '';
		$EZSQL_ERROR      = array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->schema->maybe_upgrade_table();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( array(), wp_list_pluck( (array) $EZSQL_ERROR, 'error_str' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$this->assertTrue( $this->schema->is_version_current() );
	}
}
