/**
 * Settings normalization for type-ahead.
 */

/**
 * Internal dependencies
 */
import type { TypeAheadLocalizedData, TypeAheadSettings } from '../types';

const DEFAULT_MAX_WORDS = 20;
const MIN_MAX_WORDS = 1;
const MAX_MAX_WORDS = 50;

/**
 * Normalizes the max words setting from localized script data.
 *
 * wp_localize_script() casts numeric values to strings, so this ensures
 * ability requests receive a proper integer.
 *
 * @param value Raw max words value from localized data.
 * @return Normalized max words value.
 */
export const normalizeMaxWords = ( value: unknown ): number => {
	const parsedValue = Number.parseInt(
		String( value ?? DEFAULT_MAX_WORDS ),
		10
	);

	if ( Number.isNaN( parsedValue ) ) {
		return DEFAULT_MAX_WORDS;
	}

	return Math.min( MAX_MAX_WORDS, Math.max( MIN_MAX_WORDS, parsedValue ) );
};

/**
 * Normalizes localized type-ahead settings for runtime use.
 *
 * @param raw Raw settings from window.aiTypeAheadData.
 * @return Normalized type-ahead settings.
 */
export const normalizeTypeAheadSettings = (
	raw: TypeAheadLocalizedData
): TypeAheadSettings => ( {
	enabled: Boolean( raw.enabled ),
	completionMode: raw.completionMode ?? 'smart',
	triggerDelay:
		Number.parseInt( String( raw.triggerDelay ?? 500 ), 10 ) || 500,
	confidence: Number( raw.confidence ?? 0.7 ) || 0.7,
	maxWords: normalizeMaxWords( raw.maxWords ),
	showHeadings: raw.showHeadings === true || raw.showHeadings === '1',
} );
