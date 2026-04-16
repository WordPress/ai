<?php
/**
 * Minimal WP-CLI stubs for testing CLI commands without a full WP-CLI install.
 *
 * Defined only if WP-CLI is not available. Captures output for assertions.
 *
 * @package WordPress\AI\Tests\Integration\Includes\CLI
 */

namespace {
	if ( class_exists( 'WP_CLI' ) ) {
		return;
	}

	/**
	 * Exception thrown by WP_CLI::error() in tests to simulate halting execution.
	 */
	class WP_CLI_Test_Error_Exception extends \Exception {}

	/**
	 * Stub WP_CLI class capturing static method calls for assertion.
	 */
	class WP_CLI {
		/**
		 * Captured log messages.
		 *
		 * @var array<int, array{level: string, message: string}>
		 */
		public static $messages = array();

		/**
		 * Resets the captured messages.
		 */
		public static function reset(): void {
			self::$messages = array();
		}

		/**
		 * @param string $message The error message.
		 * @throws \WP_CLI_Test_Error_Exception To simulate halting execution.
		 */
		public static function error( $message ): void {
			self::$messages[] = array(
				'level'   => 'error',
				'message' => (string) $message,
			);
			throw new \WP_CLI_Test_Error_Exception( (string) $message );
		}

		/**
		 * @param string $message The success message.
		 */
		public static function success( $message ): void {
			self::$messages[] = array(
				'level'   => 'success',
				'message' => (string) $message,
			);
		}

		/**
		 * @param string $message The log message.
		 */
		public static function log( $message ): void {
			self::$messages[] = array(
				'level'   => 'log',
				'message' => (string) $message,
			);
		}

		/**
		 * @param string $message The warning message.
		 */
		public static function warning( $message ): void {
			self::$messages[] = array(
				'level'   => 'warning',
				'message' => (string) $message,
			);
		}

		/**
		 * Stub for command registration - no-op.
		 *
		 * @param string $name    The command name.
		 * @param mixed  $handler The command handler.
		 */
		public static function add_command( $name, $handler ): void {
			// No-op for tests.
		}
	}

	/**
	 * Stub progress bar for tests.
	 */
	class WP_CLI_Test_Progress_Bar {
		/**
		 * Number of ticks.
		 *
		 * @var int
		 */
		public $ticks = 0;

		/**
		 * Whether finish() was called.
		 *
		 * @var bool
		 */
		public $finished = false;

		/**
		 * Increments the tick count.
		 */
		public function tick(): void {
			++$this->ticks;
		}

		/**
		 * Marks the progress as finished.
		 */
		public function finish(): void {
			$this->finished = true;
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param array<string, mixed> $assoc_args The associative arguments.
	 * @param string               $key        The key to look up.
	 * @param mixed                $default_value Default value.
	 * @return mixed The flag value or default.
	 */
	function get_flag_value( $assoc_args, $key, $default_value = null ) {
		return $assoc_args[ $key ] ?? $default_value;
	}

	/**
	 * @param string $label The progress bar label.
	 * @param int    $count The total count.
	 * @return \WP_CLI_Test_Progress_Bar The progress bar stub.
	 */
	function make_progress_bar( $label, $count ) {
		return new \WP_CLI_Test_Progress_Bar();
	}

	/**
	 * @param string $format  The output format.
	 * @param array  $items   The items to format.
	 * @param array  $columns The columns to display.
	 */
	function format_items( $format, $items, $columns ): void {
		$lines   = array();
		$lines[] = implode( "\t", $columns );
		foreach ( $items as $item ) {
			$row = array();
			foreach ( $columns as $col ) {
				$row[] = (string) ( $item[ $col ] ?? '' );
			}
			$lines[] = implode( "\t", $row );
		}
		echo implode( "\n", $lines ) . "\n";
	}
}
