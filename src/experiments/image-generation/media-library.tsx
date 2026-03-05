/**
 * Media Library Image Generation.
 *
 * Mounts the GenerateImageStandalone React component on the
 * AI Image Generation admin page.
 */

/**
 * WordPress dependencies
 */
import { render } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { GenerateImageStandalone } from './components/GenerateImageStandalone';

document.addEventListener( 'DOMContentLoaded', () => {
	// Mount the Standalone app if we're on the new admin page.
	const rootEl = document.getElementById( 'ai-image-generation-root' );
	if ( rootEl ) {
		render( <GenerateImageStandalone />, rootEl );
	}
} );
