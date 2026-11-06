<?php
/**
 * Default model client for AI Workspace turns.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use Throwable;
use WP_Error;
use WordPress\AiClient\Messages\DTO\Message;

use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Runs a workspace model round through the core prompt builder.
 *
 * Rounds are buffered: the model's reply arrives whole. Streaming is attempted
 * only when a {@see Stream_Driver_Interface} was injected and the caller supplies
 * a text callback. None is injected by default, because the PHP AI Client does
 * not ship provider streaming yet. A driver that returns null means this host
 * could not stream the round, and the turn answers with a buffered request
 * instead of failing.
 *
 * @since x.x.x
 */
class Prompt_Model_Client implements Model_Client_Interface {

	/**
	 * The streaming driver, when one was supplied.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Stream_Driver_Interface|null
	 */
	private $driver;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Stream_Driver_Interface|null $driver Optional. The streaming driver.
	 */
	public function __construct( ?Stream_Driver_Interface $driver = null ) {
		$this->driver = $driver;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return bool True when text generation can run.
	 */
	public function supports_text_generation(): bool {
		return has_valid_ai_credentials();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the model can be offered tools.
	 */
	public function supports_function_calling(): bool {
		return Function_Calling_Support::is_available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @param callable|null                                  $on_text            Optional. Receives text deltas as they stream.
	 * @return \WordPress\AiClient\Messages\DTO\Message|\WP_Error The assistant message, or an error.
	 */
	public function generate( array $messages, array $ability_names, string $system_instruction, ?callable $on_text = null ) {
		$messages = self::without_unsigned_thoughts( $messages );

		if ( null !== $on_text && null !== $this->driver ) {
			$streamed = $this->driver->stream( $messages, $ability_names, $system_instruction, $on_text );

			if ( null !== $streamed ) {
				return $streamed;
			}
		}

		return $this->generate_buffered( $messages, $ability_names, $system_instruction );
	}

	/**
	 * Drops thought parts that carry no signature from the conversation.
	 *
	 * A reasoning model's private thinking comes back as thought-channel parts,
	 * and the whole history is replayed on the next round. Some providers only
	 * accept that thinking back when it carries the signature they issued:
	 * Anthropic rejects an unsigned `thinking` block with a 400 ("thinking.signature:
	 * Field required"), and its connector does not yet keep the signature when it
	 * reads a reply. An omitted thinking block is accepted, so an unsigned thought
	 * part is left out rather than sent. Signed thought parts, which the Google
	 * and OpenAI connectors produce, pass through unchanged.
	 *
	 * A model message left with no parts is dropped.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages The conversation.
	 * @return list<\WordPress\AiClient\Messages\DTO\Message> The conversation without unsigned thought parts.
	 */
	public static function without_unsigned_thoughts( array $messages ): array {
		$kept = array();

		foreach ( $messages as $message ) {
			$parts   = array();
			$dropped = false;

			foreach ( $message->getParts() as $part ) {
				if ( $part->getChannel()->isThought() ) {
					$signature = $part->getThoughtSignature();

					if ( null === $signature || '' === $signature ) {
						$dropped = true;
						continue;
					}
				}

				$parts[] = $part;
			}

			if ( ! $dropped ) {
				$kept[] = $message;
				continue;
			}

			if ( array() === $parts ) {
				continue;
			}

			$kept[] = new Message( $message->getRole(), $parts );
		}

		return $kept;
	}

	/**
	 * Runs a buffered model round through the core prompt builder.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @return \WordPress\AiClient\Messages\DTO\Message|\WP_Error The assistant message, or an error.
	 */
	protected function generate_buffered( array $messages, array $ability_names, string $system_instruction ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'workspace_ai_client_unavailable',
				__( 'The WordPress AI client is not available in this environment.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		try {
			$builder = wp_ai_client_prompt( $messages )
				->using_system_instruction( $system_instruction );

			if ( array() !== $ability_names ) {
				$builder = $builder->using_abilities( ...$ability_names );
			}

			$result = $builder->generate_text_result();
		} catch ( Throwable $e ) {
			return new WP_Error(
				'workspace_generation_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result->toMessage();
	}
}
