/**
 * Reusable "copy to clipboard" hook with a transient confirmation state.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { useCopyToClipboard } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';

interface UseCopyToClipboardFeedbackOptions {
	/**
	 * The text to copy, or a function that returns it.
	 */
	text: string | ( () => string );

	/**
	 * Message announced to assistive technology when the copy succeeds.
	 */
	announcement?: string;

	/**
	 * How long the confirmation state stays active, in milliseconds.
	 *
	 * @default 4000
	 */
	timeout?: number;
}

interface UseCopyToClipboardFeedback< T extends HTMLElement > {
	/**
	 * Ref to attach to the trigger element (e.g. a Button).
	 */
	ref: ( node: T | null ) => void;

	/**
	 * Whether the content was just copied. Resets after `timeout` ms.
	 */
	hasCopied: boolean;
}

/**
 * Copies text to the clipboard and exposes a short-lived `hasCopied` flag so the
 * trigger can show a "Copied!" confirmation. Announces the copy to assistive
 * technology and cleans up its timer on unmount.
 *
 * @param options              Hook options.
 * @param options.text         The text to copy, or a function that returns it.
 * @param options.announcement Message announced to assistive technology on copy.
 * @param options.timeout      How long the confirmation state stays active, in milliseconds.
 *
 * @return Ref to attach to the trigger and the transient `hasCopied` state.
 */
export function useCopyToClipboardFeedback< T extends HTMLElement >( {
	text,
	announcement,
	timeout = 4000,
}: UseCopyToClipboardFeedbackOptions ): UseCopyToClipboardFeedback< T > {
	const [ hasCopied, setHasCopied ] = useState( false );
	const timeoutRef = useRef< ReturnType< typeof setTimeout > >();

	const ref = useCopyToClipboard< T >( text, () => {
		if ( announcement ) {
			speak( announcement );
		}

		setHasCopied( true );

		if ( timeoutRef.current ) {
			clearTimeout( timeoutRef.current );
		}

		timeoutRef.current = setTimeout( () => {
			setHasCopied( false );
		}, timeout );
	} );

	useEffect( () => {
		return () => {
			if ( timeoutRef.current ) {
				clearTimeout( timeoutRef.current );
			}
		};
	}, [] );

	return { ref, hasCopied };
}
