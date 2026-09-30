<?php
/**
 * Integration tests for the WebMCP tool curator.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\WebMCP
 */

namespace WordPress\AI\Tests\Integration\Experiments\WebMCP;

use WP_UnitTestCase;
use WordPress\AI\Experiments\WebMCP\Tool_Curator;
use WordPress\AI\Experiments\WebMCP\WebMCP;

/**
 * Tool_Curator test case.
 *
 * @since x.x.x
 */
class Tool_CuratorTest extends WP_UnitTestCase {

	/**
	 * Abilities registered by a test, unregistered on teardown.
	 *
	 * @var list<string>
	 */
	private array $registered = array();

	/**
	 * Curator under test.
	 *
	 * @var \WordPress\AI\Experiments\WebMCP\Tool_Curator
	 */
	private Tool_Curator $curator;

	/**
	 * Sets up the curator.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->curator = new Tool_Curator( 'webmcp' );
	}

	/**
	 * Cleans up.
	 */
	public function tearDown(): void {
		foreach ( $this->registered as $name ) {
			wp_unregister_ability( $name );
		}
		$this->registered = array();
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_webmcp_field_admin_abilities' );
		delete_option( 'wpai_feature_webmcp_field_visitor_abilities' );
		remove_all_filters( 'wpai_webmcp_exposed_abilities' );
		remove_all_filters( 'wpai_webmcp_max_tools' );
		parent::tearDown();
	}

	/**
	 * Registers a test ability inside the abilities init context.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Overrides.
	 */
	private function register_ability( string $name, array $args = array() ): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register a test ability.

