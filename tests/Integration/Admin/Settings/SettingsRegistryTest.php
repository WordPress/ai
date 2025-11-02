<?php
/**
 * Tests for the Settings_Registry class.
 *
 * @package WordPress\AI\Tests\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;
use WP_UnitTestCase;

/**
 * Settings_Registry test case.
 *
 * @since 0.1.0
 *
 * @covers \WordPress\AI\Admin\Settings\Settings_Registry
 * @covers \WordPress\AI\Admin\Settings\Settings_Section
 */
class Settings_Registry_Test extends WP_UnitTestCase {
	/**
	 * Registry instance under test.
	 *
	 * @var Settings_Registry
	 */
	private $registry;

	/**
	 * Sets up the test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->registry = new Settings_Registry();
	}

	/**
	 * Returns a dummy section for testing.
	 *
	 * @param string $id       Section identifier.
	 * @param int    $priority Display priority.
	 * @return Settings_Section
	 */
	private function create_section( string $id, int $priority = 10 ): Settings_Section {
		return new Settings_Section(
			$id,
			'Section ' . $id,
			'Description for ' . $id,
			static function (): void {
				// No-op render callback.
			},
			$priority
		);
	}

	/**
	 * Tests that sections are registered and retrieved.
	 */
	public function test_register_and_get_section(): void {
		$section = $this->create_section( 'example' );

		$this->assertTrue( $this->registry->register_section( $section ) );
		$this->assertTrue( $this->registry->has_section( 'example' ) );
		$this->assertSame( $section, $this->registry->get_section( 'example' ) );
	}

	/**
	 * Tests that duplicate identifiers are rejected.
	 */
	public function test_register_section_rejects_duplicates(): void {
		$this->registry->register_section( $this->create_section( 'dup' ) );

		$this->assertFalse( $this->registry->register_section( $this->create_section( 'dup' ) ) );
	}

	/**
	 * Tests that sections are sorted by priority, then identifier.
	 */
	public function test_get_sections_sorts_by_priority_then_id(): void {
		$this->registry->register_section( $this->create_section( 'b', 10 ) );
		$this->registry->register_section( $this->create_section( 'a', 10 ) );
		$this->registry->register_section( $this->create_section( 'first', 5 ) );

		$sections = $this->registry->get_sections();
		$keys     = array_keys( $sections );

		$this->assertSame( array( 'first', 'a', 'b' ), $keys );
	}
}
