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

		if ( wp_get_ability( 'ai-test/mcp-exposure' ) ) {
			wp_unregister_ability( 'ai-test/mcp-exposure' );
		}

		parent::tearDown();
	}

	/**
	 * Registers a test ability with the given meta.
	 *
	 * @param array<string, mixed> $meta Ability meta.
	 */
	private function register_test_ability( array $meta = array() ): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				'ai-test/mcp-exposure',
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
			has_action( 'rest_api_init' ),
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
