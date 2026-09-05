<?php
/**
 * Maps Anthropic Messages API server-sent events onto SDK result chunks.
 *
 * @package WordPress\AI\Experiments\AI_Workspace\Streaming
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\Streaming;

use Generator;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Providers\Http\Streaming\Contracts\EventStreamParserInterface;
use WordPress\AiClient\Providers\Http\Streaming\SseEventStreamParser;
use WordPress\AiClient\Providers\Http\Streaming\ValueObjects\ServerSentEvent;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Results\ValueObjects\CandidateDelta;
use WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk;
use WordPress\AiClient\Results\ValueObjects\ToolCallDelta;
use WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Translates an Anthropic SSE body into `GenerativeAiResultChunk` values.
 *
 * Anthropic's Messages API streams *named* events rather than the anonymous
 * delta objects OpenAI-compatible APIs use, so the mapping is provider
 * specific by nature. It is isolated here — and behind nothing but a PSR-7
 * stream — so a second provider can be added as a sibling class and so every
 * scenario below is testable from a canned body with no network access.
 *
 * The stream is single-candidate: Anthropic returns one message per request,
 * so every delta is attributed to candidate index 0. Content block indices are
 * reused as tool call slot indices, which is safe because a block index is
 * unique within a message.
 *
 * Two failure modes are deliberately loud rather than silent:
 *
 * - An `error` event throws, so a provider outage mid-answer cannot look like
 *   a short but complete reply.
 * - A stream that ends without `message_stop` throws for the same reason: a
 *   dropped connection would otherwise assemble into a plausible truncated
 *   result.
 *
 * @since x.x.x
 */
final class Anthropic_Stream_Mapper {

	/**
	 * Candidate index every delta is attributed to.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const CANDIDATE_INDEX = 0;

	/**
	 * Event stream parser.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AiClient\Providers\Http\Streaming\Contracts\EventStreamParserInterface
	 */
	private EventStreamParserInterface $parser;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\Streaming\Contracts\EventStreamParserInterface|null $parser Optional parser; defaults to the SDK's SSE parser.
	 */
	public function __construct( ?EventStreamParserInterface $parser = null ) {
		$this->parser = null === $parser ? new SseEventStreamParser() : $parser;
	}

