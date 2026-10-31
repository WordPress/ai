<?php
/**
 * Integration tests for the Block_Container_Label_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Block_Container_Label_Generation\Block_Container_Label_Generation;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Block_Container_Label_Generation Ability tests.
 *
 * @since 1.5.0
 */
class Test_Block_Container_Label_Generation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'block-container-label-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Block Container Label Generation',
			'description' => 'Generates concise labels for content blocks',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 1.5.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Testable subclass of Block_Container_Label_Generation to mock AI prompt generation.
 *
 * @since 1.5.0
 */
class Testable_Block_Container_Label_Generation extends Block_Container_Label_Generation {
	/**
	 * Mock response to return from generate_label().
	 *
	 * @var string|\WP_Error|null
	 */
	public $mock_response = null;

	/**
	 * Last prompt passed to generate_label().
	 *
	 * @var string|null
	 */
	public $last_prompt = null;

	/**
	 * {@inheritDoc}
	 */
	protected function generate_label( string $prompt ) {
		$this->last_prompt = $prompt;
		if ( null !== $this->mock_response ) {
			return $this->mock_response;
		}

		return parent::generate_label( $prompt );
	}
}

/**
 * Block_Container_Label_Generation Ability test case.
 *
 * @since 1.5.0
 */
class Block_Container_Label_GenerationTest extends WP_UnitTestCase {

	/**
	 * Block_Container_Label_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Block_Container_Label_Generation\Block_Container_Label_Generation
	 */
	private $ability;

	/**
	 * Testable Block_Container_Label_Generation ability instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Testable_Block_Container_Label_Generation
	 */
	private $testable_ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Block_Container_Label_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 1.5.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment       = new Test_Block_Container_Label_Generation_Experiment();
		$this->ability          = new Block_Container_Label_Generation(
			'ai/block-container-label-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
		$this->testable_ability = new Testable_Block_Container_Label_Generation(
			'ai/block-container-label-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.5.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since 1.5.0
	 */
	public function test_category_returns_correct_category() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'category' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability );

		$this->assertEquals( 'ai-experiments', $result, 'Category should be ai-experiments' );
	}

