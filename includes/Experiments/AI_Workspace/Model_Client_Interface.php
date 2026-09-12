<?php
/**
 * Contract for the model call a workspace turn makes.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Performs one model round for a workspace turn.
 *
 * The turn loop owns permission filtering, provenance, logging and the round
 * cap; this contract owns nothing but "send these messages, offer these tools,
 * hand back the assistant's reply". Keeping the two apart is what lets the loop
 * be tested without a network, since the tool broker is the part that has to be
 * proven.
 *
 * @since x.x.x
 */
interface Model_Client_Interface {

	/**
	 * Reports whether a text generation model is reachable.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when text generation can run.
	 */
	public function supports_text_generation(): bool;

	/**
	 * Reports whether a function-calling capable model is reachable.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the model can be offered tools.
	 */
	public function supports_function_calling(): bool;

	/**
	 * Runs one model round.
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
