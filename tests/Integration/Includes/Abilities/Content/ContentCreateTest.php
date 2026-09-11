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
	 * The category the current user is forbidden to assign, when set.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private $forbidden_category = 0;

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
	 * Disables INSERT queries so wp_insert_post() fails with a database error.
	 *
	 * @since x.x.x
	 *
	 * @param string $query The database query.
	 * @return string The query, broken when it is an INSERT.
	 */
	public function error_insert_query( string $query ): string {
		if ( 0 === strpos( $query, 'INSERT' ) ) {
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
	 * Registers a post type that only supports titles, for the unsupported-field tests.
	 *
	 * @since x.x.x
	 */
	private function register_title_only_post_type(): void {
		register_post_type(
			'wpai_title_only',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title' ),
			)
		);
	}

	/**
	 * The ability is registered in the `content` category and flagged as a non-idempotent write.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_create_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/content-create' );

		$this->assertNotNull( $ability, 'The core/content-create ability should be registered.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'Creating a post is not destructive.' );
		$this->assertFalse( $annotations['idempotent'], 'Every call creates a new post, so the ability is not idempotent.' );
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

		$this->assertFalse( wp_has_ability( 'core/content-create' ), 'The create ability should not register without any exposed post types.' );
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
	 * The input schema requires a post type, lists the writable fields, and rejects unknown properties.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_lists_the_writable_fields(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/content-create' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertSame( array( 'post_type' ), $schema['required'], 'Only the post type should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );

		$expected_keys = array(
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
		$this->assertSame( $expected_keys, array_keys( $schema['properties'] ), 'The writable fields should be listed, with taxonomies under their rest_base keys.' );

		$this->assertSame( array( 'post', 'page' ), $schema['properties']['post_type']['enum'], 'Only exposed post types should be accepted.' );
		$this->assertSame( array_values( get_post_stati( array( 'internal' => false ) ) ), $schema['properties']['status']['enum'], 'The status enum should list the non-internal statuses.' );
		$this->assertSame( array_values( get_post_format_slugs() ), $schema['properties']['format']['enum'], 'The format enum should list the registered post formats.' );
		$this->assertSame( array( 'string', 'null' ), $schema['properties']['date']['type'], 'The date should accept null to reset it.' );
		$this->assertSame( 'integer', $schema['properties']['categories']['items']['type'], 'Taxonomy terms should be given as term IDs.' );
	}

	/**
	 * Unknown properties fail validation, so an `id` cannot be smuggled into a create call.
	 *
	 * The strict schema rejects the property itself, so nothing is silently ignored.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_unknown_properties_and_missing_post_type(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$with_id = $this->create( $this->post_data( array( 'id' => 3 ) ) );
		$this->assertAbilityError( $with_id, 'ability_invalid_input', 'Creating with an id should fail validation.' );

		$data = $this->post_data();
		unset( $data['post_type'] );
		$without_type = $this->create( $data );
		$this->assertAbilityError( $without_type, 'ability_invalid_input', 'Creating without a post type should fail validation.' );

		$readonly = $this->create( $this->post_data( array( 'modified' => '2010-06-01T02:00:00Z' ) ) );
		$this->assertAbilityError( $readonly, 'ability_invalid_input', 'Read-only post fields should be rejected rather than ignored.' );
	}

	/**
	 * The output schema describes a single post with the query ability's fields.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_describes_a_post(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/content-create' )->get_output_schema();

		$this->assertSame( 'object', $schema['type'], 'The output schema should describe an object.' );
		$this->assertFalse( $schema['additionalProperties'], 'The output should only carry known post fields.' );
		$this->assertArrayNotHasKey( 'required', $schema, 'No field is required, because the caller chooses the fields.' );

		$query_schema = wp_get_ability( 'core/content-query' )->get_output_schema();
		$this->assertSame( $query_schema['oneOf'][0], $schema, 'The created post should have the same shape as a queried post.' );
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
	 * A post created without a status is a draft, like wp_insert_post().
	 *
	 * @since x.x.x
	 */
	public function test_create_defaults_to_draft(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'post_type' => 'post',
				'title'     => 'Untitled draft',
				'fields'    => array( 'id', 'status' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a post should return the created post.' );
		$this->assertSame( 'draft', $result['status'], 'A post created without a status should be a draft.' );
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
	 * A template offered by the theme is assigned to the created post.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_template(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		add_filter( 'theme_post_templates', array( $this, 'filter_theme_post_templates' ) );
		try {
			$result = $this->create( $this->post_data( array( 'template' => 'post-my-test-template.php' ) ) );
		} finally {
			remove_filter( 'theme_post_templates', array( $this, 'filter_theme_post_templates' ) );
		}

		$this->assertIsArray( $result, 'Creating a post with a valid template should succeed.' );
		$this->assertSame( 'post-my-test-template.php', get_page_template_slug( get_post( $result['id'] ) ), 'The template should be stored on the post.' );
	}

	/**
	 * A template the theme does not offer is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_template_none_available(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'template' => 'post-my-test-template.php' ) ) );

		$this->assertAbilityError( $result, 'content_invalid_template', 'An unavailable template should be rejected.' );
		$this->assertSame( 400, $result->get_error_data()['status'], 'An invalid template should be a bad request.' );
	}

	/**
	 * An empty template is always accepted and selects the default template.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_template_none(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'template' => '' ) ) );

		$this->assertIsArray( $result, 'Creating a post with the default template should succeed.' );
		$this->assertSame( '', get_page_template_slug( get_post( $result['id'] ) ), 'No template should be stored on the post.' );
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
	 * An editor can create a sticky post.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_sticky(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'sticky' => true ) ) );

		$this->assertIsArray( $result, 'Creating a sticky post should succeed.' );
		$this->assertTrue( is_sticky( $result['id'] ), 'The post should be sticky.' );
	}

	/**
	 * A post created without the sticky flag is not sticky.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_is_not_sticky_by_default(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data() );

		$this->assertIsArray( $result, 'Creating a post should succeed.' );
		$this->assertFalse( is_sticky( $result['id'] ), 'The post should not be sticky.' );
	}

	/**
	 * A contributor cannot make a post sticky.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_sticky_as_contributor(): void {
		$this->login_as( 'contributor' );
		$this->register_ability();

		$result = $this->create(
			$this->post_data(
				array(
					'sticky' => true,
					'status' => 'pending',
				)
			)
		);

		$this->assertAbilityDenied( $result, 'A contributor should not be allowed to make posts sticky.' );
	}

	/**
	 * A sticky flag given as the string "false" is not treated as sticky.
	 *
	 * The permission gate reads boolean strings, so a query-string transport cannot turn
	 * "false" into a sticky request.
	 *
	 * @since x.x.x
	 */
	public function test_string_false_sticky_is_not_sticky(): void {
		$this->login_as( 'contributor' );

		$content = new Content();
		$input   = $this->post_data(
			array(
				'sticky' => 'false',
				'status' => 'pending',
			)
		);

		$this->assertTrue( $content->check_create_permission( $input ), 'A "false" sticky string should not require the sticky capability.' );

		$result = $content->execute_content_create( $input );
		$this->assertIsArray( $result, 'Creating with a "false" sticky string should succeed.' );
		$this->assertFalse( is_sticky( $result['id'] ), 'The post should not be sticky.' );
	}

	/**
	 * An author cannot create a post as another user.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_other_author_without_permission(): void {
		$this->login_as( 'author' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'author' => self::$user_ids['editor'] ) ) );

		$this->assertAbilityDenied( $result, 'An author should not be allowed to create posts as another user.' );
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
	 * A post format is assigned to the created post.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_format(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'format' => 'gallery' ) ) );

		$this->assertIsArray( $result, 'Creating a post with a format should succeed.' );
		$this->assertSame( 'gallery', get_post_format( $result['id'] ), 'The post should have the gallery format.' );
	}

	/**
	 * The standard format leaves the post without a format term.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_standard_format(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'format' => 'standard' ) ) );

		$this->assertIsArray( $result, 'Creating a post with the standard format should succeed.' );
		$this->assertFalse( get_post_format( $result['id'] ), 'The standard format should not assign a format term.' );
	}

	/**
	 * A format outside the registered formats fails validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_invalid_format(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'format' => 'testformat' ) ) );

		$this->assertAbilityError( $result, 'ability_invalid_input', 'An unknown format should fail validation.' );
	}

	/**
	 * A valid format the theme does not support is still assigned.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_unsupported_format(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'format' => 'link' ) ) );

		$this->assertIsArray( $result, 'Creating a post with a theme-unsupported format should succeed.' );
		$this->assertSame( 'link', get_post_format( $result['id'] ), 'The post should have the link format.' );
	}

	/**
	 * A featured image is assigned to the created post.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_featured_media(): void {
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

		$result = $this->create( $this->post_data( array( 'featured_media' => $attachment_id ) ) );

		$this->assertIsArray( $result, 'Creating a post with featured media should succeed.' );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $result['id'] ), 'The attachment should be the post thumbnail.' );
	}

	/**
	 * An invalid featured media ID is reported instead of being silently ignored.
	 *
	 * The failure is surfaced so the caller knows the post was created without the image.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_invalid_featured_media(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'featured_media' => 999999 ) ) );

		$this->assertAbilityError( $result, 'content_invalid_featured_media', 'An invalid featured media ID should be reported.' );
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
	 * A post can be created with a password.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_password(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'password' => 'testing' ) ) );

		$this->assertIsArray( $result, 'Creating a password-protected post should succeed.' );
		$this->assertSame( 'testing', get_post( $result['id'] )->post_password, 'The password should be stored.' );
	}

	/**
	 * A falsey password string is kept as-is.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_falsey_password(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'password' => '0' ) ) );

		$this->assertIsArray( $result, 'Creating a post with the password "0" should succeed.' );
		$this->assertSame( '0', get_post( $result['id'] )->post_password, 'The password "0" should be stored.' );
	}

	/**
	 * An empty password does not conflict with the sticky flag.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_empty_string_password_and_sticky(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			$this->post_data(
				array(
					'password' => '',
					'sticky'   => true,
				)
			)
		);

		$this->assertIsArray( $result, 'An empty password should not conflict with sticky.' );
		$this->assertSame( '', get_post( $result['id'] )->post_password, 'No password should be stored.' );
		$this->assertTrue( is_sticky( $result['id'] ), 'The post should be sticky.' );
	}

	/**
	 * A post cannot be both sticky and password protected.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_password_and_sticky_fails(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			$this->post_data(
				array(
					'password' => '123',
					'sticky'   => true,
				)
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_field', 'A sticky post cannot have a password.' );
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

		global $wpdb;
		$wpdb->suppress_errors = true;
		add_filter( 'query', array( $this, 'error_insert_query' ) );
		try {
			$result = $this->create( $this->post_data() );
		} finally {
			remove_filter( 'query', array( $this, 'error_insert_query' ) );
			$wpdb->suppress_errors = false;
		}

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
	 * Categories are assigned under the `categories` key.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_categories(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$category = wp_insert_term( 'Test Category', 'category' );

		$result = $this->create(
			$this->post_data(
				array(
					'password'   => 'testing',
					'categories' => array( $category['term_id'] ),
				)
			)
		);

		$this->assertIsArray( $result, 'Creating a post with categories should succeed.' );
		$this->assertSame( array( $category['term_id'] ), wp_get_post_categories( $result['id'] ), 'The category should be assigned.' );
	}

	/**
	 * Tags are assigned under the `tags` key.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_tags(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$tag = wp_insert_term( 'Test Tag', 'post_tag' );

		$result = $this->create( $this->post_data( array( 'tags' => array( $tag['term_id'] ) ) ) );

		$this->assertIsArray( $result, 'Creating a post with tags should succeed.' );
		$this->assertSame( array( $tag['term_id'] ), wp_get_post_tags( $result['id'], array( 'fields' => 'ids' ) ), 'The tag should be assigned.' );
	}

	/**
	 * A CSV term list is honored when the schema is bypassed, as a query-string transport would deliver it.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_honors_csv_categories(): void {
		$this->login_as( 'editor' );

		$category  = wp_insert_term( 'Chicken', 'category' );
		$category2 = wp_insert_term( 'Ribs', 'category' );

		$result = ( new Content() )->execute_content_create(
			$this->post_data( array( 'categories' => $category['term_id'] . ',' . $category2['term_id'] ) )
		);

		$this->assertIsArray( $result, 'Creating a post with CSV categories should succeed.' );
		$this->assertSame( array( $category['term_id'], $category2['term_id'] ), wp_get_post_categories( $result['id'] ), 'Both categories should be assigned.' );
	}

	/**
	 * Nonexistent term IDs are skipped, leaving the post without categories.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_invalid_categories(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			$this->post_data(
				array(
					'password'   => 'testing',
					'categories' => array( 999999 ),
				)
			)
		);

		$this->assertIsArray( $result, 'Creating a post with an unknown category should succeed.' );
		$this->assertSame( array(), wp_get_post_categories( $result['id'] ), 'The unknown category should be skipped.' );
	}

	/**
	 * Terms the current user cannot assign deny the whole request.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_with_categories_that_cannot_be_assigned_by_current_user(): void {
		$categories               = self::factory()->category->create_many( 2 );
		$this->forbidden_category = $categories[1];

		$this->login_as( 'editor' );
		$this->register_ability();

		add_filter( 'map_meta_cap', array( $this, 'revoke_assign_term' ), 10, 4 );
		try {
			$result = $this->create(
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
	public function test_create_page_with_categories_is_rejected(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$category = wp_insert_term( 'Page Category', 'category' );

		$result = $this->create(
			array(
				'post_type'  => 'page',
				'title'      => 'A page',
				'categories' => array( $category['term_id'] ),
			)
		);

		$this->assertAbilityError( $result, 'content_invalid_field', 'Categories should be rejected for pages.' );
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
	 * The slug is sanitized like a title.
	 *
	 * @since x.x.x
	 */
	public function test_create_post_slug_is_sanitized(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create( $this->post_data( array( 'slug' => 'Tęst Acceńted Chäræcters!' ) ) );

		$this->assertIsArray( $result, 'Creating a post with a raw slug should succeed.' );
		$this->assertSame( 'test-accented-charaecters', $result['slug'], 'The slug should be sanitized.' );
	}

	/**
	 * Provides fields that a post type does not support.
	 *
	 * @return array<string, array{0: string, 1: string, 2: mixed}> Post type, field, and value.
	 */
	public function data_unsupported_fields(): array {
		return array(
			'parent on a post'          => array( 'post', 'parent', 0 ),
			'menu order on a post'      => array( 'post', 'menu_order', 1 ),
			'sticky on a page'          => array( 'page', 'sticky', true ),
			'format on a page'          => array( 'page', 'format', 'aside' ),
			'content without editor'    => array( 'wpai_title_only', 'content', 'Body' ),
			'excerpt without support'   => array( 'wpai_title_only', 'excerpt', 'Excerpt' ),
			'author without support'    => array( 'wpai_title_only', 'author', 1 ),
			'featured media unsupported' => array( 'wpai_title_only', 'featured_media', 0 ),
			'comment status unsupported' => array( 'wpai_title_only', 'comment_status', 'open' ),
			'ping status unsupported'   => array( 'wpai_title_only', 'ping_status', 'open' ),
		);
	}

	/**
	 * Fields the post type does not support are rejected rather than silently ignored.
	 *
	 * A shared schema cannot express per post type which fields apply, so execution
	 * rejects them.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_unsupported_fields
	 *
	 * @param string $post_type The post type to create.
	 * @param string $field     The unsupported field.
	 * @param mixed  $value     A valid value for the field.
	 */
	public function test_create_rejects_unsupported_fields( string $post_type, string $field, $value ): void {
		$this->register_title_only_post_type();

		try {
			$this->login_as( 'administrator' );
			$this->register_ability();

			$result = $this->create(
				array(
					'post_type' => $post_type,
					'title'     => 'Unsupported field',
					$field      => $value,
				)
			);

			$this->assertAbilityError( $result, 'content_invalid_field', "The {$field} field should be rejected for the {$post_type} post type." );
			$this->assertStringContainsString( $field, $result->get_error_message(), 'The error should name the field.' );
			$this->assertStringContainsString( $post_type, $result->get_error_message(), 'The error should name the post type.' );
		} finally {
			unregister_post_type( 'wpai_title_only' );
		}
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
	 * Page attributes and comment settings are stored.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_with_menu_order_and_comment_settings(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'post_type'      => 'page',
				'title'          => 'Ordered page',
				'menu_order'     => 7,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'fields'         => array( 'id' ),
			)
		);

		$this->assertIsArray( $result, 'Creating a page with attributes should succeed.' );
		$post = get_post( $result['id'] );
		$this->assertSame( 7, $post->menu_order, 'The menu order should be stored.' );
		$this->assertSame( 'closed', $post->comment_status, 'The comment status should be stored.' );
		$this->assertSame( 'closed', $post->ping_status, 'The ping status should be stored.' );
	}

	/**
	 * A post type that is not exposed to abilities cannot be created.
	 *
	 * @since x.x.x
	 */
	public function test_create_for_unexposed_post_type_is_rejected(): void {
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

			$input = array(
				'post_type' => 'wpai_hidden_cpt',
				'title'     => 'Hidden',
			);

			$result = $this->create( $input );
			$this->assertAbilityError( $result, 'ability_invalid_input', 'An unexposed post type should fail the post type enum.' );

			$direct = ( new Content() )->execute_content_create( $input );
			$this->assertAbilityError( $direct, 'content_invalid_post_type', 'A direct call should still reject an unexposed post type.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * A post type registered by another plugin with `show_in_abilities` can be created.
	 *
	 * @since x.x.x
	 */
	public function test_creates_a_post_type_registered_by_another_plugin(): void {
		register_post_type(
			'wpai_book',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		try {
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
		} finally {
			unregister_post_type( 'wpai_book' );
		}
	}

	/**
	 * The writable fields carry the same names, types, and enums as the posts and pages endpoints.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_matches_the_posts_endpoint_fields(): void {
		$this->register_ability();

		$properties = wp_get_ability( 'core/content-create' )->get_input_schema()['properties'];

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$endpoint_properties = ( new \WP_REST_Posts_Controller( $post_type ) )->get_item_schema()['properties'];

			foreach ( $endpoint_properties as $field => $definition ) {
				// Meta has no abilities counterpart; read-only fields are never written.
				if ( 'meta' === $field || ! empty( $definition['readonly'] ) || ! in_array( 'edit', $definition['context'], true ) ) {
					continue;
				}

				$this->assertArrayHasKey( $field, $properties, "The {$field} field of the {$post_type} endpoint should be accepted." );

				$expected_type = 'object' === $definition['type'] ? array( 'string', 'object' ) : $definition['type'];
				$this->assertSame( $expected_type, $properties[ $field ]['type'], "The {$field} field should have the type of the {$post_type} endpoint." );

				if ( isset( $definition['enum'] ) ) {
					$this->assertSame( array_values( $definition['enum'] ), $properties[ $field ]['enum'], "The {$field} field should accept the values of the {$post_type} endpoint." );
				}

				if ( isset( $definition['items'] ) ) {
					$this->assertSame( $definition['items']['type'], $properties[ $field ]['items']['type'], "The {$field} items should have the type of the {$post_type} endpoint." );
				}
			}
		}
	}

	/**
	 * A page template offered by the theme is assigned to the created page.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_with_template(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$templates = static function (): array {
			return array( 'page-my-test-template.php' => 'My Test Template' );
		};

		add_filter( 'theme_page_templates', $templates );
		try {
			$result = $this->create(
				array(
					'post_type' => 'page',
					'title'     => 'Templated page',
					'template'  => 'page-my-test-template.php',
					'fields'    => array( 'id' ),
				)
			);
		} finally {
			remove_filter( 'theme_page_templates', $templates );
		}

		$this->assertIsArray( $result, 'Creating a page with a valid template should succeed.' );
		$this->assertSame( 'page-my-test-template.php', get_page_template_slug( $result['id'] ), 'The template should be stored on the page.' );
	}

	/**
	 * A negative parent fails validation.
	 *
	 * @since x.x.x
	 */
	public function test_create_page_with_negative_parent(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'post_type' => 'page',
				'title'     => 'Orphan page',
				'parent'    => -1,
			)
		);

		$this->assertAbilityError( $result, 'ability_invalid_input', 'A negative parent should fail validation.' );
	}

	/**
	 * Custom taxonomies are accepted under their rest_base, or their name, and only for their post types.
	 *
	 * @since x.x.x
	 */
	public function test_custom_taxonomy_terms_are_accepted_under_their_rest_base_key(): void {
		register_post_type(
			'wpai_book',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title' ),
			)
		);
		register_taxonomy(
			'wpai_genre',
			'wpai_book',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'genres',
			)
		);
		register_taxonomy(
			'wpai_shelf',
			'wpai_book',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		register_taxonomy(
			'wpai_hidden_shelf',
			'wpai_book',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		try {
			$this->login_as( 'administrator' );
			$this->register_ability();

			$properties = wp_get_ability( 'core/content-create' )->get_input_schema()['properties'];
			$this->assertArrayHasKey( 'genres', $properties, 'A taxonomy with a rest_base should be accepted under it.' );
			$this->assertArrayHasKey( 'wpai_shelf', $properties, 'A taxonomy without a rest_base should be accepted under its name.' );
			$this->assertArrayNotHasKey( 'wpai_hidden_shelf', $properties, 'A taxonomy without show_in_rest should not be accepted.' );

			$genre = wp_insert_term( 'Fantasy', 'wpai_genre' );
			$shelf = wp_insert_term( 'Top shelf', 'wpai_shelf' );

			$result = $this->create(
				array(
					'post_type'  => 'wpai_book',
					'title'      => 'A shelved book',
					'genres'     => array( $genre['term_id'] ),
					'wpai_shelf' => array( $shelf['term_id'] ),
					'fields'     => array( 'id' ),
				)
			);

			$this->assertIsArray( $result, 'Creating a book with custom terms should succeed.' );
			$this->assertSame( array( $genre['term_id'] ), wp_get_object_terms( $result['id'], 'wpai_genre', array( 'fields' => 'ids' ) ), 'The genre should be assigned.' );
			$this->assertSame( array( $shelf['term_id'] ), wp_get_object_terms( $result['id'], 'wpai_shelf', array( 'fields' => 'ids' ) ), 'The shelf should be assigned.' );

			$rejected = $this->create(
				array(
					'post_type' => 'post',
					'title'     => 'Not a book',
					'genres'    => array( $genre['term_id'] ),
				)
			);
			$this->assertAbilityError( $rejected, 'content_invalid_field', 'A taxonomy of another post type should be rejected.' );
		} finally {
			unregister_taxonomy( 'wpai_genre' );
			unregister_taxonomy( 'wpai_shelf' );
			unregister_taxonomy( 'wpai_hidden_shelf' );
			unregister_post_type( 'wpai_book' );
		}
	}

	/**
	 * The core post insertion hook fires for the created post.
	 *
	 * @since x.x.x
	 */
	public function test_create_fires_wp_after_insert_post(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$calls    = array();
		$callback = static function ( $post_id, $post, $update, $post_before ) use ( &$calls ): void {
			$calls[] = array( $post_id, $update, $post_before );
		};

		add_action( 'wp_after_insert_post', $callback, 10, 4 );
		try {
			$result = $this->create( $this->post_data() );
		} finally {
			remove_action( 'wp_after_insert_post', $callback, 10 );
		}

		$this->assertIsArray( $result, 'Creating a post should succeed.' );
		$this->assertNotEmpty( $calls, 'wp_after_insert_post should fire.' );
		$last = end( $calls );
		$this->assertSame( $result['id'], $last[0], 'The hook should receive the created post.' );
		$this->assertFalse( $last[1], 'The hook should report a creation.' );
		$this->assertNull( $last[2], 'There is no previous post on creation.' );
	}

	/**
	 * Provides round-trip cases for a user without unfiltered_html.
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
