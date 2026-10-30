/**
 * WordPress dependencies
 */
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { hasBlockSupport } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { hasMinimumContent } from '../../../utils/character-count';
import { getBlockText } from '../../../utils/blocks';
import { runAbility } from '../../../utils/run-ability';
import { BLOCK_CONTAINER_LABEL_GENERATION_MINIMUM_CONTENT_LENGTH_DEFAULT } from '../constants';
import type { AIBlockContainerLabelGenerationData, Block } from '../types';

/**
 * Retrieves the block container label generation settings from the global window object.
 *
 * @return The block container label generation settings.
 */
export const getSettings = (): AIBlockContainerLabelGenerationData => {
	const settings: Partial< AIBlockContainerLabelGenerationData > =
		window?.aiBlockContainerLabelGenerationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ??
			BLOCK_CONTAINER_LABEL_GENERATION_MINIMUM_CONTENT_LENGTH_DEFAULT,
	};
};

/**
 * Generates a label for a given block's content.
 *
 * @param content         The content to generate a label for.
 * @param postId          The ID of the post.
 * @param documentContext Optional structural outline of the entire document.
 * @return A promise that resolves to the generated label.
 */
export function generateLabel(
	content: string,
	postId: number,
	documentContext?: string
): Promise< string > {
	return runAbility< string >( 'ai/block-container-label-generation', {
		content,
		post_id: postId,
		...( documentContext && { document_context: documentContext } ),
	} );
}

/**
 * Combines content fragments into one normalized string.
 *
 * @param parts Content fragments.
 * @return Normalized content.
 */
function combineContent( parts: string[] ): string {
	return parts.filter( Boolean ).join( ' ' ).replace( /\s+/g, ' ' ).trim();
}

/**
 * Checks whether a block is a container block the user is allowed to label.
 *
 * A container block is any block with inner blocks. Blocks that opt out of
 * renaming and blocks that are not fully editable are present in the block
 * tree but must not be labeled.
 *
 * @param block The block to check.
 * @return True if the block is a labelable container block, false otherwise.
 */
function isLabelableContainer( block: Block ): boolean {
	if ( block.innerBlocks.length === 0 ) {
		return false;
	}

	if ( ! hasBlockSupport( block.name, 'renaming', true ) ) {
		return false;
	}

	// `getBlockEditingMode` is missing from the block editor store's type declarations.
	const { getBlockEditingMode } = select( blockEditorStore ) as unknown as {
		getBlockEditingMode: ( clientId: string ) => string;
	};

	return getBlockEditingMode( block.clientId ) === 'default';
}

/**
 * Recursively checks whether the given blocks contain at least one labelable container block.
 *
 * @param blocks The array of blocks to check.
 * @return True if at least one labelable container block exists, false otherwise.
 */
export function hasContainerBlocks( blocks: Block[] ): boolean {
	return blocks.some(
		( block ) =>
			isLabelableContainer( block ) ||
			hasContainerBlocks( block.innerBlocks )
	);
}

/**
 * Recursively collects readable content and eligible container blocks.
 *
 * Each returned candidate contains the combined content of the container and
 * all of its descendants. Candidates are returned in post-order: each
 * container's descendants precede it, and siblings keep document order.
 *
 * @param blocks           Blocks to traverse.
 * @param minContentLength Minimum content length required for generation.
 * @return Combined subtree content and eligible label candidates.
 */
export function collectLabelCandidates(
	blocks: Block[],
	minContentLength: number
): { content: string; candidates: { clientId: string; content: string }[] } {
	const contentParts: string[] = [];
	const candidates: { clientId: string; content: string }[] = [];

	for ( const block of blocks ) {
		// Collect content and eligible container blocks from the inner blocks.
		const childResult = collectLabelCandidates(
			block.innerBlocks,
			minContentLength
		);

		// Add child candidates before the current block so descendants precede their container.
		candidates.push( ...childResult.candidates );

		const blockContent = combineContent( [
			getBlockText( block ),
			childResult.content,
		] );

		if ( blockContent ) {
			contentParts.push( blockContent );
		}

		const existingLabel = block.attributes?.metadata?.name?.trim();

		if (
			isLabelableContainer( block ) &&
			! existingLabel &&
			hasMinimumContent( blockContent, minContentLength )
		) {
			candidates.push( {
				clientId: block.clientId,
				content: blockContent,
			} );
		}
	}

	return {
		content: combineContent( contentParts ),
		candidates,
	};
}

