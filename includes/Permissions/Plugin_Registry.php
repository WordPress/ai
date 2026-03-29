<?php
/**
 * Plugin Registry for AI access control.
 *
 * @package WordPress\AI\Permissions
 */

declare( strict_types=1 );

namespace WordPress\AI\Permissions;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registry for plugins that have declared themselves as AI consumers.
 *
 * Third-party plugins register themselves via the `wpai_register_plugins` action hook.
 * Site admins can then grant or revoke AI provider access per plugin via the settings page.
 *
 * Example usage:
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
 */
final class Plugin_Registry {

	/**
	 * Registered plugins.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, array{id: string, name: string, description: string, capabilities: list<string>}>
	 */
	private array $plugins = array();

	/**
	 * Registers a plugin as an AI consumer.
	 *
	 * If a plugin with the same ID is already registered, this call is a no-op.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id Unique plugin identifier (e.g. 'my-plugin').
	 * @param array{name?: string, description?: string, capabilities?: list<string>} $args {
	 *     Optional plugin arguments.
	 *
	 *     @type string       $name         Human-readable plugin name. Defaults to the plugin ID.
	 *     @type string       $description  Brief description of how AI is used by this plugin.
	 *     @type list<string> $capabilities List of AI capability slugs this plugin requires
	 *                                      (e.g. 'text_generation', 'image_generation'). Defaults to empty.
	 * }
	 * @return void
	 */
	public function register_plugin( string $plugin_id, array $args = array() ): void {
		if ( isset( $this->plugins[ $plugin_id ] ) ) {
			return;
		}

		$capabilities = array();
		if ( isset( $args['capabilities'] ) && is_array( $args['capabilities'] ) ) {
			$capabilities = array_values(
				array_filter(
					array_map( 'sanitize_key', $args['capabilities'] )
				)
			);
		}

		$this->plugins[ $plugin_id ] = array(
			'id'           => $plugin_id,
			'name'         => sanitize_text_field( $args['name'] ?? $plugin_id ),
			'description'  => sanitize_textarea_field( $args['description'] ?? '' ),
			'capabilities' => $capabilities,
		);
	}

	/**
	 * Returns all registered plugins.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{id: string, name: string, description: string, capabilities: list<string>}>
	 */
	public function get_all_plugins(): array {
		return $this->plugins;
	}

	/**
	 * Returns a single registered plugin by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id The plugin identifier.
	 * @return array{id: string, name: string, description: string, capabilities: list<string>}|null Plugin data, or null if not found.
	 */
	public function get_plugin( string $plugin_id ): ?array {
		return $this->plugins[ $plugin_id ] ?? null;
	}

	/**
	 * Checks whether a plugin is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id The plugin identifier.
	 * @return bool True if the plugin is registered, false otherwise.
	 */
	public function has_plugin( string $plugin_id ): bool {
		return isset( $this->plugins[ $plugin_id ] );
	}
}
