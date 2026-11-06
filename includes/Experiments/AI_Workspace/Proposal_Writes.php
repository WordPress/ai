<?php
/**
 * The write loop and log row every AI Workspace proposal kind shares.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use function WordPress\AI\log_ai_request;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Writes the selected items of a proposal and logs each attempt in one shape.
 *
 * Each kind keeps its own per-item write, its own outcome record and the
 * kind-specific fields of its log row; this holds what is the same for all of
 * them, so every kind returns the same result shape and its writes join the
 * others in one log view.
 *
 * @internal This trait should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
trait Proposal_Writes {

	/**
	 * Writes the selected items of a proposal.
	 *
	 * An item that was not selected is reported as `deselected` and writes
	 * nothing. `$on_item` receives each item's outcome as soon as it is done.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @param array<int, string>   $selected The item keys the person approved.
	 * @param callable|null        $on_item  Optional. Receives each item's outcome once it is done.
	 * @return array{items: list<array<string, mixed>>, created: int, updated: int, unchanged: int, stale: int, locked: int, failed: int, denied: int, duplicate: int, deselected: int} The per-item outcomes and their counts.
	 */
	public function write( array $proposal, array $selected, ?callable $on_item = null ): array {
		$items           = isset( $proposal['items'] ) && is_array( $proposal['items'] ) ? $proposal['items'] : array();
		$proposal_id     = isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '';
		$conversation_id = isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] )
			? $proposal['conversation_id']
			: '';

		$outcomes = array();
		$counts   = array_fill_keys( Proposal_Kind::OUTCOMES, 0 );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$outcome = in_array( $this->field( $item, 'key' ), $selected, true )
				? $this->write_item( $item, $proposal_id, $conversation_id )
				: $this->deselected_outcome( $item );

			if ( isset( $counts[ $outcome['outcome'] ] ) ) {
				++$counts[ $outcome['outcome'] ];
			}

			$outcomes[] = $outcome;

			if ( null === $on_item ) {
				continue;
			}

			$on_item( $outcome );
		}

		return array(
			'items'      => $outcomes,
			'created'    => $counts['created'],
			'updated'    => $counts['updated'],
			'unchanged'  => $counts['unchanged'],
			'stale'      => $counts['stale'],
			'locked'     => $counts['locked'],
			'failed'     => $counts['failed'],
			'denied'     => $counts['denied'],
			'duplicate'  => $counts['duplicate'],
			'deselected' => $counts['deselected'],
		);
	}

	/**
	 * Writes one item.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item            The proposal item.
	 * @param string               $proposal_id     The proposal ID.
	 * @param string               $conversation_id The conversation ID.
	 * @return array<string, mixed> The item outcome.
	 */
	abstract protected function write_item( array $item, string $proposal_id, string $conversation_id ): array;

	/**
	 * Builds the outcome of an item the person did not select.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item The proposal item.
	 * @return array<string, mixed> The `deselected` outcome.
	 */
	abstract protected function deselected_outcome( array $item ): array;

	/**
	 * Returns the kind-specific fields of an item's log row.
	 *
	 * They follow the proposal ID and item key in the row's `proposal` context.
	 * No field values are logged.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item The proposal item.
	 * @return array<string, mixed> The fields.
	 */
	abstract protected function log_details( array $item ): array;

	/**
	 * Writes the single log row for one write attempt.
	 *
	 * The shape matches the rows {@see Turn_Runner} writes for tool calls, so
	 * reads and writes from one conversation join in a single log view (KTD10).
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item            The proposal item.
	 * @param string               $proposal_id     The proposal ID.
	 * @param string               $conversation_id The conversation ID.
	 * @param string               $status          The outcome status.
	 * @param int                  $duration        Duration in milliseconds.
	 * @param string               $error_code      The error code, when there is one.
	 * @param string               $error_message   The error message, when there is one.
	 */
	protected function log(
		array $item,
		string $proposal_id,
		string $conversation_id,
		string $status,
		int $duration,
		string $error_code,
		string $error_message
	): void {
		$operation = $this->log_operation();
		$key       = $this->field( $item, 'key' );

		$context = array(
			'surface'         => Turn_Runner::LOG_SURFACE,
			'conversation_id' => $conversation_id,
			'round'           => 0,
			'tool'            => array(
				'ability'  => $operation,
				'function' => $operation,
				'call_id'  => $key,
			),
			'proposal'        => array_merge(
				array(
					'id'       => $proposal_id,
					'item_key' => $key,
				),
				$this->log_details( $item )
			),
		);

		if ( '' !== $error_code ) {
			$context['error_code'] = $error_code;
		}

		if ( Turn_Runner::LOG_STATUS_DENIED === $status ) {
			$context['denial_reason'] = $error_code;
		}

		$log_data = array(
			'type'        => 'ability',
			'operation'   => $operation,
			'status'      => $status,
			'user_id'     => get_current_user_id(),
			'context'     => $context,
			'duration_ms' => $duration,
		);

		if ( '' !== $error_message ) {
			$log_data['error_message'] = $error_message;
		}

		log_ai_request( $log_data );
	}

	/**
	 * Reads a string field from a stored item.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item  The stored item.
	 * @param string               $field The field.
	 * @return string The value, or an empty string.
	 */
	protected function field( array $item, string $field ): string {
		return isset( $item[ $field ] ) && is_string( $item[ $field ] ) ? $item[ $field ] : '';
	}
}