	/**
	 * Test that meta() returns the expected meta structure.
	 *
	 * @since 1.5.0
	 */
	public function test_meta_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta, 'Meta should be an array' );
		$this->assertArrayHasKey( 'show_in_rest', $meta, 'Meta should have show_in_rest' );
		$this->assertTrue( $meta['show_in_rest'], 'show_in_rest should be true' );
	}

	/**
	 * Test that input_schema() returns the expected schema structure.
	 *
	 * @since 1.5.0
	 */
	public function test_input_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Input schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'required', $schema, 'Schema should have required' );
		$this->assertContains( 'post_id', $schema['required'], 'Schema required should contain post_id' );
		$this->assertContains( 'content', $schema['required'], 'Schema required should contain content' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'post_id', $schema['properties'], 'Schema should have post_id property' );
		$this->assertArrayHasKey( 'content', $schema['properties'], 'Schema should have content property' );
		$this->assertArrayHasKey( 'document_context', $schema['properties'], 'Schema should have document_context property' );

		// Verify post_id property.
		$this->assertEquals( 'integer', $schema['properties']['post_id']['type'], 'post_id should be integer type' );
		$this->assertEquals( 'absint', $schema['properties']['post_id']['sanitize_callback'], 'post_id should use absint' );

		// Verify content property.
		$this->assertEquals( 'string', $schema['properties']['content']['type'], 'Content should be string type' );

		// Verify document_context property.
		$this->assertEquals( 'string', $schema['properties']['document_context']['type'], 'document_context should be string type' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since 1.5.0
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'string', $schema['type'], 'Schema type should be string' );
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description property' );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since 1.5.0
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_system_instruction' );
		$method->setAccessible( true );

		$instruction = $method->invoke( $this->ability, 'system-instruction.php' );

		$this->assertIsString( $instruction, 'System instruction should be a string' );
		$this->assertStringContainsString( 'List View labels', $instruction );
	}

	/**
	 * Test that permission_callback() returns error when post_id is missing.
	 *
	 * @since 1.5.0
	 */
	public function test_permission_callback_without_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'missing_post_id', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns error for logged out user.
	 *
	 * @since 1.5.0
	 */
	public function test_permission_callback_for_logged_out_user() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		wp_set_current_user( 0 );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_post capability on a specific post.
	 *
	 * @since 1.5.0
	 */
	public function test_permission_callback_with_post_id_and_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertTrue( $result, 'Permission should be granted for user with edit_post capability' );
	}

	/**
	 * Test that permission_callback() returns error for user without edit_post capability on a specific post.
	 *
	 * @since 1.5.0
	 */
	public function test_permission_callback_with_post_id_without_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns error for non-existent post.
	 *
	 * @since 1.5.0
	 */
	public function test_permission_callback_with_nonexistent_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns false for post type without show_in_rest.
	 *
	 * @since 1.5.0
	 */
	public function test_permission_callback_with_post_type_without_show_in_rest() {
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

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_type'    => 'test_no_rest',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertFalse( $result, 'Permission should be denied for post type without show_in_rest' );

		unregister_post_type( 'test_no_rest' );
	}

	/**
	 * Test that execute_callback() returns error when content is missing or empty.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_without_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result_empty = $method->invoke( $this->ability, array() );
		$this->assertInstanceOf( WP_Error::class, $result_empty );
		$this->assertEquals( 'content_not_provided', $result_empty->get_error_code() );

		$result_whitespace = $method->invoke( $this->ability, array( 'content' => '   ' ) );
		$this->assertInstanceOf( WP_Error::class, $result_whitespace );
		$this->assertEquals( 'content_not_provided', $result_whitespace->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns error when content is below minimum length.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_with_content_too_short() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array( 'content' => 'Short content' );
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'content_too_short', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() respects filtered minimum content length.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_with_filtered_min_content_length() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = 'Short Label';

		$filter = static function () {
			return 10;
		};
		add_filter( 'wpai_min_content_length', $filter );

		$input  = array( 'content' => 'Valid length' );
		$result = $method->invoke( $this->testable_ability, $input );

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertEquals( 'Short Label', $result );
	}

	/**
	 * Test that execute_callback() returns generated label on success.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_success() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = 'Customer Testimonials';

		$input  = array( 'content' => 'This section contains multiple client reviews and customer feedback.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertEquals( 'Customer Testimonials', $result );
		$this->assertStringContainsString( '<content>This section contains multiple client reviews and customer feedback.</content>', $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() includes and escapes document_context in the prompt when provided.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_with_document_context() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = 'Hero Section';

		$input  = array(
			'content'          => 'Deploy code instantly. Ship faster than ever.',
			'document_context' => "Title: Acme Landing Page\nSection 1 (Target): Deploy code <instantly>.\nSection 2: Features",
		);
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertEquals( 'Hero Section', $result );
		$this->assertStringContainsString( '<document-context>', $this->testable_ability->last_prompt );
		$this->assertStringContainsString( 'Title: Acme Landing Page', $this->testable_ability->last_prompt );
		$this->assertStringContainsString( '&lt;instantly&gt;', $this->testable_ability->last_prompt );
		$this->assertStringContainsString( '<content>Deploy code instantly. Ship faster than ever.</content>', $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() omits document-context tag when document_context is not provided.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_without_document_context() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Sample Landing Page',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><p>Introduction and hero text</p></div><!-- /wp:group -->',
				'post_status'  => 'publish',
			)
		);

		$this->testable_ability->mock_response = 'Hero Section';

		$input  = array(
			'post_id' => $post_id,
			'content' => 'Introduction and hero text for the page.',
		);
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertEquals( 'Hero Section', $result );
		$this->assertStringNotContainsString( '<document-context>', $this->testable_ability->last_prompt );
		$this->assertStringContainsString( '<content>Introduction and hero text for the page.</content>', $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() returns error when AI generation produces empty output.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_when_ai_returns_empty() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = '';

		$input  = array( 'content' => 'This section contains multiple client reviews and customer feedback.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'no_results', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() forwards WP_Error returned by generate_label.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_when_ai_returns_error() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = new WP_Error( 'provider_error', 'Failed to reach AI provider.' );

		$input  = array( 'content' => 'This section contains multiple client reviews and customer feedback.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'provider_error', $result->get_error_code() );
		$this->assertEquals( 'Failed to reach AI provider.', $result->get_error_message() );
	}

	/**
	 * Test that execute_callback() sanitizes HTML tags and quotes from the AI response.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_sanitizes_html_in_output() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = '"<strong>Pricing Table</strong>"';

		$input  = array( 'content' => 'This section lists our product pricing, monthly tiers, and discount details.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertEquals( 'Pricing Table', $result );
	}

	/**
	 * Test that get_prompt_builder() returns WP_Error when no valid text generation model is available.
	 *
	 * @since 1.5.0
	 */
	public function test_get_prompt_builder_returns_error_without_valid_model() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_prompt_builder' );
		$method->setAccessible( true );

		$mock_builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation' ) )
			->getMock();

		$mock_builder->method( 'is_supported_for_text_generation' )
			->willReturn( false );

		add_filter(
			'wpai_block_container_label_generation_prompt_builder',
			static function () use ( $mock_builder ) {
				return $mock_builder;
			}
		);

		$result = $method->invoke( $this->ability, '<content>Test prompt content</content>' );

		remove_all_filters( 'wpai_block_container_label_generation_prompt_builder' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_model', $result->get_error_code() );
		$this->assertSame(
			'Label generation failed. Please ensure you have a connected provider that supports text generation.',
			$result->get_error_message()
		);
	}

	/**
	 * Test that get_prompt_builder() returns prompt builder when model is supported.
	 *
	 * @since 1.5.0
	 */
	public function test_get_prompt_builder_returns_builder_when_supported() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_prompt_builder' );
		$method->setAccessible( true );

		$mock_builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation' ) )
			->getMock();

		$mock_builder->method( 'is_supported_for_text_generation' )
			->willReturn( true );

		add_filter(
			'wpai_block_container_label_generation_prompt_builder',
			static function () use ( $mock_builder ) {
				return $mock_builder;
			}
		);

		$result = $method->invoke( $this->ability, '<content>Test prompt content</content>' );

		remove_all_filters( 'wpai_block_container_label_generation_prompt_builder' );

		$this->assertSame( $mock_builder, $result );
	}

	/**
	 * Test that generate_label() returns generated label string on success.
	 *
	 * @since 1.5.0
	 */
	public function test_generate_label_success() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_label' );
		$method->setAccessible( true );

		$mock_builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation', 'generate_text' ) )
			->getMock();

		$mock_builder->method( 'is_supported_for_text_generation' )
			->willReturn( true );

		$mock_builder->expects( $this->once() )
			->method( 'generate_text' )
			->willReturn( 'Pricing Table' );

		add_filter(
			'wpai_block_container_label_generation_prompt_builder',
			static function () use ( $mock_builder ) {
				return $mock_builder;
			}
		);

		$result = $method->invoke( $this->ability, '<content>Test prompt content</content>' );

		remove_all_filters( 'wpai_block_container_label_generation_prompt_builder' );

		$this->assertSame( 'Pricing Table', $result );
	}

	/**
	 * Test that generate_label() returns error when prompt builder returns WP_Error.
	 *
	 * @since 1.5.0
	 */
	public function test_generate_label_returns_error_when_builder_errors() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_label' );
		$method->setAccessible( true );

		$mock_builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation' ) )
			->getMock();

		$mock_builder->method( 'is_supported_for_text_generation' )
			->willReturn( false );

		add_filter(
			'wpai_block_container_label_generation_prompt_builder',
			static function () use ( $mock_builder ) {
				return $mock_builder;
			}
		);

		$result = $method->invoke( $this->ability, '<content>Test prompt content</content>' );

		remove_all_filters( 'wpai_block_container_label_generation_prompt_builder' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_model', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() works with the real ability instance when builder is mocked.
	 *
	 * @since 1.5.0
	 */
	public function test_execute_callback_with_real_ability_using_mock_builder() {
		$mock_builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation', 'generate_text' ) )
			->getMock();

		$mock_builder->method( 'is_supported_for_text_generation' )
			->willReturn( true );

		$mock_builder->expects( $this->once() )
			->method( 'generate_text' )
			->willReturn( 'Features Breakdown' );

		add_filter(
			'wpai_block_container_label_generation_prompt_builder',
			static function () use ( $mock_builder ) {
				return $mock_builder;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->ability,
			array( 'content' => 'Here is a comprehensive breakdown of our software features and capabilities.' )
		);

		remove_all_filters( 'wpai_block_container_label_generation_prompt_builder' );

		$this->assertSame( 'Features Breakdown', $result );
	}
}

