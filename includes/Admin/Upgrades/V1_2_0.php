<?php
/**
 * Upgrade routines for version 1.2.0.
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 1.2.0
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
 * @since 1.2.0
 * @internal
 */
class V1_2_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.2.0
	 */
	public static string $version = '1.2.0';

	/**
	 * {@inheritDoc}
	 *
	 * Migrates post and comment meta keys from the legacy `ai_` prefix to the
	 * `wpai_` prefix.
	 *
	 * @since 1.2.0
	 */
	protected function upgrade(): void {
		$this->rename_post_meta_key( 'ai_generated', 'wpai_generated' );
		$this->rename_post_meta_key( 'ai_generated_summary', 'wpai_generated_summary' );
		$this->rename_comment_meta_key( 'ai_note', 'wpai_note' );
	}

	/**
	 * Renames a post meta key for every row that uses it.
	 *
	 * @since 1.2.0
	 *
	 * @param string $old_key The existing meta key.
	 * @param string $new_key The meta key to migrate to.
	 */
	private function rename_post_meta_key( string $old_key, string $new_key ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			array( 'meta_key' => $new_key ),
			array( 'meta_key' => $old_key )
		);
	}

	/**
	 * Renames a comment meta key for every row that uses it.
	 *
	 * @since 1.2.0
	 *
	 * @param string $old_key The existing meta key.
	 * @param string $new_key The meta key to migrate to.
	 */
	private function rename_comment_meta_key( string $old_key, string $new_key ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->commentmeta,
			array( 'meta_key' => $new_key ),
			array( 'meta_key' => $old_key )
		);
	}
}
