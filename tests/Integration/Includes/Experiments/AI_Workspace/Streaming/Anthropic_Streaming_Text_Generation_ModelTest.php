<?php
/**
 * Integration tests for the Anthropic streaming text generation model.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Streaming_Text_Generation_Model;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\StreamingTextGenerationModelInterface;
use WordPress\AiClient\Results\StreamedGenerativeAiResult;
use WordPress\AiClientDependencies\Nyholm\Psr7\Stream;

/**
 * Anthropic_Streaming_Text_Generation_Model test case.
 *
 * The model extends a class shipped by the third-party `ai-provider-for-anthropic`
 * plugin, which is not a dependency of the test bootstrap. Its autoloader is
 * required on demand, and the whole case skips when the plugin is absent.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Streaming_Text_Generation_Model
 */
class Anthropic_Streaming_Text_Generation_ModelTest extends WP_UnitTestCase {

	/**
	 * Recording transporter used by each test.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming\Canned_Stream_Transporter
	 */
	private Canned_Stream_Transporter $transporter;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$autoload = WP_PLUGIN_DIR . '/ai-provider-for-anthropic/src/autoload.php';

		if ( ! file_exists( $autoload ) ) {
			$this->markTestSkipped( 'The ai-provider-for-anthropic plugin is not installed.' );
		}

		require_once $autoload;

