/**
 * WordPress dependencies
 */
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import RouterLink from './RouterLink';
import type { AbilityListItem, PolicyState, SurfaceResponse } from '../types';

export interface ListViewProps {
	/** Every registered ability, as last loaded. Kept on a failed refresh. */
	items: AbilityListItem[];
	/** The assistant admission policy, or null before the first load. */
	policy: PolicyState | null;
	/** True while the first load or a refresh is in flight. */
	isLoading: boolean;
	/**
	 * Applies a surface route response to the list (KTD8). Returns false when
	 * the response is stale because a newer one has already been applied.
	 */
	onSurfaceResponse: ( response: SurfaceResponse ) => boolean;
}

/**
 * The ability list.
 *
 * Placeholder until U3 renders the DataViews table. Errors go through
 * `useExplorer().reportError()`, and navigation through `RouterLink`.
 *
 * @param props           Component props.
 * @param props.items     The abilities.
 * @param props.isLoading Whether a load is in flight.
 * @return The view.
 */
export default function ListView( { items, isLoading }: ListViewProps ) {
	if ( isLoading && items.length === 0 ) {
		return <Spinner />;
	}

	return (
		<ul className="ai-abilities-explorer__list-placeholder">
			{ items.map( ( item ) => (
				<li key={ item.slug }>
					<RouterLink
						route={ { view: 'detail', ability: item.slug } }
					>
						{ item.name ?? item.slug }
					</RouterLink>
				</li>
			) ) }
		</ul>
	);
}
