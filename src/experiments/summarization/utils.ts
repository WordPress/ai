/**
 * Shared utilities for creating and identifying the AI-generated summary block.
 *
 * Both the block editor (useSummaryGeneration) and the bulk admin script
 * import from here, so any markup change only needs to happen in one place.
 */

/**
 * WordPress dependencies
 */
import type { Block } from '@wordpress/blocks';
import { parse } from '@wordpress/blocks';

/**
 * Creates the inner paragraph blocks for a summary string.
 *
 * @since x.x.x
 *
 * @param summary  Plain-text summary from the AI.
 * @param asString When true, returns serialized block markup. When false, returns an array of Block objects.
 * @return Serialized inner blocks or an array of Block objects for the summary group block.
 */
export function createSummaryInnerBlocks(
	summary: string,
	asString: true
): string;
export function createSummaryInnerBlocks(
	summary: string,
	asString: false
): Block< Record< string, unknown > >[];
export function createSummaryInnerBlocks(
	summary: string,
	asString: boolean
): string | Block< Record< string, unknown > >[] {
	const paragraphs = summary.split( /\n\n+/ ).filter( ( p ) => p.trim() );
	const innerBlocks = paragraphs
		.map(
			( text ) =>
				`<!-- wp:paragraph -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`
		)
		.join( '\n\n' );
	return asString ? innerBlocks : parse( innerBlocks );
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
 * @param summary  Plain-text summary from the AI.
 * @param asString When true, returns serialized block markup. When false, returns a parsed Block object.
 * @return Serialized summary block or Block object of the summary.
 */
export function createSummaryBlock( summary: string, asString: true ): string;
export function createSummaryBlock(
	summary: string,
	asString: false
): Block< Record< string, unknown > >;
export function createSummaryBlock(
	summary: string,
	asString: boolean
): string | Block< Record< string, unknown > > {
	const innerBlocks = createSummaryInnerBlocks( summary, true );

	const summaryBlockSerialized =
		`<!-- wp:group {"className":"ai-summarization-summary","aiGeneratedSummary":true} -->\n` +
		`<div class="wp-block-group ai-summarization-summary">` +
		innerBlocks +
		`</div>\n` +
		`<!-- /wp:group -->`;

	return asString
		? summaryBlockSerialized
		: parse( summaryBlockSerialized )[ 0 ]!;
}
