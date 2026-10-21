<?php
/**
 * Featured Connector plugins for the Connectors screen.
 *
 * @package WordPress\AI\Connectors
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Connectors;

use Throwable;
use WP_Connector_Registry;
use WordPress\AiClient\AiClient;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Lists reviewed Connector plugins on Settings > Connectors.
 *
 * Each featured connector is registered as a placeholder, shaped like the
 * Anthropic, Google and OpenAI connectors that core registers before their
 * plugins are installed. Core then renders its own Install, Activate and
 * Set up actions for it.
 *
 * @see docs/FEATURED_CONNECTORS.md
 *
 * @since x.x.x
 */
final class Featured_Connectors {

	/**
	 * Registers the hooks.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_connectors_init', array( $this, 'register_connectors' ) );

		// Core sorts by connector ID, which would put `ai_gateway` above `anthropic`.
		add_filter( 'script_module_data_options-connectors-wp-admin', array( $this, 'sort_connectors_by_name' ), 20 );
	}

	/**
	 * Sorts the connectors on the Connectors screen by name.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data Script module data.
	 * @return array<string, mixed> Script module data with connectors sorted by name.
	 */
	public function sort_connectors_by_name( array $data ): array {
		if ( empty( $data['connectors'] ) || ! is_array( $data['connectors'] ) ) {
			return $data;
		}

		$connectors = $data['connectors'];

		uksort(
			$connectors,
			/**
			 * @param int|string $a First connector ID.
			 * @param int|string $b Second connector ID.
			 */
			static function ( $a, $b ) use ( $connectors ): int {
				$name_a = is_array( $connectors[ $a ] ) && is_string( $connectors[ $a ]['name'] ?? null ) ? $connectors[ $a ]['name'] : (string) $a;
				$name_b = is_array( $connectors[ $b ] ) && is_string( $connectors[ $b ]['name'] ?? null ) ? $connectors[ $b ]['name'] : (string) $b;

				return strnatcasecmp( $name_a, $name_b );
			}
		);

		$data['connectors'] = $connectors;

		return $data;
	}

	/**
	 * Registers a placeholder connector for each featured connector.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Connector_Registry $registry Connector registry instance.
	 */
	public function register_connectors( WP_Connector_Registry $registry ): void {
		if ( ! wp_supports_ai() ) {
			return;
		}

		foreach ( $this->get_featured_connectors() as $connector_id => $connector ) {
			// Core already registered it, either as a default or from an active provider plugin.
			if ( $registry->is_registered( $connector_id ) ) {
				continue;
			}

			$registry->register( $connector_id, $this->get_connector_args( $connector_id, $connector ) );
		}
	}

