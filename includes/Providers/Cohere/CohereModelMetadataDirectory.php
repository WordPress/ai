<?php
/**
 * Cohere model metadata directory.
 *
 * @package WordPress\AI\Providers\Cohere
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Cohere;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Discovers Cohere chat-capable models.
 *
 * @since 0.1.0
 */
class CohereModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {
	/**
	 * {@inheritDoc}
	 */
	protected function sendListModelsRequest(): array {
		$request  = new Request( HttpMethodEnum::GET(), CohereProvider::url( 'models' ) );
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );
		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parseResponseToModelMetadataMap( $response );
	}

	/**
	 * Parses Cohere's `/models` response.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response Cohere response.
	 *
	 * @return array<string, \WordPress\AiClient\Providers\Models\DTO\ModelMetadata>
	 */
	private function parseResponseToModelMetadataMap( Response $response ): array {
		$data = $response->getData();
		if ( ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
			throw ResponseException::fromMissingData( 'Cohere', 'models' );
		}

		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);
		$options      = $this->getTextOptions();

		$metadata = array();
		foreach ( $data['models'] as $model ) {
			if ( ! is_array( $model ) || empty( $model['name'] ) ) {
				continue;
			}

			$endpoints = $model['endpoints'] ?? array();
			if ( ! is_array( $endpoints ) || ! in_array( 'chat', $endpoints, true ) ) {
				continue;
			}

			$model_id   = (string) $model['name'];
			$model_name = isset( $model['display_name'] ) && is_string( $model['display_name'] )
				? $model['display_name']
				: $model_id;

			$metadata[ $model_id ] = new ModelMetadata(
				$model_id,
				$model_name,
				$capabilities,
				$options
			);
		}

		return $metadata;
	}

	/**
	 * Returns baseline Cohere chat options.
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
			new SupportedOption( OptionEnum::topK() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);
	}
}
