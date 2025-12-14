<?php
/**
 * Integration tests for the Abstract_Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abstracts
 */

namespace WordPress\AI\Tests\Integration\Includes\Abstracts;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;

/**
 * Test ability implementation for Abstract_Ability tests.
 *
 * @since 0.1.0
 */
class Test_Ability extends Abstract_Ability {
	/**
	 * Returns the category of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return string The category of the ability.
	 */
	protected function category(): string {
		return 'test-category';
	}

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'test_input' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'test_output' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return mixed|\WP_Error The result of the ability execution, or a WP_Error on failure.
	 */
	protected function execute_callback( $input ) {
		return array( 'result' => 'test' );
	}

	/**
	 * Checks whether the current user has permission to execute the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * Returns the meta of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array( 'test' => 'meta' );
	}
}

/**
 * Test experiment for Abstract_Ability tests.
 *
 * @since 0.1.0
 */
class Test_Ability_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'test-ability-experiment',
			'label'       => 'Test Ability Experiment',
			'description' => 'A test experiment for ability testing',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Abstract_Ability test case.
 *
 * @since 0.1.0
 */
class Abstract_AbilityTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that constructor properly sets up the ability.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_sets_up_ability() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$this->assertSame( $experiment->get_label(), $ability->get_label(), 'Label should be stored in ability' );
	}

	/**
	 * Test that constructor calls parent constructor with correct properties.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_calls_parent_with_properties() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		// Verify the ability was registered with WordPress Abilities API.
		// We can't directly test parent::__construct, but we can verify the ability exists.
		$this->assertInstanceOf( Abstract_Ability::class, $ability, 'Ability should be instance of Abstract_Ability' );
	}

	/**
	 * Test that label() delegates to experiment's get_label().
	 *
	 * @since 0.1.0
	 */
	public function test_label_delegates_to_experiment() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		// Use reflection to test protected method.
		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'get_label' );
		$method->setAccessible( true );

		$result = $method->invoke( $ability );

		$this->assertEquals( $experiment->get_label(), $result, 'Label should match experiment label' );
		$this->assertEquals( 'Test Ability Experiment', $result, 'Label should be correct' );
	}

	/**
	 * Test that description() delegates to experiment's get_description().
	 *
	 * @since 0.1.0
	 */
	public function test_description_delegates_to_experiment() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		// Use reflection to test protected method.
		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'get_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $ability );

		$this->assertEquals( $experiment->get_description(), $result, 'Description should match experiment description' );
		$this->assertEquals( 'A test experiment for ability testing', $result, 'Description should be correct' );
	}

	/**
	 * Test that get_model_preferences() defaults to helper output.
	 *
	 * @since 0.1.0
	 */
	public function test_get_model_preferences_defaults_to_helper() {
		$experiment = new Test_Ability_Experiment();
		$ability = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'get_model_preferences' );
		$method->setAccessible( true );

		$result = $method->invoke( $ability );

		$this->assertSame(
			\WordPress\AI\get_preferred_models(),
			$result,
			'Default implementation should defer to helper output.'
		);
	}

	/**
	 * Test that get_model_preferences() can be overridden.
	 *
	 * @since 0.1.0
	 */
	public function test_get_model_preferences_can_be_overridden() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new class(
			'custom-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		) extends Test_Ability {
			protected function get_model_preferences(): array {
				return array(
					array( 'provider-x', 'model-y' ),
				);
			}
		};

		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'get_model_preferences' );
		$method->setAccessible( true );

		$result = $method->invoke( $ability );

		$this->assertSame(
			array(
				array( 'provider-x', 'model-y' ),
			),
			$result,
			'Overridden model preferences should be returned.'
		);
	}

	/**
	 * Test that constructor requires label.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_requires_label() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The ability properties must contain a `label` string.' );

		// Attempting to construct without a label should fail because.
		new Test_Ability( 'test-ability', array() );
	}

	/**
	 * Test that get_system_instruction() returns empty string when no system instruction file exists.
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_returns_empty_when_no_file() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$system_instruction = $ability->get_system_instruction();

		// Test ability doesn't have a system instruction file, so should return empty string.
		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertEquals( '', $system_instruction, 'System instruction should be empty when no file exists' );
	}
}
