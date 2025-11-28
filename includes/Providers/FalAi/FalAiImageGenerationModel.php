<?php
/**
 * Fal.ai image model implementation.
 *
 * @package WordPress\AI\Providers\FalAi
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\FalAi;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

use function __;

/**
 * Handles synchronous Fal.ai `fal.run` executions for image generation models.
 *
 * @since 0.1.0
 */
class FalAiImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface {
	/**
	 * {@inheritDoc}
	 */
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		$http_transporter = $this->getHttpTransporter();
		$request          = $this->createRequest(
			HttpMethodEnum::POST(),
			$this->metadata()->getId(),
			array( 'Content-Type' => 'application/json' ),
			$this->buildPayload( $prompt )
		);

		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $http_transporter->send( $request );
		$this->throwIfNotSuccessful( $response );

		return $this->parseResponseToResult( $response );
	}

	/**
	 * Builds the HTTP request for the synchronous `fal.run` endpoint.
	 *
	 * @param HttpMethodEnum $method HTTP method.
	 * @param string         $model_path Model identifier.
	 * @param array<string, string|list<string>> $headers Headers.
	 * @param array<string, mixed>|null $data Payload.
	 *
	 * @return Request
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $model_path,
		array $headers = array(),
		?array $data = null
	): Request {
		return new Request(
			$method,
			FalAiProvider::url( $model_path ),
			$headers,
			$data
		);
	}

	/**
	 * Builds the Fal.ai payload from the prompt.
	 *
	 * @param list<Message> $prompt Prompt messages.
	 *
	 * @return array<string, mixed>
	 */
	private function buildPayload( array $prompt ): array {
		return array(
			'input' => array(
				'prompt' => $this->preparePromptText( $prompt ),
			),
		);
	}

	/**
	 * Converts Fal.ai responses to a GenerativeAiResult.
	 *
	 * @param Response $response Fal.ai response.
	 *
	 * @return GenerativeAiResult
	 */
	private function parseResponseToResult( Response $response ): GenerativeAiResult {
		$response_data = $response->getData();
		if ( ! isset( $response_data['images'] ) || ! is_array( $response_data['images'] ) ) {
			throw ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'images' );
		}

		$candidates = array();
		foreach ( $response_data['images'] as $index => $image_data ) {
			if ( ! is_array( $image_data ) || empty( $image_data['url'] ) ) {
				throw ResponseException::fromInvalidData(
					$this->providerMetadata()->getName(),
					"images[{$index}]",
					'Each image must include a URL.'
				);
			}

			$mime_type = isset( $image_data['content_type'] ) && is_string( $image_data['content_type'] )
				? $image_data['content_type']
				: 'image/png';

			$file = new File( (string) $image_data['url'], $mime_type );
			$message = new Message(
				MessageRoleEnum::model(),
				array( new MessagePart( $file ) )
			);
			$candidates[] = new Candidate( $message, FinishReasonEnum::stop() );
		}

		$additional = $response_data;
		unset( $additional['images'] );

		return new GenerativeAiResult(
			$additional['request_id'] ?? '',
			$candidates,
			new TokenUsage( 0, 0, 0 ),
			$this->providerMetadata(),
			$this->metadata(),
			$additional
		);
	}

	/**
	 * Normalizes the prompt into a single user string.
	 *
	 * @param list<Message> $messages Prompt messages.
	 *
	 * @return string
	 */
	private function preparePromptText( array $messages ): string {
		if ( count( $messages ) !== 1 ) {
			throw new InvalidArgumentException(
				__( 'Fal.ai models require a single user prompt.', 'ai' )
			);
		}

		$message = $messages[0];
		if ( ! $message->getRole()->isUser() ) {
			throw new InvalidArgumentException(
				__( 'Fal.ai image prompts must originate from the user role.', 'ai' )
			);
		}

		foreach ( $message->getParts() as $part ) {
			$text = $part->getText();
			if ( is_string( $text ) && '' !== trim( $text ) ) {
				return $text;
			}
		}

		throw new InvalidArgumentException(
			__( 'Fal.ai image prompts must include text content.', 'ai' )
		);
	}

	/**
	 * Throws an exception if the response indicates failure.
	 *
	 * @param Response $response Fal.ai response.
	 *
	 * @return void
	 */
	protected function throwIfNotSuccessful( Response $response ): void {
		ResponseUtil::throwIfNotSuccessful( $response );
	}
}
