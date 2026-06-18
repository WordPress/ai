/**
 * A default minimum content length for enabling content translation.
 */
export const TRANSLATION_MINIMUM_CONTENT_COUNT_DEFAULT = 15;

/**
 * Notice ID for the content translation error notice.
 */
export const TRANSLATION_NOTICE_ID = 'ai_content_translation_error';

/**
 * Batch size for content translation.
 */
export const TRANSLATION_BATCH_SIZE = 4;

/**
 * Supported block types for content translation.
 */
export const TRANSLATION_SUPPORTED_BLOCK_TYPES = [
	'core/paragraph',
	'core/heading',
];
