/**
 * A default minimum content length for enabling content translation.
 */
export const TRANSLATION_MINIMUM_CONTENT_COUNT_DEFAULT = 1;

/**
 * Notice ID for the content translation error notice.
 */
export const TRANSLATION_NOTICE_ID = 'ai_content_translation';

/**
 * Batch size for content translation.
 */
export const TRANSLATION_BATCH_SIZE = 4;

/**
 * Block types excluded from content translation.
 *
 * Every other block's rich text attributes are translated (see
 * `getTranslatableSegments()`). Code is excluded because it is source code,
 * not prose.
 */
export const TRANSLATION_EXCLUDED_BLOCK_TYPES = [ 'core/code' ];

/**
 * Loading classes for the content translation process.
 */
export const TRANSLATION_LOADING_CLASSES = {
	TITLE: 'ai-content-translation--is-title-loading',
	BLOCKS: 'ai-content-translation--is-blocks-loading',
} as const;
