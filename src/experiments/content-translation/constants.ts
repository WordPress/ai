/**
 * A default minimum content length for enabling content translation.
 */
export const TRANSLATION_MINIMUM_CONTENT_COUNT_DEFAULT = 5;

/**
 * Notice ID for the content translation error notice.
 */
export const TRANSLATION_NOTICE_ID = 'ai_content_translation';

/**
 * Batch size for content translation.
 */
export const TRANSLATION_BATCH_SIZE = 4;

/**
 * Supported block types for content translation.
 *
 * Limited to blocks whose primary text lives in a single RichText attribute
 * named `content` or `value` (see `getEditableTextAttribute()`), the same
 * attributes `getBlockHTML()` already knows how to read. Container blocks
 * such as `core/quote` and `core/list` need no entry here: their text lives
 * in child blocks (`core/paragraph`, `core/list-item`), which are reached
 * separately because `flattenBlocks()` walks into `innerBlocks`.
 *
 * Deliberately excluded:
 * - `core/pullquote`'s and `core/quote`'s `citation` attribute, and
 *   `core/details`'s `summary` attribute: secondary text the write-back
 *   path (keyed on one attribute per block) does not target.
 * - `core/code`: source code, not prose.
 * - `core/image` and `core/table`: their text isn't a single RichText
 *   attribute, so they need dedicated handling this experiment doesn't have.
 */
export const TRANSLATION_SUPPORTED_BLOCK_TYPES = [
	'core/paragraph',
	'core/heading',
	'core/list-item',
	'core/verse',
	'core/preformatted',
	'core/pullquote',
];

/**
 * Loading classes for the content translation process.
 */
export const TRANSLATION_LOADING_CLASSES = {
	TITLE: 'ai-content-translation--is-title-loading',
	BLOCKS: 'ai-content-translation--is-blocks-loading',
} as const;
