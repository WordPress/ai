<?php
/**
 * Integration tests for Credential_Migration.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Migrations
 */

namespace WordPress\AI\Tests\Integration\Includes\Migrations;

use WP_UnitTestCase;
use WordPress\AI\Migrations\Credential_Migration;

/**
 * Credential_Migration test case.
 *
 * @since 0.5.0
 */
class Credential_MigrationTest extends WP_UnitTestCase {

	/**
	 * Returns the new-style Connectors option names under test.
	 *
	 * @since 0.5.0
	 *
	 * @return list<string>
	 */
	private static function get_connector_options(): array {
		return array(
			'connectors_ai_openai_api_key',
			'connectors_ai_google_api_key',
			'connectors_ai_anthropic_api_key',
		);
	}

	/**
	 * Removes the WordPress Connectors sanitize and mask filters before each test.
	 *
	 * WordPress 7.0 registers a sanitize_callback for these options that validates
	 * keys against a live provider registry (unavailable in tests), and an option_*
	 * filter that masks values on read. Both are removed here so the tests can
	 * write and read raw values directly.
	 *
	 * @since 0.5.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure each test starts from a clean migration state regardless of
		// bootstrap side effects that may run migrations before tests execute.
		delete_option( 'wp_ai_client_provider_credentials' );
		delete_option( 'ai_experiments_version' );

		foreach ( self::get_connector_options() as $option ) {
			delete_option( $option );
			remove_all_filters( 'sanitize_option_' . $option );
			remove_filter( 'option_' . $option, '_wp_connectors_mask_api_key' );
		}
	}

	/**
	 * Deletes all options written during a test and restores the mask filters.
	 *
	 * @since 0.5.0
	 */
	public function tearDown(): void {
		delete_option( 'wp_ai_client_provider_credentials' );
		delete_option( 'ai_experiments_version' );

		foreach ( self::get_connector_options() as $option ) {
			delete_option( $option );
			add_filter( 'option_' . $option, '_wp_connectors_mask_api_key' );
		}

		parent::tearDown();
	}

	/**
	 * Tests that run() migrates all provider credentials to the new options.
	 *
	 * @since 0.5.0
	 */
	public function test_run_migrates_credentials() {
		update_option(
			'wp_ai_client_provider_credentials',
			array(
				'openai'    => 'sk-openai-key',
				'google'    => 'google-key',
				'anthropic' => 'anthropic-key',
			)
		);

		( new Credential_Migration() )->run();

		$this->assertEquals( 'sk-openai-key', get_option( 'connectors_ai_openai_api_key' ) );
		$this->assertEquals( 'google-key', get_option( 'connectors_ai_google_api_key' ) );
		$this->assertEquals( 'anthropic-key', get_option( 'connectors_ai_anthropic_api_key' ) );
	}

	/**
	 * Tests that run() stores the current plugin version after migrating.
	 *
	 * @since 0.5.0
	 */
	public function test_run_stores_version_after_migration() {
		( new Credential_Migration() )->run();

		$this->assertEquals( Credential_Migration::TARGET_VERSION, get_option( 'ai_experiments_version' ) );
	}

	/**
	 * Tests that run() is a no-op when the stored version is already at the target.
	 *
	 * @since 0.5.0
	 */
	public function test_run_skips_when_version_already_current() {
		update_option( 'ai_experiments_version', Credential_Migration::TARGET_VERSION );
		update_option(
			'wp_ai_client_provider_credentials',
			array( 'openai' => 'sk-old-key' )
		);

		( new Credential_Migration() )->run();

		$this->assertNull(
			$this->get_option_from_db( 'connectors_ai_openai_api_key' ),
			'Should not write new option when version is current'
		);
	}

	/**
	 * Tests that run() writes no new options when no old credentials exist (fresh install).
	 *
	 * @since 0.5.0
	 */
	public function test_run_does_nothing_on_fresh_install() {
		( new Credential_Migration() )->run();

		foreach ( self::get_connector_options() as $option ) {
			$this->assertNull(
				$this->get_option_from_db( $option ),
				"$option should not be written on fresh install"
			);
		}
	}

	/**
	 * Tests that run() does not overwrite an already-set new-style credential.
	 *
	 * @since 0.5.0
	 */
	public function test_run_does_not_overwrite_existing_new_credentials() {
		update_option( 'connectors_ai_openai_api_key', 'sk-already-set' );
		update_option(
			'wp_ai_client_provider_credentials',
			array( 'openai' => 'sk-old-key' )
		);

		( new Credential_Migration() )->run();

		$this->assertEquals(
			'sk-already-set',
			get_option( 'connectors_ai_openai_api_key' ),
			'Existing new credential should not be overwritten'
		);
	}

	/**
	 * Tests that run() only migrates providers whose new credential option is empty.
	 *
	 * @since 0.5.0
	 */
	public function test_run_migrates_only_providers_missing_new_credentials() {
		update_option( 'connectors_ai_openai_api_key', 'sk-already-set' );
		update_option(
			'wp_ai_client_provider_credentials',
			array(
				'openai'    => 'sk-old-key',
				'anthropic' => 'anthropic-old-key',
			)
		);

		( new Credential_Migration() )->run();

		$this->assertEquals(
			'sk-already-set',
			get_option( 'connectors_ai_openai_api_key' ),
			'OpenAI credential should not be overwritten'
		);
		$this->assertEquals(
			'anthropic-old-key',
			get_option( 'connectors_ai_anthropic_api_key' ),
			'Anthropic credential should be migrated'
		);
	}

	/**
	 * Tests that a second call to run() after migration is already complete is a no-op.
	 *
	 * @since 0.5.0
	 */
	public function test_run_is_idempotent() {
		update_option(
			'wp_ai_client_provider_credentials',
			array( 'openai' => 'sk-openai-key' )
		);

		$migration = new Credential_Migration();
		$migration->run();

		// Simulate the old option being changed after migration has already run.
		update_option(
			'wp_ai_client_provider_credentials',
			array( 'openai' => 'sk-different-key' )
		);

		$migration->run();

		$this->assertEquals(
			'sk-openai-key',
			get_option( 'connectors_ai_openai_api_key' ),
			'Second run should not re-migrate'
		);
	}

	/**
	 * Returns the raw option value directly from the database, bypassing all filters.
	 *
	 * Returns null if the option row does not exist.
	 *
	 * @since 0.5.0
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
