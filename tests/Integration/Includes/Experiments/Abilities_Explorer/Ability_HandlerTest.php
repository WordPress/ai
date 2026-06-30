<?php
/**
 * Integration tests for the Ability_Handler class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Abilities_Explorer\Ability_Handler;

/**
 * Ability_Handler test case.
 *
 * @since 0.2.0
 */
class Ability_HandlerTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user for tests.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test get_all_abilities returns array.
	 *
	 * @since 0.2.0
	 */
	public function test_get_all_abilities_returns_array() {
		$abilities = Ability_Handler::get_all_abilities();

		$this->assertIsArray( $abilities );
	}

	/**
	 * Test get_ability returns null for non-existent ability.
	 *
	 * @since 0.2.0
	 */
	public function test_get_ability_returns_null_for_nonexistent() {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$ability = Ability_Handler::get_ability( 'nonexistent/ability-slug' );

		$this->assertNull( $ability );
	}

	/**
	 * Test validate_input returns valid for empty schema.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_returns_valid_for_empty_schema() {
		$result = Ability_Handler::validate_input( array(), array( 'foo' => 'bar' ) );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test validate_input detects missing required fields.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_detects_missing_required_fields() {
		$schema = array(
			'required'   => array( 'name', 'email' ),
			'properties' => array(
				'name'  => array( 'type' => 'string' ),
				'email' => array( 'type' => 'string' ),
			),
		);

		$input = array( 'name' => 'John' ); // Missing email.

		$result = Ability_Handler::validate_input( $schema, $input );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'email', $result['errors'][0] );
	}

	/**
	 * Test validate_input passes when all required fields present.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_passes_with_all_required_fields() {
		$schema = array(
			'required'   => array( 'name' ),
			'properties' => array(
				'name' => array( 'type' => 'string' ),
			),
		);

		$input = array( 'name' => 'John' );

		$result = Ability_Handler::validate_input( $schema, $input );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test validate_input validates string type.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_validates_string_type() {
		$schema = array(
			'properties' => array(
				'name' => array( 'type' => 'string' ),
			),
		);

		// Valid string.
		$result = Ability_Handler::validate_input( $schema, array( 'name' => 'John' ) );
		$this->assertTrue( $result['valid'] );

		// Invalid: number instead of string.
		$result = Ability_Handler::validate_input( $schema, array( 'name' => 123 ) );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_input validates number type.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_validates_number_type() {
		$schema = array(
			'properties' => array(
				'age' => array( 'type' => 'number' ),
			),
		);

		// Valid number.
		$result = Ability_Handler::validate_input( $schema, array( 'age' => 25 ) );
		$this->assertTrue( $result['valid'] );

		// Invalid: numeric strings are still strings.
		$result = Ability_Handler::validate_input( $schema, array( 'age' => '25' ) );
		$this->assertFalse( $result['valid'] );

		// Invalid: non-numeric string.
		$result = Ability_Handler::validate_input( $schema, array( 'age' => 'twenty-five' ) );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_input validates boolean type.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_validates_boolean_type() {
		$schema = array(
			'properties' => array(
				'active' => array( 'type' => 'boolean' ),
			),
		);

		// Valid boolean.
		$result = Ability_Handler::validate_input( $schema, array( 'active' => true ) );
		$this->assertTrue( $result['valid'] );

		$result = Ability_Handler::validate_input( $schema, array( 'active' => false ) );
		$this->assertTrue( $result['valid'] );

		// Invalid: string instead of boolean.
		$result = Ability_Handler::validate_input( $schema, array( 'active' => 'true' ) );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_input validates array type.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_validates_array_type() {
		$schema = array(
			'properties' => array(
				'items' => array( 'type' => 'array' ),
			),
		);

		// Valid array.
		$result = Ability_Handler::validate_input( $schema, array( 'items' => array( 1, 2, 3 ) ) );
		$this->assertTrue( $result['valid'] );

		// Invalid: string instead of array.
		$result = Ability_Handler::validate_input( $schema, array( 'items' => 'not an array' ) );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_input validates object type.
	 *
	 * @since 0.2.0
	 */
	public function test_validate_input_validates_object_type() {
		$schema = array(
			'properties' => array(
				'data' => array( 'type' => 'object' ),
			),
		);

		// Valid object (stdClass).
		$result = Ability_Handler::validate_input( $schema, array( 'data' => new \stdClass() ) );
		$this->assertTrue( $result['valid'] );

		// Valid: associative array treated as object.
		$result = Ability_Handler::validate_input( $schema, array( 'data' => array( 'key' => 'value' ) ) );
		$this->assertTrue( $result['valid'] );

		// Invalid: string instead of object.
		$result = Ability_Handler::validate_input( $schema, array( 'data' => 'not an object' ) );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_input validates integer type.
	 *
	 * @since 1.0.2
	 */
	public function test_validate_input_validates_integer_type() {
		$schema = array(
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
			),
		);

		$result = Ability_Handler::validate_input( $schema, array( 'count' => 5 ) );
		$this->assertTrue( $result['valid'] );

		$result = Ability_Handler::validate_input( $schema, array( 'count' => 1.5 ) );
		$this->assertFalse( $result['valid'] );

		$result = Ability_Handler::validate_input( $schema, array( 'count' => '5' ) );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_input validates numeric minimum and maximum constraints.
	 *
	 * @since 1.0.2
	 */
	public function test_validate_input_validates_numeric_constraints() {
		$schema = array(
			'properties' => array(
				'count' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
				),
			),
		);

		$result = Ability_Handler::validate_input( $schema, array( 'count' => 5 ) );
		$this->assertTrue( $result['valid'] );

		$result = Ability_Handler::validate_input( $schema, array( 'count' => 0 ) );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'at least 1', $result['errors'][0] );

		$result = Ability_Handler::validate_input( $schema, array( 'count' => 11 ) );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'at most 10', $result['errors'][0] );
	}

	/**
	 * Test validate_input validates enum constraints.
	 *
	 * @since 1.0.2
	 */
	public function test_validate_input_validates_enum_constraints() {
		$schema = array(
			'properties' => array(
				'strategy' => array(
					'type' => 'string',
					'enum' => array( 'existing_only', 'allow_new' ),
				),
			),
		);

		$result = Ability_Handler::validate_input( $schema, array( 'strategy' => 'existing_only' ) );
		$this->assertTrue( $result['valid'] );

		$result = Ability_Handler::validate_input( $schema, array( 'strategy' => 'invalid' ) );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'must be one of', $result['errors'][0] );
	}

	/**
	 * Test validate_input accepts a scalar top-level schema and input.
	 *
	 * The Abilities API permits non-object input schemas (e.g. a bare
	 * integer). The handler must accept a scalar input value rather than
	 * requiring an array.
	 *
	 * @since 1.1.0
	 */
	public function test_validate_input_accepts_scalar_input() {
		$schema = array(
			'type'        => 'integer',
			'description' => 'The ID of the item to be analysed',
			'required'    => true,
		);

		$result = Ability_Handler::validate_input( $schema, 42 );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test get_statistics returns expected structure.
	 *
	 * @since 0.2.0
	 */
	public function test_get_statistics_returns_expected_structure() {
		$stats = Ability_Handler::get_statistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'by_provider', $stats );
		$this->assertIsInt( $stats['total'] );
		$this->assertIsArray( $stats['by_provider'] );
		$this->assertArrayHasKey( 'Core', $stats['by_provider'] );
		$this->assertArrayHasKey( 'Plugin', $stats['by_provider'] );
		$this->assertArrayHasKey( 'Theme', $stats['by_provider'] );
	}

	/**
	 * Test invoke_ability accepts a scalar input value.
	 *
	 * Abilities may declare a non-object input schema (e.g. a bare integer),
	 * in which case the value passed to execute() is a scalar rather than an
	 * array. The handler must forward the scalar through unchanged.
	 *
	 * @since 1.1.0
	 */
	public function test_invoke_ability_accepts_scalar_input() {
		global $wp_current_filter;

		// Built-in plugin abilities registered during init use the "site"
		// category; ensure it exists so triggering init stays quiet.
		$this->ensure_site_category();

		$slug = 'ai/scalar-input-ability';

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$slug,
				array(
					'label'               => 'Scalar Input Ability',
					'description'         => 'Test ability accepting a bare integer.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'input_schema'        => array(
						'type'        => 'integer',
						'description' => 'The ID of the item to be analysed',
						'required'    => true,
					),
					'output_schema'       => array( 'type' => 'integer' ),
					'execute_callback'    => static function ( $input ) {
						return $input;
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$result = Ability_Handler::invoke_ability( $slug, 42 );

		wp_unregister_ability( $slug );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 42, $result['data'] );
	}

	/**
	 * Ensures the "site" ability category is registered.
	 *
	 * Built-in plugin abilities declare the "site" category. When a test
	 * triggers the abilities API init those abilities register, so the
	 * category must exist first to avoid an incorrect-usage notice.
	 *
	 * @since 1.1.0
	 */
	private function ensure_site_category(): void {
		if ( wp_has_ability_category( 'site' ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				'site',
				array(
					'label'       => 'Site',
					'description' => 'Site.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Test invoke_ability returns error for non-existent ability.
	 *
	 * @since 0.2.0
	 */
	public function test_invoke_ability_returns_error_for_nonexistent() {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$result = Ability_Handler::invoke_ability( 'nonexistent/ability' );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}
}
