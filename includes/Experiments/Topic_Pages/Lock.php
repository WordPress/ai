<?php
/**
 * Short database-backed serialization of reviewed Topic Page saves.
 *
 * @since x.x.x
 *
 * @package WordPress\AI
 */
declare( strict_types=1 );
namespace WordPress\AI\Experiments\Topic_Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes first saves using the options table's unique option_name index.
 *
 * @since x.x.x
 */
final class Lock {
	/**
	 * Acquires a per-topic lease without add_option's upsert race.
	 *
	 * @since x.x.x
	 *
	 * @param int $id Term ID.
	 * @param string $operation Operation being serialized.
	 * @return string|false Serialized owner, or false if another save holds the lease.
	 */
	public function acquire( int $id, string $operation ) {
		global $wpdb;
		$key   = 'wpai_topic_pages_' . $operation . '_' . $id;
		$old   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A cross-process save lock must bypass the object cache.
		$lease = is_string( $old ) ? maybe_unserialize( $old ) : null;
		if ( is_array( $lease ) && isset( $lease['expires'] ) && $lease['expires'] < time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional owner check makes release safe across requests.
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $key,
					'option_value' => $old,
				),
				array( '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional deletion cannot remove a newer owner's lease.
		}
		$owner    = maybe_serialize(
			array(
				'token'   => wp_generate_uuid4(),
				'expires' => time() + 300,
			)
		);
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, $owner ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- INSERT IGNORE and the unique option_name index admit one writer only.
		return 1 === $inserted ? $owner : false;
	}

	/**
	 * Checks that a generation request still owns its unexpired lease.
	 *
	 * @since x.x.x
	 *
	 * @param int $id Topic term ID.
	 * @param string $operation Operation being serialized.
	 * @param string $owner Serialized lease owner.
	 * @return bool Whether this request still owns the lease.
	 */
	public function owns( int $id, string $operation, string $owner ): bool {
		global $wpdb;
		$key     = 'wpai_topic_pages_' . $operation . '_' . $id;
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Ownership must bypass cached options.
		$lease   = maybe_unserialize( $owner );
		return $current === $owner && is_array( $lease ) && isset( $lease['expires'] ) && $lease['expires'] >= time();
	}

	/**
	 * Releases only this request's lease, even if an expired lease was reclaimed.
	 *
	 * @since x.x.x
	 *
	 * @param int $id Topic term ID.
	 * @param string $operation Operation being serialized.
	 *
	 * @param string $owner Serialized save lease owner.
	 */
	public function release( int $id, string $operation, string $owner ): void {
		global $wpdb;
		$key = 'wpai_topic_pages_' . $operation . '_' . $id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional owner check makes release safe across requests.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $key,
				'option_value' => $owner,
			),
			array( '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional owner check makes release safe across requests.
		wp_cache_delete( $key, 'options' );
	}
}
