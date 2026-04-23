<?php
/**
 * Local signing backend using EC P-256.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\Signing;

use WordPress\AI\Experiments\Content_Provenance\C2PA\COSE_Sign1_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\Claim_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\JUMBF_Writer;
use WordPress\AI\Experiments\Content_Provenance\Unicode_Embedder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs C2PA manifests using a locally-generated EC P-256 keypair.
 *
 * This is the zero-configuration signing tier. An ECDSA P-256 keypair and
 * self-signed X.509 certificate are generated and stored in WordPress options
 * on first use. Trust is limited to self-attestation — no third-party CA chain.
 *
 * Produces spec-compliant JUMBF manifest stores with COSE_Sign1 signatures.
 *
 * @since x.x.x
 */
class Local_Signer implements Signing_Interface {

	/**
	 * Maximum number of signing passes to converge on an exclusion length.
	 *
	 * The ECDSA signature introduces random byte values, so the VS-encoded
	 * wrapper byte count may vary slightly between passes. This caps the
	 * iteration to prevent unbounded retries.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_EXCLUSION_PASSES = 20;

	/**
	 * Keypair data containing private key PEM and certificate PEM.
	 *
	 * @since x.x.x
	 * @var array{private_key: string, certificate_pem: string}
	 */
	private array $keypair;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param array{private_key: string, certificate_pem: string} $keypair EC P-256 keypair with private_key and certificate_pem strings.
	 */
	public function __construct( array $keypair ) {
		$this->keypair = $keypair;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Builds a spec-compliant C2PA JUMBF manifest store with COSE_Sign1 signature.
	 *
	 * @since x.x.x
	 *
	 * @param string               $content  Plain text content to sign.
	 * @param array<string, mixed> $metadata Post metadata (title, post_id, etc.).
	 * @return string|\WP_Error JUMBF manifest store bytes or WP_Error on failure.
	 */
	public function sign( string $content, array $metadata ) {
		if ( empty( $this->keypair['private_key'] ) ) {
			return new \WP_Error(
				'c2pa_key_load_failed',
				esc_html__( 'Local private key is not available.', 'ai' )
			);
		}

		$certificate_der = self::pem_to_der( $this->keypair['certificate_pem'] ?? '' );

		if ( empty( $certificate_der ) ) {
			return new \WP_Error(
				'c2pa_cert_invalid',
				esc_html__( 'Local certificate is missing or invalid.', 'ai' )
			);
		}

		$manifest_label    = 'urn:uuid:' . wp_generate_uuid4();
		$action            = isset( $metadata['action'] ) ? (string) $metadata['action'] : 'c2pa.created';
		$previous_manifest = isset( $metadata['previous_manifest'] ) ? (string) $metadata['previous_manifest'] : null;
		$private_key       = $this->keypair['private_key'];

		// Compute the exclusion start offset: byte length of NFC-normalized content.
		$nfc_content     = self::nfc_normalize( $content );
		$exclusion_start = strlen( $nfc_content );

		// Iterative build: each pass signs with the declared exclusion length,
		// then checks if the actual VS-encoded wrapper matches. The signature
		// introduces randomness in the byte values, so the wrapper byte count
		// may vary slightly between passes. Typically converges in 1-3 passes.
		$exclusion_length = null;
		$jumbf            = '';

		for ( $pass = 0; $pass <= self::MAX_EXCLUSION_PASSES; $pass++ ) {
			$claim_builder = new Claim_Builder( $content, $action, $metadata, $manifest_label, $previous_manifest );

			if ( null !== $exclusion_length ) {
				$claim_builder->set_exclusions( $exclusion_start, $exclusion_length );
			}

			$claim_result = $claim_builder->build();

			try {
				$cose_builder = new COSE_Sign1_Builder( $private_key, $certificate_der, $claim_result['claim_cbor'] );
				$cose_sign1   = $cose_builder->build();
			} catch ( \RuntimeException $e ) {
				return new \WP_Error(
					'c2pa_sign_failed',
					sprintf(
						/* translators: %s: Error message from the signing operation. */
						esc_html__( 'C2PA signing failed: %s', 'ai' ),
						esc_html( $e->getMessage() )
					)
				);
			}

			$jumbf = JUMBF_Writer::build_manifest_store(
				$claim_result['claim_cbor'],
				$claim_result['assertion_map'],
				$cose_sign1,
				$manifest_label
			);

			$actual_length = Unicode_Embedder::compute_wrapper_byte_length( $jumbf );

			if ( $actual_length === $exclusion_length ) {
				return $jumbf;
			}

			$exclusion_length = $actual_length;
		}

		return $jumbf;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return string Always 'local'.
	 */
	public function get_tier(): string {
		return 'local';
	}

	/**
	 * Normalizes a string to Unicode NFC form.
	 *
	 * @since x.x.x
	 *
	 * @param string $text Input text.
	 * @return string NFC-normalized text, or original if intl extension unavailable.
	 */
	private static function nfc_normalize( string $text ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_C );

			if ( false !== $normalized ) {
				return $normalized;
			}
		}

		return $text;
	}

	/**
	 * Generates an EC P-256 keypair and self-signed X.509 certificate.
	 *
	 * @since x.x.x
	 *
	 * @param string $common_name Certificate common name (defaults to site name).
	 * @return array{private_key: string, certificate_pem: string}|\WP_Error Keypair or error.
	 */
	public static function generate_keypair( string $common_name = '' ) {
		if ( empty( $common_name ) ) {
			$blog_name   = get_bloginfo( 'name' );
			$common_name = '' !== $blog_name ? $blog_name : 'WordPress C2PA Signer';
		}

		$key = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);

		if ( false === $key ) {
			return new \WP_Error(
				'c2pa_keygen_failed',
				esc_html__( 'Failed to generate EC P-256 keypair.', 'ai' )
			);
		}

		$dn  = array( 'commonName' => $common_name );
		$csr = openssl_csr_new( $dn, $key, array( 'digest_alg' => 'sha256' ) );

		if ( false === $csr ) {
			return new \WP_Error(
				'c2pa_csr_failed',
				esc_html__( 'Failed to generate certificate signing request.', 'ai' )
			);
		}

		$cert = openssl_csr_sign( $csr, null, $key, 365, array( 'digest_alg' => 'sha256' ) );

		if ( false === $cert ) {
			return new \WP_Error(
				'c2pa_cert_sign_failed',
				esc_html__( 'Failed to generate self-signed certificate.', 'ai' )
			);
		}

		$private_key_pem = '';
		$certificate_pem = '';
		openssl_pkey_export( $key, $private_key_pem );
		openssl_x509_export( $cert, $certificate_pem );

		return array(
			'private_key'     => $private_key_pem,
			'certificate_pem' => $certificate_pem,
		);
	}

	/**
	 * Converts a PEM-encoded certificate to DER format.
	 *
	 * @since x.x.x
	 *
	 * @param string $pem PEM-encoded certificate.
	 * @return string DER bytes, or empty string on failure.
	 */
	public static function pem_to_der( string $pem ): string {
		$pem_body = preg_replace( '/-----[A-Z ]+-----/', '', $pem );

		if ( null === $pem_body || '' === $pem_body ) {
			return '';
		}

		$decoded = base64_decode( str_replace( array( "\r", "\n", ' ' ), '', $pem_body ), true );

		return false === $decoded ? '' : $decoded;
	}
}
