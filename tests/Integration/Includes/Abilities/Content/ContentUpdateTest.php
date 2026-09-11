<?php
/**
 * Integration tests for the core/content-update Ability provided by the plugin.
 *
 * The cases mirror the update tests of the WordPress core REST posts controller
 * test suite (`Tests_REST_Posts_Controller`), adapted to the ability's input and
 * output shapes.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WordPress\AI\Abilities\Content\Content;

/**
 * Content update ability test case.
 *
 * @since x.x.x
 */
class ContentUpdateTest extends Content_Ability_TestCase {

	/**
	 * A published post owned by the editor, updated by most tests.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private static $post_id;

	/**
	 * The category the current user is forbidden to assign, when set.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private $forbidden_category = 0;

	/**
	 * Creates the shared post for the update ability tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		parent::wpSetUpBeforeClass( $factory );

		self::$post_id = $factory->post->create(
			array(
				'post_author'  => self::$user_ids['editor'],
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
				'post_content' => 'Original content',
				'post_excerpt' => 'Original excerpt',
			)
		);
	}

	/**
	 * Returns an update input with every common field set, like the REST test suite's post data.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $overrides Input values to override or add.
	 * @return array<string, mixed> The ability input.
	 */
	private function post_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'      => self::$post_id,
				'title'   => 'Post Title',
				'content' => 'Post content',
				'excerpt' => 'Post excerpt',
				'status'  => 'publish',
				'author'  => get_current_user_id(),
				'fields'  => array( 'id', 'post_type', 'status', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'title_raw', 'content_raw', 'excerpt_raw', 'author', 'parent' ),
			),
			$overrides
		);
	}

	/**
	 * Updates a post through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function update( array $input ) {
		return $this->execute_ability( 'core/content-update', $input );
	}

	/**
	 * Asserts that an update result describes the updated post.
	 *
	 * The ability counterpart of the REST suite's check_update_post_response().
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result  The ability result.
	 * @param int   $post_id The ID of the post that was updated.
	 * @return \WP_Post The updated post.
	 */
	private function assert_updated_post( $result, int $post_id ): \WP_Post {
		$this->assertIsArray( $result, 'Updating a post should return the updated post.' );
		$this->assertSame( $post_id, $result['id'], 'The returned post should be the updated post.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( \WP_Post::class, $post, 'The updated post should still exist.' );

		return $post;
	}

	/**
	 * Disables UPDATE queries on the posts table so wp_update_post() fails with a database error.
	 *
	 * @since x.x.x
	 *
	 * @param string $query The database query.
	 * @return string The query, broken when it updates the posts table.
	 */
	public function error_update_query( string $query ): string {
		global $wpdb;

		if ( 0 === strpos( $query, "UPDATE `{$wpdb->posts}`" ) ) {
			$query = '],';
		}

		return $query;
	}

	/**
	 * Offers one post template for the tests that need a valid template.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string> The post templates keyed by file name.
	 */
	public function filter_theme_post_templates(): array {
		return array(
			'post-my-test-template.php' => 'My Test Template',
		);
	}

	/**
	 * Revokes the assign_term meta capability for the forbidden category.
	 *
	 * @since x.x.x
	 *
	 * @param string[] $caps    The primitive capabilities.
	 * @param string   $cap     The meta capability being checked.
	 * @param int      $user_id The user ID.
	 * @param mixed[]  $args    The capability arguments.
	 * @return string[] The primitive capabilities.
	 */
	public function revoke_assign_term( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'assign_term' === $cap && isset( $args[0] ) && $this->forbidden_category === $args[0] ) {
			$caps = array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * The ability is registered in the `content` category and flagged as an idempotent write.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_update_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/content-update' );

		$this->assertNotNull( $ability, 'The core/content-update ability should be registered.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'Updating keeps revisions, so the ability is not flagged destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'Repeating the same update leaves the post unchanged.' );
		$this->assertFalse( $annotations['open_world'], 'The ability should be marked closed-world; it only writes to the local database.' );
	}

	/**
	 * The ability is not registered when no post types are exposed to it.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_without_exposed_post_types(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			get_post_type_object( $post_type )->show_in_abilities = false;
		}

		$this->register_ability();

		$this->assertFalse( wp_has_ability( 'core/content-update' ), 'The update ability should not register without any exposed post types.' );
	}

	/**
	 * The input schema requires an ID, accepts a post type guard and the REST writable fields, and rejects unknown properties.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_requires_id_and_mirrors_the_rest_writable_fields(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/content-update' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );

		$expected_keys = array(
			'id',
			'post_type',
			'title',
			'content',
			'excerpt',
			'status',
			'slug',
			'date',
			'date_gmt',
			'author',
			'password',
			'parent',
			'menu_order',
			'comment_status',
			'ping_status',
			'format',
			'featured_media',
			'sticky',
			'template',
			'categories',
			'tags',
			'fields',
		);
		$this->assertSame( $expected_keys, array_keys( $schema['properties'] ), 'The writable fields should mirror the REST posts item schema, with taxonomies under their REST keys.' );
		$this->assertSame( 1, $schema['properties']['id']['minimum'], 'The ID should be a positive integer.' );
		$this->assertSame( array( 'post', 'page' ), $schema['properties']['post_type']['enum'], 'The post type guard should only accept exposed post types.' );

		$create_schema = wp_get_ability( 'core/content-create' )->get_input_schema();
		$this->assertSame( $create_schema['properties']['title'], $schema['properties']['title'], 'The create and update abilities should share their write fields.' );
	}

	/**
	 * Read-only post fields are rejected rather than ignored.
	 *
	 * The REST controller ignores its read-only properties on update; the strict ability
	 * schema rejects them, so a caller never believes it changed something it cannot.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_rejects_readonly_fields(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'       => self::$post_id,
				'modified' => '2010-06-01T02:00:00Z',
				'content'  => 'foo bar baz',
			)
		);

		$this->assertAbilityError( $result, 'ability_invalid_input', 'A read-only field should fail validation.' );
		$this->assertSame( 'Original content', get_post( self::$post_id )->post_content, 'Nothing should be written when validation fails.' );
	}

	/**
	 * The output schema describes a single post with the query ability's fields.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_describes_a_post(): void {
		$this->register_ability();

		$schema       = wp_get_ability( 'core/content-update' )->get_output_schema();
		$query_schema = wp_get_ability( 'core/content-query' )->get_output_schema();

		$this->assertSame( $query_schema['oneOf'][0], $schema, 'The updated post should have the same shape as a queried post.' );
	}

	/**
	 * An editor can update the common fields of a post.
	 *
	 * @since x.x.x
	 */
	public function test_update_item(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$data   = $this->post_data();
		$result = $this->update( $data );

		$post = $this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( $data['title'], $result['title_raw'], 'The returned raw title should match the input.' );
		$this->assertSame( $data['content'], $result['content_raw'], 'The returned raw content should match the input.' );
		$this->assertSame( $data['excerpt'], $result['excerpt_raw'], 'The returned raw excerpt should match the input.' );
		$this->assertSame( $data['title'], $post->post_title, 'The stored title should match the input.' );
		$this->assertSame( $data['content'], $post->post_content, 'The stored content should match the input.' );
		$this->assertSame( $data['excerpt'], $post->post_excerpt, 'The stored excerpt should match the input.' );
	}

	/**
	 * Omitted fields keep their current values.
	 *
	 * @since x.x.x
	 */
	public function test_update_keeps_omitted_fields(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'     => self::$post_id,
				'title'  => 'Only the title',
				'fields' => array( 'id', 'title_raw', 'content_raw', 'excerpt_raw', 'status' ),
			)
		);

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( 'Only the title', $result['title_raw'], 'The title should change.' );
		$this->assertSame( 'Original content', $result['content_raw'], 'The content should be kept.' );
		$this->assertSame( 'Original excerpt', $result['excerpt_raw'], 'The excerpt should be kept.' );
		$this->assertSame( 'publish', $result['status'], 'The status should be kept.' );
	}

	/**
	 * Without a field selection the updated post is returned with the lean default fields.
	 *
	 * @since x.x.x
	 */
	public function test_update_returns_lean_default_fields(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'    => self::$post_id,
				'title' => 'Lean',
			)
		);

		$this->assertIsArray( $result, 'Updating a post should return the updated post.' );
		$this->assertSame( array( 'id', 'post_type', 'status', 'date', 'slug', 'title_rendered' ), array_keys( $result ), 'The default field set should match the query ability.' );
	}

	/**
	 * An update that changes nothing still succeeds, even when repeated.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_no_change(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post  = get_post( self::$post_id );
		$input = array(
			'id'     => self::$post_id,
			'author' => (int) $post->post_author,
		);

		// Run twice to make sure that the update still succeeds even if no DB rows are updated.
		$this->assert_updated_post( $this->update( $input ), self::$post_id );
		$this->assert_updated_post( $this->update( $input ), self::$post_id );
	}

	/**
	 * A null date resets the post date, leaving a draft with a floating GMT date.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_empty_date(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id     = self::factory()->post->create();
		$future_date = '2919-07-29T18:00:00';

		$result = $this->update(
			$this->post_data(
				array(
					'id'       => $post_id,
					'date_gmt' => $future_date,
					'date'     => $future_date,
					'title'    => 'update',
					'status'   => 'draft',
				)
			)
		);

		$this->assert_updated_post( $result, $post_id );
		$this->assertSame( $future_date . '+00:00', $result['date_gmt'], 'The GMT date should be set to the future date.' );
		$this->assertSame( $future_date . '+00:00', $result['date'], 'The date should be set to the future date.' );
		$this->assertNotSame( $result['date_gmt'], $result['modified_gmt'], 'The modified date should differ from the future date.' );
		$this->assertNotSame( $result['date'], $result['modified'], 'The modified date should differ from the future date.' );

		$result = $this->update(
			$this->post_data(
				array(
					'id'       => $post_id,
					'date_gmt' => null,
					'title'    => 'test',
					'status'   => 'draft',
				)
			)
		);

		$this->assert_updated_post( $result, $post_id );
		$this->assertSame( $result['date'], $result['date_gmt'], 'A reset draft derives its GMT date from the local date.' );
		$this->assertNotSame( $future_date . '+00:00', $result['date_gmt'], 'The future GMT date should be gone.' );
		$this->assertNotSame( $future_date . '+00:00', $result['date'], 'The future date should be gone.' );
		$this->assertSame( '0000-00-00 00:00:00', get_post( $post_id )->post_date_gmt, 'The stored GMT date should be reset to the floating value.' );
	}

	/**
	 * A minimal update with only an ID and a title succeeds.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_without_extra_params(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'      => self::$post_id,
				'title'   => 'Post Title',
				'content' => 'Post content',
				'excerpt' => 'Post excerpt',
			)
		);

		$this->assert_updated_post( $result, self::$post_id );
	}

	/**
	 * An editor who cannot edit published posts is denied.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_without_permission(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$this->revoke_current_user_capability( 'edit_published_posts' );

		$result = $this->update( $this->post_data() );

		$this->assertAbilityDenied( $result, 'An editor without edit_published_posts should not update a published post.' );
	}

	/**
	 * Logged-out users, subscribers, and authors editing another user's post are denied.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_by_users_without_edit_access_is_denied(): void {
		$this->register_ability();

		wp_set_current_user( 0 );
		$data = $this->post_data( array( 'title' => 'Nope' ) );
		unset( $data['author'] );
		$this->assertAbilityDenied( $this->update( $data ), 'A logged-out user should not update posts.' );

		$this->login_as( 'subscriber' );
		$this->assertAbilityDenied( $this->update( $this->post_data( array( 'title' => 'Nope' ) ) ), 'A subscriber should not update posts.' );

		$this->login_as( 'author' );
		$this->assertAbilityDenied( $this->update( $this->post_data( array( 'title' => 'Nope' ) ) ), "An author should not update another user's post." );

		$this->assertSame( 'Original title', get_post( self::$post_id )->post_title, 'Denied updates should not write.' );
	}

	/**
	 * An author can update their own draft.
	 *
	 * @since x.x.x
	 */
	public function test_author_can_update_own_draft(): void {
		$author_id = $this->login_as( 'author' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);

		$result = $this->update(
			array(
				'id'     => $post_id,
				'title'  => 'My draft',
				'fields' => array( 'id', 'title_raw' ),
			)
		);

		$this->assert_updated_post( $result, $post_id );
		$this->assertSame( 'My draft', $result['title_raw'], 'The author should be able to update their own draft.' );
	}

	/**
	 * A contributor cannot make their post sticky.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_sticky_as_contributor(): void {
		$contributor_id = $this->login_as( 'contributor' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $contributor_id,
				'post_status' => 'pending',
			)
		);

		$result = $this->update(
			array(
				'id'     => $post_id,
				'sticky' => true,
				'status' => 'pending',
			)
		);

		$this->assertAbilityDenied( $result, 'A contributor should not be allowed to make posts sticky.' );
	}

	/**
	 * A missing post is denied before execution, and a direct call reports it as not found.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_invalid_id(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update( $this->post_data( array( 'id' => 999999 ) ) );
		$this->assertAbilityDenied( $result, 'A missing post should be denied before execution.' );

		$direct = ( new Content() )->execute_content_update( array( 'id' => 999999 ) );
		$this->assertAbilityError( $direct, 'content_not_found', 'A direct call should still fail closed on a missing post.' );
	}

	/**
	 * A post type guard that does not match the post denies the update, like a mismatched REST route.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_invalid_route(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$mismatched = $this->update( $this->post_data( array( 'post_type' => 'page' ) ) );
		$this->assertAbilityDenied( $mismatched, 'A mismatched post type guard should deny the update.' );

		$matching = $this->update( $this->post_data( array( 'post_type' => 'post' ) ) );
		$this->assert_updated_post( $matching, self::$post_id );
	}

	/**
	 * A post from a post type not exposed to abilities cannot be updated.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_for_unexposed_post_type_is_denied(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		try {
			$this->login_as( 'administrator' );
			$this->register_ability();

			$post_id = self::factory()->post->create( array( 'post_type' => 'wpai_hidden_cpt' ) );

			$result = $this->update(
				array(
					'id'    => $post_id,
					'title' => 'Hidden',
				)
			);

			$this->assertAbilityDenied( $result, 'Posts from unexposed post types should be denied.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * Post formats can be set, cleared with the standard format, and are validated.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_format(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$gallery = $this->update( $this->post_data( array( 'format' => 'gallery' ) ) );
		$this->assert_updated_post( $gallery, self::$post_id );
		$this->assertSame( 'gallery', get_post_format( self::$post_id ), 'The gallery format should be assigned.' );

		$standard = $this->update( $this->post_data( array( 'format' => 'standard' ) ) );
		$this->assert_updated_post( $standard, self::$post_id );
		$this->assertFalse( get_post_format( self::$post_id ), 'The standard format should clear the format term.' );

		$invalid = $this->update( $this->post_data( array( 'format' => 'testformat' ) ) );
		$this->assertAbilityError( $invalid, 'ability_invalid_input', 'An unknown format should fail validation.' );

		// A valid format the theme does not support is still assigned, like REST.
		$unsupported = $this->update( $this->post_data( array( 'format' => 'link' ) ) );
		$this->assert_updated_post( $unsupported, self::$post_id );
		$this->assertSame( 'link', get_post_format( self::$post_id ), 'A theme-unsupported format should still be assigned.' );
	}

	/**
	 * Dates are stored in the site timezone with their GMT counterpart, whether given as local, GMT, or with an offset.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_post_dates
	 *
	 * @param string                $status  The status of the post being updated.
	 * @param array<string, string> $params  The timezone and date inputs.
	 * @param array<string, string> $results The expected stored dates.
	 */
	public function test_update_post_date( string $status, array $params, array $results ): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		update_option( 'timezone_string', $params['timezone_string'] );

		$post_id = self::factory()->post->create( array( 'post_status' => $status ) );

		$input = array(
			'id'     => $post_id,
			'fields' => array( 'id', 'date', 'date_gmt' ),
		);
		if ( isset( $params['date'] ) ) {
			$input['date'] = $params['date'];
		}
		if ( isset( $params['date_gmt'] ) ) {
			$input['date_gmt'] = $params['date_gmt'];
		}

		$result = $this->update( $input );

		$post = $this->assert_updated_post( $result, $post_id );
		$this->assertSame( $results['date'], $post->post_date, 'The stored local date should match.' );
		$this->assertSame( $results['date_gmt'], $post->post_date_gmt, 'The stored GMT date should match.' );
		$this->assertSame( str_replace( ' ', 'T', $results['date'] ) . '-05:00', $result['date'], 'The returned local date should carry the site offset.' );
		$this->assertSame( str_replace( ' ', 'T', $results['date_gmt'] ) . '+00:00', $result['date_gmt'], 'The returned GMT date should be the UTC instant.' );
	}

	/**
	 * Invalid dates fail validation.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_invalid_date(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$date = $this->update( $this->post_data( array( 'date' => 'foo' ) ) );
		$this->assertAbilityError( $date, 'ability_invalid_input', 'An invalid date should fail validation.' );

		$date_gmt = $this->update( $this->post_data( array( 'date_gmt' => 'foo' ) ) );
		$this->assertAbilityError( $date_gmt, 'ability_invalid_input', 'An invalid GMT date should fail validation.' );
	}

	/**
	 * Updating the date of a post whose stored GMT date is empty sets both dates.
	 *
	 * @since x.x.x
	 */
	public function test_empty_post_date_gmt_shimmed_using_post_date(): void {
		global $wpdb;

		$this->login_as( 'editor' );
		$this->register_ability();

		update_option( 'timezone_string', 'America/Chicago' );

		// Set the dates through wpdb, because wp_insert_post() and wp_update_post() validate them.
		$post_id = self::factory()->post->create();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->posts,
			array(
				'post_date'     => '2016-02-23 12:00:00',
				'post_date_gmt' => '0000-00-00 00:00:00',
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		wp_cache_delete( $post_id, 'posts' );

		$post = get_post( $post_id );
		$this->assertSame( '2016-02-23 12:00:00', $post->post_date, 'Precondition: the local date is set.' );
		$this->assertSame( '0000-00-00 00:00:00', $post->post_date_gmt, 'Precondition: the GMT date is empty.' );

		$result = $this->update(
			array(
				'id'     => $post_id,
				'date'   => '2016-02-23T13:00:00',
				'fields' => array( 'id', 'date', 'date_gmt' ),
			)
		);

		$post = $this->assert_updated_post( $result, $post_id );
		$this->assertSame( '2016-02-23T13:00:00-06:00', $result['date'], 'The returned local date should match the input.' );
		$this->assertSame( '2016-02-23T19:00:00+00:00', $result['date_gmt'], 'The returned GMT date should be derived from the input.' );
		$this->assertSame( '2016-02-23 13:00:00', $post->post_date, 'The stored local date should match the input.' );
		$this->assertSame( '2016-02-23 19:00:00', $post->post_date_gmt, 'The stored GMT date should be set.' );
	}

	/**
	 * The slug is stored and sanitized like the REST slug argument.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_slug(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update( $this->post_data( array( 'slug' => 'sample-slug' ) ) );
		$post   = $this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( 'sample-slug', $result['slug'], 'The returned slug should match the input.' );
		$this->assertSame( 'sample-slug', $post->post_name, 'The stored slug should match the input.' );

		$accented = $this->update( $this->post_data( array( 'slug' => 'tęst-acceńted-chäræcters' ) ) );
		$post     = $this->assert_updated_post( $accented, self::$post_id );
		$this->assertSame( 'test-accented-charaecters', $accented['slug'], 'The returned slug should be sanitized.' );
		$this->assertSame( 'test-accented-charaecters', $post->post_name, 'The stored slug should be sanitized.' );
	}

	/**
	 * A draft slug that collides with another post is made unique.
	 *
	 * @since x.x.x
	 */
	public function test_draft_post_does_not_have_the_same_slug_as_existing_post(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		self::factory()->post->create( array( 'post_name' => 'sample-slug' ) );

		$result = $this->update(
			$this->post_data(
				array(
					'status' => 'draft',
					'slug'   => 'sample-slug',
				)
			)
		);

		$post = $this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( 'sample-slug-2', $result['slug'], 'The returned slug should be made unique.' );
		$this->assertSame( 'draft', $post->post_status, 'The post should be a draft.' );
		$this->assertSame( 'sample-slug-2', $post->post_name, 'The stored slug should be made unique.' );
	}

	/**
	 * Sticky can be set, survives unrelated updates, and can be unset.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_sticky(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$this->assert_updated_post( $this->update( $this->post_data( array( 'sticky' => true ) ) ), self::$post_id );
		$this->assertTrue( is_sticky( self::$post_id ), 'The post should be sticky.' );

		// Updating another field shouldn't change sticky status.
		$this->assert_updated_post( $this->update( $this->post_data( array( 'title' => 'This should not reset sticky' ) ) ), self::$post_id );
		$this->assertTrue( is_sticky( self::$post_id ), 'The post should stay sticky.' );

		$this->assert_updated_post( $this->update( $this->post_data( array( 'sticky' => false ) ) ), self::$post_id );
		$this->assertFalse( is_sticky( self::$post_id ), 'The post should no longer be sticky.' );
	}

	/**
	 * The excerpt and content can be set and emptied.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_excerpt_and_content(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$fields = array( 'id', 'excerpt_raw', 'content_raw' );

		$excerpt = $this->update(
			array(
				'id'      => self::$post_id,
				'excerpt' => 'An Excerpt',
				'fields'  => $fields,
			)
		);
		$this->assertSame( 'An Excerpt', $excerpt['excerpt_raw'], 'The excerpt should be set.' );

		$empty_excerpt = $this->update(
			array(
				'id'      => self::$post_id,
				'excerpt' => '',
				'fields'  => $fields,
			)
		);
		$this->assertSame( '', $empty_excerpt['excerpt_raw'], 'The excerpt should be emptied.' );

		$content = $this->update(
			array(
				'id'      => self::$post_id,
				'content' => 'Some Content',
				'fields'  => $fields,
			)
		);
		$this->assertSame( 'Some Content', $content['content_raw'], 'The content should be set.' );

		$empty_content = $this->update(
			array(
				'id'      => self::$post_id,
				'content' => '',
				'fields'  => $fields,
			)
		);
		$this->assertSame( '', $empty_content['content_raw'], 'The content should be emptied.' );
	}

	/**
	 * An empty password removes the password.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_empty_password(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		wp_update_post(
			array(
				'ID'            => self::$post_id,
				'post_password' => 'foo',
			)
		);

		$result = $this->update( $this->post_data( array( 'password' => '' ) ) );

		$post = $this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( '', $post->post_password, 'The password should be removed.' );
	}

	/**
	 * A post cannot be both sticky and password protected, in either order.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_password_and_sticky_fails(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$both = $this->update(
			$this->post_data(
				array(
					'password' => '123',
					'sticky'   => true,
				)
			)
		);
		$this->assertAbilityError( $both, 'content_invalid_field', 'A post cannot become sticky and password protected at once.' );

		stick_post( self::$post_id );
		$password_on_sticky = $this->update( $this->post_data( array( 'password' => '123' ) ) );
		$this->assertAbilityError( $password_on_sticky, 'content_invalid_field', 'A sticky post cannot be password protected.' );
		unstick_post( self::$post_id );

		wp_update_post(
			array(
				'ID'            => self::$post_id,
				'post_password' => '123',
			)
		);
		$sticky_on_protected = $this->update( $this->post_data( array( 'sticky' => true ) ) );
		$this->assertAbilityError( $sticky_on_protected, 'content_invalid_field', 'A password protected post cannot be made sticky.' );
	}

	/**
	 * Quotes survive the slashing round trip.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_quotes_in_title(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update( $this->post_data( array( 'title' => "Rob O'Rourke's Diary" ) ) );

		$post = $this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( "Rob O'Rourke's Diary", $result['title_raw'], 'The raw title should keep its quotes.' );
		$this->assertSame( "Rob O'Rourke's Diary", $post->post_title, 'The stored title should keep its quotes.' );
	}

	/**
	 * Categories replace the current ones, and an empty list removes them all.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_categories(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$category = wp_insert_term( 'Test Category', 'category' );

		$result = $this->update(
			$this->post_data(
				array(
					'title'      => 'Tester',
					'categories' => array( $category['term_id'] ),
				)
			)
		);
		$this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( array( $category['term_id'] ), wp_get_post_categories( self::$post_id ), 'The category should replace the default one.' );

		$cleared = $this->update(
			$this->post_data(
				array(
					'title'      => 'Tester',
					'categories' => array(),
				)
			)
		);
		$this->assert_updated_post( $cleared, self::$post_id );
		$this->assertSame( array(), wp_get_post_categories( self::$post_id ), 'An empty list should remove every category.' );
	}

	/**
	 * Tags are assigned under the REST `tags` key.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_tags(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$tag = wp_insert_term( 'Test Tag', 'post_tag' );

		$result = $this->update( $this->post_data( array( 'tags' => array( $tag['term_id'] ) ) ) );

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( array( $tag['term_id'] ), wp_get_post_tags( self::$post_id, array( 'fields' => 'ids' ) ), 'The tag should be assigned.' );
	}

	/**
	 * Terms the current user cannot assign deny the whole request.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_categories_that_cannot_be_assigned_by_current_user(): void {
		$categories               = self::factory()->category->create_many( 2 );
		$this->forbidden_category = $categories[1];

		$this->login_as( 'editor' );
		$this->register_ability();

		add_filter( 'map_meta_cap', array( $this, 'revoke_assign_term' ), 10, 4 );
		try {
			$result = $this->update(
				$this->post_data(
					array(
						'password'   => 'testing',
						'categories' => $categories,
					)
				)
			);
		} finally {
			remove_filter( 'map_meta_cap', array( $this, 'revoke_assign_term' ), 10 );
		}

		$this->assertAbilityDenied( $result, 'Terms the user cannot assign should deny the request.' );
	}

	/**
	 * A taxonomy that is not registered for the post type is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_update_page_with_categories_is_rejected(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$page_id  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$category = wp_insert_term( 'Page Category', 'category' );

		$result = $this->update(
			array(
				'id'         => $page_id,
				'categories' => array( $category['term_id'] ),
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_field', 'Categories should be rejected for pages.' );
	}

	/**
	 * A template offered by the theme is assigned, and an empty template clears it.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_template(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		add_filter( 'theme_post_templates', array( $this, 'filter_theme_post_templates' ) );
		try {
			$result = $this->update( $this->post_data( array( 'template' => 'post-my-test-template.php' ) ) );
			$this->assert_updated_post( $result, self::$post_id );
			$this->assertSame( 'post-my-test-template.php', get_page_template_slug( self::$post_id ), 'The template should be stored on the post.' );

			$none = $this->update( $this->post_data( array( 'template' => '' ) ) );
			$this->assert_updated_post( $none, self::$post_id );
			$this->assertSame( '', get_page_template_slug( self::$post_id ), 'An empty template should clear the stored template.' );
		} finally {
			remove_filter( 'theme_post_templates', array( $this, 'filter_theme_post_templates' ) );
		}
	}

	/**
	 * A template the theme does not offer is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_invalid_template(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update( $this->post_data( array( 'template' => 'post-my-test-template.php' ) ) );

		$this->assertAbilityError( $result, 'content_invalid_template', 'An unavailable template should be rejected.' );
	}

	/**
	 * Keeping the template a post already uses is allowed even when the theme no longer offers it.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_same_template_that_no_longer_exists(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		update_post_meta( self::$post_id, '_wp_page_template', 'post-my-invalid-template.php' );

		$result = $this->update( $this->post_data( array( 'template' => 'post-my-invalid-template.php' ) ) );

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( 'post-my-invalid-template.php', get_page_template_slug( self::$post_id ), 'The existing template should be kept.' );
	}

	/**
	 * Changing the status to one that requires publishing is gated by the publish capability.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_status_requires_publish_capability(): void {
		$contributor_id = $this->login_as( 'contributor' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $contributor_id,
				'post_status' => 'pending',
			)
		);

		$publish = $this->update(
			array(
				'id'     => $post_id,
				'status' => 'publish',
			)
		);
		$this->assertAbilityError( $publish, 'content_cannot_publish', 'A contributor should not publish.' );

		$private = $this->update(
			array(
				'id'     => $post_id,
				'status' => 'private',
			)
		);
		$this->assertAbilityError( $private, 'content_cannot_publish', 'A contributor should not make posts private.' );

		// Sending the current status is always allowed, like the REST status validation.
		$same = $this->update(
			array(
				'id'     => $post_id,
				'status' => 'pending',
				'title'  => 'Still pending',
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);
		$this->assert_updated_post( $same, $post_id );
		$this->assertSame( 'pending', $same['status'], 'The current status should be kept.' );
		$this->assertSame( 'Still pending', $same['title_raw'], 'The title should be updated.' );
	}

	/**
	 * An editor can publish a draft.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_publishes_a_draft(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$result = $this->update(
			array(
				'id'     => $post_id,
				'status' => 'publish',
				'fields' => array( 'id', 'status' ),
			)
		);

		$post = $this->assert_updated_post( $result, $post_id );
		$this->assertSame( 'publish', $result['status'], 'The returned status should be publish.' );
		$this->assertSame( 'publish', $post->post_status, 'The stored status should be publish.' );
	}

	/**
	 * The author can be reassigned by users who can edit others' posts, and is validated.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_author(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$reassigned = $this->update( $this->post_data( array( 'author' => self::$user_ids['author'] ) ) );
		$post       = $this->assert_updated_post( $reassigned, self::$post_id );
		$this->assertSame( self::$user_ids['author'], (int) $post->post_author, 'An editor should be able to reassign the author.' );
		$this->assertSame( self::$user_ids['author'], $reassigned['author']['id'], 'The returned author should be the new one.' );

		$missing = $this->update( $this->post_data( array( 'author' => 999999 ) ) );
		$this->assertAbilityError( $missing, 'content_invalid_author', 'A nonexistent author should be rejected.' );

		$author_id = $this->login_as( 'author' );
		$own_post  = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);
		$to_other  = $this->update(
			array(
				'id'     => $own_post,
				'author' => self::$user_ids['author_secondary'],
			)
		);
		$this->assertAbilityDenied( $to_other, 'An author should not reassign a post to another user.' );
	}

	/**
	 * A page can be moved under a parent, and the parent is validated.
	 *
	 * @since x.x.x
	 */
	public function test_update_page_parent(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$parent_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$page_id   = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->update(
			array(
				'id'     => $page_id,
				'parent' => $parent_id,
				'fields' => array( 'id', 'parent' ),
			)
		);
		$this->assert_updated_post( $result, $page_id );
		$this->assertSame( $parent_id, $result['parent'], 'The returned parent should match.' );
		$this->assertSame( $parent_id, (int) get_post( $page_id )->post_parent, 'The stored parent should match.' );

		$top_level = $this->update(
			array(
				'id'     => $page_id,
				'parent' => 0,
				'fields' => array( 'id', 'parent' ),
			)
		);
		$this->assert_updated_post( $top_level, $page_id );
		$this->assertSame( 0, $top_level['parent'], 'A zero parent should make the page top-level.' );

		$invalid = $this->update(
			array(
				'id'     => $page_id,
				'parent' => 999999,
			)
		);
		$this->assertAbilityError( $invalid, 'content_invalid_parent', 'A nonexistent parent should be rejected.' );
	}

	/**
	 * Page attributes and comment settings are stored.
	 *
	 * @since x.x.x
	 */
	public function test_update_page_with_menu_order_and_comment_settings(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->update(
			array(
				'id'             => $page_id,
				'menu_order'     => 3,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		$post = $this->assert_updated_post( $result, $page_id );
		$this->assertSame( 3, $post->menu_order, 'The menu order should be stored.' );
		$this->assertSame( 'closed', $post->comment_status, 'The comment status should be stored.' );
		$this->assertSame( 'closed', $post->ping_status, 'The ping status should be stored.' );
	}

	/**
	 * Provides fields that a post type does not support.
	 *
	 * @return array<string, array{0: string, 1: string, 2: mixed}> Post type, field, and value.
	 */
	public function data_unsupported_fields(): array {
		return array(
			'parent on a post'     => array( 'post', 'parent', 0 ),
			'menu order on a post' => array( 'post', 'menu_order', 1 ),
			'sticky on a page'     => array( 'page', 'sticky', true ),
			'format on a page'     => array( 'page', 'format', 'aside' ),
		);
	}

	/**
	 * Fields the post type does not support are rejected rather than silently ignored.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_unsupported_fields
	 *
	 * @param string $post_type The post type of the post to update.
	 * @param string $field     The unsupported field.
	 * @param mixed  $value     A valid value for the field.
	 */
	public function test_update_rejects_unsupported_fields( string $post_type, string $field, $value ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_type' => $post_type ) );

		$result = $this->update(
			array(
				'id'   => $post_id,
				$field => $value,
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_field', "The {$field} field should be rejected for the {$post_type} post type." );
	}

	/**
	 * A featured image can be assigned, removed, and is validated.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_featured_media(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$attachment_id = self::factory()->attachment->create_object(
			DIR_TESTDATA . '/images/canola.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'menu_order'     => 1,
			)
		);

		$set = $this->update( $this->post_data( array( 'featured_media' => $attachment_id ) ) );
		$this->assert_updated_post( $set, self::$post_id );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( self::$post_id ), 'The attachment should be the post thumbnail.' );

		$removed = $this->update( $this->post_data( array( 'featured_media' => 0 ) ) );
		$this->assert_updated_post( $removed, self::$post_id );
		$this->assertSame( 0, (int) get_post_thumbnail_id( self::$post_id ), 'The post thumbnail should be removed.' );

		$invalid = $this->update( $this->post_data( array( 'featured_media' => 999999 ) ) );
		$this->assertAbilityError( $invalid, 'content_invalid_featured_media', 'An invalid featured media ID should be reported.' );
	}

	/**
	 * The core post insertion hook fires with the previous post.
	 *
	 * @since x.x.x
	 */
	public function test_update_fires_wp_after_insert_post(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$calls    = array();
		$callback = static function ( $post_id, $post, $update, $post_before ) use ( &$calls ): void {
			$calls[] = array( $post_id, $update, $post_before );
		};

		add_action( 'wp_after_insert_post', $callback, 10, 4 );
		try {
			$result = $this->update( $this->post_data( array( 'title' => 'Hooked' ) ) );
		} finally {
			remove_action( 'wp_after_insert_post', $callback, 10 );
		}

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertNotEmpty( $calls, 'wp_after_insert_post should fire.' );
		$last = end( $calls );
		$this->assertSame( self::$post_id, $last[0], 'The hook should receive the updated post.' );
		$this->assertTrue( $last[1], 'The hook should report an update.' );
		$this->assertInstanceOf( \WP_Post::class, $last[2], 'The hook should receive the previous post.' );
		$this->assertSame( 'Original title', $last[2]->post_title, 'The previous post should carry the old values.' );
	}

	/**
	 * Sending a draft's current date back does not remove its floating GMT date.
	 *
	 * @since x.x.x
	 */
	public function test_putting_same_publish_date_does_not_remove_floating_date(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$time = gmdate( 'Y-m-d H:i:s' );
		$post = self::factory()->post->create_and_get(
			array(
				'post_status' => 'draft',
				'post_date'   => $time,
			)
		);
		$this->assertSame( '0000-00-00 00:00:00', $post->post_date_gmt, 'Precondition: the draft has a floating GMT date.' );

		$read = $this->execute_ability(
			'core/content-query',
			array(
				'id'     => $post->ID,
				'fields' => array( 'id', 'date', 'date_gmt', 'title_raw', 'content_raw', 'status' ),
			)
		);

		$result = $this->update(
			array(
				'id'      => $post->ID,
				'date'    => $read['date'],
				'title'   => $read['title_raw'],
				'content' => $read['content_raw'],
				'status'  => $read['status'],
				'fields'  => array( 'id', 'date', 'date_gmt' ),
			)
		);

		$this->assert_updated_post( $result, $post->ID );
		$this->assertEqualsWithDelta( strtotime( $read['date'] ), strtotime( $result['date'] ), 2, 'The dates should be equal.' );
		$this->assertEqualsWithDelta( strtotime( $read['date_gmt'] ), strtotime( $result['date_gmt'] ), 2, 'The GMT dates should be equal.' );
		$this->assertSame( '0000-00-00 00:00:00', get_post( $post->ID )->post_date_gmt, 'The floating GMT date should be kept.' );
	}

	/**
	 * Sending a different date removes a draft's floating GMT date.
	 *
	 * @since x.x.x
	 */
	public function test_putting_different_publish_date_removes_floating_date(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$time     = gmdate( 'Y-m-d H:i:s' );
		$new_time = gmdate( 'Y-m-d H:i:s', strtotime( '+1 week' ) );
		$post     = self::factory()->post->create_and_get(
			array(
				'post_status' => 'draft',
				'post_date'   => $time,
			)
		);
		$this->assertSame( '0000-00-00 00:00:00', $post->post_date_gmt, 'Precondition: the draft has a floating GMT date.' );

		$result = $this->update(
			array(
				'id'     => $post->ID,
				'date'   => mysql_to_rfc3339( $new_time ),
				'fields' => array( 'id', 'date' ),
			)
		);

		$this->assert_updated_post( $result, $post->ID );
		$this->assertEqualsWithDelta( strtotime( mysql_to_rfc3339( $new_time ) ), strtotime( $result['date'] ), 2, 'The dates should be equal.' );
		$this->assertNotSame( '0000-00-00 00:00:00', get_post( $post->ID )->post_date_gmt, 'The floating GMT date should be replaced.' );
	}

	/**
	 * Publishing a draft with its current date removes the floating GMT date.
	 *
	 * @since x.x.x
	 */
	public function test_publishing_post_with_same_date_removes_floating_date(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$time = gmdate( 'Y-m-d H:i:s' );
		$post = self::factory()->post->create_and_get(
			array(
				'post_status' => 'draft',
				'post_date'   => $time,
			)
		);
		$this->assertSame( '0000-00-00 00:00:00', $post->post_date_gmt, 'Precondition: the draft has a floating GMT date.' );

		$read = $this->execute_ability(
			'core/content-query',
			array(
				'id'     => $post->ID,
				'fields' => array( 'id', 'date', 'date_gmt' ),
			)
		);

		$result = $this->update(
			array(
				'id'     => $post->ID,
				'date'   => $read['date'],
				'status' => 'publish',
				'fields' => array( 'id', 'date', 'date_gmt' ),
			)
		);

		$this->assert_updated_post( $result, $post->ID );
		$this->assertEqualsWithDelta( strtotime( $read['date'] ), strtotime( $result['date'] ), 2, 'The dates should be equal.' );
		$this->assertEqualsWithDelta( strtotime( $read['date_gmt'] ), strtotime( $result['date_gmt'] ), 2, 'The GMT dates should be equal.' );
		$this->assertNotSame( '0000-00-00 00:00:00', get_post( $post->ID )->post_date_gmt, 'Publishing should set the GMT date.' );
	}

	/**
	 * A database failure surfaces as the update error with a server error status.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_db_error(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		global $wpdb;
		$wpdb->suppress_errors = true;
		add_filter( 'query', array( $this, 'error_update_query' ) );
		try {
			$result = $this->update( $this->post_data() );
		} finally {
			remove_filter( 'query', array( $this, 'error_update_query' ) );
			$wpdb->suppress_errors = false;
		}

		$this->assertAbilityError( $result, 'db_update_error', 'A failed update should surface the database error.' );
		$this->assertSame( 500, $result->get_error_data()['status'], 'A database error should be a server error.' );
	}
}
