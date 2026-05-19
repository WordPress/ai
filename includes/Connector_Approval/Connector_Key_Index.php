<?php
/**
 * Builds and queries a lookup of AI connector credentials and base URLs to connector IDs.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;

use function WordPress\AI\get_ai_connectors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Attributes outbound HTTP requests to an AI connector.
 *
 * Two matching strategies are layered: a credential index (API keys read from
 * each connector's configured setting/env/constant) is tried first, and a
 * provider base-URL index (host + port from each provider class's `url()`) is
 * used as a fallback for keyless providers such as Ollama.
 *
 * Credentials are kept in plaintext for substring scanning. They are already
 * in memory for the lifetime of each request because the connector plugins
 * themselves have read them — this class does not introduce new exposure and
 * never persists or logs key material.
 *
 * @since 1.0.0
 */
final class Connector_Key_Index {
	/**
	 * Minimum length an API key must have to be considered for matching.
	 *
	 * Short strings would produce false positives when scanning unrelated
	 * headers; real provider keys are consistently longer than this bound.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const MIN_KEY_LENGTH = 10;

	/**
	 * Mapping of connector credential → connector ID.
	 *
	 * Lazily populated on first lookup.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string>|null
	 */
	private ?array $key_to_connector = null;

	/**
	 * Mapping of normalized `host[:port]` → connector ID.
	 *
	 * Built from each registered AI provider class's base URL. Lazily populated
	 * on first lookup alongside the credential map.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string>|null
	 */
	private ?array $host_to_connector = null;

	/**
	 * Finds the connector ID whose credential or base URL matches the request.
	 *
	 * Credentials are tried first: the URL and every header value are scanned
	 * for any configured key, in both raw and `rawurlencode()` forms so keys
	 * serialized into a query string still match. If no credential matches,
	 * the request URL's host (and port) is compared to each registered
	 * provider's base URL, which lets keyless providers such as Ollama be
	 * attributed. Returns `null` when neither strategy matches.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Request arguments passed to `pre_http_request`.
	 * @param string $url The fully-qualified request URL.
	 * @return string|null Connector ID, or null if nothing matched.
	 */
	public function lookup( array $args, string $url ): ?string {
		$keys = $this->get_keys();

		if ( array() !== $keys ) {
			$haystacks = $this->collect_haystacks( $args, $url );

			foreach ( $keys as $key => $connector_id ) {
				$encoded_key = rawurlencode( $key );

				foreach ( $haystacks as $haystack ) {
					if ( str_contains( $haystack, $key ) ) {
						return $connector_id;
					}

					if ( $encoded_key !== $key && str_contains( $haystack, $encoded_key ) ) {
						return $connector_id;
					}
				}
			}
		}

		return $this->lookup_by_url( $url );
	}

	/**
	 * Clears the cached indices so the next lookup rebuilds them.
	 *
	 * Useful when connector credentials or provider registrations change during
	 * the same request (tests, long-running CLI scripts). Production requests
	 * get a fresh index anyway.
	 *
	 * @since 1.0.0
	 */
	public function invalidate(): void {
		$this->key_to_connector  = null;
		$this->host_to_connector = null;
	}

	/**
	 * Returns the credential → connector_id map, building both indices on first access.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function get_keys(): array {
		$this->build_indices();

		return (array) $this->key_to_connector;
	}

	/**
	 * Returns the host → connector_id map, building both indices on first access.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function get_hosts(): array {
		$this->build_indices();

		return (array) $this->host_to_connector;
	}

	/**
	 * Populates both the credential and host indices in a single pass.
	 *
	 * The credential map is built for every registered `ai_provider` connector
	 * that exposes auth metadata, so the existing key-scanning path keeps
	 * working even for connectors whose provider class isn't registered with
	 * the AI client. The host map is additionally populated when the provider
	 * class is resolvable through the AI client registry and extends
	 * `AbstractApiProvider`, which is the only contract that guarantees a
	 * static `url()` method.
	 *
	 * @since 1.0.0
	 */
	private function build_indices(): void {
		if ( null !== $this->key_to_connector && null !== $this->host_to_connector ) {
			return;
		}

		$ai_registry             = AiClient::defaultRegistry();
		$this->key_to_connector  = array();
		$this->host_to_connector = array();

		foreach ( get_ai_connectors() as $connector_id => $data ) {
			if ( $ai_registry->hasProvider( $connector_id ) ) {
				$provider_class_name = $ai_registry->getProviderClassName( $connector_id );

				if ( is_subclass_of( $provider_class_name, AbstractApiProvider::class ) ) {
					$host_key = self::host_port_key( $provider_class_name::url() );
					if ( null !== $host_key && ! isset( $this->host_to_connector[ $host_key ] ) ) {
						$this->host_to_connector[ $host_key ] = $connector_id;
					}
				}
			}

			$auth = isset( $data['authentication'] ) && is_array( $data['authentication'] )
				? $data['authentication']
				: array();

			foreach ( $this->read_credentials( $auth ) as $credential ) {
				if ( strlen( $credential ) < self::MIN_KEY_LENGTH ) {
					continue;
				}

				$this->key_to_connector[ $credential ] = $connector_id;
			}
		}
	}

