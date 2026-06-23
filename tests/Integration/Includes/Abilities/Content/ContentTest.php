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
	 * @param string $role The role to create the user with.
	 * @return int The new user ID.
	 */
	private function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * The ability is registered in the `content` category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/content' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'core/content', $ability->get_name() );
		$this->assertSame( 'content', $ability->get_category() );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ) );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
		$this->assertTrue( $annotations['idempotent'] );
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

		$this->assertSame( 'Core Provided', wp_get_ability( 'core/content' )->get_label() );

		$this->register_ability();

		$this->assertSame( 'Get Content', wp_get_ability( 'core/content' )->get_label() );
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

		$this->assertSame( 'object', $schema['type'] );
		$this->assertCount( 2, $schema['oneOf'] );

		[ $by_id, $by_type ] = $schema['oneOf'];

		// Mode 1 requires `id`; Mode 2 requires `post_type`. Both reject extra properties.
		$this->assertSame( array( 'id' ), $by_id['required'] );
		$this->assertSame( array( 'post_type' ), $by_type['required'] );
		$this->assertFalse( $by_id['additionalProperties'] );
		$this->assertFalse( $by_type['additionalProperties'] );

		// Query-only filters live only in the query mode, not the by-ID mode.
		$this->assertArrayHasKey( 'per_page', $by_type['properties'] );
		$this->assertArrayNotHasKey( 'per_page', $by_id['properties'] );

		// Exposed post types appear in both modes' `post_type` enum.
		$this->assertContains( 'post', $by_type['properties']['post_type']['enum'] );
		$this->assertContains( 'page', $by_id['properties']['post_type']['enum'] );
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

		$this->assertArrayNotHasKey( 'default', $by_type['properties']['status'] );
		$this->assertArrayNotHasKey( 'default', $by_type['properties']['page'] );
		$this->assertArrayNotHasKey( 'default', $by_type['properties']['per_page'] );
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

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * `post_type` is accepted alongside `id` as a guard: the by-ID mode still resolves the post.
	 *
	 * @since x.x.x
	 */
	public function test_id_mode_accepts_post_type_guard(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'        => $post_id,
				'post_type' => 'post',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['posts'][0]['id'] );
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

		$this->assertSame( 'object', $post_item['type'] );
		$this->assertArrayNotHasKey( 'required', $post_item );
		$this->assertFalse( $post_item['additionalProperties'] );
		$this->assertArrayHasKey( 'raw_content', $post_item['properties'] );
		$this->assertArrayHasKey( 'total', $schema['properties'] );
		$this->assertArrayHasKey( 'total_pages', $schema['properties'] );
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
		$this->assertContains( 'wpai_content_cpt', $enum );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'wpai_content_cpt',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content' )->execute( array( 'post_type' => 'wpai_content_cpt' ) );
		$ids    = wp_list_pluck( $result['posts'], 'id' );

		$this->assertContains( $post_id, $ids );

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

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello Content',
				'post_content' => 'Body here.',
				'post_status'  => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['posts'] );
		$this->assertSame( $post_id, $result['posts'][0]['id'] );
		$this->assertSame( 'Hello Content', $result['posts'][0]['title'] );
		$this->assertSame( 'Body here.', $result['posts'][0]['raw_content'] );
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

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A post type guard mismatch is denied before execution can probe the requested object.
	 *
	 * @since x.x.x
	 */
	public function test_get_by_id_with_mismatched_post_type_is_denied(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'        => $post_id,
				'post_type' => 'page',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
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
			$this->assertGreaterThan( 0, $post_id );

			$this->register_ability();

			$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
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

		$this->assertContains( $published, $ids );
		$this->assertNotContains( $draft, $ids );
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

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * The `fields` filter limits the returned keys.
	 *
	 * @since x.x.x
	 */
	public function test_fields_filter_limits_returned_keys(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'title' ),
			)
		);

		$this->assertSame( array( 'id', 'title' ), array_keys( $result['posts'][0] ) );
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

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Subscribers cannot request published content.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_request_published_content(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'post_type' => 'post' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Subscribers cannot fetch a published post by ID.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_get_single_published_post_by_id(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
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

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * An author can pass the draft gate but only sees their own drafts.
	 *
	 * @since x.x.x
	 */
	public function test_author_cannot_see_other_authors_drafts(): void {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );

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

		$this->assertContains( $draft_b, $ids );
		$this->assertNotContains( $draft_a, $ids );
	}

	/**
	 * Raw content is available to users who can edit the post.
	 *
	 * @since x.x.x
	 */
	public function test_raw_content_visible_to_editor(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Public body with raw block markup.',
			)
		);

		$this->login_as( 'editor' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'raw_content' ),
			)
		);

		$this->assertSame( 'Public body with raw block markup.', $result['posts'][0]['raw_content'] );
	}

	/**
	 * Password-protected content is visible to users who can edit the post.
	 *
	 * @since x.x.x
	 */
	public function test_password_protected_content_visible_to_editor(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => 'Top secret body.',
			)
		);

		$this->login_as( 'editor' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'raw_content' ),
			)
		);

		$this->assertSame( 'Top secret body.', $result['posts'][0]['raw_content'] );
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

		$this->assertCount( 2, $page1['posts'] );
		$this->assertGreaterThanOrEqual( 3, $page1['total'] );
		$this->assertSame( (int) ceil( $page1['total'] / 2 ), $page1['total_pages'] );

		$page2 = wp_get_ability( 'core/content' )->execute(
			array(
				'post_type' => 'post',
				'per_page'  => 2,
				'page'      => 2,
			)
		);

		$this->assertNotEmpty( $page2['posts'] );
		$this->assertSame( $page1['total'], $page2['total'] );
	}

	/**
	 * A single post fetched by ID still reports pagination totals of one.
	 *
	 * @since x.x.x
	 */
	public function test_single_post_reports_totals(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content' )->execute( array( 'id' => $post_id ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['total_pages'] );
	}
}
