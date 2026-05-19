<?php
/**
 * Integration tests for V1_0_0.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Upgrades
 */

namespace WordPress\AI\Tests\Integration\Admin\Upgrades;

use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades\V1_0_0;

/**
 * V1_0_0 test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades\V1_0_0
 * @since x.x.x
 */
class V1_0_0Test extends WP_UnitTestCase {

	/**
	 * Removes options before each test.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'wpai_version' );
		delete_option( 'wpai_feature_review-notes_enabled' );
		delete_option( 'wpai_feature_editorial-notes_enabled' );
		delete_option( 'wpai_feature_refine-notes_enabled' );
		delete_option( 'wpai_feature_editorial-updates_enabled' );
	}

	/**
	 * Cleans up options after each test.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		delete_option( 'wpai_version' );
		delete_option( 'wpai_feature_review-notes_enabled' );
		delete_option( 'wpai_feature_editorial-notes_enabled' );
		delete_option( 'wpai_feature_refine-notes_enabled' );
		delete_option( 'wpai_feature_editorial-updates_enabled' );

		parent::tearDown();
	}

	/**
	 * Tests that run() migrates review-notes option to editorial-notes.
	 *
	 * @since x.x.x
	 */
	public function test_run_migrates_review_notes_to_editorial_notes() {
		update_option( 'wpai_feature_review-notes_enabled', '1' );

		( new V1_0_0( '' ) )->run();

		$this->assertEquals( '1', get_option( 'wpai_feature_editorial-notes_enabled' ) );
		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_review-notes_enabled' ),
			'Old review-notes option should be deleted after migration'
		);
	}

	/**
	 * Tests that run() migrates refine-notes option to editorial-updates.
	 *
	 * @since x.x.x
	 */
	public function test_run_migrates_refine_notes_to_editorial_updates() {
		update_option( 'wpai_feature_refine-notes_enabled', '1' );

		( new V1_0_0( '' ) )->run();

		$this->assertEquals( '1', get_option( 'wpai_feature_editorial-updates_enabled' ) );
		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_refine-notes_enabled' ),
			'Old refine-notes option should be deleted after migration'
		);
	}

	/**
	 * Tests that run() migrates both options in a single pass.
	 *
	 * @since x.x.x
	 */
	public function test_run_migrates_both_options() {
		update_option( 'wpai_feature_review-notes_enabled', '1' );
		update_option( 'wpai_feature_refine-notes_enabled', '1' );

		( new V1_0_0( '' ) )->run();

		$this->assertEquals( '1', get_option( 'wpai_feature_editorial-notes_enabled' ) );
		$this->assertEquals( '1', get_option( 'wpai_feature_editorial-updates_enabled' ) );
		$this->assertNull( $this->get_option_from_db( 'wpai_feature_review-notes_enabled' ) );
		$this->assertNull( $this->get_option_from_db( 'wpai_feature_refine-notes_enabled' ) );
	}

	/**
	 * Tests that run() returns true on success.
	 *
	 * @since x.x.x
	 */
	public function test_run_returns_success_after_migration() {
		$result = ( new V1_0_0( '' ) )->run();

		$this->assertTrue( $result );
	}

	/**
	 * Tests that run() skips when version is already at target.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_when_version_already_current() {
		update_option( 'wpai_feature_review-notes_enabled', '1' );
		update_option( 'wpai_feature_refine-notes_enabled', '1' );

		( new V1_0_0( '1.0.0' ) )->run();

		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_editorial-notes_enabled' ),
			'Should not migrate when version is already current'
		);
		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_editorial-updates_enabled' ),
			'Should not migrate when version is already current'
		);
	}

	/**
	 * Tests that run() does nothing on fresh install with no old options.
	 *
	 * @since x.x.x
	 */
	public function test_run_does_nothing_on_fresh_install() {
		( new V1_0_0( '' ) )->run();

		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_editorial-notes_enabled' ),
			'editorial-notes option should not be written on fresh install'
		);
		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_editorial-updates_enabled' ),
			'editorial-updates option should not be written on fresh install'
		);
	}

	/**
	 * Tests that run() skips migration when new option already has a value.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_when_new_option_already_set() {
		update_option( 'wpai_feature_review-notes_enabled', '1' );
		update_option( 'wpai_feature_editorial-notes_enabled', 'already-set' );

		( new V1_0_0( '' ) )->run();

		$this->assertEquals(
			'already-set',
			get_option( 'wpai_feature_editorial-notes_enabled' ),
			'New option should not be overwritten'
		);
		$this->assertEquals(
			'1',
			get_option( 'wpai_feature_review-notes_enabled' ),
			'Old option should remain when migration is skipped'
		);
	}

	/**
	 * Tests that run() skips migration when old option is an empty string.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_empty_old_values() {
		update_option( 'wpai_feature_review-notes_enabled', '' );

		( new V1_0_0( '' ) )->run();

		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_editorial-notes_enabled' ),
			'New option should not be set when old option is empty string'
		);
	}

	/**
	 * Returns the raw option value directly from the database, bypassing all filters.
	 *
	 * Returns null if the option row does not exist.
	 *
	 * @since x.x.x
	 *
	 * @param string $option_name The option name to look up.
	 * @return string|null The raw value, or null if the row is absent.
	 */
	private function get_option_from_db( string $option_name ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			)
		);
	}
}
