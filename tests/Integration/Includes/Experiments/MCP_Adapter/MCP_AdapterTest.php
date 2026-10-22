<?php
/**
 * Integration tests for the MCP_Adapter experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\MCP_Adapter
 */

namespace WordPress\AI\Tests\Integration\Experiments\MCP_Adapter;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\MCP_Adapter\Exposure_Overrides;
use WordPress\AI\Experiments\MCP_Adapter\MCP_Adapter;

require_once __DIR__ . '/Class_Ability_Fixture.php';

/**
 * MCP_Adapter experiment test case.
 */
class MCP_AdapterTest extends WP_UnitTestCase {
	/**
	 * Experiment under test.
	 *
	 * @var \WordPress\AI\Experiments\MCP_Adapter\MCP_Adapter
	 */
	private MCP_Adapter $experiment;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();
		$this->experiment = new MCP_Adapter();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		delete_option( Exposure_Overrides::OPTION_NAME );
		remove_all_filters( 'wp_register_ability_args' );
		$defaults = new \ReflectionProperty( Exposure_Overrides::class, 'registration_defaults' );
		$defaults->setAccessible( true );
		$defaults->setValue( null, array() );

		foreach ( array( 'ai-test/mcp-exposure', 'ai/mcp-test-class', 'ai/mcp-test-deprecated' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		parent::tearDown();
	}

	/**
	 * Registers a test ability with the given meta.
	 *
	 * @param array<string, mixed> $meta Ability meta.
	 * @param string               $name Ability name.
	 */
	private function register_test_ability( array $meta = array(), string $name = 'ai-test/mcp-exposure' ): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$name,
				array(
					'label'               => 'MCP exposure test ability',
					'description'         => 'Test ability for MCP exposure overrides.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
					'meta'                => $meta,
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Tests the experiment id.
	 */
	public function test_get_id() {
		$this->assertSame( 'mcp-adapter', MCP_Adapter::get_id() );
	}

	/**
	 * Tests the experiment metadata.
	 */
	public function test_metadata() {
		$this->assertNotEmpty( $this->experiment->get_label() );
		$this->assertNotEmpty( $this->experiment->get_description() );
		$this->assertSame( Experiment_Category::ADMIN, $this->experiment->get_category() );
	}

	/**
	 * Tests that register() hooks the exposure filter and REST routes.
	 */
	public function test_register_adds_hooks() {
		$this->experiment->register();

		$this->assertNotFalse(
			has_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ) ),
			'Should hook wp_register_ability_args to apply exposure overrides.'
		);
		$this->assertNotFalse(
			has_action( 'rest_api_init', array( $this->experiment, 'register_rest_routes' ) ),
			'Should register REST routes.'
		);
	}

	/**
	 * Tests that a saved override hides an otherwise public ability.
	 */
	public function test_override_hides_public_ability() {
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai-test/mcp-exposure' => false ) );
		$this->experiment->register();

		$this->register_test_ability( array( 'public' => true ) );

		$ability = wp_get_ability( 'ai-test/mcp-exposure' );
		$meta    = $ability->get_meta();

		$this->assertFalse( $meta['mcp']['public'], 'Override should force meta.mcp.public to false.' );
	}

	/**
	 * Tests that a saved override exposes an otherwise private ability.
	 */
	public function test_override_exposes_private_ability() {
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai-test/mcp-exposure' => true ) );
		$this->experiment->register();

		$this->register_test_ability();

		$ability = wp_get_ability( 'ai-test/mcp-exposure' );
		$meta    = $ability->get_meta();

		$this->assertTrue( $meta['mcp']['public'], 'Override should force meta.mcp.public to true.' );
	}

	/**
	 * Tests that abilities without an override keep their meta untouched.
	 */
	public function test_no_override_leaves_meta_untouched() {
		$this->experiment->register();

		$this->register_test_ability( array( 'public' => true ) );

		$ability = wp_get_ability( 'ai-test/mcp-exposure' );
		$meta    = $ability->get_meta();

		$this->assertArrayNotHasKey( 'mcp', $meta, 'Without an override the mcp meta key should not be injected.' );
	}

	/**
	 * Tests that the registration-time default is stashed when an override is applied.
	 */
	public function test_override_stashes_registration_default() {
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai-test/mcp-exposure' => false ) );
		$this->experiment->register();

		$this->register_test_ability( array( 'public' => true ) );

		$this->assertTrue(
			Exposure_Overrides::get_registration_default( 'ai-test/mcp-exposure' ),
			'The pre-override exposure default should be recoverable.'
		);
	}

	/**
	 * Tests that stored overrides for the adapter's own abilities are ignored.
	 */
	public function test_adapter_namespace_overrides_are_ignored() {
		update_option( Exposure_Overrides::OPTION_NAME, array( 'mcp-adapter/discover-abilities' => false ) );

		$this->assertSame(
			array(),
			Exposure_Overrides::get_overrides(),
			'Stale overrides for the adapter namespace must never be applied.'
		);
	}

	/**
	 * Tests that an override reaches abilities registered through ability_class.
	 */
	public function test_override_reaches_class_based_ability() {
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai/mcp-test-class' => false ) );
		$this->experiment->register();

		Class_Ability_Fixture::register(
			'ai/mcp-test-class',
			array(
				'mcp' => array(
					'public' => true,
					'type'   => 'tool',
				),
			)
		);

		$meta = wp_get_ability( 'ai/mcp-test-class' )->get_meta();

		$this->assertFalse( $meta['mcp']['public'], 'The override must reach abilities that declare meta in their class.' );
		$this->assertSame( 'tool', $meta['mcp']['type'], 'Other mcp meta keys declared by the class must survive.' );
		$this->assertTrue( Exposure_Overrides::get_registration_default( 'ai/mcp-test-class' ), 'The screen must report the exposed default, not the overridden value.' );
	}

	/**
	 * Tests that the plugin's own abilities are exposed by default while the experiment is on.
	 */
	public function test_plugin_abilities_default_to_exposed() {
		$this->experiment->register();

		Class_Ability_Fixture::register( 'ai/mcp-test-class' );

		$meta = wp_get_ability( 'ai/mcp-test-class' )->get_meta();

		$this->assertTrue( $meta['mcp']['public'], 'AI plugin abilities should be exposed by default.' );
		$this->assertTrue( Exposure_Overrides::get_registration_default( 'ai/mcp-test-class' ) );
	}

	/**
	 * Tests that a deprecated ability is never exposed, even with an override.
	 */
	public function test_deprecated_ability_is_never_exposed() {
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai/mcp-test-deprecated' => true ) );
		$this->experiment->register();

		$this->register_test_ability(
			array(
				'public'     => true,
				'deprecated' => array(
					'since'       => '1.4.0',
					'replacement' => 'ai/mcp-test-class',
				),
			),
			'ai/mcp-test-deprecated'
		);

		$meta = wp_get_ability( 'ai/mcp-test-deprecated' )->get_meta();

		$this->assertFalse( $meta['mcp']['public'], 'Deprecated aliases duplicate their replacement and must stay hidden.' );
	}

	/**
	 * Tests that the admin menu page is registered for admins.
	 */
	public function test_admin_menu_registered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->experiment->register();
		do_action( 'admin_menu' );

		$this->assertNotEmpty(
			menu_page_url( 'ai-mcp-access', false ),
			'MCP Access admin page should be registered under Tools.'
		);
	}
}
