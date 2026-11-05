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
import {
	flattenBlocks,
	getBlockHTML,
	getEditableTextAttribute,
	type BlockWithContent,
} from '../../../utils/blocks';
import '../types.d.ts';

const NOTICE_ID = 'ai_internal_links_error';
const MINIMUM_CONTENT_COUNT_DEFAULT = 75;

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
	alt?: unknown;
	[ key: string ]: unknown;
}

interface Block {
	clientId: string;
	name: string;
	attributes: BlockAttributes;
	innerBlocks: Block[];
}

/**
 * Strips HTML tags from a string.
 *
 * @param html HTML string.
 * @return Plain text.
 */
function stripTags( html: string ): string {
	const div = document.createElement( 'div' );
	div.innerHTML = html;
	return div.textContent ?? div.innerText ?? '';
}

/**
 * Returns the set of anchor texts already hyperlinked in the post HTML.
 *
 * Parses every <a> element from the raw post content so that suggestions
 * whose anchor_text is already linked can be excluded from the results.
 *
 * @param html Raw HTML post content.
 * @return Set of already-linked text strings (lowercased for comparison).
 */
function getLinkedAnchorTexts( html: string ): Set< string > {
	const div = document.createElement( 'div' );
	div.innerHTML = html;
	const linked = new Set< string >();
	div.querySelectorAll( 'a' ).forEach( ( anchor ) => {
		const text = anchor.textContent?.trim();
		if ( text ) {
			linked.add( text.toLowerCase() );
		}
	} );
	return linked;
}

/**
 * Builds a regex pattern from anchor text that tolerates inline HTML tags
 * between words.
 *
 * Each word in the anchor is escaped for regex use, then joined with a
 * pattern that allows optional whitespace and inline HTML tags between them.
 * This lets the regex match phrases like "customer support team" even when the
 * raw HTML is `<strong>customer support</strong> team`.
 *
 * If the anchor is a single token (no spaces), the pattern is just the
 * escaped literal — no tag-tolerance needed.
 *
 * @param anchorText The anchor text to match.
 * @return A RegExp that matches the anchor across inline tag boundaries.
 */
function buildAnchorRegex( anchorText: string ): RegExp {
	// Escape every regex-special character in each word individually, then
	// join words with a pattern that allows any number of inline HTML tags
	// (and surrounding whitespace) between them.
	const TAG_GAP = '(?:\\s*(?:<[^>]*>\\s*)*)';
	const escapedWords = anchorText
		.split( /(\s+)/ )
		.filter( ( part ) => part.trim().length > 0 )
		.map( ( word ) => word.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) );

	const pattern =
		escapedWords.length > 1
			? escapedWords.join( TAG_GAP )
			: escapedWords[ 0 ] ?? '';

	return new RegExp( pattern, '' );
}

/**
 * Applies an internal link suggestion to the block editor.
 *
 * Finds the first block whose plain-text content contains the anchor text,
 * then locates the phrase in the raw HTML (tolerating inline tags between
 * words) and wraps the matched span in an `<a>` tag.
 *
 * Falls back silently if the pattern cannot match in raw HTML (e.g. a tag
 * splits a word mid-character), rather than writing back an unchanged string.
 *
 * @param suggestion The accepted link suggestion.
 * @param blocks     All blocks in the editor.
 * @return True if the link was successfully inserted, false otherwise.
 */
function applyLinkToBlock(
	suggestion: LinkSuggestion,
	blocks: Block[]
): boolean {
	const flat = flattenBlocks( blocks );
	const { anchor_text: anchorText, url } = suggestion;

	for ( const block of flat ) {
		const attributeKey = getEditableTextAttribute(
			block as BlockWithContent
		);

		if ( ! attributeKey ) {
			continue;
		}

		const rawContent = getBlockHTML( block as BlockWithContent );
		const plainContent = stripTags( rawContent );

		// Gate: anchor must exist in plain text first (fast, no-HTML check).
		if ( ! plainContent.includes( anchorText ) ) {
			continue;
		}

		// Build a word-level regex that tolerates inline HTML tags between
		// words so phrases spanning tag boundaries (e.g. `<strong>foo
		// bar</strong> baz`) can still be matched and wrapped.
		const regex = buildAnchorRegex( anchorText );
		const updatedHtml = rawContent.replace(
			regex,
			`<a href="${ url }">$&</a>`
		);

		// If nothing changed the regex didn't match in raw HTML (e.g. a tag
		// splits mid-word). Skip rather than dispatching a no-op update.
		if ( updatedHtml === rawContent ) {
			continue;
		}

		dispatch( blockEditorStore ).updateBlockAttributes( block.clientId, {
			[ attributeKey ]: updatedHtml,
		} );

		return true;
	}

	return false;
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

	const minContentLength: number = parseInt(
		window.aiInternalLinksData?.minContentLength ??
			String( MINIMUM_CONTENT_COUNT_DEFAULT ),
		10
	);

	const maxSuggestions: number = parseInt(
		window.aiInternalLinksData?.maxSuggestions ?? '5',
		10
	);

	const { content, postId } = useSelect( ( selectStore ) => {
		const editor = selectStore( editorStore );
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

		const alreadyLinked = getLinkedAnchorTexts( content );
		const excludedAnchors = [ ...alreadyLinked ];

		try {
			const result = await runAbility< SuggestionResponse >(
				'ai/internal-links',
				{
					post_content: content,
					post_id: postId,
					max_suggestions: maxSuggestions,
					excluded_anchors: excludedAnchors,
				}
			);

			const fetchedSuggestions = result?.suggestions ?? [];
			setSuggestions( fetchedSuggestions );

			if ( fetchedSuggestions.length === 0 ) {
				dispatch( noticesStore ).createNotice(
					'info',
					__(
						'No internal link suggestions found for this content.',
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
		const blocks = select( blockEditorStore ).getBlocks() as Block[];
		const applied = applyLinkToBlock( suggestion, blocks );

		if ( applied ) {
			// Remove from the list only on confirmed insertion.
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
		} else {
			// Anchor text could not be located in the block content — leave the
			// suggestion visible so the editor can dismiss it manually.
			dispatch( noticesStore ).createErrorNotice(
				__(
					'Could not insert the link automatically. The anchor text may no longer exist in the post content.',
					'ai'
				),
				{
					id: NOTICE_ID,
					isDismissible: true,
				}
			);
		}
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
