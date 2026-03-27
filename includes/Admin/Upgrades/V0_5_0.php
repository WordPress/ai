<?php
/**
 * Upgrade routines for version 0.5.0.
 *
 * Originally this file was located in `includes/Migrations/Credential_Migration.php`, which is why the since tags don't match.
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upgrade routine for migrating provider credentials to the new Connectors-based storage format.
 *
 * @since 0.6.0
 * @internal
 */
class V0_5_0 extends Abstract_Upgrade {
	/**
	 * The legacy option that stored all provider credentials as an array.
	 *
	 * @since 0.6.0
	 * @var string
	 */
	private const OLD_OPTION = 'wp_ai_client_provider_credentials';

	/**
	 * The map of provider slugs to their new Connectors option names.
	 */
	private const PROVIDER_MAP = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as a array.
		'openai'    => 'connectors_ai_openai_api_key',
		'google'    => 'connectors_ai_google_api_key',
		'anthropic' => 'connectors_ai_anthropic_api_key',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.6.0
	 */
	public static string $version = '0.5.0';

	/**
	 * {@inheritDoc}
	 *
	 * Copies legacy provider credentials to the new per-provider options.
	 *
	 * Reads the old combined credentials option and, for each known provider,
	 * copies the credential to the new option only when the new option is empty.
	 *
	 * @since 0.6.0
	 */
	protected function upgrade(): void {
		$old_credentials = get_option( self::OLD_OPTION, array() );

		if ( empty( $old_credentials ) || ! is_array( $old_credentials ) ) {
			return;
		}

		foreach ( self::PROVIDER_MAP as $provider => $new_option ) {
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
