/**
 * The list's DataViews fields: the built-in ones, and read-only fields added
 * by other plugins through the `ai.abilitiesExplorer.fields` filter (#203).
 *
 * `getBuiltInFields()` is the one place the built-in columns and filters are
 * defined. `getFields()` wraps it with the extension point, and `useFields()`
 * recomputes when a filter is added or removed after the first render.
 */

/**
 * WordPress dependencies
 */
import type { Field } from '@wordpress/dataviews/wp';
import { Component, useMemo, useSyncExternalStore } from '@wordpress/element';
import { addAction, applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ExposedIn, {
	getAssistantState,
	getAssistantStateLabels,
	type AssistantState,
} from './components/list/ExposedIn';
import {
	KNOWN_ORIGINS,
	PROVIDER_FIELD_ID,
} from './components/list/provider-filter';
import ProviderBadge, { getProviderLabel } from './components/ProviderBadge';
import type { AbilityListItem } from './types';
import { isJsonRecord } from './validate';

export type AbilityField = Field< AbilityListItem >;

/** The primary column: the ability name, linked to its detail view. */
export const TITLE_FIELD_ID = 'name';

/** The built-in columns shown when there is no saved view. */
export const DEFAULT_VISIBLE_FIELDS: readonly string[] = [
	'slug',
	PROVIDER_FIELD_ID,
	'surface',
];

/** Every built-in field ID. Built-in IDs win over extension fields. */
export const BUILT_IN_FIELD_IDS: readonly string[] = [
	TITLE_FIELD_ID,
	'slug',
	PROVIDER_FIELD_ID,
	'category',
	'surface',
	'description',
];

/**
 * Builds the provider filter options: Core, Plugin and Theme first, always,
 * then each custom provider label once, alphabetically.
 *
 * @param items The abilities.
 * @return The options.
 */
function getProviderElements(
	items: AbilityListItem[]
): { value: string; label: string }[] {
	const known: string[] = [ ...KNOWN_ORIGINS ];
	const custom = Array.from(
		new Set(
			items
				.map( ( item ) => item.provider )
				.filter(
					( provider ): provider is string =>
						typeof provider === 'string' &&
						'' !== provider &&
						! known.includes( provider )
				)
		)
	).sort( ( a, b ) => a.localeCompare( b ) );

	return [ ...known, ...custom ].map( ( value ) => ( {
		value,
		label: getProviderLabel( value ),
	} ) );
}

/**
 * Builds the category filter options: each label once, alphabetically.
 *
 * The labels arrive unescaped and are rendered by React as text, so a label
 * with `&` in it reads as `&`, not `&amp;`.
 *
 * @param items The abilities.
 * @return The options.
 */
function getCategoryElements(
	items: AbilityListItem[]
): { value: string; label: string }[] {
	return Array.from(
		new Set(
			items
				.map( ( item ) => item.category )
				.filter(
					( category ): category is string =>
						typeof category === 'string' && '' !== category
				)
		)
	)
		.sort( ( a, b ) => a.localeCompare( b ) )
		.map( ( value ) => ( { value, label: value } ) );
}

/**
 * Builds the built-in fields.
 *
 * Every free-text field may arrive as `null` when the server could not encode
 * it, so each one reads through a fallback instead of assuming a string.
 *
 * @param items The abilities, which supply the provider and category options.
 * @return The fields.
 */
export function getBuiltInFields( items: AbilityListItem[] ): AbilityField[] {
	const stateLabels = getAssistantStateLabels();
	const states: AssistantState[] = [ 'on', 'pending', 'off' ];

	return [
		{
			id: TITLE_FIELD_ID,
			type: 'text',
			label: __( 'Name', 'ai' ),
			enableHiding: false,
			enableSorting: true,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.name ?? '',
			render: ( { item } ) => <>{ item.name ?? item.slug }</>,
		},
		{
			id: 'slug',
			type: 'text',
			label: __( 'Slug', 'ai' ),
			enableSorting: true,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.slug,
			render: ( { item } ) => <code>{ item.slug }</code>,
		},
		{
			id: PROVIDER_FIELD_ID,
			type: 'text',
			label: __( 'Provider', 'ai' ),
			enableSorting: true,
			enableGlobalSearch: false,
			elements: getProviderElements( items ),
			// Single-select: the pre-filter applies one provider rule.
			filterBy: { operators: [ 'is' ], isPrimary: true },
			getValue: ( { item } ) => item.provider ?? '',
			render: ( { item } ) => {
				if ( null === item.provider ) {
					return <>—</>;
				}

				return (
					<ProviderBadge
						provider={ item.provider }
						label={ item.provider_label }
					/>
				);
			},
		},
		{
			// Filter-only: offered as a filter, never as a column.
			id: 'category',
			type: 'text',
			label: __( 'Category', 'ai' ),
			enableHiding: false,
			enableSorting: false,
			enableGlobalSearch: false,
			elements: getCategoryElements( items ),
			filterBy: { operators: [ 'is' ], isPrimary: true },
			getValue: ( { item } ) => item.category ?? '',
			render: ( { item } ) => <>{ item.category ?? '' }</>,
		},
		{
			id: 'surface',
			type: 'text',
			label: __( 'Exposed in', 'ai' ),
			enableSorting: false,
			enableGlobalSearch: false,
			elements: states.map( ( state ) => ( {
				value: state,
				label: stateLabels[ state ],
			} ) ),
			filterBy: { operators: [ 'isAny', 'isNone' ], isPrimary: true },
			getValue: ( { item } ) => getAssistantState( item ),
			render: ( { item } ) => <ExposedIn item={ item } />,
		},
		{
			// Hidden: it exists so search covers the description.
			id: 'description',
			type: 'text',
			label: __( 'Description', 'ai' ),
			enableHiding: false,
			enableSorting: false,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.description ?? '',
			// Never trimmed (#588).
			render: ( { item } ) => <>{ item.description ?? '' }</>,
		},
	];
}

/** The JavaScript filter other plugins use to add columns (#203). */
export const FIELDS_FILTER = 'ai.abilitiesExplorer.fields';

/**
 * The field properties an extension field keeps. Everything else, including
 * edit controls (`Edit`, `setValue`, `isValid`) and anything like `actions`,
 * is dropped, and every extension field is marked read-only.
 */
const EXTENSION_FIELD_KEYS = [
	'id',
	'type',
	'label',
	'header',
	'description',
	'render',
	'getValue',
	'getValueFormatted',
	'sort',
	'format',
	'elements',
	'filterBy',
	'enableSorting',
	'enableHiding',
	'enableGlobalSearch',
] as const;

const FIELD_TYPES: readonly string[] = [
	'text',
	'integer',
	'number',
	'datetime',
	'date',
	'time',
	'media',
	'boolean',
	'email',
	'password',
	'telephone',
	'color',
	'url',
	'array',
];

/**
 * Keeps one extension's cell from taking the whole table down when its render
 * callback throws. The cell renders empty instead.
 */
class FieldErrorBoundary extends Component<
	{ children: React.ReactNode },
	{ failed: boolean }
> {
	override state = { failed: false };

	static getDerivedStateFromError() {
		return { failed: true };
	}

	override render() {
		return this.state.failed ? null : this.props.children;
	}
}

/**
 * Wraps an extension callback so a throw returns a fallback instead of
 * breaking filtering, sorting or rendering for every row.
 *
 * @param callback The extension's callback.
 * @param fallback The value to return when it throws.
 * @return The guarded callback.
 */
function guard< Args extends unknown[], Result >(
	callback: ( ...args: Args ) => Result,
	fallback: Result
): ( ...args: Args ) => Result {
	return ( ...args: Args ) => {
		try {
			return callback( ...args );
		} catch {
			return fallback;
		}
	};
}

/** An extension field while it is being assembled. */
interface ExtensionField {
	[ key: string ]: unknown;
	id: string;
	label: string;
	readOnly: true;
	type?: string;
	elements?: unknown[];
	filterBy?: false | Record< string, unknown >;
	render?: ( props: object ) => React.ReactNode;
}

/**
 * Turns one filtered entry into a read-only extension field, or null when it
 * is not usable (no string ID, or a built-in ID).
 *
 * Only the properties in `EXTENSION_FIELD_KEYS` are kept, each checked for its
 * type. Callbacks are guarded so one extension cannot break the table.
 *
 * @param candidate The entry an extension returned.
 * @return The field, or null.
 */
function toExtensionField( candidate: unknown ): AbilityField | null {
	if ( ! isJsonRecord( candidate ) ) {
		return null;
	}

	const { id } = candidate;

	if (
		typeof id !== 'string' ||
		'' === id ||
		BUILT_IN_FIELD_IDS.includes( id )
	) {
		return null;
	}

	const picked: Record< string, unknown > = {};

	for ( const key of EXTENSION_FIELD_KEYS ) {
		if ( undefined !== candidate[ key ] ) {
			picked[ key ] = candidate[ key ];
		}
	}

	const {
		type,
		label,
		render,
		getValue,
		getValueFormatted,
		sort,
		elements,
		filterBy,
		enableSorting,
		enableHiding,
		enableGlobalSearch,
		...rest
	} = picked;

	const field: ExtensionField = {
		// `header`, `description` and `format` are passed through as given.
		...rest,
		id,
		label: typeof label === 'string' ? label : id,
		readOnly: true,
	};

	if ( typeof type === 'string' && FIELD_TYPES.includes( type ) ) {
		field.type = type;
	}

	const flags = { enableSorting, enableHiding, enableGlobalSearch };

	for ( const [ key, value ] of Object.entries( flags ) ) {
		if ( typeof value === 'boolean' ) {
			field[ key ] = value;
		}
	}

	if ( Array.isArray( elements ) ) {
		field.elements = elements;
	}

	if ( false === filterBy || isJsonRecord( filterBy ) ) {
		field.filterBy = filterBy;
	}

	const callbacks = { getValue, getValueFormatted, sort };

	for ( const [ key, callback ] of Object.entries( callbacks ) ) {
		if ( typeof callback === 'function' ) {
			field[ key ] = guard(
				callback as ( ...args: unknown[] ) => unknown,
				'sort' === key ? 0 : ''
			);
		}
	}

	if ( typeof render === 'function' ) {
		const Cell = render as ( props: object ) => React.ReactNode;

		field.render = ( props: object ) => (
			<FieldErrorBoundary>
				<Cell { ...props } />
			</FieldErrorBoundary>
		);
	}

	return field as unknown as AbilityField;
}

/**
 * Returns the built-in fields plus the fields added through the
 * `ai.abilitiesExplorer.fields` filter.
 *
 * The filter receives copies of the built-in fields and returns the full list.
 * Only entries with a new ID are taken from it: a built-in ID always keeps the
 * built-in field, and an ID used twice keeps its first entry. A filter that
 * throws or returns something other than an array leaves just the built-ins.
 *
 * @param items The abilities, which supply the built-in filter options.
 * @return The fields.
 */
export function getFields( items: AbilityListItem[] ): AbilityField[] {
	const builtIns = getBuiltInFields( items );
	let filtered: unknown;

	try {
		/**
		 * Filters the Abilities Explorer's table fields.
		 *
		 * Add DataViews fields to show extra, read-only columns. Built-in
		 * fields cannot be replaced or removed, and extension fields cannot
		 * carry edit controls or actions.
		 *
		 * @param {Field[]} fields The fields, built-ins first.
		 */
		filtered = applyFilters(
			FIELDS_FILTER,
			builtIns.map( ( field ) => ( { ...field } ) )
		);
	} catch {
		return builtIns;
	}

	if ( ! Array.isArray( filtered ) ) {
		return builtIns;
	}

	const seen = new Set< string >();
	const extensions: AbilityField[] = [];

	for ( const candidate of filtered ) {
		const field = toExtensionField( candidate );

		if ( field && ! seen.has( field.id ) ) {
			seen.add( field.id );
			extensions.push( field );
		}
	}

	return [ ...builtIns, ...extensions ];
}

/*
 * A version number that changes whenever a callback is added to or removed
 * from the fields filter, so a filter registered after the Explorer rendered
 * (a script that loads later) still shows its columns.
 */
let filterVersion = 0;
const listeners = new Set< () => void >();
let isWatching = false;

const onHookChange = ( hookName: string ): void => {
	if ( FIELDS_FILTER !== hookName ) {
		return;
	}

	filterVersion++;
	listeners.forEach( ( listener ) => listener() );
};

const subscribe = ( listener: () => void ): ( () => void ) => {
	if ( ! isWatching ) {
		isWatching = true;
		addAction( 'hookAdded', 'ai/abilities-explorer/fields', onHookChange );
		addAction(
			'hookRemoved',
			'ai/abilities-explorer/fields',
			onHookChange
		);
	}

	listeners.add( listener );

	return () => {
		listeners.delete( listener );
	};
};

const getFilterVersion = (): number => filterVersion;

/**
 * Returns the fields for the list, recomputed when the abilities change or a
 * fields filter is added or removed.
 *
 * @param items The abilities.
 * @return The fields.
 */
export function useFields( items: AbilityListItem[] ): AbilityField[] {
	const version = useSyncExternalStore( subscribe, getFilterVersion );

	// `version` is a dependency so a new filter recomputes the fields.
	// eslint-disable-next-line react-hooks/exhaustive-deps
	return useMemo( () => getFields( items ), [ items, version ] );
}
