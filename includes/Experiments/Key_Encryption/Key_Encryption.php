<?php
/**
 * Key Encryption experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Key_Encryption;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Opt-in experiment that encrypts AI connector API keys at rest.
 *
 * While enabled, every `connectors_ai_*_api_key` option is transparently redirected through the
 * displace-secrets-manager plugin's `set_secret()` / `get_secret()` API, so the `wp_options` table
 * never contains a plaintext provider credential. Existing keys are migrated on opt-in and
 * restored on opt-out (or on plugin deactivation) so users are never locked out of their own
 * credentials.
 *
 * @since x.x.x
 */
class Key_Encryption extends Abstract_Feature {

	/**
	 * Process-wide bridge instance.
	 *
	 * Hooks are registered against this single bridge so that re-instantiation of the experiment
	 * (in tests or in code that calls `register_settings()` multiple times) does not produce
	 * duplicate callbacks.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\Key_Encryption\Secrets_Bridge|null
	 */
	private static ?Secrets_Bridge $bridge = null;

	/**
	 * Returns the process-wide Secrets_Bridge singleton.
	 *
	 * @since x.x.x
	 */
	public static function get_bridge(): Secrets_Bridge {
		if ( null === self::$bridge ) {
			self::$bridge = new Secrets_Bridge();
		}
		return self::$bridge;
	}

	/**
	 * Resets the cached bridge.
	 *
	 * @since x.x.x
	 *
	 * @internal
	 */
	public static function reset_bridge(): void {
		self::$bridge = null;
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'key-encryption';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Key Encryption', 'ai' ),
			'description' => __( 'Encrypts AI provider API keys at rest using the Displace Secrets Manager plugin. Keys are transparently decrypted on read and re-encrypted on write. Disabling the experiment or deactivating the plugin restores plaintext keys.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		self::get_bridge()->register_option_filters();
	}

	/**
	 * Returns the option name for this experiment's individual toggle.
	 *
	 * @since x.x.x
	 */
	public static function get_toggle_option_name(): string {
		return 'wpai_feature_' . self::get_id() . '_enabled';
	}

	/**
	 * Returns whether the experiment is effectively enabled (global AND individual toggle on).
	 *
	 * Does not consult `Abstract_Feature::is_enabled()` because that
	 * method caches per-instance, which would be stale immediately after
	 * a toggle change inside the same request.
	 *
	 * @since x.x.x
	 */
	public static function is_effectively_enabled(): bool {
		$global     = self::coerce_bool( get_option( Settings_Registration::GLOBAL_OPTION, false ) );
		$individual = self::coerce_bool( get_option( self::get_toggle_option_name(), false ) );
		return $global && $individual;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		$individual = self::get_toggle_option_name();
		$global     = Settings_Registration::GLOBAL_OPTION;

		self::ensure_action( "update_option_{$individual}", array( self::class, 'handle_individual_toggle_update' ), 2 );
		self::ensure_action( "add_option_{$individual}", array( self::class, 'handle_individual_toggle_add' ), 2 );

		self::ensure_action( "update_option_{$global}", array( self::class, 'handle_global_toggle_update' ), 2 );
		self::ensure_action( "add_option_{$global}", array( self::class, 'handle_global_toggle_add' ), 2 );
	}

	/**
	 * Idempotent `add_action` wrapper used for the toggle hooks.
	 *
	 * @since x.x.x
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback to register.
	 * @param int      $accepted_args Number of accepted args.
	 */
	private static function ensure_action( string $hook, callable $callback, int $accepted_args ): void {
		if ( false !== has_action( $hook, $callback ) ) {
			return;
		}
		add_action( $hook, $callback, 10, $accepted_args );
	}

	/**
	 * Handles updates to this experiment's individual toggle.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function handle_individual_toggle_update( $old_value, $new_value ): void {
		$global = self::coerce_bool( get_option( Settings_Registration::GLOBAL_OPTION, false ) );
		$was_on = $global && self::coerce_bool( $old_value );
		$now_on = $global && self::coerce_bool( $new_value );
		self::sync_effective_state( $was_on, $now_on );
	}

	/**
	 * Handles the first-time write of this experiment's individual toggle.
	 *
	 * @since x.x.x
	 *
	 * @param string $option    Option name.
	 * @param mixed  $new_value New option value.
	 */
	public static function handle_individual_toggle_add( $option, $new_value ): void {
		unset( $option );
		$global = self::coerce_bool( get_option( Settings_Registration::GLOBAL_OPTION, false ) );
		$now_on = $global && self::coerce_bool( $new_value );
		self::sync_effective_state( false, $now_on );
	}

	/**
	 * Handles updates to the global features toggle.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function handle_global_toggle_update( $old_value, $new_value ): void {
		$individual = self::coerce_bool( get_option( self::get_toggle_option_name(), false ) );
		$was_on     = self::coerce_bool( $old_value ) && $individual;
		$now_on     = self::coerce_bool( $new_value ) && $individual;
		self::sync_effective_state( $was_on, $now_on );
	}

	/**
	 * Handles the first-time write of the global features toggle.
	 *
	 * @since x.x.x
	 *
	 * @param string $option    Option name.
	 * @param mixed  $new_value New option value.
	 */
	public static function handle_global_toggle_add( $option, $new_value ): void {
		unset( $option );
		$individual = self::coerce_bool( get_option( self::get_toggle_option_name(), false ) );
		$now_on     = self::coerce_bool( $new_value ) && $individual;
		self::sync_effective_state( false, $now_on );
	}

	/**
	 * Drives encrypt/decrypt migration when the effective enabled state transitions.
	 *
	 * @since x.x.x
	 *
	 * @param bool $was_enabled Previous effective state.
	 * @param bool $is_enabled  New effective state.
	 */
	private static function sync_effective_state( bool $was_enabled, bool $is_enabled ): void {
		if ( $was_enabled === $is_enabled ) {
			return;
		}

		if ( $is_enabled ) {
			self::get_bridge()->encrypt_all();
			return;
		}

		self::get_bridge()->decrypt_all();
	}

	/**
	 * Coerces a stored option value to a boolean.
	 *
	 * Settings stored via the REST API can arrive as
	 * `'1'`, `'0'`, `''`, `true`, `false`, etc.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw option value.
	 * @return bool The coerced boolean value.
	 */
	private static function coerce_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
		}

		if ( is_numeric( $value ) ) {
			return 0 !== (int) $value;
		}

		return (bool) $value;
	}
}
