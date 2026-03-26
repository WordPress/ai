<?php
/**
 * C2PA manifest builder.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance;

use WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and verifies C2PA content manifests.
 *
 * Constructs the claim structure required by the C2PA 2.3 text authentication
 * specification (Section A.7), delegates signing to the configured backend,
 * and provides a symmetric verification path for published content.
 *
 * @since 0.5.0
 */
class C2PA_Manifest_Builder {

	/**
	 * C2PA text magic byte sequence (ASCII "C2PATXT\0").
	 *
	 * Identifies the payload as a C2PA text manifest container per spec §A.7.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const MAGIC = "\x43\x32\x50\x41\x54\x58\x54\x00";

	/**
	 * Manifest format version.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Build a C2PA manifest for the given content.
	 *
	 * Constructs the full claim set (actions, hash, soft-binding, optional
	 * ingredient chain), delegates to the signer, and returns the signed
	 * manifest JSON alongside the content hash for post-meta storage.
	 *
	 * @since 0.5.0
	 *
	 * @param string              $content           Plain text content.
	 * @param string              $action            'c2pa.created' or 'c2pa.edited'.
	 * @param string|null         $previous_manifest Previous manifest JSON for ingredient chain (edit flow).
	 * @param array<string,mixed> $metadata          Post metadata: title, url, author, post_id.
	 * @param \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface   $signer            Signing backend to use.
	 * @return array{manifest: string, content_hash: string}|\WP_Error Signed manifest and hash, or error.
	 */
	public static function build(
		string $content,
		string $action,
		?string $previous_manifest,
		array $metadata,
		Signing_Interface $signer
	) {
		$content_hash = hash( 'sha256', $content );

		$claims = array(
			'title'        => $metadata['title'] ?? '',
			'author'       => $metadata['author'] ?? get_bloginfo( 'name' ),
			'url'          => $metadata['url'] ?? '',
			'post_id'      => $metadata['post_id'] ?? 0,
			'generated_at' => gmdate( 'c' ),
			'generator'    => 'WordPress/AI Content Provenance Experiment',
			'assertions'   => array(
				'c2pa.actions.v1'      => array(
					'action'            => $action,
					'digitalSourceType' => 'humanEdited',
				),
				'c2pa.hash.data.v1'    => array(
					'algorithm' => 'sha256',
					'hash'      => $content_hash,
				),
				'c2pa.soft_binding.v1' => array(
					'alg'             => 'vs16',
					'document_length' => mb_strlen( $content ),
				),
			),
		);

		// Add ingredient reference for edited content.
		if ( 'c2pa.edited' === $action && null !== $previous_manifest ) {
			$claims['assertions']['c2pa.ingredient.v2'] = array(
				'relationship'  => 'parentOf',
				'dc:title'      => $metadata['title'] ?? '',
				'thumbnail'     => null,
				'manifest_data' => $previous_manifest,
			);
		}

		$manifest_json = wp_json_encode(
			array(
				'magic'   => base64_encode( self::MAGIC ),
				'version' => self::VERSION,
				'claims'  => $claims,
			)
		);

		if ( ! $manifest_json ) {
			return new \WP_Error(
				'c2pa_manifest_encode_failed',
				esc_html__( 'Failed to encode C2PA manifest.', 'ai' )
			);
		}

		$result = $signer->sign( $content, $claims );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'manifest'     => $result,
			'content_hash' => $content_hash,
		);
	}

	/**
	 * Extract and verify C2PA provenance from text.
	 *
	 * Extracts embedded variation-selector data, decodes the manifest JSON,
	 * and validates the SHA-256 content hash against the stripped plain text.
	 * Signature cryptographic verification is intentionally out of scope here
	 * and delegated to the relevant ability class.
	 *
	 * @since 0.5.0
	 *
	 * @param string $text Text that may contain embedded Unicode provenance.
	 * @return array{verified: bool, status: string, manifest: array<string, mixed>|null, error: string|null}
	 */
	public static function extract_and_verify( string $text ): array {
		$embedded = Unicode_Embedder::extract( $text );

		if ( null === $embedded ) {
			return array(
				'verified' => false,
				'status'   => 'unsigned',
				'manifest' => null,
				'error'    => null,
			);
		}

		$manifest = json_decode( $embedded, true );

		if ( ! is_array( $manifest ) ) {
			return array(
				'verified' => false,
				'status'   => 'invalid',
				'manifest' => null,
				'error'    => 'Could not parse manifest',
			);
		}

		// Verify content hash against stripped plain text.
		$plain_text   = Unicode_Embedder::strip( $text );
		$content_hash = hash( 'sha256', $plain_text );
		$stored_hash  = $manifest['claims']['assertions']['c2pa.hash.data.v1']['hash'] ?? null;

		if ( $stored_hash !== $content_hash ) {
			return array(
				'verified' => false,
				'status'   => 'tampered',
				'manifest' => $manifest,
				'error'    => 'Content hash mismatch',
			);
		}

		return array(
			'verified' => true,
			'status'   => 'verified',
			'manifest' => $manifest,
			'error'    => null,
		);
	}
}
