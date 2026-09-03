<?php
/**
 * Integration tests for the Anthropic SSE to chunk mapper.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Stream_Mapper;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Results\StreamedGenerativeAiResult;
use WordPress\AiClientDependencies\Nyholm\Psr7\Stream;

/**
 * Anthropic_Stream_Mapper test case.
 *
 * Every scenario runs against a canned Anthropic Messages API SSE body, so no
 * network access is involved.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Stream_Mapper
 */
class Anthropic_Stream_MapperTest extends WP_UnitTestCase {

	/**
	 * Builds an SSE body from a list of [event, data] pairs.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array{0: string, 1: mixed}> $events Event name and payload pairs.
	 * @return string The encoded SSE body.
	 */
	private function sse( array $events ): string {
		$body = '';

		foreach ( $events as $event ) {
			$data  = is_string( $event[1] ) ? $event[1] : (string) wp_json_encode( $event[1] );
			$body .= 'event: ' . $event[0] . "\n";
			$body .= 'data: ' . $data . "\n\n";
		}

		return $body;
	}

	/**
	 * Wraps an SSE body in a streamed result built by the mapper.
	 *
	 * @since x.x.x
	 *
	 * @param string $body The SSE body.
	 * @return \WordPress\AiClient\Results\StreamedGenerativeAiResult The streamed result.
	 */
	private function stream_result( string $body ): StreamedGenerativeAiResult {
		$mapper = new Anthropic_Stream_Mapper();

		return new StreamedGenerativeAiResult(
			$mapper->map( Stream::create( $body ) ),
			new ProviderMetadata( 'anthropic', 'Anthropic', ProviderTypeEnum::cloud() ),
			new ModelMetadata(
				'claude-test',
				'Claude Test',
				array( CapabilityEnum::textGeneration() ),
				array()
			)
		);
	}

	/**
	 * A text-only stream assembles into a result whose text is the concatenated deltas.
	 *
	 * @since x.x.x
	 */
	public function test_text_only_stream_concatenates_deltas(): void {
		$body = $this->sse(
			array(
				array(
					'message_start',
					array(
						'type'    => 'message_start',
						'message' => array(
							'id'    => 'msg_01',
							'type'  => 'message',
							'role'  => 'assistant',
							'model' => 'claude-test',
							'usage' => array( 'input_tokens' => 11 ),
						),
					),
				),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array(
							'type' => 'text',
							'text' => '',
						),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Hello, ',
						),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'world!',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array(
					'message_delta',
					array(
						'delta' => array( 'stop_reason' => 'end_turn' ),
						'usage' => array( 'output_tokens' => 5 ),
					),
				),
				array( 'message_stop', array( 'type' => 'message_stop' ) ),
			)
		);

		$result = $this->stream_result( $body )->getFinalResult();

