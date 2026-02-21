<?php
/**
 * Client Loader file for the AI Experiments plugin.
 *
 * Handles loading the WP AI Client if needed.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI_Client\AI_Client;

/**
 * Client Loader class.
 *
 * @since x.x.x
 */
final class Client_Loader {

	/**
	 * Whether the WP AI Client has been initialized.
	 *
	 * @since x.x.x
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Checks if the WP AI Client is loaded.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the WP AI Client is loaded, false otherwise.
	 */
	public static function client_exists(): bool {
		return function_exists( '\wp_ai_client_prompt' );
	}

	/**
	 * Initializes the WP AI Client if needed.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		if ( $this->initialized || self::client_exists() ) {
			return;
		}

		AI_Client::init();

		// Ensure the compat function is loaded.
		require_once AI_EXPERIMENTS_PLUGIN_DIR . 'includes/compat.php';

		$this->initialized = true;
	}
}
