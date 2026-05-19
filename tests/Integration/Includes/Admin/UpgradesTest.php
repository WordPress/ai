<?php
/**
 * Integration tests for Upgrades.
 *
 * @package WordPress\AI\Tests\Integration\Admin
 */

namespace WordPress\AI\Tests\Integration\Admin;

use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades;

/**
 * Upgrades test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades
 * @since 0.6.0
 */
class UpgradesTest extends WP_UnitTestCase {

	/**
	 * Cleans up options before each test.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'wpai_version' );
		delete_option( 'wpai_failed_upgrade_message' );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_enabled' );
		delete_option( 'ai_experiment_excerpt-generation_enabled' );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_enabled' );
		delete_option( 'wpai_feature_excerpt-generation_enabled' );
	}

	/**
	 * Cleans up options after each test.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_version' );
		delete_option( 'wpai_failed_upgrade_message' );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_enabled' );
		delete_option( 'ai_experiment_excerpt-generation_enabled' );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_enabled' );
		delete_option( 'wpai_feature_excerpt-generation_enabled' );

		parent::tearDown();
	}

	/**
	 * Tests that do_upgrades() sets version to WPAI_VERSION on fresh install.
	 *
	 * @since 0.6.0
	 */
	public function test_do_upgrades_sets_version_on_fresh_install() {
		Upgrades::do_upgrades();

		$this->assertEquals( WPAI_VERSION, get_option( 'wpai_version' ) );
	}

	/**
	 * Tests that do_upgrades() skips all upgrades when already at latest version.
	 *
	 * @since 0.6.0
	 */
	public function test_do_upgrades_skips_when_version_is_current() {
		update_option( 'wpai_version', '99.0.0' );
		update_option( 'ai_experiment_excerpt-generation_enabled', '1' );

		Upgrades::do_upgrades();

		$this->assertEquals(
			'1',
			get_option( 'ai_experiment_excerpt-generation_enabled' ),
			'Old option should remain when skipped'
		);
		$this->assertNull(
			get_option( 'wpai_feature_excerpt-generation_enabled', null ),
			'New option should not be set when skipped'
		);
	}

	/**
	 * Tests that do_upgrades() repairs legacy global enabled options before version checks.
	 *
	 * @since 0.6.0
	 */
	public function test_do_upgrades_repairs_legacy_global_enabled_option_when_version_is_current() {
		update_option( 'wpai_version', '99.0.0' );
		update_option( 'ai_experiments_enabled', '1' );

		Upgrades::do_upgrades();

		$this->assertEquals(
			'1',
			get_option( 'wpai_features_enabled' ),
			'Legacy global experiments option should be repaired even when no versioned upgrades run'
		);
		$this->assertNull(
			get_option( 'ai_experiments_enabled', null ),
			'Legacy global experiments option should be deleted after repair'
		);
		$this->assertEquals(
			WPAI_VERSION,
			get_option( 'wpai_version' ),
			'Plugin version should be stored after upgrades complete'
		);
	}

	/**
	 * Tests that do_upgrades() does not overwrite the current global enabled option.
	 *
	 * @since 0.6.0
	 */
	public function test_do_upgrades_does_not_overwrite_current_global_enabled_option() {
		update_option( 'wpai_version', '99.0.0' );
		update_option( 'ai_experiments_enabled', '1' );
		add_option( 'wpai_features_enabled', false );

		Upgrades::do_upgrades();

		$this->assertFalse(
			get_option( 'wpai_features_enabled' ),
			'Current global features option should not be overwritten by stale legacy values'
		);
		$this->assertEquals(
			'1',
			get_option( 'ai_experiments_enabled' ),
			'Legacy option should remain when repair is skipped'
		);
	}

	/**
	 * Tests that do_upgrades() migrates the legacy global experiments option.
	 *
	 * @since 0.6.0
	 */
	public function test_do_upgrades_migrates_legacy_global_experiments_option() {
		update_option( 'ai_experiments_enabled', '1' );

		Upgrades::do_upgrades();

		$this->assertEquals(
			'1',
			get_option( 'wpai_features_enabled' ),
			'Legacy global experiments option should migrate to the current global features option'
		);
		$this->assertNull(
			get_option( 'ai_experiments_enabled', null ),
			'Legacy global experiments option should be deleted after migration'
		);
	}

	/**
	 * Tests that do_upgrades() repairs the singular global feature option.
	 *
	 * @since 0.6.0
	 */
	public function test_do_upgrades_repairs_singular_global_feature_option() {
		update_option( 'wpai_version', '0.9.0' );
		update_option( 'wpai_feature_enabled', '1' );

		Upgrades::do_upgrades();

		$this->assertEquals(
			'1',
			get_option( 'wpai_features_enabled' ),
			'Singular global feature option should migrate to the current global features option'
		);
		$this->assertNull(
			get_option( 'wpai_feature_enabled', null ),
			'Singular global feature option should be deleted after migration'
		);
	}

	/**
	 * Tests that do_upgrades() clears failed upgrade message on success.
	 *
	 * @since 0.6.0
	 */
	public function test_do_upgrades_clears_failed_message_on_success() {
		update_option(
			'wpai_failed_upgrade_message',
			array(
				'version' => '0.5.0',
				'error'   => 'Previous failure',
			)
		);

		Upgrades::do_upgrades();

		$this->assertFalse(
			get_option( 'wpai_failed_upgrade_message', false ),
			'Failed upgrade message should be cleared'
		);
	}

	/**
	 * Tests that init() doesn't throw errors and registers hooks.
	 *
	 * @since 0.6.0
	 */
	public function test_init_registers_admin_init_hook() {
		$upgrades = new Upgrades();
		$upgrades->init();

		$this->assertTrue( has_action( 'admin_init', array( $upgrades, 'do_upgrades' ) ) !== false );
		$this->assertTrue( has_action( 'admin_notices', array( $upgrades, 'failed_upgrade_notice' ) ) !== false );
	}

	/**
	 * Tests that failed_upgrade_notice() outputs nothing when no failure.
	 *
	 * @since 0.6.0
	 */
	public function test_failed_upgrade_notice_outputs_nothing_when_no_failure() {
		$upgrades = new Upgrades();

		ob_start();
		$upgrades->failed_upgrade_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Tests that failed_upgrade_notice() clears invalid failure data.
	 *
	 * @since 0.6.0
	 */
	public function test_failed_upgrade_notice_clears_invalid_data() {
		update_option(
			'wpai_failed_upgrade_message',
			array(
				'version' => '',
				'error'   => '',
			)
		);

		$upgrades = new Upgrades();
		$upgrades->failed_upgrade_notice();

		$this->assertFalse(
			get_option( 'wpai_failed_upgrade_message', false ),
			'Invalid failure data should be cleared'
		);
	}

	/**
	 * Tests that failed_upgrade_notice() outputs error message.
	 *
	 * @since 0.6.0
	 */
	public function test_failed_upgrade_notice_outputs_error_message() {
		update_option(
			'wpai_failed_upgrade_message',
			array(
				'version' => WPAI_VERSION,
				'error'   => 'Test upgrade failure',
			)
		);

		$upgrades = new Upgrades();

		ob_start();
		$upgrades->failed_upgrade_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( WPAI_VERSION, $output );
		$this->assertStringContainsString( 'Test upgrade failure', $output );
	}
}
