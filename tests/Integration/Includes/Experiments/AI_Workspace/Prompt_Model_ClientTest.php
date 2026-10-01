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
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
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

	/**
	 * Returns a two-turn conversation whose model reply carries a thought part.
	 *
	 * @since x.x.x
	 *
	 * @param string|null $signature The thought part's signature, or null for none.
	 * @return list<\WordPress\AiClient\Messages\DTO\Message> The conversation.
	 */
	private function conversation_with_thought( ?string $signature ): array {
		return array(
			new Message( MessageRoleEnum::user(), array( new MessagePart( 'Suggest a series.' ) ) ),
			new Message(
				MessageRoleEnum::model(),
				array(
					new MessagePart( 'Private reasoning.', MessagePartChannelEnum::thought(), $signature ),
					new MessagePart( 'Here is a series.' ),
				)
			),
			new Message( MessageRoleEnum::user(), array( new MessagePart( 'Draft it' ) ) ),
		);
	}

	/**
	 * Returns the thought parts in a conversation.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages The conversation.
	 * @return list<\WordPress\AiClient\Messages\DTO\MessagePart> The thought parts.
	 */
	private function thought_parts( array $messages ): array {
		$thoughts = array();

		foreach ( $messages as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getChannel()->isThought() ) {
					$thoughts[] = $part;
				}
			}
		}

		return $thoughts;
	}

	/**
	 * An unsigned thought part is not replayed: Anthropic rejects an unsigned
	 * thinking block, and its connector does not keep the signature.
	 *
	 * @since x.x.x
	 */
	public function test_unsigned_thought_parts_are_not_replayed(): void {
		$client = new Fallback_Recording_Model_Client();

		$client->generate( $this->conversation_with_thought( null ), array(), 'system' );

		$this->assertCount( 3, $client->sent, 'Every message with content should still be sent.' );
		$this->assertSame( array(), $this->thought_parts( $client->sent ) );
		$this->assertSame( 'Here is a series.', $client->sent[1]->getParts()[0]->getText() );
		$this->assertTrue( $client->sent[1]->getRole()->isModel() );
	}

	/**
	 * An empty signature counts as no signature.
	 *
	 * @since x.x.x
	 */
	public function test_thought_parts_with_an_empty_signature_are_not_replayed(): void {
		$client = new Fallback_Recording_Model_Client();

		$client->generate( $this->conversation_with_thought( '' ), array(), 'system' );

		$this->assertSame( array(), $this->thought_parts( $client->sent ) );
	}

	/**
	 * A signed thought part, as the Google and OpenAI connectors produce, is
	 * replayed with its signature.
	 *
	 * @since x.x.x
	 */
	public function test_signed_thought_parts_are_replayed_unchanged(): void {
		$client = new Fallback_Recording_Model_Client();

		$client->generate( $this->conversation_with_thought( 'signature-123' ), array(), 'system' );

		$thoughts = $this->thought_parts( $client->sent );
		$this->assertCount( 1, $thoughts );
		$this->assertSame( 'signature-123', $thoughts[0]->getThoughtSignature() );
		$this->assertSame( 'Private reasoning.', $thoughts[0]->getText() );
	}

	/**
	 * A model message that held only an unsigned thought is dropped rather
	 * than sent empty.
	 *
	 * @since x.x.x
	 */
	public function test_a_model_message_left_empty_is_dropped(): void {
		$messages = array(
			new Message( MessageRoleEnum::user(), array( new MessagePart( 'Hello' ) ) ),
			new Message( MessageRoleEnum::model(), array( new MessagePart( 'Only thinking.', MessagePartChannelEnum::thought() ) ) ),
		);

		$kept = Prompt_Model_Client::without_unsigned_thoughts( $messages );

		$this->assertCount( 1, $kept );
		$this->assertTrue( $kept[0]->getRole()->isUser() );
	}

	/**
	 * A conversation with nothing to drop is passed through as the same objects.
	 *
	 * @since x.x.x
	 */
	public function test_a_conversation_without_unsigned_thoughts_is_unchanged(): void {
		$messages = $this->conversation_with_thought( 'signature-123' );

		$this->assertSame( $messages, Prompt_Model_Client::without_unsigned_thoughts( $messages ) );
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
	 * The conversation the buffered path received.
	 *
	 * @var list<\WordPress\AiClient\Messages\DTO\Message>
	 */
	public $sent = array();

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
		$this->sent              = $messages;

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
