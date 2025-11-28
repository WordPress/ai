export interface ServerDetails {
	id: string | null;
	name: string | null;
	description: string | null;
	route_namespace: string | null;
	route: string | null;
	http_endpoint: string | null;
	cli_command: string | null;
	has_route: boolean;
	status: 'running' | 'initializing' | 'disabled';
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

export interface McpServerState {
	enabled: boolean;
	server: ServerDetails | null;
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
			base: string;
			tools: string;
			test: string;
		};
	};
	profileUrl: string;
	initialState: {
		enabled: boolean;
		server: ServerDetails | null;
	};
}

declare global {
	interface Window {
		aiMcpServerSettings: LocalizedSettings;
	}
}

export type CopyHandler = ( value: string, label: string ) => void;
