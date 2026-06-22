<?php
/**
 * Integration tests for the Content Translation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content_Translation\Content_Translation;
use WordPress\AI\Abilities\Content_Translation\Languages;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Content Translation Ability tests.
 *
 * @since x.x.x
 */
class Test_Content_Translation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-translation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Content Translation',
			'description' => 'Translate block content into a different language. Requires an AI connector that includes support for text generation models.',
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
 * Content Translation Ability test case.
 *
 * @since x.x.x
 */
class Content_TranslationTest extends WP_UnitTestCase {

	/**
	 * Content Translation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Content_Translation\Content_Translation
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Content_Translation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Content_Translation_Experiment();
		$this->ability    = new Content_Translation(
			'ai/content-translation',
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
	 * Test that guideline_categories() returns site and copy.
	 *
	 * @since x.x.x
	 */
	public function test_guideline_categories_returns_site_and_copy(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'guideline_categories' );
		$method->setAccessible( true );

		$this->assertSame(
			array( 'site', 'copy' ),
			$method->invoke( $this->ability )
		);
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since x.x.x
	 */
	public function test_category_returns_correct_category(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'category' );

		$method->setAccessible( true );
		$result = $method->invoke( $this->ability );

		$this->assertEquals( 'ai-experiments', $result, 'Category should be ai-experiments' );
	}

	/**
	 * Test that input_schema() returns the expected schema structure.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_returns_expected_structure(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );

		$method->setAccessible( true );
		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Input schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );

		$this->assertArrayHasKey(
			'post_id',
			$schema['properties'],
			'Schema should have post_id property'
		);
		$this->assertEquals(
			'integer',
			$schema['properties']['post_id']['type'],
			'Post ID should be integer type'
		);
		$this->assertEquals(
			'absint',
			$schema['properties']['post_id']['sanitize_callback'],
			'Post ID should use absint'
		);

		$this->assertArrayHasKey(
			'content',
			$schema['properties'],
			'Schema should have content property'
		);
		$this->assertEquals(
			'string',
			$schema['properties']['content']['type'],
			'Content should be string type'
		);

		$this->assertArrayHasKey(
			'target_language',
			$schema['properties'],
			'Schema should have target_language property'
		);
		$this->assertEquals(
			'string',
			$schema['properties']['target_language']['type'],
			'Target language should be string type'
		);
		$this->assertEquals(
			Languages::get_codes(),
			$schema['properties']['target_language']['enum'],
			sprintf( 'Target language should be enum with values %s', implode( ', ', Languages::get_codes() ) )
		);
		$this->assertEquals(
			Languages::get_default_target_language(),
			$schema['properties']['target_language']['default'],
			sprintf( 'Target language default should be %s', Languages::get_default_target_language() )
		);
		$this->assertEquals(
			'sanitize_key',
			$schema['properties']['target_language']['sanitize_callback'],
			'Target language should use sanitize_key'
		);
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_returns_expected_structure(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );

		$method->setAccessible( true );
		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'string', $schema['type'], 'Schema type should be string' );
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description' );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_returns_system_instruction(): void {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertNotEmpty( $system_instruction, 'System instruction should not be empty' );
	}

	/**
	 * Test that execute_callback() returns error when content is missing.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_without_content(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );

		$method->setAccessible( true );
		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'content_not_provided', $result->get_error_code(), 'Error code should be content_not_provided' );
	}

	/**
	 * Test that execute_callback() returns error when content is empty.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_empty_content(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );

		$method->setAccessible( true );
		$result = $method->invoke( $this->ability, array( 'content' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'content_not_provided', $result->get_error_code(), 'Error code should be content_not_provided' );
	}

	/**
	 * Test that execute_callback() returns error when target language is invalid.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_invalid_target_language(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );

		$method->setAccessible( true );
		$result = $method->invoke(
			$this->ability,
			array(
				'content'         => 'A content to translate.',
				'target_language' => 'invalid',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals(
			'invalid_target_language',
			$result->get_error_code(),
			'Error code should be invalid_target_language'
		);
	}

	/**
	 * Test that execute_callback() returns the translated content.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_translated_content(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );

		$method->setAccessible( true );

		try {
			$result = $method->invoke(
				$this->ability,
				array(
					'content'         => 'A content to translate.',
					'target_language' => 'pt-br',
				)
			);
		} catch ( \Throwable $e ) {
			$this->markTestSkipped(
				sprintf(
					'AI client not available in test environment: %s',
					$e->getMessage()
				)
			);
			return;
		}

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped(
				sprintf(
					'AI client not available in test environment: %s',
					$result->get_error_message()
				)
			);
			return;
		}

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_edit_posts_capability(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );
		$this->assertTrue(
			$result,
			'Permission should be granted for user with edit_posts capability'
		);
	}

	/**
	 * Test that permission_callback() returns true when post ID is provided and user
	 * can edit the post.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_post_id_and_edit_post_capability(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory()->post->create(
			array(
				'post_content' => 'Test content',
				'post_type'    => 'post',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertTrue(
			$result,
			'Permission should be granted for user with edit_post capability'
		);
	}

	/**
	 * Test that permission_callback() returns error for user without edit_posts capability.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_without_edit_posts_capability(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals(
			'insufficient_permissions',
			$result->get_error_code(),
			'Error code should be insufficient_permissions'
		);
	}

	/**
	 * Test that permission_callback() returns error for logged out user.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_for_logged_out_user(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		wp_set_current_user( 0 );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals(
			'insufficient_permissions',
			$result->get_error_code(),
			'Error code should be insufficient_permissions'
		);
	}

	/**
	 * Test that permission_callback() returns error for post type without show_in_rest.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_post_type_without_show_in_rest(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		register_post_type(
			'test_no_rest',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		$post_id = $this->factory()->post->create(
			array(
				'post_content' => 'Test content',
				'post_type'    => 'test_no_rest',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertFalse(
			$result,
			'Permission should be denied for post type without show_in_rest'
		);

		unregister_post_type( 'test_no_rest' );
	}

	/**
	 * Test that meta() returns the expected meta structure.
	 *
	 * @since x.x.x
	 */
	public function test_meta_returns_expected_structure(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );

		$method->setAccessible( true );
		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta, 'Meta should be an array' );
		$this->assertArrayHasKey( 'show_in_rest', $meta, 'Meta should have show_in_rest' );
		$this->assertTrue( $meta['show_in_rest'], 'show_in_rest should be true' );
	}
}
