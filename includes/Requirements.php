<?php
/**
 * Checks for external dependencies and requirements.
 *
 * @package WordPress\AI
 */

declare(strict_types=1);

namespace WordPress\AI;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Requirements
 *
 * This class checks for external dependencies and requirements for the plugin.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 */
final class Requirements {
	/**
	 * The minimum PHP version.
	 */
	private const MIN_PHP_VERSION = '7.4';

	/**
	 * The minimum WordPress version.
	 */
	private const MIN_WP_VERSION = '7.0';

	/**
	 * The resolved requirement checks, keyed by requirement slug.
	 *
	 * The value is true if the requirement is met, or the error message if not.
	 * Messages are stored as a callable so they can be translated after the checks are run.
	 *
	 * @since 0.8.0
	 *
	 * @var array<string,(true|callable():string)> $requirements An array of requirement slugs and their check results.
	 */
	private $requirements = array();

	/**
	 * Runs the plugin requirements checks.
	 *
	 * @return bool True if all requirements are met, false otherwise.
	 */
	public function are_requirements_met(): bool {
		foreach ( $this->get_requirements() as $slug => $check_callback ) {
			if ( isset( $this->requirements[ $slug ] ) ) {
				continue;
			}

			$success = $check_callback['check']();

			// The callback is stored, but only triggered inside the admin notice callback so strings can be translated.
			$this->requirements[ $slug ] = $success ? true : $check_callback['error_message'];
		}

		if ( ! $this->meets_all_requirements() ) {
			$this->display_admin_notice();
			return false;
		}

		return true;
	}

	/**
	 * Returns the plugin requirements to check, keyed by a slug for easy retrieval.
	 *
	 * Items are checked during initialization by the order they are defined in this array.
	 *
	 * Each requirement should have a 'check' callback that returns true if the requirement is met, and an 'error_message' callback that returns a string error message if the requirement is not met.
	 *
	 * The error message is stored as a callback to allow for proper translation after the checks are run, since some checks may rely on functions that are not available until later in the WordPress load process.
	 *
	 * @return array<string,array{
	 *   check:callable():bool,
	 *   error_message:callable():string
	 * }> The requirements to check.
	 */
	private function get_requirements(): array {
		return array(
			'php'        => array(
				'check'         => static fn() => version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '>=' ),
				'error_message' => static fn() => sprintf(
					// translators: %s: Minimum PHP version, %s: Current PHP version.
					esc_html__( 'PHP version %1$s or higher is required. You are running PHP version %2$s.', 'ai' ),
					esc_html( self::MIN_PHP_VERSION ),
					esc_html( PHP_VERSION )
				),
			),
			'wp'         => array(
				'check'         => static fn() => is_wp_version_compatible( self::MIN_WP_VERSION ),
				'error_message' => static fn() => sprintf(
					// translators: %s: Minimum WordPress version.
					esc_html__( 'WordPress version %s or higher is required.', 'ai' ),
					esc_html( self::MIN_WP_VERSION )
				),
			),
			'assets'     => array(
				'check'         => static function () {
					// PHPUnit tests may not have the asset built.
					if ( defined( 'WPAI_IS_TEST' ) && WPAI_IS_TEST ) {
						return true;
					}

					$asset_file = WPAI_PLUGIN_DIR . 'build/build.php';
					if ( file_exists( $asset_file ) ) {
						require_once $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
						return true;
					}

					return false;
				},
				'error_message' => static fn() => esc_html__( 'The plugin assets are not built. This is most likely because you downloaded the plugin from the GitHub repository without building the assets. Please run `nvm use && npm ci && npm run build` to build the assets.', 'ai' ),
			),
			'ai_support' => array(
				'check'         => static fn() => wp_supports_ai(),
				'error_message' => static fn() => esc_html__( 'Your WordPress environment has AI functionality disabled. The AI plugin will not work until AI support is enabled.', 'ai' ),
			),
		);
	}

	/**
	 * Checks the dependencies and display an admin notice if any are not met.
	 *
	 * @since 0.8.0
	 */
	private function display_admin_notice(): void {
		$hooks = array(
			'admin_notices',
			'network_admin_notices',
		);

		// Store a local copy to pass to the static callback.
		$requirements = $this->requirements;

		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				static function () use ( $requirements ) {
					// Messages are generated inside the hook to ensure the translation functions are available.
					$error_message = self::get_admin_notice_message_html( $requirements );

					wp_admin_notice(
						wp_kses(
							$error_message,
							array(
								'br' => array(),
								'ul' => array(),
								'li' => array(),
							)
						),
						array(
							'type' => 'error',
						)
					);
				}
			);
		}
	}

	/**
	 * Checks if all requirements are met.
	 *
	 * @since 0.8.0
	 *
	 * @return bool True if all requirements are met, false otherwise.
	 */
	private function meets_all_requirements(): bool {
		foreach ( $this->requirements as $result ) {
			if ( true !== $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Gets the admin notice message based on the failed requirements.
	 *
	 * Needs to be sanitized.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string,(true|callable():string)> $requirements The requirements check results, keyed by requirement slug.
	 *
	 * @return string The admin notice message.
	 */
	private static function get_admin_notice_message_html( array $requirements ): string {
		$error_messages = array_map(
			static function ( $result ) {
				if ( is_callable( $result ) ) {
					return $result();
				}
				return null;
			},
			$requirements
		);
		$error_messages = array_filter( $error_messages );

		if ( count( $error_messages ) === 1 ) {
			return reset( $error_messages );
		}

		return __( 'AI plugin cannot run due to the following issues:', 'ai' )
			. '<br><ul><li>'
			. implode( '</li><li>', $error_messages )
			. '</li></ul>';
	}
}
