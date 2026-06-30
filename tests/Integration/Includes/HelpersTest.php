<?php
/**
 * Integration tests for helper functions.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use BadMethodCallException;
use ReflectionProperty;
use WP_Connector_Registry;
use WP_UnitTestCase;
use WordPress\AI\Services\Guidelines;
use WordPress\AI\Tests\Integration\Includes\Services\Guidelines_CPT_Helpers;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Stub provider availability used by helper tests.
 *
 * @since 1.0.1
 */
final class Helper_Test_Provider_Availability implements ProviderAvailabilityInterface {

	/**
	 * Whether the stub provider is configured.
	 *
	 * @since 1.0.1
	 *
	 * @var bool
	 */
	public static bool $is_configured = false;

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.1
	 */
	public function isConfigured(): bool {
		return self::$is_configured;
	}
}

/**
 * Stub provider used by helper tests.
 *
 * @since 1.0.1
 */
final class Helper_Test_Provider implements ProviderInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.1
	 */
	public static function metadata(): ProviderMetadata {
		throw new BadMethodCallException( 'Helper_Test_Provider::metadata() should not be called in these tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.1
	 *
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException If model creation is attempted.
	 */
	public static function model( string $model_id, ?ModelConfig $model_config = null ): ModelInterface {
		throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'Helper_Test_Provider::model() should not be called in these tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.1
	 */
	public static function availability(): ProviderAvailabilityInterface {
		return new Helper_Test_Provider_Availability();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.1
	 */
	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		throw new BadMethodCallException( 'Helper_Test_Provider::modelMetadataDirectory() should not be called in these tests.' );
	}
}

/**
 * Stub model metadata used by image generation support tests.
 *
 * @since x.x.x
 */
final class Image_Generation_Test_Model_Metadata {

	/**
	 * Whether the stub model advertises image-generation support.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	public static bool $supports_image_generation = true;

	/**
	 * Returns the stub model's supported capabilities.
	 *
	 * @since x.x.x
	 *
	 * @return list<object{value:string}> Supported capabilities.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata API.
	public function getSupportedCapabilities(): array {
		return array(
			(object) array(
				'value' => self::$supports_image_generation
					? CapabilityEnum::IMAGE_GENERATION
					: CapabilityEnum::TEXT_GENERATION,
			),
		);
	}
}

/**
 * Stub model metadata directory used by image generation support tests.
 *
 * @since x.x.x
 */
final class Image_Generation_Test_Model_Metadata_Directory {

	/**
	 * Whether listing model metadata should throw to simulate a provider failure.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	public static bool $should_throw = false;

	/**
	 * Lists the stub model metadata.
	 *
	 * @since x.x.x
	 *
	 * @throws \RuntimeException When $should_throw is set, to exercise the support-detection guard.
	 *
	 * @return list<\WordPress\AI\Tests\Integration\Includes\Image_Generation_Test_Model_Metadata> Stub model metadata.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client model metadata directory API.
	public function listModelMetadata(): array {
		if ( self::$should_throw ) {
			throw new \RuntimeException( 'Simulated provider failure.' );
		}

		return array( new Image_Generation_Test_Model_Metadata() );
	}
}

/**
 * Stub provider exposing image-generation model metadata for support tests.
 *
 * Mirrors only the static methods that has_image_generation_support() and the AI
 * client registry invoke, so it intentionally does not implement ProviderInterface.
 *
 * @since x.x.x
 */
final class Image_Generation_Test_Provider {

	/**
	 * Returns the stub provider availability.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Tests\Integration\Includes\Helper_Test_Provider_Availability Stub availability reporting configured state.
	 */
	public static function availability(): Helper_Test_Provider_Availability {
		return new Helper_Test_Provider_Availability();
	}

	/**
	 * Returns the stub model metadata directory.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Tests\Integration\Includes\Image_Generation_Test_Model_Metadata_Directory Stub model metadata directory.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client provider API.
	public static function modelMetadataDirectory(): Image_Generation_Test_Model_Metadata_Directory {
		return new Image_Generation_Test_Model_Metadata_Directory();
	}
}

/**
 * Helper functions test case.
 *
 * @since 0.1.0
 */
class HelpersTest extends WP_UnitTestCase {

	use Guidelines_CPT_Helpers;

	/**
	 * Stub provider ID used for API key helper tests.
	 *
	 * @since 1.0.1
	 *
	 * @var string
	 */
	private const TEST_AI_PROVIDER_ID = 'wpai_helper_test_provider';

	/**
	 * Stub provider ID used for image generation support tests.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const TEST_IMAGE_PROVIDER_ID = 'wpai_helper_test_image_provider';

	/**
	 * Registered test connector IDs.
	 *
	 * @since 1.0.0
	 *
	 * @var list<string>
	 */
	private array $test_connector_ids = array();

	/**
	 * Active plugins option value before a test mutates it.
	 *
	 * @since 1.0.0
	 *
	 * @var list<string>
	 */
	private array $active_plugins = array();

	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a user with proper permissions for reading posts.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		Guidelines::reset_cache();

		$this->active_plugins = (array) get_option( 'active_plugins', array() );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		$registry = WP_Connector_Registry::get_instance();
		foreach ( $this->test_connector_ids as $connector_id ) {
			if ( null === $registry || ! $registry->is_registered( $connector_id ) ) {
				continue;
			}

			$registry->unregister( $connector_id );
		}

		update_option( 'active_plugins', $this->active_plugins );
		Guidelines::reset_cache();
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_test-feature_field_developer' );
		Helper_Test_Provider_Availability::$is_configured                = false;
		Image_Generation_Test_Model_Metadata::$supports_image_generation = true;
		Image_Generation_Test_Model_Metadata_Directory::$should_throw    = false;
		$this->unregister_test_ai_provider();
		$this->unregister_test_image_provider();

		// Recompute against the cleaned-up environment so the memoized result does
		// not leak the stub state into other test cases.
		if ( class_exists( AiClient::class ) ) {
			\WordPress\AI\has_image_generation_support( true );
		}
		parent::tearDown();
	}

	/**
	 * Test that normalize_content() strips HTML entities.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_strips_html_entities() {
		$content = 'Test &amp; content &lt;test&gt;';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '&amp;', $result, 'Should remove HTML entities' );
		$this->assertStringNotContainsString( '&lt;', $result, 'Should remove HTML entities' );
		$this->assertStringNotContainsString( '&gt;', $result, 'Should remove HTML entities' );
	}

	/**
	 * Test that normalize_content() replaces HTML linebreaks and removes linebreaks.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_replaces_linebreaks() {
		$content = 'Line 1<br>Line 2<br/>Line 3';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '<br>', $result, 'Should remove br tags' );
		$this->assertStringNotContainsString( "\n", $result, 'Should replace newlines with spaces' );
		$this->assertStringNotContainsString( "\r", $result, 'Should replace carriage returns with spaces' );
		$this->assertStringContainsString( 'Line 1', $result, 'Should preserve Line 1' );
		$this->assertStringContainsString( 'Line 2', $result, 'Should preserve Line 2' );
		$this->assertStringContainsString( 'Line 3', $result, 'Should preserve Line 3' );
	}

	/**
	 * Test that normalize_content() removes linebreaks and replaces with spaces.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_removes_linebreaks() {
		$content = "Line 1\nLine 2\rLine 3\r\nLine 4";
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( "\n", $result, 'Should replace newlines with spaces' );
		$this->assertStringNotContainsString( "\r", $result, 'Should replace carriage returns with spaces' );
		$this->assertStringContainsString( 'Line 1', $result, 'Should preserve Line 1' );
		$this->assertStringContainsString( 'Line 2', $result, 'Should preserve Line 2' );
		$this->assertStringContainsString( 'Line 3', $result, 'Should preserve Line 3' );
		$this->assertStringContainsString( 'Line 4', $result, 'Should preserve Line 4' );
		// Verify lines are separated by spaces, not running together
		$this->assertStringContainsString( 'Line 1 Line 2', $result, 'Lines should be separated by spaces' );
	}

	/**
	 * Test that normalize_content() strips HTML tags.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_strips_html_tags() {
		$content = '<p>Test <strong>content</strong> with <em>HTML</em></p>';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '<p>', $result, 'Should remove HTML tags' );
		$this->assertStringNotContainsString( '<strong>', $result, 'Should remove HTML tags' );
		$this->assertStringNotContainsString( '<em>', $result, 'Should remove HTML tags' );
		$this->assertStringContainsString( 'Test content with HTML', $result, 'Should preserve text content' );
	}

	/**
	 * Test that normalize_content() removes unrendered shortcode tags.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_removes_shortcode_tags() {
		$content = '[shortcode]content[/shortcode]';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '[shortcode]', $result, 'Should remove shortcode tags' );
		$this->assertStringContainsString( 'content', $result, 'Should preserve shortcode content' );
	}

	/**
	 * Test that normalize_content() trims whitespace.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_trims_whitespace() {
		$content = '  Test content  ';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertEquals( 'Test content', $result, 'Should trim whitespace' );
	}

	/**
	 * Test that normalize_content() applies filters.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_applies_filters() {
		add_filter(
			'wpai_pre_normalize_content',
			static function ( $content ) {
				return 'Filtered: ' . $content;
			}
		);

		$result = \WordPress\AI\normalize_content( 'test' );

		$this->assertStringContainsString( 'Filtered:', $result, 'Should apply pre-normalize filter' );

		remove_all_filters( 'wpai_pre_normalize_content' );
	}

	/**
	 * Test that count_characters_excluding_spaces() counts characters excluding spaces.
	 *
	 * @dataProvider data_count_characters_excluding_spaces
	 *
	 * @since x.x.x
	 *
	 * @param string $text The text to count characters in.
	 * @param int    $expected_count The expected count of characters excluding spaces.
	 */
	public function test_count_characters_excluding_spaces( string $text, int $expected_count ) {
		$this->assertSame( $expected_count, \WordPress\AI\count_characters_excluding_spaces( $text ) );
	}

	/**
	 * Data provider for count_characters_excluding_spaces() test.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string, int}>
	 */
	public function data_count_characters_excluding_spaces(): array {
		return array(
			'empty string'                              => array( '', 0 ),
			'only spaces'                               => array( '   ', 0 ),
			'basic text'                                => array( 'Hello world', 10 ),
			'tabs and newlines'                         => array( "Hello\tworld\nagain", 15 ),
			'html tags are ignored'                     => array( '<p>Hello <strong>world</strong></p>', 10 ),
			'html comments are ignored'                 => array( 'Hello <!-- hidden --> world', 10 ),
			'nbsp entities are spaces'                  => array( 'Hello&nbsp;world&#160;', 10 ),
			'entities count as one'                     => array( 'Hello &amp; world', 11 ),
			'unicode letters'                           => array( 'こんにちは 世界', 7 ),
			'full-width cjk space'                      => array( 'こんにちは　世界', 7 ),
			'narrow no-break space'                     => array( "Hello\u{202F}world", 10 ),
			'literal non-breaking space'                => array( "Hello\u{00A0}world", 10 ),
			'multiple html entities count individually' => array( '&copy; &reg; &trade;', 3 ),
			'mixed unicode whitespace only'             => array( " \t\n\u{00A0}\u{202F}\u{3000}", 0 ),
		);
	}

	/**
	 * Test that get_post_context() returns empty array for non-existent post.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_returns_empty_for_nonexistent_post() {
		// Expect the incorrect usage notice when abilities are called with non-existent posts.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$context = \WordPress\AI\get_post_context( 99999 );

		$this->assertIsArray( $context, 'Should return an array' );
		$this->assertEmpty( $context, 'Should return empty array for non-existent post' );
	}

	/**
	 * Test that get_post_context() returns post content.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_returns_post_content() {
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test post content',
				'post_title'   => 'Test Post',
			)
		);

		$context = \WordPress\AI\get_post_context( $post_id );

		$this->assertIsArray( $context, 'Should return an array' );
		$this->assertArrayHasKey( 'content', $context, 'Should have content key' );
		$this->assertNotEmpty( $context['content'], 'Content should not be empty' );
	}

	/**
	 * Test that get_post_context() returns post metadata.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_returns_post_metadata() {
		$author_id = $this->factory->user->create( array( 'display_name' => 'Test Author' ) );
		$post_id   = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post Title',
				'post_name'    => 'test-post-slug',
				'post_author'  => $author_id,
				'post_type'    => 'post',
				'post_excerpt' => 'Test excerpt',
			)
		);

		$context = \WordPress\AI\get_post_context( $post_id );

		$this->assertArrayHasKey( 'title', $context, 'Should have title' );
		$this->assertEquals( 'Test Post Title', $context['title'], 'Title should match' );
		$this->assertArrayHasKey( 'slug', $context, 'Should have slug' );
		$this->assertEquals( 'test-post-slug', $context['slug'], 'Slug should match' );
		$this->assertArrayHasKey( 'author', $context, 'Should have author' );
		$this->assertEquals( 'Test Author', $context['author'], 'Author should match' );
		$this->assertArrayHasKey( 'content_type', $context, 'Should have content_type' );
		$this->assertEquals( 'post', $context['content_type'], 'Content type should match' );
		$this->assertArrayHasKey( 'excerpt', $context, 'Should have excerpt' );
		$this->assertEquals( 'Test excerpt', $context['excerpt'], 'Excerpt should match' );
	}

	/**
	 * Test that get_post_context() includes categories and tags.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_includes_categories_and_tags() {
		$category_id = $this->factory->category->create( array( 'name' => 'Test Category' ) );
		$post_id     = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
			)
		);

		wp_set_post_categories( $post_id, array( $category_id ) );
		wp_set_post_tags( $post_id, array( 'Test Tag' ) );

		$context = \WordPress\AI\get_post_context( $post_id );

		// The get-terms ability returns terms grouped by taxonomy name (e.g., 'category', 'post_tag').
		$this->assertArrayHasKey( 'category', $context, 'Should have category key' );
		$this->assertStringContainsString( 'Test Category', $context['category'], 'Should include category name' );
		$this->assertArrayHasKey( 'post_tag', $context, 'Should have post_tag key' );
		$this->assertStringContainsString( 'Test Tag', $context['post_tag'], 'Should include tag name' );
	}

	/**
	 * Test that the get-post-terms ability exposes a valid output schema.
	 *
	 * @since 1.0.2
	 */
	public function test_get_post_terms_output_schema_is_valid_json_schema() {
		$ability = wp_get_ability( 'ai/get-post-terms' );
		$this->assertNotNull( $ability, 'get-post-terms ability should be registered' );

		$output_schema = $ability->get_output_schema();

		$this->assertSame( 'array', $output_schema['type'], 'Output schema should describe the list of term objects returned by the ability.' );
		$this->assertArrayNotHasKey( 'properties', $output_schema, 'Output schema should not nest array keywords under properties.' );
		$this->assertSame( 'object', $output_schema['items']['type'], 'Output schema items should describe term objects.' );
		$this->assertSame( 'integer', $output_schema['items']['properties']['term_id']['type'], 'Term schema should include term_id.' );
		$this->assertSame( 'string', $output_schema['items']['properties']['name']['type'], 'Term schema should include name.' );
		$this->assertSame( 'string', $output_schema['items']['properties']['taxonomy']['type'], 'Term schema should include taxonomy.' );
		$this->assertNotFalse( wp_json_encode( $output_schema ), 'Output schema should be JSON-encodable.' );
	}

	/**
	 * Test that the wpai_get_post_details filter modifies the ability output.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_details_filter_modifies_output() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content',
			)
		);

		$filter_callback = static function ( $details ) {
			$details['title'] = 'Filtered Title';
			return $details;
		};

		add_filter( 'wpai_get_post_details', $filter_callback );

		$ability = wp_get_ability( 'ai/get-post-details' );
		$this->assertNotNull( $ability, 'get-post-details ability should be registered' );

		$result = $ability->execute( array( 'post_id' => $post_id ) );

		remove_filter( 'wpai_get_post_details', $filter_callback );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'title', $result, 'Result should have title key' );
		$this->assertEquals( 'Filtered Title', $result['title'], 'Filter should have modified the title' );
	}

	/**
	 * Test that the wpai_get_post_details filter receives the correct arguments.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_details_filter_receives_arguments() {
		$post_id = $this->factory->post->create(
			array(
				'post_title' => 'Test Post',
			)
		);

		$filter_callback = static function ( $details, $filter_post_id, $filter_fields ) {
			$details['title'] = sprintf( 'post:%d|fields:%s', $filter_post_id, implode( ',', $filter_fields ) );
			return $details;
		};

		add_filter( 'wpai_get_post_details', $filter_callback, 10, 3 );

		$ability = wp_get_ability( 'ai/get-post-details' );
		$result  = $ability->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'title', 'slug' ),
			)
		);

		remove_filter( 'wpai_get_post_details', $filter_callback, 10 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'title', $result, 'Result should include title' );
		$this->assertSame(
			sprintf( 'post:%d|fields:title,slug', $post_id ),
			$result['title'],
			'Filter output should encode the received post ID and requested fields'
		);
	}

	/**
	 * Test that the wpai_get_post_terms filter modifies the ability output.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_terms_filter_modifies_output() {
		$category_id = $this->factory->category->create( array( 'name' => 'Original Category' ) );
		$post_id     = $this->factory->post->create();
		wp_set_post_categories( $post_id, array( $category_id ) );

		$filter_callback = static function () {
			// Replace terms with an empty array.
			return array();
		};

		add_filter( 'wpai_get_post_terms', $filter_callback );

		$ability = wp_get_ability( 'ai/get-post-terms' );
		$this->assertNotNull( $ability, 'get-post-terms ability should be registered' );

		$result = $ability->execute( array( 'post_id' => $post_id ) );

		remove_filter( 'wpai_get_post_terms', $filter_callback );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEmpty( $result, 'Filter should have replaced the terms with an empty array' );
	}

	/**
	 * Test that the wpai_get_post_terms filter receives the correct arguments.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_terms_filter_receives_arguments() {
		$category_id = $this->factory->category->create( array( 'name' => 'Test Category' ) );
		$post_id     = $this->factory->post->create();
		wp_set_post_categories( $post_id, array( $category_id ) );

		$received_post_id    = null;
		$received_taxonomies = array();

		$filter_callback = static function ( $terms, $filter_post_id, $filter_taxonomies ) use ( &$received_post_id, &$received_taxonomies ) {
			$received_post_id    = $filter_post_id;
			$received_taxonomies = $filter_taxonomies;
			return $terms;
		};

		add_filter( 'wpai_get_post_terms', $filter_callback, 10, 3 );

		$ability = wp_get_ability( 'ai/get-post-terms' );
		$result  = $ability->execute( array( 'post_id' => $post_id ) );

		remove_filter( 'wpai_get_post_terms', $filter_callback, 10 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertSame( $post_id, $received_post_id, 'Filter should receive the post ID.' );
		$this->assertSame( array( 'category', 'post_tag' ), $received_taxonomies, 'Filter should receive the allowed taxonomy names.' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() returns an array.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_returns_array() {
		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertNotEmpty( $result, 'Should not be empty' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() returns expected default models.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_returns_default_models() {
		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertCount( 5, $result, 'Should have 5 preferred models' );

		// Check first model (anthropic).
		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'anthropic', $result[0][0], 'First model provider should be anthropic' );
		$this->assertEquals( 'claude-sonnet-4-6', $result[0][1], 'First model name should be claude-sonnet-4-6' );

		// Check second model (google).
		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-3-flash-preview', $result[1][1], 'Second model name should be gemini-3-flash-preview' );

		// Check third model (google).
		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'google', $result[2][0], 'Third model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash', $result[2][1], 'Third model name should be gemini-2.5-flash' );

		// Check fourth model (openai).
		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[3][0], 'Fourth model provider should be openai' );
		$this->assertEquals( 'gpt-5.4-mini', $result[3][1], 'Fourth model name should be gpt-5.4-mini' );

		// Check fifth model (openai).
		$this->assertIsArray( $result[4], 'Fifth model should be an array' );
		$this->assertCount( 2, $result[4], 'Fifth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[4][0], 'Fifth model provider should be openai' );
		$this->assertEquals( 'gpt-4.1-mini', $result[4][1], 'Fifth model name should be gpt-4.1-mini' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() applies filter.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_applies_filter() {
		add_filter(
			'wpai_preferred_text_models',
			static function ( $models ) {
				// Add a custom model.
				$models[] = array(
					'custom',
					'custom-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertCount( 6, $result, 'Should have 6 models after filter' );
		$this->assertEquals( 'custom', $result[5][0], 'Sixth model provider should be custom' );
		$this->assertEquals( 'custom-model', $result[5][1], 'Sixth model name should be custom-model' );

		remove_all_filters( 'wpai_preferred_text_models' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() filter can replace models.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_filter_can_replace_models() {
		add_filter(
			'wpai_preferred_text_models',
			static function () {
				// Replace with a single model.
				return array(
					array(
						'test',
						'test-model',
					),
				);
			}
		);

		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertCount( 1, $result, 'Should have 1 model after filter replacement' );
		$this->assertEquals( 'test', $result[0][0], 'Model provider should be test' );
		$this->assertEquals( 'test-model', $result[0][1], 'Model name should be test-model' );

		remove_all_filters( 'wpai_preferred_text_models' );
	}

	/**
	 * Test that get_preferred_image_models() returns an array.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_returns_array() {
		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertNotEmpty( $result, 'Should not be empty' );
	}

	/**
	 * Test that get_preferred_image_models() returns expected default models.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_returns_default_models() {
		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 6, $result, 'Should have 6 preferred image models' );

		// Check first model (google).
		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'google', $result[0][0], 'First model provider should be google' );
		$this->assertEquals( 'gemini-3.1-flash-image-preview', $result[0][1], 'First model name should be gemini-3.1-flash-image-preview' );

		// Check second model (google).
		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-3-pro-image-preview', $result[1][1], 'Second model name should be gemini-3-pro-image-preview' );

		// Check third model (google).
		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'google', $result[2][0], 'Third model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash-image', $result[2][1], 'Third model name should be gemini-2.5-flash-image' );

		// Check fourth model (google).
		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'google', $result[3][0], 'Fourth model provider should be google' );
		$this->assertEquals( 'imagen-4.0-generate-001', $result[3][1], 'Fourth model name should be imagen-4.0-generate-001' );

		// Check fifth model (openai).
		$this->assertIsArray( $result[4], 'Fifth model should be an array' );
		$this->assertCount( 2, $result[4], 'Fifth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[4][0], 'Fifth model provider should be openai' );
		$this->assertEquals( 'gpt-image-2', $result[4][1], 'Fifth model name should be gpt-image-2' );

		// Check sixth model (openai).
		$this->assertIsArray( $result[5], 'Sixth model should be an array' );
		$this->assertCount( 2, $result[5], 'Sixth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[5][0], 'Sixth model provider should be openai' );
		$this->assertEquals( 'gpt-image-1.5', $result[5][1], 'Sixth model name should be gpt-image-1.5' );
	}

	/**
	 * Test that get_preferred_image_models() applies filter.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_applies_filter() {
		add_filter(
			'wpai_preferred_image_models',
			static function ( $models ) {
				// Add a custom model.
				$models[] = array(
					'custom',
					'custom-image-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 7, $result, 'Should have 7 models after filter' );
		$this->assertEquals( 'custom', $result[6][0], 'Seventh model provider should be custom' );
		$this->assertEquals( 'custom-image-model', $result[6][1], 'Seventh model name should be custom-image-model' );

		remove_all_filters( 'wpai_preferred_image_models' );
	}

	/**
	 * Test that get_preferred_image_models() filter can replace models.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_filter_can_replace_models() {
		add_filter(
			'wpai_preferred_image_models',
			static function () {
				// Replace with a single model.
				return array(
					array(
						'test',
						'test-image-model',
					),
				);
			}
		);

		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 1, $result, 'Should have 1 model after filter replacement' );
		$this->assertEquals( 'test', $result[0][0], 'Model provider should be test' );
		$this->assertEquals( 'test-image-model', $result[0][1], 'Model name should be test-image-model' );

		remove_all_filters( 'wpai_preferred_image_models' );
	}

	/**
	 * Test that get_preferred_vision_models() returns an array.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_returns_array() {
		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertNotEmpty( $result, 'Should not be empty' );
	}

	/**
	 * Test that get_preferred_vision_models() returns expected default models.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_returns_default_models() {
		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertCount( 5, $result, 'Should have 5 preferred vision models' );

		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'anthropic', $result[0][0], 'First model provider should be anthropic' );
		$this->assertEquals( 'claude-sonnet-4-6', $result[0][1], 'First model name should be claude-sonnet-4-6' );

		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-3-flash-preview', $result[1][1], 'Second model name should be gemini-3-flash-preview' );

		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'google', $result[2][0], 'Third model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash', $result[2][1], 'Third model name should be gemini-2.5-flash' );

		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[3][0], 'Fourth model provider should be openai' );
		$this->assertEquals( 'gpt-5.4-mini', $result[3][1], 'Fourth model name should be gpt-5.4-mini' );

		$this->assertIsArray( $result[4], 'Fifth model should be an array' );
		$this->assertCount( 2, $result[4], 'Fifth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[4][0], 'Fifth model provider should be openai' );
		$this->assertEquals( 'gpt-4.1-mini', $result[4][1], 'Fifth model name should be gpt-4.1-mini' );
	}

	/**
	 * Test that get_preferred_vision_models() applies filter.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_applies_filter() {
		add_filter(
			'wpai_preferred_vision_models',
			static function ( $models ) {
				$models[] = array(
					'custom',
					'custom-vision-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertCount( 6, $result, 'Should have 6 models after filter' );
		$this->assertEquals( 'custom', $result[5][0], 'Sixth model provider should be custom' );
		$this->assertEquals( 'custom-vision-model', $result[5][1], 'Sixth model name should be custom-vision-model' );

		remove_all_filters( 'wpai_preferred_vision_models' );
	}

	/**
	 * Test that get_preferred_vision_models() filter can replace models.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_filter_can_replace_models() {
		add_filter(
			'wpai_preferred_vision_models',
			static function () {
				return array(
					array(
						'test',
						'test-vision-model',
					),
				);
			}
		);

		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertCount( 1, $result, 'Should have 1 model after filter replacement' );
		$this->assertEquals( 'test', $result[0][0], 'Model provider should be test' );
		$this->assertEquals( 'test-vision-model', $result[0][1], 'Model name should be test-vision-model' );

		remove_all_filters( 'wpai_preferred_vision_models' );
	}

	/**
	 * Test that get_ai_connectors() returns active AI provider connectors.
	 *
	 * @since 1.0.0
	 */
	public function test_get_ai_connectors_returns_active_ai_provider_connectors() {
		$this->register_test_connector(
			'wpai_test_active_provider',
			array(
				'name'           => 'Active Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'none',
				),
				'plugin'         => array(
					'file' => 'active-test-provider/active-test-provider.php',
				),
			)
		);
		$this->register_test_connector(
			'wpai_test_inactive_provider',
			array(
				'name'           => 'Inactive Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'none',
				),
				'plugin'         => array(
					'file' => 'inactive-test-provider/inactive-test-provider.php',
				),
			)
		);
		$this->register_test_connector(
			'wpai_test_non_ai_connector',
			array(
				'name'           => 'Non-AI Test Connector',
				'type'           => 'other',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);
		$this->activate_test_plugin( 'active-test-provider/active-test-provider.php' );

		$connectors = \WordPress\AI\get_ai_connectors();

		$this->assertArrayHasKey( 'wpai_test_active_provider', $connectors );
		$this->assertArrayNotHasKey( 'wpai_test_inactive_provider', $connectors );
		$this->assertArrayNotHasKey( 'wpai_test_non_ai_connector', $connectors );
	}

	/**
	 * Test that get_ai_connectors() can return inactive AI provider connectors.
	 *
	 * @since 1.0.0
	 */
	public function test_get_ai_connectors_can_include_inactive_ai_provider_connectors() {
		$this->register_test_connector(
			'wpai_test_inactive_provider',
			array(
				'name'           => 'Inactive Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'none',
				),
				'plugin'         => array(
					'file' => 'inactive-test-provider/inactive-test-provider.php',
				),
			)
		);

		$connectors = \WordPress\AI\get_ai_connectors( false );

		$this->assertArrayHasKey( 'wpai_test_inactive_provider', $connectors );
	}

	/**
	 * Test that is_connector_configured() returns false for unknown connectors.
	 *
	 * @since 1.0.1
	 */
	public function test_is_connector_configured_returns_false_for_unknown_connector(): void {
		$this->assertFalse( \WordPress\AI\is_connector_configured( 'wpai_unknown_provider' ) );
	}

	/**
	 * Test that is_connector_configured() returns false when the provider is not configured.
	 *
	 * @since 1.0.1
	 */
	public function test_is_connector_configured_returns_false_when_unconfigured(): void {
		$this->register_test_ai_provider();
		Helper_Test_Provider_Availability::$is_configured = false;

		$this->assertFalse( \WordPress\AI\is_connector_configured( self::TEST_AI_PROVIDER_ID ) );
	}

	/**
	 * Test that is_connector_configured() returns true when the provider is configured.
	 *
	 * @since 1.0.1
	 */
	public function test_is_connector_configured_returns_true_when_configured(): void {
		$this->register_test_ai_provider();
		Helper_Test_Provider_Availability::$is_configured = true;

		$this->assertTrue( \WordPress\AI\is_connector_configured( self::TEST_AI_PROVIDER_ID ) );
	}

	/**
	 * Test that has_ai_credentials() detects API-key connector credentials.
	 *
	 * @since 1.0.1
	 */
	public function test_has_ai_credentials_detects_configured_api_key_connector(): void {
		$this->register_test_ai_provider();
		$this->register_test_connector(
			self::TEST_AI_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);
		$setting_name = 'connectors_ai_provider_' . self::TEST_AI_PROVIDER_ID . '_api_key';
		update_option( $setting_name, 'test-api-key' );

		try {
			$this->assertTrue( \WordPress\AI\has_ai_credentials() );
		} finally {
			delete_option( $setting_name );
		}
	}

	/**
	 * Test that has_ai_credentials() returns false when no API-key connector has authentication.
	 *
	 * Exercises the loop's continue path: a registered api_key connector with no option,
	 * env var, or constant set must not be treated as credentialed.
	 *
	 * @since 1.0.1
	 */
	public function test_has_ai_credentials_returns_false_when_no_connector_is_configured(): void {
		$this->register_test_ai_provider();
		$this->register_test_connector(
			self::TEST_AI_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);

		$this->assertFalse( \WordPress\AI\has_ai_credentials() );
	}

	/**
	 * Test that has_connector_authentication() returns false for unknown connectors.
	 *
	 * @since 1.0.1
	 */
	public function test_has_connector_authentication_returns_false_for_unknown_connector(): void {
		$this->assertFalse( \WordPress\AI\has_connector_authentication( 'wpai_unknown_provider' ) );
	}

	/**
	 * Test that has_connector_authentication() detects option-based API key auth.
	 *
	 * @since 1.0.1
	 */
	public function test_has_connector_authentication_detects_database_option(): void {
		$connector_id  = 'wpai_test_auth_provider';
		$setting_name  = 'connectors_ai_provider_wpai_test_auth_provider_api_key';
		$connector_data = array(
			'name'           => 'Auth Test Provider',
			'type'           => 'ai_provider',
			'authentication' => array(
				'method' => 'api_key',
			),
		);

		$this->register_test_connector( $connector_id, $connector_data );
		update_option( $setting_name, 'test-api-key' );

		try {
			$this->assertTrue( \WordPress\AI\has_connector_authentication( $connector_id ) );
		} finally {
			delete_option( $setting_name );
		}
	}

	/**
	 * Test that has_connector_authentication() detects env var API key auth.
	 *
	 * @since 1.0.1
	 */
	public function test_has_connector_authentication_detects_environment_variable(): void {
		$connector_id = 'wpai_env_auth_provider';
		$this->register_test_connector(
			$connector_id,
			array(
				'name'           => 'Env Auth Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method'       => 'api_key',
					'env_var_name' => 'WPAI_ENV_AUTH_PROVIDER_API_KEY',
				),
			)
		);

		$env_var_name = 'WPAI_ENV_AUTH_PROVIDER_API_KEY';
		putenv( "{$env_var_name}=test-env-key" );

		try {
			$this->assertTrue( \WordPress\AI\has_connector_authentication( $connector_id ) );
		} finally {
			putenv( $env_var_name );
		}
	}

	/**
	 * Test that a connector can advertise image generation support through the filter.
	 *
	 * Regression test: connectors that authenticate without an API key (e.g. OAuth) are
	 * not picked up by has_connector_authentication(), so they advertise support through
	 * the wpai_has_image_generation_support filter, which is request-free.
	 *
	 * @since x.x.x
	 */
	public function test_has_image_generation_support_detects_connector_via_filter(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$this->register_test_image_provider();
		$this->register_test_connector(
			self::TEST_IMAGE_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Image Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);
		Image_Generation_Test_Model_Metadata::$supports_image_generation = true;

		$this->assertFalse(
			\WordPress\AI\has_connector_authentication( self::TEST_IMAGE_PROVIDER_ID ),
			'A non-API-key connector should not report API-key authentication.'
		);
		$this->assertFalse(
			\WordPress\AI\has_image_generation_support( true ),
			'A non-API-key connector is not detected until it advertises support.'
		);

		add_filter( 'wpai_has_image_generation_support', '__return_true' );

		try {
			$this->assertTrue(
				\WordPress\AI\has_image_generation_support( true ),
				'A connector advertising support through the filter should be detected.'
			);
		} finally {
			remove_filter( 'wpai_has_image_generation_support', '__return_true' );
		}
	}

	/**
	 * Test that has_image_generation_support() still detects API-key connectors.
	 *
	 * @since x.x.x
	 */
	public function test_has_image_generation_support_detects_api_key_connector(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$this->register_test_image_provider();
		$this->register_test_connector(
			self::TEST_IMAGE_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Image Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);
		$setting_name = 'connectors_ai_provider_' . self::TEST_IMAGE_PROVIDER_ID . '_api_key';
		update_option( $setting_name, 'test-api-key' );

		Image_Generation_Test_Model_Metadata::$supports_image_generation = true;

		try {
			$this->assertTrue( \WordPress\AI\has_image_generation_support( true ) );
		} finally {
			delete_option( $setting_name );
		}
	}

	/**
	 * Test that has_image_generation_support() returns false when a connector's models lack the capability.
	 *
	 * @since x.x.x
	 */
	public function test_has_image_generation_support_returns_false_when_models_lack_capability(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$this->register_test_image_provider();
		$this->register_test_connector(
			self::TEST_IMAGE_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Image Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);
		$setting_name = 'connectors_ai_provider_' . self::TEST_IMAGE_PROVIDER_ID . '_api_key';
		update_option( $setting_name, 'test-api-key' );

		Image_Generation_Test_Model_Metadata::$supports_image_generation = false;

		try {
			$this->assertFalse( \WordPress\AI\has_image_generation_support( true ) );
		} finally {
			delete_option( $setting_name );
		}
	}

	/**
	 * Test that has_image_generation_support() skips connectors without credentials.
	 *
	 * A non-API-key connector that does not advertise support through the
	 * wpai_has_image_generation_support filter must not be detected.
	 *
	 * @since x.x.x
	 */
	public function test_has_image_generation_support_skips_connector_without_credentials(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$this->register_test_image_provider();
		$this->register_test_connector(
			self::TEST_IMAGE_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Image Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);
		Image_Generation_Test_Model_Metadata::$supports_image_generation = true;

		$this->assertFalse( \WordPress\AI\has_image_generation_support( true ) );
	}

	/**
	 * Test that the filter can suppress support for an otherwise-qualifying connector.
	 *
	 * @since x.x.x
	 */
	public function test_has_image_generation_support_filter_can_suppress(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$this->register_test_image_provider();
		$this->register_test_connector(
			self::TEST_IMAGE_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Image Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);
		$setting_name = 'connectors_ai_provider_' . self::TEST_IMAGE_PROVIDER_ID . '_api_key';
		update_option( $setting_name, 'test-api-key' );

		Image_Generation_Test_Model_Metadata::$supports_image_generation = true;

		add_filter( 'wpai_has_image_generation_support', '__return_false' );

		try {
			$this->assertFalse( \WordPress\AI\has_image_generation_support( true ) );
		} finally {
			remove_filter( 'wpai_has_image_generation_support', '__return_false' );
			delete_option( $setting_name );
		}
	}

	/**
	 * Test that has_image_generation_support() memoizes its result until the cache is reset.
	 *
	 * @since x.x.x
	 */
	public function test_has_image_generation_support_memoizes_result(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		add_filter( 'wpai_has_image_generation_support', '__return_true' );

		try {
			$computed = \WordPress\AI\has_image_generation_support( true );
			remove_filter( 'wpai_has_image_generation_support', '__return_true' );

			// Without a cache reset the memoized result is returned, even though the
			// filter that produced it has since been removed.
			$this->assertTrue( $computed );
			$this->assertSame( $computed, \WordPress\AI\has_image_generation_support() );
		} finally {
			remove_filter( 'wpai_has_image_generation_support', '__return_true' );
		}
	}

	/**
	 * Test that has_image_generation_support() skips a connector whose provider throws.
	 *
	 * @since x.x.x
	 */
	public function test_has_image_generation_support_skips_connector_that_throws(): void {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$this->register_test_image_provider();
		$this->register_test_connector(
			self::TEST_IMAGE_PROVIDER_ID,
			array(
				'name'           => 'Helper Test Image Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);
		$setting_name = 'connectors_ai_provider_' . self::TEST_IMAGE_PROVIDER_ID . '_api_key';
		update_option( $setting_name, 'test-api-key' );

		Image_Generation_Test_Model_Metadata_Directory::$should_throw = true;

		try {
			$this->assertFalse( \WordPress\AI\has_image_generation_support( true ) );
		} finally {
			Image_Generation_Test_Model_Metadata_Directory::$should_throw = false;
			delete_option( $setting_name );
		}
	}

	/**
	 * Test that connector plugin metadata is optional.
	 *
	 * @since 0.9.0
	 */
	public function test_is_connector_plugin_active_returns_true_without_plugin_metadata() {
		$this->assertTrue(
			\WordPress\AI\is_connector_plugin_active(
				array(
					'name' => 'Provider Without Plugin Metadata',
					'type' => 'ai_provider',
				)
			)
		);
	}

	/**
	 * Test that an inactive connector plugin is detected.
	 *
	 * @since 1.0.0
	 */
	public function test_is_connector_plugin_active_returns_false_for_inactive_plugin() {
		$this->assertFalse(
			\WordPress\AI\is_connector_plugin_active(
				array(
					'name'   => 'Inactive Provider',
					'type'   => 'ai_provider',
					'plugin' => array(
						'file' => 'inactive-test-provider/inactive-test-provider.php',
					),
				)
			)
		);
	}

	/**
	 * Test that active connector plugins are detected for supported file keys.
	 *
	 * @since 1.0.0
	 */
	public function test_is_connector_plugin_active_supports_plugin_file_keys() {
		$this->activate_test_plugin( 'plugin-file-provider/plugin-file-provider.php' );
		$this->activate_test_plugin( 'plugin-file-camel-provider/plugin-file-camel-provider.php' );

		$this->assertTrue(
			\WordPress\AI\is_connector_plugin_active(
				array(
					'name'   => 'Plugin File Provider',
					'type'   => 'ai_provider',
					'plugin' => array(
						'plugin_file' => 'plugin-file-provider/plugin-file-provider.php',
					),
				)
			)
		);
		$this->assertTrue(
			\WordPress\AI\is_connector_plugin_active(
				array(
					'name'   => 'Plugin File Camel Provider',
					'type'   => 'ai_provider',
					'plugin' => array(
						'pluginFile' => 'plugin-file-camel-provider/plugin-file-camel-provider.php',
					),
				)
			)
		);
	}

	/**
	 * Test that get_guidelines() returns guidelines filtered by category.
	 *
	 * @since 0.8.0
	 */
	public function test_get_guidelines_returns_guidelines(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post(
			array(
				'site' => 'Use a professional tone.',
				'copy' => 'Keep sentences under 25 words.',
			)
		);

		$result = \WordPress\AI\get_guidelines( 'site' );

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertArrayHasKey( 'site', $result, 'Should have site key' );
		$this->assertEquals( 'Use a professional tone.', $result['site'] );
	}

	/**
	 * Registers a test connector.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $connector_id Connector ID.
	 * @param array<string, mixed> $data         Connector data.
	 */
	private function register_test_connector( string $connector_id, array $data ): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry ) {
			$this->markTestSkipped( 'WP_Connector_Registry is not available.' );
		}

		if ( $registry->is_registered( $connector_id ) ) {
			$registry->unregister( $connector_id );
		}

		$registry->register( $connector_id, $data );

		$this->test_connector_ids[] = $connector_id;
	}

	/**
	 * Registers the stub provider in the AI client registry.
	 *
	 * Mutates both internal maps so that lookups by ID and by class name resolve.
	 * Bypasses the registry's public registerProvider() because that requires a
	 * fully-formed ProviderMetadata, an HTTP transporter, and default auth — all
	 * unnecessary for these tests.
	 *
	 * @since 1.0.1
	 */
	private function register_test_ai_provider(): void {
		$registry = AiClient::defaultRegistry();

		$ids_to_classes = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map                              = (array) $ids_to_classes->getValue( $registry );
		$id_map[ self::TEST_AI_PROVIDER_ID ] = Helper_Test_Provider::class;
		$ids_to_classes->setValue( $registry, $id_map );

		$classes_to_ids = new ReflectionProperty( $registry, 'registeredClassNamesToIds' );
		$classes_to_ids->setAccessible( true );
		$class_map                                = (array) $classes_to_ids->getValue( $registry );
		$class_map[ Helper_Test_Provider::class ] = self::TEST_AI_PROVIDER_ID;
		$classes_to_ids->setValue( $registry, $class_map );
	}

	/**
	 * Unregisters the stub provider from the AI client registry.
	 *
	 * @since 1.0.1
	 */
	private function unregister_test_ai_provider(): void {
		$registry = AiClient::defaultRegistry();

		$ids_to_classes = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map = (array) $ids_to_classes->getValue( $registry );
		unset( $id_map[ self::TEST_AI_PROVIDER_ID ] );
		$ids_to_classes->setValue( $registry, $id_map );

		$classes_to_ids = new ReflectionProperty( $registry, 'registeredClassNamesToIds' );
		$classes_to_ids->setAccessible( true );
		$class_map = (array) $classes_to_ids->getValue( $registry );
		unset( $class_map[ Helper_Test_Provider::class ] );
		$classes_to_ids->setValue( $registry, $class_map );
	}

	/**
	 * Registers the image generation stub provider in the AI client registry.
	 *
	 * @since x.x.x
	 */
	private function register_test_image_provider(): void {
		$registry = AiClient::defaultRegistry();

		$ids_to_classes = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map                                 = (array) $ids_to_classes->getValue( $registry );
		$id_map[ self::TEST_IMAGE_PROVIDER_ID ] = Image_Generation_Test_Provider::class;
		$ids_to_classes->setValue( $registry, $id_map );

		$classes_to_ids = new ReflectionProperty( $registry, 'registeredClassNamesToIds' );
		$classes_to_ids->setAccessible( true );
		$class_map = (array) $classes_to_ids->getValue( $registry );
		$class_map[ Image_Generation_Test_Provider::class ] = self::TEST_IMAGE_PROVIDER_ID;
		$classes_to_ids->setValue( $registry, $class_map );
	}

	/**
	 * Unregisters the image generation stub provider from the AI client registry.
	 *
	 * @since x.x.x
	 */
	private function unregister_test_image_provider(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		$ids_to_classes = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map = (array) $ids_to_classes->getValue( $registry );
		unset( $id_map[ self::TEST_IMAGE_PROVIDER_ID ] );
		$ids_to_classes->setValue( $registry, $id_map );

		$classes_to_ids = new ReflectionProperty( $registry, 'registeredClassNamesToIds' );
		$classes_to_ids->setAccessible( true );
		$class_map = (array) $classes_to_ids->getValue( $registry );
		unset( $class_map[ Image_Generation_Test_Provider::class ] );
		$classes_to_ids->setValue( $registry, $class_map );
	}

	/**
	 * Marks a plugin basename as active for the current test.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_file Plugin basename.
	 */
	private function activate_test_plugin( string $plugin_file ): void {
		$active_plugins   = (array) get_option( 'active_plugins', array() );
		$active_plugins[] = $plugin_file;

		update_option( 'active_plugins', array_values( array_unique( $active_plugins ) ) );
	}

	/**
	 * Tests that get_min_content_length() returns the default value when no filter is registered.
	 *
	 * @since x.x.x
	 */
	public function test_get_min_content_length_returns_default_value(): void {
		$result = \WordPress\AI\get_min_content_length( 'summarization', 100 );

		$this->assertSame( 100, $result );
	}

	/**
	 * Tests that get_min_content_length() returns a custom value when filtered.
	 *
	 * @since x.x.x
	 */
	public function test_get_min_content_length_returns_filtered_value(): void {
		$filter = static function () {
			return 250;
		};

		add_filter( 'wpai_min_content_length', $filter );
		$result = \WordPress\AI\get_min_content_length( 'summarization', 100 );
		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertSame( 250, $result );
	}

	/**
	 * Tests that the wpai_min_content_length filter receives the feature ID.
	 *
	 * @since x.x.x
	 */
	public function test_get_min_content_length_filter_receives_feature_id(): void {
		$received_feature_id = null;

		$filter = static function ( int $length, string $feature_id ) use ( &$received_feature_id ): int {
			$received_feature_id = $feature_id;
			return $length;
		};

		add_filter( 'wpai_min_content_length', $filter, 10, 2 );
		\WordPress\AI\get_min_content_length( 'excerpt-generation', 100 );
		remove_filter( 'wpai_min_content_length', $filter, 10 );

		$this->assertSame( 'excerpt-generation', $received_feature_id );
	}

	/**
	 * Tests that get_feature_developer_model_config() returns empty strings when unset.
	 *
	 * @since 0.9.0
	 */
	public function test_get_feature_developer_model_config_returns_empty_strings_when_unset(): void {
		$result = \WordPress\AI\get_feature_developer_model_config( 'test-feature' );

		$this->assertSame(
			array(
				'provider' => '',
				'model'    => '',
			),
			$result
		);
	}

	/**
	 * Test that get_feature_developer_model_config() returns saved provider and model values.
	 *
	 * @since 0.9.0
	 */
	public function test_get_feature_developer_model_config_returns_saved_provider_and_model(): void {
		update_option(
			'wpai_feature_test-feature_field_developer',
			array(
				'provider' => 'openai',
				'model'    => 'gpt-test',
			)
		);

		$result = \WordPress\AI\get_feature_developer_model_config( 'test-feature' );

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'gpt-test', $result['model'] );
	}

	/**
	 * Test that get_feature_developer_model_config() tolerates malformed option values.
	 *
	 * @since 0.9.0
	 */
	public function test_get_feature_developer_model_config_handles_malformed_option(): void {
		update_option( 'wpai_feature_test-feature_field_developer', 'not-an-array' );

		$result = \WordPress\AI\get_feature_developer_model_config( 'test-feature' );

		$this->assertSame( '', $result['provider'] );
		$this->assertSame( '', $result['model'] );
	}

	/**
	 * Test get_provider_availability_data() returns expected structure.
	 *
	 * @since 1.0.0
	 */
	public function test_get_provider_availability_data_returns_expected_structure(): void {
		$data = \WordPress\AI\get_provider_availability_data();

		$this->assertArrayHasKey( 'hasProvider', $data );
		$this->assertArrayHasKey( 'connectorsUrl', $data );
		$this->assertIsBool( $data['hasProvider'] );
	}

	/**
	 * Test get_provider_availability_data() reflects credential state via filter.
	 *
	 * @since 1.0.0
	 */
	public function test_get_provider_availability_data_reflects_credential_filter(): void {
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		$data = \WordPress\AI\get_provider_availability_data();
		$this->assertTrue( $data['hasProvider'] );
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );

		add_filter( 'wpai_has_ai_credentials', '__return_false' );
		$data = \WordPress\AI\get_provider_availability_data();
		$this->assertFalse( $data['hasProvider'] );
		remove_filter( 'wpai_has_ai_credentials', '__return_false' );
	}

	/**
	 * Test that connector plugin checks support plugin_file metadata.
	 *
	 * @since 0.9.0
	 */
	public function test_is_connector_plugin_active_returns_false_for_inactive_plugin_file(): void {
		$this->assertFalse(
			\WordPress\AI\is_connector_plugin_active(
				array(
					'plugin' => array(
						'plugin_file' => 'inactive-test-plugin/inactive-test-plugin.php',
					),
				)
			)
		);
	}
}
