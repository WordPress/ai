<?php
/**
 * Fal.ai provider definition.
 *
 * @package WordPress\AI\Providers\FalAi
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\FalAi;

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
 * Registers Fal.ai image models with the WP AI Client registry.
 *
 * @since 0.1.0
 */
class FalAiProvider extends AbstractApiProvider {
	/**
	 * {@inheritDoc}
	 */
	protected static function baseUrl(): string {
		return 'https://fal.run';
	}

	/**
	 * Builds a fully-qualified queue endpoint URL for the supplied path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_path Model identifier path (e.g. fal-ai/flux/dev).
	 *
	 * @return string
	 */
	public static function queueUrl( string $model_path ): string {
		return 'https://queue.fal.run/' . ltrim( $model_path, '/' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isImageGeneration() ) {
				return new FalAiImageGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		throw new RuntimeException(
			'Unsupported Fal.ai model capabilities: ' . implode(
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
			'fal',
			'Fal.ai',
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
		return new FalAiModelMetadataDirectory();
	}
}
