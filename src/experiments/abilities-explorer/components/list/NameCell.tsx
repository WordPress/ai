/**
 * The Name cell: the ability name, linked to its detail view, with the row
 * actions under it, as in `WP_List_Table`.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AbilityListItem } from '../../types';
import RouterLink from '../RouterLink';

/**
 * The " | " between two row actions. Visual only, as in core.
 *
 * @return The separator.
 */
function Separator() {
	return (
		<span className="ai-abilities-explorer__row-actions-sep" aria-hidden>
			{ ' | ' }
		</span>
	);
}

/**
 * Renders an ability's name and its row actions: "View" and "Test".
 *
 * As in `WP_List_Table`, the row actions show when the row is hovered or
 * holds keyboard focus, and always on small screens (see `index.scss`).
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The cell content.
 */
export default function NameCell( { item }: { item: AbilityListItem } ) {
	// One block, so the actions sit under the name in DataViews' flex row.
	return (
		<div className="ai-abilities-explorer__name-cell">
			<RouterLink
				route={ { view: 'detail', ability: item.slug } }
				className="ai-abilities-explorer__name"
			>
				{ item.name ?? item.slug }
			</RouterLink>
			<div className="ai-abilities-explorer__row-actions">
				<RouterLink route={ { view: 'detail', ability: item.slug } }>
					{ __( 'View', 'ai' ) }
				</RouterLink>
				<Separator />
				<RouterLink route={ { view: 'runner', ability: item.slug } }>
					{ __( 'Test', 'ai' ) }
				</RouterLink>
			</div>
		</div>
	);
}
