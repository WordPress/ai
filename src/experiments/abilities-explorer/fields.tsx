/**
 * The list's built-in DataViews fields.
 *
 * `getBuiltInFields()` is the one place the built-in columns and filters are
 * defined, so the #203 extension point can wrap it without restating them.
 */

/**
 * WordPress dependencies
 */
import type { Field } from '@wordpress/dataviews/wp';
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
import { getSettings } from './settings';
import type { AbilityListItem } from './types';

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
 * Turns a provider into the class suffix the provider pill has always used.
 *
 * @param provider The provider.
 * @return The class suffix.
 */
const toClassSuffix = ( provider: string ): string =>
	provider
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );

/**
 * Returns the translated label for a provider.
 *
 * @param provider The provider.
 * @return The label.
 */
const getProviderLabel = ( provider: string ): string =>
	getSettings().providerLabels[ provider ] ?? provider;

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
 * with `&` in it reads as `&`, not `&amp;` (R3).
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
 * it (R16), so each one reads through a fallback instead of assuming a string.
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
			// Single-select: the pre-filter applies one provider rule (KTD6).
			filterBy: { operators: [ 'is' ], isPrimary: true },
			getValue: ( { item } ) => item.provider ?? '',
			render: ( { item } ) => {
				if ( null === item.provider ) {
					return <>—</>;
				}

				return (
					<span
						className={ `ability-provider ability-provider-${ toClassSuffix(
							item.provider
						) }` }
					>
						{ item.provider_label ??
							getProviderLabel( item.provider ) }
					</span>
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
			// Hidden: it exists so search covers the description (R1).
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
