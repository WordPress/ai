<?php
/**
 * Audit trail for Site Agent actions.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use function WordPress\AI\log_ai_request;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Writes one audit row for every agent-driven ability call and decision.
 *
 * WordPress already fires `wp_after_execute_ability` for every successful
 * ability execution, so the agent does not keep its own per-call logging code.
 * A depth counter scopes the hook to agent-driven calls only.
 *
 * Rows never contain full post bodies, setting values, package URLs, or
 * credentials. They carry the ability name, the phase, the proposal ID, the
 * conversation ID, and an error code when there is one.
 *
 * @since x.x.x
 */
final class Audit {

	/**
	 * Fires after a Site Agent action decision for external audit listeners.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ACTION = 'wpai_site_agent_action';

	/**
	 * How many agent operations are on the stack.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Hooks the audit listener.
	 *
	 * @since x.x.x
	 */
	public static function init(): void {
		add_action( 'wp_after_execute_ability', array( self::class, 'on_execute' ), 10, 4 );
	}

	/**
	 * Marks the start of agent-driven work.
	 *
	 * @since x.x.x
	 */
	public static function enter(): void {
		++self::$depth;
	}

	/**
	 * Marks the end of agent-driven work.
	 *
	 * @since x.x.x
	 */
	public static function leave(): void {
		self::$depth = max( 0, self::$depth - 1 );
	}

	/**
	 * Logs an ability call that WordPress actually executed.
	 *
	 * @since x.x.x
	 *
	 * @param string           $ability_name The ability name.
	 * @param mixed            $input        The input.
	 * @param mixed            $result       The result.
	 * @param \WP_Ability|null $ability      The ability.
	 */
	public static function on_execute( string $ability_name, $input = null, $result = null, $ability = null ): void {
		unset( $input, $ability );

		if ( self::$depth < 1 || ! in_array( $ability_name, Catalog::names(), true ) ) {
			return;
		}

		self::write(
			$ability_name,
			is_wp_error( $result ) ? 'error' : 'success',
			array( 'phase' => 'execute' )
		);
	}

	/**
	 * Logs a proposal, an approval, a decline, or a denial.
	 *
	 * @since x.x.x
	 *
	 * @param string               $ability_name The ability name.
	 * @param string               $status       One of success, error, or denied.
	 * @param array<string, mixed> $context      The audit context.
	 * @param string               $error        Optional. The error message.
	 */
	public static function decision( string $ability_name, string $status, array $context, string $error = '' ): void {
		self::write( $ability_name, $status, $context, $error );
	}

	/**
	 * Writes a row and notifies listeners.
	 *
	 * @since x.x.x
	 *
	 * @param string               $ability_name The ability name.
	 * @param string               $status       One of success, error, or denied.
	 * @param array<string, mixed> $context      The audit context.
	 * @param string               $error        Optional. The error message.
	 */
	private static function write( string $ability_name, string $status, array $context, string $error = '' ): void {
		$context = array_merge(
			array(
				'surface' => 'site-agent',
				'ability' => $ability_name,
			),
			$context
		);

		/**
		 * Fires after a Site Agent action decision.
		 *
		 * @since x.x.x
		 *
		 * @param string               $ability_name The ability name.
		 * @param string               $status       The status.
		 * @param array<string, mixed> $context      The audit context.
		 * @param string               $error        The error message, or an empty string.
		 */
		do_action( 'wpai_site_agent_action', $ability_name, $status, $context, $error );

		$data = array(
			'type'      => 'site_agent',
			'operation' => '' === $ability_name ? 'unknown' : $ability_name,
			'status'    => $status,
			'user_id'   => get_current_user_id(),
			'context'   => $context,
		);

		if ( '' !== $error ) {
			$data['error_message'] = $error;
		}

		log_ai_request( $data );
	}
}
