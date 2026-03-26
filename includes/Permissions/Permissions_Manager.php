<?php
/**
 * Permissions Manager for AI access control.
 *
 * @package WordPress\AI\Permissions
 */

declare( strict_types=1 );

namespace WordPress\AI\Permissions;

use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Manages plugin-level permissions for AI provider access.
 * Its final class to prevent extension, ensuring a single 
 * source of truth for plugin permissions logic.
 *
 * Provides a central point for:
 * - Checking whether a plugin is authorized to use AI providers.
 * - Storing and retrieving per-plugin provider routing preferences.
 *
 * @since 1.0.0
 */
final class Permissions_Manager {

	/**
	 * Option name prefix for plugin access settings.
	 *
	 * Full option name: `wpai_plugin_access_{sanitized_plugin_id}`.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PLUGIN_ACCESS_OPTION_PREFIX = 'wpai_plugin_access_';

	/**
	 * Option name prefix for plugin provider routing preferences.
	 *
	 * Full option name: `wpai_plugin_providers_{sanitized_plugin_id}`.
	 * Stores a comma-separated list of allowed provider slugs (e.g. "anthropic,google").
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PLUGIN_PROVIDER_OPTION_PREFIX = 'wpai_plugin_providers_';

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var \WordPress\AI\Permissions\Permissions_Manager|null
	 */
	private static ?self $instance = null;

	/**
	 * Plugin registry instance.
	 *
	 * @since 1.0.0
	 * @var \WordPress\AI\Permissions\Plugin_Registry
	 */
	private Plugin_Registry $plugin_registry;

	/**
	 * Whether the manager has been initialized.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Private constructor — use {@see Permissions_Manager::get_instance()} instead.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->plugin_registry = new Plugin_Registry();
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \WordPress\AI\Permissions\Permissions_Manager The singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initializes the permissions system.
	 *
	 * Fires the `wpai_register_plugins` action, allowing third-party plugins to register
	 * themselves as AI consumers. Should be called once during plugin initialization.
	 *
	 * Third-party plugins should hook into `wpai_register_plugins` at `init` priority 14
	 * or earlier to ensure their registration is processed in time.
	 *
	 * Example:
	 * ```php
	 * add_action( 'wpai_register_plugins', function( $registry ) {
	 *     $registry->register_plugin( 'my-plugin', array(
	 *         'name'        => 'My Plugin',
	 *         'description' => 'Uses AI for content generation.',
	 *     ) );
	 * } );
	 * ```
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function initialize(): void {
		if ( $this->initialized ) {
			return;
		}

		/**
		 * Fires to allow third-party plugins to register as AI consumers.
		 *
		 * Plugins must register before the site admin can grant or revoke their
		 * access to connected AI providers. Registrations should be added at
		 * `init` priority 14 or earlier.
		 *
		 * @since 1.0.0
		 *
		 * @param \WordPress\AI\Permissions\Plugin_Registry $registry The plugin registry instance.
		 */
		do_action( 'wpai_register_plugins', $this->plugin_registry );

		$this->initialized = true;
	}

	/**
	 * Returns the plugin registry.
	 *
	 * @since 1.0.0
	 *
	 * @return \WordPress\AI\Permissions\Plugin_Registry The plugin registry instance.
	 */
	public function get_plugin_registry(): Plugin_Registry {
		return $this->plugin_registry;
	}

	/**
	 * Checks if a plugin has been granted access to connected AI providers.
	 *
	 * Also checks the global AI features toggle — if AI is globally disabled,
	 * this returns false regardless of the plugin's individual access setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id The plugin identifier.
	 * @return bool True if the plugin is allowed to use AI providers, false otherwise.
	 */
	public function plugin_has_access( string $plugin_id ): bool {
		// Unregistered plugins are always denied.
		if ( ! $this->plugin_registry->has_plugin( $plugin_id ) ) {
			return false;
		}

		// Respect the global AI features toggle.
		$global_enabled = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		if ( ! $global_enabled ) {
			return false;
		}

		$option_name = self::PLUGIN_ACCESS_OPTION_PREFIX . $this->sanitize_option_key( $plugin_id );
		$has_access  = (bool) get_option( $option_name, false );

		/**
		 * Filters whether a specific plugin has access to AI providers.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $has_access Whether the plugin has been granted access.
		 * @param string $plugin_id  The plugin identifier.
		 */
		return (bool) apply_filters( 'wpai_plugin_ai_access', $has_access, $plugin_id );
	}

	/**
	 * Returns the provider routing preferences for a specific plugin.
	 *
	 * Returns an empty array if no preferences are configured, which means the
	 * plugin should use the global provider preferences.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id The plugin identifier.
	 * @return list<string> Ordered list of allowed provider slugs (e.g. ['anthropic', 'google']).
	 */
	public function get_plugin_provider_preferences( string $plugin_id ): array {
		$option_name = self::PLUGIN_PROVIDER_OPTION_PREFIX . $this->sanitize_option_key( $plugin_id );
		$stored      = get_option( $option_name, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			$preferences = array();
		} else {
			$preferences = array_values(
				array_filter(
					array_map( 'trim', explode( ',', $stored ) )
				)
			);
		}

		/**
		 * Filters the provider routing preferences for a specific plugin.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $preferences Ordered list of provider slugs. Empty means use global.
		 * @param string       $plugin_id   The plugin identifier.
		 */
		return (array) apply_filters( 'wpai_plugin_provider_preferences', $preferences, $plugin_id );
	}

	/**
	 * Sanitizes a plugin ID for use as an option key suffix.
	 *
	 * Lowercases the string and replaces any character that is not a-z, 0-9,
	 * or underscore with an underscore.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id The plugin identifier.
	 * @return string The sanitized key, safe for use in option names.
	 */
	public function sanitize_option_key( string $plugin_id ): string {
		return preg_replace( '/[^a-z0-9_]/', '_', strtolower( $plugin_id ) ) ?? '';
	}
}
