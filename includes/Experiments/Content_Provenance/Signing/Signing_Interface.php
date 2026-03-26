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
 * @since 0.5.0
 */
interface Signing_Interface {

	/**
	 * Sign content and return the C2PA manifest as a JSON string.
	 *
	 * @since 0.5.0
	 *
	 * @param string               $content Plain text content to sign.
	 * @param array<string,mixed>  $claims  C2PA claims/assertions to embed.
	 * @return string|\WP_Error JSON manifest string or WP_Error on failure.
	 */
	public function sign( string $content, array $claims );

	/**
	 * Returns the trust tier label for this signer.
	 *
	 * @since 0.5.0
	 *
	 * @return string 'local' | 'connected' | 'byok'
	 */
	public function get_tier(): string;
}
