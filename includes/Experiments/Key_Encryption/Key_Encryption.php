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
 * bundled secrets API so the `wp_options` table never contains a plaintext provider
 * credential. Existing keys are migrated on opt-in and restored on opt-out (or on plugin
 * deactivation) so users are never locked out of their own credentials.
 *
 * @since 1.1.0
 */
class Key_Encryption extends Abstract_Feature {

	/**
	 * Option that records re-encryption is needed on the next request.
	 *
	 * Set by `Activation::activation_callback()` so re-activating the plugin while the experiment
	 * is still toggled on re-encrypts the plaintext keys that the previous deactivation restored.
	 *
	 * @since 1.1.0
	 */
	public const RESUME_MIGRATION_OPTION = 'wpai_key_encryption_resume_migration';

	/**
	 * Process-wide bridge instance.
	 *
	 * Hooks are registered against this single bridge so that re-instantiation of the experiment
	 * (in tests or in code that calls `register_settings()` multiple times) does not produce
	 * duplicate callbacks.
	 *
	 * @since 1.1.0
	 * @var \WordPress\AI\Experiments\Key_Encryption\Secrets_Bridge|null
	 */
	private static ?Secrets_Bridge $bridge = null;

	/**
	 * Returns the process-wide Secrets_Bridge singleton.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
			'description' => __( 'Encrypts AI provider API keys at rest using bundled libsodium encryption. Keys are transparently decrypted on read and re-encrypted on write. Disabling the experiment or deactivating the plugin restores plaintext keys.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		self::get_bridge()->register_option_filters();
	}

	/**
	 * Returns the option name for this experiment's individual toggle.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 */
	public static function is_effectively_enabled(): bool {
		$global     = self::coerce_bool( get_option( Settings_Registration::GLOBAL_OPTION, false ) );
		$individual = self::coerce_bool( get_option( self::get_toggle_option_name(), false ) );
		return $global && $individual;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	public function register_settings(): void {
		$individual = self::get_toggle_option_name();
		$global     = Settings_Registration::GLOBAL_OPTION;

		self::ensure_action( "update_option_{$individual}", array( self::class, 'handle_individual_toggle_update' ), 10, 2 );
		self::ensure_action( "add_option_{$individual}", array( self::class, 'handle_individual_toggle_add' ), 10, 2 );

		self::ensure_action( "update_option_{$global}", array( self::class, 'handle_global_toggle_update' ), 10, 2 );
		self::ensure_action( "add_option_{$global}", array( self::class, 'handle_global_toggle_add' ), 10, 2 );

		// Process any deferred re-encryption flagged by the activation hook. Priority 16 runs
		// after `_wp_connectors_init` (priority 15), so `get_ai_connectors()` is populated.
		self::ensure_action( 'init', array( self::class, 'maybe_resume_migration' ), 16, 0 );
	}

	/**
	 * Sets the deferred-migration flag.
	 *
	 * Called from the plugin activation hook so the migration runs
	 * on the next request, when the connector registry has been populated.
	 *
	 * @since 1.1.0
	 */
	public static function flag_resume_migration(): void {
		update_option( self::RESUME_MIGRATION_OPTION, '1', false );
	}

	/**
	 * Consumes the deferred-migration flag and re-encrypts plaintext keys if effectively enabled.
	 *
	 * @since 1.1.0
	 */
	public static function maybe_resume_migration(): void {
		if ( '1' !== get_option( self::RESUME_MIGRATION_OPTION, '' ) ) {
			return;
		}

		delete_option( self::RESUME_MIGRATION_OPTION );

		if ( ! self::is_effectively_enabled() ) {
			return;
		}

		self::get_bridge()->encrypt_all();
	}

	/**
	 * Idempotent `add_action` wrapper used for the toggle hooks.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback to register.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Number of accepted args.
	 */
	private static function ensure_action( string $hook, callable $callback, int $priority, int $accepted_args ): void {
		if ( false !== has_action( $hook, $callback ) ) {
			return;
		}
		add_action( $hook, $callback, $priority, $accepted_args );
	}

	/**
	 * Handles updates to this experiment's individual toggle.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