		$this->assertSame( 'Hello, world!', $result->toText() );
		$this->assertSame( 'msg_01', $result->getId() );
	}

	/**
	 * Chunks are yielded incrementally, one per text delta.
	 *
	 * @since x.x.x
	 */
	public function test_yields_a_chunk_per_text_delta(): void {
		$body = $this->sse(
			array(
				array(
					'message_start',
					array( 'message' => array( 'id' => 'msg_02' ) ),
				),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'a',
						),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'b',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array( 'message_stop', array() ),
			)
		);

		$texts = array();
		foreach ( $this->stream_result( $body ) as $chunk ) {
			$text = $chunk->getDeltaText();
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		$this->assertSame( array( 'a', 'b' ), $texts );
	}

	/**
	 * Tool arguments split across `input_json_delta` fragments are concatenated and parsed.
	 *
	 * @since x.x.x
	 */
	public function test_tool_use_json_fragments_are_concatenated_and_parsed(): void {
		$body = $this->sse(
			array(
				array(
					'message_start',
					array( 'message' => array( 'id' => 'msg_03' ) ),
				),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array(
							'type' => 'tool_use',
							'id'   => 'toolu_01',
							'name' => 'search_content',
						),
					),
				),
				// Fragments deliberately split mid-token ("quer" + "y").
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type'         => 'input_json_delta',
							'partial_json' => '{"quer',
						),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type'         => 'input_json_delta',
							'partial_json' => 'y": "hel',
						),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type'         => 'input_json_delta',
							'partial_json' => 'lo", "limit": 3}',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array(
					'message_delta',
					array(
						'delta' => array( 'stop_reason' => 'tool_use' ),
						'usage' => array( 'output_tokens' => 9 ),
					),
				),
				array( 'message_stop', array() ),
			)
		);

		$result     = $this->stream_result( $body )->getFinalResult();
		$candidates = $result->getCandidates();
		$parts      = $candidates[0]->getMessage()->getParts();

		$function_call = null;
		foreach ( $parts as $part ) {
			if ( null !== $part->getFunctionCall() ) {
				$function_call = $part->getFunctionCall();
			}
		}

		$this->assertNotNull( $function_call );
		$this->assertSame( 'toolu_01', $function_call->getId() );
		$this->assertSame( 'search_content', $function_call->getName() );
		$this->assertSame(
			array(
				'query' => 'hello',
				'limit' => 3,
			),
			$function_call->getArgs()
		);
		$this->assertTrue( $candidates[0]->getFinishReason()->isToolCalls() );
	}

	/**
	 * The `message_delta` stop reason and token usage reach the final result.
	 *
	 * @since x.x.x
	 */
	public function test_stop_reason_and_usage_reach_final_result(): void {
		$body = $this->sse(
			array(
				array(
					'message_start',
					array(
						'message' => array(
							'id'    => 'msg_04',
							'usage' => array( 'input_tokens' => 40 ),
						),
					),
				),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Truncated',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array(
					'message_delta',
					array(
						'delta' => array( 'stop_reason' => 'max_tokens' ),
						'usage' => array( 'output_tokens' => 2 ),
					),
				),
				array( 'message_stop', array() ),
			)
		);

		$result = $this->stream_result( $body )->getFinalResult();

		$this->assertSame( 40, $result->getTokenUsage()->getPromptTokens() );
		$this->assertSame( 2, $result->getTokenUsage()->getCompletionTokens() );
		$this->assertSame( 42, $result->getTokenUsage()->getTotalTokens() );

		$candidates = $result->getCandidates();
		$this->assertTrue( $candidates[0]->getFinishReason()->isLength() );
	}

	/**
	 * `thinking_delta` events land on the reasoning channel.
	 *
	 * @since x.x.x
	 */
	public function test_thinking_delta_maps_to_reasoning_channel(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_05' ) ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'thinking' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type'     => 'thinking_delta',
							'thinking' => 'Let me think.',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array(
					'content_block_start',
					array(
						'index'         => 1,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 1,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Answer.',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 1 ) ),
				array(
					'message_delta',
					array( 'delta' => array( 'stop_reason' => 'end_turn' ) ),
				),
				array( 'message_stop', array() ),
			)
		);

		$reasoning = '';
		$streamed  = $this->stream_result( $body );

		foreach ( $streamed as $chunk ) {
			$reasoning .= $chunk->getReasoningDeltaText();
		}

		$this->assertSame( 'Let me think.', $reasoning );
		$this->assertSame( 'Answer.', $streamed->getFinalResult()->toText() );
	}

	/**
	 * `ping` events are ignored and produce no chunks.
	 *
	 * @since x.x.x
	 */
	public function test_ping_events_are_ignored(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_06' ) ) ),
				array( 'ping', array( 'type' => 'ping' ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array( 'ping', array( 'type' => 'ping' ) ),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Hi',
						),
					),
				),
				array( 'ping', array( 'type' => 'ping' ) ),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array( 'message_delta', array( 'delta' => array( 'stop_reason' => 'end_turn' ) ) ),
				array( 'message_stop', array() ),
			)
		);

		$chunks = 0;
		foreach ( $this->stream_result( $body ) as $chunk ) {
			unset( $chunk );
			++$chunks;
		}

		// message_start, the text delta, and message_delta: pings add nothing.
		$this->assertSame( 3, $chunks );
	}

	/**
	 * An `error` event surfaces as an exception rather than a silent end of stream.
	 *
	 * @since x.x.x
	 */
	public function test_error_event_surfaces_as_an_exception(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_07' ) ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Partial',
						),
					),
				),
				array(
					'error',
					array(
						'type'  => 'error',
						'error' => array(
							'type'    => 'overloaded_error',
							'message' => 'Overloaded',
						),
					),
				),
			)
		);

		$this->expectException( Streaming_Exception::class );
		$this->expectExceptionMessage( 'Overloaded' );

		$this->stream_result( $body )->getFinalResult();
	}

	/**
	 * An `error` event reaches registered onError callbacks.
	 *
	 * @since x.x.x
	 */
	public function test_error_event_reaches_the_error_callback(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_08' ) ) ),
				array(
					'error',
					array(
						'error' => array(
							'type'    => 'api_error',
							'message' => 'Boom',
						),
					),
				),
			)
		);

		$seen = null;

		$streamed = $this->stream_result( $body );
		$streamed->onError(
			static function ( $error ) use ( &$seen ): void {
				$seen = $error;
			}
		);

		try {
			$streamed->getFinalResult();
		} catch ( Streaming_Exception $e ) {
			unset( $e );
		}

		$this->assertInstanceOf( Streaming_Exception::class, $seen );
	}

	/**
	 * A stream truncated mid-block fails rather than assembling a plausible result.
	 *
	 * @since x.x.x
	 */
	public function test_truncated_stream_does_not_produce_a_silently_valid_result(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_09' ) ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Half a sen',
						),
					),
				),
			)
		);

		$this->expectException( Streaming_Exception::class );

		$this->stream_result( $body )->getFinalResult();
	}

	/**
	 * A stream cut off before the final blank line is also treated as truncated.
	 *
	 * @since x.x.x
	 */
	public function test_stream_cut_mid_event_is_treated_as_truncated(): void {
		$body  = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_10' ) ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
			)
		);
		$body .= "event: content_block_delta\ndata: {\"index\":0,\"delta\":{\"type\":\"text";

		$this->expectException( Streaming_Exception::class );

		$this->stream_result( $body )->getFinalResult();
	}

	/**
	 * An unparseable event payload fails loudly.
	 *
	 * @since x.x.x
	 */
	public function test_malformed_event_payload_throws(): void {
		$body = "event: message_start\ndata: {not json}\n\n";

		$this->expectException( Streaming_Exception::class );

		$this->stream_result( $body )->getFinalResult();
	}

	/**
	 * Two tool calls in one stream keep their own argument buffers.
	 *
	 * @since x.x.x
	 */
	public function test_multiple_tool_calls_keep_separate_argument_buffers(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_11' ) ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array(
							'type' => 'tool_use',
							'id'   => 'toolu_a',
							'name' => 'alpha',
						),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type'         => 'input_json_delta',
							'partial_json' => '{"a":',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array(
					'content_block_start',
					array(
						'index'         => 1,
						'content_block' => array(
							'type' => 'tool_use',
							'id'   => 'toolu_b',
							'name' => 'beta',
						),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 1,
						'delta' => array(
							'type'         => 'input_json_delta',
							'partial_json' => '{"b":2}',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 1 ) ),
				// The first block's arguments are completed out of order on purpose.
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type'         => 'input_json_delta',
							'partial_json' => '1}',
						),
					),
				),
				array( 'message_delta', array( 'delta' => array( 'stop_reason' => 'tool_use' ) ) ),
				array( 'message_stop', array() ),
			)
		);

		$result = $this->stream_result( $body )->getFinalResult();
		$calls  = array();

		foreach ( $result->getCandidates()[0]->getMessage()->getParts() as $part ) {
			$call = $part->getFunctionCall();
			if ( null !== $call ) {
				$calls[ (string) $call->getName() ] = $call->getArgs();
			}
		}

		$this->assertSame(
			array(
				'alpha' => array( 'a' => 1 ),
				'beta'  => array( 'b' => 2 ),
			),
			$calls
		);
	}

	/**
	 * An unknown stop reason is rejected rather than silently mapped to "stop".
	 *
	 * @since x.x.x
	 */
	public function test_unknown_stop_reason_is_rejected(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_12' ) ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Hi',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array( 'message_delta', array( 'delta' => array( 'stop_reason' => 'brand_new_reason' ) ) ),
				array( 'message_stop', array() ),
			)
		);

		$this->expectException( Streaming_Exception::class );

		$this->stream_result( $body )->getFinalResult();
	}

	/**
	 * A `message_delta` without a stop reason leaves the default finish reason in place.
	 *
	 * @since x.x.x
	 */
	public function test_missing_stop_reason_falls_back_to_the_default(): void {
		$body = $this->sse(
			array(
				array( 'message_start', array( 'message' => array( 'id' => 'msg_13' ) ) ),
				array(
					'content_block_start',
					array(
						'index'         => 0,
						'content_block' => array( 'type' => 'text' ),
					),
				),
				array(
					'content_block_delta',
					array(
						'index' => 0,
						'delta' => array(
							'type' => 'text_delta',
							'text' => 'Hi',
						),
					),
				),
				array( 'content_block_stop', array( 'index' => 0 ) ),
				array(
					'message_delta',
					array(
						'delta' => array( 'stop_reason' => null ),
						'usage' => array( 'output_tokens' => 1 ),
					),
				),
				array( 'message_stop', array() ),
			)
		);

		$result = $this->stream_result( $body )->getFinalResult();

		$this->assertTrue(
			$result->getCandidates()[0]->getFinishReason()->is( FinishReasonEnum::stop() )
		);
	}
}
