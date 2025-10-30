<?php
/**
 * Abstract Ability base class.
 *
 * @package WordPress\AI\Abstracts
 */

namespace WordPress\AI\Abstracts;

use WordPress\AI\Exception\Invalid_Ability_Exception;
use WP_Ability;

/**
 * Base implementation for a WordPress Ability.
 *
 * @since 0.1.0
 */
abstract class Abstract_Ability extends WP_Ability {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $name       The name of the ability.
	 * @param array<string,mixed> $properties The properties of the ability. Must include `label`.
	 *
	 * @throws \WordPress\AI\Exception\Invalid_Ability_Exception Thrown if the label property is missing or invalid.
	 */
	public function __construct( string $name, array $properties = array() ) {
		if ( ! isset( $properties['label'] ) || ! is_string( $properties['label'] ) ) {
			throw new Invalid_Ability_Exception( esc_html__( 'The "label" property is required and must be a string.', 'ai' ) );
		}

		parent::__construct(
			$name,
			array(
				'label'               => $properties['label'],
				'description'         => $this->description(),
				'category'            => $this->category(),
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute_callback' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $this->meta(),
			)
		);
	}

	/**
	 * Returns the description of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return string The description of the ability.
	 */
	abstract protected function description(): string;

	/**
	 * Returns the category of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return string The category of the ability.
	 */
	abstract protected function category(): string;

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	abstract protected function input_schema(): array;

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	abstract protected function output_schema(): array;

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $args The input arguments to the ability.
	 * @return mixed|WP_Error The result of the ability execution, or a WP_Error on failure.
	 */
	abstract protected function execute_callback( $args );

	/**
	 * Checks whether the current user has permission to execute the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $args The input arguments to the ability.
	 * @return bool|WP_Error True if the user has permission, false or WP_Error otherwise.
	 */
	abstract protected function permission_callback( $args );

	/**
	 * Returns the meta of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	abstract protected function meta(): array;
}
