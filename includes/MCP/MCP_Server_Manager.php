<?php
/**
 * Coordinates MCP adapter bootstrapping and server telemetry for the admin UI.
 *
 * @package WordPress\AI\MCP
 */

declare( strict_types=1 );

namespace WordPress\AI\MCP;

use WP_Ability;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WordPress\AI\MCP\REST\Mcp_Server_Controller;

use function add_action;
use function add_filter;
use function get_option;
use function is_wp_error;
use function rest_get_server;
use function rest_url;
use function sanitize_text_field;
use function update_option;
use function wp_get_ability_category;
use function wp_get_abilities;
use function wp_json_encode;
use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function __;
use function function_exists;
use function mb_substr;

/**
 * Provides a thin domain layer around the MCP adapter so the React UI can
 * query status, available tools, and configuration templates via REST.
 *
 * @since 0.1.0
 */
class MCP_Server_Manager {

	/**
	 * Option storing whether the default MCP server should be created.
	 */
	private const OPTION_ENABLED = 'ai_mcp_server_enabled';

	/**
	 * Option storing the allow-list of abilities exposed via MCP.
	 */
	private const OPTION_ENABLED_TOOLS = 'ai_mcp_enabled_tools';

	/**
	 * Identifier used by the adapter's default server factory.
	 */
	private const DEFAULT_SERVER_ID = 'mcp-adapter-default-server';

