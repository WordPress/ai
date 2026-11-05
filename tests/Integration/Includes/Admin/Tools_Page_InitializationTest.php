<?php
/**
 * Integration tests for Tools page initialization.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Admin
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Admin;

use WP_UnitTestCase;
use WordPress\AI\Admin\Tools_Page;
use WordPress\AI\Main;

/**
 * Tests admin startup, including the Tools page file's bootstrap code.
 *
 * @since x.x.x
 */
class Tools_Page_InitializationTest extends WP_UnitTestCase {

	/**
	 * Tests that admin startup loads the Tools card and registers its callback.
	 *
	 * Runs separately so the Tools class is loaded during the test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_initialization_registers_tools_card(): void {
		set_current_screen( 'tools' );
		$this->assertFalse( class_exists( Tools_Page::class, false ) );

		Main::get_instance()->initialize_features();

		$callback = array( Tools_Page::class, 'render' );
		$this->assertSame( 10, has_action( 'tool_box', $callback ) );
		$this->assertTrue( is_callable( $callback ) );
	}
}
