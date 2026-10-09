<?php
/**
 * Upgrade routines for version 1.3.0.
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upgrade routine for standardizing meta keys on the `wpai_` prefix.
 *
 * Renames the legacy `ai_generated` and `ai_generated_summary` post meta keys and
 * the `ai_note` comment meta key to their `wpai_`-prefixed equivalents so all
 * plugin-owned meta shares a consistent namespace.
 *
 * @since 1.3.0
 * @internal
 */
class V1_3_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.3.0
	 */
	public static string $version = '1.3.0';

	/**
	 * {@inheritDoc}
	 *
	 * Migrates post and comment meta keys from the legacy `ai_` prefix to the
	 * `wpai_` prefix.
	 *
	 * @since 1.3.0
	 *
	 * @throws \RuntimeException Throws an exception if a migration query fails.
	 */
	protected function upgrade(): void {

		// A new install has no legacy meta to migrate.
		if ( '' === $this->db_version ) {
			return;
		}

		$this->rename_post_meta_key( 'ai_generated', 'wpai_generated' );
		$this->rename_post_meta_key( 'ai_generated_summary', 'wpai_generated_summary' );
		$this->rename_comment_meta_key( 'ai_note', 'wpai_note' );

		$this->flush_meta_caches();
	}

	/**
	 * Renames a post meta key for every row that uses it.
	 *
	 * @since 1.3.0
	 *
	 * @param string $old_key The existing meta key.
	 * @param string $new_key The meta key to migrate to.
	 *
	 * @throws \RuntimeException Throws an exception if the rename or cleanup query fails.
	 */
	private function rename_post_meta_key( string $old_key, string $new_key ): void {
		global $wpdb;

		// Rename the old key to the new key, but only for posts that don't already
		// have the new key set. This avoids creating duplicate meta if new data was
		// written under the new key before this migration ran.
		$renamed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} AS pm
				LEFT JOIN {$wpdb->postmeta} AS existing
					ON existing.post_id = pm.post_id AND existing.meta_key = %s
				SET pm.meta_key = %s
				WHERE pm.meta_key = %s AND existing.meta_id IS NULL",
				$new_key,
				$new_key,
				$old_key
			)
		);

		// Bail before the cleanup below deletes rows that were never migrated.
		$this->check_query_result( $renamed, sprintf( 'Failed to rename the %1$s post meta key to %2$s', $old_key, $new_key ) );

		// Any rows still using the old key are duplicates of a pre-existing new key.
		// The new value is authoritative, so remove the redundant old rows.
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			array( 'meta_key' => $old_key )
		);

		$this->check_query_result( $deleted, sprintf( 'Failed to remove the legacy %s post meta key', $old_key ) );
	}

	/**
	 * Renames a comment meta key for every row that uses it.
	 *
	 * @since 1.3.0
	 *
	 * @param string $old_key The existing meta key.
	 * @param string $new_key The meta key to migrate to.
	 *
	 * @throws \RuntimeException Throws an exception if the rename or cleanup query fails.
	 */
	private function rename_comment_meta_key( string $old_key, string $new_key ): void {
		global $wpdb;

		// Rename the old key to the new key, but only for comments that don't already
		// have the new key set. This avoids creating duplicate meta if new data was
		// written under the new key before this migration ran.
		$renamed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->commentmeta} AS cm
				LEFT JOIN {$wpdb->commentmeta} AS existing
					ON existing.comment_id = cm.comment_id AND existing.meta_key = %s
				SET cm.meta_key = %s
				WHERE cm.meta_key = %s AND existing.meta_id IS NULL",
				$new_key,
				$new_key,
				$old_key
			)
		);

		// Bail before the cleanup below deletes rows that were never migrated.
		$this->check_query_result( $renamed, sprintf( 'Failed to rename the %1$s comment meta key to %2$s', $old_key, $new_key ) );

		// Any rows still using the old key are duplicates of a pre-existing new key.
		// The new value is authoritative, so remove the redundant old rows.
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->commentmeta,
			array( 'meta_key' => $old_key )
		);

		$this->check_query_result( $deleted, sprintf( 'Failed to remove the legacy %s comment meta key', $old_key ) );
	}

	/**
	 * Throws when a migration query failed.
	 *
	 * `wpdb` reports failures with a `false` return rather than an exception, so an
	 * unchecked query would let the routine report success and skip the retry the
	 * failed upgrade notice offers.
	 *
	 * @since 1.3.0
	 *
	 * @param int|bool $result  The value returned by the `wpdb` call.
	 * @param string   $context Describes the query that ran, for the error message.
	 *
	 * @throws \RuntimeException Throws an exception if the query failed.
	 */
	private function check_query_result( $result, string $context ): void {
		if ( false !== $result ) {
			return;
		}

		global $wpdb;

		throw new \RuntimeException( esc_html( sprintf( '%1$s: %2$s', $context, $wpdb->last_error ) ) );
	}

	/**
	 * Clears the meta caches the direct queries above left stale.
	 *
	 * Only the affected cache groups are flushed where the object cache supports it,
	 * since a full flush would evict unrelated data for the whole installation.
	 *
	 * @since 1.3.0
	 */
	private function flush_meta_caches(): void {
		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
			wp_cache_flush_group( 'comment_meta' );
			return;
		}

		wp_cache_flush();
	}
}
