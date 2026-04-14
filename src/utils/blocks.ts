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

interface BlockWithClientId extends BlockWithContent {
	clientId: string;
	innerBlocks?: BlockWithClientId[];
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

	const serializedBlock = serialize( [ block ] );
	if ( ! serializedBlock || ! content.includes( serializedBlock ) ) {
		return content;
	}

	// Resolve which duplicate instance this clientId corresponds to and only
	// replace that occurrence in the serialized post content.
	// eslint-disable-next-line dot-notation -- getBlocks from store index signature
	const rootBlocks = select( blockEditorStore )[ 'getBlocks' ]();
	const flatBlocks = flattenBlocks(
		( rootBlocks ?? [] ) as BlockWithClientId[]
	);

	let targetOccurrence = 1;
	let matchCount = 0;

	for ( const flatBlock of flatBlocks ) {
		// eslint-disable-next-line @typescript-eslint/no-unsafe-argument -- block shape comes from editor store
		const flatSerialized = serialize( flatBlock as any );
		if ( flatSerialized !== serializedBlock ) {
			continue;
		}

		matchCount += 1;
		if ( flatBlock.clientId === clientId ) {
			targetOccurrence = matchCount;
			break;
		}
	}

	let occurrence = 0;
	let fromIndex = 0;

	while ( true ) {
		const index = content.indexOf( serializedBlock, fromIndex );
		if ( index === -1 ) {
			return content;
		}

		occurrence += 1;
		if ( occurrence === targetOccurrence ) {
			return (
				content.slice( 0, index ) +
				placeholder +
				content.slice( index + serializedBlock.length )
			);
		}

		fromIndex = index + serializedBlock.length;
	}
}
