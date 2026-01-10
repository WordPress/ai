/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-extraneous-dependencies
import { settings } from '@wordpress/icons';
import { dispatch } from '@wordpress/data';
import { store as bootStore } from '@wordpress/boot';

/**
 * Init function for the AI Experiments settings page.
 *
 * This function is called during page initialization before routes
 * register and rendering occurs. It updates the menu item icon.
 */
export async function init(): Promise< void > {
	dispatch( bootStore ).updateMenuItem( 'ai-experiments', {
		icon: settings,
	} );
}
