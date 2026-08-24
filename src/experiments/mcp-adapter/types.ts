export interface McpAbility {
	name: string;
	label: string;
	description: string;
	exposed: boolean;
}

export interface McpSettings {
	adapter_active: boolean;
	abilities: McpAbility[];
	overrides: Record< string, boolean >;
	endpoint: string | null;
}
