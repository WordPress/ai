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
 * @since x.x.x
 */
class C2PA_Verify extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'Post ID to verify. Reads the canonical signed bytes from meta.', 'ai' ),
				),
				'text'    => array(
					'type'        => 'string',
					'description' => esc_html__( 'Signed text content to verify. Prefer post_id when available.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'verified' => array(
					'type'        => 'boolean',
					'description' => esc_html__( 'Whether the content integrity check passed.', 'ai' ),
				),
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'verified', 'legacy_verified', 'unsigned', 'tampered', 'invalid' ),
					'description' => esc_html__( 'Verification status.', 'ai' ),
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
	 * @since x.x.x
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{verified: bool, status: string, manifest: array<string,mixed>|null, error: string|null}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			is_array( $input ) ? $input : array(),
			array(
				'post_id' => 0,
				'text'    => '',
			)
		);

		$text = $args['text'];

		// Prefer post_id: read the canonical signed bytes from meta.
		// This avoids sanitize_text_field stripping the invisible
		// variation selectors that carry the C2PA wrapper.
		if ( $args['post_id'] ) {
			$embedded = get_post_meta( (int) $args['post_id'], '_c2pa_embedded_content', true );
			if ( $embedded ) {
				$text = (string) $embedded;
			}
		}

		if ( empty( trim( $text ) ) ) {
			return new WP_Error( 'c2pa_empty_text', esc_html__( 'Text or post_id is required to verify.', 'ai' ) );
		}

		return C2PA_Manifest_Builder::extract_and_verify( $text );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Verification is public — no authentication required.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array( 'show_in_rest' => true );
	}
}
