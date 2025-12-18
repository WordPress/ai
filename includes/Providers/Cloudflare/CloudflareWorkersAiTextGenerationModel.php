<?php
/**
 * Cloudflare Workers AI text model implementation.
 *
 * @package WordPress\AI\Providers\Cloudflare
 */

declare( strict_types=1 );

namespace WordPress\AI\Providers\Cloudflare;

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
 * Calls Cloudflare's `/ai/run/{model}` endpoint for chat generation.
 *
 * @since 0.1.0
 */
class CloudflareWorkersAiTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {
	/**
	 * {@inheritDoc}
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$request = new Request(
			HttpMethodEnum::POST(),
			CloudflareWorkersAiProvider::url( 'run/' . $this->metadata()->getId() ),
			array( 'Content-Type' => 'application/json' ),
			$this->buildPayload( $prompt )
		);
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );
		$this->throwIfNotSuccessful( $response );

		return $this->parseResponse( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function streamGenerateTextResult( array $prompt ): \Generator {
		throw ResponseException::fromInvalidData( 'Cloudflare Workers AI', 'stream', 'Streaming is not implemented.' );
	}

	/**
	 * Builds the Cloudflare payload.
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
				__( 'Cloudflare Workers AI chat requests require at least one user message.', 'ai' )
			);
		}

		$payload = array(
			'messages' => $messages,
			'stream'   => false,
		);

		if ( null !== $config->getSystemInstruction() ) {
			array_unshift(
				$payload['messages'],
				array(
					'role'    => 'system',
					'content' => $config->getSystemInstruction(),
				)
			);
		}

		if ( null !== $config->getTemperature() ) {
			$payload['temperature'] = (float) $config->getTemperature();
		}
		if ( null !== $config->getTopP() ) {
			$payload['top_p'] = (float) $config->getTopP();
		}
		if ( null !== $config->getMaxTokens() ) {
			$payload['max_output_tokens'] = (int) $config->getMaxTokens();
		}
		if ( $config->getStopSequences() ) {
			$payload['stop_sequences'] = $config->getStopSequences();
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			if ( isset( $payload[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf(
						/* translators: %s: custom option key. */
						__( 'The custom option "%s" conflicts with an existing Cloudflare Workers AI parameter.', 'ai' ),
						$key
					)
				);
			}

			$payload[ $key ] = $value;
		}

		return $payload;
	}

	/**
	 * Converts the WP AI Client prompt into Cloudflare message objects.
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
	 * Extracts text from a message.
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
	 * Parses the Workers AI response to a WP AI result.
	 *
	 * @param Response $response HTTP response.
	 *
	 * @return GenerativeAiResult
	 */
	private function parseResponse( Response $response ): GenerativeAiResult {
		$data = $response->getData();
		if ( ! isset( $data['result']['response'] ) || ! is_string( $data['result']['response'] ) ) {
			throw ResponseException::fromMissingData( 'Cloudflare Workers AI', 'result.response' );
		}

		$message = new Message(
			MessageRoleEnum::model(),
			array( new MessagePart( $data['result']['response'] ) )
		);

		$candidate = new Candidate( $message, FinishReasonEnum::stop() );
		$prompt_tokens = (int) ( $data['result']['input_tokens'] ?? 0 );
		$output_tokens = (int) ( $data['result']['output_tokens'] ?? 0 );

		return new GenerativeAiResult(
			$data['result']['id'] ?? '',
			array( $candidate ),
			new TokenUsage( $prompt_tokens, $output_tokens, $prompt_tokens + $output_tokens ),
			$this->providerMetadata(),
			$this->metadata(),
			$data
		);
	}

	/**
	 * Ensures Workers AI returned a successful response.
	 *
	 * @param Response $response HTTP response.
	 *
	 * @return void
	 */
	protected function throwIfNotSuccessful( Response $response ): void {
		ResponseUtil::throwIfNotSuccessful( $response );
	}
}
