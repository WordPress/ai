export interface McpAbility {
	name: string;
	label: string;
	description: string;
	exposed: boolean;
	default: boolean;
}

export interface McpPluginState {
	slug: string;
	status: 'active' | 'installed' | 'missing';
	file: string | null;
	can_install: boolean;
	can_activate: boolean;
	autoinstall_error: string | null;
}

export interface McpSettings {
	adapter_active: boolean;
	abilities: McpAbility[];
	overrides: Record< string, boolean >;
	endpoint: string | null;
	plugin: McpPluginState;
}

/**
 * Pending, unsaved override edits. `true`/`false` sets an override,
 * `null` clears a saved override back to the ability's default.
 */
export type PendingOverrides = Record< string, boolean | null >;
