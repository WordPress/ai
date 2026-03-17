/**
 * Shared hook for injecting a container into an editor sidebar panel.
 *
 * Handles panel toggle cycles by continuously observing the DOM
 * and re-injecting the container when the panel is reopened.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

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
 * Hook that injects and maintains a container element at the end of a
 * named editor sidebar panel. Handles panel toggle (close/reopen) by
 * continuously observing the DOM and re-injecting as needed.
 *
 * @param panelTitle  The panel toggle button text (e.g., "Tags").
 * @param containerId A unique ID for the injected container element.
 * @return The container element, or null if not yet injected.
 */
export function usePanelInjection(
	panelTitle: string,
	containerId: string
): HTMLElement | null {
	const [ container, setContainer ] = useState< HTMLElement | null >(
		null
	);
	const containerRef = useRef< HTMLElement | null >( null );

	useEffect( () => {
		let mutating = false;

		const findAndAttach = (): void => {
			if ( mutating ) {
				return;
			}

			const panel = findPanelByTitle( panelTitle );
			const isOpen =
				panel && panel.classList.contains( 'is-opened' );

			// If the panel is closed or gone, remove our container.
			if ( ! isOpen ) {
				const existing =
					document.getElementById( containerId );
				if ( existing ) {
					mutating = true;
					existing.remove();
					mutating = false;
				}
				if ( containerRef.current ) {
					containerRef.current = null;
					setContainer( null );
				}
				return;
			}

			// Panel is open — check if our container already exists.
			const existing = document.getElementById( containerId );
			if ( existing ) {
				// Ensure it's the last child (not displaced by re-renders).
				if (
					panel &&
					panel.lastElementChild !== existing
				) {
					mutating = true;
					panel.appendChild( existing );
					mutating = false;
				}
				return;
			}

			// Create and inject our container at the end of the panel.
			const el = document.createElement( 'div' );
			el.id = containerId;
			mutating = true;
			panel!.appendChild( el );
			mutating = false;
			containerRef.current = el;
			setContainer( el );
		};

		// Try immediately.
		findAndAttach();

		// Continuously observe for panel toggles and re-renders.
		const observer = new MutationObserver( () => {
			findAndAttach();
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );

		return () => {
			observer.disconnect();
			const el = document.getElementById( containerId );
			if ( el ) {
				el.remove();
			}
			containerRef.current = null;
		};
	}, [ panelTitle, containerId ] );

	return container;
}
