<?php
/**
 * Ability Handler Class
 *
 * Handles fetching and processing abilities from the WordPress Abilities API.
 *
 * @package WordPress\AI\Features\Abilities_Explorer
 * @since n.e.x.t
 */

namespace WordPress\AI\Features\Abilities_Explorer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability Handler Class
 *
 * Provides methods for retrieving, formatting, and invoking WordPress abilities.
 *
 * @since n.e.x.t
 */
class Ability_Handler {

	/**
	 * Get all registered abilities.
	 *
	 * @since n.e.x.t
	 *
	 * @return array Array of abilities.
	 */
	public static function get_all_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$all_abilities = wp_get_abilities();

		return self::format_abilities( $all_abilities );
	}

	/**
	 * Get a single ability by slug.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $slug Ability slug (name).
	 * @return array|null Ability data or null if not found.
	 */
	public static function get_ability( $slug ): ?array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		$ability = wp_get_ability( $slug );

		if ( ! $ability ) {
			return null;
		}

		return self::format_single_ability( $ability );
	}

	/**
	 * Format abilities array.
	 *
	 * @since n.e.x.t
	 *
	 * @param array $abilities Raw abilities array (WP_Ability objects).
	 * @return array Formatted abilities.
	 */
	private static function format_abilities( $abilities ): array {
		if ( empty( $abilities ) || ! is_array( $abilities ) ) {
			return array();
		}

		$formatted = array();

		foreach ( $abilities as $ability ) {
			$formatted[] = self::format_single_ability( $ability );
		}

		return $formatted;
	}

	/**
	 * Format a single ability.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP_Ability $ability Ability object.
	 * @return array Formatted ability data.
	 */
	private static function format_single_ability( $ability ): array {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
			return array();
		}

		$name = $ability->get_name();
		$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();

		return array(
			'slug'          => $name,
			'name'          => $ability->get_label(),
			'description'   => $ability->get_description(),
			'provider'      => self::detect_provider( $name, $meta ),
			'input_schema'  => $ability->get_input_schema(),
			'output_schema' => $ability->get_output_schema(),
			'raw_data'      => array(
				'name'          => $name,
				'label'         => $ability->get_label(),
				'description'   => $ability->get_description(),
				'input_schema'  => $ability->get_input_schema(),
				'output_schema' => $ability->get_output_schema(),
				'meta'          => $meta,
			),
		);
	}

	/**
	 * Detect ability provider (Core, Plugin, or Theme).
	 *
	 * @since n.e.x.t
	 *
	 * @param string $name Ability name (slug).
	 * @param array  $meta Ability metadata.
	 * @return string Provider type.
	 */
	private static function detect_provider( $name, $meta ): string {
		// Check if provider is explicitly set in meta
		if ( isset( $meta['provider'] ) ) {
			return $meta['provider'];
		}

		// Detect based on name prefix (namespace/ability format)
		$parts = explode( '/', $name );
		if ( count( $parts ) === 2 ) {
			$namespace = $parts[0];

			// WordPress core abilities
			if ( in_array( $namespace, array( 'wordpress', 'wp', 'core' ), true ) ) {
				return 'Core';
			}

			// Check if namespace matches active theme
			if ( get_stylesheet() === $namespace || get_template() === $namespace ) {
				return 'Theme';
			}
		}

		// Default to Plugin
		return 'Plugin';
	}

	/**
	 * Invoke an ability.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $slug  Ability name.
	 * @param array  $input Input data.
	 * @return array Result with success status and data/error.
	 *
	 * @phpstan-return array{
	 *   success: bool,
	 *   code?: int|string,
	 *   data?: mixed,
	 *   error?: string,
	 * }
	 */
	public static function invoke_ability( $slug, $input = array() ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'success' => false,
				'error'   => 'Abilities API not available',
			);
		}

		$ability = wp_get_ability( $slug );

		if ( ! $ability ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Ability "%s" not found', $slug ),
			);
		}

		// If ability has no input schema, invoke without input
		$input_schema = $ability->get_input_schema();
		if ( empty( $input_schema ) ) {
			$result = $ability->execute();
		} else {
			$result = $ability->execute( $input );
		}

		// Check if result is WP_Error
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
				'data'    => $result->get_error_data(),
			);
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * Validate input against input schema.
	 *
	 * @since n.e.x.t
	 *
	 * @param array $schema Input schema.
	 * @param array $input  Input data to validate.
	 * @return array Validation result.
	 */
	public static function validate_input( $schema, $input ): array {
		$errors = array();

		if ( empty( $schema ) ) {
			return array(
				'valid'  => true,
				'errors' => array(),
			);
		}

		// Basic JSON Schema validation
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $required_field ) {
				if ( isset( $input[ $required_field ] ) ) {
					continue;
				}

				$errors[] = sprintf( 'Required field "%s" is missing', $required_field );
			}
		}

		// Type validation for properties
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $prop_name => $prop_schema ) {
				if ( ! isset( $input[ $prop_name ] ) || ! isset( $prop_schema['type'] ) ) {
					continue;
				}

				$valid = self::validate_type( $input[ $prop_name ], $prop_schema['type'] );
				if ( $valid ) {
					continue;
				}

				$errors[] = sprintf(
					'Field "%s" should be of type "%s"',
					$prop_name,
					$prop_schema['type']
				);
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Validate value type.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed  $value         Value to validate.
	 * @param string $expected_type Expected type.
	 * @return bool Whether the value matches the expected type.
	 */
	private static function validate_type( $value, $expected_type ): bool {
		switch ( $expected_type ) {
			case 'string':
				return is_string( $value );
			case 'number':
			case 'integer':
				return is_numeric( $value );
			case 'boolean':
				return is_bool( $value );
			case 'array':
				return is_array( $value );
			case 'object':
				return is_object( $value ) || is_array( $value );
			default:
				return true;
		}
	}

	/**
	 * Get ability statistics.
	 *
	 * @since n.e.x.t
	 *
	 * @return array Statistics about registered abilities.
	 */
	public static function get_statistics(): array {
		$abilities = self::get_all_abilities();

		$stats = array(
			'total'       => count( $abilities ),
			'by_provider' => array(
				'Core'   => 0,
				'Plugin' => 0,
				'Theme'  => 0,
			),
		);

		foreach ( $abilities as $ability ) {
			// Count by provider
			if ( ! isset( $ability['provider'] ) ) {
				continue;
			}

			if ( ! isset( $stats['by_provider'][ $ability['provider'] ] ) ) {
				continue;
			}

			++$stats['by_provider'][ $ability['provider'] ];
		}

		return $stats;
	}
}
