<?php
/**
 * The content write abilities.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Error;
use WP_Post;
use WP_Post_Type;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Content_Write
 *
 * Registers `core/content-create`, `core/content-update` and `core/content-delete`, the
 * write counterparts to `core/content-query`.
 *
 * These abilities only have a REST-backed implementation: {@see Content_Write_Rest} calls
 * the posts endpoint of the post type, so the capability checks, sanitization, slug
 * handling, revisions and hooks are the ones the controller already performs. The
 * permission callbacks here are the abilities' own gate in front of that, and they answer
 * a narrower question than REST does: the post type has to be exposed to abilities.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Content_Write {

	/**
	 * The ability category the write abilities belong to.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Fields reported on a written post when the input names none.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private const DEFAULT_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'id',
		'post_type',
		'status',
		'date',
		'slug',
		'link',
		'title_rendered',
	);

	/**
	 * The read ability, held for the post type and field definitions both sides share.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Abilities\Content\Content
	 */
	private Content $content;

	/**
	 * The REST-backed implementation.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Abilities\Content\Content_Write_Rest
	 */
	private Content_Write_Rest $rest;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		$this->content = new Content();
		$this->rest    = new Content_Write_Rest();
	}

	/**
	 * Registers the write abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$post_types = array_keys( $this->content->get_exposed_post_types() );
		$statuses   = array_values( get_post_stati( array( 'internal' => false ) ) );
		$fields     = array_keys( $this->content->get_post_properties() );

		$this->register_ability(
			'core/content-create',
			__( 'Content Create', 'ai' ),
			__( 'Creates a post in a post type exposed to abilities. Returns the created post. Requires an authenticated user who can create posts in that post type.', 'ai' ),
			$this->get_create_input_schema( $post_types, $statuses, $fields ),
			$this->get_post_output_schema(),
			'execute_content_create',
			'check_create_permission',
			array(
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->register_ability(
			'core/content-update',
			__( 'Content Update', 'ai' ),
			__( 'Updates a post in a post type exposed to abilities. Only the fields named in the input are changed. Returns the updated post. Requires an authenticated user who can edit that post.', 'ai' ),
			$this->get_update_input_schema( $statuses, $fields ),
			$this->get_post_output_schema(),
			'execute_content_update',
			'check_update_permission',
			array(
				'destructive' => true,
				'idempotent'  => true,
			)
		);

		$this->register_ability(
			'core/content-delete',
			__( 'Content Delete', 'ai' ),
			__( 'Moves a post to the trash, or deletes it permanently when force is set. Returns whether the post was deleted along with the post itself. Requires an authenticated user who can delete that post.', 'ai' ),
			$this->get_delete_input_schema( $fields ),
			$this->get_delete_output_schema(),
			'execute_content_delete',
			'check_delete_permission',
			array(
				'destructive' => true,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Registers one write ability, replacing any copy already registered.
	 *
	 * @since x.x.x
	 *
	 * @param string               $name        The ability name.
	 * @param string               $label       The ability label.
	 * @param string               $description The ability description.
	 * @param array<string, mixed> $input       The input schema.
	 * @param array<string, mixed> $output      The output schema.
	 * @param string               $execute     The execute callback method name.
	 * @param string               $permission  The permission callback method name.
	 * @param array<string, bool>  $annotations The `destructive` and `idempotent` hints.
	 */
	private function register_ability( string $name, string $label, string $description, array $input, array $output, string $execute, string $permission, array $annotations ): void {
		if ( wp_has_ability( $name ) ) {
			wp_unregister_ability( $name );
		}

		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => array( $this, $execute ),
				'permission_callback' => array( $this, $permission ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => $annotations['destructive'],
						'idempotent'  => $annotations['idempotent'],
						// MCP clients assume open-world (may reach external systems) when
						// the hint is absent; these abilities only write the local database.
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the `core/content-create` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array<string, mixed>|\stdClass|\WP_Error The created post, or a WP_Error on failure.
	 */
	public function execute_content_create( $input = array() ) {
		$input     = $this->to_input_array( $input );
		$post_type = $this->input_post_type( $input );

		$exposed = $this->content->get_exposed_post_types();
		if ( '' === $post_type || ! isset( $exposed[ $post_type ] ) ) {
			return $this->not_found_error();
		}

		return $this->rest->create_post( $exposed[ $post_type ], $input, $this->normalize_fields( $input ) );
	}

	/**
	 * Executes the `core/content-update` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array<string, mixed>|\stdClass|\WP_Error The updated post, or a WP_Error on failure.
	 */
	public function execute_content_update( $input = array() ) {
		$input = $this->to_input_array( $input );
		$post  = $this->exposed_post( $input );

		if ( ! $post instanceof WP_Post ) {
			return $this->not_found_error();
		}

		return $this->rest->update_post( $post, $input, $this->normalize_fields( $input ) );
	}

	/**
	 * Executes the `core/content-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array{deleted: bool, post: array<string, mixed>|\stdClass}|\WP_Error The outcome, or a WP_Error on failure.
	 */
	public function execute_content_delete( $input = array() ) {
		$input = $this->to_input_array( $input );
		$post  = $this->exposed_post( $input );

		if ( ! $post instanceof WP_Post ) {
			return $this->not_found_error();
		}

		$force = ! empty( $input['force'] );

		return $this->rest->delete_post( $post, $force, $this->normalize_fields( $input ) );
	}

	/**
	 * Checks permission for `core/content-create`.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return bool True when the current user may create the post.
	 */
	public function check_create_permission( $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$input     = $this->to_input_array( $input );
		$post_type = $this->input_post_type( $input );
		$exposed   = $this->content->get_exposed_post_types();

		if ( '' === $post_type || ! isset( $exposed[ $post_type ] ) ) {
			return false;
		}

		return current_user_can( $this->post_type_cap( $exposed[ $post_type ], 'create_posts' ) ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
	}

	/**
	 * Checks permission for `core/content-update`.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return bool True when the current user may edit the post.
	 */
	public function check_update_permission( $input = array() ): bool {
		$post = $this->permission_target( $input );

		return $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Checks permission for `core/content-delete`.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return bool True when the current user may delete the post.
	 */
	public function check_delete_permission( $input = array() ): bool {
		$post = $this->permission_target( $input );

		return $post instanceof WP_Post && current_user_can( 'delete_post', $post->ID );
	}

	/**
	 * Resolves the post a single-post permission check applies to.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return \WP_Post|null The post, or null when it does not exist or is not exposed.
	 */
	private function permission_target( $input ): ?WP_Post {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		return $this->exposed_post( $this->to_input_array( $input ) );
	}

	/**
	 * Resolves the input `id` to a post of an exposed post type.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return \WP_Post|null The post, or null when it does not exist or is not exposed.
	 */
	private function exposed_post( array $input ): ?WP_Post {
		$id = isset( $input['id'] ) && is_scalar( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( 0 === $id ) {
			return null;
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$exposed = $this->content->get_exposed_post_types();

		return isset( $exposed[ $post->post_type ] ) ? $post : null;
	}

	/**
	 * Returns the post type named by the input.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return string The post type name, or an empty string when none was given.
	 */
	private function input_post_type( array $input ): string {
		return isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? $input['post_type'] : '';
	}

	/**
	 * Normalizes the ability input to an array.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array<string, mixed> The input as an array.
	 */
	private function to_input_array( $input ): array {
		$input = rest_sanitize_object( $input );

		return is_array( $input ) ? $input : array();
	}

	/**
	 * Returns the fields to report on the written post.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return list<string> The requested field names.
	 */
	private function normalize_fields( array $input ): array {
		if ( ! isset( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return self::DEFAULT_FIELDS;
		}

		$fields = array_values( array_filter( $input['fields'], 'is_string' ) );

		return array() === $fields ? self::DEFAULT_FIELDS : $fields;
	}

	/**
	 * Resolves a capability from a post type's capability object.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param string        $capability       The capability key.
	 * @return string The capability name, or `do_not_allow` when it cannot be resolved.
	 */
	private function post_type_cap( WP_Post_Type $post_type_object, string $capability ): string {
		$cap = $post_type_object->cap->$capability ?? null;

		return is_string( $cap ) && '' !== $cap ? $cap : 'do_not_allow';
	}

	/**
	 * Returns the input schema properties shared by create and update.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $statuses The selectable post statuses.
	 * @param list<string> $fields   The selectable report fields.
	 * @return array<string, mixed> The shared input properties.
	 */
	private function get_writable_properties( array $statuses, array $fields ): array {
		return array(
			'title'          => array(
				'type'        => 'string',
				'description' => __( 'The post title.', 'ai' ),
			),
			'content'        => array(
				'type'        => 'string',
				'description' => __( 'The post content.', 'ai' ),
			),
			'excerpt'        => array(
				'type'        => 'string',
				'description' => __( 'The post excerpt.', 'ai' ),
			),
			'status'         => array(
				'type'        => 'string',
				'enum'        => $statuses,
				'description' => __( 'The post status. Publishing requires the capability to publish in the post type.', 'ai' ),
			),
			'slug'           => array(
				'type'        => 'string',
				'description' => __( 'The post slug. A conflicting slug is made unique.', 'ai' ),
			),
			'date'           => array(
				'type'        => 'string',
				'description' => __( 'The post date, in the site timezone.', 'ai' ),
			),
			'date_gmt'       => array(
				'type'        => 'string',
				'description' => __( 'The post date, as GMT.', 'ai' ),
			),
			'author'         => array(
				'type'        => 'integer',
				'description' => __( 'The ID of the post author. Assigning another user requires the capability to edit others posts.', 'ai' ),
			),
			'parent'         => array(
				'type'        => 'integer',
				'description' => __( 'The ID of the parent post, for hierarchical post types.', 'ai' ),
			),
			'password'       => array(
				'type'        => 'string',
				'description' => __( 'The password protecting the post.', 'ai' ),
			),
			'menu_order'     => array(
				'type'        => 'integer',
				'description' => __( 'The order the post should appear in.', 'ai' ),
			),
			'comment_status' => array(
				'type'        => 'string',
				'enum'        => array( 'open', 'closed' ),
				'description' => __( 'Whether comments are open on the post.', 'ai' ),
			),
			'ping_status'    => array(
				'type'        => 'string',
				'enum'        => array( 'open', 'closed' ),
				'description' => __( 'Whether the post can be pinged.', 'ai' ),
			),
			'sticky'         => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the post is sticky. Requires the capability to publish or to edit others posts.', 'ai' ),
			),
			'fields'         => $this->get_fields_property( $fields ),
		);
	}

	/**
	 * Returns the `fields` input property.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $fields The selectable report fields.
	 * @return array<string, mixed> The property definition.
	 */
	private function get_fields_property( array $fields ): array {
		return array(
			'type'        => 'array',
			'items'       => array(
				'type' => 'string',
				'enum' => $fields,
			),
			'description' => __( 'The fields to report on the written post. Defaults to a summary of the post.', 'ai' ),
		);
	}

	/**
	 * Returns the `core/content-create` input schema.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $post_types The post types exposed to abilities.
	 * @param list<string> $statuses   The selectable post statuses.
	 * @param list<string> $fields     The selectable report fields.
	 * @return array<string, mixed> The input schema.
	 */
	private function get_create_input_schema( array $post_types, array $statuses, array $fields ): array {
		$properties = array(
			'post_type' => array(
				'type'        => 'string',
				'enum'        => $post_types,
				'description' => __( 'The post type to create the post in.', 'ai' ),
			),
		) + $this->get_writable_properties( $statuses, $fields );

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'post_type' ),
			'properties'           => $properties,
		);
	}

	/**
	 * Returns the `core/content-update` input schema.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $statuses The selectable post statuses.
	 * @param list<string> $fields   The selectable report fields.
	 * @return array<string, mixed> The input schema.
	 */
	private function get_update_input_schema( array $statuses, array $fields ): array {
		$properties = array(
			'id' => array(
				'type'        => 'integer',
				'description' => __( 'The ID of the post to update.', 'ai' ),
			),
		) + $this->get_writable_properties( $statuses, $fields );

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => $properties,
		);
	}

	/**
	 * Returns the `core/content-delete` input schema.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $fields The selectable report fields.
	 * @return array<string, mixed> The input schema.
	 */
	private function get_delete_input_schema( array $fields ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array(
				'id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post to delete.', 'ai' ),
				),
				'force'  => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Whether to delete the post permanently instead of moving it to the trash.', 'ai' ),
				),
				'fields' => $this->get_fields_property( $fields ),
			),
		);
	}

	/**
	 * Returns the output schema for an ability that reports one post.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema.
	 */
	private function get_post_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->content->get_post_properties(),
		);
	}

	/**
	 * Returns the `core/content-delete` output schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema.
	 */
	private function get_delete_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'deleted', 'post' ),
			'properties'           => array(
				'deleted' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the post was deleted permanently. False when it was moved to the trash.', 'ai' ),
				),
				'post'    => $this->get_post_output_schema(),
			),
		);
	}

	/**
	 * Builds the uniform not-found error.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The not-found error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'content_not_found',
			__( 'The requested content was not found.', 'ai' ),
			array( 'status' => 404 )
		);
	}
}