	/**
	 * Adapter-provided abilities that must remain available.
	 *
	 * @var array<string>
	 */
	private array $system_tool_names = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	);

	/**
	 * Hooks filters, REST controllers, and initializes the adapter.
	 *
	 * @since 0.1.0
	 */
	public function init(): void {
		add_filter( 'mcp_adapter_create_default_server', array( $this, 'should_create_default_server' ) );
		add_filter( 'mcp_adapter_default_server_config', array( $this, 'filter_default_server_config' ) );
		add_filter( 'wp_register_ability_args', array( $this, 'filter_ability_registration_args' ), 10, 2 );

		add_action( 'init', array( $this, 'bootstrap_adapter' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest_controllers' ) );
	}

	/**
	 * Ensures the adapter spins up on every request so the server is discoverable.
	 */
	public function bootstrap_adapter(): void {
		if ( ! class_exists( McpAdapter::class ) ) {
			return;
		}

		McpAdapter::instance()->init();
	}

	/**
	 * Hooks the REST controller that the React UI consumes.
	 */
	public function register_rest_controllers(): void {
		$controller = new Mcp_Server_Controller( $this );
		$controller->register_routes();
	}

	/**
	 * Determines whether the default server should be created.
	 *
	 * @param bool $should_create Whether downstream logic wants the server.
	 * @return bool
	 */
	public function should_create_default_server( bool $should_create ): bool {
		if ( ! $should_create ) {
			return false;
		}

		return $this->is_server_enabled();
	}

	/**
	 * Applies the allow-list to the default server configuration.
	 *
	 * @param array<string, mixed> $config Default server configuration.
	 * @return array<string, mixed>
	 */
	public function filter_default_server_config( array $config ): array {
		$enabled_tools = $this->get_enabled_tool_names();

		if ( ! empty( $enabled_tools ) && ! empty( $config['tools'] ) && is_array( $config['tools'] ) ) {
			$config['tools'] = array_values( array_intersect( $config['tools'], $enabled_tools ) );
		}

		// Always include the system abilities even if filtering removed them.
		$config['tools'] = array_values( array_unique( array_merge( $this->system_tool_names, $config['tools'] ?? array() ) ) );

		return $config;
	}

	/**
	 * Filters each ability's metadata as it is registered so we can virtually
	 * toggle `mcp.public` without forcing every ability to duplicate this logic.
	 *
	 * @param array<string, mixed> $args Ability registration arguments.
	 * @param string               $name Ability name.
	 * @return array<string, mixed>
	 */
	public function filter_ability_registration_args( array $args, string $name ): array {
		if ( empty( $args['meta']['mcp'] ) || in_array( $name, $this->system_tool_names, true ) ) {
			return $args;
		}

		$enabled_tools = $this->get_enabled_tool_names();

		// Empty option means "inherit whatever the ability declares".
		if ( empty( $enabled_tools ) ) {
			return $args;
		}

		$should_remain_public = in_array( $name, $enabled_tools, true );

		if ( $should_remain_public ) {
			return $args;
		}

		$args['meta']['mcp']['public'] = false;

		return $args;
	}

	/**
	 * Whether administrators have opted into running the default server.
	 */
	public function is_server_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Persist server enabled flag.
	 *
	 * @param bool $enabled Desired state.
	 */
	public function set_server_enabled( bool $enabled ): void {
		update_option( self::OPTION_ENABLED, $enabled, false );
	}

	/**
	 * Returns the sanitized allow-list of abilities.
	 *
	 * @return array<string>
	 */
	public function get_enabled_tool_names(): array {
		$tools = get_option( self::OPTION_ENABLED_TOOLS, array() );

		if ( empty( $tools ) || ! is_array( $tools ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'sanitize_text_field', $tools ) ) );
	}

	/**
	 * Updates the allow-list used to toggle MCP exposure.
	 *
	 * @param array<int, string> $tool_names Ability names from the UI.
	 */
	public function update_enabled_tools( array $tool_names ): void {
		$sanitized = array();

		foreach ( $tool_names as $maybe_name ) {
			$maybe_name = sanitize_text_field( (string) $maybe_name );

			if ( '' === $maybe_name ) {
				continue;
			}

			if ( in_array( $maybe_name, $sanitized, true ) ) {
				continue;
			}

			$sanitized[] = $maybe_name;
		}

		update_option( self::OPTION_ENABLED_TOOLS, $sanitized, false );
	}

	/**
	 * Provides a serializable view of the MCP server. If the adapter has not yet
	 * created the server we still return useful metadata so the UI can display a
	 * helpful state.
	 *
	 * @return array<string, mixed>
	 */
	public function get_server_details(): array {
		$server = $this->get_server_instance();

		if ( ! $server ) {
			return array(
				'id'              => null,
				'name'            => null,
				'description'     => null,
				'route_namespace' => null,
				'route'           => null,
				'http_endpoint'   => null,
				'cli_command'     => null,
				'status'          => $this->is_server_enabled() ? 'initializing' : 'disabled',
				'has_route'       => false,
			);
		}

		$endpoint   = $this->build_http_endpoint( $server );
		$route_live = $this->is_route_registered( $server );

		return array(
			'id'              => $server->get_server_id(),
			'name'            => $server->get_server_name(),
			'description'     => $server->get_server_description(),
			'route_namespace' => $server->get_server_route_namespace(),
			'route'           => $server->get_server_route(),
			'http_endpoint'   => $endpoint,
			'cli_command'     => $this->get_cli_command( $server ),
			'has_route'       => $route_live,
			'status'          => $route_live ? 'running' : 'initializing',
		);
	}

	/**
	 * Builds the command users can run via WP-CLI for STDIO connections.
	 */
	public function get_cli_command( ?McpServer $server = null ): ?string {
		$server = $server ?: $this->get_server_instance();

		if ( ! $server ) {
			return null;
		}

		return sprintf( 'wp mcp-adapter serve --server=%s', $server->get_server_id() );
	}

	/**
	 * Returns the HTTP endpoint if the server is available.
	 */
	public function get_http_endpoint(): ?string {
		$server = $this->get_server_instance();

		return $server ? $this->build_http_endpoint( $server ) : null;
	}

	/**
	 * Computes template files for common MCP clients so the React UI can
	 * display copy/paste config snippets.
	 *
	 * @return array<string, array<string, string|null>>
	 */
	public function get_client_templates(): array {
		$endpoint = $this->get_http_endpoint();

		if ( ! $endpoint ) {
			return array();
		}

		return array(
			'claude-desktop' => array(
				'id'       => 'claude-desktop',
				'fileName' => 'claude_desktop_config.json',
				'content'  => $this->encode_template(
					array(
						'mcpServers' => array(
							'wordpress' => array(
								'command' => 'npx',
								'args'    => array( 'mcp-remote', $endpoint ),
								'env'     => array(
									'MCP_HEADERS' => 'Authorization: Basic <base64-credentials>',
								),
							),
						),
					)
				),
			),
			'cursor'         => array(
				'id'       => 'cursor',
				'fileName' => '.cursor/mcp.json',
				'content'  => $this->encode_template(
					array(
						'mcpServers' => array(
							'wordpress' => array(
								'command' => 'npx',
								'args'    => array( 'mcp-remote', $endpoint ),
								'env'     => array(
									'MCP_HEADERS' => 'Authorization: Basic <base64-credentials>',
								),
							),
						),
					)
				),
			),
			'generic'        => array(
				'id'       => 'generic',
				'fileName' => 'mcp-server.json',
				'content'  => $this->encode_template(
					array(
						'endpoint'    => $endpoint,
						'transports'  => array( 'http' ),
						'headersHint' => 'Authorization: Basic <base64-credentials>',
					)
				),
			),
		);
	}

	/**
	 * Queries all registered abilities and returns the ones that are marked with
	 * MCP metadata so the UI can display toggle switches and categories.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_tools(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities     = wp_get_abilities();
		$enabled_tools = $this->get_enabled_tool_names();
		$result        = array();

		/** @var WP_Ability $ability */
		foreach ( $abilities as $ability ) {
			$meta  = $ability->get_meta();
			$mcp   = $meta['mcp'] ?? null;
			$type  = $mcp['type'] ?? 'tool';
			$public = (bool) ( $mcp['public'] ?? false );

			if ( empty( $mcp ) || 'tool' !== $type ) {
				continue;
			}

			$result[] = array(
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'category'    => array(
					'slug'  => $ability->get_category(),
					'label' => $this->get_category_label( $ability->get_category() ),
				),
				'isPublic'    => $public,
				'enabled'     => $this->is_tool_enabled( $ability->get_name(), $enabled_tools ),
			);
		}

		usort(
			$result,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $result;
	}

	/**
	 * Performs a lightweight HTTP request against the MCP endpoint to validate
	 * connectivity. We treat any non-network error (including 401) as proof the
	 * endpoint is reachable so admins get actionable messaging.
	 *
	 * @param array<string, mixed> $args Optional request overrides.
	 * @return array<string, mixed>
	 */
	public function test_http_endpoint( array $args = array() ): array {
		$endpoint = $this->get_http_endpoint();

		if ( ! $endpoint ) {
			return array(
				'success' => false,
				'code'    => null,
				'message' => __( 'No MCP HTTP endpoint is currently registered.', 'ai' ),
			);
		}

		$method  = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		$headers = array();

		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $key => $value ) {
				$headers[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		$request_args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => $headers,
		);

		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$request_args['body']    = wp_json_encode( $args['body'] );
			$request_args['headers'] = array_merge(
				$request_args['headers'],
				array( 'Content-Type' => 'application/json' )
			);
		}

		$response = wp_remote_request( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'code'    => null,
				'message' => $response->get_error_message(),
			);
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$reachable = ( $code >= 200 && $code < 600 ); // Any HTTP response proves reachability.
		$message   = $reachable ? __( 'Endpoint responded.', 'ai' ) : __( 'Endpoint did not respond.', 'ai' );
		$body_snip = '';

		if ( $body ) {
			$body_snip = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 400 ) : substr( $body, 0, 400 );
		}

		return array(
			'success' => $reachable,
			'code'    => $code,
			'message' => $message,
			'body'    => $body_snip,
		);
	}

	/**
	 * Helper to fetch the adapter server instance if available.
	 */
	private function get_server_instance(): ?McpServer {
		if ( ! class_exists( McpAdapter::class ) ) {
			return null;
		}

		$adapter = McpAdapter::instance();
		$servers = $adapter->get_servers();

		if ( isset( $servers[ self::DEFAULT_SERVER_ID ] ) ) {
			return $servers[ self::DEFAULT_SERVER_ID ];
		}

		return $servers ? reset( $servers ) ?: null : null;
	}

	/**
	 * Builds the REST URL for the MCP HTTP transport.
	 */
	private function build_http_endpoint( McpServer $server ): string {
		$namespace = trim( $server->get_server_route_namespace(), '/' );
		$route     = trim( $server->get_server_route(), '/' );

		return rest_url( $namespace . '/' . $route );
	}

	/**
	 * Checks if WordPress has registered the MCP REST route yet.
	 */
	private function is_route_registered( McpServer $server ): bool {
		$rest_server = rest_get_server();

		if ( ! $rest_server ) {
			return false;
		}

		$route = '/' . trim( $server->get_server_route_namespace(), '/' ) . '/' . trim( $server->get_server_route(), '/' );

		return array_key_exists( $route, $rest_server->get_routes() );
	}

	/**
	 * Maps the stored allow-list to a boolean for the given ability.
	 *
	 * @param string        $ability_name Ability name.
	 * @param array<string> $enabled_tools Allow-list.
	 */
	private function is_tool_enabled( string $ability_name, array $enabled_tools ): bool {
		if ( in_array( $ability_name, $this->system_tool_names, true ) ) {
			return true;
		}

		return empty( $enabled_tools ) || in_array( $ability_name, $enabled_tools, true );
	}

	/**
	 * Resolves the ability category label for display purposes.
	 */
	private function get_category_label( string $slug ): string {
		$category = wp_get_ability_category( $slug );

		return $category ? $category->get_label() : $slug;
	}

	/**
	 * Pretty-prints JSON so the UI can show legible templates.
	 */
	private function encode_template( array $data ): string {
		return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}
}
