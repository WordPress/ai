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
	 * Query arguments that return the ID of every site in the network.
	 *
	 * @since 1.5.0
	 *
	 * @var array<string, int|string>
	 */
	private const ALL_SITE_IDS_QUERY = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'fields' => 'ids',
		'number' => 0,
	);

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
			'description' => __( 'Encrypts AI provider API keys at rest using bundled libsodium encryption. Keys are transparently decrypted on read and re-encrypted on write. Requires WP_SECRETS_KEY in wp-config.php. Disabling the experiment or deactivating the plugin restores plaintext keys.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
			'usage'       => array(
				'trigger' => 'none',
				'runs'    => __( 'Encrypts stored AI provider API keys when enabled', 'ai' ),
				'sends'   => __( 'Nothing', 'ai' ),
				'details' => __( 'Restores plaintext keys when disabled', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_get_secret' ) ) {
			require_once WPAI_PLUGIN_DIR . 'includes/Vendor/Secrets/load.php';
		}

		self::get_bridge()->register_option_filters();

		add_action( 'admin_notices', array( self::class, 'render_unavailable_notice' ) );
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
	 * Returns whether the experiment is effectively enabled (individual toggle on).
	 *
	 * Does not consult `Abstract_Feature::is_enabled()` because that
	 * method caches per-instance, which would be stale immediately after
	 * a toggle change inside the same request.
	 *
	 * @since 1.1.0
	 */
	public static function is_effectively_enabled(): bool {
		return self::coerce_bool( get_option( self::get_toggle_option_name(), false ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 */
	public function get_preloaded_options(): array {
		return array( self::RESUME_MIGRATION_OPTION );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	public function register_settings(): void {
		$individual = self::get_toggle_option_name();

		self::ensure_action( "update_option_{$individual}", array( self::class, 'handle_individual_toggle_update' ), 10, 2 );
		self::ensure_action( "add_option_{$individual}", array( self::class, 'handle_individual_toggle_add' ), 10, 2 );

		// Recover secrets stored under the WordPress salts.
		self::ensure_action( 'init', array( self::class, 'maybe_recover_secrets' ), 16, 0 );
		self::ensure_action( 'init', array( self::class, 'maybe_resume_migration' ), 16, 0 );
	}

	/**
	 * Recovers secrets stored under the WordPress salts.
	 *
	 * @since x.x.x
	 */
	public static function maybe_recover_secrets(): void {
		if ( ! self::is_effectively_enabled() ) {
			return;
		}

		$bridge = self::get_bridge();
		$bridge->recover_salt_wrapped_secrets();

		if ( $bridge->is_secrets_api_usable() || '1' === get_option( self::RESUME_MIGRATION_OPTION, '' ) ) {
			return;
		}

		self::flag_resume_migration();
	}

	/**
	 * Warns administrators that API keys are stored unencrypted if the Secrets API is unusable.
	 *
	 * @since x.x.x
	 */
	public static function render_unavailable_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_effectively_enabled() || self::get_bridge()->is_secrets_api_usable() ) {
			return;
		}

		$key_is_missing = class_exists( 'WP_Secrets_Config_Key_Provider' )
			&& ! wp_using_secrets_dropin()
			&& in_array(
				( new \WP_Secrets_Config_Key_Provider() )->get_key_status(),
				array( \WP_Secrets_Config_Key_Provider::KEY_STATUS_UNDEFINED, \WP_Secrets_Config_Key_Provider::KEY_STATUS_UNUSABLE ),
				true
			);

		if ( $key_is_missing ) {
			$message = sprintf(
				/* translators: 1: WP_SECRETS_KEY, 2: wp-config.php */
				__( 'Key Encryption cannot encrypt your AI provider API keys, so new keys are stored unencrypted. Add a %1$s to %2$s, or restore its previous value if it was removed, and the keys will be encrypted on the next page load.', 'ai' ),
				'<code>WP_SECRETS_KEY</code>',
				'<code>wp-config.php</code>'
			);
		} else {
			$message = sprintf(
				/* translators: %s: WP_SECRETS_KEY */
				__( 'Key Encryption cannot read or store encrypted AI provider API keys. If %s was changed or removed, restore its previous value.', 'ai' ),
				'<code>WP_SECRETS_KEY</code>'
			);
		}

		wp_admin_notice(
			$message . ' <a href="https://github.com/WordPress/ai/blob/trunk/docs/experiments/key-encryption.md#requirements">' . esc_html__( 'Learn more about WP_SECRETS_KEY', 'ai' ) . '</a>',
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'wpai-key-encryption-notice' ),
			)
		);
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
	 * @since x.x.x Keeps the flag while the Secrets API cannot store secrets.
	 */
	public static function maybe_resume_migration(): void {
		if ( '1' !== get_option( self::RESUME_MIGRATION_OPTION, '' ) ) {
			return;
		}

		if ( ! self::is_effectively_enabled() ) {
			delete_option( self::RESUME_MIGRATION_OPTION );
			return;
		}

		// Keep the flag until the keys can be encrypted, such as once WP_SECRETS_KEY is added.
		if ( ! self::get_bridge()->is_secrets_api_usable() ) {
			return;
		}

		delete_option( self::RESUME_MIGRATION_OPTION );

		self::get_bridge()->encrypt_all();
	}

	/**
	 * Runs a callback on the current site, or on every site of the network.
	 *
	 * @since 1.5.0
	 *
	 * @param bool     $network_wide Whether to run the callback on every site of the network.
	 * @param callable $callback     Callback to run in the context of each site.
	 */
	public static function for_each_site( bool $network_wide, callable $callback ): void {
		$site_ids = array( get_current_blog_id() );

		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites( self::ALL_SITE_IDS_QUERY );
		}

		foreach ( $site_ids as $site_id ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
			$switched = is_multisite() && switch_to_blog( (int) $site_id );

			try {
				$callback();
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
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
		self::sync_effective_state( self::coerce_bool( $old_value ), self::coerce_bool( $new_value ) );
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
		self::sync_effective_state( false, self::coerce_bool( $new_value ) );
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
