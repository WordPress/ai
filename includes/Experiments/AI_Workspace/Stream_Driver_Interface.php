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
 * Keeps provider-specific streaming classes behind one seam; callers never name them.
 *
 * @since x.x.x
 */
interface Stream_Driver_Interface {

	/**
	 * Streams a model round.
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
