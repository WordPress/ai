/**
 * Availability status reported by the server for the workspace.
 *
 * - `ready`: the workspace can operate.
 * - `no-credentials`: no valid AI credentials are configured.
 * - `no-function-calling`: no configured model supports function calling.
 */
export type AvailabilityStatus =
	| 'ready'
	| 'no-credentials'
	| 'no-function-calling';

export interface Availability {
	status: AvailabilityStatus;
}

export interface RestData {
	nonce: string;
	root: string;
	routes: Record< string, string >;
}

export interface LocalizedData {
	rest: RestData;
	availability: Availability;
	settingsUrl: string;
}

/**
 * The scope of site content the assistant is allowed to consider.
 */
export type ContextScope = 'site' | 'post-type' | 'selection';

declare global {
	interface Window {
		aiWorkspace?: LocalizedData;
	}
}
