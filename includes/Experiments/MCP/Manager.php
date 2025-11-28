<?php
/**
 * MCP Experiment manager.
 *
 * @package WordPress\AI\Experiments\MCP
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP;

use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Abilities\GetAbilityInfoAbility;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;
use WordPress\AI\Experiments\MCP\REST\MCP_Controller;

use function add_action;
use function add_filter;
use function array_key_exists;
use function esc_html__;
use function do_action;
use function function_exists;
use function get_option;
use function is_array;
use function reset;
use function rest_get_server;
use function rest_url;
use function sanitize_key;
use function sanitize_text_field;
use function set_url_scheme;
use function update_option;
use function wp_generate_password;
use function wp_get_ability;
use function wp_get_ability_category;
use function wp_get_abilities;
use function wp_json_encode;
use function wp_parse_url;
use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_register_ability_category;

/**
 * Coordinates MCP adapter bootstrapping, configuration persistence, and REST data.
 *
 * @since 0.1.0
 */
class Manager {

	private const OPTION_ENABLED = 'ai_mcp_server_enabled';
	private const OPTION_SERVERS = 'ai_mcp_servers';
	private const OPTION_LEGACY_TOOLS = 'ai_mcp_enabled_tools';
	private const DEFAULT_VERSION = 'v1.0.0';

	/**
	 * Bootstrap hooks.
	 */
	public function init(): void {
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_adapter_category' ), 5 );
		add_action( 'wp_abilities_api_init', array( $this, 'register_adapter_abilities' ), 5 );
		add_action( 'init', array( $this, 'bootstrap_adapter' ), 20 );
		add_action( 'mcp_adapter_init', array( $this, 'register_servers' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Ensure the MCP adapter ability category is available.
	 */
	public function register_adapter_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'mcp-adapter' ) ) {
			return;
		}

		wp_register_ability_category(
			'mcp-adapter',
			array(
				'label'       => esc_html__( 'MCP Adapter', 'ai' ),
				'description' => esc_html__( 'Built-in abilities required for MCP discovery and execution.', 'ai' ),
			)
		);
	}

	/**
	 * Register the core MCP adapter abilities when Abilities API is available.
	 */
	public function register_adapter_abilities(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return;
		}

		$this->register_adapter_category();

