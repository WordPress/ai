/**
 * Internal dependencies
 */
import './index.scss';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App, { ROOT_ID } from './components/App';
import { getSettings } from './settings';

const mountNode = document.getElementById( ROOT_ID );

if ( mountNode ) {
	const settings = getSettings();

	apiFetch.use( apiFetch.createNonceMiddleware( settings.rest.nonce ) );
	apiFetch.use( apiFetch.createRootURLMiddleware( settings.rest.root ) );

	createRoot( mountNode ).render( <App /> );
}
