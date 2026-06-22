/**
 * Chrome DevTools 3P Tools bootstrap.
 *
 * Listens for the devtoolstooldiscovery event and registers the AI plugin
 * ToolGroup. A window-level guard ensures the listener is registered exactly
 * once even when multiple feature bundles load on the same page.
 *
 * @see https://developer.chrome.com/blog/devtools-for-agents-3p-tools
 */

/**
 * Internal dependencies
 */
import { getToolGroup } from './tools';

export { exposeToDevTools } from './registry';

// Extend Window to declare the bootstrap flag and the Chrome DevTools MCP runtime.
declare global {
	interface Window {
		__aiDevToolsBootstrapped?: boolean;
	}
}

// The devtoolstooldiscovery event carries a respondWith method.
interface DevToolsToolDiscoveryEvent extends Event {
	respondWith: ( toolGroup: ReturnType< typeof getToolGroup > ) => void;
}

if ( ! window.__aiDevToolsBootstrapped ) {
	window.__aiDevToolsBootstrapped = true;

	window.addEventListener( 'devtoolstooldiscovery', ( event: Event ) => {
		const discoveryEvent = event as DevToolsToolDiscoveryEvent;
		discoveryEvent.respondWith( getToolGroup() );
	} );
}
