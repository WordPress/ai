<?php
/**
 * Integration tests for the Ability_Table class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Abilities_Explorer\Ability_Table;

/**
 * Ability_Table test case.
 *
 * @since x.x.x
 */
class Ability_TableTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user for tests.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'tools_page_ai-abilities-explorer' );
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
	 * Test get_unique_providers returns only the known origins by default.
	 *
	 * @since x.x.x
	 */
	public function test_get_unique_providers_returns_known_origins() {
		$table = new Ability_Table();
		$table->prepare_items();

		$this->assertSame( array( 'Core', 'Plugin', 'Theme' ), $table->get_unique_providers() );
	}

	/**
	 * Test get_unique_providers appends custom providers after the known origins.
	 *
	 * @since x.x.x
	 */
	public function test_get_unique_providers_includes_custom_providers() {
		global $wp_current_filter;

		$slug = 'custom-provider-plugin/table-ability';

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$slug,
				array(
					'label'               => 'Custom Provider Table Ability',
					'description'         => 'Test ability with a custom provider label.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'meta'                => array( 'provider' => 'My Custom Plugin' ),
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$table = new Ability_Table();
		$table->prepare_items();

		wp_unregister_ability( $slug );

		$this->assertSame( array( 'Core', 'Plugin', 'Theme', 'My Custom Plugin' ), $table->get_unique_providers() );
	}
}
