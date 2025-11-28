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
	cost_estimate: number | null;
	status: 'success' | 'error' | 'timeout';
	error_message: string | null;
	user_id: number | null;
	context: Record< string, unknown > | null;
}

export interface LogSummary {
	total_requests: number;
	total_tokens: number;
	total_cost: number;
	avg_duration_ms: number;
	success_rate: number;
	by_type: Record< string, number >;
	by_provider: Record< string, number >;
	by_status: Record< string, number >;
}

export interface FilterOptions {
	types: string[];
	providers: string[];
	statuses: string[];
	operations: string[];
}

export interface LogFilters {
	type: string;
	status: string;
	provider: string;
	operation: string;
	search: string;
	period: 'day' | 'week' | 'month' | 'all';
}

export interface LogSettings {
	enabled: boolean;
	retentionDays: number;
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
		enabled: boolean;
		retentionDays: number;
		summary: LogSummary;
		filters: FilterOptions;
	};
}

declare global {
	interface Window {
		AiRequestLogsSettings?: LocalizedSettings;
		aiAiRequestLogsSettings?: LocalizedSettings;
	}
}
