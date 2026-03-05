<?php
/**
 * Groq text model implementation.
 *
 * @package WordPress\AI\Providers\Groq
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Groq;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Provides access to Groq chat-completions models.
 *
 * @since 0.1.0
 */
class GroqTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
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
}
