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
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		$option = 'wpai_feature_' . self::get_id() . '_enabled';

		if ( false === has_action( "update_option_{$option}", array( self::class, 'handle_toggle_update' ) ) ) {
			add_action( "update_option_{$option}", array( self::class, 'handle_toggle_update' ), 10, 2 );
		}

		if ( false !== has_action( "add_option_{$option}", array( self::class, 'handle_toggle_add' ) ) ) {
			return;
		}

		add_action( "add_option_{$option}", array( self::class, 'handle_toggle_add' ), 10, 2 );
	}

	/**
	 * Static handler for the toggle update action.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function handle_toggle_update( $old_value, $new_value ): void {
		$was_enabled = self::coerce_bool( $old_value );
		$is_enabled  = self::coerce_bool( $new_value );

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
	 * Static handler for the toggle add action.
	 *
	 * @since x.x.x
	 *
	 * @param string $option    Option name.
	 * @param mixed  $new_value New option value.
	 */
	public static function handle_toggle_add( $option, $new_value ): void {
		unset( $option );
		if ( ! self::coerce_bool( $new_value ) ) {
			return;
		}

		self::get_bridge()->encrypt_all();
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
