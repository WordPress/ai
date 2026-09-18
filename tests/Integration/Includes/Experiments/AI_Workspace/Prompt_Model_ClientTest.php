<?php
/**
 * Integration tests for the AI Workspace model client's streaming fallback.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Prompt_Model_Client;
use WordPress\AI\Experiments\AI_Workspace\Stream_Driver_Interface;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Anthropic_Streaming_Text_Generation_Model;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Turn_Driver;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Prompt_Model_Client test case.
 *
 * A host that cannot open a stream must still get an answer: a streaming attempt
 * that raises `CODE_TRANSPORT` falls back to a buffered request rather than
 * failing the turn.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Prompt_Model_Client
 */
class Prompt_Model_ClientTest extends WP_UnitTestCase {

	/**
	 * Fallback events observed during a test.
	 *
	 * @since x.x.x
	 *
	 * @var list<array{code: int, message: string}>
	 */
	private $fallbacks = array();

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->fallbacks = array();

		add_action( 'wpai_workspace_streaming_fallback', array( $this, 'record_fallback' ), 10, 2 );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_action( 'wpai_workspace_streaming_fallback', array( $this, 'record_fallback' ), 10 );

		parent::tearDown();
	}

	/**
	 * Records a streaming fallback event.
	 *
	 * @since x.x.x
	 *
	 * @param int    $code    The exception code.
	 * @param string $message The exception message.
	 */
	public function record_fallback( $code, $message ): void {
		$this->fallbacks[] = array(
			'code'    => (int) $code,
			'message' => (string) $message,
		);
	}

	/**
	 * A transport that cannot open a stream falls back to a buffered request.
	 *
	 * @since x.x.x
	 */
	public function test_transport_failure_falls_back_to_a_buffered_request(): void {
		$autoload = WP_PLUGIN_DIR . '/ai-provider-for-anthropic/src/autoload.php';

		if ( ! file_exists( $autoload ) ) {
			$this->markTestSkipped( 'The ai-provider-for-anthropic plugin is not installed.' );
		}

		require_once $autoload;

		if ( ! Anthropic_Streaming_Text_Generation_Model::is_available() ) {
			$this->markTestSkipped( 'The streaming model is not available in this environment.' );
		}

		$model = new Anthropic_Streaming_Text_Generation_Model(
			new ModelMetadata( 'claude-test', 'Claude Test', array( CapabilityEnum::textGeneration() ), array() ),
			new ProviderMetadata( 'anthropic', 'Anthropic', ProviderTypeEnum::cloud() )
		);

		$model->setHttpTransporter( new Refusing_Stream_Transporter() );
		$model->setRequestAuthentication( new ApiKeyRequestAuthentication( 'test-key-1234567890' ) );

		$client   = new Fallback_Recording_Model_Client( new Streaming_Turn_Driver( $model ) );
		$received = array();

		$result = $client->generate(
			array( new Message( MessageRoleEnum::user(), array( new MessagePart( 'Hello' ) ) ) ),
			array(),
			'system',
			static function ( string $text ) use ( &$received ): void {
				$received[] = $text;
			}
		);

		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( 'buffered reply', $result->getParts()[0]->getText() );
		$this->assertTrue( $client->buffered_was_used );
		$this->assertSame( array(), $received, 'Nothing streamed, so nothing should have been emitted.' );

		$this->assertCount( 1, $this->fallbacks );
		$this->assertSame( Streaming_Exception::CODE_TRANSPORT, $this->fallbacks[0]['code'] );
	}

	/**
	 * A host with no streaming model goes straight to a buffered request.
	 *
	 * @since x.x.x
	 */
	public function test_absent_streaming_model_uses_the_buffered_path_silently(): void {
		$client = new Fallback_Recording_Model_Client( new Null_Stream_Driver() );

		$result = $client->generate(
			array( new Message( MessageRoleEnum::user(), array( new MessagePart( 'Hello' ) ) ) ),
			array(),
			'system',
			static function (): void {
			}
		);

		$this->assertInstanceOf( Message::class, $result );
		$this->assertTrue( $client->buffered_was_used );
		$this->assertSame( array(), $this->fallbacks, 'Not being able to stream at all is not a fallback event.' );
	}
}

/**
 * A model client whose buffered reply is canned.
 *
 * @since x.x.x
 */
class Fallback_Recording_Model_Client extends Prompt_Model_Client {

	/**
	 * Whether the buffered path ran.
	 *
	 * @var bool
	 */
	public $buffered_was_used = false;

	/**
	 * {@inheritDoc}
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation.
	 * @param list<string>                                   $ability_names      Declared abilities.
	 * @param string                                         $system_instruction The system instruction.
	 * @return \WordPress\AiClient\Messages\DTO\Message The reply.
	 */
	protected function generate_buffered( array $messages, array $ability_names, string $system_instruction ) {
		$this->buffered_was_used = true;

		return new Message( MessageRoleEnum::model(), array( new MessagePart( 'buffered reply' ) ) );
	}
}

/**
 * A driver standing in for a host with no streaming model at all.
 *
 * @since x.x.x
 */
class Null_Stream_Driver implements Stream_Driver_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation.
	 * @param list<string>                                   $ability_names      Declared abilities.
	 * @param string                                         $system_instruction The system instruction.
	 * @param callable                                       $on_text            Text delta callback.
	 * @return \WordPress\AiClient\Messages\DTO\Message|null Always null.
	 */
	public function stream( array $messages, array $ability_names, string $system_instruction, callable $on_text ): ?Message {
		return null;
	}
}

/**
 * A transporter that refuses to open a stream.
 *
 * @since x.x.x
 */
class Refusing_Stream_Transporter implements HttpTransporterInterface {

	/**
	 * Refuses every request the way a host without a usable stream transport would.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options The options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response Never returned.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception Always.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		throw new Streaming_Exception( 'Could not open a stream.', Streaming_Exception::CODE_TRANSPORT );
	}
}
