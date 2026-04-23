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
		const containerLeft = containerRect?.left ?? rect.left;
		const indent = Math.max( 0, rect.left - containerLeft );

		setStyle( {
			position: 'absolute',
			zIndex: 1,
			top: rect.top + scrollY,
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
