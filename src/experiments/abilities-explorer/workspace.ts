/**
 * Whether the AI Workspace uses an ability, and what the owner can change.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AbilityListItem, SurfaceChange } from './types';

/**
 * `used`: the Workspace offers it. `removed`: the owner took it away.
 */
export type WorkspaceState = 'used' | 'removed';

/**
 * Returns an ability's Workspace state, or null when the Workspace never uses it.
 *
 * @param item The ability.
 * @return The state, or null.
 */
export function getWorkspaceState(
	item: AbilityListItem
): WorkspaceState | null {
	if ( item.owner_excluded ) {
		return 'removed';
	}

	return item.conversational_surface ? 'used' : null;
}

/**
 * The label for each Workspace state, as the column and the filter show it.
 *
 * @return The labels keyed by state.
 */
export function getWorkspaceStateLabels(): Record< WorkspaceState, string > {
	return {
		used: __( 'Used by AI Workspace', 'ai' ),
		removed: __( 'Removed from AI Workspace', 'ai' ),
	};
}

/**
 * Returns the change a row offers, or null when it offers none.
 *
 * @param item The ability.
 * @return The change, or null.
 */
export function getWorkspaceChange(
	item: AbilityListItem
): SurfaceChange | null {
	switch ( getWorkspaceState( item ) ) {
		case 'used':
			return 'remove';
		case 'removed':
			return 'restore';
		default:
			return null;
	}
}
