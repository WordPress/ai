<?php
/**
 * Ollama text model implementation.
 *
 * @package WordPress\AI\Providers\Ollama
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Ollama;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

use function __;

/**
 * Calls the local Ollama `/api/chat` endpoint.
 *
 * @since 0.1.0
 */
class OllamaTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {
	/**
	 * {@inheritDoc}
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$request = new Request(
			HttpMethodEnum::POST(),
			rtrim( OllamaProvider::get_base_url(), '/api' ) . '/api/chat',
			array( 'Content-Type' => 'application/json' ),
			$this->buildPayload( $prompt )
		);

		$response = $this->getHttpTransporter()->send( $request );
		$this->throwIfNotSuccessful( $response );

		return $this->parseResponse( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function streamGenerateTextResult( array $prompt ): \Generator {
		throw ResponseException::fromInvalidData( 'Ollama', 'stream', 'Streaming not implemented.' );
	}

	/**
	 * Builds the request payload.
	 *
	 * @param list<Message> $prompt Prompt messages.
	 *
	 * @return array<string, mixed>
	 */
	private function buildPayload( array $prompt ): array {
		$config   = $this->getConfig();
		$messages = $this->convertPromptToMessages( $prompt );

		if ( empty( $messages ) ) {
			throw new InvalidArgumentException(
				__( 'Ollama chat requests require at least one user message.', 'ai' )
			);
		}

		$payload = array(
			'model'   => $this->metadata()->getId(),
			'messages'=> $messages,
			'stream'  => false,
		);

		if ( null !== $config->getTemperature() ) {
			$payload['options']['temperature'] = (float) $config->getTemperature();
		}
		if ( null !== $config->getTopP() ) {
			$payload['options']['top_p'] = (float) $config->getTopP();
		}
		if ( null !== $config->getTopK() ) {
			$payload['options']['top_k'] = (float) $config->getTopK();
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			$payload['options'][ $key ] = $value;
		}

		return $payload;
	}

	/**
	 * Converts prompt messages to Ollama format.
	 *
	 * @param list<Message> $prompt Prompt messages.
	 *
	 * @return list<array{role:string,content:string}>
	 */
	private function convertPromptToMessages( array $prompt ): array {
		$messages = array();

		foreach ( $prompt as $message ) {
			$text = $this->extractTextFromMessage( $message );
			if ( '' === $text ) {
				continue;
			}

			$role = $message->getRole()->isModel() ? 'assistant' : 'user';
			$messages[] = array(
				'role'    => $role,
				'content' => $text,
			);
		}

		return $messages;
	}

	/**
	 * Extracts first text part from a message.
	 *
	 * @param Message $message Message instance.
	 *
	 * @return string
	 */
	private function extractTextFromMessage( Message $message ): string {
		foreach ( $message->getParts() as $part ) {
			if ( null !== $part->getText() ) {
				return $part->getText();
			}
		}

		return '';
	}

	/**
	 * Converts Ollama response to a GenerativeAiResult.
	 *
	 * @param Response $response Response instance.
	 *
	 * @return GenerativeAiResult
	 */
	private function parseResponse( Response $response ): GenerativeAiResult {
		$data = $response->getData();
		if ( ! isset( $data['message']['content'] ) || ! is_string( $data['message']['content'] ) ) {
			throw ResponseException::fromMissingData( 'Ollama', 'message.content' );
		}

		$message = new Message(
			MessageRoleEnum::model(),
			array( new MessagePart( $data['message']['content'] ) )
		);

		$candidate = new Candidate( $message, FinishReasonEnum::stop() );

		$prompt_tokens = (int) ( $data['prompt_eval_count'] ?? 0 );
		$output_tokens = (int) ( $data['eval_count'] ?? 0 );

		return new GenerativeAiResult(
			$data['id'] ?? '',
			array( $candidate ),
			new TokenUsage( $prompt_tokens, $output_tokens, $prompt_tokens + $output_tokens ),
			$this->providerMetadata(),
			$this->metadata(),
			$data
		);
	}

	/**
	 * Validates response success.
	 *
	 * @param Response $response Response instance.
	 *
	 * @return void
	 */
	protected function throwIfNotSuccessful( Response $response ): void {
		ResponseUtil::throwIfNotSuccessful( $response );
	}
}
