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
	 * @return \WordPress\AiClient\Messages\DTO\Message|non-empty-list<\WordPress\AiClient\Messages\DTO\Message>|\WP_Error The assistant message, the assistant messages when the reply came as several, or an error.
	 */
	public function generate( array $messages, array $ability_names, string $system_instruction, ?callable $on_text = null );
}
