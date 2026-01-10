/**
 * Type definitions for AI Experiments settings.
 */

/**
 * DataForm field definition.
 */
export interface DataFormField {
	id: string;
	type: string;
	label: string;
	description?: string;
	elements?: Array< { value: string; label: string } >;
	Edit?: string | { control: string; [ key: string ]: unknown };
}

/**
 * Entry point link for an experiment.
 */
export interface EntryPoint {
	label: string;
	url: string;
	type?: string;
}

/**
 * Experiment data from the REST API.
 */
export interface ExperimentData {
	id: string;
	label: string;
	description: string;
	enabled: boolean;
	hasSettings: boolean;
	entryPoints: EntryPoint[];
	settingsFields?: DataFormField[];
	settingsValues?: Record< string, unknown >;
}

/**
 * Settings data from the REST API.
 */
export interface SettingsData {
	globalEnabled: boolean;
	experiments: ExperimentData[];
	hasValidCredentials: boolean;
	credentialsUrl: string;
}

/**
 * Window augmentation for localized settings data.
 */
declare global {
	interface Window {
		aiExperimentsSettings?: SettingsData;
	}
}
