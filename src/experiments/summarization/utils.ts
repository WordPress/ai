/**
 * Shared utilities for creating and identifying the AI-generated summary block.
 *
 * Both the block editor (useSummaryGeneration) and the bulk admin script
 * import from here, so any markup change only needs to happen in one place.
 */

/**
 * WordPress dependencies
 */
import { type Block, createBlock } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';

/**
 * Registers the `aiGeneratedSummary` attribute on `core/group`.
 *
 * Must run before `core/group` itself is registered (i.e. before
 * `registerBlockType()`/`registerCoreBlocks()` executes), otherwise the
 * attribute is missing from the block's schema and gets stripped whenever
 * the block is parsed or serialized.
 *
 * @since x.x.x
 */
export function registerSummaryBlockAttribute(): void {
	addFilter(
		'blocks.registerBlockType',
		'ai/summarization-attribute',
		( settings, name ) => {
			if ( name !== 'core/group' ) {
				return settings;
			}

			return {
				...settings,
				attributes: {
					...settings.attributes,
					aiGeneratedSummary: {
						type: 'boolean',
						default: false,
					},
				},
			};
		}
	);
}

/**
 * Creates the inner paragraph blocks for a summary string.
 *
 * @since x.x.x
 *
 * @param summary Plain-text summary from the AI.
 * @return Array of Block objects for the summary group block.
 */
export function createSummaryInnerBlocks( summary: string ): Block[] {
	return summary
		.split( /\n\n+/ )
		.map( ( paragraph ) => paragraph.trim() )
		.filter( Boolean )
		.map( ( text ) => createBlock( 'core/paragraph', { content: text } ) );
}

/**
 * Finds the AI-generated summary group block within a list of blocks.
 *
 * @since x.x.x
 *
 * @param blocks List of blocks to search.
 * @return The AI-generated summary group block, or undefined if not found.
 */
export function findSummaryBlock(
	blocks: Block< Record< string, unknown > >[]
): Block< Record< string, unknown > > | undefined {
	return blocks.find(
		( block ) =>
			block.name === 'core/group' &&
			block.attributes[ 'aiGeneratedSummary' ] === true // eslint-disable-line dot-notation
	);
}

/**
 * Creates a full AI-generated summary group block from a summary string.
 *
 * @since x.x.x
 *
 * @param summary Plain-text summary from the AI.
 * @return Block object of the summary.
 */
export function createSummaryBlock( summary: string ): Block {
	const innerBlocks = createSummaryInnerBlocks( summary );

	return createBlock(
		'core/group',
		{
			className: 'ai-summarization-summary',
			aiGeneratedSummary: true,
		},
		innerBlocks
	);
}
