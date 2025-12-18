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

	/**
	 * Test that get_system_instruction() accepts optional data parameter and exposes it to the file.
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_with_data_parameter() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		// Get the test ability's directory using reflection.
		$reflection = new \ReflectionClass( $ability );
		$file_name  = $reflection->getFileName();
		$feature_dir = dirname( $file_name );

		// Create a temporary system instruction file that uses data variables.
		$test_file = trailingslashit( $feature_dir ) . 'test-system-instruction.php';
		$test_content = <<<'PHP'
<?php
// Process the length variable if provided.
$wpai_length_desc = 'medium length';
if ( isset( $length ) ) {
	if ( 'short' === $length ) {
		$wpai_length_desc = 'short length';
	} elseif ( 'long' === $length ) {
		$wpai_length_desc = 'long length';
	}
}

// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
return <<<INSTRUCTION
You are a test assistant. The length is {$wpai_length_desc} and the tone is {$tone}. Max words: {$max_words}.
INSTRUCTION;
PHP;
		file_put_contents( $test_file, $test_content );

		try {
			// Test with data parameter.
			$data = array(
				'length'   => 'short',
				'tone'     => 'professional',
				'max_words' => 100,
			);

			$system_instruction = $ability->get_system_instruction( 'test-system-instruction.php', $data );

			// Verify the data was interpolated into the system instruction.
			$this->assertIsString( $system_instruction, 'System instruction should be a string' );
			$this->assertStringContainsString( 'short length', $system_instruction, 'System instruction should contain processed length value' );
			$this->assertStringContainsString( 'professional', $system_instruction, 'System instruction should contain tone value' );
			$this->assertStringContainsString( '100', $system_instruction, 'System instruction should contain max_words value' );
		} finally {
			// Clean up the test file.
			if ( file_exists( $test_file ) ) {
				unlink( $test_file );
			}
		}
	}

	/**
	 * Test that get_system_instruction() works without data parameter (backward compatibility).
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_without_data_parameter() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		// Get the test ability's directory using reflection.
		$reflection = new \ReflectionClass( $ability );
		$file_name  = $reflection->getFileName();
		$feature_dir = dirname( $file_name );

		// Create a temporary system instruction file without using data variables.
		$test_file = trailingslashit( $feature_dir ) . 'test-system-instruction-simple.php';
		$test_content = <<<'PHP'
<?php
// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
return <<<INSTRUCTION
You are a test assistant without data variables.
INSTRUCTION;
PHP;
		file_put_contents( $test_file, $test_content );

		try {
			// Test without data parameter (should still work).
			$system_instruction = $ability->get_system_instruction( 'test-system-instruction-simple.php' );

			// Verify it returns the content.
			$this->assertIsString( $system_instruction, 'System instruction should be a string' );
			$this->assertStringContainsString( 'test assistant', $system_instruction, 'System instruction should contain expected content' );
		} finally {
			// Clean up the test file.
			if ( file_exists( $test_file ) ) {
				unlink( $test_file );
			}
		}
	}

	/**
	 * Test that get_system_instruction() with empty data array works correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_with_empty_data_array() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		// Get the test ability's directory using reflection.
		$reflection = new \ReflectionClass( $ability );
		$file_name  = $reflection->getFileName();
		$feature_dir = dirname( $file_name );

		// Create a temporary system instruction file.
		$test_file = trailingslashit( $feature_dir ) . 'test-system-instruction-empty.php';
		$test_content = <<<'PHP'
<?php
// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
return <<<INSTRUCTION
You are a test assistant.
INSTRUCTION;
PHP;
		file_put_contents( $test_file, $test_content );

		try {
			// Test with empty data array.
			$system_instruction = $ability->get_system_instruction( 'test-system-instruction-empty.php', array() );

			// Verify it returns the content.
			$this->assertIsString( $system_instruction, 'System instruction should be a string' );
			$this->assertStringContainsString( 'test assistant', $system_instruction, 'System instruction should contain expected content' );
		} finally {
			// Clean up the test file.
			if ( file_exists( $test_file ) ) {
				unlink( $test_file );
			}
		}
	}
}
