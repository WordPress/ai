<?php
/**
 * Intercepts outbound HTTP requests carrying an AI connector credential.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

use ReflectionClass;
use Throwable;
use WP_Error;
use WordPress\AiClient\AiClient;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Hooks `pre_http_request` to enforce per-plugin, per-connector approval.
 *
 * The AI Client uses `wp_safe_remote_request()` for its outbound calls, so
 * every AI request passes through WordPress's HTTP stack and therefore this
 * filter. We use `Connector_Key_Index` to attribute a request to a specific
 * connector by matching any credential that appears in its headers or URL.
 * Requests that don't carry a configured credential are passed through so
 * unrelated HTTP traffic on the site is unaffected.
 *
 * This enforcement point has a few nice properties compared to hooking the
 * AI Client's own prompt filter:
 *
 * - Exact connector identification at block time — we're matching the actual
 *   credential leaving the site, not inferring a candidate set from builder
 *   internals.
 * - No dependency on SDK internals (no reflection).
 * - Catches any plugin that reads a credential option directly and makes its
 *   own HTTP call, not just plugins that went through `wp_ai_client_prompt()`.
 *
 * Limitations:
 * - A plugin that transforms the credential before sending (signing, custom
 *   encryption) would not be caught because the raw key isn't present in the
 *   request.
 *
 * @since 1.0.0
 */
final class Http_Guard {
	/**
	 * Caller identifier.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Caller_Identifier
	 */
	private Caller_Identifier $identifier;

	/**
	 * Approvals store.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Connector credential index.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Connector_Key_Index
	 */
	private Connector_Key_Index $key_index;

	/**
	 * Guard against re-entrant filter calls made by our own pending bookkeeping.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private bool $in_filter = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AI\Connector_Approval\Caller_Identifier $identifier Caller identifier.
	 * @param \WordPress\AI\Connector_Approval\Approvals_Store $store Approvals store.
	 * @param \WordPress\AI\Connector_Approval\Connector_Key_Index $key_index Connector credential index.
	 */
	public function __construct(
		Caller_Identifier $identifier,
		Approvals_Store $store,
		Connector_Key_Index $key_index
	) {
		$this->identifier = $identifier;
		$this->store      = $store;
		$this->key_index  = $key_index;
	}

	/**
	 * Registers the HTTP pre-request filter.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'maybe_block_request' ), 5, 3 );
	}

	/**
	 * Blocks the outbound request when the caller isn't approved for the
	 * connector whose credential the request is carrying.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $preempt Current short-circuit value from earlier filter callbacks.
	 * @param array<string, mixed> $args Parsed request arguments.
	 * @param string $url Request URL.
	 * @return mixed `WP_Error` to deny the request, or the incoming $preempt value otherwise.
	 */
	public function maybe_block_request( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( $this->in_filter ) {
			return $preempt;
		}

		if ( ! is_array( $args ) ) {
			$args = array();
		}

		if ( ! is_string( $url ) ) {
			$url = '';
		}

		$connector_id = $this->key_index->lookup( $args, $url );
		if ( null === $connector_id ) {
			return $preempt;
		}

		$this->in_filter = true;
		try {
			$caller = $this->identifier->identify( $this->provider_extension_keys( $connector_id ) );
		} finally {
			$this->in_filter = false;
		}

		if ( null === $caller ) {
			// No identifiable plugin/theme/mu-plugin on the stack — allow through
			// so core maintenance, wp-cli, and REST-originated requests aren't blocked.
			return $preempt;
		}

		if ( $this->store->is_approved( $caller['basename'], $connector_id ) ) {
			return $preempt;
		}

		$this->in_filter = true;
		try {
			$this->store->record_pending( $caller, $connector_id );
		} finally {
			$this->in_filter = false;
		}

		return new WP_Error(
			'wpai_connector_not_approved',
			sprintf(
				/* translators: 1: Connector ID. 2: Calling plugin/theme basename. */
				__( 'The "%1$s" AI connector has not been approved for use by "%2$s".', 'ai' ),
				$connector_id,
				$caller['basename']
			),
			array(
				'status'       => 403,
				'connector_id' => $connector_id,
				'caller'       => $caller,
			)
		);
	}

	/**
	 * Returns the extension that provides a connector, as a Caller_Identifier key.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connector_id Connector ID.
	 * @return list<string> The provider's extension key, or an empty list when it
	 *                      cannot be determined.
	 */
	private function provider_extension_keys( string $connector_id ): array {
		$connector = function_exists( 'wp_get_connector' ) ? wp_get_connector( $connector_id ) : null;
		$declared  = $connector['plugin']['file'] ?? '';
		if ( is_string( $declared ) && '' !== $declared ) {
			return array( Caller_Identifier::TYPE_PLUGIN . ':' . explode( '/', $declared )[0] );
		}

		try {
			$registry = AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( $connector_id ) ) {
				return array();
			}

			$file = ( new ReflectionClass( $registry->getProviderClassName( $connector_id ) ) )->getFileName();
		} catch ( Throwable $e ) {
			return array();
		}

		if ( ! is_string( $file ) ) {
			return array();
		}

		return $this->provider_keys_for_file( $file );
	}

	/**
	 * Returns the extension key for a provider class file.
	 *
	 * @since 1.4.0
	 *
	 * @param string $file Absolute path of the file defining the provider class.
	 * @return list<string> The owning extension's key, or an empty list when the
	 *                      file is bundled in a `vendor/` directory or belongs to
	 *                      no plugin, mu-plugin, or theme.
	 */
	private function provider_keys_for_file( string $file ): array {
		$file = wp_normalize_path( $file );

		if ( str_contains( $file, '/vendor/' ) ) {
			return array();
		}

		$extension = $this->identifier->classify_file( $file );
		if ( null === $extension ) {
			return array();
		}

		return array( Caller_Identifier::extension_key( $extension ) );
	}
}
