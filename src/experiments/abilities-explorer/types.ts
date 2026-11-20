/**
 * Shapes of the Abilities Explorer's localized settings and REST payloads.
 */

/**
 * A JSON object. PHP encodes an empty one as `[]`.
 */
export type JsonObject = Record< string, unknown >;
export type JsonObjectOrEmpty = JsonObject | unknown[];

/**
 * The origin every ability is counted under.
 */
export type AbilityOrigin = 'Core' | 'Plugin' | 'Theme';

/**
 * One row of the list route. A field that could not be encoded as JSON
 * arrives as `null` and is named in `unencodable_fields`.
 */
export interface AbilityListItem {
	slug: string;
	name: string | null;
	description: string | null;
	provider: string | null;
	provider_label: string | null;
	origin: AbilityOrigin;
	/** Sent unescaped: render as text. */
	category: string | null;
	show_in_rest: boolean;
	show_in_mcp: boolean;
	/** True when the AI Workspace offers this ability to the model. */
	conversational_surface: boolean;
	/** True when the site owner removed it from the AI Workspace. */
	owner_excluded: boolean;
	meta: JsonObjectOrEmpty | null;
	unencodable_fields: string[];
}

/**
 * The item route's payload.
 */
export interface AbilityDetailItem extends AbilityListItem {
	input_schema: JsonObjectOrEmpty | null;
	output_schema: JsonObjectOrEmpty | null;
	raw_data: JsonObjectOrEmpty | null;
	example_input: JsonObjectOrEmpty | null;
}

/**
 * The list route's payload.
 */
export interface AbilitiesListResponse {
	items: AbilityListItem[];
	/** Orders responses: one with a lower sequence than one already applied is stale. */
	sequence: number;
}

export type SurfaceChange = 'remove' | 'restore';

/**
 * The surface route's payload: the updated row.
 */
export interface SurfaceResponse {
	change: SurfaceChange;
	/** False when the stored state already matched; still a success. */
	changed: boolean;
	item: AbilityListItem;
	sequence: number;
}

/**
 * The invoke route's 200 payload.
 */
export type InvokeResponse =
	| { success: true; data: unknown }
	| {
			success: false;
			error: {
				code: string | number;
				message: string;
				data: unknown;
			};
	  };

/**
 * A `WP_Error` as the REST API serializes it.
 */
export interface RestErrorPayload {
	code: string;
	message: string;
	data?: {
		status?: number;
		/** Present on the invoke route's 400s. */
		errors?: string[];
		[ key: string ]: unknown;
	} | null;
}

/**
 * The settings localized as `aiAbilitiesExplorer`.
 */
export interface LocalizedSettings {
	rest: {
		nonce: string;
		root: string;
		routes: {
			abilities: string;
			item: string;
			invoke: string;
			surface: string;
		};
	};
	pageSlug: string;
	providerLabels: Record< string, string >;
}

declare global {
	interface Window {
		aiAbilitiesExplorer?: LocalizedSettings;
	}
}
