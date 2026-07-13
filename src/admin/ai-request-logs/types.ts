/**
 * Internal dependencies
 */
import type { ProviderMetadataMap } from '../types/providers';

export type SummaryPeriod =
	| 'minute'
	| 'hour'
	| 'day'
	| 'week'
	| 'month'
	| 'all';

export interface LogEntrySource {
	type?: string;
	slug?: string;
	name?: string;
	file?: string;
}

export interface LogEntryContext extends Record< string, unknown > {
	input_preview?: string;
	output_preview?: string;
	request_kind?: string;
	image_urls?: unknown[];
	image_base64_samples?: unknown[];
	source?: LogEntrySource | Record< string, unknown >;
}

export interface LogEntry {
	id: string;
	timestamp: string;
	type: 'ai_client' | 'mcp_tool' | 'ability';
	operation: string;
	provider: string | null;
	model: string | null;
	duration_ms: number | null;
	tokens_input: number | null;
	tokens_output: number | null;
	tokens_total: number | null;
	tokens_per_second: number | null;
	status: 'success' | 'error' | 'timeout';
	error_message: string | null;
	user_id: number | null;
	context: LogEntryContext | null;
}

export interface LogSummary {
	total_requests: number;
	total_tokens: number;
	avg_duration_ms: number;
	success_rate: number;
	by_type: Record< string, number >;
	by_provider: Record< string, number >;
	by_status: Record< string, number >;
}

export interface UserFilterOption {
	value: string;
	label: string;
}

export interface FilterOptions {
	types: string[];
	providers: string[];
	statuses: string[];
	operations: string[];
	users: UserFilterOption[];
}

export interface LogsQuery {
	page: number;
	perPage: number;
	search: string;
	type: string;
	status: string;
	provider: string;
	operation: string[];
	tokensFilter: string;
	userId: string;
	orderby: string;
	order: 'asc' | 'desc';
	fields: string[];
}

export interface LocalizedSettings {
	rest: {
		nonce: string;
		root: string;
		routes: {
			logs: string;
			summary: string;
			filters: string;
		};
	};
	initialState: {
		summary: LogSummary;
		filters: FilterOptions;
	};
	connectorsUrl: string;
	providerMetadata: ProviderMetadataMap;
}

declare global {
	interface Window {
		aiRequestLogsSettings?: LocalizedSettings;
	}
}
