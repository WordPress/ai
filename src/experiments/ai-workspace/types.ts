/**
 * Availability status reported by the server for the workspace.
 *
 * - `ready`: the workspace can operate.
 * - `no-credentials`: no valid AI credentials are configured.
 * - `no-function-calling`: no configured model supports function calling.
 */
export type AvailabilityStatus =
	| 'ready'
	| 'no-credentials'
	| 'no-function-calling';

export interface Availability {
	status: AvailabilityStatus;
}

export interface RestData {
	nonce: string;
	root: string;
	routes: Record< string, string >;
}

/**
 * Outcome of resolving the post a block editor handoff pointed at.
 *
 * - `ready`: the post exists and the person may read it.
 * - `denied`: the person's capabilities do not permit reading it.
 * - `not-found`: no such post.
 */
export type SeedStatus = 'ready' | 'denied' | 'not-found';

/**
 * The post a block editor handoff opened the workspace with.
 *
 * This is an identity, not content. There is deliberately no body here: the
 * workspace reads content only through the permission-checked tool path, so a
 * client-supplied body would be a second, unenforced way in. `title` is
 * author-controlled text and is treated as untrusted throughout — the server
 * flattens and clamps it, and the workspace only ever renders it as text or
 * places it in a prompt the person sees and can edit before sending.
 */
export interface WorkspaceSeed {
	postId: number;
	status: SeedStatus;
	postType: string;
	title: string;
}

export interface LocalizedData {
	rest: RestData;
	availability: Availability;
	settingsUrl: string;
	/** The seeded post, or null when the workspace was opened directly. */
	seed: WorkspaceSeed | null;
}

/**
 * The scope of site content the assistant is allowed to consider.
 *
 * There are exactly two (R6), and they match the `scope` enum the turn route
 * accepts: `site` declares the permission-filtered tools, `general` declares
 * none at all.
 */
export type ContextScope = 'site' | 'general';

/**
 * Terminal status of a turn, as reported by the server.
 *
 * `max_rounds` is neither success nor failure: the model ended the turn by
 * exhausting the round cap (R10), so it is rendered distinctly and carries no
 * retry affordance.
 */
export type TurnStatus =
	| 'complete'
	| 'max_rounds'
	| 'cancelled'
	| 'tools_unavailable'
	| 'model_unavailable';

/**
 * One post returned by a content-listing ability.
 *
 * These are exactly the fields `ai/search-content` returns. Nothing here is
 * enriched client-side: the row went through the ability's execute-time
 * permission filtering, and re-fetching any part of it would step around that.
 */
export interface PostResultRow {
	id: number;
	post_type: string;
	status: string;
	date: string;
	slug: string;
	link: string;
	title: string;
	excerpt: string;
	/**
	 * Editor URL for this post, when the ability reported one.
	 *
	 * A row carries this only when the ability determined the requesting user
	 * may edit that post, so its presence is what gates the edit action. No
	 * ability registered today reports it, so the action stays hidden rather
	 * than offering a link that would deny.
	 */
	edit_link?: string;
}

/**
 * A bounded list of posts, as returned by `ai/search-content`.
 *
 * `total` counts the posts the underlying query matched and may exceed
 * `results.length`, because row-level permission checks withhold rows.
 */
export interface PostResultSet {
	results: PostResultRow[];
	total: number;
	total_pages: number;
}

/**
 * One tool invocation, as recorded by the turn loop.
 *
 * `result` is the ability's own return value, passed through by the turn route
 * untouched. It is null for a refused or failed call, and its shape is whatever
 * the ability declared, so a consumer has to narrow it before rendering.
 */
export interface ToolCallRecord {
	ability: string;
	call_id: string | null;
	round: number;
	status: string;
	error_code: string;
	duration_ms: number;
	result?: unknown;
}

/**
 * The turn route's response body.
 */
export interface TurnResponse {
	conversation_id: string;
	scope?: string;
	status: TurnStatus;
	reason?: string;
	rounds: number;
	max_rounds?: number;
	tools: string[];
	tool_calls: ToolCallRecord[];
	messages: unknown[];
	text: string;
}

/**
 * How an assistant entry ended, from the transcript's point of view.
 *
 * `streaming` is the only non-terminal value; `error` is the only one that
 * offers a retry.
 */
export type EntryStatus = 'streaming' | TurnStatus | 'error';

/**
 * One rendered turn in the transcript.
 */
export interface TranscriptEntry {
	id: string;
	/** The person's message that opened this turn. */
	prompt: string;
	/** The assistant text received so far. */
	text: string;
	status: EntryStatus;
	/** Human-readable explanation, used for errors and unavailable states. */
	detail: string;
	scope: ContextScope;
	toolCalls: ToolCallRecord[];
	rounds: number;
	maxRounds: number;
	/** True when this turn's text arrived over the streaming transport. */
	streamed: boolean;
}

/**
 * One item of a stored write proposal, as read back from the server.
 *
 * Every field here is a resolved value that will be written verbatim. There is
 * deliberately no summary field: the model's description of a write is
 * attacker-influenceable, and R16 requires the confirmation to show the write
 * itself. The server drops anything the model supplied beyond these fields.
 */
export interface ProposalItem {
	key: string;
	post_type: string;
	status: string;
	title: string;
	content: string;
	excerpt: string;
}

/**
 * A stored write proposal awaiting confirmation.
 */
export interface Proposal {
	proposal_id: string;
	conversation_id: string;
	status: string;
	expires: number;
	max_items: number;
	items: ProposalItem[];
}

/**
 * What happened to one item of an executed proposal.
 *
 * `outcome` is one of `created`, `failed`, `denied`, `duplicate` or
 * `deselected`. It is rendered as received rather than narrowed to a union,
 * because a server that grows a new outcome should not make the transcript
 * silently drop the row.
 */
export interface ProposalItemOutcome {
	key: string;
	title: string;
	post_type: string;
	status: string;
	outcome: string;
	post_id: number;
	edit_link: string;
	error_code: string;
	error_message: string;
}

/**
 * The result of executing a proposal.
 */
export interface ProposalExecution {
	proposal_id: string;
	created: number;
	failed: number;
	denied: number;
	duplicate: number;
	deselected: number;
	items: ProposalItemOutcome[];
}

declare global {
	interface Window {
		aiWorkspace?: LocalizedData;
	}
}
