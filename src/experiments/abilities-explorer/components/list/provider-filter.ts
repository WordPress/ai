/**
 * The provider filter (#883): Core, Plugin and Theme match the origin, any other
 * value matches the exact label.
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
 * Applies the view's provider filter and removes it from the returned view, so
 * DataViews' built-in `is` handler does not filter the raw provider again.
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
