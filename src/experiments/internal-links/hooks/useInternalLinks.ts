/**
 * WordPress dependencies
 */
import { dispatch, select, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/character-count';

const NOTICE_ID = 'ai_internal_links_error';
const MINIMUM_CONTENT_COUNT_DEFAULT = 100;

export interface LinkSuggestion {
	anchor_text: string;
	url: string;
	title: string;
	context: string;
}

interface SuggestionResponse {
	suggestions: LinkSuggestion[];
}

interface BlockAttributes {
	content?: unknown;
	value?: unknown;
	[ key: string ]: unknown;
}

interface Block {
	clientId: string;
	name: string;
	attributes: BlockAttributes;
	innerBlocks: Block[];
}

/**
 * Converts a RichText attribute value (string or object) to a plain string.
 *
 * @param value Attribute value.
 * @return Plain text string.
 */
function toPlainString( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value;
	}
	if (
		value &&
		typeof value === 'object' &&
		'text' in value &&
		typeof ( value as { text?: unknown } ).text === 'string'
	) {
		return ( value as { text: string } ).text;
	}
	return '';
}

/**
 * Strips HTML tags from a string.
 *
 * @param html HTML string.
 * @return Plain text.
 */
function stripTags( html: string ): string {
	// Use the DOM to strip tags reliably in browser context.
	const div = document.createElement( 'div' );
	div.innerHTML = html;
	return div.textContent ?? div.innerText ?? '';
}

/**
 * Recursively flattens a block tree.
 *
 * @param blocks Top-level blocks.
 * @return Flat array of all blocks.
 */
function flattenAll( blocks: Block[] ): Block[] {
	return blocks.reduce< Block[] >( ( acc, block ) => {
		acc.push( block );
		if ( block.innerBlocks?.length ) {
			acc.push( ...flattenAll( block.innerBlocks ) );
		}
		return acc;
	}, [] );
}

/**
 * Applies an internal link suggestion to the block editor.
 *
 * Finds the first block whose plain-text content contains the anchor text
 * and wraps that first occurrence in an HTML <a> tag.
 *
 * @param suggestion The accepted link suggestion.
 * @param blocks     All blocks in the editor.
 */
function applyLinkToBlock( suggestion: LinkSuggestion, blocks: Block[] ): void {
	const flat = flattenAll( blocks );
	const { anchor_text: anchorText, url } = suggestion;

	for ( const block of flat ) {
		const rawContent = toPlainString(
			block.attributes.content ?? block.attributes.value ?? ''
		);
		const plainContent = stripTags( rawContent );

		if ( ! plainContent.includes( anchorText ) ) {
			continue;
		}

		const escapedAnchor = anchorText.replace(
			/[.*+?^${}()|[\]\\]/g,
			'\\$&'
		);
		const regex = new RegExp( `(${ escapedAnchor })`, '' );
		const updatedHtml = rawContent.replace(
			regex,
			`<a href="${ url }">${ anchorText }</a>`
		);

		const attributeKey =
			'content' in block.attributes ? 'content' : 'value';

		( dispatch( blockEditorStore ) as any ).updateBlockAttributes(
			block.clientId,
			{ [ attributeKey ]: updatedHtml }
		);

		return;
	}
}

/**
 * Hook for Internal Link Suggestions functionality.
 *
 * @return State and handlers for the internal links feature.
 */
export function useInternalLinks(): {
	isLoading: boolean;
	suggestions: LinkSuggestion[];
	isContentTooShort: boolean;
	minContentLength: number;
	fetchSuggestions: () => Promise< void >;
	acceptSuggestion: ( suggestion: LinkSuggestion ) => void;
	dismissSuggestion: ( suggestion: LinkSuggestion ) => void;
} {
	const [ isLoading, setIsLoading ] = useState< boolean >( false );
	const [ suggestions, setSuggestions ] = useState< LinkSuggestion[] >( [] );

	const minContentLength: number =
		( window as any ).aiInternalLinksData?.minContentLength ??
		MINIMUM_CONTENT_COUNT_DEFAULT;

	const maxSuggestions: number =
		( window as any ).aiInternalLinksData?.maxSuggestions ?? 5;

	const { content, postId } = useSelect( ( selectStore ) => {
		const editor = selectStore( editorStore ) as any;
		return {
			content: editor.getEditedPostContent() as string,
			postId: editor.getCurrentPostId() as number,
		};
	}, [] );

	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

	const fetchSuggestions = async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		if ( isContentTooShort ) {
			return;
		}

		setIsLoading( true );
		setSuggestions( [] );

		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const result = await runAbility< SuggestionResponse >(
				'ai/internal-links',
				{
					post_content: content,
					post_id: postId,
					max_suggestions: maxSuggestions,
				}
			);

			setSuggestions( result?.suggestions ?? [] );

			if ( ( result?.suggestions ?? [] ).length === 0 ) {
				dispatch( noticesStore ).createNotice(
					'info',
					__(
						'No internal link suggestions found for this post.',
						'ai'
					),
					{ type: 'snackbar' }
				);
			}
		} catch ( error: any ) {
			dispatch( noticesStore ).createErrorNotice(
				error?.message ?? String( error ),
				{
					id: NOTICE_ID,
					isDismissible: true,
				}
			);
		} finally {
			setIsLoading( false );
		}
	};

	const acceptSuggestion = ( suggestion: LinkSuggestion ) => {
		const blocks = (
			select( blockEditorStore ) as any
		 ).getBlocks() as Block[];

		applyLinkToBlock( suggestion, blocks );

		// Remove the accepted suggestion from the list.
		setSuggestions( ( prev ) =>
			prev.filter( ( s ) => s.anchor_text !== suggestion.anchor_text )
		);

		dispatch( noticesStore ).createSuccessNotice(
			__(
				'Internal link applied. Save the post to keep the change.',
				'ai'
			),
			{ type: 'snackbar' }
		);
	};

	const dismissSuggestion = ( suggestion: LinkSuggestion ) => {
		setSuggestions( ( prev ) =>
			prev.filter( ( s ) => s.anchor_text !== suggestion.anchor_text )
		);
	};

	return {
		isLoading,
		suggestions,
		isContentTooShort,
		minContentLength,
		fetchSuggestions,
		acceptSuggestion,
		dismissSuggestion,
	};
}
