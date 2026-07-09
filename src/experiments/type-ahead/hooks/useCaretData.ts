/**
 * Hooks for caret data.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { CaretData } from '../types';

/**
 * Tracks caret position and nearby text details for a contenteditable element.
 *
 * @param {HTMLElement | null} editable Rich text editable element.
 * @return {CaretData | null} Caret metadata when selection is inside the editable.
 */
export const useCaretData = (
	editable: HTMLElement | null
): CaretData | null => {
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
			const selection = doc.getSelection();
			if ( ! selection || selection.rangeCount === 0 ) {
				setCaret( null );
				return;
			}

			const range = selection.getRangeAt( 0 );
			if ( ! editable.contains( range.startContainer ) ) {
				setCaret( null );
				return;
			}

			const markerRange = range.cloneRange();
			const rects = markerRange.getClientRects();
			const rect =
				rects.item( rects.length - 1 ) ??
				markerRange.getBoundingClientRect();

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
