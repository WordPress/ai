/**
 * Internal dependencies
 */
import type { TableCaptionSettings } from './types';

/**
 * Reads whether the experiment is enabled.
 *
 * @return Resolved experiment settings.
 */
export function getSettings(): TableCaptionSettings {
	const data = window.aiTableCaptionGenerationData ?? {};
	return {
		enabled: data.enabled === true || data.enabled === '1',
	};
}
