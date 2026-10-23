<?php
/**
 * Server-side storage for Site Agent action proposals.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Persists one proposed action until the same person approves or declines it.
 *
 * The browser only ever receives the proposal ID. The ability name and the
 * exact arguments stay on the server, so approval cannot execute a request the
 * browser supplied.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Proposal_Store {

	/**
	 * Transient name prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TRANSIENT_PREFIX = 'wpai_site_agent_proposal_';

	/**
	 * How long an unconfirmed proposal remains executable, in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Creates and stores a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param int                  $user_id         The proposing user.
	 * @param string               $conversation_id The conversation the proposal belongs to.
	 * @param string               $ability_name    The ability to call.
	 * @param array<string, mixed> $args            The exact arguments.
	 * @return array<string, mixed>|\WP_Error The stored proposal, or an error.
	 */
	public function create( int $user_id, string $conversation_id, string $ability_name, array $args ) {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'site_agent_proposal_no_owner',
				__( 'A proposal must belong to a signed-in user.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		if ( '' === $conversation_id ) {
			return new WP_Error(
				'site_agent_proposal_no_conversation',
				__( 'A proposal must belong to a Site Agent conversation.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $ability_name ) {
			return new WP_Error(
				'site_agent_proposal_invalid_ability',
				__( 'A proposal must name a valid ability.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$now      = time();
		$proposal = array(
			'id'              => wp_generate_uuid4(),
			'user_id'         => $user_id,
			'conversation_id' => $conversation_id,
			'ability'         => $ability_name,
			'args'            => $args,
			'created'         => $now,
			'expires'         => $now + self::TTL,
			'status'          => 'pending',
		);

		if ( ! $this->save( $proposal ) ) {
			return new WP_Error(
				'site_agent_proposal_not_stored',
				__( 'The proposal could not be stored.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $proposal;
	}

	/**
	 * Loads a proposal owned by a user.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The requesting user.
	 * @return array<string, mixed>|null The proposal, or null.
	 */
	public function get( string $proposal_id, int $user_id ): ?array {
		if ( '' === $proposal_id || $user_id <= 0 ) {
			return null;
		}

		$stored = get_transient( $this->transient_name( $proposal_id, $user_id ) );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		if ( (int) ( $stored['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		if ( $this->is_expired( $stored ) ) {
			$this->delete( $proposal_id, $user_id );

			return null;
		}

		return $stored;
	}

	/**
	 * Reports whether a stored proposal has passed its expiry.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return bool True when it may no longer be executed.
	 */
	public function is_expired( array $proposal ): bool {
		$expires = isset( $proposal['expires'] ) ? (int) $proposal['expires'] : 0;

		return $expires <= 0 || $expires <= time();
	}

	/**
	 * Saves a proposal without extending its original expiry.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return bool True when it was written.
	 */
	public function save( array $proposal ): bool {
		$proposal_id = isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '';
		$user_id     = isset( $proposal['user_id'] ) ? (int) $proposal['user_id'] : 0;
		$expires     = isset( $proposal['expires'] ) ? (int) $proposal['expires'] : 0;

		if ( '' === $proposal_id || $user_id <= 0 || $expires <= 0 ) {
			return false;
		}

		return set_transient(
			$this->transient_name( $proposal_id, $user_id ),
			$proposal,
			max( 1, $expires - time() )
		);
	}

	/**
	 * Marks a proposal used. It can never be executed again.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return bool True when it was written.
	 */
	public function mark_used( array $proposal ): bool {
		$proposal['status'] = 'used';

		return $this->save( $proposal );
	}

	/**
	 * Deletes a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The owning user.
	 */
	public function delete( string $proposal_id, int $user_id ): void {
		if ( '' === $proposal_id || $user_id <= 0 ) {
			return;
		}

		delete_transient( $this->transient_name( $proposal_id, $user_id ) );
	}

	/**
	 * Builds the transient name holding a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The owning user.
	 * @return string The transient name.
	 */
	private function transient_name( string $proposal_id, int $user_id ): string {
		return self::TRANSIENT_PREFIX . md5( $user_id . '|' . $proposal_id );
	}
}
