/**
 * Type-ahead inline ghost text experiment.
 */

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * External dependencies
 */
import React, {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from 'react';
import { createPortal } from 'react-dom';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';

const ALLOWED_BLOCKS = [ 'core/paragraph' ];
const GHOST_COLOR = 'var(--ai-type-ahead-ghost-color, #8a8f98)';
const REQUEST_TIMEOUT_MS = 15000; // abort long-running completions to avoid apiFetch 20s timeout while giving server time.
const WHITESPACE_REGEX = /\s/;
const LEADING_WHITESPACE_REGEX = /^\s/;

type CompletionMode = 'word' | 'sentence' | 'paragraph' | 'smart';

type TypeAheadSettings = {
	enabled: boolean;
	completionMode: CompletionMode;
	triggerDelay: number;
	confidence: number; // 0-1
	showHeadings: boolean;
	maxWords: number;
	abilityName: string;
};

type Suggestion = {
	text: string;
	confidence: number;
};

type CaretData = {
	offset: number;
	rect: DOMRect | null;
	precedingText: string;
	ownerDocument: Document;
};

declare global {
	interface Window {
		aiTypeAheadData?: TypeAheadSettings;
	}
}

/**
 * Utility: convert RichText HTML to plain text for prompting.
 */
const htmlToPlainText = ( value?: string ): string => {
	if ( ! value ) {
		return '';
	}
	const temp = document.createElement( 'div' );
	temp.innerHTML = value;
	return ( temp.textContent || temp.innerText || '' ).replaceAll(
		'\u00A0',
		' '
	);
};

const shouldTriggerFromContext = ( preceding: string ): boolean => {
	const trimmed = preceding.trimEnd();
	if ( ! trimmed ) {
		return false;
	}
	const lastChar = trimmed.slice( -1 );
	if ( [ '.', '?', '!', ':' ].includes( lastChar ) ) {
		return true;
	}
	const lower = trimmed.toLowerCase();
	return lower.endsWith( 'such as' ) || lower.endsWith( 'for example' );
};

const splitSuggestion = (
	suggestion: string,
	mode: 'word' | 'sentence' | 'all'
) => {
	if ( mode === 'all' ) {
		return { apply: suggestion, remainder: '' };
	}

	if ( mode === 'word' ) {
		const match = suggestion.match( /^\s*\S+\s*/ );
		const chunk = match ? match[ 0 ] : suggestion;
		return { apply: chunk, remainder: suggestion.slice( chunk.length ) };
	}

	const sentenceMatch = suggestion.match( /^(.*?[\.!?](?:\s|$))/ );
	const sentence = sentenceMatch ? sentenceMatch[ 0 ] : suggestion;
	return { apply: sentence, remainder: suggestion.slice( sentence.length ) };
};

const addLeadingSpaceIfNeeded = (
	text: string,
	precedingText: string
): string => {
	if ( ! text || ! precedingText ) {
		return text;
	}
	const lastChar = precedingText.slice( -1 );
	if ( ! lastChar || WHITESPACE_REGEX.test( lastChar ) ) {
		return text;
	}
	if ( LEADING_WHITESPACE_REGEX.test( text ) ) {
		return text;
	}
	return ` ${ text }`;
};

const useBlockDom = ( clientId: string ) => {
	const [ state, setState ] = useState< {
		block: HTMLElement | null;
		editable: HTMLElement | null;
	} >( {
		block: null,
		editable: null,
	} );

	useEffect( () => {
		let cancelled = false;

		const queryDocuments = (): Document[] => {
			const docs: Document[] = [ document ];
			document
				.querySelectorAll(
					'iframe[name="editor-canvas"], iframe.wp-block-editor-iframe__iframe'
				)
				.forEach( ( frame ) => {
					if (
						frame instanceof HTMLIFrameElement &&
						frame.contentDocument
					) {
						docs.push( frame.contentDocument );
					}
				} );
			return docs;
		};

		const findEditable = ( blockEl: HTMLElement ): HTMLElement | null => {
			// Check if the block element itself is editable (common for paragraph blocks)
			if (
				blockEl.getAttribute( 'contenteditable' ) === 'true' ||
				blockEl.hasAttribute( 'data-rich-text-editable' )
			) {
				return blockEl;
			}

			// Otherwise search for editable elements within the block
			const candidates = Array.from(
				blockEl.querySelectorAll< HTMLElement >(
					'[data-rich-text-editable], [contenteditable]'
				)
			);
			return (
				candidates.find(
					( candidate ) =>
						candidate.getAttribute( 'contenteditable' ) !== 'false'
				) ?? null
			);
		};

		const lookup = () => {
			const selector = `[data-block="${ clientId }"]`;
			for ( const doc of queryDocuments() ) {
				const block = doc.querySelector< HTMLElement >( selector );
				if ( block ) {
					const editable = findEditable( block );
					if ( ! cancelled ) {
						setState( { block, editable } );
					}
					return;
				}
			}
			if ( ! cancelled ) {
				setState( { block: null, editable: null } );
			}
		};

		lookup();
		const interval = window.setInterval( lookup, 750 );

		return () => {
			cancelled = true;
			window.clearInterval( interval );
		};
	}, [ clientId ] );

	return state;
};

const useCaretData = ( editable: HTMLElement | null ): CaretData | null => {
	const [ caret, setCaret ] = useState< CaretData | null >( null );

	useEffect( () => {
		if ( ! editable ) {
			setCaret( null );
			return;
		}

		const doc = editable.ownerDocument || document;
		const win = doc.defaultView || window;
		const viewport = win?.visualViewport;

		const update = () => {
			const sel = doc.getSelection();
			if ( ! sel || sel.rangeCount === 0 ) {
				setCaret( null );
				return;
			}
			const range = sel.getRangeAt( 0 );
			if ( ! editable.contains( range.startContainer ) ) {
				setCaret( null );
				return;
			}

			const markerRange = range.cloneRange();
			const rects = markerRange.getClientRects();
			const rect = rects.length
				? rects[ rects.length - 1 ]
				: markerRange.getBoundingClientRect();

			const textRange = doc.createRange();
			textRange.selectNodeContents( editable );
			textRange.setEnd( range.startContainer, range.startOffset );
			const precedingText = textRange.toString();

			setCaret( {
				offset: precedingText.length,
				rect,
				precedingText,
				ownerDocument: doc,
			} );
		};

		update();

		const events: Array< keyof DocumentEventMap > = [ 'selectionchange' ];
		const elementEvents: Array< keyof HTMLElementEventMap > = [
			'keyup',
			'mouseup',
			'input',
		];

		const handleScroll = () => update();
		const handleResize = () => update();
		const handleViewportChange = () => update();
		const ResizeObserverCtor = win?.ResizeObserver ?? window.ResizeObserver;
		let resizeObserver: ResizeObserver | null = null;

		events.forEach( ( eventName ) =>
			doc.addEventListener( eventName, update )
		);
		elementEvents.forEach( ( eventName ) =>
			editable.addEventListener( eventName, update )
		);
		doc.addEventListener( 'scroll', handleScroll, true );
		win?.addEventListener( 'resize', handleResize );
		viewport?.addEventListener( 'resize', handleViewportChange );
		viewport?.addEventListener( 'scroll', handleViewportChange );

		if ( ResizeObserverCtor ) {
			resizeObserver = new ResizeObserverCtor( () => update() );
			resizeObserver.observe( editable );
		}

		return () => {
			events.forEach( ( eventName ) =>
				doc.removeEventListener( eventName, update )
			);
			elementEvents.forEach( ( eventName ) =>
				editable.removeEventListener( eventName, update )
			);
			doc.removeEventListener( 'scroll', handleScroll, true );
			win?.removeEventListener( 'resize', handleResize );
			viewport?.removeEventListener( 'resize', handleViewportChange );
			viewport?.removeEventListener( 'scroll', handleViewportChange );
			resizeObserver?.disconnect();
		};
	}, [ editable ] );

	return caret;
};

const TypeAheadOverlay: React.FC< {
	ownerDocument: Document | null;
	rect: DOMRect | null;
	container: HTMLElement | null;
	text: string | null;
} > = ( { ownerDocument, rect, container, text } ) => {
	const [ style, setStyle ] = useState< React.CSSProperties | null >( null );
	const body = ownerDocument?.body ?? document.body;
	const win = ownerDocument?.defaultView ?? window;

	useEffect( () => {
		if ( ! rect || ! body ) {
			setStyle( null );
			return;
		}

		const scrollX = win?.scrollX ?? win?.pageXOffset ?? 0;
		const scrollY = win?.scrollY ?? win?.pageYOffset ?? 0;
		const containerRect = container?.getBoundingClientRect() ?? null;
		const containerLeft = containerRect?.left ?? rect.left;
		const indent = Math.max( 0, rect.left - containerLeft );

		setStyle( {
			position: 'absolute',
			pointerEvents: 'none',
			display: 'block',
			color: GHOST_COLOR,
			opacity: 1,
			fontStyle: 'normal',
			fontSize: 'inherit',
			lineHeight: 'inherit',
			whiteSpace: 'pre-wrap',
			wordBreak: 'break-word',
			zIndex: 1000,
			top: rect.top + scrollY,
			left: containerLeft + scrollX,
			width: containerRect?.width,
			textIndent: indent ? `${ indent }px` : undefined,
		} );
	}, [ body, rect, win, container ] );

	if ( ! body || ! rect || ! text || ! style ) {
		return null;
	}

	return createPortal(
		<div
			className="ai-type-ahead-overlay"
			style={ style }
			aria-hidden="true"
		>
			{ text }
		</div>,
		body
	);
};

const TypeAheadBlock: React.FC< {
	BlockEdit: React.ComponentType< any >;
	blockProps: any;
	settings: TypeAheadSettings;
	allowedBlocks: Set< string >;
} > = ( { BlockEdit, blockProps, settings, allowedBlocks } ) => {
	const { clientId, attributes, name } = blockProps;
	const { block, editable } = useBlockDom( clientId );
	const caret = useCaretData( editable );
	const [ suggestion, setSuggestion ] = useState< Suggestion | null >( null );
	const [ requestNonce, setRequestNonce ] = useState( 0 );
	const requestRef = useRef( 0 );

	const { selectedClientId, siblingContext, postId } = useSelect(
		( selectCb ) => {
			const blockEditor = selectCb( blockEditorStore );
			const editor = selectCb( editorStore );
			const selected = blockEditor.getSelectedBlockClientId();
			const rootClientId = blockEditor.getBlockRootClientId( clientId );
			const order = rootClientId
				? blockEditor.getBlockOrder( rootClientId )
				: [];
			const index = blockEditor.getBlockIndex( clientId );
			const hasOrder = Array.isArray( order ) && order.length > 0;
			const previousId =
				hasOrder && index > 0 ? order[ index - 1 ] : null;
			const nextId =
				hasOrder && index !== -1 && index < order.length - 1
					? order[ index + 1 ]
					: null;
			const previous = previousId
				? blockEditor.getBlockAttributes( previousId )
				: null;
			const next = nextId
				? blockEditor.getBlockAttributes( nextId )
				: null;

			const neighborText = [ previous?.content, next?.content ]
				.filter( Boolean )
				.map( ( value ) => htmlToPlainText( value as string ) )
				.join( '\n\n' )
				.trim();

			return {
				selectedClientId: selected,
				siblingContext: neighborText,
				postId: editor.getCurrentPostId(),
			};
		},
		[ clientId ]
	);

	const plainContent = useMemo(
		() => htmlToPlainText( attributes?.content || '' ),
		[ attributes?.content ]
	);
	const followingText = caret ? plainContent.slice( caret.offset ) : '';
	const caretAtEnd = caret ? followingText.length === 0 : false;

	const shouldRequest =
		settings.enabled &&
		allowedBlocks.has( name ) &&
		selectedClientId === clientId &&
		caretAtEnd &&
		plainContent.trim().length > 0;

	const abilityName = settings.abilityName || 'ai/type-ahead';
	const abortControllerRef = useRef< AbortController | null >( null );
	const debounceTimerRef = useRef< number | null >( null );
	const requestTimeoutRef = useRef< number | null >( null );

	// Stable reference to current values for use in callbacks
	const stateRef = useRef( {
		caret,
		plainContent,
		followingText,
		siblingContext,
		postId,
		clientId,
		completionMode: settings.completionMode,
		confidence: settings.confidence,
		maxWords: settings.maxWords,
	} );

	// Update the ref on each render
	useEffect( () => {
		stateRef.current = {
			caret,
			plainContent,
			followingText,
			siblingContext,
			postId,
			clientId,
			completionMode: settings.completionMode,
			confidence: settings.confidence,
			maxWords: settings.maxWords,
		};
	} );

	const clearRequestTimeout = useCallback( () => {
		if ( requestTimeoutRef.current !== null ) {
			window.clearTimeout( requestTimeoutRef.current );
			requestTimeoutRef.current = null;
		}
	}, [] );

	const cancelPendingRequest = useCallback( () => {
		if ( debounceTimerRef.current !== null ) {
			window.clearTimeout( debounceTimerRef.current );
			debounceTimerRef.current = null;
		}
		if ( abortControllerRef.current ) {
			abortControllerRef.current.abort();
			abortControllerRef.current = null;
		}
		clearRequestTimeout();
	}, [ clearRequestTimeout ] );

	const fetchSuggestion = useCallback(
		async ( manual: boolean ) => {
			const state = stateRef.current;

			if ( ! state.caret ) {
				return;
			}

			// Cancel any in-flight request
			if ( abortControllerRef.current ) {
				abortControllerRef.current.abort();
			}
			clearRequestTimeout();

			// Create new abort controller for this request
			const controller = new AbortController();
			abortControllerRef.current = controller;
			const currentRequest = ++requestRef.current;
			requestTimeoutRef.current = window.setTimeout( () => {
				controller.abort();
			}, REQUEST_TIMEOUT_MS );

			try {
				const response = await apiFetch< {
					suggestion?: string;
					confidence?: number;
				} >( {
					path: `/wp-abilities/v1/abilities/${ abilityName }/run`,
					method: 'POST',
					data: {
						input: {
							post_id: state.postId,
							block_id: state.clientId,
							block_content: state.plainContent,
							preceding_text: state.caret.precedingText,
							following_text: state.followingText,
							surrounding_context: state.siblingContext,
							cursor_position: state.caret.offset,
							mode: state.completionMode,
							max_words: state.maxWords,
							manual_trigger: manual,
						},
					},
					signal: controller.signal,
				} );

				// Ignore if a newer request has been made
				if ( currentRequest !== requestRef.current ) {
					return;
				}

				if (
					! response ||
					typeof response !== 'object' ||
					! response.suggestion
				) {
					setSuggestion( null );
					return;
				}

				if (
					typeof response.confidence === 'number' &&
					response.confidence < state.confidence
				) {
					setSuggestion( null );
					return;
				}

				const precedingText =
					stateRef.current.caret?.precedingText ?? '';
				const normalizedText = addLeadingSpaceIfNeeded(
					String( response.suggestion ),
					precedingText
				);

				setSuggestion( {
					text: normalizedText,
					confidence: Number( response.confidence || 0 ),
				} );
			} catch ( error: unknown ) {
				// Ignore aborted requests
				if (
					error instanceof DOMException &&
					error.name === 'AbortError'
				) {
					return;
				}
				// eslint-disable-next-line no-console
				console.error( '[AI Type Ahead] Request failed', error );
				setSuggestion( null );
			} finally {
				if ( abortControllerRef.current === controller ) {
					abortControllerRef.current = null;
				}
				clearRequestTimeout();
			}
		},
		[ abilityName, clearRequestTimeout ]
	);

	const scheduleFetch = useCallback(
		( manual: boolean ) => {
			// Clear any pending debounce timer
			if ( debounceTimerRef.current !== null ) {
				window.clearTimeout( debounceTimerRef.current );
			}

			if ( manual ) {
				// Manual triggers bypass debounce
				fetchSuggestion( true );
				return;
			}

			// Debounce automatic triggers
			const delay = Math.max( 200, settings.triggerDelay || 500 );
			debounceTimerRef.current = window.setTimeout( () => {
				debounceTimerRef.current = null;

				const state = stateRef.current;
				const contextTriggered = state.caret
					? shouldTriggerFromContext( state.caret.precedingText )
					: false;

				// In word mode, only trigger after sentence-ending punctuation
				if ( state.completionMode === 'word' && ! contextTriggered ) {
					return;
				}

				fetchSuggestion( false );
			}, delay );
		},
		[ fetchSuggestion, settings.triggerDelay ]
	);

	// Trigger suggestion fetch when conditions are met
	useEffect( () => {
		if ( ! shouldRequest || ! caret || ! editable ) {
			cancelPendingRequest();
			setSuggestion( null );
			return;
		}

		scheduleFetch( false );

		return cancelPendingRequest;
	}, [
		shouldRequest,
		caret?.offset,
		plainContent,
		requestNonce,
		cancelPendingRequest,
		scheduleFetch,
		caret,
		editable,
	] );

	useEffect( () => {
		if ( ! editable ) {
			return;
		}

		const handleInput = () => {
			// Cancel pending request and clear suggestion when user types
			cancelPendingRequest();
			setSuggestion( null );
		};
		editable.addEventListener( 'input', handleInput );
		return () => {
			editable.removeEventListener( 'input', handleInput );
		};
	}, [ editable, cancelPendingRequest ] );

	useEffect( () => {
		if ( block ) {
			block.classList.add( 'ai-type-ahead-block' );
			return () => block.classList.remove( 'ai-type-ahead-block' );
		}
	}, [ block ] );

	const insertText = useCallback(
		( text: string ) => {
			if ( ! caret || ! editable ) {
				return;
			}

			const doc = caret.ownerDocument;
			const sel = doc.getSelection();
			if ( ! sel || sel.rangeCount === 0 ) {
				return;
			}
			const range = sel.getRangeAt( 0 );
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
			sel.removeAllRanges();
			sel.addRange( range );

			const init: InputEventInit = {
				bubbles: true,
				cancelable: false,
				data: text,
				inputType: 'insertText',
			};

			const target = editable;
			try {
				const event = new InputEvent( 'input', init );
				target.dispatchEvent( event );
			} catch ( error ) {
				const fallback = doc.createEvent( 'HTMLEvents' );
				fallback.initEvent( 'input', true, false );
				target.dispatchEvent( fallback );
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
			} else {
				setSuggestion( null );
			}
		},
		[ insertText, suggestion ]
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
				setRequestNonce( ( prev ) => prev + 1 );
				scheduleFetch( true );
			}
		};

		editable.addEventListener( 'keydown', handleKeyDown );
		return () => editable.removeEventListener( 'keydown', handleKeyDown );
	}, [ editable, suggestion, scheduleFetch, acceptSuggestion ] );

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
				text={ suggestion?.text ?? null }
			/>
			<span
				className="screen-reader-text"
				role="status"
				aria-live="polite"
			>
				{ suggestion?.text ?? '' }
			</span>
		</>
	);
};

const bootstrap = ( settings: TypeAheadSettings ) => {
	if ( ! settings.enabled ) {
		return;
	}

	const allowed = new Set( ALLOWED_BLOCKS );
	if ( settings.showHeadings ) {
		allowed.add( 'core/heading' );
	}

	const withTypeAhead = createHigherOrderComponent(
		( BlockEdit: React.ComponentType< any > ) => {
			return ( props: any ) => (
				<TypeAheadBlock
					BlockEdit={ BlockEdit }
					blockProps={ props }
					settings={ settings }
					allowedBlocks={ allowed }
				/>
			);
		},
		'withAITypeAhead'
	);

	addFilter( 'editor.BlockEdit', 'ai/type-ahead', withTypeAhead );
};

const waitForSettings = ( attempts = 0 ) => {
	const settings = window.aiTypeAheadData;
	if ( settings ) {
		bootstrap( settings );
		return;
	}

	if ( attempts > 200 ) {
		// About 5 seconds of polling; bail to avoid infinite loops.
		return;
	}

	window.setTimeout( () => waitForSettings( attempts + 1 ), 25 );
};

waitForSettings();
