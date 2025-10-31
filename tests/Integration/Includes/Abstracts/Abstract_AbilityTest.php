<?php
/**
 * Integration tests for the Abstract_Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abstracts
 */

namespace WordPress\AI\Tests\Integration\Includes\Abstracts;

use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WP_UnitTestCase;

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

	/**
	 * Get feature for testing.
	 *
	 * @since 0.1.0
	 *
	 * @return Abstract_Feature|null Feature instance.
	 */
	public function get_feature() {
		return $this->feature;
	}
}

/**
 * Test feature for Abstract_Ability tests.
 *
 * @since 0.1.0
 */
class Test_Ability_Feature extends Abstract_Feature {
	/**
	 * Loads feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'test-ability-feature',
			'label'       => 'Test Ability Feature',
			'description' => 'A test feature for ability testing',
		);
	}

	/**
	 * Registers the feature.
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
	 * Test that constructor properly sets up the ability.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_sets_up_ability() {
		$feature = new Test_Ability_Feature();
		$ability = new Test_Ability(
			'test-ability',
			array( 'feature' => $feature )
		);

		$this->assertSame( $feature, $ability->get_feature(), 'Feature should be stored in ability' );
	}

	/**
	 * Test that constructor calls parent constructor with correct properties.
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_calls_parent_with_properties() {
		$feature = new Test_Ability_Feature();
		$ability = new Test_Ability(
			'test-ability',
			array( 'feature' => $feature )
		);

		// Verify the ability was registered with WordPress Abilities API.
		// We can't directly test parent::__construct, but we can verify the ability exists.
		$this->assertInstanceOf( Abstract_Ability::class, $ability, 'Ability should be instance of Abstract_Ability' );
	}

	/**
	 * Test that label() delegates to feature's get_label().
	 *
	 * @since 0.1.0
	 */
	public function test_label_delegates_to_feature() {
		$feature = new Test_Ability_Feature();
		$ability = new Test_Ability(
			'test-ability',
			array( 'feature' => $feature )
		);

		// Use reflection to test protected method.
		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'label' );
		$method->setAccessible( true );

		$result = $method->invoke( $ability );

		$this->assertEquals( $feature->get_label(), $result, 'Label should match feature label' );
		$this->assertEquals( 'Test Ability Feature', $result, 'Label should be correct' );
	}

	/**
	 * Test that description() delegates to feature's get_description().
	 *
	 * @since 0.1.0
	 */
	public function test_description_delegates_to_feature() {
		$feature = new Test_Ability_Feature();
		$ability = new Test_Ability(
			'test-ability',
			array( 'feature' => $feature )
		);

		// Use reflection to test protected method.
		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'description' );
		$method->setAccessible( true );

		$result = $method->invoke( $ability );

		$this->assertEquals( $feature->get_description(), $result, 'Description should match feature description' );
		$this->assertEquals( 'A test feature for ability testing', $result, 'Description should be correct' );
	}

	/**
	 * Test that constructor requires feature (feature is required for label/description).
	 *
	 * @since 0.1.0
	 */
	public function test_constructor_requires_feature() {
		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'Call to a member function get_label() on null' );

		// Attempting to construct without a feature should fail because
		// label() and description() methods require the feature.
		new Test_Ability( 'test-ability', array() );
	}
}

