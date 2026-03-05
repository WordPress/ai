<?php
/**
 * OpenRouter model metadata directory.
 *
 * @package WordPress\AI\Providers\OpenRouter
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\OpenRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Discovers OpenRouter models using its OpenAI-compatible `/models` endpoint.
 *
 * @since 0.1.0
 */
class OpenRouterModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {
	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			OpenRouterProvider::url( $path ),
			$headers,
			$data
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$data = $response->getData() ?? array();
		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			throw ResponseException::fromMissingData( 'OpenRouter', 'data' );
		}

		$options      = $this->getTextOptions();
		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);

		$models = array();
		foreach ( $data['data'] as $model ) {
			if ( ! is_array( $model ) || empty( $model['id'] ) ) {
				continue;
			}

			$models[] = new ModelMetadata(
				$model['id'],
				$model['name'] ?? $model['id'],
				$capabilities,
				$options // @phpstan-ignore argument.type
			);
		}

		return $models;
	}

	/**
	 * Returns supported options for OpenRouter chat models.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption>
	 */
	private function getTextOptions(): array {
		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);
	}
}
