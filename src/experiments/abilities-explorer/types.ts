/**
 * Shapes of the Abilities Explorer's localized settings and REST payloads.
 *
 * The payloads mirror `Abilities_Controller` and `Ability_Handler::to_rest_item()`.
 */

/**
 * A JSON object. PHP encodes an empty associative array as `[]`, so fields
 * that hold an object can arrive as an empty array.
 */
export type JsonObject = Record< string, unknown >;
export type JsonObjectOrEmpty = JsonObject | unknown[];

/**
 * A `Tool_Policy::REASON_*` code.
 */
export type SurfaceReason =
	| 'withheld'
	| 'not_public'
	| 'effect_class'
	| 'capability'
	| 'filtered'
	| 'awaiting_enable'
	| 'owner_excluded'
	| 'policy_off';

/**
 * The origin every ability is counted under: `Ability_Handler::detect_origin()`.
 */
export type AbilityOrigin = 'Core' | 'Plugin' | 'Theme';

/**
 * One row of the list route.
 *
 * Each item is encode-checked on the server on its own. A field that could
 * not be encoded as JSON arrives as `null` and is named in
 * `unencodable_fields`, which is why the free-text fields are nullable.
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
	conversational_surface: boolean;
	surface_reason: SurfaceReason | null;
	surface_reason_label: string | null;
	owner_excluded: boolean;
	meta: JsonObjectOrEmpty | null;
	unencodable_fields: string[];
}

/**
 * The item route's payload: a list row plus its schemas, raw data and example input.
 */
export interface AbilityDetailItem extends AbilityListItem {
	input_schema: JsonObjectOrEmpty | null;
	output_schema: JsonObjectOrEmpty | null;
	raw_data: JsonObjectOrEmpty | null;
	example_input: JsonObjectOrEmpty | null;
}

/**
 * The assistant admission policy state.
 */
export interface PolicyState {
	disabled: boolean;
	admission_enabled: boolean;
	active: boolean;
}

/**
 * The list route's payload.
 */
export interface AbilitiesListResponse {
	items: AbilityListItem[];
	policy: PolicyState;
	/** Orders responses: one with a lower sequence than one already applied is stale. */
	sequence: number;
}

export type SurfaceChange =
	| 'remove'
	| 'restore'
	| 'disable_policy'
	| 'enable_policy';

interface SurfaceResponseBase {
	change: SurfaceChange;
	/** False when the stored state already matched; still a success. */
	changed: boolean;
	policy: PolicyState;
	sequence: number;
}

/**
 * The surface route's payload. Remove and restore answer with the updated
 * row; a policy change answers with the full list.
 */
export type SurfaceResponse =
	| ( SurfaceResponseBase & {
			change: 'remove' | 'restore';
			item: AbilityListItem;
	  } )
	| ( SurfaceResponseBase & {
			change: 'disable_policy' | 'enable_policy';
			items: AbilityListItem[];
	  } );

/**
 * The invoke route's 200 payload: the ability's own outcome.
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
 * The settings `Admin_Page::enqueue_assets()` localizes as `aiAbilitiesExplorer`.
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
	surfaceReasonLabels: Partial< Record< SurfaceReason, string > >;
	providerLabels: Record< string, string >;
}

declare global {
	interface Window {
		aiAbilitiesExplorer?: LocalizedSettings;
	}
}
