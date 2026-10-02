<?php
/**
 * Integration tests for the core/media-query Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Media
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Media;

use WP_Post;
use WP_REST_Attachments_Controller;
use WP_Test_REST_TestCase;
use WordPress\AI\Abilities\Media\Media;

/**
 * Media ability test case.
 *
 * Tests named like core's REST attachments controller tests port them to the ability. The
 * ability requires a logged-in user, so where a core test reads as a logged-out visitor,
 * the port reads as a subscriber, who sees the same attachments.
 *
 * @since x.x.x
 */
class MediaTest extends WP_Test_REST_TestCase {

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static array $user_ids = array();

	/**
	 * The path to a JPEG test image.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private static string $test_file = '';

	/**
	 * The path to a PNG test image.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private static string $test_file2 = '';

	/**
	 * The path to a test video.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private static string $test_video_file = '';

	/**
	 * The path to a test audio file.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private static string $test_audio_file = '';

	/**
	 * The path to a test RTF document.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private static string $test_rtf_file = '';

	/**
	 * The recorded posts query clauses, one entry per query.
	 *
	 * @since x.x.x
	 *
	 * @var list<array<string, string>>
	 */
	private array $posts_clauses = array();

	/**
	 * Creates the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
			'author'        => $factory->user->create( array( 'role' => 'author' ) ),
			'contributor'   => $factory->user->create( array( 'role' => 'contributor' ) ),
			'subscriber'    => $factory->user->create( array( 'role' => 'subscriber' ) ),
		);
	}

	/**
	 * Removes the copied test files.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		foreach ( array( self::$test_file, self::$test_file2, self::$test_video_file, self::$test_audio_file, self::$test_rtf_file ) as $file ) {
			if ( '' === $file || ! file_exists( $file ) ) {
				continue;
			}

			wp_delete_file( $file );
		}
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		self::$test_file       = $this->copy_test_file( 'images/canola.jpg' );
		self::$test_file2      = $this->copy_test_file( 'images/codeispoetry.png' );
		self::$test_video_file = $this->copy_test_file( 'uploads/small-video.mp4' );
		self::$test_audio_file = $this->copy_test_file( 'uploads/small-audio.mp3' );
		self::$test_rtf_file   = $this->copy_test_file( 'uploads/test.rtf' );

		$this->register_ability();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/media-query' ) ) {
			wp_unregister_ability( 'core/media-query' );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Copies a file from the WordPress test data into the temporary directory.
	 *
	 * @since x.x.x
	 *
	 * @param string $path The path relative to the test data directory.
	 * @return string The path of the copy.
	 */
	private function copy_test_file( string $path ): string {
		$copy = get_temp_dir() . wp_basename( $path );
		if ( ! file_exists( $copy ) ) {
			copy( DIR_TESTDATA . '/' . $path, $copy );
		}

		return $copy;
	}

	/**
	 * Registers the plugin's core/media-query ability and its category inside faked init actions.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$media               = new Media();
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			$media->register_category();
			$media->register();
		} finally {
			array_pop( $wp_current_filter );
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Logs in as the shared user with the given role and returns its ID.
	 *
	 * @since x.x.x
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function login_as( string $role ): int {
		wp_set_current_user( self::$user_ids[ $role ] );

		return self::$user_ids[ $role ];
	}

	/**
	 * Runs the ability through the Abilities API and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return mixed The ability result.
	 */
	private function execute( $input = array() ) {
		return wp_get_ability( 'core/media-query' )->execute( $input );
	}

	/**
	 * Records the clauses of each posts query.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $clauses The query clauses.
	 * @return array<string, string> The unchanged clauses.
	 */
	public function save_posts_clauses( $clauses ) {
		$this->posts_clauses[] = $clauses;
		return $clauses;
	}

	/**
	 * Creates an attachment for one of the visibility scenarios, owned by an administrator
	 * unless the given post fields say otherwise.
	 *
	 * @since x.x.x
	 *
	 * @param string               $fixture The scenario name.
	 * @param array<string, mixed> $args    Optional. Further post fields.
	 * @return int The attachment ID.
	 */
	private function create_fixture( string $fixture, array $args = array() ): int {
		$author = self::$user_ids['administrator'];
		$parent = 0;
		$status = 'inherit';

		if ( in_array( $fixture, array( 'published parent', 'draft parent', 'private parent' ), true ) ) {
			$parent = self::factory()->post->create(
				array(
					'post_author' => $author,
					'post_status' => strtok( $fixture, ' ' ) === 'published' ? 'publish' : strtok( $fixture, ' ' ),
				)
			);
		} elseif ( 'private item' === $fixture ) {
			$status = 'private';
		} elseif ( 'trashed item' === $fixture ) {
			$status = 'trash';
		}

		return self::factory()->attachment->create_object(
			self::$test_file,
			$parent,
			array_merge(
				array(
					'post_author'    => $author,
					'post_mime_type' => 'image/jpeg',
					'post_status'    => $status,
				),
				$args
			)
		);
	}

