/**
 * Rename modal wrapper component.
 *
 * Observes document mutations to detect when the Rename modal is opened,
 * and attaches the "Generate title" button immediately before the Cancel button.
 */

/**
 * WordPress dependencies
 */
import { createRoot, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import RenameModalButton from './RenameModalButton';
import { getSettings } from '../utils';

/**
 * Selector for identifying candidate rename modal elements in the DOM.
 */
const MODAL_SELECTOR =
	'.editor-action-modal__rename-post, .components-modal__screen-overlay';

export function RenameModalWrapper(): React.JSX.Element {
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
						'.ai-title-generation-rename-modal-wrapper'
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

				// Insert our container immediately before the Cancel button.
				container = document.createElement( 'div' );
				container.className =
					'ai-title-generation-rename-modal-wrapper';
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
