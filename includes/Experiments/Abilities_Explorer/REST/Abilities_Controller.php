<?php
/**
 * REST controller for the Abilities Explorer.
 *
 * @package WordPress\AI\Experiments\Abilities_Explorer
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Abilities_Explorer\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI\Experiments\Abilities_Explorer\Ability_Handler;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the `/ai/v1/abilities` routes the Abilities Explorer screen reads and invokes.
 *
 * Never register these as abilities: an AI assistant must not be able to invoke any ability by name.
 * Names go in the query string or body, not the path, because Apache 404s on an encoded `/`.
 *
 * @since x.x.x
 */
final class Abilities_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const API_NAMESPACE = 'ai/v1';

	/**
	 * Full path of the list route.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITIES_ROUTE = 'ai/v1/abilities';

	/**
	 * Full path of the single-ability route.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ITEM_ROUTE = 'ai/v1/abilities/item';

	/**
	 * Full path of the invoke route.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const INVOKE_ROUTE = 'ai/v1/abilities/invoke';

	/**
	 * Hooks route registration.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		$name_arg = array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::API_NAMESPACE,
			$this->relative( self::ABILITIES_ROUTE ),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			$this->relative( self::ITEM_ROUTE ),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array( 'name' => $name_arg ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			$this->relative( self::INVOKE_ROUTE ),
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'invoke' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'  => $name_arg,
						// Decoded on the server so large integers keep their precision.
						'input' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
	}

	/**
	 * Allows only an administrator's cookie-authenticated browser session.
	 *
	 * These routes can invoke any ability, so other auth methods (application passwords,
	 * or plugins that set the user via `determine_current_user`) are refused. The nonce is
	 * checked here too, because core skips its own check when an earlier
	 * `rest_authentication_errors` filter has already returned.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->forbidden( __( 'You do not have permission to use the Abilities Explorer.', 'ai' ) );
		}

		$cookie_authenticated = isset( $GLOBALS['wp_rest_auth_cookie'] ) && true === $GLOBALS['wp_rest_auth_cookie'];

		if ( ! $cookie_authenticated || ! empty( rest_get_authenticated_app_password() ) || ! self::has_valid_nonce( $request ) ) {
			return $this->forbidden( __( 'The Abilities Explorer can only be used from a logged-in browser session.', 'ai' ) );
		}

		return true;
	}

	/**
	 * Reports whether the request carries a valid `wp_rest` nonce, in the header or `_wpnonce`.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool True when the nonce is valid for the current user.
	 */
	private static function has_valid_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( null === $nonce || '' === $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}

		return false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Lists every registered ability.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_REST_Response The response.
	 */
	public function get_items(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'items' => $this->list_items(),
			),
			200
		);
	}

	/**
	 * Returns one ability with its schemas, raw data and example input.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function get_item( WP_REST_Request $request ) {
		$resolved = $this->resolve( (string) $request->get_param( 'name' ) );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$ability = $this->find( $resolved );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		return new WP_REST_Response( self::encodable( Ability_Handler::to_rest_item( $ability, true ) ), 200 );
	}

	/**
	 * Invokes an ability with the given raw JSON input.
	 *
	 * Bad requests are HTTP errors (404, 400). Whatever the ability itself returns,
	 * including its own errors, is a 200 with a `success` flag.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function invoke( WP_REST_Request $request ) {
		$name    = (string) $request->get_param( 'name' );
		$ability = $this->resolve( $name );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		$raw   = trim( (string) $request->get_param( 'input' ) );
		$input = null;

		if ( '' !== $raw ) {
			$input = json_decode( $raw, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'ai_abilities_explorer_invalid_json',
					__( 'Invalid JSON input.', 'ai' ),
					array(
						'status' => 400,
						'errors' => array( json_last_error_msg() ),
					)
				);
			}
		}

		$schema = $ability->get_input_schema();

		if ( ! empty( $schema ) ) {
			$validation = Ability_Handler::validate_input( $schema, $input );

			if ( empty( $validation['valid'] ) ) {
				return new WP_Error(
					'ai_abilities_explorer_invalid_input',
					__( 'Input validation failed.', 'ai' ),
					array(
						'status' => 400,
						'errors' => $validation['errors'],
					)
				);
			}
		}

		// Some abilities call admin-only functions such as get_plugins().
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$result = Ability_Handler::invoke_ability( $name, $input );

		if ( $result['success'] ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result['data'] ?? null,
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => $result['code'] ?? '',
					'message' => $result['error'] ?? '',
					'data'    => $result['data'] ?? null,
				),
			),
			200
		);
	}

	/**
	 * Returns every ability as a list item.
	 *
	 * @since x.x.x
	 *
	 * @return list<array<string, mixed>> The list items.
	 */
	private function list_items(): array {
		$items = array();

		foreach ( Ability_Handler::get_all_abilities() as $ability ) {
			$items[] = self::encodable( Ability_Handler::to_rest_item( $ability ) );
		}

		return $items;
	}

	/**
	 * Resolves an ability name to the registered ability.
	 *
	 * @since x.x.x
	 *
	 * @param string $name The ability name.
	 * @return \WP_Ability|\WP_Error The ability, or a 404.
	 */
	private function resolve( string $name ) {
		// Checked first: `wp_get_ability()` on an unknown name raises a `_doing_it_wrong()` notice.
		if ( '' === $name || ! wp_has_ability( $name ) ) {
			return $this->not_found();
		}

		$ability = wp_get_ability( $name );

		return null === $ability ? $this->not_found() : $ability;
	}

	/**
	 * Formats a resolved ability.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability The ability, as returned by {@see self::resolve()}.
	 * @return array<string, mixed>|\WP_Error The formatted ability, or a 404.
	 */
	private function find( \WP_Ability $ability ) {
		return Ability_Handler::get_ability( $ability->get_name() ) ?? $this->not_found();
	}

	/**
	 * Nulls any field that cannot be JSON-encoded and names it in `unencodable_fields`.
	 *
	 * One bad registrant then costs one field of one row, not the whole list.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item The item.
	 * @return array<string, mixed> The item, with `unencodable_fields` added.
	 */
	private static function encodable( array $item ): array {
		$unencodable = array();

		if ( ! self::encodes( $item ) ) {
			foreach ( $item as $field => $value ) {
				if ( self::encodes( $value ) ) {
					continue;
				}

				$item[ $field ] = null;
				$unencodable[]  = (string) $field;
			}
		}

		$item['unencodable_fields'] = $unencodable;

		return $item;
	}

	/**
	 * Reports whether a value encodes as JSON as-is.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The value.
	 * @return bool True when it encodes.
	 */
	private static function encodes( $value ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() repairs invalid UTF-8 silently; the strict answer is the point here.
		return false !== json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Returns a route path relative to the namespace.
	 *
	 * @since x.x.x
	 *
	 * @param string $route The full route path.
	 * @return string The path after the namespace, with a leading slash.
	 *
	 * @phpstan-return non-falsy-string
	 */
	private function relative( string $route ): string {
		return '/' . ltrim( substr( $route, strlen( self::API_NAMESPACE ) ), '/' );
	}

	/**
	 * Builds the 403 every refusal uses.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The message.
	 * @return \WP_Error The error.
	 */
	private function forbidden( string $message ): WP_Error {
		return new WP_Error( 'rest_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * Builds the 404 for an unknown ability.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The error.
	 */
	private function not_found(): WP_Error {
		return new WP_Error(
			'ai_abilities_explorer_not_found',
			__( 'Ability not found.', 'ai' ),
			array( 'status' => 404 )
		);
	}
}
