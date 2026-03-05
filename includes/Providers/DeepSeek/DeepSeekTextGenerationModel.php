<?php
/**
 * DeepSeek text model implementation.
 *
 * @package WordPress\AI\Providers\DeepSeek
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\DeepSeek;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Provides access to DeepSeek chat-completions models.
 *
 * @since 0.1.0
 */
class DeepSeekTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			DeepSeekProvider::url( $path ),
			$headers,
			$data
		);
	}
}
