/**
 * Wrapper component that injects content next to the excerpt link.
 */

/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { createRoot, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ExcerptInlineButton from './ExcerptInlineButton';

/**
 * Creates a retry function that will attempt to execute a callback when a condition is met.
 *
 * @param {Function} checkFn    Function that returns true when condition is met.
 * @param {Function} callback   Function to execute when condition is met.
 * @param {number}   maxRetries Maximum number of retry attempts.
 * @param {number}   delay      Delay in milliseconds between retries.
 * @return {Function} A function that starts the retry process.
 */
function createRetry(
	checkFn: () => boolean,
	callback: () => void,
	maxRetries: number,
	delay: number = 200
): () => void {
	let retryCount = 0;
	let timeoutId: NodeJS.Timeout | null = null;

	const retry = () => {
		if ( retryCount < maxRetries ) {
			retryCount++;
			timeoutId = setTimeout( () => {
				timeoutId = null;
				if ( checkFn() ) {
					callback();
				} else {
					retry();
				}
			}, delay );
		}
	};

	const start = () => {
		if ( ! timeoutId ) {
			retry();
		}
	};

	return start;
}

/**
 * ExcerptInlineWrapper component.
 *
 * Injects a generate button next to the excerpt link in the sidebar.
 *
 * @return {JSX.Element} The wrapper component.
 */
export default function ExcerptInlineWrapper(): JSX.Element {
	useEffect( () => {
		let isAttached = false;
		let root: ReturnType< typeof createRoot > | null = null;
		let container: HTMLElement | null = null;
		let observer: MutationObserver | null = null;

		const findAndAttachButton = () => {
			// Don't try if already attached.
			if ( isAttached ) {
				return;
			}

			// Find the excerpt dropdown button.
			const excerptDropdown = document.querySelector(
				'.editor-post-excerpt__dropdown button[class*="components-button"]'
			) as HTMLElement | null;

			if ( ! excerptDropdown ) {
				// Button not found yet, retry.
				const retryExcerpt = createRetry(
					() => {
						return !! document.querySelector(
							'.editor-post-excerpt__dropdown button[class*="components-button"]'
						);
					},
					findAndAttachButton,
					20
				);
				retryExcerpt();
				return;
			}

			// Check if we've already attached the button.
			if (
				excerptDropdown.parentElement?.querySelector(
					'.ai-excerpt-inline-wrapper'
				)
			) {
				isAttached = true;
				return;
			}

			// Create a container for our button.
			container = document.createElement( 'span' );
			container.className = 'ai-excerpt-inline-wrapper';
			container.style.cssText = 'float: right;';

			// Insert the container right after the button.
			excerptDropdown.parentElement?.insertBefore(
				container,
				excerptDropdown.nextSibling
			);

			// Render the button into the container.
			root = createRoot( container );
			root.render( <ExcerptInlineButton /> );

			isAttached = true;

			// Watch for DOM changes in case the excerpt section is re-rendered.
			observer = new MutationObserver( () => {
				if (
					! document.querySelector( '.ai-excerpt-inline-wrapper' )
				) {
					isAttached = false;
					findAndAttachButton();
				}
			} );

			observer.observe( document.body, {
				childList: true,
				subtree: true,
			} );
		};

		// Start looking for the excerpt button after a short delay.
		const timeoutId = setTimeout( findAndAttachButton, 500 );

		return () => {
			clearTimeout( timeoutId );
			if ( root ) {
				root.unmount();
			}
			if ( container ) {
				container.remove();
			}
			if ( observer ) {
				observer.disconnect();
			}
		};
	}, [] );

	return <></>;
}
