<?php
/**
 * Model client contract for Site Agent turns.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Runs one model round.
 *
 * Injectable so the turn loop can be tested without contacting a provider.
 *
 * @since x.x.x
 */
interface Model_Client_Interface {

	/**
	 * Reports whether text generation can run.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when text generation can run.
	 */
	public function supports_text_generation(): bool;

	/**
	 * Reports whether the model can be offered tools.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when function calling is available.
	 */
	public function supports_function_calling(): bool;

	/**
	 * Generates one assistant message.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @param callable|null                                  $on_text            Optional. Receives text deltas as they stream.
	 * @return \WordPress\AiClient\Messages\DTO\Message|\WP_Error The assistant message, or an error.
	 */
	public function generate( array $messages, array $ability_names, string $system_instruction, ?callable $on_text = null );
}
