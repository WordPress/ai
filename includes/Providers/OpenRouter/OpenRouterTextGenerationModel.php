<?php
/**
 * OpenRouter text model implementation.
 *
 * @package WordPress\AI\Providers\OpenRouter
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\OpenRouter;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Provides access to OpenRouter chat completions.
 *
 * @since 0.1.0
 */
class OpenRouterTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
			),
			$headers
		);

		return new Request(
			$method,
			OpenRouterProvider::url( $path ),
			$headers,
			$data
		);
	}
}
