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

		// `plugin` is whatever the caller asserted, so on its own it would attribute every
		// operation to a slug the caller chose. Record the backtrace-derived caller alongside it
		// so audit consumers can log both and flag a mismatch. Detection walks a backtrace, so
		// only pay for it when something is actually listening.
		if ( has_action( 'secrets_accessed' ) || has_action( "secrets_{$operation}" ) ) {
			$context['detected_plugin'] = Secrets_Context::detect_calling_plugin();
		}

		/**
		 * Fires on every secret operation.
		 *
		 * @param string $key       The secret key.
		 * @param string $operation The operation performed.
		 * @param array  $context   Caller context (never contains the secret value). The `plugin`
		 *                          entry is asserted by the caller; `detected_plugin` is derived
		 *                          from the backtrace. Neither is an authenticated identity, but a
		 *                          mismatch between them is worth surfacing.
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
		 * @param array  $context Caller context. See the `secrets_accessed` hook above for the
		 *                        difference between `plugin` and `detected_plugin`.
		 */
		do_action( "secrets_{$operation}", $key, $context );
	}
}
