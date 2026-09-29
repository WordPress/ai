/**
 * The Name cell: the ability name, linked to its detail view, with the row
 * actions under it, as in `WP_List_Table`.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AbilityListItem } from '../../types';
import RouterLink from '../RouterLink';
import { useRowSurfaceAction } from '../SurfaceActions';

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
 * Renders an ability's name and its row actions: "View", "Test", and the
 * surface action when the row offers one.
 *
 * The row actions are always visible, not revealed on hover. That matches
 * `WP_List_Table` on touch screens, and keeps them discoverable by keyboard
 * and by sight.
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The cell content.
 */
export default function NameCell( { item }: { item: AbilityListItem } ) {
	const surfaceAction = useRowSurfaceAction( item );

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
				{ surfaceAction && (
					<>
						<Separator />
						{ /*
						 * The same button whatever its label, so focus stays on
						 * it when the label flips. Disabled while pending, but
						 * still focusable.
						 */ }
						<Button
							variant="link"
							disabled={ surfaceAction.disabled }
							accessibleWhenDisabled
							onClick={ surfaceAction.onActivate }
						>
							{ surfaceAction.label }
						</Button>
					</>
				) }
			</div>
		</div>
	);
}
