<?php
/**
 * Secrets Audit Logger
 *
 * Fires WordPress actions for every secret operation so that
 * third-party audit plugins (WP Activity Log, Stream, Simple History)
 * can capture secret access patterns.
 *
 * Secret values are NEVER passed through these hooks.
 *
 * @package Displace_Secrets_Manager
 */

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit logger for secrets operations.
 */
final class Secrets_Audit {

	/**
	 * Log a secrets operation by firing the appropriate WordPress actions.
	 *
	 * @param string $operation One of 'get', 'set', 'delete', 'exists', 'list'.
	 * @param string $key       The secret key being operated on.
	 * @param array  $context   Caller context.
	 */
	public static function log( string $operation, string $key, array $context ): void {
		$context['operation'] = $operation;
		$context['timestamp'] = current_time( 'mysql', true );

		if ( empty( $context['user_id'] ) ) {
			$context['user_id'] = get_current_user_id();
		}

		/**
		 * Fires on every secret operation.
		 *
		 * @param string $key       The secret key.
		 * @param string $operation The operation performed.
		 * @param array  $context   Caller context (never contains the secret value).
		 */
		do_action( 'secrets_accessed', $key, $operation, $context );

		/**
		 * Fires for the specific operation type.
		 *
		 * Available hooks:
		 *   - secrets_get
		 *   - secrets_set
		 *   - secrets_delete
		 *   - secrets_exists
		 *   - secrets_list
		 *
		 * @param string $key     The secret key.
		 * @param array  $context Caller context.
		 */
		do_action( "secrets_{$operation}", $key, $context );
	}
}