	/**
	 * Lazily maps an Anthropic SSE body onto result chunks.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface $stream The response body stream.
	 * @return \Generator<int, \WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk> The chunks, in arrival order.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the stream errors or ends early.
	 */
	public function map( StreamInterface $stream ): Generator {
		$input_tokens = 0;
		$completed    = false;

		/*
		 * Content block indices that opened as thinking blocks. A `signature_delta`
		 * names only the index it belongs to, so the block type has to be remembered
		 * from `content_block_start` to know which deltas the signature applies to.
		 */
		$thinking_blocks = array();

		foreach ( $this->parser->parse( $stream ) as $event ) {
			$name = $event->getEvent();

			if ( 'ping' === $name ) {
				continue;
			}

			if ( 'error' === $name ) {
				throw self::provider_error( $this->decode( $event ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the factory builds the exception and escapes the provider message itself; nothing is output here.
			}

			if ( 'message_stop' === $name ) {
				$completed = true;
				continue;
			}

			$data = $this->decode( $event );

			switch ( $name ) {
				case 'message_start':
					$input_tokens = self::read_int( self::read_array( $data, 'message' ), 'usage', 'input_tokens' );
					$chunk        = $this->map_message_start( $data );
					break;

				case 'content_block_start':
					$chunk = $this->map_content_block_start( $data, $thinking_blocks );
					break;

				case 'content_block_delta':
					$chunk = $this->map_content_block_delta( $data, $thinking_blocks );
					break;

				case 'message_delta':
					$chunk = $this->map_message_delta( $data, $input_tokens );
					break;

				case 'content_block_stop':
				default:
					// Block boundaries and any event a future API version adds carry nothing to fold in.
					$chunk = null;
					break;
			}

			if ( null === $chunk ) {
				continue;
			}

			yield $chunk;
		}

		if ( ! $completed ) {
			throw new Streaming_Exception(
				esc_html__( 'The AI response stream ended before the provider signalled completion.', 'ai' ),
				Streaming_Exception::CODE_TRUNCATED // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
			);
		}
	}

	/**
	 * Maps `message_start` onto a metadata-only chunk.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data Decoded event payload.
	 * @return \WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk The chunk.
	 */
	private function map_message_start( array $data ): GenerativeAiResultChunk {
		$message = self::read_array( $data, 'message' );
		$id      = isset( $message['id'] ) && is_string( $message['id'] ) ? $message['id'] : null;

		/*
		 * Everything the buffered parser would treat as provider metadata, minus the
		 * fields the SDK models directly. Keeping the same exclusions means a streamed
		 * result's additionalData matches a buffered one for the same request.
		 */
		$additional_data = $message;
		unset(
			$additional_data['id'],
			$additional_data['role'],
			$additional_data['content'],
			$additional_data['stop_reason'],
			$additional_data['usage']
		);

		return new GenerativeAiResultChunk( $id, null, $additional_data, array() );
	}

	/**
	 * Maps `content_block_start` onto a chunk, for tool blocks only.
	 *
	 * A text or thinking block opens with no content, so it produces nothing but
	 * the note that the index is a thinking block, which a later `signature_delta`
	 * on that index needs. A `tool_use` block carries the call's id and name, which
	 * arrive here and nowhere else, so it opens the tool call slot.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data            Decoded event payload.
	 * @param array<int, bool>     $thinking_blocks Content block indices opened as thinking blocks, updated in place.
	 * @return \WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk|null The chunk, or null when there is nothing to fold in.
	 */
	private function map_content_block_start( array $data, array &$thinking_blocks ): ?GenerativeAiResultChunk {
		$block = self::read_array( $data, 'content_block' );
		$type  = isset( $block['type'] ) && is_string( $block['type'] ) ? $block['type'] : '';

		if ( 'thinking' === $type ) {
			$thinking_blocks[ self::read_index( $data ) ] = true;

			return null;
		}

		if ( 'tool_use' !== $type ) {
			return null;
		}

		$tool_call = new ToolCallDelta(
			self::read_index( $data ),
			isset( $block['id'] ) && is_string( $block['id'] ) ? $block['id'] : null,
			isset( $block['name'] ) && is_string( $block['name'] ) ? $block['name'] : null,
			''
		);

		return self::candidate_chunk( new CandidateDelta( self::CANDIDATE_INDEX, array(), null, array( $tool_call ) ) );
	}

	/**
	 * Maps `content_block_delta` onto a chunk.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data            Decoded event payload.
	 * @param array<int, bool>     $thinking_blocks Content block indices opened as thinking blocks.
	 * @return \WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk|null The chunk, or null when the delta carries nothing.
	 */
	private function map_content_block_delta( array $data, array $thinking_blocks ): ?GenerativeAiResultChunk {
		$delta = self::read_array( $data, 'delta' );
		$type  = isset( $delta['type'] ) && is_string( $delta['type'] ) ? $delta['type'] : '';

		if ( 'text_delta' === $type ) {
			$text = isset( $delta['text'] ) && is_string( $delta['text'] ) ? $delta['text'] : '';

			if ( '' === $text ) {
				return null;
			}

			return self::candidate_chunk(
				new CandidateDelta( self::CANDIDATE_INDEX, array( new MessagePart( $text ) ) )
			);
		}

		if ( 'thinking_delta' === $type ) {
			$text = isset( $delta['thinking'] ) && is_string( $delta['thinking'] ) ? $delta['thinking'] : '';

			if ( '' === $text ) {
				return null;
			}

			return self::candidate_chunk(
				new CandidateDelta(
					self::CANDIDATE_INDEX,
					array( new MessagePart( $text, MessagePartChannelEnum::thought() ) )
				)
			);
		}

		if ( 'input_json_delta' === $type ) {
			/*
			 * Tool arguments arrive as arbitrary fragments of a JSON document — a split
			 * can fall inside a key, a value, or an escape sequence. Nothing here parses
			 * them; they are handed to ChunkAccumulator, which concatenates every
			 * fragment for a slot and decodes once at the end.
			 */
			$fragment = isset( $delta['partial_json'] ) && is_string( $delta['partial_json'] ) ? $delta['partial_json'] : '';

			if ( '' === $fragment ) {
				return null;
			}

			$tool_call = new ToolCallDelta( self::read_index( $data ), null, null, $fragment );

			return self::candidate_chunk(
				new CandidateDelta( self::CANDIDATE_INDEX, array(), null, array( $tool_call ) )
			);
		}

		if ( 'signature_delta' === $type ) {
			/*
			 * Anthropic requires a thinking block to carry its signature when the block
			 * is replayed as conversation history, and rejects the whole request when it
			 * does not. The signature arrives here, once, after the block's thinking
			 * text, so it is attached to a zero-length thought part: the accumulator
			 * concatenates the text of a channel and keeps the last signature seen for
			 * it, so an empty part adds no text while still carrying the signature onto
			 * the assembled message.
			 */
			$signature = isset( $delta['signature'] ) && is_string( $delta['signature'] ) ? $delta['signature'] : '';

			if ( '' === $signature || ! isset( $thinking_blocks[ self::read_index( $data ) ] ) ) {
				return null;
			}

			return self::candidate_chunk(
				new CandidateDelta(
					self::CANDIDATE_INDEX,
					array( new MessagePart( '', MessagePartChannelEnum::thought(), $signature ) )
				)
			);
		}

		// Anything a later API version adds carries nothing the SDK models.
		return null;
	}

	/**
	 * Maps `message_delta` onto a chunk carrying the finish reason and token usage.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data         Decoded event payload.
	 * @param int                  $input_tokens Prompt tokens reported by `message_start`.
	 * @return \WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk The chunk.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the stop reason is unrecognized.
	 */
	private function map_message_delta( array $data, int $input_tokens ): GenerativeAiResultChunk {
		$delta         = self::read_array( $data, 'delta' );
		$stop_reason   = isset( $delta['stop_reason'] ) && is_string( $delta['stop_reason'] ) ? $delta['stop_reason'] : null;
		$finish_reason = null === $stop_reason ? null : self::finish_reason( $stop_reason );

		$usage         = self::read_array( $data, 'usage' );
		$output_tokens = isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] )
			? (int) $usage['output_tokens']
			: 0;

		$token_usage = new TokenUsage( $input_tokens, $output_tokens, $input_tokens + $output_tokens );

		return new GenerativeAiResultChunk(
			null,
			$token_usage,
			array(),
			array( new CandidateDelta( self::CANDIDATE_INDEX, array(), $finish_reason, array() ) )
		);
	}

