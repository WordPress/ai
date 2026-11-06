<?php
/**
 * A kind of AI Workspace write proposal.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Describes one kind of thing a proposal may write, such as drafts or a template.
 *
 * Every kind goes through the same approval path: {@see Proposal_Store} stores
 * what the model proposed, {@see REST\Proposal_Controller} shows it and runs it
 * once a person approves, and nothing else writes. A kind supplies only what
 * differs between them:
 *
 * - **Its fields and their sanitizer**, run when the proposal is stored, so the
 *   card shows exactly what will be written.
 * - **The capability check and the writer**, run again when the person approves,
 *   because a capability can be revoked in between.
 * - **What the card is shown**, which is only ever the stored values.
 *
 * @internal This interface should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
interface Proposal_Kind {

	/**
	 * Returns the kind's name, as stored on the proposal and read by the card.
	 *
	 * @since x.x.x
	 *
	 * @return string The kind name.
	 */
	public function name(): string;

	/**
	 * Returns the most items one proposal of this kind may carry.
	 *
	 * @since x.x.x
	 *
	 * @return int The item limit.
	 */
	public function max_items(): int;

	/**
	 * Normalizes, sanitizes and validates one proposed item.
	 *
	 * Only the kind's own fields survive, so anything else the model supplied,
	 * such as a summary of what it claims it will write, never reaches the card.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $item The raw item.
	 * @return array<string, string>|\WP_Error The stored fields, without a key, or an error.
	 */
	public function normalize_item( array $item );

	/**
	 * Returns the fields the card shows for one stored item.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item The stored item.
	 * @return array<string, mixed> The presented fields, without the key.
	 */
	public function present_item( array $item ): array;

	/**
	 * Writes the selected items of an approved proposal.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @param array<int, string>   $selected The item keys the person approved.
	 * @return array{items: list<array<string, mixed>>, created: int, failed: int, denied: int, duplicate: int, deselected: int} The per-item outcomes.
	 */
	public function write( array $proposal, array $selected ): array;

	/**
	 * Returns the operation name recorded on the kind's log rows and outcome.
	 *
	 * @since x.x.x
	 *
	 * @return string The operation name.
	 */
	public function log_operation(): string;
}
