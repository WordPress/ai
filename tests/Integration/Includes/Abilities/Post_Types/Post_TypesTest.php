<?php
/**
 * Integration tests for the core/post-types-query Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Post_Types
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Post_Types;

use WP_REST_Post_Types_Controller;
use WP_REST_Request;
use WordPress\AI\Abilities\Post_Types\Post_Types;

/**
 * Post types ability test case.
 *
 * Covers what the ability adds to the REST post types endpoints: its modes, fields,
 * exposure, permissions, and string inputs, plus parity checks against the REST responses.
 *
 * @since x.x.x
 */
class Post_TypesTest extends Post_Types_Ability_TestCase {

	/**
	 * The ability is registered in the `content` category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_post_types_query_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/post-types-query' );

		$this->assertNotNull( $ability, 'The core/post-types-query ability should be registered.' );
		$this->assertSame( 'Post Types Query', $ability->get_label(), 'The registered ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The ability should be marked non-destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The ability should be marked idempotent.' );
		$this->assertFalse( $annotations['open_world'], 'The ability should be marked closed-world; it only reads the local database.' );
	}

	/**
	 * The ability is not registered when no post types are exposed to it.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_core_post_types_query_ability_without_exposed_post_types(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			get_post_type_object( $post_type )->show_in_abilities = false;
		}

		$this->register_ability();

		$this->assertFalse( wp_has_ability( 'core/post-types-query' ), 'The ability should not register without any exposed post types.' );
	}

	/**
	 * When core already provides core/post-types-query, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_post_types_query(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/post-types-query',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided post types ability.',
					'category'            => 'content',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'Post Types Query', wp_get_ability( 'core/post-types-query' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * The input schema models mutually exclusive slug and list modes, each rejecting
	 * unrelated properties, and lists the post types when the input is omitted.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_models_mutually_exclusive_modes(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/post-types-query' );
		$schema  = $ability->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertEquals( (object) array(), $schema['default'], 'Omitting the input should list the post types.' );
		$this->assertCount( 2, $schema['oneOf'], 'The input schema should expose exactly two modes.' );

		[ $by_slug, $list ] = $schema['oneOf'];

		$this->assertSame( array( 'slug' ), $by_slug['required'], 'The slug mode should require a slug.' );
		$this->assertArrayNotHasKey( 'required', $list, 'The list mode should require nothing.' );
		$this->assertSame( array( 'slug', 'fields' ), array_keys( $by_slug['properties'] ), 'The slug mode should take a slug and fields.' );
		$this->assertSame( array( 'fields' ), array_keys( $list['properties'] ), 'The list mode should take only fields.' );
		$this->assertSame( array( 'post', 'page' ), $by_slug['properties']['slug']['enum'], 'The slug mode should take only the exposed post types.' );

		foreach ( $schema['oneOf'] as $mode ) {
			$this->assertFalse( $mode['additionalProperties'], "The {$mode['title']} mode should reject unrelated properties." );
			$this->assertSame(
				array_keys( $ability->get_output_schema()['oneOf'][0]['properties'] ),
				$mode['properties']['fields']['items']['enum'],
				"The {$mode['title']} mode should take the fields of the post type schema."
			);
		}
	}

	/**
	 * Branch-local defaults are omitted so the schema can compile in the client-side
	 * Abilities API validator. The ability applies the defaults itself.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_omits_oneof_branch_defaults(): void {
		$this->register_ability();

		foreach ( wp_get_ability( 'core/post-types-query' )->get_input_schema()['oneOf'] as $mode ) {
			foreach ( $mode['properties'] as $name => $property ) {
				$this->assertArrayNotHasKey( 'default', $property, "The {$name} property of the {$mode['title']} mode should rely on runtime defaults." );
			}
		}
	}

	/**
	 * The output schema describes single post type and list responses that cannot match
	 * each other.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_describes_single_post_type_and_list_responses(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/post-types-query' )->get_output_schema();

		$this->assertSame( 'object', $schema['type'], 'The output schema should describe object responses.' );
		$this->assertCount( 2, $schema['oneOf'], 'The output schema should describe single post type and list responses.' );

		[ $post_type, $list ] = $schema['oneOf'];

		$this->assertArrayNotHasKey( 'required', $post_type, 'Individual post type fields should remain optional.' );
		$this->assertFalse( $post_type['additionalProperties'], 'Returned post types should not allow unknown properties.' );
		$this->assertSame( array( 'post_types' ), $list['required'], 'The list wrapper should require the post types.' );
		$this->assertFalse( $list['additionalProperties'], 'The list wrapper should not allow unknown properties.' );
		$this->assertSame( $post_type, $list['properties']['post_types']['items'], 'The list wrapper should list post types.' );

		foreach ( $post_type['properties'] as $name => $property ) {
			$this->assertArrayNotHasKey( 'format', $property, "The {$name} field should have no format, which the client-side validator may not know." );
		}
	}

	/**
	 * Without input, the exposed post types are listed.
	 *
	 * @since x.x.x
	 */
	public function test_lists_the_exposed_post_types_without_input(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_post_types( null );

		$this->assertIsArray( $result, 'Listing the post types without input should succeed.' );
		$this->assertSame( array( 'post', 'page' ), wp_list_pluck( $result['post_types'], 'slug' ), 'The exposed post types should be listed in registration order.' );
	}

