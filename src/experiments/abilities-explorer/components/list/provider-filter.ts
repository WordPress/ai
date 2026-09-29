/**
 * The provider filter rule from #883 (R2, KTD6).
 *
 * Choosing Core, Plugin or Theme matches the ability's origin, so an ability
 * that carries a custom provider label still appears under the bucket the
 * statistics count it in. Choosing a custom provider label matches that exact
 * label. One DataViews `is` filter cannot express "origin for known providers,
 * exact label otherwise", so this runs before `filterSortAndPaginate()`.
 */

/**
 * WordPress dependencies
 */
import type { View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import type { AbilityListItem, AbilityOrigin } from '../../types';

export const PROVIDER_FIELD_ID = 'provider';

export const KNOWN_ORIGINS: readonly AbilityOrigin[] = [
	'Core',
	'Plugin',
	'Theme',
];

const isKnownOrigin = ( value: string ): value is AbilityOrigin =>
	( KNOWN_ORIGINS as readonly string[] ).includes( value );

/**
 * Applies the view's provider filter and consumes it.
 *
 * The returned view has no provider entry left in `filters`: DataViews 19
 * reapplies every `view.filters` entry with the field type's built-in `is`
 * handler, which would compare the raw provider and drop every ability whose
 * custom label differs from the chosen origin. The view given to `<DataViews>`
 * keeps the entry, so its chip still shows.
 *
 * @param items The abilities.
 * @param view  The current view.
 * @return The matching abilities, and the view to hand `filterSortAndPaginate()`.
 */
export function applyProviderFilter(
	items: AbilityListItem[],
	view: View
): { items: AbilityListItem[]; view: View } {
	const filters = view.filters ?? [];
	const entries = filters.filter(
		( filter ) => filter.field === PROVIDER_FIELD_ID
	);

	if ( 0 === entries.length ) {
		return { items, view };
	}

	const consumed: View = {
		...view,
		filters: filters.filter(
			( filter ) => filter.field !== PROVIDER_FIELD_ID
		),
	};

	let matching = items;

	for ( const entry of entries ) {
		// A chip that is open but has no value yet does not filter.
		if ( 'is' !== entry.operator || typeof entry.value !== 'string' ) {
			continue;
		}

		const chosen: string = entry.value;

		if ( '' === chosen ) {
			continue;
		}

		matching = matching.filter( ( item ) =>
			isKnownOrigin( chosen )
				? item.origin === chosen
				: item.provider === chosen
		);
	}

	return { items: matching, view: consumed };
}
