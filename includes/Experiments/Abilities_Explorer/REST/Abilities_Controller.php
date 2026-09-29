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
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\Abilities_Explorer\Ability_Handler;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the `/ai/v1/abilities` routes the Abilities Explorer screen reads and writes.
 *
 * These are plain REST routes. None of them is, or may ever be, registered as
 * an ability: "invoke any ability by name" and "change what the assistant may
 * call" are exactly the two operations the assistant must not be handed.
 *
 * Ability names travel in the query string or the body, never the path. Every
 * name contains `/`, and a percent-encoded slash in a path hits Apache's
 * default `AllowEncodedSlashes Off` 404, which looks exactly like "ability not
 * found".
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
	 * Full path of the single-ability route. Takes `name` in the query string.
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
	 * Full path of the assistant-surface route.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SURFACE_ROUTE = 'ai/v1/abilities/surface';

	/**
	 * Capability required on every route, matching the Explorer screen.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The surface changes the surface route accepts.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	public const SURFACE_CHANGES = array( 'remove', 'restore', 'disable_policy', 'enable_policy' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

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
						/*
						 * A string, decoded here rather than by the client, so a
						 * large integer keeps its precision and scalar input
						 * still works.
						 */
						'input' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			$this->relative( self::SURFACE_ROUTE ),
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'change_surface' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'change' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => self::SURFACE_CHANGES,
						),
						'name'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * The one permission check every route shares.
	 *
	 * Requires `manage_options`, and requires that cookie authentication ran:
	 * `$wp_rest_auth_cookie` is `true` only once core saw a valid logged-in
	 * cookie, which is the only case where core's REST nonce check applies. A
	 * JWT, OAuth or Basic Auth plugin that sets the user through
	 * `determine_current_user` skips that nonce check entirely, so refusing
	 * application passwords alone would not be enough. Application passwords
	 * are refused as well, as defense in depth. This keeps exactly the exposure
	 * the admin-ajax handlers had: they only ever worked from a logged-in
	 * browser.
	 *
	 * @since x.x.x
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_permission() {
		// Literal rather than self::CAPABILITY so the capability sniff can read it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->forbidden( __( 'You do not have permission to use the Abilities Explorer.', 'ai' ) );
		}

		$cookie_authenticated = isset( $GLOBALS['wp_rest_auth_cookie'] ) && true === $GLOBALS['wp_rest_auth_cookie'];

		if ( ! $cookie_authenticated || ! empty( rest_get_authenticated_app_password() ) ) {
			return $this->forbidden( __( 'The Abilities Explorer can only be used from a logged-in browser session.', 'ai' ) );
		}

		return true;
	}

	/**
	 * Lists every registered ability with the assistant policy state.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_REST_Response The response.
	 */
	public function get_items(): WP_REST_Response {
		$sequence = self::next_sequence();

		return new WP_REST_Response(
			array(
				'items'    => $this->list_items(),
				'policy'   => $this->policy_state(),
				'sequence' => $sequence,
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
		$ability = $this->find( (string) $request->get_param( 'name' ) );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		return new WP_REST_Response( self::encodable( Ability_Handler::to_rest_item( $ability, true ) ), 200 );
	}

	/**
	 * Invokes an ability with the given raw JSON input.
	 *
	 * Route-level problems are HTTP errors: 404 for an unknown ability, 400 for
	 * malformed JSON or input the Explorer's own validation rejects. Whatever
	 * the ability itself answers, including core's schema check and the
	 * ability's own permission denial, is a 200 carrying a success flag.
	 *
	 * This path writes no AI request log row of its own.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function invoke( WP_REST_Request $request ) {
		$name = (string) $request->get_param( 'name' );

		if ( '' === $name || ! wp_has_ability( $name ) ) {
			return $this->not_found();
		}

		$ability = wp_get_ability( $name );

		if ( null === $ability ) {
			return $this->not_found();
		}

		$raw   = trim( (string) $request->get_param( 'input' ) );
		$input = null;

		// An empty string means "no input", matching the admin-ajax handler.
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

		/*
		 * A REST request lacks the admin includes admin-ajax had, and abilities
		 * written against that screen call functions such as get_plugins().
		 */
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		// Runs `WP_Ability::execute()`, so the ability's permission check and schema validation still apply.
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
	 * Changes the AI Workspace's tool surface.
	 *
	 * Remove and restore answer with the updated row; a policy change answers
	 * with the full list, because it can change every row's reason. A `false`
	 * from `Tool_Policy` means the stored state already matched (for example,
	 * the ability was already removed in another tab), which is success with
	 * `changed` false, not an error.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function change_surface( WP_REST_Request $request ) {
		$change = (string) $request->get_param( 'change' );
		$policy = new Tool_Policy();

		if ( 'disable_policy' === $change || 'enable_policy' === $change ) {
			$changed  = $policy->set_policy_disabled( 'disable_policy' === $change );
			$sequence = self::next_sequence();

			return new WP_REST_Response(
				array(
					'change'   => $change,
					'changed'  => $changed,
					'items'    => $this->list_items(),
					'policy'   => $this->policy_state(),
					'sequence' => $sequence,
				),
				200
			);
		}

		$name = (string) $request->get_param( 'name' );

		if ( '' === $name ) {
			return new WP_Error(
				'ai_abilities_explorer_missing_name',
				__( 'An ability name is required.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// Stored names are validated against the registry on read, so only a name that resolves to an ability is ever written.
		if ( ! wp_has_ability( $name ) ) {
			return $this->not_found();
		}

		$changed  = 'remove' === $change
			? $policy->exclude_from_surface( $name )
			: $policy->restore_to_surface( $name );
		$sequence = self::next_sequence();

		$ability = $this->find( $name );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		return new WP_REST_Response(
			array(
				'change'   => $change,
				'changed'  => $changed,
				'item'     => self::encodable( Ability_Handler::to_rest_item( $ability ) ),
				'policy'   => $this->policy_state(),
				'sequence' => $sequence,
			),
			200
		);
	}

	/**
	 * Returns every ability as a list item.
	 *
	 * Goes through `Ability_Handler::get_all_abilities()` so exclusion reasons
	 * are computed once for the request, not once per row.
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
	 * Returns the assistant admission policy state.
	 *
	 * @since x.x.x
	 *
	 * @return array{disabled: bool, admission_enabled: bool, active: bool} The policy state.
	 */
	private function policy_state(): array {
		$policy = new Tool_Policy();

		return array(
			'disabled'          => $policy->is_policy_disabled(),
			'admission_enabled' => $policy->admission_is_enabled(),
			'active'            => $policy->is_active(),
		);
	}

	/**
	 * Loads one formatted ability by name.
	 *
	 * @since x.x.x
	 *
	 * @param string $name The ability name.
	 * @return array<string, mixed>|\WP_Error The formatted ability, or a 404.
	 */
	private function find( string $name ) {
		// Checked first: `wp_get_ability()` on an unknown name raises a `_doing_it_wrong()` notice.
		if ( '' === $name || ! wp_has_ability( $name ) ) {
			return $this->not_found();
		}

		$ability = Ability_Handler::get_ability( $name );

		return null === $ability ? $this->not_found() : $ability;
	}

	/**
	 * Makes one item safe to encode without losing the rest of the payload.
	 *
	 * The item is encoded strictly, without core's repair pass, and each field
	 * that fails (invalid UTF-8, a NaN, a resource) becomes `null` and is named
	 * in `unencodable_fields`. One bad registrant then costs one field of one
	 * row, not the whole list.
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
	 * Returns a sequence number for a response.
	 *
	 * The server's clock in microseconds, read after any write the request
	 * made and before the state the response carries is read. On one server,
	 * a response with a higher number therefore reflects every change a
	 * response with a lower number reported, so the client keeps the highest
	 * number it has seen and drops any response that carries a lower one: a
	 * list refresh that started before a change cannot overwrite it. The value
	 * stays well inside JavaScript's safe-integer range.
	 *
	 * @since x.x.x
	 *
	 * @return int The sequence number.
	 */
	private static function next_sequence(): int {
		return (int) floor( microtime( true ) * 1000000 );
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
