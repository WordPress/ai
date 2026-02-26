/**
 * Collection of block utilities.
 */

/**
 * Internal dependencies
 */
import { stripHtml } from './text';

/**
 * Minimal block shape for text extraction and flattening.
 */
export interface BlockWithContent {
	name: string;
	attributes: {
		content?: string;
		value?: string;
		alt?: string;
		caption?: string;
		[ key: string ]: unknown;
	};
	innerBlocks?: BlockWithContent[];
}

/**
 * Extracts plain text content from a block's attributes.
 *
 * @param {BlockWithContent} block The block to extract text from.
 * @return {string} The plain text content of the block.
 */
export function getBlockText( block: BlockWithContent ): string {
	const attrs = block.attributes;

	switch ( block.name ) {
		case 'core/image':
			return [ attrs.alt ?? '', attrs.caption ?? '' ]
				.filter( Boolean )
				.join( ' ' );

		case 'core/table':
			// Tables don't have a simple text field; return empty to trigger
			// the general HTML content path.
			return '';

		default:
			// Most text blocks use `content` or `value`.
			const html = ( attrs.content ?? attrs.value ?? '' ) as string;
			return stripHtml( html );
	}
}

/**
 * Recursively flattens a block tree into a flat array.
 *
 * @template T Block type extending BlockWithContent.
 * @param {T[]} blocks The top-level blocks array.
 * @return {T[]} A flat array of all blocks including inner blocks.
 */
export function flattenBlocks< T extends BlockWithContent >(
	blocks: T[]
): T[] {
	return blocks.reduce< T[] >( ( acc, block ) => {
		acc.push( block );
		if ( block.innerBlocks?.length ) {
			acc.push( ...flattenBlocks( block.innerBlocks as T[] ) );
		}
		return acc;
	}, [] );
}
