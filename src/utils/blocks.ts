/**
 * Collection of block utilities.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { select } from '@wordpress/data';
/* eslint-disable import/no-extraneous-dependencies -- @wordpress/blocks is in dependencies; types are in devDependencies */
import { serialize } from '@wordpress/blocks';

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
			return ( attrs.content ?? attrs.value ?? '' ) as string;
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

/**
 * Replaces a block with a placeholder in the content.
 *
 * @param {string} content     The content to replace the block in.
 * @param {string} clientId    The client ID of the block to replace.
 * @param {string} placeholder The placeholder to replace the block with.
 * @return {string} The content with the block replaced by the placeholder.
 */
export function replaceBlockWithPlaceholder(
	content: string,
	clientId: string,
	placeholder: string
): string {
	// eslint-disable-next-line dot-notation -- getBlock from store index signature
	const block = select( blockEditorStore )[ 'getBlock' ]( clientId );
	if ( ! block ) {
		return content;
	}

	const serializedBlock = serialize( block );
	if ( ! serializedBlock || ! content.includes( serializedBlock ) ) {
		return content;
	}

	return content.replace( serializedBlock, placeholder );
}
