<?php
/**
 * Integration tests for the core/edit-content Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Content;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Edit content ability test case.
 *
 * @since x.x.x
 */
class EditContentTest extends WP_UnitTestCase {

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates shared users for the edit content ability tests.
	 *
	 * Posts are created per test instead: the ability mutates them, so shared post
	 * fixtures would leak state between tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
			'subscriber'    => $factory->user->create( array( 'role' => 'subscriber' ) ),
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

		$this->ensure_ability_category( 'content' );

		/*
		 * The plugin registers its other abilities on the same abilities-init hook, so
		 * booting the registry here also registers `core/read-settings` (the `site`
		 * category) and `core/read-users` (the `user` category). Make sure those
		 * categories exist too; otherwise their registration emits an "incorrect usage"
		 * notice that fails these tests.
		 */
		$this->ensure_ability_category( 'site' );
		$this->ensure_ability_category( 'user' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		// Content::register() registers both content abilities; unregister both.
		foreach ( array( 'core/edit-content', 'core/read-content' ) as $ability ) {
			if ( ! wp_has_ability( $ability ) ) {
				continue;
			}

			wp_unregister_ability( $ability );
		}

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
	 * Ensures an ability category exists for an ability to attach to.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The ability category slug.
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

	/**
	 * Registers the plugin's content abilities inside a faked init action.
	 *
	 * Registers both the read and the edit ability: the edit ability is gated
	 * separately and is not part of {@see Content::register()}.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			$content = new Content();
			$content->register();
			$content->register_edit_content();
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
	 * Executes the edit ability with the given input.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function execute_edit( array $input ) {
		return wp_get_ability( 'core/edit-content' )->execute( $input );
	}

	/**
	 * The edit ability registers next to the read ability with write-oriented annotations.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_edit_content_ability(): void {
		$this->register_ability();

		$this->assertTrue( wp_has_ability( 'core/read-content' ), 'The read ability should still be registered alongside the edit ability.' );

		$ability = wp_get_ability( 'core/edit-content' );

		$this->assertNotNull( $ability, 'The core/edit-content ability should be registered.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertFalse( $annotations['readonly'], 'The ability should be marked as a write.' );
		$this->assertTrue( $annotations['destructive'], 'Replacing text is not additive, so the ability should be marked destructive.' );
		$this->assertFalse( $annotations['idempotent'], 'Repeating a call is not guaranteed to be a no-op.' );
		$this->assertFalse( $annotations['open_world'], 'The ability should be marked closed-world; it only writes the local database.' );

		$schema = $ability->get_input_schema();
		$this->assertSame( array( 'id', 'field', 'old_content', 'new_content' ), $schema['required'], 'The input schema should require the locator, field, and both texts.' );
		$this->assertFalse( $schema['additionalProperties'], 'The input schema should reject unrelated properties.' );
		$this->assertSame( 1, $schema['properties']['expected_matches']['minimum'], 'The expected matches option should require a positive count.' );
		$this->assertArrayNotHasKey( 'default', $schema['properties']['expected_matches'], 'Expected matches should rely on runtime defaults, not schema defaults.' );

		$output = $ability->get_output_schema();
		$this->assertArrayNotHasKey( 'content_raw', $output['properties'], 'The output should not echo the full field value back.' );
		$this->assertContains( 'replaced', $output['required'], 'The output should always report the replacement count.' );
		$this->assertContains( 'exact_persistence', $output['required'], 'The output should always report whether the value persisted exactly.' );
	}

	/**
	 * Read registration alone does not register the write ability.
	 *
	 * The write ability is a separate consent surface: it registers only through its
	 * own gated class, so booting the read path must not expose writes.
	 *
	 * @since x.x.x
	 */
	public function test_read_registration_does_not_register_edit_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Content() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertTrue( wp_has_ability( 'core/read-content' ), 'Read registration should register the read ability.' );
		$this->assertFalse( wp_has_ability( 'core/edit-content' ), 'Read registration should not register the write ability.' );
	}

