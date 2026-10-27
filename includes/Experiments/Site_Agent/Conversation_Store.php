<?php
/**
 * Server-side Site Agent conversations.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stores one user's conversation transcript for a short time.
 *
 * Keeping the transcript on the server means the browser cannot supply the
 * history the model sees, and an approval result can be appended to the same
 * conversation the person was reading.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Conversation_Store {

	/**
	 * Transient name prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TRANSIENT_PREFIX = 'wpai_site_agent_conversation_';

	/**
	 * How long an idle conversation is kept, in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Creates and stores a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id The owning user.
	 * @return array<string, mixed>|\WP_Error The conversation, or an error.
	 */
	public function create( int $user_id ) {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'site_agent_conversation_no_owner',
				__( 'A conversation must belong to a signed-in user.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$now          = time();
		$conversation = array(
			'id'       => wp_generate_uuid4(),
			'user_id'  => $user_id,
			'messages' => array(),
			'created'  => $now,
			'expires'  => $now + self::TTL,
		);

		if ( ! $this->save( $conversation ) ) {
			return new WP_Error(
				'site_agent_conversation_not_stored',
				__( 'The conversation could not be stored.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $conversation;
	}

	/**
	 * Loads a conversation owned by a user.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The requesting user.
	 * @return array<string, mixed>|null The conversation, or null.
	 */
	public function get( string $conversation_id, int $user_id ): ?array {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return null;
		}

		$stored = get_transient( $this->transient_name( $conversation_id, $user_id ) );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		if ( (int) ( $stored['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		$expires = (int) ( $stored['expires'] ?? 0 );

		if ( $expires <= 0 || $expires <= time() ) {
			$this->delete( $conversation_id, $user_id );

			return null;
		}

		return $stored;
	}

	/**
	 * Saves a conversation without extending its original expiry.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @return bool True when it was written.
	 */
	public function save( array $conversation ): bool {
		$conversation_id = isset( $conversation['id'] ) && is_string( $conversation['id'] ) ? $conversation['id'] : '';
		$user_id         = isset( $conversation['user_id'] ) ? (int) $conversation['user_id'] : 0;
		$expires         = isset( $conversation['expires'] ) ? (int) $conversation['expires'] : 0;

		if ( '' === $conversation_id || $user_id <= 0 || $expires <= 0 ) {
			return false;
		}

		return set_transient(
			$this->transient_name( $conversation_id, $user_id ),
			$conversation,
			max( 1, $expires - time() )
		);
	}

	/**
	 * Deletes a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user.
	 */
	public function delete( string $conversation_id, int $user_id ): void {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return;
		}

		delete_transient( $this->transient_name( $conversation_id, $user_id ) );
	}

	/**
	 * Builds the transient name holding a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user.
	 * @return string The transient name.
	 */
	private function transient_name( string $conversation_id, int $user_id ): string {
		return self::TRANSIENT_PREFIX . md5( $user_id . '|' . $conversation_id );
	}
}
