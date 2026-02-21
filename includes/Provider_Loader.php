<?php
/**
 * Provider Loader file for the AI Experiments plugin.
 *
 * Handles loading the AI providers if needed.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Client_Loader;
use WordPress\AI_Client\HTTP\WP_AI_Client_Discovery_Strategy;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;
use WordPress\GoogleAiProvider\Provider\GoogleProvider;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

/**
 * Provider Loader class.
 *
 * @since x.x.x
 */
final class Provider_Loader {

	/**
	 * Default AI providers.
	 *
	 * @since x.x.x
	 * @var array<class-string>
	 */
	private array $default_providers = array(
		AnthropicProvider::class,
		GoogleProvider::class,
		OpenAiProvider::class,
	);

	/**
	 * Whether the AI providers have been initialized.
	 *
	 * @since x.x.x
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Initializes the AI providers if needed.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		if ( $this->initialized || Client_Loader::client_exists() ) {
			return;
		}

		// Ensure the HTTP transporter is initialized.
		WP_AI_Client_Discovery_Strategy::init();

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();

		foreach ( $this->default_providers as $provider ) {
			if ( $registry->hasProvider( $provider ) ) {
				continue;
			}

			$registry->registerProvider( $provider );
		}

		$this->initialized = true;
	}
}
