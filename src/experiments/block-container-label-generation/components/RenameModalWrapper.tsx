/**
 * Rename modal wrapper component.
 *
 * Observes document mutations to detect when Gutenberg's block Rename modal is opened,
 * and attaches the "Generate label" button immediately before the Cancel button.
 */

/**
 * WordPress dependencies
 */
import { createRoot, useEffect } from '@wordpress/element';
import { select, subscribe } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import RenameModalButton from './RenameModalButton';
import { getSettings } from '../utils';

/**
 * Selector for identifying candidate rename modal elements in the DOM.
 */
const MODAL_SELECTOR =
	'.block-editor-block-rename-modal, .components-modal__screen-overlay.block-editor-block-rename-modal';

/**
 * Selector for a List View row, which carries its block's client ID in `data-block`.
 */
const LIST_VIEW_ROW_SELECTOR = '.block-editor-list-view-leaf[data-block]';

/**
 * Selector for UI the user passes through between a List View row and the
 * Rename modal.
 */
const RENAME_FLOW_SELECTOR = `[role="menu"], ${ MODAL_SELECTOR }`;

/**
 * Client ID of the List View row the user last interacted with.
 */
let listViewTargetClientId: string | null = null;

if ( typeof document !== 'undefined' ) {
	const trackListViewTarget = ( event: Event ) => {
		const target = event.target as Element | null;
		const row = target?.closest?.( LIST_VIEW_ROW_SELECTOR );

		if ( row ) {
			listViewTargetClientId = row.getAttribute( 'data-block' );
		} else if ( ! target?.closest?.( RENAME_FLOW_SELECTOR ) ) {
			listViewTargetClientId = null;
		}
	};

	document.addEventListener( 'pointerdown', trackListViewTarget, true );
	document.addEventListener( 'focusin', trackListViewTarget, true );

	/*
	 * Interactions inside the iframed editor canvas never reach this document,
	 * so also drop the tracked row when the selection moves to another block.
	 */
	let lastSelectedClientId: string | null = null;
	subscribe( () => {
		const selectedClientId =
			select( blockEditorStore ).getSelectedBlockClientId();

		if ( selectedClientId === lastSelectedClientId ) {
			return;
		}

		lastSelectedClientId = selectedClientId;

		if ( selectedClientId !== listViewTargetClientId ) {
			listViewTargetClientId = null;
		}
	}, blockEditorStore );
}

/**
 * Resolves the client ID of the block the Rename modal was opened for.
 *
 * @return The resolved block client ID, or null.
 */
function resolveTargetClientId(): string | null {
	return (
		listViewTargetClientId ??
		select( blockEditorStore ).getSelectedBlockClientId()
	);
}

/**
 * Renders the RenameModalWrapper observing the DOM for block Rename modal appearance.
 *
 * @return {React.JSX.Element} The component.
 */
export default function RenameModalWrapper(): React.JSX.Element {
	useEffect( () => {
		if ( ! getSettings().enabled ) {
			return;
		}

		let isAttached = false;
		let root: ReturnType< typeof createRoot > | null = null;
		let container: HTMLElement | null = null;
		let timeoutId: ReturnType< typeof setTimeout > | null = null;

		const detach = () => {
			if ( root ) {
				root.unmount();
				root = null;
			}
			if ( container ) {
				container.remove();
				container = null;
			}
			isAttached = false;
		};

		const findAndAttach = () => {
			if (
				isAttached &&
				container &&
				document.body.contains( container )
			) {
				return;
			}

			// Find modal overlays matching candidate selectors.
			const modalOverlays = Array.from(
				document.querySelectorAll< HTMLElement >( MODAL_SELECTOR )
			);

			for ( const overlay of modalOverlays ) {
				const form = overlay.querySelector< HTMLFormElement >( 'form' );
				if ( ! form ) {
					continue;
				}

				// Check if already injected into this form.
				if (
					form.querySelector(
						'.ai-block-container-label-generation-rename-modal-wrapper'
					)
				) {
					isAttached = true;
					return;
				}

				// Find buttons within the form.
				const buttons = Array.from(
					form.querySelectorAll< HTMLButtonElement >( 'button' )
				);

				// The Rename modal in WordPress has a tertiary Cancel button and a primary Save button.
				const cancelButton =
					buttons.find(
						( btn ) =>
							btn.classList.contains( 'is-tertiary' ) &&
							btn.type !== 'submit'
					) ||
					buttons.find(
						( btn ) =>
							btn.textContent?.trim().toLowerCase() === 'cancel'
					);

				if ( ! cancelButton || ! cancelButton.parentElement ) {
					continue;
				}

				const textInput = form.querySelector< HTMLInputElement >(
					'input.components-text-control__input, input[type="text"]'
				);

				const targetClientId = resolveTargetClientId();

				// Insert our container immediately before the Cancel button.
				container = document.createElement( 'div' );
				container.className =
					'ai-block-container-label-generation-rename-modal-wrapper';
				container.style.display = 'contents';

				cancelButton.parentElement.insertBefore(
					container,
					cancelButton
				);

				root = createRoot( container );
				root.render(
					<RenameModalButton
						inputElement={ textInput }
						formElement={ form }
						clientId={ targetClientId }
					/>
				);

				isAttached = true;
				return;
			}
		};

		const checkAndAttach = () => {
			if (
				isAttached &&
				( ! container || ! document.body.contains( container ) )
			) {
				detach();
			}

			findAndAttach();
		};

		const debouncedCheck = () => {
			if ( timeoutId ) {
				clearTimeout( timeoutId );
			}
			timeoutId = setTimeout( checkAndAttach, 50 );
		};

		// Initial check.
		findAndAttach();

		// Observe mutations on document.body for when the modal is opened or closed.
		const observer = new MutationObserver( () => {
			debouncedCheck();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );

		return () => {
			if ( timeoutId ) {
				clearTimeout( timeoutId );
			}
			observer.disconnect();
			detach();
		};
	}, [] );

	return <></>;
}
