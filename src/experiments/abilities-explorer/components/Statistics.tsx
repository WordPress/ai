/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AbilityListItem, AbilityOrigin } from '../types';

/**
 * The statistics row: Total, Core, Plugins and Theme (R4).
 *
 * Counted in the browser from each ability's origin (KTD14), so an ability
 * with a custom provider label is still counted under its bucket, the counts
 * stay current after every refresh, and search and filters never change them:
 * the caller passes every ability, not the filtered rows.
 *
 * @param props       Component props.
 * @param props.items Every registered ability.
 * @return The statistics row.
 */
export default function Statistics( { items }: { items: AbilityListItem[] } ) {
	const counts = useMemo( () => {
		const byOrigin: Record< AbilityOrigin, number > = {
			Core: 0,
			Plugin: 0,
			Theme: 0,
		};

		for ( const item of items ) {
			if ( item.origin in byOrigin ) {
				byOrigin[ item.origin ] += 1;
			}
		}

		return byOrigin;
	}, [ items ] );

	const cards = [
		{
			key: 'total',
			value: items.length,
			label: __( 'Total Abilities', 'ai' ),
		},
		{ key: 'core', value: counts.Core, label: __( 'Core', 'ai' ) },
		{ key: 'plugin', value: counts.Plugin, label: __( 'Plugins', 'ai' ) },
		{ key: 'theme', value: counts.Theme, label: __( 'Theme', 'ai' ) },
	];

	return (
		<ul
			className="ability-explorer-stats ai-abilities-explorer__stats"
			aria-label={ __( 'Ability statistics', 'ai' ) }
		>
			{ cards.map( ( card ) => (
				<li
					key={ card.key }
					className="ability-stat-card"
					data-stat={ card.key }
				>
					<div className="ability-stat-number">{ card.value }</div>
					<div className="ability-stat-label">{ card.label }</div>
				</li>
			) ) }
		</ul>
	);
}
