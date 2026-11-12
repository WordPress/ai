/**
 * Site Agent types.
 */

export interface Routes {
	turn: string;
	approve: string;
	decline: string;
	updateProposal: string;
	conversations: string;
}

export interface RestConfig {
	nonce: string;
	root: string;
	routes: Routes;
}

export interface Availability {
	status: 'ready' | 'no-credentials' | 'no-function-calling';
}

export interface LocalizedData {
	rest: RestConfig;
	availability: Availability;
	canWrite: boolean;
	settingsUrl: string;
	maxMessageLength: number;
}

export interface ProposalChange {
	field: string;
	label: string;
	from: string | null;
	to: string;
	format: 'text' | 'html';
}

export interface Proposal {
	id: string;
	ability: string;
	label: string;
	description: string;
	args: Record< string, unknown >;
	changes: ProposalChange[];
	expires: number;
}

export type EntryRole = 'user' | 'assistant' | 'system';

export interface Entry {
	id: string;
	role: EntryRole;
	text: string;
}

export interface ConversationSummary {
	id: string;
	title: string;
	created: number;
	updated: number;
	archived: boolean;
	preview: string;
}

export interface Conversation extends ConversationSummary {
	transcript: Entry[];
	proposal: Proposal | null;
}

export type TurnStatus = 'complete' | 'pending' | 'max_rounds';

export interface TurnResponse {
	conversation_id: string;
	status: TurnStatus;
	rounds: number;
	tools: string[];
	text: string;
	proposal: Proposal | null;
	transcript: Entry[];
}

export interface ApprovalResponse {
	conversation_id: string;
	proposal_id: string;
	ability: string;
	result: unknown;
	error: string;
	transcript?: Entry[];
}

export interface DeclineResponse {
	declined: boolean;
	conversation_id: string;
	transcript?: Entry[];
}

export interface ConversationsResponse {
	conversations: ConversationSummary[];
}

declare global {
	interface Window {
		aiSiteAgent?: LocalizedData;
	}
}
