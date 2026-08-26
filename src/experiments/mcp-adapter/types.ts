export interface McpAbility {
	name: string;
	label: string;
	description: string;
	exposed: boolean;
	default: boolean;
}

export interface McpSettings {
	adapter_active: boolean;
	abilities: McpAbility[];
	overrides: Record< string, boolean >;
	endpoint: string | null;
}

/**
 * Pending, unsaved override edits. `true`/`false` sets an override,
 * `null` clears a saved override back to the ability's default.
 */
export type PendingOverrides = Record< string, boolean | null >;
