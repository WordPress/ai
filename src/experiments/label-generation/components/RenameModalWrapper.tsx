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
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';

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
 * Global tracker for the last block interacted with in List View or canvas.
 */
let lastInteractedClientId: string | null = null;

if ( typeof document !== 'undefined' ) {
	const recordTargetBlock = ( event: Event ) => {
		const target = event.target as HTMLElement | null;
		const blockEl = target?.closest?.(
			'.block-editor-list-view-leaf[data-block], [data-block]'
		);
		if ( blockEl ) {
			const id = blockEl.getAttribute( 'data-block' );
			if ( id ) {
				lastInteractedClientId = id;
			}
		}
	};

	document.addEventListener( 'pointerdown', recordTargetBlock, true );
	document.addEventListener( 'contextmenu', recordTargetBlock, true );
}

/**
 * Resolves the client ID of the block currently being edited in the Rename modal.
 *
 * @param {HTMLInputElement} [inputElement] The text input element inside the modal.
 * @return {string|null} The resolved block client ID, or null.
 */
function resolveTargetClientId(
	inputElement?: HTMLInputElement | null
): string | null {
	const blockEditor = select( blockEditorStore ) as any;
	const blocks = select( blocksStore ) as any;

	const candidateIds: string[] = [];

	if ( lastInteractedClientId ) {
		candidateIds.push( lastInteractedClientId );
	}

	const selectedId = blockEditor?.getSelectedBlockClientId?.();
	if ( selectedId && ! candidateIds.includes( selectedId ) ) {
		candidateIds.push( selectedId );
	}

	const selectedIds = blockEditor?.getSelectedBlockClientIds?.();
	if ( Array.isArray( selectedIds ) ) {
		for ( const id of selectedIds ) {
			if ( ! candidateIds.includes( id ) ) {
				candidateIds.push( id );
			}
		}
	}

	if ( ! inputElement || candidateIds.length <= 1 ) {
		return candidateIds[ 0 ] ?? null;
	}

	const placeholder = inputElement.placeholder?.trim().toLowerCase();

	// Prioritize candidate whose block title matches placeholder.
	for ( const id of candidateIds ) {
		const block = blockEditor?.getBlock?.( id );
		if ( ! block ) {
			continue;
		}

		const blockType = blocks?.getBlockType?.( block.name );
		const blockTitle = blockType?.title?.trim().toLowerCase();

		if ( placeholder && blockTitle === placeholder ) {
			return id;
		}
	}

	return candidateIds[ 0 ] ?? null;
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
						'.ai-label-generation-rename-modal-wrapper'
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

				const targetClientId = resolveTargetClientId( textInput );

				// Insert our container immediately before the Cancel button.
				container = document.createElement( 'div' );
				container.className =
					'ai-label-generation-rename-modal-wrapper';
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
