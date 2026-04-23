<?php
/**
 * Bring-Your-Own-Key (BYOK) signing backend.
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
 * Signs C2PA manifests using a publisher-supplied EC private key and certificate.
 *
 * BYOK allows publishers to use their own CA-issued certificate for C2PA signing.
 * The private key and certificate are loaded from filesystem paths configured in
 * the experiment settings. This tier offers the highest trust level since the
 * certificate can be issued by a C2PA trust list CA (SSL.com, DigiCert, etc.).
 *
 * @since x.x.x
 */
class BYOK_Signer implements Signing_Interface {

	/**
	 * Maximum number of signing passes to converge on an exclusion length.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_EXCLUSION_PASSES = 20;

	/**
	 * Filesystem path to the PEM-encoded private key.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private string $key_path;

	/**
	 * Filesystem path to the PEM-encoded X.509 certificate (chain).
	 *
	 * @since x.x.x
	 * @var string
	 */
	private string $cert_path;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string $key_path  Filesystem path to a PEM-encoded private key file.
	 * @param string $cert_path Filesystem path to a PEM-encoded certificate file.
	 */
	public function __construct( string $key_path, string $cert_path = '' ) {
		$this->key_path  = $key_path;
		$this->cert_path = $cert_path;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Loads the publisher's private key and certificate, builds a spec-compliant
	 * C2PA JUMBF manifest store with COSE_Sign1 signature.
	 *
	 * @since x.x.x
	 *
	 * @param string               $content  Plain text content to sign.
	 * @param array<string, mixed> $metadata Post metadata (title, post_id, etc.).
	 * @return string|\WP_Error JUMBF manifest store bytes or WP_Error on failure.
	 */
	public function sign( string $content, array $metadata ) {
		if ( empty( $this->key_path ) ) {
			return new \WP_Error(
				'c2pa_byok_no_cert',
				esc_html__( 'BYOK private key path is not configured.', 'ai' )
			);
		}

		$safe_key_path = self::validate_file_path( $this->key_path );

		if ( is_wp_error( $safe_key_path ) ) {
			return $safe_key_path;
		}

		if ( ! is_readable( $safe_key_path ) ) {
			return new \WP_Error(
				'c2pa_byok_cert_unreadable',
				esc_html__( 'BYOK private key file is not readable. Check the path and permissions.', 'ai' )
			);
		}

		$private_key_pem = file_get_contents( $safe_key_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading local PEM key file.

		if ( false === $private_key_pem ) {
			return new \WP_Error(
				'c2pa_byok_key_read_failed',
				esc_html__( 'Failed to read BYOK private key file.', 'ai' )
			);
		}

		// Load and validate the private key.
		$key = openssl_pkey_get_private( $private_key_pem );

		if ( false === $key ) {
			return new \WP_Error(
				'c2pa_byok_key_load_failed',
				esc_html__( 'Failed to load BYOK private key. Ensure the file is a valid PEM-encoded EC private key.', 'ai' )
			);
		}

		// Load certificate.
		$certificate_der = $this->load_certificate_der();

		if ( is_wp_error( $certificate_der ) ) {
			return $certificate_der;
		}

		$manifest_label    = 'urn:uuid:' . wp_generate_uuid4();
		$action            = isset( $metadata['action'] ) ? (string) $metadata['action'] : 'c2pa.created';
		$previous_manifest = isset( $metadata['previous_manifest'] ) ? (string) $metadata['previous_manifest'] : null;

		// Compute the exclusion start offset: byte length of NFC-normalized content.
		$exclusion_start = strlen( self::nfc_normalize( $content ) );

		// Iterative build with exclusion convergence (see Local_Signer for details).
		$exclusion_length = null;
		$jumbf            = '';

		for ( $pass = 0; $pass <= self::MAX_EXCLUSION_PASSES; $pass++ ) {
			$claim_builder = new Claim_Builder( $content, $action, $metadata, $manifest_label, $previous_manifest );

			if ( null !== $exclusion_length ) {
				$claim_builder->set_exclusions( $exclusion_start, $exclusion_length );
			}

			$claim_result = $claim_builder->build();

			try {
				$cose_builder = new COSE_Sign1_Builder( $private_key_pem, $certificate_der, $claim_result['claim_cbor'] );
				$cose_sign1   = $cose_builder->build();
			} catch ( \RuntimeException $e ) {
				return new \WP_Error(
					'c2pa_byok_sign_failed',
					sprintf(
						/* translators: %s: Error message from the signing operation. */
						esc_html__( 'BYOK C2PA signing failed: %s', 'ai' ),
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
	 * @return string Always 'byok'.
	 */
	public function get_tier(): string {
		return 'byok';
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
	 * Loads the certificate from the configured path and returns DER bytes.
	 *
	 * @since x.x.x
	 *
	 * @return string|\WP_Error DER certificate bytes or WP_Error.
	 */
	private function load_certificate_der() {
		if ( empty( $this->cert_path ) ) {
			return new \WP_Error(
				'c2pa_byok_no_cert_file',
				esc_html__( 'BYOK certificate path is not configured.', 'ai' )
			);
		}

		$safe_cert_path = self::validate_file_path( $this->cert_path );

		if ( is_wp_error( $safe_cert_path ) ) {
			return $safe_cert_path;
		}

		if ( ! is_readable( $safe_cert_path ) ) {
			return new \WP_Error(
				'c2pa_byok_cert_file_unreadable',
				esc_html__( 'BYOK certificate file is not readable.', 'ai' )
			);
		}

		$cert_pem = file_get_contents( $safe_cert_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading local PEM certificate file.

		if ( false === $cert_pem ) {
			return new \WP_Error(
				'c2pa_byok_cert_read_failed',
				esc_html__( 'Failed to read BYOK certificate file.', 'ai' )
			);
		}

		$der = Local_Signer::pem_to_der( $cert_pem );

		if ( '' === $der ) {
			return new \WP_Error(
				'c2pa_byok_cert_invalid',
				esc_html__( 'BYOK certificate is not a valid PEM-encoded X.509 certificate.', 'ai' )
			);
		}

		return $der;
	}

	/**
	 * Validates a file path to prevent path traversal attacks.
	 *
	 * Resolves the real path and ensures it falls within ABSPATH or WP_CONTENT_DIR.
	 *
	 * @since x.x.x
	 *
	 * @param string $path File path to validate.
	 * @return string|\WP_Error Resolved real path, or WP_Error if path is unsafe.
	 */
	private static function validate_file_path( string $path ) {
		$real_path = realpath( $path );

		if ( false === $real_path ) {
			return new \WP_Error(
				'c2pa_byok_path_invalid',
				esc_html__( 'BYOK file path does not exist or cannot be resolved.', 'ai' )
			);
		}

		$allowed_roots = array(
			realpath( ABSPATH ),
			defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : null,
		);

		$allowed_roots = array_filter( $allowed_roots );

		foreach ( $allowed_roots as $root ) {
			if ( 0 === strpos( $real_path, $root . DIRECTORY_SEPARATOR ) ) {
				return $real_path;
			}
		}

		return new \WP_Error(
			'c2pa_byok_path_traversal',
			esc_html__( 'BYOK file path must be within the WordPress installation directory.', 'ai' )
		);
	}
}
