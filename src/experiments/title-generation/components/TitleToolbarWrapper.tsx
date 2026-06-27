/**
 * Title toolbar wrapper component for normal editing mode.
 *
 * This component uses DOM manipulation to attach the toolbar to the title field
 * in normal editing mode (non-template mode).
 */

/**
 * WordPress dependencies
 */
import { createRoot, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import TitleToolbar from './TitleToolbar';

/**
 * Creates a reusable retry function that will attempt to execute a callback
 * when a condition is met, retrying up to a maximum number of times.
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

	// Store timeout ID for cleanup
	const start = () => {
		if ( ! timeoutId ) {
			retry();
		}
	};

	return start;
}

/**
 * Creates reusable focus/blur event handlers with optional delay on blur.
 *
 * @param {Function} onFocus   Function to call on focus event.
 * @param {Function} onBlur    Function to call on blur event.
 * @param {number}   blurDelay Delay in milliseconds before calling onBlur.
 * @return {Object} Object with focus and blur handler functions.
 */
function createFocusBlurHandlers(
	onFocus: () => void,
	onBlur: () => void,
	blurDelay: number = 10
): { focus: () => void; blur: () => void } {
	return {
		focus: onFocus,
		blur: () => {
			setTimeout( onBlur, blurDelay );
		},
	};
}

/**
 * Sets up focus and blur event listeners on an element and returns a cleanup function.
 *
 * @param {HTMLElement} element        The element to attach listeners to.
 * @param {Object}      handlers       Object containing focus and blur handler functions.
 * @param {Function}    handlers.focus Callback to handle focus event.
 * @param {Function}    handlers.blur  Callback to handle blur event.
 * @param {boolean}     useFocusIn     Whether to use focusin/focusout instead of focus/blur.
 * @return {Function} Cleanup function to remove the event listeners.
 */
function setupEventListeners(
	element: HTMLElement,
	handlers: { focus: () => void; blur: () => void },
	useFocusIn: boolean = false
): () => void {
	const focusEvent = useFocusIn ? 'focusin' : 'focus';
	const blurEvent = useFocusIn ? 'focusout' : 'blur';

	element.addEventListener( focusEvent, handlers.focus );
	element.addEventListener( blurEvent, handlers.blur );

	return () => {
		element.removeEventListener( focusEvent, handlers.focus );
		element.removeEventListener( blurEvent, handlers.blur );
	};
}

/**
 * TitleToolbarWrapper component.
 *
 * Attaches the toolbar to the title field in normal editing mode.
 *
 * @return {React.JSX.Element} The wrapper component.
 */
function TitleToolbarWrapper(): React.JSX.Element {
	useEffect( () => {
		let isAttached = false;
		let root: ReturnType< typeof createRoot > | null = null;
		let removeTitleListeners: ( () => void ) | null = null;
		let removeToolbarListeners: ( () => void ) | null = null;
		let observer: MutationObserver | null = null;
		let titleInput: HTMLElement | null = null;
		let toolbarContainer: HTMLElement | null = null;
		let wrapperContainer: HTMLElement | null = null;

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

		// Check if focus is on title input or toolbar
		const isFocusedOnTitleOrToolbar = (): boolean => {
			const editorDoc = getEditorDocument();
			if ( ! editorDoc ) {
				return false;
			}

			const activeElement = editorDoc.activeElement as HTMLElement | null;
			if ( ! activeElement ) {
				return false;
			}

			// Check if focus is on title input
			if (
				activeElement === titleInput ||
				titleInput?.contains( activeElement )
			) {
				return true;
			}

			// Check if focus is on toolbar or any element within it
			// Also check if dropdown menu is open (button with is-opened class)
			if (
				toolbarContainer &&
				( activeElement === toolbarContainer ||
					toolbarContainer.contains( activeElement ) ||
					toolbarContainer.querySelector(
						'.components-dropdown-menu .is-opened'
					) )
			) {
				return true;
			}

			return false;
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

		// Check focus state and show/hide toolbar accordingly
		const updateToolbarVisibility = () => {
			if ( isFocusedOnTitleOrToolbar() ) {
				showToolbar();
			} else {
				hideToolbar();
			}
		};

		// Tear down any state left over from a previous attachment so the
		// toolbar can be re-attached to a freshly rendered title input. This
		// happens when the editor recreates the title DOM, e.g. after toggling
		// "Show template" on and then off.
		const resetAttachmentState = () => {
			if ( removeTitleListeners ) {
				removeTitleListeners();
				removeTitleListeners = null;
			}

			if ( removeToolbarListeners ) {
				removeToolbarListeners();
				removeToolbarListeners = null;
			}

			if ( root ) {
				root.unmount();
				root = null;
			}

			if ( wrapperContainer ) {
				wrapperContainer.remove();
				wrapperContainer = null;
			}

			toolbarContainer = null;
			titleInput = null;
			isAttached = false;
		};

		// Wait for the editor to be ready
		const findAndAttachToolbar = () => {
			// Don't try if already attached
			if ( isAttached ) {
				return;
			}

			const editorDoc = getEditorDocument();
			if ( ! editorDoc ) {
				// Editor iframe not found yet, retry
				const retryEditor = createRetry(
					() => getEditorDocument() !== null,
					findAndAttachToolbar,
					20
				);
				retryEditor();
				return;
			}

			// Check if toolbar wrapper already exists in the editor document
			if ( editorDoc.querySelector( '.ai-title-toolbar-wrapper' ) ) {
				isAttached = true;
				return;
			}

			// Find the title field container in normal editing mode
			const selectors = [ '.editor-post-title__input' ];

			let foundTitleInput: HTMLElement | null = null;
			for ( const selector of selectors ) {
				foundTitleInput = editorDoc.querySelector(
					selector
				) as HTMLElement;
				if ( foundTitleInput ) {
					break;
				}
			}

			if ( ! foundTitleInput ) {
				// Title field not found yet, retry
				const retryTitle = createRetry(
					() => {
						const doc = getEditorDocument();
						if ( ! doc ) {
							return false;
						}
						return !! doc.querySelector(
							'.editor-post-title__input'
						);
					},
					findAndAttachToolbar,
					10
				);
				retryTitle();
				return;
			}

			titleInput = foundTitleInput;

			// Check if we've already attached the toolbar to this element
			if ( titleInput.closest( '.ai-title-toolbar-wrapper' ) ) {
				isAttached = true;
				return;
			}

			// Find the container that wraps the title input
			const titleContainer = titleInput.parentElement;

			if ( ! titleContainer ) {
				return;
			}

			// Store the next sibling before we move the title input
			const nextSibling = titleInput.nextSibling;

			// Create a wrapper container that will hold both the toolbar and the title input
			wrapperContainer = editorDoc.createElement( 'div' );
			wrapperContainer.className = 'ai-title-toolbar-wrapper';
			wrapperContainer.style.cssText = 'position: relative;';

			// Create a container for our toolbar
			toolbarContainer = editorDoc.createElement( 'div' );
			toolbarContainer.className = 'ai-title-toolbar-container';
			toolbarContainer.setAttribute( 'role', 'toolbar' );
			toolbarContainer.setAttribute(
				'aria-label',
				__( 'Generate title toolbar', 'ai' )
			);
			toolbarContainer.style.cssText =
				'display: none; position: absolute; z-index: 1000; top: -60px;';

			// Append the toolbar container to the wrapper
			wrapperContainer.appendChild( toolbarContainer );

			// Move the title input into the wrapper container
			wrapperContainer.appendChild( titleInput );

			// Insert the wrapper container where the title input was
			titleContainer.insertBefore( wrapperContainer, nextSibling );

			// Render the toolbar into the container
			root = createRoot( toolbarContainer );
			root.render( <TitleToolbar isStandalone /> );

			// Create and attach focus/blur handlers for title input
			const titleHandlers = createFocusBlurHandlers(
				showToolbar,
				updateToolbarVisibility
			);
			removeTitleListeners = setupEventListeners(
				titleInput,
				titleHandlers
			);

			// Create and attach focus/blur handlers for toolbar container
			const toolbarHandlers = createFocusBlurHandlers(
				showToolbar,
				updateToolbarVisibility
			);
			removeToolbarListeners = setupEventListeners(
				toolbarContainer,
				toolbarHandlers,
				true // Use focusin/focusout for toolbar
			);

			// Check initial focus state
			if ( editorDoc.activeElement === titleInput ) {
				showToolbar();
			}

			isAttached = true;
		};

		// Start looking for the title field after a short delay to ensure iframe is ready
		const initialTimeout = setTimeout( () => {
			findAndAttachToolbar();
		}, 100 );

		// Also listen for DOM changes in the editor iframe.
		// The observer stays connected for the editor's lifetime so the toolbar
		// can be re-attached if the editor recreates the title input (e.g. when
		// toggling "Show template" on and then off).
		const setupObserver = () => {
			const editorDoc = getEditorDocument();
			if ( editorDoc && ! observer ) {
				observer = new MutationObserver( () => {
					const wrapperExists = !! editorDoc.querySelector(
						'.ai-title-toolbar-wrapper'
					);

					// Our injected toolbar was removed from the DOM, but we
					// still think it is attached. Reset so we can re-attach to
					// the new title input.
					if ( isAttached && ! wrapperExists ) {
						resetAttachmentState();
					}

					if ( ! isAttached && ! wrapperExists ) {
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
			clearTimeout( initialTimeout );
			clearTimeout( observerTimeout );

			// Remove event listeners
			if ( removeTitleListeners ) {
				removeTitleListeners();
			}
			if ( removeToolbarListeners ) {
				removeToolbarListeners();
			}

			// Clean up toolbar and wrapper
			if ( root ) {
				root.unmount();
			}
			if ( wrapperContainer ) {
				wrapperContainer.remove();
			} else if ( toolbarContainer ) {
				// Fallback: if wrapper wasn't created, just remove toolbar
				toolbarContainer.remove();
			}
		};
	}, [] );

	// This component doesn't render anything itself
	// It uses useEffect to attach to the DOM
	return <></>;
}

export { TitleToolbarWrapper };
