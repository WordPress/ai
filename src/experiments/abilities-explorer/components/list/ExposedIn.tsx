/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AbilityListItem } from '../../types';
import {
	getWorkspaceState,
	getWorkspaceStateLabels,
	type WorkspaceState,
} from '../../workspace';

const STATE_ICONS: Record< WorkspaceState, string > = {
	used: 'dashicons-yes-alt',
	removed: 'dashicons-minus',
};

/**
 * Renders the "Exposed in" cell.
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The cell.
 */
export default function ExposedIn( { item }: { item: AbilityListItem } ) {
	const state = getWorkspaceState( item );

	return (
		<div className="ai-abilities-explorer__exposed-in">
			<span className="ability-surface-badges">
				{ item.show_in_rest && (
					<span className="ability-surface-badge">
						{ __( 'REST', 'ai' ) }
					</span>
				) }
				{ item.show_in_mcp && (
					<span className="ability-surface-badge">
						{ __( 'MCP', 'ai' ) }
					</span>
				) }
				{ null !== state && (
					<span
						className={ `ability-surface-badge ability-surface ability-surface-${ state }` }
					>
						<span
							className={ `dashicons ${ STATE_ICONS[ state ] }` }
							aria-hidden="true"
						/>
						{ getWorkspaceStateLabels()[ state ] }
					</span>
				) }
			</span>
			{ 'used' === state && (
				// Not a tooltip: the exact model-facing text is long and must be selectable.
				<details className="ability-surface-details">
					<summary>
						{ __( 'Description AI Workspace sees', 'ai' ) }
					</summary>
					<p className="description ability-surface-description">
						{ item.description ?? '' }
					</p>
				</details>
			) }
		</div>
	);
}
