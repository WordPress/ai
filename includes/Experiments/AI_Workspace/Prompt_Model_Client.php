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
 * Buffered unless a {@see Stream_Driver_Interface} is injected; none is by default.
 *
 * @since x.x.x
 */
class Prompt_Model_Client implements Model_Client_Interface {

	/**
	 * The shortest time, in seconds, a workspace round waits for the model.
	 *
	 * Core's 30-second default cuts off large proposals.
	 *
	 * @since x.x.x
	 *
	 * @var float
	 */
	public const REQUEST_TIMEOUT = 120.0;

	/**
	 * The most tokens a workspace round asks the model to write.
	 *
	 * Some connectors default to 4,096, which truncates a large proposal.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_OUTPUT_TOKENS = 16384;

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
	 * @return \WordPress\AiClient\Messages\DTO\Message|non-empty-list<\WordPress\AiClient\Messages\DTO\Message>|\WP_Error The assistant message, the assistant messages when the reply came as several, or an error.
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
	 * Anthropic rejects replayed unsigned thinking blocks with a 400 but accepts omitted ones.
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
	 * Returns the timeout a workspace round's request uses; it only ever raises the default.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $default_timeout The timeout core would use, in seconds.
	 * @return mixed The timeout to use, in seconds, or the value given when it is not a number.
	 */
	public static function request_timeout( $default_timeout ) {
		if ( ! is_numeric( $default_timeout ) ) {
			return $default_timeout;
		}

		/**
		 * Filters the shortest time, in seconds, a workspace round waits for the model.
		 *
		 * @since x.x.x
		 *
		 * @param float $timeout The timeout in seconds. Default 120.
		 */
		$timeout = (float) apply_filters( 'wpai_workspace_request_timeout', self::REQUEST_TIMEOUT );

		return max( (float) $default_timeout, $timeout );
	}

	/**
	 * Returns the most tokens a workspace round asks the model to write.
	 *
	 * @since x.x.x
	 *
	 * @return int The output token limit.
	 */
	public static function max_output_tokens(): int {
		/**
		 * Filters the most tokens a workspace round asks the model to write.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_tokens The output token limit. Default 16384.
		 */
		$max_tokens = (int) apply_filters( 'wpai_workspace_max_output_tokens', self::MAX_OUTPUT_TOKENS );

		return $max_tokens > 0 ? $max_tokens : self::MAX_OUTPUT_TOKENS;
	}

	/**
	 * Applies a round's settings to the prompt builder.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_AI_Client_Prompt_Builder $builder            The prompt builder.
	 * @param list<string>                 $ability_names      Abilities to declare as tools.
	 * @param string                       $system_instruction The system instruction.
	 * @return \WP_AI_Client_Prompt_Builder The configured builder.
	 */
	protected function prepare_builder( $builder, array $ability_names, string $system_instruction ) {
		$builder = $builder->using_system_instruction( $system_instruction );
		$builder = $builder->using_max_tokens( self::max_output_tokens() );

		if ( array() !== $ability_names ) {
			$builder = $builder->using_abilities( ...$ability_names );
		}

		return $builder;
	}

	/**
	 * Runs a buffered model round through the core prompt builder.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @return \WordPress\AiClient\Messages\DTO\Message|non-empty-list<\WordPress\AiClient\Messages\DTO\Message>|\WP_Error The assistant message, the assistant messages when the reply came as several, or an error.
	 */
	protected function generate_buffered( array $messages, array $ability_names, string $system_instruction ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'workspace_ai_client_unavailable',
				__( 'The WordPress AI client is not available in this environment.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		$raise = array( self::class, 'request_timeout' );

		try {
			// Core reads the timeout when the builder is made; scoping it leaves abilities' own calls alone.
			add_filter( 'wp_ai_client_default_request_timeout', $raise, PHP_INT_MAX );

			try {
				$builder = wp_ai_client_prompt( $messages );
			} finally {
				remove_filter( 'wp_ai_client_default_request_timeout', $raise, PHP_INT_MAX );
			}

			$result = $this->prepare_builder( $builder, $ability_names, $system_instruction )->generate_text_result();
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

		/*
		 * Keep every candidate: OpenAI returns text and a tool call as separate candidates,
		 * and refuses a replayed function call that shares a message with anything else.
		 */
		$replies = array();

		foreach ( $result->getCandidates() as $candidate ) {
			$replies[] = $candidate->getMessage();
		}

		return 1 === count( $replies ) ? $replies[0] : $replies;
	}
}
