<?php
/**
 * Cohere provider definition.
 *
 * @package WordPress\AI\Providers\Cohere
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Cohere;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Registers Cohere chat models with the WP AI Client registry.
 *
 * @since 0.1.0
 */
class CohereProvider extends AbstractApiProvider {
	/**
	 * {@inheritDoc}
	 */
	protected static function baseUrl(): string {
		return 'https://api.cohere.ai/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new CohereTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		throw new RuntimeException(
			'Unsupported Cohere model capabilities: ' . implode(
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
			'cohere',
			'Cohere',
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
		return new CohereModelMetadataDirectory();
	}
}
