export type BuilderState =
	| 'idle'
	| 'planning'
	| 'coding'
	| 'reviewing'
	| 'fixing'
	| 'ready_to_install'
	| 'installing'
	| 'installed'
	| 'error';

export type LogLevel = 'info' | 'success' | 'warn' | 'error';

export interface LogEntry {
	id: number;
	timestamp: Date;
	level: LogLevel;
	message: string;
	detail?: string;
}

export interface PluginFile {
	path: string;
	type: string;
	description: string;
	is_main?: boolean;
	content?: string;
}

export interface SecurityIssue {
	file_path: string;
	line: number;
	pattern: string;
	line_content: string;
}

export interface GeneratedFile extends PluginFile {
	content: string;
}

export interface PluginPlan {
	plugin_name: string;
	plugin_slug: string;
	description: string;
	complexity: 'simple' | 'complex';
	is_modification?: boolean;
	files: PluginFile[];
	hooks_used?: string[];
	wp_apis_used?: string[];
	security_notes?: string[];
	architecture?: string;
}

export interface ReviewResult {
	passed: boolean;
	review_summary: string;
	suggestions?: {
		action: string;
		file_path: string;
		file_type: string;
		reason: string;
		description: string;
	}[];
}

export interface TokenUsageStep {
	step: string;
	model: string;
	input_tokens: number;
	output_tokens: number;
	continued?: number;
}

export interface TokenUsageSummary {
	total_tokens: number;
	total_input_tokens: number;
	total_output_tokens: number;
	steps: TokenUsageStep[];
}

export interface ChatMessage {
	id: string;
	role: 'user' | 'assistant';
	type:
		| 'text'
		| 'loading'
		| 'plan'
		| 'files'
		| 'review'
		| 'install'
		| 'analysis'
		| 'error';
	content: string;
	data?: any;
	timestamp: Date;
}

export interface StatusResponse {
	job_id: string;
	status:
		| 'queued'
		| 'planning'
		| 'coding'
		| 'reviewing'
		| 'fixing'
		| 'done'
		| 'error';
	current_step: string;
	plan?: PluginPlan;
	files?: GeneratedFile[];
	review?: ReviewResult;
	error?: string;
	token_usage?: TokenUsageSummary;
	_server_time?: number;
}

export interface GenerateResponse {
	job_id: string;
	status: 'queued';
	type: 'plugin_request' | 'modification_request';
}

export interface QuestionResponse {
	type: 'question' | 'other';
	response: string;
	token_usage?: TokenUsageSummary;
}

export type AnyGenerateResponse = GenerateResponse | QuestionResponse;

export interface WriteSuccessResponse {
	written: true;
	plugin: string;
}

export interface WriteErrorResponse {
	error: string;
	status?: number;
}

export interface WriteSlugConflictResponse {
	needs_confirmation: true;
	warnings: string[];
	message: string;
}

export type WriteResponse =
	| WriteSuccessResponse
	| WriteErrorResponse
	| WriteSlugConflictResponse;

export function isJobResponse(
	response: AnyGenerateResponse
): response is GenerateResponse {
	return (
		response.type === 'plugin_request' ||
		response.type === 'modification_request'
	);
}

export function needsSlugConfirmation(response: WriteResponse): response is WriteSlugConflictResponse {
	return 'needs_confirmation' in response && response.needs_confirmation;
}

export interface AnalysisNewCommand {
	name: string;
	label: string;
	url: string;
}

export interface AnalysisResponse {
	new_commands?: AnalysisNewCommand[];
	suggested_commands?: string[];
}

export interface ChatHistory {
	id?: number;
	title?: string;
	date?: string;
	messages: ChatMessage[];
	plugin_slug?: string;
}