	/**
	 * Attempts to attribute a request to a connector via its base URL.
	 *
	 * Compares the request URL's host (and port, when non-default) to each
	 * registered provider's base URL. This is the fallback for providers that
	 * don't require a credential (e.g. Ollama) where the key-scan path cannot
	 * match.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Request URL.
	 * @return string|null Connector ID, or null if no provider URL matched.
	 */
	private function lookup_by_url( string $url ): ?string {
		if ( '' === $url ) {
			return null;
		}

		$hosts = $this->get_hosts();
		if ( array() === $hosts ) {
			return null;
		}

		$host_key = self::host_port_key( $url );
		if ( null === $host_key ) {
			return null;
		}

		return $hosts[ $host_key ] ?? null;
	}

	/**
	 * Normalizes a URL to a `host[:port]` key for host-based equality matching.
	 *
	 * Using a structured comparison instead of `str_contains()` on the full URL
	 * avoids false positives like `https://api.openai.com.evil.example/foo`
	 * matching a provider whose base URL is `https://api.openai.com/v1`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to normalize.
	 * @return string|null Lowercased `host[:port]`, or null if no host is parseable.
	 */
	private static function host_port_key( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
			return null;
		}

		$key = strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$key .= ':' . $parts['port'];
		}

		return $key;
	}

	/**
	 * Returns every credential string configured for a given authentication block.
	 *
	 * Checks the DB-stored option, a declared environment variable, and a
	 * declared PHP constant. A connector may populate any one of the three.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $auth Authentication metadata from a connector registration.
	 * @return list<string>
	 */
	private function read_credentials( array $auth ): array {
		$credentials = array();

		$setting_name = isset( $auth['setting_name'] ) && is_string( $auth['setting_name'] ) ? $auth['setting_name'] : '';
		if ( '' !== $setting_name ) {
			$value = get_option( $setting_name, '' );
			if ( is_string( $value ) && '' !== $value ) {
				$credentials[] = $value;
			}
		}

		$env_var_name = isset( $auth['env_var_name'] ) && is_string( $auth['env_var_name'] ) ? $auth['env_var_name'] : '';
		if ( '' !== $env_var_name ) {
			$value = getenv( $env_var_name );
			if ( is_string( $value ) && '' !== $value ) {
				$credentials[] = $value;
			}
		}

		$constant_name = isset( $auth['constant_name'] ) && is_string( $auth['constant_name'] ) ? $auth['constant_name'] : '';
		if ( '' !== $constant_name && defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== $value ) {
				$credentials[] = $value;
			}
		}

		return $credentials;
	}

	/**
	 * Returns the list of strings that might carry a credential for a given request.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param string $url Request URL.
	 * @return list<string>
	 */
	private function collect_haystacks( array $args, string $url ): array {
		$haystacks = array();

		if ( '' !== $url ) {
			$haystacks[] = $url;
		}

		$headers = $args['headers'] ?? array();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$haystacks[] = $value;
				} elseif ( is_array( $value ) ) {
					foreach ( $value as $sub ) {
						if ( ! is_string( $sub ) || '' === $sub ) {
							continue;
						}

						$haystacks[] = $sub;
					}
				}
			}
		}

		return $haystacks;
	}
}
