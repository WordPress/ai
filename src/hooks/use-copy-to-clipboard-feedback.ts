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
	 * Whether the current text was just copied. Resets after `timeout` ms, or
	 * immediately if the text changes (e.g. the user edits the source).
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
	const [ copiedText, setCopiedText ] = useState< string | null >( null );
	const timeoutRef = useRef< ReturnType< typeof setTimeout > >();

	const ref = useCopyToClipboard< T >( text, () => {
		if ( announcement ) {
			speak( announcement );
		}

		setCopiedText( typeof text === 'function' ? text() : text );

		if ( timeoutRef.current ) {
			clearTimeout( timeoutRef.current );
		}

		timeoutRef.current = setTimeout( () => {
			setCopiedText( null );
		}, timeout );
	} );

	useEffect( () => {
		return () => {
			if ( timeoutRef.current ) {
				clearTimeout( timeoutRef.current );
			}
		};
	}, [] );

	// Only show the confirmation while the copied snapshot still matches the
	// current text. If the source changes (e.g. the user edits a textarea), the
	// trigger reverts to its default label immediately.
	const currentText = typeof text === 'function' ? text() : text;
	const hasCopied =
		copiedText !== null &&
		copiedText === currentText &&
		copiedText.length > 0;

	return { ref, hasCopied };
}
