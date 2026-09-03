<?php
/**
 * Anthropic text generation model that can stream its result.
 *
 * @package WordPress\AI\Experiments\AI_Workspace\Streaming
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\Streaming;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\StreamingTextGenerationModelInterface;
use WordPress\AiClient\Results\StreamedGenerativeAiResult;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds `streamGenerateTextResult()` to the Anthropic provider's text model.
 *
 * The `ai-provider-for-anthropic` plugin ships a buffered model only. Its class
 * is extendable (its `generateTextResult()` is `final`, but the class is not),
 * so this subclass inherits the whole request-building path — message mapping,
 * tool declarations, model config — and adds the streaming variant beside it.
 * Nothing in the provider plugin is modified.
 *
 * Two things differ from the buffered request: the body sets `stream: true`,
 * and the transport options carry `setStream(true)` so a streaming-capable
 * transporter knows to hand back a lazy body rather than a buffered string.
 * Against a transporter that ignores the flag the request still succeeds — the
 * body is simply read from a buffered stream, so the mapping is unchanged.
 *
 * Because the parent class lives in a plugin this one does not depend on, never
 * reference this class without checking `is_available()` first: autoloading it
 * where the provider plugin is absent is a fatal error.
 *
 * @since x.x.x
 */
class Anthropic_Streaming_Text_Generation_Model extends AnthropicTextGenerationModel implements StreamingTextGenerationModelInterface {

	/**
	 * Maps the provider's server-sent events onto SDK chunks.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Stream_Mapper|null
	 */
	private ?Anthropic_Stream_Mapper $mapper = null;

	/**
	 * Reports whether this class can be loaded in the current environment.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the Anthropic provider plugin supplies the parent class.
	 */
	public static function is_available(): bool {
		return class_exists( AnthropicTextGenerationModel::class )
			&& interface_exists( StreamingTextGenerationModelInterface::class );
	}

	/**
	 * Sets the event mapper, replacing the default.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Stream_Mapper $mapper The mapper.
	 */
	public function set_stream_mapper( Anthropic_Stream_Mapper $mapper ): void {
		$this->mapper = $mapper;
	}

	/**
	 * Streams generated text from a prompt.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt The prompt messages.
	 * @return \WordPress\AiClient\Results\StreamedGenerativeAiResult The streamed result.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the provider rejects the request.
	 */
	public function streamGenerateTextResult( array $prompt ): StreamedGenerativeAiResult { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface method.
		$params           = $this->prepareGenerateTextParams( $prompt );
		$params['stream'] = true;

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'text/event-stream',
		);

		$config = $this->getConfig();
		if ( 'application/json' === $config->getOutputMimeType() && $config->getOutputSchema() ) {
			$headers['anthropic-beta'] = 'structured-outputs-2025-11-13';
		}

		$options = $this->stream_request_options();

		$request = new Request(
			HttpMethodEnum::POST(),
			AnthropicProvider::url( 'messages' ),
			$headers,
			$params,
			$options
		);

		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request, $options );

		$this->assert_successful( $response );

		$mapper = null === $this->mapper ? new Anthropic_Stream_Mapper() : $this->mapper;

		return new StreamedGenerativeAiResult(
			$mapper->map( $response->getStream() ),
			$this->providerMetadata(),
			$this->metadata()
		);
	}

	/**
	 * Builds the transport options for a streaming request.
	 *
	 * The model's configured options are cloned before the stream flag is set so
	 * a streaming call cannot leave the flag behind on options that a later
	 * buffered call would reuse.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AiClient\Providers\Http\DTO\RequestOptions The options.
	 */
	private function stream_request_options(): RequestOptions {
		$configured = $this->getRequestOptions();
		$options    = null === $configured ? new RequestOptions() : clone $configured;

		$options->setStream( true );

		return $options;
	}

	/**
	 * Throws when the provider did not accept the streaming request.
	 *
	 * The check runs before a single chunk is mapped: an error response is
	 * ordinary JSON, not an event stream, and reading it as one would surface
	 * as a confusing parse failure instead of the provider's own message.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The response.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the response is not successful.
	 */
	private function assert_successful( Response $response ): void {
		if ( $response->isSuccessful() ) {
			return;
		}

		$data    = $response->getData();
		$error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$message = isset( $error['message'] ) && is_string( $error['message'] ) && '' !== $error['message']
			? $error['message']
			: __( 'The AI provider rejected the streaming request.', 'ai' );

		throw new Streaming_Exception(
			sprintf(
				/* translators: 1: HTTP status code. 2: Provider error message. */
				esc_html__( 'The streaming request failed with status %1$d: %2$s', 'ai' ),
				$response->getStatusCode(),
				esc_html( $message )
			),
			Streaming_Exception::CODE_HTTP_STATUS
		);
	}
}
