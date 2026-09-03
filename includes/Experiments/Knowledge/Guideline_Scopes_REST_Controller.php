<?php
/**
 * Guideline scopes REST API controller.
 *
 * Read-only controller exposing the `wp_guideline_scopes()` registry at
 * `/wp/v2/knowledge/guideline-scopes`. This is a registry endpoint beside the
 * data routes (the same species as `/wp/v2/statuses`): it has no write paths and
 * carries no data semantics. The Settings → Guidelines page preloads it and
 * reads/writes the scope rows through the standard `/wp/v2/knowledge` collection.
 *
 * @package WordPress\AI\Experiments\Knowledge
 *
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Knowledge;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the guideline scopes registry.
 *
 * @since 1.3.0
 */
class Guideline_Scopes_REST_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace for the guideline scopes route.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	private const API_NAMESPACE = 'wp/v2';

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		$this->namespace = self::API_NAMESPACE;
		$this->rest_base = 'knowledge/guideline-scopes';
	}

	/**
	 * Registers the routes for the controller.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks whether the current user can read guideline scopes.
	 *
	 * Gated on the knowledge read capability, matching the data routes.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full details about the request.
	 * @return true|\WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Part of the controller signature.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Granted dynamically by wp_maybe_grant_knowledge_caps().
		if ( ! current_user_can( 'read_knowledge_items' ) ) {
			return new WP_Error(
				'rest_cannot_read',
				__( 'Sorry, you are not allowed to view guideline scopes.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all registered guideline scopes.
	 *
	 * Labels are resolved at request time (in the request locale).
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Full details about the request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_items( $request ) {
		$data = array();

		foreach ( wp_guideline_scopes() as $slug => $scope ) {
			$item   = $this->prepare_item_for_response( array_merge( array( 'slug' => $slug ), $scope ), $request );
			$data[] = $this->prepare_response_for_collection( $item );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Prepares a single scope for response.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed>                  $item    Scope data with a `slug` key.
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return \WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$fields = $this->get_fields_for_response( $request );
		$data   = array();

		if ( rest_is_field_included( 'slug', $fields ) ) {
			$data['slug'] = $item['slug'];
		}
		if ( rest_is_field_included( 'title', $fields ) ) {
			$data['title'] = $item['title'] ?? '';
		}
		if ( rest_is_field_included( 'description', $fields ) ) {
			$data['description'] = $item['description'] ?? '';
		}
		if ( rest_is_field_included( 'order', $fields ) ) {
			$data['order'] = (int) ( $item['order'] ?? 0 );
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieves the scope schema, conforming to JSON Schema.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, mixed> Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'guideline-scope',
			'type'       => 'object',
			'properties' => array(
				'slug'        => array(
					'description' => __( 'An alphanumeric identifier for the scope.', 'ai' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'title'       => array(
					'description' => __( 'The title for the scope.', 'ai' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'description' => array(
					'description' => __( 'A human-readable description of the scope.', 'ai' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'order'       => array(
					'description' => __( 'The sort order of the scope on the Settings page.', 'ai' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
