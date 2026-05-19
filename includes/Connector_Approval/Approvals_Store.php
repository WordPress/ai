<?php
/**
 * Storage layer for connector approvals and pending approval requests.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the per-plugin/per-connector approval matrix and pending-requests queue.
 *
 * @since 1.0.0
 */
final class Approvals_Store {
	/**
	 * Option key for the approval matrix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const OPTION_APPROVALS = 'wpai_connector_approvals';

	/**
	 * Option key for the pending approval requests queue.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const OPTION_PENDING = 'wpai_connector_approval_pending';

	/**
	 * Hard cap on the number of pending entries tracked at once.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	public const PENDING_LIMIT = 50;

	/**
	 * Returns whether a caller is approved for a connector.
	 *
	 * @since 1.0.0
	 *
	 * @param string $caller_basename Caller basename (plugin basename, mu-plugin filename, or theme slug).
	 * @param string $connector_id    Connector ID.
	 * @return bool
	 */
	public function is_approved( string $caller_basename, string $connector_id ): bool {
		$approvals = $this->get_approvals();
		return ! empty( $approvals[ $caller_basename ][ $connector_id ] );
	}

	/**
	 * Sets (or revokes) approval for a caller/connector pair.
	 *
	 * @since 1.0.0
	 *
	 * @param string $caller_basename Caller basename.
	 * @param string $connector_id    Connector ID.
	 * @param bool   $approved        True to approve, false to revoke.
	 */
	public function set_approval( string $caller_basename, string $connector_id, bool $approved ): void {
		$approvals = $this->get_approvals();

		if ( $approved ) {
			if ( ! isset( $approvals[ $caller_basename ] ) || ! is_array( $approvals[ $caller_basename ] ) ) {
				$approvals[ $caller_basename ] = array();
			}
			$approvals[ $caller_basename ][ $connector_id ] = true;
		} elseif ( isset( $approvals[ $caller_basename ][ $connector_id ] ) ) {
			unset( $approvals[ $caller_basename ][ $connector_id ] );
			if ( empty( $approvals[ $caller_basename ] ) ) {
				unset( $approvals[ $caller_basename ] );
			}
		}

		update_option( self::OPTION_APPROVALS, $approvals, false );
	}

	/**
	 * Returns the full approval matrix.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, bool>>
	 */
	public function get_approvals(): array {
		$approvals = get_option( self::OPTION_APPROVALS, array() );
		if ( ! is_array( $approvals ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $approvals as $caller => $connectors ) {
			if ( ! is_string( $caller ) || '' === $caller ) {
				continue;
			}

			$canonical = $this->canonicalize_basename( $caller );
			if ( ! isset( $normalized[ $canonical ] ) ) {
				$normalized[ $canonical ] = array();
			}

			if ( ! is_array( $connectors ) ) {
				continue;
			}

			foreach ( $connectors as $connector_id => $value ) {
				if ( ! is_string( $connector_id ) ) {
					continue;
				}
				$existing                                  = $normalized[ $canonical ][ $connector_id ] ?? false;
				$normalized[ $canonical ][ $connector_id ] = $existing || (bool) $value;
			}
		}

		return $normalized;
	}

	/**
	 * Collapses a bare plugin slug (e.g. `ai`) to its canonical basename
	 * (e.g. `ai/ai.php`) when `get_plugins()` knows the plugin. Leaves
	 * already-canonical basenames, themes, and unknown values unchanged so
	 * stale option data can't produce duplicate rows in the admin UI.
	 *
	 * @since 1.0.0
	 *
	 * @param string $caller_basename Caller basename as stored in options.
	 * @return string
	 */
	private function canonicalize_basename( string $caller_basename ): string {
		if ( '' === $caller_basename || false !== strpos( $caller_basename, '/' ) ) {
			return $caller_basename;
		}

		if ( '' !== pathinfo( $caller_basename, PATHINFO_EXTENSION ) ) {
			return $caller_basename;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$prefix = $caller_basename . '/';
		foreach ( array_keys( get_plugins() ) as $basename ) {
			if ( str_starts_with( (string) $basename, $prefix ) ) {
				return (string) $basename;
			}
		}

		return $caller_basename;
	}

	/**
	 * Records a denied attempt so the admin can review it later.
	 *
	 * @since 1.0.0
	 *
	 * @param array{type: string, basename: string, name: string} $caller Caller identifier.
	 * @param string $connector_id Connector ID.
	 */
	public function record_pending( array $caller, string $connector_id ): void {
		$pending = $this->get_pending();
		$key     = $this->pending_key( $caller['basename'], $connector_id );
		$now     = time();

		if ( isset( $pending[ $key ] ) && is_array( $pending[ $key ] ) ) {
			$pending[ $key ]['attempts']  = (int) ( $pending[ $key ]['attempts'] ?? 0 ) + 1;
			$pending[ $key ]['last_seen'] = $now;
		} else {
			if ( count( $pending ) >= self::PENDING_LIMIT ) {
				return;
			}

			$pending[ $key ] = array(
				'caller_type'     => (string) $caller['type'],
				'caller_basename' => (string) $caller['basename'],
				'caller_name'     => (string) $caller['name'],
				'connector_id'    => $connector_id,
				'attempts'        => 1,
				'first_seen'      => $now,
				'last_seen'       => $now,
			);
		}

		update_option( self::OPTION_PENDING, $pending, false );
	}

	/**
	 * Removes a pending entry by key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Pending entry key.
	 * @return bool True if an entry was removed.
	 */
	public function remove_pending( string $key ): bool {
		$pending = $this->get_pending();
		if ( ! isset( $pending[ $key ] ) ) {
			return false;
		}

		unset( $pending[ $key ] );
		update_option( self::OPTION_PENDING, $pending, false );

		return true;
	}

	/**
	 * Returns the pending-approvals queue.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{
	 *   caller_type: string,
	 *   caller_basename: string,
	 *   caller_name: string,
	 *   connector_id: string,
	 *   attempts: int,
	 *   first_seen: int,
	 *   last_seen: int
	 * }>
	 */
	public function get_pending(): array {
		$pending = get_option( self::OPTION_PENDING, array() );
		if ( ! is_array( $pending ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $pending as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) ) {
				continue;
			}

			$basename     = $this->canonicalize_basename( (string) ( $entry['caller_basename'] ?? '' ) );
			$connector_id = (string) ( $entry['connector_id'] ?? '' );
			$canonical    = $this->pending_key( $basename, $connector_id );

			$normalized[ $canonical ] = array(
				'caller_type'     => (string) ( $entry['caller_type'] ?? '' ),
				'caller_basename' => $basename,
				'caller_name'     => (string) ( $entry['caller_name'] ?? '' ),
				'connector_id'    => $connector_id,
				'attempts'        => (int) ( $entry['attempts'] ?? 0 ),
				'first_seen'      => (int) ( $entry['first_seen'] ?? 0 ),
				'last_seen'       => (int) ( $entry['last_seen'] ?? 0 ),
			);
		}

		return $normalized;
	}

	/**
	 * Computes the stable key used for a pending-approval entry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $caller_basename Caller basename.
	 * @param string $connector_id    Connector ID.
	 * @return string
	 */
	public function pending_key( string $caller_basename, string $connector_id ): string {
		return $caller_basename . '::' . $connector_id;
	}
}
