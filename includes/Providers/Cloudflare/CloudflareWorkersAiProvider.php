<?php
/**
 * Cloudflare Workers AI provider definition.
 *
 * @package WordPress\AI\Providers\Cloudflare
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Cloudflare;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

use function apply_filters;
use function getenv;
use function get_option;
use function is_string;

/**
 * Registers Workers AI models with the WP AI Client registry.
 *
 * @since 0.1.0
 */
class CloudflareWorkersAiProvider extends AbstractApiProvider {
	/**
	 * {@inheritDoc}
	 */
	protected static function baseUrl(): string {
		return sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai',
			self::get_account_id()
		);
	}

	/**
	 * Retrieves the Cloudflare Account ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_account_id(): string {
		$account_id = getenv( 'CLOUDFLARE_ACCOUNT_ID' );

		if ( ! $account_id && defined( 'CLOUDFLARE_ACCOUNT_ID' ) ) {
			$account_id = CLOUDFLARE_ACCOUNT_ID;
		}

		if ( ! $account_id ) {
			$account_id = get_option( 'ai_cloudflare_account_id', '' );
		}

		/**
		 * Filters the Cloudflare Account ID used by the Workers AI provider.
		 *
		 * @since 0.1.0
		 *
		 * @param string|false $account_id Account identifier detected from environment or constant.
		 */
		$account_id = apply_filters( 'ai_cloudflare_account_id', $account_id );

		if ( ! $account_id || ! is_string( $account_id ) ) {
			throw new RuntimeException(
				'Cloudflare Workers AI requires a Cloudflare account ID. Set the CLOUDFLARE_ACCOUNT_ID environment variable or use the ai_cloudflare_account_id filter.'
			);
		}

		return trim( $account_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new CloudflareWorkersAiTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		throw new RuntimeException(
			'Unsupported Cloudflare Workers AI model capabilities: ' . implode(
				', ',
				array_map(
					static function ( $capability ) {
						return $capability->value;
					},
					$model_metadata->getSupportedCapabilities()
				)
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			'cloudflare',
			'Cloudflare Workers AI',
			ProviderTypeEnum::cloud()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new CloudflareWorkersAiModelMetadataDirectory();
	}
}
