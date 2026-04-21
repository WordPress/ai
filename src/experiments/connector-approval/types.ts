/**
 * Type definitions for the connector approval admin experience.
 */

export interface Connector {
	id: string;
	name: string;
	type: string;
	setting_name: string;
	owner: string;
}

export interface PluginSummary {
	basename: string;
	name: string;
}

export interface PendingEntry {
	key: string;
	caller_type: string;
	caller_basename: string;
	caller_name: string;
	connector_id: string;
	attempts: number;
	first_seen: number;
	last_seen: number;
}

export type ApprovalMatrix = Record< string, Record< string, boolean > >;

export interface ApprovalState {
	connectors: Connector[];
	approvals: ApprovalMatrix;
	pending: PendingEntry[];
	plugins: PluginSummary[];
}

export interface LocalizedData {
	restUrl: string;
	nonce: string;
}

declare global {
	interface Window {
		aiConnectorApproval?: LocalizedData;
	}
}
