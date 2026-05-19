/**
 * Connector approval admin page entry point.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ConnectorApprovalApp from './components/ConnectorApprovalApp';
import './index.scss';

domReady( () => {
	const root = document.getElementById( 'ai-connector-approval-root' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render( <ConnectorApprovalApp /> );
} );
