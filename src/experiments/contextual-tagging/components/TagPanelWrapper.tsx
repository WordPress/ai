/**
 * Wrapper component that injects AI suggestions into the Tags sidebar panel.
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
 * Container ID for the tag suggestions.
 */
const CONTAINER_ID = 'ai-contextual-tagging-tags';

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
 * TagPanelWrapper component.
 *
 * Uses DOM observation to inject the suggestion panel into the Tags
 * sidebar panel in the block editor.
 *
 * @return null - Renders via portal.
 */
export default function TagPanelWrapper(): null {
	const [ container, setContainer ] = useState< HTMLElement | null >(
		null
	);

	useEffect( () => {
		const findAndAttach = (): boolean => {
			// Don't create duplicate containers.
			if ( document.getElementById( CONTAINER_ID ) ) {
				return true;
			}

			// Find the Tags panel by its toggle button text.
			const tagsPanel = findPanelByTitle( 'Tags' );

			if ( ! tagsPanel ) {
				return false;
			}

			// Create and inject our container at the end of the panel.
			const el = document.createElement( 'div' );
			el.id = CONTAINER_ID;
			tagsPanel.appendChild( el );
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
		root.render( <SuggestionPanel taxonomy="post_tag" /> );

		return () => {
			root.unmount();
		};
	}, [ container ] );

	return null;
}
