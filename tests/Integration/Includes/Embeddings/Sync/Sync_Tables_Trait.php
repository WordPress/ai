<?php
/**
 * Creates and drops the sync tables once per test class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WordPress\AI\Embeddings\Embedding_Schema;
use WordPress\AI\Embeddings\Sync\Sync_Queue_Schema;

/**
 * Table lifecycle for sync tests.
 *
 * DDL implicitly commits the transaction `WP_UnitTestCase` opens per test, so tables are created
 * before any test transaction starts (`wpSetUpBeforeClass()`) and dropped after the last one ends
 * (`wpTearDownAfterClass()`). Rows written inside a test then roll back as usual.
 *
 * @since x.x.x
 */
trait Sync_Tables_Trait {

	/**
	 * Creates the embeddings and queue tables at their current versions.
	 *
	 * @since x.x.x
	 */
	public static function create_sync_tables(): void {
		( new Embedding_Schema() )->maybe_upgrade_table();
		( new Sync_Queue_Schema() )->maybe_upgrade_table();
	}

	/**
	 * Drops both tables and their version options.
	 *
	 * @since x.x.x
	 */
	public static function drop_sync_tables(): void {
		( new Sync_Queue_Schema() )->drop_table();
		( new Embedding_Schema() )->drop_table();
	}
}
