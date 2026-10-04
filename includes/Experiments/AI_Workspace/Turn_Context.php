<?php
/**
 * Request-scoped context describing the workspace turn an ability runs inside.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Names the conversation an ability invocation belongs to, for the length of one request.
 *
 * Abilities are called by WordPress core's function resolver, which passes only
 * the model's own arguments. An ability therefore has no way of knowing which
 * conversation it is serving, and asking the model to supply the conversation ID
 * would make an attacker-influenceable string the thing a stored proposal is
 * bound to. {@see Turn_Runner} enters this context around the tool loop instead,
 * so the binding comes from the request the server already authenticated.
 *
 * Two properties are load bearing:
 *
 * - **It is only ever entered by the turn loop**, and left in a `finally`, so a
 *   context can never outlive the request that opened it.
 * - **It re-checks the current user on every read.** A context left behind by a
 *   long-running process would otherwise be readable by whoever runs next; a
 *   context whose user is not the current user reports itself inactive.
 *
 * The absence of a context is meaningful: it is what makes a proposal created
 * outside the workspace — by the MCP surface, say — unexecutable, because
 * execution requires the stored conversation to match the request's.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Turn_Context {

	/**
	 * The conversation the current turn belongs to.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private static $conversation_id = '';

	/**
	 * The user the current turn is running as.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private static $user_id = 0;

	/**
	 * Enters a turn context.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @param int    $user_id         The user the turn runs as.
	 */
	public static function enter( string $conversation_id, int $user_id ): void {
		self::$conversation_id = $conversation_id;
		self::$user_id         = $user_id;
	}

	/**
	 * Leaves the current turn context.
	 *
	 * @since x.x.x
	 */
	public static function leave(): void {
		self::$conversation_id = '';
		self::$user_id         = 0;
	}

	/**
	 * Reports whether a turn context is active for the current user.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when an ability is running inside this user's workspace turn.
	 */
	public static function is_active(): bool {
		return '' !== self::$conversation_id
			&& self::$user_id > 0
			&& get_current_user_id() === self::$user_id;
	}

	/**
	 * Returns the conversation ID of the active turn.
	 *
	 * @since x.x.x
	 *
	 * @return string The conversation ID, or an empty string when no context is active.
	 */
	public static function get_conversation_id(): string {
		return self::is_active() ? self::$conversation_id : '';
	}
}
