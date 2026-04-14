/**
 * Media Library Image Generation.
 *
 * Mounts the GenerateImageStandalone React component on the
 * AI Image Generation admin page.
 */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { GenerateImageStandalone } from './components/GenerateImageStandalone';
import './index.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	// Mount the app if we're on the generate image page.
	const rootEl = document.getElementById( 'ai-image-generation-root' );
	if ( rootEl ) {
		const root = createRoot( rootEl );
		root.render( <GenerateImageStandalone /> );
	}
} );
