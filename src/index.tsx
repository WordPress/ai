/**
 * AI Experiments admin application entry point.
 *
 * @package WordPress\AI
 */

import domReady from '@wordpress/dom-ready';
import { createElement, createRoot, render } from '@wordpress/element';

import App from './components/App';
import type { SettingsPayload } from './types';
import './style.scss';

const mountApp = ( container: HTMLElement, settings: SettingsPayload ) => {
	if ( typeof createRoot === 'function' ) {
		const root = createRoot( container );
		root.render( <App settings={ settings } /> );
		return;
	}

	render( <App settings={ settings } />, container );
};

domReady( () => {
	const container = document.getElementById( 'ai-experiments-settings-root' );
	if ( ! container ) {
		return;
	}

	const settings =
		window.wpAiExperimentsSettings ??
		( ( container.getAttribute( 'data-settings' )
			? JSON.parse(
					container.getAttribute( 'data-settings' ) ?? '{}'
			  )
			: null ) as SettingsPayload | null );

	if ( ! settings ) {
		return;
	}

	container.removeAttribute( 'data-settings' );

	const wrapper = container.closest( '.ai-experiments-settings' );
	if ( wrapper ) {
		wrapper.classList.add( 'is-app-ready' );
	}

	mountApp( container, settings );
} );