<?php
/**
 * C2PA Sign WordPress Ability.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Provenance;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Content_Provenance\C2PA_Manifest_Builder;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Unicode_Embedder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C2PA Sign Ability.
 *
 * Registers `c2pa/sign` as a callable ability. Other experiments and plugins call:
 *   wp_do_ability( 'c2pa/sign', [ 'text' => '...', 'metadata' => [...] ] )
 *
 * Returns the signed text (with embedded Unicode provenance) on success.
 *
 * @since x.x.x
 */
class C2PA_Sign extends Abstract_Ability {

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
				'text'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Text content to sign.', 'ai' ),
				),
				'action'   => array(
					'type'              => 'string',
					'enum'              => array( 'c2pa.created', 'c2pa.edited' ),
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'C2PA action type.', 'ai' ),
				),
				'metadata' => array(
					'type'        => 'object',
					'properties'  => array(
						'title'   => array( 'type' => 'string' ),
						'url'     => array( 'type' => 'string' ),
						'author'  => array( 'type' => 'string' ),
						'post_id' => array( 'type' => 'integer' ),
					),
					'description' => esc_html__( 'Post metadata: title, url, author, post_id.', 'ai' ),
				),
			),
			'required'   => array( 'text' ),
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
				'signed_text' => array(
					'type'        => 'string',
					'description' => esc_html__( 'Text with embedded C2PA provenance.', 'ai' ),
				),
				'manifest'    => array(
					'type'        => 'string',
					'description' => esc_html__( 'JUMBF manifest store bytes.', 'ai' ),
				),
				'signer_tier' => array(
					'type'        => 'string',
					'enum'        => array( 'local', 'connected', 'byok' ),
					'description' => esc_html__( 'Signing tier used.', 'ai' ),
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
	 * @return array{signed_text: string, manifest: string, signer_tier: string}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			is_array( $input ) ? $input : array(),
			array(
				'text'     => '',
				'action'   => 'c2pa.created',
				'metadata' => array(),
			)
		);

		if ( empty( trim( $args['text'] ) ) ) {
			return new WP_Error( 'c2pa_empty_text', esc_html__( 'Text is required to sign.', 'ai' ) );
		}

		// Use the Content_Provenance experiment's signer if available, otherwise fall back to local.
		$experiment = $this->get_experiment();
		$signer     = $experiment ? $experiment->get_public_signer() : $this->make_local_signer();

		$result = C2PA_Manifest_Builder::build(
			$args['text'],
			$args['action'],
			null,
			is_array( $args['metadata'] ) ? $args['metadata'] : array(),
			$signer
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$signed_text = Unicode_Embedder::embed( $args['text'], $result['manifest'] );

		return array(
			'signed_text' => $signed_text,
			'manifest'    => $result['manifest'],
			'signer_tier' => $signer->get_tier(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool True if the user has permission.
	 */
	protected function permission_callback( $input ): bool {
		return current_user_can( 'edit_posts' );
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

	/**
	 * Attempt to get the Content_Provenance experiment instance from the registry.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Content_Provenance|null
	 */
	private function get_experiment(): ?\WordPress\AI\Experiments\Content_Provenance\Content_Provenance {
		return apply_filters( 'wpai_content_provenance_experiment_instance', null );
	}

	/**
	 * Build a fallback local signer using the stored keypair.
	 *
	 * Generates a new EC P-256 keypair if none exists.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer
	 */
	private function make_local_signer(): Local_Signer {
		$keypair = get_option( '_c2pa_local_keypair', array() );

		if ( ! is_array( $keypair ) || empty( $keypair['private_key'] ) || empty( $keypair['certificate_pem'] ) ) {
			$keypair = Local_Signer::generate_keypair();

			if ( is_wp_error( $keypair ) ) {
				return new Local_Signer(
					array(
						'private_key'     => '',
						'certificate_pem' => '',
					)
				);
			}

			update_option( '_c2pa_local_keypair', $keypair, false );
		}

		/** @var array{private_key: string, certificate_pem: string} $keypair */
		return new Local_Signer( $keypair );
	}
}
