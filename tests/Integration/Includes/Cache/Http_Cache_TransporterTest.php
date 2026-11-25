<?php
/**
 * Tests for the caching HTTP transporter.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Cache
 */

namespace WordPress\AI\Tests\Integration\Includes\Cache;

use WP_UnitTestCase;
use WordPress\AI\Cache\Cache_Store;
use WordPress\AI\Cache\Http_Cache_Transporter;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Counts calls and returns a fixed response.
 */
class Counting_Transporter implements HttpTransporterInterface {

	/**
	 * Number of calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Status to return.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Body to return.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Constructor.
	 *
	 * @param int    $status Status code.
	 * @param string $body   Body.
	 */
	public function __construct( int $status = 200, string $body = '{"ok":true}' ) {
		$this->status = $status;
		$this->body   = $body;
	}

	/**
	 * Sends a request.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request Request.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response Response.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		++$this->calls;

		return new Response( $this->status, array( 'Content-Type' => array( 'application/json' ) ), $this->body );
	}
}

/**
 * Http_Cache_Transporter test case.
 *
 * @since 1.6.0
 *
 * @covers \WordPress\AI\Cache\Http_Cache_Transporter
 */
class Http_Cache_TransporterTest extends WP_UnitTestCase {

	/**
	 * Enables caching for the test.
	 *
	 * @since 1.6.0
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( Http_Cache_Transporter::ENABLED_OPTION, true );
	}

	/**
	 * Restores caching state.
	 *
	 * @since 1.6.0
	 */
	public function tear_down(): void {
		delete_option( Http_Cache_Transporter::ENABLED_OPTION );
		remove_all_filters( 'wpai_http_cache_enabled' );
		remove_all_filters( 'wpai_http_cache_should_cache' );
		remove_all_filters( 'wpai_http_cache_ttl' );

		parent::tear_down();
	}

	/**
	 * Builds a request.
	 *
	 * @param string $body Body.
	 * @param string $uri  URI.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request Request.
	 */
	private function request( string $body, string $uri = 'https://example.com/v1/chat/completions' ): Request {
		return new Request( HttpMethodEnum::POST(), $uri, array( 'Content-Type' => array( 'application/json' ) ), $body );
	}

	/**
	 * A repeated request is served from cache.
	 *
	 * @since 1.6.0
	 */
	public function test_second_request_is_a_cache_hit(): void {
		$inner       = new Counting_Transporter();
		$transporter = new Http_Cache_Transporter( $inner, new Cache_Store() );
		$request     = $this->request( '{"model":"m","messages":[],"prompt":"cache-hit-test"}' );

		$first  = $transporter->send( $request );
		$second = $transporter->send( $request );

		$this->assertSame( 1, $inner->calls );
		$this->assertSame( $first->getBody(), $second->getBody() );
		$this->assertSame( $first->getStatusCode(), $second->getStatusCode() );
	}

	/**
	 * Caching is off unless enabled.
	 *
	 * @since 1.6.0
	 */
	public function test_disabled_cache_always_calls_the_provider(): void {
		delete_option( Http_Cache_Transporter::ENABLED_OPTION );

		$inner       = new Counting_Transporter();
		$transporter = new Http_Cache_Transporter( $inner, new Cache_Store() );
		$request     = $this->request( '{"prompt":"disabled-cache-test"}' );

		$transporter->send( $request );
		$transporter->send( $request );

		$this->assertSame( 2, $inner->calls );
	}

	/**
	 * Streaming requests are never cached.
	 *
	 * @since 1.6.0
	 */
	public function test_streaming_requests_are_not_cached(): void {
		$inner       = new Counting_Transporter();
		$transporter = new Http_Cache_Transporter( $inner, new Cache_Store() );
		$request     = $this->request( '{"stream":true,"prompt":"stream-test"}' );

		$transporter->send( $request );
		$transporter->send( $request );

		$this->assertSame( 2, $inner->calls );
	}

	/**
	 * Embedding requests are not cached.
	 *
	 * @since 1.6.0
	 */
	public function test_embedding_requests_are_not_cached(): void {
		$inner       = new Counting_Transporter();
		$transporter = new Http_Cache_Transporter( $inner, new Cache_Store() );
		$request     = $this->request( '{"input":"embedding-test"}', 'https://example.com/v1/embeddings' );

		$transporter->send( $request );
		$transporter->send( $request );

		$this->assertSame( 2, $inner->calls );
	}

	/**
	 * Error responses are not cached.
	 *
	 * @since 1.6.0
	 */
	public function test_error_responses_are_not_cached(): void {
		$inner       = new Counting_Transporter( 500, '{"error":"nope"}' );
		$transporter = new Http_Cache_Transporter( $inner, new Cache_Store() );
		$request     = $this->request( '{"prompt":"error-test"}' );

		$transporter->send( $request );
		$transporter->send( $request );

		$this->assertSame( 2, $inner->calls );
	}

	/**
	 * A per-request filter can bypass the cache.
	 *
	 * @since 1.6.0
	 */
	public function test_filter_can_bypass_the_cache(): void {
		add_filter( 'wpai_http_cache_should_cache', '__return_false' );

		$inner       = new Counting_Transporter();
		$transporter = new Http_Cache_Transporter( $inner, new Cache_Store() );
		$request     = $this->request( '{"prompt":"bypass-test"}' );

		$transporter->send( $request );
		$transporter->send( $request );

		$this->assertSame( 2, $inner->calls );
	}
}
