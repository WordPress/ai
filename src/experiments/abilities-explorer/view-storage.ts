/**
 * Persists the list view's layout, fields, sort and page size.
 *
 * Search and filters are not saved, so a stale filter cannot come back as an
 * empty table. Field IDs are never pruned, so a deactivated extension's column
 * returns with it.
 */

/**
 * WordPress dependencies
 */
import type { View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import { isJsonRecord } from './validate';

const STORAGE_KEY = 'ai.abilitiesExplorer.view';
const STORAGE_VERSION = 1;

type SortDirection = 'asc' | 'desc';

export interface SavedView {
	type: string;
	layout: Record< string, unknown >;
	fields: string[];
	sort: { field: string; direction: SortDirection } | null;
	perPage: number;
	/** Every field ID seen so far, so a hidden field stays hidden. */
	seenFields: string[];
}

interface StoredRecord extends SavedView {
	version: number;
}

type Unchecked< T > = { [ K in keyof T ]?: unknown };

const isStringArray = ( value: unknown ): value is string[] =>
	Array.isArray( value ) &&
	value.every( ( entry ) => typeof entry === 'string' );

const readSort = ( value: unknown ): SavedView[ 'sort' ] => {
	if ( ! isJsonRecord( value ) ) {
		return null;
	}

	const sort = value as Unchecked< NonNullable< SavedView[ 'sort' ] > >;

	if (
		typeof sort.field !== 'string' ||
		( sort.direction !== 'asc' && sort.direction !== 'desc' )
	) {
		return null;
	}

	return { field: sort.field, direction: sort.direction };
};

/**
 * Reads the saved view. Unusable storage reads as nothing saved.
 *
 * @return The saved view, or null.
 */
export function loadSavedView(): SavedView | null {
	let raw: string | null = null;

	try {
		raw = window.localStorage.getItem( STORAGE_KEY );
	} catch {
		return null;
	}

	if ( null === raw ) {
		return null;
	}

	let parsed: unknown;

	try {
		parsed = JSON.parse( raw );
	} catch {
		return null;
	}

	if ( ! isJsonRecord( parsed ) ) {
		return null;
	}

	const stored = parsed as Unchecked< StoredRecord >;

	if (
		stored.version !== STORAGE_VERSION ||
		typeof stored.type !== 'string' ||
		! isStringArray( stored.fields )
	) {
		return null;
	}

	const perPage = stored.perPage;

	return {
		type: stored.type,
		layout: isJsonRecord( stored.layout ) ? stored.layout : {},
		fields: stored.fields,
		sort: readSort( stored.sort ),
		perPage:
			typeof perPage === 'number' &&
			Number.isInteger( perPage ) &&
			perPage > 0
				? perPage
				: 0,
		seenFields: isStringArray( stored.seenFields ) ? stored.seenFields : [],
	};
}

/**
 * Puts back saved field IDs that DataViews dropped because they have no
 * registered field right now.
 *
 * @param visible    The visible field IDs about to be saved.
 * @param previous   The visible field IDs saved before.
 * @param registered Every registered field ID.
 * @return The visible field IDs.
 */
function keepUnregisteredFields(
	visible: string[],
	previous: string[],
	registered: string[]
): string[] {
	const next = [ ...visible ];

	previous.forEach( ( id, index ) => {
		if ( ! registered.includes( id ) && ! next.includes( id ) ) {
			next.splice( Math.min( index, next.length ), 0, id );
		}
	} );

	return next;
}

/**
 * Saves the persisted part of a view.
 *
 * @param view       The current view.
 * @param seenFields Every field ID seen so far.
 * @param registered Every registered field ID.
 */
export function saveView(
	view: View,
	seenFields: string[],
	registered: string[]
): void {
	const visible = view.fields ?? [];
	const previous = loadSavedView();

	const record: StoredRecord = {
		version: STORAGE_VERSION,
		type: view.type,
		layout:
			'layout' in view && isJsonRecord( view.layout ) ? view.layout : {},
		fields: previous
			? keepUnregisteredFields( visible, previous.fields, registered )
			: visible,
		sort: readSort( view.sort ),
		perPage: view.perPage ?? 0,
		seenFields: previous
			? mergeFieldIds( seenFields, previous.seenFields )
			: seenFields,
	};

	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( record ) );
	} catch {
		// The view still works; it is just not remembered.
	}
}

