<?php
/**
 * Displace Secrets Manager Orchestrator
 *
 * Central coordinator that manages provider registration, selection,
 * access control, and delegates operations to the active provider.
 *
 * @package Displace_Secrets_Manager
 */

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton orchestrator for the secrets system.
 */
final class Secrets_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Registered providers, keyed by provider ID.
	 *
	 * @var Secrets_Provider[]
	 */
	private $providers = array();

	/**
	 * The ID of the currently active provider.
	 *
	 * @var string|null
	 */
	private $active_provider_id = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {}

	/**
	 * Register a secrets provider.
	 *
	 * @param Secrets_Provider $provider The provider instance.
	 * @return bool True if registered.
	 */
	public function register_provider( Secrets_Provider $provider ): bool {
		$id = $provider->get_id();

		if ( isset( $this->providers[ $id ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: provider ID */
					esc_html__( 'A secrets provider with ID "%s" is already registered.', 'displace-secrets-manager' ),
					esc_html( $id )
				),
				'0.1.0'
			);
			return false;
		}

		$this->providers[ $id ] = $provider;

		/**
		 * Fires when a new secrets provider is registered.
		 *
		 * @param string              $id       Provider ID.
		 * @param Secrets_Provider $provider The provider instance.
		 */
		do_action( 'secrets_provider_registered', $id, $provider );

		return true;
	}

	/**
	 * Automatically select the best available provider.
	 *
	 * Selection strategy (decisions, not options):
	 *   1. If WP_SECRETS_PROVIDER constant is defined, use that.
	 *   2. Otherwise, pick the available provider with the highest priority.
	 *   3. The plaintext options provider is always available as last resort.
	 *
	 * @return string The selected provider ID.
	 */
	public function select_provider(): string {
		// Forced selection via constant.
		if ( defined( 'WP_SECRETS_PROVIDER' ) && isset( $this->providers[ WP_SECRETS_PROVIDER ] ) ) {
			$this->active_provider_id = WP_SECRETS_PROVIDER;

			/**
			 * Fires when a provider is selected.
			 *
			 * @param string $provider_id The selected provider ID.
			 * @param string $method      How it was selected ('constant', 'auto').
			 */
			do_action( 'secrets_provider_selected', $this->active_provider_id, 'constant' );

			return $this->active_provider_id;
		}

		// Auto-select by priority.
		$best_id       = null;
		$best_priority = -1;

		foreach ( $this->providers as $id => $provider ) {
			if ( $provider->is_available() && $provider->get_priority() > $best_priority ) {
				$best_id       = $id;
				$best_priority = $provider->get_priority();
			}
		}

		$this->active_provider_id = $best_id;

		/** This action is documented above. */
		do_action( 'secrets_provider_selected', $this->active_provider_id, 'auto' );

		return $this->active_provider_id;
	}

	/**
	 * Get the active provider instance.
	 *
	 * @return Secrets_Provider|null
	 */
	public function get_active_provider(): ?Secrets_Provider {
		if ( null === $this->active_provider_id ) {
			return null;
		}

		return $this->providers[ $this->active_provider_id ] ?? null;
	}

	/**
	 * Get the active provider ID.
	 *
	 * @return string|null
	 */
	public function get_active_provider_id(): ?string {
		return $this->active_provider_id;
	}

	/**
	 * Get a specific provider by ID.
	 *
	 * @param string $id Provider ID.
	 * @return Secrets_Provider|null
	 */
	public function get_provider( string $id ): ?Secrets_Provider {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return Secrets_Provider[]
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Resolve which provider handles a specific key.
	 *
	 * Allows per-key provider overrides via the secrets_provider filter.
	 *
	 * @param string $key     The secret key.
	 * @param array  $context Caller context.
	 * @return Secrets_Provider|null
	 */
	public function resolve_provider( string $key, array $context = [] ): ?Secrets_Provider {
		$provider_id = $this->active_provider_id;

		/**
		 * Filter which provider handles a specific secret key.
		 *
		 * @param string $provider_id The default provider ID.
		 * @param string $key         The secret key being accessed.
		 * @param array  $context     Caller context.
		 */
		$provider_id = apply_filters( 'secrets_provider', $provider_id, $key, $context );

		if ( $provider_id && isset( $this->providers[ $provider_id ] ) ) {
			return $this->providers[ $provider_id ];
		}

		return $this->get_active_provider();
	}

	/**
	 * Validate a secret key.
	 *
	 * Keys must contain a forward slash namespace separator unless
	 * the global flag is set in the context.
	 *
	 * @param string $key     The key to validate.
	 * @param array  $context Caller context.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_key( string $key, array $context = [] ) {
		if ( '' === $key ) {
			return new \WP_Error(
				'secrets_empty_key',
				__( 'Secret key must not be empty.', 'displace-secrets-manager' )
			);
		}

		$is_global = ! empty( $context['global'] );

		if ( ! $is_global && false === strpos( $key, '/' ) ) {
			return new \WP_Error(
				'secrets_invalid_key',
				sprintf(
					/* translators: %s: the invalid key */
					__( 'Secret key "%s" must be namespaced with a forward slash (e.g. "my-plugin/api_key"). Use the --global flag for unnamespaced keys.', 'displace-secrets-manager' ),
					$key
				)
			);
		}

		if ( preg_match( '/[^a-zA-Z0-9\/_\-\.]/', $key ) ) {
			return new \WP_Error(
				'secrets_invalid_characters',
				__( 'Secret key contains invalid characters. Use only alphanumeric characters, forward slashes, underscores, hyphens, and periods.', 'displace-secrets-manager' )
			);
		}

		return true;
	}

	/**
	 * Check access control for a secrets operation.
	 *
	 * @param string $key       The secret key.
	 * @param string $operation The operation ('get', 'set', 'delete', 'exists', 'list').
	 * @param array  $context   Caller context.
	 * @return bool
	 */
	public function check_access( string $key, string $operation, array $context ): bool {
		$ctx = new Secrets_Context( $context );

		// Extract namespace from the key.
		$namespace = strstr( $key, '/', true );
		if ( false === $namespace ) {
			$namespace = $key;
		}

		$allowed = $ctx->can_access_namespace( $namespace );

		/**
		 * Filter access control decisions.
		 *
		 * @param bool   $allowed   Whether access is allowed.
		 * @param string $key       The secret key.
		 * @param string $operation The operation being performed.
		 * @param array  $context   Caller context.
		 */
		$allowed = apply_filters( 'secrets_access', $allowed, $key, $operation, $ctx->to_array() );

		return (bool) $allowed;
	}

	/**
	 * Retrieve a secret.
	 *
	 * @param string $key     The secret key.
	 * @param array  $context Caller context.
	 * @return string|null
	 */
	public function get( string $key, array $context = [] ): ?string {
		$valid = $this->validate_key( $key, $context );
		if ( is_wp_error( $valid ) ) {
			_doing_it_wrong( 'get_secret', esc_html( $valid->get_error_message() ), '0.1.0' );
			return null;
		}

		if ( ! $this->check_access( $key, 'get', $context ) ) {
			/**
			 * Fires when a secret access attempt is denied.
			 *
			 * @param string $key       The secret key.
			 * @param string $operation The denied operation.
			 * @param array  $context   Caller context.
			 */
			do_action( 'secrets_access_denied', $key, 'get', $context );
			return null;
		}

		/**
		 * Short-circuit the get operation.
		 *
		 * Return a non-null value to bypass the provider entirely.
		 *
		 * @param null|string $value   Default null (no short-circuit).
		 * @param string      $key     The secret key.
		 * @param array       $context Caller context.
		 */
		$pre = apply_filters( 'secrets_pre_get', null, $key, $context );
		if ( null !== $pre ) {
			Secrets_Audit::log( 'get', $key, $context );
			return $pre;
		}

		$provider = $this->resolve_provider( $key, $context );
		if ( null === $provider ) {
			return null;
		}

		$value = $provider->get( $key, $context );

		Secrets_Audit::log( 'get', $key, $context );

		return $value;
	}

	/**
	 * Store a secret.
	 *
	 * @param string $key     The secret key.
	 * @param string $value   The plaintext value.
	 * @param array  $context Caller context.
	 * @return bool
	 */
	public function set( string $key, string $value, array $context = [] ): bool {
		$valid = $this->validate_key( $key, $context );
		if ( is_wp_error( $valid ) ) {
			_doing_it_wrong( 'set_secret', esc_html( $valid->get_error_message() ), '0.1.0' );
			return false;
		}

		if ( ! $this->check_access( $key, 'set', $context ) ) {
			do_action( 'secrets_access_denied', $key, 'set', $context );
			return false;
		}

		/**
		 * Filter the value before storage.
		 *
		 * @param string $value   The secret value.
		 * @param string $key     The secret key.
		 * @param array  $context Caller context.
		 */
		$value = apply_filters( 'secrets_pre_set', $value, $key, $context );

		$provider = $this->resolve_provider( $key, $context );
		if ( null === $provider ) {
			return false;
		}

		$result = $provider->set( $key, $value, $context );

		if ( $result ) {
			Secrets_Audit::log( 'set', $key, $context );

			/**
			 * Fires after a secret is successfully stored.
			 *
			 * @param string $key     The secret key.
			 * @param array  $context Caller context.
			 */
			do_action( 'secrets_post_set', $key, $context );
		}

		return $result;
	}

	/**
	 * Delete a secret.
	 *
	 * @param string $key     The secret key.
	 * @param array  $context Caller context.
	 * @return bool
	 */
	public function delete( string $key, array $context = [] ): bool {
		$valid = $this->validate_key( $key, $context );
		if ( is_wp_error( $valid ) ) {
			_doing_it_wrong( 'delete_secret', esc_html( $valid->get_error_message() ), '0.1.0' );
			return false;
		}

		if ( ! $this->check_access( $key, 'delete', $context ) ) {
			do_action( 'secrets_access_denied', $key, 'delete', $context );
			return false;
		}

		$provider = $this->resolve_provider( $key, $context );
		if ( null === $provider ) {
			return false;
		}

		$result = $provider->delete( $key, $context );

		Secrets_Audit::log( 'delete', $key, $context );

		/**
		 * Fires after a secret is deleted (or deletion attempted).
		 *
		 * @param string $key     The secret key.
		 * @param bool   $result  Whether the deletion succeeded.
		 * @param array  $context Caller context.
		 */
		do_action( 'secrets_post_delete', $key, $result, $context );

		return $result;
	}

	/**
	 * Check whether a secret exists.
	 *
	 * @param string $key     The secret key.
	 * @param array  $context Caller context.
	 * @return bool
	 */
	public function exists( string $key, array $context = [] ): bool {
		$valid = $this->validate_key( $key, $context );
		if ( is_wp_error( $valid ) ) {
			return false;
		}

		if ( ! $this->check_access( $key, 'exists', $context ) ) {
			do_action( 'secrets_access_denied', $key, 'exists', $context );
			return false;
		}

		$provider = $this->resolve_provider( $key, $context );
		if ( null === $provider ) {
			return false;
		}

		$result = $provider->exists( $key, $context );

		Secrets_Audit::log( 'exists', $key, $context );

		return $result;
	}

	/**
	 * List secret keys matching a prefix.
	 *
	 * @param string $prefix  Key prefix filter.
	 * @param array  $context Caller context.
	 * @return string[]
	 */
	public function list_keys( string $prefix = '', array $context = [] ): array {
		if ( ! $this->check_access( $prefix ?: '*', 'list', $context ) ) {
			do_action( 'secrets_access_denied', $prefix, 'list', $context );
			return array();
		}

		$provider = $this->resolve_provider( $prefix ?: '*', $context );
		if ( null === $provider ) {
			return array();
		}

		$keys = $provider->list_keys( $prefix, $context );

		Secrets_Audit::log( 'list', $prefix ?: '*', $context );

		return $keys;
	}

	/**
	 * Reset the singleton (for testing only).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}
}
