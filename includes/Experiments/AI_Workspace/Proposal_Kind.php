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
	 * Every outcome an item can have, in the order the counts are reported.
	 *
	 * Kinds that create report `created`, `failed`, `denied`, `duplicate` and
	 * `deselected`. Kinds that change something that exists also report
	 * `updated`, `unchanged` (it already had the new value), `stale` (it changed
	 * after the proposal was made, so nothing was written) and `locked` (another
	 * user is editing it). A kind reports every count, at zero when it never has
	 * that outcome, so the client and the model always read the same shape.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	public const OUTCOMES = array( 'created', 'updated', 'unchanged', 'stale', 'locked', 'failed', 'denied', 'duplicate', 'deselected' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * The item field holding the value an update item's target had when it was proposed, or a hash of it.
	 *
	 * An update kind sets it in {@see self::normalize_item()} only when the item
	 * has none yet, so an item the person edits keeps the snapshot it was
	 * proposed with. The store keeps it across edits whatever the edit says.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SNAPSHOT_FIELD = 'snapshot';

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
	 * Returns the name of the ability the model calls to propose this kind.
	 *
	 * The tool list, the abilities that end a turn, the abilities a follow-up
	 * turn withholds and the system instruction are all built from the kinds,
	 * so a new kind is offered by being registered and nowhere else.
	 *
	 * @since x.x.x
	 *
	 * @return string The ability name.
	 */
	public function ability(): string;

	/**
	 * Returns the coarse capability a user needs to be offered the propose ability.
	 *
	 * @since x.x.x
	 *
	 * @return string The capability, or an empty string for any user who reaches the workspace.
	 */
	public function capability(): string;

	/**
	 * Returns the sentence the system instruction carries while the propose ability is declared.
	 *
	 * @since x.x.x
	 *
	 * @return string The translated sentence.
	 */
	public function instruction(): string;

	/**
	 * Reports whether the kind changes something that already exists.
	 *
	 * Update kinds are the ones the `wpai_workspace_write_proposals_enabled`
	 * filter turns off. Kinds that only create stay on either way.
	 *
	 * @since x.x.x
	 *
	 * @return bool True for an update kind.
	 */
	public function is_update(): bool;

	/**
	 * Reports whether the person may edit an item's proposed values before approving.
	 *
	 * An edit is run back through {@see self::normalize_item()}, so it meets the
	 * same rules as the model's values.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when edits are accepted.
	 */
	public function accepts_edits(): bool;

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
	 * `$on_item` is called with each item's outcome as soon as that item is
	 * done, deselected items included, so a write that dies part way leaves the
	 * outcomes of the items it did write on the stored proposal.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @param array<int, string>   $selected The item keys the person approved.
	 * @param callable|null        $on_item  Optional. Receives each item's outcome once it is done.
	 * @return array{items: list<array<string, mixed>>, created: int, updated: int, unchanged: int, stale: int, locked: int, failed: int, denied: int, duplicate: int, deselected: int} The per-item outcomes and their counts.
	 */
	public function write( array $proposal, array $selected, ?callable $on_item = null ): array;

	/**
	 * Returns the operation name recorded on the kind's log rows and outcome.
	 *
	 * @since x.x.x
	 *
	 * @return string The operation name.
	 */
	public function log_operation(): string;
}
