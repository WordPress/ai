<?php
/**
 * The `ai/site-agent-create-draft` ability.
 *
 * @package WordPress\AI\Abilities\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Site_Agent;

use WP_Error;
use WordPress\AI\Experiments\Site_Agent\Site_Agent;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Creates a draft post or page.
 *
 * The ability is deliberately narrow: it creates a draft owned by the current
 * user and never publishes. Publishing is a separate, reviewable ability.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Create_Draft {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/site-agent-create-draft';

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
				'label'               => __( 'Create a draft', 'ai' ),
				'description'         => __( 'Creates a draft post or page owned by the current user. The title is required; content and excerpt are optional. Nothing is published.', 'ai' ),
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
	 * Checks whether the current user may create the requested draft.
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

		$post_type  = is_array( $input ) && isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';
		$capability = 'page' === $post_type ? 'edit_pages' : 'edit_posts';

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Fixed post type to capability map.
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'site_agent_create_denied',
				__( 'You do not have permission to create that content.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Creates the draft.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array<string, mixed>|\WP_Error The created draft, or an error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';
		$title     = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';

		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error(
				'site_agent_invalid_post_type',
				__( 'Only posts and pages can be created.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $title ) {
			return new WP_Error(
				'site_agent_missing_title',
				__( 'A title is required.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$postarr = array(
			'post_type'   => $post_type,
			'post_status' => 'draft',
			'post_title'  => $title,
			'post_author' => get_current_user_id(),
		);

		if ( isset( $input['content'] ) && is_scalar( $input['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( (string) $input['content'] );
		}

		if ( isset( $input['excerpt'] ) && is_scalar( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
		}

		$id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array(
			'id'     => (int) $id,
			'status' => 'draft',
			'link'   => (string) get_permalink( $id ),
			'title'  => $title,
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
			'required'             => array( 'title' ),
			'additionalProperties' => false,
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'enum'        => array( 'post', 'page' ),
					'default'     => 'post',
					'description' => __( 'Whether to create a post or a page.', 'ai' ),
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'The draft title.', 'ai' ),
				),
				'content'   => array(
					'type'        => 'string',
					'description' => __( 'Optional draft content.', 'ai' ),
				),
				'excerpt'   => array(
					'type'        => 'string',
					'description' => __( 'Optional draft excerpt.', 'ai' ),
				),
			),
		);
	}
}
