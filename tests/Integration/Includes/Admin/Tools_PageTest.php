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
 * Tests experiment links on the Available Tools screen.
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
	 * Tests that the card is absent before any experiments register.
	 */
	public function test_render_omits_empty_card(): void {
		$this->assertSame( '', $this->render_card() );
	}

	/**
	 * Tests submenu registration, callback wiring, URL resolution, and escaping.
	 */
	public function test_registers_and_renders_an_experiment(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$label       = 'Research <tool>';
		$description = 'Compare A & B.';
		$callback    = static function (): void {};
		$hook        = Tools_Page::add_experiment( 'ai-test-tool', $label, $description, $callback );

		$this->assertSame( get_plugin_page_hookname( 'ai-test-tool', 'tools.php' ), $hook );
		$this->assertSame( array( array( $label, 'manage_options', 'ai-test-tool', $label ) ), $submenu['tools.php'] );
		$this->assertSame( 10, has_action( $hook, $callback ) );

		$html = $this->render_card();
		$this->assertStringContainsString( 'AI Experiments', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'tools.php?page=ai-test-tool' ) ) . '"', $html );
		$this->assertStringContainsString( 'Research &lt;tool&gt;', $html );
		$this->assertStringContainsString( 'Compare A &amp; B.', $html );
	}

	/**
	 * Tests that unavailable experiments do not produce links or an empty card.
	 */
	public function test_default_capability_restricts_registration_and_rendering(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( Tools_Page::add_experiment( 'ai-private-tool', 'Private', 'Private tool.', static function (): void {} ) );
		$this->assertSame( '', $this->render_card() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertIsString( Tools_Page::add_experiment( 'ai-private-tool', 'Private', 'Private tool.', static function (): void {} ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->render_card() );
	}

	/**
	 * Tests that a custom capability applies to the submenu and card link.
	 */
	public function test_custom_capability_applies_to_each_entry(): void {
		global $submenu;

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		Tools_Page::add_experiment( 'ai-private-tool', 'Private tool', 'Admin only.', static function (): void {} );

		wp_set_current_user( $editor );
		$hook = Tools_Page::add_experiment( 'ai-editor-tool', 'Editor tool', 'Editor access.', static function (): void {}, 'edit_others_posts' );

		$this->assertIsString( $hook );
		$this->assertContains( array( 'Editor tool', 'edit_others_posts', 'ai-editor-tool', 'Editor tool' ), $submenu['tools.php'] );
		$html = $this->render_card();
		$this->assertStringContainsString( 'Editor tool', $html );
		$this->assertStringNotContainsString( 'Private tool', $html );
	}

	/**
	 * Tests the Tools URL fallback when core has no parent page mapping.
	 */
	public function test_falls_back_when_menu_page_url_is_empty(): void {
		global $_parent_pages;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Tools_Page::add_experiment( 'ai-test-tool', 'Research', 'Research tool.', static function (): void {} );
		unset( $_parent_pages['ai-test-tool'] );

		$this->assertSame( '', menu_page_url( 'ai-test-tool', false ) );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'tools.php?page=ai-test-tool' ) ) . '"', $this->render_card() );
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
