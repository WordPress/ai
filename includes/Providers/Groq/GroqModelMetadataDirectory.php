<?php
/**
 * Groq model metadata directory.
 *
 * @package WordPress\AI\Providers\Groq
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Groq;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Messages\Enums\ModalityEnum;
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
 * Lists Groq models and expresses their capabilities for discovery.
 *
 * @since 0.1.0
 */
class GroqModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {
	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			GroqProvider::url( $path ),
			$headers,
			$data
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$response_data = $response->getData() ?? array();
		if ( ! isset( $response_data['data'] ) || ! is_array( $response_data['data'] ) ) {
			throw ResponseException::fromMissingData( 'Groq', 'data' );
		}

		$capabilities    = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);
		$options         = $this->get_text_options();
		$models_metadata = array();

		foreach ( $response_data['data'] as $model_data ) {
			if ( ! is_array( $model_data ) || empty( $model_data['id'] ) ) {
				continue;
			}

			$model_id   = (string) $model_data['id'];
			$model_name = isset( $model_data['name'] ) && is_string( $model_data['name'] )
				? $model_data['name']
				: $model_id;

			$models_metadata[] = new ModelMetadata(
				$model_id,
				$model_name,
				$capabilities,
				$options // @phpstan-ignore argument.type
			);
		}

		return $models_metadata;
	}

	/**
	 * Returns supported options for Groq chat models.
	 *
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption>
	 */
	private function get_text_options(): array {
		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::logprobs() ),
			new SupportedOption( OptionEnum::topLogprobs() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption(
				OptionEnum::inputModalities(),
				array(
					array( ModalityEnum::text() ),
				)
			),
			new SupportedOption(
				OptionEnum::outputModalities(),
				array(
					array( ModalityEnum::text() ),
				)
			),
		);
	}
}