	/**
	 * Without requested fields, every field of the view context is returned.
	 *
	 * @since x.x.x
	 */
	public function test_default_fields(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$expected = array( 'description', 'hierarchical', 'has_archive', 'name', 'slug', 'icon', 'taxonomies', 'template', 'template_lock' );

		$this->assertSame( $expected, array_keys( $this->query_post_types( array( 'slug' => 'post' ) ) ), 'The view fields should be returned by default.' );
		$this->assertSame(
			$expected,
			array_keys(
				$this->query_post_types(
					array(
						'slug'   => 'post',
						'fields' => array(),
					)
				)
			),
			'An empty field list should return the default fields.'
		);
	}

	/**
	 * Requested fields are returned in the response order, not the requested order.
	 *
	 * @since x.x.x
	 */
	public function test_fields_keep_the_response_order(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_post_types(
			array(
				'slug'   => 'post',
				'fields' => array( 'slug', 'name' ),
			)
		);

		$this->assertSame(
			array(
				'name' => 'Posts',
				'slug' => 'post',
			),
			$result,
			'The requested fields should be returned in the response order.'
		);
	}

	/**
	 * Unknown fields fail validation.
	 *
	 * @since x.x.x
	 */
	public function test_fields_are_validated(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		foreach ( array( 'rest_base', 'rest_namespace', '_links', 'labels.name', 'unknown' ) as $field ) {
			$result = $this->query_post_types( array( 'fields' => array( 'name', $field ) ) );
			$this->assertAbilityError( $result, 'ability_invalid_input', "The {$field} field should fail validation." );
		}
	}

	/**
	 * Each mode rejects the parameters it does not take.
	 *
	 * @since x.x.x
	 */
	public function test_modes_reject_unknown_params(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$inputs = array(
			'slug with a page size' => array(
				'slug'     => 'post',
				'per_page' => 10,
			),
			'list with a context'   => array( 'context' => 'edit' ),
			'list with a type'      => array( 'type' => 'post' ),
		);

		foreach ( $inputs as $name => $input ) {
			$this->assertAbilityError( $this->query_post_types( $input ), 'ability_invalid_input', "The {$name} input should fail validation." );
		}
	}

