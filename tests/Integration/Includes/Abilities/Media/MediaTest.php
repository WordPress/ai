<?php
/**
 * Integration tests for the core/media-query Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Media
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Media;

use WP_Post;
use WP_UnitTestCase;
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
class MediaTest extends WP_UnitTestCase {

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

		$this->ensure_ability_category( 'content' );
		$this->register_ability();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		$this->remove_added_uploads();

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
	 * Registers the plugin's core/media-query ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Media() )->register();
		} finally {
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
	 * Asserts that a result is an error with the given code.
	 *
	 * @since x.x.x
	 *
	 * @param string $code    The expected error code.
	 * @param mixed  $result  The ability result.
	 * @param string $message Optional. The assertion message.
	 */
	private function assertAbilityError( string $code, $result, string $message = '' ): void {
		$this->assertWPError( $result, $message );
		$this->assertSame( $code, $result->get_error_code(), $message );
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
	 * The collection mode takes the endpoint's query parameters the ability supports.
	 *
	 * @since x.x.x
	 */
	public function test_registered_query_params(): void {
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
	}

	/**
	 * The item schema lists the fields the ability returns.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_schema(): void {
		$properties = wp_get_ability( 'core/media-query' )->get_output_schema()['anyOf'][0]['properties'];

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
		$this->assertAbilityError( 'ability_invalid_input', $this->execute( array( 'media_type' => 'image,invalid,video' ) ) );
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
		$this->assertAbilityError( 'ability_invalid_input', $this->execute( array( 'status' => 'publish' ) ) );
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
		$this->assertAbilityError( 'media_invalid_param', $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
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
		$this->assertAbilityError( 'media_invalid_param', $this->execute( array( 'status' => array( 'private', 'trash' ) ) ) );
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

		$this->assertAbilityError( 'media_post_invalid_page_number', $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
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
		$this->assertAbilityError( 'ability_invalid_permissions', $this->execute( array( 'id' => $id1 ) ) );
		$this->login_as( 'subscriber' );
		$this->assertAbilityError( 'ability_invalid_permissions', $this->execute( array( 'id' => $id1 ) ) );
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

		$this->assertAbilityError( 'ability_invalid_permissions', $this->execute( array( 'id' => $attachment_id ) ) );
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
}
