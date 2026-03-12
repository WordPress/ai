<?php
/**
 * Credential migration.
 *
 * Migrates provider API keys from the legacy `wp_ai_client_provider_credentials`
 * option to the per-provider options used by the WordPress Connectors system.
 *
 * @package WordPress\AI\Migrations
 */

declare( strict_types=1 );

namespace WordPress\AI\Migrations;

/**
 * Handles credential migration from the legacy storage format to the
 * WordPress Connectors-based storage format.
 *
 * @since 0.5.0
 */
class Credential_Migration {

	/**
	 * The legacy option that stored all provider credentials as an array.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const OLD_OPTION = 'wp_ai_client_provider_credentials';

	/**
	 * The option that tracks the last-run migration version.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const VERSION_OPTION = 'ai_experiments_version';

	/**
	 * The plugin version at which this migration must run.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const TARGET_VERSION = '0.5.0';

	/**
	 * Map of legacy provider array keys to their new Connectors option names.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, string>
	 */
	private static function get_provider_map(): array {
		return array(
			'openai'    => 'connectors_ai_openai_api_key',
			'google'    => 'connectors_ai_google_api_key',
			'anthropic' => 'connectors_ai_anthropic_api_key',
		);
	}

	/**
	 * Runs the migration if the stored version is below the target version.
	 *
	 * Compares the stored version option against the target version and, when
	 * an upgrade is detected, migrates credentials then records the new version.
	 *
	 * @since 0.5.0
	 */
	public function run(): void {
		$stored_version = get_option( self::VERSION_OPTION, '0.0.0' );

		if ( version_compare( (string) $stored_version, self::TARGET_VERSION, '>=' ) ) {
			return;
		}

		$this->maybe_migrate_credentials();
		update_option( self::VERSION_OPTION, AI_EXPERIMENTS_VERSION );
	}

	/**
	 * Copies legacy provider credentials to the new per-provider options.
	 *
	 * Reads the old combined credentials option and, for each known provider,
	 * copies the credential to the new option only when the new option is empty.
	 *
	 * @since 0.5.0
	 */
	private function maybe_migrate_credentials(): void {
		$old_credentials = get_option( self::OLD_OPTION, array() );

		if ( empty( $old_credentials ) || ! is_array( $old_credentials ) ) {
			return;
		}

		foreach ( self::get_provider_map() as $provider => $new_option ) {
			if ( empty( $old_credentials[ $provider ] ) ) {
				continue;
			}

			// Only migrate if the new option slot is empty.
			if ( '' !== get_option( $new_option, '' ) ) {
				continue;
			}

			update_option( $new_option, $old_credentials[ $provider ] );
		}

		delete_option( self::OLD_OPTION );
	}
}
