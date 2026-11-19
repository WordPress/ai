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
 * Set by {@see Turn_Runner}, never from model arguments, which are attacker-influenceable. Inactive when the
 * current user is not the turn's user.
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
	 * The ability whose raw reads are remembered.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const READ_ABILITY = 'ai/read-content-bodies';

	/**
	 * SHA-256 hashes of the stored markup read this turn, keyed by post ID.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, string>
	 */
	private static $read_hashes = array();

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
		self::$read_hashes     = array();

		add_action( 'wp_after_execute_ability', array( self::class, 'record_read' ), 10, 3 );
	}

	/**
	 * Leaves the current turn context.
	 *
	 * @since x.x.x
	 */
	public static function leave(): void {
		self::$conversation_id = '';
		self::$user_id         = 0;
		self::$read_hashes     = array();

		remove_action( 'wp_after_execute_ability', array( self::class, 'record_read' ), 10 );
	}

	/**
	 * Remembers the stored markup of each post a raw read returned, so a later stale edit can be refused.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability that ran.
	 * @param mixed  $input        Its input.
	 * @param mixed  $result       Its result.
	 */
	public static function record_read( $ability_name, $input, $result ): void {
		if ( self::READ_ABILITY !== $ability_name || ! self::is_active() || ! is_array( $result ) || ! isset( $result['posts'] ) || ! is_array( $result['posts'] ) ) {
			return;
		}

		foreach ( $result['posts'] as $post ) {
			if ( ! is_array( $post ) || ! isset( $post['id'], $post['content_raw'] ) || ! is_string( $post['content_raw'] ) ) {
				continue;
			}

			self::$read_hashes[ (int) $post['id'] ] = hash( 'sha256', $post['content_raw'] );
		}
	}

	/**
	 * Returns the hash of a post's stored markup as the model read it this turn.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post ID.
	 * @return string The SHA-256 hash, or an empty string when the post was not read raw this turn.
	 */
	public static function read_hash( int $post_id ): string {
		return self::is_active() && isset( self::$read_hashes[ $post_id ] ) ? self::$read_hashes[ $post_id ] : '';
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
