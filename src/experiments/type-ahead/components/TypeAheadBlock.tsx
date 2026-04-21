/**
 * Components for type-ahead block.
 */

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * WordPress dependencies
 */
import { VisuallyHidden } from '@wordpress/components';
import { useCallback, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { TypeAheadSettings } from '../types';
import { splitSuggestion } from '../utils/text';
import { useBlockDom } from '../hooks/useBlockDom';
import { useCaretData } from '../hooks/useCaretData';
import { useTypeAheadContext } from '../hooks/useTypeAheadContext';
import { useTypeAheadSuggestion } from '../hooks/useTypeAheadSuggestion';
import TypeAheadOverlay from './TypeAheadOverlay';

type TypeAheadBlockProps = {
	BlockEdit: ComponentType< any >;
	blockProps: any;
	settings: TypeAheadSettings;
	allowedBlocks: Set< string >;
};

/**
 * Block wrapper that adds type-ahead behavior to supported blocks.
 *
 * @param {Object}               props               Block wrapper props.
 * @param {ComponentType< any >} props.BlockEdit     Block edit component.
 * @param {any}                  props.blockProps    Block wrapper props.
 * @param {TypeAheadSettings}    props.settings      Type-ahead settings.
 * @param {Set< string >}        props.allowedBlocks Allowed blocks.
 * @return {JSX.Element} Wrapped block edit UI plus ghost text overlay.
 */
const TypeAheadBlock = ( {
	BlockEdit,
	blockProps,
	settings,
	allowedBlocks,
}: TypeAheadBlockProps ): JSX.Element => {
	const { clientId, attributes, name } = blockProps;
	const { block, editable } = useBlockDom( clientId );
	const caret = useCaretData( editable );
	const { selectedClientId, siblingContext, postId, plainContent } =
		useTypeAheadContext( clientId, attributes?.content || '' );
	const followingText = caret ? plainContent.slice( caret.offset ) : '';
	const caretAtEnd = caret ? followingText.length === 0 : false;

	const shouldRequest =
		settings.enabled &&
		allowedBlocks.has( name ) &&
		selectedClientId === clientId &&
		caretAtEnd;

	const {
		suggestion,
		setSuggestion,
		cancelPendingRequest,
		triggerManualFetch,
	} = useTypeAheadSuggestion( {
		shouldRequest,
		caret,
		editable,
		plainContent,
		followingText,
		siblingContext,
		postId,
		clientId,
		settings,
	} );

	useEffect( () => {
		if ( ! editable ) {
			return;
		}

		const handleInput = () => {
			cancelPendingRequest();
			setSuggestion( null );
		};

		editable.addEventListener( 'input', handleInput );
		return () => {
			editable.removeEventListener( 'input', handleInput );
		};
	}, [ editable, cancelPendingRequest, setSuggestion ] );

	useEffect( () => {
		if ( ! block ) {
			return;
		}

		block.classList.add( 'ai-type-ahead-block' );
		return () => block.classList.remove( 'ai-type-ahead-block' );
	}, [ block ] );

	useEffect( () => {
		if ( ! editable ) {
			return;
		}

		const isEmptyBlock =
			editable.getAttribute( 'data-empty' ) === 'true' ||
			plainContent.trim().length === 0;
		const shouldHidePlaceholder =
			Boolean( suggestion?.text ) && isEmptyBlock;

		editable.classList.toggle(
			'ai-type-ahead-hide-placeholder',
			shouldHidePlaceholder
		);

		return () => {
			editable.classList.remove( 'ai-type-ahead-hide-placeholder' );
		};
	}, [ editable, suggestion?.text, plainContent ] );

	useEffect( () => {
		if ( ! editable ) {
			return;
		}

		const removeInlineGhost = () => {
			editable
				.querySelectorAll( '.ai-type-ahead-inline-ghost' )
				.forEach( ( node ) => node.remove() );
		};

		removeInlineGhost();

		if ( ! suggestion?.text || caretAtEnd || ! caret ) {
			return removeInlineGhost;
		}

		const doc = caret.ownerDocument;
		const selection = doc.getSelection();
		if ( ! selection || selection.rangeCount === 0 ) {
			return removeInlineGhost;
		}

		const range = selection.getRangeAt( 0 );
		if ( ! editable.contains( range.startContainer ) ) {
			return removeInlineGhost;
		}

		const ghost = doc.createElement( 'span' );
		ghost.className = 'ai-type-ahead-inline-ghost';
		ghost.setAttribute( 'contenteditable', 'false' );
		ghost.setAttribute( 'aria-hidden', 'true' );
		ghost.textContent = suggestion.text;

		range.insertNode( ghost );

		// Keep caret at original insertion point, before ghost content.
		const caretRange = doc.createRange();
		caretRange.setStartBefore( ghost );
		caretRange.collapse( true );
		selection.removeAllRanges();
		selection.addRange( caretRange );

		return removeInlineGhost;
	}, [ editable, suggestion?.text, caretAtEnd, caret ] );

	const insertText = useCallback(
		( text: string ) => {
			if ( ! caret || ! editable ) {
				return;
			}

			const doc = caret.ownerDocument;
			const selection = doc.getSelection();
			if ( ! selection || selection.rangeCount === 0 ) {
				return;
			}

			const range = selection.getRangeAt( 0 );
			if ( ! editable.contains( range.startContainer ) ) {
				return;
			}

			if ( ! range.collapsed ) {
				range.deleteContents();
			}

			const textNode = doc.createTextNode( text );
			range.insertNode( textNode );
			range.setStartAfter( textNode );
			range.collapse( true );
			selection.removeAllRanges();
			selection.addRange( range );

			const init: InputEventInit = {
				bubbles: true,
				cancelable: false,
				data: text,
				inputType: 'insertText',
			};

			try {
				const inputEvent = new InputEvent( 'input', init );
				editable.dispatchEvent( inputEvent );
			} catch {
				const fallbackEvent = doc.createEvent( 'HTMLEvents' );
				fallbackEvent.initEvent( 'input', true, false );
				editable.dispatchEvent( fallbackEvent );
			}
		},
		[ caret, editable ]
	);

	const acceptSuggestion = useCallback(
		( mode: 'word' | 'sentence' | 'all' ) => {
			if ( ! suggestion ) {
				return;
			}

			const { apply, remainder } = splitSuggestion(
				suggestion.text,
				mode
			);
			if ( ! apply ) {
				return;
			}

			insertText( apply );

			if ( remainder.trim() ) {
				setSuggestion( {
					text: remainder,
					confidence: suggestion.confidence,
				} );
				return;
			}

			setSuggestion( null );
		},
		[ insertText, setSuggestion, suggestion ]
	);

	useEffect( () => {
		if ( ! editable ) {
			return;
		}

		const handleKeyDown = ( event: KeyboardEvent ) => {
			if ( suggestion && event.key === 'Tab' && ! event.shiftKey ) {
				event.preventDefault();
				acceptSuggestion( 'all' );
				return;
			}

			if (
				suggestion &&
				event.key === 'ArrowRight' &&
				( event.metaKey || event.ctrlKey )
			) {
				event.preventDefault();
				acceptSuggestion( event.shiftKey ? 'sentence' : 'word' );
				return;
			}

			if ( suggestion && event.key === 'Escape' ) {
				event.preventDefault();
				setSuggestion( null );
				return;
			}

			if (
				( event.metaKey || event.ctrlKey ) &&
				event.code === 'Space'
			) {
				event.preventDefault();
				triggerManualFetch();
			}
		};

		editable.addEventListener( 'keydown', handleKeyDown );
		return () => editable.removeEventListener( 'keydown', handleKeyDown );
	}, [
		editable,
		suggestion,
		acceptSuggestion,
		setSuggestion,
		triggerManualFetch,
	] );

	if ( ! allowedBlocks.has( name ) ) {
		return <BlockEdit { ...blockProps } />;
	}

	return (
		<>
			<BlockEdit { ...blockProps } />
			<TypeAheadOverlay
				ownerDocument={ caret?.ownerDocument ?? document }
				rect={ caret?.rect ?? null }
				container={ editable ?? null }
				text={ caretAtEnd ? suggestion?.text ?? null : null }
			/>
			<VisuallyHidden role="status" aria-live="polite">
				{ suggestion?.text ?? '' }
			</VisuallyHidden>
		</>
	);
};

export default TypeAheadBlock;
