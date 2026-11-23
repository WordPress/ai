<?php
/**
 * Deterministic cache key generation.
 *
 * @package WordPress\AI\Cache
 */

declare( strict_types=1 );

namespace WordPress\AI\Cache;

use WordPress\AiClient\Providers\Http\DTO\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Builds stable cache keys from request data.
 *
 * Keys are derived from what is actually sent to the provider, never from
 * runtime values such as timestamps or object identities, so two identical
 * requests always map to the same key.
 *
 * @since 1.6.0
 */
final class Cache_Key {

	/**
	 * Prefix shared by every key this class produces.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const PREFIX = 'wpai_';

	/**
	 * Builds a key for an AI provider HTTP request.
	 *
	 * The HTTP method, URI and body are all that distinguishes one text
	 * generation request from another: the model, prompt, temperature and
	 * system instruction are all encoded in the body by the provider's
	 * request builder. Authentication headers are deliberately excluded.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The SDK request.
	 * @param array<string, mixed>                           $extra   Optional. Additional values that should
	 *                                                                invalidate the key when they change.
	 * @return string The cache key.
	 */
	public static function for_http_request( Request $request, array $extra = array() ): string {
		$parts = array(
			'http',
			$request->getMethod()->value,
			$request->getUri(),
			(string) $request->getBody(),
			WPAI_VERSION,
		);

		if ( ! empty( $extra ) ) {
			$parts[] = self::normalize( $extra );
		}

		/**
		 * Filters the values that make up an HTTP request cache key.
		 *
		 * @since 1.6.0
		 *
		 * @param list<string> $parts The key parts, in order.
		 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The SDK request.
		 * @param array<string, mixed> $extra Additional values supplied by the caller.
		 */
		$parts = (array) apply_filters( 'wpai_cache_key_parts', $parts, $request, $extra );

		return self::make( $parts );
	}

	/**
	 * Builds a cache key from arbitrary parts.
	 *
	 * @since 1.6.0
	 *
	 * @param array<int, mixed> $parts Values that identify the cached value.
	 * @return string The cache key.
	 */
	public static function make( array $parts ): string {
		$normalized = array_map( array( self::class, 'normalize' ), $parts );

		$salt = (string) apply_filters( 'wpai_cache_key_salt', '' );

		$key = self::PREFIX . hash( 'sha256', $salt . '|' . implode( '|', $normalized ) );

		/**
		 * Filters the final cache key.
		 *
		 * @since 1.6.0
		 *
		 * @param string            $key   The generated key.
		 * @param array<int, mixed> $parts The values the key was built from.
		 */
		return (string) apply_filters( 'wpai_cache_key', $key, $parts );
	}

	/**
	 * Converts a value into a stable string.
	 *
	 * Arrays are sorted by key so insertion order cannot change the key.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $value The value to normalize.
	 * @return string The stable representation.
	 */
	private static function normalize( $value ): string {
		if ( is_array( $value ) ) {
			ksort( $value );

			$normalized = array();
			foreach ( $value as $key => $item ) {
				$normalized[ (string) $key ] = self::normalize( $item );
			}

			return (string) wp_json_encode( $normalized );
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \JsonSerializable ) {
				return self::normalize( $value->jsonSerialize() );
			}

			return self::normalize( get_object_vars( $value ) );
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		return (string) $value;
	}
}
