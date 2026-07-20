/**
 * Components for type-ahead overlay.
 */

/**
 * External dependencies
 */
import type { CSSProperties } from 'react';

/**
 * WordPress dependencies
 */
import { createPortal, useEffect, useState } from '@wordpress/element';

type TypeAheadOverlayProps = {
	ownerDocument: Document | null;
	rect: DOMRect | null;
	container: HTMLElement | null;
	text: string | null;
};

/**
 * Check whether a rect is inside a container rect.
 *
 * @param {DOMRect} innerRect     Inner rect.
 * @param {DOMRect} containerRect Container rect.
 * @return {boolean} True if the inner rect is inside the container.
 */
const isRectInsideContainer = (
	innerRect: DOMRect,
	containerRect: DOMRect
): boolean => {
	return (
		innerRect.top >= containerRect.top &&
		innerRect.bottom <= containerRect.bottom &&
		innerRect.left >= containerRect.left &&
		innerRect.right <= containerRect.right
	);
};

/**
 * Portal-rendered ghost text anchored to the caret line.
 *
 * @param {Object}      props               Overlay display state.
 * @param {Document}    props.ownerDocument Owner document.
 * @param {DOMRect}     props.rect          Rect.
 * @param {HTMLElement} props.container     Container.
 * @param {string}      props.text          Text.
 * @return {React.JSX.Element | null} Overlay element when suggestion and caret are available.
 */
const TypeAheadOverlay = ( {
	ownerDocument,
	rect,
	container,
	text,
}: TypeAheadOverlayProps ): React.JSX.Element | null => {
	const [ style, setStyle ] = useState< CSSProperties | null >( null );
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

		// If the caret rect falls outside the editable area, anchor to the container
		// so the overlay stays aligned with the visible block bounds.
		const anchorRect =
			containerRect && ! isRectInsideContainer( rect, containerRect )
				? containerRect
				: rect;

		const containerLeft = containerRect?.left ?? anchorRect.left;
		const indent = Math.max( 0, anchorRect.left - containerLeft );

		setStyle( {
			position: 'absolute',
			zIndex: 1,
			top: anchorRect.top + scrollY,
			left: containerLeft + scrollX,
			width: containerRect?.width ?? 'auto',
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

export default TypeAheadOverlay;
