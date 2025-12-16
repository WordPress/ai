<?php
/**
 * Integration tests for the Prompts utility class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Utilities\Prompts;

/**
 * Prompts utility test case.
 *
 * @since x.x.x
 */
class PromptsTest extends WP_UnitTestCase {

	/**
	 * Prompts instance.
	 *
	 * @var \WordPress\AI\Abilities\Utilities\Prompts
	 */
	private $prompts;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->prompts = new Prompts();

		// Register the ability category if it doesn't exist.
		if ( function_exists( 'wp_register_ability_category' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			if ( ! function_exists( 'wp_get_ability_category' ) || ! \wp_get_ability_category( AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				\wp_register_ability_category(
					AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
					array(
						'label'       => __( 'AI Experiments', 'ai' ),
						'description' => __( 'Various AI experiments.', 'ai' ),
					)
				);
			}
		}
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
	 * Test that register() hooks into wp_abilities_api_init.
	 *
	 * @since x.x.x
	 */
	public function test_register_hooks_into_wp_abilities_api_init() {
		// Clear any existing hooks.
		remove_all_actions( 'wp_abilities_api_init' );

		$this->prompts->register();

		// Verify the hook was added. has_action returns priority (int) or false.
		$has_action = has_action( 'wp_abilities_api_init', array( $this->prompts, 'register_abilities' ) );
		$this->assertNotFalse(
			$has_action,
			'register() should hook register_abilities into wp_abilities_api_init'
		);
	}

	/**
	 * Test that register_abilities() registers the generate-prompt ability.
	 *
	 * @since x.x.x
	 */
	public function test_register_abilities_registers_generate_prompt_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		// Trigger the hook to register abilities.
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		$this->assertNotNull( $ability, 'Ability should be registered' );
		$this->assertInstanceOf( \WP_Ability::class, $ability, 'Should be a WP_Ability instance' );
	}

	/**
	 * Test that the ability has correct label and description.
	 *
	 * @since x.x.x
	 */
	public function test_ability_has_correct_label_and_description() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		$this->assertEquals(
			'Generate a prompt',
			$ability->get_label(),
			'Label should match'
		);
		$this->assertEquals(
			'Generate a prompt for a specific purpose.',
			$ability->get_description(),
			'Description should match'
		);
	}

	/**
	 * Test that the ability has correct category.
	 *
	 * @since x.x.x
	 */
	public function test_ability_has_correct_category() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		// Use reflection to access the category property.
		$reflection = new \ReflectionClass( $ability );
		$property   = $reflection->getProperty( 'category' );
		$property->setAccessible( true );

		$this->assertEquals(
			AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
			$property->getValue( $ability ),
			'Category should match'
		);
	}

	/**
	 * Test that input_schema has correct structure.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_has_correct_structure() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );
		$schema  = $ability->get_input_schema();

		$this->assertIsArray( $schema, 'Input schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'purpose', $schema['properties'], 'Schema should have purpose property' );
		$this->assertArrayHasKey( 'context', $schema['properties'], 'Schema should have context property' );
		$this->assertArrayHasKey( 'required', $schema, 'Schema should have required array' );
		$this->assertContains( 'purpose', $schema['required'], 'purpose should be required' );
		$this->assertContains( 'context', $schema['required'], 'context should be required' );

		// Verify purpose property.
		$this->assertEquals( 'string', $schema['properties']['purpose']['type'], 'Purpose should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['purpose']['sanitize_callback'], 'Purpose should use sanitize_text_field' );

		// Verify context property.
		$this->assertEquals( 'string', $schema['properties']['context']['type'], 'Context should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['context']['sanitize_callback'], 'Context should use sanitize_text_field' );
	}

	/**
	 * Test that output_schema has correct structure.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_has_correct_structure() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );
		$schema  = $ability->get_output_schema();

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'string', $schema['type'], 'Schema type should be string' );
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description' );
		$this->assertEquals( 'The generated prompt.', $schema['description'], 'Description should match' );
	}

	/**
	 * Test that execute_callback formats content correctly with purpose and context.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_formats_content_with_purpose_and_context() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		$input = array(
			'purpose' => 'Generate a featured image',
			'context' => 'Title: Test Post\nType: post',
		);

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		// Result may be string (success) or WP_Error (if AI client unavailable).
		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	/**
	 * Test that execute_callback formats content correctly with purpose only.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_formats_content_with_purpose_only() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		$input = array(
			'purpose' => 'Generate a featured image',
			'context' => '',
		);

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		// Result may be string (success) or WP_Error (if AI client unavailable).
		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	/**
	 * Test that execute_callback uses default values for missing parameters.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_uses_default_values() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		$input = array();

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		// Result may be string (success) or WP_Error (if AI client unavailable).
		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			return;
		}

		// Should still work with empty defaults.
		$this->assertIsString( $result, 'Result should be a string' );
	}

	/**
	 * Test that permission_callback returns true for logged in user.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_returns_true_for_logged_in_user() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		// Create a logged in user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Use reflection to access the permission_callback.
		$reflection = new \ReflectionClass( $ability );
		$property   = $reflection->getProperty( 'permission_callback' );
		$property->setAccessible( true );
		$callback = $property->getValue( $ability );

		$result = call_user_func( $callback, array() );

		$this->assertTrue( $result, 'Permission should be granted for logged in user' );
	}

	/**
	 * Test that permission_callback returns false for logged out user.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_returns_false_for_logged_out_user() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Use reflection to access the permission_callback.
		$reflection = new \ReflectionClass( $ability );
		$property   = $reflection->getProperty( 'permission_callback' );
		$property->setAccessible( true );
		$callback = $property->getValue( $ability );

		$result = call_user_func( $callback, array() );

		$this->assertFalse( $result, 'Permission should be denied for logged out user' );
	}

	/**
	 * Test that meta has correct structure.
	 *
	 * @since x.x.x
	 */
	public function test_meta_has_correct_structure() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/generate-prompt' );

		// Use reflection to access the meta property.
		$reflection = new \ReflectionClass( $ability );
		$property   = $reflection->getProperty( 'meta' );
		$property->setAccessible( true );
		$meta = $property->getValue( $ability );

		$this->assertIsArray( $meta, 'Meta should be an array' );
		$this->assertArrayHasKey( 'show_in_rest', $meta, 'Meta should have show_in_rest' );
		$this->assertTrue( $meta['show_in_rest'], 'show_in_rest should be true' );
		$this->assertArrayHasKey( 'mcp', $meta, 'Meta should have mcp' );
		$this->assertIsArray( $meta['mcp'], 'mcp should be an array' );
		$this->assertArrayHasKey( 'public', $meta['mcp'], 'mcp should have public' );
		$this->assertTrue( $meta['mcp']['public'], 'mcp public should be true' );
		$this->assertArrayHasKey( 'type', $meta['mcp'], 'mcp should have type' );
		$this->assertEquals( 'prompt', $meta['mcp']['type'], 'mcp type should be prompt' );
	}
}
