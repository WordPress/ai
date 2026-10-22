/**
 * Internal dependencies
 */
import type { LocalizedSettings } from './types';

/**
 * The settings `Admin_Page::enqueue_assets()` localizes for this screen.
 *
 * @return The localized settings.
 */
export function getSettings(): LocalizedSettings {
	const settings = window.aiAbilitiesExplorer;

	if ( ! settings ) {
		throw new Error( 'aiAbilitiesExplorer is not defined.' );
	}

	return settings;
}