	/**
	 * String inputs, as a GET request delivers them on WordPress 7.0, give the same results
	 * as typed inputs.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_match_typed_inputs(): void {
		$this->login_as( 'author' );
		$this->register_ability();

		$cases = array(
			'fields'           => array( array( 'fields' => 'slug,name' ), array( 'fields' => array( 'slug', 'name' ) ) ),
			'edit-only fields' => array( array( 'fields' => 'slug,labels' ), array( 'fields' => array( 'slug', 'labels' ) ) ),
			'slug and fields'  => array(
				array(
					'slug'   => 'post',
					'fields' => 'name,supports',
				),
				array(
					'slug'   => 'post',
					'fields' => array( 'name', 'supports' ),
				),
			),
			'empty input'      => array( '', array() ),
			'empty fields'     => array( array( 'fields' => '' ), array() ),
		);

		foreach ( $cases as $name => [ $string_input, $typed_input ] ) {
			$expected = $this->query_post_types( $typed_input );
			$this->assertIsArray( $expected, "The typed {$name} input should succeed." );
			$this->assertSame( $expected, $this->query_post_types( $string_input ), "The string {$name} input should give the same result as the typed input." );
		}

		// The string fields take effect, rather than matching the default fields.
		$this->assertSame( array( 'post' ), wp_list_pluck( $this->query_post_types( $cases['edit-only fields'][0] )['post_types'], 'slug' ), 'The string edit-only field should list only the post types the user can edit.' );
		$this->assertSame( array( 'name', 'supports' ), array_keys( $this->query_post_types( $cases['slug and fields'][0] ) ), 'The string fields should limit the response.' );
	}

	/**
	 * Returns the roles from administrator to a logged-out visitor, with whether they may
	 * read post types and which post types they can read in the edit context.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: bool, 2: list<string>}> The role, or an empty string for a logged-out visitor, whether it may read post types, and the post types it can edit.
	 */
	public function data_roles(): array {
		return array(
			'administrator' => array( 'administrator', true, array( 'post', 'page' ) ),
			'editor'        => array( 'editor', true, array( 'post', 'page' ) ),
			'author'        => array( 'author', true, array( 'post' ) ),
			'contributor'   => array( 'contributor', true, array( 'post' ) ),
			'subscriber'    => array( 'subscriber', true, array() ),
			'logged out'    => array( '', false, array() ),
		);
	}

