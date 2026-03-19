<?php
/**
 * Integration tests for V0_6_0.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Upgrades
 */

namespace WordPress\AI\Tests\Integration\Admin\Upgrades;

use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades\V0_6_0;

/**
 * V0_6_0 test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades\V0_6_0
 * @since 0.6.0
 */
class V0_6_0Test extends WP_UnitTestCase {

	/**
	 * Removes options before each test.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'wpai_version' );
		delete_option( 'ai_experiment_enabled' );
		delete_option( 'wpai_feature_enabled' );
		delete_option( 'ai_experiment_excerpt-generation_enabled' );
		delete_option( 'wpai_feature_excerpt-generation_enabled' );
	}

	/**
	 * Cleans up options after each test.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_version' );
		delete_option( 'ai_experiment_enabled' );
		delete_option( 'wpai_feature_enabled' );
		delete_option( 'ai_experiment_excerpt-generation_enabled' );
		delete_option( 'wpai_feature_excerpt-generation_enabled' );

		parent::tearDown();
	}

	/**
	 * Tests that run() migrates the global enabled option.
	 *
	 * @since 0.6.0
	 */
	public function test_run_migrates_global_enabled_option() {
		update_option( 'ai_experiment_enabled', '1' );

		( new V0_6_0( '' ) )->run();

		$this->assertEquals( '1', get_option( 'wpai_feature_enabled' ) );
		$this->assertNull(
			$this->get_option_from_db( 'ai_experiment_enabled' ),
			'Old option should be deleted'
		);
	}

	/**
	 * Tests that run() returns true on success.
	 *
	 * @since 0.6.0
	 */
	public function test_run_returns_success_after_migration() {
		$result = ( new V0_6_0( '' ) )->run();

		$this->assertTrue( $result );
	}

	/**
	 * Tests that run() skips when version is already at target.
	 *
	 * @since 0.6.0
	 */
	public function test_run_skips_when_version_already_current() {
		update_option( 'ai_experiment_enabled', '1' );

		( new V0_6_0( '0.6.0' ) )->run();

		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_enabled' ),
			'Should not write new option when version is current'
		);
		$this->assertEquals(
			'1',
			get_option( 'ai_experiment_enabled' ),
			'Old option should remain when skipped'
		);
	}

	/**
	 * Tests that run() does nothing on fresh install (no old options).
	 *
	 * @since 0.6.0
	 */
	public function test_run_does_nothing_on_fresh_install() {
		( new V0_6_0( '' ) )->run();

		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_enabled' ),
			'wpai_feature_enabled should not be written on fresh install'
		);
	}

	/**
	 * Tests that run() migrates only options where new option is empty.
	 *
	 * @since 0.6.0
	 */
	public function test_run_migrates_only_options_missing_new_value() {
		update_option( 'wpai_feature_enabled', 'already-set' );
		update_option( 'ai_experiment_enabled', '1' );
		update_option( 'ai_experiment_excerpt-generation_enabled', '1' );

		( new V0_6_0( '' ) )->run();

		$this->assertEquals(
			'already-set',
			get_option( 'wpai_feature_enabled' ),
			'Global feature flag should not be overwritten'
		);
		$this->assertEquals(
			'1',
			get_option( 'wpai_feature_excerpt-generation_enabled' ),
			'Excerpt generation should be migrated'
		);
		$this->assertNull(
			$this->get_option_from_db( 'ai_experiment_excerpt-generation_enabled' ),
			'Migrated old option should be deleted'
		);
		$this->assertEquals(
			'1',
			get_option( 'ai_experiment_enabled' ),
			'Non-migrated old option should remain'
		);
	}

	/**
	 * Tests that run() handles empty string old values correctly.
	 *
	 * @since 0.6.0
	 */
	public function test_run_skips_empty_old_values() {
		update_option( 'ai_experiment_enabled', '' );

		( new V0_6_0( '' ) )->run();

		$this->assertNull(
			$this->get_option_from_db( 'wpai_feature_enabled' ),
			'New option should not be set when old is empty string'
		);
	}

	/**
	 * Returns the raw option value directly from the database, bypassing all filters.
	 *
	 * Returns null if the option row does not exist.
	 *
	 * @since 0.6.0
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
