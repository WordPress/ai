<?php
/**
 * Secrets Context
 *
 * Encapsulates caller context for access control decisions.
 *
 * @package Displace_Secrets_Manager
 */

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents the calling context for a secrets operation.
 *
 * Captures the calling plugin slug, the current user, and any
 * additional metadata so providers and access-control filters
 * have the information they need.
 */
final class Secrets_Context {

	/**
	 * The calling plugin's slug, detected automatically or passed explicitly.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * The WordPress user ID performing the operation, or 0 for system/CLI.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Whether this context originates from WP-CLI.
	 *
	 * @var bool
	 */
	private $is_cli;

	/**
	 * Arbitrary extra context data.
	 *
	 * @var array
	 */
	private $extra;

	/**
	 * Constructor.
	 *
	 * @param array $context {
	 *     Optional. Context overrides.
	 *
	 *     @type string $plugin  Plugin slug. Auto-detected if omitted.
	 *     @type int    $user_id WordPress user ID. Defaults to current user.
	 *     @type bool   $is_cli  Whether running under WP-CLI.
	 * }
	 */
	public function __construct( array $context = [] ) {
		$this->plugin_slug = $context['plugin'] ?? self::detect_calling_plugin();
		$this->user_id     = $context['user_id'] ?? get_current_user_id();
		$this->is_cli      = $context['is_cli'] ?? ( defined( 'WP_CLI' ) && WP_CLI );
		$this->extra       = $context;
	}

	/**
	 * Get the calling plugin slug.
	 *
	 * @return string
	 */
	public function get_plugin_slug(): string {
		return $this->plugin_slug;
	}

	/**
	 * Get the WordPress user ID.
	 *
	 * @return int
	 */
	public function get_user_id(): int {
		return $this->user_id;
	}

	/**
	 * Whether the operation originates from WP-CLI.
	 *
	 * @return bool
	 */
	public function is_cli(): bool {
		return $this->is_cli;
	}

	/**
	 * Get the full context as an array suitable for passing to providers.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array_merge(
			$this->extra,
			array(
				'plugin'  => $this->plugin_slug,
				'user_id' => $this->user_id,
				'is_cli'  => $this->is_cli,
			)
		);
	}

	/**
	 * Check whether the caller has cross-namespace access.
	 *
	 * @param string $target_namespace The namespace being accessed.
	 * @return bool
	 */
	public function can_access_namespace( string $target_namespace ): bool {
		if ( $this->is_cli ) {
			return true;
		}

		if ( $this->plugin_slug === $target_namespace ) {
			return true;
		}

		if ( user_can( $this->user_id, 'manage_secrets' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect the calling plugin by walking the backtrace.
	 *
	 * Looks for the first file path outside displace-secrets-manager that
	 * lives within wp-content/plugins/{slug}/.
	 *
	 * @return string The detected plugin slug, or 'unknown'.
	 */
	private static function detect_calling_plugin(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- intentional for caller detection.
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			if ( false !== strpos( $file, 'displace-secrets-manager/' ) ) {
				continue;
			}

			if ( preg_match( '#wp-content/plugins/([^/]+)/#', $file, $matches ) ) {
				return $matches[1];
			}

			if ( preg_match( '#wp-content/themes/([^/]+)/#', $file, $matches ) ) {
				return 'theme:' . $matches[1];
			}
		}

		return 'unknown';
	}
}
