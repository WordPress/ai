<?php
/**
 * Named single-runner lock for background work.
 *
 * @package WordPress\AI\Background
 */

declare( strict_types=1 );

namespace WordPress\AI\Background;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * An options-table lock that two runners cannot both acquire.
 *
 * @since x.x.x
 */
class Lock {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Default seconds after which an unrefreshed lock counts as abandoned.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_TTL = 300;

	/**
	 * Option name holding the lock.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Seconds after which an unrefreshed lock counts as abandoned.
	 *
	 * @var int
	 */
	private int $ttl;

	/**
	 * The value this instance wrote, or an empty string when not held.
	 *
	 * @var string
	 */
	private string $value = '';

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string $name Lock name: lowercase letters, digits and underscores, such as
	 *                     `embedding_sync` or `tts_job_123`.
	 * @param int    $ttl  Optional. Seconds after which an unrefreshed lock counts as abandoned.
	 *                     Default 300.
	 *
	 * @throws \InvalidArgumentException When the name or TTL is invalid.
	 */
	public function __construct( string $name, int $ttl = self::DEFAULT_TTL ) {
		// The option_name column is 191 characters; leave room for the prefix and suffix.
		if ( ! preg_match( '/^[a-z0-9_]{1,170}$/', $name ) ) {
			throw new InvalidArgumentException( 'Lock names may only contain lowercase letters, digits and underscores.' );
		}

		if ( $ttl < 1 ) {
			throw new InvalidArgumentException( 'The lock TTL must be at least one second.' );
		}

		$this->option = 'wpai_' . $name . '_lock';
		$this->ttl    = $ttl;
	}

	/**
	 * Returns the option name holding the lock.
	 *
	 * @since x.x.x
	 *
	 * @return string The option name.
	 */
	public function get_option_name(): string {
		return $this->option;
	}

	/**
	 * Returns the seconds after which an unrefreshed lock counts as abandoned.
	 *
	 * @since x.x.x
	 *
	 * @return int The TTL, in seconds.
	 */
	public function get_ttl(): int {
		return $this->ttl;
	}

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
				$this->option,
				$value
			)
		);

		if ( 1 === (int) $inserted ) {
			return $this->held( $value );
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->option ) );

		// Released between the two queries: the next run will get it.
		if ( ! is_string( $current ) ) {
			return false;
		}

		if ( $now - (int) $current < $this->ttl ) {
			return false;
		}

		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$this->option,
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
				$this->option,
				$this->value
			)
		);

		if ( 1 === (int) $updated ) {
			return $this->held( $value );
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->option ) );

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
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->option, $this->value )
		);

		$this->value = '';
		wp_cache_delete( $this->option, 'options' );
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
		wp_cache_delete( $this->option, 'options' );

		return true;
	}
}
