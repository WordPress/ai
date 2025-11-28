<?php
/**
 * Cloudflare Workers AI model metadata directory.
 *
 * @package WordPress\AI\Providers\Cloudflare
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Cloudflare;

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
 * Lists Workers AI models via Cloudflare's REST API.
 *
 * @since 0.1.0
 */
class CloudflareWorkersAiModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {
	/**
	 * {@inheritDoc}
	 */
	protected function sendListModelsRequest(): array {
		$request = new Request(
			HttpMethodEnum::GET(),
			CloudflareWorkersAiProvider::url( 'models' )
		);
		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		$response = $this->getHttpTransporter()->send( $request );
		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parseResponse( $response );
	}

	/**
	 * Parses the Cloudflare response into model metadata.
	 *
	 * @param Response $response HTTP response.
	 *
	 * @return array<string, ModelMetadata>
	 */
	private function parseResponse( Response $response ): array {
		$data = $response->getData();
		if ( ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
			throw ResponseException::fromMissingData( 'Cloudflare Workers AI', 'result' );
		}

		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);

		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);

		$map = array();

		foreach ( $data['result'] as $model ) {
			if ( ! isset( $model['id'] ) ) {
				continue;
			}

			$model_id = (string) $model['id'];
			$map[ $model_id ] = new ModelMetadata(
				$model_id,
				$model['name'] ?? $model_id,
				$capabilities,
				$options
			);
		}

		return $map;
	}
}