		$this->maybe_register_adapter_ability( 'mcp-adapter/discover-abilities', DiscoverAbilitiesAbility::class );
		$this->maybe_register_adapter_ability( 'mcp-adapter/get-ability-info', GetAbilityInfoAbility::class );
		$this->maybe_register_adapter_ability( 'mcp-adapter/execute-ability', ExecuteAbilityAbility::class );
	}

	/**
	 * Register an adapter ability if it is not already available.
	 *
	 * @param string $ability_name  Ability identifier.
	 * @param string $ability_class Fully-qualified ability class name.
	 */
	private function maybe_register_adapter_ability( string $ability_name, string $ability_class ): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
			return;
		}

		if ( ! class_exists( $ability_class ) || ! is_callable( array( $ability_class, 'register' ) ) ) {
			return;
		}

		$ability_class::register();
	}

	/**
	 * Instantiate the adapter so servers register on REST requests.
	 */
	public function bootstrap_adapter(): void {
		if ( ! class_exists( McpAdapter::class ) ) {
			return;
		}

		McpAdapter::instance()->init();
	}

	/**
	 * Register each configured server with the adapter.
	 */
	public function register_servers(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$adapter = McpAdapter::instance();
		$servers = $this->get_servers();

		foreach ( $servers as $server ) {
			if ( empty( $server['enabled'] ) ) {
				continue;
			}

			$transports = $this->map_transports_to_classes( $server['transports'] ?? array( 'http' ) );
			$tools      = $this->resolve_tools_for_server( $server );

			try {
				$adapter->create_server(
					$server['id'],
					$server['route_namespace'],
					$server['route'],
					$server['name'],
					$server['description'] ?? '',
					self::DEFAULT_VERSION,
					$transports,
					ErrorLogMcpErrorHandler::class,
					NullMcpObservabilityHandler::class,
					$tools
				);
			} catch ( \Throwable $t ) {
				// Surface via admin notices/logging hooks.
				do_action(
					'ai_mcp_server_registration_failed',
					array(
						'server' => $server['id'],
						'error'  => $t->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Wire REST endpoints.
	 */
	public function register_rest_routes(): void {
		$controller = new MCP_Controller( $this );
		$controller->register_routes();
	}

	/**
	 * Determine if MCP is globally enabled.
	 */
	public function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Toggle MCP globally.
	 */
	public function set_enabled( bool $enabled ): void {
		update_option( self::OPTION_ENABLED, $enabled, false );
	}

	/**
	 * Retrieve all server configs, ensuring a default entry exists.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_servers(): array {
		$config = get_option( self::OPTION_SERVERS, null );

		if ( ! is_array( $config ) || empty( $config ) ) {
			$config = array( $this->get_default_server_id() => $this->build_default_server_config() );
			update_option( self::OPTION_SERVERS, $config, false );
		}

		return array_map( array( $this, 'sanitize_server_config' ), $config );
	}

	/**
	 * Replace the stored server configs.
	 *
	 * @param array<string,array<string,mixed>> $servers Server definitions keyed by ID.
	 */
	public function save_servers( array $servers ): void {
		$sanitized = array();

		foreach ( $servers as $server ) {
			if ( empty( $server['id'] ) ) {
				continue;
			}

			$sanitized[ $server['id'] ] = $this->sanitize_server_config( $server );
		}

		update_option( self::OPTION_SERVERS, $sanitized, false );
	}

	/**
	 * Get a single server configuration by ID.
	 */
	public function get_server( string $server_id ): ?array {
		$servers = $this->get_servers();

		return $servers[ $server_id ] ?? null;
	}

	/**
	 * Add a new server definition.
	 *
	 * @param array<string,mixed> $data Server data.
	 * @return array<string,mixed> Newly created server configuration.
	 */
	public function add_server( array $data ): array {
		$servers = $this->get_servers();

		$name             = sanitize_text_field( $data['name'] ?? esc_html__( 'New MCP Server', 'ai' ) );
		$route_namespace  = sanitize_key( $data['route_namespace'] ?? 'mcp' );
		$route            = $this->unique_route_slug( $data['route'] ?? $name, $servers );
		$id               = $this->unique_server_id( $route );
		$description      = sanitize_text_field( $data['description'] ?? '' );
		$transports       = $this->sanitize_transports( $data['transports'] ?? array( 'http' ) );
		$server_config    = array(
			'id'              => $id,
			'name'            => $name,
			'description'     => $description,
			'route_namespace' => $route_namespace,
			'route'           => $route,
			'enabled'         => true,
			'transports'      => $transports,
			'tools'           => array(),
		);

		$servers[ $id ] = $server_config;
		$this->save_servers( $servers );

		return $server_config;
	}

	/**
	 * Update an existing server config.
	 *
	 * @param string               $server_id Server identifier.
	 * @param array<string,mixed>  $data      Fields to override.
	 * @return array<string,mixed>|null Updated server config.
	 */
	public function update_server( string $server_id, array $data ): ?array {
		$servers = $this->get_servers();

		if ( empty( $servers[ $server_id ] ) ) {
			return null;
		}

		$current = $servers[ $server_id ];

		if ( array_key_exists( 'name', $data ) ) {
			$current['name'] = sanitize_text_field( (string) $data['name'] );
		}

		if ( array_key_exists( 'description', $data ) ) {
			$current['description'] = sanitize_text_field( (string) $data['description'] );
		}

		if ( array_key_exists( 'route', $data ) ) {
			$current['route'] = $this->unique_route_slug( (string) $data['route'], $servers, $server_id );
		}

		if ( array_key_exists( 'route_namespace', $data ) ) {
			$current['route_namespace'] = sanitize_key( (string) $data['route_namespace'] );
		}

		if ( array_key_exists( 'enabled', $data ) ) {
			$current['enabled'] = (bool) $data['enabled'];
		}

		if ( array_key_exists( 'transports', $data ) ) {
			$current['transports'] = $this->sanitize_transports( $data['transports'] );
		}

		if ( array_key_exists( 'tools', $data ) && is_array( $data['tools'] ) ) {
			$current['tools'] = array_values(
				array_unique(
					array_map(
						static fn( $name ) => sanitize_text_field( (string) $name ),
						$data['tools']
					)
				)
			);
		}

		$servers[ $server_id ] = $current;
		$this->save_servers( $servers );

		return $current;
	}

	/**
	 * Build overview payload consumed by the React UI.
	 *
	 * @param string|null $requested_server Server ID to focus on.
	 * @return array<string,mixed>
	 */
	public function build_overview_payload( ?string $requested_server = null ): array {
		$servers       = $this->get_servers();
		$active_server = $requested_server && isset( $servers[ $requested_server ] )
			? $servers[ $requested_server ]
			: reset( $servers );

		if ( ! $active_server ) {
			$active_server = $this->build_default_server_config();
		}

		$active_id   = $active_server['id'];
		$runtime_map = $this->get_runtime_servers_map();

		return array(
			'enabled'        => $this->is_enabled(),
			'servers'        => $this->summarize_servers( $servers, $runtime_map ),
			'activeServerId' => $active_id,
			'activeServer'   => $this->format_server_for_response( $active_server, $runtime_map[ $active_id ] ?? null ),
			'tools'          => $this->get_available_tools( $active_server ),
			'configTemplates'=> $this->get_client_templates( $active_server ),
		);
	}

	/**
	 * Return the available tools list decorated for the UI.
	 *
	 * @param array<string,mixed> $server Server config.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_available_tools( array $server ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities      = wp_get_abilities();
		$enabled        = $server['tools'] ?? array();
		$enabled_lookup = array_fill_keys( $enabled, true );

		$items = array();

		/** @var \WP_Ability $ability */
		foreach ( $abilities as $ability ) {
			$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();

			$items[] = array(
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'category'    => array(
					'slug'  => $ability->get_category(),
					'label' => $this->get_category_label( $ability->get_category() ),
				),
				'isPublic'    => (bool) ( $meta['mcp']['public'] ?? false ),
				'enabled'     => empty( $enabled )
					? (bool) ( $meta['mcp']['public'] ?? false )
					: isset( $enabled_lookup[ $ability->get_name() ] ),
			);
		}

		usort(
			$items,
			static fn( array $a, array $b ) => strcasecmp( $a['label'], $b['label'] )
		);

		return $items;
	}

	/**
	 * Update the allow-list for a server.
	 *
	 * @param string              $server_id Server ID.
	 * @param array<int,string>   $tools     Ability names.
	 * @return array<string,mixed>|null Updated server config.
	 */
	public function update_server_tools( string $server_id, array $tools ): ?array {
		return $this->update_server(
			$server_id,
			array(
				'tools' => $tools,
			)
		);
	}

	/**
	 * Return copy/paste templates for client configuration.
	 *
	 * @param array<string,mixed> $server Server data.
	 * @return array<string,array<string,string>>
	 */
	public function get_client_templates( array $server ): array {
		$endpoint = $this->build_endpoint_from_config( $server );

		if ( ! $endpoint ) {
			return array();
		}

		return array(
			'claude-desktop' => array(
				'id'       => 'claude-desktop',
				'fileName' => 'claude_desktop_config.json',
				'content'  => wp_json_encode(
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
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
			),
			'cursor' => array(
				'id'       => 'cursor',
				'fileName' => '.cursor/mcp.json',
				'content'  => wp_json_encode(
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
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
			),
			'generic' => array(
				'id'       => 'generic',
				'fileName' => 'mcp-server.json',
				'content'  => wp_json_encode(
					array(
						'endpoint'    => $endpoint,
						'transports'  => $server['transports'] ?? array( 'http' ),
						'headersHint' => 'Authorization: Basic <base64-credentials>',
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
			),
		);
	}

	/**
	 * Test the HTTP endpoint for a given server.
	 *
	 * @param string               $server_id Server ID.
	 * @param array<string,mixed>  $args      Optional request overrides.
	 * @return array<string,mixed>
	 */
	public function test_http_endpoint( string $server_id, array $args = array() ): array {
		$server = $this->get_server( $server_id );

		if ( ! $server ) {
			return array(
				'success' => false,
				'code'    => null,
				'message' => esc_html__( 'Server not found.', 'ai' ),
			);
		}

		$endpoint = $this->build_endpoint_from_config( $server );

		if ( ! $endpoint ) {
			return array(
				'success' => false,
				'code'    => null,
				'message' => esc_html__( 'No endpoint is available for this server yet.', 'ai' ),
			);
		}

		$method   = strtoupper( $args['method'] ?? 'GET' );
		$headers  = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$sanitized_headers = array();

		foreach ( $headers as $key => $value ) {
			$sanitized_headers[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $value );
		}

		$body = $args['body'] ?? null;

		$attempts = array( $endpoint );
		$scheme   = wp_parse_url( $endpoint, PHP_URL_SCHEME );

		if ( 'https' === $scheme ) {
			$attempts[] = set_url_scheme( $endpoint, 'http' );
		}

		foreach ( $attempts as $url ) {
			$request_args = array(
				'method'    => $method,
				'timeout'   => 10,
				'headers'   => $sanitized_headers,
				'sslverify' => true,
			);

			if ( is_array( $body ) ) {
				$request_args['body']                    = wp_json_encode( $body );
				$request_args['headers']['Content-Type'] = 'application/json';
			} elseif ( is_string( $body ) && '' !== $body ) {
				$request_args['body'] = $body;
			}

			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				// On HTTPS connection failures, retry with HTTP once.
				if ( 'https' === $scheme && false !== strpos( $message, 'cURL error 7' ) && $url === $endpoint ) {
					continue;
				}

				return array(
					'success' => false,
					'code'    => null,
					'message' => $message,
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body_str = wp_remote_retrieve_body( $response );

			return array(
				'success' => $code >= 200 && $code < 400,
				'code'    => $code,
				'message' => esc_html__( 'Endpoint responded.', 'ai' ),
				'body'    => $body_str ? substr( (string) $body_str, 0, 400 ) : '',
			);
		}

		return array(
			'success' => false,
			'code'    => null,
			'message' => esc_html__( 'Unable to reach the endpoint.', 'ai' ),
		);
	}

	/**
	 * Format servers for sidebar list.
	 *
	 * @param array<string,array<string,mixed>> $servers    Server configs.
	 * @param array<string,McpServer>           $runtime_map Adapter runtime map.
	 * @return array<int,array<string,string>>
	 */
	private function summarize_servers( array $servers, array $runtime_map ): array {
		$items = array();

		foreach ( $servers as $server ) {
			$runtime = $runtime_map[ $server['id'] ] ?? null;
			$items[] = array(
				'id'          => $server['id'],
				'name'        => $server['name'],
				'description' => $server['description'] ?? '',
				'enabled'     => (bool) ( $server['enabled'] ?? true ),
				'status'      => $this->determine_status( $server, $runtime ),
			);
		}

		return $items;
	}

	/**
	 * Format a server for REST responses.
	 *
	 * @param array<string,mixed> $server  Configured server.
	 * @param McpServer|null      $runtime Runtime instance.
	 * @return array<string,mixed>
	 */
	private function format_server_for_response( array $server, ?McpServer $runtime ): array {
		return array(
			'id'              => $server['id'],
			'name'            => $server['name'],
			'description'     => $server['description'] ?? '',
			'route_namespace' => $server['route_namespace'],
			'route'           => $server['route'],
			'transports'      => $server['transports'] ?? array( 'http' ),
			'enabled'         => (bool) ( $server['enabled'] ?? true ),
			'http_endpoint'   => $runtime ? $this->build_runtime_endpoint( $runtime ) : $this->build_endpoint_from_config( $server ),
			'cli_command'     => $this->build_cli_command( $server ),
			'status'          => $this->determine_status( $server, $runtime ),
			'has_route'       => null !== $runtime,
		);
	}

	/**
	 * Figure out the status label for a server.
	 */
	private function determine_status( array $server, ?McpServer $runtime ): string {
		if ( empty( $server['enabled'] ) || ! $this->is_enabled() ) {
			return 'disabled';
		}

		return $runtime ? 'running' : 'initializing';
	}

	/**
	 * Resolve which tools should be registered for a server.
	 *
	 * @param array<string,mixed> $server Server definition.
	 * @return array<string>
	 */
	private function resolve_tools_for_server( array $server ): array {
		$tools = $server['tools'] ?? array();

		if ( ! empty( $tools ) ) {
			return array_values(
				array_unique(
					array_merge(
						$this->system_tool_names(),
						array_map( 'sanitize_text_field', $tools )
					)
				)
			);
		}

		return array_values(
			array_unique(
				array_merge(
					$this->system_tool_names(),
					$this->discover_public_tools()
				)
			)
		);
	}

	/**
	 * Discover abilities marked as MCP-public.
	 *
	 * @return array<string>
	 */
	private function discover_public_tools(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$names = array();

		foreach ( wp_get_abilities() as $ability ) {
			$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();

			if ( ! empty( $meta['mcp']['public'] ) ) {
				$names[] = $ability->get_name();
			}
		}

		return $names;
	}

	/**
	 * Build CLI command helper.
	 */
	private function build_cli_command( array $server ): string {
		return sprintf(
			'wp mcp-adapter serve --server=%s',
			$server['id']
		);
	}

	/**
	 * Build endpoint string from runtime instance.
	 */
	private function build_runtime_endpoint( McpServer $server ): string {
		$namespace = trim( $server->get_server_route_namespace(), '/' );
		$route     = trim( $server->get_server_route(), '/' );

		return rest_url( "{$namespace}/{$route}" );
	}

	/**
	 * Build endpoint string from stored config.
	 */
	private function build_endpoint_from_config( array $server ): string {
		$namespace = trim( $server['route_namespace'], '/' );
		$route     = trim( $server['route'], '/' );

		return rest_url( "{$namespace}/{$route}" );
	}

	/**
	 * Map transport slugs to class names.
	 *
	 * @param array<int,string> $transports Transport identifiers.
	 * @return array<int,class-string>
	 */
	private function map_transports_to_classes( array $transports ): array {
		$map = array(
			'http' => HttpTransport::class,
		);

		$classes = array();
		foreach ( $transports as $transport ) {
			$slug = strtolower( (string) $transport );
			if ( isset( $map[ $slug ] ) ) {
				$classes[] = $map[ $slug ];
			}
		}

		return $classes ?: array( HttpTransport::class );
	}

	/**
	 * Provide canonical list of system tool names that must remain available.
	 *
	 * @return array<string>
	 */
	private function system_tool_names(): array {
		return array(
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
		);
	}

	/**
	 * Get runtime servers keyed by ID.
	 *
	 * @return array<string,McpServer>
	 */
	private function get_runtime_servers_map(): array {
		if ( ! class_exists( McpAdapter::class ) ) {
			return array();
		}

		$map     = array();
		$adapter = McpAdapter::instance();

		foreach ( $adapter->get_servers() as $server ) {
			$map[ $server->get_server_id() ] = $server;
		}

		return $map;
	}

	/**
	 * Sanitize transport list.
	 *
	 * @param mixed $transports Raw transports.
	 * @return array<int,string>
	 */
	private function sanitize_transports( $transports ): array {
		if ( ! is_array( $transports ) ) {
			return array( 'http' );
		}

		$valid = array( 'http' );

		$list = array();
		foreach ( $transports as $transport ) {
			$slug = strtolower( sanitize_key( (string) $transport ) );
			if ( in_array( $slug, $valid, true ) ) {
				$list[] = $slug;
			}
		}

		return $list ?: array( 'http' );
	}

	/**
	 * Generate a default server configuration.
	 *
	 * @return array<string,mixed>
	 */
	private function build_default_server_config(): array {
		$legacy_tools = get_option( self::OPTION_LEGACY_TOOLS, array() );

		if ( ! empty( $legacy_tools ) ) {
			delete_option( self::OPTION_LEGACY_TOOLS );
		}

		return array(
			'id'              => $this->get_default_server_id(),
			'name'            => esc_html__( 'Default Server', 'ai' ),
			'description'     => esc_html__( 'Automatically exposes public abilities over HTTP.', 'ai' ),
			'route_namespace' => 'mcp',
			'route'           => 'default-server',
			'enabled'         => true,
			'transports'      => array( 'http' ),
			'tools'           => array_map( 'sanitize_text_field', (array) $legacy_tools ),
		);
	}

	/**
	 * Unique ID for the default server.
	 */
	private function get_default_server_id(): string {
		return 'ai-mcp-default';
	}

	/**
	 * Ensure server config has required fields and sanitized values.
	 *
	 * @param array<string,mixed> $server Raw config.
	 * @return array<string,mixed>
	 */
	private function sanitize_server_config( array $server ): array {
		return array(
			'id'              => sanitize_key( $server['id'] ?? $this->unique_server_id( 'server' ) ),
			'name'            => sanitize_text_field( $server['name'] ?? esc_html__( 'MCP Server', 'ai' ) ),
			'description'     => sanitize_text_field( $server['description'] ?? '' ),
			'route_namespace' => sanitize_key( $server['route_namespace'] ?? 'mcp' ),
			'route'           => sanitize_key( $server['route'] ?? 'default-server' ),
			'enabled'         => (bool) ( $server['enabled'] ?? true ),
			'transports'      => $this->sanitize_transports( $server['transports'] ?? array( 'http' ) ),
			'tools'           => array_map(
				static fn( $name ) => sanitize_text_field( (string) $name ),
				is_array( $server['tools'] ?? null ) ? (array) $server['tools'] : array()
			),
		);
	}

	/**
	 * Generate a unique server ID.
	 */
	private function unique_server_id( string $seed ): string {
		return sanitize_key( 'ai-mcp-' . $seed . '-' . wp_generate_password( 4, false ) );
	}

	/**
	 * Generate a unique route slug.
	 *
	 * @param string               $value        Desired slug.
	 * @param array<string,array>  $existing     All servers.
	 * @param string|null          $current_id   Current server ID (when updating).
	 * @return string
	 */
	private function unique_route_slug( string $value, array $existing, ?string $current_id = null ): string {
		$base = sanitize_key( $value );

		if ( '' === $base ) {
			$base = 'server';
		}

		$slug    = $base;
		$counter = 1;

		$conflicts = array();
		foreach ( $existing as $id => $server ) {
			if ( $current_id && $id === $current_id ) {
				continue;
			}

			$conflicts[ $server['route'] ] = true;
		}

		while ( isset( $conflicts[ $slug ] ) ) {
			$slug = $base . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	/**
	 * Helper to get ability category labels.
	 */
	private function get_category_label( string $slug ): string {
		if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( $slug ) ) {
			return $slug;
		}

		$category = function_exists( 'wp_get_ability_category' ) ? wp_get_ability_category( $slug ) : null;

		return $category ? $category->get_label() : $slug;
	}
}
