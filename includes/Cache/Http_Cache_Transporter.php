<?php
/**
 * HTTP transporter decorator that caches raw AI provider responses.
 *
 * @package WordPress\AI\Cache
 */

declare( strict_types=1 );

namespace WordPress\AI\Cache;

use Throwable;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Caches successful, non-streaming text generation responses.
 *
 * The decorator sits above the provider transport, so it caches exactly what
 * the provider returned and nothing above it has to change. Requests that are
 * not text generation (embeddings, images, speech, batches, model listings)
 * are never cached, and neither are streaming or error responses.
 *
 * Caching is off by default. Enable it site-wide with the `wpai_cache_enabled`
 * option or the `wpai_http_cache_enabled` filter, and target individual
 * requests with the `wpai_http_cache_should_cache` filter.
 *
 * @since 1.6.0
 */
class Http_Cache_Transporter implements HttpTransporterInterface {

	/**
	 * Option that turns response caching on for the site.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const ENABLED_OPTION = 'wpai_cache_enabled';

	/**
	 * Default number of seconds a response stays cached.
	 *
	 * @since 1.6.0
	 *
	 * @var int
	 */
	public const DEFAULT_TTL = HOUR_IN_SECONDS;

	/**
	 * URI fragments that identify endpoints this layer must not cache.
	 *
	 * @since 1.6.0
	 *
	 * @var list<string>
	 */
	private const NON_CACHEABLE_URI_FRAGMENTS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- This is used as an array const.
		'embed',
		'images/generations',
		'image:generate',
		'imagen',
		':predict',
		'audio/',
		'moderations',
		'/models',
		'/batches',
		'/files',
		'/uploads',
		'/responses/cancel',
	);

	/**
	 * The wrapped transporter.
	 *
	 * @since 1.6.0
	 *
	 * @var \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface
	 */
	private HttpTransporterInterface $transporter;

	/**
	 * Cache store.
	 *
	 * @since 1.6.0
	 *
	 * @var \WordPress\AI\Cache\Cache_Store
	 */
	private Cache_Store $store;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $transporter The transporter to wrap.
	 * @param \WordPress\AI\Cache\Cache_Store|null                                  $store       Optional. Cache store. Default a new instance.
	 */
	public function __construct( HttpTransporterInterface $transporter, ?Cache_Store $store = null ) {
		$this->transporter = $transporter;
		$this->store       = $store ?? new Cache_Store();
	}

	/**
	 * Sends a request, serving it from cache when possible.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request to send.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Optional transport options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The response.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		if ( ! $this->should_cache( $request ) ) {
			return $this->transporter->send( $request, $options );
		}

		$key      = Cache_Key::for_http_request( $request );
		$cached   = $this->store->get( $key );
		$response = $this->unwrap( $cached );

		if ( $response instanceof Response ) {
			/**
			 * Fires when a provider response is served from cache.
			 *
			 * @since 1.6.0
			 *
			 * @param string                                        $key     The cache key.
			 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
			 */
			do_action( 'wpai_http_cache_hit', $key, $request );

			return $response;
		}

		$response = $this->transporter->send( $request, $options );

		/**
		 * Fires when a provider response misses the cache.
		 *
		 * @since 1.6.0
		 *
		 * @param string                                        $key     The cache key.
		 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
		 */
		do_action( 'wpai_http_cache_miss', $key, $request );

		if ( $response->isSuccessful() && $this->is_json_body( $response->getBody() ) ) {
			$ttl = $this->get_ttl( $request );

			if ( $ttl > 0 ) {
				$this->store->set( $key, $this->wrap( $response ), $ttl );

				/**
				 * Fires when a provider response is stored in the cache.
				 *
				 * @since 1.6.0
				 *
				 * @param string                                        $key      The cache key.
				 * @param int                                           $ttl      Seconds the response is cached for.
				 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request  The request.
				 */
				do_action( 'wpai_http_cache_store', $key, $ttl, $request );
			}
		}

		return $response;
	}

	/**
	 * Decides whether a request may be cached.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
	 * @return bool True when the response can be cached.
	 */
	private function should_cache( Request $request ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		// Only body-bearing requests can carry a prompt and parameters.
		if ( 'POST' !== strtoupper( $request->getMethod()->value ) ) {
			return false;
		}

		$body = $request->getBody();

		if ( null === $body || '' === $body ) {
			return false;
		}

		// Never cache a streaming response: it is only valid while the
		// connection is open and cannot be replayed.
		$accept = $request->getHeaderAsString( 'Accept' );

		if ( null !== $accept && false !== stripos( $accept, 'text/event-stream' ) ) {
			return false;
		}

		$uri = $request->getUri();

		if ( false !== stripos( $uri, 'alt=sse' ) ) {
			return false;
		}

		foreach ( self::NON_CACHEABLE_URI_FRAGMENTS as $fragment ) {
			if ( false !== stripos( $uri, $fragment ) ) {
				return false;
			}
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			// Only JSON request bodies carry the prompt and parameters this
			// layer can safely key on.
			return false;
		}

		if ( ! empty( $data['stream'] ) ) {
			return false;
		}

		// Multi-modal requests can return images or audio in the response body.
		foreach ( array( 'responseModalities', 'modalities' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				continue;
			}

			$modalities = array_map( 'strtoupper', array_map( 'strval', $data[ $key ] ) );

			if ( array_intersect( $modalities, array( 'IMAGE', 'AUDIO' ) ) ) {
				return false;
			}
		}

		/**
		 * Filters whether a specific provider request may be cached.
		 *
		 * Return false to bypass the cache for a request even when caching is
		 * enabled site-wide. This runs after the built-in exclusions, so it
		 * cannot make streaming or non-text requests cacheable.
		 *
		 * @since 1.6.0
		 *
		 * @param bool                                          $should_cache Whether to cache. Default true.
		 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request      The request.
		 */
		return (bool) apply_filters( 'wpai_http_cache_should_cache', true, $request );
	}

	/**
	 * Returns whether a response body looks like JSON.
	 *
	 * Binary and streamed responses are never written to the cache.
	 *
	 * @since 1.6.0
	 *
	 * @param string|null $body The response body.
	 * @return bool True when the body starts with a JSON container.
	 */
	private function is_json_body( ?string $body ): bool {
		if ( null === $body ) {
			return false;
		}

		$body = ltrim( $body );

		return '' !== $body && ( '{' === $body[0] || '[' === $body[0] );
	}

	/**
	 * Returns whether caching is enabled for the site.
	 *
	 * @since 1.6.0
	 *
	 * @return bool True when enabled.
	 */
	private function is_enabled(): bool {
		$enabled = (bool) get_option( self::ENABLED_OPTION, false );

		/**
		 * Filters whether provider responses may be cached.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $enabled Whether caching is enabled. Default the `wpai_cache_enabled` option.
		 */
		return (bool) apply_filters( 'wpai_http_cache_enabled', $enabled );
	}

	/**
	 * Returns the cache TTL for a request.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
	 * @return int Seconds, or 0 to skip caching.
	 */
	private function get_ttl( Request $request ): int {
		/**
		 * Filters how long a provider response stays cached.
		 *
		 * @since 1.6.0
		 *
		 * @param int                                           $ttl     Seconds. Default one hour.
		 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
		 */
		return max( 0, (int) apply_filters( 'wpai_http_cache_ttl', self::DEFAULT_TTL, $request ) );
	}

	/**
	 * Converts a response into a cacheable value.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The response.
	 * @return array{status: int, headers: array<string, list<string>>, body: string|null} The cached value.
	 */
	private function wrap( Response $response ): array {
		return array(
			'status'  => $response->getStatusCode(),
			'headers' => $response->getHeaders(),
			'body'    => $response->getBody(),
		);
	}

	/**
	 * Rebuilds a response from a cached value.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $cached The cached value.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response|null The response, or null when invalid.
	 */
	private function unwrap( $cached ): ?Response {
		if ( ! is_array( $cached ) || ! isset( $cached['status'], $cached['headers'] ) ) {
			return null;
		}

		$status  = (int) $cached['status'];
		$headers = is_array( $cached['headers'] ) ? $cached['headers'] : array();
		$body    = isset( $cached['body'] ) && is_string( $cached['body'] ) ? $cached['body'] : null;

		try {
			return new Response( $status, $headers, $body );
		} catch ( Throwable $e ) {
			return null;
		}
	}
}