/**
 * Returns the union of two lists of field IDs, keeping first-seen order.
 *
 * @param first  The first list.
 * @param second The second list.
 * @return The merged list.
 */
export function mergeFieldIds( first: string[], second: string[] ): string[] {
	return [ ...first, ...second.filter( ( id ) => ! first.includes( id ) ) ];
}

/**
 * Appends the fields seen for the first time to the visible fields.
 *
 * @param visible    The visible field IDs.
 * @param seen       Every field ID seen before.
 * @param candidates The field IDs shown by default.
 * @return The visible field IDs.
 */
export function appendNewFields(
	visible: string[],
	seen: string[],
	candidates: string[]
): string[] {
	const additions = candidates.filter(
		( id ) => ! seen.includes( id ) && ! visible.includes( id )
	);

	return additions.length > 0 ? [ ...visible, ...additions ] : visible;
}

/**
 * Lays a saved layout over the default one, keeping the default column
 * styles the saved layout does not set.
 *
 * @param defaultLayout The default layout.
 * @param savedLayout   The saved layout.
 * @return The merged layout.
 */
function mergeLayout(
	defaultLayout: Record< string, unknown >,
	savedLayout: Record< string, unknown >
): Record< string, unknown > {
	const { styles: defaultValue } = defaultLayout;
	const { styles: savedValue } = savedLayout;
	const defaultStyles = isJsonRecord( defaultValue ) ? defaultValue : {};
	const savedStyles = isJsonRecord( savedValue ) ? savedValue : {};
	const styles: Record< string, unknown > = { ...savedStyles };

	for ( const [ id, style ] of Object.entries( defaultStyles ) ) {
		const saved = savedStyles[ id ];

		styles[ id ] = isJsonRecord( saved )
			? { ...( isJsonRecord( style ) ? style : {} ), ...saved }
			: style;
	}

	const merged = { ...defaultLayout, ...savedLayout };

	return 0 === Object.keys( styles ).length ? merged : { ...merged, styles };
}

/**
 * Builds the starting view: the default view with the saved one laid over it.
 *
 * @param defaultView     The default view.
 * @param saved           The saved view, or null.
 * @param fieldIds        Every registered field ID.
 * @param candidateIds    The field IDs shown by default.
 * @param supportedLayout The layout types the list offers.
 * @return The view, and every field ID seen so far.
 */
export function restoreView(
	defaultView: View,
	saved: SavedView | null,
	fieldIds: string[],
	candidateIds: string[],
	supportedLayout: string[]
): { view: View; seenFields: string[] } {
	if ( ! saved ) {
		return {
			view: {
				...defaultView,
				fields: appendNewFields(
					defaultView.fields ?? [],
					[],
					candidateIds
				),
			},
			seenFields: [ ...fieldIds ],
		};
	}

	const defaultLayout =
		'layout' in defaultView && isJsonRecord( defaultView.layout )
			? defaultView.layout
			: {};

	const next = {
		...defaultView,
		fields: appendNewFields( saved.fields, saved.seenFields, candidateIds ),
		...( supportedLayout.includes( saved.type )
			? {
					type: saved.type,
					layout: mergeLayout( defaultLayout, saved.layout ),
			  }
			: {} ),
		...( saved.sort ? { sort: saved.sort } : {} ),
		...( saved.perPage > 0 ? { perPage: saved.perPage } : {} ),
	};

	return {
		view: next as View,
		seenFields: mergeFieldIds( saved.seenFields, fieldIds ),
	};
}
