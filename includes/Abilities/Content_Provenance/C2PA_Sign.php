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
 * @since 0.5.0
 */
class C2PA_Sign extends Abstract_Ability {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 */
	public function __construct() {
		parent::__construct(
			'c2pa/sign',
			array(
				'label'       => __( 'C2PA: Sign Content', 'ai' ),
				'description' => __( 'Embed C2PA 2.3 cryptographic provenance into text content. Returns signed text with invisible Unicode watermark.', 'ai' ),
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
				'text'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
					'description'       => esc_html__( 'Plain text content to sign.', 'ai' ),
				),
				'action'   => array(
					'type'              => 'string',
					'enum'              => array( 'c2pa.created', 'c2pa.edited' ),
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'C2PA action type.', 'ai' ),
				),
				'metadata' => array(
					'type'        => 'object',
					'description' => esc_html__( 'Post metadata: title, url, author, post_id.', 'ai' ),
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
				'signed_text' => array(
					'type'        => 'string',
					'description' => esc_html__( 'Text with embedded C2PA provenance.', 'ai' ),
				),
				'manifest'    => array(
					'type'        => 'string',
					'description' => esc_html__( 'JSON manifest string.', 'ai' ),
				),
				'signer_tier' => array(
					'type'        => 'string',
					'description' => esc_html__( 'Signing tier: local, connected, or byok.', 'ai' ),
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
	 * @since 0.5.0
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
	 * @since 0.5.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array( 'show_in_rest' => true );
	}

	/**
	 * Attempt to get the Content_Provenance experiment instance from the registry.
	 *
	 * @since 0.5.0
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Content_Provenance|null
	 */
	private function get_experiment(): ?\WordPress\AI\Experiments\Content_Provenance\Content_Provenance {
		// The experiment registry is not always accessible here; use a filter for loose coupling.
		return apply_filters( 'ai_content_provenance_experiment_instance', null );
	}

	/**
	 * Build a fallback local signer using the stored keypair.
	 *
	 * @since 0.5.0
	 *
	 * @return \WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer
	 */
	private function make_local_signer(): Local_Signer {
		$keypair = get_option( '_c2pa_local_keypair', array() );
		if ( empty( $keypair['private_key'] ) ) {
			$res = openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
				)
			);
			if ( false !== $res ) {
				openssl_pkey_export( $res, $private_key );
				$details    = openssl_pkey_get_details( $res );
				$public_key = is_array( $details ) ? ( $details['key'] ?? '' ) : '';
				$keypair    = array(
					'private_key' => $private_key,
					'public_key'  => $public_key,
				);
				update_option( '_c2pa_local_keypair', $keypair );
			}
		}
		return new Local_Signer( $keypair );
	}
}
