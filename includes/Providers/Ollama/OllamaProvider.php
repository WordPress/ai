<?php
/**
 * Ollama provider definition.
 *
 * @package WordPress\AI\Providers\Ollama
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Ollama;

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

/**
 * Registers local Ollama models with the WP AI Client registry.
 *
 * @since 0.1.0
 */
class OllamaProvider extends AbstractApiProvider {
	/**
	 * {@inheritDoc}
	 */
	public static function get_base_url(): string {
		/**
		 * Filters the Ollama base URL.
		 *
		 * @since 0.1.0
		 *
		 * @param string $base_url Default base URL.
		 */
		return apply_filters( 'ai_ollama_base_url', 'http://localhost:11434/api' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function baseUrl(): string {
		return static::get_base_url();
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new OllamaTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		throw new RuntimeException(
			'Unsupported Ollama model capabilities: ' . implode(
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
			'ollama',
			'Ollama',
			ProviderTypeEnum::client()
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
		return new OllamaModelMetadataDirectory();
	}
}
