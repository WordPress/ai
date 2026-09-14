/**
 * Component for type-ahead loading indicator.
 */

/**
 * External dependencies
 */
import type { CSSProperties } from 'react';

/**
 * WordPress dependencies
 */
import { createPortal, useMemo } from '@wordpress/element';

type TypeAheadLoadingIndicatorProps = {
	ownerDocument: Document | null;
	rect: DOMRect | null;
	visible: boolean;
};

const LOADING_INDICATOR_OFFSET_TOP = -3;
const LOADING_INDICATOR_OFFSET_LEFT = 4;

/**
 * Portal-rendered breathing-dots marker anchored to the caret while a
 * suggestion request is in flight, so waiting for Type Ahead reads
 * differently from an idle blinking text cursor.
 *
 * @param {Object}   props               Indicator display state.
 * @param {Document} props.ownerDocument Owner document.
 * @param {DOMRect}  props.rect          Caret rect.
 * @param {boolean}  props.visible       Whether a request is pending.
 * @return {React.JSX.Element | null} Indicator element when a request is pending.
 */
const TypeAheadLoadingIndicator = ( {
	ownerDocument,
	rect,
	visible,
}: TypeAheadLoadingIndicatorProps ): React.JSX.Element | null => {
	const body = ownerDocument?.body ?? document.body;
	const win = ownerDocument?.defaultView ?? window;

	// Derived during render rather than through an effect: unlike the overlay
	// this needs no layout measurement, so deferring it would only paint the
	// dots at the previous caret position for a frame.
	const style = useMemo< CSSProperties | null >( () => {
		if ( ! rect || ! visible ) {
			return null;
		}

		const scrollX = win?.scrollX ?? win?.pageXOffset ?? 0;
		const scrollY = win?.scrollY ?? win?.pageYOffset ?? 0;

		return {
			position: 'absolute',
			zIndex: 1,
			top: rect.bottom + LOADING_INDICATOR_OFFSET_TOP + scrollY,
			left: rect.left + LOADING_INDICATOR_OFFSET_LEFT + scrollX,
		};
	}, [ rect, win, visible ] );

	if ( ! body || ! style ) {
		return null;
	}

	return createPortal(
		<span
			className="ai-type-ahead-loading-indicator"
			style={ style }
			aria-hidden="true"
		>
			<span className="ai-type-ahead-loading-indicator__dot" />
			<span className="ai-type-ahead-loading-indicator__dot" />
			<span className="ai-type-ahead-loading-indicator__dot" />
		</span>,
		body
	);
};

export default TypeAheadLoadingIndicator;
