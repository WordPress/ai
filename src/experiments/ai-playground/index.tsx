/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import PlaygroundApp from './components/PlaygroundApp';

/**
 * Mounts the given component into the DOM.
 *
 * @since 0.4.0
 *
 * @param jsx          - The JSX node to be mounted.
 * @param renderTarget - The target element to render the JSX into.
 */
function mountApp( jsx: JSX.Element, renderTarget: Element ) {
	const root = createRoot( renderTarget );
	root.render( jsx );
}

// Initialize the app once the DOM is ready.
domReady( () => {
	const renderTarget = document.getElementById( 'ai-playground-root' );
	if ( ! renderTarget ) {
		return;
	}

	mountApp( <PlaygroundApp />, renderTarget );
} );
