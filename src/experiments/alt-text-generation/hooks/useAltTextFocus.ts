/**
 * Hook for managing focus between alt text controls as they mount and unmount.
 */

/**
 * WordPress dependencies
 */
import { type RefCallback, useCallback, useRef } from '@wordpress/element';

type FocusTarget = 'generate' | 'notice' | 'primary';

type UseAltTextFocusReturn = {
	requestFocus: ( target: FocusTarget ) => void;
	generateButtonRef: RefCallback< HTMLButtonElement | null >;
	noticeButtonRef: RefCallback< HTMLButtonElement | null >;
	primaryButtonRef: RefCallback< HTMLButtonElement | null >;
};

/**
 * Manages focus between alt text controls as they mount and unmount.
 *
 * Focuses the requested button immediately when available, or when it mounts.
 * A new request replaces any pending focus request.
 *
 * @return {UseAltTextFocusReturn} Focus request functions, and button callback refs.
 */
export function useAltTextFocus(): UseAltTextFocusReturn {
	// References to the button elements.
	const elements = useRef< Record< FocusTarget, HTMLButtonElement | null > >(
		{
			generate: null,
			notice: null,
			primary: null,
		}
	);

	// The next target to focus on.
	const pendingFocus = useRef< FocusTarget | null >( null );

	/**
	 * Requests focus on the specified target.
	 *
	 * @param {FocusTarget} target The target to focus on.
	 * @return {void}
	 */
	const requestFocus = useCallback( ( target: FocusTarget ) => {
		const element = elements.current[ target ];

		// If the element is connected, focus it immediately.
		if ( element?.isConnected ) {
			pendingFocus.current = null;
			element.focus();
		} else {
			pendingFocus.current = target;
		}
	}, [] );

	/**
	 * Registers a button element for the specified target.
	 *
	 * @param {FocusTarget}              target  The target to register the button for.
	 * @param {HTMLButtonElement | null} element The button element to register.
	 * @return {void}
	 */
	const register = useCallback(
		( target: FocusTarget, element: HTMLButtonElement | null ) => {
			elements.current[ target ] = element;

			// If the element is connected and it's the next target to focus on,
			// focus it immediately.
			if ( element && pendingFocus.current === target ) {
				pendingFocus.current = null;
				element.focus();
			}
		},
		[]
	);

	// Callback ref that registers the Generate Alt Text button.
	const generateButtonRef = useCallback(
		( element: HTMLButtonElement | null ) =>
			register( 'generate', element ),
		[ register ]
	);

	// Callback ref that registers the Decorative Image Notice button.
	const noticeButtonRef = useCallback(
		( element: HTMLButtonElement | null ) => register( 'notice', element ),
		[ register ]
	);

	// Callback ref that registers the Primary buttons.
	const primaryButtonRef = useCallback(
		( element: HTMLButtonElement | null ) => register( 'primary', element ),
		[ register ]
	);

	return {
		requestFocus,
		generateButtonRef,
		noticeButtonRef,
		primaryButtonRef,
	};
}