		$this->transporter = new Canned_Stream_Transporter();
	}

	/**
	 * Builds a model wired to the recording transporter.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Streaming_Text_Generation_Model The model.
	 */
	private function model(): Anthropic_Streaming_Text_Generation_Model {
		$model = new Anthropic_Streaming_Text_Generation_Model(
			new ModelMetadata(
				'claude-test',
				'Claude Test',
				array( CapabilityEnum::textGeneration() ),
				array()
			),
			new ProviderMetadata( 'anthropic', 'Anthropic', ProviderTypeEnum::cloud() )
		);

		$model->setHttpTransporter( $this->transporter );
		$model->setRequestAuthentication( new ApiKeyRequestAuthentication( 'test-key-1234567890' ) );

		return $model;
	}

	/**
	 * A canned Anthropic SSE body containing a two-delta text response.
	 *
	 * @since x.x.x
	 *
	 * @return string The SSE body.
	 */
	private function sse_body(): string {
		return "event: message_start\n"
			. 'data: ' . wp_json_encode(
				array(
					'message' => array(
						'id'    => 'msg_stream',
						'model' => 'claude-test',
						'usage' => array( 'input_tokens' => 3 ),
					),
				)
			) . "\n\n"
			. "event: content_block_start\n"
			. 'data: ' . wp_json_encode(
				array(
					'index'         => 0,
					'content_block' => array( 'type' => 'text' ),
				)
			) . "\n\n"
			. "event: content_block_delta\n"
			. 'data: ' . wp_json_encode(
				array(
					'index' => 0,
					'delta' => array(
						'type' => 'text_delta',
						'text' => 'Streamed ',
					),
				)
			) . "\n\n"
			. "event: content_block_delta\n"
			. 'data: ' . wp_json_encode(
				array(
					'index' => 0,
					'delta' => array(
						'type' => 'text_delta',
						'text' => 'reply.',
					),
				)
			) . "\n\n"
			. "event: content_block_stop\n"
			. 'data: {"index":0}' . "\n\n"
			. "event: message_delta\n"
			. 'data: ' . wp_json_encode(
				array(
					'delta' => array( 'stop_reason' => 'end_turn' ),
					'usage' => array( 'output_tokens' => 4 ),
				)
			) . "\n\n"
			. "event: message_stop\n"
			. 'data: {}' . "\n\n";
	}

	/**
	 * The model declares the SDK's streaming contract.
	 *
	 * @since x.x.x
	 */
	public function test_implements_the_streaming_contract(): void {
		$this->assertInstanceOf( StreamingTextGenerationModelInterface::class, $this->model() );
	}

	/**
	 * The outgoing request asks Anthropic for a stream.
	 *
	 * @since x.x.x
	 */
	public function test_request_asks_for_a_stream(): void {
		$this->transporter->stream_body = $this->sse_body();

		$this->model()->streamGenerateTextResult( array( $this->prompt() ) );

		$request = $this->transporter->last_request;

		$this->assertInstanceOf( Request::class, $request );

		$body = json_decode( (string) $request->getBody(), true );

		$this->assertIsArray( $body );
		$this->assertTrue( $body['stream'] );
		$this->assertSame( 'claude-test', $body['model'] );
		$this->assertSame( 'text/event-stream', $request->getHeaderAsString( 'Accept' ) );

		$options = $this->transporter->last_options;

		$this->assertInstanceOf( RequestOptions::class, $options );
		$this->assertTrue( $options->isStream() );
	}

	/**
	 * The returned streamed result assembles the canned SSE body.
	 *
	 * @since x.x.x
	 */
	public function test_returns_a_streamed_result_that_assembles_the_body(): void {
		$this->transporter->stream_body = $this->sse_body();

		$streamed = $this->model()->streamGenerateTextResult( array( $this->prompt() ) );

		$this->assertInstanceOf( StreamedGenerativeAiResult::class, $streamed );

		$result = $streamed->getFinalResult();

		$this->assertSame( 'Streamed reply.', $result->toText() );
		$this->assertSame( 'msg_stream', $result->getId() );
		$this->assertSame( 3, $result->getTokenUsage()->getPromptTokens() );
		$this->assertSame( 4, $result->getTokenUsage()->getCompletionTokens() );
	}

	/**
	 * The model's own provider and model metadata reach the assembled result.
	 *
	 * @since x.x.x
	 */
	public function test_result_carries_the_model_metadata(): void {
		$this->transporter->stream_body = $this->sse_body();

		$result = $this->model()->streamGenerateTextResult( array( $this->prompt() ) )->getFinalResult();

		$this->assertSame( 'anthropic', $result->getProviderMetadata()->getId() );
		$this->assertSame( 'claude-test', $result->getModelMetadata()->getId() );
	}

	/**
	 * An unsuccessful upstream response fails before any chunk is produced.
	 *
	 * @since x.x.x
	 */
	public function test_unsuccessful_response_throws(): void {
		$this->transporter->status      = 401;
		$this->transporter->stream_body = '{"error":{"message":"invalid api key"}}';

		$this->expectException( Streaming_Exception::class );

		$this->model()->streamGenerateTextResult( array( $this->prompt() ) );
	}

	/**
	 * Builds a one-message prompt.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AiClient\Messages\DTO\Message The prompt message.
	 */
	private function prompt(): Message {
		return new Message( MessageRoleEnum::user(), array( new MessagePart( 'Say hello.' ) ) );
	}
}

/**
 * Transporter that returns a canned streamed response.
 *
 * Declared here rather than reused from the transporter test case so this file
 * can run on its own.
 *
 * @since x.x.x
 */
class Canned_Stream_Transporter implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {

	/**
	 * The last request received.
	 *
	 * @var \WordPress\AiClient\Providers\Http\DTO\Request|null
	 */
	public ?Request $last_request = null;

	/**
	 * The last options received.
	 *
	 * @var \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null
	 */
	public ?RequestOptions $last_options = null;

	/**
	 * Status code to report.
	 *
	 * @var int
	 */
	public int $status = 200;

	/**
	 * Body served as the response stream.
	 *
	 * @var string
	 */
	public string $stream_body = '';

	/**
	 * Records the call and returns the canned streamed response.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options The options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The response.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$this->last_request = $request;
		$this->last_options = $options;

		return new Response(
			$this->status,
			array( 'Content-Type' => array( 'text/event-stream' ) ),
			Stream::create( $this->stream_body )
		);
	}
}
