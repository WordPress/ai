<?php
/**
 * Builds and queries a lookup of AI connector credentials to connector IDs.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Holds the set of currently-configured connector API keys so the HTTP guard
 * can attribute an outbound request to the connector whose credential is
 * carrying it, regardless of which header or query parameter the provider
 * uses.
 *
 * Keys are kept in plaintext for substring scanning. They are already in
 * memory for the lifetime of each request because the connector plugins
 * themselves have read them — this class does not introduce new exposure and
 * never persists or logs key material.
 *
 * @since x.x.x
 */
final class Connector_Key_Index {
	/**
	 * Minimum length an API key must have to be considered for matching.
	 *
	 * Short strings would produce false positives when scanning unrelated
	 * headers; real provider keys are consistently longer than this bound.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MIN_KEY_LENGTH = 20;

	/**
	 * Mapping of connector credential → connector ID.
	 *
	 * Lazily populated on first lookup.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>|null
	 */
	private ?array $key_to_connector = null;

	/**
	 * Finds the connector ID whose credential appears in the given outbound request.
	 *
	 * Scans the URL and every header value. The first matching connector ID is
	 * returned. If no configured credential is present, `null` is returned and
	 * the caller should treat the request as non-AI traffic.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args Request arguments passed to `pre_http_request`.
	 * @param string $url The fully-qualified request URL.
	 * @return string|null Connector ID, or null if no credential matched.
	 */
	public function lookup( array $args, string $url ): ?string {
		$keys = $this->get_keys();
		if ( array() === $keys ) {
			return null;
		}

		$haystacks = $this->collect_haystacks( $args, $url );
		if ( array() === $haystacks ) {
			return null;
		}

		foreach ( $keys as $key => $connector_id ) {
			foreach ( $haystacks as $haystack ) {
				if ( false !== strpos( $haystack, $key ) ) {
					return $connector_id;
				}
			}
		}

		return null;
	}

	/**
	 * Clears the cached index so the next lookup rebuilds it.
	 *
	 * Useful when connector credentials change during the same request (tests,
	 * long-running CLI scripts). Production requests get a fresh index anyway.
	 *
	 * @since x.x.x
	 */
	public function invalidate(): void {
		$this->key_to_connector = null;
	}

	/**
	 * Returns the key → connector_id map, building it on first access.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string>
	 */
	private function get_keys(): array {
		if ( null !== $this->key_to_connector ) {
			return $this->key_to_connector;
		}

		$this->key_to_connector = array();

		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return $this->key_to_connector;
		}

		foreach ( (array) wp_get_connectors() as $connector_id => $data ) {
			if ( ! is_string( $connector_id ) || ! is_array( $data ) ) {
				continue;
			}

			if ( ( $data['type'] ?? '' ) !== 'ai_provider' ) {
				continue;
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

		return $this->key_to_connector;
	}

	/**
	 * Returns every credential string configured for a given authentication block.
	 *
	 * Checks the DB-stored option, a declared environment variable, and a
	 * declared PHP constant. A connector may populate any one of the three.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
