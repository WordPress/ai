/**
 * Localized settings access for the slug generation experiment.
 */

/**
 * Internal dependencies
 */
import type { SlugGenerationData } from './types';

const MINIMUM_CONTENT_COUNT_DEFAULT = 250;
const NUMBER_OF_SUGGESTIONS_DEFAULT = 3;

/**
 * Coerces a localized value to a positive integer.
 *
 * `wp_localize_script()` casts every scalar to a string, so numeric settings arrive
 * as e.g. `"3"`. They are coerced here because the ability's input schema rejects
 * anything that is not an integer.
 *
 * @param value    The localized value.
 * @param fallback The value to use when the input is missing or unusable.
 * @return The coerced integer.
 */
function toPositiveInt( value: unknown, fallback: number ): number {
	const parsed = Number( value );

	return Number.isFinite( parsed ) && parsed > 0
		? Math.floor( parsed )
		: fallback;
}

/**
 * Reads the settings localized from PHP onto the global window object.
 *
 * Mirrors the defaults used by the `Slug_Generation` experiment so the UI stays
 * usable if the localized data is missing or partial.
 *
 * @return The resolved slug generation settings.
 */
export function getSettings(): SlugGenerationData {
	const settings = window.aiSlugGenerationData ?? {};

	return {
		// A disabled experiment localizes as an empty string, an enabled one as "1".
		enabled: Boolean( settings.enabled ),
		minContentLength: toPositiveInt(
			settings.minContentLength,
			MINIMUM_CONTENT_COUNT_DEFAULT
		),
		numberOfSuggestions: toPositiveInt(
			settings.numberOfSuggestions,
			NUMBER_OF_SUGGESTIONS_DEFAULT
		),
	};
}
