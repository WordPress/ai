/**
 * Title toolbar wrapper component for normal editing mode.
 *
 * This component uses DOM manipulation to attach the toolbar to the title field
 * in normal editing mode (non-template mode).
 *
 * @package WordPress\AI
 */

import * as React from 'react';
import { useEffect } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import TitleToolbar from './TitleToolbar';

/**
 * TitleToolbarWrapper component.
 *
 * Attaches the toolbar to the title field in normal editing mode.
 *
 * @return {JSX.Element} The wrapper component.
 */
export default function TitleToolbarWrapper(): JSX.Element {
	useEffect( () => {
		let isAttached = false;
		let retryTimeout: NodeJS.Timeout | null = null;
		let root: ReturnType< typeof createRoot > | null = null;
		let observer: MutationObserver | null = null;
		let titleInput: HTMLElement | null = null;
		let toolbarContainer: HTMLElement | null = null;
		let focusHandler: ( ( e: Event ) => void ) | null = null;
		let blurHandler: ( ( e: Event ) => void ) | null = null;

		// Find the editor iframe
		const getEditorDocument = (): Document | null => {
			// Try to find the iframe that contains the editor
			const iframe = document.querySelector(
				'iframe[name="editor-canvas"], iframe.wp-block-editor-iframe__iframe'
			) as HTMLIFrameElement | null;

			if ( iframe && iframe.contentDocument ) {
				return iframe.contentDocument;
			}

			return null;
		};

		// Show/hide toolbar based on focus
		const showToolbar = () => {
			if ( toolbarContainer ) {
				toolbarContainer.style.display = 'flex';
			}
		};

		const hideToolbar = () => {
			if ( toolbarContainer ) {
				toolbarContainer.style.display = 'none';
			}
		};

		// Wait for the editor to be ready
		const findAndAttachToolbar = () => {
			// Don't try if already attached
			if ( isAttached ) {
				return;
			}

			const editorDoc = getEditorDocument();
			if ( ! editorDoc ) {
				// Editor iframe not found yet, try again after a short delay
				if ( ! retryTimeout ) {
					let retryCount = 0;
					const maxRetries = 20;
					const retry = () => {
						if ( retryCount < maxRetries && ! isAttached ) {
							retryCount++;
							retryTimeout = setTimeout( () => {
								retryTimeout = null;
								findAndAttachToolbar();
								if ( ! isAttached && retryCount < maxRetries ) {
									retry();
								}
							}, 200 );
						}
					};
					retry();
				}
				return;
			}

			// Check if toolbar already exists in the editor document
			if ( editorDoc.querySelector( '.ai-title-toolbar-container' ) ) {
				isAttached = true;
				return;
			}

			// Find the title field container in normal editing mode
			const selectors = [
				'.editor-post-title__input',
			];

			let foundTitleInput: HTMLElement | null = null;
			for ( const selector of selectors ) {
				foundTitleInput = editorDoc.querySelector( selector ) as HTMLElement;
				if ( foundTitleInput ) {
					break;
				}
			}

			if ( ! foundTitleInput ) {
				// Title field not found yet, try again after a short delay
				if ( ! retryTimeout ) {
					let retryCount = 0;
					const maxRetries = 10;
					const retry = () => {
						if ( retryCount < maxRetries && ! isAttached ) {
							retryCount++;
							retryTimeout = setTimeout( () => {
								retryTimeout = null;
								findAndAttachToolbar();
								if ( ! isAttached && retryCount < maxRetries ) {
									retry();
								}
							}, 200 );
						}
					};
					retry();
				}
				return;
			}

			titleInput = foundTitleInput;

			// Check if we've already attached the toolbar to this element
			if ( titleInput.parentElement?.querySelector( '.ai-title-toolbar-container' ) ) {
				isAttached = true;
				return;
			}

			// Find the container that wraps the title input
			const titleContainer = titleInput.parentElement;

			if ( ! titleContainer ) {
				return;
			}

			// Create a container for our toolbar
			toolbarContainer = editorDoc.createElement( 'div' );
			toolbarContainer.className = 'ai-title-toolbar-container';
			toolbarContainer.style.cssText =
				'display: none; position: relative; background: #fff; z-index: 1000;';

			// Insert into the title container (before the input)
			titleContainer.insertBefore( toolbarContainer, titleInput );

			// Render the toolbar into the container
			root = createRoot( toolbarContainer );
			root.render( <TitleToolbar /> );

			// Add focus/blur handlers
			focusHandler = () => showToolbar();
			blurHandler = () => hideToolbar();

			titleInput.addEventListener( 'focus', focusHandler );
			titleInput.addEventListener( 'blur', blurHandler );

			isAttached = true;
		};

		// Start looking for the title field after a short delay to ensure iframe is ready
		const initialTimeout = setTimeout( () => {
			findAndAttachToolbar();
		}, 100 );

		// Also listen for DOM changes in the editor iframe
		// But only check if we haven't attached yet
		const setupObserver = () => {
			const editorDoc = getEditorDocument();
			if ( editorDoc && ! observer ) {
				observer = new MutationObserver( () => {
					if ( ! isAttached && ! editorDoc.querySelector( '.ai-title-toolbar-container' ) ) {
						findAndAttachToolbar();
					}
				} );

				observer.observe( editorDoc.body, {
					childList: true,
					subtree: true,
				} );
			}
		};

		// Try to set up observer after a delay to ensure iframe is loaded
		const observerTimeout = setTimeout( setupObserver, 500 );

		// Cleanup function
		return () => {
			if ( observer ) {
				observer.disconnect();
			}
			if ( retryTimeout ) {
				clearTimeout( retryTimeout );
			}
			clearTimeout( initialTimeout );
			clearTimeout( observerTimeout );

			// Remove event listeners
			if ( titleInput && focusHandler && blurHandler ) {
				titleInput.removeEventListener( 'focus', focusHandler );
				titleInput.removeEventListener( 'blur', blurHandler );
			}

			// Clean up toolbar
			if ( toolbarContainer && root ) {
				root.unmount();
				toolbarContainer.remove();
			}
		};
	}, [] );

	// This component doesn't render anything itself
	// It uses useEffect to attach to the DOM
	return <></>;
}

