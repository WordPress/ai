<?php
/**
 * Integration tests for the core/content-update Ability provided by the plugin.
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
	private static int $post_id = 0;

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
	 * Returns an update input with every common field set.
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
	 * The ability is registered as a closed-world destructive write that is not idempotent,
	 * takes an ID plus the create ability's fields, and returns a post shaped like a queried
	 * one.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_update_ability(): void {
		$this->register_ability();

		$ability       = wp_get_ability( 'core/content-update' );
		$annotations   = $ability->get_meta_item( 'annotations', array() );
		$schema        = $ability->get_input_schema();
		$create_schema = wp_get_ability( 'core/content-create' )->get_input_schema();

		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Updating overwrites post fields, so the ability is flagged destructive.' );
		$this->assertFalse( $annotations['idempotent'], 'Every update touches the modified date, and the ability must stay on the POST method.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array_merge( array( 'id' ), array_keys( $create_schema['properties'] ) ), array_keys( $schema['properties'] ), 'The update should take an ID and the create ability\'s fields.' );
		$this->assertArrayNotHasKey( 'enum', $schema['properties']['status'], 'A post may keep an internal status, so the status is validated during execution.' );
		$this->assertSame( wp_get_ability( 'core/content-query' )->get_output_schema()['oneOf'][0], $ability->get_output_schema(), 'The updated post should have the same shape as a queried post.' );
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
	 * A post type guard that does not match the post denies the update.
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
		$this->register_test_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

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
	 * The slug is stored and sanitized like a title.
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
	 * The core post insertion hook fires once for the updated post, with the previous post.
	 *
	 * @since x.x.x
	 */
	public function test_update_fires_wp_after_insert_post(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		// The revision saved on update fires the hook too, so the calls are grouped by post ID.
		$calls    = array();
		$callback = static function ( $post_id, $post, $update, $post_before ) use ( &$calls ): void {
			$calls[ $post_id ][] = array( $update, $post_before );
		};

		add_action( 'wp_after_insert_post', $callback, 10, 4 );
		$result = $this->update( $this->post_data( array( 'title' => 'Hooked' ) ) );

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertCount( 1, $calls[ self::$post_id ] ?? array(), 'wp_after_insert_post should fire once for the updated post.' );
		[ $update, $post_before ] = $calls[ self::$post_id ][0];
		$this->assertTrue( $update, 'The hook should report an update.' );
		$this->assertInstanceOf( \WP_Post::class, $post_before, 'The hook should receive the previous post.' );
		$this->assertSame( 'Original title', $post_before->post_title, 'The previous post should carry the old values.' );
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
	 * An empty raw object clears the field exactly like an empty string.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_empty_raw_objects(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$fields = array( 'id', 'title_raw', 'content_raw', 'excerpt_raw' );

		$result = $this->update(
			array(
				'id'      => self::$post_id,
				'content' => array( 'raw' => '' ),
				'excerpt' => array( 'raw' => '' ),
				'fields'  => $fields,
			)
		);

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( 'Original title', $result['title_raw'], 'An omitted title should be kept.' );
		$this->assertSame( '', $result['content_raw'], 'An empty raw content should clear the content.' );
		$this->assertSame( '', $result['excerpt_raw'], 'An empty raw excerpt should clear the excerpt.' );

		$result = $this->update(
			array(
				'id'      => self::$post_id,
				'title'   => array( 'raw' => '' ),
				'content' => 'Kept so the post is not empty',
				'fields'  => $fields,
			)
		);

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( '', $result['title_raw'], 'An empty raw title should clear the title, like an empty string.' );
	}

	/**
	 * A post keeps its current status even when that status is internal, such as trash.
	 *
	 * @since x.x.x
	 */
	public function test_update_trashed_post_keeps_its_status(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_title' => 'In the trash' ) );
		wp_trash_post( $post_id );

		$result = $this->update(
			array(
				'id'     => $post_id,
				'status' => 'trash',
				'title'  => 'Fixed while trashed',
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$post = $this->assert_updated_post( $result, $post_id );
		$this->assertSame( 'trash', $result['status'], 'The trashed post should keep its status.' );
		$this->assertSame( 'Fixed while trashed', $post->post_title, 'The title should be updated.' );
	}

	/**
	 * A status that is not registered, or is internal, cannot be set.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_invalid_status(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$unknown = $this->update( $this->post_data( array( 'status' => 'teststatus' ) ) );
		$this->assertAbilityError( $unknown, 'content_invalid_status', 'An unknown status should be rejected.' );

		$internal = $this->update( $this->post_data( array( 'status' => 'trash' ) ) );
		$this->assertAbilityError( $internal, 'content_invalid_status', 'A post cannot be moved to the trash through an update.' );
		$this->assertSame( 'publish', get_post( self::$post_id )->post_status, 'The post should keep its status.' );
	}

	/**
	 * A draft child page's slug is made unique among its siblings, not among top-level pages.
	 *
	 * @since x.x.x
	 */
	public function test_draft_child_page_slug_is_unique_among_siblings(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$parent_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
				'post_name'   => 'team',
			)
		);
		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
				'post_status' => 'draft',
			)
		);

		$result = $this->update(
			array(
				'id'     => $draft_id,
				'slug'   => 'team',
				'fields' => array( 'id', 'slug' ),
			)
		);

		$this->assert_updated_post( $result, $draft_id );
		$this->assertSame( 'team-2', $result['slug'], 'The slug should be made unique among the sibling pages.' );
	}

	/**
	 * An author of 0 is ignored, so a post can be written back as it was read.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_ignores_author_zero(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'     => self::$post_id,
				'author' => 0,
				'title'  => 'Author untouched',
				'fields' => array( 'id', 'title_raw', 'author' ),
			)
		);

		$this->assert_updated_post( $result, self::$post_id );
		$this->assertSame( 'Author untouched', $result['title_raw'], 'The title should be updated.' );
		$this->assertSame( self::$user_ids['editor'], $result['author']['id'], 'The author should be unchanged.' );
	}

	/**
	 * An ID beyond the integer range is rejected instead of wrapping around onto another post.
	 *
	 * @since x.x.x
	 */
	public function test_update_rejects_ids_beyond_the_integer_range(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		// Floats near 2^64 are 4096 apart, so 2^64 + N is exact for a multiple of 4096 and casts to N.
		$aliased_id = self::factory()->post->create(
			array(
				'import_id'  => 4096 * 1024,
				'post_title' => 'Aliased title',
			)
		);
		$this->assertSame( 4096 * 1024, $aliased_id, 'The aliased post should have the requested ID.' );

		$result = $this->update(
			array(
				'id'    => 2 ** 64 + $aliased_id,
				'title' => 'Not applied',
			)
		);
		$this->assertAbilityDenied( $result, 'An ID beyond the integer range should not resolve a post.' );
		$this->assertSame( 'Aliased title', get_post( $aliased_id )->post_title, 'The aliased post should be unchanged.' );

		$parent_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$page_id   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
			)
		);

		$invalid = $this->update(
			array(
				'id'     => $page_id,
				'parent' => 2 ** 64,
			)
		);
		$this->assertAbilityError( $invalid, 'content_invalid_parent', 'A parent beyond the integer range should be rejected.' );
		$this->assertSame( $parent_id, (int) get_post( $page_id )->post_parent, 'The page should keep its parent.' );
	}

	/**
	 * The menu order of a page can be set and reset to zero.
	 *
	 * @since x.x.x
	 */
	public function test_update_page_menu_order_to_zero(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'menu_order' => 1,
			)
		);

		$result = $this->update(
			array(
				'id'         => $page_id,
				'menu_order' => 0,
			)
		);

		$post = $this->assert_updated_post( $result, $page_id );
		$this->assertSame( 0, $post->menu_order, 'The menu order should be reset to zero.' );
	}
}
