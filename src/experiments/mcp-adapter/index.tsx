/**
 * MCP Access admin page entry point.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import McpAccessApp from './components/McpAccessApp';
import './index.scss';

domReady( () => {
	const root = document.getElementById( 'ai-mcp-access-root' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render( <McpAccessApp /> );
} );
