/**
 * Wrapper component that injects AI suggestions into the Categories sidebar panel.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState, createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import SuggestionPanel from './SuggestionPanel';

/**
 * Container ID for the category suggestions.
 */
const CONTAINER_ID = 'ai-contextual-tagging-categories';

/**
 * Finds a sidebar panel by its toggle button text.
 *
 * @param title The panel title text to search for.
 * @return The panel body element, or null if not found.
 */
function findPanelByTitle( title: string ): HTMLElement | null {
	const panelBodies = document.querySelectorAll(
		'.components-panel__body'
	);

	for ( const panel of panelBodies ) {
		const toggle = panel.querySelector(
			'.components-panel__body-toggle'
		);
		if ( toggle?.textContent?.trim() === title ) {
			return panel as HTMLElement;
		}
	}

	return null;
}

/**
 * CategoryPanelWrapper component.
 *
 * Uses DOM observation to inject the suggestion panel into the Categories
 * sidebar panel in the block editor.
 *
 * @return null - Renders via portal.
 */
export default function CategoryPanelWrapper(): null {
	const [ container, setContainer ] = useState< HTMLElement | null >(
		null
	);

	useEffect( () => {
		const findAndAttach = (): boolean => {
			// Don't create duplicate containers.
			if ( document.getElementById( CONTAINER_ID ) ) {
				return true;
			}

			// Find the Categories panel by its toggle button text.
			const categoriesPanel = findPanelByTitle( 'Categories' );

			if ( ! categoriesPanel ) {
				return false;
			}

			// Create and inject our container at the end of the panel.
			const el = document.createElement( 'div' );
			el.id = CONTAINER_ID;
			categoriesPanel.appendChild( el );
			setContainer( el );
			return true;
		};

		// Try immediately.
		if ( findAndAttach() ) {
			return;
		}

		// Observe for the panel appearing.
		const observer = new MutationObserver( () => {
			if ( findAndAttach() ) {
				observer.disconnect();
			}
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );

		return () => {
			observer.disconnect();
			const el = document.getElementById( CONTAINER_ID );
			if ( el ) {
				el.remove();
			}
		};
	}, [] );

	useEffect( () => {
		if ( ! container ) {
			return;
		}

		const root = createRoot( container );
		root.render( <SuggestionPanel taxonomy="category" /> );

		return () => {
			root.unmount();
		};
	}, [ container ] );

	return null;
}
