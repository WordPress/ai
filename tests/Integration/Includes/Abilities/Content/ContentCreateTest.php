<?php
/**
 * Integration tests for the core/content-create Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WordPress\AI\Abilities\Content\Content;

/**
 * Content create ability test case.
 *
 * @since x.x.x
 */
class ContentCreateTest extends Content_Ability_TestCase {

	/**
	 * Returns a create input with every common field set.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $overrides Input values to override or add.
	 * @return array<string, mixed> The ability input.
	 */
	private function post_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'post_type' => 'post',
				'title'     => 'Post Title',
				'content'   => 'Post content',
				'excerpt'   => 'Post excerpt',
				'status'    => 'publish',
				'author'    => get_current_user_id(),
				'fields'    => array( 'id', 'post_type', 'status', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'link', 'title_raw', 'title_rendered', 'content_raw', 'content_rendered', 'excerpt_raw', 'excerpt_rendered', 'author' ),
			),
			$overrides
		);
	}

	/**
	 * Creates a post through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function create( array $input ) {
		return $this->execute_ability( 'core/content-create', $input );
	}

	/**
	 * Asserts that a create result describes a post that exists with the given input values.
	 *
	 * @since x.x.x
	 *
	 * @param mixed                $result The ability result.
	 * @param array<string, mixed> $input  The input the post was created from.
	 * @return \WP_Post The created post.
	 */
	private function assert_created_post( $result, array $input ): \WP_Post {
		$this->assertIsArray( $result, 'Creating a post should return the created post.' );
		$this->assertArrayHasKey( 'id', $result, 'The created post should carry its ID.' );

		$post = get_post( $result['id'] );
		$this->assertInstanceOf( \WP_Post::class, $post, 'The created post should exist.' );
		$this->assertSame( $input['post_type'], $post->post_type, 'The post type should match the input.' );
		$this->assertSame( $input['post_type'], $result['post_type'], 'The returned post type should match the input.' );
		$this->assertSame( $input['status'], $post->post_status, 'The post status should match the input.' );
		$this->assertSame( $input['status'], $result['status'], 'The returned status should match the input.' );
		$this->assertSame( $input['title'], $post->post_title, 'The post title should match the input.' );
		$this->assertSame( $input['title'], $result['title_raw'], 'The returned raw title should match the input.' );
		$this->assertSame( $input['content'], $post->post_content, 'The post content should match the input.' );
		$this->assertSame( $input['content'], $result['content_raw'], 'The returned raw content should match the input.' );
		$this->assertSame( $input['excerpt'], $post->post_excerpt, 'The post excerpt should match the input.' );
		$this->assertSame( $input['excerpt'], $result['excerpt_raw'], 'The returned raw excerpt should match the input.' );
		$this->assertSame( $input['author'], (int) $post->post_author, 'The post author should match the input.' );
		$this->assertSame( $input['author'], $result['author']['id'], 'The returned author should match the input.' );
		$this->assertSame( get_permalink( $post ), $result['link'], 'The returned link should be the permalink.' );

		return $post;
	}

	/**
	 * The ability is registered as a closed-world write that is neither destructive nor
	 * idempotent, requires a post type, rejects unknown properties, and returns a post shaped
	 * like a queried one.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_create_ability(): void {
		$this->register_ability();

		$ability     = wp_get_ability( 'core/content-create' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();

		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'Creating a post is not destructive.' );
		$this->assertFalse( $annotations['idempotent'], 'Every call creates a new post, so the ability is not idempotent.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'post_type' ), $schema['required'], 'Only the post type should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'post', 'page' ), $schema['properties']['post_type']['enum'], 'Only exposed post types should be accepted.' );
		$this->assertSame( wp_get_ability( 'core/content-query' )->get_output_schema()['oneOf'][0], $ability->get_output_schema(), 'The created post should have the same shape as a queried post.' );
	}

	/**
	 * When core already provides core/content-create, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_content_create(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/content-create',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided create ability.',
					'category'            => 'content',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'Content Create', wp_get_ability( 'core/content-create' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * An editor can create a published post with the common fields.
	 *
	 * @since x.x.x
	 */
	public function test_create_item(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$data   = $this->post_data();
		$result = $this->create( $data );

		$post = $this->assert_created_post( $result, $data );
		$this->assertSame( 'Post Title', $result['title_rendered'], 'The rendered title should be returned.' );
		$this->assertSame( "<p>Post content</p>\n", $result['content_rendered'], 'The rendered content should be returned.' );
		$this->assertSame( 'post-title', $post->post_name, 'The slug should be generated from the title.' );
	}

	/**
	 * Without a field selection the created post is returned with the lean default fields.
	 *
	 * @since x.x.x
	 */
	public function test_create_returns_lean_default_fields(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$data = $this->post_data();
		unset( $data['fields'] );
		$result = $this->create( $data );

		$this->assertIsArray( $result, 'Creating a post should return the created post.' );
		$this->assertSame( array( 'id', 'post_type', 'status', 'date', 'slug', 'title_rendered' ), array_keys( $result ), 'The default field set should match the query ability.' );
	}

	/**
	 * Dates are stored in the site timezone with their GMT counterpart, whether given as local, GMT, or with an offset.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_post_dates
	 *
	 * @param string                $status  The post status to create with.
	 * @param array<string, string> $params  The timezone and date inputs.
	 * @param array<string, string> $results The expected stored dates.
	 */
	public function test_create_post_date( string $status, array $params, array $results ): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		update_option( 'timezone_string', $params['timezone_string'] );

		$input = array(
			'post_type' => 'post',
			'status'    => $status,
			'title'     => 'not empty',
			'fields'    => array( 'id', 'date', 'date_gmt' ),
		);
		if ( isset( $params['date'] ) ) {
			$input['date'] = $params['date'];
		}
		if ( isset( $params['date_gmt'] ) ) {
			$input['date_gmt'] = $params['date_gmt'];
		}

		$result = $this->create( $input );

		$this->assertIsArray( $result, 'Creating a post with a date should succeed.' );
		$post = get_post( $result['id'] );

		$this->assertSame( $results['date'], $post->post_date, 'The stored local date should match.' );
		$this->assertSame( $results['date_gmt'], $post->post_date_gmt, 'The stored GMT date should match.' );
		$this->assertSame( str_replace( ' ', 'T', $results['date'] ) . '-05:00', $result['date'], 'The returned local date should carry the site offset.' );
		$this->assertSame( str_replace( ' ', 'T', $results['date_gmt'] ) . '+00:00', $result['date_gmt'], 'The returned GMT date should be the UTC instant.' );
	}

	/**
	 * A contributor creates a pending post whose GMT date floats, which the output still resolves.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_as_contributor(): void {
		$this->login_as( 'contributor' );
		$this->register_ability();

		update_option( 'timezone_string', 'America/Chicago' );

		// A pending post gets the special floating `post_date_gmt` value of '0000-00-00 00:00:00'. See #38883.
		$data   = $this->post_data( array( 'status' => 'pending' ) );
		$result = $this->create( $data );

		$post = $this->assert_created_post( $result, $data );
		$this->assertSame( '0000-00-00 00:00:00', $post->post_date_gmt, 'A pending post should have a floating GMT date.' );
		$this->assertNotSame( '', $result['date_gmt'], 'The returned GMT date should be derived from the local date.' );
		$this->assertStringEndsWith( '+00:00', $result['date_gmt'], 'The derived GMT date should be reported as UTC.' );
	}

	/**
	 * An author cannot create a post as another user, and is told why.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_other_author_without_permission(): void {
		$this->login_as( 'author' );
		$this->register_ability();

		$result = $this->create(
			$this->post_data(
				array(
					'title'  => 'Refused post for another author',
					'author' => self::$user_ids['editor'],
				)
			)
		);

		$this->assertAbilityError( $result, 'content_cannot_edit_others', 'An author should not be allowed to create posts as another user.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'The author error should carry the authorization status.' );
		$this->assertNoPostTitled( 'Refused post for another author', 'A refused create should write nothing.' );
	}

	/**
	 * Returns roles and custom statuses, with the error expected when setting the status.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: bool, 2: string|null}> The role, whether the status is public, and the expected error code.
	 */
	public function data_custom_statuses(): array {
		return array(
			'contributor, public status'     => array( 'contributor', true, 'content_cannot_publish' ),
			'contributor, non-public status' => array( 'contributor', false, null ),
			'author, public status'          => array( 'author', true, null ),
		);
	}

	/**
	 * A custom status registered as public requires the publish capability.
	 *
	 * A public status shows the post to everyone, as publishing does. The posts endpoint
	 * checks only the core statuses, which would let a contributor publish through a
	 * status a plugin registers.
	 *
	 * @dataProvider data_custom_statuses
	 *
	 * @since x.x.x
	 *
	 * @param string      $role      The role creating the post.
	 * @param bool        $is_public Whether the custom status is public.
	 * @param string|null $expected  The expected error code, or null when the create succeeds.
	 */
	public function test_create_post_with_custom_status( string $role, bool $is_public, ?string $expected ): void {
		// Registered before the ability, whose schema lists the statuses a post can be given.
		register_post_status(
			'wpai_custom',
			array(
				'label'  => 'Custom',
				'public' => $is_public,
			)
		);

		try {
			$this->login_as( $role );
			$this->register_ability();

			$result = $this->create(
				$this->post_data(
					array(
						'title'  => 'Custom status post',
						'status' => 'wpai_custom',
					)
				)
			);

			if ( null !== $expected ) {
				$this->assertAbilityError( $result, $expected, 'The custom status should be refused.' );
				$this->assertNoPostTitled( 'Custom status post', 'A refused create should write nothing.' );

				return;
			}

			$this->assertIsArray( $result, 'The custom status should be allowed.' );
			$this->assertSame( 'wpai_custom', get_post_status( $result['id'] ), 'The post should have the custom status.' );
		} finally {
			unset( $GLOBALS['wp_post_statuses']['wpai_custom'] );
		}
	}

	/**
	 * An editor can create a post as another user.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_as_other_author_with_permission(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$data   = $this->post_data( array( 'author' => self::$user_ids['author'] ) );
		$result = $this->create( $data );

		$post = $this->assert_created_post( $result, $data );
		$this->assertSame( self::$user_ids['author'], (int) $post->post_author, 'The post should belong to the given author.' );
	}

	/**
	 * Logged-out users and roles without the create capability are denied.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_without_permission(): void {
		$this->register_ability();

		wp_set_current_user( 0 );
		$data = $this->post_data( array( 'status' => 'draft' ) );
		// Logged out there is no current user to default the author to.
		unset( $data['author'] );
		$logged_out = $this->create( $data );
		$this->assertAbilityDenied( $logged_out, 'A logged-out user should not be allowed to create posts.' );

		$this->login_as( 'subscriber' );
		$subscriber = $this->create( $this->post_data( array( 'status' => 'draft' ) ) );
		$this->assertAbilityDenied( $subscriber, 'A subscriber should not be allowed to create posts.' );
	}

	/**
	 * A draft is created with derived GMT dates in the output.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_draft(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'status' => 'draft' ) ) );

		$this->assertIsArray( $result, 'Creating a draft should succeed.' );
		$post = get_post( $result['id'] );
		$this->assertSame( 'draft', $result['status'], 'The returned status should be draft.' );
		$this->assertSame( 'draft', $post->post_status, 'The stored status should be draft.' );

		// The dates are shimmed for the site offset: a draft stores no GMT date.
		$this->assertSame( gmdate( 'c', strtotime( get_gmt_from_date( $post->post_date ) . ' UTC' ) ), $result['date_gmt'], 'The GMT date should be derived from the local date.' );
		$this->assertSame( gmdate( 'c', strtotime( get_gmt_from_date( $post->post_modified ) . ' UTC' ) ), $result['modified_gmt'], 'The GMT modified date should be derived from the local date.' );
	}

	/**
	 * An editor can create a private post.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_private(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'status' => 'private' ) ) );

		$this->assertIsArray( $result, 'Creating a private post should succeed.' );
		$this->assertSame( 'private', $result['status'], 'The returned status should be private.' );
		$this->assertSame( 'private', get_post( $result['id'] )->post_status, 'The stored status should be private.' );
	}

	/**
	 * Creating a private post requires the publish capability.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_private_without_permission(): void {
		$author_id = $this->login_as( 'author' );
		$this->register_ability();

		$this->revoke_current_user_capability( 'publish_posts' );

		$result = $this->create(
			$this->post_data(
				array(
					'status' => 'private',
					'author' => $author_id,
				)
			)
		);

		$this->assertAbilityError( $result, 'content_cannot_publish', 'Creating a private post without the publish capability should fail.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'The publish error should carry the authorization status.' );
	}

	/**
	 * Publishing requires the publish capability.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_publish_without_permission(): void {
		$this->login_as( 'author' );
		$this->register_ability();

		$this->revoke_current_user_capability( 'publish_posts' );

		$result = $this->create( $this->post_data( array( 'status' => 'publish' ) ) );

		$this->assertAbilityError( $result, 'content_cannot_publish', 'Publishing without the publish capability should fail.' );
	}

	/**
	 * A status outside the registered non-internal statuses fails validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_invalid_status(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'status' => 'teststatus' ) ) );

		$this->assertAbilityError( $result, 'ability_invalid_input', 'An unknown status should fail validation.' );
	}

	/**
	 * A nonexistent author is rejected, and a negative one fails validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_invalid_author(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$negative = $this->create( $this->post_data( array( 'author' => -1 ) ) );
		$this->assertAbilityError( $negative, 'ability_invalid_input', 'A negative author ID should fail validation.' );

		$missing = $this->create( $this->post_data( array( 'author' => 999999 ) ) );
		$this->assertAbilityError( $missing, 'content_invalid_author', 'A nonexistent author should be rejected.' );
	}

	/**
	 * A UTC date is stored as given on a UTC site.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_custom_date(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'date' => '2010-01-01T02:00:00Z' ) ) );

		$this->assertIsArray( $result, 'Creating a post with a custom date should succeed.' );
		$this->assertSame( '2010-01-01T02:00:00+00:00', $result['date'], 'The returned date should match the given instant.' );
		$this->assertSame( gmmktime( 2, 0, 0, 1, 1, 2010 ), strtotime( get_post( $result['id'] )->post_date ), 'The stored date should match the given instant.' );
	}

	/**
	 * A date with a timezone offset is converted to the site timezone.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_custom_date_with_timezone(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'date' => '2010-01-01T02:00:00-10:00' ) ) );

		$this->assertIsArray( $result, 'Creating a post with an offset date should succeed.' );
		$post = get_post( $result['id'] );
		$time = gmmktime( 12, 0, 0, 1, 1, 2010 );

		$this->assertSame( '2010-01-01T12:00:00+00:00', $result['date'], 'The returned date should be converted to the site timezone.' );
		$this->assertSame( '2010-01-01T12:00:00+00:00', $result['modified'], 'The modified date should match the publication date on creation.' );
		$this->assertSame( $time, strtotime( $post->post_date ), 'The stored date should be converted to the site timezone.' );
		$this->assertSame( $time, strtotime( $post->post_modified ), 'The stored modified date should match the publication date on creation.' );
	}

	/**
	 * A database failure surfaces as the insert error with a server error status.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_db_error(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->run_with_failing_query(
			'INSERT',
			function () {
				return $this->create( $this->post_data() );
			}
		);

		$this->assertAbilityError( $result, 'db_insert_error', 'A failed insert should surface the database error.' );
		$this->assertSame( 500, $result->get_error_data()['status'], 'A database error should be a server error.' );
	}

	/**
	 * Invalid dates fail validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_invalid_date(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$date = $this->create( $this->post_data( array( 'date' => '2010-60-01T02:00:00Z' ) ) );
		$this->assertAbilityError( $date, 'ability_invalid_input', 'An invalid date should fail validation.' );

		$date_gmt = $this->create( $this->post_data( array( 'date_gmt' => '2010-60-01T02:00:00' ) ) );
		$this->assertAbilityError( $date_gmt, 'ability_invalid_input', 'An invalid GMT date should fail validation.' );
	}

	/**
	 * The title, content, and excerpt can be given as objects with a `raw` key.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_raw(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'post_type' => 'post',
				'title'     => array( 'raw' => 'Raw title' ),
				'content'   => array( 'raw' => 'Raw content' ),
				'excerpt'   => array( 'raw' => 'Raw excerpt' ),
				'fields'    => array( 'id', 'title_raw', 'content_raw', 'excerpt_raw' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a post from raw objects should succeed.' );
		$this->assertSame( 'Raw title', $result['title_raw'], 'The raw title should be stored.' );
		$this->assertSame( 'Raw content', $result['content_raw'], 'The raw content should be stored.' );
		$this->assertSame( 'Raw excerpt', $result['excerpt_raw'], 'The raw excerpt should be stored.' );

		$post = get_post( $result['id'] );
		$this->assertSame( 'Raw title', $post->post_title, 'The stored title should match the raw object.' );
		$this->assertSame( 'Raw content', $post->post_content, 'The stored content should match the raw object.' );
		$this->assertSame( 'Raw excerpt', $post->post_excerpt, 'The stored excerpt should match the raw object.' );
	}

	/**
	 * Quotes survive the slashing round trip.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_quotes_in_title(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'title' => "Rob O'Rourke's Diary" ) ) );

		$this->assertIsArray( $result, 'Creating a post with quotes in the title should succeed.' );
		$this->assertSame( "Rob O'Rourke's Diary", $result['title_raw'], 'The raw title should keep its quotes.' );
		$this->assertSame( "Rob O'Rourke's Diary", get_post( $result['id'] )->post_title, 'The stored title should keep its quotes.' );
	}

	/**
	 * A page accepts an excerpt, and the excerpt is readable again.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_with_excerpt(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'post_type' => 'page',
				'title'     => 'About',
				'excerpt'   => 'Summary',
				'status'    => 'publish',
				'fields'    => array( 'id', 'excerpt_raw', 'excerpt_rendered' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a page with an excerpt should succeed.' );
		$this->assertSame( 'Summary', $result['excerpt_raw'], 'The excerpt should be returned for the page.' );
		$this->assertSame( 'Summary', get_post( $result['id'] )->post_excerpt, 'The excerpt should be stored on the page.' );

		$read = $this->execute_ability(
			'core/content-query',
			array(
				'id'     => $result['id'],
				'fields' => array( 'excerpt_raw' ),
			)
		);

		$this->assertSame( array( 'excerpt_raw' => 'Summary' ), $read, 'The query ability should return the page excerpt.' );
	}

	/**
	 * A raw object without a `raw` key fails validation instead of being ignored.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_raw_object_without_raw_key(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'title' => array( 'rendered' => 'New' ) ) ) );

		$this->assertAbilityError( $result, 'ability_invalid_input', 'A raw object without a raw key should fail validation.' );
	}

	/**
	 * A draft slug that collides with a published post is made unique.
	 *
	 * @since x.x.x
	 */
	public function test_draft_post_does_not_have_the_same_slug_as_existing_post(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		self::factory()->post->create( array( 'post_name' => 'sample-slug' ) );

		$result = $this->create(
			$this->post_data(
				array(
					'status' => 'draft',
					'slug'   => 'sample-slug',
				)
			)
		);

		$this->assertIsArray( $result, 'Creating a draft with a taken slug should succeed.' );
		$this->assertSame( 'sample-slug-2', $result['slug'], 'The draft slug should be made unique.' );
		$this->assertSame( 'sample-slug-2', get_post( $result['id'] )->post_name, 'The stored draft slug should be made unique.' );
	}

	/**
	 * A post created without a status is a draft, so its slug is made unique the same way.
	 *
	 * @since x.x.x
	 */
	public function test_post_without_status_does_not_have_the_same_slug_as_existing_post(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		self::factory()->post->create( array( 'post_name' => 'sample-slug' ) );

		$input = $this->post_data( array( 'slug' => 'sample-slug' ) );
		unset( $input['status'] );

		$result = $this->create( $input );

		$this->assertIsArray( $result, 'Creating a post without a status should succeed.' );
		$this->assertSame( 'draft', $result['status'], 'A post without a status should be created as a draft.' );
		$this->assertSame( 'sample-slug-2', $result['slug'], 'The draft slug should be made unique.' );
	}

	/**
	 * Fields the post type does not support are ignored, as the REST API ignores them.
	 *
	 * @since x.x.x
	 */
	public function test_create_ignores_fields_the_post_type_does_not_support(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'parent' => self::factory()->post->create() ) ) );

		$this->assertIsArray( $result, 'A field the post type does not support should not fail the create.' );
		$this->assertSame( 0, get_post( $result['id'] )->post_parent, 'A post should not get a parent.' );
	}

	/**
	 * A page can be created under a parent page.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_with_parent(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$parent_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->create(
			array(
				'post_type' => 'page',
				'title'     => 'Child page',
				'parent'    => $parent_id,
				'fields'    => array( 'id', 'parent' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a child page should succeed.' );
		$this->assertSame( $parent_id, $result['parent'], 'The returned parent should match.' );
		$this->assertSame( $parent_id, (int) get_post( $result['id'] )->post_parent, 'The stored parent should match.' );

		$top_level = $this->create(
			array(
				'post_type' => 'page',
				'title'     => 'Top-level page',
				'parent'    => 0,
				'fields'    => array( 'id', 'parent' ),
			)
		);

		$this->assertIsArray( $top_level, 'Creating a top-level page should succeed.' );
		$this->assertSame( 0, $top_level['parent'], 'A zero parent should create a top-level page.' );
	}

	/**
	 * A nonexistent parent is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_with_invalid_parent(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'post_type' => 'page',
				'title'     => 'Orphan page',
				'parent'    => 999999,
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_parent', 'A nonexistent parent should be rejected.' );
	}

	/**
	 * A post type that is not exposed to abilities cannot be created.
	 *
	 * @since x.x.x
	 */
	public function test_create_for_unexposed_post_type_is_rejected(): void {
		$this->register_test_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		$this->login_as( 'administrator' );
		$this->register_ability();

		$input = array(
			'post_type' => 'wpai_hidden_cpt',
			'title'     => 'Hidden',
		);

		$result = $this->create( $input );
		$this->assertAbilityError( $result, 'ability_invalid_input', 'An unexposed post type should fail the post type enum.' );

		$direct = ( new Content() )->execute_content_create( $input );
		$this->assertAbilityError( $direct, 'content_not_found', 'A direct call should still reject an unexposed post type.' );
	}

	/**
	 * A post type registered by another plugin with `show_in_abilities` can be created.
	 *
	 * @since x.x.x
	 */
	public function test_creates_a_post_type_registered_by_another_plugin(): void {
		$this->register_test_post_type(
			'wpai_book',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->create(
			array(
				'post_type' => 'wpai_book',
				'title'     => 'A book',
				'content'   => 'Chapter one.',
				'status'    => 'publish',
				'fields'    => array( 'id', 'post_type', 'content_raw' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a custom post type post should succeed.' );
		$this->assertSame( 'wpai_book', $result['post_type'], 'The post should have the custom post type.' );
		$this->assertSame( 'Chapter one.', $result['content_raw'], 'The content should be stored.' );
	}

	/**
	 * The writable fields carry the same names, types, and enums as the posts and pages endpoints.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_matches_the_posts_endpoint_fields(): void {
		$this->register_ability();

		$properties = wp_get_ability( 'core/content-create' )->get_input_schema()['properties'];
		$endpoint   = array_merge(
			( new \WP_REST_Posts_Controller( 'page' ) )->get_item_schema()['properties'],
			( new \WP_REST_Posts_Controller( 'post' ) )->get_item_schema()['properties']
		);

		unset( $properties['post_type'], $properties['fields'] );

		foreach ( $properties as $field => $definition ) {
			$this->assertArrayHasKey( $field, $endpoint, "The {$field} field should be a field of the posts or pages endpoint." );

			$expected_type = 'object' === $endpoint[ $field ]['type'] ? array( 'string', 'object' ) : $endpoint[ $field ]['type'];
			$this->assertSame( $expected_type, $definition['type'], "The {$field} field should have the type of the endpoint field." );

			if ( ! isset( $endpoint[ $field ]['enum'] ) ) {
				continue;
			}

			$this->assertSame( array_values( $endpoint[ $field ]['enum'] ), $definition['enum'], "The {$field} field should accept the values of the endpoint field." );
		}
	}

	/**
	 * Provides round-trip cases for a user without unfiltered_html.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{0: array<string, string>, 1: array<string, array<string, string>>}> Raw input and expected values.
	 */
	public function data_post_roundtrip_as_author(): array {
		return array(
			array(
				array(
					'title'   => '\o/ ¯\_(ツ)_/¯',
					'content' => '\o/ ¯\_(ツ)_/¯',
					'excerpt' => '\o/ ¯\_(ツ)_/¯',
				),
				array(
					'title'   => array(
						'raw'      => '\o/ ¯\_(ツ)_/¯',
						'rendered' => '\o/ ¯\_(ツ)_/¯',
					),
					'content' => array(
						'raw'      => '\o/ ¯\_(ツ)_/¯',
						'rendered' => '<p>\o/ ¯\_(ツ)_/¯</p>',
					),
					'excerpt' => array(
						'raw'      => '\o/ ¯\_(ツ)_/¯',
						'rendered' => '<p>\o/ ¯\_(ツ)_/¯</p>',
					),
				),
			),
			array(
				array(
					'title'   => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
					'content' => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
					'excerpt' => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				),
				array(
					'title'   => array(
						'raw'      => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
						'rendered' => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
					),
					'content' => array(
						'raw'      => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
						'rendered' => '<p>\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;</p>',
					),
					'excerpt' => array(
						'raw'      => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
						'rendered' => '<p>\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;</p>',
					),
				),
			),
			array(
				array(
					'title'   => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'content' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'excerpt' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				array(
					'title'   => array(
						'raw'      => 'div <strong>strong</strong> oh noes',
						'rendered' => 'div <strong>strong</strong> oh noes',
					),
					'content' => array(
						'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
					),
					'excerpt' => array(
						'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
						'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
					),
				),
			),
			array(
				array(
					'title'   => '<a href="#" target="_blank" unfiltered=true>link</a>',
					'content' => '<a href="#" target="_blank" unfiltered=true>link</a>',
					'excerpt' => '<a href="#" target="_blank" unfiltered=true>link</a>',
				),
				array(
					'title'   => array(
						'raw'      => '<a href="#">link</a>',
						'rendered' => '<a href="#">link</a>',
					),
					'content' => array(
						'raw'      => '<a href="#" target="_blank">link</a>',
						'rendered' => '<p><a href="#" target="_blank">link</a></p>',
					),
					'excerpt' => array(
						'raw'      => '<a href="#" target="_blank">link</a>',
						'rendered' => '<p><a href="#" target="_blank">link</a></p>',
					),
				),
			),
		);
	}

	/**
	 * Content written by a user without unfiltered_html is filtered by kses on the way in.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_post_roundtrip_as_author
	 *
	 * @param array<string, string>                $raw      The raw input values.
	 * @param array<string, array<string, string>> $expected The expected stored and rendered values.
	 */
	public function test_post_roundtrip_as_author( array $raw, array $expected ): void {
		$this->login_as( 'author' );
		$this->register_ability();

		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'Precondition: authors cannot post unfiltered HTML.' );

		$fields = array( 'id', 'title_raw', 'title_rendered', 'content_raw', 'content_rendered', 'excerpt_raw', 'excerpt_rendered' );

		$created = $this->create( array_merge( array( 'post_type' => 'post' ), $raw, array( 'fields' => $fields ) ) );
		$this->assert_roundtrip( $created, $expected );

		$updated = $this->execute_ability( 'core/content-update', array_merge( array( 'id' => $created['id'] ), $raw, array( 'fields' => $fields ) ) );
		$this->assert_roundtrip( $updated, $expected );
	}

	/**
	 * Content written by an editor keeps or loses its scripts depending on unfiltered_html.
	 *
	 * Editors have unfiltered_html on single sites and not on multisite, so the outcome
	 * follows the capability rather than the role.
	 *
	 * @since x.x.x
	 */
	public function test_post_roundtrip_as_editor_unfiltered_html(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$raw      = array(
			'title'   => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
			'content' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
			'excerpt' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
		);
		$filtered = array(
			'title'   => array(
				'raw'      => 'div <strong>strong</strong> oh noes',
				'rendered' => 'div <strong>strong</strong> oh noes',
			),
			'content' => array(
				'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
				'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
			),
			'excerpt' => array(
				'raw'      => '<div>div</div> <strong>strong</strong> oh noes',
				'rendered' => "<div>div</div>\n<p> <strong>strong</strong> oh noes</p>",
			),
		);
		$kept     = array(
			'title'   => array(
				'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'rendered' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
			),
			'content' => array(
				'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'rendered' => "<div>div</div>\n<p> <strong>strong</strong> <script>oh noes</script></p>",
			),
			'excerpt' => array(
				'raw'      => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'rendered' => "<div>div</div>\n<p> <strong>strong</strong> <script>oh noes</script></p>",
			),
		);

		$expected = current_user_can( 'unfiltered_html' ) ? $kept : $filtered;
		$this->assertSame( ! is_multisite(), current_user_can( 'unfiltered_html' ), 'Precondition: editors have unfiltered_html on single sites only.' );

		$fields  = array( 'id', 'title_raw', 'title_rendered', 'content_raw', 'content_rendered', 'excerpt_raw', 'excerpt_rendered' );
		$created = $this->create( array_merge( array( 'post_type' => 'post' ), $raw, array( 'fields' => $fields ) ) );
		$this->assert_roundtrip( $created, $expected );

		$updated = $this->execute_ability( 'core/content-update', array_merge( array( 'id' => $created['id'] ), $raw, array( 'fields' => $fields ) ) );
		$this->assert_roundtrip( $updated, $expected );
	}

	/**
	 * Asserts the returned and stored values of a round-trip case.
	 *
	 * @since x.x.x
	 *
	 * @param mixed                                $result   The ability result.
	 * @param array<string, array<string, string>> $expected The expected values.
	 */
	private function assert_roundtrip( $result, array $expected ): void {
		$this->assertIsArray( $result, 'The write should succeed.' );

		$this->assertSame( $expected['title']['raw'], $result['title_raw'], 'The raw title should be filtered by kses.' );
		$this->assertSame( $expected['title']['rendered'], trim( $result['title_rendered'] ), 'The rendered title should be rendered from the stored value.' );
		$this->assertSame( $expected['content']['raw'], $result['content_raw'], 'The raw content should be filtered by kses.' );
		$this->assertSame( $expected['content']['rendered'], trim( $result['content_rendered'] ), 'The rendered content should be rendered from the stored value.' );
		$this->assertSame( $expected['excerpt']['raw'], $result['excerpt_raw'], 'The raw excerpt should be filtered by kses.' );
		$this->assertSame( $expected['excerpt']['rendered'], trim( $result['excerpt_rendered'] ), 'The rendered excerpt should be rendered from the stored value.' );

		$post = get_post( $result['id'] );
		$this->assertSame( $expected['title']['raw'], $post->post_title, 'The stored title should be filtered by kses.' );
		$this->assertSame( $expected['content']['raw'], $post->post_content, 'The stored content should be filtered by kses.' );
		$this->assertSame( $expected['excerpt']['raw'], $post->post_excerpt, 'The stored excerpt should be filtered by kses.' );
	}
}
