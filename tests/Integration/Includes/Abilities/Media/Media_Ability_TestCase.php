<?php
/**
 * Shared base for the media abilities integration tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Media
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Media;

use WP_Test_REST_TestCase;
use WordPress\AI\Abilities\Media\Media;

/**
 * Base test case for the media abilities.
 *
 * Provides the shared users, the test files, the ability registration, and the helpers used
 * by the query, upload, update, and delete test cases.
 *
 * @since x.x.x
 */
abstract class Media_Ability_TestCase extends WP_Test_REST_TestCase {

	/**
	 * The ability names registered by the Media class.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	protected const MEDIA_ABILITIES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'core/media-query',
		'core/media-upload',
		'core/media-update',
		'core/media-delete',
	);

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	protected static array $user_ids = array();

	/**
	 * The path to a JPEG test image.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected static string $test_file = '';

	/**
	 * The path to a PNG test image.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected static string $test_file2 = '';

	/**
	 * The path to a test video.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected static string $test_video_file = '';

	/**
	 * The path to a test audio file.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected static string $test_audio_file = '';

	/**
	 * The path to a test RTF document.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected static string $test_rtf_file = '';

	/**
	 * Creates the shared users.
	 *
	 * The uploader role is added in setUp(), as each test rolls back the stored roles.
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
			'uploader'      => $factory->user->create( array( 'role' => 'uploader' ) ),
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

		// A role that can upload files but not edit posts.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- A test role, removed in tearDown().
		add_role(
			'uploader',
			'File upload role',
			array(
				'read'         => true,
				'level_0'      => true,
				'upload_files' => true,
			)
		);

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
		foreach ( self::MEDIA_ABILITIES as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		wp_set_current_user( 0 );

		// The database rollback leaves the role in memory, where setUp() would not store it again.
		remove_role( 'uploader' );

		$this->remove_added_uploads();

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
	protected function copy_test_file( string $path ): string {
		$copy = get_temp_dir() . wp_basename( $path );
		if ( ! file_exists( $copy ) ) {
			copy( DIR_TESTDATA . '/' . $path, $copy );
		}

		return $copy;
	}

	/**
	 * Registers the plugin's media abilities and their category inside faked init actions.
	 *
	 * @since x.x.x
	 */
	protected function register_ability(): void {
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
	 * Creates an attachment of the JPEG test image, authored by the editor unless the
	 * arguments name another author.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args      Optional. Further post fields. Default empty array.
	 * @param int                  $parent_id Optional. The parent post ID. Default 0.
	 * @return int The attachment ID.
	 */
	protected function create_attachment( array $args = array(), int $parent_id = 0 ): int {
		return self::factory()->attachment->create_object(
			self::$test_file,
			$parent_id,
			array_merge(
				array(
					'post_mime_type' => 'image/jpeg',
					'post_excerpt'   => 'A sample caption',
					'post_author'    => self::$user_ids['editor'],
				),
				$args
			)
		);
	}

	/**
	 * Logs in as the shared user with the given role and returns its ID.
	 *
	 * @since x.x.x
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	protected function login_as( string $role ): int {
		wp_set_current_user( self::$user_ids[ $role ] );

		return self::$user_ids[ $role ];
	}

	/**
	 * Runs an ability through the Abilities API and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability name.
	 * @param mixed  $input        The ability input.
	 * @return mixed The ability result.
	 */
	protected function execute_ability( string $ability_name, $input ) {
		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability, sprintf( 'The %s ability should be registered.', $ability_name ) );

		return $ability->execute( $input );
	}

	/**
	 * Returns the contents of a file, base64 encoded.
	 *
	 * @since x.x.x
	 *
	 * @param string $file The file path.
	 * @return string The encoded contents.
	 */
	protected function encode_file( string $file ): string {
		return base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads a local test file.
	}

	/**
	 * Returns the fields of a REST media response that match an ability's returned fields.
	 *
	 * A `_raw` or `_rendered` field reads that key of the endpoint's object field.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $rest   The REST response data.
	 * @param list<string>         $fields The ability's field names.
	 * @return array<string, mixed> The REST values keyed by the ability's field names.
	 */
	protected function flatten_rest_fields( array $rest, array $fields ): array {
		$flattened = array();

		foreach ( $fields as $field ) {
			if ( preg_match( '/^(.+)_(raw|rendered)$/', $field, $matches ) ) {
				$flattened[ $field ] = $rest[ $matches[1] ][ $matches[2] ];
				continue;
			}

			$flattened[ $field ] = $rest[ $field ];
		}

		return $flattened;
	}
}
