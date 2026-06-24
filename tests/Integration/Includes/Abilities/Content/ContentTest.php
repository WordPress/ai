<?php
/**
 * Integration tests for the core/content Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Content;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Content ability test case.
 *
 * @since x.x.x
 */
class ContentTest extends WP_UnitTestCase {

	/**
	 * The exposure component. Held so the same instance can detach its filters on tear down.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Abilities\Show_In_Abilities
	 */
	private $show_in_abilities;

	/**
	 * Shared user IDs keyed by role or fixture name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Shared post IDs keyed by fixture name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $post_ids = array();

	/**
	 * Creates shared users and posts for the content ability tests.
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

		self::$post_ids = array(
			'published'                  => $factory->post->create( array( 'post_status' => 'publish' ) ),
			'published_content'          => $factory->post->create(
				array(
					'post_title'   => 'Hello Content',
					'post_content' => 'Body here.',
					'post_status'  => 'publish',
				)
			),
			'subscriber_content'         => $factory->post->create(
				array(
					'post_title'   => 'Visible to subscribers',
					'post_content' => 'Rendered body for subscribers.',
					'post_status'  => 'publish',
				)
			),
			'readable_single'            => $factory->post->create(
				array(
					'post_title'   => 'Readable single',
					'post_content' => 'Readable single body.',
					'post_status'  => 'publish',
				)
			),
			'limited_role_content'       => $factory->post->create(
				array(
					'post_author'  => self::$user_ids['administrator'],
					'post_title'   => 'Readable title',
					'post_content' => 'Readable body for limited role.',
					'post_excerpt' => 'Readable excerpt.',
					'post_status'  => 'publish',
				)
			),
			'raw_content'                => $factory->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => 'Public body with raw block markup.',
				)
			),
			'password_protected_editor'  => $factory->post->create(
				array(
					'post_status'   => 'publish',
					'post_password' => 'secret',
					'post_content'  => 'Top secret body.',
				)
			),
			'password_protected_limited' => $factory->post->create(
				array(
					'post_author'   => self::$user_ids['administrator'],
					'post_status'   => 'publish',
					'post_password' => 'secret',
					'post_content'  => 'Hidden rendered body.',
				)
			),
		);
	}

	/**
	 * Deletes shared posts created for the content ability tests.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		foreach ( self::$post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		self::$post_ids = array();
		self::$user_ids = array();
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core post types (post, page) as exposed to abilities.
		$this->show_in_abilities = new Show_In_Abilities();
		$this->show_in_abilities->register();

		$this->ensure_content_category();

		// The plugin also registers the `core/settings` ability (into the `site` category)
		// on the same abilities-init hook, so make sure that category exists too; otherwise
		// its registration emits an "incorrect usage" notice that fails this test.
		$this->ensure_site_category();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/content' ) ) {
			wp_unregister_ability( 'core/content' );
		}

		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );
		remove_filter( 'register_post_type_args', array( $this->show_in_abilities, 'mark_post_type' ), 10 );

		// Restore the curated post types to their unmarked state to avoid leaking into other tests.
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}

			unset( $object->show_in_abilities );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Ensures the `content` ability category exists for the ability to attach to.
	 *
	 * @since x.x.x
	 */
	private function ensure_content_category(): void {
		if ( wp_has_ability_category( 'content' ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				'content',
				array(
					'label'       => 'Content',
					'description' => 'Content.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Ensures the `site` ability category exists, used by the plugin's `core/settings`
	 * ability which registers on the same hook as `core/content`.
	 *
	 * @since x.x.x
	 */
	private function ensure_site_category(): void {
		if ( wp_has_ability_category( 'site' ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				'site',
				array(
					'label'       => 'Site',
					'description' => 'Site.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's core/content ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Content() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Logs in as a user with the given role and returns the user ID.
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function login_as( string $role ): int {
		$user_id = self::$user_ids[ $role ] ?? self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Returns roles that can read public posts but cannot edit another user's post.
	 *
	 * @return array<string, array{role: string}> Role test cases.
	 */
	public function data_roles_without_edit_access_to_other_users_posts(): array {
		return array(
			'subscriber'  => array(
				'role' => 'subscriber',
			),
			'contributor' => array(
				'role' => 'contributor',
			),
			'author'      => array(
				'role' => 'author',
			),
		);
	}

	/**
	 * The ability is registered in the `content` category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/content' );

		$this->assertNotNull( $ability, 'The core/content ability should be registered.' );
		$this->assertSame( 'core/content', $ability->get_name(), 'The registered ability should use the expected name.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The ability should be marked non-destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The ability should be marked idempotent.' );
	}

	/**
	 * When core already provides core/content, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_content(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/content',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided content ability.',
					'category'            => 'content',
					'execute_callback'    => static function (): array {
						return array( 'posts' => array() );
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertSame(
			'Core Provided',
			wp_get_ability( 'core/content' )->get_label(),
			'The core-provided ability should be registered before the plugin override.'
		);

		$this->register_ability();

		$this->assertSame(
			'Get Content',
			wp_get_ability( 'core/content' )->get_label(),
			'The plugin-provided content ability should replace the existing one.'
		);
	}

	/**
	 * The input schema models two mutually exclusive modes (by `id` or by `post_type`),
	 * each rejecting the other's properties, and exposes only marked types.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_models_mutually_exclusive_modes(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/content' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertCount( 2, $schema['oneOf'], 'The input schema should expose exactly two modes.' );

		[ $by_id, $by_type ] = $schema['oneOf'];

		// Mode 1 requires `id`; Mode 2 requires `post_type`. Both reject extra properties.
		$this->assertSame( array( 'id' ), $by_id['required'], 'The by-ID mode should require an ID.' );
		$this->assertSame( array( 'post_type' ), $by_type['required'], 'The query mode should require a post type.' );
		$this->assertFalse( $by_id['additionalProperties'], 'The by-ID mode should reject unrelated properties.' );
		$this->assertFalse( $by_type['additionalProperties'], 'The query mode should reject unrelated properties.' );

		// Query-only filters live only in the query mode, not the by-ID mode.
		$this->assertArrayHasKey( 'per_page', $by_type['properties'], 'The query mode should support pagination.' );
		$this->assertArrayNotHasKey( 'per_page', $by_id['properties'], 'The by-ID mode should not accept query-only pagination.' );

		// Exposed post types appear in both modes' `post_type` enum.
		$this->assertContains( 'post', $by_type['properties']['post_type']['enum'], 'The query mode should include exposed posts.' );
		$this->assertContains( 'page', $by_id['properties']['post_type']['enum'], 'The by-ID guard should include exposed pages.' );

		$fields_enum = $by_type['properties']['fields']['items']['enum'];
		$this->assertContains( 'content_raw', $fields_enum, 'The fields enum should include raw content.' );
		$this->assertContains( 'content_rendered', $fields_enum, 'The fields enum should include rendered content.' );
		$this->assertContains( 'title_raw', $fields_enum, 'The fields enum should include raw titles.' );
		$this->assertContains( 'title_rendered', $fields_enum, 'The fields enum should include rendered titles.' );
	}

	/**
	 * Branch-local defaults are omitted so the schema can compile in the client-side
	 * Abilities API validator. Runtime defaults are still applied by the ability.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_omits_oneof_branch_defaults(): void {
		$this->register_ability();

		$schema  = wp_get_ability( 'core/content' )->get_input_schema();
		$by_type = $schema['oneOf'][1];

		$this->assertArrayNotHasKey( 'default', $by_type['properties']['status'], 'Status should rely on runtime defaults, not schema defaults.' );
		$this->assertArrayNotHasKey( 'default', $by_type['properties']['page'], 'Page should rely on runtime defaults, not schema defaults.' );
		$this->assertArrayNotHasKey( 'default', $by_type['properties']['per_page'], 'Per-page should rely on runtime defaults, not schema defaults.' );
	}

	/**
	 * Query-mode filters cannot be combined with a by-ID lookup: passing `per_page` alongside
	 * `id` is rejected outright rather than silently ignored.
	 *
	 * @since x.x.x
	 */
	public function test_id_mode_rejects_query_only_params(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'       => 1,
				'per_page' => 10,
			)
		);

		$this->assertWPError( $result, 'Combining by-ID mode with query-only params should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Invalid mode combinations should return an input error.' );
	}

	/**
	 * `post_type` is accepted alongside `id` as a guard: the by-ID mode still resolves the post.
	 *
	 * @since x.x.x
	 */
	public function test_id_mode_accepts_post_type_guard(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::$post_ids['published'];

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'        => $post_id,
				'post_type' => 'post',
			)
		);

		$this->assertIsArray( $result, 'A matching post type guard should allow the by-ID lookup.' );
		$this->assertSame( $post_id, $result['posts'][0]['id'], 'The guarded by-ID lookup should return the requested post.' );
	}

	/**
	 * The output schema describes each post as an object with no required fields.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_has_no_required_post_fields(): void {
		$this->register_ability();

		$schema    = wp_get_ability( 'core/content' )->get_output_schema();
		$post_item = $schema['properties']['posts']['items'];

		$this->assertSame( array( 'posts', 'total', 'total_pages' ), $schema['required'], 'The response wrapper should require all top-level properties.' );
		$this->assertSame( 'object', $post_item['type'], 'Each returned post should be described as an object.' );
		$this->assertArrayNotHasKey( 'required', $post_item, 'Individual post fields should remain optional.' );
		$this->assertFalse( $post_item['additionalProperties'], 'Returned posts should not allow unknown properties.' );
		$this->assertArrayHasKey( 'content_raw', $post_item['properties'], 'The post schema should describe raw content.' );
		$this->assertArrayHasKey( 'content_rendered', $post_item['properties'], 'The post schema should describe rendered content.' );
		$this->assertArrayHasKey( 'total', $schema['properties'], 'The response schema should describe the total count.' );
		$this->assertArrayHasKey( 'total_pages', $schema['properties'], 'The response schema should describe page count.' );
	}

	/**
	 * A post type registered by another active plugin and flagged `show_in_abilities`
	 * is exposed by the ability, both in the input enum and in query results.
	 *
	 * @since x.x.x
	 */
	public function test_exposes_a_post_type_registered_by_another_plugin(): void {
		register_post_type(
			'wpai_content_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		$this->login_as( 'administrator' );
		$this->register_ability();

		// Query mode is the second `oneOf` branch; its `post_type` enum lists exposed types.
		$enum = wp_get_ability( 'core/content' )->get_input_schema()['oneOf'][1]['properties']['post_type']['enum'];
		$this->assertContains( 'wpai_content_cpt', $enum, 'Custom post types marked show_in_abilities should appear in the query enum.' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'wpai_content_cpt',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content' )->execute( array( 'post_type' => 'wpai_content_cpt' ) );
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $post_id, $ids, 'The custom post type should be queryable through the content ability.' );

		unregister_post_type( 'wpai_content_cpt' );
	}

	/**
	 * A published post can be fetched by ID.
	 *
	 * @since x.x.x
	 */
	public function test_get_single_published_post_by_id(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::$post_ids['published_content'];

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result, 'The by-ID lookup should return a response array.' );
		$this->assertCount( 1, $result['posts'], 'The by-ID lookup should return exactly one post.' );
		$this->assertSame( $post_id, $result['posts'][0]['id'], 'The by-ID lookup should return the requested post.' );
		$this->assertSame( 'Hello Content', $result['posts'][0]['title_raw'], 'Editable users should receive raw titles by default.' );
		$this->assertSame( 'Hello Content', $result['posts'][0]['title_rendered'], 'Rendered titles should be returned by default.' );
		$this->assertSame( 'Body here.', $result['posts'][0]['content_raw'], 'Editable users should receive raw content by default.' );
		$this->assertStringContainsString( 'Body here.', $result['posts'][0]['content_rendered'], 'Rendered content should be returned by default.' );
	}

	/**
	 * A missing post ID is denied before execution can probe the requested object.
	 *
	 * @since x.x.x
	 */
	public function test_get_by_missing_id_is_denied(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => 999999 ) );

		$this->assertWPError( $result, 'Missing posts should be denied before execution probes object details.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Missing posts should fail closed as a permission error.' );
	}

	/**
	 * A post type guard mismatch is denied before execution can probe the requested object.
	 *
	 * @since x.x.x
	 */
	public function test_get_by_id_with_mismatched_post_type_is_denied(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::$post_ids['published'];

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'        => $post_id,
				'post_type' => 'page',
			)
		);

		$this->assertWPError( $result, 'Mismatched post type guards should deny the lookup.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Mismatched post type guards should fail closed as a permission error.' );
	}

	/**
	 * A post from a post type not exposed to abilities is denied.
	 *
	 * @since x.x.x
	 */
	public function test_get_by_id_for_unexposed_post_type_is_denied(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'       => true,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		try {
			$this->login_as( 'administrator' );

			$post_id = self::factory()->post->create(
				array(
					'post_type'   => 'wpai_hidden_cpt',
					'post_status' => 'publish',
				)
			);
			$this->assertGreaterThan( 0, $post_id, 'The hidden custom post should be created for the denial check.' );

			$this->register_ability();

			$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

			$this->assertWPError( $result, 'Posts from unexposed post types should be denied.' );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Unexposed post types should fail closed as a permission error.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * Query mode returns only published posts by default.
	 *
	 * @since x.x.x
	 */
	public function test_query_returns_only_published_by_default(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft     = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$result = wp_get_ability( 'core/content' )->execute( array( 'post_type' => 'post' ) );
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $published, $ids, 'Published posts should be returned by default.' );
		$this->assertNotContains( $draft, $ids, 'Draft posts should not be returned by default.' );
	}

	/**
	 * Querying by slug without a post type is rejected by the input schema.
	 *
	 * @since x.x.x
	 */
	public function test_query_by_slug_requires_post_type(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'slug' => 'whatever' ) );

