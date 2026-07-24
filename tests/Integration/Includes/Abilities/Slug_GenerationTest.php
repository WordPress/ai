<?php
/**
 * Integration tests for the Slug_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Slug_Generation\Slug_Generation;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Slug_Generation Ability tests.
 */
class Test_Slug_Generation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'slug-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Slug Generation',
			'description' => 'Suggests slug suggestions from content',
		);
	}

	/**
	 * Registers the experiment.
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Slug_Generation Ability test case.
 */
class Slug_GenerationTest extends WP_UnitTestCase {

	/**
	 * Slug_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Slug_Generation\Slug_Generation
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Slug_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Slug_Generation_Experiment();
		$this->ability    = new Slug_Generation(
			'ai/slug-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that guideline_categories() returns site and copy.
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
	 * Test that input_schema() returns the expected schema structure.
	 */
	public function test_input_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Input schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'title', $schema['properties'], 'Schema should have title property' );
		$this->assertArrayHasKey( 'content', $schema['properties'], 'Schema should have content property' );
		$this->assertArrayHasKey( 'context', $schema['properties'], 'Schema should have context property' );
		$this->assertArrayHasKey( 'number_of_suggestions', $schema['properties'], 'Schema should have number_of_suggestions property' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'slugs', $schema['properties'], 'Schema should have slugs property' );
		$this->assertEquals( 'array', $schema['properties']['slugs']['type'], 'slugs should be array type' );
	}
}
