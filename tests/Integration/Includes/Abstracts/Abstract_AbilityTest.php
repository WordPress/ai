<?php
/**
 * Integration tests for the Abstract_Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abstracts
 */

namespace WordPress\AI\Tests\Integration\Includes\Abstracts;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

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
 * Test ability: sanitize_text_field on a string input property.
 *
 * @since x.x.x
 */
class Test_Ability_Sanitize_Text extends Abstract_Ability {
	/**
	 * Last input received by execute_callback.
	 *
	 * @var mixed
	 */
	public $last_input;

	/**
	 * {@inheritDoc}
	 */
	protected function category(): string {
		return 'test-category';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Content.',
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute_callback( $input ) {
		$this->last_input = $input;
		return array( 'ok' => true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array();
	}
}

/**
 * Test ability: absint and instance-method sanitize callback.
 *
 * @since x.x.x
 */
class Test_Ability_Sanitize_Callback_Styles extends Abstract_Ability {
	/**
	 * Last input received by execute_callback.
	 *
	 * @var mixed
	 */
	public $last_input;

	/**
	 * {@inheritDoc}
	 */
	protected function category(): string {
		return 'test-category';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'count'  => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => 'Count.',
				),
				'prefix' => array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'add_prefix' ),
					'description'       => 'Prefixed.',
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Test sanitize callback.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function add_prefix( $value ): string {
		return is_string( $value ) ? 'P:' . $value : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute_callback( $input ) {
		$this->last_input = $input;
		return array( 'ok' => true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array();
	}
}

/**
 * Test ability: nested object properties and array items with callbacks.
 *
 * @since x.x.x
 */
class Test_Ability_Sanitize_Nested_And_Items extends Abstract_Ability {
	/**
	 * Last input received by execute_callback.
	 *
	 * @var mixed
	 */
	public $last_input;

	/**
	 * {@inheritDoc}
	 */
	protected function category(): string {
		return 'test-category';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'parent'    => array(
					'type'       => 'object',
					'properties' => array(
						'label' => array(
							'type'              => 'string',
							'sanitize_callback' => 'strtoupper',
						),
					),
				),
				'fragments' => array(
					'type'  => 'array',
					'items' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute_callback( $input ) {
		$this->last_input = $input;
		return array( 'ok' => true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array();
	}
}

/**
 * Test ability: top-level object default in input schema with per-field callbacks.
 *
 * @since x.x.x
 */
class Test_Ability_Sanitize_Top_Level_Default extends Abstract_Ability {
	/**
	 * Last input received by execute_callback.
	 *
	 * @var mixed
	 */
	public $last_input;

	/**
	 * {@inheritDoc}
	 */
	protected function category(): string {
		return 'test-category';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'default'    => array( 'k' => '7' ),
			'properties' => array(
				'k' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute_callback( $input ) {
		$this->last_input = $input;
		return array( 'ok' => true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array();
	}
}

/**
 * Test ability: sanitize returns WP_Error.
 *
 * @since x.x.x
 */
class Test_Ability_Sanitize_Returns_Error extends Abstract_Ability {
	/**
	 * {@inheritDoc}
	 */
	protected function category(): string {
		return 'test-category';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'x' => array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'failing_sanitize' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Returns a WP_Error for "bad" input.
	 *
	 * @param mixed $value Value.
	 * @return mixed|\WP_Error
	 */
	public function failing_sanitize( $value ) {
		if ( 'bad' === $value ) {
			return new WP_Error( 'test_sanitize', 'Failure from sanitize.' );
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute_callback( $input ) {
		return array( 'ok' => true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $input ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array();
	}
}

/**
 * Test experiment for Abstract_Ability tests.
 *
 * @since 0.1.0
 */
class Test_Ability_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public static function get_id(): string {
		return 'test-ability-experiment';
	}

	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{label: string, description: string, category: string} Experiment metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Test Ability Experiment',
			'description' => 'A test experiment for ability testing',
			'category'    => Experiment_Category::EDITOR,
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
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abstracts\Test_Ability_Experiment
	 */
	private Test_Ability_Experiment $experiment;

	/**
	 * Test ability instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abstracts\Test_Ability
	 */
	private Test_Ability $ability;

	/**
	 * Directory where the Test_Ability class file resides.
	 *
	 * @var string
	 */
	private string $feature_dir;

	/**
	 * Temporary files created during tests, cleaned up in tearDown.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

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
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		$this->experiment = new Test_Ability_Experiment();
		$this->ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);

		$reflection        = new \ReflectionClass( $this->ability );
		$file_name         = $reflection->getFileName();
		$this->feature_dir = dirname( $file_name );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( ! file_exists( $file ) ) {
				continue;
			}

			wp_delete_file( $file );
		}
		$this->temp_files = array();

		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Creates a temporary system instruction file and registers it for cleanup.
	 *
	 * @param string $filename The filename to create inside the feature directory.
	 * @param string $content  The PHP file content.
	 */
	private function create_system_instruction_file( string $filename, string $content ): void {
		$path               = trailingslashit( $this->feature_dir ) . $filename;
		$this->temp_files[] = $path;
		$result             = @file_put_contents( $path, $content );

		if ( false !== $result ) {
			return;
		}

		$this->fail( sprintf( 'Failed to create system instruction file at path: %s', $path ) );
	}

	/**
	 * Test that constructor properly sets up the ability.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_sets_up_ability() {
		$this->assertSame( $this->experiment->get_label(), $this->ability->get_label(), 'Label should be stored in ability' );
		$this->assertSame( 'Test Ability Experiment', $this->ability->get_label(), 'Label should be stored in ability' );
	}

	/**
	 * Test that constructor calls parent constructor with correct properties.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_calls_parent_with_properties() {
		// Verify the ability was registered with WordPress Abilities API.
		// We can't directly test parent::__construct, but we can verify the ability exists.
		$this->assertInstanceOf( Abstract_Ability::class, $this->ability, 'Ability should be instance of Abstract_Ability' );
	}

	/**
	 * Test that label() delegates to experiment's get_label().
	 *
	 * @since 0.1.0
	 */
	public function test_label_delegates_to_experiment() {
		// Use reflection to test protected method.
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_label' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability );

		$this->assertEquals( $this->experiment->get_label(), $result, 'Label should match experiment label' );
		$this->assertEquals( 'Test Ability Experiment', $result, 'Label should be correct' );
	}

	/**
	 * Test that description() delegates to experiment's get_description().
	 *
	 * @since 0.1.0
	 */
	public function test_description_delegates_to_experiment() {
		// Use reflection to test protected method.
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability );

		$this->assertEquals( $this->experiment->get_description(), $result, 'Description should match experiment description' );
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
		$system_instruction = $this->ability->get_system_instruction();

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
		$this->create_system_instruction_file(
			'test-system-instruction.php',
			<<<'PHP'
<?php
// Process the length variable if provided.
$length_desc = 'medium length';
if ( isset( $length ) ) {
	if ( 'short' === $length ) {
		$length_desc = 'short length';
	} elseif ( 'long' === $length ) {
		$length_desc = 'long length';
	}
}

// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
return <<<INSTRUCTION
You are a test assistant. The length is {$length_desc} and the tone is {$tone}. Max words: {$max_words}.
INSTRUCTION;
PHP
		);

		$data = array(
			'length'    => 'short',
			'tone'      => 'professional',
			'max_words' => 100,
		);

		$system_instruction = $this->ability->get_system_instruction( 'test-system-instruction.php', $data );

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertStringContainsString( 'short length', $system_instruction, 'System instruction should contain processed length value' );
		$this->assertStringContainsString( 'professional', $system_instruction, 'System instruction should contain tone value' );
		$this->assertStringContainsString( '100', $system_instruction, 'System instruction should contain max_words value' );
	}

	/**
	 * Test that get_system_instruction() works without data parameter (backward compatibility).
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_without_data_parameter() {
		$this->create_system_instruction_file(
			'test-system-instruction-simple.php',
			<<<'PHP'
<?php
// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
return <<<INSTRUCTION
You are a test assistant without data variables.
INSTRUCTION;
PHP
		);

		$system_instruction = $this->ability->get_system_instruction( 'test-system-instruction-simple.php' );

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertStringContainsString( 'test assistant', $system_instruction, 'System instruction should contain expected content' );
	}

	/**
	 * Regression test: get_system_instruction() must return the instruction on every call with the same file.
	 *
	 * Previously, the implementation used require_once for explicit filenames, which returns true
	 * (not the file's return value) on subsequent calls, causing the method to silently return an
	 * empty string. This test ensures that consecutive calls always return the correct string.
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_returns_instruction_on_consecutive_calls() {
		$this->create_system_instruction_file(
			'test-system-instruction-consecutive.php',
			<<<'PHP'
<?php
// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
return <<<INSTRUCTION
You are a test assistant for consecutive calls.
INSTRUCTION;
PHP
		);

		$first_result  = $this->ability->get_system_instruction( 'test-system-instruction-consecutive.php' );
		$second_result = $this->ability->get_system_instruction( 'test-system-instruction-consecutive.php' );

		$this->assertNotEmpty( $first_result, 'First call should return the instruction' );
		$this->assertNotEmpty( $second_result, 'Second call should also return the instruction' );
		$this->assertSame( $first_result, $second_result, 'Both calls should return the same instruction' );
	}

	/**
	 * Test that get_system_instruction() with empty data array works correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_with_empty_data_array() {
		$this->create_system_instruction_file(
			'test-system-instruction-empty.php',
			<<<'PHP'
<?php
// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
return <<<INSTRUCTION
You are a test assistant.
INSTRUCTION;
PHP
		);

		$system_instruction = $this->ability->get_system_instruction( 'test-system-instruction-empty.php', array() );

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertStringContainsString( 'test assistant', $system_instruction, 'System instruction should contain expected content' );
	}

	/**
	 * Test that wpai_system_instruction filter modifies system instructions.
	 *
	 * @since 0.7.0
	 */
	public function test_system_instruction_filter() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$filter_callback = static function ( $instruction, $name, $data ) {
			return $instruction . ' Appended by filter.';
		};

		add_filter( 'wpai_system_instruction', $filter_callback, 10, 3 );

		$result = $ability->get_system_instruction();

		remove_filter( 'wpai_system_instruction', $filter_callback, 10 );

		$this->assertStringContainsString( 'Appended by filter.', $result, 'System instruction filter should modify the instruction' );
	}

	/**
	 * Test that wpai_system_instruction filter receives the correct ability name.
	 *
	 * @since 0.7.0
	 */
	public function test_system_instruction_filter_receives_ability_name() {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability(
			'test-ability',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$filter_callback = static function ( $instruction, $name ) {
			return sprintf( 'ability:%s', $name );
		};

		add_filter( 'wpai_system_instruction', $filter_callback, 10, 2 );

		$result = $ability->get_system_instruction();

		remove_filter( 'wpai_system_instruction', $filter_callback, 10 );

		$this->assertSame( 'ability:test-ability', $result, 'Filter output should encode the ability name' );
	}

	/**
	 * Test that ensure_text_generation_supported() returns WP_Error when text generation is not supported.
	 *
	 * @since 0.7.0
	 */
	public function test_ensure_text_generation_supported_returns_wp_error_when_unsupported(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'ensure_text_generation_supported' );
		$method->setAccessible( true );

		$prompt_builder = new class() {
			/**
			 * @return false
			 */
			public function is_supported_for_text_generation(): bool {
				return false;
			}
		};

		$result = $method->invoke( $this->ability, $prompt_builder, 'Text capability missing.' );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error when unsupported' );
		$this->assertSame( 'unsupported_model', $result->get_error_code(), 'Error code should be unsupported_model' );
		$this->assertSame( 'Text capability missing.', $result->get_error_message(), 'Message should be passed through' );
	}

	/**
	 * Test that ensure_text_generation_supported() returns the builder when text generation is supported.
	 *
	 * @since 0.7.0
	 */
	public function test_ensure_text_generation_supported_returns_builder_when_supported(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'ensure_text_generation_supported' );
		$method->setAccessible( true );

		$prompt_builder = new class() {
			/**
			 * @return true
			 */
			public function is_supported_for_text_generation(): bool {
				return true;
			}
		};

		$result = $method->invoke( $this->ability, $prompt_builder, 'unused' );

		$this->assertSame( $prompt_builder, $result, 'Should return the same builder instance when supported' );
	}

	/**
	 * Test that ensure_image_generation_supported() returns WP_Error when image generation is not supported.
	 *
	 * @since 0.7.0
	 */
	public function test_ensure_image_generation_supported_returns_wp_error_when_unsupported(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'ensure_image_generation_supported' );
		$method->setAccessible( true );

		$prompt_builder = new class() {
			/**
			 * @return false
			 */
			public function is_supported_for_image_generation(): bool {
				return false;
			}
		};

		$result = $method->invoke( $this->ability, $prompt_builder, 'Image capability missing.' );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error when unsupported' );
		$this->assertSame( 'unsupported_model', $result->get_error_code(), 'Error code should be unsupported_model' );
		$this->assertSame( 'Image capability missing.', $result->get_error_message(), 'Message should be passed through' );
	}

	/**
	 * Test that ensure_image_generation_supported() returns the builder when image generation is supported.
	 *
	 * @since 0.7.0
	 */
	public function test_ensure_image_generation_supported_returns_builder_when_supported(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'ensure_image_generation_supported' );
		$method->setAccessible( true );

		$prompt_builder = new class() {
			/**
			 * @return true
			 */
			public function is_supported_for_image_generation(): bool {
				return true;
			}
		};

		$result = $method->invoke( $this->ability, $prompt_builder, 'unused' );

		$this->assertSame( $prompt_builder, $result, 'Should return the same builder instance when supported' );
	}

	/**
	 * Tests that execute_callback receives input with sanitize_text_field applied from input_schema.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_input_applies_sanitize_text_field_to_string(): void {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability_Sanitize_Text(
			'ai/test-sanitize-text',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$ability->execute( array( 'content' => '<b>Hello</b>' ) );

		$this->assertIsArray( $ability->last_input );
		$this->assertArrayHasKey( 'content', $ability->last_input, 'Stripped tags' );
		$this->assertSame( 'Hello', $ability->last_input['content'] );
	}

	/**
	 * Tests that absint and object-method sanitize_callback run before execute.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_input_applies_absint_and_method_callback(): void {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability_Sanitize_Callback_Styles(
			'ai/test-sanitize-callback-styles',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$ability->execute(
			array(
				'count'  => '  42  ',
				'prefix' => 'x',
			)
		);

		$this->assertSame( 42, $ability->last_input['count'] );
		$this->assertSame( 'P:x', $ability->last_input['prefix'] );
	}

	/**
	 * Tests nested object properties and array item sanitize_callback application.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_input_sanitizes_nested_and_array_items(): void {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability_Sanitize_Nested_And_Items(
			'ai/test-sanitize-nested',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$ability->execute(
			array(
				'parent'    => array( 'label' => 'ab' ),
				'fragments' => array( '<b>one</b>', '  two  ' ),
			)
		);

		$this->assertSame( 'AB', $ability->last_input['parent']['label'] );
		$this->assertSame( array( 'one', 'two' ), $ability->last_input['fragments'] );
	}

	/**
	 * Tests that a top-level default from the input schema is passed through sanitize_callback.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_input_sanitizes_top_level_object_default(): void {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability_Sanitize_Top_Level_Default(
			'ai/test-sanitize-default',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$ability->execute( null );

		$this->assertIsArray( $ability->last_input );
		$this->assertArrayHasKey( 'k', $ability->last_input );
		$this->assertSame( 7, $ability->last_input['k'] );
	}

	/**
	 * Tests that a WP_Error from a sanitize_callback is returned from execute.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_input_propagates_wp_error_from_sanitize_callback(): void {
		$experiment = new Test_Ability_Experiment();
		$ability    = new Test_Ability_Sanitize_Returns_Error(
			'ai/test-sanitize-wp-error',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);

		$result = $ability->execute( array( 'x' => 'bad' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'test_sanitize', $result->get_error_code() );
	}
}
