<?php
/**
 * Audit trail for agent events.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use function WordPress\AI\log_ai_request;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Records every agent event in one place.
 *
 * Context never carries post content or option values.
 *
 * @since x.x.x
 */
final class Agent_Audit {

	/**
	 * The action fired for every agent event.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ACTION = 'wpai_agent_event';

	/**
	 * The AI Request Log type of every agent row.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOG_TYPE = 'agent';

	/**
	 * Records one event: fires the hook, then writes the log row.
	 *
	 * @since x.x.x
	 *
	 * @param array{
	 *     operation: string,
	 *     status: string,
	 *     user_id?: int,
	 *     context?: array<string, mixed>,
	 *     duration_ms?: int,
	 *     error_message?: string
	 * } $data The event, in the shape {@see log_ai_request()} takes, without a type.
	 */
	public static function record( array $data ): void {
		$context = $data['context'] ?? array();
		$error   = $data['error_message'] ?? '';

		/**
		 * Fires for every agent event, whether or not AI Request Logging is on.
		 *
		 * @since x.x.x
		 *
		 * @param string               $operation The operation.
		 * @param string               $status    The status: `success`, `error`, `denied`, `skipped` or `timeout`.
		 * @param array<string, mixed> $context   The event context. It carries no post content or option values.
		 * @param string               $error     The error message, or an empty string.
		 */
		do_action( 'wpai_agent_event', $data['operation'], $data['status'], $context, $error );

		log_ai_request( array_merge( $data, array( 'type' => self::LOG_TYPE ) ) );
	}

	/**
	 * Records an event in a proposal's life.
	 *
	 * @since x.x.x
	 *
	 * @param string               $event      The operation, such as `proposal_created`.
	 * @param string               $status     The status.
	 * @param array<string, mixed> $proposal   The stored proposal.
	 * @param array<string, mixed> $extra      Optional. More proposal fields, such as the edited item keys. Default empty array.
	 * @param string               $error_code Optional. The error code, when there is one. Default empty string.
	 */
	public static function proposal( string $event, string $status, array $proposal, array $extra = array(), string $error_code = '' ): void {
		$items = isset( $proposal['items'] ) && is_array( $proposal['items'] ) ? $proposal['items'] : array();
		$keys  = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'] ) || ! is_string( $item['key'] ) ) {
				continue;
			}

			$keys[] = $item['key'];
		}

		$context = array(
			'surface'         => Turn_Runner::LOG_SURFACE,
			'conversation_id' => isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] ) ? $proposal['conversation_id'] : '',
			'proposal'        => array_merge(
				array(
					'id'    => isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '',
					'kind'  => isset( $proposal['kind'] ) && is_string( $proposal['kind'] ) ? $proposal['kind'] : Draft_Writer::KIND,
					'items' => $keys,
				),
				$extra
			),
		);

		if ( '' !== $error_code ) {
			$context['error_code'] = $error_code;
		}

		if ( Turn_Runner::LOG_STATUS_DENIED === $status ) {
			$context['denial_reason'] = $error_code;
		}

		self::record(
			array(
				'operation' => $event,
				'status'    => $status,
				'user_id'   => isset( $proposal['user_id'] ) ? (int) $proposal['user_id'] : get_current_user_id(),
				'context'   => $context,
			)
		);
	}
}
