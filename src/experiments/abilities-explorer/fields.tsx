/**
 * The list's DataViews fields, built-in and from the `ai.abilitiesExplorer.fields` filter (#203).
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
import NameCell from './components/list/NameCell';
import {
	KNOWN_ORIGINS,
	PROVIDER_FIELD_ID,
} from './components/list/provider-filter';
import ProviderBadge, { getProviderLabel } from './components/ProviderBadge';
import type { AbilityListItem } from './types';
import { isJsonRecord } from './validate';

export type AbilityField = Field< AbilityListItem >;

export const TITLE_FIELD_ID = 'name';

/** The built-in columns shown when there is no saved view. */
export const DEFAULT_VISIBLE_FIELDS: readonly string[] = [
	'description',
	'slug',
	PROVIDER_FIELD_ID,
	'description',
];

/** Every built-in field ID. These win over extension fields. */
export const BUILT_IN_FIELD_IDS: readonly string[] = [
	TITLE_FIELD_ID,
	'description',
	'slug',
	PROVIDER_FIELD_ID,
	'category',
];

/**
 * Builds the provider filter options: the known origins, then custom labels.
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
 * Builds the category filter options.
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
 * @param items The abilities.
 * @return The fields.
 */
export function getBuiltInFields( items: AbilityListItem[] ): AbilityField[] {
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
			// The component itself, not a wrapper, so a focused row action keeps focus.
			render: NameCell,
		},
		{
			id: 'description',
			type: 'text',
			label: __( 'Description', 'ai' ),
			enableSorting: false,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.description ?? '',
			render: ( { item } ) => (
				<span className="ai-abilities-explorer__description">
					{ item.description ?? '' }
				</span>
			),
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
			// Filter-only.
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
	];
}

/** The JavaScript filter other plugins use to add columns (#203). */
export const FIELDS_FILTER = 'ai.abilitiesExplorer.fields';

/** The properties an extension field keeps; extension fields are read-only. */
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
 * Renders an extension's cell empty when it throws.
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
 * Wraps an extension callback so a throw returns a fallback.
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
 * Turns one filtered entry into a read-only extension field, or null.
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
 * Returns the built-in fields plus those added through the fields filter.
 *
 * @param items The abilities, which supply the built-in filter options.
 * @return The fields.
 */
export function getFields( items: AbilityListItem[] ): AbilityField[] {
	const builtIns = getBuiltInFields( items );
	let filtered: unknown;

	try {
		/**
		 * Filters the Abilities Explorer's table fields. Added fields are
		 * read-only, and built-in fields cannot be replaced.
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

// Bumped when the fields filter changes, so a late-loading filter still shows its columns.
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
 * Returns the fields, recomputed when the abilities or the fields filter change.
 *
 * @param items The abilities.
 * @return The fields.
 */
export function useFields( items: AbilityListItem[] ): AbilityField[] {
	const version = useSyncExternalStore( subscribe, getFilterVersion );

	// eslint-disable-next-line react-hooks/exhaustive-deps
	return useMemo( () => getFields( items ), [ items, version ] );
}
