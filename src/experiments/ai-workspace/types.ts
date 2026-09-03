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

export interface LocalizedData {
	rest: RestData;
	availability: Availability;
	settingsUrl: string;
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

declare global {
	interface Window {
		aiWorkspace?: LocalizedData;
	}
}
