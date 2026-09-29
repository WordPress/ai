/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import RouterLink, { BackToListLink } from './RouterLink';
import type { AbilityDetailItem } from '../types';

export interface DetailViewProps {
	/** The ability, loaded from the item route by the app shell. */
	item: AbilityDetailItem;
}

/**
 * The detail view for one ability.
 *
 * Placeholder until U5. The app shell has already resolved the ability, so a
 * missing one never reaches this view.
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The view.
 */
export default function DetailView( { item }: DetailViewProps ) {
	return (
		<div className="ai-abilities-explorer__detail">
			<div className="ai-abilities-explorer__view-actions">
				<BackToListLink />
				<RouterLink
					route={ { view: 'runner', ability: item.slug } }
					variant="primary"
				>
					{ __( 'Test Ability', 'ai' ) }
				</RouterLink>
			</div>
			<h2>{ item.name ?? item.slug }</h2>
			<p>
				<code>{ item.slug }</code>
			</p>
		</div>
	);
}
