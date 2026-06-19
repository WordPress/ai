<?php
/**
 * Site Health integration for the AI plugin.
 *
 * @package WordPress\AI\Admin
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Settings\Settings_Registration;
use function WordPress\AI\get_ai_connectors;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\has_connector_authentication;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides AI-specific information and tests to WordPress Site Health.
 *
 * Adds a debug-info section ("AI Plugin") to the Site Health Info tab and a
 * direct status test that checks whether AI credentials are configured.
 * No API keys, tokens, or secret values are ever exposed.
 *
 * @since x.x.x
 */
final class Site_Health {

	/**
	 * Registers the Site Health hooks.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
		add_filter( 'site_status_tests', array( $this, 'add_status_tests' ) );
	}

	/**
	 * Adds an "AI Plugin" section to the Site Health Info tab.
	 *
	 * Reports safe configuration status only. No secrets are included.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, mixed>> $info Existing debug-info sections.
	 * @return array<string, array<string, mixed>> Updated sections.
	 */
	public function add_debug_information( array $info ): array {
		$fields = array();

		// Global AI enabled toggle.
		$globally_enabled     = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		$fields['ai_enabled'] = array(
			'label' => __( 'AI enabled', 'ai' ),
			'value' => $globally_enabled ? __( 'Yes', 'ai' ) : __( 'No', 'ai' ),
			'debug' => $globally_enabled ? 'yes' : 'no',
		);

		// Plugin version.
		$fields['plugin_version'] = array(
			'label' => __( 'Plugin version', 'ai' ),
			'value' => WPAI_VERSION,
		);

		// Credentials status (name/source only — never the key itself).
		$has_credentials                  = has_ai_credentials();
		$fields['credentials_configured'] = array(
			'label' => __( 'Credentials configured', 'ai' ),
			'value' => $has_credentials ? __( 'Yes', 'ai' ) : __( 'No', 'ai' ),
			'debug' => $has_credentials ? 'yes' : 'no',
		);

		// Configured providers — names only.
		$configured_providers           = $this->get_configured_provider_names();
		$fields['configured_providers'] = array(
			'label' => __( 'Configured providers', 'ai' ),
			'value' => ! empty( $configured_providers )
				? implode( ', ', $configured_providers )
				: __( 'None', 'ai' ),
		);

		// Number of individually enabled features.
		$enabled_features           = $this->count_enabled_features();
		$fields['enabled_features'] = array(
			'label' => __( 'Features enabled', 'ai' ),
			'value' => $enabled_features,
		);

		$info['ai-plugin'] = array(
			'label'  => __( 'AI Plugin', 'ai' ),
			'fields' => $fields,
		);

		return $info;
	}

	/**
	 * Adds a direct status test that checks whether AI credentials are configured.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, mixed>> $tests Existing status tests.
	 * @return array<string, array<string, mixed>> Updated tests.
	 */
	public function add_status_tests( array $tests ): array {
		$tests['direct']['wpai_credentials'] = array(
			'label' => __( 'AI credentials configured', 'ai' ),
			'test'  => array( $this, 'run_credentials_test' ),
		);

		return $tests;
	}

	/**
	 * Runs the credentials status test.
	 *
	 * Returns a passing result when at least one AI connector has credentials
	 * configured, and a recommended-action result when none are found.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Site Health test result array.
	 */
	public function run_credentials_test(): array {
		if ( has_ai_credentials() ) {
			return array(
				'label'       => __( 'AI credentials are configured', 'ai' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'AI', 'ai' ),
					'color' => 'blue',
				),
				'description' => sprintf(
					'<p>%s</p>',
					__( 'At least one AI connector has credentials configured and is ready to use.', 'ai' )
				),
				'test'        => 'wpai_credentials',
			);
		}

		return array(
			'label'       => __( 'No AI credentials configured', 'ai' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'AI', 'ai' ),
				'color' => 'orange',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'No AI connector credentials have been configured. AI features will not be active until at least one connector is set up.', 'ai' )
			),
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-connectors.php' ) ),
				esc_html__( 'Manage Connectors', 'ai' )
			),
			'test'        => 'wpai_credentials',
		);
	}

	/**
	 * Returns the display names of connectors that have credentials configured.
	 *
	 * Only the human-readable name is returned; credential values are never included.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Connector display names.
	 */
	private function get_configured_provider_names(): array {
		$connectors = get_ai_connectors();
		$names      = array();

		foreach ( $connectors as $connector_id => $connector_data ) {
			if ( ! has_connector_authentication( $connector_id ) ) {
				continue;
			}

			$name    = $connector_data['name'] ?? $connector_id;
			$names[] = is_string( $name ) ? $name : $connector_id;
		}

		return $names;
	}

	/**
	 * Counts the number of individually enabled AI features.
	 *
	 * @since x.x.x
	 *
	 * @return int The number of features with their individual toggle enabled.
	 */
	private function count_enabled_features(): int {
		$registered = get_registered_settings();
		$count      = 0;

		foreach ( $registered as $option_name => $args ) {
			$option_name = (string) $option_name;
			if ( ( $args['group'] ?? '' ) !== Settings_Registration::OPTION_GROUP ) {
				continue;
			}

			// Count only per-feature enabled toggles.
			if ( 1 !== preg_match( '/^wpai_feature_.+_enabled$/', $option_name ) ) {
				continue;
			}

			if ( ! (bool) get_option( $option_name, false ) ) {
				continue;
			}

			++$count;
		}

		return $count;
	}
}
