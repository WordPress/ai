<?php
/**
 * Cohere chat model implementation.
 *
 * @package WordPress\AI\Providers\Cohere
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Cohere;

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
use function array_values;

/**
 * Provides direct access to Cohere's `/chat` endpoint.
 *
 * @since 0.1.0
 */
class CohereTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {
	/**
	 * {@inheritDoc}
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$payload = $this->buildPayload( $prompt );

		$request = new Request(
			HttpMethodEnum::POST(),
			CohereProvider::url( 'chat' ),
			array( 'Content-Type' => 'application/json' ),
			$payload
		);

		$request        = $this->getRequestAuthentication()->authenticateRequest( $request );
		$http_transport = $this->getHttpTransporter();
		$response       = $http_transport->send( $request );
		$this->throwIfNotSuccessful( $response );

		return $this->parseResponseToResult( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function streamGenerateTextResult( array $prompt ): \Generator {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are for developers.
		throw ResponseException::fromInvalidData(
			$this->providerMetadata()->getName(),
			'stream',
			__( 'Streaming is not yet implemented for the Cohere provider.', 'ai' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Builds the Cohere `/chat` payload.
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt Prompt messages.
	 *
	 * @return array<string, mixed>
	 */
	private function buildPayload( array $prompt ): array {
		$config      = $this->getConfig();
		$messages    = $this->convertPromptToMessages( $prompt );
		$system_text = $config->getSystemInstruction();

		if ( empty( $messages ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are for developers.
			throw new InvalidArgumentException(
				__( 'Cohere chat requests require at least one user message.', 'ai' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$current_message = $this->extractLatestUserMessage( $messages );
		$chat_history    = $this->convertMessagesToChatHistory( $messages );

		$payload = array(
			'model'   => $this->metadata()->getId(),
			'message' => $current_message,
		);

		if ( $system_text ) {
			$payload['preamble'] = $system_text;
		}

		if ( ! empty( $chat_history ) ) {
			$payload['chat_history'] = $chat_history;
		}

		if ( null !== $config->getCandidateCount() ) {
			$payload['response_count'] = (int) $config->getCandidateCount();
		}
		if ( null !== $config->getMaxTokens() ) {
			$payload['max_tokens'] = (int) $config->getMaxTokens();
		}
		if ( null !== $config->getTemperature() ) {
			$payload['temperature'] = (float) $config->getTemperature();
		}
		if ( null !== $config->getTopP() ) {
			$payload['top_p'] = (float) $config->getTopP();
		}
		if ( null !== $config->getTopK() ) {
			$payload['top_k'] = (int) $config->getTopK();
		}
		if ( $config->getStopSequences() ) {
			$payload['stop_sequences'] = $config->getStopSequences();
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			if ( isset( $payload[ $key ] ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are for developers.
				throw new InvalidArgumentException(
					sprintf(
						/* translators: %s: custom option key. */
						__( 'The custom option "%s" conflicts with an existing Cohere parameter.', 'ai' ),
						$key
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$payload[ $key ] = $value;
		}

		return $payload;
	}

	/**
	 * Converts the WP AI Client prompt into Cohere's messages array.
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt Prompt messages.
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
	 * Extracts the first text fragment from a message.
	 *
	 * @param \WordPress\AiClient\Messages\DTO\Message $message Prompt message.
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
	 * Converts Cohere API responses to standard results.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response Cohere response.
	 *
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult
	 */
	private function parseResponseToResult( Response $response ): GenerativeAiResult {
		$data = $response->getData();

		$text_candidates = $this->extractTextCandidates( $data );
		if ( empty( $text_candidates ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are for developers.
			throw ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'text' );
		}

		$candidates = array_map(
			static function ( string $text ): Candidate {
				$message = new Message(
					MessageRoleEnum::model(),
					array( new MessagePart( $text ) )
				);
				return new Candidate( $message, FinishReasonEnum::stop() );
			},
			$text_candidates
		);

		$usage         = $data['meta']['billed_units'] ?? array();
		$input_tokens  = (int) ( $usage['input_tokens'] ?? 0 );
		$output_tokens = (int) ( $usage['output_tokens'] ?? 0 );
		$token_usage   = new TokenUsage(
			$input_tokens,
			$output_tokens,
			$input_tokens + $output_tokens
		);

		$additional = $data;
		unset( $additional['text'], $additional['response'], $additional['generations'] );

		return new GenerativeAiResult(
			$data['generation_id'] ?? ( $data['id'] ?? '' ),
			$candidates,
			$token_usage,
			$this->providerMetadata(),
			$this->metadata(),
			$additional
		);
	}

	/**
	 * Normalizes Cohere text containers into strings.
	 *
	 * @param array<string, mixed> $data Cohere response data.
	 *
	 * @return list<string>
	 */
	private function extractTextCandidates( array $data ): array {
		$candidates = array();

		if ( isset( $data['message'] ) && is_array( $data['message'] ) ) {
			$content = $data['message']['content'] ?? array();
			if ( is_array( $content ) ) {
				foreach ( $content as $block ) {
					if ( ! isset( $block['text'] ) || ! is_string( $block['text'] ) ) {
						continue;
					}

					$candidates[] = $block['text'];
				}
			}
		}

		if ( isset( $data['text'] ) && is_string( $data['text'] ) ) {
			$candidates[] = $data['text'];
		}

		if ( isset( $data['response'] ) && is_array( $data['response'] ) ) {
			foreach ( $data['response'] as $entry ) {
				if ( ! isset( $entry['message'] ) || ! is_string( $entry['message'] ) ) {
					continue;
				}

				$candidates[] = $entry['message'];
			}
		}

		if ( isset( $data['generations'] ) && is_array( $data['generations'] ) ) {
			foreach ( $data['generations'] as $generation ) {
				if ( ! isset( $generation['text'] ) || ! is_string( $generation['text'] ) ) {
					continue;
				}

				$candidates[] = $generation['text'];
			}
		}

		return $candidates;
	}

	/**
	 * Ensures Cohere returned a successful response.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response Cohere response.
	 *
	 * @return void
	 */
	protected function throwIfNotSuccessful( Response $response ): void {
		ResponseUtil::throwIfNotSuccessful( $response );
	}

	/**
	 * Extracts the most recent user utterance for Cohere's `message` field.
	 *
	 * @param array<int, array{role:string,content:string}> $messages Normalized message list.
	 *
	 * @return string
	 */
	private function extractLatestUserMessage( array &$messages ): string {
		for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
			if ( 'user' !== $messages[ $index ]['role'] ) {
				continue;
			}

			$content = $messages[ $index ]['content'];
			unset( $messages[ $index ] );

			return $content;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are for developers.
		throw new InvalidArgumentException(
			__( 'Cohere chat requests require at least one user message.', 'ai' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Converts remaining messages into Cohere `chat_history` entries.
	 *
	 * @param array<int, array{role:string,content:string}> $messages Normalized message list.
	 *
	 * @return array<int, array{role:string,message:string}>
	 */
	private function convertMessagesToChatHistory( array $messages ): array {
		$history = array();

		foreach ( array_values( $messages ) as $message ) {
			if ( 'system' === $message['role'] ) {
				continue;
			}

			$role = 'user' === $message['role'] ? 'USER' : 'CHATBOT';

			$history[] = array(
				'role'    => $role,
				'message' => $message['content'],
			);
		}

		return $history;
	}
}
