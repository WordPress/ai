<?php
/**
 * The `ai/site-agent-update-post` ability.
 *
 * @package WordPress\AI\Abilities\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Site_Agent;

use WP_Error;
use WP_Post;
use WordPress\AI\Experiments\Site_Agent\Site_Agent;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Updates a small, explicit set of fields on an existing post or page.
 *
 * The ability can change the title, content, excerpt, or move an item between
 * draft and pending. It cannot publish, change the author, change the post
 * type, or touch fields outside this list.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Update_Post {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/site-agent-update-post';

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the ability.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			return;
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Update a post', 'ai' ),
				'description'         => __( 'Updates the title, content, excerpt, or draft/pending status of an existing post or page. Publishing is not available through this ability.', 'ai' ),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'status' => array( 'type' => 'string' ),
						'link'   => array( 'type' => 'string' ),
						'title'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'id', 'status', 'link', 'title' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'public'       => false,
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user may edit the item.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return bool|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_permission( $input = null ) {
		if ( ! Site_Agent::is_active() || ! Site_Agent::writes_allowed() ) {
			return new WP_Error(
				'site_agent_writes_disabled',
				__( 'Write proposals are disabled.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$id = is_array( $input ) && isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return new WP_Error(
				'site_agent_missing_id',
				__( 'An item ID is required.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error(
				'site_agent_update_denied',
				__( 'You do not have permission to edit that item.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Updates the item.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array<string, mixed>|\WP_Error The updated item, or an error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return new WP_Error(
				'site_agent_missing_id',
				__( 'An item ID is required.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$post = get_post( $id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'site_agent_post_not_found',
				__( 'That item was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error(
				'site_agent_invalid_post_type',
				__( 'Only posts and pages can be updated.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error(
				'site_agent_update_denied',
				__( 'You do not have permission to edit that item.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$postarr = array( 'ID' => $id );
		$changed = false;

		if ( isset( $input['title'] ) && is_scalar( $input['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
			$changed               = true;
		}

		if ( isset( $input['content'] ) && is_scalar( $input['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( (string) $input['content'] );
			$changed                 = true;
		}

		if ( isset( $input['excerpt'] ) && is_scalar( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
			$changed                 = true;
		}

		if ( isset( $input['status'] ) && is_scalar( $input['status'] ) ) {
			$status = sanitize_key( (string) $input['status'] );

			if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
				return new WP_Error(
					'site_agent_status_locked',
					__( 'This ability cannot move a published item back to draft or pending.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) {
				return new WP_Error(
					'site_agent_invalid_status',
					__( 'This ability can only move an item between draft and pending.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$postarr['post_status'] = $status;
			$changed                = true;
		}

		if ( ! $changed ) {
			return new WP_Error(
				'site_agent_nothing_to_update',
				__( 'Provide at least one field to update.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( (int) $result );

		return array(
			'id'     => (int) $result,
			'status' => $updated instanceof WP_Post ? $updated->post_status : '',
			'link'   => (string) get_permalink( (int) $result ),
			'title'  => (string) get_the_title( (int) $result ),
		);
	}

	/**
	 * Builds the input schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The post or page ID.', 'ai' ),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => __( 'Optional new title.', 'ai' ),
				),
				'content' => array(
					'type'        => 'string',
					'description' => __( 'Optional new content.', 'ai' ),
				),
				'excerpt' => array(
					'type'        => 'string',
					'description' => __( 'Optional new excerpt.', 'ai' ),
				),
				'status'  => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'pending' ),
					'description' => __( 'Optional new status. Publishing is not available.', 'ai' ),
				),
			),
		);
	}
}
