<?php
/**
 * Single-runner lock for the sync worker.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * An options-table lock that two runners cannot both acquire.
 *
 * @since x.x.x
 */
class Sync_Lock {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Option name.
	 *
	 * @since x.x.x
	 */
	public const OPTION = 'wpai_embedding_sync_lock';

	/**
	 * Seconds after which an unrefreshed lock counts as abandoned.
	 *
	 * @since x.x.x
	 */
	public const TTL = 300;

	/**
	 * The value this instance wrote, or an empty string when not held.
	 *
	 * @var string
	 */
	private string $value = '';

	/**
	 * Tries to acquire the lock.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $now Optional. Unix time. Default now.
	 * @return bool True when acquired.
	 */
	public function acquire( ?int $now = null ): bool {
		global $wpdb;

		$now   = $now ?? time();
		$value = $now . ':' . wp_generate_password( 12, false );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				self::OPTION,
				$value
			)
		);

		if ( 1 === (int) $inserted ) {
			return $this->held( $value );
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		// Released between the two queries: the next run will get it.
		if ( ! is_string( $current ) ) {
			return false;
		}

		if ( $now - (int) $current < self::TTL ) {
			return false;
		}

		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				$current
			)
		);

		return 1 === (int) $taken ? $this->held( $value ) : false;
	}

	/**
	 * Refreshes the lock's timestamp so a long run does not look abandoned.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $now Optional. Unix time. Default now.
	 * @return bool True when this instance still holds the lock.
	 */
	public function refresh( ?int $now = null ): bool {
		global $wpdb;

		if ( '' === $this->value ) {
			return false;
		}

		$token = substr( $this->value, (int) strpos( $this->value, ':' ) + 1 );
		$value = ( $now ?? time() ) . ':' . $token;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				$this->value
			)
		);

		if ( 1 === (int) $updated ) {
			return $this->held( $value );
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		if ( $value === $current || $this->value === $current ) {
			return $this->held( $current );
		}

		$this->value = '';

		return false;
	}

	/**
	 * Releases the lock if this instance holds it.
	 *
	 * @since x.x.x
	 */
	public function release(): void {
		global $wpdb;

		if ( '' === $this->value ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, $this->value )
		);

		$this->value = '';
		wp_cache_delete( self::OPTION, 'options' );
	}

	/**
	 * Checks whether this instance holds the lock.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when held.
	 */
	public function is_held(): bool {
		return '' !== $this->value;
	}

	/**
	 * Records the held value and drops any cached copy of the option.
	 *
	 * @since x.x.x
	 *
	 * @param string $value The value written.
	 * @return bool Always true.
	 */
	private function held( string $value ): bool {
		$this->value = $value;
		wp_cache_delete( self::OPTION, 'options' );

		return true;
	}
}
