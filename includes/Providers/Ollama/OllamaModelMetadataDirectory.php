<?php
/**
 * Ollama model metadata directory.
 *
 * @package WordPress\AI\Providers\Ollama
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Ollama;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Lists locally installed Ollama models via `/api/tags`.
 *
 * @since 0.1.0
 */
class OllamaModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {
	/**
	 * {@inheritDoc}
	 */
	protected function sendListModelsRequest(): array {
		$request = new Request(
			HttpMethodEnum::GET(),
			preg_replace( '#/api$#', '', OllamaProvider::get_base_url() ) . '/api/tags'
		);

		$response = $this->getHttpTransporter()->send( $request );
		$this->throwIfNotSuccessful( $response ); // @phpstan-ignore method.notFound

		return $this->parseResponse( $response );
	}

	/**
	 * Parses Ollama tags response.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response Ollama response.
	 *
	 * @return array<string, \WordPress\AiClient\Providers\Models\DTO\ModelMetadata>
	 */
	private function parseResponse( Response $response ): array {
		$data = $response->getData() ?? array();
		if ( ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
			throw ResponseException::fromMissingData( 'Ollama', 'models' );
		}

		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);

		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);

		$map = array();
		foreach ( $data['models'] as $model ) {
			if ( ! isset( $model['name'] ) ) {
				continue;
			}

			$id   = (string) $model['name'];
			$name = isset( $model['details']['family'] ) ? $model['details']['family'] . ' (' . $id . ')' : $id;

			$map[ $id ] = new ModelMetadata(
				$id,
				$name,
				$capabilities,
				$options
			);
		}

		return $map;
	}
}
