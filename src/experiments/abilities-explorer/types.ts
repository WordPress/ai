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
