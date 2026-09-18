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
import { __ } from '@wordpress/i18n';

type TypeAheadLoadingIndicatorProps = {
	ownerDocument: Document | null;
	editable: HTMLElement | null;
	rect: DOMRect | null;
	visible: boolean;
};

const LOADING_INDICATOR_OFFSET_TOP = -3;
const LOADING_INDICATOR_OFFSET_INLINE = 4;

/**
 * Portal-rendered breathing-dots marker anchored to the caret while a
 * suggestion request is in flight, so waiting for Type Ahead reads
 * differently from an idle blinking text cursor.
 *
 * @param {Object}      props               Indicator display state.
 * @param {Document}    props.ownerDocument Owner document.
 * @param {HTMLElement} props.editable      Rich text editable element.
 * @param {DOMRect}     props.rect          Caret rect.
 * @param {boolean}     props.visible       Whether a request is pending.
 * @return {React.JSX.Element | null} Indicator element when a request is pending.
 */
const TypeAheadLoadingIndicator = ( {
	ownerDocument,
	editable,
	rect,
	visible,
}: TypeAheadLoadingIndicatorProps ): React.JSX.Element | null => {
	const body = ownerDocument?.body ?? document.body;
	const win = ownerDocument?.defaultView ?? window;

	const style = useMemo< CSSProperties | null >( () => {
		if ( ! rect || ! visible ) {
			return null;
		}

		const scrollX = win?.scrollX ?? win?.pageXOffset ?? 0;
		const scrollY = win?.scrollY ?? win?.pageYOffset ?? 0;

		const directionSource = editable ?? body;
		const rtl =
			win?.getComputedStyle( directionSource ).direction === 'rtl';

		return {
			position: 'absolute',
			zIndex: 1,
			top: rect.bottom + LOADING_INDICATOR_OFFSET_TOP + scrollY,
			left: rtl
				? rect.left - LOADING_INDICATOR_OFFSET_INLINE + scrollX
				: rect.left + LOADING_INDICATOR_OFFSET_INLINE + scrollX,
			transform: rtl ? 'translateX(-100%)' : undefined,
		};
	}, [ rect, win, visible, editable, body ] );

	if ( ! body || ! style ) {
		return null;
	}

	return createPortal(
		<span
			className="ai-type-ahead-loading-indicator"
			style={ style }
			aria-label={ __( 'Loading suggestions', 'ai' ) }
		>
			<span className="ai-type-ahead-loading-indicator__dot" />
			<span className="ai-type-ahead-loading-indicator__dot" />
			<span className="ai-type-ahead-loading-indicator__dot" />
		</span>,
		body
	);
};

export default TypeAheadLoadingIndicator;
