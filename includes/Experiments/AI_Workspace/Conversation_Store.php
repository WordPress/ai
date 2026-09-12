<?php
/**
 * Session-lifetime storage for AI Workspace conversations.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stores a workspace conversation, and the cancellation marker for its turn.
 *
 * Three properties are load bearing.
 *
 * **The medium is a user-scoped transient.** Conversation state is session
 * lifetime, is regenerated on demand, and belongs to an unproven experiment, so
 * it does not earn a custom table with a `dbDelta` migration behind it, and it
 * would be wrong to leave in an autoloaded option: a transcript that has
 * accumulated retrieved post bodies is exactly the kind of row that must expire
 * on its own. Named conversation threads are out of scope for this phase (R22),
 * which is what makes an expiring medium sufficient.
 *
 * **A conversation belongs to one user.** The client sends the conversation ID,
 * so ownership is not implied by anything about the request. The transient key
 * is derived from the owner's user ID as well as the conversation ID, so another
 * user asking for the same ID reads a different key entirely, and the stored
 * owner is compared again on load. Without both, a conversation ID would be an
 * enumerable read of somebody else's private and draft post content.
 *
 * **Cancellation is stored separately.** It is written by a different HTTP
 * request than the one running the turn, so it lives in its own transient that
 * the loop re-reads between rounds. Its local cache entry is dropped before each
 * read, otherwise the loop would keep answering from the value it cached at the
 * start of the request and could never observe the cancellation.
 *
 * @since x.x.x
 */
final class Conversation_Store {

	/**
	 * Transient name prefix for stored conversations.
	 *
	 * Shares the plugin's `wpai_` transient prefix so
	 * {@see \WordPress\AI\Admin\Uninstall::delete_transients()} removes it.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TRANSIENT_PREFIX = 'wpai_workspace_conv_';

	/**
	 * Transient name prefix for cancellation markers.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CANCEL_PREFIX = 'wpai_workspace_cancel_';

	/**
	 * How long a conversation survives without being touched, in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * How long a cancellation marker survives, in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CANCEL_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Creates a new, empty conversation owned by a user.
	 *
	 * @since x.x.x
	 *
	 * @param int    $user_id The owning user ID.
	 * @param string $scope   The conversation scope.
	 * @return array{id: string, user_id: int, scope: string, created: int, updated: int, messages: list<array<string, mixed>>} The conversation.
	 */
	public function create( int $user_id, string $scope ): array {
		$now = time();

		return array(
			'id'       => wp_generate_uuid4(),
			'user_id'  => $user_id,
			'scope'    => $scope,
			'created'  => $now,
			'updated'  => $now,
			'messages' => array(),
		);
	}

	/**
	 * Loads a conversation, but only for the user who owns it.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The requesting user ID.
	 * @return array<string, mixed>|null The conversation, or null when it is missing, expired, or owned by someone else.
	 */
	public function get( string $conversation_id, int $user_id ): ?array {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return null;
		}

		$stored = get_transient( $this->transient_name( $conversation_id, $user_id ) );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		// The key already scopes the read to one user; the stored owner is
		// compared again so a hand-written transient cannot hand over a
		// conversation either.
		if ( ! isset( $stored['user_id'] ) || (int) $stored['user_id'] !== $user_id ) {
			return null;
		}

		if ( ! isset( $stored['messages'] ) || ! is_array( $stored['messages'] ) ) {
			$stored['messages'] = array();
		}

		return $stored;
	}

	/**
	 * Saves a conversation, refreshing its expiry.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation to store.
	 * @return bool True when the conversation was written.
	 */
	public function save( array $conversation ): bool {
		$conversation_id = isset( $conversation['id'] ) && is_string( $conversation['id'] ) ? $conversation['id'] : '';
		$user_id         = isset( $conversation['user_id'] ) ? (int) $conversation['user_id'] : 0;

		if ( '' === $conversation_id || $user_id <= 0 ) {
			return false;
		}

		$conversation['updated'] = time();

		return set_transient(
			$this->transient_name( $conversation_id, $user_id ),
			$conversation,
			self::TTL
		);
	}

	/**
	 * Deletes a conversation and any cancellation marker it carries.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 */
	public function delete( string $conversation_id, int $user_id ): void {
		delete_transient( $this->transient_name( $conversation_id, $user_id ) );
		$this->clear_cancellation( $conversation_id, $user_id );
	}

	/**
	 * Marks a conversation's in-flight turn as cancelled.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @return bool True when the marker was written.
	 */
	public function cancel( string $conversation_id, int $user_id ): bool {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return false;
		}

		return set_transient( $this->cancel_name( $conversation_id, $user_id ), time(), self::CANCEL_TTL );
	}

	/**
	 * Reports whether a turn has been cancelled out of band.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @return bool True when a cancellation marker is present.
	 *
	 * @phpstan-impure
	 */
	public function is_cancelled( string $conversation_id, int $user_id ): bool {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return false;
		}

		$name = $this->cancel_name( $conversation_id, $user_id );

		/*
		 * The marker is written by a different request, so the value this request
		 * cached on its first read is stale by definition. Both cache locations a
		 * transient can occupy are dropped before reading: the `transient` group
		 * when an external object cache is in use, and the underlying option when
		 * the value lives in the options table.
		 */
		wp_cache_delete( $name, 'transient' );
		wp_cache_delete( '_transient_' . $name, 'options' );
		wp_cache_delete( '_transient_timeout_' . $name, 'options' );

		return false !== get_transient( $name );
	}

	/**
	 * Removes a cancellation marker.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 */
	public function clear_cancellation( string $conversation_id, int $user_id ): void {
		if ( '' === $conversation_id || $user_id <= 0 ) {
			return;
		}

		delete_transient( $this->cancel_name( $conversation_id, $user_id ) );
	}

	/**
	 * Builds the transient name holding a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @return string The transient name.
	 */
	private function transient_name( string $conversation_id, int $user_id ): string {
		return self::TRANSIENT_PREFIX . md5( $user_id . '|' . $conversation_id );
	}

	/**
	 * Builds the transient name holding a cancellation marker.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The owning user ID.
	 * @return string The transient name.
	 */
	private function cancel_name( string $conversation_id, int $user_id ): string {
		return self::CANCEL_PREFIX . md5( $user_id . '|' . $conversation_id );
	}
}
