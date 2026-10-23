/**
 * Site Agent types.
 */

export interface Routes {
	turn: string;
	approve: string;
	decline: string;
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

export interface Proposal {
	id: string;
	ability: string;
	label: string;
	description: string;
	args: Record< string, unknown >;
	expires: number;
}

export type TurnStatus = 'complete' | 'pending' | 'max_rounds';

export interface TurnResponse {
	conversation_id: string;
	status: TurnStatus;
	rounds: number;
	tools: string[];
	text: string;
	proposal: Proposal | null;
}

export interface ApprovalResponse {
	proposal_id: string;
	ability: string;
	result: unknown;
	error: string;
}

export interface Entry {
	id: string;
	role: 'user' | 'assistant' | 'system';
	text: string;
}

declare global {
	interface Window {
		aiSiteAgent?: LocalizedData;
	}
}