	/**
	 * Asserts the fields of a returned attachment against the stored attachment.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post             $attachment The attachment.
	 * @param array<string, mixed> $data       The returned fields.
	 */
	private function check_post_data( WP_Post $attachment, array $data ): void {
		$this->assertSame( $attachment->ID, $data['id'] );
		$this->assertSame( $attachment->post_name, $data['slug'] );
		$this->assertSame( get_permalink( $attachment->ID ), $data['link'] );
		if ( '0000-00-00 00:00:00' === $attachment->post_date_gmt ) {
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $attachment->post_date ) - ( get_option( 'gmt_offset' ) * 3600 ) );
			$this->assertSame( mysql_to_rfc3339( $post_date_gmt ), $data['date_gmt'] );
		} else {
			$this->assertSame( mysql_to_rfc3339( $attachment->post_date_gmt ), $data['date_gmt'] );
		}
		$this->assertSame( mysql_to_rfc3339( $attachment->post_date ), $data['date'] );
		if ( '0000-00-00 00:00:00' === $attachment->post_modified_gmt ) {
			$post_modified_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $attachment->post_modified ) - ( get_option( 'gmt_offset' ) * 3600 ) );
			$this->assertSame( mysql_to_rfc3339( $post_modified_gmt ), $data['modified_gmt'] );
		} else {
			$this->assertSame( mysql_to_rfc3339( $attachment->post_modified_gmt ), $data['modified_gmt'] );
		}
		$this->assertSame( mysql_to_rfc3339( $attachment->post_modified ), $data['modified'] );
		$this->assertSame( (int) $attachment->post_author, $data['author'] );
		$this->assertSame( get_the_title( $attachment->ID ), $data['title_rendered'] );
		$this->assertSame( $attachment->post_status, $data['status'] );

		$this->assertArrayNotHasKey( 'title_raw', $data );
		$this->assertArrayNotHasKey( 'caption_raw', $data );
		$this->assertArrayNotHasKey( 'description_raw', $data );

		$this->assertSame( get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ), $data['alt_text'] );
		$this->assertArrayHasKey( 'media_details', $data );

		if ( $attachment->post_parent ) {
			$this->assertSame( $attachment->post_parent, $data['post'] );
		} else {
			$this->assertNull( $data['post'] );
		}

		$this->assertSame( wp_get_attachment_url( $attachment->ID ), $data['source_url'] );
	}

	/**
	 * The fields readable without edit access.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The field names.
	 */
	private function get_view_fields(): array {
		return array(
			'id',
			'date',
			'date_gmt',
			'modified',
			'modified_gmt',
			'slug',
			'status',
			'link',
			'title_rendered',
			'author',
			'description_rendered',
			'caption_rendered',
			'alt_text',
			'media_type',
			'mime_type',
			'media_details',
			'post',
			'source_url',
			'filename',
			'filesize',
		);
	}

	/**
	 * The ability is registered in the `content` category and flagged as a closed-world read.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_media_query_ability(): void {
		$ability     = wp_get_ability( 'core/media-query' );
		$annotations = $ability->get_meta_item( 'annotations', array() );

		$this->assertSame( 'Media Query', $ability->get_label(), 'The ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertTrue( $annotations['readonly'], 'The ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The ability should be marked non-destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The ability should be marked idempotent.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only reads the local database.' );
	}

	/**
	 * When core already provides core/media-query, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_media_query(): void {
		wp_unregister_ability( 'core/media-query' );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/media-query',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided media ability.',
					'category'            => 'content',
					'execute_callback'    => static function (): array {
						return array();
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertSame( 'Core Provided', wp_get_ability( 'core/media-query' )->get_label(), 'The core-provided ability should be registered first.' );

		$this->register_ability();

		$this->assertSame( 'Media Query', wp_get_ability( 'core/media-query' )->get_label(), 'The plugin-provided ability should replace it.' );
	}

	/**
	 * The input schema models a single-item mode and a collection mode, each rejecting the
	 * other mode's properties, with defaults left to the ability.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_models_mutually_exclusive_modes(): void {
		$schema = wp_get_ability( 'core/media-query' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input should be an object.' );
		$this->assertEquals( (object) array(), $schema['default'], 'Omitted input should default to an empty query.' );
		$this->assertCount( 2, $schema['oneOf'], 'The input should have two modes.' );

		[ $item, $collection ] = $schema['oneOf'];

		$this->assertSame( array( 'id' ), $item['required'], 'The single-item mode should require an ID.' );
		$this->assertFalse( $item['additionalProperties'], 'The single-item mode should reject other properties.' );
		$this->assertArrayNotHasKey( 'required', $collection, 'The collection mode should not require anything.' );
		$this->assertFalse( $collection['additionalProperties'], 'The collection mode should reject other properties.' );

		foreach ( $collection['properties'] as $name => $property ) {
			$this->assertArrayNotHasKey( 'default', $property, "The {$name} parameter should rely on the ability's defaults." );
		}
	}

	/**
	 * The output schema describes an item and a collection. Neither accepts unknown
	 * properties, which keeps the two shapes apart.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_describes_single_item_and_collection_responses(): void {
		$ability = wp_get_ability( 'core/media-query' );
		$schema  = $ability->get_output_schema();

		[ $item, $collection ] = $schema['oneOf'];

		$this->assertSame( 'object', $schema['type'], 'The output should be an object.' );
		$this->assertArrayNotHasKey( 'required', $item, 'No item field should be required, since the caller picks them.' );
		$this->assertFalse( $item['additionalProperties'], 'Items should reject unknown properties.' );
		$this->assertFalse( $collection['additionalProperties'], 'The collection should reject unknown properties.' );
		$this->assertSame( array( 'media', 'total', 'total_pages' ), $collection['required'], 'The collection should require its list and totals.' );
		$this->assertSame( $item, $collection['properties']['media']['items'], 'Collection items should use the item schema.' );
		$this->assertSame(
			array_keys( $item['properties'] ),
			$ability->get_input_schema()['oneOf'][0]['properties']['fields']['items']['enum'],
			'The fields enum should list the item properties.'
		);
	}

	/**
	 * Collection items are validated against the item schema, like a single item.
	 *
	 * @since x.x.x
	 */
	public function test_output_validation_covers_collection_items(): void {
		$attachment_id = $this->create_fixture( 'unattached' );
		add_filter( 'the_content', '__return_null', 99 );

		$this->login_as( 'subscriber' );
		$fields = array( 'description_rendered' );

		$this->assertErrorResponse(
			'ability_invalid_output',
			$this->execute(
				array(
					'id'     => $attachment_id,
					'fields' => $fields,
				)
			),
			null,
			'An invalid single item should fail validation.'
		);
		$this->assertErrorResponse( 'ability_invalid_output', $this->execute( array( 'fields' => $fields ) ), null, 'An invalid collection item should fail validation.' );
	}

	/**
	 * Every field the ability returns is a field of the REST media endpoint; `_raw` and
	 * `_rendered` fields flatten an object field of the endpoint.
	 *
	 * @since x.x.x
	 */
	public function test_fields_are_a_subset_of_the_media_endpoint_schema(): void {
		$controller = get_post_type_object( 'attachment' )->get_rest_controller();
		$this->assertInstanceOf( WP_REST_Attachments_Controller::class, $controller, 'Attachments should be served by the media endpoint.' );

		$rest   = $controller->get_item_schema()['properties'];
		$fields = array_keys( wp_get_ability( 'core/media-query' )->get_output_schema()['oneOf'][0]['properties'] );

		foreach ( $fields as $field ) {
			if ( preg_match( '/^(.+)_(raw|rendered)$/', $field, $matches ) ) {
				$this->assertArrayHasKey( $matches[1], $rest, "The endpoint should have the {$matches[1]} field." );
				$this->assertArrayHasKey( $matches[2], $rest[ $matches[1] ]['properties'], "The endpoint's {$matches[1]} field should have {$matches[2]}." );
				continue;
			}

			$this->assertArrayHasKey( $field, $rest, "The endpoint should have the {$field} field." );
		}
	}

	/**
	 * The collection mode takes the endpoint's query parameters the ability supports, with the
	 * endpoint's values.
	 *
	 * @since x.x.x
	 */
	public function test_registered_query_params(): void {
		$rest   = get_post_type_object( 'attachment' )->get_rest_controller()->get_collection_params();
		$params = wp_get_ability( 'core/media-query' )->get_input_schema()['oneOf'][1]['properties'];
		$keys   = array_keys( $params );
		sort( $keys );

		$this->assertSame(
			array(
				'author',
				'exclude',
				'fields',
				'include',
				'media_type',
				'mime_type',
				'order',
				'orderby',
				'page',
				'parent',
				'per_page',
				'search',
				'status',
			),
			$keys
		);
		$this->assertSame( $rest['status']['items']['enum'], $params['status']['items']['enum'] );
		$this->assertSame( $rest['media_type']['items']['enum'], $params['media_type']['items']['enum'] );
		$this->assertSame( array_values( array_diff( $rest['orderby']['enum'], array( 'include_slugs' ) ) ), $params['orderby']['enum'], 'The endpoint also orders by include_slugs, for its slug parameter.' );
	}

	/**
	 * The single-item mode takes an ID and the fields to return.
	 *
	 * @since x.x.x
	 */
	public function test_registered_get_item_params(): void {
		$keys = array_keys( wp_get_ability( 'core/media-query' )->get_input_schema()['oneOf'][0]['properties'] );
		$this->assertEqualSets( array( 'fields', 'id' ), $keys );
	}

	/**
	 * The item schema lists the fields the ability returns.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_schema(): void {
		$properties = wp_get_ability( 'core/media-query' )->get_output_schema()['oneOf'][0]['properties'];

		$this->assertCount( 23, $properties );
		$this->assertArrayHasKey( 'author', $properties );
		$this->assertArrayHasKey( 'alt_text', $properties );
		$this->assertArrayHasKey( 'filename', $properties );
		$this->assertArrayHasKey( 'filesize', $properties );
		$this->assertArrayHasKey( 'caption_raw', $properties );
		$this->assertArrayHasKey( 'caption_rendered', $properties );
		$this->assertArrayHasKey( 'description_raw', $properties );
		$this->assertArrayHasKey( 'description_rendered', $properties );
		$this->assertArrayHasKey( 'date', $properties );
		$this->assertArrayHasKey( 'date_gmt', $properties );
		$this->assertArrayHasKey( 'id', $properties );
		$this->assertArrayHasKey( 'link', $properties );
		$this->assertArrayHasKey( 'media_type', $properties );
		$this->assertArrayHasKey( 'mime_type', $properties );
		$this->assertArrayHasKey( 'media_details', $properties );
		$this->assertArrayHasKey( 'modified', $properties );
		$this->assertArrayHasKey( 'modified_gmt', $properties );
		$this->assertArrayHasKey( 'post', $properties );
		$this->assertArrayHasKey( 'status', $properties );
		$this->assertArrayHasKey( 'slug', $properties );
		$this->assertArrayHasKey( 'source_url', $properties );
		$this->assertArrayHasKey( 'title_raw', $properties );
		$this->assertArrayHasKey( 'title_rendered', $properties );
	}

	/**
	 * A site that allows no uploads has no media types to list, so the schema leaves the enum
	 * out rather than publish an empty one.
	 *
	 * @since x.x.x
	 */
	public function test_media_type_has_no_enum_when_no_mime_types_are_allowed(): void {
		add_filter( 'upload_mimes', '__return_empty_array' ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes -- Allows no types at all.
		$this->register_ability();

		$this->assertSame(
			array( 'type' => 'string' ),
			wp_get_ability( 'core/media-query' )->get_input_schema()['oneOf'][1]['properties']['media_type']['items']
		);
	}

	/**
	 * Lists the attachments a subscriber can read: an attachment of a draft post is left out.
	 *
	 * @since x.x.x
	 */
	public function test_get_items(): void {
		wp_set_current_user( 0 );
		$id1            = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$draft_post     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id2            = self::factory()->attachment->create_object(
			self::$test_file,
			$draft_post,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$published_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$id3            = self::factory()->attachment->create_object(
			self::$test_file,
			$published_post,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$this->login_as( 'subscriber' );
		$data = $this->execute( array( 'fields' => $this->get_view_fields() ) );
		$this->assertCount( 2, $data['media'] );
		$ids = wp_list_pluck( $data['media'], 'id' );
		$this->assertContains( $id1, $ids );
		$this->assertNotContains( $id2, $ids );
		$this->assertContains( $id3, $ids );

		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
		foreach ( $data['media'] as $item ) {
			$this->check_post_data( get_post( $item['id'] ), $item );
		}
	}

	/**
	 * An editor also reads the attachment of a draft post.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_logged_in_editor(): void {
		wp_set_current_user( self::$user_ids['editor'] );
		$id1            = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$draft_post     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id2            = self::factory()->attachment->create_object(
			self::$test_file,
			$draft_post,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$published_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$id3            = self::factory()->attachment->create_object(
			self::$test_file,
			$published_post,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$data           = $this->execute();
		$this->assertCount( 3, $data['media'] );
		$ids = wp_list_pluck( $data['media'], 'id' );
		$this->assertContains( $id1, $ids );
		$this->assertContains( $id2, $ids );
		$this->assertContains( $id3, $ids );
	}

	/**
	 * Filters by media type.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_media_type(): void {
		$id1 = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->login_as( 'subscriber' );
		$data = $this->execute();
		$this->assertSame( $id1, $data['media'][0]['id'] );
		// Videos only.
		$data = $this->execute( array( 'media_type' => 'video' ) );
		$this->assertCount( 0, $data['media'] );
		// Images only.
		$data = $this->execute( array( 'media_type' => 'image' ) );
		$this->assertSame( $id1, $data['media'][0]['id'] );
	}

	/**
	 * Filters by several media types given as a CSV string or an array.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_multiple_media_types(): void {
		$image_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
			)
		);

		$video_id = self::factory()->attachment->create_object(
			self::$test_video_file,
			0,
			array(
				'post_mime_type' => 'video/mp4',
			)
		);

		$audio_id = self::factory()->attachment->create_object(
			self::$test_audio_file,
			0,
			array(
				'post_mime_type' => 'audio/mpeg',
			)
		);

		$this->login_as( 'subscriber' );

		// Test single media type.
		$data = $this->execute( array( 'media_type' => 'image' ) )['media'];
		$this->assertCount( 1, $data, 'Response count for single media type is not 1' );
		$this->assertSame( $image_id, $data[0]['id'], 'Image ID not found in response for single media type' );

		// Test multiple media types with comma-separated string.
		$data = $this->execute( array( 'media_type' => 'image,video' ) )['media'];
		$this->assertCount( 2, $data, 'Response count for multiple media types with comma-separated string is not 2' );
		$ids = wp_list_pluck( $data, 'id' );
		$this->assertContains( $image_id, $ids, 'Image ID not found in response for multiple media types with comma-separated string' );
		$this->assertContains( $video_id, $ids, 'Video ID not found in response for multiple media types with comma-separated string' );
		$this->assertNotContains( $audio_id, $ids, 'Audio ID found in response for multiple media types with comma-separated string' );

		// Test multiple media types with array format.
		$data = $this->execute( array( 'media_type' => array( 'image', 'video', 'audio' ) ) )['media'];
		$this->assertCount( 3, $data, 'Response count for multiple media types with array format is not 3' );
		$ids = wp_list_pluck( $data, 'id' );
		$this->assertContains( $image_id, $ids, 'Image ID not found in response for multiple media types with array format' );
		$this->assertContains( $video_id, $ids, 'Video ID not found in response for multiple media types with array format' );
		$this->assertContains( $audio_id, $ids, 'Audio ID not found in response for multiple media types with array format' );

		// Test invalid media type mixed with valid ones.
		$this->assertErrorResponse( 'ability_invalid_input', $this->execute( array( 'media_type' => 'image,invalid,video' ) ) );
	}

	/**
	 * Filters by several MIME types given as a CSV string or an array.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_multiple_mime_types_and_combination(): void {
		$jpeg_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
			)
		);

		$png_id = self::factory()->attachment->create_object(
			self::$test_file2,
			0,
			array(
				'post_mime_type' => 'image/png',
			)
		);

		$mp4_id = self::factory()->attachment->create_object(
			self::$test_video_file,
			0,
			array(
				'post_mime_type' => 'video/mp4',
			)
		);

		$this->login_as( 'subscriber' );

		// Test single MIME type.
		$data = $this->execute( array( 'mime_type' => 'image/jpeg' ) )['media'];
		$this->assertCount( 1, $data, 'Response count for single MIME type is not 1' );
		$this->assertSame( $jpeg_id, $data[0]['id'], 'JPEG ID not found in response for single MIME type' );

		// Test multiple MIME types with comma-separated string.
		$data = $this->execute( array( 'mime_type' => 'image/jpeg,image/png' ) )['media'];
		$this->assertCount( 2, $data, 'Response count for multiple MIME types with comma-separated string is not 2' );
		$ids = wp_list_pluck( $data, 'id' );
		$this->assertContains( $jpeg_id, $ids, 'JPEG ID not found in response for multiple MIME types with comma-separated string' );
		$this->assertContains( $png_id, $ids, 'PNG ID not found in response for multiple MIME types with comma-separated string' );

		// Test multiple MIME types with array format.
		$data = $this->execute( array( 'mime_type' => array( 'image/jpeg', 'video/mp4' ) ) )['media'];
		$this->assertCount( 2, $data, 'Response count for multiple MIME types with array format is not 2' );
		$ids = wp_list_pluck( $data, 'id' );

		$this->assertContains( $jpeg_id, $ids, 'JPEG ID not found in response for multiple MIME types with array format' );
		$this->assertContains( $mp4_id, $ids, 'MP4 ID not found in response for multiple MIME types with array format' );
	}

	/**
	 * Media type and MIME type filters add up.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_with_media_type_and_media_types(): void {
		$audio_id = self::factory()->attachment->create_object(
			self::$test_audio_file,
			0,
			array(
				'post_mime_type' => 'audio/mpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);

		$jpeg_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);

		$png_id = self::factory()->attachment->create_object(
			self::$test_file2,
			0,
			array(
				'post_mime_type' => 'image/png',
			)
		);

		$video_id = self::factory()->attachment->create_object(
			self::$test_video_file,
			0,
			array(
				'post_mime_type' => 'video/mp4',
			)
		);

		$rtf_id = self::factory()->attachment->create_object(
			self::$test_rtf_file,
			0,
			array(
				'post_mime_type' => 'application/rtf',
			)
		);

		$this->login_as( 'subscriber' );

		// Test combination of single media type and single mime type parameters.
		$data = $this->execute(
			array(
				'media_type' => 'image',
				'mime_type'  => 'audio/mpeg',
			)
		)['media'];
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertCount( 3, $data, 'Response count for combination of single media type and single mime type parameters is not 3' );
		$this->assertContains( $jpeg_id, $ids, 'JPEG ID not found in response' );
		$this->assertContains( $png_id, $ids, 'PNG ID not found in response' );
		$this->assertContains( $audio_id, $ids, 'Audio ID found in response' );

		// Test combination of single media type and multiple mime type parameters.
		$data = $this->execute(
			array(
				'media_type' => 'audio',
				'mime_type'  => array( 'image/jpeg', 'image/png' ),
			)
		)['media'];
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertCount( 3, $data, 'Response count for combination of single media type and multiple mime type parameters is not 3' );
		$this->assertContains( $audio_id, $ids, 'Audio ID not found in response' );
		$this->assertContains( $jpeg_id, $ids, 'JPEG ID not found in response' );
		$this->assertContains( $png_id, $ids, 'PNG ID not found in response' );

		// Test combination of multiple media types and single mime type parameters.
		$data = $this->execute(
			array(
				'media_type' => 'audio,video',
				'mime_type'  => array( 'image/jpeg' ),
			)
		)['media'];
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertCount( 3, $data, 'Response count for combination of multiple media type and multiple mime type parameters is not 3' );
		$this->assertContains( $audio_id, $ids, 'Audio ID not found in response' );
		$this->assertContains( $jpeg_id, $ids, 'JPEG ID not found in response' );
		$this->assertContains( $video_id, $ids, 'Video ID not found in response' );

		// Test combination of multiple media types and multiple mime type parameters.
		$data = $this->execute(
			array(
				'media_type' => 'audio,video',
				'mime_type'  => array( 'image/jpeg', 'image/png', 'application/rtf' ),
			)
		)['media'];
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertCount( 5, $data, 'Response count for combination of multiple media type and multiple mime type parameters is not 3' );
		$this->assertContains( $audio_id, $ids, 'Audio ID not found in response' );
		$this->assertContains( $jpeg_id, $ids, 'JPEG ID not found in response' );
		$this->assertContains( $video_id, $ids, 'Video ID not found in response' );
		$this->assertContains( $png_id, $ids, 'PNG ID not found in response' );
		$this->assertContains( $rtf_id, $ids, 'RTF ID not found in response' );
	}

	/**
	 * Filters by MIME type.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_mime_type(): void {
		$id1 = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->login_as( 'subscriber' );
		$data = $this->execute();
		$this->assertSame( $id1, $data['media'][0]['id'] );
		// PNG images only.
		$data = $this->execute( array( 'mime_type' => 'image/png' ) );
		$this->assertCount( 0, $data['media'] );
		// JPEG images only.
		$data = $this->execute( array( 'mime_type' => 'image/jpeg' ) );
		$this->assertSame( $id1, $data['media'][0]['id'] );
	}

	/**
	 * Filters by parent, where 0 selects the unattached items.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_parent(): void {
		$post_id        = self::factory()->post->create( array( 'post_title' => 'Test Post' ) );
		$attachment_id  = self::factory()->attachment->create_object(
			self::$test_file,
			$post_id,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$attachment_id2 = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$this->login_as( 'subscriber' );
		// All attachments.
		$data = $this->execute();
		$this->assertCount( 2, $data['media'] );
		// Attachments without a parent.
		$data = $this->execute( array( 'parent' => 0 ) )['media'];
		$this->assertCount( 1, $data );
		$this->assertSame( $attachment_id2, $data[0]['id'] );
		// Attachments with parent=post_id.
		$data = $this->execute( array( 'parent' => $post_id ) )['media'];
		$this->assertCount( 1, $data );
		$this->assertSame( $attachment_id, $data[0]['id'] );
		// Attachments with invalid parent.
		$data = $this->execute( array( 'parent' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) )['media'];
		$this->assertCount( 0, $data );
	}

	/**
	 * A status attachments never have is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_invalid_status_param_is_error_response(): void {
		wp_set_current_user( self::$user_ids['editor'] );
		self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$this->assertErrorResponse( 'ability_invalid_input', $this->execute( array( 'status' => 'publish' ) ) );
	}

	/**
	 * The private status needs permission to read private posts.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_private_status(): void {
		wp_set_current_user( 0 );
		$attachment_id1 = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
				'post_status'    => 'private',
			)
		);
		// Users without the capability can't make the request.
		$this->login_as( 'subscriber' );
		$result = $this->execute( array( 'status' => 'private' ) );
		$this->assertErrorResponse( 'media_invalid_param', $result, 400 );
		// Properly authorized users can make the request.
		wp_set_current_user( self::$user_ids['editor'] );
		$data = $this->execute( array( 'status' => 'private' ) );
		$this->assertSame( $attachment_id1, $data['media'][0]['id'] );
	}

	/**
	 * Several statuses can be requested together.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_multiple_statuses(): void {
		wp_set_current_user( 0 );
		$attachment_id1 = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
				'post_status'    => 'private',
			)
		);
		$attachment_id2 = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
				'post_status'    => 'trash',
			)
		);
		// Users without the capability can't make the request.
		$this->login_as( 'subscriber' );
		$result = $this->execute( array( 'status' => array( 'private', 'trash' ) ) );
		$this->assertErrorResponse( 'media_invalid_param', $result, 400 );
		// Properly authorized users can make the request.
		wp_set_current_user( self::$user_ids['editor'] );
		$data = $this->execute( array( 'status' => array( 'private', 'trash' ) ) )['media'];
		$this->assertCount( 2, $data );
		$ids = array(
			$data[0]['id'],
			$data[1]['id'],
		);
		sort( $ids );
		$this->assertSame( array( $attachment_id1, $attachment_id2 ), $ids );
	}

	/**
	 * An empty first page runs no count query.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_avoid_duplicated_count_query_if_no_items(): void {
		$this->login_as( 'subscriber' );
		add_filter( 'posts_clauses', array( $this, 'save_posts_clauses' ), 10, 2 );

		$data = $this->execute( array( 'media_type' => 'video' ) );

		$this->assertCount( 1, $this->posts_clauses );
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( 0, $data['total_pages'] );
	}

	/**
	 * An empty later page runs a count query, then reports the page as out of range.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_with_empty_page_runs_count_query_after(): void {
		self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_date'      => '2022-06-12T00:00:00Z',
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);

		$this->login_as( 'subscriber' );
		add_filter( 'posts_clauses', array( $this, 'save_posts_clauses' ), 10, 2 );

		$result = $this->execute(
			array(
				'media_type' => 'image',
				'page'       => 2,
			)
		);

		$this->assertCount( 2, $this->posts_clauses );

		$this->assertErrorResponse( 'media_post_invalid_page_number', $result, 400 );
	}

	/**
	 * Returns a single attachment.
	 *
	 * @since x.x.x
	 */
	public function test_get_item(): void {
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Sample alt text' );
		$this->login_as( 'subscriber' );
		$data = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => $this->get_view_fields(),
			)
		);
		$this->check_post_data( get_post( $attachment_id ), $data );
		$this->assertSame( 'image/jpeg', $data['mime_type'] );
	}

	/**
	 * Ensures int-castable `filesize` values in attachment metadata are normalized
	 * to an integer in the response.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_valid_filesize_meta
	 *
	 * @param mixed $stored_filesize   Valid `filesize` metadata value.
	 * @param int   $expected_filesize Expected `filesize` value after normalization.
	 */
	public function test_get_item_normalizes_int_castable_filesize_meta( $stored_filesize, int $expected_filesize ): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => self::$test_file,
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertIsInt( $attachment_id );

		$meta             = wp_get_attachment_metadata( $attachment_id );
		$meta             = is_array( $meta ) ? $meta : array();
		$meta['filesize'] = $stored_filesize;
		$this->assertNotFalse( wp_update_attachment_metadata( $attachment_id, $meta ) );

		$this->login_as( 'subscriber' );
		$data = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'filesize' ),
			)
		);

		$this->assertIsArray( $data );
		$this->assertSame( $expected_filesize, $data['filesize'] );
	}

	/**
	 * Data provider.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{ 0: mixed, 1: int }>
	 */
	public function data_valid_filesize_meta(): array {
		return array(
			'integer string'      => array( '123456', 123456 ),
			'float string'        => array( '123.4', 123 ),
			'scientific notation' => array( '1e3', 1000 ),
			'float'               => array( 123.0, 123 ),
		);
	}

	/**
	 * Ensures a `filesize` metadata value that is not a positive number falls back to the
	 * actual file size.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_filesize_meta
	 *
	 * @param mixed $filesize Invalid `filesize` metadata value.
	 */
	public function test_get_item_recovers_from_invalid_filesize_meta( $filesize ): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => self::$test_file,
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertIsInt( $attachment_id );

		$meta             = wp_get_attachment_metadata( $attachment_id );
		$meta             = is_array( $meta ) ? $meta : array();
		$meta['filesize'] = $filesize;
		$this->assertNotFalse( wp_update_attachment_metadata( $attachment_id, $meta ) );

		$this->login_as( 'subscriber' );
		$data = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'filesize' ),
			)
		);

		$this->assertIsArray( $data );
		$this->assertIsInt( $data['filesize'] );
		$attached_file = wp_get_original_image_path( $attachment_id );
		$attached_file = $attached_file ? $attached_file : get_attached_file( $attachment_id );
		$this->assertIsString( $attached_file );
		$this->assertSame( filesize( $attached_file ), $data['filesize'] );
	}

	/**
	 * Data provider.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{ 0: mixed }>
	 */
	public function data_invalid_filesize_meta(): array {
		return array(
			'non-numeric string'      => array( 'corrupt' ),
			'boolean'                 => array( true ),
			'zero'                    => array( 0 ),
			'zero string'             => array( '0' ),
			'negative integer'        => array( -5 ),
			'negative integer string' => array( '-5' ),
		);
	}

	/**
	 * The media details list each image size with its URL, plus the full size.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_get_item_sizes(): void {
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);

		add_image_size( 'rest-api-test', 119, 119, true );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, self::$test_file ) );

		$this->login_as( 'subscriber' );
		$data               = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'media_details' ),
			)
		);
		$image_src          = wp_get_attachment_image_src( $attachment_id, 'rest-api-test' );
		$original_image_src = wp_get_attachment_image_src( $attachment_id, 'full' );
		remove_image_size( 'rest-api-test' );

		$this->assertIsArray( $data['media_details']['sizes'], 'Could not retrieve the sizes data.' );
		$this->assertSame( $image_src[0], $data['media_details']['sizes']['rest-api-test']['source_url'] );
		$this->assertSame( 'image/jpeg', $data['media_details']['sizes']['rest-api-test']['mime_type'] );
		$this->assertSame( $original_image_src[0], $data['media_details']['sizes']['full']['source_url'] );
		$this->assertSame( 'image/jpeg', $data['media_details']['sizes']['full']['mime_type'] );
	}

	/**
	 * A size without a URL is listed without one.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_get_item_sizes_with_no_url(): void {
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);

		add_image_size( 'rest-api-test', 119, 119, true );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, self::$test_file ) );

		add_filter( 'wp_get_attachment_image_src', '__return_false' );

		$this->login_as( 'subscriber' );
		$data = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'media_details' ),
			)
		);
		remove_filter( 'wp_get_attachment_image_src', '__return_false' );
		remove_image_size( 'rest-api-test' );

		$this->assertIsArray( $data['media_details']['sizes'], 'Could not retrieve the sizes data.' );
		$this->assertArrayNotHasKey( 'source_url', $data['media_details']['sizes']['rest-api-test'] );
	}

	/**
	 * The attachment of a draft post is not readable without access to the post.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_private_post_not_authenticated(): void {
		wp_set_current_user( 0 );
		$draft_post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$id1        = self::factory()->attachment->create_object(
			self::$test_file,
			$draft_post,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$this->assertErrorResponse( 'ability_invalid_permissions', $this->execute( array( 'id' => $id1 ) ) );
		$this->login_as( 'subscriber' );
		$this->assertErrorResponse( 'ability_invalid_permissions', $this->execute( array( 'id' => $id1 ) ) );
	}

	/**
	 * An attachment whose parent no longer exists is readable, like an unattached one.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_inherit_status_with_invalid_parent(): void {
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);
		$this->login_as( 'subscriber' );
		$data = $this->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $data );
		$this->assertSame( $attachment_id, $data['id'] );
	}

	/**
	 * An auto-draft attachment whose parent no longer exists is not readable.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_auto_status_with_invalid_parent_not_authenticated_returns_error(): void {
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
				'post_status'    => 'auto-draft',
			)
		);
		$this->login_as( 'subscriber' );

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->execute( array( 'id' => $attachment_id ) ) );
	}

	/**
	 * Every readable field holds the attachment's value.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_item(): void {
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
				'post_author'    => self::$user_ids['editor'],
			)
		);

		$this->login_as( 'subscriber' );
		$attachment = get_post( $attachment_id );
		$data       = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => $this->get_view_fields(),
			)
		);
		$this->check_post_data( $attachment, $data );
	}

	/**
	 * Only the requested fields are returned.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_item_limit_fields(): void {
		$attachment_id = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
				'post_author'    => self::$user_ids['editor'],
			)
		);
		wp_set_current_user( self::$user_ids['editor'] );
		$data = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'id', 'slug' ),
			)
		);
		$this->assertSame(
			array(
				'id',
				'slug',
			),
			array_keys( $data )
		);
	}

	/**
	 * The search matches file names.
	 *
	 * @since x.x.x
	 */
	public function test_search_item_by_filename(): void {
		$id1 = self::factory()->attachment->create_object(
			self::$test_file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
			)
		);
		$id2 = self::factory()->attachment->create_object(
			self::$test_file2,
			0,
			array(
				'post_mime_type' => 'image/png',
			)
		);

		$filename = wp_basename( self::$test_file2 );

		$this->login_as( 'subscriber' );
		$data = $this->execute( array( 'search' => $filename ) )['media'];

		$this->assertCount( 1, $data );
		$this->assertSame( $id2, $data[0]['id'] );
		$this->assertSame( 'image/png', $data[0]['mime_type'] );
		$this->assertNotSame( $id1, $data[0]['id'] );
	}

	/**
	 * Without input, the ability lists the readable items with totals.
	 *
	 * @since x.x.x
	 */
	public function test_omitted_input_lists_the_collection(): void {
		$attachment_id = $this->create_fixture( 'unattached' );

		$this->login_as( 'subscriber' );
		$result = $this->execute( null );

		$this->assertIsArray( $result, 'Omitted input should list the collection.' );
		$this->assertSame( array( 'media', 'total', 'total_pages' ), array_keys( $result ), 'The collection should return the list and its totals.' );
		$this->assertSame( array( $attachment_id ), wp_list_pluck( $result['media'], 'id' ), 'The collection should list the readable item.' );
		$this->assertSame( 1, $result['total'], 'The total should count the item.' );
		$this->assertSame( 1, $result['total_pages'], 'One item fits on one page.' );
	}

	/**
	 * A single item is returned directly, with the default fields.
	 *
	 * @since x.x.x
	 */
	public function test_id_mode_returns_the_item_with_the_default_fields(): void {
		$attachment_id = $this->create_fixture( 'unattached' );

		$this->login_as( 'subscriber' );
		$result = $this->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result, 'A readable item should be returned.' );
		$this->assertSame(
			array( 'id', 'date', 'slug', 'title_rendered', 'alt_text', 'media_type', 'mime_type', 'source_url' ),
			array_keys( $result ),
			'The item should have the default fields, in output order.'
		);
	}

	/**
	 * The requested fields come back in output order, always with the ID.
	 *
	 * @since x.x.x
	 */
	public function test_fields_limit_the_returned_keys_and_always_include_the_id(): void {
		$this->create_fixture( 'unattached' );

		$this->login_as( 'subscriber' );
		$result = $this->execute( array( 'fields' => array( 'filename', 'post' ) ) );

		$this->assertSame( array( 'id', 'post', 'filename' ), array_keys( $result['media'][0] ), 'The item should have the ID and the requested fields.' );
		$this->assertNull( $result['media'][0]['post'], 'An unattached item has no post.' );
		$this->assertSame( 'canola.jpg', $result['media'][0]['filename'], 'The file name should be returned.' );
	}

	/**
	 * Parameters the ability does not take fail input validation.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_unsupported_input
	 *
	 * @param array<string, mixed> $input The ability input.
	 */
	public function test_unsupported_input_fails_schema_validation( array $input ): void {
		$this->login_as( 'administrator' );

		$this->assertErrorResponse( 'ability_invalid_input', $this->execute( $input ) );
	}

	/**
	 * Data provider.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{ 0: array<string, mixed> }>
	 */
	public function data_unsupported_input(): array {
		return array(
			'id with a collection parameter' => array(
				array(
					'id'       => 1,
					'per_page' => 1,
				),
			),
			'zero id'                        => array( array( 'id' => 0 ) ),
			'unknown field'                  => array( array( 'fields' => array( 'guid' ) ) ),
			'REST context'                   => array( array( 'context' => 'edit' ) ),
			'REST fields'                    => array( array( '_fields' => 'id' ) ),
			'date filter'                    => array( array( 'after' => '2020-01-01T00:00:00' ) ),
			'slug filter'                    => array( array( 'slug' => 'canola' ) ),
			'include_slugs order'            => array( array( 'orderby' => 'include_slugs' ) ),
			'per_page above the maximum'     => array( array( 'per_page' => 101 ) ),
		);
	}

	/**
	 * Raw fields are returned to a user who can edit the item.
	 *
	 * @since x.x.x
	 */
	public function test_raw_fields_are_returned_to_an_editor(): void {
		$attachment_id = $this->create_fixture(
			'unattached',
			array(
				'post_title'   => 'Raw title',
				'post_excerpt' => 'Raw caption',
				'post_content' => 'Raw description',
			)
		);

		$this->login_as( 'editor' );
		$fields = array( 'title_raw', 'description_raw', 'caption_raw' );

		$this->assertSame(
			array(
				'id'              => $attachment_id,
				'title_raw'       => 'Raw title',
				'description_raw' => 'Raw description',
				'caption_raw'     => 'Raw caption',
			),
			$this->execute(
				array(
					'id'     => $attachment_id,
					'fields' => $fields,
				)
			),
			'An editor should get the raw fields of a single item.'
		);
		$this->assertSame( 'Raw title', $this->execute( array( 'fields' => $fields ) )['media'][0]['title_raw'], 'An editor should get the raw fields in a collection.' );
	}

	/**
	 * Raw fields need edit access: an item the user cannot edit is denied, and a collection
	 * needs permission to edit posts.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_roles_without_edit_access_to_other_users_items
	 *
	 * @param string $role The role to test.
	 */
	public function test_raw_fields_are_denied_without_edit_access( string $role ): void {
		$attachment_id = $this->create_fixture( 'unattached' );

		$this->login_as( $role );

		$this->assertErrorResponse(
			'ability_invalid_permissions',
			$this->execute(
				array(
					'id'     => $attachment_id,
					'fields' => array( 'title_raw' ),
				)
			),
			null,
			'Raw fields of an item the user cannot edit should be denied.'
		);

		$collection = $this->execute( array( 'fields' => array( 'caption_raw' ) ) );
		if ( 'subscriber' === $role ) {
			$this->assertErrorResponse( 'ability_invalid_permissions', $collection, null, 'Raw fields in a collection need permission to edit posts.' );
			return;
		}

		$this->assertSame( array(), $collection['media'], 'Items the user cannot edit should be left out.' );
		$this->assertSame( 1, $collection['total'], 'The total should still count them.' );
	}

	/**
	 * Data provider.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{ 0: string }>
	 */
	public function data_roles_without_edit_access_to_other_users_items(): array {
		return array(
			'subscriber'  => array( 'subscriber' ),
			'contributor' => array( 'contributor' ),
			'author'      => array( 'author' ),
		);
	}

	/**
	 * With raw fields, a collection lists only the items the user can edit.
	 *
	 * @since x.x.x
	 */
	public function test_raw_fields_limit_a_collection_to_editable_items(): void {
		$others = $this->create_fixture( 'unattached' );
		$own    = $this->create_fixture(
			'unattached',
			array(
				'post_author' => self::$user_ids['author'],
				'post_title'  => 'Own item',
			)
		);

		$this->login_as( 'author' );
		$view = $this->execute( array( 'fields' => array( 'id' ) ) );
		$edit = $this->execute( array( 'fields' => array( 'title_raw' ) ) );

		$this->assertEqualsCanonicalizing( array( $others, $own ), wp_list_pluck( $view['media'], 'id' ), 'Without raw fields, the author should read both items.' );
		$this->assertSame(
			array(
				array(
					'id'        => $own,
					'title_raw' => 'Own item',
				),
			),
			$edit['media'],
			'With raw fields, the author should only get their own item.'
		);
		$this->assertSame( 2, $edit['total'], 'The total should count both items.' );
	}

	/**
	 * The rendered caption, description, and title go through their display filters.
	 *
	 * @since x.x.x
	 */
	public function test_rendered_fields_apply_the_display_filters(): void {
		$attachment_id = $this->create_fixture(
			'private item',
			array(
				'post_title'   => 'Private item',
				'post_excerpt' => 'A sample caption',
				'post_content' => 'A sample description',
			)
		);

		$this->login_as( 'editor' );
		$data = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'title_rendered', 'caption_rendered', 'description_rendered' ),
			)
		);

		$this->assertSame( 'Private item', $data['title_rendered'], 'The private title prefix should be left out.' );
		$this->assertSame( "<p>A sample caption</p>\n", $data['caption_rendered'], 'The caption should go through the excerpt filters.' );
		$this->assertStringContainsString( '<p class="attachment">', $data['description_rendered'], 'The description should start with the attachment link core adds to the content.' );
		$this->assertStringContainsString( '<p>A sample description</p>', $data['description_rendered'], 'The description should go through the content filters.' );
	}

	/**
	 * Raw fields do not lift an item's password: the rendered caption, which core generates
	 * from the description when the caption is empty, stays gated.
	 *
	 * @since x.x.x
	 */
	public function test_raw_fields_keep_the_password_gate_on_generated_captions(): void {
		$attachment_id = $this->create_fixture(
			'unattached',
			array(
				'post_content'  => 'Secret description',
				'post_password' => 'secret',
			)
		);

		$this->login_as( 'editor' );
		$fields     = array( 'caption_raw', 'caption_rendered' );
		$single     = $this->execute(
			array(
				'id'     => $attachment_id,
				'fields' => $fields,
			)
		);
		$collection = $this->execute( array( 'fields' => $fields ) );

		$this->assertStringContainsString( 'password-protected', $single['caption_rendered'], 'The single item caption should stay gated.' );
		$this->assertStringNotContainsString( 'Secret description', $single['caption_rendered'], 'The single item caption should not reveal the description.' );
		$this->assertStringContainsString( 'password-protected', $collection['media'][0]['caption_rendered'], 'The collection item caption should stay gated.' );
		$this->assertStringNotContainsString( 'Secret description', $collection['media'][0]['caption_rendered'], 'The collection item caption should not reveal the description.' );
	}

	/**
	 * Images and other files are told apart.
	 *
	 * @since x.x.x
	 */
	public function test_media_type_tells_images_from_other_files(): void {
		$image = self::factory()->attachment->create_object( self::$test_file, 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$file  = self::factory()->attachment->create_object( self::$test_rtf_file, 0, array( 'post_mime_type' => 'application/rtf' ) );

		$this->login_as( 'subscriber' );
		$types = wp_list_pluck( $this->execute( array( 'fields' => array( 'media_type', 'mime_type' ) ) )['media'], 'media_type', 'id' );

		$this->assertSame( 'image', $types[ $image ], 'A JPEG should be an image.' );
		$this->assertSame( 'file', $types[ $file ], 'An RTF document should be a file.' );
	}

	/**
	 * The global post, which each prepared item replaces, is restored afterwards.
	 *
	 * @since x.x.x
	 */
	public function test_restores_the_global_post(): void {
		$this->create_fixture( 'unattached' );
		$previous = self::factory()->post->create_and_get();

		$this->login_as( 'subscriber' );
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test sets up a global post.

		$this->execute( array( 'fields' => array( 'description_rendered' ) ) );

		$this->assertSame( $previous, $GLOBALS['post'], 'The previous global post should be restored.' );

		unset( $GLOBALS['post'] );
		global $post;
		$this->execute( array( 'fields' => array( 'description_rendered' ) ) );

		$this->assertNull( $post, 'No global post should be left behind, even where it is bound.' );
	}

	/**
	 * A title filter that throws does not leave the title formats changed for the rest of the
	 * request.
	 *
	 * @since x.x.x
	 */
	public function test_restores_the_title_formats_when_a_title_filter_throws(): void {
		$attachment_id = $this->create_fixture( 'unattached' );
		add_filter(
			'the_title',
			static function () {
				throw new \RuntimeException( 'Broken title filter.' );
			}
		);

		$this->login_as( 'subscriber' );

		$this->assertErrorResponse( 'ability_callback_exception', $this->execute( array( 'id' => $attachment_id ) ) );
		$this->assertFalse( has_filter( 'protected_title_format' ), 'The protected title format should be restored.' );
		$this->assertFalse( has_filter( 'private_title_format' ), 'The private title format should be restored.' );
	}

	/**
	 * Which roles can read an item depends on its status and on its parent.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_visibility_by_role
	 *
	 * @param string $fixture  The item's scenario.
	 * @param string $role     The role to test, or an empty string for a logged-out visitor.
	 * @param bool   $readable Whether the role can read the item.
	 */
	public function test_visibility_by_role( string $fixture, string $role, bool $readable ): void {
		$attachment_id = $this->create_fixture( $fixture );

		if ( '' !== $role ) {
			$this->login_as( $role );
		}

		$result = $this->execute( array( 'id' => $attachment_id ) );
		if ( ! $readable ) {
			$this->assertErrorResponse( 'ability_invalid_permissions', $result, null, 'The item should not be readable.' );
			return;
		}

		$this->assertIsArray( $result, 'The item should be readable.' );
		$this->assertSame( $attachment_id, $result['id'], 'The item should be returned.' );
	}

	/**
	 * Data provider.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{ 0: string, 1: string, 2: bool }>
	 */
	public function data_visibility_by_role(): array {
		$readers = array(
			'unattached'       => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'published parent' => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'draft parent'     => array( 'administrator', 'editor' ),
			'private parent'   => array( 'administrator', 'editor' ),
			'private item'     => array( 'administrator', 'editor' ),
			'trashed item'     => array( 'administrator', 'editor' ),
		);

		$data = array();
		foreach ( $readers as $fixture => $roles ) {
			foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', '' ) as $role ) {
				$data[ $fixture . ', ' . ( '' === $role ? 'logged out' : $role ) ] = array( $fixture, $role, in_array( $role, $roles, true ) );
			}
		}

		return $data;
	}

	/**
	 * A collection leaves out the items the user cannot read, but its totals count them.
	 *
	 * @since x.x.x
	 */
	public function test_totals_count_items_withheld_from_the_user(): void {
		$readable = $this->create_fixture( 'unattached' );
		$this->create_fixture( 'draft parent' );
		$this->create_fixture( 'private parent' );

		$this->login_as( 'subscriber' );
		$result = $this->execute(
			array(
				'per_page' => 2,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);

		$this->assertSame( array( $readable ), wp_list_pluck( $result['media'], 'id' ), 'Only the readable item should be listed.' );
		$this->assertSame( 3, $result['total'], 'The total should count every item of the query.' );
		$this->assertSame( 2, $result['total_pages'], 'The pages should be counted from the total.' );
	}

	/**
	 * A later page of an empty collection reports zero totals rather than an error.
	 *
	 * @since x.x.x
	 */
	public function test_empty_collection_beyond_the_first_page_reports_zero_totals(): void {
		$this->login_as( 'subscriber' );

		$this->assertSame(
			array(
				'media'       => array(),
				'total'       => 0,
				'total_pages' => 0,
			),
			$this->execute( array( 'page' => 3 ) ),
			'An empty collection should report zero totals on any page.'
		);
	}

	/**
	 * The private status is open to users who can edit posts, who only see the private items
	 * they can read.
	 *
	 * @since x.x.x
	 */
	public function test_private_status_lists_only_readable_items_for_a_contributor(): void {
		$others = $this->create_fixture( 'private item' );
		$own    = $this->create_fixture( 'private item', array( 'post_author' => self::$user_ids['contributor'] ) );

		$this->login_as( 'contributor' );
		$result = $this->execute( array( 'status' => 'private' ) );

		$this->assertSame( array( $own ), wp_list_pluck( $result['media'], 'id' ), 'A contributor should only see their own private item.' );
		$this->assertNotContains( $others, wp_list_pluck( $result['media'], 'id' ), 'Another user\'s private item should be left out.' );
		$this->assertSame( 2, $result['total'], 'The total should count both private items.' );
	}

	/**
	 * A missing item is denied like an unreadable one, so IDs cannot be probed.
	 *
	 * @since x.x.x
	 */
	public function test_missing_item_is_denied_like_an_unreadable_item(): void {
		$unreadable = $this->create_fixture( 'draft parent' );
		$post_id    = self::factory()->post->create();

		$this->login_as( 'subscriber' );
		$missing     = $this->execute( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) );
		$forbidden   = $this->execute( array( 'id' => $unreadable ) );
		$not_a_media = $this->execute( array( 'id' => $post_id ) );

		$this->assertErrorResponse( 'ability_invalid_permissions', $missing, null, 'A missing item should be denied.' );
		$this->assertErrorResponse( 'ability_invalid_permissions', $forbidden, null, 'An unreadable item should be denied.' );
		$this->assertErrorResponse( 'ability_invalid_permissions', $not_a_media, null, 'A post that is not an attachment should be denied.' );
		$this->assertSame( $missing->get_error_message(), $forbidden->get_error_message(), 'Both denials should read the same.' );
	}

	/**
	 * Called directly, the execute callback reports an invalid ID.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_reports_an_invalid_id(): void {
		$post_id = self::factory()->post->create();

		$this->login_as( 'administrator' );

		foreach ( array( REST_TESTS_IMPOSSIBLY_HIGH_NUMBER, $post_id, 0, array( 1 ) ) as $id ) {
			$result = ( new Media() )->execute_media_query( array( 'id' => $id ) );

			$this->assertErrorResponse( 'media_post_invalid_id', $result, 404, 'An ID that is not an attachment should be invalid.' );
		}
	}

	/**
	 * Without REST exposure of the attachment type, nothing is readable, not even the totals
	 * of a collection.
	 *
	 * @since x.x.x
	 */
	public function test_items_are_not_readable_when_attachments_are_hidden_from_rest(): void {
		$attachment_id = $this->create_fixture( 'unattached' );

		$this->login_as( 'administrator' );
		$post_type               = get_post_type_object( 'attachment' );
		$post_type->show_in_rest = false;

		$single     = $this->execute( array( 'id' => $attachment_id ) );
		$collection = $this->execute();

		$post_type->show_in_rest = true;

		$this->assertErrorResponse( 'ability_invalid_permissions', $single, null, 'The item should not be readable.' );
		$this->assertErrorResponse( 'ability_invalid_permissions', $collection, null, 'The collection should be denied.' );
	}

	/**
	 * Ordering by relevance needs a search term.
	 *
	 * @since x.x.x
	 */
	public function test_orderby_relevance_requires_a_search(): void {
		$this->login_as( 'subscriber' );

		$result = $this->execute( array( 'orderby' => 'relevance' ) );

		$this->assertErrorResponse( 'media_no_search_term_defined', $result, 400 );
	}

	/**
	 * Ordering by include needs included IDs, and then keeps their order.
	 *
	 * @since x.x.x
	 */
	public function test_orderby_include_requires_and_keeps_the_included_ids(): void {
		$first  = $this->create_fixture( 'unattached' );
		$second = $this->create_fixture( 'unattached' );

		$this->login_as( 'subscriber' );

		$this->assertErrorResponse( 'media_orderby_include_missing_include', $this->execute( array( 'orderby' => 'include' ) ) );
		$this->assertSame(
			array( $first, $second ),
			wp_list_pluck(
				$this->execute(
					array(
						'include' => array( $first, $second ),
						'orderby' => 'include',
					)
				)['media'],
				'id'
			),
			'The items should follow the include order.'
		);
	}

	/**
	 * Items are ordered by date, newest first, unless asked otherwise.
	 *
	 * @since x.x.x
	 */
	public function test_order_and_orderby(): void {
		$older = $this->create_fixture(
			'unattached',
			array(
				'post_date'  => '2020-01-01 00:00:00',
				'post_title' => 'B',
			)
		);
		$newer = $this->create_fixture(
			'unattached',
			array(
				'post_date'  => '2021-01-01 00:00:00',
				'post_title' => 'A',
			)
		);

		$this->login_as( 'subscriber' );

		$this->assertSame( array( $newer, $older ), wp_list_pluck( $this->execute()['media'], 'id' ), 'Newest first by default.' );
		$this->assertSame( array( $older, $newer ), wp_list_pluck( $this->execute( array( 'order' => 'asc' ) )['media'], 'id' ), 'Oldest first in ascending order.' );
		$this->assertSame(
			array( $newer, $older ),
			wp_list_pluck(
				$this->execute(
					array(
						'orderby' => 'title',
						'order'   => 'asc',
					)
				)['media'],
				'id'
			),
			'By title in ascending order.'
		);
	}

	/**
	 * The author, include, and exclude filters narrow the collection.
	 *
	 * @since x.x.x
	 */
	public function test_author_include_and_exclude_filters(): void {
		$admins = $this->create_fixture( 'unattached' );
		$editor = $this->create_fixture( 'unattached', array( 'post_author' => self::$user_ids['editor'] ) );

		$this->login_as( 'subscriber' );

		$this->assertSame( array( $editor ), wp_list_pluck( $this->execute( array( 'author' => self::$user_ids['editor'] ) )['media'], 'id' ), 'The author filter should select the editor\'s item.' );
		$this->assertSame( array( $admins ), wp_list_pluck( $this->execute( array( 'include' => array( $admins ) ) )['media'], 'id' ), 'The include filter should select the item.' );
		$this->assertSame( array( $editor ), wp_list_pluck( $this->execute( array( 'exclude' => array( $admins ) ) )['media'], 'id' ), 'The exclude filter should drop the item.' ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- The ability's exclude parameter.
	}

	/**
	 * A MIME type the site does not allow is ignored, so the filter does not narrow the list.
	 *
	 * @since x.x.x
	 */
	public function test_unknown_mime_type_is_ignored(): void {
		$attachment_id = $this->create_fixture( 'unattached' );

		$this->login_as( 'subscriber' );

		$this->assertSame( array( $attachment_id ), wp_list_pluck( $this->execute( array( 'mime_type' => 'application/x-unknown' ) )['media'], 'id' ), 'An unknown MIME type should not filter.' );
	}

	/**
	 * GET requests deliver scalars as strings and lists as CSV strings; both forms work.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs(): void {
		$attached = $this->create_fixture( 'published parent' );
		$video    = self::factory()->attachment->create_object(
			self::$test_video_file,
			0,
			array(
				'post_author'    => self::$user_ids['editor'],
				'post_mime_type' => 'video/mp4',
			)
		);

		$this->login_as( 'editor' );

		$this->assertSame( $attached, $this->execute( array( 'id' => (string) $attached ) )['id'], 'A string ID should be read.' );
		$this->assertSame( array( 'id', 'post' ), array_keys( $this->execute( array( 'fields' => 'post,id' ) )['media'][0] ), 'A CSV field list should be read.' );

		$page = $this->execute(
			array(
				'per_page' => '1',
				'page'     => '2',
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$this->assertSame( array( $video ), wp_list_pluck( $page['media'], 'id' ), 'String page numbers should be read.' );
		$this->assertSame( 2, $page['total_pages'], 'A string page size should be read.' );

		$this->assertSame( array( $video ), wp_list_pluck( $this->execute( array( 'parent' => '0' ) )['media'], 'id' ), 'A string parent should be read.' );
		$this->assertSame( array( $video ), wp_list_pluck( $this->execute( array( 'author' => (string) self::$user_ids['editor'] ) )['media'], 'id' ), 'A string author should be read.' );
		$this->assertEqualsCanonicalizing( array( $attached, $video ), wp_list_pluck( $this->execute( array( 'include' => "{$attached},{$video}" ) )['media'], 'id' ), 'A CSV include list should be read.' );
		$this->assertSame( array( $attached ), wp_list_pluck( $this->execute( array( 'exclude' => (string) $video ) )['media'], 'id' ), 'A string exclude list should be read.' ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- The ability's exclude parameter.
	}
}
