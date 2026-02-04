<?php
/**
 * Integration tests for the Admin_Page class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Abilities_Explorer\Admin_Page;

/**
 * Admin_Page test case.
 *
 * @since 0.2.0
 */
class Admin_PageTest extends WP_UnitTestCase {
	/**
	 * Admin page instance.
	 *
	 * @var Admin_Page
	 */
	private $admin_page;

	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_page = new Admin_Page();
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
	 * Test that admin menu is registered under Tools.
	 *
	 * @since 0.2.0
	 */
	public function test_admin_menu_is_registered() {
		global $submenu;

		// Log in as admin.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->admin_page->init();
		do_action( 'admin_menu' );

		// Abilities Explorer is a submenu under Tools; submenu slugs are at index 2.
		$tools_submenus = $submenu['tools.php'] ?? array();
		$submenu_slugs  = array_column( $tools_submenus, 2 );
		$this->assertContains( 'ai-abilities-explorer', $submenu_slugs );
	}

	/**
	 * Test that AJAX action is registered.
	 *
	 * @since 0.2.0
	 */
	public function test_ajax_action_is_registered() {
		$this->admin_page->init();

		$this->assertTrue( has_action( 'wp_ajax_ai_ability_explorer_invoke' ) !== false );
	}

	/**
	 * Test generate_example_input returns empty array for empty schema.
	 *
	 * @since 0.2.0
	 */
	public function test_generate_example_input_returns_empty_for_empty_schema() {
		$reflection = new \ReflectionClass( $this->admin_page );
		$method     = $reflection->getMethod( 'generate_example_input' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->admin_page, array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test generate_example_input uses default values.
	 *
	 * @since 0.2.0
	 */
	public function test_generate_example_input_uses_default_values() {
		$reflection = new \ReflectionClass( $this->admin_page );
		$method     = $reflection->getMethod( 'generate_example_input' );
		$method->setAccessible( true );

		$schema = array(
			'properties' => array(
				'name' => array(
					'type'    => 'string',
					'default' => 'Default Name',
				),
			),
		);

		$result = $method->invoke( $this->admin_page, $schema );

		$this->assertEquals( 'Default Name', $result['name'] );
	}

	/**
	 * Test generate_example_input uses example values.
	 *
	 * @since 0.2.0
	 */
	public function test_generate_example_input_uses_example_values() {
		$reflection = new \ReflectionClass( $this->admin_page );
		$method     = $reflection->getMethod( 'generate_example_input' );
		$method->setAccessible( true );

		$schema = array(
			'properties' => array(
				'email' => array(
					'type'    => 'string',
					'example' => 'test@example.com',
				),
			),
		);

		$result = $method->invoke( $this->admin_page, $schema );

		$this->assertEquals( 'test@example.com', $result['email'] );
	}

	/**
	 * Test generate_example_input generates type-appropriate defaults.
	 *
	 * @since 0.2.0
	 */
	public function test_generate_example_input_generates_type_defaults() {
		$reflection = new \ReflectionClass( $this->admin_page );
		$method     = $reflection->getMethod( 'generate_example_input' );
		$method->setAccessible( true );

		$schema = array(
			'properties' => array(
				'text'    => array( 'type' => 'string' ),
				'count'   => array( 'type' => 'integer' ),
				'amount'  => array( 'type' => 'number' ),
				'active'  => array( 'type' => 'boolean' ),
				'items'   => array( 'type' => 'array' ),
				'options' => array( 'type' => 'object' ),
			),
		);

		$result = $method->invoke( $this->admin_page, $schema );

		$this->assertSame( '', $result['text'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( 0, $result['amount'] );
		$this->assertSame( false, $result['active'] );
		$this->assertSame( array(), $result['items'] );
		$this->assertInstanceOf( \stdClass::class, $result['options'] );
	}

	/**
	 * Test that page render requires manage_options capability.
	 *
	 * @since 0.2.0
	 */
	public function test_render_page_requires_manage_options() {
		// Log in as subscriber (no manage_options).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );

		$this->admin_page->render_page();
	}

	/**
	 * Test that page renders for admin user.
	 *
	 * @since 0.2.0
	 */
	public function test_render_page_works_for_admin() {
		// Log in as admin.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->admin_page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ability-explorer-wrap', $output );
		$this->assertStringContainsString( 'Ability Explorer', $output );
	}
}
