<?php
/**
 * Integration tests for the core/content-create Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

/**
 * Content create ability test case.
 *
 * @since x.x.x
 */
class ContentCreateTest extends Content_Ability_TestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->register_ability();
	}

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
				'type'        => 'post',
				'title_raw'   => 'Post Title',
				'content_raw' => 'Post content',
				'excerpt_raw' => 'Post excerpt',
				'status'      => 'publish',
				'author_slug' => wp_get_current_user()->user_nicename,
				'fields'      => array( 'id', 'type', 'status', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'link', 'title_raw', 'title_rendered', 'content_raw', 'content_rendered', 'excerpt_raw', 'excerpt_rendered', 'author_slug' ),
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
		$this->assertSame( $input['type'], $post->post_type, 'The post type should match the input.' );
		$this->assertSame( $input['type'], $result['type'], 'The returned post type should match the input.' );
		$this->assertSame( $input['status'], $post->post_status, 'The post status should match the input.' );
		$this->assertSame( $input['status'], $result['status'], 'The returned status should match the input.' );
		$this->assertSame( $input['title_raw'], $post->post_title, 'The post title should match the input.' );
		$this->assertSame( $input['title_raw'], $result['title_raw'], 'The returned raw title should match the input.' );
		$this->assertSame( $input['content_raw'], $post->post_content, 'The post content should match the input.' );
		$this->assertSame( $input['content_raw'], $result['content_raw'], 'The returned raw content should match the input.' );
		$this->assertSame( $input['excerpt_raw'], $post->post_excerpt, 'The post excerpt should match the input.' );
		$this->assertSame( $input['excerpt_raw'], $result['excerpt_raw'], 'The returned raw excerpt should match the input.' );
		$this->assertSame( $input['author_slug'], get_userdata( (int) $post->post_author )->user_nicename, 'The post author should match the input.' );
		$this->assertSame( $input['author_slug'], $result['author_slug'], 'The returned author slug should match the input.' );
		$this->assertSame( get_permalink( $post ), $result['link'], 'The returned link should be the permalink.' );

		return $post;
	}

	/**
	 * Asserts that no post, in any status, has the given title.
	 *
	 * @since x.x.x
	 *
	 * @param string $title   The post title.
	 * @param string $message The assertion message.
	 */
	private function assertNoPostTitled( string $title, string $message ): void {
		$query = new \WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'title'       => $title,
				'fields'      => 'ids',
			)
		);

		$this->assertSame( array(), $query->posts, $message );
	}

	/**
	 * The ability is registered as a closed-world write that is neither destructive nor
	 * idempotent, requires a post type, rejects unknown properties, and returns a post shaped
	 * like a queried one.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_create_ability(): void {
		$ability     = wp_get_ability( 'core/content-create' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();

		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertTrue( $ability->get_meta_item( 'public', false ), 'The ability should be marked public.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'Creating a post is not destructive.' );
		$this->assertFalse( $annotations['idempotent'], 'Every call creates a new post, so the ability is not idempotent.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'type' ), $schema['required'], 'Only the post type should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'post', 'page' ), $schema['properties']['type']['enum'], 'Only exposed post types should be accepted.' );
		$this->assertSame( wp_list_pluck( wp_get_ability( 'core/content-query' )->get_output_schema()['oneOf'][0]['properties'], 'type' ), wp_list_pluck( $ability->get_output_schema()['properties'], 'type' ), 'The created post should have the same fields as a queried post.' );
	}

	/**
	 * When core already provides core/content-create, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_content_create(): void {
		global $wp_current_filter;

		// Swap the copy registered in setUp() for a core-provided one.
		wp_unregister_ability( 'core/content-create' );

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
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

		$this->register_ability();

		$this->assertSame( 'Create Content', wp_get_ability( 'core/content-create' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * An editor can create a published post with the common fields.
	 *
	 * @since x.x.x
	 */
	public function test_create_item(): void {
		$this->login_as( 'editor' );

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

		$data = $this->post_data();
		unset( $data['fields'] );
		$result = $this->create( $data );

		$this->assertIsArray( $result, 'Creating a post should return the created post.' );
		$this->assertSame( array( 'id', 'type', 'status', 'date', 'slug', 'title_rendered' ), array_keys( $result ), 'The default field set should match the query ability.' );
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

		update_option( 'timezone_string', $params['timezone_string'] );

		$input = array(
			'type'      => 'post',
			'status'    => $status,
			'title_raw' => 'not empty',
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

		$result = $this->create(
			$this->post_data(
				array(
					'title_raw'   => 'Refused post for another author',
					'author_slug' => get_userdata( self::$user_ids['editor'] )->user_nicename,
				)
			)
		);

		$this->assertAbilityError( $result, 'content_cannot_edit_others', 'An author should not be allowed to create posts as another user.', 403 );
		$this->assertNoPostTitled( 'Refused post for another author', 'A refused create should write nothing.' );
	}

	/**
	 * An editor can create a post as another user.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_as_other_author_with_permission(): void {
		$this->login_as( 'editor' );

		$data   = $this->post_data( array( 'author_slug' => get_userdata( self::$user_ids['author'] )->user_nicename ) );
		$result = $this->create( $data );

		$post = $this->assert_created_post( $result, $data );
		$this->assertSame( self::$user_ids['author'], (int) $post->post_author, 'The post should belong to the given author.' );
	}

	/**
	 * An author can name themselves by their slug in capitals, which the author lookup matches
	 * through the database's case-insensitive collation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_as_self_with_a_slug_in_capitals(): void {
		$author_id = $this->login_as( 'author' );
		$slug      = wp_get_current_user()->user_nicename;
		$this->assertNotSame( $slug, strtoupper( $slug ), 'Precondition: the slug should differ in capitals.' );

		$result = $this->create( $this->post_data( array( 'author_slug' => strtoupper( $slug ) ) ) );

		$this->assertIsArray( $result, 'An author should be allowed to name themselves in capitals.' );
		$this->assertSame( (string) $author_id, get_post( $result['id'] )->post_author, 'The post should belong to the author.' );
		$this->assertSame( $slug, $result['author_slug'], 'The returned author slug should be the stored one.' );
	}

	/**
	 * Logged-out users and roles without the create capability are denied.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_without_permission(): void {
		wp_set_current_user( 0 );
		$data = $this->post_data( array( 'status' => 'draft' ) );
		// post_data() sends the current user's slug, which is empty when logged out and would fail validation before the permission check.
		unset( $data['author_slug'] );
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

		$result = $this->create( $this->post_data( array( 'status' => 'private' ) ) );

		$this->assertIsArray( $result, 'Creating a private post should succeed.' );
		$this->assertSame( 'private', $result['status'], 'The returned status should be private.' );
		$this->assertSame( 'private', get_post( $result['id'] )->post_status, 'The stored status should be private.' );
	}

	/**
	 * Returns the statuses that need the publish capability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string}> The status to create the post with.
	 */
	public function data_statuses_that_need_the_publish_capability(): array {
		return array(
			'private' => array( 'private' ),
			'publish' => array( 'publish' ),
		);
	}

	/**
	 * Creating a private or a published post requires the publish capability.
	 *
	 * @dataProvider data_statuses_that_need_the_publish_capability
	 *
	 * @since x.x.x
	 *
	 * @param string $status The status to create the post with.
	 */
	public function test_create_post_without_publish_permission( string $status ): void {
		$this->login_as( 'author' );

		wp_get_current_user()->add_cap( 'publish_posts', false );

		$result = $this->create( $this->post_data( array( 'status' => $status ) ) );

		$this->assertAbilityError( $result, 'content_cannot_publish', 'Creating the post without the publish capability should fail.', 403 );
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
	 * A custom status registered as public requires the publish capability, as publishing does.
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
		register_post_status(
			'wpai_custom',
			array(
				'label'  => 'Custom',
				'public' => $is_public,
			)
		);

		try {
			$this->login_as( $role );
			// Registered again, since the schema lists the statuses a post can be given.
			$this->register_ability();

			$result = $this->create(
				$this->post_data(
					array(
						'title_raw' => 'Custom status post',
						'status'    => 'wpai_custom',
					)
				)
			);

			if ( null !== $expected ) {
				$this->assertAbilityError( $result, $expected, 'The custom status should be refused.', 403 );
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
	 * A status outside the registered non-internal statuses fails validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_invalid_status(): void {
		$this->login_as( 'editor' );

		$result = $this->create( $this->post_data( array( 'status' => 'teststatus' ) ) );

		$this->assertAbilityError( $result, 'ability_invalid_input', 'An unknown status should fail validation.' );
	}

	/**
	 * An author slug that names no user is rejected, and an empty or non-string one fails validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_invalid_author(): void {
		$this->login_as( 'editor' );

		$empty = $this->create( $this->post_data( array( 'author_slug' => '' ) ) );
		$this->assertAbilityError( $empty, 'ability_invalid_input', 'An empty author slug should fail validation instead of being ignored.' );

		$user_id = $this->create( $this->post_data( array( 'author_slug' => self::$user_ids['author'] ) ) );
		$this->assertAbilityError( $user_id, 'ability_invalid_input', 'A user ID should fail validation.' );

		$missing = $this->create( $this->post_data( array( 'author_slug' => 'no-such-user' ) ) );
		$this->assertAbilityError( $missing, 'content_invalid_field', 'A slug that names no user should be rejected.', 400 );
		$this->assertSame(
			array( 'author_slug' => $missing->get_error_message() ),
			$missing->get_error_data()['params'],
			'The error data should map the author_slug field to the error message.'
		);
	}

	/**
	 * A slug that does not name the current user is refused the same way whether or not it
	 * names a user, so the refusal reveals nothing about whether that user exists.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_as_unknown_author_without_permission(): void {
		$this->login_as( 'author' );

		$result = $this->create( $this->post_data( array( 'author_slug' => 'no-such-user' ) ) );

		$this->assertAbilityError( $result, 'content_cannot_edit_others', 'An unknown slug should be refused like any other user\'s.' );
	}

	/**
	 * A UTC date is stored as given on a UTC site.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_custom_date(): void {
		$this->login_as( 'editor' );

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
		global $wpdb;

		$this->login_as( 'editor' );

		// Break the insert, keeping its database error out of the test output.
		add_filter(
			'query',
			static function ( string $query ): string {
				return 0 === strpos( $query, 'INSERT' ) ? '],' : $query;
			}
		);
		$suppress = $wpdb->suppress_errors();
		$result   = $this->create( $this->post_data() );
		$wpdb->suppress_errors( $suppress );

		$this->assertAbilityError( $result, 'db_insert_error', 'A failed insert should surface the database error.', 500 );
	}

	/**
	 * Invalid dates fail validation, and so do null dates, which the query ability never returns.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_invalid_date(): void {
		$this->login_as( 'editor' );

		$date = $this->create( $this->post_data( array( 'date' => '2010-60-01T02:00:00Z' ) ) );
		$this->assertAbilityError( $date, 'ability_invalid_input', 'An invalid date should fail validation.' );

		$date_gmt = $this->create( $this->post_data( array( 'date_gmt' => '2010-60-01T02:00:00' ) ) );
		$this->assertAbilityError( $date_gmt, 'ability_invalid_input', 'An invalid GMT date should fail validation.' );

		foreach ( array( 'date', 'date_gmt' ) as $field ) {
			$null_date = $this->create( $this->post_data( array( $field => null ) ) );
			$this->assertAbilityError( $null_date, 'ability_invalid_input', "A null {$field} should fail validation." );
		}
	}

	/**
	 * The title, content, and excerpt are plain strings, as the query ability returns them,
	 * so an object with a `raw` key fails validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_raw_object_fails_validation(): void {
		$this->login_as( 'editor' );

		foreach ( array( 'title_raw', 'content_raw', 'excerpt_raw' ) as $field ) {
			$result = $this->create( $this->post_data( array( $field => array( 'raw' => 'Raw object' ) ) ) );

			$this->assertAbilityError( $result, 'ability_invalid_input', "An object for {$field} should fail validation." );
		}
	}

	/**
	 * Quotes survive the slashing round trip.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_quotes_in_title(): void {
		$this->login_as( 'editor' );

		$result = $this->create( $this->post_data( array( 'title_raw' => "Rob O'Rourke's Diary" ) ) );

		$this->assertIsArray( $result, 'Creating a post with quotes in the title should succeed.' );
		$this->assertSame( "Rob O'Rourke's Diary", $result['title_raw'], 'The raw title should keep its quotes.' );
		$this->assertSame( "Rob O'Rourke's Diary", get_post( $result['id'] )->post_title, 'The stored title should keep its quotes.' );
	}

	/**
	 * A draft slug that collides with a published post is made unique.
	 *
	 * @since x.x.x
	 */
	public function test_draft_post_does_not_have_the_same_slug_as_existing_post(): void {
		$this->login_as( 'editor' );

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

		self::factory()->post->create( array( 'post_name' => 'sample-slug' ) );

		$input = $this->post_data( array( 'slug' => 'sample-slug' ) );
		unset( $input['status'] );

		$result = $this->create( $input );

		$this->assertIsArray( $result, 'Creating a post without a status should succeed.' );
		$this->assertSame( 'draft', $result['status'], 'A post without a status should be created as a draft.' );
		$this->assertSame( 'sample-slug-2', $result['slug'], 'The draft slug should be made unique.' );
	}

	/**
	 * Provides fields a post type does not support, each with a value that is otherwise valid.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: string, 2: mixed}> Post type, field, and value.
	 */
	public function data_unsupported_fields(): array {
		return array(
			'parent on a post'                => array( 'post', 'parent', 0 ),
			'excerpt on a page'               => array( 'page', 'excerpt_raw', 'Excerpt' ),
			'title without title support'     => array( 'wpai_editor_only', 'title_raw', 'Title' ),
			'content without editor support'  => array( 'wpai_title_only', 'content_raw', 'Content' ),
			'excerpt without excerpt support' => array( 'wpai_title_only', 'excerpt_raw', 'Excerpt' ),
			'author without author support'   => array( 'wpai_title_only', 'author_slug', 'admin' ),
		);
	}

	/**
	 * Fields the post type does not support are rejected instead of being ignored.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_unsupported_fields
	 *
	 * @param string $post_type The post type to create.
	 * @param string $field     The unsupported field.
	 * @param mixed  $value     A value that is otherwise valid for the field.
	 */
	public function test_create_rejects_unsupported_fields( string $post_type, string $field, $value ): void {
		$this->register_test_post_type(
			'wpai_title_only',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title' ),
			)
		);
		$this->register_test_post_type(
			'wpai_editor_only',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'editor' ),
			)
		);

		$this->login_as( 'administrator' );
		// Registered again, so the schema lists the new post types.
		$this->register_ability();

		$result = $this->create(
			array(
				'type' => $post_type,
				$field => $value,
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_field', "The {$field} field should be rejected for the {$post_type} post type.", 400 );
		$this->assertStringContainsString( $field, $result->get_error_message(), 'The error should name the field.' );
		$this->assertSame(
			array( $field => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the unsupported field to the error message.'
		);

		$written = new \WP_Query(
			array(
				'post_type'   => $post_type,
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);
		$this->assertSame( array(), $written->posts, 'A rejected create should write nothing.' );
	}

	/**
	 * A page can be created under a parent page.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_with_parent(): void {
		$this->login_as( 'editor' );

		$parent_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->create(
			array(
				'type'      => 'page',
				'title_raw' => 'Child page',
				'parent'    => $parent_id,
				'fields'    => array( 'id', 'parent' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a child page should succeed.' );
		$this->assertSame( $parent_id, $result['parent'], 'The returned parent should match.' );
		$this->assertSame( $parent_id, (int) get_post( $result['id'] )->post_parent, 'The stored parent should match.' );

		$top_level = $this->create(
			array(
				'type'      => 'page',
				'title_raw' => 'Top-level page',
				'parent'    => 0,
				'fields'    => array( 'id', 'parent' ),
			)
		);

		$this->assertIsArray( $top_level, 'Creating a top-level page should succeed.' );
		$this->assertSame( 0, $top_level['parent'], 'A zero parent should create a top-level page.' );
	}

	/**
	 * Returns the parents that a page cannot be given.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string}> What the parent is.
	 */
	public function data_invalid_parents(): array {
		return array(
			'a missing post'                 => array( 'missing' ),
			'a post of another type'         => array( 'another_type' ),
			'an ID beyond the integer range' => array( 'beyond_integer_range' ),
		);
	}

	/**
	 * A parent must be an existing post of the same type: the permalink of a page under another
	 * type would not resolve. An ID beyond the integer range is rejected instead of wrapping
	 * around onto another post.
	 *
	 * @dataProvider data_invalid_parents
	 *
	 * @since x.x.x
	 *
	 * @param string $relation What the parent is.
	 */
	public function test_create_page_with_invalid_parent( string $relation ): void {
		$this->login_as( 'editor' );

		// Floats near 2^64 are 4096 apart, so 2^64 + N is exact for a multiple of 4096 and casts to N.
		$aliased_id = self::factory()->post->create(
			array(
				'import_id' => 4096 * 1024,
				'post_type' => 'page',
			)
		);
		$this->assertSame( 4096 * 1024, $aliased_id, 'Precondition: the aliased page should have the requested ID.' );

		$parents = array(
			'missing'              => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			'another_type'         => self::factory()->post->create(),
			'beyond_integer_range' => 2 ** 64 + $aliased_id,
		);

		$result = $this->create(
			array(
				'type'      => 'page',
				'title_raw' => 'Page with an invalid parent',
				'parent'    => $parents[ $relation ],
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_field', 'An invalid parent should be rejected.', 400 );
		$this->assertSame(
			array( 'parent' => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the invalid parent field to the error message.'
		);
		$this->assertNoPostTitled( 'Page with an invalid parent', 'A rejected create should write nothing.' );
	}

	/**
	 * A post the current user cannot read cannot be the parent: its slug would show in the child's permalink.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_rejects_an_unreadable_parent(): void {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
				'post_author' => self::$user_ids['administrator'],
			)
		);

		// An author who may write pages, but not read other users' private pages.
		$this->login_as( 'author' );
		wp_get_current_user()->add_cap( 'edit_pages' );
		wp_get_current_user()->add_cap( 'publish_pages' );

		$this->assertFalse( current_user_can( 'read_post', $parent_id ), 'The author should not be able to read the private page.' );

		$result = $this->create(
			array(
				'type'      => 'page',
				'status'    => 'publish',
				'title_raw' => 'Page under a private page',
				'parent'    => $parent_id,
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_field', 'A parent the user cannot read should be rejected.' );
		$this->assertSame(
			array( 'parent' => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the unreadable parent field to the error message.'
		);
		$this->assertNoPostTitled( 'Page under a private page', 'A rejected create should write nothing.' );
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

		$input = array(
			'type'      => 'wpai_hidden_cpt',
			'title_raw' => 'Hidden',
		);

		$result = $this->create( $input );
		$this->assertAbilityError( $result, 'ability_invalid_input', 'An unexposed post type should fail the post type enum.' );

		$execute = $this->get_ability_callbacks( 'core/content-create' )['execute_callback'];
		$direct  = $execute( $input );
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
		// Registered again, so the schema lists the new post type.
		$this->register_ability();

		$result = $this->create(
			array(
				'type'        => 'wpai_book',
				'title_raw'   => 'A book',
				'content_raw' => 'Chapter one.',
				'status'      => 'publish',
				'fields'      => array( 'id', 'type', 'content_raw' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a custom post type post should succeed.' );
		$this->assertSame( 'wpai_book', $result['type'], 'The post should have the custom post type.' );
		$this->assertSame( 'Chapter one.', $result['content_raw'], 'The content should be stored.' );
	}

	/**
	 * The writable fields carry the names and types of the fields the query ability returns.
	 *
	 * Only the null the query returns for a date it cannot resolve cannot be written.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_matches_the_query_output_fields(): void {
		$properties = wp_get_ability( 'core/content-create' )->get_input_schema()['properties'];
		$queried    = wp_get_ability( 'core/content-query' )->get_output_schema()['oneOf'][0]['properties'];

		unset( $properties['fields'] );

		foreach ( $properties as $field => $definition ) {
			$this->assertArrayHasKey( $field, $queried, "The {$field} field should be a field of a queried post." );
			$this->assertContains( $definition['type'], (array) $queried[ $field ]['type'], "The {$field} field should have a type of the queried field." );
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
					'title_raw'   => '\o/ ¯\_(ツ)_/¯',
					'content_raw' => '\o/ ¯\_(ツ)_/¯',
					'excerpt_raw' => '\o/ ¯\_(ツ)_/¯',
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
					'title_raw'   => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
					'content_raw' => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
					'excerpt_raw' => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
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
					'title_raw'   => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'content_raw' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'excerpt_raw' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
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
					'title_raw'   => '<a href="#" target="_blank" unfiltered=true>link</a>',
					'content_raw' => '<a href="#" target="_blank" unfiltered=true>link</a>',
					'excerpt_raw' => '<a href="#" target="_blank" unfiltered=true>link</a>',
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

		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'Precondition: authors cannot post unfiltered HTML.' );

		$this->assert_roundtrip( $raw, $expected );
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

		$this->assertSame( ! is_multisite(), current_user_can( 'unfiltered_html' ), 'Precondition: editors have unfiltered_html on single sites only.' );

		// The author case with a script, which kses filters without unfiltered_html.
		[ $raw, $filtered ] = $this->data_post_roundtrip_as_author()[2];

		$kept = array(
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

		$this->assert_roundtrip( $raw, current_user_can( 'unfiltered_html' ) ? $kept : $filtered );
	}

	/**
	 * Creates a post with the raw values, then updates it with them again, and asserts the
	 * returned and stored values after each write.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string>                $raw      The raw input values.
	 * @param array<string, array<string, string>> $expected The expected stored and rendered values.
	 */
	private function assert_roundtrip( array $raw, array $expected ): void {
		$fields = array( 'id', 'title_raw', 'title_rendered', 'content_raw', 'content_rendered', 'excerpt_raw', 'excerpt_rendered' );

		$created = $this->create( array_merge( array( 'type' => 'post' ), $raw, array( 'fields' => $fields ) ) );
		$this->assert_roundtrip_result( $created, $expected );

		$updated = $this->execute_ability( 'core/content-update', array_merge( array( 'id' => $created['id'] ), $raw, array( 'fields' => $fields ) ) );
		$this->assert_roundtrip_result( $updated, $expected );
	}

	/**
	 * Asserts the returned and stored values of a round-trip write.
	 *
	 * @since x.x.x
	 *
	 * @param mixed                                $result   The ability result.
	 * @param array<string, array<string, string>> $expected The expected values.
	 */
	private function assert_roundtrip_result( $result, array $expected ): void {
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
