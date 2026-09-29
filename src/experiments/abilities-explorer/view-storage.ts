/**
 * Persists the list view between sessions (KTD10).
 *
 * Only the layout, the visible fields, the sort and the page size are saved.
 * Search, filters and page stay in memory, so a stale filter value (a
 * deactivated provider, a translated category) can never come back as an empty
 * table behind a blank chip.
 *
 * Field IDs are never pruned. A field that is not registered right now, such
 * as a column from a deactivated extension, keeps its place in the saved view
 * and shows again when its field returns. DataViews skips IDs it has no field
 * for. `LogsTable`'s field normalization is deliberately not reused here,
 * because it drops unknown IDs.
 *
 * The saved record also lists every field ID seen so far. A field seen for the
 * first time is appended to the visible fields when it is one the caller wants
 * shown by default; a field the user already saw and hid stays hidden.
 */

/**
 * WordPress dependencies
 */
import type { View } from '@wordpress/dataviews/wp';

const STORAGE_KEY = 'ai.abilitiesExplorer.view';
const STORAGE_VERSION = 1;

type SortDirection = 'asc' | 'desc';

/**
 * The persisted part of a view.
 */
export interface SavedView {
	type: string;
	layout: Record< string, unknown >;
	fields: string[];
	sort: { field: string; direction: SortDirection } | null;
	perPage: number;
	/** Every field ID seen when the view was saved, visible or not. */
	seenFields: string[];
}

interface StoredRecord extends SavedView {
	version: number;
}

/** A stored value before it has been checked. */
type Unchecked< T > = { [ K in keyof T ]?: unknown };

const isRecord = ( value: unknown ): value is Record< string, unknown > =>
	!! value && typeof value === 'object' && ! Array.isArray( value );

const isStringArray = ( value: unknown ): value is string[] =>
	Array.isArray( value ) &&
	value.every( ( entry ) => typeof entry === 'string' );

const readSort = ( value: unknown ): SavedView[ 'sort' ] => {
	if ( ! isRecord( value ) ) {
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
 * Reads the saved view, if there is a usable one.
 *
 * Storage that is unavailable, empty, from another version or malformed
 * reads as "nothing saved", so the list always renders.
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

	if ( ! isRecord( parsed ) ) {
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
		layout: isRecord( stored.layout ) ? stored.layout : {},
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
 * Puts back the saved field IDs that have no registered field right now.
 *
 * DataViews drops IDs it has no field for whenever the user moves or hides a
 * column (its table layout rebuilds `view.fields` from the registered fields).
 * An unregistered field cannot be hidden by the user, so any saved ID that is
 * unregistered and missing from `visible` was dropped that way, and goes back
 * at its old position. A registered field missing from `visible` was hidden
 * on purpose and stays hidden.
 *
 * @param visible    The visible field IDs about to be saved.
 * @param previous   The visible field IDs saved before.
 * @param registered Every registered field ID.
 * @return The visible field IDs, with dropped unregistered IDs restored.
 */
export function keepUnregisteredFields(
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
 * When the registered field IDs are given, saved IDs that DataViews dropped
 * because their field is not registered right now (a deactivated extension's
 * column) are kept, so a saved view never loses a third-party column (R18).
 *
 * @param view       The current view.
 * @param seenFields Every field ID seen so far.
 * @param registered Every registered field ID, if known.
 */
export function saveView(
	view: View,
	seenFields: string[],
	registered?: string[]
): void {
	const visible = view.fields ?? [];
	const previous = registered ? loadSavedView() : null;

	const record: StoredRecord = {
		version: STORAGE_VERSION,
		type: view.type,
		layout: 'layout' in view && isRecord( view.layout ) ? view.layout : {},
		fields:
			previous && registered
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
		// The view still works for this session; it just is not remembered.
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
 * Nothing already in `visible` is removed or reordered, including IDs that
 * have no registered field right now.
 *
 * @param visible    The visible field IDs.
 * @param seen       Every field ID seen before.
 * @param candidates The registered field IDs that are shown by default.
 * @return The visible field IDs, with newly seen candidates appended.
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
 * Builds the view to start from: the default view with the saved layout,
 * visible fields, sort and page size laid over it.
 *
 * @param defaultView     The default view, which also supplies search, filters and page.
 * @param saved           The saved view, or null.
 * @param fieldIds        Every registered field ID.
 * @param candidateIds    The registered field IDs that are shown by default.
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
		// With nothing saved, every candidate is new, including an
		// extension's column that is registered on the very first load.
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

	const next = {
		...defaultView,
		fields: appendNewFields( saved.fields, saved.seenFields, candidateIds ),
		...( supportedLayout.includes( saved.type )
			? { type: saved.type, layout: saved.layout }
			: {} ),
		...( saved.sort ? { sort: saved.sort } : {} ),
		...( saved.perPage > 0 ? { perPage: saved.perPage } : {} ),
	};

	return {
		// The saved type is one of `supportedLayout`, so this is a valid view.
		view: next as View,
		seenFields: mergeFieldIds( saved.seenFields, fieldIds ),
	};
}
