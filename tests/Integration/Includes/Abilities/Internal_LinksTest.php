<?php
/**
 * Integration tests for the Internal_Links Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Internal_Links\Internal_Links;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Internal_Links Ability tests.
 *
 * @since x.x.x
 */
class Test_Internal_Links_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'internal-links';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Internal Link Suggestions',
			'description' => 'Uses AI to suggest relevant internal links within post content.',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Internal_Links Ability test case.
 *
 * @since x.x.x
 */
class Internal_LinksTest extends WP_UnitTestCase {
	/**
	 * Internal_Links ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Internal_Links\Internal_Links
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Internal_Links_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Internal_Links_Experiment();
		$this->ability    = new Internal_Links(
			'ai/internal-links',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since x.x.x
	 */
	public function test_category_returns_correct_category() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'category' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability );

		$this->assertSame( 'ai-experiments', $result, 'Category should be ai-experiments' );
	}

	/**
	 * Test that input_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'post_content', $schema['properties'] );
		$this->assertArrayHasKey( 'post_id', $schema['properties'] );
		$this->assertArrayHasKey( 'max_suggestions', $schema['properties'] );
		$this->assertSame( 5, $schema['properties']['max_suggestions']['default'] );
		$this->assertContains( 'post_content', $schema['required'] );
		$this->assertContains( 'post_id', $schema['required'] );
	}

	/**
	 * Test that output_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'suggestions', $schema['properties'] );

		$item_props = $schema['properties']['suggestions']['items']['properties'];
		$this->assertArrayHasKey( 'anchor_text', $item_props );
		$this->assertArrayHasKey( 'url', $item_props );
		$this->assertArrayHasKey( 'title', $item_props );
		$this->assertArrayHasKey( 'context', $item_props );
	}

	/**
	 * Test that execute_callback() returns error when post_content is missing.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_without_post_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->ability,
			array(
				'post_id'      => 1,
				'post_content' => '',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_content_required', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns a WP_Error when no text-generation model is available.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_error_when_no_text_generation_model_available() {
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		delete_option( 'wp_ai_client_provider_credentials' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Other Post',
			)
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->ability,
			array(
				'post_id'      => $post_id,
				'post_content' => 'Check out our Other Post for more details.',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_model', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() allows authorized users.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_allows_authorized_user() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test that permission_callback() denies unauthorized users.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_denies_unauthorized_user() {
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that meta() returns expected shape.
	 *
	 * @since x.x.x
	 */
	public function test_meta_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'show_in_rest', $meta );
		$this->assertTrue( $meta['show_in_rest'] );
	}

	/**
	 * Test that get_system_instruction() returns expected content.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_returns_expected_content() {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction );
		$this->assertNotEmpty( $system_instruction );
		$this->assertStringContainsString( 'internal-linking assistant', $system_instruction );
	}

	/**
	 * Test that build_site_index() builds index of published posts excluding current post.
	 *
	 * @since x.x.x
	 */
	public function test_build_site_index_excludes_current_post_and_drafts() {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Published Post',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Draft Post',
			)
		);
		$current_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Current Post',
			)
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_site_index' );
		$method->setAccessible( true );

		$index  = $method->invoke( $this->ability, $current_post_id );
		$titles = array_column( $index, 'title' );

		$this->assertContains( 'Published Post', $titles );
		$this->assertNotContains( 'Draft Post', $titles );
		$this->assertNotContains( 'Current Post', $titles );
	}

	/**
	 * Test parse_and_validate_response() validates anchor text, site index URLs, and removes invalid suggestions.
	 *
	 * @since x.x.x
	 */
	public function test_parse_and_validate_response() {
		$plain_text = 'Learn more about WordPress REST API for content management.';
		$site_index = array(
			array(
				'url'   => 'https://example.com/rest-api/',
				'title' => 'REST API Guide',
			),
		);

		$raw_json = wp_json_encode(
			array(
				'suggestions' => array(
					// Valid suggestion.
					array(
						'anchor_text' => 'REST API',
						'url'         => 'https://example.com/rest-api/',
						'title'       => 'REST API Guide',
						'context'     => 'Learn more about WordPress REST API for content management.',
					),
					// Invalid anchor text (not in plain text).
					array(
						'anchor_text' => 'Gutenberg',
						'url'         => 'https://example.com/rest-api/',
						'title'       => 'REST API Guide',
						'context'     => 'Invalid',
					),
					// Invalid URL (not in site index).
					array(
						'anchor_text' => 'content management',
						'url'         => 'https://example.com/other/',
						'title'       => 'Other',
						'context'     => 'Invalid',
					),
				),
			)
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_and_validate_response' );
		$method->setAccessible( true );

		$suggestions = $method->invoke( $this->ability, $raw_json, $plain_text, $site_index, 5 );

		$this->assertCount( 1, $suggestions );
		$this->assertSame( 'REST API', $suggestions[0]['anchor_text'] );
		$this->assertSame( 'https://example.com/rest-api/', $suggestions[0]['url'] );
	}
}
