<?php
/**
 * Bring-Your-Own-Key (BYOK) signing backend.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\Signing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs C2PA manifests using a publisher-supplied private key.
 *
 * BYOK allows publishers to use their own certificate infrastructure. The
 * private key is loaded from a filesystem path configured in the experiment
 * settings. This tier offers the highest trust level since the key can be
 * backed by a recognised CA chain.
 *
 * @since 0.5.0
 */
class BYOK_Signer implements Signing_Interface {

	/**
	 * Filesystem path to the PEM-encoded private key.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private string $cert_path;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param string $cert_path Filesystem path to a PEM-encoded private key file.
	 */
	public function __construct( string $cert_path ) {
		$this->cert_path = $cert_path;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Loads the publisher's private key from disk, builds a C2PA manifest
	 * structure, signs it with SHA-256, and returns the manifest JSON with
	 * the signature embedded as base64.
	 *
	 * @since 0.5.0
	 *
	 * @param string              $content Plain text content to sign.
	 * @param array<string,mixed> $claims  C2PA claims/assertions to embed.
	 * @return string|\WP_Error JSON manifest string or WP_Error on failure.
	 */
	public function sign( string $content, array $claims ) {
		if ( empty( $this->cert_path ) ) {
			return new \WP_Error(
				'c2pa_byok_no_cert',
				esc_html__( 'BYOK certificate path is not configured.', 'ai' )
			);
		}

		if ( ! is_readable( $this->cert_path ) ) {
			return new \WP_Error(
				'c2pa_byok_cert_unreadable',
				esc_html__( 'BYOK certificate file is not readable. Check the path and permissions.', 'ai' )
			);
		}

		$private_key = openssl_pkey_get_private( 'file://' . $this->cert_path );

		if ( false === $private_key ) {
			return new \WP_Error(
				'c2pa_byok_key_load_failed',
				esc_html__( 'Failed to load BYOK private key. Ensure the file is a valid PEM-encoded private key.', 'ai' )
			);
		}

		$key_details = openssl_pkey_get_details( $private_key );
		$public_key  = is_array( $key_details ) ? ( $key_details['key'] ?? '' ) : '';

		$manifest_data = array(
			'magic'      => base64_encode( "\x43\x32\x50\x41\x54\x58\x54\x00" ),
			'version'    => 1,
			'claims'     => $claims,
			'signer'     => 'byok',
			'signed_at'  => gmdate( 'c' ),
			'public_key' => $public_key,
		);

		$manifest_json = wp_json_encode( $manifest_data );

		if ( false === $manifest_json ) {
			return new \WP_Error(
				'c2pa_manifest_encode_failed',
				esc_html__( 'Failed to encode C2PA manifest for BYOK signing.', 'ai' )
			);
		}

		$signature = '';
		$signed    = openssl_sign( $manifest_json, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			return new \WP_Error(
				'c2pa_byok_sign_failed',
				esc_html__( 'OpenSSL BYOK signing failed for C2PA manifest.', 'ai' )
			);
		}

		$manifest_data['signature'] = base64_encode( $signature );

		$signed_manifest = wp_json_encode( $manifest_data );

		if ( false === $signed_manifest ) {
			return new \WP_Error(
				'c2pa_manifest_encode_failed',
				esc_html__( 'Failed to encode signed BYOK C2PA manifest.', 'ai' )
			);
		}

		return $signed_manifest;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string Always 'byok'.
	 */
	public function get_tier(): string {
		return 'byok';
	}
}