	/**
	 * The edit ability is not registered when no post types are exposed to it.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_core_edit_content_ability_without_exposed_post_types(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			$this->assertNotFalse( $object, "Precondition: the {$post_type} post type should exist." );

			$object->show_in_abilities = false;
		}

		$this->register_ability();

		$this->assertFalse( wp_has_ability( 'core/edit-content' ), 'The edit ability should not register without any exposed post types.' );
	}

	/**
	 * A unique snippet in the content is replaced, revisioned, and reported compactly.
	 *
	 * @since x.x.x
	 */
	public function test_replaces_unique_snippet_in_content(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'The quick brown fox jumps over the lazy dog.',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => 'brown fox',
				'new_content' => 'red fox',
			)
		);

		$this->assertIsArray( $result, 'A unique match should be replaced successfully.' );
		$this->assertSame( $post_id, $result['id'], 'The result should identify the edited post.' );
		$this->assertSame( 'post', $result['post_type'], 'The result should report the post type.' );
		$this->assertSame( 'publish', $result['status'], 'The result should report the post status after the edit.' );
		$this->assertSame( 'content', $result['field'], 'The result should report the edited field.' );
		$this->assertSame( 1, $result['replaced'], 'Exactly one occurrence should be replaced.' );
		$this->assertTrue( $result['exact_persistence'], 'The replacement should persist exactly for an administrator.' );
		$this->assertNotSame( '', $result['modified_gmt'], 'The result should report the modified date.' );
		$this->assertArrayNotHasKey( 'content_raw', $result, 'The result should not echo the full field value back.' );

		$this->assertSame(
			'The quick red fox jumps over the lazy dog.',
			get_post( $post_id )->post_content,
			'The stored content should contain the replacement.'
		);

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertNotEmpty( $revisions, 'The edit should create a revision.' );
		$this->assertSame(
			'The quick red fox jumps over the lazy dog.',
			array_shift( $revisions )->post_content,
			'The newest revision should hold the edited content.'
		);
	}

	/**
	 * A unique snippet in the title is replaced.
	 *
	 * @since x.x.x
	 */
	public function test_replaces_unique_snippet_in_title(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Hello World Sample',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'title',
				'old_content' => 'World',
				'new_content' => 'Universe',
			)
		);

		$this->assertIsArray( $result, 'A unique title match should be replaced successfully.' );
		$this->assertSame( 1, $result['replaced'], 'Exactly one occurrence should be replaced.' );
		$this->assertSame( 'Hello Universe Sample', get_post( $post_id )->post_title, 'The stored title should contain the replacement.' );
	}

	/**
	 * A unique snippet in the excerpt is replaced.
	 *
	 * @since x.x.x
	 */
	public function test_replaces_unique_snippet_in_excerpt(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => 'A short summary of the article.',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'excerpt',
				'old_content' => 'short summary',
				'new_content' => 'brief overview',
			)
		);

		$this->assertIsArray( $result, 'A unique excerpt match should be replaced successfully.' );
		$this->assertSame( 1, $result['replaced'], 'Exactly one occurrence should be replaced.' );
		$this->assertSame( 'A brief overview of the article.', get_post( $post_id )->post_excerpt, 'The stored excerpt should contain the replacement.' );
	}

	/**
	 * A snippet that does not occur in the field is refused and the post is unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_no_match_is_refused_and_post_unchanged(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Nothing to see here.',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => 'unicorns',
				'new_content' => 'horses',
			)
		);

		$this->assertWPError( $result, 'A snippet with zero occurrences should be refused.' );
		$this->assertSame( 'content_no_match', $result->get_error_code(), 'The refusal should use the no-match error code.' );
		$this->assertSame( 'Nothing to see here.', get_post( $post_id )->post_content, 'The stored content should be unchanged.' );
	}

	/**
	 * Multiple matches with the default expected count of one are refused with the counts.
	 *
	 * @since x.x.x
	 */
	public function test_multiple_matches_with_default_expectation_are_refused(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'foo alpha foo beta foo',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => 'foo',
				'new_content' => 'bar',
			)
		);

		$this->assertWPError( $result, 'An ambiguous snippet should be refused.' );
		$this->assertSame( 'content_match_count_mismatch', $result->get_error_code(), 'The refusal should use the count-mismatch error code.' );
		$this->assertStringContainsString( '3', $result->get_error_message(), 'The error message should report the actual match count.' );

		$data = $result->get_error_data();
		$this->assertSame( 3, $data['found'], 'The error data should report the actual match count.' );
		$this->assertSame( 1, $data['expected'], 'The error data should report the expected match count.' );
		$this->assertSame( 'foo alpha foo beta foo', get_post( $post_id )->post_content, 'The stored content should be unchanged.' );
	}

	/**
	 * A matching expected count replaces every occurrence.
	 *
	 * @since x.x.x
	 */
	public function test_expected_matches_replaces_every_occurrence(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'foo alpha foo beta foo',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'               => $post_id,
				'field'            => 'content',
				'old_content'      => 'foo',
				'new_content'      => 'bar',
				'expected_matches' => 3,
			)
		);

		$this->assertIsArray( $result, 'A matching expected count should allow the replacement.' );
		$this->assertSame( 3, $result['replaced'], 'Every occurrence should be replaced.' );
		$this->assertSame( 'bar alpha bar beta bar', get_post( $post_id )->post_content, 'The stored content should contain every replacement.' );
	}

	/**
	 * An expected count that does not equal the actual count is refused.
	 *
	 * @since x.x.x
	 */
	public function test_wrong_expected_matches_is_refused(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'foo alpha foo beta foo',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'               => $post_id,
				'field'            => 'content',
				'old_content'      => 'foo',
				'new_content'      => 'bar',
				'expected_matches' => 2,
			)
		);

		$this->assertWPError( $result, 'A stale expected count should be refused.' );
		$this->assertSame( 'content_match_count_mismatch', $result->get_error_code(), 'The refusal should use the count-mismatch error code.' );
		$this->assertSame( 'foo alpha foo beta foo', get_post( $post_id )->post_content, 'The stored content should be unchanged.' );
	}

	/**
	 * A role without edit access to the post is denied and the post is unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_without_edit_cap_is_denied(): void {
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_author'  => self::$user_ids['administrator'],
				'post_status'  => 'publish',
				'post_content' => 'Public body text.',
			)
		);

		$this->login_as( 'subscriber' );

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => 'Public',
				'new_content' => 'Hacked',
			)
		);

		$this->assertWPError( $result, 'A user without edit access should be denied.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'The denial should fail closed as a permission error.' );
		$this->assertSame( 'Public body text.', get_post( $post_id )->post_content, 'The stored content should be unchanged.' );
	}

	/**
	 * A post from a post type not exposed to abilities is denied.
	 *
	 * @since x.x.x
	 */
	public function test_unexposed_post_type_is_denied(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		try {
			$this->login_as( 'administrator' );

			$post_id = self::factory()->post->create(
				array(
					'post_type'    => 'wpai_hidden_cpt',
					'post_status'  => 'publish',
					'post_content' => 'Hidden body text.',
				)
			);

			$this->register_ability();

			$result = $this->execute_edit(
				array(
					'id'          => $post_id,
					'field'       => 'content',
					'old_content' => 'Hidden',
					'new_content' => 'Visible',
				)
			);

			$this->assertWPError( $result, 'A post of an unexposed post type should be denied.' );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'The denial should fail closed as a permission error.' );
			$this->assertSame( 'Hidden body text.', get_post( $post_id )->post_content, 'The stored content should be unchanged.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * Editing a field the post type does not support is refused.
	 *
	 * @since x.x.x
	 */
	public function test_unsupported_field_is_refused(): void {
		register_post_type(
			'wpai_title_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title' ),
			)
		);

		try {
			$this->login_as( 'administrator' );

			$post_id = self::factory()->post->create(
				array(
					'post_type'    => 'wpai_title_cpt',
					'post_status'  => 'publish',
					'post_content' => 'Unsupported body text.',
				)
			);

			$this->register_ability();

			$result = $this->execute_edit(
				array(
					'id'          => $post_id,
					'field'       => 'content',
					'old_content' => 'Unsupported',
					'new_content' => 'Supported',
				)
			);

			$this->assertWPError( $result, 'Editing a field without post type support should be refused.' );
			$this->assertSame( 'content_field_not_supported', $result->get_error_code(), 'The refusal should use the unsupported-field error code.' );
			$this->assertSame( 'Unsupported body text.', get_post( $post_id )->post_content, 'The stored content should be unchanged.' );
		} finally {
			unregister_post_type( 'wpai_title_cpt' );
		}
	}

	/**
	 * A field whose entire stored value is serialized data is refused.
	 *
	 * @since x.x.x
	 */
	public function test_serialized_value_is_refused(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$serialized = 'a:1:{i:0;s:5:"hello";}';
		$post_id    = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $serialized,
			)
		);
		$this->assertSame( $serialized, get_post( $post_id )->post_content, 'Precondition: the serialized value should be stored verbatim.' );

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => 'hello',
				'new_content' => 'world',
			)
		);

		$this->assertWPError( $result, 'A serialized stored value should be refused.' );
		$this->assertSame( 'content_serialized', $result->get_error_code(), 'The refusal should use the serialized error code.' );
		$this->assertSame( $serialized, get_post( $post_id )->post_content, 'The stored content should be unchanged.' );
	}

	/**
	 * Dollar signs and backslashes in the replacement are stored literally.
	 *
	 * @since x.x.x
	 */
	public function test_dollar_and_backslash_in_new_content_are_stored_literally(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'The price is PLACEHOLDER today.',
			)
		);

		$new = 'exactly $1.50 (group $0, path C:\temp, double \\ backslash)';

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => 'PLACEHOLDER',
				'new_content' => $new,
			)
		);

		$this->assertIsArray( $result, 'A replacement containing $ and \\ should succeed.' );
		$this->assertTrue( $result['exact_persistence'], 'The literal characters should persist exactly.' );
		$this->assertSame(
			"The price is {$new} today.",
			get_post( $post_id )->post_content,
			'Dollar signs and backslashes in the replacement should be stored byte-for-byte.'
		);
	}

	/**
	 * Dollar signs and backslashes in the snippet are matched literally, not as patterns.
	 *
	 * @since x.x.x
	 */
	public function test_dollar_and_backslash_in_old_content_are_matched_literally(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$raw     = 'Total is $42.00 in the C:\data folder.';
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => wp_slash( $raw ),
			)
		);
		$this->assertSame( $raw, get_post( $post_id )->post_content, 'Precondition: the backslash content should be stored verbatim.' );

		// A regex would match "Total" via "T.tal"; literal matching must not.
		$probe = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => 'T.tal is',
				'new_content' => 'Sum is',
			)
		);

		$this->assertWPError( $probe, 'Regex metacharacters in the snippet should not act as patterns.' );
		$this->assertSame( 'content_no_match', $probe->get_error_code(), 'A pattern-style snippet should simply not match.' );

		$result = $this->execute_edit(
			array(
				'id'          => $post_id,
				'field'       => 'content',
				'old_content' => '$42.00 in the C:\data',
				'new_content' => '$99.00 in the D:\backup',
			)
		);

		$this->assertIsArray( $result, 'A snippet containing $ and \\ should match literally.' );
		$this->assertSame(
			'Total is $99.00 in the D:\backup folder.',
			get_post( $post_id )->post_content,
			'The literal snippet should be replaced byte-for-byte.'
		);
	}

	/**
	 * An unparseable post ID fails closed instead of resolving against the global post.
	 *
	 * The public callbacks can be invoked directly without schema validation, and
	 * get_post( 0 ) falls back to the global post, so a coerced ID must never reach it.
	 *
	 * @since x.x.x
	 */
	public function test_invalid_id_fails_closed_against_global_post(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Global loop post body.',
			)
		);

		$previous_post   = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating a main-loop context for the fail-closed check.

		try {
			$content = new Content();

			$this->assertFalse(
				$content->check_edit_permission(
					array(
						'id'          => 'abc',
						'field'       => 'content',
						'old_content' => 'Global',
						'new_content' => 'Hacked',
					)
				),
				'An unparseable ID should be denied, not resolved via the global post.'
			);

			$result = $content->execute_edit_content(
				array(
					'id'          => 'abc',
					'field'       => 'content',
					'old_content' => 'Global',
					'new_content' => 'Hacked',
				)
			);

			$this->assertWPError( $result, 'An unparseable ID should fail the lookup.' );
			$this->assertSame( 'content_not_found', $result->get_error_code(), 'The lookup failure should be a structural not-found error.' );
			$this->assertSame( 'Global loop post body.', get_post( $post_id )->post_content, 'The global post should be unchanged.' );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the previous global post context.
			$GLOBALS['post'] = $previous_post;
		}
	}

	/**
	 * A whole-number float expected count is accepted, matching schema validation.
	 *
	 * JSON decoding can deliver an integer as a float (e.g. `3.0`), which the schema's
	 * integer type accepts, so the runtime must accept it too.
	 *
	 * @since x.x.x
	 */
	public function test_expected_matches_accepts_whole_number_float(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'foo alpha foo',
			)
		);

		$result = $this->execute_edit(
			array(
				'id'               => $post_id,
				'field'            => 'content',
				'old_content'      => 'foo',
				'new_content'      => 'bar',
				'expected_matches' => 2.0,
			)
		);

		$this->assertIsArray( $result, 'A whole-number float expected count should be accepted.' );
		$this->assertSame( 2, $result['replaced'], 'Both occurrences should be replaced.' );
		$this->assertSame( 'bar alpha bar', get_post( $post_id )->post_content, 'The stored content should contain both replacements.' );
	}
}
