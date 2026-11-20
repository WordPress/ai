/**
 * Availability status reported by the server for the workspace.
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
 * Outcome of resolving a handoff; `prompt` (from the command palette) takes precedence over a post.
 */
export type SeedStatus = 'ready' | 'denied' | 'not-found' | 'prompt';

/**
 * The post or prompt a handoff opened the workspace with.
 *
 * No body on purpose: content is read only through the permission-checked tools. `title` is untrusted.
 */
export interface WorkspaceSeed {
	postId: number;
	status: SeedStatus;
	postType: string;
	title: string;
	/** Sanitized plain text, at most 500 characters; empty unless `status` is `prompt`. */
	prompt: string;
}

export interface LocalizedData {
	rest: RestData;
	availability: Availability;
	connectorsUrl: string;
	/** Null when the workspace was opened directly. */
	seed: WorkspaceSeed | null;
}

/**
 * The scope of site content the assistant may consider; `general` declares no tools.
 */
export type ContextScope = 'site' | 'general';

/**
 * Terminal status of a turn, as reported by the server.
 */
export type TurnStatus =
	| 'complete'
	| 'max_rounds'
	| 'cancelled'
	| 'awaiting_user'
	| 'tools_unavailable'
	| 'model_unavailable';

/**
 * One post returned by `ai/search-content`; never enriched client-side.
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
	/** Present only when the ability says the user may edit the post; gates the edit action. */
	edit_link?: string;
}

/**
 * A bounded list of posts, as returned by `ai/search-content`.
 *
 * `total - results.length` mixes later pages with permission drops; never render it as a count.
 */
export interface PostResultSet {
	results: PostResultRow[];
	total: number;
	total_pages: number;
}

/**
 * What one tool invocation retrieved, normalized by the server; never recompute these counts.
 */
export interface RetrievalSummary {
	/** Typed open-ended so a new server kind still renders. */
	kind: 'search' | 'read' | string;
	/** Untrusted model input: render as text only. */
	query: string;
	/** Null for a search. */
	requested: number | null;
	/** Items looked at before the permission filter (`examined - withheld === returned`); null means unreported. */
	examined: number | null;
	/** Items returned after the permission filter. */
	returned: number;
	/** Items withheld by permissions (never pagination); null means unknown, not zero. */
	withheld: number | null;
}

/**
 * One tool invocation, as recorded by the turn loop.
 */
export interface ToolCallRecord {
	ability: string;
	call_id: string | null;
	round: number;
	status: string;
	error_code: string;
	duration_ms: number;
	/** The ability's raw return value; narrow before rendering. */
	result?: unknown;
	/** Null when there is nothing to summarize. */
	retrieval?: RetrievalSummary | null;
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
 * How an assistant entry ended; `streaming` is the only non-terminal value.
 *
 * `dismissed` records a question closed without an answer; it renders on the question's card.
 */
export type EntryStatus = 'streaming' | TurnStatus | 'error' | 'dismissed';

/**
 * One rendered turn in the transcript.
 */
export interface TranscriptEntry {
	id: string;
	/** The ID the client gave the turn; a dismissal carries the ID of the turn that asked. */
	turnId: string;
	prompt: string;
	/** The assistant text received so far. */
	text: string;
	status: EntryStatus;
	/** Explanation shown for errors and unavailable states. */
	detail: string;
	scope: ContextScope;
	toolCalls: ToolCallRecord[];
	rounds: number;
	maxRounds: number;
	streamed: boolean;
	/** True for the automatic follow-up after a proposal settles; it has no prompt. */
	followUp: boolean;
}

/**
 * One item of a stored write proposal; no summary field, since the model's description is untrusted.
 */
export interface ProposalItem {
	key: string;
	post_type: string;
	status: string;
	title: string;
	content: string;
	excerpt: string;
	/** A template's slug, such as `404`. Empty for a draft. */
	slug: string;
	/** Theme a template is created for. Empty for a draft. */
	theme: string;
	/** True when a template replaces one the theme ships as a file. */
	replaces_theme_template: boolean;
	/** The post an update item changes. Zero for anything else. */
	post_id: number;
	/** Field an update item changes (post or site option). Empty for creates. */
	field: string;
	/** An update item's value as it is now, read when the card loads. */
	current: string;
	value: string;
	/** True when an update goes live for visitors as soon as it is applied. */
	live: boolean;
}

/**
 * A question the assistant asked, with the answers it offered.
 */
export interface Question {
	question: string;
	choices: string[];
}

/**
 * A stored write proposal awaiting confirmation.
 */
export interface Proposal {
	/** `draft` or `template` (create), `post-field` or `site-identity` (update). */
	kind: string;
	proposal_id: string;
	conversation_id: string;
	status: string;
	expires: number;
	max_items: number;
	items: ProposalItem[];
	/** What was written once approval started (possibly partial); null while pending. */
	results: ProposalExecution | null;
}

/**
 * What happened to one item of an executed proposal.
 *
 * `outcome` stays a string so a new server outcome still renders.
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
	/** Empty for an item that created something. */
	field: string;
	/** Set only when the server reports the written value in full. */
	value: string;
}

/**
 * The result of executing a proposal.
 */
export interface ProposalExecution {
	proposal_id: string;
	created: number;
	updated: number;
	/** Items that already had the proposed value. */
	unchanged: number;
	/** Items that changed after the proposal was made. */
	stale: number;
	/** Items another user was editing. */
	locked: number;
	failed: number;
	denied: number;
	duplicate: number;
	deselected: number;
	items: ProposalItemOutcome[];
	/** True when the assistant may follow up. */
	follow_up: boolean;
	/** True for a reopened chat's record of a discarded proposal. */
	discarded: boolean;
}

/**
 * A stored chat, as the history list shows it.
 */
export interface ChatSummary {
	id: string;
	name: string;
	archived: boolean;
	/** Unix timestamps, in seconds. */
	created: number;
	updated: number;
}

/**
 * A stored chat as the single-chat route returns it; the transcript is rebuilt from `entries`.
 */
export interface StoredChat extends ChatSummary {
	scope: string;
	entries: unknown[];
	write_results: unknown[];
}

declare global {
	interface Window {
		aiWorkspace?: LocalizedData;
	}
}
