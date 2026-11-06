<?php
/**
 * Integration tests for the Tools_Page class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Admin
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Admin;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Admin\Tools_Page;

/**
 * Tests tool links on the Available Tools screen.
 *
 * @covers \WordPress\AI\Admin\Tools_Page
 *
 * @since x.x.x
 */
class Tools_PageTest extends WP_UnitTestCase {

	/**
	 * Original registry entries.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_entries;

	/**
	 * Original menu globals.
	 *
	 * @var array<string, mixed>
	 */
	private array $menu_globals;

	/**
	 * Original user ID.
	 *
	 * @var int
	 */
	private int $original_user_id;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_user_id = get_current_user_id();
		$this->menu_globals     = array();
		foreach ( array( 'submenu', '_parent_pages', '_registered_pages', '_wp_submenu_nopriv' ) as $name ) {
			$this->menu_globals[ $name ] = $GLOBALS[ $name ] ?? null;
			$GLOBALS[ $name ]            = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Isolate core menu state in this test.
		}

		$entries                = $this->get_entries_property();
		$this->original_entries = $entries->getValue();
		$entries->setValue( null, array() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		$this->get_entries_property()->setValue( null, $this->original_entries );
		foreach ( $this->menu_globals as $name => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $value; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restore core menu state.
			}
		}
		wp_set_current_user( $this->original_user_id );

		parent::tearDown();
	}

	/**
	 * Tests that init registers the tool_box action.
	 */
	public function test_init_registers_tool_box_action(): void {
		remove_action( 'tool_box', array( Tools_Page::class, 'render' ) );
		$this->assertFalse( has_action( 'tool_box', array( Tools_Page::class, 'render' ) ) );

		Tools_Page::init();
		$this->assertSame( 10, has_action( 'tool_box', array( Tools_Page::class, 'render' ) ) );
	}

	/**
	 * Tests that the card is absent when no pages are registered.
	 */
	public function test_render_omits_empty_card(): void {
		$this->assertSame( '', $this->render_card() );
	}

	/**
	 * Tests submenu registration, callback wiring, URL generation, and escaping.
	 */
	public function test_registers_and_renders_page(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$label       = 'Research <tool>';
		$description = 'Compare A & B.';
		$callback    = static function (): void {};
		$hook        = Tools_Page::add_page( 'ai-test-tool', $label, $description, $callback );

		$this->assertSame( get_plugin_page_hookname( 'ai-test-tool', 'tools.php' ), $hook );
		$this->assertSame( array( array( $label, 'manage_options', 'ai-test-tool', $label ) ), $submenu['tools.php'] );
		$this->assertSame( 10, has_action( $hook, $callback ) );

		$html = $this->render_card();
		$this->assertStringContainsString( '<div class="card">', $html );
		$this->assertStringContainsString( '<h2 class="title">AI Tools</h2>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'tools.php?page=ai-test-tool' ) ) . '"', $html );
		$this->assertStringContainsString( 'Research &lt;tool&gt;', $html );
		$this->assertStringContainsString( 'Compare A &amp; B.', $html );
	}

	/**
	 * Tests that registration fails and card remains empty when user lacks required capability.
	 */
	public function test_add_page_returns_false_when_user_lacks_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( Tools_Page::add_page( 'ai-private-tool', 'Private', 'Private tool.', static function (): void {} ) );
		$this->assertSame( '', $this->render_card() );
	}

	/**
	 * Returns the private registry property for test state isolation.
	 *
	 * @return \ReflectionProperty The registry property.
	 */
	private function get_entries_property(): ReflectionProperty {
		$property = new ReflectionProperty( Tools_Page::class, 'entries' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		return $property;
	}

	/**
	 * Returns the rendered Available Tools card.
	 *
	 * @return string The card markup.
	 */
	private function render_card(): string {
		ob_start();
		try {
			Tools_Page::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}
}
