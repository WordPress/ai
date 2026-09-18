<?php
/**
 * Contract for the streaming half of a workspace model round.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WordPress\AiClient\Messages\DTO\Message;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Streams one model round, or reports that this host cannot stream.
 *
 * Separating this from {@see Model_Client_Interface} keeps every reference to the
 * third-party Anthropic provider's classes behind one seam: an implementation may
 * name them, callers never do.
 *
 * @since x.x.x
 */
interface Stream_Driver_Interface {

	/**
	 * Streams a model round.
	 *
	 * Returning null means "this host could not stream this round" — because no
	 * streaming model is available, or because the attempt failed — and asks the
	 * caller to fall back to a buffered request rather than failing the turn.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $messages           The conversation so far.
	 * @param list<string>                                   $ability_names      Abilities to declare as tools.
	 * @param string                                         $system_instruction The system instruction.
	 * @param callable                                       $on_text            Receives text deltas as they arrive.
	 * @return \WordPress\AiClient\Messages\DTO\Message|null The assistant message, or null to fall back.
	 */
	public function stream( array $messages, array $ability_names, string $system_instruction, callable $on_text ): ?Message;
}
