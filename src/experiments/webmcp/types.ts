export interface ToolResult {
	content: Array< { type: 'text'; text: string } >;
}

export interface ToolDefinition {
	name: string;
	description: string;
	inputSchema: Record< string, unknown >;
	annotations?: Record< string, unknown >;
}

export interface WebMCPTool extends ToolDefinition {
	execute: ( input: unknown ) => Promise< ToolResult >;
}

export interface ModelContext {
	registerTool?: ( tool: WebMCPTool ) => unknown;
	provideContext?: ( context: { tools: WebMCPTool[] } ) => unknown;
}

export interface BridgeData {
	screen: string;
	maxTools: number;
}

export interface WebMCPRegistry {
	registerTool: ( tool: WebMCPTool ) => void;
	getTools: () => WebMCPTool[];
	refresh: () => void;
}