		try {
			wp_register_ability(
				$name,
				array_merge(
					array(
						'label'               => 'Test ' . $name,
						'description'         => 'Test ability ' . $name,
						'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
						'execute_callback'    => static function () use ( $name ) {
							return array( 'ran' => $name );
						},
						'permission_callback' => '__return_true',
					),
					$args
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered[] = $name;
	}

	/**
	 * Tests that nothing is exposed without an opt-in.
	 */
	public function test_nothing_is_exposed_by_default(): void {
		$this->register_ability( 'webmcp-test/plain' );

		$this->assertSame( array(), $this->curator->get_exposed_names( WebMCP::CONTEXT_ADMIN ) );
		$this->assertSame( array(), $this->curator->get_exposed_names( WebMCP::CONTEXT_VISITOR ) );
		$this->assertFalse( $this->curator->has_exposed_abilities( WebMCP::CONTEXT_ADMIN ) );
	}

	/**
	 * Tests the three meta opt-in shapes.
	 */
	public function test_meta_opt_in_shapes(): void {
		$this->register_ability( 'webmcp-test/everywhere', array( 'meta' => array( 'webmcp' => true ) ) );
		$this->register_ability( 'webmcp-test/admin-only', array( 'meta' => array( 'webmcp' => 'admin' ) ) );
		$this->register_ability( 'webmcp-test/visitor-only', array( 'meta' => array( 'webmcp' => array( 'visitor' => true ) ) ) );

		$this->assertSame(
			array( 'webmcp-test/admin-only', 'webmcp-test/everywhere' ),
			$this->curator->get_exposed_names( WebMCP::CONTEXT_ADMIN )
		);
		$this->assertSame(
			array( 'webmcp-test/everywhere', 'webmcp-test/visitor-only' ),
			$this->curator->get_exposed_names( WebMCP::CONTEXT_VISITOR )
		);
	}

	/**
	 * Tests the settings field and the filter, and that unknown names are dropped.
	 */
	public function test_settings_and_filter_expose_abilities(): void {
		$this->register_ability( 'webmcp-test/from-settings' );
		$this->register_ability( 'webmcp-test/from-filter' );

		update_option( 'wpai_feature_webmcp_field_admin_abilities', "webmcp-test/from-settings, does-not/exist\n" );
		add_filter(
			'wpai_webmcp_exposed_abilities',
			static function ( array $names, string $context ) {
				if ( WebMCP::CONTEXT_ADMIN === $context ) {
					$names[] = 'webmcp-test/from-filter';
				}
				return $names;
			},
			10,
			2
		);

		$this->assertSame(
			array( 'webmcp-test/from-filter', 'webmcp-test/from-settings' ),
			$this->curator->get_exposed_names( WebMCP::CONTEXT_ADMIN )
		);
		$this->assertSame( array(), $this->curator->get_exposed_names( WebMCP::CONTEXT_VISITOR ) );
		$this->assertTrue( $this->curator->is_exposed( 'webmcp-test/from-filter', WebMCP::CONTEXT_ADMIN ) );
		$this->assertFalse( $this->curator->is_exposed( 'webmcp-test/from-filter', WebMCP::CONTEXT_VISITOR ) );
	}

	/**
	 * Tests the wire name mapping in both directions.
	 */
	public function test_tool_name_separator_round_trips(): void {
		$this->assertSame( 'core__get-post', Tool_Curator::to_tool_name( 'core/get-post' ) );
		$this->assertSame( 'core/get-post', Tool_Curator::to_ability_name( 'core__get-post' ) );
		$this->assertSame( 'my-plugin/nested-name', Tool_Curator::to_ability_name( Tool_Curator::to_tool_name( 'my-plugin/nested-name' ) ) );
	}

	/**
	 * Tests the tool shape: name, description, an object schema, and annotations from the ability.
	 */
	public function test_convert_produces_webmcp_tool_shape(): void {
		$this->register_ability(
			'webmcp-test/shape',
			array(
				'label'        => 'Shape',
				'description'  => 'Returns a shape.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'meta'         => array(
					'webmcp'      => true,
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		$tool = $this->curator->convert( wp_get_ability( 'webmcp-test/shape' ) );

		$this->assertSame( 'webmcp-test__shape', $tool['name'] );
		$this->assertSame( 'Shape. Returns a shape.', $tool['description'] );
		$this->assertSame( 'object', $tool['inputSchema']['type'] );
		$this->assertArrayHasKey( 'id', $tool['inputSchema']['properties'] );
		$this->assertTrue( $tool['annotations']['readOnlyHint'] );
		$this->assertFalse( $tool['annotations']['destructiveHint'] );
		$this->assertTrue( $tool['annotations']['idempotentHint'] );
	}

	/**
	 * Tests that an ability without an input schema gets an empty object schema, encoded as `{}` not `[]`.
	 */
	public function test_convert_gives_schemaless_ability_an_object_schema(): void {
		$this->register_ability( 'webmcp-test/no-schema', array( 'meta' => array( 'webmcp' => true ) ) );

		$tool = $this->curator->convert( wp_get_ability( 'webmcp-test/no-schema' ) );

		$this->assertSame( 'object', $tool['inputSchema']['type'] );
		$this->assertStringContainsString( '"properties":{}', wp_json_encode( $tool['inputSchema'] ) );
	}

	/**
	 * Tests the per-page cap and the truncated count.
	 */
	public function test_get_tools_respects_the_cap(): void {
		$this->register_ability( 'webmcp-test/one', array( 'meta' => array( 'webmcp' => true ) ) );
		$this->register_ability( 'webmcp-test/two', array( 'meta' => array( 'webmcp' => true ) ) );
		$this->register_ability( 'webmcp-test/three', array( 'meta' => array( 'webmcp' => true ) ) );

		add_filter( 'wpai_webmcp_max_tools', static fn() => 2 );

		$result = $this->curator->get_tools( WebMCP::CONTEXT_ADMIN );

		$this->assertCount( 2, $result['tools'] );
		$this->assertSame( 1, $result['truncated'] );
		$this->assertSame( 2, $this->curator->get_max_tools() );
	}

	/**
	 * Tests that an ability the current user may not run is not listed.
	 */
	public function test_get_tools_hides_abilities_the_user_may_not_run(): void {
		$this->register_ability( 'webmcp-test/allowed', array( 'meta' => array( 'webmcp' => true ) ) );
		$this->register_ability(
			'webmcp-test/forbidden',
			array(
				'meta'                => array( 'webmcp' => true ),
				'permission_callback' => '__return_false',
			)
		);

		$names = array_column( $this->curator->get_tools( WebMCP::CONTEXT_ADMIN )['tools'], 'name' );

		$this->assertSame( array( 'webmcp-test__allowed' ), $names );
	}

	/**
	 * Tests that an unknown context exposes nothing.
	 */
	public function test_unknown_context_exposes_nothing(): void {
		$this->register_ability( 'webmcp-test/everywhere', array( 'meta' => array( 'webmcp' => true ) ) );

		$this->assertFalse( Tool_Curator::is_valid_context( 'editor' ) );
		$this->assertSame( array(), $this->curator->get_exposed_names( 'editor' ) );
	}
}
