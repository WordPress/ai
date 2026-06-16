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
 * @since 1.1.0
 */
class ContentTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 1.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core post types (post, page) as exposed to abilities.
		Show_In_Abilities::register();

		$this->ensure_content_category();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.1.0
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/content' ) ) {
			wp_unregister_ability( 'core/content' );
		}

		remove_filter( 'register_setting_args', array( Show_In_Abilities::class, 'mark_setting' ), 10 );
		remove_filter( 'register_post_type_args', array( Show_In_Abilities::class, 'mark_post_type' ), 10 );

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
	 * @since 1.1.0
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
	 * Registers the plugin's core/content ability inside a faked init action.
	 *
	 * @since 1.1.0
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			Content::register();
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
	 * @since 1.1.0
	 */
	public function test_registers_core_content_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/content' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'core/content', $ability->get_name() );
		$this->assertSame( 'content', $ability->get_category() );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'] );
	}

	/**
	 * When core already provides core/content, the plugin's version replaces it.
	 *
	 * @since 1.1.0
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
	 * The input schema requires either `id` or `post_type` and exposes only marked types.
	 *
	 * @since 1.1.0
	 */
	public function test_input_schema_requires_id_or_post_type(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/content' )->get_input_schema();

		$this->assertSame(
			array(
				array( 'required' => array( 'id' ) ),
				array( 'required' => array( 'post_type' ) ),
			),
			$schema['anyOf']
		);

		$enum = $schema['properties']['post_type']['enum'];
		$this->assertContains( 'post', $enum );
		$this->assertContains( 'page', $enum );
	}

	/**
	 * A published post can be fetched by ID.
	 *
	 * @since 1.1.0
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
	 * Query mode returns only published posts by default.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
	 */
	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute( array( 'post_type' => 'post' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Subscribers cannot request draft posts.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * Password-protected content is withheld from users who cannot edit the post.
	 *
	 * @since 1.1.0
	 */
	public function test_password_protected_content_withheld_from_non_editor(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => 'Top secret body.',
			)
		);

		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = wp_get_ability( 'core/content' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'raw_content' ),
			)
		);

		$this->assertSame( '', $result['posts'][0]['raw_content'] );
	}

	/**
	 * Query mode paginates with `page`/`per_page` and reports totals.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
