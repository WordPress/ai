<?php
/**
 * Hugging Face text model implementation.
 *
 * @package WordPress\AI\Providers\HuggingFace
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\HuggingFace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Provides access to Hugging Face router chat models.
 *
 * @since 0.1.0
 */
class HuggingFaceTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			HuggingFaceProvider::url( $path ),
			$headers,
			$data
		);
	}
}
