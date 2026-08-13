<?php
/**
 * The REST-backed implementation of the `core/read-settings` ability.
 *
 * @package WordPress\AI
 *
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Settings;

use WordPress\AI\Abilities\Rest\Rest_Backend;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Settings_Rest
 *
 * Reads the settings that {@see Settings} exposes through `GET /wp/v2/settings` instead of
 * reading the options directly. The settings endpoint already returns every setting keyed
 * by name and cast to its registered type, so the mapping is only about names: REST keys a
 * setting by its `show_in_rest` name, while the ability keys it by its `show_in_abilities`
 * name.
 *
 * Only settings flagged with `show_in_rest` reach the endpoint. A setting exposed to
 * abilities but not to REST is reported as missing, and {@see Settings} falls back to the
 * stored option value for it.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.3.0
 */
final class Settings_Rest {

	/**
	 * Reads the exposed settings through the REST API, keyed by their exposed name.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, array{option: string, group: string, default: mixed, schema: array<string, mixed>}> $settings Exposed settings keyed by exposed name.
	 * @return array<string, mixed>|\WP_Error Values keyed by exposed name, or the error the
	 *                                        endpoint returned. Settings the REST API does
	 *                                        not expose are absent from a successful result.
	 */
	public function get_values( array $settings ) {
		$response = Rest_Backend::get( '/wp/v2/settings', array( 'context' => 'edit' ) );

		/*
		 * The error is passed on rather than reported as "no values". Reporting no values
		 * would send every setting to the stored option instead, which answers a request
		 * the endpoint just refused.
		 */
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rest_values = Rest_Backend::data( $response );
		if ( is_wp_error( $rest_values ) ) {
			return $rest_values;
		}

		$rest_names = $this->rest_names();

		$values = array();
		foreach ( $settings as $exposed_name => $setting ) {
			$rest_name = $rest_names[ $setting['option'] ] ?? null;

			if ( null === $rest_name || ! array_key_exists( $rest_name, $rest_values ) ) {
				continue;
			}

			$values[ $exposed_name ] = $rest_values[ $rest_name ];
		}

		return $values;
	}

	/**
	 * Maps each option name to the name the settings endpoint reports it under.
	 *
	 * Mirrors how `WP_REST_Settings_Controller::get_registered_options()` picks the response
	 * key: the `show_in_rest` name when one is given, and the option name otherwise.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, string> REST names keyed by option name.
	 */
	private function rest_names(): array {
		$names = array();

		foreach ( get_registered_settings() as $option_name => $args ) {
			$show = $args['show_in_rest'] ?? false;
			if ( empty( $show ) ) {
				continue;
			}

			$option_name = (string) $option_name;

			$names[ $option_name ] = is_array( $show ) && ! empty( $show['name'] ) && is_string( $show['name'] )
				? $show['name']
				: $option_name;
		}

		return $names;
	}
}
