<?php
/**
 * Captures warnings reported through wp_trigger_error().
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

/**
 * Warning capture for sync tests.
 *
 * @since x.x.x
 */
trait Captures_Warnings_Trait {

	/**
	 * Runs a callback and returns the warnings it reported through wp_trigger_error().
	 *
	 * @since x.x.x
	 *
	 * @param callable $callback The code to run.
	 * @return list<array{0: string, 1: string, 2: int}> Function name, message and level of each warning.
	 */
	private function capture_warnings( callable $callback ): array {
		$warnings = array();
		$capture  = static function ( string $function_name, string $message, int $error_level ) use ( &$warnings ): void {
			$warnings[] = array( $function_name, $message, $error_level );
		};

		add_action( 'wp_trigger_error_always_run', $capture, 10, 3 );
		// Keep PHPUnit from turning the reported warning into an exception; the action above captures it.
		add_filter( 'wp_trigger_error_trigger_error', '__return_false' );

		try {
			$callback();
		} finally {
			remove_action( 'wp_trigger_error_always_run', $capture, 10 );
			remove_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		}

		return $warnings;
	}
}