	/**
	 * Every logged-in role reads the view fields of every exposed post type, and the
	 * edit-only fields of the post types it can edit. A logged-out visitor reads nothing.
	 *
	 * @dataProvider data_roles
	 *
	 * @since x.x.x
	 *
	 * @param string       $role     The role, or an empty string for a logged-out visitor.
	 * @param bool         $allowed  Whether the role may read post types.
	 * @param list<string> $editable The post types the role can edit.
	 */
	public function test_roles_reading_post_types( string $role, bool $allowed, array $editable ): void {
		if ( '' !== $role ) {
			$this->login_as( $role );
		}
		$this->register_ability();

		$list = $this->query_post_types( array() );
		if ( $allowed ) {
			$this->assertSame( array( 'post', 'page' ), wp_list_pluck( $list['post_types'], 'slug' ), 'The role should list every exposed post type.' );
		} else {
			$this->assertAbilityDenied( $list, 'A logged-out visitor should not list the post types.' );
		}

		$edit_list = $this->query_post_types( array( 'fields' => array( 'slug', 'labels' ) ) );
		if ( array() === $editable ) {
			$this->assertAbilityDenied( $edit_list, 'The role should not list post types with edit-only fields.' );
		} else {
			$this->assertSame( $editable, wp_list_pluck( $edit_list['post_types'], 'slug' ), 'Edit-only fields should list only the post types the role can edit.' );
		}

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$single = $this->query_post_types( array( 'slug' => $post_type ) );
			if ( $allowed ) {
				$this->assertSame( $post_type, $single['slug'], "The role should read the {$post_type} post type." );
			} else {
				$this->assertAbilityDenied( $single, "A logged-out visitor should not read the {$post_type} post type." );
			}

			$single_edit = $this->query_post_types(
				array(
					'slug'   => $post_type,
					'fields' => array( 'labels' ),
				)
			);
			if ( in_array( $post_type, $editable, true ) ) {
				$this->assertSame( get_post_type_object( $post_type )->labels, $single_edit['labels'], "The role should read the labels of the {$post_type} post type." );
			} else {
				$this->assertAbilityDenied( $single_edit, "The role should not read the labels of the {$post_type} post type." );
			}
		}
	}

	/**
	 * Each edit-only field is a field REST serves only in the edit context.
	 *
	 * @since x.x.x
	 */
	public function test_edit_only_fields_are_the_rest_edit_context_fields(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$rest_fields = ( new WP_REST_Post_Types_Controller() )->get_item_schema()['properties'];

		foreach ( self::ALL_FIELDS as $field ) {
			$result = $this->query_post_types(
				array(
					'slug'   => 'post',
					'fields' => array( $field ),
				)
			);

			if ( in_array( 'view', $rest_fields[ $field ]['context'], true ) ) {
				$this->assertSame( array( $field ), array_keys( $result ), "The {$field} field should be readable without edit access." );
			} else {
				$this->assertAbilityDenied( $result, "The {$field} field should require edit access." );
			}
		}
	}

	/**
	 * Direct calls report the edit-only denials with specific errors.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_reports_edit_context_errors(): void {
		$post_types = new Post_Types();

		$this->login_as( 'subscriber' );
		$list = $post_types->execute_post_types_query( array( 'fields' => array( 'labels' ) ) );
		$this->assertAbilityError( $list, 'post_types_cannot_view', 'A user who can edit no exposed post type should be forbidden the edit context.' );
		$this->assertSame( 403, $list->get_error_data()['status'], 'The error should be forbidden.' );

		$this->login_as( 'author' );
		$single = $post_types->execute_post_types_query(
			array(
				'slug'   => 'page',
				'fields' => array( 'labels' ),
			)
		);
		$this->assertAbilityError( $single, 'post_types_forbidden_context', 'An author should be forbidden the edit context of pages.' );
		$this->assertSame( 403, $single->get_error_data()['status'], 'The error should be forbidden.' );
	}

	/**
	 * A field nested in an edit-only field needs permission to edit posts too.
	 *
	 * @since x.x.x
	 */
	public function test_nested_edit_only_fields_need_edit_access(): void {
		$post_types = new Post_Types();
		$this->login_as( 'author' );

		$single = array(
			'slug'   => 'page',
			'fields' => array( 'labels.name' ),
		);
		$this->assertFalse( $post_types->check_permission( $single ), 'An author should be denied a field nested in the labels of pages.' );
		$this->assertAbilityError( $post_types->execute_post_types_query( $single ), 'post_types_forbidden_context', 'A direct call should be forbidden the edit context.' );

		$list = $post_types->execute_post_types_query( array( 'fields' => 'slug,capabilities.edit_posts' ) );
		$this->assertSame( array( 'post' ), wp_list_pluck( $list['post_types'], 'slug' ), 'A nested capability should list only the post types an author can edit.' );
	}

	/**
	 * The edit context of a post type with its own capabilities needs those capabilities.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_with_its_own_capabilities(): void {
		$this->register_test_post_type(
			'wpai_book',
			array(
				'public'            => true,
				'capability_type'   => 'book',
				'map_meta_cap'      => true,
				'show_in_abilities' => true,
			)
		);
		$this->register_ability();

		$input = array(
			'slug'   => 'wpai_book',
			'fields' => array( 'slug', 'capabilities' ),
		);

		// Editing posts and pages does not grant the edit context of books.
		$this->login_as( 'administrator' );
		$this->assertSame( 'wpai_book', $this->query_post_types( array( 'slug' => 'wpai_book' ) )['slug'], 'Any logged-in user should read the view fields.' );
		$this->assertAbilityDenied( $this->query_post_types( $input ), 'An administrator without the book capabilities should not read them.' );
		$this->assertSame( array( 'post', 'page' ), wp_list_pluck( $this->query_post_types( array( 'fields' => array( 'slug', 'labels' ) ) )['post_types'], 'slug' ), 'Books should be left out of the edit context.' );

		// A subscriber who can edit books reads them, and only them, in the edit context.
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'edit_books' );
		wp_set_current_user( $user->ID );

		$this->assertSame(
			array(
				'capabilities' => get_post_type_object( 'wpai_book' )->cap,
				'slug'         => 'wpai_book',
			),
			$this->query_post_types( $input ),
			'The book capabilities should be returned.'
		);
		$this->assertSame( 'edit_books', $this->query_post_types( $input )['capabilities']->edit_posts, 'The capabilities should be the book capabilities.' );
		$this->assertSame( array( 'wpai_book' ), wp_list_pluck( $this->query_post_types( array( 'fields' => array( 'slug', 'labels' ) ) )['post_types'], 'slug' ), 'Only books should be listed in the edit context.' );
	}

	/**
	 * Attachments are not exposed, so they behave like a missing post type.
	 *
	 * @since x.x.x
	 */
	public function test_attachment_is_not_exposed(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$this->assert_post_type_is_not_exposed( 'attachment' );
	}

	/**
	 * A post type shown in REST is not exposed without the opt-in.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_shown_in_rest_is_not_exposed_without_opt_in(): void {
		$this->register_test_post_type(
			'wpai_rest_only',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		$this->login_as( 'administrator' );
		$this->register_ability();

		$this->assert_post_type_is_not_exposed( 'wpai_rest_only' );
	}

	/**
	 * A hidden post type is not exposed without the opt-in, and is listed once it opts in.
	 *
	 * @since x.x.x
	 */
	public function test_hidden_post_type_is_exposed_only_by_opt_in(): void {
		$this->register_test_post_type( 'wpai_hidden', array( 'public' => false ) );
		$this->register_test_post_type(
			'wpai_internal',
			array(
				'public'            => false,
				'show_in_abilities' => true,
			)
		);
		$this->login_as( 'administrator' );
		$this->register_ability();

		$this->assert_post_type_is_not_exposed( 'wpai_hidden' );

		$result = $this->query_post_types(
			array(
				'slug'   => 'wpai_internal',
				'fields' => array( 'slug', 'viewable', 'visibility' ),
			)
		);
		$this->assertSame(
			array(
				'visibility' => array(
					'show_in_nav_menus' => false,
					'show_ui'           => false,
				),
				'viewable'   => false,
				'slug'       => 'wpai_internal',
			),
			$result,
			'A hidden post type that opts in should be returned.'
		);
	}

	/**
	 * A post type that opts in is exposed even when it is not shown in REST.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_without_rest_is_exposed_by_opt_in(): void {
		$this->register_test_post_type(
			'wpai_no_rest',
			array(
				'public'            => true,
				'show_in_abilities' => true,
			)
		);
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertSame( array( 'post', 'page', 'wpai_no_rest' ), wp_list_pluck( $this->query_post_types( array() )['post_types'], 'slug' ), 'The post type should be listed.' );
		$this->assertSame( 'wpai_no_rest', $this->query_post_types( array( 'slug' => 'wpai_no_rest' ) )['slug'], 'The post type should be returned.' );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/types/wpai_no_rest' ) );
		$this->assertSame( 'rest_cannot_read_type', $response->as_error()->get_error_code(), 'REST should not serve the post type.' );
	}

	/**
	 * A hidden post type and a missing one cannot be told apart.
	 *
	 * @since x.x.x
	 */
	public function test_hidden_and_missing_post_types_are_denied_alike(): void {
		$this->register_test_post_type(
			'wpai_hidden',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		$this->login_as( 'administrator' );
		$this->register_ability();

		$hidden  = array( 'slug' => 'wpai_hidden' );
		$missing = array( 'slug' => 'wpai_missing' );

		$post_types = new Post_Types();
		$this->assertFalse( $post_types->check_permission( $hidden ), 'A hidden post type should be denied.' );
		$this->assertFalse( $post_types->check_permission( $missing ), 'A missing post type should be denied.' );
		$this->assertEquals( $post_types->execute_post_types_query( $missing ), $post_types->execute_post_types_query( $hidden ), 'A direct call should report a hidden post type like a missing one.' );

		$hidden_result  = $this->query_post_types( $hidden );
		$missing_result = $this->query_post_types( $missing );
		$this->assertAbilityError( $hidden_result, 'ability_invalid_input', 'A hidden post type should fail validation.' );
		$this->assertEquals( $missing_result, $hidden_result, 'A hidden post type should fail validation like a missing one.' );
	}

	/**
	 * A post type hidden after the ability registered is denied like one unregistered since.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_hidden_after_registration_is_denied_like_a_missing_one(): void {
		$args = array(
			'public'            => true,
			'show_in_abilities' => true,
		);
		$this->register_test_post_type( 'wpai_hidden_later', $args );
		$this->register_test_post_type( 'wpai_removed_later', $args );
		$this->login_as( 'administrator' );
		$this->register_ability();

		get_post_type_object( 'wpai_hidden_later' )->show_in_abilities = false;
		unregister_post_type( 'wpai_removed_later' );

		$hidden  = $this->query_post_types( array( 'slug' => 'wpai_hidden_later' ) );
		$missing = $this->query_post_types( array( 'slug' => 'wpai_removed_later' ) );

		$this->assertAbilityDenied( $hidden, 'A post type hidden since registration should be denied.' );
		$this->assertEquals( $missing, $hidden, 'A hidden post type should be denied like a missing one.' );
		$this->assertSame( array( 'post', 'page' ), wp_list_pluck( $this->query_post_types( array() )['post_types'], 'slug' ), 'The hidden post type should not be listed.' );
	}

	/**
	 * The taxonomies are those of the post type shown in REST.
	 *
	 * @since x.x.x
	 */
	public function test_taxonomies_are_the_taxonomies_shown_in_rest(): void {
		$this->register_test_post_type(
			'wpai_book',
			array(
				'public'            => true,
				'show_in_abilities' => true,
			)
		);
		$this->register_test_taxonomy( 'wpai_genre', 'wpai_book', array( 'show_in_rest' => true ) );
		$this->register_test_taxonomy(
			'wpai_shelf',
			'wpai_book',
			array(
				'show_in_rest'      => false,
				'show_in_abilities' => true,
			)
		);
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertSame( array( 'wpai_genre' ), $this->query_post_types( array( 'slug' => 'wpai_book' ) )['taxonomies'], 'Only the taxonomies shown in REST should be listed.' );
	}

	/**
	 * A post type without supported features returns them as an empty object.
	 *
	 * @since x.x.x
	 */
	public function test_supports_without_features_is_an_object(): void {
		$this->register_test_post_type(
			'wpai_bare',
			array(
				'public'            => true,
				'supports'          => false,
				'show_in_abilities' => true,
			)
		);
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->query_post_types(
			array(
				'slug'   => 'wpai_bare',
				'fields' => array( 'supports' ),
			)
		);

		$this->assertSame( array(), get_all_post_type_supports( 'wpai_bare' ), 'The post type should support no features.' );
		$this->assertSame( '{"supports":{}}', wp_json_encode( $result ), 'The supported features should be encoded as an object.' );
	}

	/**
	 * The permission callback requires a logged-in user.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_requires_a_logged_in_user(): void {
		$post_types = new Post_Types();

		$this->assertFalse( $post_types->check_permission( array() ), 'A logged-out visitor should not list the post types.' );
		$this->assertFalse( $post_types->check_permission( array( 'slug' => 'post' ) ), 'A logged-out visitor should not read a post type.' );

		$this->login_as( 'subscriber' );
		$this->assertTrue( $post_types->check_permission( array() ), 'A logged-in user should list the post types.' );
		$this->assertTrue( $post_types->check_permission( array( 'slug' => 'post' ) ), 'A logged-in user should read a post type.' );
	}

	/**
	 * The run endpoint takes the input from the query string of a GET request, and lists the
	 * post types without input.
	 *
	 * @since x.x.x
	 */
	public function test_run_endpoint_takes_query_string_input(): void {
		$this->login_as( 'author' );
		$this->register_ability();

		$route   = '/wp-abilities/v1/abilities/core/post-types-query/run';
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params(
			array(
				'input' => array(
					'slug'   => 'post',
					'fields' => 'name,supports',
				),
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'A GET request should run the ability.' );
		$this->assertSame(
			array(
				'name'     => 'Posts',
				'supports' => get_all_post_type_supports( 'post' ),
			),
			$response->get_data(),
			'The query string input should be applied.'
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );

		$this->assertSame( 200, $response->get_status(), 'A GET request without input should run the ability.' );
		$this->assertSame( array( 'post', 'page' ), wp_list_pluck( $response->get_data()['post_types'], 'slug' ), 'The post types should be listed without input.' );
	}

	/**
	 * Every post type field is a REST field of the same type.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_fields_are_rest_fields(): void {
		$this->register_ability();

		$rest_fields = ( new WP_REST_Post_Types_Controller() )->get_public_item_schema()['properties'];
		$fields      = wp_get_ability( 'core/post-types-query' )->get_output_schema()['oneOf'][0]['properties'];

		foreach ( $fields as $name => $schema ) {
			$this->assertArrayHasKey( $name, $rest_fields, "The {$name} field should be a REST field." );
			$this->assertSame( $rest_fields[ $name ]['type'], $schema['type'], "The {$name} field should have the REST type." );
		}
	}

	/**
	 * Returns the post types and contexts compared with REST.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: string}> The post type and the context.
	 */
	public function data_rest_comparisons(): array {
		return array(
			'post, view'   => array( 'post', 'view' ),
			'post, edit'   => array( 'post', 'edit' ),
			'page, view'   => array( 'page', 'view' ),
			'page, edit'   => array( 'page', 'edit' ),
			'custom, view' => array( 'wpai_book', 'view' ),
			'custom, edit' => array( 'wpai_book', 'edit' ),
		);
	}

	/**
	 * A post type has the values and order of the REST response, without the REST routing
	 * fields.
	 *
	 * @dataProvider data_rest_comparisons
	 *
	 * @since x.x.x
	 *
	 * @param string $post_type The post type.
	 * @param string $context   The REST context.
	 */
	public function test_post_type_matches_the_rest_response( string $post_type, string $context ): void {
		$this->register_rest_comparison_post_type();
		$this->login_as( 'editor' );
		$this->register_ability();

		$request = new WP_REST_Request( 'GET', "/wp/v2/types/{$post_type}" );
		$request->set_param( 'context', $context );
		$rest = rest_get_server()->dispatch( $request )->get_data();

		$input = array( 'slug' => $post_type );
		if ( 'edit' === $context ) {
			$input['fields'] = self::ALL_FIELDS;
		}

		$this->assertSame(
			array_diff_key(
				$rest,
				array(
					'rest_base'      => true,
					'rest_namespace' => true,
				)
			),
			$this->query_post_types( $input ),
			'The post type should match the REST response.'
		);
	}

	/**
	 * The list has the exposed post types of the REST response, in its order.
	 *
	 * @since x.x.x
	 */
	public function test_list_matches_the_rest_response(): void {
		$this->register_rest_comparison_post_type();
		$this->register_ability();

		foreach ( array( 'administrator', 'author' ) as $role ) {
			$this->login_as( $role );

			foreach ( array( 'view', 'edit' ) as $context ) {
				$request = new WP_REST_Request( 'GET', '/wp/v2/types' );
				$request->set_param( 'context', $context );
				$rest = array_intersect_key( rest_get_server()->dispatch( $request )->get_data(), get_post_types( array( 'show_in_abilities' => true ) ) );

				$expected = array();
				foreach ( $rest as $item ) {
					$expected[] = array_diff_key(
						$item,
						array(
							'rest_base'      => true,
							'rest_namespace' => true,
							'_links'         => true,
						)
					);
				}

				$result = $this->query_post_types( 'edit' === $context ? array( 'fields' => self::ALL_FIELDS ) : array() );
				$this->assertSame( $expected, $result['post_types'], "The {$context} list should match REST for the {$role}." );
			}
		}
	}

	/**
	 * Asserts that a post type is neither listed nor returned, like a missing one.
	 *
	 * @since x.x.x
	 *
	 * @param string $post_type The post type.
	 */
	private function assert_post_type_is_not_exposed( string $post_type ): void {
		$slugs = wp_list_pluck( $this->query_post_types( array() )['post_types'], 'slug' );
		$this->assertNotContains( $post_type, $slugs, "The {$post_type} post type should not be listed." );

		$slugs = wp_list_pluck( $this->query_post_types( array( 'fields' => array( 'slug', 'labels' ) ) )['post_types'], 'slug' );
		$this->assertNotContains( $post_type, $slugs, "The {$post_type} post type should not be listed in the edit context." );

		$this->assertAbilityError( $this->query_post_types( array( 'slug' => $post_type ) ), 'ability_invalid_input', "The {$post_type} post type should fail validation." );

		$result = ( new Post_Types() )->execute_post_types_query( array( 'slug' => $post_type ) );
		$this->assertAbilityError( $result, 'post_types_type_invalid', "A direct call should report the {$post_type} post type as invalid." );
		$this->assertEquals( ( new Post_Types() )->execute_post_types_query( array( 'slug' => 'wpai_missing' ) ), $result, "The {$post_type} post type should be reported like a missing one." );
	}

	/**
	 * Registers an exposed post type that sets every field REST reads.
	 *
	 * @since x.x.x
	 */
	private function register_rest_comparison_post_type(): void {
		$this->register_test_post_type(
			'wpai_book',
			array(
				'label'             => 'Books',
				'description'       => 'Books in the library.',
				'public'            => true,
				'hierarchical'      => true,
				'has_archive'       => 'books',
				'menu_icon'         => 'dashicons-book',
				'show_in_rest'      => true,
				'rest_base'         => 'books',
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor', 'page-attributes' ),
				'taxonomies'        => array( 'category' ),
				'template'          => array(
					array( 'core/heading', array( 'placeholder' => 'Title' ) ),
				),
				'template_lock'     => 'insert',
			)
		);
	}
}
