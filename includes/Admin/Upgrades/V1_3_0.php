<?php
/**
 * Upgrade routines for version 1.3.0.
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since x.x.x
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
 * @since x.x.x
 * @internal
 */
class V1_3_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static string $version = '1.3.0';

	/**
	 * {@inheritDoc}
	 *
	 * Migrates post and comment meta keys from the legacy `ai_` prefix to the
	 * `wpai_` prefix.
	 *
	 * @since x.x.x
	 */
	protected function upgrade(): void {
		$this->rename_post_meta_key( 'ai_generated', 'wpai_generated' );
		$this->rename_post_meta_key( 'ai_generated_summary', 'wpai_generated_summary' );
		$this->rename_comment_meta_key( 'ai_note', 'wpai_note' );

		// Cache flush to update any stale data.
		wp_cache_flush();
	}

	/**
	 * Renames a post meta key for every row that uses it.
	 *
	 * @since x.x.x
	 *
	 * @param string $old_key The existing meta key.
	 * @param string $new_key The meta key to migrate to.
	 */
	private function rename_post_meta_key( string $old_key, string $new_key ): void {
		global $wpdb;

		// Rename the old key to the new key, but only for posts that don't already
		// have the new key set. This avoids creating duplicate meta if new data was
		// written under the new key before this migration ran.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		// Any rows still using the old key are duplicates of a pre-existing new key.
		// The new value is authoritative, so remove the redundant old rows.
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			array( 'meta_key' => $old_key )
		);
	}

	/**
	 * Renames a comment meta key for every row that uses it.
	 *
	 * @since x.x.x
	 *
	 * @param string $old_key The existing meta key.
	 * @param string $new_key The meta key to migrate to.
	 */
	private function rename_comment_meta_key( string $old_key, string $new_key ): void {
		global $wpdb;

		// Rename the old key to the new key, but only for comments that don't already
		// have the new key set. This avoids creating duplicate meta if new data was
		// written under the new key before this migration ran.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		// Any rows still using the old key are duplicates of a pre-existing new key.
		// The new value is authoritative, so remove the redundant old rows.
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->commentmeta,
			array( 'meta_key' => $old_key )
		);
	}
}
