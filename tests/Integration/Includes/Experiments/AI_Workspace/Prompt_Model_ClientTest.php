<?php
/**
 * Integration tests for the AI Workspace model client.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Prompt_Model_Client;
use WordPress\AI\Experiments\AI_Workspace\Stream_Driver_Interface;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;

/**
 * Prompt_Model_Client test case.
 *
 * Rounds are buffered unless a stream driver is injected, and a driver that
 * cannot stream falls back to a buffered request rather than failing the turn.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Prompt_Model_Client
 */
class Prompt_Model_ClientTest extends WP_UnitTestCase {

	/**
	 * Returns a one-message conversation.
	 *
	 * @since x.x.x
	 *
	 * @return list<\WordPress\AiClient\Messages\DTO\Message> The conversation.
	 */
	private function conversation(): array {
		return array( new Message( MessageRoleEnum::user(), array( new MessagePart( 'Hello' ) ) ) );
	}

	/**
	 * With no driver injected, a round is buffered even when a text callback is supplied.
	 *
	 * @since x.x.x
	 */
	public function test_default_client_uses_the_buffered_path(): void {
		$client   = new Fallback_Recording_Model_Client();
		$received = array();

		$result = $client->generate(
			$this->conversation(),
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
	}

	/**
	 * A driver that cannot stream sends the round to the buffered path.
	 *
	 * @since x.x.x
	 */
	public function test_absent_streaming_model_uses_the_buffered_path(): void {
		$client = new Fallback_Recording_Model_Client( new Null_Stream_Driver() );

		$result = $client->generate(
			$this->conversation(),
			array(),
			'system',
			static function (): void {
			}
		);

		$this->assertInstanceOf( Message::class, $result );
		$this->assertTrue( $client->buffered_was_used );
	}

	/**
	 * An injected driver that streams answers the round without a buffered request.
	 *
	 * @since x.x.x
	 */
	public function test_injected_driver_answers_when_it_streams(): void {
		$client   = new Fallback_Recording_Model_Client( new Canned_Stream_Driver() );
		$received = array();

		$result = $client->generate(
			$this->conversation(),
			array(),
			'system',
			static function ( string $text ) use ( &$received ): void {
				$received[] = $text;
			}
		);

		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( 'streamed reply', $result->getParts()[0]->getText() );
		$this->assertFalse( $client->buffered_was_used );
		$this->assertSame( array( 'streamed reply' ), $received );
	}

	/**
	 * Without a text callback the driver is not consulted.
	 *
	 * @since x.x.x
	 */
	public function test_injected_driver_is_skipped_without_a_text_callback(): void {
		$client = new Fallback_Recording_Model_Client( new Canned_Stream_Driver() );

		$result = $client->generate( $this->conversation(), array(), 'system' );

		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( 'buffered reply', $result->getParts()[0]->getText() );
		$this->assertTrue( $client->buffered_was_used );
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
 * A driver that streams a canned reply, standing in for a host that can stream.
 *
 * @since x.x.x
 */
class Canned_Stream_Driver implements Stream_Driver_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation.
	 * @param list<string>                                   $ability_names      Declared abilities.
	 * @param string                                         $system_instruction The system instruction.
	 * @param callable                                       $on_text            Text delta callback.
	 * @return \WordPress\AiClient\Messages\DTO\Message The streamed reply.
	 */
	public function stream( array $messages, array $ability_names, string $system_instruction, callable $on_text ): ?Message {
		$on_text( 'streamed reply' );

		return new Message( MessageRoleEnum::model(), array( new MessagePart( 'streamed reply' ) ) );
	}
}
