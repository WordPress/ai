/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import RouterLink, { BackToListLink } from './RouterLink';
import type { AbilityDetailItem } from '../types';

export interface TestRunnerProps {
	/**
	 * The ability, loaded from the item route by the app shell. The shell
	 * keys this component by ability name, so its state starts fresh for each
	 * ability.
	 */
	item: AbilityDetailItem;
}

/**
 * The test runner for one ability.
 *
 * Placeholder until U5, which invokes through `invokeAbility()` in `../api`
 * and reports failures through `useExplorer().reportError()`.
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The view.
 */
export default function TestRunner( { item }: TestRunnerProps ) {
	return (
		<div className="ai-abilities-explorer__runner">
			<div className="ai-abilities-explorer__view-actions">
				<BackToListLink />
				<RouterLink
					route={ { view: 'detail', ability: item.slug } }
					variant="secondary"
				>
					{ __( 'View Details', 'ai' ) }
				</RouterLink>
			</div>
			<h2>
				{ __( 'Test Ability:', 'ai' ) } { item.name ?? item.slug }
			</h2>
			<p>
				<code>{ item.slug }</code>
			</p>
		</div>
	);
}
