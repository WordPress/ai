<?php
/**
 * Tests for cache key generation.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Cache
 */

namespace WordPress\AI\Tests\Integration\Includes\Cache;

use WP_UnitTestCase;
use WordPress\AI\Cache\Cache_Key;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Cache_Key test case.
 *
 * @since 1.6.0
 *
 * @covers \WordPress\AI\Cache\Cache_Key
 */
class Cache_KeyTest extends WP_UnitTestCase {

	/**
	 * Builds a request.
	 *
	 * @param string $body Request body.
	 * @param string $uri  Request URI.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request The request.
	 */
	private function request( string $body, string $uri = 'https://example.com/v1/chat/completions' ): Request {
		return new Request( HttpMethodEnum::POST(), $uri, array(), $body );
	}

	/**
	 * Identical requests produce identical keys.
	 *
	 * @since 1.6.0
	 */
	public function test_identical_requests_produce_the_same_key(): void {
		$body = '{"model":"m","messages":[{"role":"user","content":"Hi"}]}';

		$this->assertSame(
			Cache_Key::for_http_request( $this->request( $body ) ),
			Cache_Key::for_http_request( $this->request( $body ) )
		);
	}

	/**
	 * A different prompt produces a different key.
	 *
	 * @since 1.6.0
	 */
	public function test_different_bodies_produce_different_keys(): void {
		$this->assertNotSame(
			Cache_Key::for_http_request( $this->request( '{"prompt":"one"}' ) ),
			Cache_Key::for_http_request( $this->request( '{"prompt":"two"}' ) )
		);
	}

	/**
	 * Extra values and salts invalidate the key.
	 *
	 * @since 1.6.0
	 */
	public function test_extra_values_change_the_key(): void {
		$request = $this->request( '{"prompt":"one"}' );

		$this->assertNotSame(
			Cache_Key::for_http_request( $request ),
			Cache_Key::for_http_request( $request, array( 'feature' => 'summarization' ) )
		);
	}

	/**
	 * Keys are prefixed and hashed.
	 *
	 * @since 1.6.0
	 */
	public function test_key_is_prefixed_and_hashed(): void {
		$key = Cache_Key::make( array( 'a', 'b' ) );

		$this->assertStringStartsWith( Cache_Key::PREFIX, $key );
		$this->assertMatchesRegularExpression( '/^wpai_[a-f0-9]{64}$/', $key );
	}

	/**
	 * Array ordering does not change a generic key.
	 *
	 * @since 1.6.0
	 */
	public function test_array_order_does_not_change_the_key(): void {
		$this->assertSame(
			Cache_Key::make( array( array( 'a' => 1, 'b' => 2 ) ) ),
			Cache_Key::make( array( array( 'b' => 2, 'a' => 1 ) ) )
		);
	}
}
