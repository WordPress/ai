<?php
/**
 * C2PA Verify WordPress Ability.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Provenance;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Content_Provenance\C2PA_Manifest_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C2PA Verify Ability.
 *
 * Registers `c2pa/verify` as a callable ability. Callers pass the signed text
 * and receive a verification result with status and manifest details.
 *
 * Usage:
 *   wp_do_ability( 'c2pa/verify', [ 'text' => '...' ] )
 *
 * @since 0.5.0
 */
class C2PA_Verify extends Abstract_Ability {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 */
	public function __construct() {
		parent::__construct(
			'c2pa/verify',
			array(
				'label'       => __( 'C2PA: Verify Provenance', 'ai' ),
				'description' => __( 'Extract and verify C2PA 2.3 cryptographic provenance from signed text content. Returns verification status and manifest details.', 'ai' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text' => array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
					'description'       => esc_html__( 'Signed text content to verify.', 'ai' ),
				),
			),
			'required'   => array( 'text' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'verified' => array(
					'type'        => 'boolean',
					'description' => esc_html__( 'Whether the content signature is valid.', 'ai' ),
				),
				'status'   => array(
					'type'        => 'string',
					'description' => esc_html__( 'Verification status message.', 'ai' ),
				),
				'manifest' => array(
					'type'        => array( 'object', 'null' ),
					'description' => esc_html__( 'Decoded manifest object if found, or null.', 'ai' ),
				),
				'error'    => array(
					'type'        => array( 'string', 'null' ),
					'description' => esc_html__( 'Error message if verification failed, or null.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{verified: bool, status: string, manifest: array<string,mixed>|null, error: string|null}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			is_array( $input ) ? $input : array(),
			array(
				'text' => '',
			)
		);

		if ( empty( trim( $args['text'] ) ) ) {
			return new WP_Error( 'c2pa_empty_text', esc_html__( 'Text is required to verify.', 'ai' ) );
		}

		return C2PA_Manifest_Builder::extract_and_verify( $args['text'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Verification is public — no authentication required.
	 *
	 * @since 0.5.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool Always true.
	 */
	protected function permission_callback( $input ): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array( 'show_in_rest' => true );
	}
}