		$this->assertWPError( $result, 'Slug queries without a post type should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Invalid slug queries should return an input error.' );
	}

	/**
	 * The `fields` filter limits the returned keys.
	 *
	 * @since x.x.x
	 */
	public function test_fields_filter_limits_returned_keys(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::$post_ids['published_content'];

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'title_rendered' ),
			)
		);

		$this->assertSame( array( 'id', 'title_rendered' ), array_keys( $result['posts'][0] ) );
	}

	/**
	 * Logged-out users cannot run the ability.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'post_type' => 'post' ) );

		$this->assertWPError( $result, 'Logged-out users should not be allowed to run the content ability.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Logged-out users should receive a permission error.' );
	}

	/**
	 * Subscribers can request rendered published content.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_can_request_published_content(): void {
		$post_id = self::$post_ids['subscriber_content'];

		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'fields'    => array( 'id', 'title_rendered', 'content_rendered' ),
			)
		);
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $post_id, $ids, 'Subscribers should be able to query readable published posts.' );
		$post_index = array_search( $post_id, $ids, true );
		$this->assertIsInt( $post_index, 'The published post should be present in the subscriber query response.' );
		$post = $result['posts'][ $post_index ];
		$this->assertSame( 'Visible to subscribers', $post['title_rendered'], 'Subscribers should receive rendered titles.' );
		$this->assertStringContainsString( 'Rendered body for subscribers.', $post['content_rendered'], 'Subscribers should receive rendered content.' );
		$this->assertArrayNotHasKey( 'content_raw', $post, 'Subscribers should not receive raw content without edit access.' );
	}

	/**
	 * Subscribers can fetch a published post by ID.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_can_get_single_published_post_by_id(): void {
		$post_id = self::$post_ids['readable_single'];

		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result, 'Subscribers should be able to fetch a readable published post by ID.' );
		$this->assertSame( 'Readable single', $result['posts'][0]['title_rendered'], 'Subscribers should receive the rendered title.' );
		$this->assertStringContainsString( 'Readable single body.', $result['posts'][0]['content_rendered'], 'Subscribers should receive rendered content.' );
		$this->assertArrayNotHasKey( 'title_raw', $result['posts'][0], 'Subscribers should not receive raw titles without edit access.' );
		$this->assertArrayNotHasKey( 'content_raw', $result['posts'][0], 'Subscribers should not receive raw content without edit access.' );
	}

	/**
	 * Subscribers cannot request edit-context raw fields in query mode.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_request_raw_fields_in_query_mode(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'fields'    => array( 'content_raw' ),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be able to request raw fields in query mode.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber raw-field query requests should return a permission error.' );
	}

	/**
	 * Subscribers cannot request edit-context raw fields for a single post.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_request_raw_fields_for_single_post(): void {
		$post_id = self::$post_ids['published'];

		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'content_raw' ),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be able to request raw fields by ID.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber raw-field by-ID requests should return a permission error.' );
	}

	/**
	 * Users who cannot edit another user's post do not receive raw fields by default.
	 *
	 * @dataProvider data_roles_without_edit_access_to_other_users_posts
	 *
	 * @param string $role The role to test.
	 */
	public function test_default_fields_omit_raw_fields_for_roles_without_edit_access_to_other_users_posts( string $role ): void {
		$post_id = self::$post_ids['limited_role_content'];

		$this->login_as( $role );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result, 'The readable published post should be returned.' );
		$this->assertSame( 'Readable title', $result['posts'][0]['title_rendered'], 'Rendered title should remain visible.' );
		$this->assertStringContainsString( 'Readable body for limited role.', $result['posts'][0]['content_rendered'], 'Rendered content should remain visible.' );
		$this->assertArrayNotHasKey( 'title_raw', $result['posts'][0], 'Raw title should be omitted.' );
		$this->assertArrayNotHasKey( 'excerpt_raw', $result['posts'][0], 'Raw excerpt should be omitted.' );
		$this->assertArrayNotHasKey( 'content_raw', $result['posts'][0], 'Raw content should be omitted.' );
	}

	/**
	 * Users who cannot edit another user's post cannot explicitly request raw fields.
	 *
	 * @dataProvider data_roles_without_edit_access_to_other_users_posts
	 *
	 * @param string $role The role to test.
	 */
	public function test_raw_field_requests_are_denied_for_roles_without_edit_access_to_other_users_posts( string $role ): void {
		$post_id = self::$post_ids['limited_role_content'];

		$this->login_as( $role );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'content_raw' ),
			)
		);

		$this->assertWPError( $result, 'Raw field requests should fail for users without edit access.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Raw field requests should require edit access to the post.' );
	}

	/**
	 * Subscribers cannot request draft posts.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_request_draft_status(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'status'    => array( 'draft' ),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be allowed to query draft posts.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber draft queries should return a permission error.' );
	}

	/**
	 * Subscribers cannot request private posts.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_request_private_status(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'status'    => array( 'private' ),
			)
		);

		$this->assertWPError( $result, 'Subscribers should not be allowed to query private posts.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber private queries should return a permission error.' );
	}

	/**
	 * An author can pass the draft gate but only sees their own drafts.
	 *
	 * @since x.x.x
	 */
	public function test_author_cannot_see_other_authors_drafts(): void {
		$author_a = self::$user_ids['author'];
		$author_b = self::$user_ids['author_secondary'];

		$draft_a = self::factory()->post->create(
			array(
				'post_author' => $author_a,
				'post_status' => 'draft',
			)
		);
		$draft_b = self::factory()->post->create(
			array(
				'post_author' => $author_b,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $author_b );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'status'    => array( 'draft' ),
			)
		);
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $draft_b, $ids, 'Authors should see their own drafts.' );
		$this->assertNotContains( $draft_a, $ids, 'Authors should not see another author\'s drafts.' );
	}

	/**
	 * Raw content is available to users who can edit the post.
	 *
	 * @since x.x.x
	 */
	public function test_raw_content_visible_to_editor(): void {
		$post_id = self::$post_ids['raw_content'];

		$this->login_as( 'editor' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'content_raw' ),
			)
		);

		$this->assertSame( 'Public body with raw block markup.', $result['posts'][0]['content_raw'] );
	}

	/**
	 * Password-protected content is visible to users who can edit the post.
	 *
	 * @since x.x.x
	 */
	public function test_password_protected_content_visible_to_editor(): void {
		$post_id = self::$post_ids['password_protected_editor'];

		$this->login_as( 'editor' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'content_raw', 'content_rendered' ),
			)
		);

		$this->assertSame(
			'Top secret body.',
			$result['posts'][0]['content_raw'],
			'Editors should receive raw password-protected content.'
		);
		$this->assertStringContainsString(
			'Top secret body.',
			$result['posts'][0]['content_rendered'],
			'Editors should receive rendered password-protected content.'
		);
	}

	/**
	 * Password-protected rendered content is withheld from users who cannot edit the post.
	 *
	 * @dataProvider data_roles_without_edit_access_to_other_users_posts
	 *
	 * @param string $role The role to test.
	 */
	public function test_password_protected_rendered_content_is_empty_for_roles_without_edit_access_to_other_users_posts( string $role ): void {
		$post_id = self::$post_ids['password_protected_limited'];

		$this->login_as( $role );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'content_rendered', 'content_protected' ),
			)
		);

		$this->assertSame( '', $result['posts'][0]['content_rendered'], 'Password-protected rendered content should be withheld.' );
		$this->assertTrue( $result['posts'][0]['content_protected'], 'The protected flag should reveal the field is password-protected.' );
	}

	/**
	 * Query mode paginates with `page`/`per_page` and reports totals.
	 *
	 * @since x.x.x
	 */
	public function test_query_paginates_and_reports_totals(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$page1 = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'per_page'  => 2,
				'page'      => 1,
			)
		);

		$this->assertCount( 2, $page1['posts'], 'The first page should honor the requested per_page value.' );
		$this->assertGreaterThanOrEqual( 3, $page1['total'], 'The query should report the total matching post count.' );
		$this->assertSame( (int) ceil( $page1['total'] / 2 ), $page1['total_pages'], 'The query should report the computed total page count.' );

		$page2 = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'per_page'  => 2,
				'page'      => 2,
			)
		);

		$this->assertNotEmpty( $page2['posts'], 'The second page should return remaining posts.' );
		$this->assertSame( $page1['total'], $page2['total'], 'Pagination should keep total counts stable across pages.' );
	}

	/**
	 * A single post fetched by ID still reports pagination totals of one.
	 *
	 * @since x.x.x
	 */
	public function test_single_post_reports_totals(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::$post_ids['published'];

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

		$this->assertSame( 1, $result['total'], 'Single-post responses should report one total result.' );
		$this->assertSame( 1, $result['total_pages'], 'Single-post responses should report one total page.' );
	}
}
