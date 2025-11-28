export interface ServerSummary {
	id: string;
	name: string;
	description: string;
	enabled: boolean;
	status: 'running' | 'initializing' | 'disabled';
}

export interface ServerDetails extends ServerSummary {
	route_namespace: string;
	route: string;
	transports: string[];
	http_endpoint: string | null;
	cli_command: string | null;
	has_route: boolean;
	enabled: boolean;
}

export interface ToolSummary {
	name: string;
	label: string;
	description: string;
	category: {
		slug: string;
		label: string;
	};
	isPublic: boolean;
	enabled: boolean;
}

export interface ConfigTemplate {
	id: string;
	fileName: string;
	content: string;
}

export interface McpOverview {
	enabled: boolean;
	servers: ServerSummary[];
	activeServerId: string;
	activeServer: ServerDetails | null;
	tools: ToolSummary[];
	configTemplates: Record<string, ConfigTemplate>;
}

export interface TestResult {
	success: boolean;
	code: number | null;
	message: string;
	body?: string;
}

export interface LocalizedSettings {
	rest: {
		nonce: string;
		root: string;
		routes: {
			overview: string;
			enabled: string;
			server: string;
			addServer: string;
			tools: string;
			test: string;
		};
	};
	profileUrl: string;
}

declare global {
	interface Window {
		aiMcpServerSettings: LocalizedSettings;
	}
}

export type CopyHandler = ( value: string, label: string ) => void;