/**
 * Cache of subtree content keyed by block object.
 */
const subtreeContentCache = new WeakMap< Block, string >();

/**
 * Recursively extracts and combines text content for a single block and all its descendants.
 *
 * @param block The block to extract content from.
 * @return The combined text content.
 */
export function getBlockSubtreeContent( block: Block ): string {
	const cached = subtreeContentCache.get( block );
	if ( cached !== undefined ) {
		return cached;
	}

	const childParts = block.innerBlocks.map( ( child ) =>
		getBlockSubtreeContent( child )
	);
	const content = combineContent( [ getBlockText( block ), ...childParts ] );

	subtreeContentCache.set( block, content );

	return content;
}

/**
 * Programmatically updates the value of a React-controlled input element
 * and dispatches necessary events for React state listeners to pick up the change.
 *
 * @param {HTMLInputElement} input The input element to update.
 * @param {string}           value The new string value.
 */
export function setInputValue( input: HTMLInputElement, value: string ): void {
	const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	)?.set;

	if ( nativeInputValueSetter ) {
		nativeInputValueSetter.call( input, value );
	} else {
		input.value = value;
	}

	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

/**
 * Truncates a string to a maximum number of Unicode code points.
 *
 * @param text      The string to truncate.
 * @param maxLength The maximum number of code points to keep.
 * @return The truncated string.
 */
function truncateCodePoints( text: string, maxLength: number ): string {
	return Array.from( text ).slice( 0, maxLength ).join( '' );
}

/**
 * Builds a lightweight outline of the document to provide structural context.
 *
 * @param blocks         The top-level blocks in the editor.
 * @param postTitle      The title of the post.
 * @param targetClientId Client ID of the block currently being labeled.
 * @return Formatted document outline string.
 */
function getDocumentOutline(
	blocks: Block[],
	postTitle: string,
	targetClientId: string
): string {
	const lines: string[] = [];

	if ( postTitle.trim() ) {
		lines.push( `Title: ${ postTitle.trim() }` );
	}

	let sectionIndex = 1;

	const processBlocks = ( blockList: Block[] ) => {
		for ( const block of blockList ) {
			const isTarget = block.clientId === targetClientId;

			if ( isTarget || isLabelableContainer( block ) ) {
				const text = truncateCodePoints(
					getBlockSubtreeContent( block ),
					120
				).trim();

				if ( text || isTarget ) {
					const marker = isTarget ? ' (Target Block)' : '';
					const label = isTarget
						? ''
						: block.attributes?.metadata?.name?.trim();
					const labelInfo = label
						? ` [Current Label: ${ label }]`
						: '';

					lines.push(
						`Section ${ sectionIndex }${ marker }${ labelInfo }: ${ text }`
					);
					sectionIndex++;
				}
			}

			if ( block.innerBlocks?.length ) {
				processBlocks( block.innerBlocks );
			}
		}
	};

	processBlocks( blocks );

	return lines.join( '\n' );
}

/**
 * Builds the document context sent alongside a label request.
 *
 * @param targetClientId Client ID of the block currently being labeled.
 * @return Formatted document outline string.
 */
export function buildDocumentContext( targetClientId: string ): string {
	const postTitle =
		( select( editorStore ).getEditedPostAttribute( 'title' ) as
			| string
			| undefined ) ?? '';
	const blocks = select( blockEditorStore ).getBlocks() as Block[];

	return getDocumentOutline( blocks, postTitle, targetClientId );
}