	/**
	 * Returns the featured connectors, keyed by AI Client provider ID.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{name: string, description: string, plugin_file: string, credentials_url?: string, logo_url?: string}> The featured connectors.
	 */
	public function get_featured_connectors(): array {
		$connectors = array(
			'mistral'    => array(
				'name'            => 'Mistral',
				'description'     => __( 'Text and image generation with Mistral AI models. Requires a Mistral AI account; usage is billed by Mistral AI.', 'ai' ),
				'plugin_file'     => 'ai-provider-for-mistral/ai-provider-for-mistral.php',
				'credentials_url' => 'https://console.mistral.ai/api-keys',
				'logo_url'        => WPAI_PLUGIN_URL . 'assets/images/connectors/mistral.svg',
			),
			'ai_gateway' => array(
				'name'            => 'Vercel AI Gateway',
				'description'     => __( 'Text and image generation with models from many providers through one API key. Requires a Vercel account; usage is billed by Vercel.', 'ai' ),
				'plugin_file'     => 'vercel-ai-gateway-provider/plugin.php',
				'credentials_url' => 'https://vercel.com/d?to=%2F%5Bteam%5D%2F%7E%2Fai%2Fapi-keys&title=Get%20AI%20Gateway%20API%20key%20for%20your%20WordPress%20site',
				'logo_url'        => WPAI_PLUGIN_URL . 'assets/images/connectors/vercel-ai-gateway.svg',
			),
		);

		/**
		 * Filters the Connector plugins featured on Settings > Connectors.
		 *
		 * Use this to remove featured connectors, or to add others, such as a
		 * host's own Connector plugin. Return an empty array to feature none.
		 * Each key is the provider ID that the plugin registers with the AI
		 * Client. Entries with an invalid ID, name or plugin file are skipped
		 * and trigger a _doing_it_wrong() notice.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, array<string, string>> $connectors {
		 *     Featured connectors, keyed by AI Client provider ID.
		 *
		 *     @type string $name            Connector name.
		 *     @type string $description     Optional. What the connector offers, and any account or cost it needs.
		 *     @type string $plugin_file     Plugin main file, relative to the plugins directory. Its folder
		 *                                   must be the plugin's WordPress.org slug, which the screen uses
		 *                                   to install the plugin and to link to its listing.
		 *     @type string $credentials_url Optional. Where to get an API key.
		 *     @type string $logo_url        Optional. URL of the connector's logo.
		 * }
		 */
		$connectors = apply_filters( 'wpai_featured_connectors', $connectors );

		if ( ! is_array( $connectors ) ) {
			self::doing_it_wrong( __( 'The wpai_featured_connectors filter must return an array.', 'ai' ) );
			return array();
		}

		$valid = array();

		foreach ( $connectors as $connector_id => $connector ) {
			if ( ! is_string( $connector_id ) || ! preg_match( '/^[a-z0-9_-]+$/', $connector_id ) ) {
				self::doing_it_wrong(
					sprintf(
						/* translators: %s: Connector ID. */
						__( 'Featured connector ID "%s" is invalid. IDs may only contain lowercase letters, numbers, hyphens and underscores.', 'ai' ),
						(string) $connector_id
					)
				);
				continue;
			}

			if ( ! is_array( $connector ) ) {
				self::doing_it_wrong(
					sprintf(
						/* translators: %s: Connector ID. */
						__( 'Featured connector "%s" must be an array.', 'ai' ),
						$connector_id
					)
				);
				continue;
			}

			if ( empty( $connector['name'] ) || ! is_string( $connector['name'] ) ) {
				self::doing_it_wrong(
					sprintf(
						/* translators: %s: Connector ID. */
						__( 'Featured connector "%s" must have a name.', 'ai' ),
						$connector_id
					)
				);
				continue;
			}

			if ( empty( $connector['plugin_file'] ) || ! is_string( $connector['plugin_file'] ) || '.php' !== substr( $connector['plugin_file'], -4 ) ) {
				self::doing_it_wrong(
					sprintf(
						/* translators: %s: Connector ID. */
						__( 'Featured connector "%s" must have a plugin file ending in .php.', 'ai' ),
						$connector_id
					)
				);
				continue;
			}

			$entry = array(
				'name'        => $connector['name'],
				'description' => isset( $connector['description'] ) && is_string( $connector['description'] ) ? $connector['description'] : '',
				'plugin_file' => $connector['plugin_file'],
			);

			foreach ( array( 'credentials_url', 'logo_url' ) as $url_key ) {
				if ( empty( $connector[ $url_key ] ) || ! is_string( $connector[ $url_key ] ) ) {
					continue;
				}

				$entry[ $url_key ] = $connector[ $url_key ];
			}

			$valid[ $connector_id ] = $entry;
		}

		return $valid;
	}

	/**
	 * Triggers a _doing_it_wrong() notice for invalid output.
	 *
	 * @since x.x.x
	 *
	 * @param string $message Notice message.
	 */
	private static function doing_it_wrong( string $message ): void {
		_doing_it_wrong( self::class . '::get_featured_connectors', esc_html( $message ), 'x.x.x' );
	}

	/**
	 * Builds the connector registry arguments for a featured connector.
	 *
	 *
	 * @since x.x.x
	 *
	 * @param string                                                                                              $connector_id Connector ID.
	 * @param array{name: string, description: string, plugin_file: string, credentials_url?: string, logo_url?: string} $connector    Featured connector data.
	 * @return array<string, mixed> Connector registry arguments.
	 */
	public function get_connector_args( string $connector_id, array $connector ): array {
		$authentication = array_merge(
			array( 'method' => 'api_key' ),
			self::get_api_key_names( $connector_id )
		);

		if ( ! empty( $connector['credentials_url'] ) ) {
			$authentication['credentials_url'] = $connector['credentials_url'];
		}

		$args = array(
			'name'           => $connector['name'],
			'description'    => $connector['description'],
			'type'           => 'ai_provider',
			'plugin'         => array(
				'file'      => $connector['plugin_file'],
				'is_active' => static function () use ( $connector_id ): bool {
					try {
						return AiClient::defaultRegistry()->hasProvider( $connector_id );
					} catch ( Throwable $t ) {
						return false;
					}
				},
			),
			'authentication' => $authentication,
		);

		if ( ! empty( $connector['logo_url'] ) ) {
			$args['logo_url'] = $connector['logo_url'];
		}

		return $args;
	}

	/**
	 * Returns the API key setting, constant and environment variable names for an AI provider.
	 *
	 * @since x.x.x
	 *
	 * @param string $connector_id Connector ID.
	 * @return array{setting_name: string, constant_name: string, env_var_name: string} The names.
	 */
	public static function get_api_key_names( string $connector_id ): array {
		$sanitized_id = str_replace( '-', '_', $connector_id );
		$constant     = strtoupper( (string) preg_replace( '/([a-z])([A-Z])/', '$1_$2', $sanitized_id ) ) . '_API_KEY';

		return array(
			'setting_name'  => "connectors_ai_{$sanitized_id}_api_key",
			'constant_name' => $constant,
			'env_var_name'  => $constant,
		);
	}
}
