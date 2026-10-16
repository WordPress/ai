<?php
/**
 * The REST post types controller tests, ported to the core/post-types-query ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Post_Types
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Post_Types;

use WP_REST_Request;
use WordPress\AI\Abilities\Post_Types\Post_Types;

/**
 * Post types controller test case for the core/post-types-query ability.
 *
 * Each test keeps the name of the WP_Test_REST_Post_Types_Controller test it ports.
 *
 * @since x.x.x
 */
class Post_Types_ControllerTest extends Post_Types_Ability_TestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// REST serves post types to anyone; the ability needs a logged-in user, so use the lowest role.
		$this->login_as( 'subscriber' );
		$this->register_ability();
	}

	/**
	 * Every exposed post type is listed with its view fields.
	 *
	 * @since x.x.x
	 */
	public function test_get_items(): void {
		$result = $this->query_post_types( array() );
		$this->assertIsArray( $result, 'Listing the post types should succeed.' );

		$data       = array_column( $result['post_types'], null, 'slug' );
		$post_types = get_post_types( array( 'show_in_abilities' => true ), 'objects' );
		$this->assertCount( count( $post_types ), $data, 'Every exposed post type should be listed.' );
		$this->assertSame( $post_types['post']->name, $data['post']['slug'], 'The post slug should match.' );
		$this->check_post_type_obj( 'view', $post_types['post'], $data['post'] );
		$this->assertSame( $post_types['page']->name, $data['page']['slug'], 'The page slug should match.' );
		$this->check_post_type_obj( 'view', $post_types['page'], $data['page'] );
		$this->assertArrayNotHasKey( 'revision', $data, 'Revisions should not be listed.' );
	}

	/**
	 * A logged-out visitor cannot list the post types in the edit context.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_invalid_permission_for_context(): void {
		wp_set_current_user( 0 );
		$input = array( 'fields' => array( 'labels' ) );

		$this->assertAbilityDenied( $this->query_post_types( $input ), 'A logged-out visitor should be denied.' );

		$result = ( new Post_Types() )->execute_post_types_query( $input );
		$this->assertAbilityError( $result, 'post_types_cannot_view', 'A direct call should report the edit context as forbidden.' );
		$this->assertSame( 401, $result->get_error_data()['status'], 'A logged-out visitor should be unauthorized.' );
	}

	/**
	 * The post post type is returned with its taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_get_item(): void {
		$data = $this->query_post_types( array( 'slug' => 'post' ) );
		$this->check_post_type_obj( 'view', get_post_type_object( 'post' ), $data );
		$this->assertSame( array( 'category', 'post_tag' ), $data['taxonomies'], 'Posts should list the category and tag taxonomies.' );
	}

	/**
	 * An exposed custom post type is returned.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_cpt(): void {
		$this->register_test_post_type(
			'cpt',
			array(
				'show_in_rest'      => true,
				'rest_base'         => 'cpt',
				'rest_namespace'    => 'wordpress/v1',
				'show_in_abilities' => true,
			)
		);
		$this->register_ability();

		$data = $this->query_post_types( array( 'slug' => 'cpt' ) );
		$this->check_post_type_obj( 'view', get_post_type_object( 'cpt' ), $data );
	}

	/**
	 * The block template and its lock are returned.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_template_cpt(): void {
		$this->register_test_post_type(
			'cpt_template',
			array(
				'show_in_rest'      => true,
				'rest_base'         => 'cpt_template',
				'rest_namespace'    => 'wordpress/v1',
				'template'          => array(
					array( 'core/paragraph', array( 'placeholder' => 'Content' ) ),
				),
				'template_lock'     => 'all',
				'show_in_abilities' => true,
			)
		);
		$this->register_ability();

		$data = $this->query_post_types( array( 'slug' => 'cpt_template' ) );
		$this->check_post_type_obj( 'view', get_post_type_object( 'cpt_template' ), $data );
	}

	/**
	 * The page post type is returned without taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_page(): void {
		$data = $this->query_post_types( array( 'slug' => 'page' ) );
		$this->check_post_type_obj( 'view', get_post_type_object( 'page' ), $data );
		$this->assertSame( array(), $data['taxonomies'], 'Pages should list no taxonomies.' );
	}

	/**
	 * An unknown post type fails validation, and a direct call reports it as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_invalid_type(): void {
		$input = array( 'slug' => 'invalid' );

		$this->assertAbilityError( $this->query_post_types( $input ), 'ability_invalid_input', 'An unknown post type should fail validation.' );

		$result = ( new Post_Types() )->execute_post_types_query( $input );
		$this->assertAbilityError( $result, 'post_types_type_invalid', 'A direct call should report the post type as invalid.' );
		$this->assertSame( 404, $result->get_error_data()['status'], 'An unknown post type should not be found.' );
	}

	/**
	 * An editor reads the post post type in the edit context.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_edit_context(): void {
		$this->login_as( 'editor' );

		$data = $this->query_post_types(
			array(
				'slug'   => 'post',
				'fields' => self::ALL_FIELDS,
			)
		);
		$this->check_post_type_obj( 'edit', get_post_type_object( 'post' ), $data );
	}

	/**
	 * A logged-out visitor cannot read a post type in the edit context.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_invalid_permission_for_context(): void {
		wp_set_current_user( 0 );
		$input = array(
			'slug'   => 'post',
			'fields' => array( 'labels' ),
		);

		$this->assertAbilityDenied( $this->query_post_types( $input ), 'A logged-out visitor should be denied.' );

		$result = ( new Post_Types() )->execute_post_types_query( $input );
		$this->assertAbilityError( $result, 'post_types_forbidden_context', 'A direct call should report the edit context as forbidden.' );
		$this->assertSame( 401, $result->get_error_data()['status'], 'A logged-out visitor should be unauthorized.' );
	}

	/**
	 * Post types cannot be created: the run endpoint rejects POST.
	 *
	 * @since x.x.x
	 */
	public function test_create_item(): void {
		$this->assert_run_method_not_allowed( 'POST' );
	}

	/**
	 * Post types cannot be updated: the run endpoint rejects PUT.
	 *
	 * @since x.x.x
	 */
	public function test_update_item(): void {
		$this->assert_run_method_not_allowed( 'PUT' );
	}

	/**
	 * Post types cannot be deleted: the run endpoint rejects DELETE.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item(): void {
		$this->assert_run_method_not_allowed( 'DELETE' );
	}

	/**
	 * Every field is prepared in the edit context.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_item(): void {
		$this->login_as( 'administrator' );

		$result = $this->query_post_types( array( 'fields' => self::ALL_FIELDS ) );
		$this->assertIsArray( $result, 'Listing the post types in the edit context should succeed.' );

		$data = array_column( $result['post_types'], null, 'slug' );
		$this->check_post_type_obj( 'edit', get_post_type_object( 'post' ), $data['post'] );
	}

	/**
	 * Only the requested fields are prepared, and `id` is not a post type field.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_item_limit_fields(): void {
		$this->login_as( 'editor' );

		$data = $this->query_post_types(
			array(
				'slug'   => 'post',
				'fields' => array( 'name' ),
			)
		);
		$this->assertSame( array( 'name' ), array_keys( $data ), 'Only the name should be returned.' );

		$result = $this->query_post_types(
			array(
				'slug'   => 'post',
				'fields' => array( 'id', 'name' ),
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'The id field should not exist.' );
	}

	/**
	 * The post type schema has every field except the REST routing fields.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_schema(): void {
		$properties = wp_get_ability( 'core/post-types-query' )->get_output_schema()['oneOf'][0]['properties'];

		$this->assertCount( 14, $properties, 'Schema should have 14 properties' );
		$this->assertArrayHasKey( 'capabilities', $properties, '`capabilities` should be included in the schema' );
		$this->assertArrayHasKey( 'description', $properties, '`description` should be included in the schema' );
		$this->assertArrayHasKey( 'hierarchical', $properties, '`hierarchical` should be included in the schema' );
		$this->assertArrayHasKey( 'viewable', $properties, '`viewable` should be included in the schema' );
		$this->assertArrayHasKey( 'labels', $properties, '`labels` should be included in the schema' );
		$this->assertArrayHasKey( 'name', $properties, '`name` should be included in the schema' );
		$this->assertArrayHasKey( 'slug', $properties, '`slug` should be included in the schema' );
		$this->assertArrayHasKey( 'supports', $properties, '`supports` should be included in the schema' );
		$this->assertArrayHasKey( 'has_archive', $properties, '`has_archive` should be included in the schema' );
		$this->assertArrayHasKey( 'taxonomies', $properties, '`taxonomies` should be included in the schema' );
		$this->assertArrayNotHasKey( 'rest_base', $properties, '`rest_base` should not be included in the schema' );
		$this->assertArrayNotHasKey( 'rest_namespace', $properties, '`rest_namespace` should not be included in the schema' );
		$this->assertArrayHasKey( 'visibility', $properties, '`visibility` should be included in the schema' );
		$this->assertArrayHasKey( 'icon', $properties, '`icon` should be included in the schema' );
		$this->assertArrayHasKey( 'template', $properties, '`template` should be included in the schema' );
		$this->assertArrayHasKey( 'template_lock', $properties, '`template_lock` should be included in the schema' );
	}

	/**
	 * Asserts that the run endpoint rejects a method other than GET.
	 *
	 * @since x.x.x
	 *
	 * @param string $method The HTTP method.
	 */
	private function assert_run_method_not_allowed( string $method ): void {
		$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/core/post-types-query/run' );
		$request->set_body_params( array( 'input' => array( 'slug' => 'post' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 405, $response->get_status(), "A {$method} request should be rejected." );
		$this->assertSame( 'rest_ability_invalid_method', $response->as_error()->get_error_code(), 'A read-only ability should require GET.' );
	}
}
