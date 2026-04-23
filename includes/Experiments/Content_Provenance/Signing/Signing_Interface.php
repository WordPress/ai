<?php
/**
 * Signing backend interface.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\Signing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for C2PA signing backends.
 *
 * Each signing tier (local, connected, BYOK) must implement this interface so
 * the experiment can swap backends without changing calling code.
 *
 * Signers return a JUMBF manifest store (binary bytes) containing a
 * COSE_Sign1-signed C2PA claim. The output is ready for Unicode embedding.
 *
 * @since x.x.x
 */
interface Signing_Interface {

	/**
	 * Sign content and return the C2PA JUMBF manifest store bytes.
	 *
	 * @since x.x.x
	 *
	 * @param string               $content  Plain text content to sign.
	 * @param array<string, mixed> $metadata Post metadata (title, post_id, etc.).
	 * @return string|\WP_Error JUMBF manifest store bytes or WP_Error on failure.
	 */
	public function sign( string $content, array $metadata );

	/**
	 * Returns the trust tier label for this signer.
	 *
	 * @since x.x.x
	 *
	 * @return string 'local' | 'connected' | 'byok'
	 */
	public function get_tier(): string;
}
