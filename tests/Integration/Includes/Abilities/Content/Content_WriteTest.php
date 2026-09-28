<?php
/**
 * Integration tests for the content write abilities provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WP_User;
use WordPress\AI\Abilities\Content\Content;
use WordPress\AI\Abilities\Content\Content_Write;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Content write ability test case.
 *
 * The abilities have no native implementation: every write goes through the posts
 * endpoint. These tests therefore assert both halves of that arrangement — the mapping
 * this plugin owns, and the controller behaviour it inherits and must not swallow.
 *
 * @since x.x.x
 */
class Content_WriteTest extends WP_UnitTestCase {

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates shared users for the write ability tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator'    => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'           => $factory->user->create( array( 'role' => 'editor' ) ),
			'subscriber'       => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'contributor'      => $factory->user->create( array( 'role' => 'contributor' ) ),
			'author'           => $factory->user->create( array( 'role' => 'author' ) ),
			'author_secondary' => $factory->user->create( array( 'role' => 'author' ) ),
		);
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core post types (post, page) as exposed to abilities.
		( new Show_In_Abilities() )->register();

		foreach ( array( 'content', 'site', 'user' ) as $category ) {
			$this->ensure_ability_category( $category );
		}
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * The three write abilities are registered alongside the read ability.
	 *
	 * @since x.x.x
	 */
	public function test_registers_the_write_abilities(): void {
		$this->register_abilities();

		foreach ( array( 'core/content-create', 'core/content-update', 'core/content-delete' ) as $name ) {
			$ability = wp_get_ability( $name );

			$this->assertNotNull( $ability, sprintf( '%s should be registered.', $name ) );
			$this->assertSame( 'content', $ability->get_category(), sprintf( '%s should be in the content category.', $name ) );
		}
	}

	/**
	 * The write abilities are annotated as writes, not reads.
	 *
	 * MCP clients decide whether to ask the user before running a tool from these hints,
	 * so a write that claims to be read-only would be run unattended. The run endpoint
	 * also picks the HTTP method from them, which is why an update is not idempotent.
	 *
	 * @since x.x.x
	 */
	public function test_annotates_the_write_abilities_as_writes(): void {
		$this->register_abilities();

		$expected = array(
			'core/content-create' => array(
				'destructive' => false,
				'idempotent'  => false,
			),
			'core/content-update' => array(
				'destructive' => true,
				'idempotent'  => false,
			),
			'core/content-delete' => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		);

		foreach ( $expected as $name => $hints ) {
			$meta        = wp_get_ability( $name )->get_meta();
			$annotations = $meta['annotations'];

			$this->assertFalse( $annotations['readonly'], sprintf( '%s should not be read-only.', $name ) );
			$this->assertFalse( $annotations['open_world'], sprintf( '%s should not be open-world.', $name ) );
			$this->assertSame( $hints['destructive'], $annotations['destructive'], sprintf( '%s destructive hint should match.', $name ) );
			$this->assertSame( $hints['idempotent'], $annotations['idempotent'], sprintf( '%s idempotent hint should match.', $name ) );
		}
	}

	/**
	 * The write abilities are not registered when no post type is exposed to abilities.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_without_exposed_post_types(): void {
		$names = array( 'core/content-create', 'core/content-update', 'core/content-delete' );

		foreach ( $names as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				continue;
			}

			wp_unregister_ability( $name );
		}

		$exposed = ( new Content() )->get_exposed_post_types();
		$flags   = array();
		foreach ( $exposed as $name => $post_type_object ) {
			$flags[ $name ]                      = $post_type_object->show_in_abilities;
			$post_type_object->show_in_abilities = false;
		}

		try {
			$this->register_abilities();

			foreach ( $names as $name ) {
				$this->assertFalse( wp_has_ability( $name ), sprintf( '%s should not be registered when no post type is exposed.', $name ) );
			}
		} finally {
			foreach ( $exposed as $name => $post_type_object ) {
				$post_type_object->show_in_abilities = $flags[ $name ];
			}
		}
	}

	/**
	 * Returns input the schema must refuse, keyed by the reason.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}> The ability name and its input.
	 */
	public function data_input_the_schema_refuses(): array {
		return array(
			'an unknown format on create' => array(
				'core/content-create',
				array(
					'post_type' => 'post',
					'title'     => 'Unknown format',
					'format'    => 'not-a-format',
				),
			),
		);
	}

	/**
	 * Input the schema describes as invalid is refused before anything is written.
	 *
	 * @dataProvider data_input_the_schema_refuses
	 *
	 * @since x.x.x
	 *
	 * @param string               $ability The ability name.
	 * @param array<string, mixed> $input   The ability input.
	 */
	public function test_refuses_input_the_schema_does_not_allow( string $ability, array $input ): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( $ability )->execute( $input );

		$this->assertWPError( $result, 'Input the schema does not allow should be refused.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'The refusal should come from the input schema.' );
	}

	/**
	 * Returns the single-post abilities, with the ID sent as an integer or a string.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: bool}> The ability name and whether the ID is a string.
	 */
	public function data_single_post_abilities(): array {
		return array(
			'update, integer ID' => array( 'core/content-update', false ),
			'update, string ID'  => array( 'core/content-update', true ),
			'delete, integer ID' => array( 'core/content-delete', false ),
			'delete, string ID'  => array( 'core/content-delete', true ),
		);
	}

	/**
	 * A negative ID names no post, not the post with the same absolute ID.
	 *
	 * @dataProvider data_single_post_abilities
	 *
	 * @since x.x.x
	 *
	 * @param string $ability   The ability name.
	 * @param bool   $as_string Whether to send the ID as a string, as the query string does.
	 */
	public function test_refuses_a_negative_id( string $ability, bool $as_string ): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Left alone',
				'post_status' => 'publish',
			)
		);

		$input = array( 'id' => $as_string ? '-' . $post_id : -$post_id );
		if ( 'core/content-update' === $ability ) {
			$input['title'] = 'Should not be written';
		}

		$result = wp_get_ability( $ability )->execute( $input );

		$this->assertWPError( $result, 'A negative ID should be refused.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'A negative ID should fail the input schema.' );

		$permission = 'core/content-update' === $ability ? 'check_update_permission' : 'check_delete_permission';
		$this->assertFalse( ( new Content_Write() )->$permission( $input ), 'The permission check should not resolve a negative ID to a post either.' );

		$this->assertSame( 'Left alone', get_post( $post_id )->post_title, 'The post with the absolute ID should not be updated.' );
		$this->assertSame( 'publish', get_post_status( $post_id ), 'The post with the absolute ID should not be deleted.' );
	}

	/**
	 * Creating a post writes it and reports it.
	 *
	 * @since x.x.x
	 */
	public function test_create_writes_the_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Written by an ability',
				'content'   => 'Body written by an ability.',
				'status'    => 'publish',
				'fields'    => array( 'id', 'post_type', 'status', 'title_raw', 'content_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post should succeed for an administrator.' );
		$this->assertSame( 'post', $result['post_type'], 'The created post should be of the requested post type.' );
		$this->assertSame( 'publish', $result['status'], 'The created post should carry the requested status.' );
		$this->assertSame( 'Written by an ability', $result['title_raw'], 'The created post should carry the requested title.' );

		$post = get_post( $result['id'] );

		$this->assertNotNull( $post, 'The created post should exist in the database.' );
		$this->assertSame( 'Written by an ability', $post->post_title, 'The stored title should match the input.' );
		$this->assertSame( 'publish', $post->post_status, 'The stored status should match the input.' );
	}

	/**
	 * Creating a post reports only the requested fields.
	 *
	 * @since x.x.x
	 */
	public function test_create_reports_only_the_requested_fields(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Field projection',
				'fields'    => array( 'id', 'slug' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post should succeed.' );
		$this->assertSame( array( 'id', 'slug' ), array_keys( $result ), 'Only the requested fields should be reported.' );
	}

	/**
	 * A created post defaults to the draft status the endpoint defaults to.
	 *
	 * @since x.x.x
	 */
	public function test_create_defaults_to_a_draft(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Unspecified status',
				'fields'    => array( 'id', 'status' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post should succeed.' );
		$this->assertSame( 'draft', $result['status'], 'A post created without a status should be a draft.' );
	}

	/**
	 * Creating a post in a hierarchical post type keeps the parent.
	 *
	 * @since x.x.x
	 */
	public function test_create_keeps_the_parent_for_a_hierarchical_post_type(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Child page',
				'parent'    => $parent,
				'fields'    => array( 'id', 'post_type', 'parent' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a child page should succeed.' );
		$this->assertSame( 'page', $result['post_type'], 'The created post should be a page.' );
		$this->assertSame( $parent, $result['parent'], 'The created page should keep the requested parent.' );
	}

	/**
	 * A conflicting slug is made unique, as the endpoint makes it.
	 *
	 * @since x.x.x
	 */
	public function test_create_makes_a_conflicting_slug_unique(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		self::factory()->post->create(
			array(
				'post_name'   => 'taken-slug',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Slug conflict',
				'slug'      => 'taken-slug',
				'status'    => 'publish',
				'fields'    => array( 'id', 'slug' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post with a taken slug should succeed.' );
		$this->assertNotSame( 'taken-slug', $result['slug'], 'A conflicting slug should be made unique.' );
		$this->assertStringStartsWith( 'taken-slug', $result['slug'], 'The unique slug should still derive from the requested one.' );
	}

	/**
	 * Creating in a post type that is not exposed to abilities is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_refuses_a_post_type_that_is_not_exposed(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$result = wp_get_ability( 'core/content-create' )->execute(
				array(
					'post_type' => 'wpai_hidden_cpt',
					'title'     => 'Should not be written',
				)
			);

			$this->assertWPError( $result, 'A post type that is not exposed to abilities should be refused.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * A post type exposed to abilities but not to REST is still writable.
	 *
	 * The endpoint is the only implementation, so such a post type has no route to call
	 * until one is arranged. The route must not outlive the request that needed it.
	 *
	 * @since x.x.x
	 */
	public function test_create_writes_a_post_type_that_rest_does_not_expose(): void {
		register_post_type(
			'wpai_write_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			// The ability runs where no REST server has been built yet, as it does under
			// WP-CLI or in any request that is not a REST request.
			unset( $GLOBALS['wp_rest_server'] );

			$result = wp_get_ability( 'core/content-create' )->execute(
				array(
					'post_type' => 'wpai_write_cpt',
					'title'     => 'Written without a REST route',
					'fields'    => array( 'id', 'post_type' ),
				)
			);

			$this->assertNotWPError( $result, 'A post type that REST does not expose should still be writable.' );
			$this->assertSame( 'wpai_write_cpt', $result['post_type'], 'The post should be created in the requested post type.' );

			$this->assertArrayNotHasKey(
				'/wp/v2/wpai_write_cpt',
				rest_get_server()->get_routes(),
				'A post type that REST does not expose should have no route left after the ability ran.'
			);
		} finally {
			unregister_post_type( 'wpai_write_cpt' );
			unset( $GLOBALS['wp_rest_server'] );
		}
	}

	/**
	 * Returns fields a post type does not support, keyed by the case.
	 *
	 * `wpai_write_cpt` supports a title and an editor, and nothing else.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}> The post type and the unsupported field.
	 */
	public function data_unsupported_fields(): array {
		return array(
			'parent on a post'          => array( 'post', array( 'parent' => 1 ) ),
			'menu order on a post'      => array( 'post', array( 'menu_order' => 3 ) ),
			'sticky on a page'          => array( 'page', array( 'sticky' => true ) ),
			'format on a page'          => array( 'page', array( 'format' => 'aside' ) ),
			'excerpt on a custom type'  => array( 'wpai_write_cpt', array( 'excerpt' => 'Not supported.' ) ),
			'author on a custom type'   => array( 'wpai_write_cpt', array( 'author' => 1 ) ),
			'comments on a custom type' => array( 'wpai_write_cpt', array( 'comment_status' => 'open' ) ),
		);
	}

	/**
	 * A field the post type does not support is refused instead of silently dropped.
	 *
	 * The endpoint ignores such a field and writes the rest, so the caller would believe
	 * the field was set.
	 *
	 * @dataProvider data_unsupported_fields
	 *
	 * @since x.x.x
	 *
	 * @param string               $post_type The post type to write.
	 * @param array<string, mixed> $field     The unsupported field and its value.
	 */
	public function test_create_refuses_a_field_the_post_type_does_not_support( string $post_type, array $field ): void {
		$this->register_write_cpt();

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$result = wp_get_ability( 'core/content-create' )->execute(
				array(
					'post_type' => $post_type,
					'title'     => 'Unsupported field',
				) + $field
			);

			$this->assertWPError( $result, 'An unsupported field should be refused.' );
			$this->assertSame( 'content_invalid_field', $result->get_error_code(), 'An unsupported field should be reported as such.' );

			$written = new WP_Query(
				array(
					'post_type'   => $post_type,
					'post_status' => 'any',
					'title'       => 'Unsupported field',
					'fields'      => 'ids',
				)
			);

			$this->assertSame( array(), $written->posts, 'Nothing should be written.' );
		} finally {
			unregister_post_type( 'wpai_write_cpt' );
		}
	}

	/**
	 * A post can be given a format.
	 *
	 * @since x.x.x
	 */
	public function test_create_sets_the_post_format(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'An aside',
				'format'    => 'aside',
				'fields'    => array( 'id' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post with a format should succeed.' );
		$this->assertSame( 'aside', get_post_format( $result['id'] ), 'The post should have the requested format.' );
	}

	/**
	 * A post can be given a template the theme offers for its post type.
	 *
	 * @since x.x.x
	 */
	public function test_create_sets_a_template_the_theme_offers(): void {
		$add_template = static function ( array $templates ): array {
			$templates['wpai-test-template.php'] = 'Test template';

			return $templates;
		};

		add_filter( 'theme_post_templates', $add_template );

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$result = wp_get_ability( 'core/content-create' )->execute(
				array(
					'post_type' => 'post',
					'title'     => 'With a template',
					'template'  => 'wpai-test-template.php',
					'fields'    => array( 'id' ),
				)
			);

			$this->assertNotWPError( $result, 'Creating a post with a template the theme offers should succeed.' );
			$this->assertSame( 'wpai-test-template.php', get_page_template_slug( $result['id'] ), 'The post should use the requested template.' );
		} finally {
			remove_filter( 'theme_post_templates', $add_template );
		}
	}

	/**
	 * Content is written and reported verbatim when blocks are hooked into it.
	 *
	 * The endpoint's own read inserts hooked blocks into the raw content. The ability
	 * reports the content as stored, the way the read ability does.
	 *
	 * @since x.x.x
	 */
	public function test_create_reports_content_as_stored_when_blocks_are_hooked(): void {
		$this->register_hooked_block();

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$content = "<!-- wp:paragraph -->\n<p>Paragraph</p>\n<!-- /wp:paragraph -->";
			$result  = wp_get_ability( 'core/content-create' )->execute(
				array(
					'post_type' => 'post',
					'title'     => 'Hooked',
					'content'   => $content,
					'status'    => 'publish',
					'fields'    => array( 'id', 'content_raw' ),
				)
			);

			$this->assertNotWPError( $result, 'Creating the post should succeed.' );
			$this->assertSame( $content, $result['content_raw'], 'The reported content should be the stored content.' );
			$this->assertSame( $content, get_post( $result['id'] )->post_content, 'The content should be stored verbatim.' );
			$this->assertStringContainsString( 'wpai-test-hooked', $this->render_content( $result['id'] ), 'The hooked block should render.' );
		} finally {
			unregister_block_type( 'wpai-test/hooked' );
		}
	}

	/**
	 * A user who cannot create posts is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_is_refused_for_a_user_who_cannot_create(): void {
		wp_set_current_user( self::$user_ids['subscriber'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Should not be written',
			)
		);

		$this->assertWPError( $result, 'A subscriber should not be able to create a post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Creating without the capability should fail closed as a permission error.' );
	}

	/**
	 * A logged-out caller is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_is_refused_without_a_user(): void {
		wp_set_current_user( 0 );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Should not be written',
			)
		);

		$this->assertWPError( $result, 'An anonymous caller should not be able to create a post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Creating anonymously should fail closed as a permission error.' );
	}

	/**
	 * The endpoint's refusal to assign another author is passed on.
	 *
	 * The ability's own gate lets an author through, so this is the controller's check
	 * doing the work the ability relies on it for.
	 *
	 * @since x.x.x
	 */
	public function test_create_passes_on_the_refusal_to_assign_another_author(): void {
		wp_set_current_user( self::$user_ids['author'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Assigned to someone else',
				'author'    => self::$user_ids['author_secondary'],
			)
		);

		$this->assertWPError( $result, 'An author should not be able to create a post for another user.' );
		$this->assertSame( 'rest_cannot_edit_others', $result->get_error_code(), 'The endpoint error should be passed on unchanged.' );
	}

	/**
	 * An input the endpoint rejects is reported as the endpoint's error.
	 *
	 * @since x.x.x
	 */
	public function test_create_passes_on_an_endpoint_validation_error(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Bad status',
				'status'    => 'future',
				'date'      => 'not-a-date',
			)
		);

		$this->assertWPError( $result, 'An unparseable date should be refused.' );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code(), 'The endpoint validation error should be passed on unchanged.' );
	}

	/**
	 * Updating a post changes the fields the input names.
	 *
	 * @since x.x.x
	 */
	public function test_update_changes_the_named_fields(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Before',
				'post_content' => 'Body before.',
				'post_status'  => 'draft',
			)
		);

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'     => $post_id,
				'title'  => 'After',
				'status' => 'publish',
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'Updating a post should succeed for an administrator.' );
		$this->assertSame( $post_id, $result['id'], 'The updated post should be the requested one.' );
		$this->assertSame( 'After', $result['title_raw'], 'The reported title should be the updated one.' );
		$this->assertSame( 'publish', $result['status'], 'The reported status should be the updated one.' );

		$this->assertSame( 'After', get_post( $post_id )->post_title, 'The stored title should be updated.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'The stored status should be updated.' );
	}

	/**
	 * An update leaves the fields it does not name alone.
	 *
	 * Only the named fields are sent, so the endpoint has nothing to reset the rest to.
	 *
	 * @since x.x.x
	 */
	public function test_update_leaves_unnamed_fields_alone(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Keep the body',
				'post_content' => 'Body that must survive.',
				'post_excerpt' => 'Excerpt that must survive.',
				'post_status'  => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'     => $post_id,
				'title'  => 'Only the title changes',
				'fields' => array( 'id', 'title_raw', 'content_raw', 'excerpt_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'A partial update should succeed.' );
		$this->assertSame( 'Only the title changes', $result['title_raw'], 'The named field should change.' );
		$this->assertSame( 'Body that must survive.', $result['content_raw'], 'An unnamed field should be left alone.' );
		$this->assertSame( 'Excerpt that must survive.', $result['excerpt_raw'], 'An unnamed field should be left alone.' );
	}

	/**
	 * An update naming a field the post type does not support writes nothing.
	 *
	 * @dataProvider data_unsupported_fields
	 *
	 * @since x.x.x
	 *
	 * @param string               $post_type The post type to write.
	 * @param array<string, mixed> $field     The unsupported field and its value.
	 */
	public function test_update_refuses_a_field_the_post_type_does_not_support( string $post_type, array $field ): void {
		$this->register_write_cpt();

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$post_id = self::factory()->post->create(
				array(
					'post_type'   => $post_type,
					'post_title'  => 'Unchanged',
					'post_status' => 'publish',
				)
			);

			$result = wp_get_ability( 'core/content-update' )->execute(
				array(
					'id'    => $post_id,
					'title' => 'Should not be written',
				) + $field
			);

			$this->assertWPError( $result, 'An unsupported field should be refused.' );
			$this->assertSame( 'content_invalid_field', $result->get_error_code(), 'An unsupported field should be reported as such.' );
			$this->assertSame( 'Unchanged', get_post( $post_id )->post_title, 'Nothing should be written.' );
		} finally {
			unregister_post_type( 'wpai_write_cpt' );
		}
	}

	/**
	 * A trashed post can be updated while it keeps its status.
	 *
	 * The endpoint accepts the status a post already has, internal or not.
	 *
	 * @since x.x.x
	 */
	public function test_update_lets_a_trashed_post_keep_its_status(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_trash_post( $post_id );

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'     => $post_id,
				'status' => 'trash',
				'title'  => 'Renamed in the trash',
				'fields' => array( 'status', 'title_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'A trashed post should be able to keep its status.' );
		$this->assertSame( 'trash', $result['status'], 'The post should still be in the trash.' );
		$this->assertSame( 'Renamed in the trash', get_post( $post_id )->post_title, 'The title should be updated.' );
	}

	/**
	 * A null date resets the date, as it does through the endpoint.
	 *
	 * @since x.x.x
	 */
	public function test_update_resets_a_null_date(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'draft',
				'post_date'     => '2020-02-02 02:02:02',
				'post_date_gmt' => '2020-02-02 02:02:02',
			)
		);

		$this->assertSame( '2020-02-02 02:02:02', get_post( $post_id )->post_date_gmt, 'The draft should start with a fixed date.' );

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'     => $post_id,
				'date'   => null,
				'fields' => array( 'id' ),
			)
		);

		$this->assertNotWPError( $result, 'A null date should be accepted.' );
		$this->assertSame( '0000-00-00 00:00:00', get_post( $post_id )->post_date_gmt, 'A draft whose date is reset should get a floating date.' );
	}

	/**
	 * Content read with the read ability and written back leaves hooked blocks rendering.
	 *
	 * The endpoint marks every block hooked into written content as ignored, on the
	 * grounds that the block editor showed it to the writer. The read ability reports
	 * content as stored, without those blocks, so an agent writing it back never saw them.
	 *
	 * @since x.x.x
	 */
	public function test_update_keeps_hooked_blocks_after_a_read_and_write_back(): void {
		$this->register_hooked_block();

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$content = "<!-- wp:paragraph -->\n<p>Paragraph</p>\n<!-- /wp:paragraph -->";
			$post_id = self::factory()->post->create(
				array(
					'post_content' => $content,
					'post_status'  => 'publish',
				)
			);

			$this->assertStringContainsString( 'wpai-test-hooked', $this->render_content( $post_id ), 'The hooked block should render before the write.' );

			$read = wp_get_ability( 'core/content-query' )->execute(
				array(
					'id'     => $post_id,
					'fields' => array( 'content_raw' ),
				)
			);

			$this->assertNotWPError( $read, 'Reading the post should succeed.' );

			$result = wp_get_ability( 'core/content-update' )->execute(
				array(
					'id'      => $post_id,
					'content' => $read['content_raw'],
					'fields'  => array( 'content_raw' ),
				)
			);

			$this->assertNotWPError( $result, 'Writing the content back should succeed.' );
			$this->assertSame( $content, $result['content_raw'], 'The reported content should be the stored content.' );
			$this->assertSame( $content, get_post( $post_id )->post_content, 'The content should be stored verbatim.' );
			$this->assertStringContainsString( 'wpai-test-hooked', $this->render_content( $post_id ), 'The hooked block should still render after the write.' );
		} finally {
			unregister_block_type( 'wpai-test/hooked' );
		}
	}

	/**
	 * Updating a post that does not exist is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_refuses_a_missing_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'    => 999999,
				'title' => 'Nothing to update',
			)
		);

		$this->assertWPError( $result, 'Updating a missing post should be refused.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'A missing post should fail closed as a permission error.' );
	}

	/**
	 * Updating another user's post without the capability is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_is_refused_for_another_users_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( self::$user_ids['author'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'    => $post_id,
				'title' => 'Should not be written',
			)
		);

		$this->assertWPError( $result, 'An author should not be able to update another author\'s post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Editing a post the caller cannot edit should fail closed.' );
	}

	/**
	 * Updating a post of a post type that is not exposed is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_refuses_a_post_type_that_is_not_exposed(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_type'   => 'wpai_hidden_cpt',
					'post_status' => 'publish',
				)
			);

			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$result = wp_get_ability( 'core/content-update' )->execute(
				array(
					'id'    => $post_id,
					'title' => 'Should not be written',
				)
			);

			$this->assertWPError( $result, 'A post of an unexposed post type should not be updatable.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * Deleting a post moves it to the trash by default.
	 *
	 * @since x.x.x
	 */
	public function test_delete_trashes_by_default(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-delete' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'status' ),
			)
		);

		$this->assertNotWPError( $result, 'Trashing a post should succeed for an administrator.' );
		$this->assertFalse( $result['deleted'], 'A trashed post should not be reported as permanently deleted.' );
		$this->assertSame( $post_id, $result['post']['id'], 'The trashed post should be the requested one.' );
		$this->assertSame( 'trash', $result['post']['status'], 'The reported status should be the one the delete left behind.' );

		$this->assertSame( 'trash', get_post( $post_id )->post_status, 'The stored post should be in the trash.' );
	}

	/**
	 * Forcing a delete removes the post permanently.
	 *
	 * @since x.x.x
	 */
	public function test_delete_force_removes_the_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Gone for good',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-delete' )->execute(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'Force deleting a post should succeed for an administrator.' );
		$this->assertTrue( $result['deleted'], 'A forced delete should be reported as permanently deleted.' );
		$this->assertSame( $post_id, $result['post']['id'], 'The deleted post should be the requested one.' );
		$this->assertSame( 'Gone for good', $result['post']['title_raw'], 'The post should be reported as it was before the delete.' );

		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * Trashing a post that is already in the trash is reported.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_an_already_trashed_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_trash_post( $post_id );

		$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => $post_id ) );

		$this->assertWPError( $result, 'Trashing an already trashed post should be refused.' );
		$this->assertSame( 'rest_already_trashed', $result->get_error_code(), 'The endpoint error should be passed on unchanged.' );
	}

	/**
	 * A post type that does not support trashing is reported, not silently deleted.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_when_trashing_is_not_supported(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		add_filter( 'rest_post_trashable', '__return_false' );

		try {
			$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => $post_id ) );
		} finally {
			remove_filter( 'rest_post_trashable', '__return_false' );
		}

		$this->assertWPError( $result, 'Trashing should be refused when the post type does not support it.' );
		$this->assertSame( 'rest_trash_not_supported', $result->get_error_code(), 'The endpoint error should be passed on unchanged.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'A refused trash should leave the post alone.' );
	}

	/**
	 * Deleting another user's post without the capability is refused.
	 *
	 * @since x.x.x
	 */
	public function test_delete_is_refused_for_another_users_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( self::$user_ids['author'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => $post_id ) );

		$this->assertWPError( $result, 'An author should not be able to delete another author\'s post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Deleting a post the caller cannot delete should fail closed.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'A refused delete should leave the post alone.' );
	}

	/**
	 * Deleting a post that does not exist is refused.
	 *
	 * @since x.x.x
	 */
	public function test_delete_refuses_a_missing_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => 999999 ) );

		$this->assertWPError( $result, 'Deleting a missing post should be refused.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'A missing post should fail closed as a permission error.' );
	}

	/**
	 * A `force` that is the string "false" moves the post to the trash.
	 *
	 * The run endpoint reads a delete's input from the query string, where every value
	 * is a string, and a non-empty string is truthy.
	 *
	 * @since x.x.x
	 */
	public function test_delete_does_not_force_when_force_is_the_string_false(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-delete' )->execute(
			array(
				'id'    => $post_id,
				'force' => 'false',
			)
		);

		$this->assertNotWPError( $result, 'Trashing the post should succeed.' );
		$this->assertFalse( $result['deleted'], 'The post should be reported as trashed.' );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'The post should be in the trash, not deleted.' );
	}

	/**
	 * Returns whether the delete is forced.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: bool}> Whether to force the delete.
	 */
	public function data_delete_modes(): array {
		return array(
			'trash' => array( false ),
			'force' => array( true ),
		);
	}

	/**
	 * A user who may delete a post they may not read can delete it, and learns its ID alone.
	 *
	 * The capability to delete is the only one a delete needs. The post is read with the
	 * caller's own permissions, and a refused read must neither stop the delete nor report
	 * a delete that happened as a failure.
	 *
	 * @dataProvider data_delete_modes
	 *
	 * @since x.x.x
	 *
	 * @param bool $force Whether to delete permanently.
	 */
	public function test_delete_reports_a_post_the_caller_may_not_read_by_its_id( bool $force ): void {
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => self::$user_ids['author_secondary'],
				'post_status'  => 'private',
				'post_content' => 'Private body.',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		foreach ( array( 'delete_posts', 'delete_others_posts', 'delete_private_posts' ) as $capability ) {
			$user->add_cap( $capability );
		}

		wp_set_current_user( $user_id );
		$this->register_abilities();

		$this->assertTrue( current_user_can( 'delete_post', $post_id ), 'The caller should be able to delete the post.' );
		$this->assertFalse( current_user_can( 'read_post', $post_id ), 'The caller should not be able to read the post.' );

		$result = wp_get_ability( 'core/content-delete' )->execute(
			array(
				'id'     => $post_id,
				'force'  => $force,
				'fields' => array( 'id', 'status', 'content_raw', 'content_rendered' ),
			)
		);

		$this->assertNotWPError( $result, 'Deleting a post the caller may delete should succeed.' );
		$this->assertSame(
			array(
				'deleted' => $force,
				'post'    => array( 'id' => $post_id ),
			),
			$result,
			'A post the caller may not read should be reported by its ID alone.'
		);
		$this->assertSame( $force ? false : 'trash', get_post_status( $post_id ), 'The post should be deleted as requested.' );
	}

	/**
	 * A delete through the run endpoint moves the post to the trash when force is false.
	 *
	 * The endpoint sends the delete ability as a DELETE request and reads its input from
	 * the query string, which is how the JavaScript client sends it too.
	 *
	 * @dataProvider data_false_query_values
	 *
	 * @since x.x.x
	 *
	 * @param string $force The `force` value as the query string carries it.
	 */
	public function test_rest_delete_trashes_when_force_is_false( string $force ): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->run_through_rest(
			'DELETE',
			'core/content-delete',
			array(
				'id'    => (string) $post_id,
				'force' => $force,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'The delete should succeed.' );
		$this->assertFalse( $response->get_data()['deleted'], 'The post should be reported as trashed.' );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'The post should be in the trash, not deleted.' );
	}

	/**
	 * Returns the query string forms of false.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string}> The value.
	 */
	public function data_false_query_values(): array {
		return array(
			'false' => array( 'false' ),
			'0'     => array( '0' ),
		);
	}

	/**
	 * An update through the run endpoint is a POST request with the input in its body.
	 *
	 * The endpoint would send an ability that is both destructive and idempotent as a
	 * DELETE, with the post content and password in the query string.
	 *
	 * @since x.x.x
	 */
	public function test_rest_update_is_a_post_request(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->run_through_rest(
			'POST',
			'core/content-update',
			array(
				'id'     => $post_id,
				'title'  => 'Updated through REST',
				'fields' => array( 'id', 'title_raw' ),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'The update should be accepted as a POST request.' );
		$this->assertSame( 'Updated through REST', get_post( $post_id )->post_title, 'The post should be updated.' );

		$response = $this->run_through_rest(
			'DELETE',
			'core/content-update',
			array(
				'id'    => (string) $post_id,
				'title' => 'Should not be written',
			)
		);

		$this->assertSame( 405, $response->get_status(), 'The update should not be accepted as a DELETE request.' );
		$this->assertSame( 'Updated through REST', get_post( $post_id )->post_title, 'A refused request should leave the post alone.' );
	}

	/**
	 * Runs an ability through the REST run endpoint.
	 *
	 * GET and DELETE requests carry the input in the query string, the others in a JSON body.
	 *
	 * @since x.x.x
	 *
	 * @param string               $method  The HTTP method.
	 * @param string               $ability The ability name.
	 * @param array<string, mixed> $input   The ability input.
	 * @return \WP_REST_Response The response.
	 */
	private function run_through_rest( string $method, string $ability, array $input ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/' . $ability . '/run' );

		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$request->set_query_params( array( 'input' => $input ) );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'input' => $input ) ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Registers `wpai_write_cpt`, a post type exposed to abilities but not to REST.
	 *
	 * It supports a title and an editor, and nothing else. The caller unregisters it.
	 *
	 * @since x.x.x
	 */
	private function register_write_cpt(): void {
		register_post_type(
			'wpai_write_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);
	}

	/**
	 * Registers `wpai-test/hooked`, a block hooked after every paragraph.
	 *
	 * The caller unregisters it.
	 *
	 * @since x.x.x
	 */
	private function register_hooked_block(): void {
		register_block_type(
			'wpai-test/hooked',
			array(
				'block_hooks'     => array( 'core/paragraph' => 'after' ),
				'render_callback' => static function (): string {
					return '<div class="wpai-test-hooked">Hooked</div>';
				},
			)
		);
	}

	/**
	 * Renders a post's content the way the front end does.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post ID.
	 * @return string The rendered content.
	 */
	private function render_content( int $post_id ): string {
		$previous = $GLOBALS['post'] ?? null;
		$post     = get_post( $post_id );

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rendering needs the global post, as in the loop.
		setup_postdata( $post );

		try {
			/** This filter is documented in wp-includes/post-template.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core content filter, as the front end does.
			return (string) apply_filters( 'the_content', $post->post_content );
		} finally {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post.
		}
	}

	/**
	 * Registers the plugin's content abilities inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_abilities(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Content() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers an ability category if it is not already registered.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The category slug.
	 */
	private function ensure_ability_category( string $slug ): void {
		if ( wp_has_ability_category( $slug ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => ucfirst( $slug ),
					'description' => ucfirst( $slug ) . '.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
