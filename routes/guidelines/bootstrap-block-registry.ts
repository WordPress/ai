/**
 * WordPress dependencies
 */
import { registerCoreBlocks } from '@wordpress/block-library';
import { store as blocksStore } from '@wordpress/blocks';
import { dispatch } from '@wordpress/data';

let bootstrapped = false;

/**
 * Bootstraps the block registry with Core blocks only.
 *
 * Used on the Guidelines admin page so block names and icons are available
 * without loading the full block editor.
 */
export function bootstrapBlockRegistry(): void {
	if ( bootstrapped ) {
		return;
	}
	bootstrapped = true;

	dispatch( blocksStore ).reapplyBlockTypeFilters();
	registerCoreBlocks();
}
