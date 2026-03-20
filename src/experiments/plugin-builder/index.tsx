/**
 * Output JavaScript for the plugin builder.
 */

import { render } from '@wordpress/element';
import App from './App';
import './style.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const rootId = 'wp-ai-plugin-builder-root';
	const container = document.getElementById( rootId );
	if ( ! container ) {
		return;
	}

	render( <App />, container );
} );
