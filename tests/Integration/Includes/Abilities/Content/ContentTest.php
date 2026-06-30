<?php
/**
 * Integration tests for the core/read-content Ability provided by the plugin.
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
		if ( wp_has_ability( 'core/read-content' ) ) {
			wp_unregister_ability( 'core/read-content' );
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
	 * ability which registers on the same hook as `core/read-content`.
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
	 * Registers the plugin's core/read-content ability inside a faked init action.
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
	public function test_registers_core_read_content_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/read-content' );

		$this->assertNotNull( $ability, 'The core/read-content ability should be registered.' );
		$this->assertSame( 'core/read-content', $ability->get_name(), 'The registered ability should use the expected name.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The ability should be marked non-destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The ability should be marked idempotent.' );
	}

	/**
	 * When core already provides core/read-content, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_read_content(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/read-content',
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
			wp_get_ability( 'core/read-content' )->get_label(),
			'The core-provided ability should be registered before the plugin override.'
		);

		$this->register_ability();

		$this->assertSame(
			'Read Content',
			wp_get_ability( 'core/read-content' )->get_label(),
			'The plugin-provided content ability should replace the existing one.'
		);
	}

	/**
	 * The input schema models mutually exclusive ID, slug, and query modes, each
	 * rejecting the other modes' properties and exposing only marked types.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_models_mutually_exclusive_modes(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/read-content' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertCount( 3, $schema['oneOf'], 'The input schema should expose exactly three modes.' );

		[ $by_id, $by_slug, $query ] = $schema['oneOf'];

		// All modes reject properties from the other modes.
		$this->assertSame( array( 'id' ), $by_id['required'], 'The by-ID mode should require an ID.' );
		$this->assertSame( array( 'post_type', 'slug' ), $by_slug['required'], 'The slug mode should require post type and slug.' );
		$this->assertSame( array( 'post_type' ), $query['required'], 'The query mode should require a post type.' );
		$this->assertFalse( $by_id['additionalProperties'], 'The by-ID mode should reject unrelated properties.' );
		$this->assertFalse( $by_slug['additionalProperties'], 'The slug mode should reject unrelated properties.' );
		$this->assertFalse( $query['additionalProperties'], 'The query mode should reject unrelated properties.' );

		// Query-only filters live only in the query mode, not the single-post modes.
		$this->assertArrayHasKey( 'include', $query['properties'], 'The query mode should support included post IDs.' );
		$this->assertArrayHasKey( 'per_page', $query['properties'], 'The query mode should support pagination.' );
		$this->assertArrayNotHasKey( 'per_page', $by_id['properties'], 'The by-ID mode should not accept query-only pagination.' );
		$this->assertArrayNotHasKey( 'include', $by_slug['properties'], 'The slug mode should not accept query-only included IDs.' );
		$this->assertArrayNotHasKey( 'slug', $query['properties'], 'The query mode should not accept slug; slug is a single-post mode.' );

		// Exposed post types appear in all modes that accept `post_type`.
		$this->assertContains( 'post', $query['properties']['post_type']['enum'], 'The query mode should include exposed posts.' );
		$this->assertContains( 'page', $by_id['properties']['post_type']['enum'], 'The by-ID guard should include exposed pages.' );
		$this->assertContains( 'page', $by_slug['properties']['post_type']['enum'], 'The slug mode should include exposed pages.' );

		$this->assertSame( 1, $query['properties']['include']['minItems'], 'The include option should require at least one post ID.' );
		$this->assertTrue( $query['properties']['include']['uniqueItems'], 'The include option should reject duplicate post IDs.' );
		$this->assertSame( 'integer', $query['properties']['include']['items']['type'], 'The include option should contain post IDs.' );
		$this->assertSame( 1, $query['properties']['include']['items']['minimum'], 'The include option should contain positive post IDs.' );

		$fields_enum = $query['properties']['fields']['items']['enum'];
		$this->assertContains( 'post_type', $fields_enum, 'The fields enum should expose the post type as post_type.' );
		$this->assertNotContains( 'type', $fields_enum, 'The fields enum should not expose the post type as type.' );
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

		$schema = wp_get_ability( 'core/read-content' )->get_input_schema();
		$query  = $schema['oneOf'][2];

		$this->assertArrayNotHasKey( 'default', $query['properties']['status'], 'Status should rely on runtime defaults, not schema defaults.' );
		$this->assertArrayNotHasKey( 'default', $query['properties']['page'], 'Page should rely on runtime defaults, not schema defaults.' );
		$this->assertArrayNotHasKey( 'default', $query['properties']['per_page'], 'Per-page should rely on runtime defaults, not schema defaults.' );
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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'id'        => $post_id,
				'post_type' => 'post',
			)
		);

		$this->assertIsArray( $result, 'A matching post type guard should allow the by-ID lookup.' );
		$this->assertSame( $post_id, $result['id'], 'The guarded by-ID lookup should return the requested post directly.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'The guarded by-ID lookup should not return the query wrapper.' );
	}

	/**
	 * The output schema describes single-post and query response shapes.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_describes_single_post_and_query_responses(): void {
		$this->register_ability();

		$ability      = wp_get_ability( 'core/read-content' );
		$input_schema = $ability->get_input_schema();
		$schema       = $ability->get_output_schema();
		$post_schema  = $schema['oneOf'][0];
		$query_schema = $schema['oneOf'][1];

		$this->assertSame( 'object', $schema['type'], 'The output schema should describe object responses.' );
		$this->assertCount( 2, $schema['oneOf'], 'The output schema should describe single-post and query responses.' );
		$this->assertSame( 'object', $post_schema['type'], 'The single-post response should be described as an object.' );
		$this->assertArrayNotHasKey( 'required', $post_schema, 'Individual post fields should remain optional.' );
		$this->assertFalse( $post_schema['additionalProperties'], 'Returned posts should not allow unknown properties.' );
		$this->assertArrayHasKey( 'post_type', $post_schema['properties'], 'The post schema should describe the post type as post_type.' );
		$this->assertArrayNotHasKey( 'type', $post_schema['properties'], 'The post schema should not expose the post type as type.' );
		$this->assertSame(
			$input_schema['oneOf'][2]['properties']['fields']['items']['enum'],
			array_keys( $post_schema['properties'] ),
			'The fields enum should match the post output schema properties.'
		);
		$this->assertArrayHasKey( 'content_raw', $post_schema['properties'], 'The post schema should describe raw content.' );
		$this->assertArrayHasKey( 'content_rendered', $post_schema['properties'], 'The post schema should describe rendered content.' );
		$this->assertSame( array( 'posts', 'total', 'total_pages' ), $query_schema['required'], 'The query wrapper should require all top-level properties.' );
		$this->assertArrayHasKey( 'total', $query_schema['properties'], 'The query schema should describe the total count.' );
		$this->assertArrayHasKey( 'total_pages', $query_schema['properties'], 'The query schema should describe page count.' );
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

		// Query mode is the third `oneOf` branch; its `post_type` enum lists exposed types.
		$enum = wp_get_ability( 'core/read-content' )->get_input_schema()['oneOf'][2]['properties']['post_type']['enum'];
		$this->assertContains( 'wpai_content_cpt', $enum, 'Custom post types marked show_in_abilities should appear in the query enum.' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'wpai_content_cpt',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'post_type' => 'wpai_content_cpt' ) );
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

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result, 'The by-ID lookup should return a post array.' );
		$this->assertSame( $post_id, $result['id'], 'The by-ID lookup should return the requested post directly.' );
		$this->assertSame( 'Hello Content', $result['title_rendered'], 'Rendered titles should be returned by default.' );
		$this->assertSame(
			array( 'id', 'post_type', 'status', 'date', 'slug', 'title_rendered' ),
			array_keys( $result ),
			'Omitted fields should return the lean default field set.'
		);
		$this->assertArrayNotHasKey( 'posts', $result, 'The by-ID lookup should not return the query wrapper.' );
	}

	/**
	 * A single post fetched by ID can return explicitly requested rendered and raw content.
	 *
	 * @since x.x.x
	 */
	public function test_get_single_published_post_by_id_can_return_content_fields(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::$post_ids['published_content'];

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'post_type', 'content_rendered', 'content_raw' ),
			)
		);

		$this->assertSame( $post_id, $result['id'], 'The by-ID lookup should return the requested post.' );
		$this->assertSame( 'post', $result['post_type'], 'The by-ID lookup should return the post type as post_type.' );
		$this->assertStringContainsString( 'Body here.', $result['content_rendered'], 'Explicit content fields should include rendered content.' );
		$this->assertSame( 'Body here.', $result['content_raw'], 'Explicit content fields should include raw content.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'The by-ID lookup should not return the query wrapper.' );
	}

	/**
	 * A missing post ID is denied before execution can probe the requested object.
	 *
	 * @since x.x.x
	 */
	public function test_get_by_missing_id_is_denied(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'id' => 999999 ) );

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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

			$result = wp_get_ability( 'core/read-content' )->execute( array( 'id' => $post_id ) );

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

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'post_type' => 'post' ) );
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $published, $ids, 'Published posts should be returned by default.' );
		$this->assertNotContains( $draft, $ids, 'Draft posts should not be returned by default.' );
	}

	/**
	 * Query mode can limit results to included IDs while preserving the requested order.
	 *
	 * @since x.x.x
	 */
	public function test_query_include_limits_results_and_preserves_order(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$first  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$second = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$third  = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'include'   => array( $third, $first ),
				'fields'    => array( 'id' ),
			)
		);
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertSame( array( $third, $first ), $ids, 'Included post IDs should limit results and preserve caller order.' );
		$this->assertNotContains( $second, $ids, 'Posts outside include should not be returned.' );
	}

	/**
	 * Query include still respects the requested post type.
	 *
	 * @since x.x.x
	 */
	public function test_query_include_respects_requested_post_type(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'include'   => array( $page_id, $post_id ),
				'fields'    => array( 'id' ),
			)
		);

		$this->assertSame( array( $post_id ), wp_list_pluck( $result['posts'], 'id' ), 'Include should not leak posts from other post types.' );
	}

	/**
	 * Query include still respects row-level permissions.
	 *
	 * @since x.x.x
	 */
	public function test_query_include_respects_row_level_permissions(): void {
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

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'status'    => array( 'draft' ),
				'include'   => array( $draft_a, $draft_b ),
				'fields'    => array( 'id' ),
			)
		);

		$this->assertSame( array( $draft_b ), wp_list_pluck( $result['posts'], 'id' ), 'Include should not bypass row-level draft permissions.' );
	}

	/**
	 * Query mode can return included drafts with explicitly requested rendered and raw content.
	 *
	 * @since x.x.x
	 */
	public function test_query_draft_include_can_return_content_fields(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$draft = self::factory()->post->create(
			array(
				'post_title'   => 'Draft content fields',
				'post_content' => 'Draft body for content fields.',
				'post_status'  => 'draft',
			)
		);

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'status'    => array( 'draft' ),
				'include'   => array( $draft ),
				'fields'    => array( 'id', 'post_type', 'status', 'content_rendered', 'content_raw' ),
			)
		);

		$this->assertSame( array( $draft ), wp_list_pluck( $result['posts'], 'id' ), 'The draft query should return only the included draft.' );
		$this->assertSame( 'post', $result['posts'][0]['post_type'], 'Query responses should return the post type as post_type.' );
		$this->assertSame( 'draft', $result['posts'][0]['status'], 'The draft query should expose the requested draft status.' );
		$this->assertStringContainsString( 'Draft body for content fields.', $result['posts'][0]['content_rendered'], 'Draft query results should include rendered content when requested.' );
		$this->assertSame( 'Draft body for content fields.', $result['posts'][0]['content_raw'], 'Draft query results should include raw content when requested.' );
	}

	/**
	 * Querying by slug without a post type is rejected by the input schema.
	 *
	 * @since x.x.x
	 */
	public function test_slug_mode_requires_post_type(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'slug' => 'whatever' ) );

		$this->assertWPError( $result, 'Slug queries without a post type should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Invalid slug queries should return an input error.' );
	}

	/**
	 * Slug mode returns a single post directly when paired with a post type.
	 *
	 * @since x.x.x
	 */
	public function test_get_single_published_post_by_slug(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'content-slug-mode',
				'post_title'  => 'Content Slug Mode',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'slug'      => 'content-slug-mode',
			)
		);

		$this->assertIsArray( $result, 'The slug lookup should return a post array.' );
		$this->assertSame( $post_id, $result['id'], 'The slug lookup should return the requested post directly.' );
		$this->assertSame( 'content-slug-mode', $result['slug'], 'The slug lookup should return the matching slug.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'The slug lookup should not return the query wrapper.' );
		$this->assertArrayNotHasKey( 'total', $result, 'The slug lookup should not return query totals.' );
	}

	/**
	 * A single post fetched by slug can return explicitly requested rendered and raw content.
	 *
	 * @since x.x.x
	 */
	public function test_get_single_published_post_by_slug_can_return_content_fields(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_name'    => 'content-slug-fields',
				'post_title'   => 'Content Slug Fields',
				'post_content' => 'Slug body for content fields.',
				'post_status'  => 'publish',
			)
		);

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'slug'      => 'content-slug-fields',
				'fields'    => array( 'id', 'post_type', 'slug', 'content_rendered', 'content_raw' ),
			)
		);

		$this->assertSame( $post_id, $result['id'], 'The slug lookup should return the requested post.' );
		$this->assertSame( 'post', $result['post_type'], 'The slug lookup should return the post type as post_type.' );
		$this->assertSame( 'content-slug-fields', $result['slug'], 'The slug lookup should return the matching slug.' );
		$this->assertStringContainsString( 'Slug body for content fields.', $result['content_rendered'], 'Slug lookups should include rendered content when requested.' );
		$this->assertSame( 'Slug body for content fields.', $result['content_raw'], 'Slug lookups should include raw content when requested.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'The slug lookup should not return the query wrapper.' );
	}

	/**
	 * Query-only filters cannot be combined with slug mode.
	 *
	 * @since x.x.x
	 */
	public function test_slug_mode_rejects_query_only_params(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'slug'      => 'content-slug-mode',
				'per_page'  => 10,
			)
		);

		$this->assertWPError( $result, 'Combining slug mode with query-only params should fail validation.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'Invalid slug mode combinations should return an input error.' );
	}

	/**
	 * Include is a query-only option and cannot be combined with single-post modes.
	 *
	 * @since x.x.x
	 */
	public function test_include_cannot_be_combined_with_single_post_modes(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$by_id   = wp_get_ability( 'core/read-content' )->execute(
			array(
				'id'      => self::$post_ids['published'],
				'include' => array( self::$post_ids['published'] ),
			)
		);
		$by_slug = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'slug'      => 'whatever',
				'include'   => array( self::$post_ids['published'] ),
			)
		);

		$this->assertWPError( $by_id, 'Include should fail validation in ID mode.' );
		$this->assertSame( 'ability_invalid_input', $by_id->get_error_code(), 'ID plus include should return an input error.' );
		$this->assertWPError( $by_slug, 'Include should fail validation in slug mode.' );
		$this->assertSame( 'ability_invalid_input', $by_slug->get_error_code(), 'Slug plus include should return an input error.' );
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

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'title_rendered' ),
			)
		);

		$this->assertSame(
			array( 'id', 'title_rendered' ),
			array_keys( $result ),
			'The fields filter should limit the response to exactly the requested keys.'
		);
	}

	/**
	 * Logged-out users cannot run the ability.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );
		$this->register_ability();

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'post_type' => 'post' ) );

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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result, 'Subscribers should be able to fetch a readable published post by ID.' );
		$this->assertSame( 'Readable single', $result['title_rendered'], 'Subscribers should receive the rendered title.' );
		$this->assertArrayNotHasKey( 'title_raw', $result, 'Subscribers should not receive raw titles without edit access.' );
		$this->assertArrayNotHasKey( 'content_raw', $result, 'Subscribers should not receive raw content without edit access.' );
		$this->assertArrayNotHasKey( 'content_rendered', $result, 'Rendered content should require an explicit field request.' );
	}

	/**
	 * Subscribers cannot request edit-context raw fields in query mode.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_request_raw_fields_in_query_mode(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result, 'The readable published post should be returned.' );
		$this->assertSame( 'Readable title', $result['title_rendered'], 'Rendered title should remain visible.' );
		$this->assertArrayNotHasKey( 'title_raw', $result, 'Raw title should be omitted.' );
		$this->assertArrayNotHasKey( 'excerpt_raw', $result, 'Raw excerpt should be omitted.' );
		$this->assertArrayNotHasKey( 'content_raw', $result, 'Raw content should be omitted.' );
		$this->assertArrayNotHasKey( 'content_rendered', $result, 'Rendered content should be omitted from the lean default field set.' );
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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute(
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

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'content_raw' ),
			)
		);

		$this->assertSame(
			'Public body with raw block markup.',
			$result['content_raw'],
			'Editors should receive explicitly requested raw content.'
		);
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

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'content_raw', 'content_rendered' ),
			)
		);

		$this->assertSame(
			'Top secret body.',
			$result['content_raw'],
			'Editors should receive raw password-protected content.'
		);
		$this->assertStringContainsString(
			'Top secret body.',
			$result['content_rendered'],
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

		$result = wp_get_ability( 'core/read-content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'content_rendered', 'content_protected' ),
			)
		);

		$this->assertSame( '', $result['content_rendered'], 'Password-protected rendered content should be withheld.' );
		$this->assertTrue( $result['content_protected'], 'The protected flag should reveal the field is password-protected.' );
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

		$page1 = wp_get_ability( 'core/read-content' )->execute(
			array(
				'post_type' => 'post',
				'per_page'  => 2,
				'page'      => 1,
			)
		);

		$this->assertCount( 2, $page1['posts'], 'The first page should honor the requested per_page value.' );
		$this->assertGreaterThanOrEqual( 3, $page1['total'], 'The query should report the total matching post count.' );
		$this->assertSame( (int) ceil( $page1['total'] / 2 ), $page1['total_pages'], 'The query should report the computed total page count.' );

		$page2 = wp_get_ability( 'core/read-content' )->execute(
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
	 * A single post fetched by ID is returned directly without query totals.
	 *
	 * @since x.x.x
	 */
	public function test_single_post_returns_direct_post_object(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::$post_ids['published'];

		$result = wp_get_ability( 'core/read-content' )->execute( array( 'id' => $post_id ) );

		$this->assertSame( $post_id, $result['id'], 'Single-post responses should include the requested post ID.' );
		$this->assertArrayNotHasKey( 'posts', $result, 'Single-post responses should not include the query posts wrapper.' );
		$this->assertArrayNotHasKey( 'total', $result, 'Single-post responses should not include query totals.' );
		$this->assertArrayNotHasKey( 'total_pages', $result, 'Single-post responses should not include query page totals.' );
	}
}
