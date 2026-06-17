/**
 * Internal dependencies
 */
import type { AIContentTranslationData } from './types';

declare global {
	interface Window {
		aiContentTranslationData: AIContentTranslationData;
	}
}
