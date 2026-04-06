<?php
/**
 * Local signing backend.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\Signing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs C2PA manifests using a locally-generated RSA keypair.
 *
 * This is the zero-configuration signing tier. A keypair is generated and
 * stored in the WordPress options table on first use. Trust is limited to
 * self-attestation — no third-party CA chain.
 *
 * @since 0.5.0
 */
class Local_Signer implements Signing_Interface {

	/**
	 * Keypair data containing private and public keys.
	 *
	 * @since 0.5.0
	 * @var array{private_key: string, public_key: string}
	 */
	private array $keypair;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param array{private_key: string, public_key: string} $keypair Keypair with private_key and public_key PEM strings.
	 */
	public function __construct( array $keypair ) {
		$this->keypair = $keypair;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Builds a C2PA manifest structure, signs it with SHA-256 using the local
	 * private key, and returns the manifest JSON with the signature embedded.
	 *
	 * @since 0.5.0
	 *
	 * @param string              $content Plain text content to sign.
	 * @param array<string,mixed> $claims  C2PA claims/assertions to embed.
	 * @return string|\WP_Error JSON manifest string or WP_Error on failure.
	 */
	public function sign( string $content, array $claims ) {
		$manifest_data = array(
			'magic'      => base64_encode( "\x43\x32\x50\x41\x54\x58\x54\x00" ),
			'version'    => 1,
			'claims'     => $claims,
			'signer'     => 'local',
			'signed_at'  => gmdate( 'c' ),
			'public_key' => $this->keypair['public_key'],
		);

		$manifest_json = wp_json_encode( $manifest_data );

		if ( false === $manifest_json ) {
			return new \WP_Error(
				'c2pa_manifest_encode_failed',
				esc_html__( 'Failed to encode C2PA manifest for signing.', 'ai' )
			);
		}

		$private_key = openssl_pkey_get_private( $this->keypair['private_key'] );

		if ( false === $private_key ) {
			return new \WP_Error(
				'c2pa_key_load_failed',
				esc_html__( 'Failed to load local private key for C2PA signing.', 'ai' )
			);
		}

		$signature = '';
		$signed    = openssl_sign( $manifest_json, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			return new \WP_Error(
				'c2pa_sign_failed',
				esc_html__( 'OpenSSL signing failed for C2PA manifest.', 'ai' )
			);
		}

		$manifest_data['signature'] = base64_encode( $signature );

		$signed_manifest = wp_json_encode( $manifest_data );

		if ( false === $signed_manifest ) {
			return new \WP_Error(
				'c2pa_manifest_encode_failed',
				esc_html__( 'Failed to encode signed C2PA manifest.', 'ai' )
			);
		}

		return $signed_manifest;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string Always 'local'.
	 */
	public function get_tier(): string {
		return 'local';
	}
}
