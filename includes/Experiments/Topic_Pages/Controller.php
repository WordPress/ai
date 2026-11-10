<?php
/**
 * Editorial REST actions for topic pages.
 *
 * @package WordPress\AI
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Topic_Pages;

use WP_Error;
use WP_REST_Request;

/**
 * Separates generation from explicit save and approval transitions.
 *
 * @since x.x.x
 */
final class Controller {

	/**
	 * Register authenticated editor routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			'ai/v1',
			'/topic-pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'action'   => array(
							'type'        => 'string',
							'description' => __( 'The editorial action to perform.', 'ai' ),
							'enum'        => array( 'generate', 'save', 'discard' ),
							'required'    => true,
						),
						'section'  => array(
							'type'        => 'string',
							'description' => __( 'The topic section to target.', 'ai' ),
							'enum'        => array_merge( array( 'all' ), Store::SECTIONS ),
							'required'    => true,
						),
						'revision' => array(
							'type'        => 'string',
							'description' => __( 'The revision token used to prevent overwriting newer changes.', 'ai' ),
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Checks whether the user can manage this topic.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Authenticated request.
	 * @return bool|\WP_Error Whether this user manages the requested term.
	 */
	public function permission( WP_REST_Request $request ) {
		$term = ( new Sources() )->term( (int) $request['id'] );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- WordPress supplies the registered taxonomy capability mapping.
		return $taxonomy && current_user_can( $taxonomy->cap->manage_terms ) && current_user_can( 'edit_term', $term->term_id );
	}

	/**
	 * Returns the current editorial state.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Authenticated request.
	 * @return array<string,mixed> Current editorial state.
	 */
	public function read( WP_REST_Request $request ): array {
		return ( new Store() )->state( (int) $request['id'] );
	}

	/**
	 * Applies an authenticated editorial action.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Authenticated request.
	 * @return array<string,mixed>|\WP_Error Updated state or a recoverable error.
	 */
	public function update( WP_REST_Request $request ) {
		$term_id = (int) $request['id'];
		$store   = new Store();

		if ( 'generate' !== $request['action'] ) {
			$result = $store->change( $term_id, (string) $request['action'], (string) $request['section'], (string) $request['revision'] );

			return is_wp_error( $result ) ? $result : $store->state( $term_id );
		}

		$state = $store->get( $term_id );

		if ( $state['revision'] !== $request['revision'] ) {
			return new WP_Error( 'topic_conflict', __( 'This topic has changed. Reload before generating.', 'ai' ), array( 'status' => 409 ) );
		}

		$sections = array( $request['section'] );

		if ( 'all' === $request['section'] ) {
			$sections = Store::SECTIONS;
		}

		$lock  = new Lock();
		$owner = $lock->acquire( $term_id, 'generation' );

		if ( false === $owner ) {
			return new WP_Error( 'topic_busy', __( 'Generation is already running for this topic. Try again shortly.', 'ai' ), array( 'status' => 409 ) );
		}

		try {
			$ability = wp_get_ability( 'ai/topic-overview' );

			if ( ! $ability ) {
				return new WP_Error( 'topic_unavailable', __( 'The topic generation ability is unavailable.', 'ai' ) );
			}

			$result = $ability->execute(
				array(
					'term_id'  => $term_id,
					'sections' => $sections,
				)
			);

			if ( ! $lock->owns( $term_id, 'generation', $owner ) ) {
				return new WP_Error(
					'topic_conflict',
					__( 'This generation request expired. Your saved content has not changed. Try generating again.', 'ai' ),
					array( 'status' => 409 )
				);
			}

			$saved = is_wp_error( $result ) ? $result : $store->save_generated( $term_id, $result, $request['revision'] );

			return is_wp_error( $saved ) ? $saved : $store->state( $term_id );
		} finally {
			$lock->release( $term_id, 'generation', $owner );
		}
	}
}
