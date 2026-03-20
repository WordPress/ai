<?php
/**
 * Slug Validator logic.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Installer;

/**
 * Validates plugin slugs against WordPress.org and local installations.
 *
 * @since x.x.x
 */
class SlugValidator {

	/**
	 * Validate a plugin slug.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The plugin slug to validate.
	 * @return array{valid: bool, warnings: string[], error: string|null}
	 */
	public function validate( string $slug ): array {
		$result = array(
			'valid'    => true,
			'warnings' => array(),
			'error'    => null,
		);

		// Check for invalid characters.
		if ( ! $this->is_valid_format( $slug ) ) {
			$result['valid'] = false;
			$result['error'] = 'Invalid slug format. Use only lowercase letters, numbers, and hyphens.';
			return $result;
		}

		// Check if already installed locally (blocking).
		if ( $this->is_installed_locally( $slug ) ) {
			$result['valid'] = false;
			$result['error'] = 'A plugin with this slug is already installed on this site.';
			return $result;
		}

		// Check WordPress.org (warning only, non-blocking).
		$wp_org_check = $this->check_wordpress_org( $slug );
		if ( $wp_org_check['exists'] ) {
			$result['warnings'][] = sprintf(
				'A plugin named "%s" exists on WordPress.org. This may cause update conflicts. Consider using a more unique slug.',
				$wp_org_check['name'] ?? $slug
			);
		}

		return $result;
	}

	/**
	 * Check if slug has valid format.
	 *
	 * WordPress.org slug requirements:
	 * - 2-50 characters
	 * - Starts with letter
	 * - Only lowercase letters, numbers, and hyphens
	 * - No consecutive hyphens
	 * - Cannot end with hyphen
	 *
	 * @param string $slug Slug format
	 * @return bool
	 */
	private function is_valid_format( string $slug ): bool {
		$len = strlen( $slug );

		// Length check (2-50 characters).
		if ( $len < 2 || $len > 50 ) {
			return false;
		}

		// Must start with letter, contain only allowed chars, no consecutive hyphens, no trailing hyphen.
		return (bool) preg_match( '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Check if a plugin with this slug is already installed locally.
	 *
	 * @param string $slug Slug
	 * @return bool
	 */
	private function is_installed_locally( string $slug ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins     = get_plugins();
		$slug_prefix = $slug . '/';

		foreach ( $plugins as $plugin_path => $plugin_data ) {
			if ( 0 === strpos( $plugin_path, $slug_prefix ) ) {
				return true;
			}
		}

		// Also check if directory exists (even without a valid plugin).
		$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
		return is_dir( $plugin_dir );
	}

	/**
	 * Check if a plugin with this slug exists on WordPress.org.
	 *
	 * @param string $slug Slug
	 * @return array{exists: bool, name: string|null, error: string|null}
	 */
	private function check_wordpress_org( string $slug ): array {
		$url = sprintf( 'https://api.wordpress.org/plugins/info/1.0/%s.json', $slug );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$url,
			array(
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Can't verify, don't block.
			return array(
				'exists' => false,
				'name'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			// 404 means plugin doesn't exist - that's good.
			return array(
				'exists' => false,
				'name'   => null,
				'error'  => null,
			);
		}

		// Plugin exists on WordPress.org.
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'exists' => true,
			'name'   => $data['name'] ?? null,
			'error'  => null,
		);
	}
}