	/**
	 * Wraps a single candidate delta in a chunk.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Results\ValueObjects\CandidateDelta $delta The delta.
	 * @return \WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk The chunk.
	 */
	private static function candidate_chunk( CandidateDelta $delta ): GenerativeAiResultChunk {
		return new GenerativeAiResultChunk( null, null, array(), array( $delta ) );
	}

	/**
	 * Decodes an event payload as a JSON object.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\Streaming\ValueObjects\ServerSentEvent $event The event.
	 * @return array<string, mixed> The decoded payload.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the payload is not a JSON object.
	 */
	private function decode( ServerSentEvent $event ): array {
		$decoded = json_decode( $event->getData(), true );

		if ( ! is_array( $decoded ) ) {
			throw new Streaming_Exception(
				sprintf(
					/* translators: %s: Server-sent event name. */
					esc_html__( 'The AI response stream carried an unreadable "%s" event.', 'ai' ),
					esc_html( $event->getEvent() )
				),
				Streaming_Exception::CODE_MALFORMED // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
			);
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * Builds the exception for an `error` event.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data Decoded event payload.
	 * @return \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception The exception.
	 */
	private static function provider_error( array $data ): Streaming_Exception {
		$error   = self::read_array( $data, 'error' );
		$message = isset( $error['message'] ) && is_string( $error['message'] ) && '' !== $error['message']
			? $error['message']
			: __( 'The AI provider reported an error mid-stream.', 'ai' );

		return new Streaming_Exception( esc_html( $message ), Streaming_Exception::CODE_PROVIDER_ERROR );
	}

	/**
	 * Maps an Anthropic stop reason onto the SDK's finish reason.
	 *
	 * The mapping matches the buffered `AnthropicTextGenerationModel`, including
	 * its refusal to guess at an unknown value: a stop reason this plugin does
	 * not recognize is reported rather than flattened to "stop".
	 *
	 * @since x.x.x
	 *
	 * @param string $stop_reason The Anthropic stop reason.
	 * @return \WordPress\AiClient\Results\Enums\FinishReasonEnum The finish reason.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the stop reason is unrecognized.
	 */
	private static function finish_reason( string $stop_reason ): FinishReasonEnum {
		switch ( $stop_reason ) {
			case 'end_turn':
			case 'stop_sequence':
			case 'pause_turn':
				return FinishReasonEnum::stop();

			case 'max_tokens':
			case 'model_context_window_exceeded':
				return FinishReasonEnum::length();

			case 'refusal':
				return FinishReasonEnum::contentFilter();

			case 'tool_use':
				return FinishReasonEnum::toolCalls();
		}

		throw new Streaming_Exception(
			sprintf(
				/* translators: %s: Stop reason reported by the provider. */
				esc_html__( 'The AI provider reported an unknown stop reason "%s".', 'ai' ),
				esc_html( $stop_reason )
			),
			Streaming_Exception::CODE_MALFORMED // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
		);
	}

	/**
	 * Reads the content block index from an event payload.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data Decoded event payload.
	 * @return int The block index, defaulting to 0.
	 */
	private static function read_index( array $data ): int {
		return isset( $data['index'] ) && is_numeric( $data['index'] ) ? (int) $data['index'] : 0;
	}

	/**
	 * Reads a nested array value, returning an empty array when it is absent.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data Decoded event payload.
	 * @param string               $key  Key to read.
	 * @return array<string, mixed> The nested array.
	 */
	private static function read_array( array $data, string $key ): array {
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return array();
		}

		/** @var array<string, mixed> $value */
		$value = $data[ $key ];

		return $value;
	}

	/**
	 * Reads a two-level integer value, defaulting to 0.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data  Decoded payload.
	 * @param string               $outer Outer key.
	 * @param string               $inner Inner key.
	 * @return int The value, or 0.
	 */
	private static function read_int( array $data, string $outer, string $inner ): int {
		$nested = self::read_array( $data, $outer );

		return isset( $nested[ $inner ] ) && is_numeric( $nested[ $inner ] ) ? (int) $nested[ $inner ] : 0;
	}
}
